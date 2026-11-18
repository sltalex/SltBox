(function () {
  'use strict';

  const GRID = 16;
  const picker = document.getElementById('town-sheet-picker');
  const canvas = document.getElementById('town-sheet-canvas');
  const form = document.getElementById('town-new-asset');
  if (!picker || !canvas) return;

  const context = canvas.getContext('2d');
  const image = new Image();
  let imageWidth = 0;
  let imageHeight = 0;
  let scale = 1;
  let selection = null;
  let dragStart = null;
  let dragging = false;
  let frameMode = null;
  let activeFrameInput = null;
  let activeFrameList = null;
  const previewStates = new Map();
  const autoScanButton = document.getElementById('town-auto-scan');
  const autoScanStatus = document.getElementById('town-auto-scan-status');
  const autoScanResults = document.getElementById('town-auto-scan-results');

  function setAutoScanStatus(message, isError) {
    if (!autoScanStatus) return;
    autoScanStatus.textContent = message;
    autoScanStatus.dataset.state = isError ? 'error' : 'ready';
  }

  function previewState(input, list) {
    if (!input || !input.id) return null;
    let state = previewStates.get(input.id);
    if (!state) {
      state = { input, list, canvas: null, label: null, play: null, stop: null, timer: null, index: 0, playing: false };
      previewStates.set(input.id, state);
    } else {
      state.input = input;
      state.list = list;
    }
    return state;
  }

  function previewFrames(state) {
    return state ? parseFrames(state.input) : [];
  }

  function stopPreview(state) {
    if (!state) return;
    if (state.timer !== null) {
      window.clearInterval(state.timer);
      state.timer = null;
    }
    state.playing = false;
    const frames = previewFrames(state);
    if (state.label && frames.length) {
      state.label.textContent = '第 ' + (Math.min(state.index, frames.length - 1) + 1) + '/' + frames.length + ' 帧 · 已停';
    }
    updatePreviewControls(state);
  }

  function drawPreview(state) {
    if (!state || !state.canvas) return;
    const frames = previewFrames(state);
    if (!frames.length) {
      state.canvas.hidden = true;
      if (state.label) state.label.textContent = '';
      updatePreviewControls(state);
      return;
    }
    state.canvas.hidden = false;
    state.index = Math.max(0, Math.min(state.index, frames.length - 1));
    const frame = frames[state.index];
    const maxWidth = 128;
    const maxHeight = 96;
    const ratio = Math.min(maxWidth / Math.max(1, frame.w), maxHeight / Math.max(1, frame.h));
    const width = Math.max(16, Math.round(frame.w * ratio));
    const height = Math.max(16, Math.round(frame.h * ratio));
    if (state.canvas.width !== width) state.canvas.width = width;
    if (state.canvas.height !== height) state.canvas.height = height;
    const previewContext = state.canvas.getContext('2d');
    previewContext.imageSmoothingEnabled = false;
    previewContext.clearRect(0, 0, width, height);
    if (image.complete && imageWidth && imageHeight) {
      previewContext.drawImage(image, frame.x, frame.y, frame.w, frame.h, 0, 0, width, height);
    }
    if (state.label) state.label.textContent = '第 ' + (state.index + 1) + '/' + frames.length + ' 帧 · ' + (state.playing ? '播放中' : '已停');
    updatePreviewControls(state);
  }

  function updatePreviewControls(state) {
    if (!state) return;
    const frames = previewFrames(state);
    const animated = frames.length > 1;
    if (state.play) state.play.disabled = !animated || state.playing;
    if (state.stop) state.stop.disabled = !state.playing;
  }

  function startPreview(state) {
    const frames = previewFrames(state);
    if (!state || frames.length < 2 || state.playing) return;
    state.playing = true;
    state.timer = window.setInterval(() => {
      const current = previewFrames(state);
      if (current.length < 2) {
        stopPreview(state);
        drawPreview(state);
        return;
      }
      state.index = (state.index + 1) % current.length;
      drawPreview(state);
    }, 125);
    drawPreview(state);
  }

  function mountPreview(state, host) {
    if (!state || !host) return;
    stopPreview(state);
    host.replaceChildren();
    const frames = previewFrames(state);
    if (!frames.length) return;
    const wrapper = document.createElement('span');
    wrapper.className = 'town-frame-preview';
    const canvasElement = document.createElement('canvas');
    canvasElement.className = 'town-frame-preview-canvas';
    canvasElement.title = '动画预览';
    canvasElement.width = 32;
    canvasElement.height = 32;
    const controls = document.createElement('span');
    controls.className = 'town-frame-preview-controls';
    const play = document.createElement('button');
    play.type = 'button';
    play.textContent = '▶ 试播';
    play.title = '播放动画帧';
    play.addEventListener('click', () => startPreview(state));
    const stop = document.createElement('button');
    stop.type = 'button';
    stop.className = 'quiet';
    stop.textContent = '⏹ 停止';
    stop.title = '停止并保留当前帧';
    stop.addEventListener('click', () => stopPreview(state));
    const label = document.createElement('small');
    label.className = 'town-frame-preview-label';
    controls.append(play, stop, label);
    wrapper.append(canvasElement, controls);
    host.appendChild(wrapper);
    state.canvas = canvasElement;
    state.play = play;
    state.stop = stop;
    state.label = label;
    drawPreview(state);
  }

  function field(name) {
    return form ? form.querySelector('[name="' + name + '"]') : null;
  }

  function setField(name, value) {
    const element = field(name);
    if (element) element.value = String(value);
  }

  function parseFrames(input) {
    if (!input) return [];
    try {
      const value = JSON.parse(input.value || '[]');
      return Array.isArray(value) ? value.filter(frame => frame && Number.isFinite(Number(frame.x)) &&
        Number.isFinite(Number(frame.y)) && Number.isFinite(Number(frame.w)) && Number.isFinite(Number(frame.h))) : [];
    } catch (_) {
      input.value = '[]';
      return [];
    }
  }

  function writeFrames(input, frames) {
    if (!input) return;
    input.value = JSON.stringify(frames.map(frame => ({
      x: Math.round(Number(frame.x)),
      y: Math.round(Number(frame.y)),
      w: Math.round(Number(frame.w)),
      h: Math.round(Number(frame.h))
    })));
    input.dispatchEvent(new Event('change',{bubbles:true}));
  }

  function listForInput(input) {
    if (!input) return null;
    const id = input.id;
    return id ? document.querySelector('[data-frame-list="' + CSS.escape(id) + '"]') : null;
  }

  function renderFrameList(input, list) {
    if (!list) return;
    const state = previewState(input, list);
    stopPreview(state);
    const frames = parseFrames(input);
    list.innerHTML = '';
    if (!frames.length) {
      list.textContent = '暂无帧。点击“＋框选添加帧”，再在图集上拖拽。';
      return;
    }
    const previewHost = document.createElement('span');
    previewHost.className = 'town-frame-preview-host';
    list.appendChild(previewHost);
    mountPreview(state, previewHost);
    frames.forEach((frame, index) => {
      const row = document.createElement('span');
      row.className = 'town-frame-item';
      row.textContent = '#' + (index + 1) + ' (' + frame.x + ',' + frame.y + ') ' + frame.w + '×' + frame.h + ' ';
      const replace = document.createElement('button');
      replace.type = 'button';
      replace.textContent = '框选';
      replace.title = '在图集上重新框选这一帧';
      replace.addEventListener('click', () => beginFramePick(input, list, index));
      const up = document.createElement('button');
      up.type = 'button';
      up.textContent = '↑';
      up.title = '前移';
      up.disabled = index === 0;
      up.addEventListener('click', () => {
        if (index < 1) return;
        const next = frames.splice(index, 1)[0];
        frames.splice(index - 1, 0, next);
        writeFrames(input, frames);
        renderFrameList(input, list);
      });
      const down = document.createElement('button');
      down.type = 'button';
      down.textContent = '↓';
      down.title = '后移';
      down.disabled = index === frames.length - 1;
      down.addEventListener('click', () => {
        if (index >= frames.length - 1) return;
        const next = frames.splice(index, 1)[0];
        frames.splice(index + 1, 0, next);
        writeFrames(input, frames);
        renderFrameList(input, list);
      });
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = '✕';
      remove.title = '删除';
      remove.addEventListener('click', () => {
        frames.splice(index, 1);
        writeFrames(input, frames);
        renderFrameList(input, list);
      });
      row.append(replace, up, down, remove);
      list.appendChild(row);
    });
  }

  function beginFramePick(input, list, replaceIndex) {
    activeFrameInput = input;
    activeFrameList = list;
    frameMode = { replaceIndex: Number.isInteger(replaceIndex) ? replaceIndex : null };
    selection = null;
    dragStart = null;
    const hint = document.getElementById('town-frame-hint');
    if (hint) hint.textContent = frameMode.replaceIndex === null
      ? '请在图集上拖拽框选，松手后添加一帧；Esc 取消'
      : '请在图集上拖拽框选，松手后替换当前帧；Esc 取消';
    document.querySelectorAll('[data-frame-add]').forEach(button => {
      button.textContent = button.dataset.frameInput === (input && input.id)
        ? '✎ 请在图上拖拽框选（Esc 取消）' : '＋框选添加帧';
    });
    draw();
  }

  function cancelFramePick() {
    frameMode = null;
    activeFrameInput = null;
    activeFrameList = null;
    selection = null;
    const hint = document.getElementById('town-frame-hint');
    if (hint) hint.textContent = '';
    document.querySelectorAll('[data-frame-add]').forEach(button => { button.textContent = '＋框选添加帧'; });
    draw();
  }

  function pointerPoint(event) {
    const rect = canvas.getBoundingClientRect();
    const sx = canvas.width / Math.max(1, rect.width);
    const sy = canvas.height / Math.max(1, rect.height);
    return {
      x: Math.max(0, Math.min(canvas.width, (event.clientX - rect.left) * sx)),
      y: Math.max(0, Math.min(canvas.height, (event.clientY - rect.top) * sy))
    };
  }

  function imagePoint(point) {
    return { x: point.x / scale, y: point.y / scale };
  }

  function chooseRegion(rect) {
    selection = { x: rect.x, y: rect.y, w: rect.w, h: rect.h };
    setField('sheet_id', picker.value);
    setField('x', rect.x);
    setField('y', rect.y);
    setField('width', rect.w);
    setField('height', rect.h);
    setField('display_width', rect.w);
    setField('display_height', rect.h);
    recalculateFootprint(form);
    draw();
  }

  function scanTransparentGrid() {
    if (!image.complete || !image.naturalWidth || !image.naturalHeight) {
      throw new Error('图集仍在加载，请稍后再试。');
    }
    const scan = document.createElement('canvas');
    scan.width = image.naturalWidth;
    scan.height = image.naturalHeight;
    const scanContext = scan.getContext('2d', { willReadFrequently: true });
    scanContext.drawImage(image, 0, 0);
    const pixels = scanContext.getImageData(0, 0, scan.width, scan.height).data;
    const scanner = window.townAssetScan;
    if (!scanner || typeof scanner.findTransparentGridRegions !== 'function') {
      throw new Error('自动识别模块未加载，请刷新页面后重试。');
    }
    return scanner.findTransparentGridRegions(
      scan.width,
      scan.height,
      (x, y) => pixels[(y * scan.width + x) * 4 + 3],
      GRID,
      8
    );
  }

  function renderAutoScanResults() {
    if (!autoScanResults) return;
    autoScanResults.replaceChildren();
    let regions;
    try {
      regions = scanTransparentGrid();
    } catch (error) {
      setAutoScanStatus(error instanceof Error ? error.message : '自动识别失败，请刷新页面后重试。', true);
      return;
    }
    setAutoScanStatus(regions.length ? `识别到 ${regions.length} 个候选区域，点击候选区域即可填入表单。` : '没有识别到透明素材。', false);
    if (regions.length) {
      const bulkButton = document.createElement('button');
      bulkButton.type = 'button';
      bulkButton.className = 'town-auto-scan-bulk';
      bulkButton.textContent = `全部加入草稿（${Math.min(regions.length, 500)} 个）`;
      bulkButton.title = regions.length > 500 ? '超过 500 个候选区域，只保存前 500 个' : '将所有候选区域批量保存为素材草稿';
      bulkButton.addEventListener('click', () => submitBulkDrafts(regions));
      autoScanResults.appendChild(bulkButton);
    }
    for (const [index, region] of regions.entries()) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'quiet';
      button.textContent = `#${index + 1} ${region.x},${region.y} ${region.w}×${region.h}`;
      button.title = '使用这个自动识别区域';
      button.addEventListener('click', () => chooseRegion(region));
      autoScanResults.appendChild(button);
    }
  }

  function submitBulkDrafts(regions) {
    if (!form || !picker.value || !regions.length) return;
    const optionText = picker.selectedOptions[0]?.textContent?.trim() || '图集';
    const prefix = optionText.replace(/\.[^.]+$/, '').replace(/[^\p{L}\p{N}_-]+/gu, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'sheet';
    const sheetId = picker.value;
    const items = regions.slice(0, 500).map((region, index) => {
      const ordinal = String(index + 1).padStart(4, '0');
      const coordinate = `${region.x}-${region.y}-${region.w}-${region.h}`;
      return {
        asset_id: `auto-${sheetId.slice(0, 12)}-${region.x}-${region.y}`,
        sheet_id: sheetId,
        name: `${prefix}-自动素材-${ordinal}`,
        category: 'object',
        subcategory: 'auto-scan',
        tags: JSON.stringify(['auto-scan', `region:${coordinate}`]),
        x: region.x, y: region.y, width: region.w, height: region.h,
        display_width: region.w, display_height: region.h,
        anchor: JSON.stringify({ x: .5, y: 1 }),
        footprint: '',
        collision: JSON.stringify({ x: .1, y: .55, width: .8, height: .4 }),
        frames: '[]', navigation: JSON.stringify({ type: 'blocked', blocked_npc: true, required_capabilities: [] }),
        sort_mode: 'y_sort', split_y: ''
      };
    });
    const batchForm = document.createElement('form');
    batchForm.method = 'post';
    batchForm.action = window.location.href.split('#')[0];
    const add = (name, value) => {
      const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; batchForm.appendChild(input);
    };
    add('csrf', form.elements.namedItem('csrf')?.value || '');
    add('operation', 'town_asset_bulk_item');
    add('panel', 'town_assets');
    add('items', JSON.stringify(items));
    autoScanButton.disabled = true;
    setAutoScanStatus(`正在保存 ${items.length} 个素材草稿…`, false);
    document.body.appendChild(batchForm);
    batchForm.submit();
  }

  function snapRect(start, end) {
    const left = Math.floor(Math.min(start.x, end.x) / GRID) * GRID;
    const top = Math.floor(Math.min(start.y, end.y) / GRID) * GRID;
    const right = Math.ceil(Math.max(start.x, end.x) / GRID) * GRID;
    const bottom = Math.ceil(Math.max(start.y, end.y) / GRID) * GRID;
    const x = Math.min(Math.max(0, left), Math.max(0, imageWidth - GRID));
    const y = Math.min(Math.max(0, top), Math.max(0, imageHeight - GRID));
    return {
      x,
      y,
      w: Math.max(GRID, Math.min(Math.max(GRID, right - x), imageWidth - x)),
      h: Math.max(GRID, Math.min(Math.max(GRID, bottom - y), imageHeight - y))
    };
  }

  function draw() {
    if (!context || !image.complete || !imageWidth || !imageHeight) return;
    context.clearRect(0, 0, canvas.width, canvas.height);
    context.imageSmoothingEnabled = false;
    context.drawImage(image, 0, 0, canvas.width, canvas.height);

    context.save();
    context.strokeStyle = 'rgba(255,255,255,.18)';
    context.lineWidth = 1;
    for (let x = GRID; x < imageWidth; x += GRID) {
      context.beginPath();
      context.moveTo(x * scale + .5, 0);
      context.lineTo(x * scale + .5, canvas.height);
      context.stroke();
    }
    for (let y = GRID; y < imageHeight; y += GRID) {
      context.beginPath();
      context.moveTo(0, y * scale + .5);
      context.lineTo(canvas.width, y * scale + .5);
      context.stroke();
    }
    context.restore();

    if (!selection) return;
    const x = selection.x * scale;
    const y = selection.y * scale;
    const width = selection.w * scale;
    const height = selection.h * scale;
    context.save();
    context.fillStyle = frameMode ? 'rgba(255,215,94,.18)' : 'rgba(33,103,212,.18)';
    context.fillRect(x, y, width, height);
    context.strokeStyle = frameMode ? '#ffd75e' : '#2167d4';
    context.lineWidth = 2;
    context.strokeRect(x + 1, y + 1, Math.max(0, width - 2), Math.max(0, height - 2));
    context.fillStyle = '#fff';
    context.font = '11px sans-serif';
    context.fillText(selection.w + '×' + selection.h, x + 4, y + 14);
    if(form.dataset.contact && !frameMode){
      const u=JSON.parse(form.dataset.contact);context.strokeStyle='#34e581';context.lineWidth=2;
      context.strokeRect(x+u[0]*width,y+u[1]*height,(u[2]-u[0])*width,(u[3]-u[1])*height);
    }
    context.restore();
  }

  function loadSheet() {
    if (!picker.value) return;
    const option = picker.selectedOptions[0];
    const width = Number(option && option.dataset.width) || 1;
    const height = Number(option && option.dataset.height) || 1;
    scale = Math.min(620 / width, 1250 / height);
    imageWidth = width;
    imageHeight = height;
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    selection = null;
    setField('sheet_id', picker.value);
    image.onload = function () {
      draw();
      previewStates.forEach(drawPreview);
    };
    image.onerror = function () { context && context.clearRect(0, 0, canvas.width, canvas.height); };
    image.src = '?panel=town_assets&sheet=' + encodeURIComponent(picker.value);
    draw();
  }

  function finishSelection(event) {
    if (!dragging || !selection) return;
    dragging = false;
    if (event && canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
    const start = dragStart || { x: selection.x, y: selection.y };
    const end = { x: selection.x + selection.w, y: selection.y + selection.h };
    const rect = snapRect(start, end);
    selection = rect;
    dragStart = null;

    if (frameMode && activeFrameInput) {
      const frames = parseFrames(activeFrameInput);
      if (frameMode.replaceIndex === null) frames.push(rect);
      else frames[frameMode.replaceIndex] = rect;
      writeFrames(activeFrameInput, frames);
      recalculateFootprint(activeFrameInput.form);
      renderFrameList(activeFrameInput, activeFrameList);
      cancelFramePick();
      return;
    }

    setField('x', rect.x);
    setField('y', rect.y);
    setField('width', rect.w);
    setField('height', rect.h);
    setField('display_width', rect.w);
    setField('display_height', rect.h);
    recalculateFootprint(form);
    draw();
  }

  function formField(target, name) { return target.elements.namedItem(name); }
  async function recalculateFootprint(target) {
    const status=target.querySelector('[data-footprint-status]') || document.getElementById(target.id+'-foot-status');
    try {
      const field=name=>formField(target,name), number=name=>Number(field(name)?.value);
      const sheetId=field('sheet_id')?.value;
      let source=image;
      if(sheetId!==picker.value || !image.complete || !image.naturalWidth) {
        source=new Image();source.src='?panel=town_assets&sheet='+encodeURIComponent(sheetId);await source.decode();
      }
      const frames=JSON.parse(field('frames')?.value||'[]');
      const regions=frames.length?frames:[{x:number('x'),y:number('y'),w:number('width'),h:number('height')}];
      let union=[1,1,0,0];
      for(const frame of regions) {
        const x=Number(frame.x),y=Number(frame.y),w=Number(frame.w??frame.width),h=Number(frame.h??frame.height);
        if(x<0||y<0||w<1||h<1||x+w>source.naturalWidth||y+h>source.naturalHeight)throw Error('框选区域超出图集');
        const off=document.createElement('canvas');off.width=w;off.height=h;const ctx=off.getContext('2d');ctx.drawImage(source,x,y,w,h,0,0,w,h);const pixels=ctx.getImageData(0,0,w,h).data;
        let top=h,bottom=-1;
        for(let yy=0;yy<h;yy++)for(let xx=0;xx<w;xx++)if(pixels[(yy*w+xx)*4+3]>0){top=Math.min(top,yy);bottom=Math.max(bottom,yy);}
        if(bottom<0)throw Error('存在全透明帧，无法识别占地');
        const cut=top+Math.floor((bottom-top+1)*2/3);let left=w,right=-1,ct=h,cb=-1;
        for(let yy=cut;yy<=bottom;yy++)for(let xx=0;xx<w;xx++)if(pixels[(yy*w+xx)*4+3]>0){left=Math.min(left,xx);right=Math.max(right,xx);ct=Math.min(ct,yy);cb=Math.max(cb,yy);}
        union=[Math.min(union[0],left/w),Math.min(union[1],ct/h),Math.max(union[2],(right+1)/w),Math.max(union[3],(cb+1)/h)];
      }
      const dw=number('display_width'),dh=number('display_height');
      const foot={width:Math.ceil(Number(((union[2]-union[0])*dw/16).toFixed(8))),height:Math.ceil(Number(((union[3]-union[1])*dh/16).toFixed(8)))};
      if(!dw||!dh||foot.width<1||foot.height<1||foot.width>16||foot.height>16)throw Error('占地超过 16×16 格，请调整显示尺寸或手动填写');
      field('footprint').value=JSON.stringify(foot);field('collision').value=JSON.stringify({x:union[0],y:union[1],width:union[2]-union[0],height:union[3]-union[1]});
      field('footprint').setCustomValidity('');
      if(status)status.textContent=`自动占地 ${foot.width}×${foot.height} 格（16 像素/格）`;
      target.dataset.contact=JSON.stringify(union);
      draw();
    } catch(error) { if(status)status.textContent=error.message; const field=formField(target,'footprint');if(field)field.setCustomValidity(error.message); }
  }
  document.querySelectorAll('form').forEach(target=>{
    if(!formField(target,'footprint'))return;
    if(target!==form){
      for(const name of ['display_width','display_height']){const field=formField(target,name);field.type='number';field.style.width='76px';field.title=name==='display_width'?'显示宽度（像素）':'显示高度（像素）';}
      const button=document.createElement('button');button.type='button';button.textContent='重新计算占地';
      button.onclick=()=>recalculateFootprint(target);
      const label=document.createElement('small');label.id=target.id+'-foot-status';
      target.append(button,label);
      for(const name of ['footprint','collision']){const field=formField(target,name);field.type='text';field.style.maxWidth='160px';field.title=name==='footprint'?'占地，可手动修改':'碰撞范围，可手动修改';}
    }
    target.querySelector('[data-recalculate-footprint]')?.addEventListener('click',()=>recalculateFootprint(target));
    for(const name of ['x','y','width','height','display_width','display_height','frames'])formField(target,name)?.addEventListener('change',()=>recalculateFootprint(target));
    formField(target,'footprint')?.addEventListener('input',()=>formField(target,'footprint').setCustomValidity(''));
  });

  picker.addEventListener('change', loadSheet);
  autoScanButton?.addEventListener('click', event => {
    event.preventDefault();
    renderAutoScanResults();
  });
  canvas.addEventListener('pointerdown', function (event) {
    if (!imageWidth || !imageHeight || event.button !== 0) return;
    event.preventDefault();
    canvas.setPointerCapture(event.pointerId);
    const point = imagePoint(pointerPoint(event));
    dragStart = point;
    selection = { x: point.x, y: point.y, w: 0, h: 0 };
    dragging = true;
    draw();
  });
  canvas.addEventListener('pointermove', function (event) {
    if (!dragging || !selection) return;
    const point = imagePoint(pointerPoint(event));
    const start = dragStart || { x: selection.x, y: selection.y };
    const left = Math.min(start.x, point.x);
    const top = Math.min(start.y, point.y);
    selection = { x: left, y: top, w: Math.abs(point.x - start.x), h: Math.abs(point.y - start.y) };
    draw();
  });
  canvas.addEventListener('pointerup', finishSelection);
  canvas.addEventListener('pointercancel', finishSelection);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && frameMode) { event.preventDefault(); cancelFramePick(); }
  });
  document.querySelectorAll('[data-frame-add]').forEach(button => {
    const input = document.getElementById(button.dataset.frameInput || '');
    const list = input ? listForInput(input) : null;
    button.addEventListener('click', () => beginFramePick(input, list, null));
    if (input) renderFrameList(input, list);
  });

  loadSheet();
}());
