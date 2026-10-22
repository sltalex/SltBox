<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const AQUARIUM_CATALOG_VERSION = 8;
const AQUARIUM_INITIAL_COINS = 1000;
const AQUARIUM_CARD_PACK_ID = 'aquarium-card-pack';

function aquarium_card_pack_price(?PDO $pdo = null): int
{
    if ($pdo === null) { return 200; }
    $price = $pdo->query('SELECT card_pack_price FROM aquarium_catalog_settings WHERE id = 1')->fetchColumn();
    if ($price !== false) { return max(0, min(2147483647, (int)$price)); }
    return 200;
}

function aquarium_catalog(?PDO $pdo = null): array
{
    $cardPackPrice = aquarium_card_pack_price($pdo);
    return ['catalog_version' => AQUARIUM_CATALOG_VERSION, 'items' => [
        ['id' => 'clownfish', 'name' => '小丑鱼', 'category' => 'fish', 'price' => 100, 'asset_key' => 'clownfish', 'habitat' => 'marine'],
        ['id' => 'blue-tang', 'name' => '蓝吊鱼', 'category' => 'fish', 'price' => 180, 'asset_key' => 'blue-tang', 'habitat' => 'marine'],
        ['id' => 'butterflyfish', 'name' => '蝴蝶鱼', 'category' => 'fish', 'price' => 260, 'asset_key' => 'butterflyfish', 'habitat' => 'marine'],
        ['id' => 'angelfish', 'name' => '神仙鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'angelfish', 'habitat' => 'tropical'],
        ['id' => 'lionfish', 'name' => '狮子鱼', 'category' => 'fish', 'price' => 300, 'asset_key' => 'lionfish', 'habitat' => 'marine'],
        ['id' => 'pufferfish', 'name' => '河豚', 'category' => 'fish', 'price' => 240, 'asset_key' => 'pufferfish', 'habitat' => 'marine'],
        ['id' => 'seahorse', 'name' => '海马', 'category' => 'fish', 'price' => 280, 'asset_key' => 'seahorse', 'habitat' => 'marine'],
        ['id' => 'yellow-tang', 'name' => '黄吊鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'yellow-tang', 'habitat' => 'marine'],
        ['id' => 'royal-gramma', 'name' => '皇家伽玛', 'category' => 'fish', 'price' => 260, 'asset_key' => 'royal-gramma', 'habitat' => 'marine'],
        ['id' => 'mandarinfish', 'name' => '曼达林鱼', 'category' => 'fish', 'price' => 320, 'asset_key' => 'mandarinfish', 'habitat' => 'marine'],
        ['id' => 'moorish-idol', 'name' => '镰鱼', 'category' => 'fish', 'price' => 340, 'asset_key' => 'moorish-idol', 'habitat' => 'marine'],
        ['id' => 'cardinalfish', 'name' => '天竺鲷', 'category' => 'fish', 'price' => 190, 'asset_key' => 'cardinalfish', 'habitat' => 'marine'],
        ['id' => 'great-white-shark', 'name' => '大白鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'great-white-shark', 'habitat' => 'marine'],
        ['id' => 'whale-shark', 'name' => '鲸鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'whale-shark', 'habitat' => 'marine'],
        ['id' => 'scalloped-hammerhead', 'name' => '路氏双髻鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'scalloped-hammerhead', 'habitat' => 'marine'],
        ['id' => 'blacktip-reef-shark', 'name' => '黑鳍礁鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'blacktip-reef-shark', 'habitat' => 'marine'],
        ['id' => 'zebra-shark', 'name' => '斑马鲨', 'category' => 'fish', 'price' => 600, 'asset_key' => 'zebra-shark', 'habitat' => 'marine'],
        ['id' => 'sardine', 'name' => '沙丁鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'sardine', 'habitat' => 'marine'],
        ['id' => 'anchovy', 'name' => '鳀鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'anchovy', 'habitat' => 'marine'],
        ['id' => 'herring', 'name' => '大西洋鲱鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'herring', 'habitat' => 'marine'],
        ['id' => 'atlantic-mackerel', 'name' => '大西洋鲭鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'atlantic-mackerel', 'habitat' => 'marine'],
        ['id' => 'yellowfin-tuna', 'name' => '黄鳍金枪鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'yellowfin-tuna', 'habitat' => 'marine'],
        ['id' => 'silver-pomfret', 'name' => '银鲳', 'category' => 'fish', 'price' => 280, 'asset_key' => 'silver-pomfret', 'habitat' => 'marine'],
        ['id' => 'milkfish', 'name' => '虱目鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'milkfish', 'habitat' => 'marine'],
        ['id' => 'giant-trevally', 'name' => '浪人鲹', 'category' => 'fish', 'price' => 600, 'asset_key' => 'giant-trevally', 'habitat' => 'marine'],
        ['id' => 'barracuda', 'name' => '大魣', 'category' => 'fish', 'price' => 600, 'asset_key' => 'barracuda', 'habitat' => 'marine'],
        ['id' => 'ocean-sunfish', 'name' => '翻车鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'ocean-sunfish', 'habitat' => 'marine'],
        ['id' => 'manta-ray', 'name' => '双吻前口蝠鲼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'manta-ray', 'habitat' => 'marine'],
        ['id' => 'eagle-ray', 'name' => '斑点鹰魟', 'category' => 'fish', 'price' => 600, 'asset_key' => 'eagle-ray', 'habitat' => 'marine'],
        ['id' => 'blue-spotted-stingray', 'name' => '蓝斑条尾魟', 'category' => 'fish', 'price' => 280, 'asset_key' => 'blue-spotted-stingray', 'habitat' => 'marine'],
        ['id' => 'redtail-catfish', 'name' => '红尾鲶', 'category' => 'fish', 'price' => 600, 'asset_key' => 'redtail-catfish', 'habitat' => 'tropical'],
        ['id' => 'arowana', 'name' => '银龙鱼', 'category' => 'fish', 'price' => 600, 'asset_key' => 'arowana', 'habitat' => 'tropical'],
        ['id' => 'discus', 'name' => '七彩神仙鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'discus', 'habitat' => 'tropical'],
        ['id' => 'goldfish', 'name' => '金鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'goldfish', 'habitat' => 'freshwater'],
        ['id' => 'koi', 'name' => '锦鲤', 'category' => 'fish', 'price' => 600, 'asset_key' => 'koi', 'habitat' => 'freshwater'],
        ['id' => 'guppy', 'name' => '孔雀鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'guppy', 'habitat' => 'tropical'],
        ['id' => 'neon-tetra', 'name' => '霓虹灯鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'neon-tetra', 'habitat' => 'tropical'],
        ['id' => 'zebra-danio', 'name' => '斑马鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'zebra-danio', 'habitat' => 'tropical'],
        ['id' => 'siamese-fighting-fish', 'name' => '暹罗斗鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'siamese-fighting-fish', 'habitat' => 'tropical'],
        ['id' => 'banggai-cardinalfish', 'name' => '邦盖天竺鲷', 'category' => 'fish', 'price' => 140, 'asset_key' => 'banggai-cardinalfish', 'habitat' => 'marine'],
        ['id' => 'firefish', 'name' => '火焰虾虎鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'firefish', 'habitat' => 'marine'],
        ['id' => 'sixline-wrasse', 'name' => '六线龙', 'category' => 'fish', 'price' => 140, 'asset_key' => 'sixline-wrasse', 'habitat' => 'marine'],
        ['id' => 'fairy-wrasse', 'name' => '太阳仙女隆头鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'fairy-wrasse', 'habitat' => 'marine'],
        ['id' => 'flame-angelfish', 'name' => '火焰神仙鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'flame-angelfish', 'habitat' => 'marine'],
        ['id' => 'coral-beauty', 'name' => '珊瑚美人神仙鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'coral-beauty', 'habitat' => 'marine'],
        ['id' => 'emperor-angelfish', 'name' => '皇帝神仙鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'emperor-angelfish', 'habitat' => 'marine'],
        ['id' => 'foxface-rabbitfish', 'name' => '狐狸鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'foxface-rabbitfish', 'habitat' => 'marine'],
        ['id' => 'sailfin-tang', 'name' => '帆鳍吊鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'sailfin-tang', 'habitat' => 'marine'],
        ['id' => 'naso-tang', 'name' => '橙棘吊鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'naso-tang', 'habitat' => 'marine'],
        ['id' => 'purple-tang', 'name' => '紫吊鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'purple-tang', 'habitat' => 'marine'],
        ['id' => 'rainbow-trout', 'name' => '虹鳟', 'category' => 'fish', 'price' => 280, 'asset_key' => 'rainbow-trout', 'habitat' => 'freshwater'],
        ['id' => 'brown-trout', 'name' => '褐鳟', 'category' => 'fish', 'price' => 290, 'asset_key' => 'brown-trout', 'habitat' => 'freshwater'],
        ['id' => 'brook-trout', 'name' => '溪红点鲑', 'category' => 'fish', 'price' => 280, 'asset_key' => 'brook-trout', 'habitat' => 'freshwater'],
        ['id' => 'lake-trout', 'name' => '湖红点鲑', 'category' => 'fish', 'price' => 310, 'asset_key' => 'lake-trout', 'habitat' => 'freshwater'],
        ['id' => 'arctic-char', 'name' => '北极红点鲑', 'category' => 'fish', 'price' => 300, 'asset_key' => 'arctic-char', 'habitat' => 'freshwater'],
        ['id' => 'atlantic-salmon', 'name' => '大西洋鲑', 'category' => 'fish', 'price' => 340, 'asset_key' => 'atlantic-salmon', 'habitat' => 'freshwater'],
        ['id' => 'sockeye-salmon', 'name' => '红鲑', 'category' => 'fish', 'price' => 350, 'asset_key' => 'sockeye-salmon', 'habitat' => 'freshwater'],
        ['id' => 'chinook-salmon', 'name' => '大鳞大麻哈鱼', 'category' => 'fish', 'price' => 370, 'asset_key' => 'chinook-salmon', 'habitat' => 'freshwater'],
        ['id' => 'coho-salmon', 'name' => '银大麻哈鱼', 'category' => 'fish', 'price' => 340, 'asset_key' => 'coho-salmon', 'habitat' => 'freshwater'],
        ['id' => 'arctic-grayling', 'name' => '北极茴鱼', 'category' => 'fish', 'price' => 300, 'asset_key' => 'arctic-grayling', 'habitat' => 'freshwater'],
        ['id' => 'yellow-perch', 'name' => '黄鲈', 'category' => 'fish', 'price' => 220, 'asset_key' => 'yellow-perch', 'habitat' => 'freshwater'],
        ['id' => 'european-perch', 'name' => '河鲈', 'category' => 'fish', 'price' => 240, 'asset_key' => 'european-perch', 'habitat' => 'freshwater'],
        ['id' => 'zander', 'name' => '梭鲈', 'category' => 'fish', 'price' => 300, 'asset_key' => 'zander', 'habitat' => 'freshwater'],
        ['id' => 'walleye', 'name' => '玻璃梭鲈', 'category' => 'fish', 'price' => 290, 'asset_key' => 'walleye', 'habitat' => 'freshwater'],
        ['id' => 'northern-pike', 'name' => '白斑狗鱼', 'category' => 'fish', 'price' => 350, 'asset_key' => 'northern-pike', 'habitat' => 'freshwater'],
        ['id' => 'muskellunge', 'name' => '北美狗鱼', 'category' => 'fish', 'price' => 380, 'asset_key' => 'muskellunge', 'habitat' => 'freshwater'],
        ['id' => 'largemouth-bass', 'name' => '大口黑鲈', 'category' => 'fish', 'price' => 280, 'asset_key' => 'largemouth-bass', 'habitat' => 'freshwater'],
        ['id' => 'smallmouth-bass', 'name' => '小口黑鲈', 'category' => 'fish', 'price' => 270, 'asset_key' => 'smallmouth-bass', 'habitat' => 'freshwater'],
        ['id' => 'bluegill', 'name' => '蓝鳃太阳鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'bluegill', 'habitat' => 'freshwater'],
        ['id' => 'pumpkinseed', 'name' => '瓜子仁太阳鱼', 'category' => 'fish', 'price' => 230, 'asset_key' => 'pumpkinseed', 'habitat' => 'freshwater'],
        ['id' => 'black-crappie', 'name' => '黑莓鲈', 'category' => 'fish', 'price' => 240, 'asset_key' => 'black-crappie', 'habitat' => 'freshwater'],
        ['id' => 'white-crappie', 'name' => '白莓鲈', 'category' => 'fish', 'price' => 240, 'asset_key' => 'white-crappie', 'habitat' => 'freshwater'],
        ['id' => 'channel-catfish', 'name' => '斑点叉尾鮰', 'category' => 'fish', 'price' => 300, 'asset_key' => 'channel-catfish', 'habitat' => 'freshwater'],
        ['id' => 'wels-catfish', 'name' => '欧洲巨鲶', 'category' => 'fish', 'price' => 420, 'asset_key' => 'wels-catfish', 'habitat' => 'freshwater'],
        ['id' => 'sterlet', 'name' => '小体鲟', 'category' => 'fish', 'price' => 360, 'asset_key' => 'sterlet', 'habitat' => 'freshwater'],
        ['id' => 'american-paddlefish', 'name' => '匙吻鲟', 'category' => 'fish', 'price' => 420, 'asset_key' => 'american-paddlefish', 'habitat' => 'freshwater'],
        ['id' => 'bowfin', 'name' => '弓鳍鱼', 'category' => 'fish', 'price' => 300, 'asset_key' => 'bowfin', 'habitat' => 'freshwater'],
        ['id' => 'alligator-gar', 'name' => '鳄雀鳝', 'category' => 'fish', 'price' => 430, 'asset_key' => 'alligator-gar', 'habitat' => 'freshwater'],
        ['id' => 'longnose-gar', 'name' => '长吻雀鳝', 'category' => 'fish', 'price' => 400, 'asset_key' => 'longnose-gar', 'habitat' => 'freshwater'],
        ['id' => 'tench', 'name' => '丁鱥', 'category' => 'fish', 'price' => 260, 'asset_key' => 'tench', 'habitat' => 'freshwater'],
        ['id' => 'cardinal-tetra', 'name' => '红莲灯鱼', 'category' => 'fish', 'price' => 150, 'asset_key' => 'cardinal-tetra', 'habitat' => 'tropical'],
        ['id' => 'rummy-nose-tetra', 'name' => '红鼻剪刀鱼', 'category' => 'fish', 'price' => 160, 'asset_key' => 'rummy-nose-tetra', 'habitat' => 'tropical'],
        ['id' => 'ember-tetra', 'name' => '火焰灯鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'ember-tetra', 'habitat' => 'tropical'],
        ['id' => 'black-neon-tetra', 'name' => '黑莲灯鱼', 'category' => 'fish', 'price' => 150, 'asset_key' => 'black-neon-tetra', 'habitat' => 'tropical'],
        ['id' => 'lemon-tetra', 'name' => '柠檬灯鱼', 'category' => 'fish', 'price' => 160, 'asset_key' => 'lemon-tetra', 'habitat' => 'tropical'],
        ['id' => 'congo-tetra', 'name' => '刚果灯鱼', 'category' => 'fish', 'price' => 210, 'asset_key' => 'congo-tetra', 'habitat' => 'tropical'],
        ['id' => 'bleeding-heart-tetra', 'name' => '红心灯鱼', 'category' => 'fish', 'price' => 180, 'asset_key' => 'bleeding-heart-tetra', 'habitat' => 'tropical'],
        ['id' => 'serpae-tetra', 'name' => '红裙灯鱼', 'category' => 'fish', 'price' => 160, 'asset_key' => 'serpae-tetra', 'habitat' => 'tropical'],
        ['id' => 'harlequin-rasbora', 'name' => '三角灯鱼', 'category' => 'fish', 'price' => 150, 'asset_key' => 'harlequin-rasbora', 'habitat' => 'tropical'],
        ['id' => 'chili-rasbora', 'name' => '辣椒灯鱼', 'category' => 'fish', 'price' => 150, 'asset_key' => 'chili-rasbora', 'habitat' => 'tropical'],
        ['id' => 'celestial-pearl-danio', 'name' => '银河斑马鱼', 'category' => 'fish', 'price' => 180, 'asset_key' => 'celestial-pearl-danio', 'habitat' => 'tropical'],
        ['id' => 'white-cloud-minnow', 'name' => '白云金丝鱼', 'category' => 'fish', 'price' => 140, 'asset_key' => 'white-cloud-minnow', 'habitat' => 'tropical'],
        ['id' => 'rosy-barb', 'name' => '玫瑰鲃', 'category' => 'fish', 'price' => 180, 'asset_key' => 'rosy-barb', 'habitat' => 'tropical'],
        ['id' => 'tiger-barb', 'name' => '四间鲃', 'category' => 'fish', 'price' => 180, 'asset_key' => 'tiger-barb', 'habitat' => 'tropical'],
        ['id' => 'cherry-barb', 'name' => '樱桃鲃', 'category' => 'fish', 'price' => 170, 'asset_key' => 'cherry-barb', 'habitat' => 'tropical'],
        ['id' => 'denison-barb', 'name' => '丹尼逊鲃', 'category' => 'fish', 'price' => 230, 'asset_key' => 'denison-barb', 'habitat' => 'tropical'],
        ['id' => 'bala-shark', 'name' => '银鲨', 'category' => 'fish', 'price' => 280, 'asset_key' => 'bala-shark', 'habitat' => 'tropical'],
        ['id' => 'swordtail', 'name' => '剑尾鱼', 'category' => 'fish', 'price' => 190, 'asset_key' => 'swordtail', 'habitat' => 'tropical'],
        ['id' => 'southern-platy', 'name' => '月光鱼', 'category' => 'fish', 'price' => 160, 'asset_key' => 'southern-platy', 'habitat' => 'tropical'],
        ['id' => 'sailfin-molly', 'name' => '帆鳍摩利鱼', 'category' => 'fish', 'price' => 210, 'asset_key' => 'sailfin-molly', 'habitat' => 'tropical'],
        ['id' => 'endlers-livebearer', 'name' => '安德拉斯孔雀鱼', 'category' => 'fish', 'price' => 170, 'asset_key' => 'endlers-livebearer', 'habitat' => 'tropical'],
        ['id' => 'dwarf-gourami', 'name' => '丽丽鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'dwarf-gourami', 'habitat' => 'tropical'],
        ['id' => 'pearl-gourami', 'name' => '珍珠马甲鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'pearl-gourami', 'habitat' => 'tropical'],
        ['id' => 'honey-gourami', 'name' => '蜜丽丽鱼', 'category' => 'fish', 'price' => 190, 'asset_key' => 'honey-gourami', 'habitat' => 'tropical'],
        ['id' => 'kissing-gourami', 'name' => '接吻鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'kissing-gourami', 'habitat' => 'tropical'],
        ['id' => 'blue-ram', 'name' => '荷兰凤凰鱼', 'category' => 'fish', 'price' => 230, 'asset_key' => 'blue-ram', 'habitat' => 'tropical'],
        ['id' => 'bolivian-ram', 'name' => '玻利维亚凤凰鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'bolivian-ram', 'habitat' => 'tropical'],
        ['id' => 'cockatoo-cichlid', 'name' => '凤头短鲷', 'category' => 'fish', 'price' => 250, 'asset_key' => 'cockatoo-cichlid', 'habitat' => 'tropical'],
        ['id' => 'kribensis', 'name' => '红肚凤凰鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'kribensis', 'habitat' => 'tropical'],
        ['id' => 'oscar', 'name' => '地图鱼', 'category' => 'fish', 'price' => 300, 'asset_key' => 'oscar', 'habitat' => 'tropical'],
        ['id' => 'jack-dempsey', 'name' => '蓝宝石慈鲷', 'category' => 'fish', 'price' => 280, 'asset_key' => 'jack-dempsey', 'habitat' => 'tropical'],
        ['id' => 'electric-yellow-cichlid', 'name' => '非洲王子鱼', 'category' => 'fish', 'price' => 230, 'asset_key' => 'electric-yellow-cichlid', 'habitat' => 'tropical'],
        ['id' => 'frontosa', 'name' => '六间鱼', 'category' => 'fish', 'price' => 330, 'asset_key' => 'frontosa', 'habitat' => 'tropical'],
        ['id' => 'boesemans-rainbowfish', 'name' => '澳洲彩虹鱼', 'category' => 'fish', 'price' => 250, 'asset_key' => 'boesemans-rainbowfish', 'habitat' => 'tropical'],
        ['id' => 'dwarf-neon-rainbowfish', 'name' => '电光美人鱼', 'category' => 'fish', 'price' => 210, 'asset_key' => 'dwarf-neon-rainbowfish', 'habitat' => 'tropical'],
        ['id' => 'threadfin-rainbowfish', 'name' => '丝鳍彩虹鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'threadfin-rainbowfish', 'habitat' => 'tropical'],
        ['id' => 'clown-loach', 'name' => '皇冠泥鳅', 'category' => 'fish', 'price' => 260, 'asset_key' => 'clown-loach', 'habitat' => 'tropical'],
        ['id' => 'kuhli-loach', 'name' => '库利鳅', 'category' => 'fish', 'price' => 190, 'asset_key' => 'kuhli-loach', 'habitat' => 'tropical'],
        ['id' => 'panda-corydoras', 'name' => '熊猫鼠鱼', 'category' => 'fish', 'price' => 180, 'asset_key' => 'panda-corydoras', 'habitat' => 'tropical'],
        ['id' => 'bronze-corydoras', 'name' => '青铜鼠鱼', 'category' => 'fish', 'price' => 170, 'asset_key' => 'bronze-corydoras', 'habitat' => 'tropical'],
        ['id' => 'bristlenose-pleco', 'name' => '胡子异型鱼', 'category' => 'fish', 'price' => 230, 'asset_key' => 'bristlenose-pleco', 'habitat' => 'tropical'],
        ['id' => 'zebra-pleco', 'name' => '斑马异型鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'zebra-pleco', 'habitat' => 'tropical'],
        ['id' => 'glass-catfish', 'name' => '玻璃猫鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'glass-catfish', 'habitat' => 'tropical'],
        ['id' => 'african-butterflyfish', 'name' => '非洲蝴蝶鱼', 'category' => 'fish', 'price' => 260, 'asset_key' => 'african-butterflyfish', 'habitat' => 'tropical'],
        ['id' => 'elephantnose-fish', 'name' => '象鼻鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'elephantnose-fish', 'habitat' => 'tropical'],
        ['id' => 'regal-angelfish', 'name' => '蓝纹神仙鱼', 'category' => 'fish', 'price' => 300, 'asset_key' => 'regal-angelfish', 'habitat' => 'marine'],
        ['id' => 'queen-angelfish', 'name' => '女王神仙鱼', 'category' => 'fish', 'price' => 340, 'asset_key' => 'queen-angelfish', 'habitat' => 'marine'],
        ['id' => 'bicolor-angelfish', 'name' => '双色神仙鱼', 'category' => 'fish', 'price' => 250, 'asset_key' => 'bicolor-angelfish', 'habitat' => 'marine'],
        ['id' => 'copperband-butterflyfish', 'name' => '铜带蝴蝶鱼', 'category' => 'fish', 'price' => 290, 'asset_key' => 'copperband-butterflyfish', 'habitat' => 'marine'],
        ['id' => 'raccoon-butterflyfish', 'name' => '浣熊蝴蝶鱼', 'category' => 'fish', 'price' => 270, 'asset_key' => 'raccoon-butterflyfish', 'habitat' => 'marine'],
        ['id' => 'threadfin-butterflyfish', 'name' => '人字蝴蝶鱼', 'category' => 'fish', 'price' => 280, 'asset_key' => 'threadfin-butterflyfish', 'habitat' => 'marine'],
        ['id' => 'powder-blue-tang', 'name' => '粉蓝吊鱼', 'category' => 'fish', 'price' => 320, 'asset_key' => 'powder-blue-tang', 'habitat' => 'marine'],
        ['id' => 'achilles-tang', 'name' => '阿基里斯吊鱼', 'category' => 'fish', 'price' => 340, 'asset_key' => 'achilles-tang', 'habitat' => 'marine'],
        ['id' => 'clown-triggerfish', 'name' => '小丑炮弹鱼', 'category' => 'fish', 'price' => 330, 'asset_key' => 'clown-triggerfish', 'habitat' => 'marine'],
        ['id' => 'picasso-triggerfish', 'name' => '毕加索炮弹鱼', 'category' => 'fish', 'price' => 310, 'asset_key' => 'picasso-triggerfish', 'habitat' => 'marine'],
        ['id' => 'yellow-boxfish', 'name' => '黄箱鲀', 'category' => 'fish', 'price' => 290, 'asset_key' => 'yellow-boxfish', 'habitat' => 'marine'],
        ['id' => 'longhorn-cowfish', 'name' => '长角箱鲀', 'category' => 'fish', 'price' => 320, 'asset_key' => 'longhorn-cowfish', 'habitat' => 'marine'],
        ['id' => 'blue-green-chromis', 'name' => '蓝绿光鳃鱼', 'category' => 'fish', 'price' => 170, 'asset_key' => 'blue-green-chromis', 'habitat' => 'marine'],
        ['id' => 'yellowtail-damselfish', 'name' => '黄尾蓝雀鲷', 'category' => 'fish', 'price' => 180, 'asset_key' => 'yellowtail-damselfish', 'habitat' => 'marine'],
        ['id' => 'sergeant-major', 'name' => '五带豆娘鱼', 'category' => 'fish', 'price' => 210, 'asset_key' => 'sergeant-major', 'habitat' => 'marine'],
        ['id' => 'lyretail-anthias', 'name' => '金花鲈', 'category' => 'fish', 'price' => 230, 'asset_key' => 'lyretail-anthias', 'habitat' => 'marine'],
        ['id' => 'purple-firefish', 'name' => '紫火焰虾虎鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'purple-firefish', 'habitat' => 'marine'],
        ['id' => 'yellow-watchman-goby', 'name' => '黄守望虾虎鱼', 'category' => 'fish', 'price' => 220, 'asset_key' => 'yellow-watchman-goby', 'habitat' => 'marine'],
        ['id' => 'neon-goby', 'name' => '霓虹虾虎鱼', 'category' => 'fish', 'price' => 190, 'asset_key' => 'neon-goby', 'habitat' => 'marine'],
        ['id' => 'flame-hawkfish', 'name' => '火焰鹰鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'flame-hawkfish', 'habitat' => 'marine'],
        ['id' => 'longnose-hawkfish', 'name' => '长吻鹰鱼', 'category' => 'fish', 'price' => 260, 'asset_key' => 'longnose-hawkfish', 'habitat' => 'marine'],
        ['id' => 'bluehead-wrasse', 'name' => '蓝头隆头鱼', 'category' => 'fish', 'price' => 240, 'asset_key' => 'bluehead-wrasse', 'habitat' => 'marine'],
        ['id' => 'harlequin-tuskfish', 'name' => '花面猪齿鱼', 'category' => 'fish', 'price' => 320, 'asset_key' => 'harlequin-tuskfish', 'habitat' => 'marine'],
        ['id' => 'panther-grouper', 'name' => '驼背鲈', 'category' => 'fish', 'price' => 330, 'asset_key' => 'panther-grouper', 'habitat' => 'marine'],
        ['id' => 'snowflake-moray', 'name' => '星斑裸胸鳝', 'category' => 'fish', 'price' => 350, 'asset_key' => 'snowflake-moray', 'habitat' => 'marine'],
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
        ['id' => 'crystal-red-shrimp', 'name' => '水晶红虾', 'category' => 'shrimp', 'price' => 150, 'asset_key' => 'crystal-red-shrimp'],
        ['id' => 'blue-dream-shrimp', 'name' => '蓝梦虾', 'category' => 'shrimp', 'price' => 140, 'asset_key' => 'blue-dream-shrimp'],
        ['id' => 'yellow-shrimp', 'name' => '黄金米虾', 'category' => 'shrimp', 'price' => 130, 'asset_key' => 'yellow-shrimp'],
        ['id' => 'tiger-shrimp', 'name' => '虎纹虾', 'category' => 'shrimp', 'price' => 160, 'asset_key' => 'tiger-shrimp'],
        ['id' => 'bamboo-shrimp', 'name' => '竹节虾', 'category' => 'shrimp', 'price' => 180, 'asset_key' => 'bamboo-shrimp'],
        ['id' => 'cleaner-shrimp', 'name' => '清洁虾', 'category' => 'shrimp', 'price' => 200, 'asset_key' => 'cleaner-shrimp'],
        ['id' => 'peppermint-shrimp', 'name' => '薄荷虾', 'category' => 'shrimp', 'price' => 170, 'asset_key' => 'peppermint-shrimp'],
        ['id' => 'harlequin-shrimp', 'name' => '小丑虾', 'category' => 'shrimp', 'price' => 240, 'asset_key' => 'harlequin-shrimp'],
        ['id' => 'hermit-crab', 'name' => '寄居蟹', 'category' => 'crab', 'price' => 150, 'asset_key' => 'hermit-crab'],
        ['id' => 'fiddler-crab', 'name' => '招潮蟹', 'category' => 'crab', 'price' => 170, 'asset_key' => 'fiddler-crab'],
        ['id' => 'porcelain-crab', 'name' => '瓷蟹', 'category' => 'crab', 'price' => 210, 'asset_key' => 'porcelain-crab'],
        ['id' => 'emerald-crab', 'name' => '绿宝石蟹', 'category' => 'crab', 'price' => 230, 'asset_key' => 'emerald-crab'],
        ['id' => 'red-claw-crab', 'name' => '红螯蟹', 'category' => 'crab', 'price' => 160, 'asset_key' => 'red-claw-crab'],
        ['id' => 'vampire-crab', 'name' => '吸血鬼蟹', 'category' => 'crab', 'price' => 220, 'asset_key' => 'vampire-crab'],
        ['id' => 'pom-pom-crab', 'name' => '拳击蟹', 'category' => 'crab', 'price' => 240, 'asset_key' => 'pom-pom-crab'],
        ['id' => 'blue-swimmer-crab', 'name' => '蓝花蟹', 'category' => 'crab', 'price' => 200, 'asset_key' => 'blue-swimmer-crab'],
        ['id' => 'sally-lightfoot-crab', 'name' => '红石蟹', 'category' => 'crab', 'price' => 190, 'asset_key' => 'sally-lightfoot-crab'],
        ['id' => 'halloween-crab', 'name' => '万圣节蟹', 'category' => 'crab', 'price' => 260, 'asset_key' => 'halloween-crab'],
        ['id' => 'scallop', 'name' => '扇贝', 'category' => 'shellfish', 'price' => 80, 'asset_key' => 'scallop'],
        ['id' => 'oyster', 'name' => '牡蛎', 'category' => 'shellfish', 'price' => 70, 'asset_key' => 'oyster'],
        ['id' => 'giant-clam', 'name' => '砗磲', 'category' => 'shellfish', 'price' => 240, 'asset_key' => 'giant-clam'],
        ['id' => 'blue-mussel', 'name' => '蓝贻贝', 'category' => 'shellfish', 'price' => 90, 'asset_key' => 'blue-mussel'],
        ['id' => 'pearl-oyster', 'name' => '珍珠贝', 'category' => 'shellfish', 'price' => 220, 'asset_key' => 'pearl-oyster'],
        ['id' => 'cockle', 'name' => '鸟蛤', 'category' => 'shellfish', 'price' => 100, 'asset_key' => 'cockle'],
        ['id' => 'razor-clam', 'name' => '蛏子', 'category' => 'shellfish', 'price' => 110, 'asset_key' => 'razor-clam'],
        ['id' => 'abalone', 'name' => '鲍鱼', 'category' => 'shellfish', 'price' => 180, 'asset_key' => 'abalone'],
        ['id' => 'conch', 'name' => '海螺', 'category' => 'shellfish', 'price' => 160, 'asset_key' => 'conch'],
        ['id' => 'nautilus', 'name' => '鹦鹉螺', 'category' => 'shellfish', 'price' => 250, 'asset_key' => 'nautilus'],
        ['id' => 'reef-rock', 'name' => '礁石假山', 'category' => 'decoration', 'price' => 120, 'asset_key' => 'reef-rock'],
        ['id' => 'staghorn-coral', 'name' => '鹿角珊瑚', 'category' => 'coral', 'price' => 180, 'asset_key' => 'staghorn-coral'],
        ['id' => 'elkhorn-coral', 'name' => '麋角珊瑚', 'category' => 'coral', 'price' => 200, 'asset_key' => 'elkhorn-coral'],
        ['id' => 'table-acropora', 'name' => '桌形轴孔珊瑚', 'category' => 'coral', 'price' => 220, 'asset_key' => 'table-acropora'],
        ['id' => 'birdsnest-coral', 'name' => '鸟巢珊瑚', 'category' => 'coral', 'price' => 190, 'asset_key' => 'birdsnest-coral'],
        ['id' => 'cauliflower-coral', 'name' => '菜花珊瑚', 'category' => 'coral', 'price' => 180, 'asset_key' => 'cauliflower-coral'],
        ['id' => 'brain-coral', 'name' => '脑珊瑚', 'category' => 'coral', 'price' => 220, 'asset_key' => 'brain-coral'],
        ['id' => 'maze-coral', 'name' => '迷宫珊瑚', 'category' => 'coral', 'price' => 230, 'asset_key' => 'maze-coral'],
        ['id' => 'plate-coral', 'name' => '圆盘珊瑚', 'category' => 'coral', 'price' => 160, 'asset_key' => 'plate-coral'],
        ['id' => 'scroll-coral', 'name' => '卷叶珊瑚', 'category' => 'coral', 'price' => 200, 'asset_key' => 'scroll-coral'],
        ['id' => 'mushroom-coral', 'name' => '蘑菇珊瑚', 'category' => 'coral', 'price' => 150, 'asset_key' => 'mushroom-coral'],
        ['id' => 'hammer-coral', 'name' => '锤头珊瑚', 'category' => 'coral', 'price' => 240, 'asset_key' => 'hammer-coral'],
        ['id' => 'frogspawn-coral', 'name' => '蛙卵珊瑚', 'category' => 'coral', 'price' => 240, 'asset_key' => 'frogspawn-coral'],
        ['id' => 'torch-coral', 'name' => '火炬珊瑚', 'category' => 'coral', 'price' => 260, 'asset_key' => 'torch-coral'],
        ['id' => 'bubble-coral', 'name' => '气泡珊瑚', 'category' => 'coral', 'price' => 220, 'asset_key' => 'bubble-coral'],
        ['id' => 'candy-cane-coral', 'name' => '糖果珊瑚', 'category' => 'coral', 'price' => 180, 'asset_key' => 'candy-cane-coral'],
        ['id' => 'sun-coral', 'name' => '太阳珊瑚', 'category' => 'coral', 'price' => 240, 'asset_key' => 'sun-coral'],
        ['id' => 'sea-fan-coral', 'name' => '海扇珊瑚', 'category' => 'coral', 'price' => 210, 'asset_key' => 'sea-fan-coral'],
        ['id' => 'organ-pipe-coral', 'name' => '风琴珊瑚', 'category' => 'coral', 'price' => 200, 'asset_key' => 'organ-pipe-coral'],
        ['id' => 'finger-leather-coral', 'name' => '指状皮革珊瑚', 'category' => 'coral', 'price' => 160, 'asset_key' => 'finger-leather-coral'],
        ['id' => 'star-polyp-coral', 'name' => '绿星珊瑚', 'category' => 'coral', 'price' => 170, 'asset_key' => 'star-polyp-coral'],
        ['id' => 'giant-kelp', 'name' => '巨藻', 'category' => 'algae', 'price' => 140, 'asset_key' => 'giant-kelp'],
        ['id' => 'sugar-kelp', 'name' => '糖海带', 'category' => 'algae', 'price' => 90, 'asset_key' => 'sugar-kelp'],
        ['id' => 'wakame-algae', 'name' => '裙带菜', 'category' => 'algae', 'price' => 100, 'asset_key' => 'wakame-algae'],
        ['id' => 'bull-kelp', 'name' => '牛尾藻', 'category' => 'algae', 'price' => 130, 'asset_key' => 'bull-kelp'],
        ['id' => 'sea-lettuce-algae', 'name' => '海莴苣', 'category' => 'algae', 'price' => 80, 'asset_key' => 'sea-lettuce-algae'],
        ['id' => 'sea-grapes-algae', 'name' => '海葡萄', 'category' => 'algae', 'price' => 100, 'asset_key' => 'sea-grapes-algae'],
        ['id' => 'feather-caulerpa', 'name' => '羽状蕨藻', 'category' => 'algae', 'price' => 90, 'asset_key' => 'feather-caulerpa'],
        ['id' => 'codium-algae', 'name' => '松藻', 'category' => 'algae', 'price' => 100, 'asset_key' => 'codium-algae'],
        ['id' => 'halimeda-algae', 'name' => '仙掌藻', 'category' => 'algae', 'price' => 110, 'asset_key' => 'halimeda-algae'],
        ['id' => 'sea-palm-algae', 'name' => '海棕榈', 'category' => 'algae', 'price' => 110, 'asset_key' => 'sea-palm-algae'],
        ['id' => 'red-gracilaria', 'name' => '红江蓠', 'category' => 'algae', 'price' => 100, 'asset_key' => 'red-gracilaria'],
        ['id' => 'dragon-tongue-algae', 'name' => '龙舌红藻', 'category' => 'algae', 'price' => 130, 'asset_key' => 'dragon-tongue-algae'],
        ['id' => 'irish-moss-algae', 'name' => '爱尔兰角叉菜', 'category' => 'algae', 'price' => 110, 'asset_key' => 'irish-moss-algae'],
        ['id' => 'dulse-algae', 'name' => '掌状红皮藻', 'category' => 'algae', 'price' => 110, 'asset_key' => 'dulse-algae'],
        ['id' => 'sargassum-algae', 'name' => '马尾藻', 'category' => 'algae', 'price' => 120, 'asset_key' => 'sargassum-algae'],
        ['id' => 'bladderwrack-algae', 'name' => '泡叶藻', 'category' => 'algae', 'price' => 100, 'asset_key' => 'bladderwrack-algae'],
        ['id' => 'spiral-wrack-algae', 'name' => '螺旋墨角藻', 'category' => 'algae', 'price' => 110, 'asset_key' => 'spiral-wrack-algae'],
        ['id' => 'peacock-tail-algae', 'name' => '孔雀尾藻', 'category' => 'algae', 'price' => 120, 'asset_key' => 'peacock-tail-algae'],
        ['id' => 'shaving-brush-algae', 'name' => '毛笔藻', 'category' => 'algae', 'price' => 100, 'asset_key' => 'shaving-brush-algae'],
        ['id' => 'mermaids-cup-algae', 'name' => '美人杯藻', 'category' => 'algae', 'price' => 120, 'asset_key' => 'mermaids-cup-algae'],
        ['id' => 'mystery-snail', 'name' => '神秘螺', 'category' => 'snail', 'price' => 90, 'asset_key' => 'mystery-snail'],
        ['id' => 'rabbit-snail', 'name' => '兔螺', 'category' => 'snail', 'price' => 150, 'asset_key' => 'rabbit-snail'],
        ['id' => 'zebra-nerite', 'name' => '斑马螺', 'category' => 'snail', 'price' => 110, 'asset_key' => 'zebra-nerite'],
        ['id' => 'horned-nerite', 'name' => '角螺', 'category' => 'snail', 'price' => 130, 'asset_key' => 'horned-nerite'],
        ['id' => 'ramshorn-snail', 'name' => '扁卷螺', 'category' => 'snail', 'price' => 80, 'asset_key' => 'ramshorn-snail'],
        ['id' => 'malaysian-trumpet-snail', 'name' => '马来螺', 'category' => 'snail', 'price' => 90, 'asset_key' => 'malaysian-trumpet-snail'],
        ['id' => 'assassin-snail', 'name' => '杀手螺', 'category' => 'snail', 'price' => 140, 'asset_key' => 'assassin-snail'],
        ['id' => 'japanese-trapdoor-snail', 'name' => '日本活板螺', 'category' => 'snail', 'price' => 120, 'asset_key' => 'japanese-trapdoor-snail'],
        ['id' => 'bladder-snail', 'name' => '膀胱螺', 'category' => 'snail', 'price' => 70, 'asset_key' => 'bladder-snail'],
        ['id' => 'pond-snail', 'name' => '椎实螺', 'category' => 'snail', 'price' => 80, 'asset_key' => 'pond-snail'],
        ['id' => 'turbo-snail', 'name' => '蝾螺', 'category' => 'snail', 'price' => 160, 'asset_key' => 'turbo-snail'],
        ['id' => 'trochus-snail', 'name' => '马蹄螺', 'category' => 'snail', 'price' => 150, 'asset_key' => 'trochus-snail'],
        ['id' => 'astrea-snail', 'name' => '星螺', 'category' => 'snail', 'price' => 170, 'asset_key' => 'astrea-snail'],
        ['id' => 'nassarius-snail', 'name' => '织纹螺', 'category' => 'snail', 'price' => 120, 'asset_key' => 'nassarius-snail'],
        ['id' => 'cerith-snail', 'name' => '蟹守螺', 'category' => 'snail', 'price' => 130, 'asset_key' => 'cerith-snail'],
        ['id' => 'money-cowrie', 'name' => '货贝', 'category' => 'snail', 'price' => 180, 'asset_key' => 'money-cowrie'],
        ['id' => 'tiger-cowrie', 'name' => '虎斑宝贝', 'category' => 'snail', 'price' => 210, 'asset_key' => 'tiger-cowrie'],
        ['id' => 'sundial-snail', 'name' => '车轮螺', 'category' => 'snail', 'price' => 190, 'asset_key' => 'sundial-snail'],
        ['id' => 'strawberry-top-snail', 'name' => '草莓钟螺', 'category' => 'snail', 'price' => 180, 'asset_key' => 'strawberry-top-snail'],
        ['id' => 'wentletrap-snail', 'name' => '梯螺', 'category' => 'snail', 'price' => 200, 'asset_key' => 'wentletrap-snail'],
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
        ['id' => 'moon-jellyfish', 'name' => '月亮水母', 'category' => 'jellyfish', 'price' => 260, 'asset_key' => 'moon-jellyfish'],
        ['id' => 'lion-mane-jellyfish', 'name' => '狮鬃水母', 'category' => 'jellyfish', 'price' => 520, 'asset_key' => 'lion-mane-jellyfish'],
        ['id' => 'blue-blubber-jellyfish', 'name' => '蓝脂水母', 'category' => 'jellyfish', 'price' => 330, 'asset_key' => 'blue-blubber-jellyfish'],
        ['id' => 'cannonball-jellyfish', 'name' => '炮弹水母', 'category' => 'jellyfish', 'price' => 300, 'asset_key' => 'cannonball-jellyfish'],
        ['id' => 'upside-down-jellyfish', 'name' => '倒立水母', 'category' => 'jellyfish', 'price' => 360, 'asset_key' => 'upside-down-jellyfish'],
        ['id' => 'flower-hat-jellyfish', 'name' => '花笠水母', 'category' => 'jellyfish', 'price' => 460, 'asset_key' => 'flower-hat-jellyfish'],
        ['id' => 'sea-nettle', 'name' => '海刺水母', 'category' => 'jellyfish', 'price' => 390, 'asset_key' => 'sea-nettle'],
        ['id' => 'crystal-jellyfish', 'name' => '水晶水母', 'category' => 'jellyfish', 'price' => 480, 'asset_key' => 'crystal-jellyfish'],
        ['id' => 'comb-jelly', 'name' => '栉水母', 'category' => 'jellyfish', 'price' => 340, 'asset_key' => 'comb-jelly'],
        ['id' => 'portuguese-man-o-war', 'name' => '僧帽水母', 'category' => 'jellyfish', 'price' => 500, 'asset_key' => 'portuguese-man-o-war'],
        ['id' => AQUARIUM_CARD_PACK_ID, 'name' => '水族卡包', 'category' => 'pack', 'price' => $cardPackPrice, 'asset_key' => 'aquarium-card-pack'],
    ]];
}

function aquarium_item(string $id, ?PDO $pdo = null): array
{
    foreach (aquarium_catalog($pdo)['items'] as $item) {
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
            'inventory' => [], 'scene' => [], 'wallet' => [], 'fruit_backpack' => 0, 'fruit_recycle_price' => 1];
        if (is_array($profile)) {
            $state['initialized'] = (bool)$profile['initialized'];
            $state['coins'] = (int)$profile['coins'];
            $state['scene_version'] = (int)$profile['scene_version'];
            $state['scene'] = json_decode((string)$profile['scene_json'], true, 16, JSON_THROW_ON_ERROR);
            $fruit = $pdo->prepare('SELECT harvested_total FROM tree_profiles WHERE user_id = :user');
            $fruit->execute([':user' => $userId]);
            $state['fruit_backpack'] = max(0, (int)($fruit->fetchColumn() ?: 0));
            $price = $pdo->query('SELECT price_per_fruit FROM fruit_recycle_settings WHERE id = 1')->fetchColumn();
            if ($price !== false) { $state['fruit_recycle_price'] = (int)$price; }
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
    if (!in_array($action, ['initialize', 'buy', 'open_pack', 'save_scene', 'recycle_fruit'], true)) {
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
        $openedItemId = null;
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
                } elseif ($action === 'open_pack') {
                    $openedItemId = aquarium_open_pack($pdo, $userId, $operationId, $profile, $body, $now);
                } elseif ($action === 'recycle_fruit') {
                    aquarium_recycle_fruit($pdo, $userId, $operationId, $profile, $body, $now, $driver);
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
        if ($action === 'open_pack') {
            // Keep the reward in the response so the client can show a
            // reveal notification. On an idempotent retry the mutation is
            // skipped above, so recover the same reward from the wallet
            // ledger using the operation identifier.
            if ($openedItemId === null) {
                $reward = $pdo->prepare('SELECT item_id FROM aquarium_wallet_entries '
                    . 'WHERE user_id = :user AND operation_id = :operation AND reason = :reason');
                $reward->execute([':user' => $userId, ':operation' => $operationId, ':reason' => 'pack_open']);
                $value = $reward->fetchColumn();
                if (is_string($value) && $value !== '') { $openedItemId = $value; }
            }
            if ($openedItemId !== null) {
                $openedItem = aquarium_item($openedItemId, $pdo);
                // Keep the response distinct from a backpack instance: the
                // reward is a catalogue item, so clients consume `item_id`.
                // `opened_item_id` is retained as a compact compatibility
                // field for clients that only need the stable catalogue id.
                $openedItem['item_id'] = $openedItem['id'];
                unset($openedItem['id']);
                $state['opened_item'] = $openedItem;
                $state['opened_item_id'] = $openedItemId;
            }
        }
        $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function aquarium_recycle_fruit(PDO $pdo, string $userId, string $operationId, array $profile,
    array $body, DateTimeImmutable $now, string $driver): void
{
    $quantity = $body['quantity'] ?? null;
    if (!is_int($quantity) || $quantity < 1 || $quantity > 1000000000) {
        throw new ApiError('invalid_quantity', 'A positive fruit quantity is required.', 400);
    }
    $price = (int)($pdo->query('SELECT price_per_fruit FROM fruit_recycle_settings WHERE id = 1')->fetchColumn() ?: 0);
    $tree = $pdo->prepare('SELECT fruit_total, harvested_total FROM tree_profiles WHERE user_id = :user'
        . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
    $tree->execute([':user' => $userId]);
    $state = $tree->fetch(PDO::FETCH_ASSOC);
    if (!is_array($state) || (int)$state['harvested_total'] < $quantity) {
        throw new ApiError('insufficient_fruit', 'Not enough fruits in the backpack.', 409);
    }
    $coins = (int)$profile['coins'] + ($quantity * $price);
    if ($coins > 2147483647) { throw new ApiError('coin_limit', 'The aquarium wallet limit was exceeded.', 409); }
    $pdo->prepare('UPDATE tree_profiles SET fruit_total = fruit_total - :quantity, harvested_total = harvested_total - :quantity, state_version = state_version + 1, updated_at = :now WHERE user_id = :user')
        ->execute([':quantity' => $quantity, ':now' => auth_sql_time($now), ':user' => $userId]);
    $pdo->prepare('UPDATE aquarium_profiles SET coins = :coins, updated_at = :now WHERE user_id = :user')
        ->execute([':coins' => $coins, ':now' => auth_sql_time($now), ':user' => $userId]);
    aquarium_record_wallet($pdo, $userId, $operationId, $quantity * $price, $coins, 'fruit_recycle', null, $now);
}

function aquarium_buy(PDO $pdo, string $userId, string $operationId, array $profile, array $body, DateTimeImmutable $now): void
{
    // Accept the previous catalog version for already queued purchases from
    // older clients. The server still validates the item and price below.
    $catalogVersion = aquarium_int($body, 'catalog_version');
    if (!in_array($catalogVersion, [2, 3, 4, 5, 6, 7, AQUARIUM_CATALOG_VERSION], true)) {
        throw new ApiError('catalog_conflict', 'The aquarium catalog changed. Refresh the catalog.', 409);
    }
    $item = aquarium_item(aquarium_string($body, 'item_id'), $pdo);
    // Aquarium life is awarded by opening a card pack. Keep the pack itself
    // as the only directly purchasable catalogue item.
    if ($item['category'] !== 'pack' && $catalogVersion === AQUARIUM_CATALOG_VERSION) {
        throw new ApiError('draw_only', 'Aquarium items can only be obtained from card packs.', 409);
    }
    $balance = (int)$profile['coins'] - $item['price'];
    if ($balance < 0) { throw new ApiError('insufficient_coins', 'Not enough aquarium coins.', 409); }
    $pdo->prepare('UPDATE aquarium_profiles SET coins = :coins, updated_at = :now WHERE user_id = :user')
        ->execute([':coins' => $balance, ':now' => auth_sql_time($now), ':user' => $userId]);
    $pdo->prepare('INSERT INTO aquarium_inventory (id, user_id, item_id, created_at) VALUES (:id, :user, :item, :created)')
        ->execute([':id' => auth_random_id(), ':user' => $userId, ':item' => $item['id'], ':created' => auth_sql_time($now)]);
    aquarium_record_wallet($pdo, $userId, $operationId, -$item['price'], $balance, 'purchase', $item['id'], $now);
}

/**
 * Consume one owned water-aquarium card pack and grant one random catalogue
 * item. The caller already holds the account transaction and profile lock;
 * inventory row locking keeps this safe when two devices open packs at once.
 *
 * @return string The granted catalogue item identifier, included in the
 *                mutation response as `opened_item`.
 */
function aquarium_open_pack(PDO $pdo, string $userId, string $operationId, array $profile,
    array $body, DateTimeImmutable $now): string
{
    $packInstanceId = null;
    if (isset($body['pack_instance_id']) && is_string($body['pack_instance_id'])) {
        $packInstanceId = $body['pack_instance_id'];
    } elseif (isset($body['item_id']) && is_string($body['item_id'])) {
        // `item_id` is accepted for a short compatibility window so clients
        // that use the generic aquarium command field can open a pack too.
        $packInstanceId = $body['item_id'];
    }
    if ($packInstanceId === null || !preg_match('/\A[a-f0-9]{32}\z/D', $packInstanceId)) {
        throw new ApiError('invalid_pack', 'A valid water-aquarium card pack instance is required.', 400);
    }

    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $owned = $pdo->prepare('SELECT item_id FROM aquarium_inventory WHERE user_id = :user AND id = :id' . $lock);
    $owned->execute([':user' => $userId, ':id' => strtolower($packInstanceId)]);
    $itemId = $owned->fetchColumn();
    if (!is_string($itemId) || $itemId !== AQUARIUM_CARD_PACK_ID) {
        throw new ApiError('pack_not_found', 'The water-aquarium card pack is no longer in the backpack.', 409);
    }

    // Packs never award another pack. All regular fish, plants, decorations
    // and other aquarium life remain eligible for the random reward.
    $rewards = array_values(array_filter(aquarium_catalog()['items'],
        static fn(array $item): bool => $item['category'] !== 'pack'));
    if ($rewards === []) { throw new ApiError('pack_unavailable', 'No aquarium rewards are configured.', 503); }
    $reward = $rewards[random_int(0, count($rewards) - 1)];

    $deleted = $pdo->prepare('DELETE FROM aquarium_inventory WHERE user_id = :user AND id = :id');
    $deleted->execute([':user' => $userId, ':id' => strtolower($packInstanceId)]);
    if ($deleted->rowCount() !== 1) {
        throw new ApiError('pack_not_found', 'The water-aquarium card pack is no longer in the backpack.', 409);
    }
    $pdo->prepare('INSERT INTO aquarium_inventory (id, user_id, item_id, created_at) VALUES (:id, :user, :item, :created)')
        ->execute([':id' => auth_random_id(), ':user' => $userId, ':item' => $reward['id'], ':created' => auth_sql_time($now)]);
    aquarium_record_wallet($pdo, $userId, $operationId, 0, (int)$profile['coins'], 'pack_open', $reward['id'], $now);
    $pdo->prepare('UPDATE aquarium_profiles SET updated_at = :now WHERE user_id = :user')
        ->execute([':now' => auth_sql_time($now), ':user' => $userId]);
    return $reward['id'];
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
        if ($owned[$normalizedId] === AQUARIUM_CARD_PACK_ID) {
            throw new ApiError('invalid_scene', 'Card packs belong in the backpack and cannot be placed in the aquarium.', 400);
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
    if (!is_int($value) || $value < -1 || $value > 8) {
        throw new ApiError('invalid_scene', 'Aquarium layer must be an integer from -1 to 8.', 400);
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
