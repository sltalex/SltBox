// Resident reads are scoped to both the login epoch and the selected person.
export class ResidentReads {
  constructor() { this.sequence = 0; this.reset(0); }
  reset(epoch) {
    this.epoch = epoch; this.selected = null; this.detail = null;
    this.records = new Map(); this.cursor = 0; this.hasMore = false;
    this.pending = {}; this.errors = {};
  }
  select(id) { this.reset(this.epoch); this.selected = id; }
  request(kind, older = false) {
    if (!this.selected || !['detail', 'history'].includes(kind)) return null;
    if (older && (!this.hasMore || this.pending.history)) return null;
    const request_id = `${this.epoch}:${++this.sequence}`;
    const cursor = kind === 'history' && older ? this.cursor : 0;
    this.pending[kind] = { request_id, cursor }; delete this.errors[kind];
    return { type: kind === 'detail' ? 'residentDetailRequested' : 'residentConversationsRequested', npc_id: this.selected, request_id, cursor, session_epoch: this.epoch };
  }
  accept(kind, payload) {
    const pending = this.pending[kind];
    if (!pending || payload?.session_epoch !== this.epoch || payload.npc_id !== this.selected || payload.request_id !== pending.request_id) return false;
    delete this.pending[kind];
    if (payload.code || payload.ok === false) { this.errors[kind] = '读取失败，请重试'; return true; }
    if (kind === 'detail') {
      if (payload.resident?.npc_id !== this.selected) { this.errors.detail = '村民资料无效'; return true; }
      this.detail = payload.resident;
    } else {
      if (!Array.isArray(payload.conversations) || !Number.isSafeInteger(payload.cursor) || payload.cursor < 0 || (payload.has_more && (payload.cursor === 0 || (pending.cursor > 0 && payload.cursor >= pending.cursor)))) {
        this.errors.history = '对话分页无效，请刷新'; return true;
      }
      if (pending.cursor === 0) this.records.clear();
      for (const record of payload.conversations) if (Number.isSafeInteger(record?.event_id) && record.event_id > 0 && typeof record.text === 'string') this.records.set(record.event_id, record);
      this.cursor = payload.cursor; this.hasMore = payload.has_more === true;
    }
    return true;
  }
  history() { return [...this.records.values()].sort((a, b) => a.event_id - b.event_id); }
}

const states = { walk: '行走', work: '工作', rest: '休息', talk: '交谈', player_talk: '与玩家交谈', follow: '跟随', wait: '等待' };
const jobs = { farmer: '农民', merchant: '商人', craftsman: '工匠' };
const label = npc => `${jobs[npc.occupation] || npc.occupation || '村民'} · ${states[npc.runtime?.state] || npc.runtime?.state || '休息'}`;
const element = (tag, text, className) => Object.assign(document.createElement(tag), { textContent: text ?? '', ...(className ? { className } : {}) });

/**
 * Keep invitation affordances derived from the same authoritative task shape
 * used by the resident detail projection.  A rest task at a rest state is an
 * idle home period and may be interrupted by a player invitation; every other
 * running task remains busy. Agent pause keeps invitations disabled until an
 * administrator resumes that NPC.
 */
export function residentInviteAvailability(npc = {}, task = null, runtime = {}) {
  const invitation = task?.player_invitation === true && ['follow', 'player_talk'].includes(task.action);
  const ownedInvitation = invitation && task.player_invitation_owned === true;
  const idleRest = task?.status === 'running' && task.action === 'rest' && runtime.state === 'rest';
  // The realtime NPC delta can arrive before the detail request that contains
  // the task ownership projection. Once the server says this NPC is in the
  // face-to-face player_talk state, keep the compose box usable while that
  // detail request catches up. The API still authoritatively checks ownership
  // and adjacency for every line.
  const runtimeTaskId = runtime.current_task_id || runtime.task_id || '';
  const detailTaskIsCurrent = !task || !runtimeTaskId || !task.task_id || task.task_id === runtimeTaskId;
  const realtimeConversation = runtime.state === 'player_talk'
    && (!detailTaskIsCurrent || !task || task.action === 'player_talk')
    && (!task || task.status === 'running');
  const conversationReady = (ownedInvitation || realtimeConversation)
    && runtime.state === 'player_talk';
  const conversationWalking = ownedInvitation && task.action === 'player_talk' && !conversationReady;
  const busy = task?.status === 'running' && !idleRest && !conversationReady;
  const paused = npc.agent_paused === true;
  const reason = paused ? 'Agent 已暂停，请先在后台恢复' : conversationWalking ? '村民正在走来，请等到面对面后再说话' : busy ? '村民正在忙，请先等一等' : '';
  return {
    talkDisabled: paused || busy || (ownedInvitation && !conversationReady),
    followDisabled: paused || busy || ownedInvitation,
    cancelVisible: ownedInvitation,
    reason,
    idleRest,
    busy,
    ownedInvitation,
  };
}

export function residentPhotoValidation(file = {}) {
  const mime = String(file?.type || '');
  const size = Number(file?.size || 0);
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(mime) || !Number.isFinite(size) || size < 1 || size > 4 * 1024 * 1024)
    return '请选择 4 MB 以内的 PNG、JPEG 或 WebP 图片。';
  return null;
}

export function createResidents({ host, locate, stopFollowing, avatarUrl, clearKeys, refreshNpc = () => {} }) {
  const reads = new ResidentReads();
  const strip = document.getElementById('resident-strip');
  const panel = document.getElementById('resident-panel');
  const body = document.getElementById('resident-detail');
  const history = document.getElementById('resident-history');
  const search = document.getElementById('resident-search');
  const directory = document.getElementById('resident-directory');
  const dialogueInput = document.getElementById('resident-dialogue-input');
  const contextDialogue = document.getElementById('context-dialogue-input');
  const photoButton = document.getElementById('context-dialogue-photo');
  const photoInput = document.getElementById('context-dialogue-photo-input');
  const dialoguePanel = document.getElementById('player-dialogue');
  const dialogueHistory = document.getElementById('player-dialogue-history');
  const context = document.getElementById('map-context');
  const timers = new Map();
  let npcs = new Map(), following = false, locating = false, listKey = '', dialogueOpen = false, activeDialogueInput = dialogueInput, lastRuntimeDialogueKey = '';
  let dialogueLines = [];
  const current = () => npcs.get(reads.selected);
  function appendNpcDialogue(text, taskId = '') {
    const dialogue = typeof text === 'string' ? text.trim() : '';
    if (!dialogue) return false;
    const key = `${reads.selected || ''}:${taskId || ''}:${dialogue}`;
    if (key === lastRuntimeDialogueKey) return false;
    // The same reply can arrive through the HTTP response and the realtime
    // snapshot. Keep one visible line while allowing the same wording in a
    // later turn with a different task id.
    if (dialogueLines.some(line => line.speaker === 'npc' && line.text === dialogue && (!taskId || line.taskId === taskId))) return false;
    lastRuntimeDialogueKey = key;
    dialogueLines.push({ speaker: 'npc', text: dialogue, taskId: taskId || '' });
    return true;
  }
  function replyText(payload = {}) {
    const invitation = payload?.invitation && typeof payload.invitation === 'object' ? payload.invitation : {};
    return [invitation.dialogue, payload.dialogue, invitation.reply, payload.reply, invitation.npc_reply, payload.npc_reply]
      .find(value => typeof value === 'string' && value.trim())?.trim() || '';
  }
  function captureRuntimeDialogue(values) {
    const npc = values instanceof Map ? values.get(reads.selected) : null;
    if (!npc) return;
    const runtime = npc?.runtime || npc || {};
    const dialogueValue = runtime.dialogue ?? npc.dialogue;
    const dialogue = typeof dialogueValue === 'string' ? dialogueValue.trim() : '';
    if (!dialogue) return;
    // A snapshot may arrive just before the state transition or after the
    // dialogue panel was opened. The reply itself is authoritative, so do not
    // discard it because a transient state label is stale.
    if (runtime.state !== 'player_talk' && !dialogueOpen) return;
    appendNpcDialogue(dialogue, runtime.current_task_id || runtime.task_id || '');
  }
  function send(kind, older = false) {
    const message = reads.request(kind, older); if (!message) return;
    clearTimeout(timers.get(kind));
    timers.set(kind, setTimeout(() => receive(kind, { ...message, code: 'timeout' }), 22000));
    host(message); render();
  }
  function portrait(npc) {
    const url = avatarUrl(npc.avatar_asset_id);
    if (!url) return element('span', npc.name?.slice(0, 1) || '人', 'resident-avatar resident-initial');
    const image = Object.assign(document.createElement('img'), { src: url, alt: '', className: 'resident-avatar', draggable: false });
    image.addEventListener('error', () => image.replaceWith(element('span', npc.name?.slice(0, 1) || '人', 'resident-avatar resident-initial')), { once: true });
    return image;
  }
  function choose(id) {
    clearKeys(); following = false; locating = false; stopFollowing();
    reads.select(id); dialogueLines = []; lastRuntimeDialogueKey = ''; listKey = '';
    panel.hidden = true; document.querySelector('.workspace').classList.remove('resident-open');
    renderList(); send('detail'); send('history'); renderContext();
  }
  function hideProfile() {
    panel.hidden = true;
    document.querySelector('.workspace').classList.remove('resident-open');
  }
  function hideDialogue() {
    dialogueOpen = false;
    renderDialogue();
  }
  function openProfile() {
    if (!reads.selected) return;
    hideDialogue(); panel.hidden = false;
    document.querySelector('.workspace').classList.add('resident-open');
    document.getElementById('resident-close').focus({ preventScroll: true });
    send('detail'); send('history'); render();
  }
  function openDialogue() {
    hideProfile(); dialogueOpen = true; activeDialogueInput = contextDialogue || dialogueInput;
    captureRuntimeDialogue(npcs);
    renderDialogue(); activeDialogueInput?.focus();
  }
  function renderDialogue() {
    if (!dialoguePanel) return;
    const npc = current();
    dialoguePanel.hidden = !dialogueOpen || !npc || !panel.hidden;
    if (!npc || dialoguePanel.hidden) return;
    document.getElementById('player-dialogue-name').textContent = `与${npc.name || '村民'}交谈`;
    document.getElementById('player-dialogue-state').textContent = npc.runtime?.state === 'player_talk' ? '面对面交谈' : label(npc);
    const historyTimeline = reads.history().flatMap(record => {
      const speakerId = record.speaker?.npc_id || record.speaker?.id;
      // Player conversations are stored as one event containing both sides.
      // Render both turns separately so an eventual history refresh cannot
      // hide the NPC reply or label it as the player's message.
      if (speakerId === 'player' && typeof record.player_text === 'string' && record.player_text.trim()) {
        return [
          { speaker: 'player', text: record.player_text.trim(), meta: record.created_at },
          { speaker: 'npc', text: record.text, meta: record.created_at },
        ];
      }
      return [{ speaker: speakerId === npc.npc_id ? 'npc' : 'player', text: record.text, meta: record.created_at }];
    });
    // The immediate invitation response and the following history refresh
    // intentionally overlap. Remove only adjacent identical lines; repeated
    // wording in separate turns remains visible.
    const timeline = [...historyTimeline, ...dialogueLines].filter((line, index, values) => {
      const previous = values[index - 1];
      return !previous || previous.speaker !== line.speaker || previous.text !== line.text;
    });
    dialogueHistory.replaceChildren(...timeline.map(line => {
      const item = element('div', '', `player-dialogue-line ${line.speaker === 'npc' ? 'npc' : 'player'}`);
      item.append(element('small', `${line.speaker === 'npc' ? (npc.name || '村民') : '我'}${line.meta ? ` · ${line.meta}` : ''}`), element('span', line.text));
      return item;
    }));
    dialogueHistory.scrollTop = dialogueHistory.scrollHeight;
  }
  function close() {
    for (const timer of timers.values()) clearTimeout(timer); timers.clear();
    following = false; locating = false; stopFollowing(); reads.select(null); panel.hidden = true; dialogueLines = []; lastRuntimeDialogueKey = ''; hideDialogue();
    document.querySelector('.workspace').classList.remove('resident-open');
    listKey = ''; renderList();
  }
  function renderList() {
    const values = [...npcs.values()].filter(npc => npc.enabled !== false);
    const key = JSON.stringify([reads.selected, search.value, values.map(n => [n.npc_id, n.name, n.occupation, n.avatar_asset_id, avatarUrl(n.avatar_asset_id), n.runtime?.state, n.runtime?.dialogue, ...(search.value.trim() ? [n.runtime?.tile_x, n.runtime?.tile_y] : [])])]);
    if (key === listKey) return; listKey = key;
    document.getElementById('resident-count').textContent = `${values.length} 位村民`;
    strip.replaceChildren(...values.map(npc => {
      const button = element('button', '', 'resident-chip'); button.type = 'button';
      button.setAttribute('aria-pressed', String(reads.selected === npc.npc_id));
      button.setAttribute('aria-label', `${npc.name}，${label(npc)}，查看详情`);
      button.append(portrait(npc), element('span', npc.name), element('small', states[npc.runtime?.state] || '休息'));
      const unread = npc.npc_id !== reads.selected && ['talk', 'player_talk'].includes(npc.runtime?.state) && String(npc.runtime?.dialogue || '').trim();
      if (unread) button.append(element('span', '新', 'resident-unread'));
      button.addEventListener('click', () => choose(npc.npc_id)); return button;
    }));
    const query = search.value.trim().toLowerCase();
    const filtered = values.filter(n => `${n.name} ${label(n)} ${n.runtime?.tile_x},${n.runtime?.tile_y}`.toLowerCase().includes(query));
    directory.replaceChildren(...filtered.map(npc => {
      const button = element('button', `${npc.name} · ${label(npc)}`, 'resident-directory-item');
      button.type = 'button'; button.addEventListener('click', () => choose(npc.npc_id)); return button;
    }));
    if (!filtered.length) directory.append(element('p', '没有匹配的村民', 'resident-muted'));
  }
  function renderContext() {
    if (!context) return;
    const npc = current();
    context.hidden = !npc;
    if (!npc) return;
    const r = npc.runtime || {};
    document.getElementById('context-name').textContent = npc.name || '村民';
    document.getElementById('context-state').textContent = label(npc);
    const avatar = document.getElementById('context-avatar');
    const url = avatarUrl(npc.avatar_asset_id);
    if (url && avatar.tagName !== 'IMG') {
      const image = portrait(npc); image.id = 'context-avatar'; avatar.replaceWith(image);
    } else if (avatar.tagName === 'IMG' && !url) {
      const initial = element('span', npc.name?.slice(0, 1) || '人', 'resident-avatar resident-initial'); initial.id = 'context-avatar'; avatar.replaceWith(initial);
    } else if (avatar.tagName !== 'IMG') avatar.textContent = npc.name?.slice(0, 1) || '人';
    const task = reads.detail?.task || null;
    const availability = residentInviteAvailability(npc, task, r);
    const note = document.getElementById('context-note');
    if (note) note.textContent = locating ? '镜头正在跟随这位居民' : availability.reason || (r.state === 'player_talk' ? '已面对面，可以继续说话' : '可查看资料或发起互动');
    const talk = document.getElementById('context-talk');
    const follow = document.getElementById('context-follow');
    const locateButton = document.getElementById('context-locate');
    // The map action opens the dialogue window even when the NPC is busy; the
    // server then returns the authoritative busy response. The compact detail
    // form remains disabled until the NPC is available.
    talk.disabled = npc.agent_paused === true;
    follow.disabled = availability.followDisabled;
    locateButton.textContent = locating ? '取消定位' : '定位';
    talk.title = availability.reason || '面对面交谈';
    follow.title = availability.reason || '邀请村民跟随';
    talk.textContent = r.state === 'player_talk' ? '继续交谈' : '交谈';
    renderDialogue();
  }
  function nearby(id, distance = null) {
    const prompt = document.getElementById('nearby-prompt');
    if (!prompt) return;
    const npc = id ? npcs.get(id) : null;
    prompt.hidden = !npc || reads.selected === id;
    if (!npc || prompt.hidden) return;
    document.getElementById('nearby-prompt-text').textContent = `${npc.name || '村民'}在附近${Number.isFinite(distance) ? ` · ${Math.max(1, Math.round(distance))} 格` : ''}`;
    document.getElementById('nearby-prompt-talk').onclick = () => { choose(id); openDialogue(); };
    document.getElementById('nearby-prompt-profile').onclick = () => { choose(id); openProfile(); };
    document.getElementById('nearby-prompt-locate').onclick = () => { choose(id); clearKeys(); locating = true; locate(id, false); renderContext(); };
    const availability = residentInviteAvailability(npc, reads.detail?.task || null, npc.runtime || {});
    document.getElementById('nearby-prompt-talk').disabled = npc.agent_paused === true;
    document.getElementById('nearby-prompt-talk').title = availability.reason || '面对面交谈';
  }
  function renderRuntime() {
    const npc = current(); if (!npc || panel.hidden) return;
    const r = npc.runtime || reads.detail?.runtime || {};
    document.getElementById('resident-live').textContent = `${label(npc)} · (${r.tile_x ?? '—'}, ${r.tile_y ?? '—'})${npc.agent_paused ? ' · Agent 已暂停' : ''}`;
    const task = reads.detail?.task || null;
    // Every running task is authoritative. A normal player can only cancel
    // their own invitation; work, travel, trade and NPC-to-NPC interaction
    // remain busy even when the stale snapshot has not caught up yet.
    const availability = residentInviteAvailability(npc, task, r);
    const talkButton = document.getElementById('resident-invite-talk');
    const followButton = document.getElementById('resident-invite-follow');
    const cancelButton = document.getElementById('resident-invite-cancel');
    const disabledReason = availability.reason;
    talkButton.disabled = availability.talkDisabled;
    followButton.disabled = availability.followDisabled;
    talkButton.title = disabledReason;
    followButton.title = disabledReason;
    talkButton.setAttribute('aria-label', disabledReason ? `邀请交谈：${disabledReason}` : '邀请交谈');
    followButton.setAttribute('aria-label', disabledReason ? `邀请跟随：${disabledReason}` : '邀请跟随');
    cancelButton.hidden = !availability.cancelVisible;
    cancelButton.title = availability.cancelVisible ? '结束当前账号发起的邀请' : '';
    dialogueInput.disabled = availability.talkDisabled;
    dialogueInput.title = disabledReason;
  }
  function render() {
    if (!reads.selected) return;
    document.getElementById('resident-name').textContent = current()?.name || '村民详情';
    renderRuntime();
    const d = reads.detail;
    body.replaceChildren();
    if (reads.errors.detail) body.append(element('p', reads.errors.detail, 'resident-error'));
    else if (!d) body.append(element('p', '正在读取村民资料…', 'resident-muted'));
    if (d) {
      const rows = element('dl', '', 'resident-facts');
      const add = (name, value) => { rows.append(element('dt', name), element('dd', value || '尚未设置')); };
      const text = value => typeof value === 'string' ? value : Array.isArray(value) ? value.join('、') : value && typeof value === 'object' ? Object.entries(value).map(([key,v]) => `${key}：${v}`).join('、') : '';
      add('人格', text(d.profile?.personality)); add('说话方式', d.profile?.speech_style);
      add('家庭背景', d.profile?.family_background); add('价值观', text(d.profile?.values)); add('恐惧', text(d.profile?.fears));
      add('近期目标', d.profile?.short_term_goal); add('长期目标', d.profile?.long_term_goal);
      add('家', d.home ? `${d.home.home_asset_name || d.home.home_asset_id} (${d.home.tile_x}, ${d.home.tile_y})` : '');
      add('余额', String(d.balance ?? 0)); add('库存', (d.inventory || []).map(i => `${i.item_id} × ${i.quantity}`).join('、') || '空');
      add('当前任务', d.task ? `${states[d.task.action] || d.task.action} · ${d.task.target_id || '当前位置'} · ${d.task.status}（路线 ${d.task.route_cursor}/${Math.max(0,d.task.route_length-1)}）` : '暂无任务');
      if (d.task?.failure_reason) add('失败原因', d.task.failure_reason);
      add('心情', text(current()?.runtime?.mood || d.runtime?.mood)); body.append(rows);
      const statNames = { mood: '心情', health: '健康', stamina: '体能', labor: '劳动', academics: '学业', culture: '文化', social: '社交', wealth: '财富', stress: '压力', reputation: '声望' };
      // Realtime stats_changed events can arrive after the detail request.
      // Prefer the projection with the higher revision so an open profile is
      // updated in place instead of showing the cached detail indefinitely.
      const detailStats = d.stats && typeof d.stats === 'object' ? d.stats : {};
      const liveStats = current()?.stats && typeof current().stats === 'object' ? current().stats : {};
      const statProjection = Number(liveStats.revision || 0) > Number(detailStats.revision || 0) ? liveStats : detailStats;
      const statValues = statProjection.values || {};
      const statLevels = statProjection.levels || {};
      const statGrid = element('div', '', 'resident-stat-grid');
      Object.entries(statNames).forEach(([key, name]) => {
        const value = Math.max(1, Math.min(100, Number(statValues[key] ?? 50)));
        const card = element('div', '', 'resident-stat');
        const head = element('div', '', 'resident-stat-head'); head.append(element('span', name), element('strong', `${value}`));
        const track = element('div', '', 'resident-meter'); const fill = element('i'); fill.style.width = `${value}%`; track.append(fill);
        card.append(head, track, element('small', statLevels[key] || '一般')); statGrid.append(card);
      });
      body.append(statGrid);
      const metrics = element('div', '', 'resident-metrics');
      const meter = (name, value, detail) => {
        if (!Number.isFinite(value)) return;
        const row = element('div', '', 'resident-metric');
        const head = element('div', '', 'resident-metric-head'); head.append(element('span', name), element('small', detail || `${Math.round(value * 100)}%`));
        const track = element('div', '', 'resident-meter'); const fill = element('i'); fill.style.width = `${Math.max(0, Math.min(1, value)) * 100}%`; track.append(fill); row.append(head, track); metrics.append(row);
      };
      const moodValue = Number(current()?.runtime?.mood?.value ?? current()?.runtime?.mood_score ?? d.runtime?.mood?.value ?? d.runtime?.mood_score);
      meter('心情', moodValue > 1 ? moodValue / 100 : moodValue, Number.isFinite(moodValue) ? `${Math.round(moodValue > 1 ? moodValue : moodValue * 100)}%` : '');
      const routeLength = Number(d.task?.route_length || 0), routeCursor = Number(d.task?.route_cursor || 0);
      if (routeLength > 1) meter('任务进度', routeCursor / (routeLength - 1), `${routeCursor}/${routeLength - 1}`);
      if (metrics.childElementCount) body.append(metrics);
      const schedule = element('table', '', 'resident-schedule');
      const caption = element('caption', '日程'); schedule.append(caption);
      const head = element('thead'), tr = element('tr'); for (const name of ['星期', '时间', '行动']) tr.append(element('th', name)); head.append(tr); schedule.append(head);
      const tbody = element('tbody'), time = value => `${String(Math.floor(value/60)).padStart(2,'0')}:${String(value%60).padStart(2,'0')}`;
      for (const slot of d.schedule || []) {
        const row = element('tr'); row.append(element('td', slot.weekday === -1 ? '每天' : `周${['日','一','二','三','四','五','六'][slot.weekday] || slot.weekday}`), element('td', `${time(slot.start_minute)}–${time(slot.end_minute)}`), element('td', `${states[slot.action] || slot.action}${slot.target_id ? ' · '+slot.target_id : ''}`)); tbody.append(row);
      }
      schedule.append(tbody); body.append(schedule);
    }
    const oldHeight = history.scrollHeight, oldTop = history.scrollTop;
    history.replaceChildren(...reads.history().map(record => {
      const item = element('li', '', 'resident-message');
      item.append(element('strong', `${record.speaker?.name || '村民'} → ${record.listener?.name || '村民'}`), element('p', record.text), element('small', `${record.created_at} UTC${record.approach_tile ? ` · (${record.approach_tile.tile_x}, ${record.approach_tile.tile_y})` : ''}`)); return item;
    }));
    if (!reads.records.size) history.append(element('li', reads.pending.history ? '正在读取对话…' : reads.errors.history || '暂无已完成的对话记录', 'resident-muted'));
    history.scrollTop = oldTop + Math.max(0, history.scrollHeight - oldHeight);
    const older = document.getElementById('resident-older'); older.hidden = !reads.hasMore; older.disabled = !!reads.pending.history;
    document.getElementById('resident-read-status').textContent = reads.errors.history || (reads.pending.history ? '正在读取…' : '记录仅包含已完成的交谈');
    document.getElementById('resident-camera').textContent = following ? '停止镜头跟随' : '镜头跟随';
    document.getElementById('resident-locate').textContent = locating ? '取消定位' : '定位';
    renderContext();
  }
  function receive(kind, payload) {
    if (!reads.accept(kind, payload)) return;
    clearTimeout(timers.get(kind)); timers.delete(kind); render();
  }
  document.getElementById('resident-close').addEventListener('click', close);
  document.getElementById('resident-locate').addEventListener('click', () => { hideProfile(); hideDialogue(); clearKeys(); following = false; if (locating) { locating = false; stopFollowing(); } else { locating = true; locate(reads.selected, false); } renderContext(); });
  document.getElementById('resident-camera').addEventListener('click', () => { hideProfile(); hideDialogue(); clearKeys(); following = !following; if (following) locate(reads.selected, true); else stopFollowing(); renderContext(); });
  document.getElementById('resident-refresh').addEventListener('click', () => { send('detail'); send('history'); });
  document.getElementById('resident-older').addEventListener('click', () => send('history', true));
  function invite(intent) {
    if (!reads.selected) return;
    const input = activeDialogueInput || dialogueInput;
    const dialogue = intent === 'talk' ? input.value.trim() : '';
    if (intent === 'talk' && !dialogue) {
      document.getElementById('resident-invite-status').textContent = '请先输入想对村民说的话';
      input.focus();
      return;
    }
    const request_id = `${reads.epoch}:${++reads.sequence}`;
    document.getElementById('resident-invite-status').textContent = intent === 'cancel' ? '正在结束邀请…' : intent === 'follow' ? '正在邀请跟随…' : '正在邀请交谈…';
    if (intent === 'talk') {
      dialogueLines.push({ speaker: 'player', text: dialogue });
      input.value = '';
      renderDialogue();
    }
    host({ type: 'residentInviteRequested', npc_id: reads.selected, intent, dialogue, request_id, session_epoch: reads.epoch });
  }
  document.getElementById('resident-invite-talk').addEventListener('click', () => { if (contextDialogue && dialogueInput.value.trim()) contextDialogue.value = dialogueInput.value; hideProfile(); dialogueOpen = true; activeDialogueInput = contextDialogue || dialogueInput; renderDialogue(); invite('talk'); });
  document.getElementById('resident-invite-follow').addEventListener('click', () => { hideProfile(); hideDialogue(); invite('follow'); });
  document.getElementById('resident-invite-cancel').addEventListener('click', () => invite('cancel'));
  document.getElementById('context-talk')?.addEventListener('click', openDialogue);
  document.getElementById('context-follow')?.addEventListener('click', () => { hideProfile(); hideDialogue(); invite('follow'); });
  document.getElementById('context-locate')?.addEventListener('click', () => { hideProfile(); hideDialogue(); clearKeys(); if (locating) { locating = false; stopFollowing(); } else { locating = true; locate(reads.selected, false); } renderContext(); });
  document.getElementById('context-profile')?.addEventListener('click', openProfile);
  document.getElementById('context-dialogue-send')?.addEventListener('click', () => { activeDialogueInput = contextDialogue; invite('talk'); });
  photoButton?.addEventListener('click', () => { if (current() && !photoButton.disabled) photoInput?.click(); });
  photoInput?.addEventListener('change', () => {
    const file = photoInput.files?.[0]; if (!file || !reads.selected) return;
    const validationError = residentPhotoValidation(file);
    if (validationError) {
      document.getElementById('context-dialogue-status').textContent = validationError;
      photoInput.value = ''; return;
    }
    const reader = new FileReader();
    photoButton.disabled = true;
    document.getElementById('context-dialogue-status').textContent = '正在发送图片…';
    reader.onload = () => {
      const result = String(reader.result || ''); const comma = result.indexOf(',');
      if (comma < 0) { photoButton.disabled = false; return; }
      host({ type: 'residentPhotoRequested', npc_id: reads.selected, mime: file.type, file_name: file.name.slice(0, 80), image_base64: result.slice(comma + 1), request_id: `${reads.epoch}:${++reads.sequence}`, session_epoch: reads.epoch });
      photoInput.value = '';
    };
    reader.onerror = () => { photoButton.disabled = false; document.getElementById('context-dialogue-status').textContent = '图片读取失败。'; };
    reader.readAsDataURL(file);
  });
  function bindDialogueShortcut(input) {
    input?.addEventListener('keydown', event => {
      // Shift+Enter is the primary send shortcut. Keep Ctrl/Cmd+Enter as a
      // compatibility shortcut; plain Enter remains available for newlines.
      if (event.key === 'Enter' && (event.shiftKey || event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        activeDialogueInput = input;
        invite('talk');
      }
    });
  }
  bindDialogueShortcut(contextDialogue);
  bindDialogueShortcut(dialogueInput);
  document.getElementById('player-dialogue-close')?.addEventListener('click', hideDialogue);
  search.addEventListener('input', renderList);
  panel.addEventListener('focusin', clearKeys);
  return {
    reset(epoch) { close(); reads.reset(epoch); npcs = new Map(); listKey = ''; renderList(); },
    update(values) { npcs = values; captureRuntimeDialogue(values); if (reads.selected && !npcs.has(reads.selected)) close(); renderList(); renderRuntime(); renderContext(); renderDialogue(); },
    detail: payload => receive('detail', payload), history: payload => receive('history', payload),
    photoResult(payload) {
      if (payload?.session_epoch !== reads.epoch || payload.npc_id !== reads.selected) return;
      if (photoButton) photoButton.disabled = false;
      const status = document.getElementById('context-dialogue-status');
      if (payload.code) { status.textContent = payload.code === 'npc_busy' ? '我现在正在忙，请先等一等' : '图片发送失败，请稍后重试。'; return; }
      if (payload.extracted_text) dialogueLines.push({ speaker: 'player', text: `（图片内容）${payload.extracted_text}` });
      const reply = replyText(payload);
      if (reply) appendNpcDialogue(reply, payload.invitation?.task_id || payload.task_id || '');
      status.textContent = '村民已回复'; renderDialogue(); send('detail');
    },
    inviteResult(payload) {
      if (payload?.session_epoch !== reads.epoch || payload.npc_id !== reads.selected) return;
      const status = document.getElementById('resident-invite-status');
      status.textContent = payload.code ? ({ npc_busy: '我现在正在忙，请先等一等', npc_paused: '该村民的 Agent 已暂停', player_target_missing: '你已离线或不在共享小镇中', player_target_no_route: '村民暂时无法走到你身边', npc_invitation_forbidden: '这不是你发起的邀请', npc_invitation_not_found: '请先让村民走到你身边' }[payload.code] || '邀请未被接受') : (payload.invitation?.continued ? '村民回复了你' : '村民正在走来');
      if (!payload.code) {
        const invitation = payload.invitation || {};
        const npc = current();
        // The invitation response already contains the authoritative NPC
        // reply. Apply it immediately instead of waiting for the next snapshot
        // (which may arrive before the state_changed event reaches this client).
        const reply = replyText(payload);
        const isDialogue = payload.intent === 'talk' || invitation.action === 'player_talk' || invitation.state === 'player_talk';
        if (npc && isDialogue && typeof reply === 'string' && reply.trim()) {
          appendNpcDialogue(reply, invitation.task_id || payload.task_id || '');
          npc.runtime = { ...(npc.runtime || {}), state: invitation.state || npc.runtime?.state || 'player_talk', dialogue: reply, current_task_id: invitation.task_id || npc.runtime?.current_task_id };
          refreshNpc();
        }
        if (payload.intent === 'talk') { dialogueInput.value = ''; if (contextDialogue) contextDialogue.value = ''; renderDialogue(); }
        // The response is rendered immediately above. Also refresh the
        // confirmed conversation event so a delayed realtime snapshot or a
        // WebView bridge race cannot leave the second NPC reply out of the
        // dialogue panel after the next render.
        if (payload.intent === 'talk') send('history');
        send('detail');
      }
      render();
    },
    choose, nearby, selectedId: () => reads.selected, cancelCamera() { if (!following && !locating) return; following = false; locating = false; stopFollowing(); renderContext(); }
  };
}
