"""Build the shared 180-species server/client catalogue from reviewed species rows.

Rows: stable id | Chinese name | body shape / motion family | season codes |
local-time period codes | weather codes. Existing 32 entries keep their rules.
S/U/A/W = spring/summer/autumn/winter; M/D/E/N = morning/day/dusk/night;
C/O/F/D/R/S/T = clear/cloudy/fog/drizzle/rain/snow/thunderstorm.
"""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVER = ROOT / "server/deskbox-api/config/wildlife.json"
CLIENT = ROOT / "src/DeskBox/Assets/Tree/Wildlife/catalog.json"
BASE_IDS = (
    "butterfly honeybee ladybug swallow dragonfly firefly frog cricket squirrel hedgehog "
    "bullfinch fox sparrow magpie owl rabbit mantis cicada moth grasshopper robin "
    "kingfisher woodpecker heron crow deer raccoon turtle field-mouse badger otter snail"
).split()

EXTRA = {
    "昆虫": """
swallowtail-butterfly|凤蝶|butterfly|SU|MDE|CO
monarch-butterfly|帝王蝶|butterfly|UA|MD|CO
blue-morpho|蓝闪蝶|butterfly|SU|MD|CO
cabbage-white|菜粉蝶|butterfly|SUA|MD|CO
peacock-butterfly|孔雀蝶|butterfly|SA|MD|CO
fritillary|珍珠蛱蝶|butterfly|UA|MD|CO
skipper|弄蝶|butterfly|SU|MDE|CO
glasswing|透翅蝶|butterfly|SU|MD|COF
bumblebee|熊蜂|bee|SU|MD|CO
carpenter-bee|木蜂|bee|SU|MD|CO
leafcutter-bee|切叶蜂|bee|SU|MD|CO
paper-wasp|长脚胡蜂|bee|UA|MD|CO
hornet|虎头蜂|bee|UA|MDE|CO
hoverfly|食蚜蝇|fly|SUA|MD|CO
flower-fly|花蝇|fly|SU|MD|CO
stag-beetle|锹甲|beetle|UA|EN|COF
rhinoceros-beetle|独角仙|beetle|U|EN|COF
jewel-beetle|吉丁虫|beetle|SU|MD|CO
longhorn-beetle|天牛|beetle|UA|MDE|CO
scarab|金龟子|beetle|SUA|MDE|CO
weevil|象鼻虫|beetle|SA|MD|CO
tiger-beetle|虎甲|beetle|SU|MD|CO
dung-beetle|蜣螂|beetle|UA|EN|COF
click-beetle|叩头虫|beetle|SU|EN|COF
water-beetle|龙虱|waterbug|SU|DE|ODR
red-dragonfly|赤蜻蜓|dragonfly|UA|DE|CO
emperor-dragonfly|王蜻蜓|dragonfly|U|MD|CO
damselfly|豆娘|dragonfly|SU|MD|COD
banded-demoiselle|带翅豆娘|dragonfly|SU|DE|CO
green-darner|碧伟蜓|dragonfly|SU|MD|CO
orchid-mantis|兰花螳螂|mantis|SU|MD|CO
stick-insect|竹节虫|stick|UA|EN|COF
leaf-insect|叶䗛|stick|SU|MDE|CO
katydid|螽斯|grasshopper|UA|EN|COF
locust|蝗虫|grasshopper|UA|MD|CO
leafhopper|叶蝉|grasshopper|SUA|MD|CO
treehopper|角蝉|grasshopper|UA|MD|CO
mole-cricket|蝼蛄|grasshopper|SU|EN|ODRF
luna-moth|月形天蚕蛾|moth|SU|EN|COF
hawk-moth|天蛾|moth|SU|EN|COF
silk-moth|家蚕蛾|moth|SU|EN|COF
atlas-moth|皇蛾|moth|U|EN|COF
emperor-moth|大孔雀蛾|moth|SU|EN|COF
ant|蚂蚁|ant|SUA|MD|COD
lacewing|草蛉|fly|SU|EN|COF
mayfly|蜉蝣|fly|SU|DE|ODRF
water-strider|水黾|waterbug|SU|MD|ODR
earwig|蠼螋|beetle|SUA|EN|ODRF
termite|白蚁|ant|SU|EN|ODRF
scorpionfly|蝎蛉|fly|SU|MD|CO
""",
    "飞鸟": """
blue-tit|蓝山雀|songbird|SW|MD|COS
great-tit|大山雀|songbird|SAW|MD|COS
goldfinch|金翅雀|songbird|SA|MD|CO
chaffinch|苍头燕雀|songbird|SAW|MD|COS
wagtail|鹡鸰|longtail|SUA|MDE|COD
lark|云雀|songbird|SUA|MD|CO
nightingale|夜莺|songbird|SU|EN|COF
thrush|画眉|songbird|SA|MDE|CO
blackbird|乌鸫|songbird|SAW|MD|COF
canary|金丝雀|songbird|SU|MD|CO
hummingbird|蜂鸟|nectar|SU|MD|CO
sunbird|太阳鸟|nectar|SU|MD|CO
hoopoe|戴胜|crested|SUA|MD|CO
waxwing|太平鸟|crested|AW|MD|COS
oriole|黄鹂|songbird|SU|MD|CO
paradise-flycatcher|寿带鸟|longtail|SU|MD|CO
blue-jay|蓝松鸦|crested|SA|MD|CO
jackdaw|寒鸦|corvid|AW|MDE|COFS
raven|渡鸦|corvid|AW|MDE|COFS
rook|秃鼻乌鸦|corvid|AW|MD|COFS
kestrel|红隼|raptor|SAW|MD|CO
peregrine-falcon|游隼|raptor|SAW|MD|CO
sparrowhawk|雀鹰|raptor|SAW|MDE|CO
buzzard|鵟|raptor|AW|MD|COFS
eagle|雕|raptor|AW|MD|CO
osprey|鹗|raptor|SU|MD|COD
barn-owl|仓鸮|owl|SAW|EN|COF
little-owl|小鸮|owl|SAW|EN|COF
eagle-owl|雕鸮|owl|AW|EN|COFS
tawny-owl|灰林鸮|owl|AW|EN|COF
mallard|绿头鸭|duck|SUA|MD|ODR
mandarin-duck|鸳鸯|duck|SU|MD|COD
teal|绿翅鸭|duck|SA|MD|ODR
goose|雁|wader|SA|MDE|COFS
swan|天鹅|wader|AW|MD|COFS
egret|白鹭|wader|SU|MD|ODR
ibis|朱鹮|wader|SU|MD|COD
crane|丹顶鹤|wader|AW|MD|COFS
stork|白鹳|wader|SU|MD|COD
coot|骨顶鸡|duck|SU|MD|ODR
moorhen|黑水鸡|duck|SU|DE|ODR
seagull|海鸥|wader|SAW|MD|COD
tern|燕鸥|longtail|SU|MD|COD
sandpiper|鹬|wader|SA|MDE|ODR
plover|鸻|wader|SA|MD|COD
pigeon|鸽子|songbird|SUAW|MDE|COD
dove|斑鸠|songbird|SUAW|MDE|COD
quail|鹌鹑|groundbird|SA|MDE|CO
pheasant|雉鸡|groundbird|SAW|MD|CO
cuckoo|杜鹃|longtail|SU|MDE|CO
""",
    "小动物": """
chipmunk|花栗鼠|rodent|SA|MD|CO
flying-squirrel|鼯鼠|rodent|SA|EN|COF
red-squirrel|红松鼠|rodent|SAW|MD|COS
hamster|仓鼠|rodent|SA|EN|COF
bank-vole|红背䶄|rodent|AW|EN|COFS
dormouse|睡鼠|rodent|SU|EN|COF
jerboa|跳鼠|rodent|U|EN|CO
shrew|鼩鼱|rodent|SA|EN|COF
mole|鼹鼠|rodent|SUA|EN|ODRF
pika|鼠兔|rabbit|AW|MD|COS
hare|野兔|rabbit|SAW|MDE|COS
cottontail|棉尾兔|rabbit|SU|MDE|CO
snow-hare|雪兔|rabbit|W|MDE|OS
arctic-fox|北极狐|fox|W|EN|OS
silver-fox|银狐|fox|AW|EN|COFS
weasel|黄鼬|mustelid|SAW|EN|COF
stoat|白鼬|mustelid|W|EN|COS
ferret|雪貂|mustelid|AW|EN|COFS
marten|松貂|mustelid|SAW|EN|COF
mink|水貂|mustelid|SU|DE|ODR
skunk|臭鼬|mustelid|SUA|EN|COF
civet|果子狸|raccoon|SU|EN|COF
red-panda|小熊猫|raccoon|SAW|MD|COF
panda|熊猫|raccoon|SA|MD|CO
koala|考拉|raccoon|SU|MD|CO
opossum|负鼠|raccoon|SUA|EN|COF
long-eared-hedgehog|长耳刺猬|hedgehog|UA|EN|COF
porcupine|豪猪|hedgehog|AW|EN|COF
beaver|河狸|beaver|SU|DE|ODR
muskrat|麝鼠|beaver|SU|DE|ODR
water-rat|水鼠|rodent|SU|DE|ODR
sea-otter|海獭|mustelid|SU|DE|ODR
bat|蝙蝠|bat|SUA|EN|COF
fruit-bat|果蝠|bat|SU|EN|COF
tenrec|马岛猬|hedgehog|SU|EN|COF
box-turtle|箱龟|turtle|SU|MD|COD
tortoise|陆龟|turtle|SU|MD|CO
gecko|壁虎|lizard|SU|EN|COF
lizard|蜥蜴|lizard|SU|MD|CO
chameleon|变色龙|lizard|SU|MD|CO
salamander|蝾螈|lizard|SU|EN|ODRF
newt|水螈|lizard|SU|DE|ODR
tree-frog|树蛙|frog|SU|EN|ODRF
toad|蟾蜍|frog|SU|EN|ODRF
axolotl|美西螈|frog|SU|DE|ODR
snake|游蛇|snake|SU|MD|CO
grass-snake|草游蛇|snake|SU|DE|COD
slug|蛞蝓|snail|SUA|EN|ODRF
""",
}

SEASONS = dict(zip("SUAW", ("spring", "summer", "autumn", "winter")))
PERIODS = dict(zip("MDEN", ("morning", "day", "dusk", "night")))
WEATHERS = dict(zip("COFDRST", ("clear", "cloudy", "fog", "drizzle", "rain", "snow", "thunderstorm")))
PREFERRED = {
    "waterbug": "rain", "duck": "rain", "wader": "drizzle", "frog": "rain",
    "snail": "rain", "beaver": "rain", "owl": "fog", "bat": "fog",
    "moth": "fog", "rodent": "cloudy", "fox": "fog", "mustelid": "cloudy",
}


def added_species() -> list[dict]:
    result = []
    for category, rows in EXTRA.items():
        for row in rows.strip().splitlines():
            species_id, name, shape, seasons, periods, weathers = row.split("|")
            allowed_weather = [WEATHERS[code] for code in weathers]
            preferred = PREFERRED.get(shape, "clear")
            result.append({
                "id": species_id, "name": name, "category": category,
                "seasons": [SEASONS[code] for code in seasons],
                "periods": [PERIODS[code] for code in periods],
                "weathers": allowed_weather,
                "preferred_weathers": [preferred] if preferred in allowed_weather else [],
                "motion": shape,
            })
    return result


def build() -> list[dict]:
    existing = json.loads(SERVER.read_text(encoding="utf-8"))["items"]
    base = [item for item in existing if item["id"] in BASE_IDS]
    assert [item["id"] for item in base] == BASE_IDS, "The original 32 species changed order."
    items = base + added_species()
    ids = [item["id"] for item in items]
    assert len(ids) == len(set(ids)) == 180
    for category in EXTRA:
        assert sum(item["category"] == category for item in items) == 60, category
    return items


def main() -> None:
    items = build()
    data = json.dumps({"items": items}, ensure_ascii=False, indent=2) + "\n"
    SERVER.write_text(data, encoding="utf-8")
    CLIENT.parent.mkdir(parents=True, exist_ok=True)
    CLIENT.write_text(data, encoding="utf-8")
    print("Generated 180 animal records: 60 insects, 60 birds, 60 small animals.")


if __name__ == "__main__":
    main()
