import { clone, validateLayout, validatePlacement, playerCanStand, findStandingPosition } from './town-rules.js';
import { mergePresenceDelta, expirePresence, presencePosition, mergeNpcDelta } from './town-realtime.js';
import { createResidents } from './town-residents.js';
import { objectDepth, splitDisplayMetrics, splitForegroundDepth, TOWN_LAYER_STEP, TOWN_PLAYER_LAYER, TOWN_TERRAIN_DEPTH } from './town-depth.js';

// Shared town scene; the authenticated server owns world and player state.
const $ = id => document.getElementById(id);
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const state = {
  catalog: null, initial: null, layout: null, scene: null, game: null, world: null, assetSheets: new Map(), townAssets: new Map(), chunks: new Map(), objects: new Map(), presence: new Map(), npcs: new Map(), npcCursor: 0, inventory: new Map(), pendingActions: new Map(), cursor: 0, graylisted: false, requestedChunks: new Set(), shared: true,
  mode: 'roam', category: 'all', selectedAsset: null, selectedInstance: null,
  dirty: false, authenticated: false, canSave: false, saving: false, conflict: false,
  version: 0, epoch: 0, history: [], revision: 0, saveRevision: -1,
  visible: !document.hidden, hostActive: true, noticeTimer: null, pendingState: null,
  playerReady: false, awaitingSpawn: false, lastSentPosition: null, wasMoving: false,
  lastPresencePollAt: 0, presencePollPendingUntil: 0, lastNpcPollAt: 0, npcPollPendingUntil: 0,
  status: '正在载入…', fatal: false, realtime: false, zoom: 2, modal: false, search: '', subcategory: 'all', page: 1, legacyItems: [], recentAssets: [], assetImages: new Map(), assetCatalogReleaseVersion: -1, assetCatalogHash: null, sheetStates: new Map(), dialogueBubbles: new Map(), showNpcNames: false, showNpcStates: false, brushMode: false, brushLastTile: null
};

const residents = createResidents({
  host, clearKeys,
  refreshNpc: () => state.scene?.renderNpcs?.(),
  avatarUrl: id => catalogItem(id)?.asset || null,
  locate(id, follow) {
    const scene = state.scene, npc = state.npcs.get(id); if (!scene || !npc) return;
    scene.cameras.main.stopFollow();
    const sprite = scene.npcImages?.get(id), r = npc.runtime || npc;
    scene.cameras.main.centerOn(sprite?.x ?? (Number(r.tile_x)+.5)*16, sprite?.y ?? (Number(r.tile_y)+1)*16);
    if (follow && sprite) scene.cameras.main.startFollow(sprite, true, .15, .15);
  },
  stopFollowing() {
    const scene = state.scene; if (!scene) return;
    scene.cameras.main.stopFollow();
    if (state.mode === 'roam' && scene.player) scene.cameras.main.startFollow(scene.player, true, .12, .12, 0, 48);
  }
});

const townCategoryLabels = {
  character: '人物', animal: '动物', monster: '怪物', terrain: '地形', water: '水面', farmland: '农田',
  fence: '围栏', building: '建筑', tree: '树木', path: '道路', object: '物件', tool: '工具',
  ui: '界面', portrait: '头像', effect: '特效', font: '字体', other: '其他'
};

function catalogItem(id) {
  return state.catalog?.items.find(item => item.id === id) || state.legacyItems.find(item => item.id === id) || null;
}

function visualPosition(item,x,y,foot=item.footprint){
  const contact=item.asset_source==='town'?item.collision:null;
  return {x:(x+foot.width/2)*16+(contact?(.5-contact.x-contact.width/2)*item.display_width:0),y:(y+foot.height)*16+(contact?(1-contact.y-contact.height)*item.display_height:0)};
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

function renderDialogueBubbles() {
  const hostElement = $('town-bubbles'), scene = state.scene;
  if (!hostElement || !scene || !state.catalog || state.fatal) return;
  const statusOnlyDialogues = new Set(['与玩家交谈', '正在与玩家进行交谈', '交谈中', 'player_talk']);
  const candidates = [...state.npcs.values()]
    .filter(npc => npc.enabled !== false && ['talk', 'player_talk'].includes((npc.runtime || npc).state))
    .map(npc => ({ npc, runtime: npc.runtime || npc, id: npc.npc_id || npc.id, text: String((npc.runtime || npc).dialogue || '').trim() }))
    .filter(item => item.id && item.text && !statusOnlyDialogues.has(item.text))
    .sort((a, b) => (a.id === residents.selectedId() ? -1 : 0) - (b.id === residents.selectedId() ? -1 : 0) || a.id.localeCompare(b.id))
    .slice(0, 4);
  const active = new Set(candidates.map(item => item.id));
  for (const [id, entry] of state.dialogueBubbles) {
    if (!active.has(id)) { entry.node.remove(); state.dialogueBubbles.delete(id); }
  }
  const canvas = $('game')?.querySelector('canvas'), viewport = $('map-viewport');
  if (!canvas || !viewport) return;
  const canvasRect = canvas.getBoundingClientRect(), viewportRect = viewport.getBoundingClientRect();
  const camera = scene.cameras.main, internalWidth = Math.max(1, scene.scale.width), internalHeight = Math.max(1, scene.scale.height);
  const occupied = [];
  const now = performance.now();
  for (const item of candidates) {
    let entry = state.dialogueBubbles.get(item.id);
    if (!entry || entry.text !== item.text) {
      entry?.node.remove();
      const node = document.createElement('button'); node.type = 'button'; node.className = 'town-dialogue-bubble';
      node.addEventListener('click', () => residents.choose(item.id));
      state.dialogueBubbles.set(item.id, entry = { node, text: item.text, startedAt: now });
      hostElement.append(node);
    }
    const elapsed = now - entry.startedAt;
    const lifetime = Math.min(6000, Math.max(3000, 2200 + item.text.length * 105));
    if (elapsed >= lifetime) { entry.node.remove(); state.dialogueBubbles.delete(item.id); continue; }
    entry.node.textContent = `${item.npc.name || '村民'}：${item.text}`;
    entry.node.setAttribute('aria-label', `${item.npc.name || '村民'}说：${item.text}。点击查看聊天记录`);
    const sprite = scene.npcImages?.get(item.id), worldX = sprite?.x ?? ((Number(item.runtime.tile_x) + .5) * 16), worldY = sprite?.y ?? ((Number(item.runtime.tile_y) + 1) * 16);
    const px = (worldX - camera.worldView.x) * camera.zoom / internalWidth * canvasRect.width + (canvasRect.left - viewportRect.left);
    const py = (worldY - camera.worldView.y) * camera.zoom / internalHeight * canvasRect.height + (canvasRect.top - viewportRect.top);
    const width = entry.node.offsetWidth, height = entry.node.offsetHeight;
    const offsets = [[0, -height - 8], [0, height + 16], [-width / 2 - 8, -height / 2], [width / 2 + 8, -height / 2]];
    let chosen = offsets[0], bestScore = Number.POSITIVE_INFINITY;
    for (const [ox, oy] of offsets) {
      const left = px + ox - width / 2, top = py + oy - height, right = left + width, bottom = top + height;
      const overflow = Math.max(0, -left) + Math.max(0, -top) + Math.max(0, right - viewportRect.width) + Math.max(0, bottom - viewportRect.height);
      const overlap = occupied.reduce((score, box) => score + Math.max(0, Math.min(right, box.right) - Math.max(left, box.left)) * Math.max(0, Math.min(bottom, box.bottom) - Math.max(top, box.top)), 0);
      const score = overflow * 1000 + overlap;
      if (score < bestScore) { bestScore = score; chosen = [ox, oy]; }
    }
    const left = px + chosen[0], top = py + chosen[1];
    entry.node.style.left = `${left}px`; entry.node.style.top = `${top}px`;
    occupied.push({ left: left - width / 2, right: left + width / 2, top: top - height, bottom: top });
  }
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
function canEditObject(value) { const owner = value?.owner_id || value?.owner_user_id || value?.owner || ''; return !!owner && owner === currentOwner(); }

function updateUI() {
  const workspace = document.querySelector('.workspace');
  workspace?.classList.toggle('mode-roam', state.mode === 'roam');
  workspace?.classList.toggle('mode-edit', state.mode === 'edit');
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
  $('version-label').textContent = state.graylisted ? `共享版本 ${state.version} · ${state.realtime ? '实时连接' : '轮询同步'}` : '共享小镇 · 灰度开放';
  if ($('realtime-label')) $('realtime-label').textContent = state.realtime ? '实时连接' : '轮询同步';
  if ($('realtime-dot')) $('realtime-dot').className = `status-dot ${state.realtime ? 'ok' : 'busy'}`;
  if ($('player-action-label')) $('player-action-label').textContent = state.mode === 'edit' ? '编辑地图' : '漫游小镇';
  if ($('zoom-status')) $('zoom-status').textContent = `${state.zoom}×`;
  const brush = $('brush-toggle');
  if (brush) {
    brush.hidden = state.mode !== 'edit';
    brush.classList.toggle('active', state.brushMode && state.mode === 'edit');
    brush.setAttribute('aria-pressed', String(state.brushMode && state.mode === 'edit'));
  }
  $('save-state').textContent = state.status;
  $('save-state').className = `save-state${state.conflict || state.status.includes('失败') || state.status.includes('超时') ? ' error' : state.dirty ? ' dirty' : state.version > 0 ? ' saved' : ''}`;
  const counts = new Map();
  for (const item of state.layout.placements) counts.set(item.asset_id, (counts.get(item.asset_id) || 0) + 1);
  for (const button of $('material-list').children) {
    const remaining = Math.max(0, state.inventory.get(button.dataset.id) || 0);
    button.classList.toggle('chosen', button.dataset.id === state.selectedAsset);
    button.classList.toggle('depleted', button.dataset.source!=='town' && remaining <= 0);
    button.querySelector('.remaining').textContent = button.dataset.source === 'town' ? '小镇素材' : `可用 ${remaining}`;
    button.setAttribute('aria-pressed', String(button.dataset.id === state.selectedAsset));
  }
  for (const button of document.querySelectorAll('#categories [data-category]')) button.classList.toggle('active', button.dataset.category === state.category);
}

function selectMaterial(item) {
  if (!state.graylisted) { notice('共享小镇正在灰度开放'); return; }
  if (item.asset_source !== 'town' && (state.inventory.get(item.id) ?? 0) <= 0) { notice('这种材料暂无可用库存'); return; }
  state.recentAssets = [item.id, ...state.recentAssets.filter(id => id !== item.id)].slice(0, 6);
  state.mode = 'edit'; state.selectedAsset = item.id; state.selectedInstance = null;
  state.scene?.loadItemTexture(item); state.scene?.cameras.main.stopFollow(); state.scene?.cancelDrag(); state.scene?.refreshGhost(); updateUI(); renderRecentMaterials(); $('game').focus({ preventScroll: true });
}

function renderRecentMaterials() {
  const root = $('recent-materials'), list = $('recent-material-list');
  if (!root || !list) return;
  const items = state.recentAssets.map(id => catalogItem(id)).filter(Boolean);
  root.hidden = !items.length;
  list.replaceChildren(...items.map(item => {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'recent-material-item'; button.title = item.name;
    const image = Object.assign(document.createElement('img'), { src: item.asset || 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', alt: '', draggable: false });
    button.append(image, Object.assign(document.createElement('span'), { textContent: item.name }));
    button.addEventListener('click', () => selectMaterial(item)); return button;
  }));
}

function renderMaterials() {
  renderCategories();
  renderRecentMaterials();
  updateAssetStatus();
  const list = townMaterialItems().filter(item => (state.category === 'all' || item.category === state.category) && (state.subcategory === 'all' || item.subcategory === state.subcategory) && (!state.search || `${item.name} ${item.id}`.toLowerCase().includes(state.search.toLowerCase())));
  const pages = Math.max(1, Math.ceil(list.length / 24));
  state.page = Math.max(1, Math.min(state.page, pages));
  const elements = list.slice((state.page - 1) * 24, state.page * 24).map(item => {
    const button = document.createElement('button');
    button.className = 'material-item'; button.dataset.id = item.id; button.dataset.source = item.asset_source || 'legacy';
    button.title = `${item.name} · ${item.subcategory || item.category} · 占地 ${item.footprint.width}×${item.footprint.height}`;
    const image = Object.assign(document.createElement('img'), { src: item.asset || 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', alt: '', draggable: false, loading: 'lazy' });
    image.classList.toggle('asset-loading', !item.asset);
    button.dataset.loadState = state.sheetStates.get(item.sheet_id)?.status || 'loading';
    image.addEventListener('error', () => { image.classList.remove('asset-loading'); image.classList.add('asset-error'); image.alt = '素材加载失败'; });
    button.append(image, Object.assign(document.createElement('span'), { className: 'item-name', textContent: item.name }), Object.assign(document.createElement('span'), { className: 'remaining' }));
    button.addEventListener('click', () => selectMaterial(item)); return button;
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

function applyObjectLayer() {
  if (!state.selectedInstance) return;
  const placement = state.layout.placements.find(value => value.instance_id === state.selectedInstance);
  if (!canEditObject(placement)) { notice('只能编辑自己放置的物件'); return; }
  const layer = Number($('object-layer')?.value);
  if (!Number.isInteger(layer) || layer < 1 || layer > 6) { notice('素材层级必须是第 1 层到第 6 层'); return; }
  const currentLayer = Math.min(6, Math.max(1, Number(placement.layer || 1)));
  if (layer === currentLayer) { $('object-menu').hidden = true; return; }
  const operationId = crypto.randomUUID().replaceAll('-', '');
  recordHistory();
  placement.layer = layer;
  state.pendingActions.set(operationId, { action: 'move', objectId: placement.object_id || placement.instance_id });
  markChanged();
  $('object-menu').hidden = true;
  host({ type: 'townActionRequested', action: 'move', payload: { operation_id: operationId, object_id: placement.object_id || placement.instance_id, object_version: placement.object_version || 1, tile_x: placement.tile_x, tile_y: placement.tile_y, chunk_x: placement.chunk_x ?? Math.floor(placement.tile_x / 24), chunk_y: placement.chunk_y ?? Math.floor(placement.tile_y / 24), layer } });
  notice(`已提交第 ${layer} 层，正在保存`);
}

function save() { notice('共享小镇的放置、移动和删除会自动保存'); }

function clearKeys() {
  const scene = state.scene;
  if (scene?.keys) for (const key of Object.values(scene.keys)) key.reset();
  scene?.player?.anims.stop();
}

function playerPosition() {
  if(!state.playerReady || state.awaitingSpawn || !state.scene?.player)return null;
  const pixel_x=Math.round(state.scene.player.x*100)/100,pixel_y=Math.round(state.scene.player.y*100)/100;
  return {pixel_x,pixel_y,tile_x:Math.floor(pixel_x/16),tile_y:Math.floor(pixel_y/16),session_epoch:state.epoch};
}

function sendPlayerPresence(force=false) {
  const position=playerPosition();
  if(!position || !state.graylisted || !state.authenticated)return;
  const now=Date.now(),key=`${position.pixel_x}:${position.pixel_y}`,changed=key!==state.lastSentPosition;
  const elapsed=now-(state.lastHeartbeatAt||0);
  if(!force && now<(state.presencePendingUntil||0))return;
  const interval = state.realtime ? 100 : 1000;
  if(!(changed && (force||elapsed>=interval)) && elapsed<10000)return;
  state.lastSentPosition=key;state.lastHeartbeatAt=now;state.presencePendingUntil=now+interval;
  host({type:'presenceHeartbeat',payload:{...position,force}});
}

function applyVisibility() {
  clearKeys();
  sendPlayerPresence(true);
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
  const incomingVersion = payload.scene_version ?? payload.world_version;
  state.version = Number.isSafeInteger(incomingVersion) ? incomingVersion : 0;
  state.authenticated = !!payload.authenticated;
  state.canSave = !!payload.can_save;
  state.conflict = !!payload.conflict;
  state.dirty = !!payload.dirty;
  state.saving = false; state.saveRevision = -1; state.revision = 0; state.history = [];
  state.selectedAsset = null; state.selectedInstance = null;
  state.status = typeof payload.status === 'string' ? payload.status : state.dirty ? '有尚未保存的布局' : state.authenticated ? '云端布局已载入' : '登录后可保存小镇';
  $('account').textContent = payload.account_label || (state.authenticated ? '当前账号的小镇' : '我的小镇 · 未登录');
  state.scene.renderObjects();
  if (previousEpoch !== state.epoch) clearKeys();
  renderMaterials();
}

function setSaveStatus(payload) {
  if (typeof payload === 'string') payload = { status: payload };
  if (!payload) return;
  if (Number.isSafeInteger(payload.scene_version || payload.world_version)) state.version = payload.scene_version || payload.world_version;
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
    selected_instance: state.selectedInstance, moving: !!scene?.drag, pending_count: state.pendingActions.size,
    layout: state.layout ? clone(state.layout) : null,
    fps: Math.round(state.game?.loop.actualFps || 0), paused: !state.game?.loop.running,
    materials: state.catalog?.items.length || 0, graylisted: state.graylisted, chunks: state.chunks.size, presence: state.presence.size, realtime: state.realtime,
    player_ready:state.playerReady&&!state.awaitingSpawn,map:{width:state.catalog?.map_width,height:state.catalog?.map_height},
    canvas: scene ? { width: scene.scale.width, height: scene.scale.height } : null,
    assets: [...state.sheetStates].map(([id, value]) => ({sheet_id:id, status:value.status, width:value.width, height:value.height})),
    textures: [...state.townAssets.values()].map(item => ({id:item.id, ready:!!scene?.textures.exists(`item-${item.id}`), crop:item.asset_frame})),
    camera: scene ? { scroll_x: scene.cameras.main.scrollX, scroll_y: scene.cameras.main.scrollY, zoom: scene.cameras.main.zoom, view: {x:scene.cameras.main.worldView.x,y:scene.cameras.main.worldView.y,width:scene.cameras.main.worldView.width,height:scene.cameras.main.worldView.height} } : null
  };
}

window.DeskBoxTownPrototype = {
  setResidentDetail: residents.detail, setResidentConversations: residents.history,
  setResidentInviteResult: residents.inviteResult,
  loadState, loadWorld, loadChunks, applyChanges, setPresence, setNpcs, applyNpcChanges, applyRealtimeNpc, applyRealtimePresence, applyRealtimeSnapshot, setRealtimeState, setInventory, setAssetCatalog, setAssetSheet, applyActionResult, setWorld, setChunks,
  setSaveStatus,
  setVisibility(visible) { state.hostActive = !!visible; applyVisibility(); },
  setHostActive(active) { state.hostActive = !!active; applyVisibility(); },
  clearKeys,
  playerPosition,
  snapshot: diagnostics,
  setStage(stage) {
    if (!state.layout || !Number.isInteger(stage) || stage < 1 || stage > 5 || stage === state.layout.demo_stage) return;
    recordHistory(); state.layout.demo_stage = stage; markChanged();
  }
};
Object.defineProperty(window, '__townPrototypeDiagnostics', { get: diagnostics });

class TownScene extends (window.Phaser?.Scene || class {}) {
  constructor() { super('town-prototype'); this.objectImages = new Map(); this.objectForegroundImages = new Map(); this.presenceImages = new Map(); this.drag = null; this.pan = null; this.pointerInside = false; this.lastPointer = null; }

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

    const overlayDepth = (state.catalog.map_height + 16) * state.catalog.tile_size + 6 * TOWN_LAYER_STEP + 100;
    this.selectionGraphics = this.add.graphics().setDepth(overlayDepth + 1);
    this.gridGraphics = this.add.graphics().setDepth(overlayDepth);
    this.ghost = this.add.image(0, 0, 'tree-1').setOrigin(.5, 1).setAlpha(.62).setDepth(overlayDepth - 1).setVisible(false);
    this.footGhost = this.add.graphics().setDepth(overlayDepth + 2);
    this.player = this.add.sprite(0, 0, 'player', 0).setOrigin(.5, 1).setVisible(false);
    this.npcImages = new Map();
    this.playerShadow = this.add.ellipse(0, 0, 11, 5, 0x234223, .22).setVisible(false);
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
    this.renderObjects(); this.renderPresence(); this.renderNpcs(); this.drawGrid();
    $('loading').hidden = true;
    renderMaterials();
    if (state.pendingState) { const payload = state.pendingState; state.pendingState = null; loadState(payload); }
    host({ type: 'ready', shared: true }); host({ type: 'worldRequested' });
    applyVisibility();
  }

  drawTerrain() {
    const size = 16, width = state.catalog.map_width * size, height = state.catalog.map_height * size;
    this.add.tileSprite(0, 0, width, height, 'terrain-grass').setOrigin(0).setDepth(TOWN_TERRAIN_DEPTH);
    const g = this.add.graphics().setDepth(TOWN_TERRAIN_DEPTH + 10), world = state.catalog.world || {};
    const road = (x, y, w, h, color = 0xd6c184) => { g.fillStyle(color, 1); g.fillRect(x * size, y * size, w * size, h * size); };
    road(88, 0, 16, state.catalog.map_height); road(0, 88, state.catalog.map_width, 16); road(64, 94, 40, 4, 0xe1cf91);
    const river = world.river;
    if (river) { g.fillStyle(0x6faec0, 1); g.fillRect(river.x * size, river.y * size, river.width * size, river.height * size); g.fillStyle(0x9ed0c9, 1); g.fillRect((river.x - 2) * size, 0, 2 * size, height); g.fillRect((river.x + river.width) * size, 0, 2 * size, height); }
    const bridge = world.bridge;
    if (bridge) { g.fillStyle(0x9b6f48, 1); g.fillRect(bridge.x * size, bridge.y * size, bridge.width * size, bridge.height * size); for (let x = bridge.x; x < bridge.x + bridge.width; x += 2) { g.lineStyle(1, 0x6f4a34, .55); g.lineBetween(x * size, bridge.y * size, x * size, (bridge.y + bridge.height) * size); } }
    const forests = Array.isArray(world.forest_regions) ? world.forest_regions : world.forest ? [world.forest] : [];
    for (const forest of forests) { g.fillStyle(0x5c854c, .94); g.fillRect(forest.x * size, forest.y * size, forest.width * size, forest.height * size); for (let y = forest.y + 3; y < forest.y + forest.height - 2; y += 6) for (let x = forest.x + 3; x < forest.x + forest.width - 2; x += 6) { g.fillStyle(0x356344, .92); g.fillCircle(x * size, y * size, 8); g.fillStyle(0x7eaa5d, .9); g.fillCircle((x - 1) * size, (y - 1) * size, 5); } }
    const square = world.center_square || { x: 84, y: 84, width: 24, height: 24 }; g.fillStyle(0xe6d4a0, .95); g.fillRect(square.x * size, square.y * size, square.width * size, square.height * size); g.lineStyle(2, 0xa88e5c, .65); g.strokeRect(square.x * size, square.y * size, square.width * size, square.height * size);
  }

  spawnPlayer(preferred = playerPosition()) {
    const position = findStandingPosition(state.catalog,state.layout,preferred);
    if (!position) { notice('小镇没有可站立的位置'); return; }
    this.player.setPosition(position.x, position.y).setVisible(true);
    this.playerShadow.setVisible(true); state.playerReady=true;
    this.player.setFrame(state.catalog.player.frames.down[0]);
    this.cameras.main.centerOn(this.player.x, this.player.y);
    this.syncPlayerDepth(); clearKeys();
  }

  syncPlayerDepth() {
    this.player.setDepth(this.player.y + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP + .1);
    this.playerShadow.setPosition(this.player.x, this.player.y - 1).setDepth(this.player.y + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP - .2);
  }

  loadItemTexture(item) {
    if(item.asset_source==='town' && state.sheetStates.get(item.sheet_id)?.status!=='ready')return false;
    const key = `item-${item.id}`;
    if (this.textures.exists(key)) return true;
    if (!item.asset) return false;
    this.loadingItems ||= new Set();
    if (!this.loadingItems.has(item.id)) {
      this.loadingItems.add(item.id);
      this.load.image(key, item.asset);
      this.load.once(`filecomplete-image-${key}`, () => { this.loadingItems.delete(item.id); this.renderObjects(); this.refreshGhost(); });
      this.load.once('loaderror', file => {
        if (file?.key !== key) return;
        this.loadingItems.delete(item.id);
        this.textures.remove(key);
        item.asset = null;
        notice(`素材“${item.name || item.id}”加载失败，请重新选择`);
        host({ type: 'assetError', message: `town_asset_texture_failed:${item.id}` });
        this.renderObjects(); this.refreshGhost();
      });
      this.load.start();
    }
    return false;
  }

  splitCrop(item) {
    return splitDisplayMetrics(item);
  }

  placeSelectedAsset(world) {
    if (!state.selectedAsset) return false;
    const position = this.placementPosition(world, state.selectedAsset);
    const error = validatePlacement(state.catalog, state.layout, state.selectedAsset, position.x, position.y, null, this.player);
    if (error) { if (!state.brushMode) notice(error); return false; }
    const item = catalogItem(state.selectedAsset);
    if (!item || !this.loadItemTexture(item)) { if (!state.brushMode) notice(state.sheetStates.get(item?.sheet_id)?.status === 'error' ? '素材加载失败，请点击“重试素材”' : '素材加载中，请稍候'); return false; }
    const brushKey = `${position.x},${position.y}`;
    if (state.brushMode && state.brushLastTile === brushKey) return false;
    recordHistory();
    const operationId = crypto.randomUUID().replaceAll('-', ''), optimisticId = crypto.randomUUID().replaceAll('-', '');
    const object = { instance_id: optimisticId, object_id: optimisticId, asset_id: state.selectedAsset, decoration_id: state.selectedAsset, owner_id: currentOwner(), tile_x: position.x, tile_y: position.y, layer: 1, object_version: 1 };
    state.layout.placements.push(object);
    state.brushLastTile = brushKey;
    markChanged();
    state.pendingActions.set(operationId, { action: 'place', optimisticId });
    host({ type: 'townActionRequested', action: 'place', payload: { operation_id: operationId, asset_id: object.asset_id, tile_x: object.tile_x, tile_y: object.tile_y, chunk_x: Math.floor(object.tile_x / 24), chunk_y: Math.floor(object.tile_y / 24), layer: object.layer } });
    this.renderObjects();
    return true;
  }

  destroyObjectVisual(instanceId) {
    this.objectImages.get(instanceId)?.destroy();
    this.objectImages.delete(instanceId);
    this.objectForegroundImages.get(instanceId)?.destroy();
    this.objectForegroundImages.delete(instanceId);
  }

  setObjectVisual(placement, item, x, y, alpha = 1, tint = 0xffffff) {
    const image = this.objectImages.get(placement.instance_id);
    if (!image) return;
    const split = this.splitCrop(item);
    image.setPosition(x, split ? y - split.frontHeight : y)
      .setDepth(objectDepth(item, placement, y))
      .setAlpha(alpha).setTint(tint);
    if (split) {
      const foreground = this.objectForegroundImages.get(placement.instance_id);
      foreground?.setPosition(x, y).setDepth(splitForegroundDepth(y)).setAlpha(alpha).setTint(tint);
    }
  }

  createObjectImages(placement, item) {
    let image = this.objectImages.get(placement.instance_id);
    if (!image) {
      image = this.add.image(0, 0, `item-${item.id}`).setOrigin(.5, 1);
      this.objectImages.set(placement.instance_id, image);
    }
    const split = this.splitCrop(item);
    let foreground = this.objectForegroundImages.get(placement.instance_id);
    if (split && !foreground) {
      foreground = this.add.image(0, 0, `item-${item.id}`).setOrigin(.5, 1);
      this.objectForegroundImages.set(placement.instance_id, foreground);
    }
    if (!split && foreground) {
      foreground.destroy();
      this.objectForegroundImages.delete(placement.instance_id);
      foreground = null;
    }
    image.setTexture(`item-${item.id}`).setCrop(0, 0, item.width, split ? split.splitY : item.height);
    if (foreground) {
      foreground.setTexture(`item-${item.id}`).setCrop(0, split.splitY, item.width, item.height - split.splitY);
      foreground.setDisplaySize(item.display_width, split.frontHeight);
    }
    image.setDisplaySize(item.display_width, split ? split.backHeight : item.display_height);
    return { image, foreground, split };
  }

  renderObjects() {
    if (!state.layout || !this.objectShadows) return;
    const ids = new Set(state.layout.placements.map(item => item.instance_id));
    for (const id of this.objectImages.keys()) if (!ids.has(id)) this.destroyObjectVisual(id);
    for (const id of this.objectForegroundImages.keys()) if (!ids.has(id)) this.destroyObjectVisual(id);
    this.objectShadows.clear();
    for (const placement of state.layout.placements) {
      const item = catalogItem(placement.asset_id);
      const bounds = placement.footprint || item?.footprint || { width: 1, height: 1 };
      if (!item || !this.loadItemTexture(item)) {
        this.destroyObjectVisual(placement.instance_id);
        this.objectShadows.lineStyle(1, canEditObject(placement) ? 0xf7e3a2 : 0x777f68, .95);
        this.objectShadows.strokeRect(placement.tile_x*16, placement.tile_y*16, bounds.width*16, bounds.height*16);
        continue;
      }
      const { image } = this.createObjectImages(placement, item);
      const {x,y}=visualPosition(item,placement.tile_x,placement.tile_y,bounds);
      this.setObjectVisual(placement, item, x, y, 1, 0xffffff);
      image.clearTint(); this.objectForegroundImages.get(placement.instance_id)?.clearTint();
      if (item.category === 'building') {
        this.objectShadows.fillStyle(0x2f4928, .12);
        this.objectShadows.fillEllipse(x, y - 3, item.footprint.width * 16 + 7, Math.max(8, item.footprint.height * 8));
      }
    }
    this.objectShadows.setDepth(-10);
    this.drawSelection(); this.drawGrid(); this.refreshGhost();
  }

  renderPresence() {
    const visible = new Set();
    for (const [id, player] of state.presence) {
      if (id === currentOwner()) continue;
      visible.add(id);
      const {x, y} = presencePosition(player);
      let container = this.presenceImages.get(id);
      if (!container) {
        const avatar = this.add.sprite(0, 0, 'player', 0).setOrigin(.5, 1);
        const label = this.add.text(0, -36, player.display_name || player.account_label || '玩家', { fontFamily: 'Microsoft YaHei', fontSize: '10px', color: '#fffef0', backgroundColor: '#294432cc', padding: { x: 3, y: 2 } }).setOrigin(.5, 1);
        container = this.add.container(x, y, [avatar, label]); this.presenceImages.set(id, container);
      }
      container.list[1].setText(player.display_name || player.account_label || '玩家');
      container.setDepth(y + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP + 1);
      if (Math.abs(container.x - x) + Math.abs(container.y - y) > 1) {
        this.tweens.killTweensOf(container);
        const avatar = container.list[0], dx = x - container.x, dy = y - container.y;
        const facing = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up');
        avatar.play(`walk-${facing}`, true);
        this.tweens.add({ targets: container, x, y, duration: state.realtime ? 110 : 280, ease: 'Linear',
          onUpdate: () => container.setDepth(container.y + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP + 1),
          onComplete: () => avatar.anims.stop() });
      } else container.setPosition(x, y);
    }
    for (const [id, value] of this.presenceImages) if (!visible.has(id)) { value.destroy(); this.presenceImages.delete(id); }
  }

  renderNpcs() {
    residents.update(state.npcs);
    const visible = new Set();
    let nearbyId = null, nearbyDistance = Infinity;
    const playerTile = this.player ? { x: this.player.x / 16, y: this.player.y / 16 } : null;
    for (const npc of state.npcs.values()) {
      if (npc.enabled === false) continue;
      const id = npc.npc_id || npc.id;
      if (!id) continue;
      visible.add(id);
      const runtime = npc.runtime || npc;
      const route = Array.isArray(runtime.route) ? runtime.route.filter(point => Number.isFinite(Number(point?.x)) && Number.isFinite(Number(point?.y))) : [];
      const x = (Number(runtime.tile_x || 0) + .5) * 16, y = (Number(runtime.tile_y || 0) + 1) * 16;
      if (playerTile) {
        const distance = Math.hypot(Number(runtime.tile_x || 0) + .5 - playerTile.x, Number(runtime.tile_y || 0) + .5 - playerTile.y);
        if (distance <= 5 && distance < nearbyDistance) { nearbyId = id; nearbyDistance = distance; }
      }
      const item = npc.avatar_asset_id ? catalogItem(npc.avatar_asset_id) : null;
      const textureKey = item && this.textures.exists(`item-${item.id}`) ? `item-${item.id}` : 'player';
      let container = this.npcImages.get(id);
      if (!container) {
        const avatar = this.add.sprite(0, 0, textureKey, 0).setOrigin(.5, 1);
        const label = this.add.text(0, -36, npc.name || '村民', { fontFamily: 'Microsoft YaHei', fontSize: '10px', color: '#fffef0', backgroundColor: '#5b3e39dd', padding: { x: 3, y: 2 } }).setOrigin(.5, 1);
        const stateLabel = this.add.text(0, -49, '', { fontFamily: 'Microsoft YaHei', fontSize: '8px', color: '#fffef0', backgroundColor: '#294432cc', padding: { x: 2, y: 1 } }).setOrigin(.5, 1);
        // Dialogue is rendered by the DOM bubble layer so it can wrap, remain
        // accessible and follow the same visual style as player chat. Keep a
        // hidden Phaser slot for compatibility with existing container indices.
        const bubble = this.add.text(0, -64, '', { fontFamily: 'Microsoft YaHei', fontSize: '9px', color: '#3e2b25', backgroundColor: '#fff8dfdd', padding: { x: 3, y: 2 }, wordWrap: { width: 130 } }).setOrigin(.5, 1).setVisible(false);
        container = this.add.container(x, y, [avatar, label, stateLabel, bubble]);
        this.npcImages.set(id, container);
      }
      const avatar = container.list[0], label = container.list[1], stateLabel = container.list[2], bubble = container.list[3];
      avatar.setTexture(textureKey, 0);
      if (textureKey === 'player') avatar.setTint(0xffd58a); else avatar.clearTint();
      label.setText(npc.name || '村民');
      const stateNames = { walk: '行走', work: '工作', rest: '休息', talk: '交谈', player_talk: '面对面交谈', follow: '跟随', wait: '等待' };
      const stateName = stateNames[runtime.state] || runtime.state || '休息';
      stateLabel.setText(stateName);
      label.setVisible(state.showNpcNames);
      stateLabel.setVisible(state.showNpcStates);
      // A dialogue bubble is a confirmed line of speech, not a replacement
      // for the NPC's state label.  The server only marks a talk task as
      // dialogue-ready after the target is still available, adjacent, and
      // facing the initiator; hide stale text as soon as the NPC leaves talk.
      const dialogue = ['talk', 'player_talk'].includes(runtime.state) && typeof runtime.dialogue === 'string' ? runtime.dialogue.trim() : '';
      const speech = new Set(['与玩家交谈', '正在与玩家进行交谈', '交谈中', 'player_talk']).has(dialogue) ? '' : dialogue;
      bubble.setText('');
      bubble.setVisible(false);
      const depth = y + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP + 3;
      container.setDepth(depth);
      const routeKey = route.length > 1 ? `${runtime.task_id || runtime.current_task_id || ''}:${JSON.stringify(route)}` : '';
      if (routeKey && container.npcRouteKey !== routeKey) {
        container.npcRouteKey = routeKey; this.tweens.killTweensOf(container);
        const first = route[0]; container.setPosition((Number(first.x) + .5) * 16, (Number(first.y) + 1) * 16);
        container.npcMovement?.destroy();
        let previous = first;
        container.npcMovement = this.tweens.chain({ targets: container,
          tweens: route.slice(1).map(point => {
            const dx = Number(point.x) - Number(previous.x), dy = Number(point.y) - Number(previous.y);
            const facing = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up');
            previous = point;
            return { x: (Number(point.x) + .5) * 16, y: (Number(point.y) + 1) * 16, duration: 135, ease: 'Linear',
              onStart: () => { if (textureKey === 'player') avatar.play(`walk-${facing}`, true); },
              onUpdate: () => container.setDepth(container.y + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP + 3) };
          }), onComplete: () => { if (textureKey === 'player') avatar.anims.stop(); }
        });
      } else if (!routeKey) {
        container.npcRouteKey = '';
        if (Math.abs(container.x - x) + Math.abs(container.y - y) > 1) {
          this.tweens.killTweensOf(container);
          this.tweens.add({ targets: container, x, y, duration: Math.min(900, Math.max(220, Math.hypot(container.x - x, container.y - y) * 3)), ease: 'Sine.easeInOut' });
        } else container.setPosition(x, y);
      }
      if (runtime.state === 'work' || runtime.state === 'talk' || runtime.state === 'player_talk') {
        this.tweens.killTweensOf(avatar);
        this.tweens.add({ targets: avatar, y: -2, duration: 420, yoyo: true, repeat: -1, ease: 'Sine.easeInOut' });
      } else this.tweens.killTweensOf(avatar);
    }
    for (const [id, value] of this.npcImages) if (!visible.has(id)) { value.destroy(); this.npcImages.delete(id); }
    residents.nearby(nearbyId, Number.isFinite(nearbyDistance) ? nearbyDistance : null);
    renderDialogueBubbles();
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
    const item = { ...catalogItem(placement.asset_id), footprint: placement.footprint || catalogItem(placement.asset_id)?.footprint || {width:1,height:1} };
    this.selectionGraphics.lineStyle(1, 0xfff8cb, 1);
    this.selectionGraphics.strokeRect(placement.tile_x * 16, placement.tile_y * 16, item.footprint.width * 16, item.footprint.height * 16);
  }

  itemAt(x, y) {
    const depth = placement => Math.max(this.objectImages.get(placement.instance_id)?.depth ?? Number.NEGATIVE_INFINITY, this.objectForegroundImages.get(placement.instance_id)?.depth ?? Number.NEGATIVE_INFINITY);
    const values = [...state.layout.placements].filter(value => this.objectImages.has(value.instance_id) || this.objectForegroundImages.has(value.instance_id)).sort((a, b) => depth(b) - depth(a));
    for (const placement of values) {
      const image = this.objectImages.get(placement.instance_id);
      const foreground = this.objectForegroundImages.get(placement.instance_id);
      const candidates = [];
      if (foreground?.getBounds().contains(x, y)) candidates.push({ visual: foreground, cropY: this.splitCrop(catalogItem(placement.asset_id))?.splitY || 0 });
      if (image?.getBounds().contains(x, y)) candidates.push({ visual: image, cropY: 0 });
      for (const candidate of candidates) {
        const visual = candidate.visual;
        const textureX = Math.floor((x - visual.getTopLeft().x) / visual.scaleX);
        const textureY = candidate.cropY + Math.floor((y - visual.getTopLeft().y) / visual.scaleY);
        const alpha = this.textures.getPixelAlpha(textureX, textureY, visual.texture.key);
        if (alpha === null || alpha > 24) return placement;
      }
    }
    return [...state.layout.placements].reverse().find(value => {
      const foot = value.footprint || catalogItem(value.asset_id)?.footprint || {width:1,height:1};
      return x >= value.tile_x*16 && x < (value.tile_x+foot.width)*16 && y >= value.tile_y*16 && y < (value.tile_y+foot.height)*16;
    }) || null;
  }

  worldPoint(pointer) { return this.cameras.main.getWorldPoint(pointer.x, pointer.y); }

  pointerDown(pointer) {
    $('game').focus({ preventScroll: true });
    if (state.mode !== 'edit' || state.modal || !state.graylisted) return;
    if (pointer.rightButtonDown()) {
      this.cancelDrag();
      const world = this.worldPoint(pointer), object = this.itemAt(world.x, world.y);
      if (object) {
        state.selectedInstance = object.instance_id; state.selectedAsset = null;
        if (!canEditObject(object)) { notice('只能编辑自己放置的物件'); return; }
        const menu = $('object-menu'), pane = $('map-viewport').getBoundingClientRect(), game = $('game').getBoundingClientRect();
        menu.style.left = `${Math.min(pane.width-104, pointer.x+game.left-pane.left)}px`;
         $('object-layer').value = String(Math.min(6, Math.max(1, Number(object.layer || 1))));
         menu.style.top = `${Math.min(pane.height-145, pointer.y+game.top-pane.top)}px`;
        menu.hidden = false; this.drawSelection(); updateUI(); return;
      }
      $('object-menu').hidden = true;
      this.pan = { x: pointer.x, y: pointer.y, scrollX: this.cameras.main.scrollX, scrollY: this.cameras.main.scrollY };
      return;
    }
    if (!pointer.leftButtonDown()) return;
    $('object-menu').hidden = true;
    if (this.drag?.clickMove) { this.pointerUp(true); return; }
    const world = this.worldPoint(pointer); this.pointerInside = true; this.lastPointer = world;
    if (state.selectedAsset) {
      state.brushLastTile = null;
      this.placeSelectedAsset(world);
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
    if (state.selectedAsset && state.brushMode && pointer.isDown) this.placeSelectedAsset(world);
    if (this.drag && (pointer.isDown || this.drag.clickMove)) {
      const placement = state.layout.placements.find(item => item.instance_id === this.drag.id);
      if (!placement) { this.cancelDrag(); return; }
      this.drag.moved ||= Math.hypot(pointer.x - this.drag.startX, pointer.y - this.drag.startY) > 4;
      if (this.drag.moved) {
        this.drag.tileX = Math.floor(world.x / 16) - this.drag.offsetX;
        this.drag.tileY = Math.floor(world.y / 16) - this.drag.offsetY;
        this.drag.error = validatePlacement(state.catalog, state.layout, placement.asset_id, this.drag.tileX, this.drag.tileY, placement.instance_id, this.player);
        this.drag.valid = !this.drag.error;
        const item = catalogItem(placement.asset_id);
         const dragPosition = visualPosition(item, this.drag.tileX, this.drag.tileY, placement.footprint || item.footprint);
         this.setObjectVisual(placement, item, dragPosition.x, dragPosition.y, .65, this.drag.valid ? 0xffffff : 0xef6666);
      }
    }
    this.refreshGhost();
  }

  pointerUp(confirm = false) {
    if (this.drag?.clickMove && confirm !== true) return;
    if (this.pan) { this.pan = null; state.brushLastTile = null; return; }
    if (!this.drag) { state.brushLastTile = null; return; }
    const drag = this.drag; this.drag = null;
    state.brushLastTile = null;
    if (drag.moved) {
      if (drag.valid) {
        const placement = state.layout.placements.find(item => item.instance_id === drag.id);
        if (placement && (placement.tile_x !== drag.tileX || placement.tile_y !== drag.tileY)) { recordHistory(); placement.tile_x = drag.tileX; placement.tile_y = drag.tileY; placement.chunk_x = Math.floor(drag.tileX / 24); placement.chunk_y = Math.floor(drag.tileY / 24); const operationId = crypto.randomUUID().replaceAll('-', ''); state.pendingActions.set(operationId, { action: 'move', objectId: placement.object_id || placement.instance_id }); markChanged(); host({ type: 'townActionRequested', action: 'move', payload: { operation_id: operationId, object_id: placement.object_id || placement.instance_id, object_version: placement.object_version || 1, tile_x: placement.tile_x, tile_y: placement.tile_y, chunk_x: placement.chunk_x, chunk_y: placement.chunk_y, layer: Math.min(6, Math.max(1, Number(placement.layer || 1))) } }); }
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
      const item = { ...catalogItem(placement.asset_id), footprint: placement.footprint || catalogItem(placement.asset_id)?.footprint || {width:1,height:1} };
      const settledPosition = visualPosition(item, placement.tile_x, placement.tile_y, placement.footprint || item.footprint);
      this.setObjectVisual(placement, item, settledPosition.x, settledPosition.y, 1, 0xffffff);
      this.objectImages.get(placement.instance_id)?.clearTint(); this.objectForegroundImages.get(placement.instance_id)?.clearTint();
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
      if (!this.loadItemTexture(item)) return;
      this.ghost.setTexture(`item-${item.id}`).setDisplaySize(item.display_width, item.display_height).setPosition(...Object.values(visualPosition(item,x,y))).setTint(error ? 0xef7777 : 0xffffff).setVisible(true);
    }
    if (!item) return;
    this.footGhost.fillStyle(error ? 0xb94937 : 0x5a8a49, .25);
    this.footGhost.lineStyle(1, error ? 0xf0a28a : 0xf5f3b2, .95);
    this.footGhost.fillRect(x * 16, y * 16, item.footprint.width * 16, item.footprint.height * 16);
    this.footGhost.strokeRect(x * 16, y * 16, item.footprint.width * 16, item.footprint.height * 16);
  }

  update(time, delta) {
    if (!this.player || state.fatal) return;
    const focusedControl = document.activeElement?.matches('input,textarea,select,[contenteditable]') || document.activeElement?.closest('#resident-panel,#resident-strip');
    const enabled = state.playerReady && !state.awaitingSpawn && state.visible && state.hostActive && !document.hidden && !state.modal && !focusedControl;
    let dx = 0, dy = 0;
    if (enabled) {
      dx = Number(this.keys.RIGHT.isDown || this.keys.D.isDown) - Number(this.keys.LEFT.isDown || this.keys.A.isDown);
      dy = Number(this.keys.DOWN.isDown || this.keys.S.isDown) - Number(this.keys.UP.isDown || this.keys.W.isDown);
    }
    if (state.mode === 'edit') {
      if (dx || dy) residents.cancelCamera();
      if (dx || dy) this.cameras.main.setScroll(this.cameras.main.scrollX+dx*240*Math.min(delta,50)/1000/state.zoom, this.cameras.main.scrollY+dy*240*Math.min(delta,50)/1000/state.zoom);
      dx=0;dy=0;
    }
    drawMinimap();
    renderDialogueBubbles();
    for(const item of state.townAssets.values())if(item.decodedFrames?.length>1){
      const frame=Math.floor(time/140)%item.decodedFrames.length;
      if(item.animationFrame===frame)continue;item.animationFrame=frame;
      const texture=this.textures.get(`item-${item.id}`);
      if(texture?.context){texture.context.clearRect(0,0,texture.canvas.width,texture.canvas.height);texture.context.drawImage(item.decodedFrames[frame],0,0,texture.canvas.width,texture.canvas.height);texture.refresh();}
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
    if (state.graylisted && Date.now()>(state.changesPendingUntil||0) && (!state.lastChangesAt || Date.now() - state.lastChangesAt > 3000)) { state.lastChangesAt = Date.now();state.changesPendingUntil=Date.now()+21000; host({ type: 'changesRequested', cursor: state.cursor }); }
    const now = Date.now();
    if (now - (state.lastPresenceExpiryAt || 0) >= 1000) {
      state.lastPresenceExpiryAt = now;
      if (expirePresence(state.presence, now)) { this.renderPresence(); if ($('online-count')) $('online-count').textContent = `${state.presence.size} 人在线`; }
    }
    if (state.graylisted && !state.realtime && now >= state.presencePollPendingUntil && now - state.lastPresencePollAt >= 1000) {
      state.lastPresencePollAt = now; state.presencePollPendingUntil = now + 5000; host({ type: 'presenceRequested' });
    }
    if (state.graylisted && !state.realtime && now >= state.npcPollPendingUntil && now - state.lastNpcPollAt >= 1000) {
      state.lastNpcPollAt = now; state.npcPollPendingUntil = now + 5000; host({ type: 'npcChangesRequested' });
    }
    const moving=!!(dx||dy);
    if (moving) residents.cancelCamera();
    sendPlayerPresence(state.wasMoving && !moving); state.wasMoving=moving;
    if (state.graylisted && state.playerReady && (!state.lastViewportAt || Date.now() - state.lastViewportAt > 1200)) {
      state.lastViewportAt = Date.now(); const view = this.cameras.main.worldView, chunks = [];
      for (let yy = Math.max(0,Math.floor(view.top/384)-1); yy <= Math.min(Math.ceil(state.catalog.map_height/24)-1,Math.floor(view.bottom/384)+1); yy++)
        for (let xx = Math.max(0,Math.floor(view.left/384)-1); xx <= Math.min(Math.ceil(state.catalog.map_width/24)-1,Math.floor(view.right/384)+1); xx++) chunks.push({chunk_x:xx,chunk_y:yy});
      const key = chunks.map(c=>`${c.chunk_x}:${c.chunk_y}`).join(',');
      if (key !== state.viewportKey || Date.now()-(state.lastViewportRequest||0)>15000) { state.viewportKey=key;state.lastViewportRequest=Date.now();host({type:'viewportChanged',chunks}); }
    }
  }
}

function objectFromRemote(value) {
  const rawLayer = Number(value.layer);
  const layer = Number.isInteger(rawLayer) ? Math.min(6, Math.max(1, rawLayer)) : 1;
  return { ...value, instance_id: value.instance_id || value.object_id, object_id: value.object_id || value.instance_id, asset_id: value.asset_id || value.decoration_id, tile_x: Number(value.tile_x), tile_y: Number(value.tile_y), layer, owner_id: value.owner_id || value.owner_user_id || value.owner || '', object_version: Number(value.object_version || value.version || 1) };
}
function syncObjectLayout() {
  for(const value of state.objects.values())if(!catalogItem(value.asset_id))state.catalog.items.push({id:value.asset_id,name:'历史素材',asset_source:value.asset_source,footprint:value.footprint||{width:1,height:1},display_width:(value.footprint?.width||1)*16,display_height:(value.footprint?.height||1)*16});
  state.layout = { ...(state.layout || {}), template_version: 2, catalog_version: state.catalog?.version || 4, world_id: 'main', placements: [...state.objects.values()], objects: [...state.objects.values()] };
}
function loadWorld(payload) {
  if (!payload) return;
  const incomingEpoch = Number.isSafeInteger(payload.session_epoch) ? payload.session_epoch : state.epoch;
  if(incomingEpoch<state.epoch)return;
  if(incomingEpoch!==state.epoch) {
    residents.reset(incomingEpoch);
    state.realtime=false;
    state.playerReady=false;state.awaitingSpawn=false;state.lastSentPosition=null;state.lastHeartbeatAt=0;state.wasMoving=false;
    state.scene?.player?.setVisible(false);state.scene?.playerShadow?.setVisible(false);
  }
  if (incomingEpoch !== state.epoch) { state.chunks.clear(); state.objects.clear(); state.presence.clear(); state.npcs.clear(); state.npcCursor=0; state.inventory.clear(); state.pendingActions.clear(); state.assetSheets.clear(); state.assetImages.clear(); clearSheetStates(); state.assetCatalogReleaseVersion=-1;state.assetCatalogHash=null; state.townAssets.clear(); state.viewportKey=null;state.changesPendingUntil=0;state.presencePendingUntil=0;state.presencePollPendingUntil=0;state.npcPollPendingUntil=0;state.lastPresencePollAt=0;state.lastNpcPollAt=0; state.layout = { template_version: 2, catalog_version: 4, world_id: 'main', demo_stage: 5, placements: [] }; state.selectedAsset = null; state.selectedInstance = null; clearKeys(); state.scene?.renderObjects(); state.scene?.renderPresence(); state.scene?.renderNpcs(); }
  state.epoch = incomingEpoch;
  state.world = payload;
  const meta = payload.world && typeof payload.world === 'object' ? payload.world : payload;
  state.graylisted = !!(payload.enabled ?? meta.enabled ?? payload.graylisted ?? meta.graylisted ?? false);
  state.authenticated = typeof payload.authenticated==='boolean' ? payload.authenticated : !!payload.account_id;
  state.canSave = state.graylisted && state.authenticated;
  if (!state.graylisted) residents.reset(incomingEpoch);
  // The v2 endpoint is authoritative for catalog metadata, while local assets
  // provide the packaged image paths. Merge by stable id so old clients and
  // partial server catalogs remain renderable.
  if (Array.isArray(payload.catalog) && payload.catalog.length) {
    const local = new Map((state.catalog?.items || []).map(item => [item.id, item]));
    state.catalog.items = payload.catalog.map(item => ({ ...(local.get(item.id) || {}), ...item, id: item.id, asset: item.asset || local.get(item.id)?.asset }));
    state.catalog.version = Number(payload.catalog_version || state.catalog.version || 4);
    state.legacyItems = state.catalog.items.filter(item=>!item.id.startsWith('town-source-')).map(item=>({...item,asset_source:'legacy',asset:item.asset_key?`../Decorations/Source/${item.asset_key}.svg`:item.asset}));
    state.catalog.items=[...state.legacyItems,...state.townAssets.values()];
  }
  if (Number.isInteger(meta.map_width)) state.catalog.map_width = meta.map_width;
  if (Number.isInteger(meta.map_height)) state.catalog.map_height = meta.map_height;
  if (Number.isInteger(meta.tile_size)) state.catalog.tile_size = meta.tile_size;
  if (Number.isInteger(meta.chunk_size)) state.catalog.chunk_size = meta.chunk_size;
  state.catalog.world.width_tiles=state.catalog.map_width;state.catalog.world.height_tiles=state.catalog.map_height;
  state.catalog.world.river.height=state.catalog.map_height;
  state.scene?.cameras.main.setBounds(0,0,state.catalog.map_width*16,state.catalog.map_height*16);
  if (Number.isSafeInteger(payload.scene_version || payload.world_version)) state.version = payload.scene_version || payload.world_version;
  if (payload.inventory) setInventory(payload);
  if (payload.presence) setPresence(payload);
  if (payload.layout) loadState(payload);
  if(state.objects.size) {syncObjectLayout();state.scene?.renderObjects();}
  if(state.graylisted && !state.playerReady && payload.player && state.scene) {
    state.scene.spawnPlayer(payload.player);state.awaitingSpawn=true;state.viewportKey=null;
  }
  if (payload.chunks) loadChunks(payload);
  state.status = state.graylisted ? '共享小镇已开放，正在加载区块' : payload.code==='unavailable' ? '共享小镇载入失败，请重新载入' : '当前账号尚未开放共享小镇';
  updateUI();
  if (state.graylisted && state.playerReady && !state.chunks.size) {
    const cx=Math.floor(state.scene.player.x/384),cy=Math.floor(state.scene.player.y/384),chunks=[];
    for(let yy=Math.max(0,cy-1);yy<=Math.min(Math.ceil(state.catalog.map_height/24)-1,cy+1);yy++)
      for(let xx=Math.max(0,cx-1);xx<=Math.min(Math.ceil(state.catalog.map_width/24)-1,cx+1);xx++)chunks.push({chunk_x:xx,chunk_y:yy});
    host({type:'viewportChanged',chunks});
  }
}
function loadChunks(payload) {
  if (payload?.session_epoch !== state.epoch) return;
  const chunks=payload?.chunks || [];
  state.conflict=false;
  state.chunks.clear();state.objects.clear();
  for(const chunk of chunks){
    state.chunks.set(`${chunk.chunk_x},${chunk.chunk_y}`,chunk);
    for(const value of chunk.objects||[])state.objects.set(value.object_id||value.instance_id,objectFromRemote(value));
  }
  syncObjectLayout();
  if(state.awaitingSpawn && state.scene){
    const preferred={pixel_x:state.scene.player.x,pixel_y:state.scene.player.y};
    state.awaitingSpawn=false;
    if(!playerCanStand(state.catalog,state.layout,preferred.pixel_x,preferred.pixel_y))state.scene.spawnPlayer(preferred);
    sendPlayerPresence(true);
  }
  state.scene?.renderObjects();updateUI();
}
function unloadFarChunks(centerX, centerY) {
  for (const [key, chunk] of state.chunks) {
    if (Math.abs(Number(chunk.chunk_x) - centerX) <= 2 && Math.abs(Number(chunk.chunk_y) - centerY) <= 2) continue;
    for (const value of chunk.objects || []) { const id = value.object_id || value.instance_id; state.objects.delete(id); state.layout?.placements && (state.layout.placements = state.layout.placements.filter(item => (item.object_id || item.instance_id) !== id)); }
    state.chunks.delete(key);
  }
}
function applyChanges(payload) {
  if(payload?.session_epoch!==state.epoch)return;
  state.changesPendingUntil=0;
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
  if(payload?.session_epoch!==state.epoch)return;
  if(state.realtime && !payload.realtime_snapshot)return;
  state.presencePendingUntil=0;
  state.presencePollPendingUntil=0;
  state.presence.clear();
  for (const value of (Array.isArray(payload?.presence) ? payload.presence : payload?.presence?.players || payload?.players || [])) state.presence.set(value.account_id || value.user_id || value.id, value);
  state.scene?.renderPresence?.();
  if ($('online-count')) $('online-count').textContent = `${state.presence.size} 人在线`;
}
function setRealtimeState(connected) {
  if(connected?.session_epoch!==state.epoch)return;
  state.realtime = connected.connected === true;
  if (state.realtime) {
    state.presencePollPendingUntil = Date.now() + 60000;
    state.npcPollPendingUntil = Date.now() + 60000;
  } else {
    state.presencePollPendingUntil = 0;
    state.npcPollPendingUntil = 0;
  }
  updateUI();
}
function setNpcs(payload) {
  if(payload?.session_epoch!==state.epoch)return;
  if(state.realtime && !payload.realtime_snapshot)return;
  state.npcs.clear();
  for(const value of payload?.npcs || []) {
    const id = value.npc_id || value.id;
    if (!id) continue;
    state.npcs.set(id, value);
  }
  state.npcCursor = Number(payload?.cursor || state.npcCursor || 0);
  state.scene?.renderNpcs?.();
}
function applyNpcChanges(payload) {
  if(payload?.session_epoch!==state.epoch)return;
  if(state.realtime)return;
  state.npcPollPendingUntil=0;
  for(const event of payload?.events || []) {
    const npc = state.npcs.get(event.npc_id), data = event.payload || {};
    if(npc && event.type === 'state_changed') npc.runtime = { ...(npc.runtime || {}), ...data };
  }
  state.npcCursor = Number(payload?.cursor || state.npcCursor || 0);
  state.scene?.renderNpcs?.();
}
function applyRealtimeNpc(payload) {
  if(payload?.session_epoch!==state.epoch || !payload?.npc_id)return;
  if(!mergeNpcDelta(state.npcs,payload))return;
  state.scene?.renderNpcs?.();
}
function applyRealtimePresence(payload) {
  if(payload?.session_epoch!==state.epoch)return;
  if(!mergePresenceDelta(state.presence,payload))return;
  state.presencePendingUntil=0;
  state.scene?.renderPresence?.();
  if($('online-count'))$('online-count').textContent=`${state.presence.size} 人在线`;
}
function applyRealtimeSnapshot(payload) {
  if(payload?.session_epoch!==state.epoch)return;
  setPresence({...payload,realtime_snapshot:true});
  setNpcs({...payload.npc_snapshot,session_epoch:state.epoch,realtime_snapshot:true});
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
  if(payload?.session_epoch!==state.epoch)return;
  if (!payload) return;
  const operationId = payload.operation_id || payload.operation || null;
  let pending = operationId ? state.pendingActions.get(operationId) : null;
  if (!operationId && state.pendingActions.size === 1) pending = state.pendingActions.values().next().value;
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
  if (Number.isSafeInteger(payload.scene_version || payload.world_version)) state.version = payload.scene_version || payload.world_version;
  if(payload.code==='object_conflict'||payload.code==='position_conflict'){state.viewportKey=null;notice('物件或占地已更新，正在重新载入当前区块');}
  state.saving = false; state.conflict = !!payload.conflict || payload.code === 'object_conflict' || payload.code === 'position_conflict'; state.dirty = failed ? state.dirty : false; state.status = failed ? (payload.message || payload.code || '共享小镇操作失败，请稍后重试') : '共享小镇操作已自动保存';
  if(state.history.length && !failed)state.history=[];
  syncObjectLayout(); state.scene?.renderObjects(); updateUI();
}

function setWorld(payload) { loadWorld(payload); }
function setChunks(payload) { loadChunks(payload); }
function clearSheetStates() {
  for (const value of state.sheetStates.values()) clearTimeout(value.timer);
  state.sheetStates.clear();
}
function updateAssetStatus() {
  const values=[...state.sheetStates.values()], errors=values.filter(v=>v.status==='error').length;
  $('asset-status').textContent = state.catalogError ? '素材目录加载失败' : state.catalogLoading ? '素材目录加载中…' : errors ? `${errors} 张图集加载失败` : values.some(v=>v.status==='loading') ? '素材加载中…' : values.length ? '素材已就绪' : '暂无已发布素材';
  $('retry-assets').hidden=!errors&&!state.catalogError;
}
function requestSheet(sheet) {
  const version=state.assetCatalogReleaseVersion, epoch=state.epoch;
  const loading={...sheet,status:'loading'};
  state.sheetStates.set(sheet.sheet_id,loading);
  loading.timer=setTimeout(()=>{if(epoch!==state.epoch || version!==state.assetCatalogReleaseVersion || state.sheetStates.get(sheet.sheet_id)!==loading)return;setAssetSheet({sheet_id:sheet.sheet_id,session_epoch:epoch,release_version:version,status:'error'});},20500);
  host({type:'townAssetSheetRequested',sheet_id:sheet.sheet_id,release_version:version});
  updateAssetStatus();
}
function setAssetCatalog(payload) {
  if (payload.session_epoch !== state.epoch) return;
  state.catalogLoading=false;
  if(payload.status==='error'){state.catalogError=true;updateAssetStatus();return;}
  state.catalogError=false;updateAssetStatus();
  const releaseVersion=Number(payload.release_version||0), hash=payload.config_sha256||null;
  if (releaseVersion===state.assetCatalogReleaseVersion && hash===state.assetCatalogHash) return;
  state.assetCatalogReleaseVersion=releaseVersion;state.assetCatalogHash=hash;
  clearSheetStates(); state.assetSheets.clear();state.assetImages.clear();
  for(const item of state.townAssets.values()) if(state.scene?.textures.exists(`item-${item.id}`))state.scene.textures.remove(`item-${item.id}`);
  state.townAssets.clear();
  for(const raw of payload.items||[]) if(raw.id?.startsWith('town-source-'))state.townAssets.set(raw.id,{...raw,asset_source:'town',asset:null});
  if(state.catalog)state.catalog.items=[...state.legacyItems,...state.townAssets.values()];
  for(const sheet of payload.sheets||[])requestSheet(sheet);
  renderMaterials();state.scene?.renderObjects();
}
function setAssetSheet(payload) {
  if(payload.session_epoch!==state.epoch || payload.release_version!==state.assetCatalogReleaseVersion)return;
  const loading=state.sheetStates.get(payload.sheet_id);if(!loading)return;
  clearTimeout(loading.timer);
  const fail=()=>{
    if(state.sheetStates.get(payload.sheet_id)!==loading)return;
    loading.status='error';renderMaterials();state.scene?.renderObjects();state.scene?.refreshGhost();
    host({type:'assetError',sheet_id:payload.sheet_id,message:'PNG decode or crop failed'});
  };
  if(payload.status==='error' || !payload.url){fail();return;}
  const image=new Image();image.crossOrigin='anonymous';image.decoding='async';
  state.assetImages.set(payload.sheet_id,image);
  image.onerror=fail;
  image.onload=()=>{
    if(state.sheetStates.get(payload.sheet_id)!==loading || payload.session_epoch!==state.epoch)return;
    if(image.naturalWidth!==loading.width || image.naturalHeight!==loading.height){fail();return;}
    try {
      for(const item of state.townAssets.values()) {
        if(item.sheet_id!==payload.sheet_id)continue;
        const frames=item.frames?.length?item.frames:[item];item.decodedFrames=[];
        for(const frame of frames){
          const x=Number(frame.x??item.x),y=Number(frame.y??item.y),w=Number(frame.w??frame.width??item.width),h=Number(frame.h??frame.height??item.height);
          if(![x,y,w,h].every(Number.isFinite)||w<1||h<1||x<0||y<0||x+w>image.naturalWidth||y+h>image.naturalHeight)throw Error('invalid_crop');
          const canvas=document.createElement('canvas');canvas.width=w;canvas.height=h;
          const context=canvas.getContext('2d',{willReadFrequently:true});context.imageSmoothingEnabled=false;context.drawImage(image,x,y,w,h,0,0,w,h);
          if(!context.getImageData(0,0,w,h).data.some((v,i)=>i%4===3&&v>0))throw Error('empty_crop');
          item.decodedFrames.push(canvas);
          if(item.decodedFrames.length===1){item.asset=canvas.toDataURL('image/png');item.asset_frame={x,y,width:w,height:h};}
        }
        const key=`item-${item.id}`;
        if(state.scene.textures.exists(key))state.scene.textures.remove(key);
        const textureCanvas=document.createElement('canvas');textureCanvas.width=item.decodedFrames[0].width;textureCanvas.height=item.decodedFrames[0].height;textureCanvas.getContext('2d').drawImage(item.decodedFrames[0],0,0);
        state.scene.textures.addCanvas(key,textureCanvas);
      }
      loading.status='ready';state.assetSheets.set(payload.sheet_id,payload.url);
      renderMaterials();state.scene?.renderObjects();state.scene?.refreshGhost();
      host({type:'assetReady',sheet_id:payload.sheet_id,width:image.naturalWidth,height:image.naturalHeight,items:[...state.townAssets.values()].filter(i=>i.sheet_id===payload.sheet_id).map(i=>({id:i.id,crop:i.asset_frame,texture:state.scene.textures.exists(`item-${i.id}`)}))});
    }catch(error){fail();}
  };
  image.src=payload.url;
}
function drawMinimap(){
  if(!state.scene||!state.catalog)return;
  const canvas=$('minimap'),ctx=canvas.getContext('2d'),scale=180/state.catalog.map_width,w=state.catalog.world;
  ctx.fillStyle='#8bab56';ctx.fillRect(0,0,180,180);
  const rect=(r,color)=>{if(!r)return;ctx.fillStyle=color;ctx.fillRect(r.x*scale,r.y*scale,r.width*scale,r.height*scale);};
  rect({x:88,y:0,width:16,height:state.catalog.map_height},'#d6c184');rect({x:0,y:88,width:state.catalog.map_width,height:16},'#d6c184');rect(w.river,'#6faec0');rect(w.bridge,'#9b6f48');for(const f of w.forest_regions||[w.forest])rect(f,'#356344');rect(w.center_square,'#e6d4a0');
  for(const object of state.objects.values())rect({x:object.tile_x,y:object.tile_y,width:Math.max(2,object.footprint?.width||1),height:Math.max(2,object.footprint?.height||1)},canEditObject(object)?'#e1ae4f':'#6a588a');
  const view=state.scene.cameras.main.worldView;ctx.strokeStyle='#fff';ctx.lineWidth=1;ctx.strokeRect(view.x/16*scale,view.y/16*scale,view.width/16*scale,view.height/16*scale);
  const player=state.scene.player;ctx.fillStyle='#e84738';ctx.beginPath();ctx.arc(player.x/16*scale,player.y/16*scale,2.5,0,Math.PI*2);ctx.fill();
  $('minimap-coordinates').textContent=`X: ${Math.floor(player.x/16)} · Y: ${Math.floor(player.y/16)}`;
}
function setupControls() {
  $('show-npc-names')?.addEventListener('change', event => { state.showNpcNames = event.target.checked === true; state.scene?.renderNpcs?.(); });
  $('show-npc-states')?.addEventListener('change', event => { state.showNpcStates = event.target.checked === true; state.scene?.renderNpcs?.(); });
  $('materials-toggle')?.addEventListener('click', () => {
    const workspace = document.querySelector('.workspace');
    const collapsed = workspace.classList.toggle('materials-collapsed');
    $('materials-toggle').setAttribute('aria-label', collapsed ? '展开素材栏' : '收起素材栏');
    $('materials-toggle').title = collapsed ? '展开素材栏' : '收起素材栏';
  });
  $('brush-toggle')?.addEventListener('click', () => {
    if (state.mode !== 'edit') return;
    state.brushMode = !state.brushMode;
    state.brushLastTile = null;
    updateUI();
    notice(state.brushMode ? '连续铺设已开启，按住鼠标拖动即可铺设' : '连续铺设已关闭');
  });
  $('retry-assets').onclick=()=>{if(state.catalogError){state.catalogError=false;state.catalogLoading=true;updateAssetStatus();host({type:'retryAssetsRequested'});return;}for(const sheet of state.sheetStates.values())if(sheet.status==='error')requestSheet(sheet);};
  $('minimap').onclick=event=>{if(state.mode!=='edit')return;const rect=event.target.getBoundingClientRect();state.scene?.cameras.main.centerOn((event.clientX-rect.left)/rect.width*state.catalog.map_width*16,(event.clientY-rect.top)/rect.height*state.catalog.map_height*16);};
  $('object-delete').onclick=()=>{$('object-menu').hidden=true;removeSelected();};
  $('object-layer-apply').onclick=applyObjectLayer;
  $('object-move').onclick=()=>{
    $('object-menu').hidden=true;
    const placement=state.layout.placements.find(p=>p.instance_id===state.selectedInstance);
    if(!placement||!canEditObject(placement))return;
    state.selectedAsset=null;state.scene.cancelDrag();
    state.scene.drag={id:placement.instance_id,clickMove:true,moved:true,offsetX:0,offsetY:0,tileX:placement.tile_x,tileY:placement.tile_y,valid:true};
    $('game').focus({preventScroll:true});notice('移动鼠标选择位置，左击确认，Esc 取消');
  };
  $('search')?.addEventListener('input', event => { state.search = event.target.value; state.page = 1; renderMaterials(); });
  $('subcategory')?.addEventListener('change', event => { state.subcategory = event.target.value; state.page = 1; renderMaterials(); });
  $('page-prev')?.addEventListener('click', () => { state.page--; renderMaterials(); });
  $('page-next')?.addEventListener('click', () => { state.page++; renderMaterials(); });
  $('roam').addEventListener('click', () => { residents.cancelCamera(); $('object-menu').hidden=true; state.mode = 'roam'; state.brushMode = false; state.brushLastTile = null; document.querySelector('.workspace')?.classList.add('mode-roam'); document.querySelector('.workspace')?.classList.remove('mode-edit'); state.selectedAsset = null; state.selectedInstance = null; state.scene?.cancelDrag(); clearKeys(); state.scene?.cameras.main.centerOn(state.scene.player.x,state.scene.player.y); state.scene?.cameras.main.startFollow(state.scene.player, true, 1, 1, 0, 0); state.scene?.drawSelection(); state.scene?.drawGrid(); state.scene?.refreshGhost(); updateUI(); $('game').focus({ preventScroll: true }); });
  $('edit').addEventListener('click', () => { residents.cancelCamera(); state.mode = 'edit'; state.brushLastTile = null; document.querySelector('.workspace')?.classList.add('mode-edit'); document.querySelector('.workspace')?.classList.remove('mode-roam'); clearKeys(); state.scene?.cameras.main.stopFollow(); state.scene?.drawGrid(); updateUI(); $('game').focus({ preventScroll: true }); });
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
    if (document.activeElement?.matches('input,textarea,select,[contenteditable]') || document.activeElement?.closest('#resident-panel,#resident-strip') || state.modal) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); save(); return; }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); $('undo').click(); return; }
    if (state.mode === 'edit' && (event.key === 'Delete' || event.key === 'Backspace')) { event.preventDefault(); removeSelected(); }
    if (event.key === 'Escape') { $('object-menu').hidden=true; state.scene?.cancelDrag(); state.selectedAsset = null; state.selectedInstance = null; state.brushLastTile = null; state.brushMode = false; state.scene?.refreshGhost(); state.scene?.drawSelection(); updateUI(); }
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
  state.catalog = await readLocalJson('catalog.json');
  state.initial = {template_version:2,catalog_version:4,world_id:'main',demo_stage:5,placements:[]};
  if (state.catalog.version !== 4 || state.catalog.tile_size !== 16 || state.catalog.map_width !== 768 || state.catalog.map_height !== 768 || state.catalog.items.length !== 1500 || state.catalog.tree_stages.length !== 5) throw new Error('素材目录版本或地图尺寸不兼容');
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
