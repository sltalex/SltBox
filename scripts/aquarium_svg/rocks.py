"""Deterministic, original vector hardscape artwork for the aquarium.

The shapes are geological illustrations, not photographs. No bitmap, font,
filter or external resource is required to render the generated SVGs.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import random
from pathlib import Path
from xml.etree import ElementTree
from xml.sax.saxutils import escape


PALETTES = {
    "granite": ("#d8cfc0", "#91887d", "#49494a"),
    "slate": ("#879799", "#535f67", "#242e36"),
    "lava": ("#82766c", "#4d4640", "#222323"),
    "basalt": ("#8a9798", "#4e5a61", "#252f35"),
    "sandstone": ("#dfbf85", "#aa7e4e", "#604831"),
    "limestone": ("#e1deca", "#a6a58f", "#62685c"),
    "dragon": ("#c8ac79", "#87704a", "#4d4932"),
    "seiryu": ("#aab2b1", "#657575", "#354549"),
    "calcite": ("#f7eee1", "#c4c6bd", "#828f91"),
    "river": ("#b7b4a2", "#7b8074", "#4b5554"),
    "black": ("#7d8689", "#41494b", "#222a2d"),
    "white": ("#f4f1e5", "#cfcdc0", "#939c95"),
    "red-lava": ("#bc8068", "#854f3f", "#443533"),
    "coral": ("#ede1c9", "#c1b398", "#787969"),
    "moss": ("#b2b391", "#72775e", "#3d4d42"),
    "pumice": ("#e0ddce", "#b7af99", "#7b796b"),
    "wood": ("#cfa778", "#93714e", "#503e32"),
    "quartz": ("#e9e6df", "#b1bec0", "#677f8b"),
    "gneiss": ("#c4b7a5", "#857f7b", "#454e54"),
    "marble": ("#e6e5d7", "#b7c0b5", "#727e79"),
    "tuff": ("#c6bca2", "#958a72", "#585e50"),
    "shale": ("#afa38c", "#79776b", "#454d46"),
    "conglomerate": ("#c4ae91", "#8c7b65", "#52574d"),
    "chert": ("#bdafa2", "#8d8279", "#494d4f"),
    "obsidian": ("#84939b", "#394b55", "#17252e"),
    "schist": ("#9dafbe", "#687e90", "#354b61"),
    "greenstone": ("#a8b7a3", "#6c8773", "#36594c"),
    "serpentine": ("#bfbc8a", "#879262", "#3f6250"),
}


def number(value):
    return f"{value:.2f}".rstrip("0").rstrip(".")


def closed_path(points):
    """Quadratic corner interpolation keeps chipped edges gently worn."""
    midpoint = lambda a, b: ((a[0] + b[0]) / 2, (a[1] + b[1]) / 2)
    start = midpoint(points[-1], points[0])
    result = [f"M{number(start[0])} {number(start[1])}"]
    for i, p in enumerate(points):
        end = midpoint(p, points[(i + 1) % len(points)])
        result.append(f"Q{number(p[0])} {number(p[1])} {number(end[0])} {number(end[1])}")
    return " ".join(result) + "Z"


def roughen(points, rng, scale=4.0):
    result = []
    for i, p in enumerate(points):
        q = points[(i + 1) % len(points)]
        distance = math.dist(p, q)
        subdivisions = max(1, int(distance / 15))
        for j in range(subdivisions):
            t = j / subdivisions
            result.append((p[0] + (q[0] - p[0]) * t + rng.uniform(-scale, scale),
                           p[1] + (q[1] - p[1]) * t + rng.uniform(-scale, scale)))
    return result


def oval(cx, cy, rx, ry, rng, rotation=0, jagged=0.035):
    points = []
    for i in range(28):
        a = i * math.tau / 28
        r = 1 + rng.uniform(-jagged, jagged)
        x, y = math.cos(a) * rx * r, math.sin(a) * ry * r
        points.append((cx + x * math.cos(rotation) - y * math.sin(rotation),
                       cy + x * math.sin(rotation) + y * math.cos(rotation)))
    return points


class RockDrawing:
    def __init__(self, identity):
        self.rng = random.Random(int(hashlib.sha256(identity.encode()).hexdigest()[:16], 16))
        self.defs = []
        self.parts = []
        self.index = 0

    def stone(self, points, material="granite", holes=None, smooth=False, texture=1):
        rng = self.rng
        self.index += 1
        key = f"r{self.index}"
        light, mid, dark = PALETTES[material]
        path = closed_path(points if smooth else roughen(points, rng))
        for hole in holes or []:
            path += " " + closed_path(roughen(hole, rng, 1.1))
        xs, ys = zip(*points)
        x0, x1, y0, y1 = min(xs), max(xs), min(ys), max(ys)
        width, height = x1 - x0, y1 - y0
        self.defs.append(f'<radialGradient id="{key}g" cx=".25" cy=".12" r=".95" fx=".2" fy=".05"><stop stop-color="{light}"/><stop offset=".43" stop-color="{mid}"/><stop offset="1" stop-color="{dark}"/></radialGradient>')
        self.defs.append(f'<linearGradient id="{key}shade" x1="0" y1="0" x2="1" y2=".6"><stop stop-color="{light}" stop-opacity=".25"/><stop offset=".5" stop-color="{dark}" stop-opacity="0"/><stop offset="1" stop-color="{dark}" stop-opacity=".38"/></linearGradient>')
        for suffix, color in (("mineral", light), ("weather", dark)):
            self.defs.append(f'<radialGradient id="{key}{suffix}"><stop stop-color="{color}" stop-opacity=".32"/><stop offset=".55" stop-color="{color}" stop-opacity=".16"/><stop offset="1" stop-color="{color}" stop-opacity="0"/></radialGradient>')
        self.defs.append(f'<clipPath id="{key}clip"><path d="{path}" clip-rule="evenodd"/></clipPath>')
        self.parts.append(f'<path d="{path}" fill="url(#{key}g)" fill-rule="evenodd"/>')
        self.parts.append(f'<g clip-path="url(#{key}clip)">')
        # Mineral mottling uses small gradient-filled vector islands rather than
        # a procedural filter; it remains supported by the Windows SVG decoder.
        for _ in range(max(18, int(width * height / (1400 if smooth else 550)))):
            x, y = rng.uniform(x0, x1), rng.uniform(y0, y1)
            rx, ry = rng.uniform(5, 24), rng.uniform(4, 18)
            d = closed_path(oval(x, y, rx, ry, rng, rng.uniform(-1, 1), .35))
            self.parts.append(f'<path d="{d}" fill="url(#{key}{rng.choice(("mineral", "weather"))})" opacity="{number(.45 if smooth else .8)}"/>')
        if not smooth:
            # Overlapping weathered folds have continuous shading, not flat triangular facets.
            for j in range(8):
                x = x0 + width * (j / 7) + rng.uniform(-15, 15)
                drift = rng.uniform(-width * .22, width * .22)
                d = f"M{number(x)} {number(y0 - 10)} C{number(x + drift)} {number(y0 + height * .3)} {number(x - drift * .3)} {number(y0 + height * .7)} {number(x + drift)} {number(y1 + 10)} L{number(x + width * .25)} {number(y1 + 10)} Q{number(x + width * .09)} {number(y0 + height * .5)} {number(x + width * .11)} {number(y0 - 10)}Z"
                self.parts.append(f'<path d="{d}" fill="url(#{key}shade)" opacity=".65"/>')
        # Fine mineral grains follow the volume lighting. Small footprints do not get
        # as many particles as full-sized rocks, keeping every generated file bounded.
        grain_count = min(2200, max(50, int(width * height / 65 * texture)))
        for _ in range(grain_count):
            x, y = rng.uniform(x0, x1), rng.uniform(y0, y1)
            radius = rng.uniform(.25, 1.25) * (1.3 if material == "granite" else .8)
            color = rng.choice((light, light, dark, mid))
            opacity = rng.uniform(.12, .48) * (.65 if smooth else 1)
            self.parts.append(f'<ellipse cx="{number(x)}" cy="{number(y)}" rx="{number(radius)}" ry="{number(radius * rng.uniform(.4, 1.4))}" fill="{color}" opacity="{number(opacity)}"/>')
        if material in ("sandstone", "slate", "shale", "gneiss", "schist", "wood"):
            self.strata(x0, y0, width, height, material)
        if material in ("seiryu", "marble", "quartz", "serpentine", "greenstone", "calcite"):
            self.veins(x0, y0, width, height, light, dark, material)
        if material in ("lava", "red-lava", "coral", "pumice", "dragon", "tuff"):
            self.pores(x0, y0, width, height, light, dark, material)
        if material == "conglomerate":
            for _ in range(int(width * height / 600)):
                x, y = rng.uniform(x0, x1), rng.uniform(y0, y1)
                d = closed_path(oval(x, y, rng.uniform(4, 13), rng.uniform(3, 9), rng))
                self.parts.append(f'<path d="{d}" fill="{rng.choice((light, dark, "#a7a795", "#847e72"))}" opacity=".6" stroke="{dark}" stroke-width=".6"/>')
        if material == "obsidian":
            for i in range(9):
                x = x0 + width * rng.uniform(.15, .9)
                y = y0 + height * rng.uniform(.1, .8)
                self.parts.append(f'<path d="M{number(x-40)} {number(y+40)} Q{number(x-25)} {number(y-30)} {number(x+45)} {number(y-50)} Q{number(x+15)} {number(y-2)} {number(x+34)} {number(y+60)}" fill="none" stroke="{light}" stroke-width="{number(rng.uniform(.7, 2.3))}" opacity=".18"/>')
        if not smooth:
            self.cracks(x0, y0, width, height, light, dark, material)
        if material == "moss":
            for _ in range(350):
                x = rng.uniform(x0, x1)
                y = y0 + height * rng.betavariate(1.2, 3.5)
                r = rng.uniform(.7, 2.4)
                self.parts.append(f'<path d="M{number(x-r)} {number(y+r)}q{number(r)} {number(-r*2)} {number(r*2)} 0" fill="none" stroke="{rng.choice(("#4c6540", "#76904d", "#a2ad67", "#324a35"))}" stroke-width="{number(r*.8)}" opacity=".75"/>')
        self.parts.append('</g>')

    def cracks(self, x0, y0, w, h, light, dark, material):
        rng = self.rng
        for _ in range(max(3, int(w * h / 11000))):
            x, y = rng.uniform(x0, x0 + w), rng.uniform(y0, y0 + h * .65)
            path = f"M{number(x)} {number(y)}"
            for _ in range(rng.randint(3, 6)):
                x += rng.uniform(-w * .09, w * .08)
                y += rng.uniform(h * .015, h * .09)
                path += f"l{number(rng.uniform(-w * .06, w * .06))} {number(rng.uniform(h * .015, h * .05))}"
            self.parts.append(f'<path d="{path}" fill="none" stroke="{dark}" stroke-width="{number(rng.uniform(.5, 1.9))}" opacity=".55"/>')
            self.parts.append(f'<path d="{path}" transform="translate(-1,-1)" fill="none" stroke="{light}" stroke-width=".55" opacity=".4"/>')

    def strata(self, x0, y0, w, h, material):
        rng = self.rng
        light, mid, dark = PALETTES[material]
        count = 36 if material in ("shale", "slate", "schist") else 22
        vertical = material == "wood"
        for i in range(count):
            v = i / count
            bend = rng.uniform(-h * .06, h * .09)
            y = y0 + h * v
            d = f"M{number(x0-10)} {number(y)} C{number(x0+w*.28)} {number(y+bend)} {number(x0+w*.58)} {number(y-bend)} {number(x0+w+10)} {number(y+h*.1)}"
            if vertical:
                x = x0 + w * v
                d = f"M{number(x)} {number(y0-10)} C{number(x+bend)} {number(y0+h*.35)} {number(x-bend)} {number(y0+h*.6)} {number(x+w*.12)} {number(y0+h+10)}"
            self.parts.append(f'<path d="{d}" fill="none" stroke="{light if i % 3 == 0 else dark}" stroke-width="{number(rng.uniform(.5, 2.8))}" opacity="{number(rng.uniform(.18, .5))}"/>')

    def veins(self, x0, y0, w, h, light, dark, material):
        rng = self.rng
        for _ in range(12 if material == "marble" else 7):
            x, y = x0 + rng.uniform(-w * .1, w * .7), y0 + rng.uniform(0, h * .9)
            parts = [f"M{number(x)} {number(y)}"]
            for _ in range(9):
                x += rng.uniform(w * .03, w * .13)
                y += rng.uniform(-h * .10, h * .12)
                parts.append(f"L{number(x)} {number(y)}")
            d = " ".join(parts)
            self.parts.append(f'<path d="{d}" fill="none" stroke="{light if material != "marble" else dark}" stroke-width="{number(rng.uniform(.8, 3.3))}" stroke-linejoin="round" opacity=".55"/>')
            self.parts.append(f'<path d="{d}" transform="translate(1.1,1.4)" fill="none" stroke="{dark}" stroke-width=".45" opacity=".25"/>')

    def pores(self, x0, y0, w, h, light, dark, material):
        rng = self.rng
        density = 400 if material in ("coral", "pumice") else 950
        for _ in range(max(10, int(w * h / density))):
            x, y = rng.uniform(x0, x0 + w), rng.uniform(y0, y0 + h)
            rx = rng.uniform(1.4, 4.5) if material in ("coral", "pumice") else rng.uniform(2, 8)
            ry = rx * (rng.uniform(2, 4) if material == "dragon" else rng.uniform(.5, 1.2))
            p = oval(x, y, rx, ry, rng, jagged=.2)
            d = closed_path(p)
            self.parts.append(f'<path d="{d}" fill="{dark}" opacity="{number(rng.uniform(.3,.65))}"/>')
            self.parts.append(f'<path d="M{number(x-rx*.7)} {number(y+ry*.5)}Q{number(x)} {number(y+ry*1.3)} {number(x+rx*.8)} {number(y+ry*.4)}" fill="none" stroke="{light}" stroke-width=".9" opacity=".5"/>')

    def rounded(self, cx, cy, rx, ry, material, rotation=0):
        self.stone(oval(cx, cy, rx, ry, self.rng, rotation, .045), material, smooth=True)

    def shard(self, x, y, w, h, material, tilt=0):
        # A splintered projecting rock, with a fully individual serrated crest.
        self.stone([(x-w*.5,y),(x-w*.39+tilt,y-h*.59),(x-w*.2+tilt,y-h*.81),
                    (x-w*.05+tilt,y-h),(x+w*.15+tilt,y-h*.89),(x+w*.28+tilt,y-h*.52),
                    (x+w*.48,y-h*.18),(x+w*.5,y),(x+w*.1,y+8)], material)

    def render(self, entry):
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 450" width="600" height="450">\n' + f'<title>{escape(entry["name"])}</title><desc>Original naturalistic vector geological illustration, {escape(entry["id"])}. Transparent background.</desc>\n<defs>' + "\n".join(self.defs) + '</defs>\n' + "\n".join(self.parts) + '\n</svg>\n'


def build(entry):
    a = RockDrawing(entry["id"])
    k = entry["id"]
    # Every layout is independently authored: stacked slabs, caves, eroded
    # arches, river stones and mineral outcrops do not share one silhouette.
    if k == "reef-rock":
        a.stone([(90,390),(77,312),(124,265),(152,226),(181,244),(217,139),(284,109),(318,138),(351,119),(390,198),(443,184),(469,237),(513,275),(526,368),(475,402),(225,411)],"coral")
    elif k in ("rock-arch", "coral-arch"):
        coral = k == "coral-arch"
        a.stone([(55,399),(69,284),(113,206),(133,137),(211,88),(294,70),(387,91),(445,139),(478,220),(537,385),(460,401),(418,375),(411,294),(382,213),(339,178),(273,168),(221,211),(180,291),(165,393)],"coral" if coral else "limestone")
        if coral:
            a.shard(100,389,80,162,"coral",-18)
            a.shard(483,389,84,144,"coral",17)
    elif k == "granite-rock":
        a.stone([(78,351),(103,233),(164,159),(281,119),(382,153),(445,212),(500,315),(485,378),(399,408),(208,404),(113,385)],"granite")
    elif k in ("slate-rock", "rock-plate", "shale-rock"):
        count = 7 if k == "slate-rock" else 3 if k == "rock-plate" else 12
        for i in range(count):
            y = 395 - i * (22 if count != 3 else 36)
            dx = 15 * math.sin(i * 2)
            extent = 210 - i * 9
            a.stone([(300-extent+dx,y-19),(287-extent+dx,y-32),(365+dx,y-66),(300+extent+dx,y-33),(302+extent+dx,y-7),(320+dx,y+5),(300-extent+dx,y+3)],"shale" if k == "shale-rock" else "slate")
    elif k in ("lava-rock", "red-lava-rock", "pumice-rock"):
        a.stone([(86,365),(97,272),(141,252),(143,174),(205,142),(235,158),(269,101),(327,113),(369,155),(419,138),(459,198),(457,274),(514,307),(504,384),(413,408),(253,401),(164,413)],"lava" if k == "lava-rock" else "red-lava" if k == "red-lava-rock" else "pumice")
    elif k == "basalt-rock":
        a.shard(239,402,255,300,"basalt",-12)
        a.shard(413,406,187,206,"basalt",-32)
        a.shard(109,407,119,126,"basalt",18)
    elif k == "sandstone-rock":
        a.stone([(75,381),(95,326),(126,306),(114,263),(156,235),(150,188),(201,151),(210,112),(303,94),(365,119),(376,178),(432,212),(450,289),(509,339),(502,394),(241,412)],"sandstone")
    elif k == "limestone-rock":
        a.stone([(81,376),(89,279),(151,197),(211,158),(321,162),(402,203),(451,270),(500,316),(496,391),(393,411),(191,406)],"limestone",[oval(212,268,33,22,a.rng),oval(373,322,19,35,a.rng)])
    elif k == "dragon-stone":
        a.shard(269,407,258,337,"dragon",32)
        a.shard(428,404,155,188,"dragon",-22)
        a.shard(122,406,129,161,"dragon",34)
    elif k == "seiryu-stone":
        a.shard(331,405,297,351,"seiryu",-67)
        a.shard(143,408,166,196,"seiryu",26)
        a.shard(468,405,126,121,"seiryu",-17)
    elif k in ("calcite-rock", "quartz-rock"):
        material = "calcite" if k == "calcite-rock" else "quartz"
        for x,y,w,h,t in [(175,382,126,238,-12),(369,399,145,281,15),(284,409,152,337,-35),(448,412,121,151,30),(122,413,116,102,0)]:
            a.stone([(x-w*.5,y),(x-w*.44+t,y-h*.76),(x+t,y-h),(x+w*.32+t,y-h*.85),(x+w*.5,y-22),(x+w*.21,y+6)],material)
    elif k in ("pagoda-stone", "terraced-rock"):
        for i in range(9):
            y = 403-i*31
            radius = 210-i*17
            shift = math.sin(i*.9)*18
            a.stone([(300-radius+shift,y),(285-radius+shift,y-17),(294-radius*.6+shift,y-37),(300+radius*.72+shift,y-45),(315+radius+shift,y-22),(300+radius+shift,y+1),(323+shift,y+13)],"sandstone" if k == "pagoda-stone" else "shale")
    elif k in ("river-pebble", "black-pebble", "white-pebble", "cobblestone-rock"):
        material = {"river-pebble":"river","black-pebble":"black","white-pebble":"white","cobblestone-rock":"granite"}[k]
        if k == "cobblestone-rock":
            for x,y,rx,ry,rot in [(194,235,83,57,-.2),(351,223,103,64,.15),(434,319,97,75,-.1),(267,341,120,72,.08),(111,349,66,46,.2)]:
                a.rounded(x,y,rx,ry,material,rot)
        elif k == "black-pebble":
            a.rounded(296,338,195,75,material,-.08)
            a.rounded(290,245,147,51,material,.08)
            a.rounded(302,174,90,36,material,-.07)
        elif k == "white-pebble":
            a.rounded(350,306,145,104,material,-.15)
            a.rounded(169,356,98,59,material,.22)
        else:
            a.rounded(288,300,190,116,material,-.13)
    elif k == "coral-rock":
        a.stone([(74,385),(86,313),(124,289),(104,217),(148,170),(201,184),(221,126),(275,136),(295,76),(342,94),(355,157),(410,145),(444,198),(425,257),(486,263),(515,313),(492,396),(357,411),(178,406)],"coral",[oval(254,259,25,40,a.rng),oval(369,289,30,22,a.rng)])
    elif k in ("reef-rubble", "rubble-pile"):
        mat = "coral" if k == "reef-rubble" else "granite"
        for i in range(19):
            row = i//6
            x = 90+(i%6)*77+a.rng.uniform(-18,18)
            y = 290+row*36+a.rng.uniform(-8,16)
            w = a.rng.uniform(55,105)
            a.shard(x,y,w,a.rng.uniform(53,104),mat,a.rng.uniform(-15,15))
    elif k in ("cave-rock", "tunnel-rock", "pagoda-cave"):
        mat = "sandstone" if k == "pagoda-cave" else "seiryu" if k == "cave-rock" else "limestone"
        outer = [(59,390),(83,257),(143,174),(192,132),(281,109),(379,130),(462,202),(521,321),(533,397),(451,414),(135,414)]
        holes = [oval(299,290,107 if k != "tunnel-rock" else 152,84 if k != "tunnel-rock" else 69,a.rng,-.05)]
        a.stone(outer,mat,holes)
        if k == "pagoda-cave":
            # Narrow cap stones preserve the transparent cavity below.
            for i in range(3):
                y = 175-i*31
                half = 187-i*29
                a.stone([(300-half,y),(300-half-13,y-14),(300-half*.6,y-34),(300+half*.75,y-29),(300+half+10,y-7),(300+half,y+7)],mat)
    elif k == "stacked-rock":
        for x,y,rx,ry,r in [(298,353,193,59,.02),(286,258,157,48,-.06),(306,177,113,41,.08),(294,108,67,30,-.04)]:
            a.rounded(x,y,rx,ry,"river",r)
    elif k == "mossy-rock":
        a.stone([(76,376),(100,265),(174,195),(259,178),(322,196),(392,162),(451,207),(473,285),(518,342),(495,404),(300,411),(148,402)],"moss")
    elif k == "cliff-rock":
        a.stone([(105,409),(119,99),(175,77),(218,91),(261,74),(317,86),(346,128),(385,110),(445,143),(459,289),(489,405),(353,415)],"shale")
    elif k == "overhang-rock":
        a.stone([(93,401),(119,326),(152,285),(177,220),(141,202),(109,159),(116,116),(205,91),(327,77),(423,105),(486,144),(478,183),(427,207),(320,216),(280,237),(288,315),(320,405)],"sandstone")
    elif k == "spire-rock":
        a.shard(310,410,224,365,"seiryu",-25)
        a.shard(180,412,122,154,"seiryu",25)
        a.shard(432,412,109,112,"seiryu",-15)
    elif k == "boulder-cluster":
        a.rounded(330,230,125,139,"granite",-.18)
        a.rounded(161,326,112,82,"granite",-.12)
        a.rounded(440,336,109,76,"granite",.23)
        a.rounded(298,368,101,47,"granite",.02)
    elif k == "rock-shelf":
        a.shard(197,410,122,247,"slate",-13)
        a.shard(397,409,121,198,"slate",26)
        a.stone([(66,204),(110,174),(261,151),(468,160),(537,185),(527,215),(409,235),(246,230),(101,242)],"slate")
    elif k == "canyon-rock":
        a.stone([(66,411),(82,319),(105,281),(116,209),(166,164),(218,152),(240,215),(217,259),(232,307),(244,347),(223,414)],"sandstone")
        a.stone([(346,412),(361,340),(348,285),(379,233),(372,165),(424,98),(470,113),(496,182),(490,263),(531,331),(534,410)],"sandstone")
    elif k == "mesa-rock":
        a.stone([(69,405),(91,361),(109,293),(148,257),(160,160),(174,142),(421,142),(447,170),(445,254),(477,302),(496,360),(534,401),(363,414),(210,411)],"sandstone")
    elif k == "petrified-wood":
        a.stone([(147,411),(165,207),(189,84),(249,69),(279,92),(307,74),(351,103),(380,238),(427,387),(405,414)],"wood")
        a.stone([(400,407),(405,328),(440,299),(471,311),(506,399),(484,414)],"wood")
    elif k == "gneiss-rock":
        a.stone([(76,376),(93,271),(149,220),(183,121),(256,100),(343,149),(395,196),(463,229),(514,355),(485,407),(302,411)],"gneiss")
    elif k == "marble-rock":
        a.stone([(101,385),(91,273),(142,164),(227,140),(299,159),(343,116),(413,137),(468,239),(487,365),(430,409),(221,414)],"marble")
    elif k == "tuff-rock":
        a.stone([(81,398),(91,286),(128,269),(144,207),(210,194),(233,140),(312,135),(354,183),(411,165),(450,220),(491,239),(523,378),(452,413),(259,406)],"tuff")
    elif k == "conglomerate-rock":
        a.stone([(89,372),(113,247),(169,200),(223,149),(309,141),(380,172),(445,225),(487,320),(493,390),(407,414),(230,404)],"conglomerate")
    elif k == "chert-rock":
        a.stone([(112,389),(97,279),(142,200),(209,184),(230,131),(323,150),(379,114),(438,191),(455,277),(499,348),(471,410),(271,412)],"chert")
    elif k == "obsidian-rock":
        a.shard(329,412,286,337,"obsidian",-63)
        a.shard(142,410,170,187,"obsidian",16)
        a.shard(477,411,102,111,"obsidian",-24)
    elif k == "blue-schist":
        a.shard(289,412,287,313,"schist",-47)
        a.shard(444,412,176,169,"schist",-34)
    elif k == "greenstone-rock":
        a.stone([(76,383),(107,254),(170,218),(223,142),(303,113),(353,170),(425,160),(455,242),(514,343),(482,405),(328,411),(190,403)],"greenstone")
    elif k == "serpentine-rock":
        a.stone([(90,382),(106,248),(169,187),(260,144),(349,167),(416,221),(474,240),(511,347),(487,408),(303,414),(167,403)],"serpentine")
    elif k == "columnar-basalt":
        for x,y,w,h in [(150,388,80,201),(387,395,86,259),(226,405,88,324),(309,413,88,361),(458,411,74,159),(98,413,64,96)]:
            a.stone([(x-w*.5,y),(x-w*.5,y-h+19),(x,y-h),(x+w*.5,y-h+17),(x+w*.5,y),(x,y+7)],"basalt")
    elif k == "reef-boulder":
        a.stone([(72,367),(83,284),(122,202),(189,140),(281,120),(364,141),(443,196),(503,279),(526,361),(495,403),(354,413),(171,407)],"coral",[oval(176,290,20,17,a.rng),oval(389,259,27,21,a.rng)])
    else:
        raise ValueError(f"No authored rock geometry for {k}")
    return a.render(entry)


def generate(entries, output_dir):
    output_dir = Path(output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    paths = []
    for entry in entries:
        content = build(entry)
        ElementTree.fromstring(content)
        path = output_dir / f'{entry["id"]}.svg'
        with path.open("w", encoding="utf-8", newline="\n") as target:
            target.write(content)
        paths.append(path)
    return paths


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, default=root / "docs/design/aquarium-realistic-expansion-plan.json")
    parser.add_argument("--output", type=Path, default=root / "src/DeskBox/Assets/Aquarium/Svg")
    args = parser.parse_args()
    entries = json.loads(args.plan.read_text(encoding="utf-8"))["rocks"]
    paths = generate(entries, args.output)
    print(f"Generated and XML-validated {len(paths)} original rock SVGs in {args.output}")
