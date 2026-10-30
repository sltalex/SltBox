#!/usr/bin/env python3
"""Validate the client/API tree decoration catalog and generated artwork."""
from __future__ import annotations

import hashlib
import json
import re
import sys
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLIENT = ROOT / "src" / "DeskBox" / "Assets" / "Tree" / "Decorations"
SERVER = ROOT / "server" / "deskbox-api" / "config" / "tree-decorations.json"

SUBCATEGORIES = {
    "tree": {"lanterns", "hanging_garden", "bird_home", "wind_chime", "ribbons_signs", "fruit_seed", "carving_masks", "bird_cages", "celestial", "glass_charms"},
    "ground": {"flora", "stones", "water", "containers", "furniture", "lighting", "signs", "paths", "play", "craft"},
    "large": {"gates", "pavilions", "bridges", "seating", "shelters", "greenhouses", "carts", "towers", "water_features", "sculptures"},
}

# Immutable metadata for the twenty products shipped before the expansion.
LEGACY = [
    ("red-lantern", "红灯笼", "tree", "common", "red-lantern"),
    ("wishing-ribbons", "祈愿带", "tree", "common", "wishing-ribbons"),
    ("wind-chime", "风铃", "tree", "common", "wind-chime"),
    ("birdhouse", "鸟屋", "tree", "common", "birdhouse"),
    ("mushroom-cluster", "蘑菇簇", "ground", "common", "mushroom-cluster"),
    ("wildflower-bed", "野花圃", "ground", "common", "wildflower-bed"),
    ("stepping-stones", "汀步石", "ground", "common", "stepping-stones"),
    ("watering-can", "浇水壶", "ground", "common", "watering-can"),
    ("fruit-basket", "果篮", "ground", "common", "fruit-basket"),
    ("stone-lantern", "石灯", "ground", "common", "stone-lantern"),
    ("low-fence", "矮栅栏", "ground", "common", "low-fence"),
    ("small-mailbox", "小邮箱", "ground", "common", "small-mailbox"),
    ("star-lights", "星星彩灯", "tree", "rare", "star-lights"),
    ("tree-door", "树洞小门", "tree", "rare", "tree-door"),
    ("evergreen-ivy", "常青藤", "tree", "rare", "evergreen-ivy"),
    ("wooden-nameplate", "木制铭牌", "tree", "rare", "wooden-nameplate"),
    ("wooden-bench", "长椅", "large", "rare", "wooden-bench"),
    ("branch-swing", "秋千", "large", "rare", "branch-swing"),
    ("picnic-rug", "野餐垫", "large", "rare", "picnic-rug"),
    ("small-fountain", "小喷泉", "large", "rare", "small-fountain"),
]

# SHA-256 values captured from the pre-expansion release.  Keeping these
# literals means the guard remains useful after the expansion itself becomes
# the repository's new HEAD.
LEGACY_HASHES = {
    "red-lantern": ("08d7e87d12e264dd55de72b318869dd682f1eda3a1a5c57dcbc9135549c00d61", "8e2bda8170b73054bb3e3cf3f799f6b72606406bc4e89237ab0891cc982e6a05"),
    "wishing-ribbons": ("1366260b1e9592231a616544afcb04f20a2a3da282c461ae474929aba02f018b", "b03159611ff9f87d86cb2216c56acc0c87c54d34059b408c47a3ed379cc40b2d"),
    "wind-chime": ("98472604d686adc82b6fb1d5122a0b69aefba67ea85b5466b1551d01b8981c3d", "b71fe07b7b1fe4880a30bdbdd01f3365e1b2b0303861fcee239a500955e270f1"),
    "birdhouse": ("28369d479363aeafda3f2c837f2c87127ff494b2a0c78d5627406980c0791770", "c3822072e6bc7df621e1a2f7cf1322c88ad7714370877e44e7aaa3923d264602"),
    "mushroom-cluster": ("e6bc884674cdee406e2e567adda284b89b5465734fb707cdc45bd358d3cbe0d7", "153d726fb66a1041e5e0e502175356e43b33cce45eee288dd289459a8ccb1eec"),
    "wildflower-bed": ("82426a7f89535a45151187a38c5b303ecb46b79ea40ad0200343bae87452dfe0", "4961e5a428f5bf16d43c7918e08e28c70ff7f3ce1d83e15d8f4b956bc98e6fa5"),
    "stepping-stones": ("c5649e97b276bc569be47f98273374a36d61dab7a7841d6126a318dad0313fc5", "5efb439309d6a36c6339476c04114151a0b3edd212a9c146108e7b8e7dad94ad"),
    "watering-can": ("39bd9b00b2e5c5a2eede84e395b18c7b611b2aa267717ab8d48cec2937c39708", "ead40249ac246df0cb8461a8224eb51bd2e8a51b3a01d5a1df370107c2516009"),
    "fruit-basket": ("c962ddf25c5e47286b80bcdb3f7b0cb96567836975adafd55a3771cee908fc08", "c2c77d182a0b5e0fc3be3fae4f58effa782a71291fba085a4f8cb7291401ebcb"),
    "stone-lantern": ("18396b746f735abf1b630c197afc989809a5f6542e63f9bc95f954af19c80897", "da218f4186db4d4494779084fa7f4603b73851889ef7d23b4498826e586c8fc4"),
    "low-fence": ("7717140e1b1dd93fc49cc355061ff68d51114ab8613e2c7b957a868213b7d56f", "3aae02d22e6b2d77ddbfd3e04dda7fd927f14b699705c50c839caa6fa3eada5d"),
    "small-mailbox": ("520a4c9597103b8ec1ee7ac5e7853ac6bdf65bc66e34580339dd8da229bad815", "c1f3ab22f75208736e2ec02b38dff4538f7ff866c2d623244664ad4d6d32e0be"),
    "star-lights": ("9600d6a1e0b3741c51858a409517096a82ccb743f80eb9ae3670f48be542beaa", "4103e476846dc9bf7d93cd41794e0e498d477b10875680c2fe7eb9fb6e6ebf11"),
    "tree-door": ("79f7f6c184fdd37255444a0823881b6ad02c9f6201e0226390d6957f56d19770", "ddc9fc32896dd8e8d09f315e76fe13b15d7f1baed2e26f07330a19a49b0689e3"),
    "evergreen-ivy": ("eb8aec2964e0492ec23d9d715ef00c45434bb6d17d73a2abda8939812b9a3af3", "ad5747ef2725791179ae4b5b56da7a0f3589a3cbd6ae5108d5f186907afd4dc4"),
    "wooden-nameplate": ("b0e87e2dcb7f78e8a320758886333277620da8b20472260b8b487b67e408f107", "5047184533af7176db188e1c653a144c7b79b7520fb659cd9a684c9aa5b874c6"),
    "wooden-bench": ("4f46c40450a7e7f49ffc5ca9a2905674d0686abc245c07584b0b51c529f80c25", "8b463ac643ed71ec1477b5d96bfb0a9aa509c21f22cbd70df79935649ccfc4fa"),
    "branch-swing": ("4c2d8b92589f255772223cc9362e80c18020e919c557f833332368a41d9330f6", "2cf3e54beae8de554cfbdab248e1bb9e3efc8eded6f8f8763b9f2d7a3e6fc10c"),
    "picnic-rug": ("60d4d5cd21bdcdfb7d43a5ac394cb47568a96ed7b0452c6333149317d6ab4cc7", "9bddcedf0a4135cb68f265db8463fad747be09555a7484a6c073191f119051e7"),
    "small-fountain": ("c6c738fc12902427ecd21f2730a5d8352e081030f83bd1339d48b3e5778cb96f", "67407f0dc96319cb4e75a5720365a51f4f661903e44ec6956303481b33d820b1"),
}

SLUG = re.compile(r"\A[a-z0-9]+(?:[_-][a-z0-9]+)*\Z")
VARIANT_ID = re.compile(r"\A(.+)-(\d{2})\Z")
ROTATE = re.compile(r"\s*rotate\([^)]*\)", re.IGNORECASE)
WS = re.compile(r"\s+")


def fail(message: str) -> None:
    raise AssertionError(message)


def geometry_signature(path: Path) -> str:
    """Canonical XML geometry, with paint and rotate transforms removed."""
    try:
        root = ET.fromstring(path.read_text(encoding="utf-8"))
    except (OSError, ET.ParseError) as exc:
        fail(f"invalid SVG {path}: {exc}")

    def clean(element: ET.Element) -> tuple:
        tag = element.tag.rsplit("}", 1)[-1]
        attrs = []
        for key, value in element.attrib.items():
            key = key.rsplit("}", 1)[-1]
            if key in {"fill", "stroke", "color", "stop-color", "flood-color"}:
                value = "<paint>"
            elif key == "style":
                value = re.sub(r"(?:fill|stroke|color|stop-color|flood-color)\s*:[^;]+", "<paint>", value, flags=re.I)
            elif key == "transform":
                value = ROTATE.sub("", value)
            value = WS.sub(" ", value.strip())
            attrs.append((key, value))
        attrs.sort()
        text = WS.sub(" ", (element.text or "").strip())
        return tag, tuple(attrs), text, tuple(clean(child) for child in list(element))

    return repr(clean(root))


def sha256(path: Path) -> str:
    # Git may materialize the tracked SVGs with CRLF on Windows; compare the
    # canonical LF payload so line-ending conversion is not mistaken for a
    # changed legacy drawing.
    return hashlib.sha256(path.read_bytes().replace(b"\r\n", b"\n")).hexdigest()


def main() -> int:
    client_bytes = (CLIENT / "catalog.json").read_bytes()
    server_bytes = SERVER.read_bytes()
    if client_bytes != server_bytes:
        fail("client/API catalog bytes differ")
    doc = json.loads(client_bytes.decode("utf-8"))
    if doc.get("catalog_version") != 3:
        fail(f"expected catalog_version 3, got {doc.get('catalog_version')!r}")
    items = doc.get("items")
    if not isinstance(items, list) or len(items) != 20:
        fail("catalog must contain only the original twenty decorations")
    if Counter(item.get("category") for item in items) != Counter({"tree": 8, "ground": 8, "large": 4}):
        fail("unexpected category counts or retired theme in catalog")
    for field in ("id", "name", "asset_key"):
        if len({item.get(field) for item in items}) != 20:
            fail(f"catalog {field} values are not unique")
    for index, item in enumerate(items):
        legacy = LEGACY[index]
        actual = (item.get("id"), item.get("name"), item.get("category"),
                  item.get("rarity"), item.get("asset_key"))
        if actual != legacy:
            fail(f"original item changed at index {index}: {actual!r}")
        category = item["category"]
        if item.get("subcategory") not in SUBCATEGORIES[category]:
            fail(f"invalid subcategory for {item['id']}")
        for folder in ("Source", "Blur"):
            path = CLIENT / folder / f"{item['asset_key']}.svg"
            if not path.is_file():
                fail(f"missing original artwork: {path}")
            expected = LEGACY_HASHES[item["id"]][0 if folder == "Source" else 1]
            if sha256(path) != expected:
                fail(f"original artwork changed: {path.name}")
    theme_keys = {"spring-garden", "midsummer-camp", "autumn-harvest", "winter-starlight"}
    expected_files = {f"{item['asset_key']}.svg" for item in items} | {f"{key}.svg" for key in theme_keys}
    for folder in ("Source", "Blur"):
        actual_files = {path.name for path in (CLIENT / folder).glob("*.svg")}
        if actual_files != expected_files:
            fail(f"{folder} includes removed artwork or is missing original artwork: "
                 f"extra={sorted(actual_files - expected_files)[:5]}, missing={sorted(expected_files - actual_files)[:5]}")
    print("ok: 20 original decorations (tree=8, ground=8, large=4), "
          "1500 generated items removed, Source/Blur retain four historical themes, "
          f"catalog sha256={hashlib.sha256(client_bytes).hexdigest()}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except AssertionError as exc:
        print(f"error: {exc}", file=sys.stderr)
        raise SystemExit(1)
