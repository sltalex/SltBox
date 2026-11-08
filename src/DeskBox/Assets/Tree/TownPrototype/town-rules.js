export const clone = value => JSON.parse(JSON.stringify(value));
export const footprint = (item, x, y) => ({ x, y, width: item.footprint.width, height: item.footprint.height });
export const intersects = (a, b) => a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;

export function validatePlacement(catalog, layout, assetId, x, y, ignoreId = null, player = null) {
  const item = catalog.items.find(value => value.id === assetId);
  if (!item || !Number.isInteger(x) || !Number.isInteger(y)) return '请选择有效的材料';
  const rect = footprint(item, x, y);
  if (x < 0 || y < 0 || x + rect.width > catalog.map_width || y + rect.height > catalog.map_height) return '物件超出地图边界';
  if ([catalog.world.pond, catalog.world.tree, catalog.world.spawn].some(area => intersects(rect, area))) return '这里是水面、主树或出生区域';
  const other = layout.placements.filter(value => value.instance_id !== ignoreId);
  if (other.length >= 48) return '最多摆放 48 件装饰';
  if (other.filter(value => value.asset_id === assetId).length >= 3) return '这种材料已全部使用';
  for (const placement of other) {
    const otherItem = catalog.items.find(value => value.id === placement.asset_id);
    if (otherItem && intersects(rect, footprint(otherItem, placement.tile_x, placement.tile_y))) return '物件占地发生重叠';
  }
  if (player && intersects(rect, { x: (player.x - 3) / catalog.tile_size, y: (player.y - 4) / catalog.tile_size, width: 6 / catalog.tile_size, height: 4 / catalog.tile_size })) return '请先让人物离开放置位置';
  return null;
}

export function validateLayout(catalog, layout) {
  if (!layout || layout.template_version !== 1 || layout.catalog_version !== catalog.version || !Number.isInteger(layout.demo_stage) || layout.demo_stage < 1 || layout.demo_stage > 5 || !Array.isArray(layout.placements) || layout.placements.length > 48) return '小镇布局格式不兼容';
  const ids = new Set();
  const checked = { ...layout, placements: [] };
  for (const value of layout.placements) {
    if (!value || typeof value.instance_id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(value.instance_id) || ids.has(value.instance_id)) return '摆放对象编号无效或重复';
    const error = validatePlacement(catalog, checked, value.asset_id, value.tile_x, value.tile_y);
    if (error) return error;
    ids.add(value.instance_id);
    checked.placements.push(value);
  }
  return null;
}

export function playerCanStand(catalog, layout, x, y) {
  const size = catalog.tile_size;
  const body = { x: (x - 3) / size, y: (y - 4) / size, width: 6 / size, height: 4 / size };
  if (body.x < 0 || body.y < 0 || body.x + body.width > catalog.map_width || body.y + body.height > catalog.map_height) return false;
  if ([catalog.world.pond, catalog.world.tree].some(area => intersects(body, area))) return false;
  return !layout.placements.some(placement => {
    const item = catalog.items.find(value => value.id === placement.asset_id);
    return item?.solid && intersects(body, footprint(item, placement.tile_x, placement.tile_y));
  });
}
