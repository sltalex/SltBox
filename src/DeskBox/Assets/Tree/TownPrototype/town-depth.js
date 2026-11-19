export const TOWN_LAYER_STEP = 1000;
export const TOWN_PLAYER_LAYER = 3;
export const TOWN_TERRAIN_DEPTH = -100000;
export const TOWN_BACKGROUND_DEPTH = -50000;
export const TOWN_FOREGROUND_DEPTH = 100000;

function rawSplitValues(item) {
  // A catalog can briefly contain multiple aliases while a release is being
  // rolled out. Parse each alias independently and use the most complete
  // candidate. Merging all aliases is unsafe: a stale one-line `split_y` can
  // become an extra boundary beside the newer two-line configuration and
  // turn a three-slice house into four incorrectly ordered slices.
  const values = [item?.split_lines, item?.split_lines_json, item?.splitLines, item?.split_y];
  const candidates = [];
  const append = raw => {
    if (Array.isArray(raw)) { if (raw.length) candidates.push(raw); return; }
    if (raw && typeof raw === 'object') {
      // A few older admin responses wrapped the list as `{ lines: [...] }`.
      // Accept that shape as well so a valid split configuration cannot fall
      // back to one full y-sorted texture just because the response adapter
      // used the database-shaped object.
      append(raw.lines ?? raw.values ?? raw.split_lines ?? raw.splitLines);
      return;
    }
    if (typeof raw === 'string') {
      const trimmed = raw.trim();
      if (!trimmed) return;
      if (trimmed.startsWith('[')) {
        try {
          const parsed = JSON.parse(trimmed);
          if (Array.isArray(parsed) && parsed.length) candidates.push(parsed);
        } catch {
          // Try the next alias. The server validates persisted metadata; this
          // path only protects the renderer from an incomplete old response.
        }
        return;
      }
      const parsed = trimmed.split(/[,，;；\s]+/).filter(Boolean);
      if (parsed.length) candidates.push(parsed);
      return;
    }
    if (raw !== undefined && raw !== null && raw !== '') candidates.push([raw]);
  };
  for (const raw of values) append(raw);
  if (!candidates.length) return [];
  const normalized = candidates.map(candidate => [...new Set(candidate)]);
  normalized.sort((a, b) => b.length - a.length);
  return normalized[0];
}

function hasUsableSplitValues(item) {
  const sourceHeight = Number(item?.height || 0);
  return rawSplitValues(item).some(value => {
    const line = Number(value);
    return Number.isFinite(line) && line > 0 && (sourceHeight <= 0 || line < sourceHeight);
  });
}

/**
 * Imported tall buildings may predate the split metadata columns. Give them a
 * safe first render with two 16-pixel-aligned lines; explicit editor values
 * always take precedence over this fallback.
 */
export function defaultBuildingSplitLines(item) {
  const sourceHeight = Number(item?.height || 0);
  const category = String(item?.category || '').toLowerCase();
  const subcategory = String(item?.subcategory || '').toLowerCase();
  const name = String(item?.name || '').toLowerCase();
  const id = String(item?.id || '').toLowerCase();
  const mode = String(item?.sort_mode || '').trim().toLowerCase();
  // An explicit `split` choice is authoritative even when an imported item
  // was classified as object/other.  If its split lines were lost in an old
  // release, keep it split on the first render instead of falling back to a
  // single y-sorted texture (which reverses the roof/wall/base occlusion).
  const isHouseLike = category === 'building' || subcategory === 'house' ||
    subcategory.includes('房屋') || subcategory.includes('建筑') ||
    name.includes('房屋') || name.includes('房子') || name.includes('house') ||
    name.includes('building') || id.includes('fangwu') || id.includes('house');
  const isExplicitSplit = mode === 'split' || isHouseLike;
  if (!isExplicitSplit) return [];
  if (!Number.isFinite(sourceHeight) || sourceHeight < 48) return [];
  const snap = value => Math.round(value / 16) * 16;
  let first = snap(sourceHeight / 3);
  let second = snap(sourceHeight * 2 / 3);
  first = Math.max(16, Math.min(sourceHeight - 32, first));
  second = Math.max(first + 16, Math.min(sourceHeight - 16, second));
  return first < second && second < sourceHeight ? [first, second] : [];
}

export function assetSortMode(item) {
  const mode = String(item?.sort_mode || '').trim().toLowerCase();
  const category = String(item?.category || '').toLowerCase();
  // Older/automatically scanned catalog rows may contain split metadata before
  // the explicit sort_mode column was added. Treat that metadata as the
  // authoritative signal so a multi-part house is never rendered as one
  // y-sorted image (which makes the lower slice cover the player and hides the
  // interior slice).
  const hasSplitMetadata = hasUsableSplitValues(item);
  // Explicit split lines are authoritative even when an imported house was
  // classified as `object`/`other`.  The editor stores the semantic choice in
  // the split metadata; requiring category=building here silently rendered
  // those assets as one y-sorted image, which put the lower wall in front of
  // the player and hid the middle slice.
  if ((mode === '' || mode === 'y_sort') &&
      (hasSplitMetadata || defaultBuildingSplitLines(item).length > 0)) return 'split';
  if (['manual', 'y_sort', 'background', 'foreground', 'split'].includes(mode)) return mode;
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

/**
 * Resolve the depth of one vertical slice.  A split building is ordered by
 * the slice's bottom edge, while its first and last slices stay in fixed
 * foreground/background bands.  Keeping this in one function prevents the
 * renderer and any future hit-testing code from assigning the lower slice to
 * the foreground by accident.
 */
export function splitSegmentDepth(segment, placement, bottomY) {
  if (segment?.depthBand === 'foreground') return splitForegroundDepth(bottomY);
  if (segment?.depthBand === 'background') return TOWN_BACKGROUND_DEPTH + bottomY;
  return objectDepth({ sort_mode: 'y_sort' }, placement, bottomY);
}

export function splitDisplayMetrics(item) {
  const sourceHeight = Number(item?.height || 0);
  const displayHeight = Number(item?.display_height || sourceHeight || 0);
  const splitY = Number(item?.split_y);
  if (assetSortMode(item) !== 'split' || !Number.isFinite(splitY) || splitY <= 0 || splitY >= sourceHeight) return null;
  const ratio = splitY / sourceHeight;
  return { splitY, frontHeight: displayHeight * (1 - ratio), backHeight: displayHeight * ratio, ratio };
}

/**
 * Returns the drawable vertical slices for a split building. The original
 * format accepted one split_y value (upper foreground + lower background).
 * Newer assets may provide split_lines to describe multiple slices. Interior
 * slices are y-sorted with the player, while the first and last slices remain
 * fixed in front of and behind the player respectively. The first slice is
 * the image's upper part; the admin editor defines that part as foreground.
 */
export function splitDisplaySegments(item) {
  const sourceHeight = Number(item?.height || 0);
  const displayHeight = Number(item?.display_height || sourceHeight || 0);
  if (assetSortMode(item) !== 'split' || !Number.isFinite(sourceHeight) || sourceHeight <= 0 ||
      !Number.isFinite(displayHeight) || displayHeight <= 0) return [];
  // The published API exposes `split_lines`, but older release snapshots and
  // locally staged catalog rows may still carry the database-shaped
  // `split_lines_json` field (or camelCase from an editor draft). Accept all
  // of them here so a valid three-part asset cannot silently collapse to the
  // legacy two-part fallback after a catalog refresh.
  let values = rawSplitValues(item);
  const normalizeLines = source => [...new Set(source.map(value => Number(value)))]
    .filter(value => Number.isFinite(value) && value > 0 && value < sourceHeight)
    .sort((a, b) => a - b);
  let lines = normalizeLines(values);
  // Do not let a stale/invalid alias (for example a line equal to the source
  // height) disable split rendering.  Use the same deterministic three-slice
  // fallback as a newly imported tall house instead of drawing the entire
  // image on one depth plane.
  if (!lines.length) lines = normalizeLines(defaultBuildingSplitLines(item));
  if (!lines.length) return [];
  const boundaries = [0, ...lines, sourceHeight];
  const scale = displayHeight / sourceHeight;
  return boundaries.slice(0, -1).map((sourceY, index) => {
    const end = boundaries[index + 1];
    return {
      index,
      sourceY,
      sourceHeight: end - sourceY,
      displayHeight: (end - sourceY) * scale,
      bottomRatio: end / sourceHeight,
      depthBand: index === 0 ? 'foreground' : (index === boundaries.length - 2 ? 'background' : 'y_sort'),
    };
  });
}

/**
 * Resolve the screen-space bounds for every split slice from one shared
 * object bottom.  Keeping this calculation beside splitDisplaySegments makes
 * the slices contiguous even when display dimensions are scaled or refreshed
 * while the scene is running.
 */
export function splitSegmentLayout(item, objectBottomY) {
  const segments = splitDisplaySegments(item);
  if (!segments.length) return [];
  const totalDisplayHeight = segments.reduce((sum, segment) => sum + segment.displayHeight, 0);
  const bottom = Number(objectBottomY);
  if (!Number.isFinite(totalDisplayHeight) || totalDisplayHeight <= 0 || !Number.isFinite(bottom)) return [];
  return segments.map(segment => {
    const bottomY = bottom - totalDisplayHeight + segment.bottomRatio * totalDisplayHeight;
    return {
      ...segment,
      topY: bottomY - segment.displayHeight,
      bottomY,
    };
  });
}
