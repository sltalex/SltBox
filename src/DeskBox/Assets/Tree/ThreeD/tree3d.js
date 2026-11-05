import * as THREE from './three.module.min.js';
import { createTreeMap } from './tree-map.js';

// Coordinates are the native 260 x 260 scene, including the 50px tree offset.
// C# supplies fruit locations, animal trajectories and decoration transforms.
const W = 260;
const H = 260;
const canvas = document.createElement('canvas');
canvas.setAttribute('aria-label', '3D tree');
canvas.tabIndex = 0;
document.body.appendChild(canvas);
const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power' });
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.setClearColor(0x000000, 0);
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.25;

const scene = new THREE.Scene();
const camera = new THREE.OrthographicCamera(-180, 180, 180, -180, .1, 2000);
const ambient = new THREE.HemisphereLight(0xf8ffe5, 0x3c6555, 2.1);
const sunlight = new THREE.DirectionalLight(0xffdfac, 3);
sunlight.position.set(-120, 300, 400);
scene.add(ambient, sunlight);
const ground = new THREE.Group();
const tree = new THREE.Group();
const crown = new THREE.Group();
const fruits = new THREE.Group();
const wildlife = new THREE.Group();
const decorations = new THREE.Group();
const treeDecorations = new THREE.Group();
const trunkDecorations = new THREE.Group();
const weather = new THREE.Group();
const leaves = new THREE.Group();
scene.add(ground, tree, wildlife, decorations, weather, leaves);
tree.add(crown);
tree.add(trunkDecorations);
crown.add(fruits);
crown.add(treeDecorations);
const textureLoader = new THREE.TextureLoader();
const textures = new Map();
let state = {};
let visible = false;
let animationEnabled = false;
let stage = 0;
let fruitKey = '';
let wildlifeKey = '';
let decorationKey = '';
let weatherKey = '';
let animal = null;
let clock = 0;
let animalStart = 0;
let request = 0;
let lastFrame = 0;
let disposed = false;
let frameTransport = false;
let framePending = false;
let frameDirty = false;
const cursor = new THREE.Vector2();
const raycaster = new THREE.Raycaster();

function report(message) {
  window.chrome?.webview?.postMessage(message);
}
function fail(error) {
  report({ type: 'error', message: String(error?.message || error).slice(0, 300) });
  visible = false;
  if (request) cancelAnimationFrame(request);
  request = 0;
}
canvas.addEventListener('webglcontextlost', event => {
  event.preventDefault();
  fail(new Error('WebGL context lost'));
});

function clear(group) {
  for (const child of [...group.children]) {
    group.remove(child);
    child.traverse(node => {
      node.geometry?.dispose();
      if (node.material) {
        const materials = Array.isArray(node.material) ? node.material : [node.material];
        materials.forEach(material => material.dispose());
      }
    });
  }
}
function material(color) {
  return new THREE.MeshStandardMaterial({ color, flatShading: true, roughness: .92 });
}
function mesh(parent, geometry, color, x, y, z = 0) {
  const object = new THREE.Mesh(geometry, material(color));
  object.position.set(x, H - y, z);
  parent.add(object);
  return object;
}
function branch(x1, y1, x2, y2, radius, color = 0x90633e) {
  const start = new THREE.Vector3(x1, H - y1, 0);
  const end = new THREE.Vector3(x2, H - y2, 3);
  const direction = end.clone().sub(start);
  const object = new THREE.Mesh(new THREE.CylinderGeometry(radius * .62, radius, direction.length(), 7), material(color));
  object.position.copy(start).add(end).multiplyScalar(.5);
  object.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), direction.normalize());
  tree.add(object);
}
function foliage(x, y, rx, ry, rz, color) {
  const object = mesh(crown, new THREE.IcosahedronGeometry(1, 1), color, x - 130, y + 82);
  object.scale.set(rx, ry, rz);
  return object;
}
function makeTree(value) {
  tree.remove(crown);
  tree.remove(trunkDecorations);
  crown.remove(fruits);
  crown.remove(treeDecorations);
  clear(tree);
  clear(crown);
  crown.add(fruits);
  crown.add(treeDecorations);
  tree.add(crown);
  tree.add(trunkDecorations);
  // Pivot matches the native crown. Children use the same visual coordinates.
  crown.position.set(130, H - 178, 0);
  if (value === 1) {
    branch(130, 244, 129, 211, 3);
    foliage(116, 214, 16, 7, 6, 0x70bb63).rotation.z = -.32;
    foliage(139, 202, 15, 8, 6, 0x94cf73).rotation.z = .45;
    return;
  }
  const top = [0, 0, 186, 162, 143, 130][value];
  const radius = [0, 0, 42, 52, 72, 88][value];
  branch(130, 244, 126, top + 6, 4.8 + value);
  branch(129, 222, 104, top + 14, 3.5 + value * .35);
  branch(130, 211, 157, top + 8, 3.5 + value * .35);
  branch(126, 192, 115, top - 20, 3);
  branch(134, 224, 145, 208, 2.7, 0xb98956);
  const clusters = [
    [-.60, .12, .48, .40, 0x4f9d57], [.56, .09, .49, .42, 0x67ae58],
    [0, -.32, .55, .46, 0x7fbe63], [-.31, -.18, .46, .44, 0x8ac76c],
    [.30, -.24, .46, .45, 0x91ca70], [0, .15, .58, .40, 0x63aa55]
  ];
  for (let i = 0; i < clusters.length; i++) {
    const [dx, dy, rx, ry, color] = clusters[i];
    const object = foliage(130 + dx * radius, top + dy * radius, rx * radius, ry * radius, 21 + value * 3, color);
    object.position.z = i * 3;
  }
  if (value >= 4) {
    for (const [x, y, size] of [[-.72, -.20, .29], [.72, -.18, .31], [-.14, -.62, .36]]) {
      foliage(130 + radius * x, top + radius * y, radius * size, radius * size * .85, 20, 0xa2d67e);
    }
  }
}
function assetUrl(asset) {
  if (typeof asset !== 'string' || asset.includes('..') || asset.includes(':') ||
      asset.startsWith('/') || asset.includes('\\')) throw new Error('Invalid local texture');
  return '../' + asset;
}
function texture(asset) {
  if (textures.has(asset)) return textures.get(asset);
  const image = textureLoader.load(assetUrl(asset), loaded => {
    // WebGL cannot reliably upload viewBox-only SVG images on all ANGLE drivers.
    // Rasterize the existing local artwork without changing the source resource.
    if (asset.toLowerCase().endsWith('.svg')) {
      const bitmap = document.createElement('canvas');
      bitmap.width = Math.max(1, loaded.image.naturalWidth || 256);
      bitmap.height = Math.max(1, loaded.image.naturalHeight || 256);
      bitmap.getContext('2d').drawImage(loaded.image, 0, 0, bitmap.width, bitmap.height);
      loaded.image = bitmap;
      loaded.needsUpdate = true;
    }
    requestRender();
  }, undefined,
    () => fail(new Error('Unable to load local texture: ' + asset)));
  image.colorSpace = THREE.SRGBColorSpace;
  textures.set(asset, image);
  return image;
}
function sprite(parent, asset, x, y, width, height, order, depthTest = false) {
  const map = texture(asset);
  const object = new THREE.Sprite(new THREE.SpriteMaterial({
    map, transparent: true, depthTest, depthWrite: false, toneMapped: false, alphaTest: .05
  }));
  object.position.set(x, H - y, 80);
  object.scale.set(width, height, 1);
  object.renderOrder = order;
  parent.add(object);
  return object;
}
function treeSurfaces() {
  return [...tree.children, ...crown.children].filter(object => object.isMesh);
}
// Native positions describe a flat illustration. Bind them once to actual tree
// geometry; camera changes must never change an item's attachment in the world.
function treeAttachment(x, y, surfaces) {
  const point = new THREE.Vector3(x, H - y, 40);
  const normal = new THREE.Vector3(0, 0, 1);
  let attachedSurface;
  const ray = new THREE.Raycaster(new THREE.Vector3(x, H - y, 512), new THREE.Vector3(0, 0, -1));
  const hit = ray.intersectObjects(surfaces, false)[0];
  if (hit) {
    attachedSurface = hit.object;
    point.copy(hit.point);
    normal.copy(hit.face.normal).applyNormalMatrix(new THREE.Matrix3().getNormalMatrix(hit.object.matrixWorld));
  } else {
    // At the edge, old artwork coordinates may fall outside the new silhouette.
    // Find its nearest real surface instead of leaving the object in empty air.
    const triangle = new THREE.Triangle(), closest = new THREE.Vector3();
    const source = point.clone();
    let distance = Infinity;
    for (const surface of surfaces) {
      const geometry = surface.geometry, vertices = geometry.attributes.position, indices = geometry.index;
      const count = indices ? indices.count : vertices.count;
      for (let index = 0; index < count; index += 3) {
        triangle.a.fromBufferAttribute(vertices, indices ? indices.getX(index) : index).applyMatrix4(surface.matrixWorld);
        triangle.b.fromBufferAttribute(vertices, indices ? indices.getX(index + 1) : index + 1).applyMatrix4(surface.matrixWorld);
        triangle.c.fromBufferAttribute(vertices, indices ? indices.getX(index + 2) : index + 2).applyMatrix4(surface.matrixWorld);
        triangle.closestPointToPoint(source, closest);
        const nextDistance = source.distanceToSquared(closest);
        if (nextDistance < distance) {
          attachedSurface = surface;
          distance = nextDistance;
          point.copy(closest);
          triangle.getNormal(normal);
        }
      }
    }
  }
  point.addScaledVector(normal, 3);
  return { point, surface: attachedSurface };
}
function makeFruits(items) {
  clear(fruits);
  scene.updateMatrixWorld(true);
  const surfaces = crown.children.filter(object => object.isMesh);
  for (const item of items) {
    const object = sprite(fruits, item.asset || 'fruit.svg', item.x - 130,
      item.y + 82, 24, 28, 10, true);
    object.position.copy(fruits.worldToLocal(treeAttachment(item.x, item.y, surfaces).point));
    object.userData = { type: 'fruit', index: item.index, pendingId: String(item.pendingId || '0') };
    object.material.opacity = item.picking ? .35 : 1;
    object.userData.disabled = !!item.picking;
  }
}
function makeAnimal(item) {
  clear(wildlife);
  animal = null;
  if (!item) return;
  animal = sprite(wildlife, item.asset, item.x, item.y, item.visualSize, item.visualSize, 20);
  animal.userData = { type: 'wildlife', encounterId: item.encounterId, disabled: !!item.capturing };
  animal.material.opacity = item.capturing ? .35 : 1;
  animalStart = clock;
}
function makeDecorations(items) {
  clear(decorations);
  clear(treeDecorations);
  clear(trunkDecorations);
  scene.updateMatrixWorld(true);
  const surfaces = treeSurfaces();
  for (const item of items) {
    const size = (item.size || 50) * item.scale;
    if (item.category === 'tree') {
      const attachment = treeAttachment(item.x * W, item.y * H, surfaces);
      const parent = attachment.surface?.parent === crown ? treeDecorations : trunkDecorations;
      const object = sprite(parent, item.asset, 0, 0, size, size, 8 + item.layer * 8, true);
      object.position.copy(parent.worldToLocal(attachment.point));
      object.material.rotation = -item.rotation * Math.PI / 180;
    } else {
      // Upright cutouts turn horizontally only. Their base stays on the ground
      // even at the steepest camera pitch, unlike a camera-facing Sprite.
      const geometry = new THREE.PlaneGeometry(size, size);
      geometry.rotateZ(-item.rotation * Math.PI / 180);
      geometry.computeBoundingBox();
      geometry.translate(0, -geometry.boundingBox.min.y, 0);
      const object = new THREE.Mesh(geometry, new THREE.MeshBasicMaterial({
        map: texture(item.asset), transparent: true, depthTest: true, depthWrite: false,
        toneMapped: false, alphaTest: .05, side: THREE.DoubleSide
      }));
      object.position.set(item.x * W, 16, (item.y - .72) * 240);
      object.renderOrder = 8 + item.layer * 8;
      decorations.add(object);
    }
  }
}
function makeWeather(condition, isDay) {
  clear(weather);
  sunlight.intensity = isDay ? 3 : .75;
  ambient.intensity = isDay ? 2.1 : 1.2;
  if (condition === 'Clear') {
    const object = mesh(weather, new THREE.SphereGeometry(12, 12, 8), isDay ? 0xffd579 : 0xd7e5ee, 222, 32, -10);
    if (isDay) {
      for (let i = 0; i < 8; i++) {
        const ray = mesh(weather, new THREE.BoxGeometry(2, 7, 1), 0xf8cf6b,
          222 + Math.cos(i * Math.PI / 4) * 20, 32 + Math.sin(i * Math.PI / 4) * 20, -11);
        ray.rotation.z = Math.PI / 2 - i * Math.PI / 4;
      }
    } else object.scale.x = .8;
  }
  if (['Cloudy', 'Drizzle', 'Rain', 'Snow', 'Thunderstorm'].includes(condition)) {
    const dark = ['Rain', 'Thunderstorm'].includes(condition);
    for (let i = 0; i < 6; i++) {
      const cloud = mesh(weather, new THREE.IcosahedronGeometry(1, 1), dark ? 0x899da8 : 0xe9f1ee,
        32 + i * 36, 25 + (i % 3) * 7, -20);
      cloud.scale.set(20, 8 + (i % 2) * 4, 6);
    }
  }
  if (condition === 'Fog') {
    for (let i = 0; i < 3; i++) {
      const fog = mesh(weather, new THREE.PlaneGeometry(230, 20), 0xe2ede9, 130, 80 + i * 64, 100);
      fog.material.transparent = true;
      fog.material.opacity = .24;
    }
  }
  const snow = condition === 'Snow';
  const count = snow ? 24 : condition === 'Drizzle' ? 9 : ['Rain', 'Thunderstorm'].includes(condition) ? 28 : 0;
  for (let i = 0; i < count; i++) {
    const drop = mesh(weather, snow ? new THREE.SphereGeometry(1.7, 5, 4) : new THREE.BoxGeometry(.9, 9, .9),
      snow ? 0xffffff : 0x91cbe7, (i * 79 + 11) % W, (i * 43 + 9) % H, 110);
    drop.userData.drop = true;
  }
  if (snow) mesh(weather, new THREE.SphereGeometry(1, 24, 8), 0xe5f2f4, 130, 238, 20).scale.set(94, 6, 30);
  if (condition === 'Thunderstorm') {
    const bolt = mesh(weather, new THREE.ConeGeometry(5, 24, 3), 0xffed97, 204, 62, 100);
    bolt.rotation.z = -.3;
  }
}
function makeLeaves(value) {
  clear(leaves);
  if (value < 3) return;
  for (let i = 0; i < (value === 3 ? 2 : 3); i++) {
    const leaf = mesh(leaves, new THREE.IcosahedronGeometry(1, 0), 0xb9cb76, 90 + i * 30, 170, 40);
    leaf.scale.set(3, 1.3, .6);
    leaf.userData.phase = i * 2.1;
  }
}
function sampleMotion(item, elapsed, enabled) {
  const frames = item.frames || [];
  if (!frames.length) return { x: 0, y: 0, scaleX: 1, scaleY: 1, rotation: 0, opacity: 1 };
  const at = enabled && !item.capturing ? (elapsed % item.seconds) / item.seconds : 0;
  let a = frames[0], b = frames[frames.length - 1];
  for (let i = 1; i < frames.length; i++) {
    if (at <= frames[i].at) { a = frames[i - 1]; b = frames[i]; break; }
  }
  const t = b.at === a.at ? 0 : Math.max(0, Math.min(1, (at - a.at) / (b.at - a.at)));
  const smooth = (1 - Math.cos(t * Math.PI)) / 2;
  const result = {};
  for (const key of ['x', 'y', 'rotation', 'scaleX', 'scaleY', 'opacity']) {
    const linear = key === 'x' && item.fliesAcrossSky;
    const mix = linear ? t : smooth;
    result[key] = a[key] + (b[key] - a[key]) * mix;
  }
  return result;
}
function updateAnimation() {
  crown.rotation.z = animationEnabled ? Math.sin(clock * Math.PI / 2.8) * .025 : 0;
  const viewDirection = camera.getWorldDirection(new THREE.Vector3());
  const viewYaw = Math.atan2(-viewDirection.x, -viewDirection.z);
  decorations.children.forEach(object => { object.rotation.y = viewYaw; });
  if (animal && state.wildlife) {
    const item = state.wildlife;
    const motion = sampleMotion(item, clock - animalStart, animationEnabled);
    animal.position.set(item.x + motion.x, H - item.y - motion.y, 90);
    animal.scale.set(item.visualSize * motion.scaleX, item.visualSize * motion.scaleY, 1);
    animal.material.rotation = -motion.rotation * Math.PI / 180;
    animal.material.opacity = motion.opacity * (item.capturing ? .35 : 1);
    animal.userData.disabled = !!item.capturing;
  }
  const snow = state.weather === 'Snow';
  weather.children.forEach(object => {
    if (!object.userData.drop) return;
    const i = weather.children.indexOf(object);
    object.position.y = H - ((clock * (snow ? 11 : 95) + i * 43) % H);
  });
  leaves.visible = animationEnabled && !['Rain', 'Snow', 'Thunderstorm'].includes(state.weather);
  leaves.children.forEach((leaf, i) => {
    const phase = (clock + leaf.userData.phase) % 10;
    leaf.position.set(90 + i * 30 + Math.sin(phase) * 15, 100 - phase * 9, 40);
    leaf.rotation.z = phase;
  });
}
function render(time) {
  request = 0;
  if (disposed || !visible || document.hidden) return;
  if (lastFrame && time - lastFrame < (frameTransport ? 66 : 33) && animationEnabled) {
    request = requestAnimationFrame(render);
    return;
  }
  if (lastFrame && animationEnabled) clock += Math.min(.08, (time - lastFrame) / 1000);
  lastFrame = time;
  updateAnimation();
  renderer.render(scene, camera);
  if (frameTransport && !framePending) {
    framePending = true;
    frameDirty = false;
    report({ type: 'frame', png: canvas.toDataURL('image/png') });
  } else if (frameTransport) frameDirty = true;
  if (animationEnabled) request = requestAnimationFrame(render);
}
function requestRender() {
  if (!request && !disposed && visible && !document.hidden) request = requestAnimationFrame(render);
}
function resize() {
  const width = Math.max(1, document.documentElement.clientWidth);
  const height = Math.max(1, document.documentElement.clientHeight);
  map.resize(width / height);
  renderer.setSize(width, height, false);
  requestRender();
}
function setSceneState(next) {
  const oldAnimalId = state.wildlife?.encounterId;
  state = next || {};
  animationEnabled = !!state.animationEnabled;
  const value = Math.max(1, Math.min(5, state.stage || 1));
  if (stage !== value) {
    stage = value;
    makeTree(stage);
    makeLeaves(stage);
    fruitKey = '';
    decorationKey = '';
  }
  const fk = JSON.stringify(state.fruits || []);
  if (fk !== fruitKey) { fruitKey = fk; makeFruits(state.fruits || []); }
  const wk = JSON.stringify(state.wildlife || null);
  if (wk !== wildlifeKey) {
    wildlifeKey = wk;
    if (oldAnimalId !== state.wildlife?.encounterId || !animal) makeAnimal(state.wildlife);
    else if (animal) animal.userData.disabled = !!state.wildlife?.capturing;
  }
  const dk = JSON.stringify(state.decorations || []);
  if (dk !== decorationKey) { decorationKey = dk; makeDecorations(state.decorations || []); }
  const weatherSignature = JSON.stringify([state.weather, state.isDay]);
  if (weatherSignature !== weatherKey) { weatherKey = weatherSignature; makeWeather(state.weather, state.isDay !== false); }
  requestRender();
}
function setVisibility(value) {
  visible = !!value;
  lastFrame = 0;
  if (!visible && request) { cancelAnimationFrame(request); request = 0; }
  if (visible) requestRender();
}
function setAnimationEnabled(value) {
  animationEnabled = !!value;
  lastFrame = 0;
  requestRender();
}
function resetScene() {
  state = {};
  stage = 0;
  fruitKey = wildlifeKey = decorationKey = weatherKey = '';
  clear(fruits); clear(wildlife); clear(decorations); clear(treeDecorations); clear(trunkDecorations); clear(weather); clear(leaves);
  makeTree(1);
  map.reset();
  requestRender();
}
function hitTest(event) {
  const rect = canvas.getBoundingClientRect();
  cursor.set((event.clientX - rect.left) / rect.width * 2 - 1, -(event.clientY - rect.top) / rect.height * 2 + 1);
  raycaster.setFromCamera(cursor, camera);
  if (animal && state.wildlife && !animal.userData.disabled && animal.material.opacity > .05) {
    const point = animal.getWorldPosition(new THREE.Vector3()).project(camera);
    const halfX = state.wildlife.hitSize / (camera.right - camera.left);
    const halfY = state.wildlife.hitSize / (camera.top - camera.bottom);
    if (Math.abs(cursor.x - point.x) <= halfX && Math.abs(cursor.y - point.y) <= halfY)
      return animal.userData;
  }
  const hits = raycaster.intersectObjects(fruits.children);
  const blockers = raycaster.intersectObjects([...treeSurfaces(), ...map.world.children], false);
  return hits.find(hit => !hit.object.userData.disabled &&
    (!blockers.length || hit.distance <= blockers[0].distance + .1))?.object.userData;
}
function tapAtNormalized(x, y) {
  if (!visible || disposed || !Number.isFinite(x) || !Number.isFinite(y) || x < 0 || x > 1 || y < 0 || y > 1) return;
  const rect = canvas.getBoundingClientRect();
  const hit = hitTest({ clientX: rect.left + x * rect.width, clientY: rect.top + y * rect.height });
  if (hit?.type === 'fruit') report({ type: 'fruitTapped', index: hit.index, pendingId: hit.pendingId });
  if (hit?.type === 'wildlife') report({ type: 'wildlifeTapped', encounterId: hit.encounterId });
}
let drag = null;
canvas.addEventListener('pointerdown', event => {
  if (!visible || disposed || event.button !== 0) return;
  canvas.focus({preventScroll:true});
  drag = {id:event.pointerId,x:event.clientX,y:event.clientY,startX:event.clientX,startY:event.clientY,moved:false};
  canvas.setPointerCapture(event.pointerId);
});
canvas.addEventListener('pointermove', event => {
  if (!drag || drag.id !== event.pointerId) return;
  const rect = canvas.getBoundingClientRect();
  if (Math.hypot(event.clientX-drag.startX,event.clientY-drag.startY)>4) drag.moved=true;
  if(drag.moved) map.orbit((event.clientX-drag.x)/rect.width,(event.clientY-drag.y)/rect.height);
  drag.x=event.clientX;drag.y=event.clientY;
});
canvas.addEventListener('pointerup', event => {
  if(!drag || drag.id !== event.pointerId) return;
  const moved=drag.moved;drag=null;
  canvas.releasePointerCapture(event.pointerId);
  const rect=canvas.getBoundingClientRect();
  if(!moved) tapAtNormalized((event.clientX-rect.left)/rect.width,(event.clientY-rect.top)/rect.height);
});
canvas.addEventListener('lostpointercapture',()=>{drag=null;});
canvas.addEventListener('wheel',event=>{
  if(!visible || disposed || event.deltaX && !event.deltaY) return;
  const pixels = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? canvas.clientHeight : 1);
  map.zoomBy(-pixels / 120);
  event.preventDefault();
},{passive:false});
canvas.addEventListener('keydown',event=>{
  if(!visible || disposed) return;
  const keys={ArrowLeft:[-1,0],ArrowRight:[1,0],ArrowUp:[0,1],ArrowDown:[0,-1]};
  if(keys[event.key]) { map.move(...keys[event.key]);event.preventDefault(); }
  else if(event.key==='Home') { map.reset();event.preventDefault(); }
});
document.addEventListener('visibilitychange', () => {
  lastFrame = 0;
  if (document.hidden && request) { cancelAnimationFrame(request); request = 0; }
  else requestRender();
});
window.addEventListener('pagehide', () => {
  disposed = true;
  if (request) cancelAnimationFrame(request);
  clear(fruits); clear(treeDecorations); clear(trunkDecorations); clear(crown); clear(tree); clear(wildlife); clear(decorations);
  clear(weather); clear(leaves); clear(ground); clear(map.world);
  textures.forEach(map => map.dispose());
  textures.clear();
  renderer.dispose();
});
window.addEventListener('error', event => fail(event.error || event.message));
window.addEventListener('unhandledrejection', event => fail(event.reason));
// ExecuteScriptAsync can swallow a JavaScript exception as a null result.
// Report bridge failures explicitly so the native host can restore 2D.
const guarded = action => (...args) => {
  try { action(...args); } catch (error) { fail(error); }
};
window.DeskBoxTree3D = {
  setSceneState: guarded(setSceneState),
  setVisibility: guarded(setVisibility),
  setAnimationEnabled: guarded(setAnimationEnabled),
  resetScene: guarded(resetScene),
  enableFrameTransport: guarded(() => { frameTransport = true; requestRender(); }),
  acknowledgeFrame: () => { framePending = false; if (frameDirty) requestRender(); },
  tapAtNormalized: guarded(tapAtNormalized),
  orbitBy: guarded((dx,dy)=>{ if(visible) map.orbit(dx,dy); }),
  moveView: guarded((x,y)=>{ if(visible) map.move(x,y); }),
  zoomBy: guarded(steps=>{ if(visible) map.zoomBy(steps); }),
  resetView: guarded(()=>{ if(visible) map.reset(); })
};
const map = createTreeMap(scene, camera, requestRender);
new ResizeObserver(resize).observe(document.documentElement);
makeTree(1);
resize();
// Compile and draw before confirming readiness, even while the native host is hidden.
renderer.render(scene, camera);
report({ type: 'ready' });
