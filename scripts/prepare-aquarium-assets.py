"""Prepare licensed photographic aquarium assets from Wikimedia Commons.

Run: uv run --with pillow --with numpy --with opencv-python-headless python scripts/prepare-aquarium-assets.py
Source photographs and API metadata are cached under .artifacts/aquarium-sources.
The shipped photographs retain their source licenses; see Assets/Aquarium/LICENSES.md.
"""

from pathlib import Path
import json
import urllib.parse
import urllib.request
from PIL import Image, ImageDraw, ImageOps
import cv2
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / ".artifacts" / "aquarium-sources"
OUTPUT = ROOT / "src" / "DeskBox" / "Assets" / "Aquarium"
SOURCES = {
    "clownfish": "File:Common clownfish.jpg",
    "blue-tang": "File:Paracanthurus-hepatus-paletten-doktorfisch.jpg",
    "blue-tang-alt": "File:Paracanthurus hepatus - Wilhelma 01.jpg",
    "butterflyfish": "File:Pez mariposa arábigo (Chaetodon melapterus), islas Ad Dimaniyat, Omán, 2024-08-13, DD 77.jpg",
    "seagrass": "File:Caulerpa prolifera133.jpg",
    "fan-algae": "File:Udotea flabellum (mermaid's fan alga) (San Salvador Island, Bahamas) 1 (15860589498).jpg",
    "reef-rock": "File:A pumice stone.jpg",
    "background": "File:Underwater Shots of Barren Sandy Sea floor in Bali Diving Sites.png",
    "seagrass-alt": "File:Vallisneria asiatica var. biwaensis..JPG",
    "reef-rock-alt": "File:Crinoids in fossiliferous limestone 1.jpg",
    "rock-arch-new": "File:Rock Arch - geograph.org.uk - 592784.jpg",
}


def fetch(url):
    request = urllib.request.Request(url, headers={"User-Agent": "SltBoxAssetPreparation/1.0 (licensed desktop aquarium assets)"})
    with urllib.request.urlopen(request, timeout=45) as response:
        return response.read()


def download_sources():
    CACHE.mkdir(parents=True, exist_ok=True)
    for key, title in SOURCES.items():
        metadata_path = CACHE / (key + ".json")
        if not metadata_path.exists():
            query = urllib.parse.urlencode({"action": "query", "format": "json", "titles": title,
                "prop": "imageinfo", "iiprop": "url|extmetadata", "iiurlwidth": "1280"})
            pages = json.loads(fetch("https://commons.wikimedia.org/w/api.php?" + query))["query"]["pages"]
            info = next(iter(pages.values()))["imageinfo"][0]
            metadata_path.write_text(json.dumps(info, ensure_ascii=False, indent=2), encoding="utf-8")
        else:
            info = json.loads(metadata_path.read_text(encoding="utf-8"))
        path = CACHE / (key + ".jpg")
        if not path.exists():
            url = info.get("thumburl", info["url"]).split("?")[0]
            data = fetch(url)
            path.write_bytes(data)
        print(key, info["extmetadata"]["LicenseShortName"]["value"], flush=True)


def contact_sheet():
    sheet = Image.new("RGB", (1200, ((len(SOURCES) + 2) // 3) * 300), "#153a40")
    draw = ImageDraw.Draw(sheet)
    for index, key in enumerate(SOURCES):
        image = Image.open(CACHE / (key + ".jpg")).convert("RGB")
        image.thumbnail((390, 260))
        x, y = (index % 3) * 400, (index // 3) * 300
        sheet.paste(image, (x, y + 24))
        draw.text((x + 4, y + 4), key + " " + str(Image.open(CACHE / (key + ".jpg")).size), fill="white")
    path = CACHE / "sources-preview.jpg"
    sheet.save(path, quality=92)
    print(path)


def cutout(key, box, target, flip=False):
    """GrabCut a photograph rectangle, crop alpha bounds, then fit the logical slot."""
    image = cv2.imread(str(CACHE / (key + ".jpg")), cv2.IMREAD_COLOR)
    if image is None:
        raise FileNotFoundError(key)
    x, y, width, height = box
    crop = image[y:y + height, x:x + width]
    mask = np.zeros(crop.shape[:2], np.uint8)
    rect = (max(1, width // 30), max(1, height // 30), max(2, width - width // 15), max(2, height - height // 15))
    bgd = np.zeros((1, 65), np.float64)
    fgd = np.zeros((1, 65), np.float64)
    cv2.grabCut(crop, mask, rect, bgd, fgd, 7, cv2.GC_INIT_WITH_RECT)
    alpha = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype(np.uint8)
    alpha = cv2.morphologyEx(alpha, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    alpha = cv2.GaussianBlur(alpha, (3, 3), 0)
    rgba = cv2.cvtColor(crop, cv2.COLOR_BGR2RGBA)
    rgba[:, :, 3] = alpha
    ys, xs = np.where(alpha > 12)
    if len(xs) == 0:
        raise RuntimeError("GrabCut produced no foreground for " + key)
    rgba = rgba[max(0, ys.min() - 3):min(rgba.shape[0], ys.max() + 4), max(0, xs.min() - 3):min(rgba.shape[1], xs.max() + 4)]
    if flip:
        rgba = np.ascontiguousarray(rgba[:, ::-1])
    out = Image.fromarray(rgba, "RGBA")
    out.thumbnail(target, Image.Resampling.LANCZOS)
    canvas = Image.new("RGBA", target, (0, 0, 0, 0))
    canvas.alpha_composite(out, ((target[0] - out.width) // 2, (target[1] - out.height) // 2))
    canvas.save(OUTPUT / (key + ".png"), optimize=True)


def shaped_crop(source_key, box, target, ellipse=True):
    """Use a photographic crop when a textured subject has no separable background."""
    image = Image.open(CACHE / (source_key + ".jpg")).convert("RGBA").crop((box[0], box[1], box[0] + box[2], box[1] + box[3]))
    image.thumbnail(target, Image.Resampling.LANCZOS)
    canvas = Image.new("RGBA", target, (0, 0, 0, 0))
    alpha = Image.new("L", image.size, 0)
    draw = ImageDraw.Draw(alpha)
    if ellipse:
        draw.ellipse((3, 3, image.width - 3, image.height - 3), fill=255)
    else:
        draw.rounded_rectangle((3, 3, image.width - 3, image.height - 3), radius=18, fill=255)
    image.putalpha(alpha)
    canvas.alpha_composite(image, ((target[0] - image.width) // 2, (target[1] - image.height) // 2))
    canvas.save(OUTPUT / (source_key + ".png"), optimize=True)


def color_cutout(source_key, box, target, kind, flip=False):
    image = np.array(Image.open(CACHE / (source_key + ".jpg")).convert("RGB"))
    x, y, width, height = box
    crop = image[y:y + height, x:x + width]
    red, green, blue = crop[:, :, 0].astype(np.int16), crop[:, :, 1].astype(np.int16), crop[:, :, 2].astype(np.int16)
    if kind == "clown":
        mask = (((red > 125) & (red >= green - 18) & (green > blue + 15))
            | ((red > 175) & (green > 175) & (blue > 150)))
        polygon = np.array([[45, 125], [105, 55], [265, 25], [430, 65], [640, 110],
            [705, 185], [650, 315], [460, 395], [240, 365], [75, 275]], np.int32)
        keep = np.zeros(mask.shape, np.uint8)
        cv2.fillPoly(keep, [polygon], 1)
        mask &= keep.astype(bool)
    elif kind == "grass":
        mask = (green > red + 10) & (green > blue + 5) & (green > 45)
    elif kind == "fan":
        saturation = np.maximum.reduce([red, green, blue]) - np.minimum.reduce([red, green, blue])
        mask = (green > red + 6) & (green > blue - 10) & (green > 35) & (saturation > 22)
        yy, xx = np.ogrid[:mask.shape[0], :mask.shape[1]]
        mask &= (((xx - mask.shape[1] * .55) / (mask.shape[1] * .58)) ** 2
            + ((yy - mask.shape[0] * .47) / (mask.shape[0] * .53)) ** 2 < 1)
        mask &= yy < int(mask.shape[0] * .67)
    elif kind == "rock":
        mask = (np.abs(red - green) < 32) & (np.abs(green - blue) < 32) & (red > 70)
    elif kind == "arch":
        mask = (np.abs(red - green) < 34) & (np.abs(green - blue) < 34) & (red > 65)
    else:
        mask = np.ones(crop.shape[:2], dtype=bool)
    mask = (mask.astype(np.uint8) * 255)
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
    mask = cv2.GaussianBlur(mask, (3, 3), 0)
    rgba = np.dstack((crop, mask)).astype(np.uint8)
    ys, xs = np.where(mask > 20)
    if not len(xs):
        raise RuntimeError("color segmentation produced no foreground for " + source_key)
    rgba = rgba[max(0, ys.min() - 3):min(rgba.shape[0], ys.max() + 4), max(0, xs.min() - 3):min(rgba.shape[1], xs.max() + 4)]
    if flip:
        rgba = np.ascontiguousarray(rgba[:, ::-1])
    out = Image.fromarray(rgba, "RGBA")
    out.thumbnail(target, Image.Resampling.LANCZOS)
    canvas = Image.new("RGBA", target, (0, 0, 0, 0))
    canvas.alpha_composite(out, ((target[0] - out.width) // 2, (target[1] - out.height) // 2))
    canvas.save(OUTPUT / (source_key + ".png"), optimize=True)


def make_assets():
    OUTPUT.mkdir(parents=True, exist_ok=True)
    Image.open(CACHE / "background.jpg").convert("RGB").resize((840, 600), Image.Resampling.LANCZOS).save(OUTPUT / "background.png", optimize=True)
    # The source clownfish faces left; the scene uses a consistent right-facing silhouette.
    color_cutout("clownfish", (220, 80, 720, 420), (125, 84), "clown", flip=True)
    cutout("blue-tang", (150, 190, 980, 530), (150, 100))
    cutout("butterflyfish", (90, 120, 1120, 600), (132, 110))
    color_cutout("seagrass-alt", (450, 40, 780, 900), (150, 220), "grass")
    color_cutout("fan-algae", (170, 520, 760, 560), (186, 210), "fan")
    shaped_crop("reef-rock-alt", (520, 380, 360, 300), (220, 154))
    color_cutout("rock-arch-new", (130, 100, 450, 300), (238, 170), "arch")
    # Map the alternate source names to the catalog's stable asset keys.
    (OUTPUT / "seagrass-alt.png").replace(OUTPUT / "seagrass.png")
    (OUTPUT / "reef-rock-alt.png").replace(OUTPUT / "reef-rock.png")
    (OUTPUT / "rock-arch-new.png").replace(OUTPUT / "rock-arch.png")
    print("prepared", OUTPUT)


if __name__ == "__main__":
    download_sources()
    # The arch source is fetched by the small one-off research command; keep a stable cache name for reproducibility.
    if not (CACHE / "rock-arch-new.jpg").exists():
        raise SystemExit("Missing .artifacts/aquarium-sources/rock-arch-new.jpg; fetch the documented Commons source first")
    make_assets()
    contact_sheet()
