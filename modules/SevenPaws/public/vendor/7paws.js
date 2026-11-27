// Upstream generated renderer; DeskBox maintains the social poses and props here (generator sources are not distributed).
window.createCat = function (host, options = {}) {
const stageEl = host, hintEl = document.createElement('div');
// The look-development page had on-page controls; here they are detached stand-ins so the shared drawing code stays identical.
const stubs = {}, $ = id => stubs[id] || (stubs[id] = document.createElement(id.startsWith('btn') ? 'button' : 'div'));
const offs = [], on = (type, fn, opt) => { window.addEventListener(type, fn, opt); offs.push(() => window.removeEventListener(type, fn, opt)); };
let dead = false, raf = 0, followKeys = false, showKeyLabels = true, idleActions = options.idleActions !== false, lastStrikeAt = -1e9, afterRender = null;
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const clamp = (v, a, b) => Math.max(a, Math.min(b, v)), mix = (a, b, t) => a + (b - a) * t, rnd = (a, b) => a + Math.random() * (b - a);
const V = (x, y, z) => new THREE.Vector3(x, y, z);

let renderer;
try { renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: !!options.win7Capture }); }
catch (e) { if (options.onError) options.onError(String(e)); return null; }
renderer.setPixelRatio(options.pixelRatio > 0 ? options.pixelRatio : Math.min(1.5, devicePixelRatio || 1));   // [7paws] cap DPR: 2x framebuffers cost far more memory than they show
stageEl.appendChild(renderer.domElement);
renderer.domElement.addEventListener('webglcontextlost', event => { event.preventDefault(); if (options.onError) options.onError('WebGL context lost'); });
const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(29, 1, 0.1, 100);
const CAM = V(0, 5.0, 10.2), LOOK = V(0, 1.35, 0.45);
const VIEW = new URLSearchParams(location.search);
const flat = color => new THREE.MeshBasicMaterial({ color });
const back = color => new THREE.MeshBasicMaterial({ color, side: THREE.BackSide });

/* ---------- materials that a coat preset recolours ---------- */
const onFace = (color, k) => new THREE.MeshBasicMaterial({ color, polygonOffset: true, polygonOffsetFactor: -4 * k, polygonOffsetUnits: -4 * k });
const M = { lid: onFace('#101010', 3), blush: flat('#f08f9c'), shut: onFace('#8b8f86', 1), fur: flat('#101010'), patch: flat('#101010'), line: back('#8b8f86'), eye: onFace('#b9d36b', 1), eye2: onFace('#b9d36b', 1), pupil: onFace('#0b0b0b', 2), whisker: flat('#f4f4ee'), whiskerOut: flat('#101010'), stripe: onFace('#101010', 1), inner: flat('#101010') };   // [7paws] eye2 = second iris for heterochromia
M.leg = new THREE.MeshBasicMaterial({ color: '#ffffff', vertexColors: true });
const LEG_COAT = { base: new THREE.Color('#101010'), tip: new THREE.Color('#101010'), tmp: new THREE.Color(), fade: [0.38, 0.62] };

/* ---------- desk, keyboard, mouse ---------- */
const deskMat = flat('#e9ebb6'), deskEdge = flat('#1c1d1a');
const desk = new THREE.Mesh(new THREE.BoxGeometry(40, 0.4, 7), deskMat); desk.position.set(0, -0.2, 1.2); scene.add(desk); desk.visible = false;
const horizon = new THREE.Mesh(new THREE.BoxGeometry(40, 0.05, 0.05), deskEdge); horizon.position.set(0, 0.0, -2.3); scene.add(horizon); horizon.visible = false;

const ROWS = [
  [['Backquote',1,'`'],['Digit1',1,'1'],['Digit2',1,'2'],['Digit3',1,'3'],['Digit4',1,'4'],['Digit5',1,'5'],['Digit6',1,'6'],['Digit7',1,'7'],['Digit8',1,'8'],['Digit9',1,'9'],['Digit0',1,'0'],['Minus',1,'-'],['Equal',1,'='],['Backspace',2,'⌫']],
  [['Tab',1.5,'⇥'],['KeyQ',1,'Q'],['KeyW',1,'W'],['KeyE',1,'E'],['KeyR',1,'R'],['KeyT',1,'T'],['KeyY',1,'Y'],['KeyU',1,'U'],['KeyI',1,'I'],['KeyO',1,'O'],['KeyP',1,'P'],['BracketLeft',1,'['],['BracketRight',1,']'],['Backslash',1.5,'\\']],
  [['CapsLock',1.75,'⇪'],['KeyA',1,'A'],['KeyS',1,'S'],['KeyD',1,'D'],['KeyF',1,'F'],['KeyG',1,'G'],['KeyH',1,'H'],['KeyJ',1,'J'],['KeyK',1,'K'],['KeyL',1,'L'],['Semicolon',1,';'],['Quote',1,"'"],['Enter',2.25,'⏎']],
  [['ShiftLeft',2.25,'⇧'],['KeyZ',1,'Z'],['KeyX',1,'X'],['KeyC',1,'C'],['KeyV',1,'V'],['KeyB',1,'B'],['KeyN',1,'N'],['KeyM',1,'M'],['Comma',1,','],['Period',1,'.'],['Slash',1,'/'],['ShiftRight',2.75,'⇧']],
  [['ControlLeft',1.25,'⌃'],['AltLeft',1.25,'⌥'],['MetaLeft',1.25,'⌘'],['Space',7.5,'space'],['MetaRight',1.25,'⌘'],['AltRight',1.25,'⌥'],['ControlRight',1.25,'⌃']]
];
const KB = { unit: 0.18, z0: 0.92, x0: 0 }, KEY_TOP = 0.2;
const DESK_DX = 0.2825;                                  // keyboard + mouse together are centred on the cat
const roundedSlab = (w, d, r, hgt) => { const sh = new THREE.Shape(), x = w / 2, y = d / 2; sh.moveTo(-x + r, -y); sh.lineTo(x - r, -y); sh.quadraticCurveTo(x, -y, x, -y + r); sh.lineTo(x, y - r); sh.quadraticCurveTo(x, y, x - r, y); sh.lineTo(-x + r, y); sh.quadraticCurveTo(-x, y, -x, y - r); sh.lineTo(-x, -y + r); sh.quadraticCurveTo(-x, -y, -x + r, -y);
  const g = new THREE.ExtrudeGeometry(sh, { depth: hgt, bevelEnabled: false, curveSegments: 6 }); g.rotateX(-Math.PI / 2); return g; };
const KEYCAP = { plain: new THREE.Color('#fbfbf3'), mod: new THREE.Color('#e3dfce'), accent: new THREE.Color('#f2b25c') };
const capColor = new THREE.Color('#fbfbf3'), hitColor = new THREE.Color('#b9d36b');

const baseColorFor = (code, w, accent) => {
  if (accent === 'accent') return KEYCAP.accent;
  if (accent === 'mod') return KEYCAP.mod;
  if (accent === 'plain') return KEYCAP.plain;
  return (code === 'Enter' || code === 'Backquote') ? KEYCAP.accent : ((w > 1 && code !== 'Space') || /^(Alt|Meta|Control)/.test(code)) ? KEYCAP.mod : KEYCAP.plain;
};
const specsFromRows = rows => {
  const out = [];
  rows.forEach((row, r) => { let x = 0; row.forEach(([code, w, label]) => {
    out.push({ code, label, uStart: x, rowY: r, rowSpan: 1, uWidth: w });
    x += w;
  }); });
  return out;
};
const K = (code, label, uStart, rowY, uWidth = 1, extra = {}) => ({ code, label, uStart, rowY, uWidth, ...extra });
const NAV_X = 15.35, NUM_X = 18.7;
const KEYS_104 = [
  K('Escape', 'Esc', 0, 0, 1, { accent: 'accent' }),
  K('F1', 'F1', 2, 0), K('F2', 'F2', 3, 0), K('F3', 'F3', 4, 0), K('F4', 'F4', 5, 0),
  K('F5', 'F5', 6.5, 0), K('F6', 'F6', 7.5, 0), K('F7', 'F7', 8.5, 0), K('F8', 'F8', 9.5, 0),
  K('F9', 'F9', 11, 0), K('F10', 'F10', 12, 0), K('F11', 'F11', 13, 0), K('F12', 'F12', 14, 0),
  K('PrintScreen', 'Prt', NAV_X, 0, 1, { accent: 'mod' }),
  K('ScrollLock', 'Scr', NAV_X + 1, 0, 1, { accent: 'mod' }),
  K('Pause', 'Pau', NAV_X + 2, 0, 1, { accent: 'mod' }),

  K('Backquote', '`', 0, 1.25, 1, { accent: 'plain' }),
  K('Digit1', '1', 1, 1.25), K('Digit2', '2', 2, 1.25), K('Digit3', '3', 3, 1.25),
  K('Digit4', '4', 4, 1.25), K('Digit5', '5', 5, 1.25), K('Digit6', '6', 6, 1.25),
  K('Digit7', '7', 7, 1.25), K('Digit8', '8', 8, 1.25), K('Digit9', '9', 9, 1.25),
  K('Digit0', '0', 10, 1.25), K('Minus', '-', 11, 1.25), K('Equal', '=', 12, 1.25),
  K('Backspace', '⌫', 13, 1.25, 2),
  K('Insert', 'Ins', NAV_X, 1.25, 1, { accent: 'mod' }),
  K('Home', 'Hm', NAV_X + 1, 1.25, 1, { accent: 'mod' }),
  K('PageUp', 'PU', NAV_X + 2, 1.25, 1, { accent: 'mod' }),
  K('NumLock', 'Num', NUM_X, 1.25, 1, { accent: 'mod' }),
  K('NumpadDivide', '/', NUM_X + 1, 1.25, 1, { accent: 'mod' }),
  K('NumpadMultiply', '*', NUM_X + 2, 1.25, 1, { accent: 'mod' }),
  K('NumpadSubtract', '-', NUM_X + 3, 1.25, 1, { accent: 'mod' }),

  K('Tab', '⇥', 0, 2.25, 1.5),
  K('KeyQ', 'Q', 1.5, 2.25), K('KeyW', 'W', 2.5, 2.25), K('KeyE', 'E', 3.5, 2.25),
  K('KeyR', 'R', 4.5, 2.25), K('KeyT', 'T', 5.5, 2.25), K('KeyY', 'Y', 6.5, 2.25),
  K('KeyU', 'U', 7.5, 2.25), K('KeyI', 'I', 8.5, 2.25), K('KeyO', 'O', 9.5, 2.25),
  K('KeyP', 'P', 10.5, 2.25), K('BracketLeft', '[', 11.5, 2.25), K('BracketRight', ']', 12.5, 2.25),
  K('Backslash', '\\', 13.5, 2.25, 1.5),
  K('Delete', 'Del', NAV_X, 2.25, 1, { accent: 'mod' }),
  K('End', 'End', NAV_X + 1, 2.25, 1, { accent: 'mod' }),
  K('PageDown', 'PD', NAV_X + 2, 2.25, 1, { accent: 'mod' }),
  K('Numpad7', '7', NUM_X, 2.25), K('Numpad8', '8', NUM_X + 1, 2.25), K('Numpad9', '9', NUM_X + 2, 2.25),
  K('NumpadAdd', '+', NUM_X + 3, 2.25, 1, { accent: 'mod', rowSpan: 2 }),

  K('CapsLock', '⇪', 0, 3.25, 1.75),
  K('KeyA', 'A', 1.75, 3.25), K('KeyS', 'S', 2.75, 3.25), K('KeyD', 'D', 3.75, 3.25),
  K('KeyF', 'F', 4.75, 3.25), K('KeyG', 'G', 5.75, 3.25), K('KeyH', 'H', 6.75, 3.25),
  K('KeyJ', 'J', 7.75, 3.25), K('KeyK', 'K', 8.75, 3.25), K('KeyL', 'L', 9.75, 3.25),
  K('Semicolon', ';', 10.75, 3.25), K('Quote', "'", 11.75, 3.25),
  K('Enter', '⏎', 12.75, 3.25, 2.25, { accent: 'accent' }),
  K('Numpad4', '4', NUM_X, 3.25), K('Numpad5', '5', NUM_X + 1, 3.25), K('Numpad6', '6', NUM_X + 2, 3.25),

  K('ShiftLeft', '⇧', 0, 4.25, 2.25),
  K('KeyZ', 'Z', 2.25, 4.25), K('KeyX', 'X', 3.25, 4.25), K('KeyC', 'C', 4.25, 4.25),
  K('KeyV', 'V', 5.25, 4.25), K('KeyB', 'B', 6.25, 4.25), K('KeyN', 'N', 7.25, 4.25),
  K('KeyM', 'M', 8.25, 4.25), K('Comma', ',', 9.25, 4.25), K('Period', '.', 10.25, 4.25),
  K('Slash', '/', 11.25, 4.25), K('ShiftRight', '⇧', 12.25, 4.25, 2.75),
  K('ArrowUp', '↑', NAV_X + 1, 4.25, 1, { accent: 'mod' }),
  K('Numpad1', '1', NUM_X, 4.25), K('Numpad2', '2', NUM_X + 1, 4.25), K('Numpad3', '3', NUM_X + 2, 4.25),
  K('NumpadEnter', '↵', NUM_X + 3, 4.25, 1, { accent: 'accent', rowSpan: 2 }),

  K('ControlLeft', 'Ctrl', 0, 5.25, 1.25),
  K('MetaLeft', 'Win', 1.25, 5.25, 1.25),
  K('AltLeft', 'Alt', 2.5, 5.25, 1.25),
  K('Space', '␣', 3.75, 5.25, 6.25, { accent: 'plain' }),
  K('AltRight', 'Alt', 10, 5.25, 1.25),
  K('MetaRight', 'Win', 11.25, 5.25, 1.25),
  K('ContextMenu', '▤', 12.5, 5.25, 1.25),
  K('ControlRight', 'Ctrl', 13.75, 5.25, 1.25),
  K('ArrowLeft', '←', NAV_X, 5.25, 1, { accent: 'mod' }),
  K('ArrowDown', '↓', NAV_X + 1, 5.25, 1, { accent: 'mod' }),
  K('ArrowRight', '→', NAV_X + 2, 5.25, 1, { accent: 'mod' }),
  K('Numpad0', '0', NUM_X, 5.25, 2),
  K('NumpadDecimal', '.', NUM_X + 2, 5.25, 1),
];

const LAYOUTS = {};
function buildLayout(id, totalUnits, totalRows, specs) {
  const group = new THREE.Group(); scene.add(group);
  const baseCenterX = DESK_DX + (7.5 - totalUnits / 2) * KB.unit;
  const baseZ = KB.z0 + totalRows / 2 * KB.unit;
  const base = new THREE.Mesh(roundedSlab(totalUnits * KB.unit + 0.2, totalRows * KB.unit + 0.2, 0.11, 0.1), flat('#22231f'));
  base.position.set(baseCenterX, 0, baseZ); group.add(base);
  const keyMap = new Map();
  for (const spec of specs) {
    const span = spec.rowSpan || 1;
    const wx = DESK_DX + (7.5 - (spec.uStart + spec.uWidth / 2)) * KB.unit;
    const wz = KB.z0 + (totalRows - (spec.rowY + span / 2)) * KB.unit;
    const baseColor = baseColorFor(spec.code, spec.uWidth, spec.accent);
    const mesh = new THREE.Mesh(roundedSlab(spec.uWidth * KB.unit - 0.03, span * KB.unit - 0.03, 0.035, 0.09), flat('#fbfbf3'));
    mesh.geometry.translate(0, -0.045, 0);
    mesh.position.set(wx, 0.145, wz); group.add(mesh);
    keyMap.set(spec.code, { code: spec.code, label: spec.label, mesh, base: baseColor, ox: wx - baseCenterX, x: wx, z: wz, down: false, glow: 0 });
  }
  const mouseX = baseCenterX - totalUnits / 2 * KB.unit - 0.445;
  const layout = { id, totalUnits, totalRows, group, base, baseCenterX, keys: keyMap, mouseX, mouseZ: 1.08, mouseAt: V(mouseX, 0, 1.08) };
  LAYOUTS[id] = layout;
  return layout;
}
buildLayout('65', 15, 5, specsFromRows(ROWS));
buildLayout('104', 22.7, 6.25, KEYS_104);
let activeLayout = LAYOUTS['65'];
let board = activeLayout.group;
let keys = activeLayout.keys;
let MOUSE_AT = activeLayout.mouseAt.clone();
let showKeyboard = true;
LAYOUTS['104'].group.visible = false;
const mouseGroup = new THREE.Group(); mouseGroup.position.copy(MOUSE_AT); scene.add(mouseGroup);
// Which paw works the mouse, and which way the desk set is laid out. The picture mirrors its owner: a mouse in the owner's right
// hand sits on the left of the picture. For a left-handed mouse the keyboard and mouse swap sides, still centred on the cat together.
let MOUSE_PAW = 'L', DESK_DIR = 1;
function setHanded(leftMouse) {
  DESK_DIR = leftMouse ? -1 : 1; MOUSE_PAW = leftMouse ? 'R' : 'L';
  for (const layout of Object.values(LAYOUTS)) {
    layout.base.position.x = layout.baseCenterX * DESK_DIR;
    layout.keys.forEach(k => { k.x = layout.baseCenterX * DESK_DIR + k.ox; k.mesh.position.x = k.x; });
    layout.mouseAt.x = layout.mouseX * DESK_DIR;
  }
  MOUSE_AT.copy(activeLayout.mouseAt);
}
function applyLayout(id) {
  const next = id === '104' ? LAYOUTS['104'] : LAYOUTS['65'];
  if (next === activeLayout) { next.group.visible = showKeyboard; return; }
  [...S.down.keys()].forEach(keyUp);
  shownKeys = new Set(); downAt.clear();
  for (const layout of Object.values(LAYOUTS)) {
    layout.group.visible = layout === next && showKeyboard;
    layout.keys.forEach(k => { k.down = false; k.glow = 0; k.mesh.position.y = 0.145; k.mesh.material.color.copy(k.base); });
  }
  activeLayout = next;
  board = next.group;
  keys = next.keys;
  MOUSE_AT.copy(next.mouseAt);
  mouseGroup.position.set(MOUSE_AT.x, 0, MOUSE_AT.z);
  S.wigX = 0; S.wigZ = 0; S.lastMouse = -1e9;
}
// Touch-typing hands: these keys belong to the left hand, everything else to the right. The keyboard faces the cat, so the
// owner's left hand is the paw on the right of the picture.
const LEFT_HAND = new Set(['Escape', 'F1', 'F2', 'F3', 'F4', 'F5', 'Backquote', 'Digit1', 'Digit2', 'Digit3', 'Digit4', 'Digit5', 'Tab', 'KeyQ', 'KeyW', 'KeyE', 'KeyR', 'KeyT', 'CapsLock', 'KeyA', 'KeyS', 'KeyD', 'KeyF', 'KeyG', 'ShiftLeft', 'KeyZ', 'KeyX', 'KeyC', 'KeyV', 'KeyB', 'ControlLeft', 'AltLeft', 'MetaLeft']);
const pawFor = code => LEFT_HAND.has(code) ? 'R' : 'L';
const mouseBody = new THREE.Mesh(new THREE.SphereGeometry(0.3, 24, 16), flat('#fbfbf3')); mouseBody.scale.set(0.7, 0.42, 1.2); mouseBody.position.y = 0.1; mouseGroup.add(mouseBody);
const mouseRim = new THREE.Mesh(mouseBody.geometry, back('#1c1d1a')); mouseRim.scale.set(0.77, 0.47, 1.27); mouseRim.position.y = 0.1; mouseGroup.add(mouseRim);
const mouseBtn = new THREE.Mesh(new THREE.BoxGeometry(0.018, 0.02, 0.16), flat('#1c1d1a')); mouseBtn.position.set(0, 0.215, 0.24); mouseGroup.add(mouseBtn);
const mouseWheel = new THREE.Mesh(new THREE.BoxGeometry(0.04, 0.03, 0.09), flat('#f2b25c')); mouseWheel.position.set(0, 0.222, 0.16); mouseGroup.add(mouseWheel);
const BTN_INK = new THREE.Color('#1c1d1a');

/* ---------- the cat: one flowing body, an oval head, oval-ended legs, and a tail that grows out of the hip ---------- */
const cat = new THREE.Group(); cat.position.set(0, 0, 0.08); scene.add(cat);
const ball = (r, mat, sx = 1, sy = 1, sz = 1) => { const m = new THREE.Mesh(new THREE.SphereGeometry(r, 40, 28), mat); m.scale.set(sx, sy, sz); return m; };
const add = (parent, mesh, x, y, z) => { mesh.position.set(x, y, z); parent.add(mesh); return mesh; };
const spline = pts => { const c = new THREE.SplineCurve(pts.map(([a, b]) => new THREE.Vector2(a, b))); const table = c.getPoints(120); return y => { let k = 0; while (k < table.length - 2 && table[k + 1].x < y) k++; const a = table[k], b = table[k + 1], f = clamp((y - a.x) / Math.max(1e-6, b.x - a.x), 0, 1); return a.y + (b.y - a.y) * f; }; };
// The body is a single lofted surface: at each height an ellipse with its own width, depth and forward offset.
const BODY_H = 2.5;                                   // the neck continues up inside the head, so no gap can open behind it
const halfW = spline([[0, 0.86], [0.07, 0.9], [0.3, 1.0], [0.62, 1.03], [0.95, 0.93], [1.3, 0.75], [1.6, 0.58], [1.85, 0.47], [2.02, 0.41], [BODY_H, 0.37]]);
// Front and back outlines seen from the side: an upright chest and a full, rounded back down to the rump.
const frontZ = spline([[0, 0.4], [0.07, 0.43], [0.3, 0.5], [0.6, 0.54], [0.95, 0.52], [1.3, 0.48], [1.6, 0.46], [1.9, 0.42], [2.02, 0.4], [BODY_H, 0.36]]);
const backZ = spline([[0, -1.14], [0.07, -1.19], [0.3, -1.32], [0.6, -1.38], [0.95, -1.24], [1.3, -1.0], [1.6, -0.76], [1.9, -0.56], [2.02, -0.5], [BODY_H, -0.44]]);
const halfD = y => (frontZ(y) - backZ(y)) / 2, midZ = y => (frontZ(y) + backZ(y)) / 2;
const upper = new THREE.Group(); cat.add(upper);            // chest and head lean; the feet and tail stay put
const torso = new THREE.Group(); upper.add(torso);
let NECK_W = 1, SHOULDER_W = 1, builtNeck = -1;
const bodyMesh = new THREE.Mesh(new THREE.BufferGeometry(), new THREE.MeshBasicMaterial({ color: 0x101010, side: THREE.DoubleSide })); torso.add(bodyMesh); M.body = bodyMesh.material;
function buildBody() {
  if (builtNeck === NECK_W + SHOULDER_W * 100) return; builtNeck = NECK_W + SHOULDER_W * 100; bodyMesh.geometry.dispose();
  bodyMesh.geometry = new THREE.ParametricGeometry((u, v, out) => { const y = v * BODY_H, a = u * Math.PI * 2, close = Math.sqrt(1 - (1 - Math.min(1, y / 0.12)) ** 2), f = (1 + (NECK_W - 1) * smooth(1.15, 1.95, y)) * (1 + (SHOULDER_W - 1) * smooth(0.55, 1.4, y) * (1 - smooth(1.6, 2.25, y))) * (1 + 0.34 * smooth(1.72, 2.2, y)), g = 1 + (f / (1 + 0.34 * smooth(1.72, 2.2, y)) - 1) * 0.3; const top = 1 - 0.8 * smooth(2.17, 2.46, y);   /* the hidden top of the neck narrows to a point so it can never poke out of the head */ out.set(Math.cos(a) * halfW(y) * f * close * top, y, midZ(y) + 0.1 * (1 - top) + Math.sin(a) * halfD(y) * g * close * top); }, 56, 44);
}
const chest = add(torso, ball(1, M.patch, 0.3, 0.5, 0.16), 0, 1.22, 0.4);
const hindFeet = [-1, 1].map(s => { const g = new THREE.Group(); g.position.set(s * 0.6, 0.1, 0.25); cat.add(g); const rim = add(g, ball(1, M.line, 0.216, 0.118, 0.325), 0, -0.012, 0.022); const foot = add(g, ball(1, M.patch, 0.2, 0.11, 0.31), 0, 0, 0); foot.renderOrder = 1; rim.renderOrder = 0; return g; });

const HEAD_R = [0.86, 0.66, 0.7];
upper.matrixAutoUpdate = false;                              // the chest leans by shearing, so its base never leaves the desk
const headFix = new THREE.Group(); headFix.matrixAutoUpdate = false; upper.add(headFix);   // undoes the shear so the head keeps its shape
const head = new THREE.Group(); head.position.set(0, 2.3, 0.1); headFix.add(head);
const TEX = 256, mkTex = () => { const c = document.createElement('canvas'); c.width = c.height = TEX; return { g: c.getContext('2d'), t: new THREE.CanvasTexture(c) }; };
const bodyTex = mkTex(), headTex = mkTex(); M.body.map = bodyTex.t; M.head = new THREE.MeshBasicMaterial({ map: headTex.t }); M.tail = flat('#101010');
// u runs round the cat (0.25 = facing the viewer, 0.75 = the back), v runs bottom to top
const blob = (g, u, v, ru, rv, color, rot = 0) => { g.fillStyle = color; for (const du of [-1, 0, 1]) { g.beginPath(); g.ellipse((u + du) * TEX, (1 - v) * TEX, ru * TEX, rv * TEX, rot, 0, 7); g.fill(); } };
function paintCoat(c) {
  const b = bodyTex.g, h = headTex.g, pat = c.pattern || '';
  for (const g of [b, h]) { g.fillStyle = c.fur; g.fillRect(0, 0, TEX, TEX); }
  if (pat.includes('tabby')) {
    // a leaf-shaped mark from (u0,v0) to (u1,v1): fat in the middle, pointed at both ends
    const leaf = (g, u0, v0, u1, v1, th) => { g.fillStyle = c.stripe; for (const du of [-1, 0, 1]) { const x0 = (u0 + du) * TEX, y0 = (1 - v0) * TEX, x1 = (u1 + du) * TEX, y1 = (1 - v1) * TEX, mx = (x0 + x1) / 2, my = (y0 + y1) / 2, len = Math.hypot(x1 - x0, y1 - y0), nx = -(y1 - y0) / len * th * TEX, ny = (x1 - x0) / len * th * TEX;
      g.beginPath(); g.moveTo(x0, y0); g.quadraticCurveTo(mx + nx, my + ny, x1, y1); g.quadraticCurveTo(mx - nx * 0.9, my - ny * 0.9, x0, y0); g.fill(); } };
    const marks = [[0.2, 0.17, 0.05], [0.285, 0.1, 0.03], [0.37, 0.19, 0.06], [0.455, 0.12, 0.035], [0.54, 0.16, 0.05], [0.62, 0.09, 0.03]];   // height, length, how far it droops
    for (const [v, len, droop] of marks) { leaf(b, 0.86, v + 0.02, 0.86 + len + 0.12, v - droop, 0.02); leaf(b, 0.64, v + 0.02, 0.64 - len - 0.12, v - droop, 0.02); }
    leaf(b, 0.75, 0.12, 0.75, 0.7, 0.02);                             // spine
    leaf(h, 0.75, 0.35, 0.75, 0.85, 0.022); leaf(h, 0.68, 0.45, 0.62, 0.72, 0.016); leaf(h, 0.82, 0.45, 0.88, 0.72, 0.016);   // back of the head
  }
  if (pat.includes('calico')) {
    blob(b, 0.04, 0.42, 0.15, 0.2, c.patchA); blob(b, 0.56, 0.22, 0.13, 0.13, c.patchB); blob(b, 0.78, 0.5, 0.16, 0.22, c.patchB); blob(b, 0.5, 0.56, 0.1, 0.12, c.patchA);
    blob(h, 0.11, 0.7, 0.13, 0.24, c.patchA, 0.3); blob(h, 0.42, 0.72, 0.11, 0.22, c.patchB, -0.3); blob(h, 0.75, 0.5, 0.2, 0.3, c.patchB);
  }
  if (c.mask) {
    const rgb = [1, 3, 5].map(i => parseInt(c.mask.slice(i, i + 2), 16)).join(',');
    h.save(); h.translate(0.25 * TEX, 0.533 * TEX); h.scale(0.18 * TEX, 0.238 * TEX);
    const mask = h.createRadialGradient(0, 0, 0, 0, 0, 1);
    mask.addColorStop(0, `rgba(${rgb},1)`); mask.addColorStop(0.65, `rgba(${rgb},1)`); mask.addColorStop(1, `rgba(${rgb},0)`);
    h.fillStyle = mask; h.fillRect(-1, -1, 2, 2); h.restore();
  }
  // [7paws] AI/photo-authored markings: ellipses in part uv space (u wraps around the body).
  if (c.spots && c.spots.length) for (const sp of c.spots) {
    const g2 = sp.part === 'head' ? h : b, rot = (sp.rotation || 0) * Math.PI / 180;
    g2.save(); g2.globalAlpha = clamp(sp.opacity == null ? 1 : sp.opacity, 0, 1); g2.fillStyle = sp.color;
    for (const du of [-1, 0, 1]) { g2.beginPath(); g2.ellipse((clamp(sp.x || 0, 0, 1) + du) * TEX, (1 - clamp(sp.y || 0, 0, 1)) * TEX, clamp(sp.rx || 0.02, 0.005, 0.6) * TEX, clamp(sp.ry || 0.02, 0.005, 0.6) * TEX, rot, 0, 7); g2.fill(); }
    g2.restore();
  }
    bodyTex.t.needsUpdate = headTex.t.needsUpdate = true; M.tail.color.set(c.tail || c.fur);
}
add(head, ball(1, M.head, HEAD_R[0], HEAD_R[1], HEAD_R[2]), 0, 0, 0);
const tongue = add(head, ball(1, new THREE.MeshBasicMaterial({ color: '#f08a98' }), 0.06, 0.085, 0.04), 0.02, -0.36, 0.6); tongue.visible = false;
const muzzle = add(head, ball(1, M.patch, 0.13, 0.08, 0.1), 0, -0.4, 0.5);
for (const k of ['eye', 'eye2', 'pupil', 'stripe', 'inner']) M[k].side = THREE.DoubleSide;

// Ears: a solid rounded form whose base sits inside the head, so the outline runs from head to ear without a step.
const earR = spline([[0, 0.3], [0.12, 0.285], [0.32, 0.22], [0.48, 0.13], [0.56, 0.062], [0.588, 0]]), EAR_H = 0.588, EAR_T = 0.5;
const earGeo = new THREE.LatheGeometry(Array.from({ length: 29 }, (_, i) => new THREE.Vector2(Math.max(0, earR(i / 28 * EAR_H)), i / 28 * EAR_H)), 32);
// The inner marking is a strip of the ear's own front surface, lifted a hair so it never floats.
const earInnerGeo = new THREE.ParametricGeometry((u, v, out) => { const y = mix(0.1, 0.47, v), r = earR(y), a = (u - 0.5) * 2 * 0.72 * Math.sqrt(Math.max(0, 1 - v ** 5)) * (0.72 + 0.28 * Math.min(1, v * 6)); out.set(r * Math.sin(a), y, r * Math.cos(a) * EAR_T + 0.007); }, 20, 28);
const ears = [-1, 1].map(s => { const g = new THREE.Group(); g.position.set(s * 0.46, 0.3, -0.04); g.rotation.z = -s * 0.24; head.add(g);
  M.ear = M.ear || new THREE.MeshBasicMaterial({ color: 0x101010, side: THREE.DoubleSide }); const ear = new THREE.Mesh(earGeo, M.ear); ear.scale.z = EAR_T; g.add(ear);
  const inner = new THREE.Mesh(earInnerGeo, M.inner); inner.renderOrder = 3; g.add(inner); return g; });

// A patch is a small ellipse wrapped onto the head's surface, so eyes and markings sit on the face from every angle.
const PR = 6, PS = 32;
function makePatch(mat, order) {
  const geo = new THREE.BufferGeometry(), index = [];
  geo.setAttribute('position', new THREE.BufferAttribute(new Float32Array((1 + PR * PS) * 3), 3));
  for (let k = 0; k < PS; k++) index.push(0, 1 + k, 1 + (k + 1) % PS);
  for (let r = 0; r < PR - 1; r++) for (let k = 0; k < PS; k++) { const a = 1 + r * PS + k, b = 1 + r * PS + (k + 1) % PS, c = a + PS, d = b + PS; index.push(a, c, b, b, c, d); }
  geo.setIndex(index); const mesh = new THREE.Mesh(geo, mat); mesh.renderOrder = order; mesh.frustumCulled = false; head.add(mesh); return mesh;
}
const onHead = (x, y, z, lift, out, n) => { const k = 1 / Math.sqrt((x / HEAD_R[0]) ** 2 + (y / HEAD_R[1]) ** 2 + (z / HEAD_R[2]) ** 2); x *= k; y *= k; z *= k;
  const nx = x / HEAD_R[0] ** 2, ny = y / HEAD_R[1] ** 2, nz = z / HEAD_R[2] ** 2, nl = Math.hypot(nx, ny, nz); out.setXYZ(n, x + nx / nl * lift, y + ny / nl * lift, z + nz / nl * lift); };
function fillPatch(mesh, cx, cy, rx, ry, turn, lift) {
  cx = clamp(cx, -0.78, 0.78); cy = clamp(cy, -0.58, 0.58);
  const pos = mesh.geometry.attributes.position, inside = Math.max(0.03, 1 - (cx / HEAD_R[0]) ** 2 - (cy / HEAD_R[1]) ** 2), cz = HEAD_R[2] * Math.sqrt(inside);
  // tangent directions at the centre: along x and along y on the ellipsoid
  let ax = 1, az = -(cx / HEAD_R[0] ** 2) / (cz / HEAD_R[2] ** 2), al = Math.hypot(ax, az); ax /= al; az /= al;
  let by = 1, bz = -(cy / HEAD_R[1] ** 2) / (cz / HEAD_R[2] ** 2), bl = Math.hypot(by, bz); by /= bl; bz /= bl;
  const co = Math.cos(turn), si = Math.sin(turn);
  onHead(cx, cy, cz, lift, pos, 0);
  for (let r = 1; r <= PR; r++) for (let k = 0; k < PS; k++) { const a = k / PS * Math.PI * 2, u = Math.cos(a) * rx * r / PR, v = Math.sin(a) * ry * r / PR, lx = u * co - v * si, ly = u * si + v * co;
    onHead(cx + ax * lx, cy + by * ly, cz + az * lx + bz * ly, lift, pos, 1 + (r - 1) * PS + k); }
  pos.needsUpdate = true;
}
M.mouth = onFace('#d8667c', 2); const mouth = makePatch(M.mouth, 4); mouth.visible = false;
const eyes = [-1, 1].map(s => ({ s, iris: makePatch(s > 0 ? M.eye2 : M.eye, 3), pupil: makePatch(M.pupil, 4), lid: makePatch(M.lid, 5), blush: makePatch(M.blush, 2) }));   // [7paws] s > 0 is the viewer-right eye, using the optional eye2 colour
const stripes = [-0.2, 0, 0.2].map(x => ({ x, mesh: makePatch(M.stripe, 3) }));

// Whiskers: three separate hairs per side, each rooted at its own spot on the cheek; light over the face, coat-dark beyond it.
const whiskers = [];
for (const s of [-1, 1]) for (let i = 0; i < 3; i++) {
  const pivot = new THREE.Group(); head.add(pivot);
  const pts = new THREE.QuadraticBezierCurve3(V(0, 0, 0), V(s * 0.24, 0.005, 0), V(s * 0.44, -0.075, 0)).getPoints(14);
  pivot.add(new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts.slice(0, 7)), 8, 0.008, 5), M.whisker));
  pivot.add(new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts.slice(6)), 10, 0.008, 5), M.whiskerOut));
  whiskers.push({ s, i, pivot });
}

/* A limb or tail is a "sausage": a tube whose thickness varies along its length and whose end is rounded. */
const SEG = 48, SIDES = 14, CAP = 5, RINGS = SEG + 1 + CAP;
function makeSausage(mat, order) {
  const geo = new THREE.BufferGeometry(), index = [];
  geo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(RINGS * SIDES * 3), 3));
  geo.setAttribute('color', new THREE.BufferAttribute(new Float32Array(RINGS * SIDES * 3), 3));
  for (let r = 0; r < RINGS - 1; r++) for (let k = 0; k < SIDES; k++) { const a = r * SIDES + k, b = r * SIDES + (k + 1) % SIDES, c = a + SIDES, d = b + SIDES; index.push(a, b, c, b, d, c); }
  geo.setIndex(index); const mesh = new THREE.Mesh(geo, mat); mesh.frustumCulled = false; mesh.renderOrder = order; return mesh;
}
const sP = V(0, 0, 0), sT = V(0, 0, 0), sN = V(0, 0, 0), sB = V(0, 0, 0);
function shapeSausage(mesh, path, radius, ref) {
  const pos = mesh.geometry.attributes.position; let n = 0, endR = 0;
  for (let r = 0; r < RINGS; r++) {
    let rad;
    if (r <= SEG) { const t = r / SEG; path.getPoint(t, sP); path.getTangent(t, sT); rad = radius(t); if (r === SEG) endR = rad; }
    else { const a = (r - SEG) / CAP * Math.PI / 2; path.getPoint(1, sP); path.getTangent(1, sT); sP.addScaledVector(sT, endR * Math.sin(a)); rad = endR * Math.cos(a); }
    sN.copy(ref).addScaledVector(sT, -ref.dot(sT)).normalize(); sB.crossVectors(sT, sN);
    for (let k = 0; k < SIDES; k++) { const a = k / SIDES * Math.PI * 2, c = Math.cos(a) * rad, d = Math.sin(a) * rad; pos.setXYZ(n, sP.x + sN.x * c + sB.x * d, sP.y + sN.y * c + sB.y * d, sP.z + sN.z * c + sB.z * d);
      if (mesh.material.vertexColors) { const t = Math.min(1, r / SEG), f = smooth(LEG_COAT.fade[0], LEG_COAT.fade[1], t); LEG_COAT.tmp.copy(LEG_COAT.base).lerp(LEG_COAT.tip, f); const col = mesh.geometry.attributes.color; col.setXYZ(n, LEG_COAT.tmp.r, LEG_COAT.tmp.g, LEG_COAT.tmp.b); col.needsUpdate = true; }
      n++; }
  }
  pos.needsUpdate = true;
}
const smooth = (a, b, v) => { const t = clamp((v - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); };
const AX = V(1, 0, 0), AZ = V(0, 0, 1);

// Tail: starts inside the hip so it grows out of the body, thick at the root and tapering to a round tip.
const tailCurve = new THREE.CatmullRomCurve3([V(0.3, 0.36, -0.75), V(0.82, 0.2, -0.64), V(1.16, 0.3, -0.5), V(1.3, 0.58, -0.42), V(1.46, 0.8, -0.37), V(1.66, 0.87, -0.35)], false, 'catmullrom', 0.5);
const tail = makeSausage(M.tail, 1); cat.add(tail);
let TAIL_K = 1;
const buildTail = k => { TAIL_K = k; shapeSausage(tail, tailCurve, t => 0.16 * TAIL_K * (1 + 0.7 * (1 - smooth(0, 0.16, t)) ** 2), AZ); };
buildTail(1);
// Asleep, the tail comes down and lies curled on the desk beside the body.
const TAIL_UP = tailCurve.points.map(q => q.clone()), TAIL_DOWN = [V(0.3, 0.36, -0.75), V(0.84, 0.15, -0.62), V(1.22, 0.12, -0.42), V(1.44, 0.12, -0.1), V(1.46, 0.12, 0.26), V(1.3, 0.12, 0.52)];
let tailDown = 0;
let tailWag = 0;
const poseTail = (k, wag = 0) => { if (Math.abs(k - tailDown) < 0.002 && Math.abs(wag - tailWag) < 0.002) return; tailDown = k; tailWag = wag; const e = k * k * (3 - 2 * k), n = tailCurve.points.length; tailCurve.points.forEach((q, i) => { q.lerpVectors(TAIL_UP[i], TAIL_DOWN[i], e); const tip = Math.max(0, (i - (n - 3)) / 2); q.x += wag * 0.22 * tip * tip; q.y += Math.abs(wag) * 0.05 * tip; }); buildTail(TAIL_K); };

/* ---------- forelimbs: oval-ended legs from the shoulder to wherever the paw is ---------- */
const SHOULDER = { L: V(-0.25, 1.5, 0.19), R: V(0.25, 1.5, 0.19) };       // world; L is screen left = the cat's right paw
let LEG_R = 0.16, FOOT = 1;
const REST = { L: V(-0.23, 0.11, 0.76), R: V(0.23, 0.11, 0.76) }, UP = { L: V(-0.46, 1.1, 0.9), R: V(0.46, 1.1, 0.9) };
const paws = {};
for (const side of ['L', 'R']) {
  const rim = makeSausage(M.line, 1), core = makeSausage(M.leg, 2); scene.add(rim, core);
  const root = ball(1, M.fur); scene.add(root);                       // closes the top of the leg inside the chest
  const foot = new THREE.Group(); scene.add(foot); const fr = add(foot, ball(1, M.line, 0.216, 0.118, 0.325), 0, -0.012, 0.022), ff = add(foot, ball(1, M.patch, 0.2, 0.11, 0.31), 0, 0, 0); fr.renderOrder = 2; ff.renderOrder = 3;
  paws[side] = { side, rim, core, foot, root, pos: REST[side].clone(), to: REST[side].clone(), from: V(0, 0, 0), tapAt: -1e9, landed: true, landedAt: -1e9, pending: null, held: 0, mode: 'rest' };
}
const tmpC = V(0, 0, 0), curve = new THREE.QuadraticBezierCurve3(V(0, 0, 0), V(0, 0, 0), V(0, 0, 0));
const legRadius = t => LEG_R * mix(1.6, 0.8, smooth(0, 0.8, t)), rimRadius = t => legRadius(t) + RIM_W * smooth(0.3, 0.6, t); let RIM_W = 0.03;
function drawLimb(p, shoulder) {
  // A leg hangs straight when the paw is on the desk below it, and bends at the elbow as the paw lifts or reaches.
  const s = p.side === 'L' ? -1 : 1, tip = tmpC.copy(p.pos); p.foot.position.copy(p.pos); tip.y += 0.05 * FOOT; tip.z -= 0.1 * FOOT;
  const dx = tip.x - shoulder.x, dz = tip.z - shoulder.z, drop = shoulder.y - tip.y, slack = clamp(1 - drop / 1.25, 0, 1), far = Math.hypot(dx, dz);
  curve.v0.copy(shoulder); curve.v2.copy(tip);
  curve.v1.set(shoulder.x + dx * 0.5 + s * (0.1 * slack + 0.04 * far), tip.y + drop * (0.5 - 0.35 * slack) + 0.16 * far, shoulder.z + dz * 0.5 + 0.08 + 0.45 * slack + 0.1 * far);
  // Standing on the desk, the leg bows forward just enough to sit in front of the chest so its outline shows; reaching out, the outline thins.
  const out = smooth(0.6, 1.0, far), lifted = smooth(0.25, 0.7, p.pos.y);   // a lifted paw keeps a full outline
  { const fr = p.foot.children[0]; fr.position.set(0, -0.012 - 0.024 * lifted, 0.022 * (1 - lifted)); fr.scale.set(0.216 - 0.01 * lifted, 0.118, 0.325 - 0.01 * lifted); } curve.v1.z = mix(Math.max(curve.v1.z, tip.z + 0.32), curve.v1.z, Math.max(out, lifted)); RIM_W = mix(0.03, 0.013, Math.max(out, lifted));
  shapeSausage(p.core, curve, legRadius, AX); shapeSausage(p.rim, curve, rimRadius, AX);
  p.root.position.copy(shoulder); p.root.scale.setScalar(legRadius(0) * 1.02);
}

/* ---------- coats and backgrounds ---------- */
const COATS = [
  { id: 'tuxedo', name: '奶牛猫', fur: '#151515', patch: '#f8f6ef', line: '#8b8f86', eye: '#e2b93b', whisker: '#f4f4ee', stripe: '#151515', inner: '#151515' },
  { id: 'orange', name: '橘猫', pattern: 'tabby', fur: '#e9973f', patch: '#fbe8c9', line: '#7a4312', eye: '#5c8a3a', whisker: '#fffaf0', stripe: '#c26a1f', inner: '#e9973f' },
  { id: 'tabby', name: '狸花猫', pattern: 'tabby', fur: '#a08a6c', patch: '#a08a6c', line: '#3f3324', eye: '#8fae4a', whisker: '#f4f0e4', stripe: '#4d3d2b', inner: '#d9a79a' },
  { id: 'calico', name: '三花猫', pattern: 'calico', fur: '#f8f6ef', patch: '#f8f6ef', patchA: '#e9973f', patchB: '#1d1c1a', tail: '#1d1c1a', line: '#8a8676', eye: '#c79a2e', whisker: '#b5b1a2', whiskerOut: '#b5b1a2', stripe: '#f8f6ef', inner: '#f2b9b0' },
  { id: 'siamese', name: '暹罗猫', pattern: 'point', fur: '#e2d6c0', patch: '#2e2521', line: '#4a3c30', eye: '#8fbde8', whisker: '#efe8dc', whiskerOut: '#8a7b66', stripe: '#e2d6c0', inner: '#3a2f28', lid: '#2e2521', shut: '#16110f', leg: '#2e2521', legFade: [0.38, 0.62], mask: '#2e2521', tail: '#2e2521', ear: '#2e2521' },
  { id: 'black', name: '黑猫', fur: '#101010', patch: '#101010', line: '#8b8f86', eye: '#eab03c', whisker: '#a4a79e', whiskerOut: '#101010', stripe: '#101010', inner: '#101010' },
  { id: 'white', name: '白猫', fur: '#f8f6ef', patch: '#f8f6ef', line: '#8a8676', eye: '#5aa9c9', whisker: '#b5b1a2', whiskerOut: '#b5b1a2', stripe: '#f8f6ef', inner: '#f2b9b0' },
  { id: 'blue', name: '蓝灰猫', fur: '#8090a0', patch: '#8090a0', line: '#3c4650', eye: '#e0a63a', whisker: '#f4f4ee', stripe: '#667686', inner: '#c59aa0' },
  { id: 'gray-white', name: '灰白猫', fur: '#77808b', patch: '#f8f6ef', line: '#3c4650', eye: '#e0a63a', whisker: '#f4f4ee', whiskerOut: '#3c4650', stripe: '#77808b', inner: '#c59aa0' },
  { id: 'orange-white', name: '橘白猫', fur: '#e9973f', patch: '#fbf3e4', line: '#7a4312', eye: '#5c9a3a', whisker: '#fffaf0', whiskerOut: '#7a4312', stripe: '#e9973f', inner: '#efb0a0' },
  { id: 'tortie', name: '玳瑁猫', pattern: 'calico', fur: '#24201d', patch: '#24201d', patchA: '#c8692c', patchB: '#8c4d22', tail: '#24201d', line: '#8b8f86', eye: '#e2a83a', whisker: '#e8e4da', whiskerOut: '#24201d', stripe: '#24201d', inner: '#b98a80' },
  { id: 'cream', name: '奶油猫', pattern: 'tabby', fur: '#f2dcb3', patch: '#faf0dc', line: '#9a7b52', eye: '#c4722a', whisker: '#b5a688', whiskerOut: '#b5a688', stripe: '#e2bf8a', inner: '#eeaaa0' },
  { id: 'silver', name: '银渐层', fur: '#d6dadd', patch: '#f1f2f2', line: '#6e767c', eye: '#4aa868', whisker: '#9ea6ac', whiskerOut: '#9ea6ac', stripe: '#d6dadd', inner: '#e6b6b6' }
];
const WALLS = [['浅色桌面', 'transparent', '#e9ebb6'], ['深色桌面', '#1f2226', '#454c57'], ['蓝色桌面', '#2f5d8a', '#454c57'], ['照片桌面', 'linear-gradient(135deg,#3b2f4a,#b8693f 55%,#e9c46a)', '#454c57']];
const coatLuma = hex => { const n = parseInt(hex.slice(1), 16); return (0.2126 * (n >> 16 & 255) + 0.7152 * (n >> 8 & 255) + 0.0722 * (n & 255)) / 255; };   // [7paws] brightness judged from the colour itself so photo-derived coats work too
function applyCoat(c) { const furLum = coatLuma(c.fur); for (const k of ['fur', 'patch', 'line', 'eye', 'whisker', 'stripe', 'inner']) M[k].color.set(c[k]); M.eye2.color.set(c.eye2 || c.eye); M.body.color.set('#ffffff'); M.ear.color.set(c.ear || c.fur); LEG_COAT.base.set(c.fur); LEG_COAT.tip.set(c.leg || c.fur); LEG_COAT.fade = c.legFade || [0.38, 0.62]; paintCoat(c); M.lid.color.set(c.lid || c.fur); whiskers.forEach(w => w.pivot.scale.setScalar(0.78)); { const pale = furLum > 0.78, o = pale ? '#b5b1a2' : '#f1eee2', w = pale ? 0.7 : 1; renderer.domElement.style.filter = [[w, 0], [-w, 0], [0, w], [0, -w]].map(([x, y]) => `drop-shadow(${x}px ${y}px 0 ${o})`).join(' '); } M.shut.color.set(c.shut || (furLum < 0.16 ? c.eye : c.line)); M.whiskerOut.color.set(c.whiskerOut || c.line); muzzle.visible = false; chest.visible = false; hitColor.set(c.eye); }
function setCoat(i) { applyCoat(COATS[i]); [...stageEl.querySelectorAll('#coats button')].forEach((b, n) => b.setAttribute('aria-pressed', n === i)); }
function setWall(i) { stageEl.style.background = WALLS[i][1]; deskMat.color.set(WALLS[i][2]); [...stageEl.querySelectorAll('#walls button')].forEach((b, n) => b.setAttribute('aria-pressed', n === i)); }
COATS.forEach((c, i) => { const b = document.createElement('button'); b.type = 'button'; b.innerHTML = `<span class="sw" style="background:${c.fur}"></span>${c.name}`; b.onclick = () => setCoat(i); $('coats').appendChild(b); });
WALLS.forEach((w, i) => { const b = document.createElement('button'); b.type = 'button'; b.innerHTML = `<span class="sw" style="background:${w[1]}"></span>${w[0]}`; b.onclick = () => setWall(i); $('walls').appendChild(b); });

/* ---------- body shape: a handful of proportions, the same ones a custom pet would set ---------- */
const SHAPE = [
  ['headSize', '头的大小', 0.75, 1.5, 0.86], ['headW', '头的宽度', 0.8, 1.35, 0.94], ['headH', '头的高度', 0.75, 1.25, 1.01], ['neck', '头的高低', -0.3, 0.3, 0.02], ['neckW', '脖子宽度', 0.8, 2.2, 1.31],
  ['earSize', '耳朵大小', 0.5, 1.6, 1.05], ['earSpread', '耳朵间距', 0.6, 1.4, 0.95], ['eyeSize', '眼睛大小', 0.6, 1.8, 1.2], ['eyeSpread', '眼睛间距', 0.6, 1.5, 0.9], ['eyeY', '眼睛高低', -0.25, 0.4, 0.31], ['eyeTilt', '眼睛角度', -0.7, 0.7, -0.08],
  ['bodyW', '身体宽度', 0.7, 1.5, 0.86], ['bodyH', '身体高度', 0.8, 1.3, 0.98],
  ['legThick', '腿的粗细', 0.5, 1.7, 0.97], ['legGap', '两腿间距', 0.5, 1.8, 1], ['footSize', '脚的大小', 0.6, 1.7, 0.89], ['tailThick', '尾巴粗细', 0.4, 1.7, 0.73], ['shoulderW', '脖子下方宽度', 0.8, 1.6, 1.15], ['whiskY', '胡子高低', -0.15, 0.25, 0.08]
];
const P = {}; SHAPE.forEach(([k, , , , d]) => P[k] = d);
function applyShape() {
  head.scale.set(P.headSize * P.headW, P.headSize * P.headH, P.headSize);
  ears.forEach((g, i) => { const s = i ? 1 : -1; g.position.x = s * 0.46 * P.earSpread; g.scale.setScalar(P.earSize); });
  NECK_W = P.neckW; SHOULDER_W = P.shoulderW; buildBody();
  stripes.forEach(st => fillPatch(st.mesh, st.x * 0.8, clamp(P.eyeY + 0.3, 0.34, 0.5) - Math.abs(st.x) * 0.25, 0.04, 0.09, 0, 0.006));   // forehead marks stay above the eyes
  whiskers.forEach(w => { const x = w.s * 0.4, y = P.eyeY * 0.35 - 0.2 + P.whiskY - w.i * 0.085, z = HEAD_R[2] * Math.sqrt(Math.max(0.03, 1 - (x / HEAD_R[0]) ** 2 - (y / HEAD_R[1]) ** 2));
    w.pivot.position.set(x, y, z + 0.008); w.pivot.rotation.set(0, -w.s * 0.52, w.s * (0.2 - w.i * 0.2)); });
  hindFeet.forEach((g, i) => { g.position.x = (i ? 1 : -1) * 0.6 * P.bodyW; g.scale.setScalar(P.footSize); });
  LEG_R = 0.16 * P.legThick; FOOT = P.footSize; for (const side of ['L', 'R']) paws[side].foot.scale.setScalar(P.footSize);
  for (const side of ['L', 'R']) { const s = side === 'L' ? -1 : 1; SHOULDER[side].x = s * Math.min(0.25 * P.legGap, 0.42 * P.bodyW); SHOULDER[side].y = 1.5 * P.bodyH; REST[side].x = s * 0.23 * P.legGap; UP[side].x = s * 0.46 * P.legGap; UP[side].y = 1.1 * P.bodyH;
  }
  buildTail(P.tailThick);
  $('shapeOut').value = SHAPE.filter(([k, , , , d]) => P[k] !== d).map(([k]) => `${k}=${P[k]}`).join(' ') || '默认';
}
SHAPE.forEach(([k, label, lo, hi, d]) => {
  const wrap = document.createElement('label'); wrap.style.cssText = 'display:flex;flex-direction:column;font-size:13px;color:var(--muted)'; wrap.textContent = label;
  const input = document.createElement('input'); input.type = 'range'; input.id = 'sh-' + k; input.min = lo; input.max = hi; input.step = 0.01; input.value = d;
  input.oninput = () => { P[k] = +input.value; applyShape(); }; wrap.appendChild(input); $('sliders').appendChild(wrap);
});
$('btnResetShape').onclick = () => { SHAPE.forEach(([k, , , , d]) => { P[k] = d; $('sh-' + k).value = d; }); applyShape(); };
$('btnCopy').onclick = () => { const out = $('shapeOut'), note = $('copied'); out.select();
  (navigator.clipboard ? navigator.clipboard.writeText(out.value) : Promise.reject()).then(() => note.textContent = '已复制，发给我就行', () => note.textContent = '请手动复制下面这行'); };

/* ---------- state ---------- */
const RISE = 30, FALL = 46, STRIKE = RISE + FALL;
const S = { last: performance.now(), lastKey: -1e9, lastMouse: -1e9, lastInput: performance.now(), sleepAt: 0, down: new Map(), thump: 0, thumpSide: 0, jolt: -1e9, rate: 0,
  turn: 0, nod: 0, tilt: 0, lid: 0, blinkAt: performance.now() + 2500, blinkUntil: 0, happyUntil: 0, bounceAt: -1e9, act: null, actUntil: 0, nextAct: performance.now() + 6000, loaf: 0, curl: 0,
  gazeX: 0, gazeY: 0, pointer: null, phase: 0, lastSide: 'L', lastGazeOffset: 0, alt: 'L', spin: 0, spinning: false, mouseDown: false, wigX: 0, wigZ: 0, lidDir: 1, mouseLean: 0, sx: 0, fwd: 0, fwdGoal: 0, demoMouse: 0, wheelAt: -1e9, wheelDir: 1, chips: [] };
const social = { action: '', started: 0, duration: 0, side: -1, care: null, nextJoy: 0, passive: '' };
const bowl = new THREE.Group(); scene.add(bowl); bowl.visible = false;
add(bowl, new THREE.Mesh(new THREE.CylinderGeometry(.38, .28, .16, 24), flat('#dfab62')), 0, .12, 1.18);
add(bowl, new THREE.Mesh(new THREE.CylinderGeometry(.31, .31, .025, 24), flat('#79502f')), 0, .21, 1.18);
for (let i=0;i<8;i++) add(bowl, ball(.06, flat('#f6d293')), Math.cos(i*2.4)*.22, .24, 1.18+Math.sin(i*2.4)*.18);
const yarn = new THREE.Group(); scene.add(yarn); yarn.visible = false;
add(yarn, ball(.22, flat('#dc8f9c')), 0, 0, 0);
for (let i=0;i<3;i++) { const ring=new THREE.Mesh(new THREE.TorusGeometry(.218,.013,6,28),flat('#f6c1c7')); ring.rotation.set(i*.8,i*.6,0); yarn.add(ring); }
const sleeping = t => S.sleepAt && t > S.sleepAt;
if (VIEW.get('eyes') === 'shut') { S.blinkUntil = 1e13; S.blinkAt = 1e14; } if (VIEW.get('eyes') === 'happy') S.happyUntil = 1e13; if (VIEW.get('coat')) setTimeout(() => setCoat(+VIEW.get('coat')), 0); if (VIEW.get('wall')) setTimeout(() => setWall(+VIEW.get('wall')), 0);   // preview switches
let SLEEP_AFTER = VIEW.get('sleep') ? +VIEW.get('sleep') * 1000 : (options.sleepMinutes || 5) * 60000;              // falls asleep by itself after this long without keys or mouse
const zzz = document.createElement('div'); zzz.className = 'zzz'; zzz.innerHTML = '<span>Z</span><span>Z</span><span>Z</span>'; stageEl.appendChild(zzz);
const wake = t => { if (S.sleepAt) { S.sleepAt = 0; S.blinkUntil = t + 320; S.blinkAt = t + 620; S.wokeAt = t; S.bounceAt = t + 150; } S.lastInput = t; S.act = null; S.nextAct = t + rnd(6000, 11000); resumeFrameLoop(); };

function land(p, t) {
  p.landed = true; p.landedAt = t; S.thump = 1; S.thumpSide = p.side === 'L' ? -1 : 1; S.jolt = t;
  const k = p.pending; p.pending = null; if (!k) return;
  k.glow = 1; k.down = S.down.has(k.code); if (showKeyLabels) addChip(k); if (!k.down) { const c = S.chips[S.chips.length - 1]; if (c) setTimeout(() => c.gone = performance.now(), 160); }
}
function strike(side, x, z, t, key) {
  const p = paws[side]; if (!p.landed) land(p, t);
  if (p.mode === 'up') p.pos.copy(UP[side]);
  p.from.copy(p.pos); p.to.set(x, KEY_TOP + 0.09, z); p.tapAt = p.mode === 'up' ? t - RISE : t; p.landed = false; p.pending = key || null; p.mode = 'key';
  S.lastKey = t; lastStrikeAt = t; S.rate = Math.min(12, S.rate + 1); S.lastSide = side;
}
function keyDown(code, t = performance.now()) {
  if (S.down.has(code)) return; social.action=''; wake(t);
  const k = keys.get(code); let side, x, z;
  side = code === 'Space' && t - S.lastMouse < 1600 ? (MOUSE_PAW === 'L' ? 'R' : 'L') : pawFor(code);
  if (k) { x = k.x; z = k.z; if (code === 'Space') x += (side === 'L' ? -1 : 1) * 0.3; } else { x = (side === 'L' ? -1 : 1) * 0.8; z = KB.z0 + 0.55; }
  // Remember the pressed key's own horizontal offset from the keyboard centre (k.ox, 0 = middle),
  // so the eyes look at the key itself - not at the paw and not at the shifted Space tap.
  S.lastGazeOffset = k ? k.ox : ((side === 'L' ? -1 : 1) * 0.8 - activeLayout.baseCenterX * DESK_DIR);
  S.down.set(code, side); strike(side, x, z, t, k); paws[side].held = 1;
}
function keyUp(code) {
  const side = S.down.get(code); if (!side) return; S.down.delete(code);
  const k = keys.get(code); if (k) k.down = false;
  if (![...S.down.values()].includes(side)) paws[side].held = 0;
  const c = S.chips.find(c => c.code === code && !c.gone); if (c) c.gone = performance.now();
}
function addChip(k) { const el = document.createElement('div'); el.className = 'chip'; el.textContent = k.label; stageEl.appendChild(el); S.chips.push({ el, k, code: k.code, born: performance.now(), gone: 0 }); }

/* ---------- click-through hit regions ---------- */
// Hand-added on top of the generated file: the app toggles window click-through from these
// screen-space boxes. They are projected from the live pose, so they follow leans and strikes.
const hitBox = new THREE.Box3(), hitV = new THREE.Vector3();
const hitRect = (box, pad) => {
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  for (const x of [box.min.x, box.max.x]) for (const y of [box.min.y, box.max.y]) for (const z of [box.min.z, box.max.z]) {
    hitV.set(x, y, z).project(camera);
    const px = (hitV.x + 1) / 2, py = (1 - hitV.y) / 2;
    if (px < x0) x0 = px; if (px > x1) x1 = px; if (py < y0) y0 = py; if (py > y1) y1 = py;
  }
  x0 = clamp(x0 - pad, 0, 1); y0 = clamp(y0 - pad, 0, 1); x1 = clamp(x1 + pad, 0, 1); y1 = clamp(y1 + pad, 0, 1);
  return [x0, y0, x1 - x0, y1 - y0];
};
const hitExpand = objs => { hitBox.makeEmpty(); for (const o of objs) hitBox.expandByObject(o, true); return hitRect(hitBox, 0.012); };

let demo = false;
const stopDemo = () => { if (!demo) return; demo = false; $('btnDemo').textContent = '播放演示'; hintEl.textContent = '正在跟随你的键盘'; [...S.down.keys()].forEach(keyUp); };
let lastPointer = null;
on('pointermove', e => { const r = stageEl.getBoundingClientRect(); S.pointer = { x: (e.clientX - r.left) / r.width * 2 - 1, y: (e.clientY - r.top) / r.height * 2 - 1 };
  if (e.pointerType === 'touch') return;
  if (lastPointer) { const dx = e.clientX - lastPointer.x, dy = e.clientY - lastPointer.y; if (Math.abs(dx) + Math.abs(dy) > 2) { const t = performance.now(); wake(t); S.lastMouse = t; S.wigX = clamp(S.wigX + dx * 0.004, -0.16, 0.16); S.wigZ = clamp(S.wigZ + dy * 0.004, -0.12, 0.12); } }
  lastPointer = { x: e.clientX, y: e.clientY }; });
// Only a press on the head is a pet: the eyes arch shut and the head leans into the hand for as long as it is held.
const petRay = new THREE.Raycaster(), petAt = new THREE.Vector2();
const onHeadAt = e => { if (!stageEl.contains(e.target) || (e.target.closest && e.target.closest('button, input, a'))) return 0; const r = renderer.domElement.getBoundingClientRect(); petAt.set((e.clientX - r.left) / r.width * 2 - 1, -((e.clientY - r.top) / r.height * 2 - 1)); petRay.setFromCamera(petAt, camera);
  if (!petRay.intersectObject(head, true).length) return 0; proj.setFromMatrixPosition(head.matrixWorld).project(camera); return petAt.x > proj.x ? -1 : 1; };
on('pointerdown', e => { if (e.button !== 0) return; const t = performance.now(), side = onHeadAt(e); wake(t); if (side) { S.pet = true; S.petSide = side; S.happyUntil = 1e15; return; }
});
on('pointerout', e => { /* keep the last global cursor position for gaze tracking */ });
const endPet = () => { if (S.pet) { S.pet = false; S.happyUntil = performance.now() + 900; } };
on('pointerup', endPet); on('pointercancel', endPet);
$('btnDemo').onclick = () => { if (demo) stopDemo(); else { demo = true; demoI = 0; demoNext = performance.now() + 300; $('btnDemo').textContent = '停止演示'; hintEl.textContent = '演示中，敲任意键接管'; } };
$('btnSleep').onclick = () => { stopDemo(); S.sleepAt = performance.now() + 300; S.lastKey = S.lastMouse = -1e9; };
$('btnSpin').onclick = () => { S.spinning = true; S.spin = 0; };

const DEMO = 'hello 7paws '; let demoI = 0, demoNext = performance.now() + 900, demoHold = null, demoRest = false;
function runDemo(t) {
  if (!demo || t < demoNext) return;
  if (demoHold) { keyUp(demoHold); demoHold = null; demoNext = t + rnd(70, 170); if (demoRest) { demoRest = false; demoNext = t + 6200; S.demoMouse = t + 4600; S.lastMouse = t; } return; }
  const ch = DEMO[demoI++], code = ch === ' ' ? 'Space' : 'Key' + ch.toUpperCase();
  keyDown(code, t); demoHold = code; demoNext = t + rnd(80, 130); if (demoI >= DEMO.length) { demoI = 0; demoRest = true; }
}

/* ---------- frame ---------- */
const shoulderW = V(0, 0, 0), proj = V(0, 0, 0), tmpGoal = V(0, 0, 0);
let renderTimer = 0, debugTicks = 0;
// [7paws] frame budget: high-refresh screens do not need a redraw per vsync, and a resting cat
// needs far fewer than an active one. Paint at 60 FPS when busy, ~18 FPS idle, ~12 FPS asleep.
let pauseTimer = 0, paused = false, refreshMs = 16.7, refreshSamples = 0, lastDirectFrame = 0;
const manualRender = !!options.manualRender;
const scheduleFrame = () => { if (!manualRender) raf = requestAnimationFrame(frame); };
// [7paws] input during a pause must not wait for the remaining delay
function resumeFrameLoop() {
  if (!paused || !pauseTimer) return;
  window.clearTimeout(pauseTimer); pauseTimer = 0; paused = false;
  if (!dead) raf = requestAnimationFrame(frame);
}
function frame() {
  const t = performance.now(), realDt = Math.min(1, (t - S.last) / 1000), dt = Math.min(0.05, realDt); S.last = t;
  if (!manualRender && lastDirectFrame > 0 && refreshSamples < 6) { const gap = t - lastDirectFrame; if (gap >= 3.2 && gap <= 25) { refreshMs = refreshSamples === 0 ? gap : Math.min(refreshMs, gap); refreshSamples += 1; } }
  const ease = k => 1 - Math.exp(-dt * k);
  runDemo(t);
  if (!S.sleepAt && !demo && t - S.lastInput > SLEEP_AFTER) S.sleepAt = t;
  if (VIEW.get('pose')) { S.act = VIEW.get('pose'); S.actUntil = t + (+VIEW.get('left') || 900); }   // preview switch: hold one idle action at a chosen moment
  const typing = t - S.lastKey < 3000, hover = VIEW.has('hover') || t - S.lastKey < 900, asleep = sleeping(t);   // hover: paws stay raised only briefly after the last key
  if (demo && t < S.demoMouse) { S.lastMouse = t; S.wigX = Math.sin(t / 170) * 0.12; S.wigZ = Math.cos(t / 230) * 0.08; S.mouseDown = S.demoMouse - t < 900 && S.demoMouse - t > 650; }
  const mouseOn = mouseGroup.visible && t - S.lastMouse < 1600 && !asleep, mousing = mouseGroup.visible && t - S.lastMouse < 2200 && S.lastMouse > S.lastKey && !asleep;   // paw on the mouse / whole attention on the mouse
  if (social.action && (t-social.started>=social.duration || S.down.size || S.mouseDown || S.pet)) social.action='';
  const socialActive=!!social.action, quiet=!typing && !mousing && !asleep && !S.pet && t>S.happyUntil && t-S.lastInput>3500;
  social.passive=quiet && !socialActive && idleActions && social.care ? (social.care.hunger<=25?'hungry':social.care.mood<=30?'sad':social.care.mood>=80?'happy':'') : '';
  if(social.passive==='sad') { S.act='loaf'; S.actUntil=t+1000; }
  if(social.passive==='hungry') { S.act=Math.sin(t/1800)>0?'lookL':'lookR'; S.actUntil=t+1000; }
  if(social.passive==='happy' && t>social.nextJoy && !reduceMotion) { social.action='joy';social.started=t;social.duration=1600;social.nextJoy=t+45000; }
  S.wigX *= Math.exp(-dt * 5); S.wigZ *= Math.exp(-dt * 5); S.mouseLean += ((mousing ? 1 : 0) - S.mouseLean) * ease(6);
  mouseGroup.position.set(MOUSE_AT.x + (DESK_DIR > 0 ? Math.min(S.wigX, 0.05) : Math.max(S.wigX, -0.05)), 0, MOUSE_AT.z + S.wigZ); mouseBody.material.color.copy(capColor).lerp(hitColor, S.mouseDown && mouseOn ? 1 : 0);
  const wheelK = Math.max(0, 1 - (t - S.wheelAt) / 260), wheelEase = wheelK > 0 ? Math.sin((1 - wheelK) * Math.PI) : 0;
  mouseWheel.position.z = 0.16 + S.wheelDir * 0.022 * wheelEase; mouseWheel.position.y = 0.222 - 0.006 * wheelEase; mouseGroup.rotation.x = -S.wheelDir * 0.07 * wheelEase; mouseBtn.material.color.copy(BTN_INK).lerp(hitColor, wheelEase);
  S.rate *= Math.exp(-dt * 1.2); const fast = 0;   // 0 = unhurried typing, 1 = hammering away
  S.thump *= Math.exp(-dt * 13);

  if (dead) return;
  // [7paws] busy drives the frame rate; idle actions, blinks and sleep Zzz stay at the low rate.
  const settling = asleep && t - (S.sleepAt || 0) < 900;
  const busy = !!social.action || settling || (!asleep && (t - S.lastKey < 3000 || t - S.lastMouse < 2400 || S.pet || t < S.happyUntil || mouseOn || mousing || S.chips.length > 0));
  const w = stageEl.clientWidth, h = stageEl.clientHeight; if (!w || !h) { scheduleFrame(); return; }
  zzz.style.fontSize = (w * 0.063) + 'px';
  if (renderer.domElement.width !== Math.round(w * renderer.getPixelRatio()) || renderer.domElement.height !== Math.round(h * renderer.getPixelRatio())) { renderer.setSize(w, h, false); camera.aspect = w / h; camera.fov = w / h < 1 ? 38 : 31; camera.updateProjectionMatrix(); }
  if (VIEW.has('a')) S.spin = +VIEW.get('a');
  if (S.spinning) { S.spin += dt * 2.4; if (S.spin >= Math.PI * 2) { S.spin = 0; S.spinning = false; } }
  camera.position.set(Math.sin(S.spin) * CAM.z, CAM.y, Math.cos(S.spin) * CAM.z + (1 - Math.cos(S.spin)) * LOOK.z); camera.lookAt(LOOK);

  // keys
  const jolt = 0.03 * Math.exp(-(t - S.jolt) / 55); board.position.y = -jolt;
  keys.forEach(k => { if (!k.down && k.glow > 0) k.glow = Math.max(0, k.glow - dt * 5); k.mesh.position.y = 0.145 - (k.down ? 0.045 : 0); k.mesh.material.color.copy(k.base).lerp(hitColor, k.glow); });

  // body
  S.phase += dt * (asleep ? 0.9 : 1.7 + Math.min(1.4, S.rate * 0.14));
  const breath = reduceMotion ? 0 : Math.sin(S.phase), bounceE = (t - S.bounceAt) / 420, bounce = bounceE < 1 ? Math.sin(Math.PI * bounceE) : 0;
  const reach = (paws.L.pos.z > 0.86 ? paws.L.pos.x - REST.L.x : 0) + (paws.R.pos.z > 0.86 ? paws.R.pos.x - REST.R.x : 0);
  const act0 = S.act, stretching = act0 === 'stretch', licking = act0 === 'lick', grooming = act0 === 'groom', loafing = act0 === 'loaf', curling = act0 === 'curl', yawning = act0 === 'yawn', flicking = act0 === 'tail', pouncing = act0 === 'pounce', actLeft = S.actUntil - t;
  const yawn = yawning ? Math.sin(clamp(1 - actLeft / 1900, 0, 1) * Math.PI) ** 0.7 : 0, crouch = pouncing && actLeft > 520, swat = pouncing && actLeft <= 520 && actLeft > 120;
  if (pouncing && actLeft <= 520 && !S.pounced) { S.pounced = true; S.bounceAt = t; } if (!pouncing) S.pounced = false;
  S.loaf += ((loafing ? 1 : 0) - S.loaf) * ease(typing || mousing || S.pet ? 8 : 4);
  S.curl += ((curling ? 1 : 0) - S.curl) * ease(typing || mousing || S.pet ? 8 : 3.5);
  S.low = (S.low || 0) + ((stretching ? 1 : crouch ? 0.55 : 0) - (S.low || 0)) * ease(crouch ? 9 : 5);
  const loaf = S.loaf, curl = S.curl, squat = 1 - 0.3 * loaf - 0.2 * curl - 0.16 * S.low;
  torso.scale.set(P.bodyW * (1 + 0.1 * loaf + 0.18 * curl + breath * (0.012 + 0.012 * loaf) - bounce * 0.03), P.bodyH * squat * (1 + breath * 0.012 + bounce * 0.05 - S.thump * (0.012 + 0.02 * fast)), 1 + 0.08 * loaf + 0.16 * curl + breath * 0.02);
  hindFeet.forEach((g, i) => { g.position.x = (i ? 1 : -1) * 0.6 * P.bodyW * (1 - 0.12 * loaf - 0.28 * curl); g.position.y = 0.1; g.position.z = 0.25 - 0.22 * loaf - 0.12 * curl; });
  // The body leans toward what the paws are doing, so the arms keep their length instead of stretching.
  let farZ = 0; for (const sd of ['L', 'R']) { const q = paws[sd]; if (q.held || t - q.tapAt < 260) farZ = Math.max(farZ, q.to.z); }
  if (farZ) S.fwdGoal = clamp(0.14 + (farZ - KB.z0) * 0.32, 0.14, 0.42);
  S.fwd += ((asleep ? 0 : mousing ? 0.2 : hover ? S.fwdGoal : 0) - S.fwd) * ease(7);
  S.sx += (clamp(reach * 0.13, -0.2, 0.2) - DESK_DIR * S.mouseLean * 0.24 - S.sx) * ease(9);
  const sx = S.sx + S.thump * S.thumpSide * 0.012 + (crouch ? Math.sin(t / 55) * 0.02 : 0), fwd = S.fwd;
  upper.matrix.set(1, sx, 0, 0,  0, 1 - fwd * fwd * 0.35, 0, 0,  0, fwd, 1, 0,  0, 0, 0, 1);
  S.tailDown = (S.tailDown || 0) + ((asleep || loafing || curling ? 1 : 0) - (S.tailDown || 0)) * ease(2.6); poseTail(S.tailDown < 0.003 ? 0 : S.tailDown > 0.997 ? 1 : S.tailDown, flicking ? Math.sin(t / 85) * Math.sin(clamp(1 - actLeft / 1400, 0, 1) * Math.PI) : crouch ? Math.sin(t / 60) * 0.5 : 0);
  tail.rotation.z = Math.sin(t / 900) * 0.05 * (1 - S.tailDown) + (typing ? Math.sin(t / (160 - 70 * fast)) * (0.02 + 0.07 * fast) : 0);

  // head
  const happy = t < S.happyUntil, calm = (idleActions || VIEW.has('pose')) && !typing && !asleep && !happy && !mousing && t - S.lastInput > 3500;
  if (S.wantStretch) { S.wantStretch = false; if (calm && !reduceMotion) { S.act = 'stretch'; S.actUntil = t + 1700; S.nextAct = t + rnd(6000, 12000); } }
  if (calm && !social.action && !social.passive && idleActions && !reduceMotion && t > S.nextAct) { const pool = ['lookL', 'lookR', 'tilt', 'ear', 'ear', 'lick', 'lick', 'groom', 'groom', 'loaf', 'curl', 'stretch', 'yawn', 'tail']; S.act = pool[Math.floor(Math.random() * pool.length)]; S.actUntil = t + ({ ear: 420, lick: 3200, groom: 4600, loaf: 5200, curl: 5600 }[S.act] || 1700); S.nextAct = t + rnd(7000, 15000); }
  if (S.act && (t > S.actUntil || !calm)) S.act = null;
  const looking = typing && t - S.lastKey < 1000;
  // Look toward the key that was actually pressed, judged by its position on the keyboard
  // (k.ox: negative = left half, 0 = centre, positive = right half). The Space bar is centred,
  // so it now looks straight ahead instead of being treated as the left hand and glancing left.
  const look = looking ? clamp((S.lastGazeOffset || 0) / (activeLayout.totalUnits * KB.unit / 2), -1, 1) : 0;
  // While the real cursor is moving, the cat watches the cursor itself: direction is measured from the head's place on screen.
  const follow = mousing && S.pointer && !(demo && t < S.demoMouse); let pdx = 0, pdy = 0;
  if (follow) { proj.setFromMatrixPosition(head.matrixWorld).project(camera); pdx = S.pointer.x - proj.x; pdy = S.pointer.y + proj.y; }
  const turnT = asleep ? 0 : follow ? clamp(pdx * 0.5, -0.4, 0.4) : mousing ? -0.36 * DESK_DIR : looking ? look * 0.3 : grooming ? 0.48 + Math.sin(t / 180) * 0.05 : curling ? -0.14 : loafing ? 0.1 : S.act === 'lookL' ? -0.45 : S.act === 'lookR' ? 0.45 : S.pointer && !typing ? clamp(S.pointer.x, -1, 1) * 0.22 : 0;
  const nodT = asleep ? 0.5 : yawning ? -0.3 * yawn : crouch ? 0.16 : happy ? -0.12 : grooming ? 0.3 + Math.sin(t / 150) * 0.08 : curling ? 0.2 : loafing ? 0.1 : licking ? 0.34 + Math.sin(t / 140) * 0.09 : stretching ? -0.18 : looking ? 0.3 : follow ? clamp(pdy * 0.5, -0.28, 0.4) : mousing ? 0.22 : S.pointer && !typing ? clamp(S.pointer.y * 0.2, -0.16, 0.2) : 0;
  const tiltT = asleep ? 0.16 : grooming ? -0.28 : curling ? 0.1 : licking ? -0.12 : S.act === 'tilt' ? 0.2 : happy ? (S.petSide || -1) * 0.1 + Math.sin(t / 330) * 0.06 : 0;
  S.turn += (turnT - S.turn) * ease(7); S.nod += (nodT - S.nod) * ease(6); S.tilt += (tiltT - S.tilt) * ease(5);
  { const w = (t - (S.wokeAt || -1e9)) / 750; S.shake = w > 0.15 && w < 1 ? Math.sin(w * 26) * 0.16 * (1 - w) : 0; }
  head.rotation.set(S.nod + S.thump * (0.05 + 0.05 * fast), S.turn + S.shake, S.tilt);
  head.position.x = 0.04 * grooming - 0.05 * curling;
  head.position.z = 0.1 - fwd * 0.5 + 0.22 * loaf + 0.3 * curl;
  head.position.y = (2.3 * P.bodyH + P.neck) * (1 - 0.32 * loaf - 0.22 * curl - 0.16 * S.low) + breath * 0.018 + bounce * 0.09 - (asleep ? 0.14 : 0) - S.thump * 0.02;
  { const py = head.position.y; headFix.matrix.set(1, -sx, 0, sx * py,  0, 1, 0, 0,  0, -fwd, 1, fwd * py,  0, 0, 0, 1); }
  const flick = S.act === 'ear' ? Math.sin((S.actUntil - t) / 420 * Math.PI * 2) * 0.28 : 0;
  { const relax = S.lidDir < 0 ? 0.2 * smooth(0.5, 1, S.lid) : 0; ears[0].rotation.z = 0.24 + relax + flick; ears[1].rotation.z = -0.24 - relax - flick * 0.5; ears[0].rotation.x = ears[1].rotation.x = -0.32 * fast; }

  // eyes
  if (t > S.blinkAt) { S.blinkUntil = t + 130; S.blinkAt = t + rnd(2600, 5600); }
  S.lidDir = 1;                      // happy: lower lid rises into an arch; blink and sleep: upper lid comes down
  const lidT = asleep || t < S.blinkUntil || happy || grooming ? 1 : curling ? 0.32 : loafing ? 0.22 : licking || stretching || yawn > 0.25 ? 1 : 0; S.lid += (lidT - S.lid) * (1 - Math.exp(-realDt * (lidT > S.lid ? 34 : 16)));   // eyelids keep real time even if frames are dropped
  const gx = follow ? clamp(pdx * 2.2, -1, 1) : mousing ? -0.8 * DESK_DIR : looking ? look * 0.5 : S.pointer ? clamp(S.pointer.x * 1.2, -1, 1) : 0, gy = follow ? clamp(pdy * 2.2, -1, 1) : looking || mousing ? 1 : S.pointer ? clamp(S.pointer.y * 1.3, -1, 1) : 0;
  S.gazeX += (gx - S.gazeX) * ease(9); S.gazeY += (gy - S.gazeY) * ease(9);
  const widen = (looking ? 1.25 : pouncing ? 1.9 : 1) * (1 - 0.3 * fast);
  const ex = 0.37 * P.eyeSpread, ey = P.eyeY, er = 0.205 * P.eyeSize, iry = er * 0.74, c = S.lid, dir = S.lidDir || 1;
  for (const e of eyes) {                                           // the eye keeps its shape; a fur-coloured lid slides over it and leaves a soft crescent
    // Closing: the eye squashes flat, then becomes one clean curved line (down-curve for a blink or sleep, arch when happy).
    const pet = dir < 0, shut = c > 0.7, h = iry * mix(1, pet ? 0.7 : 0.3, smooth(0, 0.8, c)), eyc = ey + (dir * h * (pet ? 0.84 : 0.7) - iry) * smooth(0.35, 0.7, c);   // the closed line rests half an eye below the eye's centre
    e.iris.material = shut ? M.shut : (e.s > 0 ? M.eye2 : M.eye);
    fillPatch(e.iris, e.s * ex, eyc, er * (shut ? (pet ? 0.8 : 0.92) : 1), h, e.s * P.eyeTilt, 0.008);
    const prx = er * 0.3 * widen, dx = clamp(S.gazeX * er * 0.42, -(er * 0.9 - prx), er * 0.9 - prx);
    const room = h * Math.sqrt(Math.max(0, 1 - ((Math.abs(dx) + prx * 0.7) / er) ** 2));   // how tall the eye is where the pupil sits
    const pry = Math.min(er * 0.62, room * 0.68), dy = clamp(-S.gazeY * iry * 0.34, -(room - pry), room - pry);
    fillPatch(e.pupil, e.s * ex + dx, eyc + dy, prx, pry, e.s * P.eyeTilt * 0.5, 0.013);
    e.pupil.visible = c < 0.45; e.lid.visible = shut;
    if (shut) fillPatch(e.lid, e.s * ex, eyc + dir * h * (pet ? 0.32 : 0.6), er * (pet ? 0.8 : 1.08), h, e.s * P.eyeTilt, 0.018);
    const joy = pet ? smooth(0.5, 1, c) : 0;                         // being petted: rosy cheeks appear and the ears relax outward
    e.blush.visible = joy > 0.02; if (e.blush.visible) fillPatch(e.blush, e.s * ex * 1.42, ey - er * 1.3, er * 0.46 * joy, er * 0.25 * joy, 0, 0.007);
  }

  // paws
  for (const side of ['L', 'R']) {
    const p = paws[side], since = t - p.tapAt;
    if (since < STRIKE) {
      const k = 1 - Math.pow(1 - Math.min(1, since / 60), 3); p.pos.lerpVectors(p.from, p.to, k);
      p.pos.y = since < RISE ? mix(p.from.y, Math.max(p.from.y, p.to.y + 0.7), since / RISE) : mix(Math.max(p.from.y, p.to.y + 0.7), p.to.y - 0.03, Math.pow((since - RISE) / FALL, 2));
    } else {
      if (!p.landed) land(p, t);
      if (p.held) { p.pos.x += (p.to.x - p.pos.x) * ease(30); p.pos.z += (p.to.z - p.pos.z) * ease(30); p.pos.y += (p.to.y - 0.04 - p.pos.y) * ease(30); }
      else if (side === MOUSE_PAW && mouseOn && since > STRIKE + 110) { p.mode = 'mouse'; tmpGoal.copy(mouseGroup.position); tmpGoal.y = 0.3 - (S.mouseDown ? 0.05 : 0); tmpGoal.z += 0.02; p.pos.lerp(tmpGoal, ease(16)); }
      else if (licking && side === 'R' && since > STRIKE + 110) { p.mode = 'lick'; head.getWorldPosition(tmpGoal); tmpGoal.x += 0.06; tmpGoal.y += -0.5 * P.headSize - Math.sin(t / 140) * 0.06; tmpGoal.z += 0.66 * P.headSize; p.pos.lerp(tmpGoal, ease(12)); }
      else if (grooming && side === 'R' && since > STRIKE + 110) { p.mode = 'groom'; tmpGoal.set(0.48 * P.bodyW, 1.38 * P.bodyH + Math.sin(t / 170) * 0.035, 0.68); p.pos.lerp(tmpGoal, ease(10)); }
      else if ((loaf > 0.03 || curl > 0.03) && since > STRIKE + 110) { p.mode = 'tuck'; tmpGoal.set((side === 'L' ? -1 : 1) * 0.1, 0.18, 0.48 + 0.08 * curl); p.pos.lerp(tmpGoal, ease(8)); }
      else if (swat && side === (S.pointer && S.pointer.x > 0 ? 'R' : 'L') && since > STRIKE + 110) { p.mode = 'swat'; tmpGoal.set((side === 'L' ? -1 : 1) * 0.62, 1.25, 1.05); p.pos.lerp(tmpGoal, ease(26)); }
      else if (stretching && since > STRIKE + 110) { p.mode = 'rest'; tmpGoal.copy(REST[side]); tmpGoal.z += 0.85; p.pos.lerp(tmpGoal, ease(8)); }
      else if (since > STRIKE + 110) { p.mode = hover ? 'up' : 'rest'; const goal = hover ? tmpGoal.copy(UP[side]) : REST[side]; if (hover) goal.z += fwd * 0.6; p.pos.lerp(goal, ease(hover ? 20 : 9)); if (hover) p.pos.y += Math.sin(t / 240 + (side === 'L' ? 0 : 1.8)) * 0.004; }
    }
    shoulderW.copy(SHOULDER[side]); shoulderW.x += sx * shoulderW.y; shoulderW.z += fwd * shoulderW.y; shoulderW.y = shoulderW.y * (1 - fwd * fwd * 0.35) * squat + breath * 0.02 + bounce * 0.06;
    drawLimb(p, shoulderW);
  }

  // Dedicated social poses use world-space paws, so input and hit regions keep working.
  cat.position.y=0; cat.rotation.y=0;
  const action=social.action, a=Math.max(0,(t-social.started)/1000), motion=reduceMotion?0:1;
  bowl.visible=action==='feed'; yarn.visible=action==='play'; activeLayout.group.visible=showKeyboard && action!=='feed' && action!=='play';
  if(social.passive==='hungry') { head.rotation.x+=.22; head.position.y-=.1; }
  if(social.passive==='sad') { ears[0].rotation.z+=.18;ears[1].rotation.z-=.18; }
  if(action) {
    let lift=0;
    if(action==='feed') { head.rotation.x=.58+Math.sin(a*9)*.12*motion;head.position.y-=.5;head.position.z+=.35; }
    if(action==='play') { yarn.position.set(Math.sin(a*4)*.65,.26+Math.abs(Math.sin(a*5))*.18*motion,1.28);yarn.rotation.z=a*3*motion;head.rotation.y=Math.sin(a*4)*.25;head.rotation.x=.32; }
    if(action==='wave' || action==='highfive') head.rotation.y=social.side*.5;
    if(action==='celebrate' || action==='joy') { lift=Math.abs(Math.sin(a*5))*.22*motion;cat.position.y=lift;if(action==='celebrate') cat.rotation.y=motion*a*Math.PI*2/2.8; }
    cat.updateMatrixWorld(true);
    for(const side of ['L','R']) {
      const p=paws[side], sign=side==='L'?-1:1;
      if(action==='feed') p.pos.set(sign*.4,.18,1.05);
      if(action==='play') p.pos.set(Math.sin(a*4)*.65+sign*.14,.3+Math.max(0,Math.sin(a*6+sign))*.5*motion,1.22);
      if((action==='wave' || action==='highfive') && sign===social.side) p.pos.set(sign*(action==='wave'?.65:.95),1.7+Math.sin(a*9)*.12*motion,.65);
      if(action==='celebrate' || action==='joy') p.pos.set(sign*.4,.35, .76).applyMatrix4(cat.matrixWorld);
      shoulderW.copy(SHOULDER[side]);if(action==='celebrate' || action==='joy') shoulderW.applyMatrix4(cat.matrixWorld);drawLimb(p,shoulderW);
    }
  }
  renderer.domElement.dataset.socialAction=action || social.passive || 'idle';
  renderer.render(scene, camera);
  // labels above the keys that were hit
  tongue.visible = (licking && paws.R.mode === 'lick') || (grooming && paws.R.mode === 'groom'); if (tongue.visible) { const k = Math.sin(t / (grooming ? 170 : 140)); tongue.position.y = -0.38 - 0.05 * k; tongue.scale.set(0.06, 0.085 + 0.03 * k, 0.04); }
  mouth.visible = yawn > 0.04; if (mouth.visible) fillPatch(mouth, 0, -0.29 - 0.05 * yawn, 0.03 + 0.085 * yawn, 0.01 + 0.14 * yawn, 0, 0.012);
  zzz.style.display = asleep ? 'block' : 'none';
  if (asleep) { head.getWorldPosition(proj); proj.x += 0.5; proj.y += 0.5; proj.project(camera); zzz.style.left = ((proj.x + 1) / 2 * w) + 'px'; zzz.style.top = ((1 - proj.y) / 2 * h) + 'px'; }
  S.chips = S.chips.filter(c => { const out = c.gone ? (t - c.gone) / 240 : 0; if (out >= 1) { c.el.remove(); return false; }
    proj.set(c.k.x, 0.75, c.k.z).project(camera); c.el.style.left = ((proj.x + 1) / 2 * w) + 'px'; c.el.style.top = ((1 - proj.y) / 2 * h - out * 8) + 'px'; c.el.style.opacity = String(Math.min(1, (t - c.born) / 80) * (1 - out)); return true; });
  // [7paws] Win7 capture must run before the WebGL drawing buffer is cleared.
  if (afterRender) afterRender();
  if (manualRender) return;
  // [7paws] a ~60Hz screen can follow vsync directly; faster screens are held to 60 FPS.
  // The pause leaves room for this frame's own work plus the wait for the next vsync.
  if (busy && (refreshSamples < 6 || refreshMs >= 13.5)) { paused = false; lastDirectFrame = t; raf = requestAnimationFrame(frame); return; }
  lastDirectFrame = 0;
  const spentMs = performance.now() - t;
  const budgetMs = busy ? 16.7 : asleep ? 85 : 55;
  const waitMs = Math.max(4, Math.round(budgetMs - spentMs - refreshMs * 0.35));
  raf = 0;
  paused = !busy;
  pauseTimer = window.setTimeout(() => { pauseTimer = 0; paused = false; raf = requestAnimationFrame(frame); }, waitMs);
}
// A coat is named by its id; an unknown id falls back to the first coat.
const coatIndex = id => Math.max(0, COATS.findIndex(c => c.id === id));
setCoat(coatIndex(options.coat)); applyShape();
if (manualRender) renderTimer = setInterval(() => { if (debugTicks < 3 && options.onDebug) { debugTicks++; options.onDebug('render:timer'); } frame(); }, 33); else scheduleFrame();

/* ---------- what the app can ask of the cat ---------- */
// A press whose key is not being shown (privacy mode, or a key off the little keyboard) still gets a paw tap, with no key attached.
// The signal names the owner's hand; the keyboard faces the cat, so the owner's left hand is the paw on the right of the picture.
const plainTap = side => { const t = performance.now(), s = side === 'Left' ? 'R' : 'L'; social.action=''; wake(t); strike(s, (s === 'L' ? -1 : 1) * rnd(0.45, 1.0), KB.z0 + rnd(0.35, 0.7), t, null); };
let shownKeys = new Set(), buttonsDown = 0, buttonsAt = 0;
// The system sometimes never reports a key coming back up (on macOS, any key let go while Command is held). A paw therefore
// lets go by itself after a moment instead of staying planted on a key nobody is pressing.
const HOLD_MAX = 1400, downAt = new Map();
const letGo = setInterval(() => { const t = performance.now();
  for (const [code, at] of downAt) if (t - at > HOLD_MAX) { keyUp(code); downAt.delete(code); }
  if (S.mouseDown && buttonsAt && t - buttonsAt > HOLD_MAX) S.mouseDown = false; }, 200);
let captureCanvas = null;
const roundedRectPath = (ctx, x, y, w, h, r) => {
  const radius = Math.max(0, Math.min(r, w / 2, h / 2));
  ctx.beginPath();
  ctx.moveTo(x + radius, y);
  ctx.lineTo(x + w - radius, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
  ctx.lineTo(x + w, y + h - radius);
  ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
  ctx.lineTo(x + radius, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
  ctx.lineTo(x, y + radius);
  ctx.quadraticCurveTo(x, y, x + radius, y);
  ctx.closePath();
};
const captureDomText = (ctx, el, stageRect, scaleX, scaleY, opacity) => {
  const style = getComputedStyle(el), rect = el.getBoundingClientRect();
  if (style.display === 'none' || style.visibility === 'hidden' || rect.width <= 0 || rect.height <= 0) return;
  const alpha = parseFloat(style.opacity);
  ctx.save();
  ctx.globalAlpha = opacity * (Number.isFinite(alpha) ? alpha : 1);
  ctx.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  const x = (rect.left - stageRect.left + rect.width / 2) * scaleX;
  const y = (rect.top - stageRect.top + rect.height / 2) * scaleY;
  if (el.classList.contains('chip')) {
    const left = (rect.left - stageRect.left) * scaleX, top = (rect.top - stageRect.top) * scaleY;
    const width = rect.width * scaleX, height = rect.height * scaleY;
    const radius = Math.min(parseFloat(style.borderTopLeftRadius) || 7, rect.height / 2) * scaleX;
    ctx.shadowColor = 'rgba(0,0,0,0.14)';
    ctx.shadowBlur = 8 * scaleY;
    ctx.shadowOffsetY = 3 * scaleY;
    roundedRectPath(ctx, left, top, width, height, radius);
    ctx.fillStyle = style.backgroundColor;
    ctx.fill();
    ctx.shadowColor = 'transparent';
    ctx.lineWidth = Math.max(1, parseFloat(style.borderTopWidth) || 1) * scaleX;
    ctx.strokeStyle = style.borderTopColor;
    ctx.stroke();
  } else {
    ctx.shadowColor = 'rgba(30,24,10,0.22)';
    ctx.shadowBlur = 2 * scaleY;
    ctx.shadowOffsetY = 2 * scaleY;
  }
  ctx.fillStyle = style.color;
  ctx.fillText(el.textContent || '', x, y, rect.width * scaleX * 0.95);
  ctx.restore();
};
const renderCaptureFrame = opacity => {
  const source = renderer.domElement, width = source.width, height = source.height;
  const stageRect = stageEl.getBoundingClientRect();
  if (width < 8 || height < 8 || stageRect.width <= 0 || stageRect.height <= 0) return null;
  if (!captureCanvas) captureCanvas = document.createElement('canvas');
  if (captureCanvas.width !== width || captureCanvas.height !== height) {
    captureCanvas.width = width;
    captureCanvas.height = height;
  }
  const ctx = captureCanvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) return null;
  const scaleX = width / stageRect.width, scaleY = height / stageRect.height;
  ctx.clearRect(0, 0, width, height);
  ctx.globalAlpha = clamp(opacity, 0, 1);
  ctx.drawImage(source, 0, 0, width, height);
  if (getComputedStyle(zzz).display !== 'none') {
    for (const span of zzz.querySelectorAll('span')) captureDomText(ctx, span, stageRect, scaleX, scaleY, opacity);
  }
  for (const chip of S.chips) captureDomText(ctx, chip.el, stageRect, scaleX, scaleY, opacity);
  return ctx.getImageData(0, 0, width, height);
};
return {
  performSocial(kind, elapsed = 0, side = -1) {
    const durations={feed:4200,play:4600,wave:2600,highfive:3000,celebrate:2800};
    if(!durations[kind] || elapsed*1000>=durations[kind] || S.down.size || S.mouseDown || S.pet) return;
    const t=performance.now();wake(t);social.action=kind;social.started=t-Math.max(0,elapsed)*1000;social.duration=durations[kind];social.side=side<0?-1:1;
    S.lastKey=S.lastMouse=-1e9;
  },
  setCare(care) { social.care=care; if(!care) social.action=''; },
  coats: COATS.map(c => c.id),
  setAfterRender(callback) { afterRender = callback; },
  captureFrame(opacity) { return renderCaptureFrame(opacity); },
  headSideAt(clientX, clientY) {
    return onHeadAt({ target: stageEl, clientX, clientY });
  },
  hitRegions() {
    if (!stageEl.clientWidth || !stageEl.clientHeight) return [];
    scene.updateMatrixWorld(true);
    return [
      hitExpand([head]),
      hitExpand([torso, ...hindFeet, paws.L.rim, paws.L.core, paws.L.root, paws.L.foot, paws.R.rim, paws.R.core, paws.R.root, paws.R.foot]),
      hitExpand([tail]),
      hitExpand([activeLayout.group]),
      hitExpand([mouseGroup]),
    ];
  },
  setPointerNdc(x, y) {
    S.pointer = { x, y };
  },
  setLayout(id) {
    applyLayout(id);
  },
  setMouseActive() {
    const t = performance.now();
    wake(t);
    S.lastMouse = t;
  },
  nudgeMouse(dx, dy) {
    S.wigX = clamp(S.wigX + dx, -0.16, 0.16);
    S.wigZ = clamp(S.wigZ + dy, -0.12, 0.12);
  },
  setInput(keySet, buttonSet) {
    for (const code of shownKeys) if (!keySet.has(code)) { keyUp(code); downAt.delete(code); }
    for (const code of keySet) if (!shownKeys.has(code)) { keyDown(code); downAt.set(code, performance.now()); }
    shownKeys = new Set(keySet);
    const t = performance.now(), n = buttonSet.size;
    if (n !== buttonsDown) { wake(t); S.lastMouse = t; S.mouseDown = n > 0; buttonsDown = n; buttonsAt = n > 0 ? t : 0; }
  },
  tap(side) {
    if (!followKeys) { plainTap(side); return; }
    const at = performance.now(); setTimeout(() => { if (!dead && lastStrikeAt < at && !S.down.size && !shownKeys.size) plainTap(side); }, 45);
  },
  setOptions(o) {
    if ('showKeyboard' in o) { showKeyboard = !!o.showKeyboard; activeLayout.group.visible = showKeyboard; }
    if ('showMouse' in o) mouseGroup.visible = !!o.showMouse;
    if ('followKeys' in o) { followKeys = !!o.followKeys; if (!followKeys) { [...S.down.keys()].forEach(keyUp); shownKeys = new Set(); downAt.clear(); } }
    if ('leftMouse' in o) setHanded(!!o.leftMouse);
    if ('coat' in o) setCoat(coatIndex(o.coat));
    if ('sleepMinutes' in o && !VIEW.get('sleep')) SLEEP_AFTER = (+o.sleepMinutes > 0 ? clamp(+o.sleepMinutes, 1, 120) : 1e12) * 60000;
    if ('idleActions' in o) { idleActions = !!o.idleActions; S.act = null; S.nextAct = performance.now() + rnd(6000, 11000); }
    if ('showKeyLabels' in o) {
      showKeyLabels = !!o.showKeyLabels;
      if (!showKeyLabels) { S.chips.forEach(c => c.el.remove()); S.chips = []; }
    }
  },
  // [7paws] whole-palette coat (photo-derived); takes the same shape as a COATS entry.
  setCustomCoat(c) { applyCoat(c); },
  // The mouse is being moved somewhere on the system. No position is involved; the paw goes to the mouse and nudges it.
  mouseMoved() { const t = performance.now(); wake(t); S.lastMouse = t; S.wigX = clamp(S.wigX + rnd(-0.07, 0.07), -0.16, 0.16); S.wigZ = clamp(S.wigZ + rnd(-0.06, 0.06), -0.12, 0.12); },
  // [7paws] the scroll wheel turned; roll the desk mouse and let the cat look over.
  mouseWheel(dir) { const t = performance.now(); wake(t); S.lastMouse = t; S.wheelAt = t; S.wheelDir = Math.sign(dir) || 1; S.wigX = clamp(S.wigX + rnd(-0.02, 0.02), -0.16, 0.16); },
  clear() { wake(performance.now()); [...S.down.keys()].forEach(keyUp); shownKeys = new Set(); downAt.clear(); buttonsDown = 0; buttonsAt = 0; S.mouseDown = false; },
  state() { const t = performance.now(); return sleeping(t) ? 'sleeping' : S.pet || t < S.happyUntil ? 'petting' : t - S.lastKey < 3000 ? 'typing' : mouseGroup.visible && t - S.lastMouse < 2200 ? 'mouse' : 'idle'; },
  idleAction() { return S.act || ''; },
  fastForwardToSleep() { S.sleepAt = performance.now(); S.lastKey = S.lastMouse = -1e9; },
  destroy() { dead = true; afterRender = null; clearInterval(letGo); clearInterval(renderTimer); clearTimeout(pauseTimer); cancelAnimationFrame(raf); offs.forEach(f => f()); S.chips.forEach(c => c.el.remove()); zzz.remove(); renderer.domElement.remove(); renderer.dispose(); },
};
};
