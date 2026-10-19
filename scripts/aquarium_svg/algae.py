"""Twenty original cartoon marine algae with species-specific thallus geometry."""

from __future__ import annotations

from html import escape
from math import cos, sin, pi
from pathlib import Path
from xml.etree import ElementTree as ET

INK = "#254e57"


def path(d, fill="none", stroke=INK, width=3.5):
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" stroke-width="{width}" '
            f'stroke-linecap="round" stroke-linejoin="round"/>')


def ellipse(x, y, rx, ry, fill, stroke="none", width=3):
    return f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" fill="{fill}" stroke="{stroke}" stroke-width="{width}"/>'


def stem(d, color="#6aab70", width=7):
    return [path(d, stroke=INK, width=width+4), path(d, stroke=color, width=width)]


def holdfast():
    return [path("M84 290 Q93 275 111 280 Q127 266 144 278 Q163 267 176 280 Q193 274 200 289 Q178 299 143 299 Q109 299 84 290 Z", "#b1ac8f"),
            path("M115 284 Q126 277 141 283 M161 287 l14 -3", stroke="#e0d7b2", width=3)]


def blade(d, color="#7cc47a", vein=None, light="#bfeaa1"):
    p = [path(d, color)]
    if vein:
        p.append(path(vein, stroke=light, width=3))
    return p


def simple_leaf(x, y, dx, dy, color):
    mx, my = x+dx*.5, y+dy*.5
    cx, cy = -dy*.15, dx*.15
    return [path(f"M{x} {y} Q{mx+cx:.1f} {my+cy-3:.1f} {x+dx} {y+dy} Q{mx-cx:.1f} {my-cy+3:.1f} {x} {y} Z", color),
            path(f"M{x} {y} L{x+dx*.79:.1f} {y+dy*.79:.1f}", stroke="#d3e7a1", width=2)]


def transformed(parts, angle=0, scale=1, pivot=284):
    return (f'<g transform="rotate({angle} 140 {pivot}) '
            f'translate({140*(1-scale):.1f} {pivot*(1-scale):.1f}) scale({scale})">'
            + "".join(parts) + "</g>")


def giant_kelp():
    p = holdfast() + stem("M139 285 C106 214 174 155 134 46 M137 252 C185 203 153 126 186 72", "#a0af5b", 6)
    for x,y,dx,dy,c in [(134,80,-64,-36,"#b4bd69"),(139,109,65,-45,"#bdc77a"),(135,149,-72,-32,"#99b669"),(129,184,64,-38,"#b4c879"),(127,221,-67,-31,"#a5c276"),(164,199,63,-45,"#bad17f"),(166,151,61,-32,"#a2bc6b"),(176,111,-40,-48,"#bdcd79"),(142,260,58,-34,"#9fb970")]:
        p += simple_leaf(x,y,dx,dy,c)
        p += [ellipse(x,y,5,7,"#d8d792",INK,2),ellipse(x-1,y-2,1.5,2,"#fff3bd")]
    p += simple_leaf(134,47,-9,-27,"#c2cf81")
    return p


def sugar_kelp():
    p = holdfast()
    for rot,s,c,l in [(-16,.88,"#b7b966","#e9dc93"),(15,.95,"#97af5c","#cedb83"),(0,1,"#b3c56d","#e9e79a")]:
        q = blade("M135 280 Q121 255 123 223 Q105 208 116 184 Q100 161 116 139 Q102 116 117 97 Q105 74 125 58 Q119 34 140 20 Q160 38 153 58 Q169 74 157 96 Q173 116 159 139 Q175 163 160 184 Q174 206 159 224 Q161 254 146 280 Z", c, "M141 274 Q131 219 140 167 Q148 99 140 32", l)
        for y in [72,111,151,193,232]:
            q.append(path(f"M125 {y} q15 13 29 0", stroke=l, width=2.5))
        p.append(transformed(q,rot,s))
    return p


def wakame():
    p = holdfast()
    for rot,s in [(-27,.8),(24,.84),(0,1)]:
        q = stem("M141 284 Q131 190 145 40","#81af67",7)
        for i in range(6):
            y = 240-i*32
            for sign in [-1,1]:
                dx = (69-i*7)*sign
                q += blade(f"M141 {y} Q{141+dx*.6:.1f} {y-6} {141+dx} {y-42} Q{141+dx*.74:.1f} {y-7} {141+dx*.88:.1f} {y+2} Q{141+dx*.42:.1f} {y+15} 141 {y+11} Z","#83b96e",f"M141 {y+4} Q{141+dx*.6:.1f} {y+2} {141+dx*.84:.1f} {y-28}","#cde395")
        q += blade("M145 66 Q119 39 145 20 Q168 41 145 66 Z","#98c77e","M145 58 L145 28")
        p.append(transformed(q,rot,s))
    return p


def bull_kelp():
    p = holdfast() + stem("M139 283 C99 229 184 182 147 111","#b7ab67",11)
    p += [ellipse(145,106,19,25,"#d6c77e",INK,3.5),path("M136 100 q-4 -12 4 -17",stroke="#f9e8ad",width=4)]
    for d,c,v in [("M133 89 Q75 48 39 90 Q71 34 118 57 Q143 65 141 88 Z","#92b56c","M135 81 Q87 47 51 76"),
                  ("M143 84 Q105 36 142 17 Q120 47 153 79 Z","#bad184","M144 72 Q124 41 139 27"),
                  ("M150 81 Q184 27 225 50 Q185 49 162 89 Z","#b2c97d","M157 80 Q184 41 214 47"),
                  ("M157 89 Q208 53 250 107 Q215 81 166 104 Z","#98b56e","M165 94 Q210 72 240 99"),
                  ("M132 95 Q67 79 43 141 Q57 95 132 108 Z","#c2ce88","M124 100 Q76 91 52 122"),
                  ("M161 103 Q225 90 233 158 Q218 114 158 116 Z","#b0c27d","M170 109 Q213 107 228 144")]:
        p += blade(d,c,v,"#e3e4a4")
    return p


def lettuce():
    p = holdfast()
    for d,c in [("M134 281 Q102 264 81 237 Q51 246 48 222 Q25 211 42 187 Q19 169 39 152 Q24 127 48 116 Q40 89 65 88 Q74 62 96 80 Q119 63 127 89 Q150 95 137 118 Q154 135 138 159 Q155 182 140 206 Q154 242 146 280 Z","#5aa774"),
                ("M140 281 Q126 243 133 214 Q117 194 132 177 Q116 155 136 138 Q117 115 139 97 Q127 77 148 67 Q149 44 172 56 Q194 39 208 61 Q237 56 236 80 Q262 88 247 109 Q268 129 248 146 Q263 172 239 179 Q245 204 216 210 Q198 254 153 284 Z","#8bcb78"),
                ("M141 282 Q116 265 108 242 Q80 245 77 224 Q55 211 72 195 Q56 173 79 163 Q80 139 104 148 Q122 132 136 152 Q157 140 169 158 Q194 151 201 175 Q225 185 211 206 Q214 231 188 237 Q177 264 154 284 Z","#a9dd81")]:
        p.append(path(d,c))
    for d in ["M140 276 Q107 213 70 121","M151 270 Q186 191 221 89","M145 272 Q147 211 105 167"]:
        p.append(path(d,stroke="#d6f0a6",width=5))
    return p


def grapes():
    p = holdfast()
    for x,top,shift in [(95,119,-10),(145,49,8),(194,96,12)]:
        p += stem(f"M141 283 Q{x-5} 226 {x} {top}","#68b77d",5)
        for j in range((265-top)//24):
            y = top+j*24
            for side in [-1,1]:
                gx = x+side*(10+(j%2)*5)+shift*j*.05
                p += stem(f"M{x} {y+14} L{gx:.1f} {y}","#75b675",3)
                p += [ellipse(gx,y,11,12,"#90ce78",INK,2.5),ellipse(gx-3,y-4,3.5,4,"#d4eca1")]
    return p


def feather():
    p = holdfast()
    for rot,s in [(-34,.8),(30,.87),(0,1)]:
        q = []
        for i in range(12):
            y,spread = 250-i*18,46*(sin((i+1)*pi/14)**.5)
            for side in [-1,1]:
                q.append(path(f"M140 {y+4} Q{140+side*spread*.6:.1f} {y-2} {140+side*spread:.1f} {y-23} Q{140+side*spread*.9:.1f} {y+1} 140 {y+14} Z","#72c78b",width=2))
        q += stem("M140 282 L140 36","#a1d491",4)
        p.append(transformed(q,rot,s,282))
    return p


def codium():
    p = holdfast()
    d = "M141 281 L139 207 L104 165 L82 112 L56 92 M105 164 L117 118 L111 77 M139 207 L173 161 L185 108 L212 75 M173 161 L149 119 L151 66 M185 109 L175 80 M141 252 L201 210 L221 166 L248 150 M201 209 L192 184 M82 112 L91 85"
    p += stem(d,"#69ae88",17)
    p.append(path(d,stroke="#96d2a3",width=4))
    return p


def halimeda():
    p = holdfast()
    p += stem("M139 282 L102 247 L76 207 L64 165 M102 247 L128 203 L109 159 L95 117 L130 82 L155 48 M139 282 L161 252 L184 212 L213 173 M184 212 L161 167 L147 122 L130 82 M161 167 L192 129 L178 87 L155 48", "#77ac87", 4)
    for x,y,rot in [(102,247,-16),(161,252,12),(76,207,-27),(128,203,-9),(184,212,19),(64,165,-38),(109,159,-19),(161,167,9),(213,173,24),(95,117,-27),(147,122,-8),(192,129,17),(130,82,-13),(178,87,17),(155,48,0)]:
        q = [path(f"M{x} {y+22} Q{x-25} {y+8} {x-23} {y-11} Q{x} {y-29} {x+23} {y-11} Q{x+26} {y+8} {x} {y+22} Z","#8ac995"),path(f"M{x-14} {y-8} Q{x} {y-19} {x+15} {y-8}",stroke="#cfebad",width=4),path(f"M{x} {y+15} L{x} {y-10}",stroke="#69a98a",width=2.5)]
        p.append(f'<g transform="rotate({rot} {x} {y})">'+"".join(q)+"</g>")
    return p


def sea_palm():
    p = holdfast()+[path("M126 285 Q132 234 127 197 L119 164 Q127 145 146 155 Q163 178 153 200 Q144 237 150 285 Z","#a4ae70"),path("M137 277 Q137 219 140 187",stroke="#d8d599",width=5)]
    for d,c in [("M132 165 Q72 91 31 173 Q63 127 125 182 Z","#96b76f"),("M137 159 Q110 65 75 99 Q101 98 125 165 Z","#b6c881"),("M140 158 Q126 67 158 58 Q145 100 153 159 Z","#9eba70"),("M150 160 Q178 86 220 110 Q183 109 162 174 Z","#b3c37c"),("M158 172 Q228 126 253 197 Q219 159 159 188 Z","#8eac68"),("M129 181 Q78 152 61 223 Q88 185 139 193 Z","#bdd18b"),("M156 185 Q216 167 216 237 Q203 195 150 196 Z","#aec981")]:
        p.append(path(d,c))
    p += [path("M130 172 Q78 121 46 157 M144 154 Q137 106 151 76 M162 180 Q215 147 242 184 M131 188 Q88 166 69 207",stroke="#d9e6a2",width=3)]
    return p


def gracilaria():
    p = holdfast()
    for d in ["M141 283 Q124 216 132 137 L123 65 M133 174 L97 141 L75 91 L51 67 M97 142 L103 99 L87 55 M132 214 L76 188 L51 149 L31 139 M76 187 L80 155 M133 144 L163 105 L162 61 L181 34 M162 105 L198 86 L221 51 M140 250 L194 208 L216 168 L249 146 M194 208 L175 170 L190 139 M215 169 L218 131 L245 104","M128 101 L103 73 M77 98 L60 106 M164 73 L143 47 M199 86 L204 111 M51 149 L50 121 M175 174 L151 147 M216 168 L245 184"]:
        p += stem(d,"#d77f9e",7)
    p.append(path("M139 267 Q128 218 131 190 M165 105 L184 94 M76 184 L58 158",stroke="#f6b4b7",width=2.5))
    return p


def dragon_tongue():
    p = holdfast()
    for rot,s,c in [(-38,.82,"#b96c96"),(36,.87,"#d786a2"),(-14,.95,"#db8ba8"),(17,1,"#bd6f9c"),(0,.78,"#e69bb1")]:
        q = blade("M140 284 Q123 253 123 223 L108 207 L116 183 L103 160 L115 136 L109 112 L125 94 L119 73 Q136 59 140 27 Q146 61 159 71 L152 94 L169 113 L159 138 L175 159 L161 182 L172 206 L157 225 Q155 258 140 284 Z",c,"M140 274 Q132 195 140 84","#f6c1c5")
        p.append(transformed(q,rot,s))
    return p


def irish_moss():
    p = holdfast()
    # Broad dichotomous straps distinguish this species from thin Gracilaria.
    d = "M131 285 L124 236 L98 202 L77 163 L44 144 L31 111 L45 105 L58 133 L79 142 L79 112 L61 83 L75 75 L94 104 L98 155 L119 189 L126 158 L117 123 L118 84 L103 52 L117 45 L133 78 L132 118 L143 146 L160 114 L160 78 L176 49 L190 56 L176 85 L177 121 L158 165 L157 193 L189 170 L201 130 L229 105 L240 117 L215 139 L209 170 L232 155 L255 148 L261 163 L235 172 L201 200 L158 226 L151 285 Z"
    p += [path(d,"#ce83ae"),path("M142 274 L142 219 L91 159 M141 217 L142 157 M147 215 L199 179 M90 155 L90 109 M174 121 L170 84",stroke="#f0b6cf",width=5)]
    return p


def dulse():
    p = holdfast()
    for rot,s,c in [(-30,.85,"#a85e8d"),(32,.84,"#b76b98"),(0,1,"#d387aa")]:
        q = blade("M139 283 Q120 254 118 218 Q89 211 83 188 L75 152 Q72 140 81 138 Q91 138 94 152 L103 171 L101 126 L83 96 Q78 87 86 81 Q94 76 102 87 L115 107 L115 71 Q114 58 124 57 Q135 58 133 72 L135 115 L143 145 L148 106 L161 78 Q167 67 175 72 Q184 78 177 89 L166 113 L168 145 L190 121 Q200 111 207 119 Q214 126 204 136 L185 157 L178 192 Q170 216 157 225 L151 283 Z",c,"M140 269 L140 191 L125 154 M141 205 L159 161","#f2b7ca")
        p.append(transformed(q,rot,s,283))
    return p


def sargassum():
    p = holdfast()+stem("M141 284 Q137 201 140 125 L151 37 M140 234 L92 193 L81 138 M141 202 L197 164 L207 100","#b8af64",5)
    for x,y,dx,dy in [(144,72,-39,-23),(147,90,46,-21),(140,118,-42,-23),(140,139,39,-30),(140,162,-46,-26),(140,184,39,-24),(93,191,-47,-24),(88,169,22,-36),(198,165,43,-21),(202,135,-24,-31),(141,241,-35,-27),(143,255,48,-31)]:
        p += simple_leaf(x,y,dx,dy,"#c3be75")
        p += [ellipse(x+dx*.45,y+5,6,7,"#dccb83",INK,2),ellipse(x+dx*.45-1.5,y+3,1.8,2,"#fff2b6")]
    return p


def bladderwrack():
    p = holdfast()
    d = "M132 286 L120 235 L91 199 L79 158 L52 132 L43 102 Q47 91 55 98 L65 124 L88 145 L98 125 L97 85 L109 64 Q120 62 120 74 L111 91 L116 129 L108 164 L128 202 L141 225 L149 178 L149 132 L134 97 L137 58 Q144 46 151 57 L151 91 L168 123 L171 166 L198 144 L210 112 L208 81 Q216 69 223 82 L224 114 L211 152 L190 176 L168 205 L153 286 Z"
    p += [path(d,"#a8b66e"),path("M141 276 L139 231 L100 170 M159 199 L160 133 M174 188 L207 152",stroke="#d9dc92",width=4)]
    for x,y in [(96,179),(104,191),(150,154),(161,154),(197,157),(206,147),(104,113),(153,110)]:
        p += [ellipse(x,y,6,9,"#d1cb80",INK,2),ellipse(x-1.5,y-3,2,3,"#f3e8aa")]
    return p


def spiral_wrack():
    p = holdfast()
    for rot,s,c in [(-29,.83,"#a5b16d"),(28,.87,"#bdc17b"),(0,1,"#b7bd74")]:
        q = []
        for i in range(5):
            y,side = 278-i*46,(-1)**i
            x = 140+side*20
            q += blade(f"M140 {y} Q{x-side*44} {y-14} {x} {y-34} L140 {y-46} Q{x+side*31} {y-28} 140 {y} Z",c)
            q.append(path(f"M140 {y-2} Q{x+side*17} {y-23} 140 {y-44}",stroke="#e4dea0",width=3))
        q += blade("M140 48 Q114 30 140 19 Q163 31 140 48 Z",c)
        p.append(transformed(q,rot,s,278))
    return p


def peacock():
    p = holdfast()
    p += stem("M142 286 L142 255 L91 254 M142 255 L195 252 M142 255 L142 210", "#a0b788", 8)
    for x,y,r,angle in [(91,254,71,-24),(195,252,70,25),(142,210,85,0)]:
        q = [path(f"M{x} {y} Q{x-r} {y-30} {x-r} {y-r} Q{x-r*.5:.1f} {y-r*1.28:.1f} {x} {y-r*1.1:.1f} Q{x+r*.5:.1f} {y-r*1.28:.1f} {x+r} {y-r} Q{x+r} {y-30} {x} {y} Z","#c1b47e")]
        for t,c in [(.84,"#e3d4a5"),(.65,"#929c6d"),(.47,"#e1cda0"),(.29,"#8ca58a")]:
            q.append(path(f"M{x-r*t:.1f} {y-r*t*.92:.1f} Q{x} {y-r*t*1.45:.1f} {x+r*t:.1f} {y-r*t*.92:.1f}",stroke=c,width=6))
        p.append(f'<g transform="rotate({angle} {x} {y})">'+"".join(q)+"</g>")
    return [transformed(p, 0, .84)]


def brush():
    p = holdfast()
    for x,top,bottom,r in [(94,154,281,34),(198,159,283,32),(144,88,282,45)]:
        p += stem(f"M{x} {bottom} L{x} {top+42}","#7ca67d",10)
        p.append(path(f"M{x-4} {top+66} Q{x-r} {top+29} {x-r} {top-8} Q{x-r*.7:.1f} {top-51} {x} {top-53} Q{x+r*.8:.1f} {top-51} {x+r} {top-8} Q{x+r} {top+29} {x+4} {top+66} Z","#7cbd83"))
        for i in range(13):
            dx,y = (i-6)*r/7,top-45+abs(i-6)*4
            p.append(path(f"M{x} {top+49} Q{x+dx*1.2:.1f} {top+4} {x+dx:.1f} {y}",stroke="#bee19b" if i%2 else "#529c78",width=2.5))
    return p


def wineglass():
    p = holdfast()
    for x,y,r,lean in [(69,195,39,-8),(205,187,44,9),(139,124,52,0)]:
        p += stem(f"M{x+lean} 282 Q{x+lean} 227 {x} {y+11}","#a0cfaa",5)
        p += [path(f"M{x-r} {y} Q{x-r*.6:.1f} {y+33} {x} {y+18} Q{x+r*.6:.1f} {y+33} {x+r} {y} Z","#a1d7bb"),ellipse(x,y,r,r*.32,"#c5e8ba",INK,3)]
        for i in range(18):
            a = i*pi/9
            p.append(path(f"M{x} {y} L{x+cos(a)*r*.9:.1f} {y+sin(a)*r*.27:.1f}",stroke="#72b897",width=2))
        p.append(ellipse(x,y,5,2,"#7dbb99"))
    return p


DRAWINGS = {
    "giant-kelp": giant_kelp, "sugar-kelp": sugar_kelp, "wakame-algae": wakame,
    "bull-kelp": bull_kelp, "sea-lettuce-algae": lettuce, "sea-grapes-algae": grapes,
    "feather-caulerpa": feather, "codium-algae": codium, "halimeda-algae": halimeda,
    "sea-palm-algae": sea_palm, "red-gracilaria": gracilaria,
    "dragon-tongue-algae": dragon_tongue, "irish-moss-algae": irish_moss,
    "dulse-algae": dulse, "sargassum-algae": sargassum, "bladderwrack-algae": bladderwrack,
    "spiral-wrack-algae": spiral_wrack, "peacock-tail-algae": peacock,
    "shaving-brush-algae": brush, "mermaids-cup-algae": wineglass,
}


def generate(entries, output_path):
    output = Path(output_path)
    output.mkdir(parents=True, exist_ok=True)
    targets = []
    for entry in entries:
        key = entry["id"]
        body = "\n".join(DRAWINGS[key]())
        svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 320" width="280" height="320">'
               f'<title>{escape(entry["name"])}</title><desc>Original cartoon {escape(entry["en_name"])}. '
               f'Species-specific marine thallus silhouette, cel shading, transparent background.</desc>\n{body}\n</svg>\n')
        ET.fromstring(svg)
        target = output / f"{key}.svg"
        target.write_text(svg, encoding="utf-8", newline="\n")
        targets.append(target)
    return targets
