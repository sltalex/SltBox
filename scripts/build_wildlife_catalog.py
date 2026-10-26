"""Build the reviewed 184-species wildlife catalog and SQL seed.

The provided artwork is authoritative for species membership. The catalog uses
Northern-Hemisphere seasons, local time periods, current weather, and one of
three scene placements: tree, grass, or sky.
"""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SERVER = ROOT / "server/deskbox-api/config/wildlife.json"
LEGACY = ROOT / "server/deskbox-api/config/wildlife-legacy.json"
CLIENT = ROOT / "src/DeskBox/Assets/Tree/Wildlife/catalog.json"
MIGRATION = ROOT / "server/deskbox-api/database/016_wildlife_catalog_v2.sql"

BIRD_IDS = """
swallow bullfinch sparrow magpie owl robin kingfisher woodpecker heron crow
blue-tit great-tit goldfinch chaffinch wagtail lark nightingale thrush blackbird canary
hummingbird sunbird hoopoe waxwing oriole paradise-flycatcher blue-jay jackdaw raven rook
kestrel peregrine-falcon sparrowhawk buzzard eagle osprey barn-owl little-owl eagle-owl tawny-owl
mallard mandarin-duck teal goose swan egret ibis crane stork coot moorhen seagull tern
sandpiper plover pigeon dove quail pheasant cuckoo
""".split()

ANIMAL_IDS = """
squirrel hedgehog fox rabbit deer raccoon field-mouse badger otter chipmunk flying-squirrel
red-squirrel hamster bank-vole dormouse jerboa shrew mole pika hare cottontail snow-hare
arctic-fox silver-fox weasel stoat ferret marten mink skunk civet red-panda panda koala
opossum long-eared-hedgehog porcupine beaver muskrat water-rat sea-otter bat fruit-bat tenrec
""".split()

# id | Chinese display name | active seasons | scene placement
# S/U/A/W = spring/summer/autumn/winter. Butterflies are diurnal and avoid wet weather.
BUTTERFLIES = """
adonis-blue|阿多尼斯蓝蝶|U|grass
albatross|信天翁蝶|SUAW|grass
banded-hairstreak|带纹灰蝶|U|tree
banded-peacock|带纹孔雀蝶|SUAW|tree
black-swallowtail|黑凤蝶|SU|grass
blue-banded-king-crow|蓝带紫斑蝶|SUAW|tree
blue-morpho|蓝闪蝶|SUAW|grass
blue-pansy|蓝眼蛱蝶|SUAW|grass
blue-tiger|蓝虎斑蝶|SUAW|tree
bright-copper|亮铜蝶|SUA|grass
brimstone|钩粉蝶|SA|grass
cabbage-white|菜粉蝶|SUA|grass
chocolate-pansy|巧克力蛱蝶|SUAW|grass
clouded-yellow|橙黄豆粉蝶|SUA|grass
comma|白钩蛱蝶|SUA|tree
common-blue|普通蓝蝶|SUA|grass
common-crow|紫斑蝶|SUAW|tree
common-jezebel|报喜斑粉蝶|SUAW|tree
common-map|地图蝶|SUA|tree
common-rose|红珠凤蝶|SUAW|grass
crimson-rose|红纹凤蝶|SUAW|grass
cuban-emerald|古巴翠凤蝶|SUAW|tree
dead-leaf|枯叶蝶|SUAW|tree
doris-longwing|多丽丝长翅蝶|SUAW|tree
eastern-tailed-blue|东尾蓝蝶|SUA|grass
eastern-tiger-swallowtail|东部虎凤蝶|SU|tree
emerald-swallowtail|翠凤蝶|SUAW|tree
giant-swallowtail|巨凤蝶|SU|tree
glasswing|透翅蝶|SUAW|tree
great-eggfly|大紫蛱蝶|SUAW|tree
great-mormon|大凤蝶|SUAW|tree
gulf-fritillary|湾纹蝶|SUA|grass
heliconius|袖蝶|SUAW|tree
holly-blue|冬青蓝蝶|SU|grass
idea-leuconoe|大白斑蝶|SUAW|tree
indian-leafwing|印度枯叶蝶|SUAW|tree
julia|朱丽亚蝶|SUAW|grass
karner-blue|卡纳蓝蝶|U|grass
lemon-pansy|柠檬蛱蝶|SUAW|grass
little-blue|小蓝蝶|SU|grass
long-tailed-blue|长尾蓝蝶|SUA|grass
malachite|孔雀石蝶|SUAW|tree
marsh-fritillary|沼泽网蛱蝶|U|grass
mazarine-blue|马扎林蓝蝶|U|grass
meadow-brown|草地褐蝶|U|grass
monarch|帝王蝶|UA|grass
orange-oakleaf|橙枯叶蝶|SUAW|tree
painted-lady|小红蛱蝶|SUA|grass
paper-kite|纸鸢蝶|SUAW|tree
peacock|孔雀蝶|SUA|tree
philippine-leafwing|菲律宾枯叶蝶|SUAW|tree
pink-rose|粉红珠凤蝶|SUAW|grass
pipevine-swallowtail|麝凤蝶|SU|tree
plain-tiger|虎斑蝶|SUAW|grass
postman|邮差蝶|SUAW|tree
purple-emperor|紫闪蛱蝶|U|tree
purple-hairstreak|紫灰蝶|U|tree
queen|女王蝶|SUA|grass
red-admiral|红蛱蝶|SUA|tree
red-lacewing|红锯蛱蝶|SUAW|tree
ringlet|环蝶|U|grass
rusty-tipped-page|锈尖蛱蝶|SUAW|tree
scarlet-mormon|猩红凤蝶|SUAW|tree
silver-washed-fritillary|银纹豹蛱蝶|U|tree
silvery-blue|银蓝蝶|SU|grass
small-tortoiseshell|小荨麻蛱蝶|SUA|tree
southern-dogface|南方狗脸蝶|SUA|grass
spicebush-swallowtail|香樟凤蝶|SU|tree
striped-tiger|条纹虎斑蝶|SUAW|grass
sulphur|硫黄蝶|SUA|grass
swallowtail|凤蝶|SU|tree
tawny-coster|黄襟蛱蝶|SUAW|grass
tree-nymph|树仙蝶|SUAW|tree
tropical-leafwing|热带枯叶蝶|SUAW|tree
white-admiral|白蛱蝶|U|tree
white-peacock|白孔雀蝶|SUAW|grass
yellow-glassy-tiger|黄透翅虎斑蝶|SUAW|tree
yellow-swallowtail|黄凤蝶|SU|tree
zebra-longwing|斑马长翅蝶|SUAW|tree
zebra-swallowtail|斑马凤蝶|SU|tree
"""

SEASONS = dict(zip("SUAW", ("spring", "summer", "autumn", "winter")))

SKY_IDS = {
    "swallow", "kestrel", "peregrine-falcon", "sparrowhawk", "buzzard", "eagle", "osprey",
    "goose", "swan", "seagull", "tern", "bat", "fruit-bat",
    "owl", "barn-owl", "little-owl", "eagle-owl", "tawny-owl",
}
GRASS_BIRDS = {
    "heron", "mallard", "mandarin-duck", "teal", "egret", "ibis", "crane", "stork",
    "coot", "moorhen", "sandpiper", "plover", "quail", "pheasant", "wagtail", "lark",
}
TREE_ANIMALS = {
    "squirrel", "chipmunk", "flying-squirrel", "red-squirrel", "dormouse", "marten",
    "civet", "red-panda", "koala", "opossum",
}

# The first hand-authored catalog profiles predate the motion metadata field.
# Keep their established client trajectories while naming the ecological action
# explicitly for the versioned server catalog.
ACTION_OVERRIDES = {
    "swallow": "glide", "bullfinch": "perch", "sparrow": "forage", "magpie": "hop",
    "owl": "glide", "robin": "walk", "kingfisher": "dive", "woodpecker": "peck",
    "heron": "wade", "crow": "glide", "squirrel": "climb", "hedgehog": "walk",
    "fox": "stalk", "rabbit": "hop", "deer": "graze", "raccoon": "forage",
    "field-mouse": "scurry", "badger": "walk", "otter": "slide",
}

PREFERRED_WEATHER_OVERRIDES = {
    "mandarin-duck": ["clear"],
    "goose": ["cloudy"],
    "swan": ["clear"],
    "crane": ["clear"],
    "snow-hare": ["snow"],
    "arctic-fox": ["snow"],
}


def placement(species_id: str, category: str) -> str:
    if species_id in SKY_IDS:
        return "sky"
    if category == "飞鸟":
        return "grass" if species_id in GRASS_BIRDS else "tree"
    if category == "小动物":
        return "tree" if species_id in TREE_ANIMALS else "grass"
    raise ValueError(species_id)


def retained_species(existing: dict[str, dict], ids: list[str], category: str) -> list[dict]:
    result: list[dict] = []
    for species_id in ids:
        item = dict(existing[species_id])
        item["category"] = category
        item["placement"] = placement(species_id, category)
        item["weathers"] = [value for value in item["weathers"] if value != "thunderstorm"]
        if species_id in ACTION_OVERRIDES:
            item["motion"] = ACTION_OVERRIDES[species_id]
        if species_id in PREFERRED_WEATHER_OVERRIDES:
            item["preferred_weathers"] = PREFERRED_WEATHER_OVERRIDES[species_id]
        if species_id == "rabbit":
            item["periods"] = ["morning", "dusk", "night"]
            item["weathers"] = ["clear", "cloudy", "fog"]
            item["preferred_weathers"] = ["cloudy"]
        result.append(item)
    return result


def butterfly_species() -> list[dict]:
    result: list[dict] = []
    for row in BUTTERFLIES.strip().splitlines():
        species_id, name, seasons, scene = row.split("|")
        result.append({
            "id": species_id,
            "name": name,
            "category": "蝴蝶",
            "seasons": [SEASONS[code] for code in seasons],
            "periods": ["morning", "day"],
            "weathers": ["clear", "cloudy"],
            "preferred_weathers": ["clear"],
            "placement": scene,
            "motion": "butterfly",
        })
    return result


def sql_text(versioned: list[tuple[int, dict]]) -> str:
    def literal(value: str) -> str:
        return "'" + value.replace("'", "''") + "'"

    values = []
    for version, item in versioned:
        fields = [
            str(version), literal(item["id"]), literal(item["name"]), literal(item["category"]),
            literal(json.dumps(item["seasons"], ensure_ascii=False, separators=(",", ":"))),
            literal(json.dumps(item["periods"], ensure_ascii=False, separators=(",", ":"))),
            literal(json.dumps(item["weathers"], ensure_ascii=False, separators=(",", ":"))),
            literal(json.dumps(item["preferred_weathers"], ensure_ascii=False, separators=(",", ":"))),
            literal(item["placement"]), literal(item.get("motion", "")), "CURRENT_TIMESTAMP",
        ]
        values.append("(" + ",".join(fields) + ")")
    return """-- Versioned wildlife catalog. Generated by scripts/build_wildlife_catalog.py.
CREATE TABLE IF NOT EXISTS wildlife_catalog_entries (
    catalog_version TINYINT UNSIGNED NOT NULL,
    species_id VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL,
    seasons_json JSON NOT NULL,
    periods_json JSON NOT NULL,
    weathers_json JSON NOT NULL,
    preferred_weathers_json JSON NOT NULL,
    placement VARCHAR(16) NOT NULL,
    motion VARCHAR(32) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (catalog_version, species_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO wildlife_catalog_entries
    (catalog_version,species_id,name,category,seasons_json,periods_json,weathers_json,
     preferred_weathers_json,placement,motion,updated_at)
VALUES
""" + ",\n".join(values) + "\nON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),\n" \
        "seasons_json=VALUES(seasons_json),periods_json=VALUES(periods_json),\n" \
        "weathers_json=VALUES(weathers_json),preferred_weathers_json=VALUES(preferred_weathers_json),\n" \
        "placement=VALUES(placement),motion=VALUES(motion),updated_at=VALUES(updated_at);\n"


def main() -> None:
    source = CLIENT if CLIENT.exists() else SERVER
    existing_items = json.loads(source.read_text(encoding="utf-8"))["items"]
    existing = {item["id"]: item for item in existing_items}
    missing = sorted((set(BIRD_IDS) | set(ANIMAL_IDS)) - existing.keys())
    if missing:
        raise ValueError(f"Retained catalog entries are missing: {missing}")

    items = retained_species(existing, BIRD_IDS, "飞鸟")
    items += retained_species(existing, ANIMAL_IDS, "小动物")
    items += butterfly_species()
    ids = [item["id"] for item in items]
    assert len(ids) == len(set(ids)) == 184
    assert sum(item["category"] == "飞鸟" for item in items) == 60
    assert sum(item["category"] == "小动物" for item in items) == 44
    assert sum(item["category"] == "蝴蝶" for item in items) == 80
    assert all(item["placement"] in {"tree", "grass", "sky"} for item in items)
    assert all(item["seasons"] and item["periods"] and item["weathers"] and
               item["preferred_weathers"] and item["motion"] for item in items)
    assert all(set(item["preferred_weathers"]).issubset(item["weathers"]) for item in items)

    legacy_ids = set(BIRD_IDS) | set(ANIMAL_IDS) | {"blue-morpho", "cabbage-white", "glasswing"}
    legacy = [item for item in items if item["id"] in legacy_ids]
    assert len(legacy) == 107

    canonical = json.dumps({"catalog_version": 2, "items": items}, ensure_ascii=False, indent=2) + "\n"
    compatibility = json.dumps({"catalog_version": 1, "items": legacy}, ensure_ascii=False, indent=2) + "\n"
    SERVER.write_text(canonical, encoding="utf-8")
    CLIENT.write_text(canonical, encoding="utf-8")
    LEGACY.write_text(compatibility, encoding="utf-8")
    MIGRATION.write_text(sql_text([(1, item) for item in legacy] + [(2, item) for item in items]), encoding="utf-8")
    print("Generated v2 catalog: 60 birds, 44 small animals, 80 butterflies; v1 compatibility: 107 species.")


if __name__ == "__main__":
    main()
