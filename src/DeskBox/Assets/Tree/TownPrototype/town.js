import { clone, validateLayout, validatePlacement, playerCanStand } from './town-rules.js';

// One isolated town prototype: material editing and visual exploration use demo data.
const $ = id => document.getElementById(id);
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const state = {
  catalog: null, initial: null, layout: null, scene: null, game: null, world: null, assetSheets: new Map(), townAssets: new Map(), chunks: new Map(), objects: new Map(), presence: new Map(), inventory: new Map(), pendingActions: new Map(), cursor: 0, graylisted: false, requestedChunks: new Set(), shared: true,
  mode: 'roam', category: 'all', selectedAsset: null, selectedInstance: null,
  dirty: false, authenticated: false, canSave: false, saving: false, conflict: false,
  version: 0, epoch: 0, history: [], revision: 0, saveRevision: -1,
  visible: !document.hidden, hostActive: true, noticeTimer: null, pendingState: null,
  status: '正在载入…', fatal: false, zoom: 2, modal: false, search: '', subcategory: 'all', page: 1, legacyItems: []
};

const townCategoryLabels = {
  character: '人物', animal: '动物', monster: '怪物', terrain: '地形', water: '水面', farmland: '农田',
  fence: '围栏', building: '建筑', tree: '树木', path: '道路', object: '物件', tool: '工具',
  ui: '界面', portrait: '头像', effect: '特效', font: '字体', other: '其他'
};

function catalogItem(id) {
  return state.catalog?.items.find(item => item.id === id) || state.legacyItems.find(item => item.id === id) || null;
}

function townMaterialItems() { return [...state.townAssets.values()]; }

function renderCategories() {
  const host = $('categories');
  if (!host) return;
  const categories = [...new Set(townMaterialItems().map(item => item.category).filter(Boolean))];
  const all = document.createElement('button');
  all.dataset.category = 'all'; all.textContent = '全部'; all.className = state.category === 'all' ? 'active' : '';
  all.addEventListener('click', () => { state.category = 'all'; state.subcategory = 'all'; state.page = 1; renderMaterials(); });
  host.replaceChildren(all, ...categories.map(category => {
    const button = document.createElement('button');
    button.dataset.category = category;
    button.textContent = townCategoryLabels[category] || category;
    button.className = state.category === category ? 'active' : '';
    button.addEventListener('click', () => { state.category = category; state.subcategory = 'all'; state.page = 1; renderMaterials(); });
    return button;
  }));
}

function host(message) {
  const value = { ...message, session_epoch: state.epoch };
  if (window.chrome?.webview) window.chrome.webview.postMessage(value);
  else window.dispatchEvent(new CustomEvent('townPrototypeMessage', { detail: value }));
}

function reportError(error) {
  const message = String(error?.message || error);
  state.fatal = true;
  $('loading').hidden = false;
  $('loading').classList.add('error');
  $('loading').replaceChildren(Object.assign(document.createElement('strong'), { textContent: '小镇暂时无法打开' }), Object.assign(document.createElement('span'), { textContent: message }));
  $('save').disabled = true;
  host({ type: 'error', message });
}

function notice(message, duration = 2600) {
  window.clearTimeout(state.noticeTimer);
  $('notice').textContent = message;
  $('notice').hidden = false;
  state.noticeTimer = window.setTimeout(() => { $('notice').hidden = true; }, duration);
}

function confirmAction(title, message) {
  clearKeys();
  const dialog = $('confirmation');
  if (dialog.open) return Promise.resolve(false);
  $('confirm-title').textContent = title;
  $('confirm-message').textContent = message;
  state.modal = true;
  return new Promise(resolve => {
    const finish = accepted => {
      dialog.close(); state.modal = false;
      $('confirm-cancel').onclick = null;
      $('confirm-accept').onclick = null;
      dialog.oncancel = null;
      resolve(accepted);
      $('game').focus({ preventScroll: true });
    };
    $('confirm-cancel').onclick = () => finish(false);
    $('confirm-accept').onclick = () => finish(true);
    dialog.oncancel = event => { event.preventDefault(); finish(false); };
    dialog.showModal();
  });
}

function currentOwner() { return state.world?.account_id || state.world?.account || ''; }
function canEditObject(value) { const owner = value?.owner_id || value?.owner_user_id || value?.owner || ''; return !owner || owner === currentOwner() || owner === 'demo'; }

function updateUI() {
  if (!state.catalog || !state.layout) return;
  $('roam').classList.toggle('active', state.mode === 'roam');
  $('edit').classList.toggle('active', state.mode === 'edit');
  $('roam').setAttribute('aria-pressed', String(state.mode === 'roam'));
  $('edit').setAttribute('aria-pressed', String(state.mode === 'edit'));
  $('stage').value = String(state.layout.demo_stage);
  $('placed-count').textContent = `${state.layout.placements.length} 件`;
  $('undo').disabled = !state.history.length;
  $('delete').disabled = !state.selectedInstance || !canEditObject(state.layout.placements.find(value => value.instance_id === state.selectedInstance));
  const selected = state.selectedInstance ? state.layout.placements.find(item => item.instance_id === state.selectedInstance)?.asset_id : state.selectedAsset;
  $('selection-name').textContent = catalogItem(selected)?.name || '尚未选择';
  $('mode-description').textContent = state.mode === 'roam' ? '漫游模式' : state.selectedAsset ? '点击地图放置 · 拖动物件移动' : '编辑模式 · 选择材料或已摆放物件';
  $('save').disabled = !state.authenticated || !state.graylisted || !state.canSave || state.saving || state.conflict || state.fatal;
  $('save').textContent = state.saving ? '正在保存…' : '保存小镇';
  $('reload').disabled = state.saving;
  $('version-label').textContent = state.graylisted ? `共享版本 ${state.version}` : '共享小镇 · 灰度开放';
  $('save-state').textContent = state.status;
  $('save-state').className = `save-state${state.conflict || state.status.includes('失败') || state.status.includes('超时') ? ' error' : state.dirty ? ' dirty' : state.version > 0 ? ' saved' : ''}`;
  const counts = new Map();
  for (const item of state.layout.placements) counts.set(item.asset_id, (counts.get(item.asset_id) || 0) + 1);
  for (const button of $('material-list').children) {
    const remaining = Math.max(0, state.inventory.get(button.dataset.id) || 0);
    button.classList.toggle('chosen', button.dataset.id === state.selectedAsset);
    button.classList.toggle('depleted', remaining <= 0);
    button.querySelector('.remaining').textContent = button.dataset.source === 'town' ? '小镇素材' : `可用 ${remaining}`;
    button.setAttribute('aria-pressed', String(button.dataset.id === state.selectedAsset));
  }
  for (const button of document.querySelectorAll('#categories [data-category]')) button.classList.toggle('active', button.dataset.category === state.category);
}

function renderMaterials() {
  renderCategories();
  const list = townMaterialItems().filter(item => (state.category === 'all' || item.category === state.category) && (state.subcategory === 'all' || item.subcategory === state.subcategory) && (!state.search || `${item.name} ${item.id}`.toLowerCase().includes(state.search.toLowerCase())));
  const pages = Math.max(1, Math.ceil(list.length / 24));
  state.page = Math.max(1, Math.min(state.page, pages));
  const elements = list.slice((state.page - 1) * 24, state.page * 24).map(item => {
    const button = document.createElement('button');
    button.className = 'material-item'; button.dataset.id = item.id; button.dataset.source = item.asset_source || 'legacy';
    button.title = `${item.name} · ${item.subcategory || item.category} · 占地 ${item.footprint.width}×${item.footprint.height}`;
    const image = Object.assign(document.createElement('img'), { src: item.asset || 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', alt: '', draggable: false, loading: 'lazy' });
    button.append(image, Object.assign(document.createElement('span'), { className: 'item-name', textContent: item.name }), Object.assign(document.createElement('span'), { className: 'remaining' }));
    button.addEventListener('click', () => {
      if (!state.graylisted) { notice('共享小镇正在灰度开放'); return; }
      if (item.asset_source !== 'town' && (state.inventory.get(item.id) ?? 0) <= 0) { notice('这种材料暂无可用库存'); return; }
      state.mode = 'edit'; state.selectedAsset = item.id; state.selectedInstance = null;
      state.scene?.loadItemTexture(item); state.scene?.cameras.main.stopFollow(); state.scene?.cancelDrag(); state.scene?.refreshGhost(); updateUI(); $('game').focus({ preventScroll: true });
    }); return button;
  });
  $('material-list').replaceChildren(...elements);
  if ($('page-label')) $('page-label').textContent = `${state.page} / ${pages} · ${list.length} 项`;
  if ($('page-prev')) $('page-prev').disabled = state.page <= 1;
  if ($('page-next')) $('page-next').disabled = state.page >= pages;
  const subs = [...new Set(townMaterialItems().filter(item => state.category === 'all' || item.category === state.category).map(item => item.subcategory).filter(Boolean))];
  if ($('subcategory')) { const selected = state.subcategory; $('subcategory').replaceChildren(Object.assign(document.createElement('option'), { value: 'all', textContent: '全部子分类' }), ...subs.map(sub => Object.assign(document.createElement('option'), { value: sub, textContent: sub }))); $('subcategory').value = selected; }
  updateUI();
}

function recordHistory() {
  state.history.push(clone(state.layout));
  if (state.history.length > 64) state.history.shift();
}

function markChanged() {
  state.revision++; state.dirty = true;
  if (!state.conflict) state.status = state.saving ? '正在提交上一项操作' : state.authenticated ? '正在提交共享小镇操作' : '请登录后编辑共享小镇';
  host({ type: 'layoutChanged', layout: clone(state.layout), edit_revision: state.revision });
  state.scene?.renderObjects();
  updateUI();
}

function removeSelected() {
  if (!state.selectedInstance) return;
  const object = state.layout.placements.find(value => value.instance_id === state.selectedInstance);
  if (!canEditObject(object)) { notice('只能删除自己放置的物件'); return; }
  const operationId = crypto.randomUUID().replaceAll('-', '');
  recordHistory(); state.layout.placements = state.layout.placements.filter(value => value.instance_id !== state.selectedInstance);
  state.selectedInstance = null; state.scene?.cancelDrag(); markChanged();
  state.pendingActions.set(operationId, { action: 'delete', objectId: object.object_id || object.instance_id });
  host({ type: 'townActionRequested', action: 'delete', payload: { operation_id: operationId, object_id: object.object_id || object.instance_id, object_version: object.object_version || 1 } });
}

function save() { notice('共享小镇的放置、移动和删除会自动保存'); }

function clearKeys() {
  const scene = state.scene;
  if (scene?.keys) for (const key of Object.values(scene.keys)) key.reset();
  scene?.player?.anims.stop();
}

function applyVisibility() {
  clearKeys();
  if (!state.game?.isBooted) return;
  const visible = state.visible && state.hostActive && !document.hidden;
  if (visible) state.game.loop.wake();
  else state.game.loop.sleep();
}

function loadState(payload) {
  if (!state.scene || !state.catalog) { state.pendingState = clone(payload); return; }
  if (!payload?.layout) throw new Error('服务器未返回小镇布局');
  const error = validateLayout(state.catalog, payload.layout);
  if (error) { notice(error); host({ type: 'error', message: error }); return; }
  const previousEpoch = state.epoch;
  state.graylisted = !!(payload.graylisted ?? state.graylisted);
  state.epoch = Number.isSafeInteger(payload.session_epoch) ? payload.session_epoch : state.epoch;
  state.scene.cancelDrag();
  state.layout = clone(payload.layout);
  state.version = Number.isSafeInteger(payload.scene_version) ? payload.scene_version : 0;
  state.authenticated = !!payload.authenticated;
  state.canSave = !!payload.can_save;
  state.conflict = !!payload.conflict;
  state.dirty = !!payload.dirty;
  state.saving = false; state.saveRevision = -1; state.revision = 0; state.history = [];
  state.selectedAsset = null; state.selectedInstance = null;
  state.status = typeof payload.status === 'string' ? payload.status : state.dirty ? '有尚未保存的布局' : state.authenticated ? '云端布局已载入' : '登录后可保存小镇';
  $('account').textContent = payload.account_label || (state.authenticated ? '当前账号的小镇' : '我的小镇 · 未登录');
  state.scene.renderObjects(); state.scene.spawnPlayer();
  if (previousEpoch !== state.epoch) clearKeys();
  renderMaterials();
}

function setSaveStatus(payload) {
  if (typeof payload === 'string') payload = { status: payload };
  if (!payload) return;
  if (Number.isSafeInteger(payload.scene_version)) state.version = payload.scene_version;
  if (typeof payload.can_save === 'boolean') state.canSave = payload.can_save;
  if (typeof payload.conflict === 'boolean') state.conflict = payload.conflict;
  if (typeof payload.authenticated === 'boolean') state.authenticated = payload.authenticated;
  const newerEdits = state.saveRevision >= 0 && state.revision > state.saveRevision;
  if (typeof payload.dirty === 'boolean') state.dirty = payload.dirty || newerEdits;
  if (typeof payload.saving === 'boolean') state.saving = payload.saving;
  else if (payload.dirty === false || payload.can_save === true || payload.conflict || /失败|超时|冲突|登录|取消/.test(payload.status || '')) state.saving = false;
  state.status = newerEdits && payload.dirty === false ? '上一份布局已保存，新修改尚未保存' : payload.status || state.status;
  updateUI();
}

function diagnostics() {
  const scene = state.scene;
  return {
    ready: !!scene && !state.fatal, mode: state.mode, zoom: state.zoom,
    session_epoch: state.epoch, scene_version: state.version, dirty: state.dirty,
    status: state.status, authenticated: state.authenticated, saving: state.saving,
    player: scene?.player ? { x: Math.round(scene.player.x * 100) / 100, y: Math.round(scene.player.y * 100) / 100, tile_x: Math.floor(scene.player.x / 16), tile_y: Math.floor(scene.player.y / 16) } : null,
    layout: state.layout ? clone(state.layout) : null,
    fps: Math.round(state.game?.loop.actualFps || 0), paused: !state.game?.loop.running,
    materials: state.catalog?.items.length || 0, graylisted: state.graylisted, chunks: state.chunks.size, presence: state.presence.size,
    canvas: scene ? { width: scene.scale.width, height: scene.scale.height } : null,
    camera: scene ? { scroll_x: scene.cameras.main.scrollX, scroll_y: scene.cameras.main.scrollY, zoom: scene.cameras.main.zoom } : null
  };
}

window.DeskBoxTownPrototype = {
  loadState, loadWorld, loadChunks, applyChanges, setPresence, setInventory, applyActionResult, setWorld, setChunks, setAssetCatalog, setAssetSheet,
  setSaveStatus,
  setVisibility(visible) { state.hostActive = !!visible; applyVisibility(); },
  setHostActive(active) { state.hostActive = !!active; applyVisibility(); },
  clearKeys,
  snapshot: diagnostics,
  setStage(stage) {
    if (!state.layout || !Number.isInteger(stage) || stage < 1 || stage > 5 || stage === state.layout.demo_stage) return;
    recordHistory(); state.layout.demo_stage = stage; markChanged();
  }
};
Object.defineProperty(window, '__townPrototypeDiagnostics', { get: diagnostics });

class TownScene extends (window.Phaser?.Scene || class {}) {
  constructor() { super('town-prototype'); this.objectImages = new Map(); this.presenceImages = new Map(); this.drag = null; this.pan = null; this.pointerInside = false; this.lastPointer = null; }

  preload() {
    this.failedAssets = [];
    this.load.on('loaderror', file => this.failedAssets.push(file.key));
    const catalog = state.catalog;
    for (const [name, asset] of Object.entries(catalog.terrain)) this.load.image(`terrain-${name}`, asset);
    // Shared decorations are loaded on demand for visible chunks only.
    catalog.tree_stages.forEach((stage, index) => this.load.image(`tree-${index + 1}`, stage.asset));
    this.load.spritesheet('player', catalog.player.asset, { frameWidth: catalog.player.frame_width, frameHeight: catalog.player.frame_height });
  }

  create() {
    if (this.failedAssets.length) { reportError(new Error(`素材加载失败：${this.failedAssets.join('、')}`)); return; }
    state.scene = this;
    this.drawTerrain();
    this.objectShadows = this.add.graphics();
    this.treeShadow = this.add.ellipse(0, 0, 35, 12, 0x294b25, .17);
    this.tree = this.add.image(0, 0, 'tree-5').setOrigin(.5, 1);
    this.selectionGraphics = this.add.graphics().setDepth(10001);
    this.gridGraphics = this.add.graphics().setDepth(10000);
    this.ghost = this.add.image(0, 0, 'tree-1').setOrigin(.5, 1).setAlpha(.62).setDepth(9999).setVisible(false);
    this.footGhost = this.add.graphics().setDepth(10002);
    this.player = this.add.sprite(0, 0, 'player', 0).setOrigin(.5, 1);
    this.playerShadow = this.add.ellipse(0, 0, 11, 5, 0x234223, .22);
    for (const [direction, frames] of Object.entries(state.catalog.player.frames)) this.anims.create({ key: `walk-${direction}`, frames: frames.map(frame => ({ key: 'player', frame })), frameRate: 8, repeat: -1 });
    this.facing = 'down';
    this.keys = this.input.keyboard.addKeys('UP,DOWN,LEFT,RIGHT,W,A,S,D');
    this.input.keyboard.addCapture(['UP', 'DOWN', 'LEFT', 'RIGHT', 'SPACE']);
    this.input.mouse.disableContextMenu();
    this.cameras.main.setBounds(0, 0, state.catalog.map_width * 16, state.catalog.map_height * 16);
    this.cameras.main.setZoom(state.zoom);
    this.cameras.main.roundPixels = true;
    this.cameras.main.startFollow(this.player, true, .12, .12, 0, 48);
    this.scale.on('resize', () => { clearKeys(); this.refreshGhost(); });
    this.input.on('pointerdown', pointer => this.pointerDown(pointer));
    this.input.on('pointermove', pointer => this.pointerMove(pointer));
    this.input.on('pointerup', pointer => this.pointerUp(pointer));
    this.input.on('pointerupoutside', pointer => { this.pointerInside = false; this.cancelDrag(); this.refreshGhost(); });
    this.input.on('gameout', () => { this.pointerInside = false; if (!this.drag) this.refreshGhost(); });
    this.input.on('wheel', (_pointer, _objects, _dx, deltaY) => {
      if (state.modal || !deltaY) return;
      state.zoom = Phaser.Math.Clamp(state.zoom + (deltaY < 0 ? 1 : -1), 1, 4);
      this.cameras.main.setZoom(state.zoom); $('zoom-label').textContent = `${state.zoom}×`;
    });
    this.renderObjects(); this.renderPresence(); this.spawnPlayer(); this.drawGrid();
    $('loading').hidden = true;
    renderMaterials();
    if (state.pendingState) { const payload = state.pendingState; state.pendingState = null; loadState(payload); }
    host({ type: 'ready', shared: true }); host({ type: 'worldRequested' });
    applyVisibility();
  }

  drawTerrain() {
    const size = 16, width = state.catalog.map_width * size, height = state.catalog.map_height * size;
    this.add.tileSprite(0, 0, width, height, 'terrain-grass').setOrigin(0).setDepth(-100);
    const g = this.add.graphics().setDepth(-90), world = state.catalog.world || {};
    const road = (x, y, w, h, color = 0xd6c184) => { g.fillStyle(color, 1); g.fillRect(x * size, y * size, w * size, h * size); };
    road(88, 0, 16, 192); road(0, 88, 192, 16); road(64, 94, 40, 4, 0xe1cf91);
    const river = world.river;
    if (river) { g.fillStyle(0x6faec0, 1); g.fillRect(river.x * size, river.y * size, river.width * size, river.height * size); g.fillStyle(0x9ed0c9, 1); g.fillRect((river.x - 2) * size, 0, 2 * size, height); g.fillRect((river.x + river.width) * size, 0, 2 * size, height); }
    const bridge = world.bridge;
    if (bridge) { g.fillStyle(0x9b6f48, 1); g.fillRect(bridge.x * size, bridge.y * size, bridge.width * size, bridge.height * size); for (let x = bridge.x; x < bridge.x + bridge.width; x += 2) { g.lineStyle(1, 0x6f4a34, .55); g.lineBetween(x * size, bridge.y * size, x * size, (bridge.y + bridge.height) * size); } }
    const forests = Array.isArray(world.forest_regions) ? world.forest_regions : world.forest ? [world.forest] : [];
    for (const forest of forests) { g.fillStyle(0x5c854c, .94); g.fillRect(forest.x * size, forest.y * size, forest.width * size, forest.height * size); for (let y = forest.y + 3; y < forest.y + forest.height - 2; y += 6) for (let x = forest.x + 3; x < forest.x + forest.width - 2; x += 6) { g.fillStyle(0x356344, .92); g.fillCircle(x * size, y * size, 8); g.fillStyle(0x7eaa5d, .9); g.fillCircle((x - 1) * size, (y - 1) * size, 5); } }
    const square = world.center_square || { x: 84, y: 84, width: 24, height: 24 }; g.fillStyle(0xe6d4a0, .95); g.fillRect(square.x * size, square.y * size, square.width * size, square.height * size); g.lineStyle(2, 0xa88e5c, .65); g.strokeRect(square.x * size, square.y * size, square.width * size, square.height * size);
  }

  spawnPlayer() {
    const spawn = state.catalog.world.spawn;
    this.player.setPosition((spawn.x + spawn.width / 2) * 16, (spawn.y + spawn.height / 2) * 16);
    this.player.setFrame(state.catalog.player.frames.down[0]);
    this.cameras.main.centerOn(this.player.x, this.player.y);
    this.syncPlayerDepth(); clearKeys();
  }

  syncPlayerDepth() {
    this.player.setDepth(this.player.y + .1);
    this.playerShadow.setPosition(this.player.x, this.player.y - 1).setDepth(this.player.y - .2);
  }

  loadItemTexture(item) {
    const key = `item-${item.id}`;
    if (this.textures.exists(key)) return true;
    if (!item.asset) return false;
    this.loadingItems ||= new Set();
    if (!this.loadingItems.has(item.id)) {
      this.loadingItems.add(item.id);
      this.load.image(key, item.asset);
      this.load.once('complete', () => { this.renderObjects(); this.refreshGhost(); });
      this.load.start();
    }
    return false;
  }

  renderObjects() {
    if (!state.layout || !this.tree) return;
    const ids = new Set(state.layout.placements.map(item => item.instance_id));
    for (const [id, image] of this.objectImages) if (!ids.has(id)) { image.destroy(); this.objectImages.delete(id); }
    this.objectShadows.clear();
    for (const placement of state.layout.placements) {
      const item = catalogItem(placement.asset_id);
      if (!item || !this.loadItemTexture(item)) continue;
      let image = this.objectImages.get(placement.instance_id);
      if (!image) { image = this.add.image(0, 0, `item-${item.id}`).setOrigin(.5, 1); this.objectImages.set(placement.instance_id, image); }
      const x = (placement.tile_x + item.footprint.width / 2) * 16;
      const y = (placement.tile_y + item.footprint.height) * 16;
      image.setTexture(`item-${item.id}`).setPosition(x, y).setDisplaySize(item.display_width, item.display_height).setDepth(y).setAlpha(1).clearTint();
      if (item.category === 'building') {
        this.objectShadows.fillStyle(0x2f4928, .12);
        this.objectShadows.fillEllipse(x, y - 3, item.footprint.width * 16 + 7, Math.max(8, item.footprint.height * 8));
      }
    }
    this.objectShadows.setDepth(-10);
    const area = state.catalog.world.main_tree || state.catalog.world.tree, stage = state.catalog.tree_stages[state.layout.demo_stage - 1];
    const treeX = (area.x + area.width / 2) * 16, treeY = (area.y + area.height) * 16;
    this.tree.setTexture(`tree-${state.layout.demo_stage}`).setPosition(treeX, treeY).setDisplaySize(stage.width, stage.height).setDepth(treeY);
    this.treeShadow.setPosition(treeX, treeY - 3).setDepth(treeY - .5).setScale(state.layout.demo_stage <= 2 ? .35 : state.layout.demo_stage === 3 ? .5 : 1);
    this.drawSelection(); this.drawGrid(); this.refreshGhost();
  }

  renderPresence() {
    for (const value of this.presenceImages.values()) value.destroy();
    this.presenceImages.clear();
    for (const [id, player] of state.presence) {
      if (id === currentOwner()) continue;
      const x = (Number(player.tile_x) + .5) * 16, y = (Number(player.tile_y) + 1) * 16;
      const avatar = this.add.sprite(x, y, 'player', 0).setOrigin(.5, 1).setDepth(y + 1);
      const label = this.add.text(x, y - 36, player.display_name || player.account_label || '玩家', { fontFamily: 'Microsoft YaHei', fontSize: '10px', color: '#fffef0', backgroundColor: '#294432cc', padding: { x: 3, y: 2 } }).setOrigin(.5, 1).setDepth(y + 2);
      this.presenceImages.set(id, this.add.container(0, 0, [avatar, label]));
    }
  }

  drawGrid() {
    if (!this.gridGraphics) return;
    this.gridGraphics.clear();
    if (state.mode !== 'edit') return;
    this.gridGraphics.lineStyle(.5, 0x3e5c38, .18);
    for (let i = 0; i <= state.catalog.map_width; i++) this.gridGraphics.lineBetween(i * 16, 0, i * 16, state.catalog.map_height * 16);
    for (let i = 0; i <= state.catalog.map_height; i++) this.gridGraphics.lineBetween(0, i * 16, state.catalog.map_width * 16, i * 16);
  }

  drawSelection() {
    this.selectionGraphics.clear();
    if (state.mode !== 'edit' || !state.selectedInstance || this.drag) return;
    const placement = state.layout.placements.find(item => item.instance_id === state.selectedInstance);
    if (!placement) return;
    const item = catalogItem(placement.asset_id);
    this.selectionGraphics.lineStyle(1, 0xfff8cb, 1);
    this.selectionGraphics.strokeRect(placement.tile_x * 16, placement.tile_y * 16, item.footprint.width * 16, item.footprint.height * 16);
  }

  itemAt(x, y) {
    const values = [...state.layout.placements].filter(value => this.objectImages.has(value.instance_id)).sort((a, b) => this.objectImages.get(b.instance_id).depth - this.objectImages.get(a.instance_id).depth);
    for (const placement of values) {
      const image = this.objectImages.get(placement.instance_id);
      if (!image.getBounds().contains(x, y)) continue;
      const textureX = Math.floor((x - image.getTopLeft().x) / image.scaleX);
      const textureY = Math.floor((y - image.getTopLeft().y) / image.scaleY);
      const alpha = this.textures.getPixelAlpha(textureX, textureY, image.texture.key);
      if (alpha === null || alpha > 24) return placement;
    }
    return null;
  }

  worldPoint(pointer) { return this.cameras.main.getWorldPoint(pointer.x, pointer.y); }

  pointerDown(pointer) {
    $('game').focus({ preventScroll: true });
    if (state.mode !== 'edit' || state.modal || !state.graylisted) return;
    if (pointer.rightButtonDown()) {
      this.cancelDrag();
      this.pan = { x: pointer.x, y: pointer.y, scrollX: this.cameras.main.scrollX, scrollY: this.cameras.main.scrollY };
      return;
    }
    if (!pointer.leftButtonDown()) return;
    const world = this.worldPoint(pointer); this.pointerInside = true; this.lastPointer = world;
    if (state.selectedAsset) {
      const position = this.placementPosition(world, state.selectedAsset);
      const error = validatePlacement(state.catalog, state.layout, state.selectedAsset, position.x, position.y, null, this.player);
      if (error) { notice(error); return; }
      recordHistory();
      const operationId = crypto.randomUUID().replaceAll('-', ''), optimisticId = crypto.randomUUID().replaceAll('-', '');
      const object = { instance_id: optimisticId, object_id: optimisticId, asset_id: state.selectedAsset, decoration_id: state.selectedAsset, owner_id: currentOwner(), tile_x: position.x, tile_y: position.y, object_version: 1 };
      state.layout.placements.push(object);
      markChanged();
      state.pendingActions.set(operationId, { action: 'place', optimisticId });
      host({ type: 'townActionRequested', action: 'place', payload: { operation_id: operationId, asset_id: object.asset_id, tile_x: object.tile_x, tile_y: object.tile_y, chunk_x: Math.floor(object.tile_x / 24), chunk_y: Math.floor(object.tile_y / 24) } });
      this.refreshGhost();
      return;
    }
    const selected = this.itemAt(world.x, world.y);
    state.selectedInstance = selected?.instance_id || null;
    if (selected && canEditObject(selected)) {
      this.drag = { id: selected.instance_id, startX: pointer.x, startY: pointer.y, offsetX: Math.floor(world.x / 16) - selected.tile_x, offsetY: Math.floor(world.y / 16) - selected.tile_y, moved: false, tileX: selected.tile_x, tileY: selected.tile_y, valid: true };
    } else if (selected) notice('只能移动自己放置的物件');
    this.drawSelection(); updateUI();
  }

  placementPosition(world, assetId) {
    const item = catalogItem(assetId);
    return { x: Math.floor(world.x / 16) - Math.floor(item.footprint.width / 2), y: Math.floor(world.y / 16) - item.footprint.height + 1 };
  }

  pointerMove(pointer) {
    if (this.pan && pointer.isDown) {
      this.cameras.main.setScroll(this.pan.scrollX - (pointer.x - this.pan.x) / state.zoom, this.pan.scrollY - (pointer.y - this.pan.y) / state.zoom);
      this.ghost.setVisible(false); this.footGhost.clear();
      return;
    }
    const world = this.worldPoint(pointer); this.pointerInside = true; this.lastPointer = world;
    if (this.drag && pointer.isDown) {
      const placement = state.layout.placements.find(item => item.instance_id === this.drag.id);
      if (!placement) { this.cancelDrag(); return; }
      this.drag.moved ||= Math.hypot(pointer.x - this.drag.startX, pointer.y - this.drag.startY) > 4;
      if (this.drag.moved) {
        this.drag.tileX = Math.floor(world.x / 16) - this.drag.offsetX;
        this.drag.tileY = Math.floor(world.y / 16) - this.drag.offsetY;
        this.drag.error = validatePlacement(state.catalog, state.layout, placement.asset_id, this.drag.tileX, this.drag.tileY, placement.instance_id, this.player);
        this.drag.valid = !this.drag.error;
        const item = catalogItem(placement.asset_id);
        this.objectImages.get(placement.instance_id).setPosition((this.drag.tileX + item.footprint.width / 2) * 16, (this.drag.tileY + item.footprint.height) * 16).setAlpha(.65).setTint(this.drag.valid ? 0xffffff : 0xef6666);
      }
    }
    this.refreshGhost();
  }

  pointerUp() {
    if (this.pan) { this.pan = null; return; }
    if (!this.drag) return;
    const drag = this.drag; this.drag = null;
    if (drag.moved) {
      if (drag.valid) {
        const placement = state.layout.placements.find(item => item.instance_id === drag.id);
        if (placement && (placement.tile_x !== drag.tileX || placement.tile_y !== drag.tileY)) { recordHistory(); placement.tile_x = drag.tileX; placement.tile_y = drag.tileY; placement.chunk_x = Math.floor(drag.tileX / 24); placement.chunk_y = Math.floor(drag.tileY / 24); const operationId = crypto.randomUUID().replaceAll('-', ''); state.pendingActions.set(operationId, { action: 'move', objectId: placement.object_id || placement.instance_id }); markChanged(); host({ type: 'townActionRequested', action: 'move', payload: { operation_id: operationId, object_id: placement.object_id || placement.instance_id, object_version: placement.object_version || 1, tile_x: placement.tile_x, tile_y: placement.tile_y, chunk_x: placement.chunk_x, chunk_y: placement.chunk_y } }); }
      } else notice(drag.error || '这里无法放置');
    }
    this.renderObjects();
  }

  cancelDrag() {
    this.pan = null;
    if (!this.drag) return;
    const placement = state.layout?.placements.find(item => item.instance_id === this.drag.id);
    this.drag = null;
    if (placement) {
      const item = catalogItem(placement.asset_id);
      this.objectImages.get(placement.instance_id)?.setPosition((placement.tile_x + item.footprint.width / 2) * 16, (placement.tile_y + item.footprint.height) * 16).setAlpha(1).clearTint();
    }
    this.refreshGhost(); this.drawSelection();
  }

  refreshGhost() {
    if (!this.ghost) return;
    this.ghost.setVisible(false); this.footGhost.clear();
    if (state.mode !== 'edit' || !this.pointerInside || !this.lastPointer) return;
    let item, x, y, error;
    if (this.drag?.moved) {
      const placement = state.layout.placements.find(value => value.instance_id === this.drag.id);
      item = catalogItem(placement.asset_id);
      x = this.drag.tileX; y = this.drag.tileY; error = this.drag.error;
    } else if (state.selectedAsset) {
      item = catalogItem(state.selectedAsset);
      ({ x, y } = this.placementPosition(this.lastPointer, item.id));
      error = validatePlacement(state.catalog, state.layout, item.id, x, y, null, this.player);
      this.ghost.setTexture(`item-${item.id}`).setDisplaySize(item.display_width, item.display_height).setPosition((x + item.footprint.width / 2) * 16, (y + item.footprint.height) * 16).setTint(error ? 0xef7777 : 0xffffff).setVisible(true);
    }
    if (!item) return;
    this.footGhost.fillStyle(error ? 0xb94937 : 0x5a8a49, .25);
    this.footGhost.lineStyle(1, error ? 0xf0a28a : 0xf5f3b2, .95);
    this.footGhost.fillRect(x * 16, y * 16, item.footprint.width * 16, item.footprint.height * 16);
    this.footGhost.strokeRect(x * 16, y * 16, item.footprint.width * 16, item.footprint.height * 16);
  }

  update(time, delta) {
    if (!this.player || state.fatal) return;
    const focusedControl = document.activeElement?.matches('input,textarea,select,[contenteditable]');
    const enabled = state.mode === 'roam' && state.visible && state.hostActive && !document.hidden && !state.modal && !focusedControl;
    let dx = 0, dy = 0;
    if (enabled) {
      dx = Number(this.keys.RIGHT.isDown || this.keys.D.isDown) - Number(this.keys.LEFT.isDown || this.keys.A.isDown);
      dy = Number(this.keys.DOWN.isDown || this.keys.S.isDown) - Number(this.keys.UP.isDown || this.keys.W.isDown);
    }
    if (dx || dy) {
      const distance = 68 * Math.min(delta, 50) / 1000 / Math.hypot(dx, dy);
      const nextX = this.player.x + dx * distance, nextY = this.player.y + dy * distance;
      if (playerCanStand(state.catalog, state.layout, nextX, this.player.y)) this.player.x = nextX;
      if (playerCanStand(state.catalog, state.layout, this.player.x, nextY)) this.player.y = nextY;
      const direction = Math.abs(dy) >= Math.abs(dx) && dy ? dy > 0 ? 'down' : 'up' : dx > 0 ? 'right' : 'left';
      this.facing = direction; this.player.play(`walk-${direction}`, true);
    } else { this.player.anims.stop(); this.player.setFrame(state.catalog.player.frames[this.facing][0]); }
    this.syncPlayerDepth();
    $('coordinates').textContent = `${Math.floor(this.player.x / 16)}, ${Math.floor(this.player.y / 16)}`;
    if (!reducedMotion.matches && this.water) this.water.tilePositionX = Math.floor(time / 700) % 16;
    if (state.graylisted && (!state.lastChangesAt || Date.now() - state.lastChangesAt > 3000)) { state.lastChangesAt = Date.now(); host({ type: 'changesRequested', cursor: state.cursor }); }
    if (state.graylisted && (!state.lastHeartbeatAt || Date.now() - state.lastHeartbeatAt > 10000)) { state.lastHeartbeatAt = Date.now(); host({ type: 'presenceHeartbeat', payload: { tile_x: Math.floor(this.player.x / 16), tile_y: Math.floor(this.player.y / 16), chunk_x: Math.floor(this.player.x / 384), chunk_y: Math.floor(this.player.y / 384) } }); }
    if (state.graylisted && (!state.lastViewportAt || Date.now() - state.lastViewportAt > 1200)) { state.lastViewportAt = Date.now(); const view = this.cameras.main.worldView, cx = Math.floor((view.x + view.width / 2) / 384), cy = Math.floor((view.y + view.height / 2) / 384), chunks = []; unloadFarChunks(cx, cy); for (let yy = cy - 1; yy <= cy + 1; yy++) for (let xx = cx - 1; xx <= cx + 1; xx++) if (xx >= 0 && xx < 8 && yy >= 0 && yy < 8) chunks.push({ chunk_x: xx, chunk_y: yy }); host({ type: 'viewportChanged', chunks }); }
  }
}

function objectFromRemote(value) {
  return { ...value, instance_id: value.instance_id || value.object_id, object_id: value.object_id || value.instance_id, asset_id: value.asset_id || value.decoration_id, tile_x: Number(value.tile_x), tile_y: Number(value.tile_y), owner_id: value.owner_id || value.owner_user_id || value.owner || '', object_version: Number(value.object_version || value.version || 1) };
}
function syncObjectLayout() {
  state.layout = { ...(state.layout || {}), template_version: 2, catalog_version: state.catalog?.version || 4, world_id: 'main', placements: [...state.objects.values()], objects: [...state.objects.values()] };
}
function loadWorld(payload) {
  if (!payload) return;
  const incomingEpoch = Number.isSafeInteger(payload.session_epoch) ? payload.session_epoch : state.epoch;
  if (incomingEpoch !== state.epoch) { state.chunks.clear(); state.objects.clear(); state.presence.clear(); state.inventory.clear(); state.pendingActions.clear(); state.assetSheets.clear(); state.townAssets.clear(); state.layout = { template_version: 2, catalog_version: 4, world_id: 'main', demo_stage: 5, placements: [] }; state.selectedAsset = null; state.selectedInstance = null; clearKeys(); state.scene?.renderObjects(); state.scene?.renderPresence(); }
  state.epoch = incomingEpoch;
  state.world = payload;
  const meta = payload.world && typeof payload.world === 'object' ? payload.world : payload;
  state.graylisted = !!(payload.enabled ?? meta.enabled ?? payload.graylisted ?? meta.graylisted ?? false);
  state.authenticated = !!(payload.authenticated ?? meta.authenticated ?? state.authenticated);
  state.canSave = state.graylisted && state.authenticated;
  // The v2 endpoint is authoritative for catalog metadata, while local assets
  // provide the packaged image paths. Merge by stable id so old clients and
  // partial server catalogs remain renderable.
  if (Array.isArray(payload.catalog) && payload.catalog.length) {
    const local = new Map((state.catalog?.items || []).map(item => [item.id, item]));
    state.catalog.items = payload.catalog.map(item => ({ ...(local.get(item.id) || {}), ...item, id: item.id, asset: item.asset || local.get(item.id)?.asset }));
    state.catalog.version = Number(payload.catalog_version || state.catalog.version || 4);
  }
  if (Number.isInteger(meta.map_width)) state.catalog.map_width = meta.map_width;
  if (Number.isInteger(meta.map_height)) state.catalog.map_height = meta.map_height;
  if (Number.isInteger(meta.tile_size)) state.catalog.tile_size = meta.tile_size;
  if (Number.isInteger(meta.chunk_size)) state.catalog.chunk_size = meta.chunk_size;
  if (Number.isSafeInteger(payload.scene_version)) state.version = payload.scene_version;
  if (payload.inventory) setInventory(payload);
  if (payload.presence) setPresence(payload);
  if (payload.layout) loadState(payload);
  if (payload.chunks) loadChunks(payload);
  state.status = state.graylisted ? '共享小镇已开放，正在加载区块' : '当前账号尚未开放共享小镇';
  updateUI();
  if (state.graylisted) host({ type: 'viewportChanged', chunks: [{ chunk_x: 3, chunk_y: 3 }, { chunk_x: 4, chunk_y: 3 }, { chunk_x: 3, chunk_y: 4 }, { chunk_x: 4, chunk_y: 4 }] });
}
function loadChunks(payload) {
  const chunks = Array.isArray(payload) ? payload : payload?.chunks || [];
  for (const chunk of chunks) {
    const key = `${chunk.chunk_x},${chunk.chunk_y}`;
    state.chunks.set(key, chunk);
    for (const value of chunk.objects || []) state.objects.set(value.object_id || value.instance_id, objectFromRemote(value));
  }
  if (Number.isSafeInteger(payload?.next_cursor)) state.cursor = payload.next_cursor;
  else if (Number.isSafeInteger(payload?.cursor)) state.cursor = payload.cursor;
  syncObjectLayout(); state.scene?.renderObjects(); updateUI();
}
function unloadFarChunks(centerX, centerY) {
  for (const [key, chunk] of state.chunks) {
    if (Math.abs(Number(chunk.chunk_x) - centerX) <= 2 && Math.abs(Number(chunk.chunk_y) - centerY) <= 2) continue;
    for (const value of chunk.objects || []) { const id = value.object_id || value.instance_id; state.objects.delete(id); state.layout?.placements && (state.layout.placements = state.layout.placements.filter(item => (item.object_id || item.instance_id) !== id)); }
    state.chunks.delete(key);
  }
}
function applyChanges(payload) {
  for (const change of payload?.changes || payload?.events || []) {
    const id = change.object_id;
    if (change.action === 'delete' || change.type === 'delete') state.objects.delete(id);
    else state.objects.set(id, objectFromRemote(change.object || change));
  }
  if (Number.isSafeInteger(payload?.next_cursor)) state.cursor = payload.next_cursor;
  else if (Number.isSafeInteger(payload?.cursor)) state.cursor = payload.cursor;
  state.dirty = false; state.saving = false; state.status = '共享小镇已同步';
  syncObjectLayout(); state.scene?.renderObjects(); updateUI();
}
function setPresence(payload) {
  state.presence.clear();
  for (const value of (Array.isArray(payload?.presence) ? payload.presence : payload?.presence?.players || payload?.players || [])) state.presence.set(value.account_id || value.user_id || value.id, value);
  state.scene?.renderPresence?.();
  if ($('online-count')) $('online-count').textContent = `${state.presence.size} 人在线`;
}
function setInventory(payload) {
  state.inventory.clear();
  for (const value of (Array.isArray(payload?.inventory) ? payload.inventory : payload?.inventory?.items || payload?.items || [])) {
    const id = value.item_id || value.asset_id || value.decoration_id;
    if (!id) continue;
    // The shared-town API reports the total inventory together with the
    // quantity already reserved by the personal tree/town.  Placement must
    // use the available amount, while older responses only contain quantity.
    const available = value.available_quantity ?? value.available ?? value.quantity;
    state.inventory.set(id, Math.max(0, Number(available) || 0));
  }
  renderMaterials();
}
function applyActionResult(payload) {
  if (!payload) return;
  const operationId = payload.operation_id || payload.operation || null;
  let pending = operationId ? state.pendingActions.get(operationId) : null;
  if (!pending && state.pendingActions.size) pending = state.pendingActions.values().next().value;
  const failed = payload.ok === false || !!payload.code;
  if (failed) {
    // The map is edited optimistically so placement feels immediate. The
    // authoritative error must restore the previous remote object state.
    if (pending?.action === 'place' && pending.optimisticId) {
      state.layout.placements = state.layout.placements.filter(value => value.instance_id !== pending.optimisticId);
    }
    if (pending?.action === 'move' && pending.objectId) {
      const remote = state.objects.get(pending.objectId);
      if (remote) state.layout.placements = state.layout.placements.map(value => value.instance_id === pending.objectId ? { ...remote } : value);
    }
    if (pending?.action === 'delete' && pending.objectId && state.objects.has(pending.objectId)) {
      const remote = state.objects.get(pending.objectId);
      if (!state.layout.placements.some(value => value.instance_id === pending.objectId)) state.layout.placements.push({ ...remote });
    }
    if (pending) state.pendingActions.delete(operationId || [...state.pendingActions.entries()].find(([, value]) => value === pending)?.[0]);
  } else {
    if (payload.object) {
      const object = objectFromRemote(payload.object), remoteId = object.object_id || object.instance_id;
      if (pending?.action === 'place' && pending.optimisticId) state.objects.delete(pending.optimisticId);
      state.objects.set(remoteId, object);
    }
    if (payload.deleted_object_id || (payload.action === 'delete' && pending?.objectId)) state.objects.delete(payload.deleted_object_id || pending.objectId);
    if (pending) state.pendingActions.delete(operationId || [...state.pendingActions.entries()].find(([, value]) => value === pending)?.[0]);
  }
  if (payload.inventory) setInventory(payload.inventory);
  if (Number.isSafeInteger(payload.scene_version)) state.version = payload.scene_version;
  state.saving = false; state.conflict = !!payload.conflict || payload.code === 'object_conflict' || payload.code === 'position_conflict'; state.dirty = failed ? state.dirty : false; state.status = failed ? (payload.message || payload.code || '共享小镇操作失败，请稍后重试') : '共享小镇操作已自动保存';
  syncObjectLayout(); state.scene?.renderObjects(); updateUI();
}

function setWorld(payload) { loadWorld(payload); }
function setChunks(payload) { loadChunks(payload); }
function setAssetCatalog(payload) {
  const items = Array.isArray(payload?.items) ? payload.items : [];
  state.townAssets.clear();
  for (const raw of items) {
    if (!raw?.id || !String(raw.id).startsWith('town-source-')) continue;
    const item = { ...raw, id: raw.id, asset_source: 'town', asset: null };
    state.townAssets.set(item.id, item);
  }
  const legacy = state.legacyItems.length ? state.legacyItems : (state.catalog?.items?.filter(item => item.asset_source !== 'town') || []);
  if (state.catalog) state.catalog.items = [...legacy, ...state.townAssets.values()];
  for (const sheet of (payload?.sheets || [])) if (sheet?.sheet_id && !state.assetSheets.has(sheet.sheet_id)) host({ type: 'townAssetSheetRequested', sheet_id: sheet.sheet_id });
  renderMaterials();
}
function setAssetSheet(payload) {
  if (!payload?.sheet_id || typeof payload.url !== 'string') return;
  state.assetSheets.set(payload.sheet_id, payload.url);
  const image = new Image();
  image.onload = () => {
    for (const item of state.townAssets.values()) {
      if (item.sheet_id !== payload.sheet_id) continue;
      const frame = Array.isArray(item.frames) && item.frames.length ? item.frames[0] : item;
      const sourceX = Number(frame.x ?? item.x), sourceY = Number(frame.y ?? item.y);
      const sourceWidth = Number(frame.w ?? frame.width ?? item.width);
      const sourceHeight = Number(frame.h ?? frame.height ?? item.height);
      const canvas = document.createElement('canvas'); canvas.width = sourceWidth; canvas.height = sourceHeight;
      const context = canvas.getContext('2d'); if (!context) continue;
      context.imageSmoothingEnabled = false;
      context.drawImage(image, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, sourceWidth, sourceHeight);
      if (item.frames?.length && item.display_width === item.width && item.display_height === item.height) {
        item.display_width = sourceWidth;
        item.display_height = sourceHeight;
      }
      item.asset = canvas.toDataURL('image/png');
    }
    renderMaterials(); state.scene?.renderObjects(); state.scene?.refreshGhost();
  };
  image.src = payload.url;
}
function setupControls() {
  $('search')?.addEventListener('input', event => { state.search = event.target.value; state.page = 1; renderMaterials(); });
  $('subcategory')?.addEventListener('change', event => { state.subcategory = event.target.value; state.page = 1; renderMaterials(); });
  $('page-prev')?.addEventListener('click', () => { state.page--; renderMaterials(); });
  $('page-next')?.addEventListener('click', () => { state.page++; renderMaterials(); });
  $('roam').addEventListener('click', () => { state.mode = 'roam'; state.selectedAsset = null; state.selectedInstance = null; state.scene?.cancelDrag(); clearKeys(); state.scene?.cameras.main.startFollow(state.scene.player, true, .12, .12, 0, 48); state.scene?.drawSelection(); state.scene?.drawGrid(); state.scene?.refreshGhost(); updateUI(); $('game').focus({ preventScroll: true }); });
  $('edit').addEventListener('click', () => { state.mode = 'edit'; clearKeys(); state.scene?.cameras.main.stopFollow(); state.scene?.drawGrid(); updateUI(); $('game').focus({ preventScroll: true }); });
  $('stage').addEventListener('change', () => window.DeskBoxTownPrototype.setStage(Number($('stage').value)));
  $('save').addEventListener('click', save);
  $('delete').addEventListener('click', removeSelected);
  $('undo').addEventListener('click', () => {
    if (!state.history.length) return;
    state.scene?.cancelDrag(); state.layout = state.history.pop(); state.selectedInstance = null; state.selectedAsset = null; markChanged();
  });
  $('reset').addEventListener('click', async () => {
    if (!await confirmAction('恢复示例布局', '当前布置将替换为示例小镇。云端布局会在你点击“保存小镇”后更新。')) return;
    recordHistory(); state.layout = clone(state.initial); state.selectedAsset = null; state.selectedInstance = null;
    state.scene?.cancelDrag(); state.scene?.spawnPlayer(); markChanged(); notice('已恢复示例，尚未保存到云端');
  });
  $('reload').addEventListener('click', async () => {
    if (!state.authenticated) { notice('请先在软件中登录'); return; }
    if (state.dirty && !await confirmAction('重新载入云端布局', '尚未保存的修改将被云端布局替换。')) return;
    host({ type: 'reloadRequested' });
  });
  $('help').addEventListener('click', () => { $('help-panel').hidden = !$('help-panel').hidden; $('help').setAttribute('aria-expanded', String(!$('help-panel').hidden)); });
  window.addEventListener('blur', () => { clearKeys(); state.scene?.cancelDrag(); });
  document.addEventListener('visibilitychange', () => { state.visible = !document.hidden; applyVisibility(); });
  window.addEventListener('keydown', event => {
    if (document.activeElement?.matches('input,textarea,select,[contenteditable]') || state.modal) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); save(); return; }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); $('undo').click(); return; }
    if (state.mode === 'edit' && (event.key === 'Delete' || event.key === 'Backspace')) { event.preventDefault(); removeSelected(); }
    if (event.key === 'Escape') { state.scene?.cancelDrag(); state.selectedAsset = null; state.selectedInstance = null; state.scene?.refreshGhost(); state.scene?.drawSelection(); updateUI(); }
  });
  window.addEventListener('pagehide', () => { clearKeys(); state.game?.destroy(true); });
}

async function readLocalJson(path) {
  const response = await fetch(path, { cache: 'no-store' });
  if (!response.ok) throw new Error(`缺少本地资源：${path}`);
  return await response.json();
}

async function boot() {
  setupControls();
  if (!window.Phaser || !Phaser.VERSION.startsWith('3.90.')) throw new Error('本地地图引擎未加载');
  [state.catalog, state.initial] = await Promise.all([readLocalJson('catalog.json'), readLocalJson('default-layout.json')]);
  if (state.catalog.version !== 4 || state.catalog.tile_size !== 16 || state.catalog.map_width !== 192 || state.catalog.map_height !== 192 || state.catalog.items.length !== 1500 || state.catalog.tree_stages.length !== 5) throw new Error('素材目录版本或地图尺寸不兼容');
  for (const asset of [...Object.values(state.catalog.terrain), state.catalog.player.asset, ...state.catalog.tree_stages.map(stage => stage.asset), ...state.catalog.items.map(item => item.asset)]) if (!/^(?:sprites\/|\.\.\/Decorations\/Source\/)[a-zA-Z0-9_./-]+\.(?:png|svg)$/.test(asset)) throw new Error('素材路径不在原型目录中');
  const error = validateLayout(state.catalog, state.initial);
  if (error) throw new Error(`示例布局无效：${error}`);
  state.layout = clone(state.initial);
  state.status = '等待共享小镇权限与区块数据';
  state.game = new Phaser.Game({
    type: Phaser.AUTO, parent: 'game', backgroundColor: '#8bab56', pixelArt: true, roundPixels: true,
    audio: { noAudio: true }, banner: false, antialias: false,
    scale: { mode: Phaser.Scale.RESIZE, width: $('game').clientWidth, height: $('game').clientHeight },
    input: { mouse: { preventDefaultWheel: true }, keyboard: true },
    render: { pixelArt: true, roundPixels: true, antialias: false },
    fps: { target: 60, smoothStep: true }, scene: TownScene
  });
}

boot().catch(reportError);
