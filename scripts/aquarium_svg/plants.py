"""Original botanical SVG illustrations for the aquarium's 50 plant species.

Only the Python standard library is required. Each species has its own growth
habit, leaf arrangement, proportions and surface detail; no photographs, fonts,
external resources or SVG filters are used. The transparent canvases have a
stable planting line and are suitable for deterministic offline rasterization.
"""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import math
from pathlib import Path
import random
import re
import xml.etree.ElementTree as ET


GREEN = ("#183e25", "#3e7835", "#8baf54", "#c2d68b")
LIME = ("#25502a", "#599a42", "#a9cb6d", "#d3e6a1")
OLIVE = ("#303e24", "#657542", "#a0aa67", "#d2cf91")
BRONZE = ("#3d352d", "#6d6642", "#9f925e", "#cfbc82")
RED = ("#4b2430", "#8d3e4b", "#c77a6c", "#e7ac8c")
RUBY = ("#4c2537", "#953d58", "#c66c76", "#eab29c")
TEAL = ("#1b3c35", "#32654a", "#76956b", "#b0c69a")


def f(value: float) -> str:
    return f"{value:.2f}".rstrip("0").rstrip(".")


class BotanicalSvg:
    def __init__(self, entry: dict):
        self.entry = entry
        self.rng = random.Random(int.from_bytes(hashlib.sha256(entry["id"].encode()).digest()[:8], "big"))
        self.parts: list[str] = []
        self.defs: list[str] = []
        self.gradient_count = 0
        self.gradients: dict[tuple, str] = {}
        self.palette = GREEN

    def path(self, d: str, fill: str = "none", stroke: str | None = None,
             width: float = 1, opacity: float = 1, extra: str = "") -> None:
        d = re.sub(r"-?\d+\.\d+(?:e[+-]?\d+)?",lambda m:f(float(m.group())),d)
        attributes = f'd="{d}" fill="{fill}" opacity="{f(opacity)}"'
        if stroke:
            attributes += f' stroke="{stroke}" stroke-width="{f(width)}" stroke-linecap="round" stroke-linejoin="round"'
        self.parts.append(f"<path {attributes} {extra}/>")

    def ellipse(self, x: float, y: float, rx: float, ry: float, fill: str,
                opacity: float = 1, rotation: float = 0) -> None:
        self.parts.append(f'<ellipse cx="{f(x)}" cy="{f(y)}" rx="{f(rx)}" ry="{f(ry)}" '
                          f'fill="{fill}" opacity="{f(opacity)}" transform="rotate({f(rotation)} {f(x)} {f(y)})"/>')

    def gradient(self, palette: tuple = GREEN) -> str:
        if palette in self.gradients:
            return self.gradients[palette]
        ident = f"leaf-{self.gradient_count}"
        self.gradient_count += 1
        self.defs.append(f'<linearGradient id="{ident}" x1="0" y1="0" x2="0.85" y2="1">'
                         f'<stop offset="0" stop-color="{palette[2]}"/>'
                         f'<stop offset="0.34" stop-color="{palette[1]}"/>'
                         f'<stop offset="0.65" stop-color="{palette[2]}"/>'
                         f'<stop offset="1" stop-color="{palette[0]}"/></linearGradient>')
        reference=f"url(#{ident})"
        self.gradients[palette]=reference
        return reference

    def leaf(self, x: float, y: float, length: float, width: float, angle: float,
             shape: str = "lance", palette: tuple | None = None, veins: int = 5,
             opacity: float = 1, spots: bool = False) -> None:
        """Draw one asymmetrical, translucent leaf in its local botanical frame."""
        palette = palette or self.palette
        g = self.gradient(palette)
        bend = self.rng.uniform(-0.11, 0.11) * length
        l, w, b = length, width, bend
        self.parts.append(f'<g transform="translate({f(x)} {f(y)}) rotate({f(angle)})">')
        if shape == "round":
            d = f"M 0 0 C {-w*.8} {-l*.12} {-w*1.14} {-l*.7} {-w*.3} {-l*.95} C {w*.1} {-l*1.05} {w*.9} {-l*.94} {w} {-l*.55} C {w*1.14} {-l*.15} {w*.35} {-l*.04} 0 0 Z"
        elif shape in ("heart", "lotus"):
            d = f"M 0 0 C {-w*.24} {-l*.35} {-w*.93} {-l*.01} {-w} {-l*.48} C {-w*1.14} {-l*.91} {-w*.27} {-l*1.05} {b} {-l} C {w*.54} {-l*1.02} {w*1.1} {-l*.71} {w*.91} {-l*.37} C {w*.67} {-l*.01} {w*.14} {-l*.3} 0 0 Z"
        elif shape == "ruffled":
            points = [(0., 0.)]
            for i in range(1, 16):
                t = i / 16
                edge = w * math.sin(math.pi * t) ** .75 * (1 + .13 * math.sin(t * math.pi * 15))
                points.append((-edge + b * t, -l * t))
            points.append((b, -l))
            for i in range(15, 0, -1):
                t = i / 16
                edge = w * math.sin(math.pi * t) ** .75 * (1 + .12 * math.sin(t * math.pi * 15 + 1))
                points.append((edge + b * t, -l * t))
            d = "M " + " L ".join(f"{f(px)} {f(py)}" for px, py in points) + " Z"
        elif shape == "oval":
            d = f"M 0 0 C {-w*.8} {-l*.11} {-w*1.12} {-l*.62} {b-w*.35} {-l*.93} Q {b} {-l*1.05} {b+w*.38} {-l*.92} C {w*1.04} {-l*.6} {w*.83} {-l*.12} 0 0 Z"
        elif shape == "trilobe":
            d = f"M 0 0 Q {-w*.92} {-l*.04} {-w} {-l*.43} Q {-w*1.03} {-l*.67} {-w*.43} {-l*.6} Q {-w*.52} {-l*1.05} 0 {-l} Q {w*.48} {-l*1.04} {w*.4} {-l*.62} Q {w*1.12} {-l*.78} {w} {-l*.42} Q {w*.8} {-l*.09} 0 0 Z"
        else:
            d = f"M 0 0 C {-w*.75} {-l*.19} {-w*.96} {-l*.55} {b} {-l} C {w*.76+b} {-l*.62} {w*.83} {-l*.2} 0 0 Z"
        self.path(d, g, opacity=opacity)
        self.path(f"M 0 0 Q {f(b*.25)} {f(-l*.56)} {f(b)} {f(-l*.97)}", stroke=palette[3], width=max(.4, w*.025), opacity=.55 * opacity)
        for i in range(1, veins + 1):
            t = i / (veins + 1)
            side_w = w * math.sin(math.pi * t) * .82
            for side in (-1, 1):
                self.path(f"M {f(b*t*t)} {f(-l*t)} Q {f(side*side_w*.48+b*t)} {f(-l*(t+.04))} {f(side*side_w+b*t)} {f(-l*min(t+.12,.98))}",
                          stroke=palette[3], width=.45, opacity=.3 * opacity)
        if spots:
            for _ in range(max(8, int(l / 4))):
                t = self.rng.uniform(.18, .87)
                sx = self.rng.uniform(-.74, .74) * w * math.sin(math.pi * t) + b * t
                self.ellipse(sx, -l*t, self.rng.uniform(.7, 2.3), self.rng.uniform(.5, 1.6), palette[0], .34)
        self.parts.append("</g>")

    def stem(self, x: float, y: float, tx: float, ty: float, width: float = 2.2,
             color: str | None = None) -> None:
        self.path(f"M {f(x)} {f(y)} C {f(x+(tx-x)*.22)} {f(y+(ty-y)*.4)} {f(tx-(tx-x)*.15)} {f(ty+(y-ty)*.3)} {f(tx)} {f(ty)}",
                  stroke=color or self.palette[1], width=width)
        if width > 2:
            self.path(f"M {f(x-.45)} {f(y)} Q {f(x+(tx-x)*.4-.45)} {f((y+ty)*.5)} {f(tx-.4)} {f(ty)}",
                      stroke=self.palette[3], width=.6, opacity=.36)

    def roots(self, x: float, y: float, count: int = 15, spread: float = 35,
              length: float = 20, fine: bool = False) -> None:
        for i in range(count):
            dx = self.rng.uniform(-spread, spread)
            ly = self.rng.uniform(length*.35, length)
            end_y = min(487, y + ly)
            self.path(f"M {f(x+self.rng.uniform(-5,5))} {f(y)} Q {f(x+dx*.48)} {f(y+ly*.4)} {f(x+dx)} {f(end_y)}",
                      stroke="#927955" if i % 3 else "#c8b08a", width=.45 if fine else .8, opacity=.6)
            if i % 2 == 0:
                self.path(f"M {f(x+dx*.6)} {f(y+ly*.62)} l {f(dx*.25+2)} {f(ly*.12)}", stroke="#ae9a73", width=.36, opacity=.55)

    def document(self) -> str:
        name = html.escape(self.entry["name"])
        scientific = html.escape(self.entry["scientific_name"])
        return ('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="500" viewBox="0 0 400 500" '
                f'role="img" aria-label="{name}">\n<title>{name} — {scientific}</title>\n'
                '<desc>Original naturalistic botanical vector study. Transparent background; original geometry and gradients.</desc>\n'
                '<defs>\n' + '\n'.join(self.defs) + '\n</defs>\n' + '\n'.join(self.parts) + '\n</svg>\n')


def ribbon_cluster(s: BotanicalSvg, count: int, height: float, width: float,
                   curl: float = 0, clumps: int = 1) -> None:
    for c in range(clumps):
        base_x = 200 + (c - (clumps-1)/2) * (200 / max(1, clumps))
        base_y = 463 + s.rng.uniform(-5, 3)
        s.roots(base_x, base_y, 12 if clumps == 1 else 6, 25, 17)
        for i in range(count):
            bx = base_x + s.rng.uniform(-19, 19)
            by = base_y + s.rng.uniform(-4, 1)
            h = height * s.rng.uniform(.54, 1)
            side = (i / max(1,count-1) - .5)
            tx = max(25, min(375, bx + side * min(240, height*.7) + s.rng.uniform(-15,15)))
            ty = by-h
            w = width * s.rng.uniform(.62,1.12)
            cx = (bx+tx)/2 + curl * math.sin(i*2.7)
            d = f"M {f(bx)} {f(by)} C {f(bx-w+curl*.4)} {f(by-h*.36)} {f(cx-w)} {f(ty+h*.2)} {f(tx)} {f(ty)} C {f(cx+w)} {f(ty+h*.25)} {f(bx+w+curl*.4)} {f(by-h*.32)} {f(bx+w*.4)} {f(by)} Z"
            s.path(d,s.gradient(s.palette),opacity=s.rng.uniform(.85,1))
            s.path(f"M {f(bx+w*.16)} {f(by-4)} C {f(bx+curl*.4)} {f(by-h*.35)} {f(cx)} {f(ty+h*.22)} {f(tx)} {f(ty+2)}",
                   stroke=s.palette[3],width=.55,opacity=.4)
            if width>8:
                s.path(f"M {f(bx-w*.2)} {f(by-7)} Q {f(cx-w*.24)} {f(by-h*.58)} {f(tx)} {f(ty+4)}",stroke=s.palette[0],width=.5,opacity=.28)


def rosette(s: BotanicalSvg, count: int, length: float, width: float, shape: str,
            height: float = 370, spotted: bool = False, rhizome: bool = False) -> None:
    if rhizome:
        s.path("M 153 459 C 170 447 218 456 248 445",stroke="#65704a",width=8)
    s.roots(200,462,25,53,23)
    leaves=[]
    for i in range(count):
        angle = (i/(count-1)-.5)*132 + s.rng.uniform(-7,7)
        ll = length*s.rng.uniform(.66,1.02)
        petiole = max(13,height-ll)*s.rng.uniform(.38,.9)
        # Keep long outer leaves on the canvas without clipping or stretching
        # their anatomy. Tall species have a naturally more upright crown.
        lateral_budget = max(35, 168-width*1.3-ll*.11)
        max_angle = math.degrees(math.asin(min(.95,lateral_budget/(ll+petiole*.52))))
        angle = max(-max_angle,min(max_angle,angle))
        angle_rad = math.radians(angle)
        bx = 200+math.sin(angle_rad)*petiole*.52
        by = 460-math.cos(angle_rad)*petiole
        leaves.append((by,angle,ll,bx,by))
    for _,angle,ll,bx,by in sorted(leaves):
        s.stem(200+s.rng.uniform(-8,8),460,bx,by,2.3)
        s.leaf(bx,by,ll,width*s.rng.uniform(.77,1.1),angle,shape,veins=7,spots=spotted)


def stem_bunch(s: BotanicalSvg, stems: int, height: float, leaf_length: float,
               leaf_width: float, shape: str = "lance", nodes: int = 7,
               whorl: int = 2, red_tops: bool = False, creeping: bool = False) -> None:
    for j in range(stems):
        bx=90+j*220/max(1,stems-1)+s.rng.uniform(-8,8)
        h=height*s.rng.uniform(.65,1)
        by=463+s.rng.uniform(-5,2)
        tx=bx+s.rng.uniform(-25,25)
        s.roots(bx,by,6,18,17)
        s.stem(bx,by,tx,by-h,2.7)
        for k in range(nodes):
            t=.08+k*.85/max(1,nodes-1)
            y=by-h*t
            x=bx+(tx-bx)*t
            growth=1-.48*t
            palette=RED if red_tops and t>.65 else s.palette
            for side in range(whorl):
                a=(-1 if side%2==0 else 1)*(42+side//2*26+s.rng.uniform(-10,10))
                leaf_scale=growth*(.76 if side>1 else 1)
                s.leaf(x,y,leaf_length*leaf_scale,leaf_width*leaf_scale,a,shape,palette,veins=3 if leaf_width<13 else 5)
            if creeping:
                s.stem(x,y,x+s.rng.uniform(-32,32),min(474,y+35),.7,"#a9a36f")
        s.leaf(tx,by-h,leaf_length*.35,leaf_width*.4,0,shape,palette,veins=3)


def finely_divided(s: BotanicalSvg, stems: int, height: float, levels: int,
                   reach: float, pattern: str) -> None:
    for j in range(stems):
        bx=90+j*220/max(1,stems-1)+s.rng.uniform(-10,10)
        by=460+s.rng.uniform(-4,4)
        h=height*s.rng.uniform(.68,1)
        tx=bx+s.rng.uniform(-16,16)
        s.roots(bx,by,5,12,20,True)
        s.stem(bx,by,tx,by-h,1.8)
        for k in range(levels):
            t=.1+k*.87/max(1,levels-1)
            x=bx+(tx-bx)*t
            y=by-h*t
            r=reach*(.99-.56*t)
            if pattern == "fan":
                for side in (-1,1):
                    for n in range(10):
                        a=math.radians(side*(19+n*7))
                        ex=x+math.sin(a)*r
                        ey=y-math.cos(a)*r
                        s.path(f"M {f(x)} {f(y)} Q {f(x+math.sin(a)*r*.35)} {f(y-r*.5)} {f(ex)} {f(ey)}",stroke=s.palette[1 if n%3 else 2],width=.8,opacity=.9)
                        for q in (.62,.81):
                            s.path(f"M {f(x+(ex-x)*q)} {f(y+(ey-y)*q)} l {f(side*4)} -5",stroke=s.palette[2],width=.5,opacity=.7)
            elif pattern in ("pinnate","soft"):
                for side in (-1,1):
                    ex=x+side*r
                    ey=y-r*.42
                    s.path(f"M {f(x)} {f(y)} Q {f(x+side*r*.55)} {f(y-r*.4)} {f(ex)} {f(ey)}",stroke=s.palette[1],width=.85)
                    for n in range(1,10):
                        u=n/11
                        lx=x+side*r*u
                        ly=y-r*.42*u
                        needle=r*.3*(1-u*.65)
                        s.leaf(lx,ly,needle,1.3 if pattern=="pinnate" else 2.0,side*(24+n*2),palette=s.palette,veins=0)
                        s.leaf(lx,ly,needle*.8,1.0,side*(121-n*2),palette=s.palette,veins=0,opacity=.9)
            else:
                rays=7 if pattern=="forked" else 10
                for n in range(rays):
                    a=-78+n*156/max(1,rays-1)+s.rng.uniform(-7,7)
                    ll=r*s.rng.uniform(.65,1)
                    s.leaf(x,y,ll,1.25 if pattern=="redneedle" else 1.7,a,palette=s.palette,veins=0)
                    if pattern=="forked":
                        ar=math.radians(a)
                        s.leaf(x+math.sin(ar)*ll*.5,y-math.cos(ar)*ll*.5,ll*.45,1,a+23,veins=0)


def moss(s: BotanicalSvg, habit: str) -> None:
    n={"java":35,"christmas":21,"fissidens":28,"riccia":48}[habit]
    for i in range(n):
        bx=s.rng.uniform(70,330)
        by=s.rng.uniform(434,468)
        h=s.rng.uniform(40,175 if habit=="christmas" else 115)
        bend=s.rng.uniform(-42,42)
        s.stem(bx,by,bx+bend,by-h,1.0)
        s.roots(bx,by,2,7,10,True)
        if habit=="riccia":
            def fork(x,y,length,angle,depth):
                rad=math.radians(angle)
                ex=x+math.sin(rad)*length
                ey=y-math.cos(rad)*length
                s.path(f"M {f(x)} {f(y)} L {f(ex)} {f(ey)}",stroke=s.palette[2 if depth%2 else 1],width=1.7+depth*.23)
                if depth:
                    fork(ex,ey,length*.62,angle-31,depth-1)
                    fork(ex,ey,length*.62,angle+34,depth-1)
            fork(bx,by,h*.34,s.rng.uniform(-30,30),3)
            continue
        nodes=10 if habit=="fissidens" else 8
        for k in range(nodes):
            t=.1+k*.85/nodes
            x=bx+bend*t
            y=by-h*t
            if habit=="christmas":
                reach=(1-t)*h*.38
                for side in (-1,1):
                    s.path(f"M {f(x)} {f(y)} L {f(x+side*reach)} {f(y-reach*.33)}",stroke=s.palette[1],width=.9)
                    for m in range(1,7):
                        u=m/7
                        s.leaf(x+side*reach*u,y-reach*.33*u,5.7,1.4,side*35,veins=0)
                        s.leaf(x+side*reach*u,y-reach*.33*u,4.4,1.1,side*127,veins=0)
            else:
                for side in (-1,1):
                    length=(13 if habit=="fissidens" else 9)*(1-t*.4)
                    s.leaf(x,y,length,2.8 if habit=="fissidens" else 1.25,side*(56 if habit=="fissidens" else 38),"lance",veins=0)


def floating(s: BotanicalSvg, kind: str) -> None:
    profiles={"duckweed":(26,16,9,"oval",110),"salvinia":(16,30,18,"oval",145),
              "frogbit":(8,65,37,"heart",235),"amazon":(11,51,29,"round",235),
              "lettuce":(12,91,29,"ruffled",220)}
    count,l,w,shape,root_length=profiles[kind]
    if kind=="lettuce":
        for j in range(24):
            sx=200+s.rng.uniform(-12,12)
            sy=274+s.rng.uniform(-4,6)
            ex=sx+s.rng.uniform(-42,42)
            ey=min(480,sy+s.rng.uniform(100,root_length))
            s.path(f"M {f(sx)} {f(sy)} C {f(sx-16)} {f(sy+70)} {f(ex+12)} {f(ey-50)} {f(ex)} {f(ey)}",stroke="#827c55",width=.6,opacity=.73)
            for t in (.35,.55,.7,.84):
                s.path(f"M {f(sx+(ex-sx)*t)} {f(sy+(ey-sy)*t)} l {f(s.rng.uniform(-13,13))} 16",stroke="#9e9870",width=.4,opacity=.55)
        for i in range(count):
            angle=-150+i*300/(count-1)
            s.leaf(200,276,l*s.rng.uniform(.8,1.1),w,angle,shape,veins=8)
        for i in range(6):
            s.leaf(200,269,58,20,-100+i*40,"ruffled",veins=7)
        return
    leaves=[]
    for j in range(count):
        x=s.rng.uniform(100,300)
        y=s.rng.uniform(275,355) if kind in ("duckweed","salvinia") else s.rng.uniform(235,294)
        length=l*s.rng.uniform(.8,1.15)
        leaves.append((y,x,length))
        s.roots(x,y,2 if kind=="duckweed" else 7,8 if kind=="duckweed" else 26,root_length,True)
    for y,x,length in sorted(leaves):
        s.leaf(x,y,length,w*s.rng.uniform(.8,1.05),s.rng.uniform(-65,65),shape,veins=4 if kind!="duckweed" else 1,spots=kind=="salvinia")


def lily(s: BotanicalSvg, kind: str) -> None:
    s.roots(200,461,24,36,24)
    if kind=="banana":
        for i in range(7):
            s.leaf(200,454,34+s.rng.uniform(-8,8),7,-105+i*35,"oval",OLIVE,veins=0)
    count=7 if kind=="tiger" else 5
    for i in range(count):
        angle=-69+i*138/(count-1)
        height=s.rng.uniform(160,300)
        x=200+math.sin(math.radians(angle))*height*.25
        y=460-height*.7
        s.stem(200,461,x,y,2.1)
        s.leaf(x,y,s.rng.uniform(85,125),s.rng.uniform(43,63),angle*.35,"lotus",veins=8,spots=kind=="tiger")


def fern(s: BotanicalSvg, kind: str) -> None:
    s.roots(200,461,20,41,23)
    for i in range(9):
        angle=math.radians(-57+i*114/8)
        h=s.rng.uniform(230,350)
        tx=200+math.sin(angle)*h*.57
        ty=455-math.cos(angle)*h
        s.stem(200,458,tx,ty,2.0)
        for n in range(1,11):
            t=n/12
            x=200+(tx-200)*t
            y=458+(ty-458)*t
            length=(1-t*.6)*49
            for side in (-1,1):
                a=math.degrees(angle)+side*57
                if kind=="bolbitis":
                    s.leaf(x,y,length,9,a,"ruffled",veins=4)
                else:
                    ar=math.radians(a)
                    ex=x+math.sin(ar)*length
                    ey=y-math.cos(ar)*length
                    s.stem(x,y,ex,ey,.8)
                    for q in range(1,7):
                        u=q/7
                        for branch in (-1,1):
                            s.leaf(x+(ex-x)*u,y+(ey-y)*u,15*(1-u*.6),2.6,a+branch*40,veins=0)
        s.leaf(tx,ty,30,8,math.degrees(angle),"lance",veins=3)


def fan_algae(s: BotanicalSvg) -> None:
    s.roots(200,462,18,29,21)
    for i in range(7):
        x=117+i*27
        y=365+s.rng.uniform(-50,45)
        r=s.rng.uniform(66,95)
        s.stem(200,460,x,y,4)
        s.parts.append(f'<g transform="translate({f(x)} {f(y)}) rotate({f(-32+i*10)})">')
        d=f"M 0 0 C {-r*.13} {-r*.22} {-r*.81} {-r*.47} {-r*.82} {-r*.79} C {-r*.8} {-r*1.1} {-r*.48} {-r*1.08} {-r*.27} {-r*1.12} Q 0 {-r*1.2} {r*.28} {-r*1.09} C {r*.58} {-r*1.1} {r*.83} {-r*.99} {r*.8} {-r*.77} Q {r*.53} {-r*.3} 0 0 Z"
        s.path(d,s.gradient(s.palette))
        for q in range(25):
            a=math.radians(-43+q*86/24)
            s.path(f"M 0 0 Q {f(math.sin(a)*r*.5)} {f(-math.cos(a)*r*.57)} {f(math.sin(a)*r*1.08)} {f(-math.cos(a)*r*1.05)}",stroke=s.palette[3],width=.55,opacity=.4)
        for q in (.45,.57,.69,.81,.94):
            s.path(f"M {f(-r*.71*q)} {f(-r*.8*q)} Q 0 {f(-r*1.4*q)} {f(r*.71*q)} {f(-r*.8*q)}",stroke=s.palette[0],width=.65,opacity=.2)
        s.parts.append("</g>")


def lattice(s: BotanicalSvg) -> None:
    s.roots(200,462,24,40,24)
    for i in range(8):
        l=s.rng.uniform(235,340)
        w=s.rng.uniform(25,39)
        angle=(-1+i*2/7)*math.degrees(math.asin(159/(l+w)))
        s.parts.append(f'<g transform="translate(200 460) rotate({f(angle)})">')
        s.path(f"M 0 0 L 0 {-l*.15}",stroke=s.palette[1],width=2)
        s.path(f"M 0 {-l*.15} C {-w} {-l*.35} {-w} {-l*.75} 0 {-l} C {w} {-l*.75} {w} {-l*.35} 0 {-l*.15} Z",s.palette[2],opacity=.11)
        s.path(f"M 0 {-l*.12} L 0 {-l}",stroke=s.palette[2],width=1.0)
        for q in range(1,8):
            u=q/8
            dx=(u-.5)*w*1.3
            s.path(f"M 0 {-l*.15} Q {f(dx*2)} {-l*.55} 0 {-l}",stroke=s.palette[1],width=.75,opacity=.8)
        for q in range(1,32):
            t=q/33
            y=-l*(.15+.85*t)
            ew=w*.76*math.sin(math.pi*t)
            s.path(f"M {f(-ew)} {f(y)} Q 0 {f(y-5)} {f(ew)} {f(y)}",stroke=s.palette[2],width=.75)
        s.parts.append("</g>")


def pennywort(s: BotanicalSvg, trilobe: bool = False) -> None:
    count=19 if trilobe else 12
    s.path("M 63 455 C 123 443 206 465 327 444",stroke=s.palette[1],width=2)
    for i in range(count):
        x=s.rng.uniform(75,325)
        by=s.rng.uniform(448,465)
        ty=by-s.rng.uniform(45,147 if trilobe else 290)
        tx=x+s.rng.uniform(-25,25)
        s.stem(x,by,tx,ty,1.4)
        s.roots(x,by,3,8,15,True)
        s.leaf(tx,ty,35 if trilobe else s.rng.uniform(52,74),27 if trilobe else 40,s.rng.uniform(-30,30),"trilobe" if trilobe else "round",veins=6)


def species(s: BotanicalSvg) -> None:
    ident=s.entry["id"]
    if ident=="seagrass":
        s.palette=OLIVE; ribbon_cluster(s,19,371,10,17)
    elif ident=="vallisneria":
        s.palette=LIME; ribbon_cluster(s,18,414,7,48)
    elif ident=="dwarf-hairgrass":
        s.palette=LIME; ribbon_cluster(s,19,127,1.6,10,6)
    elif ident=="dwarf-sagittaria":
        s.palette=GREEN; ribbon_cluster(s,9,185,6,4,3)
    elif ident=="micro-sword":
        s.palette=TEAL; ribbon_cluster(s,8,124,3.2,5,6)
    elif ident=="eriocaulon-cinereum":
        s.palette=LIME; ribbon_cluster(s,69,193,1.8,1)
        for x,y in ((166,246),(217,229),(238,265)):
            s.stem(200,460,x,y,1.2); s.ellipse(x,y,4,3.2,"#cfceb0")
    elif ident=="fan-algae":
        s.palette=OLIVE; fan_algae(s)
    elif ident=="anubias":
        s.palette=TEAL; rosette(s,11,122,40,"oval",265,rhizome=True)
    elif ident=="anubias-heterophylla":
        s.palette=GREEN; rosette(s,10,219,34,"lance",370,rhizome=True)
    elif ident=="java-fern":
        s.palette=GREEN; rosette(s,16,261,24,"ruffled",335,True,True)
    elif ident=="amazon-sword":
        s.palette=LIME; rosette(s,14,240,40,"lance",385)
    elif ident=="cryptocoryne-balansae":
        s.palette=OLIVE; rosette(s,14,318,13,"ruffled",365)
    elif ident=="cryptocoryne-wendtii":
        s.palette=BRONZE; rosette(s,13,171,28,"ruffled",278,True)
    elif ident=="cryptocoryne-parva":
        s.palette=GREEN; rosette(s,21,93,10,"lance",137)
    elif ident=="bucephalandra-motleyana":
        s.palette=TEAL; rosette(s,18,83,21,"ruffled",190,True,True)
    elif ident in ("java-moss","christmas-moss","fissidens-fontanus","riccia"):
        s.palette=LIME if ident=="riccia" else TEAL if ident=="fissidens-fontanus" else GREEN
        moss(s,{"java-moss":"java","christmas-moss":"christmas","fissidens-fontanus":"fissidens","riccia":"riccia"}[ident])
    elif ident in ("duckweed","salvinia","frogbit","water-lettuce","amazon-frogbit"):
        s.palette=LIME if ident in ("water-lettuce","duckweed") else GREEN
        floating(s,{"water-lettuce":"lettuce","amazon-frogbit":"amazon"}.get(ident,ident))
    elif ident in ("hornwort","cabomba","myriophyllum-mattogrossense","limnophila-sessiliflora","rotala-wallichii"):
        profiles={"hornwort":(5,361,14,53,"forked"),"cabomba":(5,350,11,61,"fan"),
                  "myriophyllum-mattogrossense":(5,365,12,55,"pinnate"),"limnophila-sessiliflora":(6,359,13,49,"soft"),"rotala-wallichii":(7,353,19,36,"redneedle")}
        s.palette=RED if ident=="rotala-wallichii" else LIME if ident=="limnophila-sessiliflora" else GREEN
        finely_divided(s,*profiles[ident])
    elif ident in ("tiger-lotus","water-lily","banana-plant"):
        s.palette=RED if ident=="tiger-lotus" else GREEN
        lily(s,{"tiger-lotus":"tiger","water-lily":"lily","banana-plant":"banana"}[ident])
    elif ident=="aponogeton-madagascariensis":
        s.palette=OLIVE; lattice(s)
    elif ident in ("bolbitis","water-sprite"):
        s.palette=TEAL if ident=="bolbitis" else LIME; fern(s,ident)
    elif ident in ("pennywort","hydrocotyle-tripartita"):
        s.palette=LIME; pennywort(s,ident=="hydrocotyle-tripartita")
    else:
        profiles={
            "egeria-densa":(GREEN,5,362,36,5,"lance",13,4,False,False),
            "elodea-canadensis":(TEAL,7,306,25,4,"lance",14,3,False,False),
            "rotala-rotundifolia":(OLIVE,7,340,40,10,"oval",10,2,True,False),
            "rotala-macrandra":(RUBY,5,295,74,25,"ruffled",7,2,False,False),
            "ludwigia-repens":(BRONZE,5,298,66,20,"oval",7,2,True,False),
            "ludwigia-arcuata":(RED,8,341,51,3.8,"lance",11,2,False,False),
            "hygrophila-polysperma":(LIME,6,333,64,11,"lance",9,2,False,False),
            "hygrophila-corymbosa":(GREEN,4,324,98,21,"lance",7,2,False,False),
            "bacopa-caroliniana":(OLIVE,6,337,43,17,"oval",10,2,False,False),
            "pearl-weed":(LIME,11,190,20,5,"oval",9,2,False,True),
            "staurogyne-repens":(GREEN,7,158,55,16,"lance",5,2,False,True),
            "monte-carlo":(LIME,18,78,14,7,"round",5,2,False,True),
            "alternanthera-reineckii":(RUBY,5,308,84,18,"lance",8,2,False,False),
        }
        if ident not in profiles:
            raise ValueError(f"No botanical illustration profile for {ident}")
        palette,*args=profiles[ident]
        s.palette=palette
        stem_bunch(s,*args)


def generate(entries: list[dict], output_dir: str | Path) -> list[Path]:
    """Write exactly the requested plant SVGs; return their output paths."""
    output_dir=Path(output_dir)
    output_dir.mkdir(parents=True,exist_ok=True)
    paths=[]
    for entry in entries:
        ident=entry["id"]
        if not ident or any(ch not in "abcdefghijklmnopqrstuvwxyz0123456789-" for ch in ident):
            raise ValueError("Plant IDs must be safe lower-case asset names")
        svg=BotanicalSvg(entry)
        species(svg)
        content=svg.document()
        tree=ET.fromstring(content)
        if tree.attrib.get("viewBox")!="0 0 400 500":
            raise ValueError(f"Unexpected canvas for {ident}")
        path=output_dir/f"{ident}.svg"
        with path.open("w",encoding="utf-8",newline="\n") as target:
            target.write(content)
        paths.append(path)
    return paths


def main() -> None:
    root=Path(__file__).resolve().parents[2]
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest",type=Path,default=root/"docs/design/aquarium-realistic-expansion-plan.json")
    parser.add_argument("--output-dir",type=Path,default=root/"src/DeskBox/Assets/Aquarium/Svg")
    args=parser.parse_args()
    manifest=json.loads(args.manifest.read_text(encoding="utf-8-sig"))
    paths=generate(manifest["plants"],args.output_dir)
    print(f"Generated and XML-validated {len(paths)} original botanical SVG assets.")


if __name__=="__main__":
    main()
