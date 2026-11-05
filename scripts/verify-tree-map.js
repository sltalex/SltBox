// Run against the local Tree/ThreeD page with playwright-cli run-code --filename.
async page => {
  const errors = [], requests = [];
  page.on('pageerror', error => errors.push(String(error)));
  page.on('request', request => requests.push(request.url()));
  await page.addInitScript(() => {
    window.treeMessages = [];
    window.chrome = window.chrome || {};
    window.chrome.webview = { postMessage(message) {
      if (message.type === 'frame') {
        window.treeFrame = message.png;
        window.treeFrames = (window.treeFrames || 0) + 1;
        setTimeout(() => window.DeskBoxTree3D.acknowledgeFrame(), 0);
      } else window.treeMessages.push(message);
    }};
  });
  await page.reload();
  await page.waitForFunction(() => window.treeMessages.some(m => m.type === 'ready'));
  await page.setViewportSize({ width: 800, height: 600 });
  const state = { stage: 5, animationEnabled: false, weather: 'Clear', isDay: true,
    fruits: [{index: 0, pendingId: '99', asset: 'Fruits/Source/fruit_r01_c01.png', x: 100, y: 130}],
    wildlife: { encounterId: 'map-bird', asset: 'Wildlife/Source/swallow.png',
      x: 220, y: 40, visualSize: 36, hitSize: 48, seconds: 10,
      frames: [{at: 0,x:0,y:0,rotation:0,scaleX:-1,scaleY:1,opacity:1}], fliesAcrossSky: true },
    decorations: [{category:'ground',asset:'Decorations/Source/mushroom-cluster.svg',size:45,x:.3,y:.88,scale:1,rotation:0,layer:0}] };
  await page.evaluate(value => {
    window.DeskBoxTree3D.setSceneState(value);
    window.DeskBoxTree3D.setVisibility(true);
  }, state);
  await page.waitForTimeout(500);
  const picture = () => page.screenshot({ omitBackground: true });
  const baseline = await picture();
  await page.screenshot({path:'output/playwright/tree-map-overview.png',omitBackground:true});
  await page.mouse.move(400,300);
  await page.mouse.wheel(0,-240); await page.waitForTimeout(150);
  if(baseline.equals(await picture())) throw new Error('Wheel up did not zoom in');
  await page.mouse.wheel(0,240); await page.waitForTimeout(150);
  if(!baseline.equals(await picture())) throw new Error('Wheel down did not restore zoom');
  for(const direction of [-1,1]) {
    for(let i=0;i<3;i++) await page.evaluate(value=>window.DeskBoxTree3D.zoomBy(value),direction*10);
    await page.waitForTimeout(100);
    const limit=await picture();
    await page.evaluate(value=>window.DeskBoxTree3D.zoomBy(value),direction*10);
    await page.waitForTimeout(100);
    if(!limit.equals(await picture())) throw new Error('Zoom limit failed');
  }
  await page.evaluate(()=>window.DeskBoxTree3D.resetView()); await page.waitForTimeout(100);
  if(!baseline.equals(await picture())) throw new Error('Home did not restore zoom');
  // Real browser drag must change the camera without harvesting or capturing.
  await page.mouse.move(400,300); await page.mouse.down();
  await page.mouse.move(560,335,{steps:8}); await page.mouse.up();
  await page.waitForTimeout(150);
  if (baseline.equals(await picture())) throw new Error('Mouse orbit did not change the map');
  if (await page.evaluate(()=>window.treeMessages.some(m=>m.type.endsWith('Tapped')))) throw new Error('Drag triggered a tree action');
  await page.screenshot({path:'output/playwright/tree-map-rotated.png',omitBackground:true});
  for (const direction of ['ArrowUp','ArrowDown','ArrowLeft','ArrowRight']) {
    const before = await picture();
    await page.keyboard.press(direction); await page.waitForTimeout(100);
    if (before.equals(await picture())) throw new Error(direction+' did not move the view');
  }
  await page.keyboard.press('Home'); await page.waitForTimeout(100);
  if (!baseline.equals(await picture())) throw new Error('Home did not restore the original view');
  // Native forwarding uses the same camera operations with animations disabled.
  await page.evaluate(()=>{window.DeskBoxTree3D.orbitBy(.2,.1);window.DeskBoxTree3D.moveView(1,1);});
  await page.waitForTimeout(100);
  if (baseline.equals(await picture())) throw new Error('Native view forwarding did not redraw');
  await page.evaluate(()=>window.DeskBoxTree3D.resetView());
  await page.waitForTimeout(100);
  // ResizeObserver must redraw without resetting the user's camera or zoom.
  await page.evaluate(()=>{window.DeskBoxTree3D.orbitBy(.13,.05);window.DeskBoxTree3D.moveView(1,1);window.DeskBoxTree3D.zoomBy(2);});
  await page.waitForTimeout(100);
  const cameraBeforeResize=await picture();
  for(const [width,height] of [[800,240],[800,900],[240,700],[900,180],[800,600]]) {
    await page.setViewportSize({width,height}); await page.waitForTimeout(100);
    const size=await page.evaluate(()=>{
      const canvas=document.querySelector('canvas');
      return {width:canvas.clientWidth,height:canvas.clientHeight};
    });
    if(size.width!==width||size.height!==height) throw new Error('Canvas did not fill resized viewport');
  }
  if(!cameraBeforeResize.equals(await picture())) throw new Error('Resize reset the camera or zoom');
  await page.evaluate(()=>window.DeskBoxTree3D.resetView()); await page.waitForTimeout(100);
  // Scan front/side views through the production hit-test. Back views now
  // correctly occlude fruit; verify-tree-anchors covers those hidden targets.
  for (const angle of [0,.12,-.12]) {
    await page.evaluate(value=>{window.DeskBoxTree3D.resetView();window.DeskBoxTree3D.orbitBy(value,0);},angle);
    await page.waitForTimeout(100);
    const hits = await page.evaluate(()=>{
      window.treeMessages=[];
      for(let y=.05;y<.95;y+=.0125) for(let x=.05;x<.95;x+=.0125) window.DeskBoxTree3D.tapAtNormalized(x,y);
      return window.treeMessages;
    });
    if(!hits.some(m=>m.type==='fruitTapped'&&m.pendingId==='99')) throw new Error('Fruit missed after rotation '+angle);
    if(!hits.some(m=>m.type==='wildlifeTapped'&&m.encounterId==='map-bird')) throw new Error('Bird missed after rotation '+angle);
  }
  await page.evaluate(()=>window.DeskBoxTree3D.resetView());
  for(let stage=1;stage<=5;stage++) {
    await page.evaluate(value=>window.DeskBoxTree3D.setSceneState(value),{...state,stage,fruits:stage===5?state.fruits:[]});
    await page.waitForTimeout(100);
    await page.screenshot({path:`output/playwright/tree-map-stage-${stage}.png`,omitBackground:true});
  }
  for(const weather of ['Cloudy','Fog','Drizzle','Rain','Snow','Thunderstorm']) {
    await page.evaluate(value=>window.DeskBoxTree3D.setSceneState(value),{...state,weather});
    await page.waitForTimeout(50);
  }
  await page.evaluate(value=>window.DeskBoxTree3D.setSceneState(value),state);
  await page.setViewportSize({width:300,height:130});
  await page.evaluate(()=>window.DeskBoxTree3D.enableFrameTransport());
  await page.waitForFunction(()=>window.treeFrame);
  const alpha = await page.evaluate(async()=>{
    const image=new Image();image.src=window.treeFrame;await image.decode();
    const canvas=document.createElement('canvas');canvas.width=image.naturalWidth;canvas.height=image.naturalHeight;
    const ctx=canvas.getContext('2d');ctx.fillStyle='#00c4a7';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(image,0,0);
    return Array.from(ctx.getImageData(0,0,1,1).data);
  });
  if(alpha.join(',')!=='0,196,167,255') throw new Error('Map covered the custom background: '+alpha);
  await page.screenshot({path:'output/playwright/tree-map-tile.png',omitBackground:true});
  await page.evaluate(()=>window.DeskBoxTree3D.setAnimationEnabled(true));
  const frames=await page.evaluate(()=>window.treeFrames);await page.waitForTimeout(300);
  if((await page.evaluate(()=>window.treeFrames))<=frames) throw new Error('Animation transport froze');
  await page.evaluate(()=>window.DeskBoxTree3D.setVisibility(false));
  await page.waitForTimeout(100);
  const hidden=await page.evaluate(()=>window.treeFrames);await page.waitForTimeout(200);
  if((await page.evaluate(()=>window.treeFrames))!==hidden) throw new Error('Hidden map kept rendering');
  await page.evaluate(()=>window.DeskBoxTree3D.setVisibility(true));await page.waitForTimeout(150);
  if((await page.evaluate(()=>window.treeFrames))<=hidden) throw new Error('Map failed to resume');
  const runtimeErrors=await page.evaluate(()=>window.treeMessages.filter(m=>m.type==='error'));
  if(errors.length||runtimeErrors.length) throw new Error(JSON.stringify({errors,runtimeErrors}));
  if(requests.some(url=>!url.startsWith('http://127.0.0.1:8765/'))) throw new Error('External asset request');
  return { stages:5, weatherModes:7, mouseOrbit:true, arrowKeys:4, homeReset:true,
    rotatedHitTests:3, resizePreservesCamera:true, wheelZoom:true, zoomLimits:true, transparentCorner:alpha, hiddenStopped:true, resumed:true, errors };
}
