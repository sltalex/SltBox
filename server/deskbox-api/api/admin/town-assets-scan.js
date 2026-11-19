(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.townAssetScan = factory();
}(typeof globalThis === 'object' ? globalThis : this, function () {
  'use strict';

  function findTransparentGridRegions(width, height, alphaAt, grid, threshold) {
    const cellSize = Math.max(1, Number(grid) || 16);
    const alphaThreshold = Math.max(0, Number(threshold) || 8);
    const columns = Math.ceil(width / cellSize);
    const rows = Math.ceil(height / cellSize);
    const occupied = new Set();
    for (let gy = 0; gy < rows; gy += 1) {
      for (let gx = 0; gx < columns; gx += 1) {
        let found = false;
        for (let y = gy * cellSize; y < Math.min(height, (gy + 1) * cellSize) && !found; y += 1) {
          for (let x = gx * cellSize; x < Math.min(width, (gx + 1) * cellSize); x += 1) {
            if (Number(alphaAt(x, y)) >= alphaThreshold) { found = true; break; }
          }
        }
        if (found) occupied.add(gx + ',' + gy);
      }
    }
    const remaining = new Set(occupied);
    const regions = [];
    while (remaining.size) {
      const first = remaining.values().next().value;
      remaining.delete(first);
      const queue = [first];
      const cells = [];
      while (queue.length) {
        const key = queue.shift();
        const [x, y] = key.split(',').map(Number);
        cells.push([x, y]);
        for (const neighbor of [[x - 1, y], [x + 1, y], [x, y - 1], [x, y + 1]]) {
          const neighborKey = neighbor[0] + ',' + neighbor[1];
          if (remaining.delete(neighborKey)) queue.push(neighborKey);
        }
      }
      const left = Math.min(...cells.map(cell => cell[0])) * cellSize;
      const top = Math.min(...cells.map(cell => cell[1])) * cellSize;
      const right = Math.min(width, (Math.max(...cells.map(cell => cell[0])) + 1) * cellSize);
      const bottom = Math.min(height, (Math.max(...cells.map(cell => cell[1])) + 1) * cellSize);
      regions.push({ x: left, y: top, w: right - left, h: bottom - top });
    }
    return regions.sort((a, b) => a.y - b.y || a.x - b.x);
  }

  return { findTransparentGridRegions };
}));
