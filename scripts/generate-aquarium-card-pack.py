"""Render the original three-card aquarium pack artwork and size previews.

Run: uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python
     scripts/generate-aquarium-card-pack.py [--check]

The root-level SVG is the editable source. This separate asset does not change
the aquarium species collection or its fixed inventory. Only the matching PNG
and .artifacts/aquarium-card-pack-preview outputs are generated here.
"""

from __future__ import annotations

import argparse
import io
import re
from pathlib import Path
from xml.etree import ElementTree as ET

import resvg_py
from PIL import Image, ImageChops, ImageDraw


ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "src/DeskBox/Assets/Aquarium"
SOURCE = ASSETS / "aquarium-card-pack.svg"
OUTPUT = ASSETS / "aquarium-card-pack.png"
PREVIEW = ROOT / ".artifacts/aquarium-card-pack-preview"
SIZE = (512, 512)
NAMESPACE = "{http://www.w3.org/2000/svg}"


def validate_source() -> None:
    root = ET.parse(SOURCE).getroot()
    if root.tag != NAMESPACE + "svg" or root.get("viewBox") != "0 0 512 512":
        raise ValueError("The card artwork requires a self-contained 512-square SVG.")
    identifiers = [node.get("id") for node in root.iter() if node.get("id")]
    if len(identifiers) != len(set(identifiers)):
        raise ValueError("Duplicate SVG IDs.")
    cards = [node.get("id") for node in root if node.tag == NAMESPACE + "g"]
    if cards != ["card-back", "card-middle", "card-front"]:
        raise ValueError("Expected precisely three ordered card layers.")
    for node in root.iter():
        if node.tag.removeprefix(NAMESPACE) in {"image", "script", "foreignObject", "text", "filter"}:
            raise ValueError("Artwork must use standalone paths, shapes and gradients only.")
        for name, value in node.attrib.items():
            if name.rsplit("}", 1)[-1] == "href" and not value.startswith("#"):
                raise ValueError("External SVG resource.")
            for reference in re.findall(r"url\(([^)]+)\)", value):
                if not reference.startswith("#") or reference[1:] not in identifiers:
                    raise ValueError("Missing or external SVG paint reference.")


def render() -> Image.Image:
    png = resvg_py.svg_to_bytes(svg_path=str(SOURCE), skip_system_fonts=True)
    with Image.open(io.BytesIO(png)) as rendered:
        result = rendered.convert("RGBA")
    validate_image(result)
    return result


def validate_image(image: Image.Image) -> None:
    if image.mode != "RGBA" or image.size != SIZE:
        raise ValueError("Card artwork must be a 512 x 512 RGBA PNG.")
    alpha = image.getchannel("A")
    if alpha.getextrema() != (0, 255):
        raise ValueError("Card artwork needs opaque faces and a transparent background.")
    bounds = alpha.getbbox()
    if bounds is None or min(bounds[0], bounds[1], 512 - bounds[2], 512 - bounds[3]) < 16:
        raise ValueError("Card artwork requires at least 16 transparent pixels at every edge.")


def fit(image: Image.Image, size: tuple[int, int]) -> Image.Image:
    result = image.copy()
    result.thumbnail(size, Image.Resampling.LANCZOS)
    return result


def previews(image: Image.Image) -> None:
    PREVIEW.mkdir(parents=True, exist_ok=True)
    preview = Image.new("RGB", (1000, 820), "#E8EFEA")
    draw = ImageDraw.Draw(preview)
    draw.rectangle((500, 0, 999, 819), fill="#102F36")
    for left, foreground in ((0, "#102F36"), (500, "#F2D59B")):
        draw.text((left + 26, 22), "AQUARIUM / THREE-CARD STACK", fill=foreground, font_size=18)
        large = fit(image, (390, 390))
        preview.paste(large, (left + 55, 56), large)
        for x, width, height in ((left + 30, 72, 64), (left + 150, 96, 76), (left + 292, 180, 140)):
            small = fit(image, (width, height))
            y = 496 + (140 - small.height) // 2
            preview.paste(small, (x + (width - small.width) // 2, y), small)
            draw.text((x, 655), f"{width} x {height}", fill=foreground, font_size=15)
        for x, width, height, label in (
            (left + 30, 68, 60, "Store 68x60"),
            (left + 180, 54, 43, "Bag 54x43"),
            (left + 330, 76, 64, "Reveal 76x64"),
        ):
            small = fit(image, (width, height))
            preview.paste(small, (x + (width - small.width) // 2, 705 + (64 - small.height) // 2), small)
            draw.text((x, 787), label, fill=foreground, font_size=13)
    preview.save(PREVIEW / "card-stack-light-dark.png")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Validate the source and verify the PNG matches it.")
    args = parser.parse_args()
    validate_source()
    artwork = render()
    if args.check:
        with Image.open(OUTPUT) as existing:
            validate_image(existing)
            if any(channel.getbbox() is not None for channel in ImageChops.difference(existing, artwork).split()):
                raise ValueError("Export is stale. Run without --check to regenerate it.")
        print("PASS: three standalone card layers; SVG/PNG pixel parity; 512 x 512 RGBA; transparent margins.")
    else:
        artwork.save(OUTPUT, optimize=True)
        previews(artwork)
        print(f"Exported {OUTPUT}")
        print(f"Alpha bounds: {artwork.getchannel('A').getbbox()}")
        print(f"Preview: {PREVIEW / 'card-stack-light-dark.png'}")


if __name__ == "__main__":
    main()
