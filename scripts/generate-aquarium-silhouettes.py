"""Generate deliberately unrecognizable but shape-aware atlas placeholders.

Requires Pillow and CairoSVG. Run from the repository root.
"""

from io import BytesIO
from pathlib import Path

import cairosvg
from PIL import Image, ImageFilter


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src/DeskBox/Assets/Aquarium/Svg"
DESTINATION = ROOT / "src/DeskBox/Assets/Aquarium/Silhouettes"
DESTINATION.mkdir(parents=True, exist_ok=True)

for source in sorted(SOURCE.glob("*.svg")):
    rendered = cairosvg.svg2png(bytestring=source.read_bytes(), output_width=128, output_height=128)
    with Image.open(BytesIO(rendered)) as image:
        alpha = image.convert("RGBA").getchannel("A")
    # Expand contours, then blur away markings and small identifying details.
    shape = alpha.point(lambda value: 255 if value >= 28 else 0)
    shape = shape.filter(ImageFilter.MaxFilter(15)).filter(ImageFilter.GaussianBlur(9))
    silhouette = Image.new("RGBA", (128, 128), (112, 125, 138, 0))
    silhouette.putalpha(shape.point(lambda value: round(value * 0.68)))
    silhouette.save(DESTINATION / f"{source.stem}.png", optimize=True)

print(f"Generated {len(list(DESTINATION.glob('*.png')))} aquarium silhouettes.")
