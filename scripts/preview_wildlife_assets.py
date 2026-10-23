"""Render a local wildlife contact sheet for visual QA (not a shipped asset)."""

from __future__ import annotations

import io
import json
from pathlib import Path

import cairosvg
from PIL import Image, ImageDraw, ImageFont

root = Path(__file__).resolve().parents[1]
assets = root / "src/DeskBox/Assets/Tree/Wildlife"
items = json.loads((assets / "catalog.json").read_text(encoding="utf-8"))["items"]
columns, cell_width, cell_height = 10, 112, 102
rows = (len(items) + columns - 1) // columns
sheet = Image.new("RGBA", (columns * cell_width, rows * cell_height), "#eef2f0")
draw = ImageDraw.Draw(sheet)
font = ImageFont.truetype("C:/Windows/Fonts/msyh.ttc", 12)
for index, item in enumerate(items):
    x, y = (index % columns) * cell_width, (index // columns) * cell_height
    svg = (assets / "Source" / (item["id"] + ".svg")).read_bytes()
    image = Image.open(io.BytesIO(cairosvg.svg2png(bytestring=svg, output_width=72, output_height=72)))
    sheet.alpha_composite(image, (x + 20, y + 3))
    draw.text((x + 4, y + 78), item["name"], fill="#26383b", font=font)
    draw.text((x + 4, y + 92), item["id"][:17], fill="#647477", font=ImageFont.load_default())
target = root / ".codex-test-temp/wildlife-180-preview.png"
target.parent.mkdir(parents=True, exist_ok=True)
sheet.save(target)
print(target)
