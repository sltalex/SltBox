"""Original, deterministic cartoon crab SVGs for the aquarium.

Ten hand-authored silhouettes share rounded teal outlines and top-left lighting.
Only vector shapes are emitted: no raster images, fonts, filters or references.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from xml.etree import ElementTree
from xml.sax.saxutils import escape


INK = "#173e49"
SPECIES = {
    "hermit-crab": "寄居蟹",
    "fiddler-crab": "招潮蟹",
    "porcelain-crab": "瓷蟹",
    "emerald-crab": "绿宝石蟹",
    "red-claw-crab": "红螯蟹",
    "vampire-crab": "吸血鬼蟹",
    "pom-pom-crab": "拳击蟹",
    "blue-swimmer-crab": "蓝花蟹",
    "sally-lightfoot-crab": "红石蟹",
    "halloween-crab": "万圣节蟹",
}


class Drawing:
    def __init__(self):
        self.parts = []

    def path(self, data, fill="none", stroke=INK, width=4, **attrs):
        extra = " ".join(f'{key.replace("_", "-")}="{value}"' for key, value in attrs.items())
        self.parts.append(f'<path d="{data}" fill="{fill}" stroke="{stroke}" stroke-width="{width}" stroke-linecap="round" stroke-linejoin="round" {extra}/>')

    def ellipse(self, x, y, rx, ry, fill, stroke=INK, width=4, **attrs):
        extra = " ".join(f'{key.replace("_", "-")}="{value}"' for key, value in attrs.items())
        self.parts.append(f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" fill="{fill}" stroke="{stroke}" stroke-width="{width}" {extra}/>')

    def circle(self, x, y, radius, fill, stroke="none", width=0):
        self.ellipse(x, y, radius, radius, fill, stroke, width)

    def group(self, transform):
        self.parts.append(f'<g transform="{transform}">')

    def end(self):
        self.parts.append('</g>')

    def tube(self, data, fill, width=10):
        self.path(data, stroke=INK, width=width + 5)
        self.path(data, stroke=fill, width=width)

    def document(self, entry):
        name = SPECIES[entry["id"]]
        return ('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240" viewBox="0 0 320 240">\n'
                f'<title>{escape(name)} — original cartoon aquarium illustration</title>\n'
                '<desc>Hand-authored vector crab with a transparent background, rounded outlines and playful species-specific features.</desc>\n'
                + "\n".join(self.parts) + '\n</svg>\n')


def legs(a, color, kind="regular"):
    """Four walking legs per side, drawn before the carapace."""
    shapes = {
        "regular": [
            "M119 142 Q92 137 72 146 L45 166",
            "M117 151 Q89 153 77 167 L57 187",
            "M120 160 Q101 172 95 188 L83 207",
            "M128 167 Q118 181 118 196 L113 215",
        ],
        "flat": [
            "M117 136 Q86 125 62 134 L27 151",
            "M114 147 Q84 148 63 167 L30 179",
            "M119 157 Q90 173 82 192 L54 209",
            "M129 164 Q108 180 105 202 L94 220",
        ],
        "chunky": [
            "M117 139 Q83 137 69 153 L43 168",
            "M118 150 Q92 156 79 174 L61 190",
            "M125 159 Q106 176 104 192 L92 207",
            "M136 167 Q128 184 130 201 L123 215",
        ],
    }
    for transform in ("", "translate(320 0) scale(-1 1)"):
        if transform:
            a.group(transform)
        for index, data in enumerate(shapes[kind]):
            a.tube(data, color, 9 if kind != "chunky" else 12)
        if transform:
            a.end()


def claw(a, x, y, color, light, scale=1, rotate=0, mirror=False, broad=False):
    a.group(f'translate({x} {y}) rotate({rotate}) scale({-scale if mirror else scale} {scale})')
    if broad:
        a.path("M-22 24 C-41 11 -44 -7 -34 -24 C-25 -38 -9 -42 2 -35 L-11 -12 L2 -4 L21 -32 C38 -22 46 -4 38 12 C30 29 6 34 -22 24Z", color)
        a.path("M-30 -11 Q-25 -27 -12 -29 L-20 -12 Q-23 -1 -14 8 Q-29 7 -30 -11Z", light, "none", 0)
    else:
        a.path("M-15 25 C-34 16 -36 -5 -27 -23 C-21 -37 -9 -41 1 -36 L-10 -14 L1 -4 L17 -31 C32 -25 38 -11 32 7 C27 22 7 33 -15 25Z", color)
        a.path("M-25 -13 Q-23 -29 -11 -31 L-18 -14 Q-22 -2 -12 8 Q-28 5 -25 -13Z", light, "none", 0)
    a.path("M-12 16 Q0 22 15 16", stroke=light, width=3)
    a.end()


def arms(a, color, left=(69, 107), right=(251, 107), width=12):
    a.tube(f"M119 140 Q88 150 {left[0]} {left[1]}", color, width)
    a.tube(f"M201 140 Q232 150 {right[0]} {right[1]}", color, width)


def body(a, color, light, shadow, kind="round"):
    if kind == "round":
        a.path("M98 141 C96 111 118 96 160 96 C202 96 224 111 222 141 C221 174 196 190 160 190 C124 190 99 174 98 141Z", color)
        a.path("M103 150 C121 175 194 181 218 148 C213 175 189 187 159 187 C130 187 109 175 103 150Z", shadow, "none", 0)
        a.path("M112 122 C120 106 146 103 164 106 C173 109 164 116 150 117 C132 117 121 128 115 132 Q108 133 112 122Z", light, "none", 0)
    elif kind == "flat":
        a.path("M90 143 L101 130 C105 111 129 102 160 102 C191 102 215 111 219 130 L230 143 L219 153 C209 174 187 181 160 181 C133 181 111 174 101 153Z", color)
        a.path("M102 151 Q160 183 218 151 C207 169 185 177 160 177 C135 177 113 169 102 151Z", shadow, "none", 0)
        a.path("M111 129 Q127 110 160 112 Q178 111 179 117 Q176 124 154 123 Q128 123 116 137Z", light, "none", 0)
    elif kind == "square":
        a.path("M103 140 L107 111 Q110 96 127 95 L193 95 Q210 96 213 111 L217 140 Q217 175 192 187 L128 187 Q103 175 103 140Z", color)
        a.path("M108 151 Q160 179 212 151 Q207 176 190 183 L130 183 Q113 176 108 151Z", shadow, "none", 0)
        a.path("M116 111 Q117 103 130 103 L168 103 Q176 103 172 109 Q167 113 144 113 Q127 113 119 121Z", light, "none", 0)


def eyes(a, left=140, right=180, base_y=115, top_y=83, color="#f7fff3", stalk="#ec974d", pupil=INK):
    for x in (left, right):
        a.tube(f"M{x} {base_y} Q{x - 2} {top_y + 16} {x} {top_y + 7}", stalk, 7)
        a.ellipse(x, top_y, 13, 16, color)
        a.ellipse(x + 2, top_y + 2, 5, 7, pupil, "none", 0)
        a.circle(x, top_y - 2, 2.2, "#ffffff")


def face(a, y=151, blush="#ff9e91", smile="happy"):
    a.ellipse(133, y - 5, 8, 4, blush, "none", 0)
    a.ellipse(187, y - 5, 8, 4, blush, "none", 0)
    if smile == "happy":
        a.path(f"M149 {y} Q160 {y + 14} 171 {y}", stroke=INK, width=3.4)
    elif smile == "open":
        a.path(f"M149 {y} Q160 {y + 3} 171 {y} Q169 {y + 17} 160 {y + 17} Q151 {y + 17} 149 {y}Z", INK, INK, 2)
        a.path(f"M154 {y + 13} Q160 {y + 8} 166 {y + 13} Q160 {y + 19} 154 {y + 13}Z", "#ff9587", "none", 0)
    else:
        a.path(f"M150 {y + 2} Q158 {y + 9} 166 {y + 2} L172 {y - 1}", stroke=INK, width=3.4)


def hermit(a):
    # The offset spiralled shell is the dominant silhouette, not a crab recolour.
    a.path("M166 181 C152 172 144 146 147 113 C150 73 179 40 213 38 C255 36 281 62 281 100 C281 145 252 181 216 189Z", "#f3bc79")
    a.path("M179 177 C214 179 268 147 275 107 C279 150 252 183 215 186Z", "#d88c5f", "none", 0)
    a.path("M165 112 C163 72 190 45 222 47 Q244 48 256 61 C224 46 184 73 180 110Z", "#ffe1a1", "none", 0)
    a.path("M185 157 C225 171 263 146 262 108 C262 74 232 61 208 77 C187 90 187 120 205 132 C222 145 244 133 242 116 C240 102 225 98 218 108 C212 118 219 124 225 120", stroke="#905e51", width=5)
    for d in ("M124 166 Q102 173 94 190 L82 205", "M141 171 Q131 189 133 210", "M160 171 Q162 192 171 207", "M177 169 Q192 182 201 201"):
        a.tube(d, "#e88762", 11)
    a.tube("M112 154 Q83 166 61 138", "#e88762", 12)
    a.tube("M174 151 Q194 146 200 125", "#e88762", 10)
    claw(a, 54, 124, "#f59b71", "#ffcc9c", .88, -19)
    claw(a, 204, 116, "#f59b71", "#ffcc9c", .62, 12, True)
    a.path("M97 146 C97 120 116 108 143 108 C173 108 188 125 187 148 C186 176 170 187 141 187 C113 187 98 174 97 146Z", "#f59b71")
    a.path("M104 155 Q143 180 180 152 Q175 182 141 183 Q112 183 104 155Z", "#df7c65", "none", 0)
    a.path("M109 133 Q117 117 140 117 Q151 119 142 124 Q122 124 115 139Z", "#ffcc9c", "none", 0)
    eyes(a, 120, 156, 127, 92, stalk="#f59b71")
    a.ellipse(115, 153, 7, 4, "#ffbd9c", "none", 0)
    a.ellipse(166, 153, 7, 4, "#ffbd9c", "none", 0)
    a.path("M130 155 Q141 167 152 155", stroke=INK, width=3.5)


def fiddler(a):
    legs(a, "#e99e57")
    arms(a, "#e99e57", (63, 117), (240, 134))
    claw(a, 73, 86, "#ffcd73", "#fff0bc", 1.40, -12, broad=True)
    claw(a, 247, 121, "#f2aa62", "#ffda91", .57, 18, True)
    body(a, "#efac65", "#ffda91", "#ce784e", "square")
    a.path("M112 130 Q160 146 209 128", stroke="#cf804d", width=5)
    eyes(a, 143, 180, 116, 77, stalk="#eeac68")
    face(a, 156, "#ffcf9c", "cheeky")


def porcelain(a):
    legs(a, "#e8a5af", "flat")
    arms(a, "#e8a5af", (63, 104), (257, 104))
    claw(a, 59, 92, "#fff0d9", "#ffffff", 1.06, -24, broad=True)
    claw(a, 261, 92, "#fff0d9", "#ffffff", 1.06, 24, True, True)
    body(a, "#fff0d9", "#ffffff", "#efb7b8", "flat")
    for x, y, r in ((117, 135, 4), (130, 124, 3), (151, 119, 3), (178, 123, 4), (201, 137, 4), (122, 153, 3), (199, 156, 3), (160, 168, 3)):
        a.circle(x, y, r, "#cf788d")
    eyes(a, 141, 179, 123, 91, stalk="#f3c9bd")
    face(a, 146, "#f6aab3")


def emerald(a):
    legs(a, "#43ac80", "chunky")
    arms(a, "#43ac80", (66, 119), (254, 119), 17)
    claw(a, 63, 103, "#4bc493", "#9be5a8", 1.05, -22)
    claw(a, 257, 103, "#4bc493", "#9be5a8", 1.05, 22, True)
    body(a, "#4bc493", "#9be5a8", "#2b8e76")
    a.path("M138 114 L160 106 L181 115 L172 132 L149 132Z", "#72d798", "none", 0)
    for x, y in ((116, 137), (127, 124), (195, 127), (205, 142), (117, 156), (203, 159)):
        a.circle(x, y, 3, "#238b73")
    eyes(a, 142, 178, 120, 89, stalk="#4bc493")
    face(a, 151, "#a7e6a2", "open")


def red_claw(a):
    legs(a, "#94715c")
    arms(a, "#b66f56", (62, 107), (258, 107))
    claw(a, 61, 91, "#f37860", "#ffc78e", 1.12, -22)
    claw(a, 259, 91, "#f37860", "#ffc78e", 1.12, 22, True)
    body(a, "#b88e61", "#eacb83", "#8d6b59", "square")
    a.path("M123 115 Q160 131 199 115", stroke="#d1a96a", width=6)
    eyes(a, 140, 180, 118, 84, stalk="#c39d6a")
    face(a, 150, "#eda77c")


def vampire(a):
    legs(a, "#9463b9")
    arms(a, "#9463b9", (70, 112), (250, 112))
    claw(a, 67, 101, "#bd8add", "#e3bdf3", .93, -29)
    claw(a, 253, 101, "#bd8add", "#e3bdf3", .93, 29, True)
    body(a, "#a97acb", "#d7a4ed", "#7955a4", "square")
    a.path("M121 109 Q160 95 199 109 L190 125 Q160 113 130 125Z", "#dac1ef", "none", 0)
    eyes(a, 140, 180, 118, 84, "#ffe780", "#ad81ce")
    face(a, 150, "#e1acdf", "open")
    # Tiny pale mouth corners suggest the name without a frightening expression.
    a.path("M151 151 L155 156 L158 152 M163 152 L166 156 L169 151", "#fff3d7", "#fff3d7", 2)


def pompom(a, x, y):
    a.group(f"translate({x} {y})")
    a.path("M-30 4 Q-43 -6 -30 -16 Q-36 -31 -20 -32 Q-17 -48 -4 -37 Q7 -49 15 -34 Q33 -39 33 -23 Q48 -18 36 -5 Q47 11 31 17 Q32 34 15 31 Q6 46 -6 32 Q-23 40 -27 23 Q-43 22 -30 4Z", "#ffd7df", INK, 3.5)
    a.ellipse(0, 0, 25, 24, "#fff0e8", "none", 0)
    for d in ("M-14 -10 L-23 -22", "M-2 -15 L-3 -29", "M12 -11 L22 -25", "M17 2 L29 2", "M12 14 L18 25", "M-3 16 L-8 27", "M-17 4 L-28 8"):
        a.path(d, stroke="#ee9ab7", width=3)
    for x1, y1 in ((-11, -3), (3, -8), (12, 2), (-3, 9)):
        a.circle(x1, y1, 3, "#f7b5c8")
    a.end()


def boxer(a):
    legs(a, "#d6ab95")
    arms(a, "#ddb19e", (67, 114), (253, 114), 10)
    pompom(a, 62, 91)
    pompom(a, 258, 91)
    body(a, "#f3d6ad", "#fff2ca", "#d8a999")
    for d in ("M109 131 L127 128 L122 140 L108 145Z", "M117 164 L135 169 L129 180Z", "M202 125 L211 137 L198 145 L191 133Z", "M191 167 L207 156 L209 167 L198 178Z"):
        a.path(d, "#b58b8c", "none", 0)
    eyes(a, 141, 179, 119, 85, stalk="#e8c59f")
    face(a, 149, "#f3a2a9", "open")


def swimmer(a):
    legs(a, "#649bd4", "flat")
    # Paddle-shaped rear legs clearly distinguish this swimming crab.
    for transform in ("", "translate(320 0) scale(-1 1)"):
        if transform:
            a.group(transform)
        a.path("M105 193 C91 188 72 193 69 207 C68 222 95 225 108 212 Q114 203 105 193Z", "#76b9ec")
        a.path("M78 205 Q90 197 101 202", stroke="#c0e5f5", width=4)
        if transform:
            a.end()
    arms(a, "#74acdb", (62, 111), (258, 111))
    claw(a, 59, 96, "#95d5ef", "#e1f7f6", .94, -24)
    claw(a, 261, 96, "#95d5ef", "#e1f7f6", .94, 24, True)
    body(a, "#68b9d6", "#b5e9e7", "#428baf", "flat")
    a.path("M102 137 L84 137 L99 151 M218 137 L236 137 L221 151", "#68b9d6", INK, 3)
    for x, y, r in ((119, 132, 4), (132, 120, 3), (158, 118, 3), (187, 122, 3), (201, 135, 4), (120, 153, 3), (198, 154, 3), (139, 167, 3), (181, 167, 3)):
        a.circle(x, y, r, "#daf6ee")
    eyes(a, 142, 178, 123, 91, stalk="#75bfd8")
    face(a, 145, "#b4e5eb", "cheeky")


def sally(a):
    legs(a, "#ed8a51", "flat")
    arms(a, "#ee8a50", (65, 113), (255, 113), 9)
    claw(a, 62, 103, "#f19c57", "#ffdc8b", .77, -32)
    claw(a, 258, 103, "#f19c57", "#ffdc8b", .77, 32, True)
    body(a, "#ed7553", "#ffb76b", "#c7554c", "flat")
    for d in ("M115 124 Q130 115 146 116", "M174 116 Q190 115 205 124", "M108 137 L128 133", "M192 133 L212 137", "M113 152 L130 153", "M190 153 L207 152", "M127 165 L139 161", "M181 161 L193 165"):
        a.path(d, stroke="#ffd57e", width=4)
    a.path("M111 173 L105 183 M209 173 L215 183 M80 174 L72 182 M240 174 L248 182", stroke="#81c4c6", width=6)
    eyes(a, 142, 178, 120, 88, stalk="#f49658")
    face(a, 144, "#ffd294", "open")


def halloween(a):
    legs(a, "#f2a050", "chunky")
    arms(a, "#c57abe", (67, 110), (253, 110), 13)
    claw(a, 63, 94, "#c98dce", "#edc2e5", 1.04, -24)
    claw(a, 257, 94, "#c98dce", "#edc2e5", 1.04, 24, True)
    body(a, "#374d61", "#687088", "#25394e", "square")
    a.path("M109 118 Q118 106 135 110 L141 126 Q126 134 111 133Z", "#f5b55e", "none", 0)
    a.path("M211 118 Q202 106 185 110 L179 126 Q194 134 209 133Z", "#f5b55e", "none", 0)
    a.path("M141 170 Q160 179 179 170", stroke="#dd8952", width=5)
    eyes(a, 142, 178, 119, 83, "#fff0af", "#e5ac62")
    a.ellipse(133, 146, 7, 4, "#a1839e", "none", 0)
    a.ellipse(187, 146, 7, 4, "#a1839e", "none", 0)
    a.path("M149 149 Q160 161 171 149", stroke="#fce1a1", width=3.5)


BUILDERS = {
    "hermit-crab": hermit,
    "fiddler-crab": fiddler,
    "porcelain-crab": porcelain,
    "emerald-crab": emerald,
    "red-claw-crab": red_claw,
    "vampire-crab": vampire,
    "pom-pom-crab": boxer,
    "blue-swimmer-crab": swimmer,
    "sally-lightfoot-crab": sally,
    "halloween-crab": halloween,
}


def generate(entries, output_dir):
    """Write exactly the requested, known crab assets and validate their SVG XML."""
    output_dir = Path(output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    paths = []
    for entry in entries:
        ident = entry["id"]
        if ident not in BUILDERS:
            raise ValueError(f"No authored crab geometry for {ident}")
        drawing = Drawing()
        BUILDERS[ident](drawing)
        content = drawing.document(entry)
        root = ElementTree.fromstring(content)
        if root.attrib.get("viewBox") != "0 0 320 240":
            raise ValueError(f"Unexpected canvas for {ident}")
        path = output_dir / f"{ident}.svg"
        with path.open("w", encoding="utf-8", newline="\n") as target:
            target.write(content)
        paths.append(path)
    return paths


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path)
    parser.add_argument("--output-dir", type=Path, default=root / "src/DeskBox/Assets/Aquarium/Svg")
    args = parser.parse_args()
    entries = json.loads(args.manifest.read_text(encoding="utf-8-sig"))["crabs"] if args.manifest else [{"id": key} for key in SPECIES]
    generated = generate(entries, args.output_dir)
    print(f"Generated and XML-validated {len(generated)} original cartoon crab SVGs.")
