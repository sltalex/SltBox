from pathlib import Path
import json, hashlib, math, random, shutil
root = Path(r'D:\work\C#\deskbox')
town = root/'src/DeskBox/Assets/Tree/TownPrototype'
server = root/'server/deskbox-api/config/tree-decorations.json'
items = json.loads(server.read_text(encoding='utf-8'))['items'][:50]
# preserve current 50 exactly in order
assert len(items)==50
cats = {
    'tree':['lanterns','ribbons_signs','wind_chime','bird_home','celestial','carving_masks','hanging_garden'],
    'ground':['flora','stones','containers','lighting','paths','signs','water_features','furniture','garden'],
    'large':['seating','water_features','building','furniture','pathways','pavilion']
}
# realistic names per category/subcategory for deterministic, unique generated entries
prefixes = {
 'flora':['月桂','紫藤','薄荷','铃兰','雏菊','绣球','金盏','芍药','蕨叶','芦苇','竹影','向日葵','蓝莓','野蔷薇','风铃草','银叶','星辰','苔藓','蒲公英','石楠'],
 'lighting':['萤火','铜灯','纸罩','月影','河畔','松果','玻璃','暖枝','星芒','石芯'],
 'furniture':['橡木','藤编','溪石','松木','花园','露台','谷仓','枫叶','竹制','草编'],
 'building':['谷仓','茶屋','木工坊','玻璃房','果酱屋','观景亭','林间屋','石屋','花房','钟楼'],
 'paths':['鹅卵石','木桩','落叶','砂砾','青砖','树根','苔痕','花砖','溪石','白石'],
 'pathways':['林间','河岸','春泥','青苔','月光','花径','枫影','杉木','碎石','水纹'],
 'water_features':['涟漪','莲叶','小溪','雨滴','浮萍','水车','荷塘','溪湾','浅滩','瀑布'],
 'seating':['长椅','秋千','凉亭','石凳','躺椅','木台','茶席','藤椅','观景台','树桩'],
 'containers':['陶罐','木箱','竹篮','石盆','花瓶','水缸','果筐','苔盆','藤篓','茶桶'],
 'stones':['溪石','山石','鹅卵','青苔石','花岗岩','白石','红砂岩','玄武岩','河滩石','垒石'],
 'signs':['木牌','路标','公告栏','方向牌','欢迎牌','小旗','留言板','地图牌','门牌','风向标'],
 'lanterns':['纸灯','彩灯','提灯','星灯','花灯','铜灯','竹灯','萤灯','月灯','穗灯'],
 'ribbons_signs':['彩带','祈愿签','布旗','纸签','木牌','风幡','花结','祝福牌','铃牌','小旗'],
 'wind_chime':['风铃','竹铃','贝铃','铜铃','花铃','雨铃','松铃','星铃','溪铃','玉铃'],
 'bird_home':['鸟屋','燕巢','鹊亭','鸽舍','雀台','木巢','藤巢','鸟笼','林巢','风巢'],
 'celestial':['星盘','月轮','流星','日晷','星轨','云灯','天球','银河','晨星','夜光'],
 'carving_masks':['树脸','木雕','精灵门','叶面具','林神像','根雕','鹿首','狐面','花纹柱','守林牌'],
 'hanging_garden':['藤帘','垂花','绿帘','空中花篮','吊兰','花瀑','蔓墙','叶幕','藤灯','果藤'],
 'garden':['花坛','绿篱','苗圃','果园','药草地','香草畦','种子台','花架','菜园','花窗'],
 'pavilion':['亭子','长廊','水榭','茶亭','观景阁','风雨亭','木廊','溪亭','花亭','小桥亭'],
}
# synthetic SVG generator (simple but structurally varied)
def svg_for(i, cat, sub, variant):
    # deterministic structural recipe from index
    rng=random.Random(i*7919+variant*101)
    w,h=32+(i%5)*8,32+((i//5)%5)*8
    bg=['#7a9d56','#a96b4b','#5f8e9e','#b08b54','#87639d','#4b9278','#c76b5c'][i%7]
    accent=['#e4c85b','#f5e6b3','#9ed1d0','#ed9f62','#d9a2c8','#d5e681','#d79a84'][i%7]
    cx=w//2; base=h-3
    shape=i%9
    body=[]
    if shape==0: body=[f'<path d="M2 {base} L{cx} 4 L{w-2} {base} Z" fill="{bg}"/>',f'<circle cx="{cx}" cy="{h//2}" r="{max(3,w//7)}" fill="{accent}"/>']
    elif shape==1: body=[f'<rect x="3" y="{h//3}" width="{w-6}" height="{h//2}" rx="{2+i%4}" fill="{bg}"/>',f'<path d="M3 {h//3} L{cx} 4 L{w-3} {h//3}" fill="{accent}"/>']
    elif shape==2: body=[f'<ellipse cx="{cx}" cy="{h//2}" rx="{w//3}" ry="{h//3}" fill="{bg}"/>',f'<path d="M{cx-2} 4 L{cx+4} {h-3}" stroke="{accent}" stroke-width="3"/>']
    elif shape==3: body=[f'<path d="M{cx} 2 C{cx-12} {h//3} 2 {h//3} 2 {base} H{w-2} C{w-2} {h//3} {cx+12} {h//3} {cx} 2" fill="{bg}"/>',f'<circle cx="{cx-6}" cy="{h//2}" r="3" fill="{accent}"/><circle cx="{cx+6}" cy="{h//2+3}" r="3" fill="{accent}"/>']
    elif shape==4: body=[f'<path d="M4 {base} H{w-4} L{w-7} 8 H7 Z" fill="{bg}"/>',f'<path d="M{cx} 8 V{base}" stroke="{accent}" stroke-width="3"/>']
    elif shape==5: body=[f'<path d="M{cx} 2 L{w-3} {h//2} L{cx} {base} L3 {h//2} Z" fill="{bg}"/>',f'<circle cx="{cx}" cy="{h//2}" r="5" fill="{accent}"/>']
    elif shape==6: body=[f'<path d="M2 {base} Q{cx} {h//4} {w-2} {base} V{h-9} Q{cx} {h//2} 2 {h-9} Z" fill="{bg}"/>',f'<path d="M6 {h-10} Q{cx} {h//2} {w-6} {h-10}" stroke="{accent}" stroke-width="3" fill="none"/>']
    elif shape==7: body=[f'<rect x="4" y="4" width="{w-8}" height="{h-8}" rx="{w//4}" fill="{bg}"/>',f'<path d="M{cx} 4 V{base} M4 {h//2} H{w-4}" stroke="{accent}" stroke-width="2"/>']
    else: body=[f'<path d="M{cx} 2 L{w-3} {h-7} L{cx+5} {base} L{cx-5} {base} L3 {h-7} Z" fill="{bg}"/>',f'<circle cx="{cx}" cy="{h//2}" r="{3+i%5}" fill="{accent}"/>']
    return '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><g stroke="#314b38" stroke-width="1.2" stroke-linejoin="round">%s</g></svg>'%(w,h,w,h,''.join(body))

# append generated ids, distribute 1450 among top categories, each subcategory in cycle
generated=[]
category_plan = ['tree'] * 492 + ['ground'] * 462 + ['large'] * 496
for n in range(1,1451):
    cat=category_plan[n-1]
    subs=cats[cat]; sub=subs[((n-1)//3)%len(subs)]
    # ensure target counts reasonably balanced; generated asset key stable
    iid=f'town-shared-{n:04d}'
    family=prefixes.get(sub,prefixes['flora'])
    # Keep a concrete Chinese object name while making the stable generated
    # entries unique even when two subcategories share a family prefix.
    name=f'{family[(n-1)%len(family)]}·{n:04d}'
    # The live decoration service supports the existing common/rare contract.
    # Keep generated entries within that contract so both catalog validators
    # accept the same 1500-item directory.
    generated.append({'id':iid,'name':name,'category':cat,'subcategory':sub,'rarity':['common','common','rare'][n%3],'asset_key':iid})
items2=[]
# preserve 50 metadata with explicit fields
for idx,it in enumerate(items):
    x=dict(it)
    # Keep the WebView page self-contained; parent-directory URLs are blocked
    # by the virtual-host mapping used by the packaged town window.
    x['asset']=f'sprites/original/{it["asset_key"]}.svg'
    x['blur_asset']=f'sprites/original/{it["asset_key"]}.svg'
    x['display_width']=max(16,32+(idx%4)*16)
    x['display_height']=max(24,32+(idx%5)*16)
    x['anchor']={'x':0.5,'y':1}
    x['footprint']={'width':1+(idx%3==0),'height':1+(idx%4==0)}
    x['collision']={'x':0.15,'y':0.55,'width':0.7,'height':0.4}
    x['chunk_radius']=1
    items2.append(x)
for n,it in enumerate(generated,1):
    cat,it_sub=it['category'],it['subcategory']
    w=16+(n%4)*8; h=24+((n//4)%5)*8
    if cat=='large': w+=16; h+=16
    it.update({'asset':f'sprites/generated/{it["id"]}.svg','blur_asset':f'sprites/generated/{it["id"]}.svg','display_width':w,'display_height':h,'anchor':{'x':0.5,'y':1},'footprint':{'width':1+(n%4==0)+(cat=='large'),'height':1+(n%5==0)+(cat=='large')},'collision':{'x':0.12,'y':0.5,'width':0.76,'height':0.42},'chunk_radius':1})
    items2.append(it)
# fix bool footprint values to ints
for it in items2:
    it['footprint']['width']=int(it['footprint']['width']); it['footprint']['height']=int(it['footprint']['height'])
catalog={'version':4,'catalog_version':4,'tile_size':16,'map_width':192,'map_height':192,'chunk_size':24,'world_id':'main','world_version':'town-world-v2','terrain_version':2,'chunks_x':8,'chunks_y':8,'world':{'width_tiles':192,'height_tiles':192,'chunk_size':24,'center_square':{'x':84,'y':84,'width':24,'height':24},'spawn':{'x':93,'y':97,'width':6,'height':6},'main_tree':{'x':94,'y':88,'width':4,'height':4},'river':{'x':64,'y':0,'width':12,'height':192},'bridge':{'x':61,'y':88,'width':18,'height':12},'forest':{'x':136,'y':18,'width':45,'height':70}},'terrain':{'grass':'sprites/grass.png','path':'sprites/path.png','water':'sprites/water.png'},'player':{'asset':'sprites/player.png','frame_width':16,'frame_height':32,'frames':{'down':[0,1,2,3],'right':[4,5,6,7],'up':[8,9,10,11],'left':[12,13,14,15]}},'tree_stages':[{'asset':f'sprites/tree-stage-{i}.png','width':16 if i<4 else 48,'height':[12,12,15,31,84][i-1]} for i in range(1,6)],'categories':['tree','ground','large'],'items':items2}
# write
(town/'catalog.json').write_text(json.dumps(catalog,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
# default shared example with 12 placements using preserved 50 ids
placements=[]
for k,it in enumerate(items[:12]):
    placements.append({'object_id':f'demo-{k+1}','instance_id':f'demo-{k+1}','asset_id':it['id'],'decoration_id':it['id'],'owner_id':'demo','tile_x':76+(k%4)*4,'tile_y':74+(k//4)*5,'object_version':1,'owner_display':'示例玩家'})
default={'template_version':2,'catalog_version':4,'world_id':'main','demo_stage':5,'placements':placements,'objects':placements}
(town/'default-layout.json').write_text(json.dumps(default,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
# generate SVGs
out=town/'sprites'/'generated'; out.mkdir(exist_ok=True)
original=town/'sprites'/'original'; original.mkdir(exist_ok=True)
for item in items:
    source_file=root/'src/DeskBox/Assets/Tree/Decorations/Source'/(item['asset_key']+'.svg')
    shutil.copyfile(source_file, original/(item['asset_key']+'.svg'))
for n,it in enumerate(generated,1):
    p=out/(it['id']+'.svg')
    if not p.exists(): p.write_text(svg_for(n,it['category'],it['subcategory'],n%20),encoding='utf-8')
# Keep the server directory and the desktop decoration catalog byte-for-byte
# aligned with the generated metadata.  The town page uses its richer local
# catalog, while the regular tree UI consumes the compact fields below.
core_items=[]
for item in items2:
    core_items.append({key: item[key] for key in ('id','name','category','subcategory','rarity','asset_key','footprint','anchor','collision','chunk_radius') if key in item})
core_document={'catalog_version':4,'items':core_items}
for path in (server, root/'src/DeskBox/Assets/Tree/Decorations/catalog.json'):
    path.write_text(json.dumps(core_document, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
source=root/'src/DeskBox/Assets/Tree/Decorations/Source'; blur=root/'src/DeskBox/Assets/Tree/Decorations/Blur'
for item in generated:
    src=out/(item['asset_key']+'.svg')
    shutil.copyfile(src, source/(item['asset_key']+'.svg'))
    shutil.copyfile(src, blur/(item['asset_key']+'.svg'))
print('catalog items',len(items2),'generated',len(generated),'hash',hashlib.sha256((town/'catalog.json').read_bytes()).hexdigest())
