"""Twenty original cartoon aquatic snails with species-specific shell geometry.

Small side-view animals use opaque vector paths and generous transparent padding.
Shell families deliberately include planispiral, domed, stepped, spiny, turreted,
and cowrie shapes; the original shellfish conch and nautilus are not reused.
"""

from __future__ import annotations

from html import escape
from pathlib import Path
import xml.etree.ElementTree as ET


INK = "#244a50"


def path(d, fill="none", stroke=INK, width=4, opacity=1):
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" '
            f'stroke-width="{width}" stroke-linecap="round" '
            f'stroke-linejoin="round" opacity="{opacity}"/>')


def ellipse(x, y, rx, ry, fill, stroke="none", width=4, opacity=1):
    return (f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" '
            f'fill="{fill}" stroke="{stroke}" stroke-width="{width}" '
            f'opacity="{opacity}"/>')


def body(color="#a4cdb1", shadow="#6ea895", head=228, tentacles="thin"):
    p = [path("M47 217 Q57 202 95 201 L180 199 Q198 186 218 194 Q232 204 256 211 Q269 217 264 225 Q216 240 167 232 Q109 243 58 232 Q43 227 47 217 Z", color),
         path("M51 225 Q109 231 166 222 Q223 232 260 217 Q274 230 239 235 Q201 240 168 232 Q110 243 58 232 Z", shadow, "none"),
         path(f"M{head-20} 212 Q{head-29} 194 {head-21} 172 Q{head-10} 157 {head+6} 171 Q{head+17} 187 {head+18} 212 Z", color)]
    if tentacles == "broad":
        p += [path(f"M{head-18} 178 Q{head-38} 154 {head-28} 138 Q{head-12} 146 {head-9} 175 Z", color),
              path(f"M{head+2} 177 Q{head+8} 148 {head+24} 142 Q{head+27} 163 {head+9} 182 Z", color)]
    else:
        p += [path(f"M{head-17} 176 Q{head-28} 156 {head-39} 155", stroke=shadow, width=5),
              path(f"M{head+3} 176 Q{head+19} 151 {head+33} 157", stroke=shadow, width=5)]
    p += [ellipse(head-14, 181, 4, 5.5, INK), ellipse(head+4, 181, 4, 5.5, INK),
          ellipse(head-15, 179, 1.2, 1.8, "#fffef0"), ellipse(head+3, 179, 1.2, 1.8, "#fffef0"),
          ellipse(head-22, 192, 6, 3.3, "#ed929e", opacity=.75),
          ellipse(head+12, 192, 6, 3.3, "#ed929e", opacity=.75),
          path(f"M{head-10} 194 Q{head-5} 201 {head+1} 194", width=2.6),
          path("M75 218 Q107 215 126 217", stroke="#e7f4db", width=3, opacity=.7)]
    return p


def spiral(x, y, scale=1, color="#a56840", width=4):
    return (f'<g transform="translate({x} {y}) scale({scale})">' +
            path("M44 28 C4 56 -51 26 -43 -18 C-35 -59 16 -63 32 -31 C45 -2 15 17 -3 4 C-18 -7 -4 -27 7 -17", stroke=color, width=width) + "</g>")


def mystery():
    p = body("#e6ce86", "#c4a565")
    p += [path("M80 208 Q48 184 59 133 Q67 99 101 94 Q106 56 133 66 Q149 40 166 61 Q194 60 195 89 Q219 108 208 157 Q211 192 182 211 Z", "#f2c664"),
          path("M62 161 Q78 196 123 197 Q181 207 204 166 Q205 200 179 213 L84 211 Q63 196 62 161 Z", "#d9944e", "none"),
          path("M101 94 Q139 99 166 61 M100 98 Q156 76 187 101", stroke="#b07c42", width=4),
          spiral(136, 148, 1.15, "#b97d3f", 4.3),
          path("M78 133 Q85 112 108 110 M119 78 Q127 72 134 76", stroke="#fff0b0", width=7)]
    return p


def rabbit():
    p = body("#f5bc62", "#d38b40", tentacles="broad")
    p += [path("M78 211 Q63 196 75 174 L83 143 L100 117 L107 91 L125 71 L137 36 Q144 25 149 43 L154 73 L169 94 L174 121 L192 147 L204 181 Q213 201 189 216 Z", "#806759"),
          path("M90 201 Q129 214 186 198 M81 174 Q131 193 200 177 M87 146 Q129 164 190 149 M102 119 Q137 135 173 123 M110 94 Q138 107 167 96 M126 72 Q140 82 154 75", stroke="#c49a70", width=7),
          path("M99 204 Q80 187 92 175 M115 210 Q98 191 108 180 M133 212 Q115 192 124 184 M151 213 Q133 194 141 187 M169 210 Q154 192 160 188 M118 151 Q104 139 110 125 M141 157 Q124 144 131 131 M164 157 Q146 143 152 133", stroke="#513f3a", width=3),
          path("M126 83 L137 58 M101 135 L110 124", stroke="#ead1a1", width=4)]
    return p


def zebra():
    p = body("#c5d5a2", "#8aa982")
    p += [path("M63 210 C43 184 51 125 84 106 Q135 76 180 103 Q220 126 210 180 Q207 204 185 216 Z", "#f1be59"),
          path("M82 108 Q78 152 67 193 L76 208 Q100 154 97 100 M115 94 Q124 134 100 213 L115 214 Q143 147 135 94 M152 95 Q176 145 138 215 L152 216 Q190 161 172 99 M189 110 Q217 160 176 215 L187 214 Q225 182 209 145", "#514440", "none"),
          path("M64 185 Q110 216 200 190", stroke="#ac743e", width=4, opacity=.6),
          path("M83 126 Q90 115 108 111 M135 106 L145 107", stroke="#fff3c4", width=6)]
    return p


def horned():
    p = body("#d3bd88", "#ab926a")
    p += [path("M76 126 L63 93 L95 108 L119 84 L127 60 L147 91 L175 88 L197 68 L195 110 L220 123 L244 116 L226 148 L216 179 Q207 208 185 214 L83 211 Q56 184 76 126 Z", "#dfb85e"),
          path("M76 126 L91 121 L91 190 L109 211 L89 211 Q63 186 76 126 M123 91 L141 91 L141 214 L121 214 Z M175 88 L192 105 L191 210 L169 215 Z M209 121 L226 141 L216 179 L207 199 Z", "#675143", "none"),
          path("M71 171 Q129 187 216 170 M75 189 Q134 207 207 192", stroke="#f9d786", width=5),
          path("M107 112 Q115 101 126 103", stroke="#fff2b6", width=6)]
    return p


def ramshorn():
    p = body("#e2a3af", "#bc788b")
    p += [ellipse(136, 145, 76, 73, "#d98d8b", INK),
          ellipse(134, 143, 62, 61, "#f6b7a8", "#bb756f", 3),
          spiral(132, 143, 1.25, "#b9686d", 6),
          path("M75 168 Q103 202 143 203 Q178 199 200 171", stroke="#d88b89", width=9),
          path("M86 117 Q100 93 126 91 M144 92 L154 95", stroke="#ffe9c9", width=7),
          path("M121 216 Q132 197 158 197 L181 209", stroke="#985960", width=4)]
    return p


def trumpet():
    p = body("#b6c7ae", "#829d94")
    p += [path("M72 211 L83 183 L88 161 L101 136 L107 112 L119 89 L125 68 L138 38 Q145 26 149 43 L153 69 L161 91 L170 113 L180 138 L189 160 L200 185 Q213 202 190 216 Z", "#d2ba87"),
          path("M82 184 Q129 197 199 185 M90 162 Q135 173 189 160 M101 138 Q136 149 178 137 M108 114 Q137 124 170 113 M120 91 Q138 101 160 91 M126 69 Q141 79 153 70", stroke="#8b765b", width=4),
          path("M98 188 L95 204 M117 190 L113 207 M140 194 L137 211 M164 194 L160 211 M184 190 L181 205 M108 165 L105 177 M131 170 L128 181 M153 169 L150 182 M174 166 L172 177 M119 143 L117 153 M141 147 L140 156 M160 143 L160 153 M124 116 L123 125 M146 121 L146 129", stroke="#9f5c4f", width=5),
          path("M133 70 L140 50 M111 138 L118 122", stroke="#f4e4ba", width=4)]
    return p


def assassin():
    p = body("#c4ba96", "#9c967f")
    p += [path("M79 212 Q55 188 78 157 L91 127 L112 107 L122 80 L144 50 L151 79 L172 104 L179 130 L200 156 Q226 193 193 215 Z", "#efc15d"),
          path("M79 159 Q123 179 203 160 L213 179 Q129 200 70 179 Z M97 124 Q135 138 179 127 L188 144 Q134 160 85 144 Z M122 80 Q144 94 158 87 L173 106 Q142 118 113 106 Z", "#665448", "none"),
          path("M81 198 Q137 216 202 195 M109 119 L96 141 M128 85 L120 105", stroke="#fff0ad", width=4),
          path("M249 198 Q270 188 270 171 Q263 160 260 173 L257 187", "#c4ba96", INK, 3)]
    return p


def trapdoor():
    p = body("#9eaf8a", "#6e8f7c")
    p += [path("M67 204 Q49 175 67 144 L91 115 L104 85 L128 69 L142 43 L152 69 L174 90 L183 117 Q220 147 210 182 Q204 207 182 217 Z", "#92a37c"),
          path("M94 116 Q135 131 181 116 M105 88 Q134 102 173 91 M67 150 Q135 175 205 147", stroke="#536f61", width=5),
          path("M69 181 Q120 213 195 195 M78 166 Q131 190 204 169", stroke="#c0c998", width=8),
          ellipse(184, 198, 20, 15, "#75836b", "#466154", 3),
          spiral(183, 198, .25, "#b6bf95", 8),
          path("M108 100 L115 94 M85 140 L101 133", stroke="#e5e6be", width=6)]
    return p


def bladder():
    p = body("#d8c8ae", "#ac9e8b")
    p += [path("M76 211 C49 188 60 148 83 124 Q99 106 100 83 L120 96 Q148 96 168 113 Q201 127 207 167 Q212 199 184 214 Z", "#cda770"),
          path("M85 125 Q132 125 148 166 Q155 198 131 215", stroke="#9a724e", width=5),
          path("M102 101 Q125 113 145 110", stroke="#8a7158", width=4),
          path("M73 172 Q78 196 112 204 Q167 224 195 190", stroke="#e4c998", width=9),
          path("M88 141 Q101 132 112 140 M169 145 Q180 155 181 168", stroke="#fff0c7", width=6),
          ellipse(146, 157, 6, 4, "#aa774c"), ellipse(166, 178, 5, 3, "#aa774c")]
    return p


def pond():
    p = body("#acbba7", "#7d9b90", tentacles="broad")
    p += [path("M76 208 Q53 184 73 153 L94 135 L110 113 L112 89 L131 69 L140 41 L151 67 L166 85 L169 114 L191 137 Q218 164 206 191 Q201 209 180 216 Z", "#bf9769"),
          path("M113 91 Q140 102 166 85 M110 114 Q141 129 169 114 M94 137 Q146 147 176 180 Q181 204 157 215", stroke="#86654d", width=4),
          path("M74 176 Q93 206 141 207", stroke="#e4be8c", width=10),
          path("M107 149 Q132 148 153 169 M123 113 L136 114 M132 73 L140 68", stroke="#f5dfb2", width=6),
          path("M92 161 L85 180 M108 167 L105 192 M125 175 L125 200", stroke="#aa7e55", width=3)]
    return p


def turbo():
    p = body("#9fbd96", "#70967f")
    p += [path("M70 210 Q39 180 58 140 Q69 113 104 108 Q98 77 125 65 Q144 55 161 73 Q189 75 193 104 Q218 116 220 152 Q224 194 189 214 Z", "#83b6a4"),
          path("M74 205 Q116 226 169 209 Q211 194 216 157", stroke="#4c8b81", width=9),
          spiral(139, 151, 1.18, "#367c72", 6),
          path("M107 108 Q151 113 184 94 M115 82 Q135 91 156 74", stroke="#427e71", width=4),
          path("M77 134 Q91 118 112 121 M121 78 L133 74", stroke="#ddedb8", width=7)]
    for x, y in [(71,155),(80,178),(101,193),(155,202),(183,186),(203,152),(188,121),(157,116)]:
        p.append(ellipse(x,y,6,4,"#e2d49c", "#7c9b7e",2))
    return p


def trochus():
    p = body("#c7a1af", "#977a98")
    p += [path("M59 199 L78 166 L96 136 L114 107 L131 79 L146 50 L160 78 L178 106 L195 137 L213 167 L229 198 Q217 222 144 224 Q79 224 59 199 Z", "#eed7b5"),
          path("M71 183 Q147 206 219 182 L229 198 Q218 210 194 214 L181 203 L164 218 L147 207 L129 217 L111 205 L95 212 L82 199 L65 205 Z M89 150 Q147 167 204 152 L212 166 L197 170 L184 161 L169 174 L152 166 L136 175 L121 163 L108 169 L97 158 L80 163 Z M108 117 Q149 132 185 119 L193 133 L177 133 L165 126 L149 138 L134 127 L120 133 L101 132 Z M128 86 L142 94 L156 85 L171 96 L177 105 Q148 116 114 106 Z", "#bf6f7b", "none"),
          path("M64 199 Q144 224 224 199 M80 166 Q147 188 211 167 M98 137 Q145 154 194 137 M116 108 Q147 121 177 108", stroke="#8f6470", width=3),
          path("M128 98 L143 69 M90 170 L99 156", stroke="#fff2d7", width=5)]
    return p


def astrea():
    p = body("#b8c8a8", "#849e8d")
    p += [path("M59 198 L74 178 L61 158 L91 158 L95 133 L87 119 L114 120 L121 92 L145 61 L157 89 L179 119 L203 116 L194 141 L215 158 L239 158 L225 183 L231 201 Q196 220 146 218 Q94 221 59 198 Z", "#d2c796"),
          path("M76 179 Q145 196 224 181 M96 135 Q144 153 191 137 M119 119 Q147 127 178 119 M91 158 Q153 171 214 158", stroke="#8e9b75", width=5),
          path("M62 198 Q101 217 144 212 Q194 216 229 201", stroke="#faf0c3", width=6),
          path("M109 151 L99 173 M134 157 L127 180 M159 157 L159 183 M183 154 L193 177 M134 114 L133 126 M155 114 L160 129", stroke="#f4e5b7", width=7)]
    return p


def nassarius():
    p = body("#d0ac8c", "#a78573")
    p += [path("M78 208 Q56 187 73 159 L95 134 L108 109 L129 93 L141 68 L155 96 L174 113 L180 139 Q210 165 203 187 Q201 208 179 215 Z", "#b18a76"),
          path("M74 166 Q126 183 201 165 M77 187 Q129 205 198 187 M96 137 Q138 152 178 137 M109 112 Q140 124 174 114", stroke="#e6c5a4", width=5),
          path("M95 154 L87 194 M115 160 L108 203 M138 165 L134 210 M162 166 L164 209 M185 163 L190 198 M118 128 L114 145 M138 131 L137 148 M157 131 L162 146", stroke="#e6c5a4", width=4),
          path("M242 201 Q259 182 262 145 Q269 138 273 148 Q278 175 257 209", "#d0ac8c", INK, 3),
          path("M125 106 L135 96", stroke="#f9dfbf", width=5)]
    return p


def cerith():
    p = body("#acb0bd", "#7c889e")
    p += [path("M89 214 L79 199 L87 176 L90 158 L103 138 L106 116 L117 96 L122 74 L137 43 Q144 22 149 42 L154 73 L160 94 L168 115 L174 136 L182 156 L188 178 L199 198 L191 214 Z", "#827f91"),
          path("M88 178 Q132 193 188 177 M93 158 Q138 171 181 157 M104 138 Q137 149 174 136 M109 116 Q140 126 168 115 M119 96 Q142 106 160 95 M124 74 Q140 84 154 73", stroke="#bbb4b4", width=4),
          path("M87 201 Q139 218 195 200", stroke="#bbb4b4", width=6)]
    for y, xs in [(194,[98,118,139,160,181]),(173,[105,125,146,169]),(152,[115,134,156]),(131,[122,140,157]),(109,[129,146]),(88,[135,148])]:
        for x in xs:
            p.append(ellipse(x,y,4,5,"#d0c3bb", "#635e74",1.5))
    p.append(path("M137 62 L141 48", stroke="#e7d7cd", width=4))
    return p


def cowrie(tiger=False):
    color = "#e0d2be" if tiger else "#e8e6b7"
    p = body("#bfacba" if tiger else "#b4c8bb", "#8e8099" if tiger else "#7f9c96")
    # Neither cowrie has an exposed spire: both have a smooth, rolled lip.
    d = ("M56 193 C39 159 62 119 99 105 C135 87 179 96 203 119 Q230 150 217 187 Q209 213 172 216 L96 216 Q67 214 56 193 Z" if tiger else
         "M59 191 Q50 170 66 150 L83 131 Q99 114 117 122 Q140 110 162 120 Q183 114 197 133 L215 155 Q230 176 216 194 Q205 211 183 210 L90 212 Q69 212 59 191 Z")
    p += [path(d, color),
          path("M60 190 Q120 215 215 189 L210 203 Q174 224 96 216 Q71 214 60 200 Z", "#c3b7a4" if tiger else "#b7b88e", "none"),
          path("M74 194 Q140 212 205 193", stroke="#faf4d9", width=7)]
    if tiger:
        for x,y,rx,ry in [(84,135,8,6),(112,119,7,5),(139,112,5,4),(170,119,9,7),(192,138,6,9),(202,165,7,6),(180,180,10,7),(150,191,7,6),(119,184,9,8),(90,176,7,5),(72,157,5,6),(111,151,8,6),(141,141,8,7),(162,162,7,5)]:
            p += [ellipse(x,y,rx,ry,"#735f67"),ellipse(x-1,y-1,rx*.4,ry*.35,"#b7a18b")]
    else:
        p += [path("M83 150 Q143 129 195 153 M77 168 Q139 146 205 173", stroke="#c9c99b", width=5),
              ellipse(141,163,19,13,"#d6d5a6", "#adb690",3)]
    p.append(path("M92 133 Q109 121 126 124", stroke="#fff8e7", width=7))
    return p


def sundial():
    p = body("#c3aa95", "#9a8677")
    p += [path("M54 187 Q53 150 95 132 Q139 111 179 128 Q215 141 224 174 L224 192 Q192 219 140 219 Q83 220 54 197 Z", "#e8c19a"),
          ellipse(139,171,85,44,"#f3d8af", INK),
          ellipse(138,166,64,31,"#e9c396", "#9b7562",3),
          ellipse(138,163,43,22,"#efd8b1", "#9b7562",3),
          spiral(137,160,.43,"#91644e",7),
          path("M60 190 Q141 227 220 190", stroke="#bd8464", width=4)]
    for d in ["M64 156 L78 164", "M81 141 L94 152", "M105 131 L113 144", "M131 128 L132 139", "M157 129 L154 142", "M181 136 L173 147", "M203 147 L190 156", "M217 162 L202 166", "M220 180 L203 178", "M203 198 L192 185", "M173 210 L167 193", "M143 214 L143 198", "M111 212 L116 195", "M83 202 L94 187", "M61 186 L77 179"]:
        p.append(path(d,stroke="#a96b4f",width=7))
    p.append(path("M93 141 Q107 134 120 134",stroke="#fff0d8",width=5))
    return p


def strawberry():
    p = body("#deb8af", "#b58d91")
    p += [path("M64 197 L87 165 L107 141 L116 113 L136 88 L146 65 L160 90 L179 114 L185 140 L207 165 L224 197 Q208 220 143 220 Q83 220 64 197 Z", "#dc8b8c"),
          path("M68 196 Q142 222 219 196 M87 166 Q146 183 205 165 M108 141 Q144 155 184 140 M118 114 Q148 128 177 114", stroke="#a66076",width=4)]
    for y,xs in [(197,[87,109,132,156,179,202]),(175,[100,122,145,168,191]),(153,[121,144,167]),(131,[130,151,168]),(108,[138,155])]:
        for x in xs:
            p.append(ellipse(x,y,5.2,4.7,"#ffe2c4", "#f0b8a6",1.5))
    p.append(path("M139 95 L145 85",stroke="#fff0d8",width=5))
    return p


def wentletrap():
    p = body("#bad1d1", "#8daeb5")
    p += [path("M81 211 Q63 195 79 173 Q76 153 97 142 Q93 121 112 108 Q107 91 124 79 Q119 63 134 51 L143 31 L151 51 Q169 65 157 80 Q178 93 168 110 Q193 124 182 143 Q207 156 199 176 Q222 195 199 213 Z", "#f1e5cf"),
          path("M80 176 Q128 192 199 176 M98 143 Q139 157 182 143 M113 109 Q140 125 168 110 M124 80 Q143 92 157 80 M133 53 Q145 64 154 54", stroke="#a9acb4",width=4),
          path("M92 210 Q77 190 91 178 M109 214 Q93 192 108 182 M128 217 Q114 193 127 187 M148 217 Q132 194 147 188 M169 216 Q152 195 167 186 M188 212 Q175 192 186 182 M107 173 Q95 155 108 147 M127 179 Q114 160 126 151 M148 180 Q136 160 146 154 M170 178 Q158 159 169 151 M120 138 Q110 123 122 116 M140 143 Q129 126 140 119 M160 141 Q151 125 160 118 M132 104 Q123 91 134 85 M150 106 Q144 94 153 87", stroke="#ffffff",width=7),
          path("M93 210 L97 207 M131 213 L136 209 M171 211 L177 207",stroke="#bdc8c6",width=2)]
    return p


DRAWERS = {
    "mystery-snail": mystery,
    "rabbit-snail": rabbit,
    "zebra-nerite": zebra,
    "horned-nerite": horned,
    "ramshorn-snail": ramshorn,
    "malaysian-trumpet-snail": trumpet,
    "assassin-snail": assassin,
    "japanese-trapdoor-snail": trapdoor,
    "bladder-snail": bladder,
    "pond-snail": pond,
    "turbo-snail": turbo,
    "trochus-snail": trochus,
    "astrea-snail": astrea,
    "nassarius-snail": nassarius,
    "cerith-snail": cerith,
    "money-cowrie": cowrie,
    "tiger-cowrie": lambda: cowrie(tiger=True),
    "sundial-snail": sundial,
    "strawberry-top-snail": strawberry,
    "wentletrap-snail": wentletrap,
}


def generate(entries, output_path):
    output_path = Path(output_path)
    output_path.mkdir(parents=True, exist_ok=True)
    if len(entries) != 20 or {item["id"] for item in entries} != set(DRAWERS):
        raise ValueError("Snail plan must contain exactly the twenty authored species")
    for item in entries:
        title = escape(item["name"])
        svg = (f'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="280" viewBox="0 0 320 280">\n'
               f'<title>{title}</title>\n<desc>Original cartoon aquatic snail, transparent background.</desc>\n' +
               "\n".join(DRAWERS[item["id"]]()) + "\n</svg>\n")
        ET.fromstring(svg)
        (output_path / f'{item["id"]}.svg').write_text(svg, encoding="utf-8")
