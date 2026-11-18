export const TOWN_LAYER_STEP = 1000;
export const TOWN_PLAYER_LAYER = 3;
export const TOWN_TERRAIN_DEPTH = -100000;
export const TOWN_BACKGROUND_DEPTH = -50000;
export const TOWN_FOREGROUND_DEPTH = 100000;

export function assetSortMode(item) {
  const mode = String(item?.sort_mode || '').trim().toLowerCase();
  if (['manual', 'y_sort', 'background', 'foreground', 'split'].includes(mode)) return mode;
  const category = String(item?.category || '').toLowerCase();
  if (['terrain', 'water', 'path'].includes(category)) return 'background';
  return 'y_sort';
}

export function objectDepth(item, placement, visualY) {
  const mode = assetSortMode(item);
  if (mode === 'background' || mode === 'split') return TOWN_BACKGROUND_DEPTH + visualY;
  if (mode === 'foreground') return TOWN_FOREGROUND_DEPTH + visualY;
  if (mode === 'manual') {
    const layer = Math.min(6, Math.max(1, Number(placement?.layer || 1)));
    return visualY + layer * TOWN_LAYER_STEP;
  }
  // y_sort shares the same depth band as the player and NPC feet. This makes
  // a tree or prop move in front of/behind a character as the character walks.
  return visualY + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP;
}

export function splitForegroundDepth(visualY) {
  return TOWN_FOREGROUND_DEPTH + visualY;
}

export function splitDisplayMetrics(item) {
  const sourceHeight = Number(item?.height || 0);
  const displayHeight = Number(item?.display_height || sourceHeight || 0);
  const splitY = Number(item?.split_y);
  if (assetSortMode(item) !== 'split' || !Number.isFinite(splitY) || splitY <= 0 || splitY >= sourceHeight) return null;
  const ratio = splitY / sourceHeight;
  return { splitY, frontHeight: displayHeight * (1 - ratio), backHeight: displayHeight * ratio, ratio };
}
