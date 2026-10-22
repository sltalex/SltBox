"""Rebuild centered transparent fruit PNGs from the supplied 20x10 atlas.

The atlas cells are already aligned to the 20-column grid.  Cropping with a
horizontal offset causes the right edge of a cell to include part of the next
fruit, so each source cell must be read from its nominal grid boundary.
"""

from __future__ import annotations

import json
from pathlib import Path

from PIL import Image, ImageFilter


ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "src" / "DeskBox" / "Assets" / "Tree"
MANIFEST = json.loads((ASSETS / "fruits-manifest.json").read_text(encoding="utf-8"))
ATLAS = Image.open(ASSETS / "fruits-atlas.png").convert("RGBA")
OUT = ASSETS / "Fruits"
CELL_W = 176
CELL_H = ATLAS.height / 10
OUTPUT_SIZE = 176


def build(item: dict) -> None:
    index = item["atlasIndex"]
    col, row = index % 20, index // 20
    left = col * CELL_W
    top = round(row * CELL_H)
    crop = Image.new("RGBA", (CELL_W, round(CELL_H)))
    source = ATLAS.crop((left, top, min(left + CELL_W, ATLAS.width), round((row + 1) * CELL_H)))
    crop.paste(source, (0, 0))

    # The source background has alpha near zero, while painted subjects are
    # nearly opaque. Remove low-alpha glow without destroying edge antialiasing.
    alpha = crop.getchannel("A")
    alpha = alpha.point(lambda value: 0 if value < 18 else value)
    crop.putalpha(alpha)
    bbox = alpha.getbbox()
    if bbox is None:
        raise ValueError(f"Empty cutout: {item['id']}")
    subject = crop.crop(bbox)
    if subject.width > OUTPUT_SIZE - 8 or subject.height > OUTPUT_SIZE - 8:
        subject.thumbnail((OUTPUT_SIZE - 8, OUTPUT_SIZE - 8), Image.Resampling.LANCZOS)
    output = Image.new("RGBA", (OUTPUT_SIZE, OUTPUT_SIZE))
    output.alpha_composite(subject, ((OUTPUT_SIZE - subject.width) // 2, (OUTPUT_SIZE - subject.height) // 2))
    output.save(OUT / f"{item['id']}.png", optimize=True)

    silhouette = Image.new("RGBA", output.size, (78, 84, 91, 0))
    shape = output.getchannel("A").filter(ImageFilter.GaussianBlur(7))
    silhouette.putalpha(shape.point(lambda value: min(215, value)))
    silhouette.save(OUT / f"{item['id']}-silhouette.png", optimize=True)


if __name__ == "__main__":
    OUT.mkdir(parents=True, exist_ok=True)
    if ATLAS.size != (3520, 2336):
        raise ValueError(f"Unexpected atlas dimensions: {ATLAS.size}")
    for entry in MANIFEST["items"]:
        build(entry)
    print(f"Rebuilt {len(MANIFEST['items'])} transparent fruit cutouts and silhouettes")
