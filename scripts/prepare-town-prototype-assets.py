"""Extract the local town prototype's original pixel art using reviewed recipes."""
from __future__ import annotations

import argparse
from collections import Counter, defaultdict
import hashlib
import json
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[1]
DESTINATION = ROOT / "src/DeskBox/Assets/Tree/TownPrototype"
CATALOG_SOURCE = ROOT / "server/deskbox-api/config/town-prototype-catalog.json"

# Rectangles are [left, top, right, bottom] in the unscaled source PNG.
RECIPES = {
    "shed": ("Buildings/Shed..png", (0, 0, 112, 128)),
    "well": ("Buildings/Well..png", (0, 0, 48, 80)),
    "flora_fern": ("TileSheets/furniture..png", (96, 688, 112, 720)),
    "flora_flowerpot": ("TileSheets/furniture..png", (48, 688, 64, 720)),
    "flora_cactus": ("TileSheets/furniture..png", (208, 688, 224, 720)),
    "flora_bush": ("TileSheets/furniture..png", (64, 688, 80, 720)),
    "flora_mushroom": ("TerrainFeatures/upperCavePlants..png", (384, 0, 448, 96)),
    "flora_reeds": ("TerrainFeatures/grass..png", (0, 0, 16, 20)),
    "prop_bench": ("TileSheets/furniture..png", (0, 96, 32, 128)),
    "prop_lamp": ("TileSheets/Craftables..png", (48, 192, 64, 224)),
    "prop_barrel": ("TileSheets/Craftables..png", (112, 32, 128, 64)),
    "prop_scarecrow": ("TileSheets/Craftables..png", (0, 32, 16, 64)),
    "prop_fence": ("TileSheets/Craftables..png", (16, 320, 32, 352)),
    "prop_sign": ("TileSheets/furniture..png", (0, 0, 16, 32)),
    "grass": ("spring_outdoorsTileSheet..png", (0, 256, 16, 272)),
    "path": ("spring_outdoorsTileSheet..png", (96, 352, 112, 368)),
    "water": ("spring_outdoorsTileSheet..png", (144, 208, 160, 224)),
    "tree-stage-1": ("TerrainFeatures/tree1_spring..png", (32, 128, 48, 140)),
    "tree-stage-2": ("TerrainFeatures/tree1_spring..png", (0, 128, 16, 140)),
    "tree-stage-3": ("TerrainFeatures/tree1_spring..png", (16, 128, 32, 143)),
    "tree-stage-4": ("TerrainFeatures/tree1_spring..png", (0, 96, 16, 127)),
    "tree-stage-5": ("TerrainFeatures/tree1_spring..png", (0, 0, 48, 84)),
}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def image_font(size: int) -> ImageFont.FreeTypeFont:
    for name in ("C:/Windows/Fonts/msyh.ttc", "C:/Windows/Fonts/arial.ttf"):
        if Path(name).exists():
            return ImageFont.truetype(name, size)
    return ImageFont.load_default()


def checkerboard(size: tuple[int, int]) -> Image.Image:
    image = Image.new("RGBA", size, "#e8edee")
    draw = ImageDraw.Draw(image)
    for y in range(0, size[1], 8):
        for x in range(0, size[0], 8):
            if (x // 8 + y // 8) % 2:
                draw.rectangle((x, y, x + 7, y + 7), fill="#d7dfe2")
    return image


def extract(source_root: Path) -> tuple[dict[str, Image.Image], list[dict]]:
    outputs: dict[str, Image.Image] = {}
    records: list[dict] = []

    def crop(source: str, rect: tuple[int, int, int, int], label: str, position=(0, 0)):
        path = source_root / source
        with Image.open(path) as raw:
            original = raw.convert("RGBA")
            left, top, right, bottom = rect
            if not (0 <= left < right <= original.width and 0 <= top < bottom <= original.height):
                raise ValueError(f"Out-of-bounds crop: {label} {source} {rect}")
            image = original.crop(rect)
            if image.getchannel("A").getbbox() is None:
                raise ValueError(f"Empty crop: {label}")
            records.append({"id": label, "source": source,
                            "source_sha256": sha256(path.read_bytes()),
                            "source_size": list(original.size), "rectangle": list(rect),
                            "destination_position": list(position)})
            return image

    for identifier, (source, rectangle) in RECIPES.items():
        outputs[identifier] = crop(source, rectangle, identifier)

    # The lower-right 32x16 cell is an opaque white source placeholder rather
    # than part of the coop. Preserve the remaining source pixels verbatim.
    coop = Image.new("RGBA", (96, 128))
    coop.alpha_composite(crop("Buildings/Coop..png", (0, 0, 96, 112), "coop:body"))
    coop.alpha_composite(crop("Buildings/Coop..png", (0, 112, 64, 128), "coop:base", (0, 112)), (0, 112))
    outputs["coop"] = coop

    # The mill PNG includes separate moving sails and a door. Only the first sail
    # frame is composited onto the complete static body, at its physical axle.
    mill = Image.new("RGBA", (64, 128))
    mill.alpha_composite(crop("Buildings/Mill..png", (0, 0, 48, 128), "mill:body", (8, 0)), (8, 0))
    mill.alpha_composite(crop("Buildings/Mill..png", (64, 0, 96, 32), "mill:sails", (16, 32)), (16, 32))
    outputs["mill"] = mill

    # Alex's first four rows are the complete clothed four-direction walk set.
    # Preserve 16x32 cells and their common foot position, including transparent padding.
    player = Image.new("RGBA", (64, 128))
    for row, direction in enumerate(("down", "right", "up", "left")):
        for column in range(4):
            rect = (column * 16, row * 32, (column + 1) * 16, (row + 1) * 32)
            position = (column * 16, row * 32)
            player.alpha_composite(crop("Characters/Alex..png", rect,
                                       f"player:{direction}:{column}", position), position)
    outputs["player"] = player
    return outputs, records


def make_previews(source_root: Path, preview: Path, catalog: dict,
                  outputs: dict[str, Image.Image], records: list[dict]) -> None:
    preview.mkdir(parents=True, exist_ok=True)
    names = {item["id"]: item["name"] for item in catalog["items"]}
    identifiers = [item["id"] for item in catalog["items"]] + [f"tree-stage-{n}" for n in range(1, 6)]
    cell_width, cell_height = 230, 200
    collage = Image.new("RGB", (cell_width * 4, cell_height * 6 + 68), "#f5f6f1")
    draw = ImageDraw.Draw(collage)
    draw.text((18, 12), "小镇原型 · 16 款装饰与五阶段树木切割预览", font=image_font(21), fill="#183a29")
    draw.text((18, 42), "原始透明像素；预览仅使用最近邻整数缩放。", font=image_font(13), fill="#556355")
    for index, identifier in enumerate(identifiers):
        image = outputs[identifier]
        x, y = (index % 4) * cell_width, (index // 4) * cell_height + 68
        background = checkerboard((cell_width - 16, cell_height - 48))
        scale = max(1, min(4, (background.width - 12) // image.width,
                           (background.height - 8) // image.height))
        display = image.resize((image.width * scale, image.height * scale), Image.Resampling.NEAREST)
        background.alpha_composite(display, ((background.width - display.width) // 2,
                                              background.height - display.height))
        collage.paste(background.convert("RGB"), (x + 8, y))
        title = names.get(identifier, "树木阶段 " + identifier.rsplit("-", 1)[-1])
        draw.text((x + 10, y + cell_height - 46), title, font=image_font(15), fill="#183a29")
        draw.text((x + 10, y + cell_height - 23),
                  f"{identifier}  {image.width}×{image.height}", font=image_font(11), fill="#536058")
    collage.save(preview / "asset-contact-sheet.png")

    grouped = defaultdict(list)
    for record in records:
        grouped[record["source"]].append(record)
    for source, source_records in grouped.items():
        with Image.open(source_root / source) as raw:
            original = raw.convert("RGBA")
        scale = 2 if original.width > 200 else 4
        annotated = checkerboard((original.width * scale, original.height * scale))
        annotated.alpha_composite(original.resize(annotated.size, Image.Resampling.NEAREST))
        canvas = ImageDraw.Draw(annotated)
        for record in source_records:
            left, top, right, bottom = record["rectangle"]
            box = (left * scale, top * scale, right * scale - 1, bottom * scale - 1)
            canvas.rectangle(box, outline="#f04452", width=2)
            canvas.text((left * scale + 2, top * scale + 2), record["id"],
                        font=image_font(10), fill="white", stroke_width=1, stroke_fill="#1b2730")
        annotated.save(preview / (source.replace("/", "_").replace("..png", "") + "-annotated.png"))

    frames = checkerboard((64 * 6, 128 * 6))
    frames.alpha_composite(outputs["player"].resize(frames.size, Image.Resampling.NEAREST))
    frames.save(preview / "player-walk-frames.png")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path,
                        default=ROOT.parent / "星露谷物语素材" / "Stardew valley")
    parser.add_argument("--preview", type=Path, default=ROOT / ".codex-test-temp/town-prototype")
    parser.add_argument("--verify-only", action="store_true")
    args = parser.parse_args()
    catalog_bytes = CATALOG_SOURCE.read_bytes()
    catalog = json.loads(catalog_bytes)
    assert Counter(item["category"] for item in catalog["items"]) == {"building": 4, "flora": 6, "prop": 6}
    outputs, records = extract(args.source)
    sprites = DESTINATION / "sprites"
    if not args.verify_only:
        sprites.mkdir(parents=True, exist_ok=True)
        for identifier, image in outputs.items():
            image.save(sprites / f"{identifier}.png")
        (DESTINATION / "catalog.json").write_bytes(catalog_bytes)
    assert (DESTINATION / "catalog.json").read_bytes() == catalog_bytes
    asset_hashes = []
    for identifier, expected in outputs.items():
        path = sprites / f"{identifier}.png"
        with Image.open(path) as actual:
            assert actual.size == expected.size, identifier
            assert actual.convert("RGBA").tobytes() == expected.tobytes(), identifier
        asset_hashes.append({"id": identifier, "file": f"sprites/{identifier}.png",
                             "width": expected.width, "height": expected.height,
                             "sha256": sha256(path.read_bytes())})
    item_pixels = [sha256(outputs[item["id"]].tobytes()) for item in catalog["items"]]
    assert len(set(item_pixels)) == 16, "Duplicate decoration pixel content"
    manifest = {"version": 1, "source_directory": str(args.source),
                "pixel_processing": "RGBA fixed rectangles, no resampling; mill assembly and actor atlas preserve source pixels",
                "cuts": records, "outputs": asset_hashes}
    if not args.verify_only:
        (DESTINATION / "crop-manifest.json").write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        make_previews(args.source, args.preview, catalog, outputs, records)
    print(json.dumps({"decorations": 16, "outputs": len(outputs), "source_rectangles": len(records),
                      "categories": dict(Counter(item["category"] for item in catalog["items"])),
                      "pixel_parity": True, "catalog_parity": True,
                      "preview": str(args.preview / "asset-contact-sheet.png")}, ensure_ascii=False))


if __name__ == "__main__":
    main()
