import { invoke } from "@tauri-apps/api/core";
import { SevenPawsPet } from "./pet/sevenPaws";
import { defaultSettings } from "./defaults";
import { getSocial, type SocialState } from "./socialBridge";
import type { AppSettings } from "./types";
import "./care.css";

export function startSocialPresentation(scene: SevenPawsPet, guest = false) {
  const label = document.createElement("div"); label.className = "social-pet-label"; label.hidden = true; document.body.append(label);
  const seen = new Set<string>();
  const toast = document.createElement("div"); toast.className = "pet-toast"; toast.hidden = true; toast.setAttribute("role","status"); document.body.append(toast);
  const badge = document.createElement("div"); badge.className = "pet-badge"; badge.hidden = true; document.body.append(badge);
  const stars = document.createElement("div"); stars.className = "pet-stars"; stars.hidden = true;
  for (let i=0;i<3;i++) { const star=document.createElement("span"); star.textContent="✦"; stars.append(star); } document.body.append(stars);
  let accountKey = "";
  let firstSnapshot = true;
  let toastUntil = 0;
  const notices: string[] = [];
  let state: SocialState | undefined;
  let received = Date.now();
  let outfit = "";
  let stopped = false;
  let polling = false;
  let needReminderAt = 0;
  const poll = async () => {
    if (polling || stopped) return;
    polling = true;
    try {
      const next = await getSocial();
      if (next.server_time !== state?.server_time) received = Date.now();
      state = next;
      if (!state.connected) { toast.hidden=true; badge.hidden=true; stars.hidden=true; notices.length=0; }
      if (state.me && accountKey !== state.me.user_id) {
        accountKey=state.me.user_id; seen.clear(); firstSnapshot=true;notices.length=0;toast.hidden=true;toastUntil=0;needReminderAt=0;
        try { for(const id of JSON.parse(localStorage.getItem(`pet-seen-${accountKey}`) ?? "[]")) seen.add(String(id)); } catch { /* Invalid local cache starts fresh. */ }
      }
      const visitor = guest ? state.sessions?.find(s=>s.kind==="visit" && s.status==="active" && s.recipient===state?.me?.user_id)?.other : state.me;
      const care=visitor?.care;
      scene.setCare(state.connected && care ? care : null);
      badge.hidden=!state.connected || !['bell_badge','companion_title'].includes(care?.badge ?? '');
      badge.textContent=`${care?.badge==='companion_title'?'♡ 贴心伙伴':'🔔'} ${visitor?.name ?? "小猫"} · Lv.${care?.level ?? 1}`;
      const hearts=care?.effect==='heart_glow';
      stars.hidden=!state.connected || !['star_glow','heart_glow'].includes(care?.effect ?? '');
      stars.classList.toggle('pet-hearts',hearts);
      for(const star of stars.children) if(star.textContent!==(hearts?'♥':'✦')) star.textContent=hearts?'♥':'✦';
      if(!guest && state.connected && care && Date.now()>needReminderAt && (care.hunger<=25 || care.mood<=30)) {
        notices.push(care.hunger<=25?'肚子有点饿啦，想吃小鱼饭':'想和你玩一会儿毛线球');needReminderAt=Date.now()+600000;
      }
      if (guest) {
        const visit = state.sessions?.find(s => s.kind === "visit" && s.status === "active" && s.recipient === state?.me?.user_id);
        if (visit && state.connected && outfit !== JSON.stringify(visit.other.outfit)) {
          outfit = JSON.stringify(visit.other.outfit);
          const settings = await invoke<AppSettings>("get_settings");
          scene.applySettings({ ...settings, coat: visit.other.outfit.coat, showKeyLabels: false, purrPet: false, purrSleep: false, reminderEnabled: false }, visit.other.outfit.cats);
        }
      }
      for (const e of state.connected ? [...state.events ?? []].reverse() : []) {
        if (seen.has(e.id)) continue;
        seen.add(e.id);
        const age=(state.server_time ?? 0)-Number(e.created_at);
        if (!guest && (!firstSnapshot || age<30) && age<604800) {
          const descriptions: Record<string,string> = {
            gift:`${e.sender_name ?? "另一只小猫"}送来了 3 鱼干`,
            visit_start:`${e.sender_name ?? "另一只小猫"}来串门啦`,
            focus_start:`和${e.sender_name ?? "另一只小猫"}开始一起专注`,
            focus_done:"专注完成！+10 鱼干，+15 经验", feed:"开饭啦！+10 经验", play:"毛线球真好玩！+5 经验",
            reward_2:"升到 Lv.2！解锁开心转圈",reward_3:"升到 Lv.3！获得贴心伙伴名牌",reward_5:"升到 Lv.5！获得爱心环绕特效"
          };
          if(descriptions[e.kind] && (e.recipient===state.me?.user_id || e.sender===e.recipient)) notices.push(descriptions[e.kind]);
        }
        if (age>8 || age<0) continue;
        if(['feed','play','celebrate'].includes(e.kind) && e.sender===visitor?.user_id) scene.performSocial(e.kind,Math.min(age,1.2));
        if (e.kind === "wave" || e.kind === "highfive") {
          const incoming=state.sessions?.find(s=>s.kind==='visit' && s.status==='active' && s.recipient===state?.me?.user_id);
          if(!guest || (visitor && [e.sender,e.recipient].includes(visitor.user_id))) scene.performSocial(e.kind,Math.min(age,1.2),guest?1:incoming?-1:1);
        }
      }
      if(state.connected) {
        firstSnapshot=false;
        try { localStorage.setItem(`pet-seen-${accountKey}`,JSON.stringify([...seen].slice(-100))); } catch { /* Private storage may be unavailable. */ }
      }
      if(notices.length>5) notices.splice(0,notices.length-5);
      if (seen.size > 200) { const keep = [...seen].slice(-100); seen.clear(); keep.forEach(id => seen.add(id)); }
      update();
    } catch { label.hidden = true; }
    finally { polling = false; }
  };
  function update() {
    if (!state?.connected) { label.hidden = true; return; }
    const now = (state.server_time ?? 0) + (Date.now() - received) / 1000;
    if(Date.now()>=toastUntil) {
      const next=notices.shift(); toast.hidden=!next;
      if(next) { toast.textContent=next; toastUntil=Date.now()+6000; }
    }
    const focus = state.sessions?.find(s => s.kind === "focus" && s.status === "active" && s.expires_at > now);
    const visit = state.sessions?.find(s => s.kind === "visit" && s.status === "active" && s.expires_at > now);
    const current = guest ? visit : focus ?? visit;
    label.hidden = !current;
    if (current) {
      const sec = Math.max(0, Math.ceil(current.expires_at - now));
      label.textContent = `${guest ? current.other.name : current.kind === "focus" ? "一起专注" : "串门中"} ${Math.floor(sec/60)}:${(sec%60).toString().padStart(2,"0")}`;
    }
  }
  const interval = setInterval(() => { void poll(); },1000);
  const timer = setInterval(update,500);
  window.addEventListener("beforeunload", () => { stopped = true; clearInterval(interval); clearInterval(timer); });
  void poll();
}

export async function bootGuest(stage: HTMLDivElement) {
  if (!await invoke<boolean>("is_win7_mode").catch(() => false)) document.body.classList.add("social-headroom");
  const settings = await invoke<AppSettings>("get_settings").catch(() => defaultSettings);
  const state = await getSocial();
  const visit = state.sessions?.find(s => s.kind === "visit" && s.status === "active" && s.recipient === state.me?.user_id);
  if (!visit) return;
  const scene = new SevenPawsPet(stage, { ...settings, coat: visit.other.outfit.coat, showKeyLabels: false, purrPet: false, purrSleep: false }, visit.other.outfit.cats, () => {});
  startSocialPresentation(scene, true);
  window.addEventListener("resize", () => scene.refreshHitRegions());
  window.addEventListener("beforeunload", () => scene.destroy());
}
