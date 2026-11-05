// Run with playwright-cli run-code --filename against the local Tree/ThreeD page.
async page => {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error)));
  await page.route('**/ThreeD/tree3d.js', async route => {
    const response = await route.fetch();
    await route.fulfill({ response, body: (await response.text()) +
      '\nwindow.__anchors = { THREE, scene, camera, crown, fruits, decorations, treeDecorations, trunkDecorations, tree, map, hitTest };' });
  });
  await page.addInitScript(() => {
    window.treeMessages = [];
    window.chrome = { webview: { postMessage: message => window.treeMessages.push(message) } };
  });
  await page.reload();
  await page.waitForFunction(() => window.treeMessages.some(message => message.type === 'ready'));
  await page.setViewportSize({ width: 800, height: 600 });
  const state = { stage: 5, animationEnabled: false, weather: null, isDay: true,
    fruits: [{ index: 0, pendingId: '99', asset: 'Fruits/Source/fruit_r01_c01.png', x: 205, y: 72 }],
    decorations: [
      { category: 'tree', asset: 'Decorations/Source/red-lantern.svg', size: 45, x: .74, y: .32, scale: 1, rotation: 0, layer: 0 },
      { category: 'ground', asset: 'Decorations/Source/mushroom-cluster.svg', size: 45, x: .3, y: .88, scale: 1, rotation: 25, layer: 0 },
      { category: 'tree', asset: 'Decorations/Source/tree-door.svg', size: 30, x: .5, y: .85, scale: 1, rotation: 0, layer: 0 }
    ] };
  await page.evaluate(value => {
    window.DeskBoxTree3D.setSceneState(value);
    window.DeskBoxTree3D.setVisibility(true);
  }, state);
  await page.waitForTimeout(300);
  const metrics = await page.evaluate(() => {
    const { THREE, scene, crown, fruits, decorations, treeDecorations, camera } = window.__anchors;
    scene.updateMatrixWorld(true);
    const foliage = crown.children.filter(object => object.isMesh);
    function distanceToCrown(object) {
      const point = object.getWorldPosition(new THREE.Vector3());
      let distance = Infinity;
      const triangle = new THREE.Triangle(), closest = new THREE.Vector3();
      for (const leaf of foliage) {
        const geometry = leaf.geometry.index ? leaf.geometry.toNonIndexed() : leaf.geometry;
        const vertices = geometry.attributes.position;
        for (let index = 0; index < vertices.count; index += 3) {
          triangle.a.fromBufferAttribute(vertices, index).applyMatrix4(leaf.matrixWorld);
          triangle.b.fromBufferAttribute(vertices, index + 1).applyMatrix4(leaf.matrixWorld);
          triangle.c.fromBufferAttribute(vertices, index + 2).applyMatrix4(leaf.matrixWorld);
          triangle.closestPointToPoint(point, closest);
          distance = Math.min(distance, point.distanceTo(closest));
        }
        if (geometry !== leaf.geometry) geometry.dispose();
      }
      return distance;
    }
    const fruit = fruits.children[0], ornament = treeDecorations.children[0];
    return { fruitDistance: distanceToCrown(fruit), decorationDistance: distanceToCrown(ornament),
      fruitDepthTest: fruit.material.depthTest, decorationDepthTest: ornament.material.depthTest };
  });
  if (metrics.fruitDistance > 8 || metrics.decorationDistance > 8 ||
      !metrics.fruitDepthTest || !metrics.decorationDepthTest)
    throw new Error('Tree attachments float away from canopy: ' + JSON.stringify(metrics));
  const views = await page.evaluate(async () => {
    const { THREE, scene, camera, crown, fruits, decorations, treeDecorations, trunkDecorations, tree, map, hitTest } = window.__anchors;
    const fruit = fruits.children[0], ornament = treeDecorations.children[0];
    if (ornament.parent !== treeDecorations || treeDecorations.parent !== crown)
      throw new Error('Tree ornaments do not share crown sway');
    if (trunkDecorations.children.length !== 1 || trunkDecorations.parent !== tree)
      throw new Error('Trunk ornament is not attached to the trunk');
    const trunkPoint = trunkDecorations.children[0].getWorldPosition(new THREE.Vector3());
    crown.rotation.z = .025;
    scene.updateMatrixWorld(true);
    if (trunkPoint.distanceTo(trunkDecorations.children[0].getWorldPosition(new THREE.Vector3())) > .0001)
      throw new Error('Crown sway moved a trunk ornament');
    crown.rotation.z = 0;
    scene.updateMatrixWorld(true);
    const fruitPoint = fruit.getWorldPosition(new THREE.Vector3());
    const ornamentPoint = ornament.getWorldPosition(new THREE.Vector3());
    let checked = 0, occluded = 0, visibleHits = 0;
    for (const pitch of [-1, 0, 1]) for (let angle = 0; angle < 12; angle++) {
      window.DeskBoxTree3D.resetView();
      window.DeskBoxTree3D.orbitBy(angle / 12, pitch);
      window.DeskBoxTree3D.moveView(1, -1);
      window.DeskBoxTree3D.zoomBy(1);
      await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      scene.updateMatrixWorld(true);
      if (fruitPoint.distanceTo(fruit.getWorldPosition(new THREE.Vector3())) > .0001 ||
          ornamentPoint.distanceTo(ornament.getWorldPosition(new THREE.Vector3())) > .0001)
        throw new Error('Camera changed a tree attachment');
      for (const object of decorations.children) {
        const vertices = object.geometry.attributes.position;
        let bottom = Infinity;
        for (let i = 0; i < vertices.count; i++)
          bottom = Math.min(bottom, new THREE.Vector3().fromBufferAttribute(vertices, i).applyMatrix4(object.matrixWorld).y);
        if (Math.abs(bottom - 16) > .0001) throw new Error('Ground decoration floated: ' + bottom);
      }
      const projected = fruitPoint.clone().project(camera);
      const rect = document.querySelector('canvas').getBoundingClientRect();
      const ray = new THREE.Raycaster();
      ray.setFromCamera(new THREE.Vector2(projected.x, projected.y), camera);
      const blockers = ray.intersectObjects([...tree.children, ...crown.children].filter(node => node.isMesh));
      const hidden = blockers.length && blockers[0].distance < ray.ray.origin.distanceTo(fruitPoint) - .1;
      const hit = hitTest({ clientX: rect.left + (projected.x + 1) * rect.width / 2,
        clientY: rect.top + (1 - projected.y) * rect.height / 2 });
      if (hidden) {
        occluded++;
        if (hit?.type === 'fruit') throw new Error('Clicked a fruit through the tree');
      } else if (hit?.pendingId === '99') visibleHits++;
      checked++;
    }
    if (!occluded || !visibleHits) throw new Error('Camera coverage missed hidden/visible fruit cases');
    return { checked, occluded, visibleHits };
  });
  await page.evaluate(() => window.DeskBoxTree3D.resetView());
  for (let stage = 1; stage <= 5; stage++) {
    await page.evaluate(value => window.DeskBoxTree3D.setSceneState(value), { ...state, stage });
    await page.waitForTimeout(70);
    const counts = await page.evaluate(() => ({ fruits: window.__anchors.fruits.children.length,
      tree: window.__anchors.treeDecorations.children.length + window.__anchors.trunkDecorations.children.length,
      ground: window.__anchors.decorations.children.length }));
    if (counts.fruits !== 1 || counts.tree !== 2 || counts.ground !== 1)
      throw new Error('Stage change lost an attachment: ' + JSON.stringify(counts));
  }
  for (const [name, yaw, pitch] of [['front', 0, 0], ['side', .25, 0], ['back', .5, 0], ['overhead', 0, 1]]) {
    await page.evaluate(([yaw, pitch]) => {
      window.DeskBoxTree3D.resetView(); window.DeskBoxTree3D.orbitBy(yaw, pitch);
    }, [yaw, pitch]);
    await page.waitForTimeout(100);
    await page.screenshot({ path: `output/playwright/tree-anchors-${name}.png`, omitBackground: true });
  }
  const runtimeErrors = await page.evaluate(() => window.treeMessages.filter(message => message.type === 'error'));
  if (errors.length || runtimeErrors.length) throw new Error(JSON.stringify({ errors, runtimeErrors }));
  return { ...metrics, ...views, stages: 5, errors };
}
