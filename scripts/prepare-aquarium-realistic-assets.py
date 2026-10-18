"""Download and prepare realistic aquarium cut-outs from Wikimedia Commons.

The generated PNGs are transparent crops used by the aquarium scene.  Source
metadata is written beside the cached downloads so LICENSES.md can retain
attribution when the assets are regenerated.
"""

from __future__ import annotations

import json
import urllib.parse
import urllib.request
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw


ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / ".artifacts" / "aquarium-realistic-sources"
OUTPUT = ROOT / "src" / "DeskBox" / "Assets" / "Aquarium"

# The titles are stable Commons file names selected for clear, isolated
# subjects.  The API response retains author and license metadata.
SOURCES = {
    "angelfish": "File:Pterophyllum scalare 03.jpg",
    "lionfish": "File:Pterois volitans Manado-e.jpg",
    "pufferfish": "File:Pez globo (Arothron diadematus), mar Rojo, Egipto, 2023-04-17, DD 19.jpg",
    "seahorse": "File:Longsnout Seahorse (Hippocampus reidi).jpg",
    "yellow-tang": "File:Yellow tang in the Leningrad zoo 7.jpg",
    "royal-gramma": "File:Pez abuela real (Gramma loreto), San Pedro, Belice, 2025-12-18, DD 31.jpg",
    "mandarinfish": "File:Synchiropus splendidus 2 Luc Viatour.jpg",
    "moorish-idol": "File:Idole des Maures (Zanclus cornutus).jpg",
    "cardinalfish": "File:Apogonidae Sphaeramia nematoptera 3.jpg",
    "java-fern": "File:Java Fern (Microsorum pteropus) growing in the wild..jpg",
    "hornwort": "File:Ceratophyllum demersum, hornwort, rigid hornwort, coontail, or coon's tail. .jpg",
    "amazon-sword": "File:Echinodorus amazonicus-2-veda aquarium-bengaluru-India.jpg",
    "vallisneria": "File:Vallisneria nana-3-veda aquarium-bengaluru-India.jpg",
    "porcelain-crab": "File:Petrolisthes elongatus (New Zealand half crab).JPG",
    "emerald-crab": "File:Emerald Crab munching on Hair Algae.jpg",
    "hermit-crab": "File:Hairy yellow hermit crab Aniculus maximus.jpg",
    "fiddler-crab": "File:Compressed fiddler crab (Uca coarctata) male.jpg",
}


def fetch(url: str) -> bytes:
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "SltBoxAssetPreparation/1.0 (https://sltbox.2leaves.cn)"},
    )
    with urllib.request.urlopen(request, timeout=45) as response:
        return response.read()


def download_sources() -> None:
    CACHE.mkdir(parents=True, exist_ok=True)
    for key, title in SOURCES.items():
        metadata_path = CACHE / f"{key}.json"
        if not metadata_path.exists():
            query = urllib.parse.urlencode(
                {
                    "action": "query",
                    "format": "json",
                    "titles": title,
                    "prop": "imageinfo",
                    "iiprop": "url|extmetadata",
                    "iiurlwidth": "1600",
                }
            )
            pages = json.loads(fetch("https://commons.wikimedia.org/w/api.php?" + query))["query"]["pages"]
            page = next(iter(pages.values()))
            if "imageinfo" not in page:
                raise RuntimeError(f"Commons source not found: {title}")
            metadata_path.write_text(json.dumps(page["imageinfo"][0], ensure_ascii=False, indent=2), encoding="utf-8")
        info = json.loads(metadata_path.read_text(encoding="utf-8"))
        source_path = CACHE / f"{key}.jpg"
        if not source_path.exists():
            url = info.get("thumburl", info["url"]).split("?")[0]
            source_path.write_bytes(fetch(url))
        print(key, Image.open(source_path).size, flush=True)


def _foreground(image: np.ndarray) -> np.ndarray:
    height, width = image.shape[:2]
    mask = np.zeros((height, width), np.uint8)
    rect = (max(2, width // 35), max(2, height // 35), max(4, width - width // 18), max(4, height - height // 18))
    background = np.zeros((1, 65), np.float64)
    foreground = np.zeros((1, 65), np.float64)
    cv2.grabCut(image, mask, rect, background, foreground, 3, cv2.GC_INIT_WITH_RECT)
    alpha = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype(np.uint8)
    alpha = cv2.morphologyEx(alpha, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    return cv2.GaussianBlur(alpha, (3, 3), 0)


def _cyan_water_mask(image: np.ndarray) -> np.ndarray:
    blue, green, red = cv2.split(image)
    water = (blue > 105) & (green > 105) & (red < 165) & ((blue + green) > red * 2.0)
    alpha = (~water).astype(np.uint8) * 255
    alpha = cv2.morphologyEx(alpha, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    return cv2.GaussianBlur(alpha, (3, 3), 0)


def _green_plant_mask(image: np.ndarray) -> np.ndarray:
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    hue, saturation, value = cv2.split(hsv)
    blue, green_channel, red = cv2.split(image)
    green = ((hue >= 24) & (hue <= 92) & (saturation >= 45) & (value >= 28)
             & (green_channel >= blue + 5) & (green_channel >= red + 5))
    alpha = green.astype(np.uint8) * 255
    alpha = cv2.morphologyEx(alpha, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    alpha = cv2.morphologyEx(alpha, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
    return cv2.GaussianBlur(alpha, (3, 3), 0)


def _remove_small_components(alpha: np.ndarray, minimum_area: int) -> np.ndarray:
    count, labels, stats, _ = cv2.connectedComponentsWithStats(alpha, connectivity=8)
    cleaned = np.zeros_like(alpha)
    for label in range(1, count):
        if stats[label, cv2.CC_STAT_AREA] >= minimum_area:
            cleaned[labels == label] = 255
    return cleaned


def cutout(key: str, target: tuple[int, int]) -> None:
    source = cv2.imread(str(CACHE / f"{key}.jpg"), cv2.IMREAD_COLOR)
    if source is None:
        raise FileNotFoundError(key)
    crops = {
        "hornwort": (0.10, 0.08, 0.76, 0.88),
        "vallisneria": (0.12, 0.04, 0.66, 0.86),
    }
    if key in crops:
        left, top, width, height = crops[key]
        source = source[round(source.shape[0] * top) : round(source.shape[0] * (top + height)),
                        round(source.shape[1] * left) : round(source.shape[1] * (left + width))]
    # GrabCut scales poorly with large source photos.  A 600px working copy
    # preserves enough edge detail for these small UI assets while keeping
    # regeneration practical on developer machines.
    longest = max(source.shape[:2])
    if longest > 600:
        factor = 600 / longest
        source = cv2.resize(source, (round(source.shape[1] * factor), round(source.shape[0] * factor)), interpolation=cv2.INTER_AREA)
    alpha = _cyan_water_mask(source) if key == "lionfish" else (
        _green_plant_mask(source) if key in {"hornwort", "vallisneria"} else _foreground(source))
    if key == "lionfish":
        alpha = _remove_small_components(alpha, 250)
    elif key in {"yellow-tang", "cardinalfish"}:
        alpha = _remove_small_components(alpha, 55)
    rgba = cv2.cvtColor(source, cv2.COLOR_BGR2RGBA)
    rgba[:, :, 3] = alpha
    ys, xs = np.where(alpha > 18)
    if len(xs) == 0:
        raise RuntimeError(f"GrabCut produced no foreground for {key}")
    rgba = rgba[max(0, ys.min() - 4) : min(rgba.shape[0], ys.max() + 5), max(0, xs.min() - 4) : min(rgba.shape[1], xs.max() + 5)]
    image = Image.fromarray(rgba, "RGBA")
    image.thumbnail(target, Image.Resampling.LANCZOS)
    canvas = Image.new("RGBA", target, (0, 0, 0, 0))
    canvas.alpha_composite(image, ((target[0] - image.width) // 2, (target[1] - image.height) // 2))
    canvas.save(OUTPUT / f"{key}.png", optimize=True)


def contact_sheet() -> None:
    keys = list(SOURCES)
    sheet = Image.new("RGB", (1200, ((len(keys) + 3) // 4) * 260), "#12383f")
    draw = ImageDraw.Draw(sheet)
    for index, key in enumerate(keys):
        image = Image.open(OUTPUT / f"{key}.png").convert("RGBA")
        tile = Image.new("RGBA", (280, 220), (20, 64, 70, 255))
        image.thumbnail((270, 200), Image.Resampling.LANCZOS)
        tile.alpha_composite(image, ((280 - image.width) // 2, 18 + (200 - image.height) // 2))
        x, y = (index % 4) * 300, (index // 4) * 260
        sheet.paste(tile.convert("RGB"), (x, y))
        draw.text((x + 5, y + 4), key, fill="white")
    sheet.save(CACHE / "prepared-preview.jpg", quality=92)


def main() -> None:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    download_sources()
    for key in SOURCES:
        target = (240, 240) if key in {"java-fern", "hornwort", "amazon-sword", "vallisneria"} else (240, 180)
        cutout(key, target)
    contact_sheet()
    print(f"prepared realistic aquarium assets in {OUTPUT}")


if __name__ == "__main__":
    main()
