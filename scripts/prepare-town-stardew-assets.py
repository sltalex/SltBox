"""Build the town decoration image pack from the user's Stardew Valley assets.

The town catalog keeps its stable ids and placement metadata.  Only the visual
asset is replaced.  Every generated PNG has a manifest entry with its source
file, crop rectangle and source hash so the pack can be reproduced and audited.
"""
from __future__ import annotations

import hashlib
import json
import shutil
from dataclasses import dataclass
from pathlib import Path

from PIL import Image

REPO = Path(r"D:\work\C#\deskbox")
SOURCE_ROOT = Path(r"D:\work\C#\星露谷物语素材\Stardew valley")
TOWN = REPO / "src" / "DeskBox" / "Assets" / "Tree" / "TownPrototype"
OUT = TOWN / "sprites" / "stardew"
CATALOG = TOWN / "catalog.json"
MANIFEST = OUT / "manifest.json"

LOCALIZED_MARKERS = (".zh-CN", ".ja-JP", ".de-DE", ".es-ES", ".pt-BR", ".ru-RU")


@dataclass(frozen=True)
class Candidate:
    source: Path
    rect: tuple[int, int, int, int]
    kind: str


def source_hash(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def has_pixels(image: Image.Image, minimum: int = 20) -> bool:
    alpha = image.getchannel("A")
    return alpha.getbbox() is not None and sum(1 for value in alpha.getdata() if value > 8) >= minimum


def add_whole_file(candidates: list[Candidate], directory: str) -> None:
    for path in sorted((SOURCE_ROOT / directory).glob("*.png")):
        if any(marker in path.stem for marker in LOCALIZED_MARKERS):
            continue
        with Image.open(path) as image:
            rgba = image.convert("RGBA")
            if has_pixels(rgba):
                candidates.append(Candidate(path, (0, 0, rgba.width, rgba.height), "source"))


def add_grid_cells(candidates: list[Candidate], directory: str, names: tuple[str, ...], cell: int = 32) -> None:
    for name in names:
        path = SOURCE_ROOT / directory / name
        if not path.is_file():
            continue
        with Image.open(path) as image:
            rgba = image.convert("RGBA")
            for y in range(0, rgba.height, cell):
                for x in range(0, rgba.width, cell):
                    right = min(x + cell, rgba.width)
                    bottom = min(y + cell, rgba.height)
                    crop = rgba.crop((x, y, right, bottom))
                    if has_pixels(crop, minimum=max(20, cell * cell // 32)):
                        candidates.append(Candidate(path, (x, y, right, bottom), "grid32"))


def main() -> None:
    if not SOURCE_ROOT.is_dir():
        raise SystemExit(f"素材目录不存在: {SOURCE_ROOT}")
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    items = catalog.get("items")
    if not isinstance(items, list) or len(items) != 1500:
        raise SystemExit(f"小镇目录应有 1500 项，实际为 {len(items) if isinstance(items, list) else 'unknown'}")

    candidates: list[Candidate] = []
    # Whole files are the most useful for buildings and tree forms, so they
    # appear first in the deterministic pack.
    add_whole_file(candidates, "Buildings")
    for name in ("grass..png", "mushroom_tree..png", "tree1_spring..png", "tree1_summer..png",
                 "tree1_fall..png", "tree2_spring..png", "tree2_summer..png", "tree2_fall..png",
                 "tree3_spring..png", "tree3_winter..png", "tree_palm..png"):
        path = SOURCE_ROOT / "TerrainFeatures" / name
        if path.is_file():
            with Image.open(path) as image:
                rgba = image.convert("RGBA")
                if has_pixels(rgba):
                    candidates.append(Candidate(path, (0, 0, rgba.width, rgba.height), "source"))
    for name in ("Fence1..png", "Fence2..png", "Fence3..png", "Fence5..png", "shadow..png"):
        path = SOURCE_ROOT / "LooseSprites" / name
        if path.is_file():
            with Image.open(path) as image:
                rgba = image.convert("RGBA")
                if has_pixels(rgba):
                    candidates.append(Candidate(path, (0, 0, rgba.width, rgba.height), "source"))

    add_grid_cells(candidates, "TileSheets", (
        "furniture..png", "Craftables..png", "bushes..png", "crops..png",
        "fruitTrees..png", "Floors..png", "debris..png", "tools..png", "weapons..png",
    ))
    # Add a few natural spritesheet cells after the furniture and craftables.
    add_grid_cells(candidates, "TerrainFeatures", ("grass..png", "upperCavePlants..png", "Quartz..png"))

    # The supplied pack contains many additional map and interior sheets.  Use
    # their non-empty 32 px cells as a deterministic fallback so every catalog
    # entry still points to an image from the supplied source tree.
    if len(candidates) < len(items):
        seen = {(candidate.source, candidate.rect) for candidate in candidates}
        for path in sorted(SOURCE_ROOT.rglob("*.png")):
            if path.relative_to(SOURCE_ROOT).parts[0] in {"Animals", "Characters", "Portraits", "Fonts"}:
                continue
            if any(marker in path.stem for marker in LOCALIZED_MARKERS):
                continue
            with Image.open(path) as image:
                rgba = image.convert("RGBA")
                for y in range(0, rgba.height, 32):
                    for x in range(0, rgba.width, 32):
                        rect = (x, y, min(x + 32, rgba.width), min(y + 32, rgba.height))
                        if (path, rect) in seen:
                            continue
                        if has_pixels(rgba.crop(rect), minimum=32):
                            candidates.append(Candidate(path, rect, "fallback-grid32"))
                            seen.add((path, rect))
                        if len(candidates) >= len(items):
                            break
                    if len(candidates) >= len(items):
                        break
            if len(candidates) >= len(items):
                break

    # Start the catalog with a representative mix so the opening town scene
    # shows buildings, trees, fencing and terrain instead of twelve variants
    # of the first building sheet.
    priority = {
        "Barn..png": 0, "Coop..png": 1, "Mill..png": 2, "Shed..png": 3,
        "tree1_spring..png": 4, "tree2_spring..png": 5, "tree3_spring..png": 6,
        "grass..png": 7, "Fence1..png": 8, "Fence2..png": 9,
        "Fence3..png": 10, "Fence5..png": 11,
    }
    candidates.sort(key=lambda candidate: (priority.get(candidate.source.name, 1000), candidate.source.as_posix(), candidate.rect))

    if len(candidates) < len(items):
        raise SystemExit(f"可用星露谷切片不足：{len(candidates)} < {len(items)}")

    if OUT.exists():
        for path in OUT.glob("stardew-*.png"):
            path.unlink()
    OUT.mkdir(parents=True, exist_ok=True)
    manifest_items = []
    for index, item in enumerate(items):
        candidate = candidates[index]
        filename = f"stardew-{index + 1:04d}.png"
        output = OUT / filename
        with Image.open(candidate.source) as image:
            image.convert("RGBA").crop(candidate.rect).save(output, format="PNG", optimize=True)
        item["asset"] = f"sprites/stardew/{filename}"
        item["blur_asset"] = f"sprites/stardew/{filename}"
        manifest_items.append({
            "item_id": item["id"],
            "asset": item["asset"],
            "source": candidate.source.relative_to(SOURCE_ROOT).as_posix(),
            "rect": list(candidate.rect),
            "kind": candidate.kind,
            "source_sha256": source_hash(candidate.source),
        })

    catalog["asset_source"] = "D:/work/C#/星露谷物语素材/Stardew valley"
    catalog["asset_manifest"] = "sprites/stardew/manifest.json"
    CATALOG.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    MANIFEST.write_text(json.dumps({
        "source_root": str(SOURCE_ROOT),
        "source_policy": "stardew-valley-local-assets",
        "items": manifest_items,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"items": len(items), "candidates": len(candidates), "output": str(OUT), "manifest": str(MANIFEST)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
