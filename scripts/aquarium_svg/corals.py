"""Twenty original cartoon corals with individually authored species structure."""

from __future__ import annotations

from html import escape
from math import cos, sin, pi
from pathlib import Path
from xml.etree import ElementTree as ET

INK = "#254e64"


def path(d, fill="none", stroke=INK, width=4, opacity=1):
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" stroke-width="{width}" '
            f'stroke-linecap="round" stroke-linejoin="round" opacity="{opacity}"/>')


def ellipse(x, y, rx, ry, fill, stroke="none", width=3):
    return f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" fill="{fill}" stroke="{stroke}" stroke-width="{width}"/>'


def branch(d, color, width=15, light=None):
    result = [path(d, stroke=INK, width=width + 5), path(d, stroke=color, width=width)]
    if light:
        result.append(path(d, stroke=light, width=max(2, width / 4), opacity=.65))
    return result


def base(color="#b1a0ad"):
    return [path("M79 246 Q78 234 99 235 Q109 220 130 226 Q146 216 162 224 Q187 215 200 229 Q227 224 235 243 Q205 258 157 257 Q109 259 79 246 Z", color),
            path("M94 244 Q144 251 217 242", stroke="#ddd3cf", width=4)]


def dots(coords, color="#ffe2c8", radius=2.8):
    return [ellipse(x, y, radius, radius, color) for x, y in coords]


def flower(cx, cy, radius, color, center, petals=12, phase=0):
    pieces = []
    for n in range(petals):
        a = phase + n * 2 * pi / petals
        x, y = cx + cos(a) * radius, cy + sin(a) * radius * .72
        pieces.append(path(f"M{cx:.1f} {cy:.1f} Q{(cx+x)/2:.1f} {(cy+y)/2-5:.1f} {x:.1f} {y:.1f}", stroke=INK, width=8))
        pieces.append(path(f"M{cx:.1f} {cy:.1f} Q{(cx+x)/2:.1f} {(cy+y)/2-5:.1f} {x:.1f} {y:.1f}", stroke=color, width=4.5))
    pieces += [ellipse(cx, cy, radius * .43, radius * .29, center, INK, 2.5), ellipse(cx-2, cy-2, 3, 2, "#fff1c9")]
    return pieces


def staghorn():
    p = base()
    p += branch("M158 240 Q156 187 149 143 L133 94 L132 39 M148 147 L99 127 L71 82 L48 68 M98 126 L92 68 M149 180 L205 147 L222 107 L250 82 M205 147 L190 113 L194 68 M152 123 L184 90 L211 48 M132 95 L107 81 L107 42 M221 108 L257 121 L278 109", "#f49288", 16, "#ffd8b5")
    p += dots([(132,42),(107,45),(93,70),(51,70),(195,71),(211,51),(251,84),(276,111)], "#ffe4c2", 5)
    p += dots([(156,213),(154,194),(143,164),(131,132),(116,121),(83,97),(203,154),(215,128)], "#d7657a", 3)
    return p


def elkhorn():
    p = base("#bca494")
    p += [path("M145 241 L134 181 Q92 184 78 158 L56 118 Q46 102 55 95 Q64 90 74 108 L96 136 L102 98 L84 64 Q79 53 88 49 Q97 44 103 57 L118 81 L122 52 Q123 39 133 41 Q143 44 140 58 L137 114 Q137 138 154 142 L166 111 L165 70 Q166 52 176 55 Q186 57 184 72 L186 91 L206 59 Q213 48 223 55 Q231 62 222 74 L196 116 L193 143 Q215 132 227 111 L240 83 Q246 69 256 76 Q264 83 257 97 L243 127 L263 120 Q279 116 281 126 Q282 137 261 143 Q238 153 221 177 Q204 196 179 197 L177 242 Z", "#e9b96b"),
          path("M155 229 Q155 181 120 155 Q109 143 118 102 M169 231 L177 169 Q219 165 241 124 M180 164 L186 115", stroke="#ffe4a7", width=8)]
    p += dots([(90,145),(104,154),(131,167),(175,178),(204,170),(229,145),(155,208)], "#c68b53", 3)
    return p


def table():
    p = base("#9b94ad")
    p += branch("M155 240 L155 194 M154 206 L95 180 M157 211 L221 177", "#ab7cb9", 20)
    p += [path("M35 151 Q43 130 73 136 Q79 114 108 122 Q121 100 148 113 Q168 97 188 113 Q214 105 231 124 Q263 119 276 142 Q293 152 279 166 Q219 197 153 194 Q81 191 38 168 Z", "#b896da"),
          path("M39 159 Q94 180 157 181 Q224 182 281 151 L278 166 Q220 197 152 194 Q81 190 39 167 Z", "#826bad"),
          path("M56 150 Q101 132 154 133 Q216 131 263 147", stroke="#e7c2ed", width=6)]
    for x,y in [(65,153),(85,142),(105,159),(124,141),(145,160),(165,141),(187,159),(207,142),(232,155),(252,149)]:
        p += [path(f"M{x} {y+4} l-3 -11 m3 9 l6 -10", stroke="#f4d0ef", width=4)]
    return p


def birdsnest():
    p = base()
    limbs = ["M156 241 L147 170 L105 128 L77 88 L78 53 M108 132 L61 133 L39 109 M106 127 L107 74 L92 43 M147 171 L151 99 L129 63 L136 34 M149 122 L173 80 L173 47 M157 210 L213 164 L240 122 L275 98 M213 164 L202 124 L220 78 L219 48 M240 123 L244 72 M199 128 L174 107", "M117 143 L100 110 L70 109 M150 151 L187 141 L211 98 M178 192 L235 176 L271 144 M184 185 L181 153 M66 132 L57 87 M152 99 L153 65"]
    for d in limbs:
        p += branch(d, "#e7a0cf", 10, "#ffe0ee")
    p += dots([(79,56),(40,110),(93,45),(136,37),(173,49),(218,50),(245,74),(274,100),(270,145)], "#fff0e8", 3.5)
    return p


def cauliflower():
    p = base("#a4a0c4")
    p += branch("M155 240 L153 192 M154 208 L98 160 M155 206 L213 159 M152 196 L157 112", "#c879c9", 22)
    for x,y,r in [(76,162,29),(105,129,30),(157,102,34),(207,126,30),(239,164,27),(130,163,30),(182,165,34)]:
        p.append(ellipse(x,y,r,r*.89,"#e8a7de",INK,3.5))
        for dx,dy in [(-.45,-.35),(.1,-.5),(.5,-.05),(-.3,.25),(.2,.3)]:
            p.append(ellipse(x+dx*r,y+dy*r,r*.23,r*.2,"#f7c5e8","#c67bbd",2))
        p.append(path(f"M{x-r*.55} {y-r*.3} q4 -9 12 -8", stroke="#ffe8f3", width=4))
    return p


def brain():
    p = [path("M49 209 C29 170 58 103 108 80 C164 54 233 80 265 127 Q296 174 273 210 Q243 242 164 245 Q84 243 49 209 Z", "#75cebb"),
         path("M51 208 Q159 256 273 208 Q247 244 162 245 Q83 243 51 208 Z", "#469d9a", "none")]
    lines = ["M69 198 Q88 212 97 198 Q105 184 86 175 Q67 164 79 147 Q89 132 108 148 Q127 161 121 178 Q115 196 133 210", "M100 115 Q116 96 137 111 Q156 127 142 145 Q128 165 146 179 Q160 189 151 219", "M159 93 Q187 95 185 115 Q183 132 164 129 Q149 126 153 143 Q157 157 178 158 Q201 160 190 180 Q181 200 204 216", "M214 111 Q241 131 229 147 Q219 159 211 144 Q198 129 193 145 M250 165 Q264 191 245 204 Q225 215 219 191 Q211 169 230 174"]
    for d in lines:
        p += [path(d,stroke="#348b88",width=13),path(d,stroke="#c1efbc",width=6)]
    p.append(path("M70 125 Q83 107 99 101",stroke="#ddf9d1",width=6))
    return p


def maze():
    p = [path("M55 223 Q32 196 46 157 Q40 125 70 110 Q71 76 110 76 Q134 46 170 65 Q204 54 225 83 Q259 86 265 118 Q292 142 276 171 Q288 206 258 223 Q209 249 156 247 Q101 250 55 223 Z", "#efbc69")]
    for d in ["M66 208 L66 171 Q65 159 80 158 L103 158 L103 132 L82 132 L82 111", "M92 222 L126 222 L126 182 L91 182 L91 201", "M116 105 L116 91 L149 91 L149 126 L126 126 L126 152 L161 152 L161 187 L147 187 L147 225", "M172 83 L201 94 L201 124 L177 124 L177 169 L207 169 L207 196 L178 196 L178 228", "M227 109 L243 128 L243 148 L221 148 L221 123", "M249 174 L249 205 L226 218 L226 188"]:
        p += [path(d,stroke="#b87955",width=12),path(d,stroke="#ffe4a1",width=5)]
    return p


def plate():
    p = [ellipse(160,207,124,43,"#d27da6",INK,4),ellipse(160,194,126,42,"#f0aaca",INK,4)]
    for i in range(36):
        a=2*pi*i/36
        p.append(path(f"M{160+cos(a)*18:.2f} {194+sin(a)*5:.2f} Q{160+cos(a+.06)*65:.2f} {194+sin(a+.06)*22:.2f} {160+cos(a)*116:.2f} {194+sin(a)*36:.2f}",stroke="#ba6a96",width=2.8))
    p += [ellipse(160,194,19,7,"#9a5682",INK,2.5),path("M76 173 Q108 161 142 160",stroke="#ffe6ed",width=5)]
    return p


def scroll():
    p = base("#a398aa")
    for d,shade in [("M61 207 Q89 182 106 173 Q71 164 71 137 Q72 114 103 115 Q130 117 123 136 Q116 149 101 138 Q135 166 168 156 Q135 124 152 100 Q169 79 192 98 Q205 114 189 125 Q228 128 251 104 Q276 139 243 170 Q217 188 198 185 Q226 200 254 194 Q258 227 208 238 Q137 258 80 239 Q48 231 61 207 Z", "#dfa56d"), ("M63 208 Q107 218 147 197 Q182 180 213 188 Q229 196 253 194 Q255 209 237 216 Q196 211 159 232 Q108 249 80 236 Q55 228 63 208 Z", "#f5c38d")]:
        p.append(path(d,shade))
    p += [path("M82 124 Q96 115 110 126 M159 106 Q168 95 179 106 M85 205 Q125 209 158 188 Q199 166 228 179 M86 231 Q124 237 151 223",stroke="#ffe5b4",width=7),path("M124 136 Q114 154 135 166 M188 125 Q177 139 194 151 M244 141 Q220 162 194 159",stroke="#bb7e60",width=4)]
    return p


def mushroom():
    p = base("#9c8caf")
    for x,y,r,c in [(103,180,58,"#ac97e0"),(203,182,61,"#76c9cf"),(154,124,62,"#d19bdf")]:
        p += [path(f"M{x-10} {y+11} l-4 42 q14 13 28 0 l-4 -42 Z","#a492c8"),ellipse(x,y,r,r*.45,c,INK,4)]
        for i in range(16):
            a=i*pi/8
            p.append(path(f"M{x+cos(a)*11:.1f} {y+sin(a)*4:.1f} L{x+cos(a)*r*.86:.1f} {y+sin(a)*r*.39:.1f}",stroke="#f1d5f4",width=3))
        p += [ellipse(x,y,11,5,"#74609c",INK,2),path(f"M{x-r*.65} {y-r*.24} q14 -9 30 -7",stroke="#fff0f5",width=4)]
    return p


def tentacle(kind):
    palette={"hammer":("#8ac8a9","#cfe5a2"),"frogspawn":("#b392cf","#d4edaa"),"torch":("#70c9b2","#fff0ad")}
    stem,tip=palette[kind]
    p=base("#a797af")
    p += [path("M93 232 Q110 207 125 217 Q147 194 170 213 Q195 200 222 231 Q170 253 93 232 Z","#9a77a0")]
    for i in range(15):
        x=62+(i%5)*46+(i//5)*5; y=72+(i//5)*44+(i%3)*7
        if kind=="torch": y-=18 if i<5 else 0
        d=f"M{130+i*4} 230 Q{x+(-16 if i%2 else 22)} 175 {x} {y}"
        p+=branch(d,stem,10 if kind!="torch" else 7)
        if kind=="hammer":
            p += branch(f"M{x-11} {y+1} Q{x} {y-10} {x+12} {y}",tip,12)
        elif kind=="frogspawn":
            for dx,dy in [(-7,0),(5,-4),(1,8)]:p.append(ellipse(x+dx,y+dy,7,6,tip,INK,2))
        else:
            p += [ellipse(x,y,5.5,7,tip,INK,2),ellipse(x-1,y-2,2,2,"#fff9dd")]
    return p


def bubble():
    p=base("#aa96b2")
    for x,y,r in [(74,184,23),(98,145,25),(138,123,29),(185,121,28),(222,153,27),(246,194,24),(112,194,30),(158,167,32),(202,197,31),(156,218,26)]:
        p += [ellipse(x,y,r,r*1.05,"#d6d5f3",INK,3),ellipse(x+4,y+7,r*.64,r*.66,"#b4addd"),path(f"M{x-r*.5} {y-r*.1} q0 {-r*.5} {r*.45} {-r*.58}",stroke="#fff7ff",width=5)]
    return p


def candy():
    p=base("#a19aad")
    for x,y,r in [(83,149,29),(142,114,32),(213,133,29),(115,201,28),(190,198,30)]:
        p += branch(f"M159 239 Q{x} 220 {x} {y+7}","#cc959c",15)
        p += [ellipse(x,y,r,r*.78,"#a9dfbe",INK,4),ellipse(x,y,r*.68,r*.49,"#54a99b","#e1f0bb",5),ellipse(x,y,7,4,"#356875")]
        for i in range(12):
            a=i*pi/6
            p.append(path(f"M{x+cos(a)*r*.76:.1f} {y+sin(a)*r*.57:.1f} l{cos(a)*r*.17:.1f} {sin(a)*r*.15:.1f}",stroke="#edf8c7",width=3))
    return p


def sun():
    p=base("#aa929a")
    for x,y,r in [(81,180,34),(124,133,35),(190,131,34),(242,183,31),(157,201,39)]:
        p += [path(f"M{x-14} {y+8} l2 34 q12 13 26 -1 l-1 -33 Z","#ed9a57"),ellipse(x,y+5,r*.65,r*.45,"#ee9553",INK,3)]
        p += flower(x,y,r,"#ffd36e","#e98850",14)
    return p


def seafan():
    p=base("#9c91aa")
    p += branch("M155 245 L152 175", "#c17fbf",12)
    for angle in [-76,-58,-39,-20,0,20,39,58,76]:
        a=angle*pi/180; x=155+sin(a)*122; y=184-cos(a)*142
        d=f"M155 213 Q{155+sin(a)*50:.1f} 159 {x:.1f} {y:.1f}"
        p += branch(d,"#d29bd5",5)
        for j in range(3):
            frac=.35+j*.19
            bx=155+(x-155)*frac; by=213+(y-213)*frac
            for side in (-1,1):
                ex=bx+side*(19+frac*17); ey=by-23
                p+=branch(f"M{bx:.1f} {by:.1f} Q{ex:.1f} {by-6:.1f} {ex:.1f} {ey:.1f}","#dca9dc",3)
    for y,w in [(91,76),(124,98),(155,108)]:
        p.append(path(f"M{155-w} {y+16} Q155 {y-17} {155+w} {y+16}",stroke="#ab73b3",width=3))
    return p


def organ():
    p=base("#ae939e")
    for i in range(9):
        x=60+i*25; y=145-(i%3)*32
        p += [path(f"M{x-8} 238 L{x-8} {y} Q{x} {y-7} {x+8} {y} L{x+8} 238 Z","#d77782"),path(f"M{x-3} 229 L{x-3} {y+10}",stroke="#f4b29e",width=3)]
        p+=flower(x,y,19,"#b4dfb3","#80b5a0",8,pi/8)
    p += [path("M56 205 Q162 190 268 202 M58 227 Q162 214 267 226",stroke="#9f5d78",width=6)]
    return p


def leather():
    p=base("#b0a099")
    p += [path("M138 240 Q123 212 103 195 Q69 176 73 142 Q73 129 83 130 Q92 132 92 146 Q95 162 108 165 Q111 150 99 119 Q89 98 101 92 Q112 88 120 110 L130 141 Q139 125 131 94 L125 63 Q123 46 135 47 Q147 46 148 65 L155 108 Q168 93 170 64 Q171 47 183 50 Q193 54 190 71 L181 115 Q177 137 183 144 Q205 124 211 98 Q215 83 227 88 Q237 93 230 111 Q222 142 207 163 Q223 163 233 145 Q240 133 249 140 Q258 150 247 166 Q229 196 198 203 L181 241 Z","#daa997"),path("M148 229 Q146 184 128 168 M171 230 Q174 182 203 161 M151 172 L157 125",stroke="#f9d5b1",width=8)]
    p += dots([(111,177),(126,151),(143,188),(168,157),(185,181),(209,176),(141,95),(178,96)],"#b77e88",3)
    return p


def starpolyps():
    p=[path("M41 231 Q35 212 57 206 Q63 182 88 188 Q105 165 131 180 Q151 166 174 177 Q203 162 223 184 Q252 180 266 204 Q287 212 276 233 Q246 252 158 253 Q78 256 41 231 Z","#9d81b7")]
    for x,y,r in [(69,218,22),(109,197,25),(154,210,26),(198,195,24),(246,217,22),(114,232,20),(208,232,19)]:
        p+=flower(x,y,r,"#b3e89b","#65bba3",8,pi/8)
    return p


DRAWINGS = {
    "staghorn-coral": staghorn, "elkhorn-coral": elkhorn,
    "table-acropora": table, "birdsnest-coral": birdsnest,
    "cauliflower-coral": cauliflower, "brain-coral": brain,
    "maze-coral": maze, "plate-coral": plate, "scroll-coral": scroll,
    "mushroom-coral": mushroom, "hammer-coral": lambda: tentacle("hammer"),
    "frogspawn-coral": lambda: tentacle("frogspawn"), "torch-coral": lambda: tentacle("torch"),
    "bubble-coral": bubble, "candy-cane-coral": candy, "sun-coral": sun,
    "sea-fan-coral": seafan, "organ-pipe-coral": organ,
    "finger-leather-coral": leather, "star-polyp-coral": starpolyps,
}


def generate(entries, output_path):
    output = Path(output_path)
    output.mkdir(parents=True, exist_ok=True)
    targets = []
    for entry in entries:
        key = entry["id"]
        body = "\n".join(DRAWINGS[key]())
        svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 280" width="320" height="280">'
               f'<title>{escape(entry["name"])}</title><desc>Original cartoon {escape(entry["en_name"])}. '
               f'Individually authored species morphology, cel shading, transparent background.</desc>\n{body}\n</svg>\n')
        ET.fromstring(svg)
        target = output / f"{key}.svg"
        target.write_text(svg, encoding="utf-8", newline="\n")
        targets.append(target)
    return targets
