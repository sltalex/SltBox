import "./style.css";
import { invoke } from "@tauri-apps/api/core";
import { listen } from "@tauri-apps/api/event";
import { getCurrentWindow } from "@tauri-apps/api/window";
import { SevenPawsPet } from "./pet/sevenPaws.ts";
import { COATS } from "./pet/coats";
import { ReminderController } from "./reminder.ts";
import { defaultSettings } from "./defaults";
import type { AppSettings, CatInput, CustomCat } from "./types";
import { bootGuest, startSocialPresentation } from "./socialPet";

const isTauri = "__TAURI_INTERNALS__" in window || "__TAURI__" in window;

const stage = document.getElementById("stage") as HTMLDivElement;
const permNotice = document.getElementById("permNotice") as HTMLDivElement;

let settings: AppSettings = { ...defaultSettings };
let customCats: CustomCat[] = [];
let scene: SevenPawsPet;
let win7Mode = false;
const reminder = new ReminderController();
let lastReminderKey = "";

function syncReminder() {
  const key = `${settings.reminderEnabled}|${settings.reminderText}|${settings.reminderIntervalMinutes}`;
  if (key === lastReminderKey) return;
  lastReminderKey = key;
  reminder.configure(settings.reminderEnabled, settings.reminderText, settings.reminderIntervalMinutes);
}

function bindPointer() {
  const canvas = stage.querySelector("canvas");
  if (!canvas) return;
  let lastOverHead: boolean | null = null;

  canvas.addEventListener("pointerdown", async (e) => {
    if (e.button !== 0) return;
    if (scene.pickHead(e.clientX, e.clientY)) return;

    if (!isTauri) return;
    if (win7Mode) {
      await invoke("win7_begin_drag").catch(() => undefined);
      return;
    }
    try {
      await invoke("set_dragging", { dragging: true });
      await getCurrentWindow().startDragging();
    } catch {
      // The window position watcher persists the final position.
    } finally {
      await invoke("set_dragging", { dragging: false });
    }
  });

  canvas.addEventListener("pointermove", (e) => {
    const overHead = !!scene.pickHead(e.clientX, e.clientY);
    canvas.style.cursor = overHead ? "pointer" : "default";
    if (win7Mode && overHead !== lastOverHead) {
      lastOverHead = overHead;
      void invoke("win7_set_over_head", { over: overHead });
    }
  });
}

function listenGlobalKeysForDev() {
  if (!new URLSearchParams(location.search).has("localinput")) return;
  window.addEventListener("keydown", (e) => {
    if (e.repeat) return;
    scene.handleInput({ kind: "keyDown", code: e.code, repeat: false });
  });
  window.addEventListener("keyup", (e) => {
    scene.handleInput({ kind: "keyUp", code: e.code });
  });
  window.addEventListener("wheel", (e) => {
    if (!e.deltaY) return;
    scene.handleInput({ kind: "mouseWheel", delta: e.deltaY < 0 ? 1 : -1 });
  }, { passive: true });
}

function runDemo() {
  const script = ["KeyH", "KeyE", "KeyL", "KeyL", "KeyO", "Space", "KeyC", "KeyA", "KeyT"];
  let step = 0;
  const type = () => {
    const code = script[step++ % script.length];
    scene.handleInput({ kind: "keyDown", code, repeat: false });
    window.setTimeout(() => scene.handleInput({ kind: "keyUp", code }), 100);
    window.setTimeout(type, step % script.length === 0 ? 1800 : 240);
  };
  window.setTimeout(type, 500);
}

async function boot() {
  if (isTauri && new URLSearchParams(location.search).has("guest")) { await bootGuest(stage); return; }
  if (!isTauri) {
    document.documentElement.style.background = "#f6d97a";
    document.body.style.position = "relative";
    document.body.style.width = "660px";
    document.body.style.height = "480px";
    document.body.style.margin = "0";
    document.body.style.boxShadow = "inset 0 0 0 1px rgba(0,0,0,0.35)";
  }

  if (isTauri) {
    try {
      settings = await invoke<AppSettings>("get_settings");
    } catch {
      settings = { ...defaultSettings };
    }
    try {
      customCats = await invoke<CustomCat[]>("get_custom_cats");
    } catch {
      customCats = [];
    }
  }

  if (isTauri) {
    win7Mode = await invoke<boolean>("is_win7_mode").catch(() => false);
    if (!win7Mode && await invoke<boolean>("is_deskbox_managed").catch(() => false)) {
      document.body.classList.add("social-headroom");
    }
    if (win7Mode) {
      void invoke("win7_debug_log", { message: "frontend:win7_mode" }).catch(() => undefined);
    }
  }

  const params = new URLSearchParams(location.search);
  const debugCoat = params.get("previewCoat");
  if (debugCoat && COATS.some((c) => c.id === debugCoat)) settings.coat = debugCoat;

  try {
    scene = new SevenPawsPet(stage, settings, customCats, (regions) => {
      if (isTauri) void invoke("set_hit_regions", { regions });
    }, win7Mode);
  } catch (error) {
    if (isTauri) {
      void invoke("win7_debug_log", { message: `scene:init ${String(error)}` }).catch(() => undefined);
    }
    throw error;
  }
  if (win7Mode) {
    void invoke("win7_debug_log", { message: "frontend:scene_created" }).catch(() => undefined);
  }

  bindPointer();
  listenGlobalKeysForDev();
  syncReminder();

  document.addEventListener("contextmenu", (event) => {
    event.preventDefault();
    if (isTauri) {
      void invoke("open_settings");
    } else {
      window.open("settings.html", "_blank", "width=268,height=540");
    }
  });

  if (isTauri) {
    await listen<CatInput>("cat-input", (ev) => scene.handleInput(ev.payload));
    if (win7Mode) {
      await listen<{ kind: "move" | "down" | "up"; x: number; y: number; button: number }>("win7-pointer", (ev) => {
        const canvas = stage.querySelector("canvas");
        if (!canvas) return;
        const rect = canvas.getBoundingClientRect();
        const type = ev.payload.kind === "move" ? "pointermove" : ev.payload.kind === "down" ? "pointerdown" : "pointerup";
        canvas.dispatchEvent(new PointerEvent(type, {
          bubbles: true,
          cancelable: true,
          pointerId: 1,
          pointerType: "mouse",
          button: ev.payload.button,
          buttons: ev.payload.kind === "down" ? 1 : 0,
          clientX: rect.left + ev.payload.x * rect.width,
          clientY: rect.top + ev.payload.y * rect.height,
        }));
      });
    }
    await listen<AppSettings>("cat-settings", (ev) => {
      settings = ev.payload;
      scene.applySettings(settings, customCats);
      syncReminder();
    });
    await listen<CustomCat[]>("cat-cats", (ev) => {
      customCats = ev.payload;
      scene.applySettings(settings, customCats);
    });
    await listen<{ trusted: boolean }>("cat-input-status", (ev) => {
      permNotice.classList.toggle("hidden", ev.payload.trusted);
    });
  }

  window.addEventListener("resize", () => {
    scene.refreshHitRegions();
  });

  if (params.get("demo") === "1") runDemo();
  if (!isTauri) (window as unknown as { __pet: SevenPawsPet }).__pet = scene;
  scene.refreshHitRegions();
  if (isTauri && await invoke<boolean>("is_deskbox_managed").catch(() => false)) startSocialPresentation(scene);
}

void boot();
