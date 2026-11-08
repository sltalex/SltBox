export const clone = value => value == null ? value : JSON.parse(JSON.stringify(value));
export const footprint = (item, x, y) => ({ x, y, width: Number(item?.footprint?.width) || 1, height: Number(item?.footprint?.height) || 1 });
export const intersects = (a, b) => a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
export const itemFor = (catalog, id) => catalog?.items?.find(item => item.id === id || item.asset_id === id || item.decoration_id === id) || null;
export const objectsOf = layout => Array.isArray(layout?.objects) ? layout.objects : Array.isArray(layout?.placements) ? layout.placements : [];
export const normalizedObject = object => ({
  object_id: object.object_id || object.instance_id || crypto.randomUUID().replaceAll('-', ''),
  instance_id: object.instance_id || object.object_id,
  asset_id: object.asset_id || object.decoration_id,
  decoration_id: object.decoration_id || object.asset_id,
  owner_id: object.owner_id || object.owner || '',
  owner_display: object.owner_display || object.owner_name || '',
  tile_x: Number(object.tile_x), tile_y: Number(object.tile_y),
  object_version: Number.isInteger(object.object_version) ? object.object_version : Number(object.version) || 1,
  chunk_x: Number.isInteger(object.chunk_x) ? object.chunk_x : Math.floor(Number(object.tile_x) / 24),
  chunk_y: Number.isInteger(object.chunk_y) ? object.chunk_y : Math.floor(Number(object.tile_y) / 24)
});

export function chunkKey(x, y) { return `${x},${y}`; }
export function chunkOf(catalog, x, y) { const size = catalog.chunk_size || 24; return { x: Math.floor(x / size), y: Math.floor(y / size) }; }
export function protectedAreas(catalog) {
  const world = catalog.world || {};
  return [world.main_tree || world.tree, world.spawn, world.center_square].filter(area => area && Number.isFinite(area.x));
}
export function riverArea(catalog) { return catalog.world?.river || null; }
export function bridgeArea(catalog) { return catalog.world?.bridge || null; }
export function forestAreas(catalog) {
  const world = catalog.world || {};
  if (Array.isArray(world.forest_regions)) return world.forest_regions.filter(area => area && Number.isFinite(area.x));
  if (Array.isArray(world.forests)) return world.forests.filter(area => area && Number.isFinite(area.x));
  return world.forest ? [world.forest] : [];
}
export function forestArea(catalog) { return forestAreas(catalog)[0] || null; }
export function terrainAt(catalog, x, y) {
  const river = riverArea(catalog), bridge = bridgeArea(catalog), forests = forestAreas(catalog);
  const tile = { x, y, width: 1, height: 1 };
  if (river && intersects(tile, river) && !(bridge && intersects(tile, bridge))) return 'water';
  if (forests.some(forest => intersects(tile, forest))) return 'forest';
  return 'plain';
}

export function validatePlacement(catalog, layout, assetId, x, y, ignoreId = null, player = null, options = {}) {
  const item = itemFor(catalog, assetId);
  if (!item || !Number.isInteger(x) || !Number.isInteger(y)) return '请选择有效的材料';
  const rect = footprint(item, x, y);
  const width = catalog.map_width || catalog.world?.width_tiles || 192;
  const height = catalog.map_height || catalog.world?.height_tiles || 192;
  if (x < 0 || y < 0 || x + rect.width > width || y + rect.height > height) return '物件超出地图边界';
  for (const area of protectedAreas(catalog)) if (intersects(rect, area)) return '这里是主树、出生区或中心保留区';
  const river = riverArea(catalog), bridge = bridgeArea(catalog), forests = forestAreas(catalog);
  if (river && intersects(rect, river) && !(bridge && intersects(rect, bridge))) return '河流区域不能放置装饰';
  if (forests.some(forest => intersects(rect, forest))) return '森林区域保留为公共地形';
  const values = objectsOf(layout);
  for (const placement of values) {
    if ((placement.object_id || placement.instance_id) === ignoreId) continue;
    const other = itemFor(catalog, placement.asset_id || placement.decoration_id);
    if (other && intersects(rect, footprint(other, Number(placement.tile_x), Number(placement.tile_y)))) return '物件占地发生重叠';
  }
  if (player && !options.ignorePlayer) {
    const size = catalog.tile_size || 16;
    const body = { x: (player.x - 3) / size, y: (player.y - 4) / size, width: 6 / size, height: 4 / size };
    if (intersects(rect, body)) return '请先让人物离开放置位置';
  }
  return null;
}

export function validateLayout(catalog, layout) {
  if (!layout || (layout.template_version !== 2 && layout.template_version !== 1) || (layout.catalog_version && layout.catalog_version !== catalog.version && layout.catalog_version !== catalog.catalog_version) || !Array.isArray(objectsOf(layout))) return '小镇布局格式不兼容';
  const ids = new Set(), checked = { ...layout, objects: [] };
  for (const raw of objectsOf(layout)) {
    const value = normalizedObject(raw);
    const id = value.object_id;
    if (!id || !/^[A-Za-z0-9_-]{1,128}$/.test(id) || ids.has(id)) return '摆放对象编号无效或重复';
    const error = validatePlacement(catalog, checked, value.asset_id, value.tile_x, value.tile_y, null, null, { ignorePlayer: true });
    if (error) return error;
    ids.add(id); checked.objects.push(value);
  }
  return null;
}

export function playerCanStand(catalog, layout, x, y) {
  const size = catalog.tile_size || 16;
  const width = catalog.map_width || 192, height = catalog.map_height || 192;
  const body = { x: (x - 3) / size, y: (y - 4) / size, width: 6 / size, height: 4 / size };
  if (body.x < 0 || body.y < 0 || body.x + body.width > width || body.y + body.height > height) return false;
  const river = riverArea(catalog), bridge = bridgeArea(catalog), forests = forestAreas(catalog);
  if (river && intersects(body, river) && !(bridge && intersects(body, bridge))) return false;
  if (forests.some(forest => intersects(body, forest))) return false;
  const mainTree = catalog.world?.main_tree || catalog.world?.tree;
  if (mainTree && intersects(body, mainTree)) return false;
  return !objectsOf(layout).some(placement => {
    const item = itemFor(catalog, placement.asset_id || placement.decoration_id);
    const blockingSubcategories = new Set(['building', 'pavilion', 'shelters', 'greenhouses', 'gates', 'towers']);
    const solid = item?.solid === true || blockingSubcategories.has(item?.subcategory);
    return solid && intersects(body, footprint(item, Number(placement.tile_x), Number(placement.tile_y)));
  });
}
