"""Build original aquarium SVG artwork and transparent WinUI PNG exports.

Run: uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python
     scripts/generate-aquarium-svg-assets.py

Only Svg/ and Vector/ outputs are owned by this generator. Photographic legacy
assets and their licenses are never overwritten. No network or credentials are
used by the generator; uv may fetch the two pinned rendering dependencies.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib
import io
import json
import re
from pathlib import Path
from xml.etree import ElementTree as ET

from PIL import Image, ImageDraw
import resvg_py

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "src/DeskBox/Assets/Aquarium"
SVG_DIR = ASSETS / "Svg"
PNG_DIR = ASSETS / "Vector"
PREVIEW = ROOT / ".artifacts/aquarium-svg-preview"
GROUPS = {"fish": "fish", "plants": "plants", "rocks": "rocks"}
TARGETS = {"fish": (320, 200), "plants": (200, 250), "rocks": (320, 240)}


def load_plan():
    plan = json.loads((ROOT / "docs/design/aquarium-realistic-expansion-plan.json").read_text(encoding="utf-8-sig"))
    identities = set()
    for group in GROUPS:
        if len(plan[group]) != 50:
            raise ValueError(f"Expected 50 {group}")
        for entry in plan[group]:
            key = entry["id"]
            if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", key) or key in identities:
                raise ValueError(f"Unsafe or duplicate asset key: {key}")
            identities.add(key)
    return plan


def check_svg(path):
    root = ET.parse(path).getroot()
    if root.tag != "{http://www.w3.org/2000/svg}svg" or not root.get("viewBox"):
        raise ValueError(f"Invalid SVG root: {path.name}")
    identifiers = {node.get("id") for node in root.iter() if node.get("id")}
    for node in root.iter():
        if node.tag.rsplit("}", 1)[-1] in {"image", "script", "foreignObject", "text", "filter"}:
            raise ValueError(f"Non-vector or unsupported SVG content: {path.name}")
        for attribute, value in node.attrib.items():
            if attribute.rsplit("}", 1)[-1] == "href" and not value.startswith("#"):
                raise ValueError(f"External SVG reference: {path.name}")
            for reference in re.findall(r"url\(([^)]+)\)", value):
                if not reference.startswith("#") or reference[1:] not in identifiers:
                    raise ValueError(f"Missing or external paint reference: {path.name}: {reference}")


def check_png(path):
    with Image.open(path) as image:
        if image.mode != "RGBA" or image.getchannel("A").getextrema() != (0, 255):
            raise ValueError(f"PNG needs visible content and transparent pixels: {path.name}")
        if image.width > 320 or image.height > 250:
            raise ValueError(f"Unexpected runtime image size: {path.name}")


def export_png(key, group):
    data = resvg_py.svg_to_bytes(svg_path=str(SVG_DIR / f"{key}.svg"), skip_system_fonts=True)
    with Image.open(io.BytesIO(data)) as rendered:
        image = rendered.convert("RGBA")
    bounds = image.getchannel("A").getbbox()
    if bounds is None:
        raise ValueError(f"Empty artwork: {key}")
    if bounds[0] == 0 or bounds[1] == 0 or bounds[2] == image.width or bounds[3] == image.height:
        raise ValueError(f"Artwork touches the SVG canvas edge: {key}")
    image = image.crop(bounds)
    width, height = TARGETS[group]
    image.thumbnail((width - 12, height - 12), Image.Resampling.LANCZOS)
    result = Image.new("RGBA", (width, height))
    top = (height - image.height) // 2 if group == "fish" else height - image.height - 6
    result.alpha_composite(image, ((width - image.width) // 2, top))
    target = PNG_DIR / f"{key}.png"
    result.save(target, optimize=True)
    check_png(target)


def contact_sheet(entries, group):
    tile_width, tile_height, columns = 230, 205, 5
    rows = (len(entries) + columns - 1) // columns
    sheet = Image.new("RGB", (columns * tile_width, rows * tile_height), "#102f36")
    draw = ImageDraw.Draw(sheet)
    for index, entry in enumerate(entries):
        x, y = index % columns * tile_width, index // columns * tile_height
        draw.rounded_rectangle((x + 5, y + 5, x + tile_width - 5, y + tile_height - 5), 10, fill="#1a4148")
        with Image.open(PNG_DIR / f'{entry["id"]}.png') as source:
            tile = source.convert("RGBA")
        tile.thumbnail((tile_width - 22, tile_height - 37), Image.Resampling.LANCZOS)
        sheet.paste(tile, (x + (tile_width - tile.width) // 2, y + 8 + (tile_height - 37 - tile.height) // 2), tile)
        draw.text((x + 11, y + tile_height - 23), entry["id"], fill="#d4e6df")
    sheet.save(PREVIEW / f"{group}.jpg", quality=94)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--category", choices=["all", *GROUPS], default="all")
    parser.add_argument("--render-only", action="store_true", help="Keep the SVG masters, only export and validate PNGs")
    parser.add_argument("--check", action="store_true", help="Validate existing outputs without writing them")
    args = parser.parse_args()
    plan = load_plan()
    groups = list(GROUPS) if args.category == "all" else [args.category]
    if not args.check:
        for directory in [SVG_DIR, PNG_DIR, PREVIEW]:
            directory.mkdir(parents=True, exist_ok=True)
    hashes = set()
    for group in groups:
        if not args.check and not args.render_only:
            importlib.import_module(f"aquarium_svg.{GROUPS[group]}").generate(plan[group], SVG_DIR)
        for entry in plan[group]:
            key = entry["id"]
            check_svg(SVG_DIR / f"{key}.svg")
            if not args.check:
                export_png(key, group)
            check_png(PNG_DIR / f"{key}.png")
            digest = hashlib.sha256((PNG_DIR / f"{key}.png").read_bytes()).hexdigest()
            if digest in hashes:
                raise ValueError(f"Duplicate rendered artwork: {key}")
            hashes.add(digest)
        if not args.check:
            contact_sheet(plan[group], group)
        print(f"{group}: 50 SVG / 50 transparent PNG validated", flush=True)


if __name__ == "__main__":
    main()
