import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const code = await readFile(new URL('../src/DeskBox/Assets/Tree/TownPrototype/town-rules.js', import.meta.url), 'utf8');
const { clone, intersects, validatePlacement, validateLayout, playerCanStand } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const catalog = {
  version: 1, tile_size: 16, map_width: 48, map_height: 48,
  world: { pond: { x: 34, y: 8, width: 9, height: 8 }, tree: { x: 23, y: 22, width: 3, height: 3 }, spawn: { x: 23, y: 29, width: 3, height: 3 } },
  items: [{ id: 'bench', footprint: { width: 2, height: 1 }, solid: true }, { id: 'flower', footprint: { width: 1, height: 1 }, solid: false }]
};
const initial = { template_version: 1, catalog_version: 1, demo_stage: 5, placements: [{ instance_id: 'first', asset_id: 'bench', tile_x: 10, tile_y: 10 }] };
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
check(validateLayout(catalog, initial) === null, 'valid initial layout');
check(validatePlacement(catalog, initial, 'bench', 12, 10) === null, 'adjacent footprints may share an edge');
check(validatePlacement(catalog, initial, 'bench', 11, 10) !== null, 'footprints may not overlap');
check(validatePlacement(catalog, initial, 'bench', -1, 8) !== null, 'negative map boundary');
check(validatePlacement(catalog, initial, 'bench', 47, 8) !== null, 'whole footprint must fit map');
check(validatePlacement(catalog, initial, 'bench', 46, 47) === null, 'last full fitting tile accepted');
for (const area of Object.values(catalog.world)) check(validatePlacement(catalog, initial, 'flower', area.x, area.y) !== null, 'reserved and water areas excluded');
check(validatePlacement(catalog, initial, 'unknown', 4, 4) !== null, 'unknown material rejected');
check(validatePlacement(catalog, initial, 'bench', 4.5, 4) !== null, 'grid coordinates required');
check(validatePlacement(catalog, initial, 'bench', 10, 10, 'first') === null, 'moving object ignores its old footprint');
check(validatePlacement(catalog, initial, 'flower', 8, 8, null, { x: 136, y: 136 }) !== null, 'cannot place onto player feet');
const full = clone(initial);
full.placements.push({ instance_id: 'second', asset_id: 'bench', tile_x: 14, tile_y: 10 }, { instance_id: 'third', asset_id: 'bench', tile_x: 18, tile_y: 10 });
check(validateLayout(catalog, full) === null, 'three available demo instances accepted');
check(validatePlacement(catalog, full, 'bench', 20, 10) !== null, 'cannot use a fourth demo instance');
check(validatePlacement(catalog, full, 'bench', 20, 10, 'first') === null, 'moving does not consume another instance');
const duplicate = clone(initial);
duplicate.placements.push({ ...duplicate.placements[0], tile_x: 14 });
check(validateLayout(catalog, duplicate) !== null, 'duplicate instance id rejected');
for (const demo_stage of [0, 6, 1.5]) check(validateLayout(catalog, { ...initial, demo_stage }) !== null, 'invalid growth stage rejected');
check(validateLayout(catalog, { ...initial, catalog_version: 2 }) !== null, 'incompatible catalog version rejected');
check(!playerCanStand(catalog, initial, 165, 165), 'solid object blocks feet');
check(playerCanStand(catalog, { ...initial, placements: [{ instance_id: 'flower', asset_id: 'flower', tile_x: 10, tile_y: 10 }] }, 165, 165), 'non-solid flowers remain walkable');
check(!playerCanStand(catalog, initial, 34 * 16 + 8, 8 * 16 + 8), 'water blocks feet');
check(!playerCanStand(catalog, initial, 24 * 16, 24 * 16), 'main tree trunk blocks feet');
check(!playerCanStand(catalog, initial, 0, 8), 'player body cannot escape world');
check(playerCanStand(catalog, initial, 24.5 * 16, 30.5 * 16), 'spawn remains walkable');
check(!intersects({ x: 0, y: 0, width: 1, height: 1 }, { x: 1, y: 0, width: 1, height: 1 }), 'edge touch does not count as overlap');
assert.deepEqual(initial.placements, [{ instance_id: 'first', asset_id: 'bench', tile_x: 10, tile_y: 10 }]);
checks++;

try {
  const actualCatalog = JSON.parse(await readFile(new URL('../src/DeskBox/Assets/Tree/TownPrototype/catalog.json', import.meta.url), 'utf8'));
  const actualDefault = JSON.parse(await readFile(new URL('../src/DeskBox/Assets/Tree/TownPrototype/default-layout.json', import.meta.url), 'utf8'));
  check(actualCatalog.items.length === 16, 'exactly sixteen distinct catalog entries');
  check(validateLayout(actualCatalog, actualDefault) === null, 'real preset obeys the same placement contract');
} catch (error) {
  if (error.code !== 'ENOENT') throw error;
  console.log('Catalog assets still being generated; pure-rule checks completed.');
}
console.log(`Town prototype rule checks passed: ${checks}`);
