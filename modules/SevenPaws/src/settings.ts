import "./style.css";
import { invoke } from "@tauri-apps/api/core";
import { listen } from "@tauri-apps/api/event";
import { getCurrentWindow } from "@tauri-apps/api/window";
import { COATS } from "./pet/coats";
import { defaultSettings } from "./defaults";
import type { AppSettings, CatPattern, CatSpot, CustomCat } from "./types";

const isTauri = "__TAURI_INTERNALS__" in window || "__TAURI__" in window;
const isWindows = /Windows/i.test(navigator.userAgent);
const socialBtn = document.getElementById("socialBtn") as HTMLButtonElement;
if (isTauri) void invoke<boolean>("is_deskbox_managed").then(managed => {
  socialBtn.hidden = !managed;
  socialBtn.onclick = () => { void invoke("open_social"); };
});

const menuEl = document.getElementById("menu") as HTMLDivElement;
const coatsEl = document.getElementById("coats") as HTMLDivElement;
const scaleSlider = document.getElementById("scaleSlider") as HTMLInputElement;
const scaleValue = document.getElementById("scaleValue") as HTMLSpanElement;
const opacitySlider = document.getElementById("opacitySlider") as HTMLInputElement;
const opacityValue = document.getElementById("opacityValue") as HTMLSpanElement;
const sleepSelect = document.getElementById("sleepSelect") as HTMLButtonElement;
const layoutSelect = document.getElementById("layoutSelect") as HTMLButtonElement;
const sleepLabel = sleepSelect.querySelector(".dropdown-label") as HTMLSpanElement;
const layoutLabel = layoutSelect.querySelector(".dropdown-label") as HTMLSpanElement;
const leftMouseInput = document.getElementById("leftMouse") as HTMLInputElement;
const alwaysOnTopInput = document.getElementById("alwaysOnTop") as HTMLInputElement;
const clickThroughLockedInput = document.getElementById("clickThroughLocked") as HTMLInputElement;
const showTrayIconInput = document.getElementById("showTrayIcon") as HTMLInputElement;
const autostartInput = document.getElementById("autostart") as HTMLInputElement;
const runAsAdminInput = document.getElementById("runAsAdmin") as HTMLInputElement;
const privacyInput = document.getElementById("privacyMode") as HTMLInputElement;
const purrPetInput = document.getElementById("purrPet") as HTMLInputElement;
const purrSleepInput = document.getElementById("purrSleep") as HTMLInputElement;
const idleActionsInput = document.getElementById("idleActions") as HTMLInputElement;
const labelsInput = document.getElementById("showKeyLabels") as HTMLInputElement;
const reminderEnabledInput = document.getElementById("reminderEnabled") as HTMLInputElement;
const reminderTextInput = document.getElementById("reminderText") as HTMLInputElement;
const reminderIntervalInput = document.getElementById("reminderInterval") as HTMLInputElement;
const mcpEnabledInput = document.getElementById("mcpEnabled") as HTMLInputElement;
const mcpClientSelect = document.getElementById("mcpClientSelect") as HTMLButtonElement;
const mcpClientLabel = mcpClientSelect.querySelector(".dropdown-label") as HTMLSpanElement;
const mcpCopyBtn = document.getElementById("mcpCopyBtn") as HTMLButtonElement;
const mcpLocation = document.getElementById("mcpLocation") as HTMLSpanElement;
const updateBtn = document.getElementById("updateBtn") as HTMLButtonElement;
const githubBtn = document.getElementById("githubBtn") as HTMLButtonElement;
const appVersionEl = document.getElementById("appVersion") as HTMLSpanElement;
const resetBtn = document.getElementById("resetPosBtn") as HTMLButtonElement;
const quitBtn = document.getElementById("quitBtn") as HTMLButtonElement;
const shortcutSection = document.getElementById("shortcutSection") as HTMLDivElement;
const createShortcutBtn = document.getElementById("createShortcutBtn") as HTMLButtonElement;
const shortcutHint = document.getElementById("shortcutHint") as HTMLDivElement;
const gameSection = document.getElementById("gameSection") as HTMLDivElement;
const restartAdminBtn = document.getElementById("restartAdminBtn") as HTMLButtonElement;
const gameHint = document.getElementById("gameHint") as HTMLDivElement;
const closeBtn = document.getElementById("closeBtn") as HTMLButtonElement;
const headEl = menuEl.querySelector<HTMLElement>(".menu-head");
const customCoatEl = document.getElementById("customCoat") as HTMLDivElement;
const patternSelect = document.getElementById("patternSelect") as HTMLButtonElement;
const patternLabel = patternSelect.querySelector(".dropdown-label") as HTMLSpanElement;
const photoBtn = document.getElementById("photoBtn") as HTMLButtonElement;
const photoInput = document.getElementById("photoInput") as HTMLInputElement;
const catNameInput = document.getElementById("catName") as HTMLInputElement;
const spotsStatus = document.getElementById("spotsStatus") as HTMLSpanElement;
const spotsClearBtn = document.getElementById("spotsClearBtn") as HTMLButtonElement;
const coatCodeInput = document.getElementById("coatCodeInput") as HTMLInputElement;
const coatCodeGen = document.getElementById("coatCodeGen") as HTMLButtonElement;
const coatCodeImport = document.getElementById("coatCodeImport") as HTMLButtonElement;
const deleteCatBtn = document.getElementById("deleteCatBtn") as HTMLButtonElement;
const colorSlotButtons = Array.from(document.querySelectorAll<HTMLButtonElement>(".color-slot"));

type ColorSlot = keyof Pick<CustomCat, "fur" | "patch" | "accent" | "eye" | "eye2">;

let settings: AppSettings = { ...defaultSettings };
let customCats: CustomCat[] = [];
let coatGridKey = "";
let deleteArmedAt = 0;
let appVersion = "dev";
let reminderTextTimer: number | null = null;
let reminderIntervalTimer: number | null = null;
let photoPickerOpen = false;

function setPhotoPickerOpen(open: boolean) {
  if (photoPickerOpen === open) return;
  photoPickerOpen = open;
  // The picker is a native window, so the global mouse hook sees clicks in it
  // as "outside the settings window"; tell Rust to hold off auto-closing.
  if (isTauri) void invoke("set_settings_modal", { open }).catch(() => {});
}

function disarmDelete() {
  deleteArmedAt = 0;
  deleteCatBtn.textContent = "删除这只猫";
}

interface Choice {
  value: string;
  label: string;
  badge?: string;
}

const layoutChoices: Choice[] = [
  { value: "65", label: "65% 紧凑" },
  { value: "104", label: "104 键全尺寸" },
];
const sleepChoices: Choice[] = [
  { value: "1", label: "1 分钟" },
  { value: "3", label: "3 分钟" },
  { value: "5", label: "5 分钟" },
  { value: "10", label: "10 分钟" },
  { value: "30", label: "30 分钟" },
  { value: "0", label: "不睡" },
];
const patternChoices: Choice[] = [
  { value: "solid", label: "纯色" },
  { value: "bicolor", label: "双色" },
  { value: "tabby", label: "虎斑" },
  { value: "calico", label: "三花" },
];

type McpClientId =
  | "qoder"
  | "claudeCode"
  | "cursor"
  | "codebuddy"
  | "zcode"
  | "trae"
  | "vscode"
  | "windsurf"
  | "codex"
  | "deepseekHarness"
  | "opencode"
  | "pi"
  | "cherryStudio"
  | "workbuddy"
  | "antigravity";

interface McpClient {
  id: McpClientId;
  name: string;
  location: string;
}

// Same clients and order as SevenShell: Qoder first with a 荐 badge.
const MCP_CLIENTS: McpClient[] = [
  { id: "qoder", name: "Qoder", location: "~/.qoder/mcp.json" },
  { id: "claudeCode", name: "Claude Code", location: ".mcp.json" },
  { id: "cursor", name: "Cursor", location: ".cursor/mcp.json" },
  { id: "codebuddy", name: "CodeBuddy Code CLI", location: "~/.codebuddy/.mcp.json" },
  { id: "zcode", name: "ZCode", location: "Settings → MCP Servers" },
  { id: "trae", name: "TRAE", location: "Settings → MCP" },
  { id: "vscode", name: "VS Code", location: ".vscode/mcp.json" },
  { id: "windsurf", name: "Windsurf", location: "~/.codeium/windsurf/mcp_config.json" },
  { id: "codex", name: "Codex", location: "~/.codex/config.toml" },
  { id: "deepseekHarness", name: "DeepSeek Harness", location: "$DSH_HOME/profiles/web/cordis.patch.yml" },
  { id: "opencode", name: "OpenCode", location: "~/.config/opencode/opencode.json" },
  { id: "pi", name: "Pi", location: "~/.pi/agent/mcp.json" },
  { id: "cherryStudio", name: "Cherry Studio", location: "Settings → MCP Servers" },
  { id: "workbuddy", name: "WorkBuddy", location: "~/.workbuddy/mcp.json" },
  { id: "antigravity", name: "Antigravity", location: "~/.gemini/config/mcp_config.json" },
];

let mcpClient: McpClientId = "qoder";
let mcpCommand = "7paws.exe";

function mcpClientInfo(id: McpClientId): McpClient {
  return MCP_CLIENTS.find((c) => c.id === id) ?? MCP_CLIENTS[0];
}

function mcpSnippet(id: McpClientId): string {
  const command = mcpCommand;
  const args = ["--mcp"];
  switch (id) {
    case "vscode":
      return JSON.stringify(
        { servers: { "7paws": { type: "stdio", command, args } } },
        null,
        2,
      );
    case "cherryStudio":
      return JSON.stringify(
        {
          mcpServers: {
            "7paws": {
              name: "7paws",
              description: "",
              baseUrl: "",
              command,
              args,
              isActive: true,
              type: "stdio",
            },
          },
        },
        null,
        2,
      );
    case "codex":
      return [
        "[mcp_servers.7paws]",
        `command = ${JSON.stringify(command)}`,
        'args = ["--mcp"]',
      ].join("\n");
    case "deepseekHarness":
      return [
        "- insert:",
        "    - id: mcp-7paws",
        "      name: '@deepseek-ai/dsh-mcp-client'",
        "      config:",
        "        serverName: 7paws",
        "        transport: stdio",
        `        command: ${JSON.stringify(command)}`,
        '        args: ["--mcp"]',
      ].join("\n");
    case "opencode":
      return JSON.stringify(
        { mcp: { "7paws": { type: "local", command: [command, "--mcp"] } } },
        null,
        2,
      );
    default:
      return JSON.stringify(
        { mcpServers: { "7paws": { command, args } } },
        null,
        2,
      );
  }
}

function syncMcpClient() {
  const info = mcpClientInfo(mcpClient);
  mcpClientLabel.textContent = info.name;
  mcpLocation.textContent = `写进 ${info.location}`;
}

async function copyToClipboard(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    // Older WKWebView or denied permission: fall back to a hidden textarea.
  }
  try {
    const area = document.createElement("textarea");
    area.value = text;
    area.style.position = "fixed";
    area.style.opacity = "0";
    document.body.appendChild(area);
    area.select();
    const ok = document.execCommand("copy");
    area.remove();
    return ok;
  } catch {
    return false;
  }
}

async function copyMcpSnippet() {
  const ok = await copyToClipboard(mcpSnippet(mcpClient));
  mcpCopyBtn.textContent = ok ? "已复制" : "复制失败";
  window.setTimeout(() => {
    mcpCopyBtn.textContent = "复制";
  }, 1600);
}

const updatePopup = document.createElement("div");
updatePopup.className = "update-popup hidden";
updatePopup.innerHTML = `<span class="update-text"></span><button class="btn ghost update-go" type="button" hidden>前往发布页</button>`;
document.body.appendChild(updatePopup);
const updateText = updatePopup.querySelector<HTMLSpanElement>(".update-text")!;
const updateGoBtn = updatePopup.querySelector<HTMLButtonElement>(".update-go")!;

let updateBusy = false;
let updateTimer = 0;

function closeUpdatePopup() {
  updatePopup.classList.add("hidden");
  window.clearTimeout(updateTimer);
}

function showUpdatePopup(message: string, hasUpdate: boolean) {
  closeUpdatePopup();
  updateText.textContent = message;
  updateGoBtn.hidden = !hasUpdate;
  const menuRect = menuEl.getBoundingClientRect();
  const margin = 8;
  updatePopup.style.width = `${Math.min(188, Math.max(140, menuRect.width - margin * 2))}px`;
  updatePopup.classList.remove("hidden");
  const width = updatePopup.offsetWidth;
  const left = Math.min(
    Math.max(menuRect.left + (menuRect.width - width) / 2, menuRect.left + margin),
    menuRect.right - width - margin,
  );
  updatePopup.style.left = `${Math.round(left)}px`;
  updatePopup.style.top = `${Math.round(updateBtn.getBoundingClientRect().bottom + 6)}px`;
  // The update notice waits for the user; status messages fade out on their own.
  if (!hasUpdate) {
    updateTimer = window.setTimeout(closeUpdatePopup, 4000);
  }
}

function parseVersion(value: string): number[] {
  return value
    .replace(/^v/i, "")
    .split("-")[0]
    .split(".")
    .map((part) => parseInt(part, 10) || 0);
}

function isNewerVersion(remote: string, current: string): boolean {
  const a = parseVersion(remote);
  const b = parseVersion(current);
  for (let i = 0; i < Math.max(a.length, b.length); i += 1) {
    if ((a[i] ?? 0) !== (b[i] ?? 0)) return (a[i] ?? 0) > (b[i] ?? 0);
  }
  return false;
}

// User-initiated only: no telemetry. jsDelivr mirrors the repo tags with CORS
// and no API quota; the GitHub releases API is only a fallback (it rate-limits
// unauthenticated callers, which shared IPs hit quickly).
async function fetchLatestVersion(signal: AbortSignal): Promise<string | null> {
  try {
    const response = await fetch("https://data.jsdelivr.com/v1/packages/gh/7wdn/7paws", { signal });
    if (response.ok) {
      const data = (await response.json()) as { versions?: { version?: string }[] };
      let latest = "";
      for (const entry of data.versions ?? []) {
        const version = String(entry.version ?? "").trim();
        if (version && (!latest || isNewerVersion(version, latest))) latest = version;
      }
      if (latest) return latest.startsWith("v") ? latest : `v${latest}`;
    }
  } catch {
    // Fall through to the releases API.
  }
  const response = await fetch("https://api.github.com/repos/7wdn/7paws/releases/latest", {
    signal,
    headers: { Accept: "application/vnd.github+json" },
  });
  if (!response.ok) return null;
  const release = (await response.json()) as { tag_name?: string };
  const tag = String(release.tag_name ?? "").trim();
  return tag || null;
}

function updateErrorMessage(error: unknown): string {
  if (error instanceof DOMException && error.name === "AbortError") {
    return "检查超时，请检查网络后重试";
  }
  if (error instanceof TypeError) {
    return "网络连接失败，请检查网络或代理后重试";
  }
  return "检查失败，请稍后再试";
}

async function checkForUpdates() {
  if (updateBusy) return;
  updateBusy = true;
  updateBtn.classList.add("spinning");
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), 8000);
  try {
    const latest = await fetchLatestVersion(controller.signal);
    if (!latest) {
      showUpdatePopup("检查失败，版本服务暂时不可用", false);
      return;
    }
    const current = appVersion === "dev" ? "0.0.0" : appVersion;
    const currentLabel = appVersion === "dev" ? "开发预览" : `v${appVersion}`;
    if (isNewerVersion(latest, current)) {
      showUpdatePopup(`发现新版本 ${latest}（当前 ${currentLabel}）`, true);
    } else {
      showUpdatePopup(`已是最新版本（${currentLabel}）`, false);
    }
  } catch (error) {
    showUpdatePopup(updateErrorMessage(error), false);
  } finally {
    window.clearTimeout(timer);
    updateBtn.classList.remove("spinning");
    updateBusy = false;
  }
}

const dropdownPopup = document.createElement("div");
dropdownPopup.className = "dropdown-popup hidden";
dropdownPopup.setAttribute("role", "listbox");
document.body.appendChild(dropdownPopup);

let openSelect: HTMLButtonElement | null = null;

function closeDropdown() {
  if (!openSelect) return;
  openSelect.classList.remove("open");
  openSelect.setAttribute("aria-expanded", "false");
  openSelect = null;
  dropdownPopup.classList.add("hidden");
}

function positionPopup(button: HTMLButtonElement) {
  const rect = button.getBoundingClientRect();
  const width = Math.max(rect.width, 150);
  dropdownPopup.style.width = `${width}px`;
  let left = rect.left;
  if (left + width > window.innerWidth - 8) left = window.innerWidth - width - 8;
  let top = rect.bottom + 6;
  if (top + dropdownPopup.offsetHeight > window.innerHeight - 8) {
    top = rect.top - dropdownPopup.offsetHeight - 6;
  }
  dropdownPopup.style.left = `${Math.max(8, left)}px`;
  dropdownPopup.style.top = `${Math.max(8, top)}px`;
}

function openDropdown(
  button: HTMLButtonElement,
  choices: Choice[],
  current: () => string,
  pick: (value: string) => void,
) {
  if (openSelect === button) {
    closeDropdown();
    return;
  }
  closeColorPopup();
  closeDropdown();
  openSelect = button;
  button.classList.add("open");
  button.setAttribute("aria-expanded", "true");
  dropdownPopup.innerHTML = "";
  for (const choice of choices) {
    const option = document.createElement("button");
    option.type = "button";
    option.className = "dropdown-option";
    option.setAttribute("role", "option");
    option.setAttribute("aria-selected", String(choice.value === current()));
    const label = document.createElement("span");
    label.textContent = choice.label;
    option.appendChild(label);
    if (choice.badge) {
      const badge = document.createElement("span");
      badge.className = "rec-badge";
      badge.textContent = choice.badge;
      option.appendChild(badge);
    }
    option.addEventListener("click", () => {
      pick(choice.value);
      closeDropdown();
    });
    dropdownPopup.appendChild(option);
  }
  dropdownPopup.classList.remove("hidden");
  positionPopup(button);
}

const COLOR_SWATCHES = [
  "#151515", "#2b2620", "#3f3324", "#4d3d2b", "#7a4312", "#8b8f86",
  "#a08a6c", "#b5b1a2", "#c26a1f", "#e9973f", "#e2b93b", "#c79a2e",
  "#8090a0", "#667686", "#5c8a3a", "#5aa9c9", "#fbe8c9", "#f8f6ef",
];

const colorPopup = document.createElement("div");
colorPopup.className = "color-popup hidden";
colorPopup.innerHTML = `<div class="color-grid"></div><input class="color-hex" type="text" maxlength="7" spellcheck="false" aria-label="颜色值" />`;
document.body.appendChild(colorPopup);
const colorGrid = colorPopup.querySelector<HTMLDivElement>(".color-grid")!;
const colorHex = colorPopup.querySelector<HTMLInputElement>(".color-hex")!;

let colorSlot: ColorSlot | null = null;

for (const swatch of COLOR_SWATCHES) {
  const option = document.createElement("button");
  option.type = "button";
  option.style.background = swatch;
  option.title = swatch;
  option.addEventListener("click", () => pickColor(swatch));
  colorGrid.appendChild(option);
}

function closeColorPopup() {
  if (!colorSlot) return;
  colorSlot = null;
  colorPopup.classList.add("hidden");
}

function pickColor(hex: string) {
  if (!colorSlot) return;
  const slot = colorSlot;
  closeColorPopup();
  saveActiveCat({ [slot]: hex } as Partial<CustomCat>);
}

function openColorPopup(button: HTMLButtonElement, slot: ColorSlot) {
  const cat = activeCat();
  if (!cat) return;
  closeDropdown();
  if (colorSlot === slot) {
    closeColorPopup();
    return;
  }
  closeColorPopup();
  colorSlot = slot;
  colorHex.value = cat[slot];
  colorPopup.classList.remove("hidden");
  const rect = button.getBoundingClientRect();
  const width = colorPopup.offsetWidth;
  const height = colorPopup.offsetHeight;
  let left = rect.left;
  if (left + width > window.innerWidth - 8) left = window.innerWidth - width - 8;
  let top = rect.bottom + 6;
  if (top + height > window.innerHeight - 8) top = rect.top - height - 6;
  colorPopup.style.left = `${Math.max(8, left)}px`;
  colorPopup.style.top = `${Math.max(8, top)}px`;
}

colorHex.addEventListener("keydown", (e) => {
  if (e.key === "Enter") colorHex.blur();
});
colorHex.addEventListener("change", () => {
  const raw = colorHex.value.trim();
  const hex = raw.startsWith("#") ? raw : `#${raw}`;
  const cat = activeCat();
  if (/^#[0-9a-fA-F]{6}$/.test(hex)) {
    pickColor(hex.toLowerCase());
  } else if (colorSlot && cat) {
    colorHex.value = cat[colorSlot];
  }
});

function activeCat(): CustomCat | null {
  return customCats.find((c) => c.id === settings.coat) ?? null;
}

function coatSwatch(cat: CustomCat): string {
  if (cat.pattern === "solid") return cat.fur;
  const second = cat.pattern === "calico" ? cat.accent : cat.patch;
  return `linear-gradient(135deg, ${cat.fur} 50%, ${second} 50%)`;
}

function hexOf(rgb: number[]): string {
  return `#${rgb
    .map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, "0"))
    .join("")}`;
}

// Local-only palette extraction: downsample, drop the edges, cluster the pixels
// and pick the largest colour plus the two most distinct ones. No photo leaves the device.
async function extractColorsFromPhoto(
  file: File,
): Promise<{ fur: string; patch: string; accent: string; pattern: CatPattern }> {
  // createImageBitmap is missing from older WKWebView, so decode through an <img>.
  const url = URL.createObjectURL(file);
  let image: HTMLImageElement;
  try {
    image = await new Promise<HTMLImageElement>((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error("decode failed"));
      img.src = url;
    });
  } finally {
    URL.revokeObjectURL(url);
  }
  const width = 96;
  const height = Math.max(16, Math.round((image.height / image.width) * width));
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  if (!ctx) throw new Error("canvas unavailable");
  ctx.drawImage(image, 0, 0, width, height);
  const { data } = ctx.getImageData(0, 0, width, height);
  const samples: number[][] = [];
  for (let y = Math.floor(height * 0.08); y < height * 0.92; y += 1) {
    for (let x = Math.floor(width * 0.08); x < width * 0.92; x += 1) {
      const i = (y * width + x) * 4;
      if (data[i + 3] < 200) continue;
      samples.push([data[i], data[i + 1], data[i + 2]]);
    }
  }
  if (samples.length < 32) throw new Error("no pixels");
  const centers = [0.12, 0.4, 0.68, 0.9].map((f) => [...samples[Math.floor(f * (samples.length - 1))]]);
  const counts = new Array(centers.length).fill(0);
  for (let iter = 0; iter < 12; iter += 1) {
    const sums = centers.map(() => [0, 0, 0]);
    counts.fill(0);
    for (const sample of samples) {
      let best = 0;
      let bestDist = Infinity;
      for (let k = 0; k < centers.length; k += 1) {
        const c = centers[k];
        const dist = (sample[0] - c[0]) ** 2 + (sample[1] - c[1]) ** 2 + (sample[2] - c[2]) ** 2;
        if (dist < bestDist) {
          bestDist = dist;
          best = k;
        }
      }
      counts[best] += 1;
      sums[best][0] += sample[0];
      sums[best][1] += sample[1];
      sums[best][2] += sample[2];
    }
    for (let k = 0; k < centers.length; k += 1) {
      if (counts[k]) centers[k] = sums[k].map((v) => v / counts[k]);
    }
  }
  const ranked = centers.map((c, k) => ({ c, n: counts[k] })).sort((a, b) => b.n - a.n);
  const distance = (a: number[], b: number[]) => Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);
  const fur = ranked[0].c;
  const patch = ranked.slice(1).find((r) => distance(r.c, fur) > 60)?.c;
  const accent = ranked.slice(1).find((r) => distance(r.c, fur) > 60 && (!patch || distance(r.c, patch) > 60))?.c;
  const pattern: CatPattern = accent ? "calico" : patch ? "bicolor" : "solid";
  return {
    fur: hexOf(fur),
    patch: hexOf(patch ?? fur),
    accent: hexOf(accent ?? patch ?? fur),
    pattern,
  };
}

function commitReminderText() {
  void saveSettings({ reminderText: reminderTextInput.value });
}

function commitReminderInterval() {
  const minutes = Math.floor(Number(reminderIntervalInput.value));
  void saveSettings({
    reminderIntervalMinutes: Number.isFinite(minutes) && minutes > 0 ? Math.min(minutes, 1440) : 1,
  });
}

function flushReminderInputs() {
  if (reminderTextTimer !== null) {
    window.clearTimeout(reminderTextTimer);
    reminderTextTimer = null;
    commitReminderText();
  }
  if (reminderIntervalTimer !== null) {
    window.clearTimeout(reminderIntervalTimer);
    reminderIntervalTimer = null;
    commitReminderInterval();
  }
}

function hide() {
  flushReminderInputs();
  closeDropdown();
  if (isTauri) {
    void invoke("close_settings");
  } else {
    window.close();
  }
}

function menuButtonSelected(coatId: string) {
  for (const btn of coatsEl.querySelectorAll<HTMLButtonElement>(".coat-btn")) {
    btn.setAttribute("aria-pressed", String(btn.dataset.coat === coatId));
  }
}

function buildCoatButtons() {
  coatsEl.innerHTML = "";
  for (const coat of COATS) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "coat-btn";
    btn.dataset.coat = coat.id;
    const swatch = coat.pattern === "calico"
      ? `conic-gradient(${coat.fur} 0 40%, ${coat.patchA ?? "#e9973f"} 40% 70%, ${coat.patchB ?? "#1d1c1a"} 70%)`
      : coat.pattern === "tabby"
        ? `linear-gradient(180deg, ${coat.fur} 62%, ${coat.stripe ?? coat.fur} 62%)`
        : coat.pattern === "point"
          ? `radial-gradient(circle at 50% 58%, ${coat.tail ?? coat.paw} 0 30%, ${coat.fur} 38%)`
          : coat.paw === coat.fur
            ? coat.fur
            : `linear-gradient(135deg, ${coat.fur} 55%, ${coat.paw} 55%)`;
    const sw = document.createElement("span");
    sw.className = "coat-swatch";
    sw.style.background = swatch;
    const label = document.createElement("span");
    label.className = "coat-name";
    label.textContent = coat.name;
    btn.append(sw, label);
    btn.title = coat.name;
    btn.addEventListener("click", () => {
      if (settings.coat === coat.id) return;
      void saveSettings({ coat: coat.id });
    });
    coatsEl.appendChild(btn);
  }
  for (const cat of customCats) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "coat-btn";
    btn.dataset.coat = cat.id;
    const sw = document.createElement("span");
    sw.className = "coat-swatch";
    sw.style.background = coatSwatch(cat);
    const label = document.createElement("span");
    label.className = "coat-name";
    label.textContent = cat.name;
    btn.append(sw, label);
    btn.title = cat.name;
    btn.addEventListener("click", () => {
      if (settings.coat === cat.id) return;
      void saveSettings({ coat: cat.id });
    });
    coatsEl.appendChild(btn);
  }
  const add = document.createElement("button");
  add.type = "button";
  add.className = "coat-btn coat-add";
  add.textContent = "＋ 新建";
  add.addEventListener("click", () => void createCat());
  coatsEl.appendChild(add);
  menuButtonSelected(settings.coat);
}

function syncCoatGrid() {
  const key = JSON.stringify(customCats.map((c) => [c.id, c.name, c.fur, c.patch, c.accent, c.pattern]));
  if (key === coatGridKey) return;
  coatGridKey = key;
  buildCoatButtons();
}

function syncControls() {
  disarmDelete();
  scaleSlider.value = String(Math.round(settings.scale * 100));
  scaleValue.textContent = `${Math.round(settings.scale * 100)}%`;
  opacitySlider.value = String(Math.round(settings.opacity * 100));
  opacityValue.textContent = `${Math.round(settings.opacity * 100)}%`;
  sleepLabel.textContent = sleepChoices.find((c) => c.value === String(settings.sleepMinutes))?.label
    ?? `${settings.sleepMinutes} 分钟`;
  layoutLabel.textContent = layoutChoices.find((c) => c.value === settings.keyboardLayout)?.label
    ?? settings.keyboardLayout;
  leftMouseInput.checked = settings.leftMouse;
  alwaysOnTopInput.checked = settings.alwaysOnTop;
  clickThroughLockedInput.checked = settings.clickThroughLocked;
  showTrayIconInput.checked = settings.showTrayIcon;
  autostartInput.checked = settings.autostart;
  runAsAdminInput.checked = settings.runAsAdmin;
  privacyInput.checked = settings.privacyMode;
  purrPetInput.checked = settings.purrPet;
  purrSleepInput.checked = settings.purrSleep;
  idleActionsInput.checked = settings.idleActions;
  labelsInput.checked = settings.showKeyLabels;
  reminderEnabledInput.checked = settings.reminderEnabled;
  if (document.activeElement !== reminderTextInput) reminderTextInput.value = settings.reminderText;
  if (document.activeElement !== reminderIntervalInput) {
    reminderIntervalInput.value = String(settings.reminderIntervalMinutes);
  }
  mcpEnabledInput.checked = settings.mcpEnabled;
  syncMcpClient();
  syncCoatGrid();
  menuButtonSelected(settings.coat);
  const cat = activeCat();
  if (cat) {
    if (document.activeElement !== catNameInput) catNameInput.value = cat.name;
    for (const btn of colorSlotButtons) {
      const dot = btn.querySelector<HTMLElement>(".color-dot");
      if (dot) dot.style.background = cat[btn.dataset.slot as ColorSlot];
    }
    patternLabel.textContent = patternChoices.find((c) => c.value === cat.pattern)?.label ?? "双色";
    spotsStatus.textContent = cat.spots.length ? `斑纹 ${cat.spots.length} 处` : "暂无斑纹";
    spotsClearBtn.hidden = cat.spots.length === 0;
  }
  customCoatEl.hidden = !cat;
  requestAnimationFrame(updateMenuScroll);
}

function updateMenuScroll() {
  const scrollEl = menuEl.querySelector<HTMLElement>(".menu-scroll");
  if (!scrollEl) return;
  scrollEl.classList.toggle("has-scroll", scrollEl.scrollHeight > scrollEl.clientHeight + 1);
}

async function saveSettings(patch: Partial<AppSettings>) {
  const next = { ...settings, ...patch };
  settings = next;
  syncControls();
  if (!isTauri) return;
  try {
    const saved = await invoke<AppSettings>("update_settings", { patch: next });
    settings = saved;
    syncControls();
  } catch {
    // Keep the local state; the next save retries.
  }
}

async function saveActiveCat(patch: Partial<CustomCat>) {
  const cat = activeCat();
  if (!cat) return;
  const next: CustomCat = { ...cat, ...patch };
  const index = customCats.findIndex((c) => c.id === cat.id);
  if (index >= 0) customCats[index] = next;
  syncControls();
  if (!isTauri) return;
  try {
    const saved = await invoke<CustomCat>("save_custom_cat", { cat: next, activate: true });
    const i = customCats.findIndex((c) => c.id === saved.id);
    if (i >= 0) customCats[i] = saved;
    else customCats.push(saved);
    if (settings.coat !== saved.id) settings = { ...settings, coat: saved.id };
    syncControls();
  } catch {
    // Keep the local state; the next save retries.
  }
}

async function createCat() {
  const cat: CustomCat = {
    id: isTauri ? "" : `my-cat-${Date.now().toString(36)}`,
    name: "我的猫",
    fur: "#e9973f",
    patch: "#fbe8c9",
    accent: "#c26a1f",
    eye: "#5c8a3a",
    eye2: "#5c8a3a",
    pattern: "bicolor",
    spots: [],
  };
  if (!isTauri) {
    customCats.push(cat);
    settings = { ...settings, coat: cat.id };
    syncControls();
    return;
  }
  try {
    const saved = await invoke<CustomCat>("save_custom_cat", { cat, activate: true });
    if (!customCats.some((c) => c.id === saved.id)) customCats.push(saved);
    settings = { ...settings, coat: saved.id };
    syncControls();
  } catch {
    // Ignore; the user can retry.
  }
}

async function removeActiveCat() {
  const cat = activeCat();
  if (!cat) return;
  customCats = customCats.filter((c) => c.id !== cat.id);
  if (settings.coat === cat.id) settings = { ...settings, coat: "tuxedo" };
  syncControls();
  if (!isTauri) return;
  try {
    await invoke("delete_custom_cat", { id: cat.id });
  } catch {
    // The list listener will reconcile.
  }
}

function catFromCode(raw: string): CustomCat | null {
  try {
    const parsed = JSON.parse(raw) as Record<string, unknown>;
    if (!parsed || typeof parsed !== "object") return null;
    const str = (value: unknown, fallback: string) =>
      typeof value === "string" && value.trim() ? value : fallback;
    const patternRaw = str(parsed.pattern, "bicolor");
    const pattern: CatPattern = (["solid", "bicolor", "tabby", "calico"] as const).includes(
      patternRaw as CatPattern,
    )
      ? (patternRaw as CatPattern)
      : "bicolor";
    return {
      id: "",
      name: str(parsed.name, "导入的猫").slice(0, 16),
      fur: str(parsed.fur, "#151515"),
      patch: str(parsed.patch, "#f8f6ef"),
      accent: str(parsed.accent, "#e9973f"),
      eye: str(parsed.eye, "#e2b93b"),
      eye2: str(parsed.eye2, str(parsed.eye, "#e2b93b")),
      pattern,
      spots: Array.isArray(parsed.spots) ? (parsed.spots.slice(0, 24) as CatSpot[]) : [],
    };
  } catch {
    return null;
  }
}

async function importCoatCode() {
  const cat = catFromCode(coatCodeInput.value);
  if (!cat) {
    coatCodeImport.textContent = "格式不对";
    window.setTimeout(() => {
      coatCodeImport.textContent = "导入毛色码";
    }, 1800);
    return;
  }
  coatCodeInput.value = "";
  if (!isTauri) {
    cat.id = `my-cat-${Date.now().toString(36)}`;
    customCats.push(cat);
    settings = { ...settings, coat: cat.id };
    syncControls();
    return;
  }
  try {
    const saved = await invoke<CustomCat>("save_custom_cat", { cat, activate: true });
    if (!customCats.some((c) => c.id === saved.id)) customCats.push(saved);
    settings = { ...settings, coat: saved.id };
    syncControls();
  } catch {
    // Ignore; the user can retry.
  }
}

async function refresh() {
  if (isTauri) {
    try {
      settings = await invoke<AppSettings>("get_settings");
    } catch {
      // Keep the last known state.
    }
    try {
      customCats = await invoke<CustomCat[]>("get_custom_cats");
    } catch {
      // Keep the last known list.
    }
    try {
      mcpCommand = await invoke<string>("get_mcp_command");
    } catch {
      // Keep the default command.
    }
    try {
      appVersion = await invoke<string>("get_app_version");
    } catch {
      appVersion = "dev";
    }
  }
  appVersionEl.textContent = appVersion === "dev" ? "7paws 开发预览" : `7paws v${appVersion}`;
  syncControls();
}

function bindControls() {
  scaleSlider.addEventListener("input", () => {
    const pct = Number(scaleSlider.value);
    scaleValue.textContent = `${pct}%`;
  });

  scaleSlider.addEventListener("change", () => {
    void saveSettings({ scale: Number(scaleSlider.value) / 100 });
  });

  opacitySlider.addEventListener("input", () => {
    const pct = Number(opacitySlider.value);
    opacityValue.textContent = `${pct}%`;
  });

  opacitySlider.addEventListener("change", () => {
    void saveSettings({ opacity: Number(opacitySlider.value) / 100 });
  });

  sleepSelect.addEventListener("click", () => {
    openDropdown(
      sleepSelect,
      sleepChoices,
      () => String(settings.sleepMinutes),
      (value) => void saveSettings({ sleepMinutes: Number(value) }),
    );
  });

  layoutSelect.addEventListener("click", () => {
    openDropdown(
      layoutSelect,
      layoutChoices,
      () => settings.keyboardLayout,
      (value) => void saveSettings({ keyboardLayout: value === "104" ? "104" : "65" }),
    );
  });

  leftMouseInput.addEventListener("change", () => {
    void saveSettings({ leftMouse: leftMouseInput.checked });
  });
  alwaysOnTopInput.addEventListener("change", () => {
    void saveSettings({ alwaysOnTop: alwaysOnTopInput.checked });
  });
  clickThroughLockedInput.addEventListener("change", () => {
    void saveSettings({ clickThroughLocked: clickThroughLockedInput.checked });
  });
  showTrayIconInput.addEventListener("change", () => {
    void saveSettings({ showTrayIcon: showTrayIconInput.checked });
  });
  autostartInput.addEventListener("change", () => {
    void saveSettings({ autostart: autostartInput.checked });
  });
  runAsAdminInput.addEventListener("change", () => {
    void saveSettings({ runAsAdmin: runAsAdminInput.checked });
  });
  privacyInput.addEventListener("change", () => {
    void saveSettings({ privacyMode: privacyInput.checked });
  });
  purrPetInput.addEventListener("change", () => {
    void saveSettings({ purrPet: purrPetInput.checked });
  });
  purrSleepInput.addEventListener("change", () => {
    void saveSettings({ purrSleep: purrSleepInput.checked });
  });
  idleActionsInput.addEventListener("change", () => {
    void saveSettings({ idleActions: idleActionsInput.checked });
  });
  labelsInput.addEventListener("change", () => {
    void saveSettings({ showKeyLabels: labelsInput.checked });
  });

  reminderEnabledInput.addEventListener("change", () => {
    void saveSettings({ reminderEnabled: reminderEnabledInput.checked });
  });
  reminderTextInput.addEventListener("input", () => {
    if (reminderTextTimer !== null) window.clearTimeout(reminderTextTimer);
    reminderTextTimer = window.setTimeout(() => {
      reminderTextTimer = null;
      commitReminderText();
    }, 300);
  });
  reminderTextInput.addEventListener("change", () => {
    if (reminderTextTimer !== null) {
      window.clearTimeout(reminderTextTimer);
      reminderTextTimer = null;
    }
    commitReminderText();
  });
  reminderIntervalInput.addEventListener("input", () => {
    if (reminderIntervalTimer !== null) window.clearTimeout(reminderIntervalTimer);
    reminderIntervalTimer = null;
    if (!reminderIntervalInput.value.trim()) return;
    reminderIntervalTimer = window.setTimeout(() => {
      reminderIntervalTimer = null;
      commitReminderInterval();
    }, 300);
  });
  reminderIntervalInput.addEventListener("change", () => {
    if (reminderIntervalTimer !== null) {
      window.clearTimeout(reminderIntervalTimer);
      reminderIntervalTimer = null;
    }
    commitReminderInterval();
  });

  mcpEnabledInput.addEventListener("change", () => {
    void saveSettings({ mcpEnabled: mcpEnabledInput.checked });
  });

  mcpClientSelect.addEventListener("click", () => {
    openDropdown(
      mcpClientSelect,
      MCP_CLIENTS.map((client) => ({
        value: client.id,
        label: client.name,
        badge: client.id === "qoder" ? "荐" : undefined,
      })),
      () => mcpClient,
      (value) => {
        mcpClient = value as McpClientId;
        syncMcpClient();
      },
    );
  });

  mcpCopyBtn.addEventListener("click", () => void copyMcpSnippet());

  updateBtn.addEventListener("click", () => void checkForUpdates());

  createShortcutBtn.addEventListener("click", async () => {
    createShortcutBtn.disabled = true;
    shortcutHint.className = "menu-hint mcp-hint";
    shortcutHint.textContent = "正在创建…";
    try {
      const message = await invoke<string>("create_desktop_shortcut");
      shortcutHint.className = "menu-hint mcp-hint success";
      shortcutHint.textContent = message;
    } catch (error) {
      shortcutHint.className = "menu-hint mcp-hint error";
      shortcutHint.textContent = String(error);
    } finally {
      createShortcutBtn.disabled = false;
      updateMenuScroll();
    }
  });

  restartAdminBtn.addEventListener("click", async () => {
    restartAdminBtn.disabled = true;
    gameHint.className = "menu-hint mcp-hint";
    gameHint.textContent = "正在等待权限确认…";
    try {
      const message = await invoke<string>("restart_as_admin");
      gameHint.className = "menu-hint mcp-hint success";
      gameHint.textContent = message;
    } catch (error) {
      gameHint.className = "menu-hint mcp-hint error";
      gameHint.textContent = String(error);
      restartAdminBtn.disabled = false;
    }
  });

  updateGoBtn.addEventListener("click", () => {
    closeUpdatePopup();
    if (isTauri) {
      void invoke("open_release_page");
    } else {
      window.open("https://github.com/7wdn/7paws/releases/latest", "_blank");
    }
  });

  githubBtn.addEventListener("click", () => {
    if (isTauri) {
      void invoke("open_project_page");
    } else {
      window.open("https://github.com/7wdn/7paws", "_blank");
    }
  });

  patternSelect.addEventListener("click", () => {
    const cat = activeCat();
    if (!cat) return;
    openDropdown(
      patternSelect,
      patternChoices,
      () => cat.pattern,
      (value) => saveActiveCat({ pattern: value as CatPattern }),
    );
  });

  catNameInput.addEventListener("change", () => {
    const name = catNameInput.value.trim();
    if (!name) {
      const cat = activeCat();
      if (cat) catNameInput.value = cat.name;
      return;
    }
    saveActiveCat({ name });
  });

  spotsClearBtn.addEventListener("click", () => {
    saveActiveCat({ spots: [] });
  });

  deleteCatBtn.addEventListener("click", () => {
    // Two-step in-place confirmation: the native confirm() dialog shows the
    // webview origin (localhost:1420 / tauri://localhost) and breaks the theme.
    if (deleteArmedAt && Date.now() - deleteArmedAt < 3000) {
      disarmDelete();
      void removeActiveCat();
      return;
    }
    deleteArmedAt = Date.now();
    deleteCatBtn.textContent = "再点一次确认删除";
    window.setTimeout(() => {
      if (deleteArmedAt && Date.now() - deleteArmedAt >= 3000) disarmDelete();
    }, 3050);
  });

  coatCodeGen.addEventListener("click", () => {
    const cat = activeCat();
    if (!cat) return;
    coatCodeInput.value = JSON.stringify(cat);
    coatCodeInput.select();
  });

  coatCodeImport.addEventListener("click", () => void importCoatCode());

  for (const btn of colorSlotButtons) {
    btn.addEventListener("click", () => openColorPopup(btn, btn.dataset.slot as ColorSlot));
  }

  photoBtn.addEventListener("click", () => {
    setPhotoPickerOpen(true);
    photoInput.click();
  });
  photoInput.addEventListener("cancel", () => setPhotoPickerOpen(false));
  photoInput.addEventListener("change", async () => {
    setPhotoPickerOpen(false);
    const file = photoInput.files?.[0];
    photoInput.value = "";
    if (!file) return;
    photoBtn.disabled = true;
    photoBtn.textContent = "正在取色…";
    try {
      const colors = await extractColorsFromPhoto(file);
      await saveActiveCat(colors);
      photoBtn.textContent = "已取色，可继续微调";
    } catch {
      photoBtn.textContent = "这张照片读不出来，换一张试试";
    } finally {
      photoBtn.disabled = false;
      window.setTimeout(() => {
        photoBtn.textContent = "从照片取色";
      }, 2400);
    }
  });

  resetBtn.addEventListener("click", async () => {
    if (isTauri) {
      await invoke("reset_position");
    }
    hide();
  });

  quitBtn.addEventListener("click", async () => {
    if (!isTauri) return;
    await invoke("quit_app");
  });

  closeBtn.addEventListener("click", hide);

  headEl?.addEventListener("pointerdown", (e) => {
    if (!isTauri || e.button !== 0) return;
    if ((e.target as HTMLElement).closest("button")) return;
    e.preventDefault();
    void getCurrentWindow().startDragging();
  });
}

async function boot() {
  let portable = false;
  if (isTauri && isWindows) {
    portable = await invoke<boolean>("is_portable_install").catch(() => false);
    const elevated = await invoke<boolean>("is_elevated").catch(() => false);
    if (elevated) {
      restartAdminBtn.hidden = true;
      gameHint.hidden = true;
    }
  }
  shortcutSection.hidden = !(isTauri && isWindows && portable);
  gameSection.hidden = !(isTauri && isWindows);
  if (isTauri && await invoke<boolean>("is_deskbox_managed").catch(() => false)) {
    for (const id of ["autostart", "runAsAdmin"]) {
      document.getElementById(id)?.closest("label")?.setAttribute("hidden", "");
    }
    document.getElementById("mcpEnabled")?.closest(".menu-section")?.setAttribute("hidden", "");
    updateBtn.hidden = true;
    gameSection.hidden = true;
    shortcutSection.hidden = true;
  }
  buildCoatButtons();
  bindControls();
  await refresh();

  if (isTauri) {
    await listen<AppSettings>("cat-settings", (ev) => {
      settings = ev.payload;
      syncControls();
    });
    await listen<CustomCat[]>("cat-cats", (ev) => {
      customCats = ev.payload;
      syncControls();
    });
  }

  window.addEventListener("focus", () => setPhotoPickerOpen(false));
  window.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    if (!updatePopup.classList.contains("hidden")) {
      closeUpdatePopup();
      return;
    }
    if (colorSlot) {
      closeColorPopup();
      return;
    }
    if (openSelect) {
      closeDropdown();
      return;
    }
    hide();
  });
  window.addEventListener("blur", flushReminderInputs);
  document.addEventListener("pointerdown", (e) => {
    const target = e.target as Node;
    if (openSelect && !(dropdownPopup.contains(target) || openSelect.contains(target))) {
      closeDropdown();
    }
    if (colorSlot && !colorPopup.contains(target) && !(target instanceof Element && target.closest(".color-slot"))) {
      closeColorPopup();
    }
    if (
      !updatePopup.classList.contains("hidden")
      && !updatePopup.contains(target)
      && !(target instanceof Element && target.closest("#updateBtn"))
    ) {
      closeUpdatePopup();
    }
  });
  menuEl.querySelector<HTMLElement>(".menu-scroll")?.addEventListener("scroll", () => {
    closeDropdown();
    closeColorPopup();
    closeUpdatePopup();
  }, {
    passive: true,
  });
  window.addEventListener("resize", () => {
    closeDropdown();
    closeColorPopup();
    closeUpdatePopup();
    updateMenuScroll();
  });
}

void boot();
