"""Import the supplied individual fruit images and write explicit manifest mappings.

The client no longer derives an image from atlas coordinates at runtime.  The
legacy atlas metadata remains in the manifest for the API, while each client
catalogue entry receives a concrete normal and blurred PNG path.
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
from pathlib import Path

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
MANIFEST_PATH = ROOT / "src" / "DeskBox" / "Assets" / "Tree" / "fruits-manifest.json"
DEST_ROOT = ROOT / "src" / "DeskBox" / "Assets" / "Tree" / "Fruits"
SOURCE_DEST = DEST_ROOT / "Source"
BLUR_DEST = DEST_ROOT / "Blur"
SOURCE_NAME_PATTERN = re.compile(r"^fruit_r(?P<row>\d+)_c(?P<column>\d+)\.png$", re.IGNORECASE)
# The server's atlasIndex is the old 20-column catalogue order.  The supplied
# directory contains 21 files per physical row; the extra column is retained
# as an unreferenced local asset and must not shift the existing 112 mappings.
LEGACY_CATALOG_COLUMNS = 20


def natural_key(path: Path) -> tuple[int, int, str]:
    match = SOURCE_NAME_PATTERN.fullmatch(path.name)
    if match is None:
        raise ValueError(
            f"Invalid fruit asset filename '{path.name}'; expected fruit_rXX_cYY.png"
        )
    return int(match["row"]), int(match["column"]), path.name


def validate_sources(normal_root: Path, blur_root: Path) -> list[Path]:
    normal = sorted(normal_root.glob("*.png"), key=natural_key)
    blur = sorted(blur_root.glob("*.png"), key=natural_key)
    if len(normal) != 210 or len(blur) != 210:
        raise ValueError(f"Expected 210 normal and blur images, got {len(normal)} and {len(blur)}")
    normal_names = {p.name for p in normal}
    blur_names = {p.name for p in blur}
    if normal_names != blur_names:
        raise ValueError("Normal and blur image names do not match")
    for path in normal:
        with Image.open(path) as image:
            if image.mode != "RGBA":
                raise ValueError(f"Normal image must be transparent RGBA: {path} (mode={image.mode})")
    return normal


def remove_white_background(path: Path, destination: Path) -> None:
    with Image.open(path).convert("RGBA") as image:
        pixels = []
        for red, green, blue, _ in image.getdata():
            luminance = (299 * red + 587 * green + 114 * blue) // 1000
            # Preserve the supplied grey blur while feathering near-white
            # background pixels into transparency.
            alpha = max(0, min(220, (250 - luminance) * 3))
            pixels.append((red, green, blue, alpha))
        output = Image.new("RGBA", image.size)
        output.putdata(pixels)
        output.save(destination, optimize=True)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--normal", type=Path, default=Path(r"C:\Users\Administrator\Desktop\img"))
    parser.add_argument("--blur", type=Path, default=Path(r"C:\Users\Administrator\Desktop\img_blur"))
    args = parser.parse_args()

    normal = validate_sources(args.normal, args.blur)
    manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    items = manifest.get("items", [])
    if len(items) != 112:
        raise ValueError(f"Expected the existing 112-item catalogue, got {len(items)}")

    SOURCE_DEST.mkdir(parents=True, exist_ok=True)
    BLUR_DEST.mkdir(parents=True, exist_ok=True)
    blur_by_name = {p.name: p for p in args.blur.glob("*.png")}

    for path in normal:
        shutil.copy2(path, SOURCE_DEST / path.name)
        remove_white_background(blur_by_name[path.name], BLUR_DEST / path.name)

    # atlasIndex is used only once, during this import, to preserve the old
    # catalogue's visual ordering. Runtime rendering uses the explicit paths.
    for item in items:
        index = int(item["atlasIndex"])
        row, column = divmod(index, LEGACY_CATALOG_COLUMNS)
        filename = f"fruit_r{row + 1:02d}_c{column + 1:02d}.png"
        if not (SOURCE_DEST / filename).is_file() or not (BLUR_DEST / filename).is_file():
            raise ValueError(f"Missing mapped asset for {item['id']}: {filename}")
        item["assetFile"] = f"Fruits/Source/{filename}"
        item["blurAssetFile"] = f"Fruits/Blur/{filename}"

    manifest["assetMode"] = "individual"
    manifest["assetCount"] = len(normal)
    MANIFEST_PATH.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Imported {len(normal)} normal and {len(normal)} blur images; mapped {len(items)} catalogue entries")


if __name__ == "__main__":
    main()
