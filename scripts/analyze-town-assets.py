"""批量分析共享小镇 PNG 素材。

输入可以是一个素材目录，也可以是单张 16 像素网格图集。分析器只生成
初始元数据，不修改原图，适合在导入后台前批量预审素材。

输出的 footprint/collision 是保守估计，建筑和屋顶类素材会标记为
needs_review，避免把视觉占地误当成真实碰撞。
"""
from __future__ import annotations

import argparse
import json
import math
from collections import deque
from pathlib import Path
from typing import Iterable

from PIL import Image


DEFAULT_TILE_SIZE = 16
DEFAULT_ALPHA_THRESHOLD = 8
MAX_COMPONENTS = 5000

_CATEGORY_RULES = (
    ("building", ("building", "house", "shed", "coop", "barn", "mill", "well", "屋", "房", "仓", "亭")),
    ("tree", ("tree", "wood", "bush", "fern", "flower", "flora", "plant", "树", "灌木", "花", "草")),
    ("fence", ("fence", "gate", "wall", "围栏", "栅栏", "门")),
    ("water", ("water", "river", "lake", "pond", "water" , "水", "河", "湖")),
    ("path", ("path", "road", "floor", "tile", "路", "地砖")),
    ("terrain", ("grass", "ground", "terrain", "soil", "地面", "草地", "土")),
    ("character", ("player", "npc", "villager", "character", "人物", "村民")),
)


def infer_category(name: str) -> str:
    lowered = name.casefold()
    for category, tokens in _CATEGORY_RULES:
        if any(token.casefold() in lowered for token in tokens):
            return category
    return "object"


def infer_sort_mode(category: str, name: str, width: int, height: int) -> tuple[str, str, list[str]]:
    lowered = name.casefold()
    warnings: list[str] = []
    if category in {"terrain", "water", "path"}:
        return "background", "normal", warnings
    if any(token in lowered for token in ("foreground", "front", "roof-edge", "canopy-front", "前景", "屋檐")):
        return "foreground", "foreground", warnings
    if category == "building" or height >= max(64, width * 1.5):
        warnings.append("建筑或高大素材建议确认前景遮挡方式")
    if category == "building" and width >= 64 and height >= 64:
        return "y_sort", "split", warnings
    return "y_sort", "normal", warnings


def alpha_bbox(image: Image.Image, threshold: int) -> tuple[int, int, int, int] | None:
    alpha = image.getchannel("A")
    if threshold <= 0:
        return alpha.getbbox()
    # Point transforms keep the scan in Pillow's C implementation and avoid
    # copying a full RGBA buffer for every asset.
    mask = alpha.point(lambda value: 255 if value >= threshold else 0)
    return mask.getbbox()


def normalized_contact(image: Image.Image, bbox: tuple[int, int, int, int], threshold: int) -> tuple[dict, dict]:
    left, top, right, bottom = bbox
    width = max(1, right - left)
    height = max(1, bottom - top)
    pixels = image.getchannel("A").load()
    cut = top + math.floor(height * 2 / 3)
    contact_left, contact_right = right, left
    contact_top, contact_bottom = bottom, top
    for y in range(max(top, cut), bottom):
        for x in range(left, right):
            if pixels[x, y] >= threshold:
                contact_left = min(contact_left, x)
                contact_right = max(contact_right, x + 1)
                contact_top = min(contact_top, y)
                contact_bottom = max(contact_bottom, y + 1)
    if contact_right <= contact_left:
        contact_left, contact_right, contact_top, contact_bottom = left, right, top, bottom
    collision = {
        "x": round((contact_left - left) / width, 4),
        "y": round((contact_top - top) / height, 4),
        "width": round((contact_right - contact_left) / width, 4),
        "height": round((contact_bottom - contact_top) / height, 4),
    }
    footprint = {
        "width": max(1, math.ceil((contact_right - contact_left) / DEFAULT_TILE_SIZE)),
        "height": max(1, math.ceil((contact_bottom - contact_top) / DEFAULT_TILE_SIZE)),
    }
    return footprint, collision


def analyze_image(path: Path, tile_size: int, threshold: int, region: tuple[int, int, int, int] | None = None, asset_id: str | None = None) -> dict | None:
    with Image.open(path) as source:
        image = source.convert("RGBA")
        if region is not None:
            x, y, width, height = region
            image = image.crop((x, y, x + width, y + height))
        bbox = alpha_bbox(image, threshold)
        if bbox is None:
            return None
        left, top, right, bottom = bbox
        width = right - left
        height = bottom - top
        category = infer_category(path.stem if asset_id is None else asset_id)
        sort_mode, occlusion_mode, warnings = infer_sort_mode(category, path.stem, width, height)
        footprint, collision = normalized_contact(image, bbox, threshold)
        contact_left = left + round(collision["x"] * width)
        contact_right = contact_left + round(collision["width"] * width)
        contact_bottom = top + round((collision["y"] + collision["height"]) * height)
        anchor = {
            "x": round(((contact_left + contact_right) / 2) / image.width, 4),
            "y": round(contact_bottom / image.height, 4),
        }
        # The helper uses the standard tile size for the collision estimate;
        # rescale here for callers analyzing a different tile grid.
        footprint["width"] = max(1, math.ceil((collision["width"] * width) / tile_size))
        footprint["height"] = max(1, math.ceil((collision["height"] * height) / tile_size))
        confidence = 0.92
        if occlusion_mode == "split":
            confidence = 0.72
        if category == "object" and width <= tile_size * 2 and height <= tile_size * 2:
            confidence = 0.98
        return {
            "id": asset_id or path.stem,
            "source": str(path),
            "region": {"x": region[0], "y": region[1], "width": region[2], "height": region[3]} if region else None,
            "image_size": {"width": image.width, "height": image.height},
            "alpha_bbox": {"x": left, "y": top, "width": width, "height": height},
            "anchor": anchor,
            "footprint": footprint,
            "collision": collision,
            "category": category,
            "sort_mode": sort_mode,
            "occlusion_mode": occlusion_mode,
            "confidence": confidence,
            "needs_review": bool(warnings),
            "warnings": warnings,
        }


def occupied_grid_cells(image: Image.Image, tile_size: int, threshold: int) -> set[tuple[int, int]]:
    alpha = image.getchannel("A")
    cells: set[tuple[int, int]] = set()
    for gy, y in enumerate(range(0, image.height, tile_size)):
        for gx, x in enumerate(range(0, image.width, tile_size)):
            box = alpha.crop((x, y, min(image.width, x + tile_size), min(image.height, y + tile_size)))
            if box.point(lambda value: 255 if value >= threshold else 0).getbbox() is not None:
                cells.add((gx, gy))
    return cells


def grid_components(path: Path, tile_size: int, threshold: int) -> Iterable[tuple[int, int, int, int]]:
    with Image.open(path) as source:
        image = source.convert("RGBA")
        source_width, source_height = image.width, image.height
        cells = occupied_grid_cells(image, tile_size, threshold)
    remaining = set(cells)
    components = 0
    regions: list[tuple[int, int, int, int]] = []
    while remaining and components < MAX_COMPONENTS:
        start = remaining.pop()
        queue = deque([start])
        group = [start]
        while queue:
            x, y = queue.popleft()
            for neighbor in ((x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1)):
                if neighbor in remaining:
                    remaining.remove(neighbor)
                    queue.append(neighbor)
                    group.append(neighbor)
        min_x = min(x for x, _ in group)
        max_x = max(x for x, _ in group)
        min_y = min(y for _, y in group)
        max_y = max(y for _, y in group)
        components += 1
        left = min_x * tile_size
        top = min_y * tile_size
        right = min(source_width, (max_x + 1) * tile_size)
        bottom = min(source_height, (max_y + 1) * tile_size)
        regions.append((left, top, right - left, bottom - top))
    yield from sorted(regions, key=lambda region: (region[1], region[0]))


def input_files(value: Path) -> list[Path]:
    if value.is_file():
        return [value]
    return sorted(path for path in value.rglob("*") if path.is_file() and path.suffix.casefold() == ".png")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", type=Path, help="素材 PNG 或包含 PNG 的目录")
    parser.add_argument("-o", "--output", type=Path, required=True, help="分析结果 JSON")
    parser.add_argument("--tile-size", type=int, default=DEFAULT_TILE_SIZE)
    parser.add_argument("--alpha-threshold", type=int, default=DEFAULT_ALPHA_THRESHOLD)
    parser.add_argument("--grid-sheet", action="store_true", help="把每张 PNG 当成 16 像素网格图集，合并相邻非空网格")
    args = parser.parse_args()
    if args.tile_size < 1 or args.alpha_threshold < 0 or args.alpha_threshold > 255:
        parser.error("tile-size 和 alpha-threshold 参数无效")
    assets: list[dict] = []
    skipped: list[str] = []
    for path in input_files(args.input):
        if not args.grid_sheet:
            result = analyze_image(path, args.tile_size, args.alpha_threshold)
            if result is None:
                skipped.append(str(path))
            else:
                assets.append(result)
            continue
        for index, region in enumerate(grid_components(path, args.tile_size, args.alpha_threshold), start=1):
            result = analyze_image(path, args.tile_size, args.alpha_threshold, region, f"{path.stem}-{index:04d}")
            if result is not None:
                assets.append(result)
    document = {
        "schema_version": 1,
        "tile_size": args.tile_size,
        "alpha_threshold": args.alpha_threshold,
        "source": str(args.input),
        "assets": assets,
        "skipped": skipped,
        "summary": {
            "asset_count": len(assets),
            "needs_review": sum(1 for asset in assets if asset["needs_review"]),
            "sort_modes": {mode: sum(1 for asset in assets if asset["sort_mode"] == mode) for mode in ("y_sort", "background", "foreground")},
        },
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(document["summary"], ensure_ascii=False))


if __name__ == "__main__":
    main()
