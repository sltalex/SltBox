"""Decode the 184 PNG pairs, verify originals, and create catalog contact sheets."""

import argparse
import hashlib
import json
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

from import_wildlife_assets import validate_file_set, validate_image

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "src/DeskBox/Assets/Tree/Wildlife"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-original", type=Path)
    parser.add_argument("--blur-original", type=Path)
    parser.add_argument("--output", type=Path, default=ROOT / ".codex-test-temp/wildlife-v2")
    args = parser.parse_args()
    manifest = json.loads((ASSETS / "catalog.json").read_text(encoding="utf-8"))
    assert manifest == json.loads((ROOT / "server/deskbox-api/config/wildlife.json").read_text(encoding="utf-8"))
    items = manifest["items"]
    assert len(items) == 184
    expected = {item["id"] + ".png" for item in items}
    report = {"catalog_version": manifest["catalog_version"], "pairs": len(items), "folders": {}}
    args.output.mkdir(parents=True, exist_ok=True)
    font = ImageFont.truetype("C:/Windows/Fonts/msyh.ttc", 14)
    for folder, original in (("Source", args.source_original), ("Blur", args.blur_original)):
        directory = ASSETS / folder
        validate_file_set(directory, expected, folder)
        assert {path.name for path in directory.iterdir()} == expected, f"Extra files in {folder}"
        for item in items:
            path = directory / (item["id"] + ".png")
            validate_image(path)
            with Image.open(path) as decoded:
                decoded.load()
            if original is not None:
                assert hashlib.sha256(path.read_bytes()).digest() == hashlib.sha256(
                    (original / path.name).read_bytes()).digest(), f"Original changed: {folder}/{path.name}"
        report["folders"][folder] = {"decoded": len(items), "original_hashes_verified": original is not None}
        for category in ("飞鸟", "小动物", "蝴蝶"):
            group = [item for item in items if item["category"] == category]
            assert len(group) == {"飞鸟": 60, "小动物": 44, "蝴蝶": 80}[category]
            sheet = Image.new("RGB", (8 * 140, ((len(group) + 7) // 8) * 128), "#eef2ed")
            draw = ImageDraw.Draw(sheet)
            for index, item in enumerate(group):
                with Image.open(directory / (item["id"] + ".png")) as decoded:
                    animal = decoded.convert("RGBA")
                animal.thumbnail((96, 96), Image.Resampling.LANCZOS)
                x, y = index % 8 * 140, index // 8 * 128
                sheet.paste(animal, (x + (140 - animal.width) // 2, y), animal)
                draw.text((x + 5, y + 100), item["name"], font=font, fill="#344044")
            sheet.save(args.output / f"{category}-{folder}.png")
    (args.output / "validation.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
