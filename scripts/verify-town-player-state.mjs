import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

// Exercise the actual WebView bridge handlers with rendering stubs. Phaser
// physics and collision geometry are covered separately by verify-town-prototype.
const base=new URL('../src/DeskBox/Assets/Tree/TownPrototype/',import.meta.url);
const rulesCode=await readFile(new URL('town-rules.js',base),'utf8');
const rules=await import(`data:text/javascript;base64,${Buffer.from(rulesCode).toString('base64')}`);
const catalog=JSON.parse(await readFile(new URL('catalog.json',base),'utf8'));
const messages=[],elements=new Map();
const element=id=>{
  if(!elements.has(id))elements.set(id,{textContent:'',hidden:false,disabled:false,classList:{add(){},remove(){},toggle(){}}});
  return elements.get(id);
};
const context=vm.createContext({...rules,console,Map,Set,JSON,Math,Date,
  document:{hidden:false,activeElement:null,getElementById:element},
  window:{matchMedia:()=>({matches:false}),Phaser:{Scene:class{}},
    chrome:{webview:{postMessage:message=>messages.push(message)}}}});
const code=(await readFile(new URL('town.js',base),'utf8'))
  .replace(/^import .*?;\r?\n/,'')
  .replace('boot().catch(reportError);','');
vm.runInContext(code,context);
const state=vm.runInContext('state',context);
state.catalog=catalog;state.layout={placements:[]};
vm.runInContext('renderMaterials=()=>{};updateUI=()=>{};',context);
const spawns=[];
state.scene={player:{x:0,y:0,setVisible(){},anims:{stop(){}}},playerShadow:{setVisible(){}},
  cancelDrag(){},renderObjects(){},renderPresence(){},
  cameras:{main:{setBounds(x,y,width,height){state.bounds={width,height};}}},
  spawnPlayer(preferred){
    spawns.push(preferred);
    const point=rules.findStandingPosition(catalog,state.layout,preferred);
    Object.assign(this.player,point);state.playerReady=true;
  }};
const bridge=context.window.DeskBoxTownPrototype;
const world=(epoch,player)=>({session_epoch:epoch,enabled:true,authenticated:true,can_save:true,
  account_id:epoch===10?'account-a':'account-b',map_width:768,map_height:768,tile_size:16,chunk_size:24,
  player,layout:{template_version:2,catalog_version:4,demo_stage:5,placements:[]}});
const saved={pixel_x:9608.25,pixel_y:9615.5,tile_x:600,tile_y:600};
bridge.loadWorld(world(10,saved));
assert.equal(spawns.length,1);
assert.equal(state.scene.player.x,saved.pixel_x);
assert.equal(state.bounds.width,12288);
assert.equal(bridge.playerPosition(),null,'Do not save before authoritative objects load.');
assert(messages.some(message=>message.type==='viewportChanged'&&message.chunks.some(c=>c.chunk_x===25&&c.chunk_y===25)));
bridge.loadChunks({session_epoch:10,chunks:[{chunk_x:25,chunk_y:25,objects:[]}]});
assert.equal(bridge.playerPosition().pixel_x,saved.pixel_x);
state.scene.player.x=9624.5;
vm.runInContext('sendPlayerPresence(true)',context);
const last=messages.filter(message=>message.type==='presenceHeartbeat').at(-1);
assert.equal(last.payload.pixel_x,9624.5);assert.equal(last.payload.tile_x,601);
bridge.loadWorld(world(10,saved));
assert.equal(spawns.length,1,'Refreshing the world must not respawn a moving player.');
assert.equal(state.scene.player.x,9624.5);
const other={pixel_x:648,pixel_y:655,tile_x:40,tile_y:40};
bridge.loadWorld(world(11,other));
assert.equal(spawns.length,2);assert.equal(state.scene.player.x,648);
bridge.loadChunks({session_epoch:10,chunks:[{chunk_x:25,chunk_y:25,objects:[]}]});
assert.equal(bridge.playerPosition(),null,'A previous account response must not finish the new spawn.');
bridge.loadChunks({session_epoch:11,chunks:[{chunk_x:1,chunk_y:1,objects:[]}]});
assert.equal(bridge.playerPosition().pixel_x,648);
bridge.loadWorld(world(10,saved));
assert.equal(state.scene.player.x,648,'A stale world response must not move the current account.');
console.log('Shared town bridge checks passed: restore, expanded viewport, exact heartbeat, refresh, account switch, stale responses');
