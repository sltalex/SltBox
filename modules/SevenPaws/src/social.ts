import "./social.css";
import "./care.css";
import { getSocial, submitSocial, type SocialState, type PetSession } from "./socialBridge";
let state: SocialState = { connected: false, message: "正在连接…" };
let pending = "";
let pendingAt = 0;
let lastRender = "";
let receivedAt = Date.now();
let polling = false;
const el = (id: string) => document.getElementById(id)!;
const input = (id: string) => el(id) as HTMLInputElement;
function button(label: string, action: string, args: Record<string, unknown>, cls = "") {
  const b = document.createElement("button"); b.type = "button"; b.textContent = label; b.className = cls;
  b.disabled = !!pending || !state.connected;
  b.onclick = () => { void send(action, args); }; return b;
}
async function send(action: string, args: Record<string, unknown> = {}) {
  if (pending) return;
  try { pending = await submitSocial(action, args); pendingAt = Date.now(); el("status").textContent = "正在处理…"; render(true); }
  catch (e) { el("status").textContent = String(e); el("status").className = "error"; }
}
function row(title: string, note: string) {
  const r = document.createElement("div"); r.className = "row";
  const head = document.createElement("div"); head.className = "row-head";
  const name = document.createElement("span"); name.className = "row-title"; name.textContent = title;
  const detail = document.createElement("span"); detail.className = "hint"; detail.textContent = note;
  head.append(name, detail); r.append(head); return r;
}
function timer(s: PetSession) {
  const t = document.createElement("span"); t.className = "timer"; t.dataset.deadline = String(s.expires_at); return t;
}
function render(force = false) {
  const key = JSON.stringify([state.me,state.account_wallet,state.inventory,state.shop,state.rewards,state.directory,state.directory_more,state.sessions,state.events,state.connected,pending]);
  if (!force && key === lastRender) return;
  lastRender = key;
  for (const id of ["account", "socialContent", "careSection", "shopSection", "rewardsSection"]) el(id).hidden = !state.me || !state.connected;
  el("sessionSection").hidden = !state.sessions?.length || !state.connected;
  el("eventsSection").hidden = !state.events?.length || !state.connected;
  if (!state.me) return;
  el("petName").textContent = state.me.name; el("friendCode").textContent = state.me.friend_code ?? "";
  el("coins").textContent = String(state.me.coins ?? 0);
  renderCare();
  const today = new Date((state.server_time ?? 0) * 1000 + 8 * 3600000).toISOString().slice(0,10);
  (el("daily") as HTMLButtonElement).disabled = !!pending || state.me.reward_day === today;
  el("daily").textContent = state.me.reward_day === today ? "今日奖励已领取" : "领取今日 5 鱼干";
  for (const f of ["nameForm","browseForm"]) for (const b of el(f).querySelectorAll("button")) b.disabled = !!pending;
  const directory = el("directory"); directory.replaceChildren();
  if (!state.directory?.length) { const p = document.createElement("p"); p.className="empty"; p.textContent="暂时没有找到小猫。对方在 Box 开启小猫并登录后会出现在这里。"; directory.append(p); }
  for (const f of state.directory ?? []) {
    const status = f.presence === "online" ? "在线" : f.presence === "busy" ? "忙碌 · 陪伴中" : "离线";
    const r = row(f.name, `${f.username ?? "Box 账号"} · ${status}${f.care ? ` · Lv.${f.care.level}` : ""}`);
    r.dataset.presence = f.presence ?? "offline";
    const actions = document.createElement("div"); actions.className = "actions";
    const args = { target: f.user_id };
    const visit=button("去串门", "visit", args), focus=button("一起专注", "focus", args);
    if(f.presence==='offline') { visit.disabled=focus.disabled=true;visit.title=focus.title="对方尚未上线，可以先送鱼干"; }
    actions.append(button("送 3 鱼干", "gift", args),visit,focus);
    if (state.sessions?.some(s => s.status === "active" && s.other.user_id === f.user_id)) actions.append(button("挥爪", "wave", args), button("击掌", "highfive", args));
    r.append(actions); directory.append(r);
  }
  if (state.directory_more) { const p = document.createElement("p"); p.className="hint"; p.textContent="显示前 50 只小猫，可输入账号或名字查找更多。"; directory.append(p); }
  const sessions = el("sessions"); sessions.replaceChildren();
  for (const s of (state.sessions ?? []).filter(s => s.status === "active")) {
    const incoming = s.recipient === state.me.user_id;
    const text = s.kind === "visit" ? (incoming ? "来你桌面串门" : "去对方桌面串门") : "双人专注";
    const r = row(`${s.other.name} · ${text}`, "正在陪伴");
    r.append(timer(s));
    const actions = document.createElement("div"); actions.className = "actions";
    actions.append(button("结束陪伴", "session_end", { session: s.id }, "secondary"));
    r.append(actions); sessions.append(r);
  }
  const events = el("events"); events.replaceChildren();
  for (const e of state.events ?? []) {
    const otherId = e.sender === state.me?.user_id ? e.recipient : e.sender;
    const other = state.directory?.find(f => f.user_id === otherId) ?? state.sessions?.find(s => s.other.user_id === otherId)?.other;
    const descriptions: Record<string,string> = { wave:"一起挥爪", highfive:"一起击掌", feed:"吃了一份小鱼饭 · +10 经验", play:"玩毛线球 · +5 经验", focus_done:"专注完成 · +10 鱼干 / +15 经验", visit_start:"开始串门", focus_start:"开始双人专注", celebrate:"表演开心转圈", reward_2:"Lv.2 解锁开心转圈", reward_3:"Lv.3 获得贴心伙伴称号", reward_5:"Lv.5 获得爱心环绕特效" };
    const text = e.kind === "gift" ? (e.recipient === state.me.user_id ? "送来 3 鱼干" : "收到你的 3 鱼干") : descriptions[e.kind] ?? e.kind;
    const name = e.sender === e.recipient ? state.me.name : other?.name ?? (e.sender === state.me.user_id ? e.recipient_name : e.sender_name) ?? "另一只小猫";
    const date = new Date(Number(e.created_at)*1000).toLocaleString("zh-CN",{month:"numeric",day:"numeric",hour:"2-digit",minute:"2-digit"});
    events.append(row(name, `${text} · ${date}`));
  }
  updateTimers();
}
function renderCare() {
  const care = state.me?.care;
  el("careSection").hidden = !care || !state.connected;
  el("shopSection").hidden = !state.shop || !state.connected;
  if (!care) return;
  el("careStats").textContent = `Lv.${care.level}　饱食 ${care.hunger}/100　心情 ${care.mood}/100`;
  const progress = el("experience") as HTMLProgressElement;
  progress.max = care.level_goal; progress.value = care.level_progress;
  el("careHint").textContent = `距离升级还需 ${care.level_goal-care.level_progress} 经验 · 小鱼饭 ${state.inventory?.fish_meal ?? 0} 份`;
  const actions = el("careActions"); actions.replaceChildren();
  const feed = button("喂一份小鱼饭", "feed", {});
  feed.disabled ||= !(state.inventory?.fish_meal) || care.hunger >= 100;
  const play = button("玩毛线球", "play", {});
  const remaining = Math.max(0, care.last_play_at+600-(state.server_time ?? 0));
  play.disabled ||= !(state.inventory?.wool_ball) || remaining>0;
  if (remaining>0) play.textContent = `休息 ${Math.ceil(remaining/60)} 分钟后陪玩`;
  actions.append(feed,play);
  el("rewardsSection").hidden=!state.connected || !state.rewards;
  const rewards=el("rewards");rewards.replaceChildren();
  for(const reward of state.rewards ?? []) {
    const unlocked=care.level>=reward.level && (state.inventory?.[reward.id] ?? 0)>0;
    const r=row(reward.name,`Lv.${reward.level} · ${unlocked?"已解锁":"未解锁"}`);
    const description=document.createElement("p");description.className="hint";description.textContent=reward.description;r.append(description);
    if(unlocked) {
      if(reward.kind==='action') r.append(button("表演", "celebrate", {}));
      else { const equipped=care[reward.kind]===reward.id;r.append(button(equipped?"卸下":"装备","equip",{slot:reward.kind,item:equipped?"":reward.id},"secondary")); }
    }
    rewards.append(r);
  }
  const shop = el("shop"); shop.replaceChildren();
  const wallet=state.account_wallet;
  el("accountCoins").textContent=wallet?.initialized ? `账户金币余额：${wallet.coins}` : "请先在 Box 水族箱中初始化账户金币钱包。";
  for (const item of state.shop ?? []) {
    const owned = state.inventory?.[item.id] ?? 0;
    const r = row(item.name, `${item.price} 金币${owned ? ` · 已拥有 ${owned}` : ""}`);
    const description = document.createElement("p"); description.className = "hint"; description.textContent = item.description;
    r.append(description);
    const buttons = document.createElement("div"); buttons.className = "actions";
    const buy = button(item.kind !== "food" && owned>0 ? "已拥有" : `${item.price} 金币购买`, "buy", {item:item.id,currency:"account_coins"});
    buy.disabled ||= !wallet?.initialized || wallet.currency!=="account_coins" || wallet.coins<item.price || (item.kind !== "food" && owned>0) || (item.kind==="food" && owned>=99);
    buttons.append(buy);
    if ((item.kind === "badge" || item.kind === "effect") && owned>0) {
      const equipped = care[item.kind] === item.id;
      buttons.append(button(equipped ? "卸下" : "装备", "equip", {slot:item.kind,item:equipped?"":item.id},"secondary"));
    }
    r.append(buttons); shop.append(r);
  }
}
function updateTimers() {
  const now = (state.server_time ?? 0) + (Date.now() - receivedAt) / 1000;
  for (const t of document.querySelectorAll<HTMLElement>("[data-deadline]")) {
    const seconds = Math.max(0, Math.ceil(Number(t.dataset.deadline) - now));
    t.textContent = `${Math.floor(seconds / 60).toString().padStart(2,"0")}:${(seconds % 60).toString().padStart(2,"0")}`;
  }
}
el("browseForm").onsubmit = e => { e.preventDefault(); void send("browse", { search: input("browseInput").value.trim() }); };
el("nameForm").onsubmit = e => { e.preventDefault(); if (state.me) void send("profile", { name: input("name").value.trim(), revision: state.me.revision, outfit: state.me.outfit }); };
el("daily").onclick = () => { void send("daily"); };
el("refresh").onclick = () => { void poll(); };
async function poll() {
  if (polling) return;
  polling = true;
  try {
    const next = await getSocial();
    if (next.server_time !== state.server_time) receivedAt = Date.now();
    state = next;
    if (pending && state.result_id === pending) { pending = ""; el("status").textContent = state.result_message ?? "操作完成"; }
    else if (pending && Date.now() - pendingAt > 120000) { pending = ""; el("status").textContent = "操作等待超时，请刷新后确认结果。"; }
    else if (!pending) el("status").textContent = state.message;
    el("status").className = state.connected ? "" : "error"; render();
  } catch (e) { el("status").textContent = String(e); }
  finally { polling = false; }
}
setInterval(() => { void poll(); },1000); setInterval(updateTimers,250); void poll();
