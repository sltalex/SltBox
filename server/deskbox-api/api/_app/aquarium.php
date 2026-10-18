<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const AQUARIUM_CATALOG_VERSION = 4;
const AQUARIUM_INITIAL_COINS = 1000;

function aquarium_catalog(): array
{
    return ['catalog_version' => AQUARIUM_CATALOG_VERSION, 'items' => [
        ['id' => 'clownfish', 'name' => '小丑鱼', 'category' => 'fish', 'price' => 100, 'asset_key' => 'clownfish'],
        ['id' => 'blue-tang', 'name' => '蓝吊鱼', 'category' => 'fish', 'price' => 180, 'asset_key' => 'blue-tang'],
        ['id' => 'butterflyfish', 'name' => '蝴蝶鱼', 'category' => 'fish', 'price' => 260, 'asset_key' => 'butterflyfish'],
        ['id' => 'angelfish', 'name' => '神仙鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'angelfish'],
        ['id' => 'lionfish', 'name' => '狮子鱼', 'category' => 'fish', 'price' => 300, 'asset_key' => 'lionfish'],
        ['id' => 'pufferfish', 'name' => '河豚', 'category' => 'fish', 'price' => 240, 'asset_key' => 'pufferfish'],
        ['id' => 'seahorse', 'name' => '海马', 'category' => 'fish', 'price' => 280, 'asset_key' => 'seahorse'],
        ['id' => 'yellow-tang', 'name' => '黄吊鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'yellow-tang'],
        ['id' => 'royal-gramma', 'name' => '皇家伽玛', 'category' => 'fish', 'price' => 260, 'asset_key' => 'royal-gramma'],
        ['id' => 'mandarinfish', 'name' => '曼达林鱼', 'category' => 'fish', 'price' => 320, 'asset_key' => 'mandarinfish'],
        ['id' => 'moorish-idol', 'name' => '镰鱼', 'category' => 'fish', 'price' => 340, 'asset_key' => 'moorish-idol'],
        ['id' => 'cardinalfish', 'name' => '天竺鲷', 'category' => 'fish', 'price' => 190, 'asset_key' => 'cardinalfish'],
        ['id' => 'great-white-shark', 'name' => '大白鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'great-white-shark'],
        ['id' => 'whale-shark', 'name' => '鲸鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'whale-shark'],
        ['id' => 'scalloped-hammerhead', 'name' => '路氏双髻鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'scalloped-hammerhead'],
        ['id' => 'blacktip-reef-shark', 'name' => '黑鳍礁鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'blacktip-reef-shark'],
        ['id' => 'zebra-shark', 'name' => '斑马鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'zebra-shark'],
        ['id' => 'sardine', 'name' => '沙丁鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'sardine'],
        ['id' => 'anchovy', 'name' => '鳀鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'anchovy'],
        ['id' => 'herring', 'name' => '大西洋鲱鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'herring'],
        ['id' => 'atlantic-mackerel', 'name' => '大西洋鲭鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'atlantic-mackerel'],
        ['id' => 'yellowfin-tuna', 'name' => '黄鳍金枪鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'yellowfin-tuna'],
        ['id' => 'silver-pomfret', 'name' => '银鲳', 'category' => 'fish', 'price' => 280, 'asset_key' => 'silver-pomfret'],
        ['id' => 'milkfish', 'name' => '虱目鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'milkfish'],
        ['id' => 'giant-trevally', 'name' => '浪人鲹', 'category' => 'fish', 'price' => 600, 'asset_key' => 'giant-trevally'],
        ['id' => 'barracuda', 'name' => '大魣', 'category' => 'fish', 'price' => 600, 'asset_key' => 'barracuda'],
        ['id' => 'ocean-sunfish', 'name' => '翻车鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'ocean-sunfish'],
        ['id' => 'manta-ray', 'name' => '双吻前口蝠鲼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'manta-ray'],
        ['id' => 'eagle-ray', 'name' => '斑点鹰魟', 'category' => 'fish', 'price' => 600, 'asset_key' => 'eagle-ray'],
        ['id' => 'blue-spotted-stingray', 'name' => '蓝斑条尾魟', 'category' => 'fish', 'price' => 280, 'asset_key' => 'blue-spotted-stingray'],
        ['id' => 'redtail-catfish', 'name' => '红尾鲶', 'category' => 'fish', 'price' => 600, 'asset_key' => 'redtail-catfish'],
        ['id' => 'arowana', 'name' => '银龙鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'arowana'],
        ['id' => 'discus', 'name' => '七彩神仙鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'discus'],
        ['id' => 'goldfish', 'name' => '金鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'goldfish'],
        ['id' => 'koi', 'name' => '锦鲤', 'category' => 'fish', 'price' => 600, 'asset_key' => 'koi'],
        ['id' => 'guppy', 'name' => '孔雀鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'guppy'],
        ['id' => 'neon-tetra', 'name' => '霓虹灯鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'neon-tetra'],
        ['id' => 'zebra-danio', 'name' => '斑马鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'zebra-danio'],
        ['id' => 'siamese-fighting-fish', 'name' => '暹罗斗鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'siamese-fighting-fish'],
        ['id' => 'banggai-cardinalfish', 'name' => '邦盖天竺鲷', 'category' => 'fish', 'price' => 140, 'asset_key' => 'banggai-cardinalfish'],
        ['id' => 'firefish', 'name' => '火焰虾虎鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'firefish'],
        ['id' => 'sixline-wrasse', 'name' => '六线龙', 'category' => 'fish', 'price' => 140, 'asset_key' => 'sixline-wrasse'],
        ['id' => 'fairy-wrasse', 'name' => '太阳仙女隆头鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'fairy-wrasse'],
        ['id' => 'flame-angelfish', 'name' => '火焰神仙鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'flame-angelfish'],
        ['id' => 'coral-beauty', 'name' => '珊瑚美人神仙鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'coral-beauty'],
        ['id' => 'emperor-angelfish', 'name' => '皇帝神仙鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'emperor-angelfish'],
        ['id' => 'foxface-rabbitfish', 'name' => '狐狸鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'foxface-rabbitfish'],
        ['id' => 'sailfin-tang', 'name' => '帆鳍吊鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'sailfin-tang'],
        ['id' => 'naso-tang', 'name' => '橙棘吊鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'naso-tang'],
        ['id' => 'purple-tang', 'name' => '紫吊鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'purple-tang'],
        ['id' => 'seagrass', 'name' => '海草簇', 'category' => 'plant', 'price' => 60, 'asset_key' => 'seagrass'],
        ['id' => 'fan-algae', 'name' => '扇形海藻', 'category' => 'plant', 'price' => 100, 'asset_key' => 'fan-algae'],
        ['id' => 'anubias', 'name' => '水榕', 'category' => 'plant', 'price' => 80, 'asset_key' => 'anubias'],
        ['id' => 'java-fern', 'name' => '爪哇蕨', 'category' => 'plant', 'price' => 90, 'asset_key' => 'java-fern'],
        ['id' => 'hornwort', 'name' => '金鱼藻', 'category' => 'plant', 'price' => 70, 'asset_key' => 'hornwort'],
        ['id' => 'amazon-sword', 'name' => '亚马逊剑', 'category' => 'plant', 'price' => 110, 'asset_key' => 'amazon-sword'],
        ['id' => 'vallisneria', 'name' => '苦草', 'category' => 'plant', 'price' => 85, 'asset_key' => 'vallisneria'],
        ['id' => 'java-moss', 'name' => '爪哇莫丝', 'category' => 'plant', 'price' => 100, 'asset_key' => 'java-moss'],
        ['id' => 'christmas-moss', 'name' => '圣诞莫丝', 'category' => 'plant', 'price' => 100, 'asset_key' => 'christmas-moss'],
        ['id' => 'fissidens-fontanus', 'name' => '凤凰莫丝', 'category' => 'plant', 'price' => 100, 'asset_key' => 'fissidens-fontanus'],
        ['id' => 'riccia', 'name' => '鹿角苔', 'category' => 'plant', 'price' => 100, 'asset_key' => 'riccia'],
        ['id' => 'duckweed', 'name' => '浮萍', 'category' => 'plant', 'price' => 100, 'asset_key' => 'duckweed'],
        ['id' => 'salvinia', 'name' => '槐叶萍', 'category' => 'plant', 'price' => 100, 'asset_key' => 'salvinia'],
        ['id' => 'frogbit', 'name' => '水鳖', 'category' => 'plant', 'price' => 100, 'asset_key' => 'frogbit'],
        ['id' => 'water-lettuce', 'name' => '大薸', 'category' => 'plant', 'price' => 100, 'asset_key' => 'water-lettuce'],
        ['id' => 'amazon-frogbit', 'name' => '南美水鳖', 'category' => 'plant', 'price' => 100, 'asset_key' => 'amazon-frogbit'],
        ['id' => 'cabomba', 'name' => '绿菊', 'category' => 'plant', 'price' => 100, 'asset_key' => 'cabomba'],
        ['id' => 'egeria-densa', 'name' => '水蕴草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'egeria-densa'],
        ['id' => 'elodea-canadensis', 'name' => '加拿大水蕴草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'elodea-canadensis'],
        ['id' => 'cryptocoryne-balansae', 'name' => '皱叶椒草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'cryptocoryne-balansae'],
        ['id' => 'cryptocoryne-wendtii', 'name' => '温蒂椒草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'cryptocoryne-wendtii'],
        ['id' => 'cryptocoryne-parva', 'name' => '迷你椒草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'cryptocoryne-parva'],
        ['id' => 'rotala-rotundifolia', 'name' => '圆叶节节菜', 'category' => 'plant', 'price' => 100, 'asset_key' => 'rotala-rotundifolia'],
        ['id' => 'rotala-macrandra', 'name' => '大红叶', 'category' => 'plant', 'price' => 100, 'asset_key' => 'rotala-macrandra'],
        ['id' => 'ludwigia-repens', 'name' => '匍匐柳叶菜', 'category' => 'plant', 'price' => 100, 'asset_key' => 'ludwigia-repens'],
        ['id' => 'ludwigia-arcuata', 'name' => '针叶柳', 'category' => 'plant', 'price' => 100, 'asset_key' => 'ludwigia-arcuata'],
        ['id' => 'hygrophila-polysperma', 'name' => '小水蓑衣', 'category' => 'plant', 'price' => 100, 'asset_key' => 'hygrophila-polysperma'],
        ['id' => 'hygrophila-corymbosa', 'name' => '大水蓑衣', 'category' => 'plant', 'price' => 100, 'asset_key' => 'hygrophila-corymbosa'],
        ['id' => 'bacopa-caroliniana', 'name' => '卡罗莱纳假马齿苋', 'category' => 'plant', 'price' => 100, 'asset_key' => 'bacopa-caroliniana'],
        ['id' => 'pearl-weed', 'name' => '珍珠草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'pearl-weed'],
        ['id' => 'dwarf-hairgrass', 'name' => '牛毛毡', 'category' => 'plant', 'price' => 100, 'asset_key' => 'dwarf-hairgrass'],
        ['id' => 'dwarf-sagittaria', 'name' => '矮慈姑', 'category' => 'plant', 'price' => 100, 'asset_key' => 'dwarf-sagittaria'],
        ['id' => 'micro-sword', 'name' => '巴西微剑', 'category' => 'plant', 'price' => 100, 'asset_key' => 'micro-sword'],
        ['id' => 'staurogyne-repens', 'name' => '匍匐叉柱花', 'category' => 'plant', 'price' => 100, 'asset_key' => 'staurogyne-repens'],
        ['id' => 'monte-carlo', 'name' => '蒙特卡罗珍珠草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'monte-carlo'],
        ['id' => 'tiger-lotus', 'name' => '红虎睡莲', 'category' => 'plant', 'price' => 100, 'asset_key' => 'tiger-lotus'],
        ['id' => 'water-lily', 'name' => '子午莲', 'category' => 'plant', 'price' => 100, 'asset_key' => 'water-lily'],
        ['id' => 'aponogeton-madagascariensis', 'name' => '网格草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'aponogeton-madagascariensis'],
        ['id' => 'banana-plant', 'name' => '香蕉草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'banana-plant'],
        ['id' => 'bolbitis', 'name' => '非洲水蕨', 'category' => 'plant', 'price' => 100, 'asset_key' => 'bolbitis'],
        ['id' => 'water-sprite', 'name' => '水蕨', 'category' => 'plant', 'price' => 100, 'asset_key' => 'water-sprite'],
        ['id' => 'pennywort', 'name' => '巴西天胡荽', 'category' => 'plant', 'price' => 100, 'asset_key' => 'pennywort'],
        ['id' => 'hydrocotyle-tripartita', 'name' => '三裂天胡荽', 'category' => 'plant', 'price' => 100, 'asset_key' => 'hydrocotyle-tripartita'],
        ['id' => 'myriophyllum-mattogrossense', 'name' => '马托格罗索狐尾藻', 'category' => 'plant', 'price' => 100, 'asset_key' => 'myriophyllum-mattogrossense'],
        ['id' => 'limnophila-sessiliflora', 'name' => '无柄花石龙尾', 'category' => 'plant', 'price' => 100, 'asset_key' => 'limnophila-sessiliflora'],
        ['id' => 'rotala-wallichii', 'name' => '红松尾', 'category' => 'plant', 'price' => 100, 'asset_key' => 'rotala-wallichii'],
        ['id' => 'alternanthera-reineckii', 'name' => '红丁香', 'category' => 'plant', 'price' => 100, 'asset_key' => 'alternanthera-reineckii'],
        ['id' => 'eriocaulon-cinereum', 'name' => '灰谷精草', 'category' => 'plant', 'price' => 100, 'asset_key' => 'eriocaulon-cinereum'],
        ['id' => 'bucephalandra-motleyana', 'name' => '摩氏辣椒榕', 'category' => 'plant', 'price' => 100, 'asset_key' => 'bucephalandra-motleyana'],
        ['id' => 'anubias-heterophylla', 'name' => '异叶水榕', 'category' => 'plant', 'price' => 100, 'asset_key' => 'anubias-heterophylla'],
        ['id' => 'cherry-shrimp', 'name' => '樱花虾', 'category' => 'shrimp', 'price' => 90, 'asset_key' => 'cherry-shrimp'],
        ['id' => 'amano-shrimp', 'name' => '大和藻虾', 'category' => 'shrimp', 'price' => 120, 'asset_key' => 'amano-shrimp'],
        ['id' => 'hermit-crab', 'name' => '寄居蟹', 'category' => 'crab', 'price' => 150, 'asset_key' => 'hermit-crab'],
        ['id' => 'fiddler-crab', 'name' => '招潮蟹', 'category' => 'crab', 'price' => 170, 'asset_key' => 'fiddler-crab'],
        ['id' => 'porcelain-crab', 'name' => '瓷蟹', 'category' => 'crab', 'price' => 210, 'asset_key' => 'porcelain-crab'],
        ['id' => 'emerald-crab', 'name' => '绿宝石蟹', 'category' => 'crab', 'price' => 230, 'asset_key' => 'emerald-crab'],
        ['id' => 'scallop', 'name' => '扇贝', 'category' => 'shellfish', 'price' => 80, 'asset_key' => 'scallop'],
        ['id' => 'oyster', 'name' => '牡蛎', 'category' => 'shellfish', 'price' => 70, 'asset_key' => 'oyster'],
        ['id' => 'reef-rock', 'name' => '礁石假山', 'category' => 'decoration', 'price' => 120, 'asset_key' => 'reef-rock'],
        ['id' => 'rock-arch', 'name' => '拱洞岩石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'rock-arch'],
        ['id' => 'granite-rock', 'name' => '花岗岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'granite-rock'],
        ['id' => 'slate-rock', 'name' => '板岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'slate-rock'],
        ['id' => 'lava-rock', 'name' => '火山岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'lava-rock'],
        ['id' => 'basalt-rock', 'name' => '玄武岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'basalt-rock'],
        ['id' => 'sandstone-rock', 'name' => '砂岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'sandstone-rock'],
        ['id' => 'limestone-rock', 'name' => '石灰岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'limestone-rock'],
        ['id' => 'dragon-stone', 'name' => '龙纹石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'dragon-stone'],
        ['id' => 'seiryu-stone', 'name' => '青龙石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'seiryu-stone'],
        ['id' => 'calcite-rock', 'name' => '方解石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'calcite-rock'],
        ['id' => 'pagoda-stone', 'name' => '宝塔石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'pagoda-stone'],
        ['id' => 'river-pebble', 'name' => '河卵石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'river-pebble'],
        ['id' => 'black-pebble', 'name' => '黑卵石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'black-pebble'],
        ['id' => 'white-pebble', 'name' => '白卵石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'white-pebble'],
        ['id' => 'red-lava-rock', 'name' => '红火山岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'red-lava-rock'],
        ['id' => 'coral-rock', 'name' => '珊瑚石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'coral-rock'],
        ['id' => 'reef-rubble', 'name' => '礁石碎块', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'reef-rubble'],
        ['id' => 'coral-arch', 'name' => '珊瑚拱门', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'coral-arch'],
        ['id' => 'cave-rock', 'name' => '洞穴岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'cave-rock'],
        ['id' => 'tunnel-rock', 'name' => '隧道岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'tunnel-rock'],
        ['id' => 'stacked-rock', 'name' => '堆叠岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'stacked-rock'],
        ['id' => 'mossy-rock', 'name' => '苔藓岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'mossy-rock'],
        ['id' => 'cliff-rock', 'name' => '断崖岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'cliff-rock'],
        ['id' => 'overhang-rock', 'name' => '悬挑岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'overhang-rock'],
        ['id' => 'spire-rock', 'name' => '石柱岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'spire-rock'],
        ['id' => 'boulder-cluster', 'name' => '巨石群', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'boulder-cluster'],
        ['id' => 'rock-plate', 'name' => '岩板', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'rock-plate'],
        ['id' => 'rock-shelf', 'name' => '岩棚', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'rock-shelf'],
        ['id' => 'rubble-pile', 'name' => '碎石堆', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'rubble-pile'],
        ['id' => 'canyon-rock', 'name' => '峡谷岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'canyon-rock'],
        ['id' => 'mesa-rock', 'name' => '台地岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'mesa-rock'],
        ['id' => 'cobblestone-rock', 'name' => '鹅卵石岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'cobblestone-rock'],
        ['id' => 'pumice-rock', 'name' => '浮石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'pumice-rock'],
        ['id' => 'petrified-wood', 'name' => '木化石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'petrified-wood'],
        ['id' => 'quartz-rock', 'name' => '石英岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'quartz-rock'],
        ['id' => 'gneiss-rock', 'name' => '片麻岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'gneiss-rock'],
        ['id' => 'marble-rock', 'name' => '大理岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'marble-rock'],
        ['id' => 'tuff-rock', 'name' => '凝灰岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'tuff-rock'],
        ['id' => 'shale-rock', 'name' => '页岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'shale-rock'],
        ['id' => 'conglomerate-rock', 'name' => '砾岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'conglomerate-rock'],
        ['id' => 'chert-rock', 'name' => '燧石岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'chert-rock'],
        ['id' => 'obsidian-rock', 'name' => '黑曜岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'obsidian-rock'],
        ['id' => 'blue-schist', 'name' => '蓝片岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'blue-schist'],
        ['id' => 'greenstone-rock', 'name' => '绿岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'greenstone-rock'],
        ['id' => 'serpentine-rock', 'name' => '蛇纹岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'serpentine-rock'],
        ['id' => 'columnar-basalt', 'name' => '柱状玄武岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'columnar-basalt'],
        ['id' => 'terraced-rock', 'name' => '梯田岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'terraced-rock'],
        ['id' => 'pagoda-cave', 'name' => '宝塔洞岩', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'pagoda-cave'],
        ['id' => 'reef-boulder', 'name' => '礁石巨石', 'category' => 'decoration', 'price' => 180, 'asset_key' => 'reef-boulder'],
    ]];
}

function aquarium_item(string $id): array
{
    foreach (aquarium_catalog()['items'] as $item) {
        if ($item['id'] === $id) { return $item; }
    }
    throw new ApiError('invalid_item', 'Unknown aquarium item.', 400);
}

function aquarium_read_state(PDO $pdo, string $userId): array
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        // Keep the wallet, inventory and layout from different commits out of one response.
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $query = $pdo->prepare('SELECT initialized, coins, scene_version, scene_json '
            . 'FROM aquarium_profiles WHERE user_id = :user' . $lock);
        $query->execute([':user' => $userId]);
        $profile = $query->fetch(PDO::FETCH_ASSOC);
        $state = ['initialized' => false, 'coins' => 0, 'scene_version' => 0,
            'inventory' => [], 'scene' => [], 'wallet' => []];
        if (is_array($profile)) {
            $state['initialized'] = (bool)$profile['initialized'];
            $state['coins'] = (int)$profile['coins'];
            $state['scene_version'] = (int)$profile['scene_version'];
            $state['scene'] = json_decode((string)$profile['scene_json'], true, 16, JSON_THROW_ON_ERROR);
            $inventory = $pdo->prepare('SELECT id, item_id, created_at FROM aquarium_inventory '
                . 'WHERE user_id = :user ORDER BY created_at, id');
            $inventory->execute([':user' => $userId]);
            foreach ($inventory->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $entry['created_at'] = auth_parse_sql_time($entry['created_at'])->format(DateTimeInterface::ATOM);
                $state['inventory'][] = $entry;
            }
            $wallet = $pdo->prepare('SELECT id, delta, balance, reason, item_id, created_at FROM aquarium_wallet_entries '
                . 'WHERE user_id = :user ORDER BY id DESC LIMIT 100');
            $wallet->execute([':user' => $userId]);
            foreach ($wallet->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $entry['id'] = (string)$entry['id'];
                $entry['delta'] = (int)$entry['delta'];
                $entry['balance'] = (int)$entry['balance'];
                $entry['created_at'] = auth_parse_sql_time($entry['created_at'])->format(DateTimeInterface::ATOM);
                $state['wallet'][] = $entry;
            }
        }
        if ($ownTransaction) { $pdo->commit(); }
        return $state;
    } catch (Throwable $error) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function aquarium_mutate(PDO $pdo, string $userId, array $body, callable $clock): array
{
    $operationId = aquarium_string($body, 'operation_id');
    if (!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $operationId)) {
        throw new ApiError('invalid_operation', 'A stable operation identifier is required.', 400);
    }
    $action = aquarium_string($body, 'action');
    if (!in_array($action, ['initialize', 'buy', 'save_scene'], true)) {
        throw new ApiError('invalid_action', 'Unknown aquarium action.', 400);
    }
    $requestHash = hash('sha256', json_encode(aquarium_canonical_body($body), JSON_THROW_ON_ERROR));
    $now = $clock();
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $pdo->beginTransaction();
        if ($driver === 'sqlite') {
            // SQLite tests acquire their writer lock before taking a read snapshot.
            $pdo->prepare('UPDATE aquarium_profiles SET user_id = user_id WHERE user_id = :user')
                ->execute([':user' => $userId]);
        }
        if ($action === 'initialize') {
            $sql = 'INSERT INTO aquarium_profiles '
                . '(user_id, initialized, coins, scene_version, scene_json, created_at, updated_at) '
                . "VALUES (:user, 0, 0, 0, '[]', :created, :updated) "
                . ($driver === 'sqlite' ? 'ON CONFLICT(user_id) DO NOTHING'
                    : 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)');
            $pdo->prepare($sql)->execute([':user' => $userId,
                ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
        }
        // All mutations serialize on this account's wallet, including the first grant.
        $lock = $pdo->prepare('SELECT initialized, coins, scene_version FROM aquarium_profiles '
            . 'WHERE user_id = :user' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $lock->execute([':user' => $userId]);
        $profile = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile)) {
            throw new ApiError('aquarium_not_initialized', 'Initialize the aquarium first.', 409);
        }
        $existing = $pdo->prepare('SELECT request_hash FROM aquarium_operations '
            . 'WHERE user_id = :user AND operation_id = :operation');
        $existing->execute([':user' => $userId, ':operation' => $operationId]);
        $previousHash = $existing->fetchColumn();
        if ($previousHash !== false) {
            if (!hash_equals((string)$previousHash, $requestHash)) {
                throw new ApiError('operation_conflict', 'This operation identifier was used for a different request.', 409);
            }
        } else {
            if ($action === 'initialize') {
                if (!(bool)$profile['initialized']) {
                    $pdo->prepare('UPDATE aquarium_profiles SET initialized = 1, coins = :coins, updated_at = :now WHERE user_id = :user')
                        ->execute([':coins' => AQUARIUM_INITIAL_COINS, ':now' => auth_sql_time($now), ':user' => $userId]);
                    aquarium_record_wallet($pdo, $userId, $operationId, AQUARIUM_INITIAL_COINS,
                        AQUARIUM_INITIAL_COINS, 'initial_grant', null, $now);
                }
            } else {
                if (!(bool)$profile['initialized']) {
                    throw new ApiError('aquarium_not_initialized', 'Initialize the aquarium first.', 409);
                }
                if ($action === 'buy') {
                    aquarium_buy($pdo, $userId, $operationId, $profile, $body, $now);
                } else {
                    aquarium_save_scene($pdo, $userId, $profile, $body, $now);
                }
            }
            $pdo->prepare('INSERT INTO aquarium_operations (user_id, operation_id, action, request_hash, created_at) '
                . 'VALUES (:user, :operation, :action, :hash, :created)')
                ->execute([':user' => $userId, ':operation' => $operationId, ':action' => $action,
                    ':hash' => $requestHash, ':created' => auth_sql_time($now)]);
        }
        $state = aquarium_read_state($pdo, $userId);
        $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function aquarium_buy(PDO $pdo, string $userId, string $operationId, array $profile, array $body, DateTimeImmutable $now): void
{
    // Accept the previous catalog version for already queued purchases from
    // older clients. The server still validates the item and price below.
    $catalogVersion = aquarium_int($body, 'catalog_version');
    if (!in_array($catalogVersion, [2, 3, AQUARIUM_CATALOG_VERSION], true)) {
        throw new ApiError('catalog_conflict', 'The aquarium catalog changed. Refresh the catalog.', 409);
    }
    $item = aquarium_item(aquarium_string($body, 'item_id'));
    $balance = (int)$profile['coins'] - $item['price'];
    if ($balance < 0) { throw new ApiError('insufficient_coins', 'Not enough aquarium coins.', 409); }
    $pdo->prepare('UPDATE aquarium_profiles SET coins = :coins, updated_at = :now WHERE user_id = :user')
        ->execute([':coins' => $balance, ':now' => auth_sql_time($now), ':user' => $userId]);
    $pdo->prepare('INSERT INTO aquarium_inventory (id, user_id, item_id, created_at) VALUES (:id, :user, :item, :created)')
        ->execute([':id' => auth_random_id(), ':user' => $userId, ':item' => $item['id'], ':created' => auth_sql_time($now)]);
    aquarium_record_wallet($pdo, $userId, $operationId, -$item['price'], $balance, 'purchase', $item['id'], $now);
}

function aquarium_save_scene(PDO $pdo, string $userId, array $profile, array $body, DateTimeImmutable $now): void
{
    $version = aquarium_int($body, 'base_scene_version');
    if ($version !== (int)$profile['scene_version']) {
        throw new ApiError('scene_conflict', 'The aquarium layout changed on another device.', 409);
    }
    $scene = $body['scene'] ?? null;
    if (!is_array($scene) || !array_is_list($scene)) {
        throw new ApiError('invalid_scene', 'The aquarium layout must be a list of owned instances.', 400);
    }
    $inventoryQuery = $pdo->prepare('SELECT id, item_id FROM aquarium_inventory WHERE user_id = :user');
    $inventoryQuery->execute([':user' => $userId]);
    // Instance IDs are generated as lowercase hex, but older MySQL tables may
    // contain the same identifiers with a different case. Normalize the
    // lookup and the duplicate check so a case-only representation difference
    // cannot make an owned item look unowned.
    $owned = [];
    foreach ($inventoryQuery->fetchAll(PDO::FETCH_ASSOC) as $inventory) {
        $owned[strtolower((string)$inventory['id'])] = (string)$inventory['item_id'];
    }
    $seen = [];
    $normalized = [];
    foreach ($scene as $entry) {
        // api_decode_body keeps nested JSON objects as stdClass instances
        // while converting only the top-level request to an array.
        // Normalize each placement before validating its fields.
        if ($entry instanceof stdClass) { $entry = get_object_vars($entry); }
        if (!is_array($entry)) { throw new ApiError('invalid_scene', 'Invalid aquarium instance.', 400); }
        $id = aquarium_string($entry, 'instance_id');
        $normalizedId = strtolower($id);
        if (!isset($owned[$normalizedId]) || isset($seen[$normalizedId])) {
            throw new ApiError('invalid_scene', 'Each instance must be owned by this account and placed only once.', 400);
        }
        $seen[$normalizedId] = true;
        $normalizedEntry = ['instance_id' => $normalizedId,
            'x' => aquarium_coordinate($entry, 'x', 0, 1), 'y' => aquarium_coordinate($entry, 'y', 0, 1),
            'scale' => aquarium_coordinate($entry, 'scale', 0.5, 1.5)];
        // Layer was added after the original scene contract. Preserve old
        // scenes that omit it, while validating it whenever a client sends it.
        if (array_key_exists('layer', $entry)) {
            $normalizedEntry['layer'] = aquarium_layer($entry['layer']);
        }
        $normalized[] = $normalizedEntry;
    }
    $pdo->prepare('UPDATE aquarium_profiles SET scene_json = :scene, scene_version = scene_version + 1, '
        . 'updated_at = :now WHERE user_id = :user')
        ->execute([':scene' => json_encode($normalized, JSON_THROW_ON_ERROR),
            ':now' => auth_sql_time($now), ':user' => $userId]);
}

function aquarium_layer(mixed $value): int
{
    if (!is_int($value) || $value < -1 || $value > 1) {
        throw new ApiError('invalid_scene', 'Aquarium layer must be -1, 0 or 1.', 400);
    }
    return $value;
}

function aquarium_record_wallet(PDO $pdo, string $userId, string $operationId, int $delta,
    int $balance, string $reason, ?string $itemId, DateTimeImmutable $now): void
{
    $pdo->prepare('INSERT INTO aquarium_wallet_entries '
        . '(user_id, operation_id, delta, balance, reason, item_id, created_at) '
        . 'VALUES (:user, :operation, :delta, :balance, :reason, :item, :created)')
        ->execute([':user' => $userId, ':operation' => $operationId, ':delta' => $delta, ':balance' => $balance,
            ':reason' => $reason, ':item' => $itemId, ':created' => auth_sql_time($now)]);
}

function aquarium_string(array $body, string $key): string
{
    if (!isset($body[$key]) || !is_string($body[$key]) || $body[$key] === '') {
        throw new ApiError('invalid_request', 'Required aquarium text field is missing: ' . $key, 400);
    }
    return $body[$key];
}

function aquarium_int(array $body, string $key): int
{
    if (!isset($body[$key]) || !is_int($body[$key]) || $body[$key] < 0) {
        throw new ApiError('invalid_request', 'Expected a nonnegative integer: ' . $key, 400);
    }
    return $body[$key];
}

function aquarium_coordinate(array $entry, string $key, float $min, float $max): float
{
    $value = $entry[$key] ?? null;
    if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value) || $value < $min || $value > $max) {
        throw new ApiError('invalid_scene', 'Aquarium position or scale is outside the supported range.', 400);
    }
    return (float)$value;
}

function aquarium_canonical_body(array $body): array
{
    if (!array_is_list($body)) { ksort($body, SORT_STRING); }
    foreach ($body as $key => $value) {
        if (is_array($value)) { $body[$key] = aquarium_canonical_body($value); }
    }
    return $body;
}
