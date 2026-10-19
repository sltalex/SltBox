"""Generate ten original, transparent cartoon shellfish SVG illustrations.

The shell geometry is individually authored for each species. The output uses
only paths, ellipses and groups so it stays portable through SVG rasterizers.
"""

from __future__ import annotations

import argparse
from html import escape
from pathlib import Path
import xml.etree.ElementTree as ET


INK = "#194957"
SPECIES = {
    "scallop": "扇贝",
    "oyster": "牡蛎",
    "giant-clam": "砗磲",
    "blue-mussel": "蓝贻贝",
    "pearl-oyster": "珍珠贝",
    "cockle": "鸟蛤",
    "razor-clam": "蛏子",
    "abalone": "鲍鱼",
    "conch": "海螺",
    "nautilus": "鹦鹉螺",
}


def path(d, fill="none", stroke=INK, width=4.5, opacity=1):
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" '
            f'stroke-width="{width}" stroke-linecap="round" '
            f'stroke-linejoin="round" opacity="{opacity}"/>')


def ellipse(x, y, rx, ry, fill, stroke="none", width=4.5, opacity=1):
    return (f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" '
            f'fill="{fill}" stroke="{stroke}" stroke-width="{width}" '
            f'opacity="{opacity}"/>')


def face(x, y, spread=17, cheek="#ef8694", smile=True):
    parts = []
    for side in (-1, 1):
        px = x + side * spread
        parts += [ellipse(px, y, 5.2, 7, INK),
                  ellipse(px - 1.3, y - 2.5, 1.7, 2.2, "#fffdf4"),
                  ellipse(px + side * 8, y + 11, 8, 4, cheek, opacity=.7)]
    if smile:
        parts.append(path(f"M{x-6} {y+11} Q{x} {y+19} {x+6} {y+11}", width=3))
    return "\n".join(parts)


def scallop():
    p = [
        # Scalloped fan rim and a distinct, short hinge at the foot.
        path("M124 188 L107 210 Q134 216 160 207 Q188 215 214 209 L195 188 Z", "#de8298"),
        path("M145 199 Q107 186 68 153 Q48 139 58 123 Q36 104 54 89 Q43 65 69 60 Q66 36 95 39 Q102 19 126 33 Q142 14 160 29 Q183 14 198 33 Q226 24 232 47 Q261 43 262 67 Q287 74 275 97 Q291 115 271 131 Q279 150 256 164 Q220 189 176 199 Q161 207 145 199 Z", "#f69eae"),
        path("M57 124 Q85 151 135 169 Q159 185 178 177 Q230 164 275 116 Q286 133 256 164 Q220 189 176 199 Q160 208 145 199 Q107 186 68 153 Q50 140 57 124 Z", "#df7793", "none"),
        path("M160 187 Q150 111 160 41", stroke="#ffd1c8", width=12),
        path("M144 184 Q123 115 119 49 M128 180 Q88 117 80 74 M114 174 Q82 151 65 119 M176 183 Q196 111 201 51 M193 181 Q230 120 244 82 M207 174 Q246 147 263 121", stroke="#f4b8b8", width=7),
        path("M150 192 Q127 104 126 40 M170 192 Q194 103 198 39 M131 185 Q96 126 72 69 M190 187 Q226 124 254 73", stroke="#ce6b87", width=3.2),
        path("M80 58 Q91 49 99 51 M145 38 Q155 32 167 35 M224 62 Q238 59 245 70", stroke="#fff0db", width=6),
        face(160, 150, 18, "#d35e83"),
    ]
    return p


def oyster():
    p = [
        path("M70 176 Q45 168 53 148 Q35 132 49 115 Q39 91 64 82 Q61 57 87 55 Q103 31 125 44 Q146 23 166 40 Q192 29 207 48 Q232 42 240 63 Q267 65 266 88 Q290 103 276 124 Q288 148 267 163 Q265 190 237 189 Q220 212 193 201 Q173 218 149 204 Q127 218 106 201 Q81 208 70 176 Z", "#5cabb0"),
        path("M72 164 Q76 185 110 186 Q136 207 164 192 Q189 209 211 190 Q246 192 251 164 Q277 146 268 120 Q242 174 192 177 Q132 190 72 164 Z", "#34818f", "none"),
        path("M74 142 Q57 123 73 104 Q64 85 91 81 Q92 59 118 67 Q139 47 158 59 Q182 45 199 65 Q226 62 229 83 Q255 89 247 111 Q266 134 247 151 Q249 171 223 175 Q208 193 183 181 Q160 194 138 181 Q112 191 99 173 Q77 178 74 142 Z", "#b5e2da"),
        path("M94 141 Q81 125 94 110 Q88 95 110 91 Q118 74 137 82 Q157 66 176 80 Q201 74 208 95 Q229 101 225 119 Q242 137 223 151 Q208 168 183 162 Q162 177 142 163 Q112 174 94 141 Z", "#fae5cc", "#558d93", 3),
        path("M99 140 Q128 160 165 153 Q201 157 223 131 Q235 144 215 154 Q203 168 182 162 Q161 177 142 163 Q111 174 99 140 Z", "#edb6a6", "none"),
        path("M66 103 Q67 94 77 94 M88 65 Q100 59 110 66 M179 45 L191 52 M252 90 Q261 98 254 106 M247 177 L236 182 M73 153 L78 163", stroke="#d0efe2", width=5),
        face(159, 122, 20, "#f19b96"),
    ]
    return p


def giant_clam():
    p = [
        # A broad wavy mantle is deliberately different from the pearl shell.
        path("M56 139 Q34 117 52 99 Q43 73 69 68 Q73 43 100 52 Q111 26 136 44 Q159 22 179 44 Q206 28 220 52 Q248 43 254 69 Q282 75 271 99 Q291 120 266 141 L228 178 L92 177 Z", "#958bd4"),
        path("M60 130 Q66 111 83 119 Q87 97 107 107 Q117 87 135 102 Q157 79 176 103 Q195 84 213 106 Q233 96 241 119 Q260 109 266 133 L236 166 L80 167 Z", "#6053a5", INK, 4),
        path("M67 129 Q78 135 88 123 Q101 140 116 120 Q135 143 154 122 Q175 145 192 121 Q210 142 227 124 Q239 140 257 129", stroke="#d9c1f6", width=7),
        path("M45 148 Q55 133 75 145 Q87 129 105 144 Q123 128 141 143 Q159 128 178 143 Q196 127 215 143 Q234 128 250 145 Q271 133 280 150 Q264 193 221 208 Q161 228 102 207 Q62 191 45 148 Z", "#79d5c6"),
        path("M54 164 Q109 200 165 197 Q224 200 272 165 Q256 200 216 211 Q161 227 105 208 Q72 197 54 164 Z", "#42a99e", "none"),
        path("M85 153 Q95 185 120 204 M121 153 Q127 184 141 211 M162 152 L163 214 M202 153 Q197 187 183 211 M239 154 Q227 188 206 205", stroke="#d1f4d9", width=6),
        path("M83 76 Q89 66 100 69 M128 57 Q136 52 144 56 M190 55 Q200 52 207 61 M234 77 L240 86", stroke="#d7c6f4", width=6),
        face(161, 161, 20, "#f1a3aa"),
    ]
    return p


def blue_mussel():
    p = [
        # A pair of tapered valves, set on opposing diagonals.
        path("M110 193 C63 176 39 128 54 88 Q66 55 87 66 C108 88 147 137 159 173 Q166 202 141 211 Q127 214 110 193 Z", "#638dc3"),
        path("M88 78 Q66 88 75 122 Q87 160 121 181", stroke="#aedee5", width=8),
        path("M111 190 Q91 146 94 129", stroke="#3b639a", width=4),
        path("M128 202 C95 181 113 148 131 112 C156 68 192 31 220 31 C253 30 274 61 267 99 C259 139 230 180 192 204 Q150 227 128 202 Z", "#3968a3"),
        path("M131 194 C117 176 135 143 151 116 C176 77 201 51 221 48 Q249 47 249 81 C244 124 219 163 185 191 Q151 212 131 194 Z", "#6ca2cf", "none"),
        path("M132 194 Q173 203 213 159 Q249 121 256 64 Q277 103 243 151 Q220 186 189 204 Q151 224 132 204 Z", "#31588d", "none"),
        path("M144 170 Q160 122 190 88 Q208 66 225 63", stroke="#bde4e7", width=10),
        path("M150 192 Q185 175 210 143 M160 199 Q197 182 225 147", stroke="#82adce", width=3.5),
        path("M238 76 Q244 92 236 109", stroke="#8ec7de", width=5),
        face(181, 157, 17, "#d495b5"),
    ]
    return p


def pearl_oyster():
    p = [
        path("M79 148 Q48 132 56 108 Q42 86 64 71 Q63 45 90 45 Q107 23 130 37 Q158 16 181 36 Q208 23 226 46 Q253 45 255 72 Q278 88 265 110 Q269 134 239 150 Z", "#e89ebc"),
        path("M82 135 Q69 124 77 108 Q64 91 83 82 Q80 63 103 64 Q117 47 135 57 Q159 40 180 57 Q203 47 215 65 Q236 63 235 84 Q255 96 241 111 Q250 129 230 137 Z", "#fff0de", "#ba719f", 3.5),
        path("M92 127 Q111 85 135 66 M124 136 Q136 88 150 64 M168 139 Q172 90 170 64 M201 136 Q209 92 193 68 M226 128 Q233 104 219 86", stroke="#f5c4d0", width=5),
        path("M46 157 Q46 142 66 140 Q161 126 254 141 Q275 143 275 157 Q265 187 230 203 Q160 226 92 204 Q59 188 46 157 Z", "#eab1cb"),
        ellipse(160, 151, 109, 29, "#fce6d3", INK, 4),
        path("M75 179 Q153 207 244 177 Q219 203 161 211 Q108 209 75 179 Z", "#c27eac", "none"),
        path("M96 184 L108 199 M128 191 L133 206 M162 194 L163 208 M194 190 L191 204 M224 183 L216 196", stroke="#f9cddb", width=5),
        ellipse(160, 130, 43, 43, "#f3edfa", INK, 4),
        path("M130 158 Q168 172 194 143 Q190 170 162 173 Q142 173 130 158 Z", "#bfbbeb", "none"),
        ellipse(143, 108, 12, 8, "#ffffff"),
        ellipse(160, 99, 4, 3, "#ffffff"),
        face(161, 131, 14, "#efa7ba"),
        path("M238 46 L241 35 M248 53 L259 50 M88 105 L82 96", stroke="#fff3df", width=4),
    ]
    return p


def cockle():
    p = [
        # Rounded heart silhouette and closely spaced radiating ribs.
        path("M159 205 C137 199 75 169 60 126 C42 82 72 39 111 39 Q143 38 159 64 Q179 37 208 40 C253 43 279 86 257 130 Q231 182 159 205 Z", "#f7bd62"),
        path("M66 131 Q105 166 160 172 Q212 168 255 132 Q228 182 159 205 Q91 178 66 131 Z", "#e18b49", "none"),
        path("M157 190 Q113 144 100 63 M147 187 Q88 140 79 81 M167 190 Q206 141 220 66 M179 185 Q237 132 241 90 M157 188 Q143 122 151 82 M164 187 Q179 122 169 82", stroke="#ffdc88", width=7),
        path("M159 195 Q119 136 121 55 M159 195 Q195 133 197 55 M134 185 Q92 156 66 113 M183 183 Q227 154 258 112", stroke="#c77a42", width=3.2),
        path("M83 68 Q93 54 109 55 M208 57 Q224 61 232 72", stroke="#fff0be", width=7),
        face(160, 139, 20, "#ed9473"),
    ]
    return p


def razor_clam():
    p = [
        # Two straight narrow shell valves; the exposed mantle has the face.
        '<g transform="rotate(-13 130 120)">',
        path("M83 57 Q83 38 103 38 L122 38 Q140 39 141 57 L141 180 Q141 202 120 204 L102 204 Q82 203 82 183 Z", "#d4a257"),
        path("M93 58 Q92 48 104 48 L115 48 Q126 48 126 59 L126 180 Q126 190 114 192 L104 192 Q94 190 94 180 Z", "#ffe3a0", "none"),
        path("M91 76 L130 76 M91 109 L130 109 M91 143 L130 143 M91 177 L130 177", stroke="#c18b45", width=3),
        path("M99 58 L99 170", stroke="#fff1c7", width=5),
        '</g>',
        '<g transform="rotate(9 190 120)">',
        path("M164 198 Q155 208 168 215 Q184 222 194 213 Q209 219 220 210 Q228 201 216 195 Z", "#8fcdb1"),
        path("M163 59 Q161 42 176 37 Q184 33 193 38 Q201 29 212 38 Q225 43 222 62 L218 192 L163 191 Z", "#c9efd4"),
        path("M153 66 Q153 48 169 48 Q174 48 177 57 L177 188 Q174 205 163 205 Q151 203 151 187 Z", "#e2b565"),
        path("M177 61 Q177 51 186 51 L211 51 Q228 51 229 67 L229 187 Q229 205 212 206 L194 206 Q178 205 177 189 Z", "#f5ce7f"),
        path("M211 56 Q222 56 222 70 L222 187 Q222 199 211 199 L198 199 Q215 184 211 56 Z", "#d5a354", "none"),
        path("M183 80 L222 80 M183 115 L222 115 M183 151 L222 151 M183 183 L222 183", stroke="#d4a15a", width=3),
        path("M187 65 L187 105 M187 155 L187 173", stroke="#fff2c6", width=6),
        face(199, 133, 11, "#e99c7b"),
        '</g>',
    ]
    return p


def abalone():
    p = [
        # Ear-shaped turquoise shell, recognisable by its row of breathing holes.
        path("M54 161 C36 129 58 85 97 58 C133 32 190 25 231 53 C273 81 286 132 255 167 C220 205 158 218 105 196 Q73 185 54 161 Z", "#70beac"),
        path("M60 147 Q89 188 145 192 Q212 199 262 144 Q251 177 211 195 Q149 220 102 195 Q74 186 60 147 Z", "#439484", "none"),
        path("M76 151 C61 126 80 91 114 70 C146 50 189 48 219 69 C253 94 261 126 237 152 C210 182 161 192 120 177 Q91 168 76 151 Z", "#d0e6ce", INK, 3.5),
        path("M85 142 C78 120 98 94 127 79 Q166 58 201 78 Q234 100 230 125 Q225 159 182 169 Q117 184 85 142 Z", "#9edaca", "none"),
        path("M105 142 Q100 114 128 96 Q158 76 189 89 Q216 102 210 127 Q202 149 173 151 Q148 153 137 137 Q126 120 146 109 Q162 99 177 110 Q189 122 173 131", stroke="#9a9ace", width=7),
        path("M106 159 Q140 177 180 167 Q215 161 230 140", stroke="#f3d4df", width=6),
        path("M111 71 Q153 47 191 59", stroke="#e8f8d8", width=6),
    ]
    for x, y, rx, ry in [(75, 117, 7, 9), (86, 94, 7, 8), (104, 76, 7, 7), (127, 63, 6, 6), (151, 56, 5, 5)]:
        p += [ellipse(x, y, rx + 2.5, ry + 2.5, "#b6ead1"), ellipse(x, y, rx, ry, "#285a68", INK, 2)]
    p += [face(183, 145, 16, "#e5a1b1")]
    return p


def conch():
    p = [
        # Pointed spiral spire, flared shell lip and a cheerful exposed foot.
        path("M222 91 Q243 63 254 27 Q266 61 250 94 Q267 110 253 138 Q270 159 240 180 L123 188 Q85 173 87 139 Q88 100 128 83 Q172 60 222 91 Z", "#f7c995"),
        path("M196 78 Q205 57 226 46 Q229 70 219 91", "#f1ad87"),
        path("M224 96 Q243 99 257 112 M231 121 Q248 125 256 138 M230 148 Q246 152 250 165", stroke="#d78977", width=5),
        path("M224 104 Q216 78 186 70 Q172 56 163 45 L153 72 Q133 72 120 84 L97 78 L102 102 Q90 119 87 141 L69 154 L92 166 Q99 184 123 188 L227 184 Q263 151 224 104 Z", "#f2ae8c"),
        path("M103 154 Q122 180 162 175 Q199 167 224 144 Q246 160 227 184 L123 188 Q101 184 93 166 Z", "#d48278", "none"),
        path("M121 147 C98 125 126 89 160 91 C194 92 204 123 187 141 C174 157 146 151 143 133 Q141 118 154 115 Q169 112 173 125", stroke="#bc746a", width=8),
        path("M116 109 Q126 93 146 90 M191 90 L207 104", stroke="#ffe7bc", width=7),
        path("M226 113 Q248 143 228 179 Q213 191 194 182 Q181 171 183 154 Q188 127 210 113 Q220 108 226 113 Z", "#ffe3bc"),
        path("M220 127 Q234 145 220 169 Q212 181 202 173 Q195 160 204 143 Z", "#a65e71", INK, 3),
        path("M84 176 Q107 167 128 177 Q147 188 174 181 Q185 171 201 177 Q220 189 208 203 Q166 216 121 211 Q82 211 64 197 Q59 182 84 176 Z", "#87d4be"),
        path("M81 185 L74 155 M103 181 L109 151", stroke="#70bba8", width=11),
        ellipse(72, 151, 11, 13, "#c0eed4", INK, 3),
        ellipse(110, 148, 11, 13, "#c0eed4", INK, 3),
        ellipse(72, 152, 4, 6, INK), ellipse(110, 149, 4, 6, INK),
        ellipse(71, 150, 1.6, 2, "#fffdf3"), ellipse(109, 147, 1.6, 2, "#fffdf3"),
        path("M86 185 Q94 193 102 183", width=3),
        ellipse(75, 185, 7, 4, "#ecb0ac"),
        path("M128 196 Q163 204 190 197", stroke="#c7efcf", width=5),
    ]
    return p


def nautilus():
    p = [
        # A chambered round shell, alternating curved bands and curled arms.
        path("M95 156 Q80 151 67 164 Q50 173 40 160 Q33 148 42 142 M94 171 Q73 181 59 176 Q39 172 35 186 Q33 198 47 201 M104 182 Q92 204 73 199 Q59 195 57 211 M116 187 Q113 215 95 213", stroke="#d27e64", width=12),
        path("M95 156 Q80 151 67 164 Q50 173 40 160 Q33 148 42 142 M94 171 Q73 181 59 176 Q39 172 35 186 Q33 198 47 201 M104 182 Q92 204 73 199 Q59 195 57 211 M116 187 Q113 215 95 213", stroke="#f5b284", width=7),
        path("M106 157 Q93 141 77 142 Q55 145 60 161 Q59 181 88 189 Q119 198 144 181 L139 150 Z", "#f7bc91"),
        path("M124 188 C85 165 81 108 104 69 C128 25 185 17 228 42 C275 70 290 128 263 168 C235 210 168 217 124 188 Z", "#fff0ce"),
        path("M109 159 Q149 197 206 180 Q252 161 271 116 Q284 154 252 183 Q194 226 135 197 Q119 188 109 171 Z", "#e4bd92", "none"),
        path("M125 47 Q133 69 155 79 L165 69 Q147 49 150 34 Z", "#d28062", "none"),
        path("M174 28 Q165 54 173 72 L186 75 Q186 50 197 30 Z", "#d28062", "none"),
        path("M220 37 Q198 55 194 78 L206 87 Q221 63 240 52 Z", "#d28062", "none"),
        path("M258 75 Q230 82 211 100 L216 113 Q245 101 270 102 Z", "#d28062", "none"),
        path("M276 129 Q249 119 220 125 L214 139 Q244 143 267 157 Z", "#d28062", "none"),
        path("M250 181 Q237 155 206 146 L194 157 Q217 175 224 198 Z", "#d28062", "none"),
        path("M195 205 Q197 180 180 162 L164 164 Q169 184 165 204 Z", "#d28062", "none"),
        path("M137 195 Q153 180 152 160 L140 151 Q134 171 118 181 Z", "#d28062", "none"),
        path("M107 146 Q125 152 139 141 L139 128 Q121 134 98 119 Z", "#d28062", "none"),
        path("M99 96 Q119 115 141 112 L149 99 Q125 92 110 73 Z", "#d28062", "none"),
        path("M111 152 C92 109 127 64 170 68 C215 68 237 103 221 135 C206 164 168 170 146 150 C123 131 130 104 151 96 C174 87 198 100 197 118 C196 135 176 144 164 134 Q153 126 160 116 Q167 109 175 118", stroke="#9d6a5e", width=4.5),
        path("M125 55 Q143 39 162 39 M215 55 Q240 66 250 85", stroke="#fff9e9", width=6),
        ellipse(81, 156, 10, 12, "#fff5d9", INK, 3),
        ellipse(78, 157, 4.5, 6.5, INK),
        ellipse(77, 154, 1.6, 2, "#ffffff"),
        path("M70 176 Q77 182 84 176", width=3),
        ellipse(93, 172, 7, 4, "#e69583"),
    ]
    return p


DRAWINGS = {
    "scallop": scallop, "oyster": oyster, "giant-clam": giant_clam,
    "blue-mussel": blue_mussel, "pearl-oyster": pearl_oyster,
    "cockle": cockle, "razor-clam": razor_clam, "abalone": abalone,
    "conch": conch, "nautilus": nautilus,
}


def build(entry):
    ident = entry["id"]
    if ident not in DRAWINGS:
        raise ValueError(f"No authored cartoon shellfish geometry for {ident}")
    title = escape(entry.get("name", SPECIES[ident]))
    body = "\n".join(DRAWINGS[ident]())
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 240" '
            f'width="320" height="240">\n<title>{title}</title>\n'
            f'<desc>Original cartoon shellfish illustration: {ident}. '
            f'Rounded dark teal outlines, flat cel shading, transparent background.</desc>\n'
            f'{body}\n</svg>\n')


def generate(entries, output: Path):
    """Write exactly the requested SVGs, validating each XML document."""
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    paths = []
    for entry in entries:
        content = build(entry)
        ET.fromstring(content)
        target = output / f'{entry["id"]}.svg'
        with target.open("w", encoding="utf-8", newline="\n") as stream:
            stream.write(content)
        paths.append(target)
    return paths


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path,
                        default=root / "src/DeskBox/Assets/Aquarium/Svg")
    args = parser.parse_args()
    entries = [{"id": ident, "name": name} for ident, name in SPECIES.items()]
    paths = generate(entries, args.output_dir)
    print(f"Generated and XML-validated {len(paths)} cartoon shellfish SVGs.")
