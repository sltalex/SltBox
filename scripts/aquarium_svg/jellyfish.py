"""Metadata and validation helpers for the ten hand-authored jellyfish SVGs.

The artwork lives in ``src/DeskBox/Assets/Aquarium/Svg`` as standalone,
transparent 640 x 400 SVG files.  This small module deliberately has no
network or image dependencies: it can be used by asset checks or by a catalog
build to enumerate the jellyfish identities and verify that every source is
well-formed vector artwork.
"""

from __future__ import annotations

import argparse
import runpy
import re
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
SVG_DIR = ROOT / "src" / "DeskBox" / "Assets" / "Aquarium" / "Svg"

# Keep these fields aligned with the aquarium catalog plan.  The palette and
# silhouette tags make the ten entries easy to distinguish in the store.
JELLYFISH_METADATA = (
    {"id": "moon-jellyfish", "name": "月亮水母", "scientific_name": "Aurelia aurita", "price": 260, "width": 118, "height": 142, "speed": .34, "palette": "pearl-lilac", "silhouette": "round-bell"},
    {"id": "lion-mane-jellyfish", "name": "狮鬃水母", "scientific_name": "Cyanea capillata", "price": 520, "width": 178, "height": 220, "speed": .24, "palette": "copper-crimson", "silhouette": "ribbed-dome"},
    {"id": "blue-blubber-jellyfish", "name": "蓝脂水母", "scientific_name": "Catostylus mosaicus", "price": 330, "width": 138, "height": 154, "speed": .31, "palette": "cobalt-aqua", "silhouette": "squat-bell"},
    {"id": "cannonball-jellyfish", "name": "炮弹水母", "scientific_name": "Stomolophus meleagris", "price": 300, "width": 132, "height": 126, "speed": .28, "palette": "smoky-blue", "silhouette": "compact-bell"},
    {"id": "upside-down-jellyfish", "name": "倒立水母", "scientific_name": "Cassiopea xamachana", "price": 360, "width": 152, "height": 178, "speed": .20, "palette": "olive-plum", "silhouette": "inverted-bell"},
    {"id": "flower-hat-jellyfish", "name": "花笠水母", "scientific_name": "Olindias formosus", "price": 460, "width": 148, "height": 184, "speed": .38, "palette": "neon-pastel", "silhouette": "clear-flower"},
    {"id": "sea-nettle", "name": "海刺水母", "scientific_name": "Chrysaora quinquecirrha", "price": 390, "width": 142, "height": 190, "speed": .32, "palette": "amber-rose", "silhouette": "trailing-cone"},
    {"id": "crystal-jellyfish", "name": "水晶水母", "scientific_name": "Aequorea victoria", "price": 480, "width": 128, "height": 170, "speed": .35, "palette": "mint-glass", "silhouette": "clear-dome"},
    {"id": "comb-jelly", "name": "栉水母", "scientific_name": "Mnemiopsis leidyi", "price": 340, "width": 104, "height": 148, "speed": .42, "palette": "iridescent-rainbow", "silhouette": "oval-comb"},
    {"id": "portuguese-man-o-war", "name": "僧帽水母", "scientific_name": "Physalia physalis", "price": 500, "width": 166, "height": 214, "speed": .18, "palette": "violet-indigo", "silhouette": "sail-float"},
)

_KEY = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")


def validate(path: Path) -> None:
    """Parse one SVG and enforce the same safe vector boundary as the asset build."""
    root = ET.parse(path).getroot()
    if root.tag != "{http://www.w3.org/2000/svg}svg":
        raise ValueError(f"Invalid SVG root: {path.name}")
    if root.get("viewBox") != "0 0 640 400":
        raise ValueError(f"Unexpected viewBox: {path.name}")
    for node in root.iter():
        tag = node.tag.rsplit("}", 1)[-1]
        if tag in {"image", "script", "foreignObject", "text", "filter"}:
            raise ValueError(f"Non-vector or unsupported element {tag}: {path.name}")
        for attribute, value in node.attrib.items():
            if attribute.rsplit("}", 1)[-1] == "href" and not value.startswith("#"):
                raise ValueError(f"External reference: {path.name}")
            for reference in re.findall(r"url\(([^)]+)\)", value):
                if not reference.startswith("#"):
                    raise ValueError(f"External paint reference: {path.name}")


def validate_all(svg_dir: Path = SVG_DIR) -> list[Path]:
    """Validate all ten sources and return them in catalog order."""
    written: list[Path] = []
    for entry in JELLYFISH_METADATA:
        key = entry["id"]
        if not _KEY.fullmatch(key):
            raise ValueError(f"Unsafe asset key: {key}")
        path = svg_dir / f"{key}.svg"
        if not path.is_file():
            raise FileNotFoundError(path)
        validate(path)
        written.append(path)
    return written


def generate(entries: list[dict], output_dir: Path) -> list[Path]:
    """Validate the reviewed jellyfish masters for the shared asset builder.

    Jellyfish are hand-authored masters rather than procedurally regenerated
    drawings.  The shared builder still calls every category through a
    ``generate`` hook, so this function enforces the manifest and returns the
    existing sources without replacing the reviewed artwork.
    """
    expected = {entry["id"] for entry in JELLYFISH_METADATA}
    received = {entry["id"] for entry in entries}
    if received != expected:
        raise ValueError("Jellyfish manifest does not match the reviewed masters")
    return validate_all(Path(output_dir))


def render_png(svg_dir: Path = SVG_DIR, png_dir: Path | None = None) -> list[Path]:
    """Render transparent 240 x 220 runtime cards with the pinned resvg stack.

    Install the optional renderer with ``uv run --with resvg-py==0.5.0
    --with pillow==13.0.0`` before invoking this helper.
    """
    shared = runpy.run_path(str(ROOT / "scripts/generate-aquarium-svg-assets.py"))
    destination = png_dir or (svg_dir.parent / "Vector")
    destination.mkdir(parents=True, exist_ok=True)
    written: list[Path] = []
    for entry in JELLYFISH_METADATA:
        key = entry["id"]
        shared["export_png"](key, "jellyfish", svg_dir, destination)
        written.append(destination / f"{key}.png")
    return written


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--svg-dir", type=Path, default=SVG_DIR)
    parser.add_argument("--render-png", action="store_true", help="also render transparent Vector/*.png exports")
    parser.add_argument("--png-dir", type=Path, default=None)
    args = parser.parse_args()
    files = validate_all(args.svg_dir)
    print(f"Validated {len(files)} jellyfish SVG sources.")
    if args.render_png:
        exports = render_png(args.svg_dir, args.png_dir)
        print(f"Rendered {len(exports)} PNG exports.")
