export type CatPattern = "solid" | "bicolor" | "tabby" | "calico";

export interface CatSpot {
  part: "head" | "body";
  x: number;
  y: number;
  rx: number;
  ry: number;
  rotation: number;
  color: string;
  opacity: number;
}

export interface CustomCat {
  id: string;
  name: string;
  fur: string;
  patch: string;
  accent: string;
  eye: string;
  eye2: string;
  pattern: CatPattern;
  spots: CatSpot[];
}

export interface AppSettings {
  x: number | null;
  y: number | null;
  scale: number;
  opacity: number;
  alwaysOnTop: boolean;
  clickThroughLocked: boolean;
  purrPet: boolean;
  purrSleep: boolean;
  autostart: boolean;
  runAsAdmin: boolean;
  privacyMode: boolean;
  leftMouse: boolean;
  coat: string;
  sleepMinutes: number;
  idleActions: boolean;
  showKeyLabels: boolean;
  keyboardLayout: "65" | "104";
  showTrayIcon: boolean;
  mcpEnabled: boolean;
  reminderEnabled: boolean;
  reminderText: string;
  reminderIntervalMinutes: number;
}

export type CatInput =
  | { kind: "keyDown"; code: string; repeat: boolean }
  | { kind: "keyUp"; code: string }
  | { kind: "mouseDown"; button: string }
  | { kind: "mouseUp"; button: string }
  | { kind: "mouseMoved"; nx: number; ny: number }
  | { kind: "mouseWheel"; delta: number }
  | { kind: "privacyTap"; side: "Left" | "Right" };

export type PawSide = "L" | "R";