import assert from 'node:assert/strict';
import scanner from '../server/deskbox-api/api/admin/town-assets-scan.js';
const { findTransparentGridRegions } = scanner;

const width = 64;
const height = 32;
const alpha = new Uint8Array(width * height);
const mark = (x, y, w, h) => {
  for (let yy = y; yy < y + h; yy += 1) {
    for (let xx = x; xx < x + w; xx += 1) alpha[yy * width + xx] = 255;
  }
};
mark(0, 0, 16, 16);
mark(32, 0, 16, 32);

const regions = findTransparentGridRegions(width, height, (x, y) => alpha[y * width + x], 16, 8);
assert.deepEqual(regions, [
  { x: 0, y: 0, w: 16, h: 16 },
  { x: 32, y: 0, w: 16, h: 32 }
]);
console.log('town auto-scan checks passed');
