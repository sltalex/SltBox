"""Original, reproducible cartoon shrimp artwork for the aquarium.

The standalone transparent SVGs use only vector geometry.  Every shrimp faces
right: its tail fan is on the left and its eyes and rostrum are on the right.
The silhouettes, appendages and markings distinguish the ten named animals.
"""

from __future__ import annotations

import argparse
from html import escape
from pathlib import Path
import xml.etree.ElementTree as ET


INK = "#173e4a"
CREAM = "#fff5df"

NAMES = {
    "cherry-shrimp": "樱花虾",
    "amano-shrimp": "大和藻虾",
    "crystal-red-shrimp": "水晶红虾",
    "blue-dream-shrimp": "蓝梦虾",
    "yellow-shrimp": "黄金米虾",
    "tiger-shrimp": "虎纹虾",
    "bamboo-shrimp": "竹节虾",
    "cleaner-shrimp": "清洁虾",
    "peppermint-shrimp": "薄荷虾",
    "harlequin-shrimp": "小丑虾",
}


def path(d: str, fill: str = "none", stroke: str = INK, width: float = 3.6,
         extra: str = "") -> str:
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" '
            f'stroke-width="{width}" stroke-linecap="round" '
            f'stroke-linejoin="round" {extra}/>')


def ellipse(x: float, y: float, rx: float, ry: float, fill: str,
            stroke: str = "none", width: float = 3, extra: str = "") -> str:
    return (f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" '
            f'fill="{fill}" stroke="{stroke}" stroke-width="{width}" {extra}/>')


def eye(x: float, y: float, size: float = 10) -> str:
    return (path(f"M{x - 3} {y + 12} L{x} {y + 5}", width=4.8)
            + ellipse(x, y, size, size + 1, CREAM, INK, 3.2)
            + ellipse(x + 3, y + 1, size * .48, size * .58, INK)
            + ellipse(x + 4, y - 2, 1.8, 2.2, "#ffffff"))


# Body profiles are deliberately different, from the compact harlequin to the
# sleek cleaner; identical category silhouettes are not simply recoloured.
PROFILES = {
    "cherry-shrimp": {
        "body": "M70 151 C81 113 104 85 142 81 C183 77 222 86 244 109 L274 108 L257 121 Q266 144 244 154 Q221 161 203 139 C173 120 137 127 115 151 Q93 174 70 166 Z",
        "base": "#f56d70", "shade": "#d44760", "light": "#ffb2a2",
        "segments": ["M99 104 Q112 128 105 158", "M124 85 Q137 107 137 133", "M154 82 Q163 104 162 127", "M183 86 Q196 108 191 133"],
        "tail": "round", "eyes": (231, 100, 250, 108),
    },
    "amano-shrimp": {
        "body": "M66 151 Q85 122 113 108 C146 88 188 87 221 103 L244 113 L274 115 L251 126 Q252 143 237 146 Q218 148 202 133 C168 119 139 129 114 149 Q89 168 68 164 Z",
        "base": "#aabc89", "shade": "#738c64", "light": "#dae4b6",
        "segments": ["M96 120 Q110 132 109 153", "M119 104 Q134 116 134 137", "M145 94 Q157 109 158 126", "M175 94 Q183 109 182 127"],
        "tail": "narrow", "eyes": (230, 102, 250, 109),
    },
    "crystal-red-shrimp": {
        "body": "M68 155 Q79 115 108 93 C142 68 188 73 220 94 L244 109 L276 110 L254 123 Q260 145 242 153 Q221 159 202 139 C168 117 136 126 113 154 Q91 176 70 168 Z",
        "base": "#fff1e4", "shade": "#e3ccc2", "light": "#ffffff",
        "segments": ["M99 102 Q114 124 109 158", "M125 83 Q140 104 139 131", "M156 77 Q168 100 165 127", "M188 82 Q198 109 192 133"],
        "tail": "round", "eyes": (230, 99, 250, 106),
    },
    "blue-dream-shrimp": {
        "body": "M70 153 C76 116 94 83 130 76 C173 67 205 81 228 98 L245 112 L273 113 L255 124 Q260 148 238 155 Q220 159 204 139 C175 116 138 126 115 155 Q90 178 71 167 Z",
        "base": "#3979d1", "shade": "#285499", "light": "#88c9f2",
        "segments": ["M94 99 Q113 120 106 161", "M119 80 Q137 102 135 135", "M150 74 Q167 98 163 126", "M183 80 Q197 102 191 132"],
        "tail": "broad", "eyes": (231, 100, 251, 107),
    },
    "yellow-shrimp": {
        "body": "M77 157 Q78 117 105 88 C134 58 179 69 212 89 L242 109 L273 109 L255 124 Q259 147 238 154 Q219 157 202 138 C173 115 142 122 120 151 Q98 178 79 170 Z",
        "base": "#ffd75e", "shade": "#e6a435", "light": "#fff2a4",
        "segments": ["M96 103 Q115 122 112 158", "M122 76 Q139 98 140 129", "M151 70 Q170 93 168 126", "M181 78 Q199 103 192 131"],
        "tail": "round", "eyes": (230, 100, 251, 107),
    },
    "tiger-shrimp": {
        "body": "M61 154 Q79 112 113 91 C146 72 184 83 217 99 L242 110 L278 109 L256 124 Q262 145 244 153 Q222 159 204 139 C173 121 142 128 113 154 Q87 179 64 168 Z",
        "base": "#ffc580", "shade": "#d99055", "light": "#ffe3b5",
        "segments": ["M91 111 Q106 128 105 160", "M117 89 Q133 109 133 136", "M146 81 Q159 102 158 126", "M180 86 Q190 106 188 132"],
        "tail": "broad", "eyes": (232, 101, 252, 108),
    },
    "bamboo-shrimp": {
        "body": "M67 153 Q89 118 116 104 C141 88 158 84 181 88 Q219 69 244 101 L264 113 L251 126 Q258 147 238 155 Q213 160 195 140 C165 125 139 135 113 155 Q88 174 69 167 Z",
        "base": "#c68c61", "shade": "#90694e", "light": "#e5bb8e",
        "segments": ["M98 119 Q109 133 106 159", "M123 101 Q136 120 134 140", "M148 91 Q159 111 156 130", "M174 90 Q180 112 177 131"],
        "tail": "narrow", "eyes": (231, 96, 249, 105),
    },
    "cleaner-shrimp": {
        "body": "M54 149 Q88 122 119 112 C154 102 191 103 221 114 L246 118 L280 119 L255 131 Q258 146 242 151 Q219 155 202 139 Q163 129 128 143 Q88 164 57 162 Z",
        "base": "#ee5f52", "shade": "#c44342", "light": "#ffb59a",
        "segments": ["M91 126 Q102 137 103 156", "M119 113 Q130 126 130 141", "M148 107 Q157 119 155 134", "M177 106 Q185 119 182 135"],
        "tail": "long", "eyes": (231, 108, 253, 114),
    },
    "peppermint-shrimp": {
        "body": "M64 153 Q83 111 117 92 C150 75 188 87 220 103 L246 114 L277 113 L257 128 Q259 147 242 153 Q223 157 202 140 C173 119 141 128 113 154 Q87 178 66 166 Z",
        "base": "#ffd4cf", "shade": "#e5a49c", "light": "#fff0e5",
        "segments": ["M95 110 Q111 128 106 160", "M122 91 Q136 111 133 136", "M151 85 Q163 104 159 128", "M182 90 Q193 112 188 132"],
        "tail": "narrow", "eyes": (233, 103, 253, 110),
    },
    "harlequin-shrimp": {
        "body": "M80 149 Q92 120 116 99 C143 77 174 82 196 96 Q219 86 240 105 L258 117 L274 118 L255 128 Q259 148 239 155 Q216 158 199 140 Q171 123 145 136 Q116 157 86 163 Z",
        "base": "#f3ecd8", "shade": "#d8c4bf", "light": "#fffdf1",
        "segments": ["M112 103 Q125 122 124 147", "M139 87 Q150 107 150 133", "M166 86 Q180 105 177 131"],
        "tail": "petal", "eyes": (229, 99, 250, 107),
    },
}


def tail(style: str, base: str, light: str) -> str:
    if style == "round":
        shapes = ["M81 151 Q57 130 41 134 Q34 151 62 165Z", "M78 157 Q54 157 42 175 Q47 191 73 174Z", "M80 161 Q68 180 77 194 Q97 191 92 165Z"]
    elif style == "broad":
        shapes = ["M78 149 Q53 121 31 132 Q33 155 63 165Z", "M73 157 Q40 153 31 174 Q44 197 73 176Z", "M80 162 Q64 182 74 199 Q102 193 94 166Z"]
    elif style == "long":
        shapes = ["M64 148 Q42 128 24 139 Q26 156 53 159Z", "M60 155 Q35 152 21 169 Q37 184 61 167Z", "M65 160 Q52 179 61 190 Q81 185 77 162Z"]
    elif style == "petal":
        shapes = ["M89 150 Q58 122 46 139 Q40 156 74 165Z", "M86 158 Q56 151 44 172 Q46 195 77 177Z", "M90 160 Q73 183 87 196 Q111 187 103 162Z"]
    else:
        shapes = ["M78 149 L41 134 Q34 143 67 161Z", "M77 158 L35 168 Q43 184 77 171Z", "M80 162 L72 193 Q91 193 91 164Z"]
    p = [path(d, base) for d in shapes]
    p.append(path("M69 158 L48 146 M68 167 L48 175 M82 170 L81 185", stroke=light, width=3))
    return "".join(p)


def walking_legs(item_id: str, profile: dict) -> str:
    base = profile["shade"]
    if item_id == "cleaner-shrimp":
        paths = ["M155 132 L146 160 L164 183", "M177 134 L174 164 L197 188", "M199 137 L213 165 L240 186", "M222 141 L245 161 L279 171"]
    elif item_id == "harlequin-shrimp":
        paths = ["M143 132 L131 157 L148 181", "M163 131 L160 160 L179 185", "M186 137 L190 162 L211 181"]
    else:
        paths = ["M137 130 L124 153 L140 174", "M159 128 L153 156 L174 180", "M181 131 L185 157 L206 178", "M206 141 L220 162 L246 177"]
    p = []
    for d in paths:
        p.extend([path(d, stroke=INK, width=7), path(d, stroke=base, width=3.4)])
    return "".join(p)


def antennae(item_id: str, light: str) -> str:
    if item_id == "cleaner-shrimp":
        paths = ["M239 117 Q241 60 289 27", "M249 121 Q280 80 302 85", "M234 112 Q211 52 256 26", "M252 124 Q285 111 304 127"]
        color = CREAM
    elif item_id == "harlequin-shrimp":
        paths = ["M244 117 Q259 74 291 65", "M249 121 Q279 100 300 111"]
        color = "#9cbddd"
    elif item_id == "peppermint-shrimp":
        paths = ["M244 116 Q264 65 299 59", "M252 124 Q285 97 302 105", "M239 113 Q229 78 251 60"]
        color = "#f5757d"
    elif item_id == "bamboo-shrimp":
        paths = ["M240 112 Q255 76 284 69", "M248 120 Q277 95 295 103"]
        color = light
    else:
        paths = ["M243 115 Q258 75 293 64", "M253 123 Q280 96 301 104"]
        color = light
    return "".join(path(d, stroke=INK, width=6.6) + path(d, stroke=color, width=2.6) for d in paths)


def spots(points: list[tuple], color: str, border: str = "none") -> str:
    return "".join(ellipse(x, y, rx, ry, color, border, 2) for x, y, rx, ry in points)


def markings(item_id: str, p: dict) -> str:
    # The clip keeps bold cartoon markings on the shell rather than the limbs.
    shapes = [path("M65 157 Q101 139 124 122 Q162 105 203 126 Q231 144 260 133 L273 176 L56 189Z", p["shade"], "none")]
    if item_id == "cherry-shrimp":
        shapes += [path("M106 103 Q132 80 169 91 Q186 94 190 102 Q166 94 137 101 Q117 108 106 103Z", p["light"], "none"), spots([(122, 111, 4, 3), (140, 105, 3, 2), (161, 107, 3, 2), (215, 120, 6, 4)], "#ffaaa0")]
    elif item_id == "amano-shrimp":
        shapes += [path("M91 128 Q152 89 215 111", stroke=p["light"], width=7), spots([(104, 135, 4, 3), (123, 122, 4, 3), (142, 114, 4, 3), (161, 112, 4, 3), (180, 114, 4, 3), (199, 122, 4, 3), (218, 131, 4, 3)], "#516951"), spots([(117, 133, 2, 2), (137, 125, 2, 2), (157, 122, 2, 2), (177, 124, 2, 2)], "#eef2ce")]
    elif item_id == "crystal-red-shrimp":
        shapes += [path("M75 111 L102 89 L117 133 L106 174 L80 179Z", "#ef5565", "none"), path("M135 71 L164 70 Q184 102 177 133 L148 135 Q151 101 135 71Z", "#ef5565", "none"), path("M205 82 L251 102 L270 153 L239 166 L215 147 Q223 117 205 82Z", "#ef5565", "none"), path("M142 85 L157 83 M214 103 L224 107", stroke="#ffb0a2", width=5)]
    elif item_id == "blue-dream-shrimp":
        shapes += [path("M97 106 Q114 77 145 83 Q169 85 178 92 Q141 86 120 102 L108 117Z", p["light"], "none"), path("M198 102 Q220 110 228 131 L215 134 Q208 117 196 112Z", "#58a3e3", "none"), spots([(118, 126, 3, 2.5), (140, 113, 3, 2.5), (165, 112, 3, 2.5)], "#b1e3f7")]
    elif item_id == "yellow-shrimp":
        shapes += [path("M97 111 Q120 67 155 78 Q183 84 207 103", stroke=p["light"], width=8), path("M83 146 L96 133 L99 150 L89 160Z", "#fff2a4", "none"), spots([(215, 124, 7, 4), (132, 109, 4, 3), (158, 100, 4, 3)], "#ffeeb0")]
    elif item_id == "tiger-shrimp":
        shapes += [path("M76 116 L88 109 Q106 127 102 153 L92 163 Q94 134 76 116Z", INK, "none"), path("M104 87 L117 82 Q139 105 135 133 L124 137 Q126 111 104 87Z", INK, "none"), path("M140 72 L151 76 Q170 100 163 128 L153 127 Q159 98 140 72Z", INK, "none"), path("M176 80 L187 86 Q203 110 195 135 L185 132 Q190 109 176 80Z", INK, "none"), path("M211 94 L223 101 L238 142 L228 148 Q221 119 211 94Z", INK, "none"), path("M108 101 Q142 77 180 96", stroke=p["light"], width=4)]
    elif item_id == "bamboo-shrimp":
        shapes += [path("M88 136 Q136 95 185 103 Q209 90 235 112", stroke="#f5dab1", width=8), path("M88 149 Q130 116 176 117 Q208 109 233 127", stroke="#75583f", width=5), path("M191 93 Q197 109 193 136", stroke="#75583f", width=4), spots([(207, 109, 3, 3), (220, 117, 3, 3), (230, 132, 3, 3)], "#f5dab1")]
    elif item_id == "cleaner-shrimp":
        shapes += [path("M49 147 Q138 104 209 119 Q230 127 254 126", stroke=CREAM, width=10), path("M57 154 Q141 117 204 132", stroke="#ffd3af", width=3), spots([(123, 125, 2.2, 2.2), (151, 121, 2.2, 2.2), (180, 122, 2.2, 2.2)], "#ffc5a8")]
    elif item_id == "peppermint-shrimp":
        shapes += [path("M68 145 Q132 81 192 105 L236 128", stroke="#ec5969", width=7), path("M79 156 Q138 109 185 120 Q214 134 236 143", stroke="#ec5969", width=6), path("M92 118 L111 144 M121 91 L139 126 M152 84 L166 119 M185 94 L192 130 M215 105 L220 140", stroke="#e77980", width=4), path("M103 110 Q140 86 168 97", stroke=CREAM, width=4)]
    else:
        shapes += [spots([(102, 136, 10, 8), (133, 111, 13, 11), (163, 103, 11, 8), (183, 123, 10, 8), (219, 119, 13, 10), (239, 144, 9, 7)], "#dd86a0", "#a5618d"), spots([(133, 111, 7, 6), (219, 119, 7, 6), (102, 136, 5, 4)], "#7ca4c8"), path("M117 99 Q138 83 155 90", stroke=CREAM, width=5)]
    return '<g clip-path="url(#shell)">' + "".join(shapes) + '</g>'


def fan_hand(x: int, y: int, mirrored: bool = False) -> str:
    transform = f'translate({x} {y})' + (" scale(-1 1)" if mirrored else "")
    return (f'<g transform="{transform}">'
            + path("M0 0 C-17 -9 -24 -25 -19 -36 Q-9 -42 -1 -29 Q6 -43 15 -36 Q24 -28 14 -9 Z", "#e9bf85")
            + path("M0 -3 L-13 -29 M1 -6 L0 -31 M5 -5 L13 -29", stroke="#a47653", width=2.7)
            + '</g>')


def claws(item_id: str, p: dict) -> str:
    if item_id == "bamboo-shrimp":
        return (path("M216 148 Q224 179 250 180 L263 161", stroke=INK, width=8)
                + path("M216 148 Q224 179 250 180 L263 161", stroke=p["base"], width=4)
                + fan_hand(264, 162)
                + path("M201 143 Q195 166 212 174 L222 161", stroke=INK, width=7)
                + path("M201 143 Q195 166 212 174 L222 161", stroke=p["base"], width=3.5)
                + fan_hand(222, 157, True))
    if item_id == "harlequin-shrimp":
        return (path("M212 148 Q231 167 256 166", stroke=INK, width=9)
                + path("M212 148 Q231 167 256 166", stroke="#ead7c7", width=4)
                + path("M250 170 Q246 149 261 140 Q272 136 277 151 Q294 143 298 155 Q302 174 283 184 Q267 189 250 170Z", "#f3e7d3")
                + spots([(270, 167, 11, 8), (282, 157, 6, 5)], "#dd86a0", "#a5618d")
                + spots([(270, 167, 6, 4)], "#78a9c9")
                + path("M197 144 Q205 176 227 181", stroke=INK, width=8)
                + path("M197 144 Q205 176 227 181", stroke="#ead7c7", width=3.5)
                + path("M221 183 Q215 166 228 157 Q240 152 245 168 Q258 165 261 178 Q258 193 241 197 Q228 196 221 183Z", "#f3e7d3")
                + spots([(239, 181, 9, 7)], "#dd86a0", "#a5618d")
                + spots([(239, 181, 4, 4)], "#78a9c9"))
    if item_id == "cleaner-shrimp":
        return (path("M238 146 L256 156 L279 151", stroke=INK, width=6.5)
                + path("M238 146 L256 156 L279 151", stroke=CREAM, width=2.6)
                + path("M277 150 L288 143 M278 152 L289 155", stroke=CREAM, width=3.8))
    return (path("M230 147 Q244 167 262 158 L270 151", stroke=INK, width=6)
            + path("M230 147 Q244 167 262 158 L270 151", stroke=p["light"], width=2.6)
            + path("M264 155 L272 156 L276 149", stroke=p["light"], width=3))


def artwork(item_id: str) -> str:
    p = PROFILES[item_id]
    x1, y1, x2, y2 = p["eyes"]
    parts = [f'<defs><clipPath id="shell"><path d="{p["body"]}"/></clipPath></defs>',
             walking_legs(item_id, p), antennae(item_id, p["light"]),
             tail(p["tail"], p["base"], p["light"]), path(p["body"], p["base"]),
             markings(item_id, p)]
    for segment in p["segments"]:
        parts.append(path(segment, stroke=INK, width=2.4, extra='opacity="0.7"'))
    parts.append(path(p["body"], "none", width=3.8))
    # A simple expressive mouth and cheeks keep the result clearly illustrative.
    parts += [eye(x1, y1, 9), eye(x2, y2, 10.5),
              path(f"M{x2 - 8} {y2 + 28} Q{x2 - 2} {y2 + 34} {x2 + 4} {y2 + 27}", width=2.6),
              ellipse(x2 - 14, y2 + 21, 5, 3, p["light"]), claws(item_id, p)]
    if item_id == "harlequin-shrimp":
        # Leaf-shaped sensory appendages are a distinctive harlequin silhouette.
        parts += [path("M217 100 Q196 87 199 73 Q212 68 220 91Z", "#f3e7d3"),
                  ellipse(208, 82, 4, 5, "#dd86a0")]
    return "".join(parts)


def generate(entries, output: Path) -> list[Path]:
    """Generate transparent 320 x 240 SVGs for the requested shrimp entries."""
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    paths = []
    for entry in entries:
        item_id = entry["id"]
        if item_id not in PROFILES:
            raise ValueError(f"Unknown cartoon shrimp identity: {item_id}")
        title = escape(entry.get("name", NAMES[item_id]))
        svg = ('<?xml version="1.0" encoding="UTF-8"?>\n'
               '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240" '
               'viewBox="0 0 320 240" role="img">\n'
               f'<title>{title}</title>\n'
               '<desc>Original cartoon shrimp vector illustration with a transparent background; '
               'tail left and head right.</desc>\n'
               + artwork(item_id) + '\n</svg>\n')
        ET.fromstring(svg)
        destination = output / f"{item_id}.svg"
        with destination.open("w", encoding="utf-8", newline="\n") as stream:
            stream.write(svg)
        paths.append(destination)
    return paths


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=Path(__file__).resolve().parents[2]
                        / "src/DeskBox/Assets/Aquarium/Svg")
    args = parser.parse_args()
    generated = generate([{"id": item_id} for item_id in NAMES], args.output)
    print(f"Generated and XML-validated {len(generated)} cartoon shrimp SVG sources.")
