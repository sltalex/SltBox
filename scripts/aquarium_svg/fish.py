"""Reproducible, self-contained natural-history fish illustrations.

Only SVG source files are produced.  The drawings are original vector artwork,
not photographs; the anatomy and markings distinguish the named fish.  The
shared build script rasterizes the sources for the existing aquarium renderer.
"""

from __future__ import annotations

import argparse
import json
import math
import random
from html import escape
from pathlib import Path
import xml.etree.ElementTree as ET


def path(d: str, fill: str, stroke: str = "none", width: float = 1,
         opacity: float = 1, extra: str = "") -> str:
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" '
            f'stroke-width="{width}" opacity="{opacity}" '
            f'stroke-linejoin="round" stroke-linecap="round" {extra}/>')


def ellipse(x, y, rx, ry, fill, opacity=1, extra=""):
    return (f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" '
            f'fill="{fill}" opacity="{opacity}" {extra}/>')


def mix(color, other, weight):
    a = [int(color[i:i + 2], 16) for i in (1, 3, 5)]
    b = [int(other[i:i + 2], 16) for i in (1, 3, 5)]
    return "#" + "".join(f"{round(x * (1 - weight) + y * weight):02x}"
                         for x, y in zip(a, b))


# Each profile has a distinct anatomical outline; reused families describe
# genuinely related body plans rather than a universal ellipse.
BODIES = {
    "reef": "M170 191 C208 150 255 122 345 131 C410 135 443 165 476 185 Q498 192 510 204 Q492 213 477 218 C440 253 398 272 334 271 C255 269 209 237 170 212 Z",
    "tang": "M180 186 C226 149 224 103 312 92 C402 77 447 140 467 182 L502 199 L487 217 Q465 220 455 236 C422 287 368 308 301 292 C230 275 212 225 180 216 Z",
    "butterfly": "M184 185 C228 150 225 101 315 91 C396 82 437 121 449 160 L514 197 L503 208 L466 204 C444 242 413 294 333 297 C260 294 223 249 184 218 Z",
    "yellowtang": "M180 187 C218 165 223 110 302 103 C379 90 434 118 453 165 L513 194 L508 205 L468 210 C444 258 404 287 335 288 C254 290 217 239 180 217 Z",
    "dwarfangel": "M170 184 Q220 114 315 111 C411 108 456 160 480 189 L500 201 L477 221 C439 268 381 283 311 278 Q227 265 170 217 Z",
    "emperor": "M155 185 Q216 99 323 93 C413 86 460 143 478 181 L513 198 L501 214 L480 216 C442 277 394 302 312 291 Q221 277 155 218 Z",
    "gramma": "M151 190 C221 159 273 154 359 158 C423 158 477 178 511 197 L519 207 Q492 215 480 226 C417 249 365 247 303 241 C226 236 190 221 151 211 Z",
    "mandarin": "M160 192 C229 169 271 142 348 147 C413 147 443 164 459 182 Q509 181 518 202 Q492 220 471 220 C433 239 373 251 311 242 C234 232 195 220 160 211 Z",
    "cardinal": "M164 188 C228 151 255 124 337 130 C411 126 458 163 486 187 L510 200 L496 217 C455 252 398 263 336 264 C260 262 208 232 164 214 Z",
    "sardine": "M139 194 C215 177 265 159 357 161 C431 159 484 181 529 199 L534 207 L514 215 C446 238 373 241 298 230 C225 220 185 213 139 208 Z",
    "anchovy": "M125 195 Q243 169 348 172 C432 171 489 187 539 197 L543 205 L507 217 C447 229 373 232 294 219 Q190 210 125 207 Z",
    "herring": "M140 191 C210 166 280 147 363 151 C447 151 492 179 529 199 L535 207 L518 216 C449 244 376 252 297 237 C227 228 186 217 140 211 Z",
    "mackerel": "M139 192 C226 159 272 138 365 148 C447 157 483 177 526 198 L535 207 L510 218 C430 249 362 245 291 232 Q198 216 139 209 Z",
    "tuna": "M133 187 C226 169 264 113 365 120 C441 126 490 173 537 195 L551 208 L528 222 C474 239 433 267 360 273 C266 274 232 231 133 215 Z",
    "pomfret": "M167 183 C221 145 222 94 321 78 C405 71 446 120 466 175 L497 199 L484 218 C461 249 420 295 337 302 C246 312 225 256 167 217 Z",
    "milkfish": "M133 190 C205 167 274 132 363 141 C443 143 485 173 526 193 L538 205 L511 220 C446 253 376 259 298 239 C221 225 180 216 133 213 Z",
    "trevally": "M143 185 C217 159 267 105 364 113 C435 119 472 150 491 176 L532 200 L525 214 C478 241 440 268 357 277 C268 277 229 234 143 216 Z",
    "barracuda": "M106 194 C236 174 332 165 421 175 Q483 177 530 184 L574 200 L572 208 L546 209 L557 217 L526 224 Q450 230 359 221 Q219 211 106 209 Z",
    "arowana": "M106 187 C201 157 303 148 414 165 C458 175 493 190 532 186 L556 182 L547 203 Q533 227 495 231 Q338 245 211 218 L106 211 Z",
    "goldfish": "M203 183 C244 140 277 117 354 127 Q447 130 478 183 L510 200 L503 213 L477 221 C437 266 399 285 331 276 C261 268 237 231 203 217 Z",
    "koi": "M146 188 C219 151 285 128 375 140 C448 150 479 179 510 194 L526 204 L515 217 C477 243 427 257 352 257 C265 251 217 229 146 216 Z",
    "guppy": "M224 194 C269 169 316 158 373 166 Q449 172 496 195 L514 201 L500 214 C450 236 403 244 354 233 Q279 220 224 209 Z",
    "tetra": "M156 191 C226 164 282 150 355 154 Q442 151 509 193 L523 202 L510 217 C447 240 382 249 309 236 Q222 222 156 212 Z",
    "danio": "M153 193 C218 170 286 159 368 168 C446 174 480 188 518 199 L526 206 L506 218 C442 235 383 240 314 227 Q228 219 153 210 Z",
    "betta": "M225 188 C280 155 326 150 381 158 C445 165 483 188 507 196 L521 201 L506 213 C452 235 398 249 337 239 C284 227 252 217 225 212 Z",
    "firefish": "M149 192 C223 176 277 164 350 166 Q437 169 512 194 L525 200 L515 214 Q449 238 379 239 C290 229 221 216 149 209 Z",
    "wrasse": "M151 187 C228 149 278 135 361 146 C435 155 475 178 506 190 L522 199 L514 214 C457 248 395 264 317 245 Q220 225 151 215 Z",
    "foxface": "M151 186 C218 144 276 113 358 124 C424 130 443 151 458 171 L526 195 L533 205 L517 214 L470 211 C430 254 386 280 314 267 Q224 251 151 216 Z",
    "naso": "M145 187 C214 154 272 123 354 129 C417 135 454 155 479 178 L518 185 L527 198 L520 212 L500 215 Q448 265 356 269 Q244 258 145 216 Z",
}


# Back, illuminated flank, belly, fin, body plan, caudal fin, dorsal fin.
PROFILES = {
    "clownfish": ("#703b20", "#ed8c29", "#f4bb72", "#d17a35", "reef", "round", "reef"),
    "blue-tang": ("#142968", "#246bd0", "#689fd1", "#2556a5", "tang", "yellowfork", "tang"),
    "butterflyfish": ("#857226", "#e4ce53", "#f3e7a6", "#d4b934", "butterfly", "round", "tang"),
    "yellow-tang": ("#a18615", "#f3d23c", "#f9e97a", "#d9bd38", "yellowtang", "fork", "sail"),
    "royal-gramma": ("#44285b", "#9a59bd", "#b593c9", "#9c6dbd", "gramma", "round", "low"),
    "mandarinfish": ("#183c3b", "#318f8b", "#a3bf99", "#277e84", "mandarin", "round", "mandarin"),
    "moorish-idol": ("#535a52", "#ede9cc", "#f9f3db", "#b0b6a2", "butterfly", "fork", "banner"),
    "cardinalfish": ("#626b4c", "#c8c397", "#efe5cf", "#b0a480", "cardinal", "fork", "cardinal"),
    "sardine": ("#314f60", "#b9d3d6", "#f2efde", "#7e9b9d", "sardine", "fork", "small"),
    "anchovy": ("#294750", "#b7cad0", "#e9e4d5", "#83989c", "anchovy", "fork", "small"),
    "herring": ("#2c5768", "#b2c8ce", "#ecece2", "#788e9b", "herring", "fork", "small"),
    "atlantic-mackerel": ("#205d6b", "#91bfc2", "#e9e8d8", "#607f85", "mackerel", "fork", "mackerel"),
    "yellowfin-tuna": ("#1d475b", "#7fa9ba", "#e0e8e5", "#c7b245", "tuna", "sickle", "tuna"),
    "silver-pomfret": ("#557080", "#d5dfdc", "#f3f0dc", "#819b9f", "pomfret", "fork", "pomfret"),
    "milkfish": ("#345661", "#a8c6ca", "#ebe9db", "#647d80", "milkfish", "deepfork", "small"),
    "giant-trevally": ("#2c4549", "#8ca4a4", "#d7d8cb", "#536b6a", "trevally", "deepfork", "trevally"),
    "barracuda": ("#425e61", "#afc6bb", "#e6e8d9", "#718d82", "barracuda", "fork", "barracuda"),
    "arowana": ("#667f79", "#c2d0bc", "#ede6c9", "#c0ba9a", "arowana", "round", "arowana"),
    "goldfish": ("#914021", "#e0a050", "#f2d29d", "#db7946", "goldfish", "veil", "goldfish"),
    "koi": ("#898b7d", "#deded0", "#f5eddb", "#c1c6b2", "koi", "fork", "low"),
    "guppy": ("#456b65", "#97bbac", "#e0d3bd", "#aa674c", "guppy", "fan", "guppy"),
    "neon-tetra": ("#374b60", "#9caeb7", "#d5d6cf", "#95a3a8", "tetra", "fork", "small"),
    "zebra-danio": ("#4e5c66", "#b8bda4", "#ece1c2", "#a5a78d", "danio", "fork", "small"),
    "siamese-fighting-fish": ("#163d54", "#38889a", "#8ec4b7", "#3e7e91", "betta", "betta", "betta"),
    "banggai-cardinalfish": ("#5a656a", "#d0d3c4", "#f1eed8", "#929e99", "cardinal", "lyre", "banggai"),
    "firefish": ("#9c978b", "#e9e2d1", "#f6e8d6", "#c95b4a", "firefish", "point", "firefish"),
    "sixline-wrasse": ("#4b3c59", "#987a99", "#cfb4b1", "#697a71", "wrasse", "round", "low"),
    "fairy-wrasse": ("#724549", "#bd8479", "#dfc19a", "#9b595e", "wrasse", "lyre", "reef"),
    "flame-angelfish": ("#a23f22", "#eb7e34", "#f5b35d", "#b96a37", "dwarfangel", "round", "tang"),
    "coral-beauty": ("#272a5d", "#d58c65", "#dcb692", "#454578", "dwarfangel", "round", "tang"),
    "emperor-angelfish": ("#284c6b", "#6aafb8", "#b8c9b2", "#5a88a6", "emperor", "round", "emperor"),
    "foxface-rabbitfish": ("#999332", "#e1ce49", "#f2e18c", "#c3bc62", "foxface", "fork", "spines"),
    "sailfin-tang": ("#615b36", "#b1a875", "#e2d4a6", "#9c9763", "yellowtang", "round", "sail"),
    "naso-tang": ("#454a4c", "#899595", "#c4cbbe", "#7a817c", "naso", "lyre", "low"),
    "purple-tang": ("#312c58", "#6c568e", "#a997b3", "#706393", "yellowtang", "yellowfork", "tang"),
}


def defs(back, flank, belly, fin, body):
    return f'''<defs>
<linearGradient id="body" x1="0" y1="0" x2="0" y2="1">
<stop offset="0" stop-color="{back}"/><stop offset=".27" stop-color="{flank}"/>
<stop offset=".47" stop-color="{mix(flank, '#ffffff', .22)}"/>
<stop offset=".64" stop-color="{flank}"/><stop offset=".88" stop-color="{belly}"/>
<stop offset="1" stop-color="{mix(belly, back, .25)}"/></linearGradient>
<linearGradient id="fin" x1="1" y1=".3" x2="0" y2=".8">
<stop stop-color="{mix(fin, back, .25)}"/><stop offset=".7" stop-color="{fin}" stop-opacity=".88"/>
<stop offset="1" stop-color="{mix(fin, '#e8ebdb', .36)}" stop-opacity=".6"/></linearGradient>
<linearGradient id="shade" x1="0" y1="0" x2="0" y2="1">
<stop stop-color="#0d2429" stop-opacity=".22"/><stop offset=".36" stop-color="#ffffff" stop-opacity=".08"/>
<stop offset=".63" stop-color="#ffffff" stop-opacity="0"/><stop offset="1" stop-color="#172b31" stop-opacity=".2"/></linearGradient>
<linearGradient id="royal" x1="0" y1="0" x2="1" y2="0">
<stop stop-color="#dfb937"/><stop offset=".43" stop-color="#e5c44b"/><stop offset=".56" stop-color="#9d64a7"/>
<stop offset="1" stop-color="#755299"/></linearGradient>
<linearGradient id="fire" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#a13c30"/>
<stop offset=".42" stop-color="#d78265"/><stop offset=".65" stop-color="#eee3cb"/></linearGradient>
<clipPath id="outline"><path d="{body}"/></clipPath>
</defs>'''


def eye(x, y, radius=7, iris="#b5ae74"):
    return (ellipse(x, y, radius + 1.8, radius + 1.3, "#263632", .65)
            + ellipse(x, y, radius, radius * .96, iris)
            + ellipse(x + 1, y, radius * .61, radius * .7, "#101c20")
            + ellipse(x + 1.6, y - radius * .31, radius * .16, radius * .15, "#f4f7e9", .87))


def fin_shape(d, rays, color="#26464e"):
    return (path(d, "url(#fin)", color, .65, .97)
            + "".join(path(ray, "none", color, .8, .38) for ray in rays))


def tail(kind, tx=165):
    if kind in ("fork", "yellowfork", "deepfork", "sickle", "lyre"):
        bottom, top = (293, 105) if kind in ("deepfork", "sickle", "lyre") else (269, 131)
        notch = 94 if kind != "sickle" else 76
        d = f"M{tx+6} 186 Q111 177 61 {top} Q72 171 {notch} 201 Q72 233 61 {bottom} Q112 231 {tx+6} 216 Z"
        if kind == "lyre":
            d = f"M{tx+6} 186 Q109 173 51 104 Q90 179 104 201 Q88 240 51 297 Q115 232 {tx+6} 216 Z"
        if kind == "yellowfork":
            return path(d, "#e7c84c", "#9c973c", .7) + "".join(path(f"M{tx} 202 Q116 {y} 69 {y}", "none", "#9a8d31", .8, .4) for y in range(145, 268, 15))
        rays = [f"M{tx} 202 Q111 {y} 68 {y}" for y in range(top+12, bottom, 16)]
    elif kind in ("veil", "betta", "fan"):
        top, bottom = (110, 306) if kind == "fan" else (80, 328)
        d = f"M{tx+8} 185 Q160 157 101 {top} C66 {top-12} 53 {top+17} 60 {top+43} C31 159 45 189 38 207 C46 232 31 262 65 280 Q61 {bottom+12} 99 {bottom} Q165 244 {tx+8} 216 Z"
        rays = [f"M{tx} 202 Q122 {(201+y)/2:.1f} 64 {y}" for y in range(top+10, bottom, 15)]
    elif kind == "point":
        d = f"M{tx+5} 188 Q105 177 55 201 Q105 225 {tx+5} 215 Z"
        rays = [f"M{tx} 202 L61 {y}" for y in (191, 198, 205, 212)]
    else:
        d = f"M{tx+7} 187 C127 174 112 145 77 145 C42 171 43 235 78 259 C114 257 132 228 {tx+7} 216 Z"
        rays = [f"M{tx} 202 Q104 {y} 67 {y}" for y in range(157, 256, 12)]
    return fin_shape(d, rays)


def dorsal(kind):
    shapes = {
        "reef": ("M201 167 L218 127 L237 135 L250 110 L268 121 L282 101 L302 113 L319 100 Q382 88 431 157 L401 162 Z", (235, 120, 420, 145)),
        "tang": ("M199 177 Q202 101 248 75 Q316 52 369 84 Q425 101 452 169 L405 183 Z", (220, 110, 430, 140)),
        "sail": ("M199 184 Q184 86 233 36 Q294 20 354 50 Q418 73 447 157 L411 182 Z", (210, 85, 420, 130)),
        "banner": ("M235 145 Q231 65 278 39 Q339 8 414 31 Q331 28 310 64 Q318 104 392 145 Z", (258, 67, 365, 125)),
        "low": ("M176 183 L194 154 L213 158 L232 143 L253 149 L277 132 L302 142 Q368 117 445 178 L418 187 Z", (204, 154, 413, 168)),
        "mandarin": ("M269 164 Q250 94 303 75 Q341 71 348 153 Z M167 185 Q172 142 224 133 Q267 124 277 161 Z", (180, 151, 320, 125)),
        "cardinal": ("M275 146 L317 58 Q335 85 363 135 Z M180 181 Q181 105 217 81 Q263 107 279 151 Z", (207, 112, 345, 107)),
        "small": ("M268 168 L309 104 Q333 106 341 117 L360 159 Z", (293, 133, 340, 130)),
        "mackerel": ("M327 151 L373 89 L387 145 Z M217 173 L241 145 L274 162 Z", (345, 123, 375, 128)),
        "tuna": ("M325 131 Q345 58 380 45 Q361 88 403 137 Z M218 166 Q227 109 266 82 Q252 131 288 143 Z", (348, 96, 378, 118)),
        "pomfret": ("M179 190 Q177 123 215 82 Q268 48 324 77 L387 127 Z", (205, 119, 316, 90)),
        "trevally": ("M212 166 Q229 92 281 62 Q269 119 319 124 Z M345 120 L373 89 L388 126 Z", (235, 113, 286, 126)),
        "barracuda": ("M345 178 L379 123 L414 178 Z M159 190 L182 141 L217 180 Z", (359, 157, 397, 159)),
        "arowana": ("M115 190 Q149 119 231 120 L255 136 L279 137 L307 148 L316 163 Z", (141, 143, 277, 153)),
        "goldfish": ("M243 163 Q252 87 302 77 Q349 81 415 155 Z", (265, 119, 378, 128)),
        "guppy": ("M271 177 Q263 112 286 111 Q325 135 370 172 Z", (285, 135, 338, 156)),
        "betta": ("M241 185 Q237 105 270 82 Q289 67 318 83 Q361 89 398 164 Z", (259, 116, 358, 138)),
        "banggai": ("M293 144 L333 37 Q337 95 371 140 Z M170 184 Q183 66 243 64 Q226 94 268 153 Z", (198, 111, 339, 110)),
        "firefish": ("M358 169 Q352 78 379 42 Q394 83 387 170 Z M173 188 Q185 148 243 150 Q290 151 316 169 Z", (195, 164, 292, 168)),
        "emperor": ("M180 170 Q203 80 292 63 Q392 62 444 156 L399 177 Z", (217, 113, 407, 126)),
        "spines": ("M188 174 L198 127 L216 148 L232 103 L249 133 L266 91 L281 120 L297 78 L315 113 L334 85 L350 121 L366 103 Q409 117 447 166 Z", (208, 147, 410, 148)),
    }
    d, (x1, y1, x2, y2) = shapes[kind]
    rays = [f"M{round(x1+(x2-x1)*i/12)} 179 L{round(x1+(x2-x1)*i/12)} {round(y1+(y2-y1)*i/12)}" for i in range(13)]
    result = fin_shape(d, rays)
    if kind in ("sail", "tang", "pomfret", "emperor"):
        result += fin_shape("M199 215 Q205 288 282 327 Q355 348 425 253 L412 232 Z", [f"M{240+i*12} 244 L{240+i*12} {300+int(14*math.sin(i))}" for i in range(12)])
    elif kind == "betta":
        result += fin_shape("M235 216 Q237 289 267 304 Q318 334 372 319 L414 268 L456 230 Z", [f"M{263+i*13} 230 L{255+i*13} {306-int(i*3)}" for i in range(13)])
    elif kind in ("tuna", "trevally"):
        result += fin_shape("M226 231 Q231 295 269 322 Q252 272 307 256 Z", ["M238 242 Q246 281 265 313"])
    else:
        result += fin_shape("M224 225 Q242 277 286 275 Q304 259 321 240 Z", [f"M{241+i*10} 238 L{246+i*10} 268" for i in range(6)])
    return result


def scales(seed, large=False):
    rng = random.Random(seed)
    result = []
    size = 15 if large else 7
    for row, y in enumerate(range(105, 299, size)):
        for x in range(164 + (row % 2) * size // 2, 486, size + 2):
            alpha = rng.uniform(.09, .21)
            result.append(path(f"M{x} {y} q{size*.72:.1f} {size*.4:.1f} 0 {size*.9:.1f}", "none", "#203f48", .55, round(alpha, 3)))
            if (x + y) % 3 == 0:
                result.append(path(f"M{x+1} {y} q{size*.6:.1f} {size*.4:.1f} 0 {size*.7:.1f}", "none", "#fbf3d6", .6, .16))
    return "".join(result)


def spots(seed, fill, count, radius, x_range=(172, 490), y_range=(125, 282), opacity=.8):
    rng = random.Random(seed)
    return "".join(ellipse(round(rng.uniform(*x_range), 2), round(rng.uniform(*y_range), 2), round(rng.uniform(radius*.65, radius*1.25), 2), round(rng.uniform(radius*.65, radius), 2), fill, opacity) for _ in range(count))


def markings(item_id):
    s = []
    if item_id == "clownfish":
        for x, w in ((199, 23), (313, 39), (430, 32)):
            s.append(path(f"M{x} 99 Q{x-17} 162 {x+9} 196 Q{x+22} 227 {x-6} 302 L{x+w} 302 Q{x+w+26} 237 {x+w+7} 191 Q{x+w-16} 140 {x+w} 99 Z", "#e5e2cb", "#343b35", 5))
    elif item_id == "blue-tang":
        s.append(path("M169 179 Q234 171 258 130 Q370 102 426 160 Q383 193 325 178 Q274 170 271 204 Q248 243 169 218 Z", "#152a48"))
        s.append(path("M250 151 Q288 126 331 128 Q356 131 370 145 Q315 140 273 168 Z", "#4483ca"))
    elif item_id in ("butterflyfish", "moorish-idol"):
        for x, width in ((228, 36), (400, 42)):
            s.append(path(f"M{x} 60 Q{x-20} 177 {x+16} 334 L{x+width+20} 334 Q{x+width-17} 168 {x+width} 60 Z", "#27312d"))
        if item_id == "moorish-idol":
            s.append(path("M292 230 Q340 191 390 208 L406 281 L305 316 Z", "#dec857"))
        else:
            s.append(path("M146 79 Q228 131 254 222 L229 304 L151 299 Z", "#222e2e"))
            for y in range(130, 287, 10):
                s.append(path(f"M259 {y} L389 {y-22}", "none", "#83772f", .9, .65))
    elif item_id == "royal-gramma":
        s.append(path("M100 90 H560 V300 H100Z", "url(#royal)"))
        s.append(path("M455 186 L488 210", "none", "#3b2d48", 6))
    elif item_id == "mandarinfish":
        for d in ("M180 214 Q215 178 267 205 T351 188 Q399 177 428 205", "M216 156 Q257 187 303 165 T386 165 Q417 165 455 184", "M217 231 Q259 205 293 228 T368 221 T431 222", "M454 166 Q416 199 451 238", "M336 150 Q309 186 338 217 Q353 232 341 252"):
            s.append(path(d, "none", "#8f4c27", 15))
            s.append(path(d, "none", "#77c1b6", 5))
    elif item_id == "cardinalfish":
        s.append(path("M340 100 Q326 195 344 287 L382 287 Q364 191 381 100 Z", "#3d4236"))
        s.append(spots(4, "#84685a", 55, 4, (168, 333), (135, 270)))
    elif item_id in ("sardine", "anchovy", "herring", "milkfish"):
        s.append(path("M144 201 Q337 188 505 198", "none", "#f6f4e3", 9 if item_id == "anchovy" else 3.5, .7))
        s.append(path("M150 210 Q329 222 476 213", "none", "#6d8c96", 1, .8))
        if item_id == "sardine":
            s.extend(ellipse(239+i*20, 180-i*.5, 3.4, 2.6, "#436273", .65) for i in range(10))
    elif item_id == "atlantic-mackerel":
        for i, x in enumerate(range(184, 487, 17)):
            s.append(path(f"M{x} 128 q-15 13 -2 23 t-5 24 t5 18", "none", "#1c454f", 5, .85))
    elif item_id == "yellowfin-tuna":
        s.append(path("M138 204 Q348 176 520 205", "none", "#c5b861", 3, .55))
    elif item_id == "giant-trevally":
        s.append(path("M153 213 L271 204 Q317 153 411 182", "none", "#506c71", 1.5, .8))
        s.extend(path(f"M{x} 198 l-6 7 l6 7", "none", "#536e73", 1.2) for x in range(164, 270, 9))
    elif item_id == "barracuda":
        for x in range(150, 489, 29):
            s.append(path(f"M{x} 169 q-9 25 8 35 l7 -5 q-6 -16 1 -31Z", "#4e6a61", opacity=.6))
    elif item_id == "goldfish":
        s.append(path("M225 152 Q274 121 327 147 Q355 198 413 184 Q441 173 467 210 Q450 247 407 252 Q331 263 288 231 Q261 205 225 204 Z", "#d36931", opacity=.8))
    elif item_id == "koi":
        for d in ("M440 122 Q401 150 427 186 Q468 204 514 174 L512 126 Z", "M302 130 Q281 165 311 185 Q340 214 372 179 Q391 149 368 123 Z", "M173 168 Q209 144 240 165 Q272 188 247 214 Q230 230 204 220 Z"):
            s.append(path(d, "#b95031", opacity=.96))
        for d in ("M278 148 Q256 170 273 190 L297 196 L310 179 Z", "M395 183 Q375 186 390 215 Q420 225 433 211 L423 190 Z"):
            s.append(path(d, "#323b36", opacity=.93))
    elif item_id == "guppy":
        s.append(ellipse(310, 199, 22, 18, "#304640"))
        s.append(ellipse(362, 191, 17, 11, "#b67f45"))
        s.append(path("M295 209 Q356 189 429 204", "none", "#61a8b4", 5, .8))
    elif item_id == "neon-tetra":
        s.append(path("M174 206 Q328 189 471 196", "none", "#329dbd", 13, .95))
        s.append(path("M180 202 Q330 186 470 191", "none", "#8fe0df", 2, .86))
        s.append(path("M166 215 Q277 201 348 217 L359 252 L165 239Z", "#b74d48"))
    elif item_id == "zebra-danio":
        for y in range(176, 232, 12):
            s.append(path(f"M151 {y+17} Q316 {y-9} 494 {y+6}", "none", "#3d536f", 5, .95))
    elif item_id == "siamese-fighting-fish":
        s.append(path("M253 201 Q338 166 429 187", "none", "#5ca5a6", 9, .45))
        s.append(path("M411 167 Q393 200 416 231 L446 218 L456 176Z", "#763e4c", opacity=.6))
    elif item_id == "banggai-cardinalfish":
        for x, w in ((218, 32), (326, 43), (442, 28)):
            s.append(path(f"M{x} 88 Q{x-15} 180 {x+5} 296 L{x+w} 296 Q{x+w-20} 180 {x+w} 88Z", "#263331"))
        s.append(spots(9, "#f0edda", 67, 2.2))
    elif item_id == "firefish":
        s.append(path("M100 90 H560 V300 H100Z", "url(#fire)"))
        s.append(path("M458 190 Q449 210 467 225", "none", "#ca7658", 5))
    elif item_id == "sixline-wrasse":
        for y in range(149, 250, 18):
            s.append(path(f"M172 {y+20} Q334 {y-10} 497 {y+38}", "none", "#be8c57", 6, .95))
    elif item_id == "fairy-wrasse":
        s.append(path("M154 210 Q318 175 452 199 L482 218 Q317 225 154 221Z", "#4d7180", opacity=.86))
        s.append(path("M439 150 Q419 196 451 237 L482 217 L484 167Z", "#718e9c", opacity=.65))
    elif item_id in ("flame-angelfish", "coral-beauty"):
        for x in range(212, 422, 41 if item_id == "flame-angelfish" else 16):
            s.append(path(f"M{x} 91 Q{x-5} 152 {x+5} 201 L{x+14} 263 L{x+23} 266 Q{x+17} 173 {x+25} 90Z", "#30394b" if item_id == "flame-angelfish" else "#555079", opacity=.9 if item_id == "flame-angelfish" else .55))
        if item_id == "coral-beauty":
            s.append(path("M391 95 Q401 160 444 184 L478 225 L530 230 L530 90Z", "#3b3d67", opacity=.9))
    elif item_id == "emperor-angelfish":
        for y in range(103, 299, 18):
            s.append(path(f"M155 {y+32} Q274 {y-12} 413 {y+8}", "none", "#e1cf69", 8, .94))
        s.append(path("M409 102 Q382 193 441 283 L487 233 L468 160Z", "#253b46"))
        s.append(path("M460 159 L503 209 L486 225 L439 181Z", "#e6dcb0"))
    elif item_id == "foxface-rabbitfish":
        s.append(path("M432 119 Q404 188 448 273 L487 239 L470 175 Z", "#e7e2c5"))
        s.append(path("M452 123 Q433 174 460 225 L496 246 L518 221 L472 178Z", "#2d3632"))
    elif item_id == "sailfin-tang":
        for x in range(205, 451, 38):
            s.append(path(f"M{x} 65 Q{x-26} 193 {x+12} 330 L{x+31} 330 Q{x+3} 191 {x+21} 65Z", "#665c39", opacity=.85))
        s.append(spots(13, "#e2d38e", 95, 1.7))
    elif item_id == "naso-tang":
        s.append(path("M479 161 L507 193 L494 210 L474 182Z", "#bc9e53"))
        s.append(path("M488 203 L526 198 L526 212 L499 217Z", "#b17a4d"))
        s.append(ellipse(186, 203, 13, 5, "#d5a34e"))
    elif item_id == "purple-tang":
        s.append(spots(14, "#352c55", 170, 1.5))
    elif item_id == "yellow-tang":
        s.append(path("M185 203 L224 202", "none", "#f7efce", 4))
    return "".join(s)


def regular(item_id):
    back, flank, belly, fin, shape, tailkind, dorsalkind = PROFILES[item_id]
    body = BODIES[shape]
    # Attach each caudal fin to its actual peduncle.  A single shared anchor
    # leaves visible seams on the deeper-bodied surgeonfish and butterflyfish.
    tx = {
        "reef": 170, "tang": 180, "butterfly": 184, "yellowtang": 180,
        "dwarfangel": 170, "emperor": 155, "gramma": 151, "mandarin": 160,
        "cardinal": 164, "sardine": 139, "anchovy": 125, "herring": 140,
        "mackerel": 139, "tuna": 133, "pomfret": 167, "milkfish": 133,
        "trevally": 143, "barracuda": 106, "arowana": 106, "goldfish": 203,
        "koi": 146, "guppy": 224, "tetra": 156, "danio": 153, "betta": 225,
        "firefish": 149, "wrasse": 151, "foxface": 151, "naso": 145,
    }[shape]
    pieces = [defs(back, flank, belly, fin, body), tail(tailkind, tx), dorsal(dorsalkind)]
    if item_id == "yellowfin-tuna":
        for x in range(145, 230, 15):
            y = 184-(x-145)*.21
            pieces.append(path(f"M{x} {y} l5 -12 l9 7 Z M{x} {404-y} l5 12 l9 -7 Z", "#c8b65e", "#7d884f", .6))
    if item_id in ("sailfin-tang", "banggai-cardinalfish"):
        pieces.append('<g opacity=".5">' + "".join(path(f"M{x} 75 Q{x-30} 190 {x+15} 325", "none", "#343c39", 7) for x in range(225, 394, 35)) + '</g>')
    pieces += [path(body, "url(#body)", back, .7), '<g clip-path="url(#outline)">', markings(item_id), scales(item_id, item_id == "arowana"), path(body, "url(#shade)"), '</g>']
    if item_id in ("guppy", "siamese-fighting-fish"):
        for i in range(27):
            y = 116+i*6.8
            x = 73+20*math.sin(i*.7)
            pieces.append(ellipse(round(x, 1), round(y, 1), 3.3, 2.4, "#383b43", .5))
    # Near pectoral fin is translucent and visibly rooted behind the operculum.
    pieces.append(fin_shape("M407 215 Q367 210 324 253 Q365 264 402 233 Z", [f"M401 218 L{335+i*9} {251-i*3}" for i in range(6)]))
    pieces.append(path("M432 172 Q413 202 431 234", "none", mix(back, '#112725', .25), 1.4, .7))
    eye_x, eye_y = (488, 193) if shape in ("barracuda", "arowana") else (471, 190) if shape in ("anchovy", "firefish", "wrasse", "naso", "foxface") else (466, 190)
    pieces.append(eye(eye_x, eye_y, 8 if shape == "cardinal" else 6.4, "#9eaa7f" if item_id != "cardinalfish" else "#aa6851"))
    mouthx = 532 if shape in ("arowana", "barracuda") else 502
    pieces.append(path(f"M{mouthx} 207 q-11 1 -17 -3", "none", back, 1.2, .8))
    if item_id in ("koi", "arowana"):
        pieces.append(path("M514 213 Q538 225 540 231" if item_id == "koi" else "M548 186 Q567 169 581 170", "none", "#b9bba2", 2.2))
    return "".join(pieces)


def shark(item_id):
    zebra = item_id == "zebra-shark"
    whale = item_id == "whale-shark"
    hammer = item_id == "scalloped-hammerhead"
    back, flank, belly = ("#566351", "#b5b18a", "#d5cfaa") if zebra else ("#263d4a", "#638291", "#b3c4c1") if whale else ("#3b535d", "#78909a", "#e0e5da")
    body = ("M129 192 Q234 155 361 150 Q435 147 508 172 Q548 183 553 207 Q549 241 508 250 Q415 253 340 238 Q229 222 129 214Z" if whale else
            "M126 192 Q230 162 354 157 C437 150 493 175 557 197 L575 207 Q546 220 508 224 Q431 249 350 236 Q230 218 126 212Z")
    if item_id == "great-white-shark":
        body = "M126 192 Q224 167 340 148 C421 135 489 166 550 195 L575 207 Q549 222 511 231 Q433 269 348 244 Q228 220 126 212Z"
    if hammer:
        body = "M128 192 Q233 170 359 164 Q439 158 509 186 L528 156 Q552 151 577 168 L585 184 L568 202 L577 234 Q562 253 540 251 L519 230 L508 217 Q425 239 354 233 Q233 217 128 212Z"
    pieces = [defs(back, flank, belly, flank, body)]
    taild = ("M172 189 Q103 161 23 107 Q53 160 123 202 Q66 216 48 258 Q115 242 174 215Z" if zebra else "M165 190 Q106 150 68 60 Q62 129 105 199 Q75 238 83 280 Q128 242 165 216Z")
    pieces.append(fin_shape(taild, ["M149 204 Q98 158 70 78", "M149 205 L88 264"]))
    pieces.append(fin_shape("M307 168 Q315 93 354 64 Q345 113 397 171Z" if not zebra else "M287 169 Q313 119 340 117 Q337 144 377 164Z", ["M322 163 Q331 122 351 77"]))
    pieces.append(fin_shape("M207 184 L222 153 L247 177Z", ["M218 177 L223 162"]))
    pieces.append(fin_shape("M315 226 Q266 272 233 274 Q251 238 271 218Z", ["M295 230 L247 264"]))
    pieces.append(path(body, "url(#body)", back, .7))
    pieces.append('<g clip-path="url(#outline)">')
    pieces.append(path("M126 209 Q241 213 347 210 Q437 206 515 217 L573 209 L586 260 L127 258Z", belly, opacity=.86))
    if whale:
        for y in range(169, 230, 13):
            for x in range(183, 520, 15):
                pieces.append(ellipse(x+(y%3)*2, y+3*math.sin(x*.04), 2.6, 2.1, "#d7dfcf", .82))
        pieces.append(path("M172 187 Q326 178 461 187 M172 202 Q337 193 467 203", "none", "#b6c9bc", 1.3, .55))
    elif zebra:
        pieces.append(spots(25, "#615c42", 130, 2.4, (160, 533), (161, 238)))
    pieces.append('</g>')
    pieces.append(fin_shape("M413 216 Q382 271 310 303 Q350 251 353 215Z", ["M406 219 L325 291", "M390 221 L332 282"]))
    for i in range(5):
        x = 438-i*10
        pieces.append(path(f"M{x} {182+i} q-9 16 -1 32", "none", "#354e52", 1.6, .85))
    if item_id == "blacktip-reef-shark":
        for d in ("M339 76 L354 64 Q348 85 351 99 L335 97Z", "M68 60 L73 104 L81 105Z", "M310 303 L332 283 L336 288Z"):
            pieces.append(path(d, "#273a3f"))
    if hammer:
        pieces.append(path("M530 169 Q555 161 577 176 M530 229 Q554 244 567 234", "none", "#b8c9c6", 1.3, .6))
        pieces.append(eye(574, 177, 4, "#7a8c7f"))
        pieces.append(path("M532 216 Q545 220 557 214", "none", "#334a4d", 1.2))
    elif whale:
        pieces.append(eye(530, 195, 4, "#7a9289"))
        pieces.append(path("M549 210 Q542 213 530 214", "none", "#334d55", 1.7))
    else:
        pieces.append(eye(527, 194, 4.3, "#697d77"))
        pieces.append(path("M548 213 Q531 217 508 212", "none", "#394b4e", 1.3))
    return "".join(pieces)


def ray(item_id):
    spotted = item_id == "blue-spotted-stingray"
    eagle = item_id == "eagle-ray"
    body = ("M174 205 Q212 121 317 103 Q399 97 469 142 Q494 162 509 197 Q495 226 476 247 Q421 292 330 294 Q228 288 174 205Z" if spotted else
            "M189 205 Q277 129 319 41 Q363 55 408 109 Q444 153 484 185 L522 190 L531 208 L502 220 Q461 248 408 279 Q361 324 320 361 Q279 284 189 205Z")
    back, flank, belly = ("#747046", "#b2a566", "#d1c89a") if spotted else ("#243c43", "#57727b", "#9bafb0") if eagle else ("#203038", "#40565f", "#7b9098")
    pieces = [defs(back, flank, belly, flank, body)]
    pieces.append(path("M214 205 C141 186 126 232 73 218 Q42 204 27 211", "none", back, 7 if spotted else 4))
    if eagle:
        pieces.append(path("M183 205 Q109 189 42 236", "none", "#273f45", 2))
    pieces.append(path(body, "url(#body)", back, .8))
    pieces.append('<g clip-path="url(#outline)">')
    for i in range(16):
        if spotted:
            pieces.append(path(f"M453 201 Q{320-i*4} {116+i*11} {183+i*9} {153+i*6}", "none", "#756c42", .7, .23))
        else:
            pieces.append(path(f"M444 201 Q{334-i*3} {107+i*13} {320-i*4} {44+i*21}", "none", "#9aaba6", .7, .16))
    if spotted:
        pieces.append(spots(63, "#3499b2", 40, 6, (210, 470), (119, 284)))
        pieces.append(spots(63, "#71cad3", 40, 2.5, (210, 470), (119, 284), .7))
    elif eagle:
        pieces.append(spots(64, "#d0d6c3", 165, 2.6, (216, 480), (54, 346)))
    pieces.append('</g>')
    if not spotted and not eagle:
        pieces.append(path("M479 187 Q526 152 552 168 Q523 173 521 193 L511 203Z", "#61787e", "#2c4149", 1))
        pieces.append(path("M482 217 Q530 243 546 227 Q523 225 519 209Z", "#7a9294", "#2c4149", 1))
    pieces.append(path("M218 205 Q332 183 445 196", "none", "#c0cebd", 2, .18))
    pieces.append(eye(475 if not spotted else 470, 188, 4.5, "#a7ad83"))
    pieces.append(ellipse(458, 186, 6, 3, "#263e3d", .8))
    return "".join(pieces)


def seahorse():
    body = "M296 83 Q312 55 341 75 Q357 86 357 110 L402 126 L405 143 L365 143 Q352 164 344 183 Q374 206 355 244 Q336 271 303 284 Q275 300 286 321 Q298 341 318 326 Q328 315 317 309 Q307 305 306 317 Q296 313 301 302 Q318 289 331 305 Q347 329 319 345 Q289 364 269 337 Q246 305 278 275 Q312 252 315 228 Q283 215 280 188 Q275 156 305 130 L294 114Z"
    p = [defs("#6b6645", "#c0ae74", "#dfcf9a", "#c3c697", body)]
    p.append(fin_shape("M282 163 Q240 151 243 183 Q250 214 289 216Z", [f"M286 190 L249 {y}" for y in range(163, 212, 7)]))
    p.append(path(body, "url(#body)", "#5f6245", .9))
    p.append('<g clip-path="url(#outline)">')
    p.append(path("M317 89 Q334 135 310 163 Q295 194 335 221 Q337 254 285 294", "none", "#837e50", 5, .6))
    for i in range(22):
        y = 118+i*9
        p.append(path(f"M268 {y} Q308 {y+6} 369 {y-4}", "none", "#6f724b", 1.8, .63))
        p.append(path(f"M270 {y+2} Q310 {y+9} 367 {y-2}", "none", "#e2d9ab", 1.5, .55))
    p.append(spots(53, "#8c8050", 96, 1.7, (280, 365), (85, 287)))
    p.append('</g>')
    for x, y, dx in ((297, 84, -12), (302, 105, -15), (285, 152, -10), (279, 177, -10), (287, 206, -12), (350, 215, 11), (345, 239, 10)):
        p.append(path(f"M{x} {y} l{dx} -8 l{-dx+4} 15Z", "#a19661", "#71764b", .6))
    p.append(eye(339, 103, 6.3, "#bbaa72"))
    p.append(path("M392 138 L405 140", "none", "#645f42", 1.4))
    return "".join(p)


def special(item_id):
    if item_id == "seahorse":
        return seahorse()
    if item_id == "angelfish":
        body = "M228 190 Q280 115 350 115 Q413 120 457 183 L489 201 L477 216 Q427 257 357 269 Q288 268 228 216Z"
        p = [defs("#4e6265", "#c0c9bd", "#e4e2c8", "#a4b4ac", body), tail("fork", 232)]
        p.append(fin_shape("M254 180 Q283 75 337 27 Q341 94 401 139Z", [f"M{281+i*12} 161 L337 {42+i*11}" for i in range(9)]))
        p.append(fin_shape("M254 221 Q284 310 329 360 Q333 292 399 247Z", [f"M{281+i*12} 237 L329 {345-i*11}" for i in range(9)]))
        p.append(path(body, "url(#body)", "#566b66", .6))
        p.append('<g clip-path="url(#outline)">')
        for x, w in ((274, 26), (350, 30), (437, 20)):
            p.append(path(f"M{x} 89 Q{x-18} 189 {x+6} 289 L{x+w} 290 Q{x+w-16} 190 {x+w} 89Z", "#344849", opacity=.9))
        p.append(scales(item_id))
        p.append('</g>')
        p.append(path("M409 237 Q397 312 421 367 M421 235 Q421 307 446 343", "none", "#bec4b1", 2.3))
        p.append(fin_shape("M403 209 Q367 209 337 245 L399 230Z", ["M400 215 L349 239"]))
        p.append(eye(450, 189, 6.5, "#b3a287"))
        return "".join(p)
    if item_id == "lionfish":
        body = BODIES["reef"]
        p = [defs("#633d31", "#b58a69", "#d7bca0", "#b1957c", body), tail("round")]
        for i in range(13):
            x = 214+i*16
            tipy = 58+30*math.cos(i*.45)
            p.append(fin_shape(f"M{x-13} 175 Q{x-4} 110 {x+19} {tipy} Q{x+7} 123 {x+16} 170Z", [f"M{x+6} 165 L{x+19} {tipy}"]))
        p.append(fin_shape("M403 205 Q344 132 238 128 Q204 190 239 286 Q318 300 398 230Z", [f"M401 214 L{240+int(26*math.cos(i))} {136+i*13}" for i in range(13)]))
        p.append(path(body, "url(#body)", "#6b4939", .6))
        p.append('<g clip-path="url(#outline)">')
        for x in range(176, 494, 24):
            p.append(path(f"M{x} 95 Q{x-17} 180 {x+20} 300 L{x+32} 300 Q{x-2} 188 {x+12} 95Z", "#e0cdb0"))
        p.append('</g>')
        p.append(fin_shape("M422 210 Q384 242 369 333 L346 303 L315 330 L300 298 L269 315 L263 280 L233 289 L248 250 L219 251 L248 226 Q345 202 422 210Z", [f"M418 213 L{239+i*13} {268+int(50*math.sin(i*.13))}" for i in range(12)]))
        for i in range(11):
            p.append(path(f"M{252+i*10} {246+i*5} l10 -12 l6 16 l-9 12Z", "#68483c", opacity=.7))
        p.append(path("M463 179 Q473 155 462 145 M455 179 Q449 158 440 152", "none", "#bc9b78", 3))
        p.append(eye(467, 190, 6.5, "#a89e7a"))
        return "".join(p)
    if item_id == "pufferfish":
        body = "M184 185 C202 131 267 105 351 115 C433 123 470 155 480 185 L512 194 L515 210 L487 224 C466 274 417 293 344 290 C251 290 199 253 184 215Z"
        p = [defs("#35423a", "#647367", "#c4c9a7", "#8c9981", body), tail("round", 190)]
        p.append(fin_shape("M260 137 L268 95 Q304 92 313 126Z", ["M275 127 L275 103", "M290 126 L290 101"]))
        p.append(path(body, "url(#body)", "#384c3f", .6))
        p.append('<g clip-path="url(#outline)">' + spots(21, "#dddcc1", 290, 2.8, (185, 493), (111, 290)) + '</g>')
        p.append(fin_shape("M432 211 Q386 219 375 257 Q414 255 440 230Z", [f"M433 218 L{380+i*9} {251-i*3}" for i in range(6)]))
        p.append(eye(468, 183, 8, "#b7c39b"))
        p.append(path("M497 200 Q507 201 512 205", "none", "#6e7c63", 2))
        return "".join(p)
    if item_id == "ocean-sunfish":
        body = "M188 150 Q197 112 238 103 C333 66 441 107 479 172 L505 191 L508 211 L482 224 C458 281 371 311 280 292 Q205 284 186 247 L196 224 L185 208 L193 188 L183 173Z"
        p = [defs("#566762", "#aab6a5", "#d4d9bd", "#859b88", body)]
        p.append(fin_shape("M254 122 Q263 30 321 27 Q313 66 344 107Z", ["M280 105 L310 40", "M291 109 L313 51"]))
        p.append(fin_shape("M255 282 Q274 359 315 374 Q317 334 350 289Z", ["M285 295 L309 359"]))
        p.append(path(body, "url(#body)", "#52675c", .8))
        p.append('<g clip-path="url(#outline)">' + spots(29, "#5d7468", 260, 1.4, (189, 486), (98, 294), .32) + spots(31, "#e0e5ca", 90, 2.5, (205, 469), (109, 279), .25) + '</g>')
        p.append(path("M222 118 Q247 207 226 276", "none", "#6c8371", 2.7, .65))
        p.append(fin_shape("M418 207 Q391 225 376 228 L397 200Z", ["M414 208 L382 224"]))
        p.append(eye(465, 183, 6.5, "#a5b296"))
        p.append(path("M494 201 Q505 203 508 207", "none", "#567160", 2))
        return "".join(p)
    if item_id == "redtail-catfish":
        body = "M146 190 Q230 155 348 154 C431 151 491 168 525 186 Q558 199 556 212 Q540 232 493 236 C424 259 337 255 269 237 Q200 221 146 215Z"
        p = [defs("#2d413c", "#627768", "#d4cfab", "#8f7761", body)]
        p.append(path("M154 187 Q115 162 75 144 Q56 195 75 253 Q120 240 155 216Z", "#b7603e", "#795239", 1))
        p.append(fin_shape("M285 168 Q302 92 334 94 L368 161Z", ["M307 157 L327 102"]))
        p.append(path(body, "url(#body)", "#2f4b40", .8))
        p.append('<g clip-path="url(#outline)">')
        p.append(path("M146 211 Q317 183 497 211 L555 203 L554 235 Q317 261 147 221Z", "#e0d8b4", opacity=.94))
        p.append(spots(38, "#344e3d", 75, 2.5, (310, 536), (158, 203)))
        p.append('</g>')
        p.append(fin_shape("M445 222 Q415 255 363 269 Q373 238 397 215Z", ["M436 224 L372 260"]))
        for d in ("M538 209 Q582 234 609 222", "M528 219 Q551 262 593 266", "M536 206 Q586 188 609 176", "M516 223 Q505 255 519 280"):
            p.append(path(d, "none", "#d8d0a9", 2.5))
        p.append(eye(507, 187, 5.8, "#a4a984"))
        p.append(path("M551 210 L527 211", "none", "#375541", 1.8))
        return "".join(p)
    if item_id == "discus":
        body = "M207 190 Q227 118 289 94 Q358 65 420 114 Q462 144 475 183 L504 199 L491 215 Q457 272 411 295 Q332 333 265 289 Q223 258 207 217Z"
        p = [defs("#425c64", "#a3b9aa", "#d2c6a0", "#899c87", body), tail("round", 210)]
        p.append(fin_shape("M220 176 Q223 71 319 53 Q389 50 443 134 L415 159Z", [f"M{251+i*13} 151 L{252+i*12} {94-i*2}" for i in range(12)]))
        p.append(fin_shape("M222 228 Q250 338 333 341 Q410 337 450 264 L427 245Z", [f"M{251+i*13} 259 L{252+i*12} {310-i*2}" for i in range(12)]))
        p.append(path(body, "url(#body)", "#557268", .6))
        p.append('<g clip-path="url(#outline)">')
        for x in range(227, 460, 30):
            p.append(path(f"M{x} 67 Q{x-9} 171 {x+10} 320", "none", "#54716b", 9, .35))
        for y in range(108, 308, 15):
            p.append(path(f"M203 {y} Q257 {y-25} 291 {y+1} T358 {y} T436 {y+10}", "none", "#b26c53", 4, .84))
        p.append(scales(item_id))
        p.append('</g>')
        p.append(fin_shape("M418 215 Q371 231 361 262 Q407 249 427 231Z", ["M421 220 L371 254"]))
        p.append(eye(464, 190, 6.4, "#ba8763"))
        return "".join(p)
    raise ValueError(f"No drawing for {item_id}")


def generate(entries, output_dir):
    """Write one transparent, standalone 640 x 400 SVG per fish entry."""
    output_dir = Path(output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    results = []
    known = set(PROFILES) | {
        "angelfish", "lionfish", "pufferfish", "seahorse", "great-white-shark",
        "whale-shark", "scalloped-hammerhead", "blacktip-reef-shark", "zebra-shark",
        "ocean-sunfish", "manta-ray", "eagle-ray", "blue-spotted-stingray",
        "redtail-catfish", "discus",
    }
    for entry in entries:
        item_id = entry["id"]
        if item_id not in known:
            raise ValueError(f"Unknown fish identity: {item_id}")
        if item_id in PROFILES:
            artwork = regular(item_id)
        elif item_id.endswith("shark") or item_id == "scalloped-hammerhead":
            artwork = shark(item_id)
        elif item_id.endswith("ray"):
            artwork = ray(item_id)
        else:
            artwork = special(item_id)
        title = escape(entry.get("scientific_name", item_id))
        svg = ('<?xml version="1.0" encoding="UTF-8"?>\n'
               '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400" '
               'viewBox="0 0 640 400" role="img">\n'
               f'<title>{title}</title>\n'
               '<desc>Original naturalistic vector illustration; transparent background.</desc>\n'
               + artwork + '\n</svg>\n')
        ET.fromstring(svg)
        destination = output_dir / f"{item_id}.svg"
        with destination.open("w", encoding="utf-8", newline="\n") as stream:
            stream.write(svg)
        results.append(destination)
    return results


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, default=root / "docs/design/aquarium-realistic-expansion-plan.json")
    parser.add_argument("--output", type=Path, default=root / "src/DeskBox/Assets/Aquarium/Svg")
    args = parser.parse_args()
    plan = json.loads(args.plan.read_text(encoding="utf-8"))
    written = generate(plan["fish"], args.output)
    print(f"Generated and XML-validated {len(written)} fish SVG sources.")
