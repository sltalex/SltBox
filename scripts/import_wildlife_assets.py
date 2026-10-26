"""Validate and import paired bright and unrevealed wildlife PNGs.

Usage: python scripts/import_wildlife_assets.py C:/path/to/bright C:/path/to/blur
Both folders must contain one PNG named by each stable species ID. Files are
copied byte-for-byte; no artwork is generated or recompressed.
"""

from __future__ import annotations

import argparse
import json
import shutil
from pathlib import Path

from PIL import Image


CATALOG = Path(__file__).resolve().parents[1] / "src/DeskBox/Assets/Tree/Wildlife/catalog.json"


def species_ids() -> tuple[str, ...]:
    with CATALOG.open(encoding="utf-8") as source:
        return tuple(item["id"] for item in json.load(source)["items"])


def validate_image(path: Path) -> None:
    with Image.open(path) as opened:
        if opened.format != "PNG" or opened.mode not in ("RGBA", "LA", "P"):
            raise ValueError(f"{path.name}: expected a transparent PNG")
        if opened.mode == "P" and "transparency" not in opened.info:
            raise ValueError(f"{path.name}: palette PNG has no transparency")
        image = opened.convert("RGBA")
    width, height = image.size
    if not (64 <= width <= 2048 and 64 <= height <= 2048):
        raise ValueError(f"{path.name}: image size must be 64–2048 pixels per side")
    alpha = image.getchannel("A")
    minimum, maximum = alpha.getextrema()
    if minimum != 0 or maximum < 200:
        raise ValueError(f"{path.name}: foreground and transparent background are required")
    corners = ((0, 0), (width - 1, 0), (0, height - 1), (width - 1, height - 1))
    if any(alpha.getpixel(point) > 16 for point in corners):
        raise ValueError(f"{path.name}: opaque content touches a corner")
    small = alpha.resize((28, 28), Image.Resampling.LANCZOS)
    if sum(value > 90 for value in small.getdata()) < 8:
        raise ValueError(f"{path.name}: animal disappears at widget scale")


def validate_file_set(source: Path, expected: set[str], label: str) -> None:
    actual = {path.name for path in source.glob("*.png")}
    if actual != expected:
        missing = sorted(expected - actual)
        extra = sorted(actual - expected)
        raise ValueError(f"{label} filenames do not match the catalog; missing={missing}, extra={extra}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path, help="folder with bright species-id.png files")
    parser.add_argument("blur", type=Path, help="folder with unrevealed species-id.png files")
    args = parser.parse_args()
    source: Path = args.source.resolve()
    blur: Path = args.blur.resolve()
    species = species_ids()
    expected = {f"{item}.png" for item in species}
    validate_file_set(source, expected, "bright")
    validate_file_set(blur, expected, "blur")
    for item in species:
        validate_image(source / f"{item}.png")
        validate_image(blur / f"{item}.png")
    target = Path(__file__).resolve().parents[1] / "src" / "DeskBox" / "Assets" / "Tree" / "Wildlife"
    normal = target / "Source"
    blurred = target / "Blur"
    normal.mkdir(parents=True, exist_ok=True)
    blurred.mkdir(parents=True, exist_ok=True)
    for item in species:
        shutil.copyfile(source / f"{item}.png", normal / f"{item}.png")
        shutil.copyfile(blur / f"{item}.png", blurred / f"{item}.png")
    print(f"Validated and imported {len(species)} paired wildlife PNGs without recompression.")


if __name__ == "__main__":
    main()
