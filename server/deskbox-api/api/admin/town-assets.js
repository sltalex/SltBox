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
    draw();
  }

  picker.addEventListener('change', loadSheet);
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
