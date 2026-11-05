import * as THREE from './three.module.min.js';

// Visual-only world. Native tree coordinates and all account state stay unchanged.
export function createTreeMap(scene, camera, invalidate) {
  const world = new THREE.Group();
  scene.add(world);
  const target = new THREE.Vector3(130, 108, 0);
  let yaw = .48, pitch = .46, aspect = 1;
  let zoom = 1;
  const clamp = THREE.MathUtils.clamp;
  const mat = color => new THREE.MeshStandardMaterial({ color, flatShading: true, roughness: 1 });
  const add = (geometry, color, x, y, z, parent = world) => {
    const mesh = new THREE.Mesh(geometry, mat(color));
    mesh.position.set(x + 130, y, z);
    parent.add(mesh);
    return mesh;
  };
  const cylinder = (radius, height, color, x, y, z, sides = 8) =>
    add(new THREE.CylinderGeometry(radius, radius, height, sides), color, x, y, z);
  // Layered island, with an open clearing around the user's tree.
  cylinder(275, 23, 0x997859, 0, -8, 0, 12);
  cylinder(282, 8, 0x5b8a56, 0, 7, 0, 12);
  cylinder(278, 5, 0x91b975, 0, 13, 0, 12);
  cylinder(88, 1, 0xb4c98a, 0, 16, 0, 32);
  // Irregular pond sits in a shallow stone rim.
  const rim = cylinder(65, 2, 0xc1bca0, 136, 16, 70, 10);
  rim.scale.set(1, 1, .64);
  const pond = cylinder(60, 2, 0x70b6be, 136, 17.2, 70, 10);
  pond.scale.set(1, 1, .61);
  for (let i = 0; i < 4; i++) {
    const lily = cylinder(5, .5, 0x6f9d66, 116 + i * 13, 18.6, 61 + Math.sin(i * 2) * 17, 7);
    lily.rotation.y = i;
  }
  // A winding stepping-stone trail, never obscuring the main tree's roots.
  for (let i = 0; i < 19; i++) {
    const z = -240 + i * 25;
    const x = -85 + Math.sin(i * .34) * 25;
    const stone = cylinder(10 + i % 3, 1.3, i % 2 ? 0xd5c4a0 : 0xcbbb99, x, 16.8, z, 6);
    stone.scale.z = .65;
    stone.rotation.y = i * .63;
  }
  const pine = (x, z, height, tone) => {
    cylinder(3.5, height * .5, 0x937451, x, 16 + height * .25, z, 6);
    for (let tier = 0; tier < 3; tier++) {
      add(new THREE.ConeGeometry(height * (.29 - tier * .045), height * .53, 7), tone,
        x, 16 + height * (.48 + tier * .19), z);
    }
  };
  [[-190,-115,72],[-145,-197,88],[-55,-223,66],[40,-230,82],[143,-180,76],
    [218,-85,62],[226,28,68],[-220,18,65],[-184,151,70],[20,232,63],[115,210,77]]
    .forEach(([x,z,h], i) => pine(x,z,h,[0x558e69,0x6a9e70,0x78a477][i%3]));
  for (let i = 0; i < 28; i++) {
    const a = i * 2.39996, r = 140 + (i % 4) * 24;
    const x = Math.cos(a) * r, z = Math.sin(a) * r;
    if (x > 70 && z > 0 && z < 135) continue;
    const rock = add(new THREE.IcosahedronGeometry(7 + i % 4, 0), 0xa4ad97, x, 19, z);
    rock.scale.set(1.3,.65,1);
    for (let j = 0; j < 3; j++) {
      add(new THREE.ConeGeometry(2, 9 + j * 2, 3), 0x6d9c5f, x + 12 + j * 4, 20, z + j * 3);
    }
  }
  // Picnic seat and timber fence give the clearing a human scale.
  const seat = add(new THREE.BoxGeometry(45,4,15),0xb48a59,-131,35,61);
  seat.rotation.y = -.25;
  for (const x of [-146,-116]) for (const z of [57,66])
    add(new THREE.BoxGeometry(3,18,3),0x826947,x,25,z);
  add(new THREE.BoxGeometry(45,14,3),0xc69c67,-131,48,69).rotation.y=-.25;
  for (let i=0;i<6;i++) {
    const x=12+i*23;
    add(new THREE.BoxGeometry(4,24,4),0xc2a476,x,28,-113);
    if(i<5) add(new THREE.BoxGeometry(23,3,3),0xd4b78b,x+11.5,34,-113);
  }

  function update() {
    const span = 365 / zoom;
    camera.left = -span * Math.max(1,aspect) / 2;
    camera.right = -camera.left;
    camera.top = span * Math.max(1,1/aspect) / 2;
    camera.bottom = -camera.top;
    camera.position.copy(target).add(new THREE.Vector3(
      Math.sin(yaw)*Math.cos(pitch), Math.sin(pitch), Math.cos(yaw)*Math.cos(pitch)).multiplyScalar(800));
    camera.lookAt(target);
    camera.updateProjectionMatrix();
    camera.updateMatrixWorld();
    invalidate();
  }
  function orbit(dx,dy) {
    if(!Number.isFinite(dx)||!Number.isFinite(dy)) return;
    yaw = THREE.MathUtils.euclideanModulo(yaw-dx*Math.PI*2,Math.PI*2);
    pitch = clamp(pitch+dy*1.5,.22,1.12);
    update();
  }
  function move(horizontal,forward) {
    if(!Number.isFinite(horizontal)||!Number.isFinite(forward)) return;
    // Arrow directions follow the camera, even after orbiting around the tree.
    target.x = clamp(target.x + (Math.cos(yaw)*horizontal-Math.sin(yaw)*forward)*14, -35,295);
    target.z = clamp(target.z + (-Math.sin(yaw)*horizontal-Math.cos(yaw)*forward)*14,-165,165);
    update();
  }
  function zoomBy(steps) {
    if(!Number.isFinite(steps)) return;
    zoom = clamp(zoom * Math.exp(clamp(steps,-10,10)*.12), .55, 2.8);
    update();
  }
  function reset() { target.set(130,108,0); yaw=.48; pitch=.46; zoom=1; update(); }
  update();
  return { world, orbit, move, zoomBy, reset, resize(value) { aspect=value; update(); } };
}
