"""Generate the remaining original illustrated aquarium additions.

The launch aquarium uses photographic cut-outs. Most fish, crabs and plants
now use the reproducible Wikimedia preparation workflow in
``prepare-aquarium-realistic-assets.py``. This script only owns the remaining
small, deterministic illustrations and can run without a network download.
"""

from pathlib import Path
from typing import Iterable

from PIL import Image, ImageDraw


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "src" / "DeskBox" / "Assets" / "Aquarium"
SCALE = 3
SIZE = (240, 180)


def rgba(color: tuple[int, int, int], alpha: int = 255) -> tuple[int, int, int, int]:
    return (*color, alpha)


def canvas() -> tuple[Image.Image, ImageDraw.ImageDraw]:
    image = Image.new("RGBA", (SIZE[0] * SCALE, SIZE[1] * SCALE), (0, 0, 0, 0))
    return image, ImageDraw.Draw(image)


def p(point: tuple[float, float]) -> tuple[int, int]:
    return (round(point[0] * SCALE), round(point[1] * SCALE))


def box(bounds: tuple[float, float, float, float]) -> tuple[int, int, int, int]:
    return tuple(round(value * SCALE) for value in bounds)  # type: ignore[return-value]


def polygon(draw: ImageDraw.ImageDraw, points: Iterable[tuple[float, float]], fill, outline=None, width=1):
    points = [p(point) for point in points]
    draw.polygon(points, fill=fill)
    if outline:
        draw.line(points + [points[0]], fill=outline, width=width * SCALE, joint="curve")


def ellipse(draw: ImageDraw.ImageDraw, bounds, fill, outline=None, width=1):
    draw.ellipse(box(bounds), fill=fill, outline=outline, width=width * SCALE if outline else 1)


def line(draw: ImageDraw.ImageDraw, points, fill, width=1):
    draw.line([p(point) for point in points], fill=fill, width=width * SCALE, joint="curve")


def eye(draw: ImageDraw.ImageDraw, x: float, y: float):
    ellipse(draw, (x - 5, y - 5, x + 5, y + 5), rgba((245, 251, 244)))
    ellipse(draw, (x - 2, y - 2, x + 3, y + 3), rgba((12, 35, 42)))


def fish(name: str, body, accent, pattern: str):
    image, draw = canvas()
    outline = rgba((20, 57, 68), 235)
    # Tail first, then the body and fins, which keeps the silhouettes clean.
    polygon(draw, [(55, 88), (17, 52), (17, 125)], rgba(accent), outline, 2)
    ellipse(draw, (42, 43, 196, 134), rgba(body), outline, 2)
    polygon(draw, [(92, 49), (126, 17), (143, 53)], rgba(accent), outline, 2)
    polygon(draw, [(104, 128), (135, 161), (151, 126)], rgba(accent), outline, 2)
    if pattern == "stripes":
        for x in (82, 112, 143, 172):
            line(draw, [(x, 51), (x - 9, 127)], rgba((250, 246, 219), 220), 8)
    elif pattern == "spots":
        for x, y, r in ((101, 70, 7), (132, 103, 8), (161, 72, 6), (178, 108, 5)):
            ellipse(draw, (x - r, y - r, x + r, y + r), rgba(accent, 210))
    elif pattern == "spines":
        for x in range(74, 182, 16):
            line(draw, [(x, 50), (x - 10, 20)], rgba(accent), 3)
            line(draw, [(x, 127), (x - 10, 154)], rgba(accent), 3)
    elif pattern == "round":
        ellipse(draw, (68, 58, 184, 132), rgba(accent, 90), rgba(accent, 150), 2)
        for x, y in ((92, 70), (128, 62), (158, 87), (111, 112), (160, 115)):
            ellipse(draw, (x - 4, y - 4, x + 4, y + 4), rgba((245, 241, 203), 180))
    eye(draw, 177, 75)
    line(draw, [(181, 109), (196, 113), (184, 118)], outline, 2)
    save(image, name)


def seahorse():
    image, draw = canvas()
    outline = rgba((20, 57, 68), 240)
    body = rgba((229, 161, 79))
    line(draw, [(126, 135), (111, 119), (118, 101), (141, 91), (140, 69), (128, 50),
                (129, 32), (145, 22)], body, 18)
    line(draw, [(126, 135), (111, 119), (118, 101), (141, 91), (140, 69), (128, 50),
                (129, 32), (145, 22)], outline, 2)
    polygon(draw, [(142, 22), (171, 30), (148, 42)], body, outline, 2)
    polygon(draw, [(124, 70), (91, 86), (118, 96)], rgba((248, 190, 95)), outline, 2)
    eye(draw, 148, 28)
    for y in (54, 71, 88, 105):
        line(draw, [(121, y), (108, y + 5)], rgba((172, 93, 66), 200), 3)
    save(image, "seahorse")


def plant(name: str, color, style: str):
    image, draw = canvas()
    outline = rgba((15, 73, 65), 220)
    base = rgba((31, 91, 64), 230)
    # A transparent illustration has a low visual footprint over the seabed.
    line(draw, [(120, 166), (120, 83)], rgba((18, 76, 59), 230), 5)
    if style == "broad":
        leaves = [((120, 112), (70, 70), (70, 36)), ((120, 129), (164, 76), (186, 44)),
                  ((120, 92), (99, 37), (121, 15)), ((122, 145), (57, 113), (33, 80)),
                  ((123, 143), (174, 125), (200, 91))]
    elif style == "fern":
        leaves = [((120, 145), (83, 105), (50, 68)), ((120, 130), (156, 90), (190, 56)),
                  ((120, 111), (94, 63), (85, 21)), ((120, 105), (145, 55), (162, 18))]
    elif style == "branch":
        leaves = [((120, 154), (78, 101), (53, 43)), ((120, 145), (165, 94), (207, 44)),
                  ((116, 133), (87, 69), (63, 19)), ((128, 126), (160, 62), (188, 15))]
    else:
        leaves = [((112, 164), (83, 90), (68, 10)), ((119, 164), (109, 73), (104, 4)),
                  ((126, 164), (142, 72), (150, 9)), ((134, 163), (176, 92), (199, 24))]
    for start, bend, tip in leaves:
        line(draw, [start, bend, tip], color, 12 if style == "broad" else 7)
        line(draw, [start, bend, tip], outline, 1)
        if style == "fern":
            for offset in (-1, 1):
                line(draw, [bend, (bend[0] + 16 * offset, bend[1] - 16),
                            (tip[0] + 12 * offset, tip[1] - 5)], rgba(color[:3], 180), 4)
    ellipse(draw, (103, 157, 137, 175), base)
    save(image, name)


def shrimp(name: str, body, stripe):
    image, draw = canvas()
    outline = rgba((30, 56, 63), 230)
    line(draw, [(50, 120), (75, 97), (108, 78), (151, 80), (181, 99)], rgba(body), 21)
    line(draw, [(50, 120), (75, 97), (108, 78), (151, 80), (181, 99)], outline, 2)
    for x in (77, 101, 126, 151):
        line(draw, [(x, 83), (x - 8, 105)], rgba(stripe), 4)
    polygon(draw, [(181, 99), (216, 83), (205, 108)], rgba(body), outline, 2)
    for x in (77, 103, 129, 155):
        line(draw, [(x, 99), (x - 15, 132)], rgba(body), 3)
    line(draw, [(71, 92), (39, 67)], rgba(body), 2)
    line(draw, [(72, 95), (34, 93)], rgba(body), 2)
    ellipse(draw, (166, 91, 176, 101), rgba((245, 251, 244)))
    ellipse(draw, (169, 94, 174, 99), rgba((10, 31, 38)))
    save(image, name)


def crab(name: str, shell, claw):
    image, draw = canvas()
    outline = rgba((29, 57, 58), 235)
    ellipse(draw, (74, 57, 169, 135), rgba(shell), outline, 2)
    for x in (82, 98, 145, 161):
        line(draw, [(x, 108), (x - 26 if x < 120 else x + 26, 142)], rgba(shell), 5)
    line(draw, [(83, 84), (47, 55)], rgba(claw), 7)
    line(draw, [(160, 84), (194, 48)], rgba(claw), 7)
    ellipse(draw, (31, 43, 58, 68), rgba(claw), outline, 2)
    ellipse(draw, (184, 36, 211, 63), rgba(claw), outline, 2)
    ellipse(draw, (91, 69, 103, 81), rgba((245, 251, 244)))
    ellipse(draw, (143, 69, 155, 81), rgba((245, 251, 244)))
    ellipse(draw, (95, 73, 101, 79), rgba((10, 31, 38)))
    ellipse(draw, (147, 73, 153, 79), rgba((10, 31, 38)))
    save(image, name)


def shell(name: str, shell_color, inner):
    image, draw = canvas()
    outline = rgba((68, 48, 44), 230)
    polygon(draw, [(36, 137), (46, 77), (77, 38), (120, 22), (163, 38), (194, 77),
                   (204, 137)], rgba(shell_color), outline, 2)
    for x in range(55, 196, 23):
        line(draw, [(120, 137), (x, 50 + abs(120 - x) * .12)], rgba(inner, 180), 3)
    ellipse(draw, (70, 121, 170, 158), rgba(inner, 170), outline, 2)
    save(image, name)


def save(image: Image.Image, name: str):
    OUTPUT.mkdir(parents=True, exist_ok=True)
    image.resize(SIZE, Image.Resampling.LANCZOS).save(OUTPUT / f"{name}.png", optimize=True)


def main():
    plant("anubias", rgba((62, 145, 91)), "broad")
    shrimp("cherry-shrimp", (214, 75, 64), (250, 177, 137))
    shrimp("amano-shrimp", (154, 174, 157), (238, 223, 173))
    shell("scallop", (222, 137, 128), (248, 204, 176))
    shell("oyster", (137, 157, 149), (224, 229, 195))
    print(f"generated aquarium illustrations in {OUTPUT}")


if __name__ == "__main__":
    main()
