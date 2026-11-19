import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {assetSortMode, defaultBuildingSplitLines, objectDepth, splitDisplayMetrics, splitDisplaySegments, splitForegroundDepth, splitSegmentDepth, splitSegmentLayout, TOWN_BACKGROUND_DEPTH, TOWN_FOREGROUND_DEPTH, TOWN_LAYER_STEP, TOWN_PLAYER_LAYER} from '../src/DeskBox/Assets/Tree/TownPrototype/town-depth.js';
import {validatePlacement} from '../src/DeskBox/Assets/Tree/TownPrototype/town-rules.js';

const code = await readFile(new URL('../src/DeskBox/Assets/Tree/TownPrototype/town.js', import.meta.url), 'utf8');
const html = await readFile(new URL('../src/DeskBox/Assets/Tree/TownPrototype/index.html', import.meta.url), 'utf8');
assert.match(code, /clock\.time_zone === 'Asia\/Shanghai'[^\n]+北京时间/,
  'The shared-town clock must label the authoritative Beijing timezone.');
const stackCatalog = {map_width: 16, map_height: 16, items: [{id: 'base', footprint: {width: 2, height: 2}}], world: {}};
assert.equal(validatePlacement(stackCatalog, {placements: [{instance_id: 'existing', asset_id: 'base', tile_x: 4, tile_y: 4}]}, 'base', 4, 4), null,
  'Multiple town materials may share the same footprint during layout.');
assert.match(code, /setDepth\(objectDepth\(item, placement, y\)\)/);
assert.doesNotMatch(code, /setDepth\(\(placement\.tile_y\+bounds\.height\)\*16 \+ layer \* TOWN_LAYER_STEP\)/);
assert.equal(assetSortMode({category: 'water'}), 'background');
assert.equal(assetSortMode({sort_mode: 'foreground', category: 'building'}), 'foreground');
assert.equal(assetSortMode({sort_mode: 'split', category: 'building'}), 'split');
assert.equal(assetSortMode({category: 'building', split_lines: [48, 96]}), 'split', 'Split metadata enables multi-part house rendering when sort_mode is missing.');
assert.equal(assetSortMode({category: 'building', sort_mode: 'y_sort', split_lines_json: '[48,96]'}), 'split', 'Legacy y_sort rows with split metadata are upgraded at render time.');
assert.equal(assetSortMode({category: 'object', sort_mode: 'y_sort', split_lines: [48, 96]}), 'split', 'Explicit split metadata wins even when an imported house has a non-building category.');
assert.equal(assetSortMode({category: 'other', sort_mode: '', split_lines_json: '[48,96]'}), 'split', 'Database-shaped split metadata is authoritative across legacy categories.');
assert.deepEqual(defaultBuildingSplitLines({category: 'building', height: 160}), [48, 112], 'Tall buildings without split metadata get a conservative three-slice fallback.');
assert.equal(assetSortMode({category: 'building', sort_mode: 'y_sort', height: 160}), 'split', 'Tall legacy buildings are automatically split instead of rendering as one depth plane.');
assert.equal(assetSortMode({id: 'town-source-fangwu01', name: '房屋01', category: 'object', sort_mode: 'y_sort', height: 176}), 'split', 'House-like imported assets are split even when their legacy category is object.');
assert.deepEqual(defaultBuildingSplitLines({category: 'object', sort_mode: 'split', height: 176}), [64, 112], 'Explicit split mode keeps an imported non-building asset split when lines are missing.');
assert.equal(assetSortMode({category: 'object', sort_mode: 'split', height: 176}), 'split');
assert.equal(splitDisplaySegments({category: 'object', sort_mode: 'split', height: 176, display_height: 176}).length, 3,
  'An explicit split asset without persisted lines still renders three slices instead of one full-depth image.');
assert.equal(splitDisplaySegments({id: 'town-source-fangwu01', name: '房屋01', category: 'object', sort_mode: 'y_sort', height: 176, display_height: 176, split_lines: [176]}).length, 3,
  'An invalid persisted split alias falls back to three deterministic house slices.');
assert.equal(assetSortMode({sort_mode: 'manual'}), 'manual');
assert.equal(objectDepth({sort_mode: 'y_sort'}, {layer: 1}, 120), 120 + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP);
assert.equal(objectDepth({sort_mode: 'background'}, {layer: 6}, 120), TOWN_BACKGROUND_DEPTH + 120);
assert.equal(objectDepth({sort_mode: 'split'}, {layer: 6}, 120), TOWN_BACKGROUND_DEPTH + 120);
assert.equal(objectDepth({sort_mode: 'foreground'}, {layer: 1}, 120), TOWN_FOREGROUND_DEPTH + 120);
assert.equal(splitForegroundDepth(120), TOWN_FOREGROUND_DEPTH + 120);
assert.equal(objectDepth({sort_mode: 'manual'}, {layer: 6}, 120), 120 + 6 * TOWN_LAYER_STEP);
assert.deepEqual(splitDisplayMetrics({sort_mode: 'split', height: 128, display_height: 256, split_y: 64}), {splitY: 64, frontHeight: 128, backHeight: 128, ratio: .5});
assert.equal(splitDisplayMetrics({sort_mode: 'split', height: 128, display_height: 256, split_y: 128}), null);
assert.deepEqual(splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines: [48, 96]}), [
  {index: 0, sourceY: 0, sourceHeight: 48, displayHeight: 96, bottomRatio: 1 / 3, depthBand: 'foreground'},
  {index: 1, sourceY: 48, sourceHeight: 48, displayHeight: 96, bottomRatio: 2 / 3, depthBand: 'y_sort'},
  {index: 2, sourceY: 96, sourceHeight: 48, displayHeight: 96, bottomRatio: 1, depthBand: 'background'},
]);
assert.deepEqual(splitDisplaySegments({sort_mode: 'split', height: 128, display_height: 256, split_y: 64}), [
  {index: 0, sourceY: 0, sourceHeight: 64, displayHeight: 128, bottomRatio: .5, depthBand: 'foreground'},
  {index: 1, sourceY: 64, sourceHeight: 64, displayHeight: 128, bottomRatio: 1, depthBand: 'background'},
]);
assert.deepEqual(splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines: '[48,96]'}),
  splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines: [48, 96]}),
  'Cached JSON split metadata must produce the same three slices.');
assert.deepEqual(splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines_json: '[48,96]'}),
  splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines: [48, 96]}),
  'Database-shaped split metadata must produce the same three slices.');
assert.deepEqual(splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines: [], split_lines_json: '[48,96]'}),
  splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines: [48, 96]}),
  'An empty public alias must not mask populated legacy split metadata.');
assert.deepEqual(splitDisplaySegments({sort_mode: 'split', height: 176, display_height: 176, split_lines: [96], split_lines_json: '[96,144]'}),
  splitDisplaySegments({sort_mode: 'split', height: 176, display_height: 176, split_lines: [96, 144]}),
  'A stale one-line alias must not mask the richer three-slice metadata.');
assert.deepEqual(splitDisplaySegments({sort_mode: 'split', height: 176, display_height: 176, split_lines: [96, 144], split_y: 48}),
  splitDisplaySegments({sort_mode: 'split', height: 176, display_height: 176, split_lines: [96, 144]}),
  'A stale legacy split_y must not add a fourth slice beside the richer two-line metadata.');
assert.deepEqual(splitDisplaySegments({category: 'building', sort_mode: 'y_sort', height: 160, display_height: 160}), [
  {index: 0, sourceY: 0, sourceHeight: 48, displayHeight: 48, bottomRatio: .3, depthBand: 'foreground'},
  {index: 1, sourceY: 48, sourceHeight: 64, displayHeight: 64, bottomRatio: .7, depthBand: 'y_sort'},
  {index: 2, sourceY: 112, sourceHeight: 48, displayHeight: 48, bottomRatio: 1, depthBand: 'background'},
], 'A tall legacy building renders all three fallback slices.');
const split3 = splitDisplaySegments({sort_mode: 'split', height: 144, display_height: 288, split_lines: [48, 96]});
assert.equal(split3.reduce((sum, segment) => sum + segment.sourceHeight, 0), 144, 'Three slices cover the complete source height.');
assert.equal(split3.reduce((sum, segment) => sum + segment.displayHeight, 0), 288, 'Three slices cover the complete display height.');
assert.equal(split3[0].depthBand, 'foreground', 'The upper slice is the roof/eave foreground.');
assert.equal(split3[1].depthBand, 'y_sort', 'The middle slice follows the character feet.');
assert.equal(split3[2].depthBand, 'background', 'The lower slice stays behind the character.');
assert.equal(splitSegmentDepth(split3[0], {layer: 1}, 144), TOWN_FOREGROUND_DEPTH + 144, 'Upper slice stays in front.');
assert.equal(splitSegmentDepth(split3[2], {layer: 1}, 288), TOWN_BACKGROUND_DEPTH + 288, 'Lower slice stays behind.');
assert.equal(splitSegmentDepth(split3[1], {layer: 1}, 240), objectDepth({sort_mode: 'y_sort'}, {layer: 1}, 240), 'Middle slice follows foot y-sort.');
const playerDepth = y => y + TOWN_PLAYER_LAYER * TOWN_LAYER_STEP;
assert(splitSegmentDepth(split3[0], {layer: 1}, 144) > playerDepth(240), 'Upper slice must remain above a player at the house middle.');
assert(splitSegmentDepth(split3[2], {layer: 1}, 288) < playerDepth(240), 'Lower slice must remain below a player at the house middle.');
assert(splitSegmentDepth(split3[1], {layer: 1}, 192) < playerDepth(240), 'Middle slice is behind a player below its boundary.');
assert(splitSegmentDepth(split3[1], {layer: 1}, 288) > playerDepth(240), 'Middle slice is in front of a player below its boundary.');
const fangwu01 = splitDisplaySegments({
  id: 'town-source-fangwu01', sort_mode: 'split', category: 'building',
  width: 128, height: 176, display_width: 128, display_height: 176,
  split_lines: [96, 144]
});
assert.deepEqual(fangwu01.map(segment => [segment.sourceY, segment.sourceHeight, segment.displayHeight, segment.depthBand]), [
  [0, 96, 96, 'foreground'], [96, 48, 48, 'y_sort'], [144, 32, 32, 'background']
], 'town-source-fangwu01 keeps all three source regions contiguous.');
assert(splitSegmentDepth(fangwu01[0], {layer: 1}, 96) > playerDepth(128), 'fangwu01 upper roof stays in front.');
assert(splitSegmentDepth(fangwu01[1], {layer: 1}, 144) > playerDepth(128), 'fangwu01 middle wall is in front of a player above its lower edge.');
assert(splitSegmentDepth(fangwu01[1], {layer: 1}, 144) < playerDepth(176), 'fangwu01 middle wall is behind a player below its lower edge.');
assert(splitSegmentDepth(fangwu01[2], {layer: 1}, 176) < playerDepth(128), 'fangwu01 lower wall stays behind.');
const fangwuLayout = splitSegmentLayout({
  id: 'town-source-fangwu01', sort_mode: 'split', category: 'building',
  width: 128, height: 176, display_width: 128, display_height: 176,
  split_lines: [96, 144]
}, 544);
assert.deepEqual(fangwuLayout.map(segment => [segment.topY, segment.bottomY]), [
  [368, 464], [464, 512], [512, 544]
], 'town-source-fangwu01 split slices remain contiguous at the object bottom.');
assert.equal(fangwuLayout.at(-1).bottomY, 544, 'The lower slice keeps the authoritative object bottom.');
assert.equal(fangwuLayout[0].topY, 368, 'The upper slice starts at the full display top.');
assert.match(code, /destroyObjectVisual\(placement\.instance_id\)/);
assert.match(code, /objectSegmentImages\.get\(placement\.instance_id\)/);
assert.doesNotMatch(code, /cropY\s*\+\s*Math\.floor\(\(y\s*-\s*visual\.getTopLeft\(\)\.y\)/,
  'Per-slice hit testing must not add the original source Y to an already cropped texture.');
assert.match(code, /const bounds = visual\.getBounds\(\);\s*if \(!bounds\.contains\(x, y\)\) continue;/,
  'Per-slice hit testing must reject points outside the visual bounds before reading pixel alpha.');
assert.match(code, /if \(alpha !== null && alpha > 24\) return placement;/,
  'A null alpha from an out-of-bounds texture read must not select an unrelated placement.');
assert.doesNotMatch(code, /if \(alpha === null \|\| alpha > 24\) return placement;/,
  'Out-of-bounds getPixelAlpha results must not be treated as hits.');
// Split slices must use real per-slice textures. Reused Phaser Images must
// clear a crop left by an earlier two-part catalog before a three-part update.
assert.match(code, /refreshSplitTextures\(item\)/);
assert.match(code, /item\.width = w; item\.height = h/,
  'Decoded town frames must be the source of truth for split sampling dimensions.');
assert.match(code, /texture\.source\?\.\[0\]\?\.update\?\.\(\)/,
  'Canvas-backed split textures must update through Phaser TextureSource.');
assert.doesNotMatch(code, /texture\.refresh\(\)/,
  'Phaser Texture has no refresh() method; a thrown refresh would drop later slices.');
assert.match(code, /this\.textures\.exists\(key\) \? this\.textures\.get\(key\) : null/,
  'Missing split texture keys must not reuse Phaser __MISSING.');
assert.match(code, /const textureKey = splitSegments\.length \? splitTextureKeys\[index\]/);
assert.match(code, /setTexture\(textureKey\)\s*\.setOrigin\(\.5, 1\)\s*\.setDisplaySize/,
  'Split slices use complete textures and must not apply a Phaser crop rectangle.');
assert.match(code, /image\.setCrop\(\);/,
  'Split slices clear any old Phaser crop state after binding the current slice texture.');
assert.match(code, /image\.isCropped\s*=\s*false/,
  'Split slices explicitly clear Phaser crop state for WebView builds that retain the flag.');
assert.match(code, /cropped: image\.isCropped === true/,
  'Diagnostics report Phaser crop state rather than the always-present crop object.');
assert.match(code, /objectSegmentSignatures/);
assert.match(code, /signature = definitions\.map/);
assert.match(code, /signature change is the safe point to rebuild the segment objects/i);
assert.match(code, /objectForegroundImages\.set\(placement\.instance_id, splitSegments\.length > 1 \? segments\[0\]/);
assert.doesNotMatch(code, /setCrop\(0,\s*segment\.sourceY,\s*item\.width,\s*segment\.sourceHeight\)/);
assert.match(code, /placeSelectedAsset\(world\)/);
assert.match(html, /id="brush-toggle"/);
assert.match(html, /town\.js\?v=20261117-house-split27/);
assert.match(code, /town-depth\.js\?v=20261117-house-split27/);
assert.match(code, /const sourceHeight = Number\(source\.height\)/,
  'Split textures use the decoded frame height instead of a stale catalog height.');
assert.match(code, /item\.width = sourceWidth;\s*item\.height = sourceHeight/,
  'Split layout dimensions are synchronized before canvas extraction.');
assert.match(code, /splitSegmentLayout\(item, y\)/,
  'All split slices use one contiguous screen-space layout calculation.');
assert.match(code, /legacyImage && !segments\.includes\(legacyImage\)/,
  'Changing from the legacy full image to split slices must destroy the stale image.');
console.log('Town asset automatic sort-mode checks passed');
