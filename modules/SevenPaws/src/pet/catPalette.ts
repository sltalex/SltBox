import type { CatSpot, CustomCat } from "../types";

export interface CoatPalette {
  pattern: CustomCat["pattern"];
  fur: string;
  patch: string;
  line: string;
  eye: string;
  eye2: string;
  whisker: string;
  whiskerOut: string;
  stripe: string;
  inner: string;
  patchA?: string;
  patchB?: string;
  tail?: string;
  spots: CatSpot[];
}

export function mixHex(a: string, b: string, t: number): string {
  const pa = parseInt(a.slice(1), 16);
  const pb = parseInt(b.slice(1), 16);
  const channel = (shift: number) =>
    Math.round(((pa >> shift) & 255) * (1 - t) + ((pb >> shift) & 255) * t);
  return `#${[channel(16), channel(8), channel(0)]
    .map((v) => v.toString(16).padStart(2, "0"))
    .join("")}`;
}

export function luminance(hex: string): number {
  const n = parseInt(hex.slice(1), 16);
  const r = (n >> 16) & 255;
  const g = (n >> 8) & 255;
  const b = n & 255;
  return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
}

export function buildCoatPalette(c: CustomCat): CoatPalette {
  const fur = c.fur;
  const lum = luminance(fur);
  const pale = lum > 0.78;
  const line = lum < 0.2
    ? mixHex(fur, "#b5b1a2", 0.6)
    : lum > 0.8
      ? mixHex(fur, "#8a8676", 0.75)
      : mixHex(fur, "#1c1a16", 0.5);
  const stripe = mixHex(fur, "#1c1a16", 0.28);
  const whisker = lum > 0.7 ? "#b5b1a2" : "#f4f4ee";
  const inner = mixHex(fur, "#f2b9b0", pale ? 0.65 : 0.45);
  const base: CoatPalette = {
    pattern: c.pattern,
    fur,
    patch: c.patch,
    line,
    eye: c.eye,
    eye2: c.eye2 || c.eye,
    whisker,
    whiskerOut: line,
    stripe,
    inner,
    spots: c.spots ?? [],
  };
  switch (c.pattern) {
    case "solid":
      return { ...base, patch: fur, stripe: fur };
    case "tabby":
      return { ...base, pattern: "tabby" };
    case "calico":
      return {
        ...base,
        patch: fur,
        patchA: c.patch,
        patchB: c.accent,
        tail: c.accent,
        stripe: fur,
      };
    default:
      return { ...base, pattern: "bicolor" };
  }
}