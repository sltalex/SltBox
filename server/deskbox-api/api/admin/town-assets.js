(function () {
  'use strict';

  // 供局部刷新后重新初始化（新增/保存/删除不会整页跳转）
  let activeCtx = null;
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || !activeCtx) return;
    // 框选中时 Esc 优先取消框选，不关浮层
    if (activeCtx.frameMode()) { event.preventDefault(); activeCtx.cancel(); return; }
    if (activeCtx.floatOpen && activeCtx.floatOpen()) { event.preventDefault(); activeCtx.closeFloat(); }
  });

  function boot() {

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
  // 当前主画布（大图框选区）正在显示的图集 ID
  let currentSheetId = '';
  // 图集大图缓存：sheet_id -> { image, promise }，避免同一张图重复请求
  const sheetImages = new Map();
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

  /** 图集大图的请求地址（与画布、预览共用同一出口） */
  function sheetImageUrl(sheetId) {
    return '?panel=town_assets&sheet=' + encodeURIComponent(sheetId);
  }

  /**
   * 按 sheet_id 加载图集大图，带缓存。
   * 返回 Promise<Image>，失败时 reject（调用方需自行兜底，不能退回别的图集）。
   */
  function loadSheetImage(sheetId) {
    if (!sheetId) return Promise.reject(new Error('未选择图集'));
    let entry = sheetImages.get(sheetId);
    if (!entry) {
      const sheetImage = new Image();
      entry = { image: sheetImage, promise: null };
      entry.promise = new Promise(function (resolve, reject) {
        sheetImage.onload = function () { resolve(sheetImage); };
        sheetImage.onerror = function () { reject(new Error('图集加载失败：' + sheetId)); };
      });
      sheetImage.src = sheetImageUrl(sheetId);
      sheetImages.set(sheetId, entry);
    }
    return entry.promise;
  }

  /** 从表单（含 form 属性关联的控件）里取该记录绑定的图集 ID */
  function sheetIdForTarget(target) {
    const select = target && target.elements ? target.elements.namedItem('sheet_id') : null;
    if (select && select.value) return select.value;
    return picker.value;
  }

  /**
   * 取得某条记录（其表单）绑定的图集 ID。
   * 记录行的图集下拉通过 form="..." 关联到表单，仍能被 form.elements 取到。
   */
  function sheetIdForInput(input) {
    if (!input) return picker.value;
    const owner = input.form || (input.getAttribute && document.getElementById(input.getAttribute('form') || ''));
    return sheetIdForTarget(owner);
  }

  /** 让预览状态绑定到它自己那条记录的图集，图集变化时重新拉图 */
  function refreshPreviewSource(state) {
    if (!state || !state.input) return;
    const sheetId = sheetIdForInput(state.input);
    if (state.sheetId === sheetId) return;
    state.sheetId = sheetId;
    state.image = null;
    if (!sheetId) return;
    loadSheetImage(sheetId).then(function (loaded) {
      // 期间又切换过图集，丢弃这次结果，避免显示错图
      if (state.sheetId !== sheetId) return;
      state.image = loaded;
      drawPreview(state);
    }).catch(function () { /* 加载失败保持空白，绝不回退到其他图集 */ });
  }

  function previewState(input, list) {
    if (!input || !input.id) return null;
    let state = previewStates.get(input.id);
    if (!state) {
      state = { input, list, canvas: null, label: null, play: null, stop: null, timer: null, index: 0, playing: false, sheetId: '', image: null };
      previewStates.set(input.id, state);
    } else {
      state.input = input;
      state.list = list;
    }
    refreshPreviewSource(state);
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
    // 只使用本记录所属图集的大图，不再复用顶部“框选图集”的画布图
    const source = state.image;
    if (source && source.complete && source.naturalWidth && source.naturalHeight) {
      previewContext.drawImage(source, frame.x, frame.y, frame.w, frame.h, 0, 0, width, height);
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

  /* ===== 新增标记：资源 ID 自动生成 ===== */
  const idInput = form ? form.querySelector('input[name="asset_id"]') : null;
  const idButton = document.getElementById('town-new-asset-id-gen');

  function normalizeAssetId(value) {
    return String(value || '').trim().toLowerCase().replace(/^town-source-/, '');
  }

  /** 把任意文本压成 ID 片段：只留小写字母/数字/短横线，中文等会被过滤掉 */
  function idSlug(text, limit) {
    return String(text || '')
      .replace(/\.(png|jpe?g|webp|gif|bmp)$/i, '')
      .replace(/[^A-Za-z0-9]+/g, '-')
      .slice(0, limit || 24)
      .replace(/^-+|-+$/g, '')
      .toLowerCase();
  }

  /** 图集文件名 → ID 片段（中文文件名会得到空串，交给序号区分） */
  function sheetIdSlug(sheetId) {
    const option = Array.prototype.find.call(picker.options, item => item.value === sheetId);
    if (!option) return '';
    return idSlug(String(option.textContent || '').replace(/\s*\([^()]*\)\s*$/, ''), 24);
  }

  /** 页面上已经用掉的资源 ID（列表里的 + 表单里当前的值），用于避重 */
  function usedAssetIds() {
    const ids = new Set();
    document.querySelectorAll('.town-assets-items code.tai-id').forEach(function (node) {
      const value = normalizeAssetId(node.textContent);
      if (value) ids.add(value);
    });
    if (idInput && idInput.value.trim()) ids.add(normalizeAssetId(idInput.value));
    return ids;
  }

  /** town-source-<分类>-<图集>-<序号> */
  function buildAssetId() {
    if (!form) return '';
    const category = String(formField(form, 'category')?.value || 'other');
    const sheetId = String(formField(form, 'sheet_id')?.value || picker.value || '');
    const parts = [idSlug(category, 24) || 'asset'];
    const sheet = sheetIdSlug(sheetId);
    if (sheet) parts.push(sheet);
    // 序号比较在“去掉 town-source- 前缀”的形态上做，和 normalizeAssetId 保持一致
    const base = parts.join('-').slice(0, 88).replace(/-+$/, '');
    const used = usedAssetIds();
    const escape = base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp('^' + escape + '-(\\d{1,4})$');
    let next = 1;
    used.forEach(function (id) {
      const match = id.match(pattern);
      if (match) next = Math.max(next, Number(match[1]) + 1);
    });
    let candidate = 'town-source-' + base + '-' + String(next).padStart(2, '0');
    while (used.has(normalizeAssetId(candidate)) && next < 9999) {
      next += 1;
      candidate = 'town-source-' + base + '-' + String(next).padStart(2, '0');
    }
    return candidate;
  }

  /** force=true 强制重算；否则只在空着、或上次由系统生成时才填（手改过就不动） */
  function fillAssetId(force) {
    if (!idInput) return '';
    const auto = idInput.dataset.auto === '1';
    if (!force && !auto && idInput.value.trim() !== '') return idInput.value;
    const value = buildAssetId();
    if (!value) return '';
    idInput.value = value;
    idInput.dataset.auto = '1';
    idInput.setCustomValidity('');
    return value;
  }
  window.fillTownAssetId = fillAssetId;

  if (idInput) {
    idInput.addEventListener('input', function () { idInput.dataset.auto = '0'; });
  }
  idButton?.addEventListener('click', function (event) {
    event.preventDefault();
    fillAssetId(true);
    if (idInput) { idInput.focus(); idInput.select(); }
  });
  if (form) {
    ['sheet_id', 'category'].forEach(function (name) {
      formField(form, name)?.addEventListener('change', function () { fillAssetId(false); });
    });
  }

  /* ===== 新增标记：记住上次填的名称/分类/排序/子分类，方便连着加同批元素 ===== */
  const PREFILL_KEY = 'townNewAssetDefaults';
  const PREFILL_FIELDS = ['name', 'category', 'sort_mode', 'subcategory'];

  function readPrefill() {
    try { return JSON.parse(localStorage.getItem(PREFILL_KEY) || '{}') || {}; } catch (error) { return {}; }
  }

  function savePrefill() {
    if (!form) return;
    const data = readPrefill();
    PREFILL_FIELDS.forEach(function (name) {
      const element = formField(form, name);
      if (element && typeof element.value === 'string') data[name] = element.value;
    });
    try { localStorage.setItem(PREFILL_KEY, JSON.stringify(data)); } catch (error) { /* 隐私模式下忽略 */ }
  }

  function applyPrefill() {
    if (!form) return;
    const data = readPrefill();
    PREFILL_FIELDS.forEach(function (name) {
      const element = formField(form, name);
      const value = data[name];
      if (!element || typeof value !== 'string' || value === '') return;
      // 下拉框里已经没有这个选项（分类/排序改过枚举）就跳过，别把值清空
      if (element.tagName === 'SELECT' &&
        !Array.prototype.some.call(element.options, option => option.value === value)) return;
      element.value = value;
      if (name === 'name') element.dataset.prefill = '1';
    });
  }

  PREFILL_FIELDS.forEach(function (name) {
    const element = formField(form, name);
    if (!element) return;
    element.addEventListener(element.tagName === 'SELECT' ? 'change' : 'input', savePrefill);
  });
  const nameInput = formField(form, 'name');
  if (nameInput) {
    // 带入上次名称后不选中文本：首次聚焦把光标放到末尾，直接往后打字或自己删除即可
    nameInput.addEventListener('focus', function () {
      if (nameInput.dataset.prefill !== '1') return;
      nameInput.dataset.prefill = '0';
      try {
        const end = nameInput.value.length;
        nameInput.setSelectionRange(end, end);
      } catch (error) { /* 忽略 */ }
    });
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

  /* ---------- 记录行的「框选预览」：没有动画帧也能看到框选出来的图 ---------- */
  const regionPreviews = new Map();

  /** 读取该记录的框选矩形；无效（未框选/超界）时返回 null */
  function regionRectOf(target) {
    const read = function (name) {
      const field = formField(target, name);
      return field ? Number(field.value) : NaN;
    };
    const x = read('x');
    const y = read('y');
    const w = read('width');
    const h = read('height');
    if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(w) || !Number.isFinite(h)) return null;
    if (w < 1 || h < 1 || x < 0 || y < 0) return null;
    return { x: x, y: y, w: w, h: h };
  }

  /** 在记录行的「操作」列创建预览位（插在帧列表之前，只创建一次） */
  function mountRegionPreview(target) {
    if (!target || !target.id) return null;
    if (regionPreviews.has(target.id)) return regionPreviews.get(target.id);
    const framesInput = formField(target, 'frames');
    const list = framesInput ? listForInput(framesInput) : null;
    if (!list || !list.parentNode) return null;
    const host = document.createElement('span');
    host.className = 'town-region-preview';
    host.style.cssText = 'display:inline-block;margin:0 8px 6px 0;vertical-align:top;text-align:center';
    const previewCanvas = document.createElement('canvas');
    previewCanvas.width = 48;
    previewCanvas.height = 48;
    // 棋盘格底衬，方便看清透明区域
    previewCanvas.style.cssText = 'display:block;image-rendering:pixelated;border:1px solid #ccd4e0;border-radius:6px;' +
      'background:repeating-conic-gradient(#e9edf4 0% 25%,#ffffff 0% 50%) 0 0/12px 12px';
    const label = document.createElement('small');
    label.style.cssText = 'display:block;margin-top:2px;max-width:150px;font-size:11px;line-height:1.3;color:#6b7789';
    label.textContent = '框选预览';
    host.append(previewCanvas, label);
    // 优先放进按钮行左侧，避免预览独占一行把行高撑大
    const actionBar = list.parentNode.querySelector('.tai-actions');
    if (actionBar) actionBar.insertBefore(host, actionBar.firstChild);
    else list.parentNode.insertBefore(host, list);
    const entry = { target: target, host: host, canvas: previewCanvas, label: label };
    regionPreviews.set(target.id, entry);
    return entry;
  }

  function drawRegionPreview(entry) {
    if (!entry) return;
    const target = entry.target;
    const previewCanvas = entry.canvas;
    const previewContext = previewCanvas.getContext('2d');
    const rect = regionRectOf(target);
    previewContext.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
    if (!rect) {
      entry.label.textContent = '未框选';
      return;
    }
    // 等比缩放到 64×64 以内，最多放大 4 倍：列表里只做缩略图，别把行高撑起来
    const ratio = Math.min(64 / rect.w, 64 / rect.h, 4);
    const width = Math.max(8, Math.round(rect.w * ratio));
    const height = Math.max(8, Math.round(rect.h * ratio));
    if (previewCanvas.width !== width) previewCanvas.width = width;
    if (previewCanvas.height !== height) previewCanvas.height = height;
    const sheetId = sheetIdForTarget(target);
    entry.label.textContent = rect.w + '×' + rect.h + ' @' + rect.x + ',' + rect.y;
    if (!sheetId) {
      entry.label.textContent = '未选择图集';
      return;
    }
    loadSheetImage(sheetId).then(function (source) {
      // 期间记录可能被修改：以最新坐标重画
      if (regionPreviews.get(target.id) !== entry) return;
      const now = regionRectOf(target);
      if (!now) {
        entry.label.textContent = '未框选';
        previewContext.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
        return;
      }
      const naturalWidth = source.naturalWidth || 0;
      const naturalHeight = source.naturalHeight || 0;
      if (!naturalWidth || !naturalHeight) return;
      // 越界部分裁掉，避免画出空白导致误判
      const sx = Math.max(0, Math.min(now.x, naturalWidth - 1));
      const sy = Math.max(0, Math.min(now.y, naturalHeight - 1));
      const sw = Math.max(1, Math.min(now.w, naturalWidth - sx));
      const sh = Math.max(1, Math.min(now.h, naturalHeight - sy));
      previewContext.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
      previewContext.imageSmoothingEnabled = false;
      previewContext.drawImage(source, sx, sy, sw, sh, 0, 0, previewCanvas.width, previewCanvas.height);
    }).catch(function () {
      if (regionPreviews.get(target.id) !== entry) return;
      entry.label.textContent = '图集加载失败';
    });
  }

  function refreshRegionPreview(target) {
    if (!target || !target.id) return;
    const entry = regionPreviews.get(target.id) || mountRegionPreview(target);
    if (entry) drawRegionPreview(entry);
  }

  function beginFramePick(input, list, replaceIndex) {
    activeFrameInput = input;
    activeFrameList = list;
    frameMode = { replaceIndex: Number.isInteger(replaceIndex) ? replaceIndex : null };
    selection = null;
    dragStart = null;
    // 这条记录可能属于另一张图集：先把主画布切到它自己的大图，否则会在错误的图上框选
    const targetSheet = sheetIdForInput(input);
    if (targetSheet && targetSheet !== currentSheetId) {
      const hasOption = Array.prototype.some.call(picker.options, function (item) { return item.value === targetSheet; });
      if (hasOption) {
        picker.value = targetSheet;
        selectSheet(targetSheet);
      }
    }
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
        frames: '[]', auto_navigation_draft: '1',
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

  /** 把指定图集的大图铺到主画布上（不触碰任何表单字段） */
  function selectSheet(sheetId) {
    if (!sheetId) return;
    const option = Array.prototype.find.call(picker.options, function (item) { return item.value === sheetId; });
    const width = Number(option && option.dataset.width) || 1;
    const height = Number(option && option.dataset.height) || 1;
    scale = Math.min(620 / width, 1250 / height);
    imageWidth = width;
    imageHeight = height;
    currentSheetId = sheetId;
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    selection = null;
    image.onload = function () {
      draw();
      previewStates.forEach(drawPreview);
    };
    image.onerror = function () { context && context.clearRect(0, 0, canvas.width, canvas.height); };
    image.src = sheetImageUrl(sheetId);
    draw();
  }

  /** 顶部“框选图集”下拉切换：主画布与“新增标记”表单的图集一起跟随 */
  function loadSheet() {
    if (!picker.value) return;
    selectSheet(picker.value);
    setField('sheet_id', picker.value);
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
      // 每条记录都挂一个「框选预览」，与有没有动画帧无关
      refreshRegionPreview(target);
    }
    target.querySelector('[data-recalculate-footprint]')?.addEventListener('click',()=>recalculateFootprint(target));
    for(const name of ['x','y','width','height','display_width','display_height','frames'])formField(target,name)?.addEventListener('change',()=>{recalculateFootprint(target);refreshRegionPreview(target);});
    // 记录行更换图集后：帧预览要换成新图集的大图，占地也要按新图重算
    formField(target,'sheet_id')?.addEventListener('change',()=>{
      if(target===form)return; // 新增表单的图集由顶部下拉的 loadSheet 统一处理
      const framesInput=formField(target,'frames');
      if(framesInput)renderFrameList(framesInput,listForInput(framesInput));
      recalculateFootprint(target);
      refreshRegionPreview(target);
    });
    formField(target,'footprint')?.addEventListener('input',()=>formField(target,'footprint').setCustomValidity(''));
  });

  picker.addEventListener('change', function () {
    loadSheet();      // 主画布跟着切图
    fillAssetId(false); // 图集变了，自动 ID 跟着换（手填过的不动）
  });
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
  /* ===== 新增标记：右侧悬浮面板（可收起，记住上次状态） ===== */
  const floatPanel = document.getElementById('town-new-asset-float');
  const floatOpener = document.getElementById('town-new-asset-open');
  const FLOAT_KEY = 'townNewAssetFloatOpen';
  function floatStoredOpen() {
    try { return localStorage.getItem(FLOAT_KEY) !== '0'; } catch (error) { return true; }
  }
  function applyFloat(open, focus) {
    if (!floatPanel || !floatOpener) return;
    floatPanel.hidden = !open;
    floatOpener.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && focus) {
      const first = floatPanel.querySelector('input[name="asset_id"]');
      if (first) first.focus();
    }
  }
  function setFloatOpen(open, focus) {
    applyFloat(open, focus);
    try { localStorage.setItem(FLOAT_KEY, open ? '1' : '0'); } catch (error) { /* 隐私模式下忽略 */ }
  }
  if (floatPanel && floatOpener) {
    if (floatOpener.dataset.tnToggle !== '1') {
      floatOpener.dataset.tnToggle = '1';
      floatOpener.addEventListener('click', () => setFloatOpen(floatPanel.hidden === true, true));
      floatPanel.addEventListener('click', event => {
        const closer = event.target.closest ? event.target.closest('[data-tn-close]') : null;
        if (!closer) return;
        setFloatOpen(false, false);
        floatOpener.focus();
      });
    }
    applyFloat(floatStoredOpen(), false);
  }

  // 登记给外层唯一的 keydown 监听，避免重复初始化时叠加监听
  activeCtx = {
    frameMode: () => !!frameMode,
    cancel: cancelFramePick,
    floatOpen: () => !!(floatPanel && floatOpener && !floatPanel.hidden),
    closeFloat: () => setFloatOpen(false, false),
  };
  document.querySelectorAll('[data-frame-add]').forEach(button => {
    const input = document.getElementById(button.dataset.frameInput || '');
    const list = input ? listForInput(input) : null;
    button.addEventListener('click', () => beginFramePick(input, list, null));
    if (input) renderFrameList(input, list);
  });

  // 先恢复上次填的分类/排序/子分类，自动 ID 才会用上同一个分类
  applyPrefill();
  loadSheet();
  fillAssetId(false);
  }

  window.TownAssets = { init: boot };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());
