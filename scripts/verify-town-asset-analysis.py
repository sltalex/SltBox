"""Small deterministic checks for analyze-town-assets.py."""
from __future__ import annotations

import importlib.util
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "analyze-town-assets.py"
SPEC = importlib.util.spec_from_file_location("town_asset_analyzer", MODULE_PATH)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="town-asset-analysis-") as directory:
        root = Path(directory)
        image_path = root / "shed.png"
        image = Image.new("RGBA", (96, 112), (0, 0, 0, 0))
        draw = ImageDraw.Draw(image)
        draw.rectangle((16, 8, 79, 63), fill=(80, 120, 80, 255))
        draw.rectangle((32, 64, 63, 103), fill=(120, 80, 40, 255))
        image.save(image_path)
        result = MODULE.analyze_image(image_path, 16, 8)
        assert result is not None
        assert result["category"] == "building"
        assert result["sort_mode"] == "y_sort"
        assert result["occlusion_mode"] == "split"
        assert 0 < result["anchor"]["x"] <= 1
        assert 0 < result["anchor"]["y"] <= 1
        assert result["footprint"]["width"] >= 1
        assert result["collision"]["y"] >= 0

        sheet_path = root / "sheet.png"
        sheet = Image.new("RGBA", (34, 18), (0, 0, 0, 0))
        sheet.putpixel((1, 1), (255, 255, 255, 255))
        sheet.putpixel((33, 17), (255, 255, 255, 255))
        sheet.save(sheet_path)
        regions = list(MODULE.grid_components(sheet_path, 16, 8))
        assert regions == [(0, 0, 16, 16), (32, 16, 2, 2)], regions
    print("town asset analyzer: passed")


if __name__ == "__main__":
    main()
