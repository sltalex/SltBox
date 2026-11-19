"""Anatomy-led, individually specified cartoon insect illustrations."""

from __future__ import annotations

from .common import Drawing, INK, CREAM


def _pair(d: Drawing, paint) -> None:
    """Paint a left-side component and its mirror, retaining clean silhouettes."""
    part = Drawing(features=())
    paint(part)
    d.parts.extend(part.parts)
    d.silhouettes.extend(part.silhouettes)
    d.add('<g transform="translate(128 0) scale(-1 1)">' + ''.join(part.parts) + '</g>',
          '<g transform="translate(128 0) scale(-1 1)">' + ''.join(part.silhouettes) + '</g>')


def _butterfly_body(d: Drawing, color: str = "#66524a", fluffy: bool = False) -> None:
    d.line("M60 37Q50 19 44 17M68 37Q78 19 84 17", width=2.5, silhouette=True)
    d.spot(44, 17, 2.3, INK)
    d.spot(84, 17, 2.3, INK)
    d.ellipse(64, 70, 6 if not fluffy else 10, 31, color, width=3)
    if fluffy:
        d.detail("M55 51 51 47 55 61 51 66 56 73 55 82 61 79 66 84 72 78 76 71 73 63 77 58 72 53Z", color)
    d.ellipse(64, 43, 8 if not fluffy else 10, 10, color, width=3)
    d.eye(60, 41, 2)
    d.eye(68, 41, 2)
    d.line("M61 69h6m-6 10h6m-5 9h4", "#c4aa87", 1.8)


def _legs(d: Drawing, *, color: str = INK, long: bool = False) -> None:
    if long:
        data = "M46 57 24 43 10 52M44 75 19 76 7 94M47 94 27 111 21 122"
    else:
        data = "M44 53 28 42 19 49M40 72 22 73 16 86M43 92 29 106 31 116"
    _pair(d, lambda p: p.line(data, color, 3.5, silhouette=True))


def _beetle(d: Drawing, body: str, head: str, *, rx: float = 29,
            ry: float = 37, center: float = 78, head_y: float = 37) -> None:
    _legs(d)
    d.ellipse(64, center, rx, ry, body)
    d.path(f"M{64-rx+1:g} {center-10:g}Q64 {center-ry-17:g} {64+rx-1:g} {center-10:g}L{64+rx-3:g} {center-22:g}Q64 {center-ry-13:g} {64-rx+3:g} {center-22:g}Z", head, width=3)
    d.ellipse(64, head_y, 20, 14, head, width=3)
    d.line(f"M64 {center-ry+6:g}V{center+ry-3:g}", width=2.5)
    d.eye(52, head_y-2, 2.5)
    d.eye(76, head_y-2, 2.5)


def _flying_legs(d: Drawing, color: str = INK) -> None:
    d.line("M51 89 43 106 33 110M66 91 72 111 83 115M85 82 99 101 111 101", color, 3, silhouette=True)


def _wing(d: Drawing, data: str, fill: str = "#e3f0e8", *, veins: str = "",
          opacity: float = 1) -> None:
    d.path(data, fill, width=3, opacity=opacity)
    if veins:
        d.line(veins, "#94b5aa", 1.6, opacity=.85)


def _butterfly() -> Drawing:
    d = Drawing(features=("scalloped pink forewings", "orange lower wings", "cream oval wing eyes"))
    def wing(p):
        p.path("M60 55C47 25 30 19 16 28 3 39 10 60 35 66 13 69 8 88 22 102 36 116 56 97 61 77Z", "#e97f9c")
        p.detail("M58 68C34 63 19 74 22 91c3 15 21 14 36-12Z", "#f2ad5c")
        p.detail("M49 46C35 32 21 31 17 40c-3 9 9 17 24 18Z", "#f8bcc8")
        p.ellipse(29, 43, 7, 10, CREAM, "none", silhouette=False)
        p.spot(29, 43, 3.5, "#8c4774")
        p.spot(28, 88, 4.4, CREAM)
        p.spot(39, 96, 3.2, CREAM)
        p.line("M58 63 24 38M57 69 18 77M57 75 35 101", "#a75270", 1.8)
    _pair(d, wing)
    _butterfly_body(d)
    return d


def _honeybee() -> Drawing:
    d = Drawing(features=("golden abdomen with dark bands", "fuzzy brown thorax", "two clear rounded wings"))
    _wing(d, "M55 59C26 51 20 23 35 20 48 16 64 34 66 55Z", veins="M57 52 35 26M48 39 35 39")
    _wing(d, "M67 55C64 24 81 13 93 23 104 33 91 51 75 61Z", veins="M73 52 85 24M78 40 94 34")
    _flying_legs(d)
    d.ellipse(48, 76, 32, 25, "#edb744")
    d.detail("M27 57Q22 75 30 94l10 5Q30 76 39 53Z", "#66503b")
    d.detail("M47 51Q40 75 51 101l11-1Q52 77 59 54Z", "#66503b")
    d.path("M18 74 6 80l13 6", "#6a5140", width=3)
    d.circle(78, 69, 22, "#9e764b")
    d.detail("M61 57 57 62 61 67 57 73 64 76 62 83 70 82 74 89 79 84 85 87 88 79 95 78 91 70 95 63 87 61 84 54 77 57 70 52Z", "#bd9459")
    d.circle(99, 66, 16, "#74543c")
    d.line("M95 50 89 33 84 31M105 51 111 35 118 34", width=2.5, silhouette=True)
    d.eye(104, 63, 4, True)
    d.line("M105 76q6 3 10-1", "#3e3933", 2)
    d.detail("M69 57 73 55 77 58 81 55 85 60", "none", "#ead7a2", 2)
    return d


def _stag_beetle() -> Drawing:
    d = Drawing(features=("large branching antler mandibles", "broad square head", "chestnut split elytra"))
    _legs(d, long=True)
    d.path("M42 63C29 78 31 110 47 118h34c16-8 18-40 5-55Z", "#9b5436")
    d.detail("M42 68Q35 94 48 111l10 3V65Z", "#bd7951")
    d.detail("M72 66v49l9-5q9-19 1-40Z", "#75402e")
    d.line("M64 64v53M47 77l-3 22M81 77l3 22", "#623b2d", 2)
    d.path("M37 44Q64 31 91 44l-3 24Q64 77 40 67Z", "#633b2e")
    d.path("M42 43 33 30 31 10 41 18 47 30 51 20 55 27 52 40Z", "#74462f")
    d.path("M86 43 95 30 97 10 87 18 81 30 77 20 73 27 76 40Z", "#74462f")
    d.line("M40 49 24 35 18 37M88 49 104 35 110 37", width=2.5, silhouette=True)
    d.eye(45, 49, 3, True)
    d.eye(83, 49, 3, True)
    return d


def _water_strider() -> Drawing:
    d = Drawing(features=("extremely long splayed water-walking legs", "thin cigar abdomen", "short grasping forelegs"))
    d.line("M56 66 30 35 8 22M72 66 98 35 120 22M54 79 26 88 6 115M74 79 102 88 122 115", "#6f6553", 3.5, silhouette=True)
    d.line("M56 55 39 51 31 64M72 55 89 51 97 64", width=3, silhouette=True)
    d.ellipse(64, 83, 8, 31, "#887955")
    d.detail("M60 63q-5 28 4 48V62Z", "#d2bb7e")
    d.ellipse(64, 53, 11, 13, "#4f5446", width=3)
    d.ellipse(64, 35, 10, 10, "#5d5b49", width=3)
    d.line("M58 29 47 15 38 11M70 29 81 15 90 11", width=2.2, silhouette=True)
    d.eye(58, 33, 2.8, True)
    d.eye(70, 33, 2.8, True)
    d.line("M61 65v30m6-26v25", "#c7b779", 1.5)
    return d


def _butterflies() -> dict[str, Drawing]:
    result = {}
    d = Drawing(features=("long swallowtail hindwing streamers", "pale yellow wings with tiger stripes", "blue and red lower-wing marks"))
    def swallowtail(p):
        p.path("M61 56Q43 13 14 17L8 40Q23 58 49 67C25 62 18 78 25 91l13 2-2 26 13-16 10-28Z", "#f4dc7c")
        p.detail("M14 19 22 39l14 5-7-17ZM31 28l12 24 10 4-10-23ZM9 40l13 15 17 3-9-10ZM24 75l9 12 12 2 11-16-11 7-8-11Z", "#4b4438")
        p.line("M58 61 20 27M56 69 31 88M50 87 40 108", "#bda45d", 1.8)
        p.spot(40, 84, 4.7, "#68b7c7")
        p.spot(51, 78, 4, "#de705d")
    _pair(d, swallowtail)
    _butterfly_body(d, "#514b3d")
    result["swallowtail-butterfly"] = d

    d = Drawing(features=("elongated orange forewings", "bold black vein network", "white-spotted black wing margins"))
    def monarch(p):
        p.path("M61 55C48 23 28 9 13 17 4 29 8 50 18 58l29 12C21 66 13 80 18 94 25 112 50 107 60 77Z", "#eb9146")
        p.detail("M13 17 20 25Q27 38 28 47l-9 9-9-8Q2 29 13 17ZM18 93Q29 111 47 99l-4 10Q22 117 16 100ZM20 58 52 63l9 9-14 1Z", "#393c3c")
        p.line("M59 60 21 23M54 58 29 43l-1-14M49 68 24 84l-5 12M55 77l-9 23M33 79l3 24M27 44 17 48", "#41413a", 2.5)
        for x, y in ((13,27),(15,36),(18,44),(22,52),(22,96),(30,103),(40,104)):
            p.spot(x, y, 2.1, CREAM)
    _pair(d, monarch)
    _butterfly_body(d, "#3f4140")
    result["monarch-butterfly"] = d

    d = Drawing(features=("broad angular electric-blue wings", "dark chocolate wing borders", "pale sky-blue inner flashes"))
    def morpho(p):
        p.path("M61 57 41 15 17 11 6 30 13 53 44 68C16 64 9 79 18 96L39 108 58 84Z", "#369ed3")
        p.detail("M17 11 28 17 19 30 22 47 13 53 6 30ZM18 96l22 5 14-18 4 1-8 20-11 4ZM41 15l20 42-12-10Z", "#3f4351")
        p.detail("M31 29 49 55 32 49 22 34ZM33 75l17-1-13 21-13-7Z", "#93d6ef")
        p.line("M59 62 20 21M55 68 16 39M55 77 23 89M44 43l-9-23", "#2376a5", 1.8)
        for x, y in ((14,29),(17,43),(25,98),(36,103)):
            p.spot(x, y, 2, "#c8e5ec")
    _pair(d, morpho)
    _butterfly_body(d, "#3f4b59")
    result["blue-morpho"] = d

    d = Drawing(features=("soft white rounded wings", "charcoal forewing tips", "two dark forewing spots"))
    def white(p):
        p.path("M61 57C50 22 30 14 16 25 3 37 10 56 45 69C17 66 12 86 25 99 39 111 56 96 61 78Z", "#f4f1d7")
        p.detail("M16 25Q4 35 11 48l13 2 1-12 8-7Z", "#59615a")
        p.spot(32, 52, 5.7, "#62695c")
        p.spot(39, 68, 4.1, "#78816c")
        p.detail("M30 82q-10 8-2 13 12 6 23-11Z", "#e4e8c3")
        p.line("M58 63 28 29M57 70 23 88M49 47 23 57", "#c1c9ad", 1.5)
    _pair(d, white)
    _butterfly_body(d, "#71816a")
    result["cabbage-white"] = d

    d = Drawing(features=("four large concentric peacock eyespots", "deep red scalloped wings", "blue-violet eye halos"))
    def peacock(p):
        p.path("M61 58C49 26 29 15 12 24L7 42 17 57 44 68 25 64 13 77 17 92 31 104 49 97 60 80Z", "#b94f45")
        p.detail("M12 24 19 30 18 43 11 49 7 42ZM17 92l14 12 18-7 4-8-14 7-16-12Z", "#70493f")
        p.ellipse(29, 42, 12, 13, "#e2bd69", "#704c49", 2, False)
        p.ellipse(29, 42, 8, 9, "#557abb", "none", silhouette=False)
        p.spot(29, 43, 5, "#333b52")
        p.spot(26, 39, 2.5, CREAM)
        p.ellipse(32, 84, 10, 11, "#6682b8", "#d9bc73", 3, False)
        p.spot(32, 84, 5.5, "#383c50")
        p.spot(29, 81, 2, CREAM)
        p.line("M58 63 44 37M55 73 43 91", "#8d413a", 1.8)
    _pair(d, peacock)
    _butterfly_body(d, "#5d4137")
    result["peacock-butterfly"] = d

    d = Drawing(features=("jagged copper-orange wings", "checkerboard dark markings", "pearl spots on hindwing edges"))
    def fritillary(p):
        p.path("M61 57 44 22 21 18 8 30 12 49 25 61 48 69 26 66 13 78 20 98 35 105 52 94 61 78Z", "#d88a42")
        for x,y,r in ((27,31,3),(39,38,3.5),(22,46,4),(36,54,3),(46,61,3),(26,79,3.5),(39,87,3.3)):
            p.spot(x,y,r,"#704e3b")
        p.line("M58 62 25 23M55 70 18 37M55 76 27 95", "#8e5d3b", 1.8)
        p.detail("M19 87 23 96 35 101 48 94l-3-6-10 6-8-5Z", "#a16b42")
        for x,y in ((24,93),(33,98),(43,94)):
            p.ellipse(x,y,2.6,4,CREAM,"none",silhouette=False)
    _pair(d,fritillary)
    _butterfly_body(d,"#71503c")
    result["fritillary"] = d

    d = Drawing(features=("small triangular wings held at two angles", "stout furry body", "golden translucent window spots"))
    def skipper(p):
        p.path("M60 56 47 15 31 24 32 59 10 71 17 95 58 81Z", "#a4713e")
        p.detail("M46 23 53 50 42 45 39 29ZM22 77 42 65 38 86 21 91Z", "#d4a65c")
        p.detail("M41 34h5v8h-5ZM45 48h6v7h-6ZM25 77l8-4 1 7-8 4Z", CREAM)
        p.line("M58 62 44 21M56 71 19 88", "#76543a", 1.8)
    _pair(d,skipper)
    _butterfly_body(d,"#8b6947",True)
    d.line("M58 31 49 24l-5 2M70 31 79 24l5 2",width=2,silhouette=True)
    result["skipper"] = d

    d = Drawing(features=("slender transparent forewings", "dark brown vein frames", "amber wing shoulders and white tip bands"))
    def glasswing(p):
        p.path("M61 57C48 24 33 12 22 20 10 32 17 58 46 70 22 70 16 84 26 98 39 109 56 95 61 80Z", "#e4f2ec", "#735543", 4, opacity=.74)
        p.detail("M22 20 31 23 24 34 26 48 18 49Q12 29 22 20Z", "#ae764a")
        p.detail("M20 32 25 29 33 38 28 44 22 40Z", "#fff8e9")
        p.line("M58 63 25 24M56 70 22 47M56 76 30 98M39 39 41 61M38 78l3 18", "#967c61", 1.5)
        p.detail("M28 93 33 100 48 94 55 82 50 83 43 91Z", "#be8d58", opacity=.6)
    _pair(d,glasswing)
    _butterfly_body(d,"#735742")
    result["glasswing"] = d
    return result


def _moths() -> dict[str, Drawing]:
    result = {}
    d=Drawing(features=("broad brown roof-shaped wings", "cream zigzag crossbands", "feathery antennae"))
    def common(p):
        p.path("M61 52 39 20 17 26 7 60 22 75 13 91 40 106 60 79Z","#a98b70")
        p.detail("M12 54 28 59 34 50 49 62 58 58 60 67 47 71 34 61 27 70 8 64Z","#dfc99f")
        p.detail("M20 89 34 91 43 82 54 85 47 96 40 106Z","#806953")
        p.spot(29,43,4.5,"#d5b987")
        p.line("M59 64 26 32M56 75 27 96","#785f50",1.8)
    _pair(d,common)
    _butterfly_body(d,"#7f6956",True)
    d.line("M58 33 44 18m9 8-9 1m5-6-7 0M70 33 84 18m-9 8 9 1m-5-6 7 0","#917d63",2.5,silhouette=True)
    result["moth"]=d

    d=Drawing(features=("pale green crescent wings", "very long twisting hindwing tails", "small transparent eyespots"))
    def luna(p):
        p.path("M61 53C43 22 22 12 10 26L7 48 25 65 49 67C27 64 21 78 30 89L40 90 33 118 46 108 58 79Z","#a8cc83")
        p.detail("M10 26Q27 21 48 46l13 7-9-17Q27 8 10 22Z","#987c89")
        p.ellipse(29,48,6,7,"#e6dfb0","#8a8e61",2,False)
        p.spot(29,48,2.5,"#667855")
        p.ellipse(39,77,5,6,"#e4ddaf","#7d9061",2,False)
        p.line("M57 61 22 28M55 72 31 84M48 85l-9 27","#7aa36b",1.6)
    _pair(d,luna)
    _butterfly_body(d,"#d8e0b8",True)
    result["luna-moth"]=d

    d=Drawing(features=("long pointed swept-back forewings", "pink barred hindwings", "thick streamlined tapered body"))
    def hawk(p):
        p.path("M60 49 19 19 7 81 43 68 26 101 57 88Z","#8c806a")
        p.detail("M43 68 26 101 51 92 58 76Z","#d48a8d")
        p.detail("M46 75 34 84l-4 8 22-10ZM48 86l-6 9 8-3Z","#5a514b")
        p.line("M55 53 15 69M46 44 21 65M54 63 30 64","#b9aa8b",2)
    _pair(d,hawk)
    d.ellipse(64,75,11,36,"#88775f")
    d.path("M54 88 64 120 74 88","#a89073",width=3)
    d.line("M57 70h14m-16 11h18m-15 11h12m-10 10h8","#534c43",2.5)
    d.circle(64,40,11,"#938066",width=3)
    d.line("M58 33 46 18M70 33 82 18",width=2.5,silhouette=True)
    d.eye(58,39,2.5);d.eye(70,39,2.5)
    result["hawk-moth"]=d

    d=Drawing(features=("fluffy cream body", "rounded ivory wings with tan veins", "large comb-like brown antennae"))
    def silk(p):
        p.path("M60 55C43 25 21 22 10 43 3 60 18 72 42 75 19 79 18 99 34 107 48 114 58 94 61 78Z","#efe4c7")
        p.line("M57 64 20 39M52 67 11 56M55 78 28 96M33 50 27 69","#c8b28b",2)
        p.detail("M18 60 30 65 36 61 48 69 56 67 58 73 47 77 35 69 30 73 17 68Z","#d7c29d")
        p.spot(35,49,3.5,"#baa183")
    _pair(d,silk)
    _butterfly_body(d,"#e4d3b1",True)
    d.line("M57 34 42 17m10 10-13-1m10-5-11-3M71 34 86 17m-10 10 13-1m-10-5 11-3","#9f8667",3,silhouette=True)
    result["silk-moth"]=d

    d=Drawing(features=("enormous russet snake-head wing tips", "large pale triangular wing windows", "scalloped orange-and-cream margins"))
    def atlas(p):
        p.path("M60 53 45 21 20 12 7 22 10 39 30 46 13 57 10 84 26 108 48 102 60 78Z","#b96945")
        p.detail("M7 22 22 20 36 32 30 39 12 35Z","#d9b781")
        p.spot(17,24,3.2,"#4e4841")
        p.detail("M37 41 25 56 49 61Z",CREAM,"#80593f",2)
        p.detail("M34 76 25 92 47 90Z",CREAM,"#80593f",2)
        p.line("M12 71 29 66 39 70 54 65M16 92l12 9 14-4 11-12","#f1cf91",4)
        p.line("M12 79 27 75 40 78 53 73","#654d45",2)
    _pair(d,atlas)
    _butterfly_body(d,"#9e6b49",True)
    result["atlas-moth"]=d

    d=Drawing(features=("four large black-and-blue concentric eyespots", "rounded grey-brown wings", "rose and cream wavy borders"))
    def emperor(p):
        p.path("M61 53C40 16 14 16 7 42L14 65 38 70C16 68 8 87 23 105 36 118 56 101 61 79Z","#a78c80")
        p.line("M12 41 23 37 33 42 44 37 54 47M17 91 28 99 41 97 53 83","#e1c1ac",5)
        p.line("M13 52 25 48 40 52 53 46M22 104l16 5 13-9","#8d5f6c",2.5)
        for x,y,r in ((31,53,11),(34,86,9)):
            p.spot(x,y,r+2,"#d8b989");p.spot(x,y,r,"#667898");p.spot(x,y,r-4,"#333b47");p.spot(x-2,y-3,2,CREAM)
    _pair(d,emperor)
    _butterfly_body(d,"#776355",True)
    result["emperor-moth"]=d
    return result


def _pollinators() -> dict[str, Drawing]:
    result={}
    d=Drawing(features=("round densely furry abdomen", "broad ochre neck band", "white tail tuft"))
    _wing(d,"M59 56C31 49 26 19 42 18 59 17 69 39 68 55Z",veins="M59 46 42 25")
    _wing(d,"M72 54C75 28 96 24 103 38 109 50 91 61 77 63Z",veins="M80 52 97 38")
    _flying_legs(d)
    d.path("M21 57 16 64 11 66 15 74 10 80 15 86 15 94 23 97 29 105 39 104 46 111 54 106 65 108 71 99 79 96 79 85 84 77 79 67 79 59 68 54 62 47 50 49 42 44 34 50Z","#3d4140")
    d.detail("M31 51Q21 74 31 102l16 7Q35 78 45 47Z","#e0b744")
    d.detail("M16 66 11 75l4 13 4 8 9 7Q20 78 28 55l-7 2Z","#eee9d6")
    d.circle(78,68,25,"#474643")
    d.detail("M62 48 75 44 90 50 97 61 88 66 79 59 69 66 56 62Z","#dcad42")
    d.circle(103,70,15,"#41413c",width=3)
    d.eye(107,67,4,True)
    d.line("M99 57 95 42 89 39M109 58 117 45",width=2.5,silhouette=True)
    d.line("M39 56 36 65m11-7-1 9M64 54l4 6m6-8 3 6","#e6cb72",1.7)
    result["bumblebee"]=d

    d=Drawing(features=("large glossy blue-black abdomen", "golden fuzzy thorax", "smoky violet wings"))
    _wing(d,"M59 55C29 39 29 14 46 17 65 19 73 42 70 58Z","#b5b0c5",veins="M64 47 44 23M52 32 41 38")
    _wing(d,"M74 57C80 29 101 27 108 42 112 56 93 68 78 65Z","#9e9db5",veins="M82 57 103 42")
    _flying_legs(d,"#45434d")
    d.ellipse(43,82,33,25,"#41434e")
    d.detail("M18 75Q29 57 51 62l10 8Q36 62 25 86Z","#707587")
    d.line("M24 91q17 8 34 0M31 99q15 4 24-1","#85889a",2)
    d.circle(80,72,24,"#c7a15c")
    d.detail("M63 57 61 66 56 72 63 77 61 85 70 83 77 91 83 85 91 88 93 79 100 75 95 67 96 60 87 59 82 51 74 56Z","#d4b66e")
    d.circle(104,68,16,"#46464e",width=3)
    d.eye(108,65,4,True)
    d.line("M100 54 96 39M111 54 119 42",width=2.5,silhouette=True)
    result["carpenter-bee"]=d

    d=Drawing(features=("slim dark bee with pale abdominal hair bands", "rust-orange pollen brush underneath", "large cutting mandibles"))
    _wing(d,"M56 60C28 45 27 22 40 21 55 20 67 41 66 57Z",veins="M57 50 39 28M47 37l-9 6")
    _wing(d,"M68 57C69 35 86 23 97 32 109 44 89 59 76 63Z",veins="M75 53 92 34")
    _flying_legs(d,"#5b5448")
    d.ellipse(45,79,31,20,"#6e6c5a")
    d.detail("M18 77q13 13 44 9l-3 12-9-4-7 5-7-5-8 3-5-7Z","#d19b4c")
    d.line("M30 62q-6 15 0 32M43 60q-6 16 0 35M56 62q-5 14 0 29","#e3dbb8",4)
    d.ellipse(77,69,19,21,"#85826a")
    d.circle(98,64,17,"#69705a",width=3)
    d.path("M109 71 121 75 112 82 107 77","#a29b77",width=2.5)
    d.line("M92 49 88 36 80 33M102 48 111 35 118 37",width=2.5,silhouette=True)
    d.eye(102,61,4,True)
    d.line("M68 57l2 7m5-10 2 6m6-6 1 7","#ccc5a2",1.8)
    result["leafcutter-bee"]=d

    d=Drawing(features=("very narrow thread waist", "long dangling yellow legs", "brown folded ribbon wings"))
    d.line("M57 69 40 88 35 116M72 64 80 90 93 117M84 57 107 76 119 98", "#c39440",3,silhouette=True)
    _wing(d,"M61 57 22 23Q10 16 12 31L49 73Z","#c0aa82",veins="M54 61 18 28")
    _wing(d,"M71 55 50 17Q42 8 40 21L57 67Z","#cfbc93",veins="M64 56 46 22")
    d.path("M54 75Q25 68 16 84c-8 17 13 28 32 17l20-18Z","#ac703e")
    d.detail("M22 77q-4 13 9 25l10-2q-13-10-11-24ZM47 77l10 4-9 16-9 3Z","#e8bd52")
    d.path("M56 78 80 62l5 7-22 16Z","#6e4b35",width=3)
    d.ellipse(82,59,15,18,"#a77240",width=3)
    d.path("M91 45q17-6 24 8l-2 14-17 3-9-12Z","#bb8b45",width=3)
    d.detail("M100 49h8l5 9-12 1Z","#e6c26c")
    d.eye(106,52,3.5)
    d.line("M99 44 96 27 90 24M110 44 118 29 123 29",width=2.2,silhouette=True)
    result["paper-wasp"]=d

    d=Drawing(features=("large orange hornet head", "dark chest and amber-banded abdomen", "powerful angular mandibles"))
    _wing(d,"M53 60C23 46 17 23 31 21 48 18 65 42 65 60Z","#d5c19b",veins="M55 52 29 27M43 39 32 43")
    _wing(d,"M67 58C63 29 82 18 94 28 106 39 88 60 76 66Z","#d8c9a6",veins="M72 53 87 29")
    _flying_legs(d,"#8c603b")
    d.path("M59 62C39 52 18 60 10 79l7 21 23 5 29-22Z","#dba047")
    d.detail("M17 67 29 61q-8 20 10 41l-14 1Q11 91 17 67ZM45 59l12 3q-4 14 5 22L48 95Q36 79 45 59Z","#5a4436")
    d.ellipse(78,69,21,22,"#68462f")
    d.detail("M65 54q10-10 22-2l4 8-25 5Z","#a96c37")
    d.path("M91 50 103 43 119 53l2 20-12 13-19-7-6-16Z","#d68c3e",width=3)
    d.path("M108 77 122 79l-8 10-8-6Z","#996036",width=2.5)
    d.eye(108,58,4,True)
    d.line("M98 47 94 31 85 27M111 45 118 31 124 33",width=2.8,silhouette=True)
    result["hornet"]=d

    d=Drawing(features=("huge red compound eyes", "short fly antennae", "yellow-and-black banded tapered abdomen"))
    _wing(d,"M52 57C27 46 23 20 39 21 56 21 66 43 63 58Z",veins="M55 48 38 28M46 38 34 41")
    _wing(d,"M67 59C71 32 93 24 104 35 116 49 94 65 75 66Z",veins="M77 55 99 37")
    _flying_legs(d,"#8b7348")
    d.path("M56 66Q23 54 17 78 16 98 45 106L69 84Z","#e2b54b")
    d.detail("M24 66q-6 17 10 34l10 4Q30 82 36 63ZM49 64l12 5q-9 14-2 25l-12 9Q39 84 49 64Z","#4d4940")
    d.ellipse(78,68,19,21,"#72765a")
    d.circle(101,63,20,"#a77453",width=3)
    d.ellipse(104,59,11,15,"#ab6254","#603f35",2,False)
    d.ellipse(90,58,6,12,"#bc7c66","none",silhouette=False)
    d.spot(101,54,3,"#ddae8c")
    d.line("M107 44 111 38M96 43 93 37",width=2.5,silhouette=True)
    d.detail("M66 55q9-8 19 1l-6 5-9-2Z","#b7bc7c")
    result["hoverfly"]=d

    d=Drawing(features=("metallic green thorax", "bronze oval abdomen with dark seams", "large ruby-red fly eyes"))
    _wing(d,"M62 57C32 38 20 13 36 16 57 19 73 43 70 58Z",veins="M60 46 35 23M48 34l-9 9")
    _wing(d,"M72 58C83 34 105 31 112 47 117 61 91 71 78 67Z",veins="M83 58 106 47")
    _flying_legs(d,"#566358")
    d.ellipse(42,82,28,25,"#97804d")
    d.detail("M20 72q12-17 31-9l5 8q-21-4-28 15Z","#b1a863")
    d.line("M24 95q16 8 33-2M23 83q17 9 34-1M32 63l-1 35","#665d41",2.5)
    d.circle(77,69,23,"#668d70")
    d.detail("M62 57 73 50l15 6-10 6-11 1Z","#a8c29a")
    d.circle(101,64,18,"#717853",width=3)
    d.ellipse(106,61,10,14,"#a95d4c","#70483b",2,False)
    d.spot(103,56,3,"#d8a17c")
    d.line("M98 47 96 38M108 47 116 40",width=2.2,silhouette=True)
    result["flower-fly"]=d
    return result


def _beetles() -> dict[str, Drawing]:
    result={}
    d=Drawing(features=("round scarlet split wing covers", "seven large black spots", "black head with white cheek patches"))
    d.line("M39 47 25 33 18 39M33 70 15 68 10 76M40 94 26 110 19 110M89 47 103 33 110 39M95 70 113 68 118 76M88 94 102 110 109 110",width=3,silhouette=True)
    d.circle(64,38,19,"#424743",width=3)
    d.line("M55 23 49 12M73 23 79 12",width=2.5,silhouette=True)
    d.ellipse(64,76,36,40,"#df6552")
    d.detail("M37 55Q27 81 43 101l8-2Q36 76 47 47Z","#ee8c71")
    d.line("M64 38v77",width=3)
    for x,y,r in ((46,58,6),(82,58,6),(42,81,7),(86,81,7),(50,101,5),(78,101,5),(64,43,5)):
        d.spot(x,y,r,"#3f4642")
    d.spot(50,35,4,CREAM);d.spot(78,35,4,CREAM)
    d.eye(58,29,2);d.eye(70,29,2)
    result["ladybug"]=d

    d=Drawing(features=("glowing yellow-green tail lantern", "long dark soft wing covers", "salmon shield over the head"))
    d.circle(64,93,30,"#e4e8a0","none",silhouette=False,opacity=.24)
    d.line("M48 58 29 48 20 58M45 76 24 79 19 90M48 94 30 111 22 111M80 58 99 48 108 58M83 76 104 79 109 90M80 94 98 111 106 111",width=2.8,silhouette=True)
    d.path("M43 56Q41 29 64 29 87 29 85 56l-4 43Q64 116 47 99Z","#4f5d50")
    d.detail("M47 88q17 10 34 0l-2 16q-15 19-30 0Z","#e8e79c")
    d.line("M49 98h30M54 107h20","#aaa95e",1.8)
    d.path("M43 46Q39 21 64 21 89 21 85 46L72 53 56 53Z","#d17d65")
    d.detail("M56 27q8-6 16 0l4 13-12 5-12-5Z","#6b6352")
    d.line("M64 52v35M53 57l-2 22M75 57l2 22","#82916d",2)
    d.line("M55 23 45 11M73 23 83 11",width=2.5,silhouette=True)
    d.eye(56,31,2.4);d.eye(72,31,2.4)
    result["firefly"]=d

    d=Drawing(features=("single broad curved rhinoceros horn", "domed mahogany shell", "thick toothed digging forelegs"))
    _beetle(d,"#84543b","#6b4935",rx=31,ry=34,center=85,head_y=44)
    d.path("M61 44C43 34 47 11 70 7L65 18Q57 25 71 36l4 9Z","#835f42",width=3)
    d.path("M42 56 24 46 19 51 27 55 18 59 29 64 41 65M86 56 104 46 109 51 101 55 110 59 99 64 87 65","#6c4c38",width=2.5)
    d.detail("M40 79q0-14 13-18l6 1-5 49-9-7Z","#a97850")
    d.line("M48 82v21M79 81v22","#b1875a",2)
    d.line("M49 39 36 31 29 34M79 39 92 31 99 34",width=2.5,silhouette=True)
    result["rhinoceros-beetle"]=d

    d=Drawing(features=("narrow pointed jewel-like abdomen", "turquoise and copper metallic stripes", "angular shield-shaped pronotum"))
    _legs(d)
    d.path("M43 46 33 69 43 103 64 121 85 103 95 69 85 46Z","#4d927d")
    d.detail("M45 52 39 69 48 100 61 114V52Z","#75bda4")
    d.detail("M69 51v63l12-16 7-29-6-18Z","#3e777d")
    d.line("M64 48v69M46 62l11 40M82 61 71 102","#c4a260",3)
    d.path("M43 49 40 30 51 24h26l11 6-3 19-21 9Z","#508073",width=3)
    d.ellipse(64,23,15,12,"#618d72",width=3)
    d.line("M52 18 41 8M76 18 87 8",width=2,silhouette=True)
    d.eye(53,23,3);d.eye(75,23,3)
    d.line("M51 34h26M49 40h30","#b0bf7f",1.5)
    result["jewel-beetle"]=d

    d=Drawing(features=("very long jointed curling antennae", "elongated yellow-and-black wing covers", "narrow neck and angular shoulders"))
    _legs(d,long=True)
    d.path("M44 52Q34 69 42 111l22 9 22-9q8-42-2-59Z","#b4a359")
    d.detail("M40 67h48v10H40ZM41 92h46v10H41Z","#454b40")
    d.detail("M49 54 55 55v59l-8-4Z","#d7c375")
    d.line("M64 55v61",width=2.5)
    d.path("M46 51 43 35 52 28h24l9 7-3 16-18 7Z","#535442",width=3)
    d.ellipse(64,26,15,11,"#5c6049",width=3)
    d.line("M53 19 36 8 18 13 8 33M75 19 92 8 110 13 120 33","#65654c",3,silhouette=True)
    d.line("M28 10 32 15M18 13l5 5M100 10l-4 5M110 13l-5 5","#c5b877",2)
    d.eye(53,26,3,True);d.eye(75,26,3,True)
    result["longhorn-beetle"]=d

    d=Drawing(features=("round emerald wing covers", "gold-edged sculpted pronotum", "fan-shaped antenna tips"))
    _beetle(d,"#5f9c6d","#6a9262",rx=33,ry=35,center=82,head_y=35)
    d.detail("M36 74Q39 52 57 49v62Q39 106 36 90Z","#86bd83")
    d.line("M46 61q-8 21 2 43M55 58q-6 23 0 48M74 59q6 24 0 47M83 63q8 20-1 39","#c2bd78",2)
    d.line("M43 51Q64 41 85 51","#d2ba70",3)
    d.line("M49 31 36 19M79 31 92 19",width=2.5,silhouette=True)
    d.path("M37 22 24 12l-1 7 11 8ZM91 22 104 12l1 7-11 8Z","#b4a866",width=2)
    result["scarab"]=d

    d=Drawing(features=("very long down-curved elephant snout", "pear-shaped pebbled wing covers", "elbowed antennae halfway along snout"))
    _legs(d)
    d.path("M48 45Q33 58 34 88q0 31 30 32 30-1 30-32 1-30-14-43Z","#8d8d63")
    d.detail("M47 59q-10 33 6 52l8 4V52Z","#a6a57a")
    d.line("M64 48v68M47 62 45 101M80 62l3 39","#6a7458",2)
    for x,y in ((44,73),(51,89),(45,103),(78,72),(84,89),(77,105)):
        d.spot(x,y,2.4,"#c2bd8c")
    d.ellipse(64,41,16,15,"#818364",width=3)
    d.path("M57 30Q53 13 64 7 75 13 71 30l-7 8Z","#a4a17a",width=3)
    d.line("M58 21 43 16 39 25M70 21 85 16 89 25",width=2.5,silhouette=True)
    d.eye(53,39,3);d.eye(75,39,3)
    result["weevil"]=d

    d=Drawing(features=("huge protruding compound eyes", "long running legs and ivory sickle jaws", "copper-green wings with pale zigzag spots"))
    _legs(d,long=True)
    d.path("M45 55Q34 81 44 111l20 8 20-8q10-30-1-56Z","#5f8772")
    d.detail("M44 65q-7 23 4 43l11 7V60Z","#a1a378")
    d.line("M64 58v57",width=2.5)
    _pair(d,lambda p:p.detail("M44 69l10 4-3 6-9-3ZM42 93l10-5 5 6-9 7Z",CREAM))
    d.path("M48 55 43 42l9-10h24l9 10-5 13Z","#6f7254",width=3)
    d.circle(46,30,10,"#6f8770",width=3);d.circle(82,30,10,"#6f8770",width=3)
    d.path("M52 28 45 14 55 19 61 13l-1 16M76 28l7-14-10 5-6-6 1 16",CREAM,width=2.5)
    d.line("M46 22 31 10M82 22 97 10",width=2,silhouette=True)
    d.eye(44,29,5,True);d.eye(84,29,5,True)
    result["tiger-beetle"]=d

    d=Drawing(features=("heavy black domed beetle body", "shovel-toothed forelegs", "deep parallel wing-cover grooves"))
    _beetle(d,"#555c4c","#5d6250",rx=34,ry=34,center=86,head_y=40)
    d.path("M39 58 21 46 14 50 22 56 12 61 22 65 13 71 31 72 43 65M89 58 107 46 114 50 106 56 116 61 106 65 115 71 97 72 85 65","#696d54",width=3)
    d.detail("M37 83q1-20 20-28l-5 17-5 30Z","#8c9270")
    d.line("M43 79v24M52 72v37M76 72v37M85 79v24","#939b7a",2)
    d.path("M48 30 44 20l10 4 10-5 10 5 10-4-4 10Z","#787c5e",width=2.5)
    d.line("M50 35 36 26 28 31M78 35 92 26 100 31",width=2.5,silhouette=True)
    result["dung-beetle"]=d

    d=Drawing(features=("very long narrow tapered wing covers", "backward-pointing pronotum corners", "fine striped brown shell"))
    _legs(d)
    d.path("M45 52Q38 76 46 109l18 13 18-13q8-33 1-57Z","#9a7956")
    d.detail("M47 58q-7 28 5 49l8 7V55Z","#bd956b")
    d.line("M64 55v63M51 65v34M57 64v44M72 64v44M78 65v34","#745c47",1.8)
    d.path("M42 53 44 31 51 25h26l7 6 2 22-13-7H55Z","#795e43",width=3)
    d.ellipse(64,24,13,10,"#8d6d4a",width=3)
    d.line("M54 18 43 9M74 18 85 9",width=2.5,silhouette=True)
    d.eye(54,23,2.6);d.eye(74,23,2.6)
    d.line("M51 36h26","#b39062",2)
    result["click-beetle"]=d

    d=Drawing(features=("smooth streamlined olive oval shell", "fringed paddle hindlegs", "pale yellow border around wing covers"))
    d.line("M44 49 29 35 19 39M84 49 99 35 109 39M39 71 21 73 14 86M89 71 107 73 114 86",width=3,silhouette=True)
    d.path("M42 91 17 99 9 115l28-8 14-13M86 91l25 8 8 16-28-8-14-13","#8c9364",width=3)
    d.line("M18 103 15 112m9-11-4 11m9-12-4 10M110 103l3 9m-9-11 4 11m-9-12 4 10","#566b50",1.7)
    d.ellipse(64,75,34,43,"#596d55")
    d.ellipse(64,76,28,37,"#7d8d5b","#c7be79",3,False)
    d.detail("M43 65Q47 42 59 42v61Q43 90 43 65Z","#a7ad74")
    d.line("M64 39v73","#465947",2.5)
    d.path("M40 47Q40 23 64 23 88 23 88 47Z","#7b8759",width=3)
    d.ellipse(64,23,15,11,"#6b7752",width=3)
    d.line("M52 18 40 9M76 18 88 9",width=2,silhouette=True)
    d.eye(52,24,3);d.eye(76,24,3)
    result["water-beetle"]=d

    d=Drawing(features=("slender inward-curved rear forceps with separated tips", "long exposed segmented abdomen", "short leathery brown wing covers"))
    d.line("M51 43 29 35 19 45M48 59 27 63 17 78M51 78 32 92 24 105M77 43 99 35 109 45M80 59 101 63 111 78M77 78 96 92 104 105",width=2.8,silhouette=True)
    d.path("M51 67Q45 83 52 98l12 7 12-7q7-15 0-31Z","#8b5f43",width=3)
    d.line("M48 80h32M49 89h30M54 98h20","#523f34",2)
    d.path("M55 99C43 106 42 118 48 122 53 125 59 121 59 115 55 119 51 120 49 117 47 113 52 107 58 104Z","#916645",width=2.5)
    d.path("M73 99C85 106 86 118 80 122 75 125 69 121 69 115 73 119 77 120 79 117 81 113 76 107 70 104Z","#916645",width=2.5)
    d.path("M48 40Q64 33 80 40l2 32-18 9-18-9Z","#aa7c50",width=3)
    d.line("M64 42v34M53 49v17M75 49v17","#795a3d",2)
    d.ellipse(64,32,15,13,"#77513a",width=3)
    d.line("M54 23 40 13 30 16M74 23 88 13 98 16",width=2.5,silhouette=True)
    d.eye(53,31,2.7);d.eye(75,31,2.7)
    result["earwig"]=d
    return result


def _dragon_body(d: Drawing, body: str, thorax: str, *, slim: bool=False,
                 head_y: float=31, tail_y: float=119) -> None:
    width=4 if slim else 7
    d.path(f"M{64-width} 48Q{62-width} 88 61 {tail_y}h6Q{66+width} 88 {64+width} 48Z",body,width=3)
    d.ellipse(64,48,7 if slim else 10,14,thorax,width=3)
    for y in (67,78,89,100,111):
        if y<tail_y-3:d.line(f"M{60 if slim else 58} {y}h{8 if slim else 12}","#536b5d",1.8)
    d.ellipse(57,head_y,7 if slim else 9,8 if slim else 10,thorax,width=2.5)
    d.ellipse(71,head_y,7 if slim else 9,8 if slim else 10,thorax,width=2.5)
    d.eye(55,head_y-1,3.4 if slim else 4,True)
    d.eye(73,head_y-1,3.4 if slim else 4,True)
    d.line("M57 52 47 64M71 52 81 64M58 59 49 73M70 59 79 73",width=2,silhouette=True)


def _dragonflies() -> dict[str, Drawing]:
    result={}
    d=Drawing(features=("four wide transparent wings held level", "turquoise ringed abdomen", "large separated blue compound eyes"))
    def common(p):
        _wing(p,"M59 48C37 28 13 23 7 35 3 48 32 56 58 56Z","#d6e9df",veins="M57 51 13 37M42 45l-5 9M29 40l-5 9M18 36l-5 7")
        _wing(p,"M59 60C31 55 8 64 10 78 14 91 39 78 59 68Z","#d0e3d7",veins="M56 64 16 75M39 67l-5 10M25 70l-4 10")
        p.detail("M18 31h8v4h-8Z","#936347")
    _pair(d,common)
    _dragon_body(d,"#6ca9a0","#719d94")
    result["dragonfly"]=d

    d=Drawing(features=("bright red thick abdomen", "short amber-stained rounded wings", "scarlet head and wing bases"))
    def red(p):
        _wing(p,"M58 49Q24 23 9 37 1 52 58 58Z","#e9dec1",veins="M55 53 14 41M37 43l-5 10")
        _wing(p,"M59 61Q14 55 11 73 10 91 59 70Z","#e4d8b5",veins="M56 65 17 75M35 68l-7 11")
        p.detail("M58 49 43 43 40 53 57 58ZM57 61 42 62l2 11 14-5Z","#d8a052",opacity=.72)
        p.detail("M17 35h8v4h-8Z","#92593f")
    _pair(d,red)
    _dragon_body(d,"#cc6850","#b67459",tail_y=112)
    d.line("M64 68v36","#e39669",2)
    result["red-dragonfly"]=d

    d=Drawing(features=("long blue abdomen with black central stripe", "large emerald chest", "long narrow swept wings"))
    def emperor(p):
        _wing(p,"M58 47 13 18Q3 16 7 31L55 55Z","#d9e8dd",veins="M54 49 12 24M39 39 34 46M25 31l-4 8")
        _wing(p,"M56 61 13 52Q2 55 10 70L57 69Z","#d3e5d6",veins="M54 64 11 61M34 60l-5 9M20 57l-4 9")
        p.detail("M12 20h8v3h-8Z","#74654b")
    _pair(d,emperor)
    _dragon_body(d,"#609fb1","#7aa36b",head_y=28)
    d.detail("M62 61h4l1 51h-6Z","#3f6570")
    d.line("M59 40 61 51M69 40 67 51","#c2d28c",2.3)
    result["emperor-dragonfly"]=d

    d=Drawing(features=("two pairs of slender wings folded steeply upward", "needle-thin powder-blue abdomen", "widely separated bead eyes"))
    def damsel(p):
        _wing(p,"M60 58 38 10Q30 2 29 17L54 68Z","#dae7e1",veins="M56 58 34 15M44 34l-5 4M49 45l-5 4")
        _wing(p,"M59 64 21 22Q13 17 16 31L55 72Z","#dbe9e3",veins="M53 63 21 29M37 47l-5 6")
        p.detail("M30 11 34 9 38 17l-4 2Z","#6d7770")
    _pair(d,damsel)
    _dragon_body(d,"#88b4c7","#71a59e",slim=True,head_y=42,tail_y=121)
    d.line("M62 75v32","#426b77",1.8)
    result["damselfly"]=d

    d=Drawing(features=("broad dark blue bands across upright wings", "metallic emerald slender body", "petal-shaped rounded wing tips"))
    def banded(p):
        _wing(p,"M59 61C40 34 24 6 15 14 3 24 34 51 56 69Z","#9fc3bf",veins="M54 58 17 20")
        _wing(p,"M58 69C34 57 8 36 7 48 4 61 33 73 57 76Z","#b4d0c5",veins="M51 71 13 50")
        p.detail("M21 13Q31 21 40 34l-10 9-13-13Z","#516d84")
        p.detail("M17 42 34 55l-5 13-17-10Z","#526c7e")
    _pair(d,banded)
    _dragon_body(d,"#527d76","#62968c",slim=True,head_y=44,tail_y=119)
    d.line("M61 79v28","#8cc0aa",1.8)
    result["banded-demoiselle"]=d

    d=Drawing(features=("large apple-green thorax", "blue-and-brown swollen segmented abdomen", "strong veined wings spread diagonally"))
    def darner(p):
        _wing(p,"M59 47C37 17 14 11 7 22 1 35 33 51 57 55Z","#d8e5cf",veins="M53 48 13 24M39 36l-5 9M26 28l-6 9")
        _wing(p,"M57 59C26 45 4 49 9 63 14 77 40 72 58 67Z","#cfe1c9",veins="M52 64 14 58M35 58l-4 12")
        p.detail("M10 21h9v3h-9Z","#997852")
    _pair(d,darner)
    _dragon_body(d,"#79a8af","#92b978",head_y=27,tail_y=122)
    d.detail("M60 68h8l-1 15h-6ZM61 92h6v15h-6Z","#7e7652")
    d.line("M59 40q5-5 10 0M59 47h10","#c9d995",2)
    result["green-darner"]=d
    return result


def _hoppers() -> dict[str, Drawing]:
    result={}
    d=Drawing(features=("dark glossy cricket body", "powerful bent hind thighs", "antennae longer than the body"))
    d.line("M83 39Q52 8 13 12M91 38Q91 6 117 8","#685847",2.2,silhouette=True)
    d.path("M40 74 14 53 10 61 29 91 13 115l21-14 21-22Z","#79633f",width=3)
    d.line("M32 99 49 118M72 82 69 107 86 116M91 76 108 93 119 90","#786749",3,silhouette=True)
    d.path("M24 67Q42 43 77 53l13 25-23 21-40-6Z","#6c6250")
    d.path("M28 64 61 56l14 28-46 8Z","#8b7953",width=3)
    d.line("M32 70 66 83M41 64l-3 20M50 61l-3 25M59 63l-2 25","#5b5443",1.7)
    d.path("M77 49 91 38 108 47 108 70 91 80 76 70Z","#7a6e4b",width=3)
    d.eye(98,51,5,True)
    d.path("M105 67 118 73 108 78","#a19064",width=2.5)
    d.line("M29 90 9 96M31 94 14 105","#b39b64",2,silhouette=True)
    result["cricket"]=d

    d=Drawing(features=("large green folded jumping thighs", "leaf-like long forewings", "short stiff antennae"))
    d.path("M53 73 28 43 17 50 37 81 14 108l30-16 21-16Z","#87a65e",width=3)
    d.line("M37 89 58 116M71 84 75 108 91 118M91 73 113 95 120 94","#668e55",3.5,silhouette=True)
    d.path("M16 64Q34 44 76 51l21 21-23 25-52-8Z","#8eb267")
    d.path("M20 60 72 54 82 76l-60 9Z","#a7c47c",width=3)
    d.line("M27 67 72 72M39 61l-2 20M51 59l-2 22M63 58l-2 20","#6f9557",1.7)
    d.path("M76 51 87 37 107 41l10 20-13 18-24-6Z","#8aaf61",width=3)
    d.eye(101,47,5,True)
    d.line("M91 38 86 22M101 38 109 23","#6c7d4a",2.5,silhouette=True)
    d.path("M109 66 120 73l-14 4","#c5c88a",width=2.5)
    result["grasshopper"]=d

    d=Drawing(features=("triangular rotating mantis head", "folded serrated praying forelegs", "long narrow green abdomen"))
    d.line("M54 88 29 111 18 113M73 88 94 110 110 112M55 70 34 85 23 81M73 70 94 85 106 81","#719c59",3.5,silhouette=True)
    d.path("M56 57 49 84 55 115h18l6-31-7-27Z","#86ad61")
    d.detail("M59 61h7v48h-8Z","#b2cb85")
    d.line("M57 84h16M55 95h20M57 105h16","#638450",1.6)
    d.path("M58 57 55 36h18l-3 21Z","#a6c77f",width=3)
    d.path("M49 51 27 43 20 23l13 7 5 12 17 0M79 51l22-8 7-20-13 7-5 12-17 0","#91b86e",width=3)
    d.line("M27 34 32 33m-2 6 5-2M101 34l-5-1m2 6-5-2","#527b49",2)
    d.path("M44 28 64 16 84 28 73 40H55Z","#9cbe73",width=3)
    d.eye(49,27,4.5,True);d.eye(79,27,4.5,True)
    d.line("M55 20 45 8M73 20 83 8",width=2,silhouette=True)
    result["mantis"]=d

    d=Drawing(features=("white-and-rose orchid petal legs", "small pointed mantis head", "broad flattened pink abdomen"))
    d.line("M55 87 32 111 17 113M73 87 96 111 111 113","#ae8e91",2.5,silhouette=True)
    d.path("M36 101q-16-10-15 5l15 10 9-11ZM92 101q16-10 15 5l-15 10-9-11Z","#ecc6cb",width=2.5)
    d.path("M55 59Q42 78 48 99l16 18 16-18q6-21-7-40Z","#f2d8d8")
    d.detail("M53 68 64 62 75 68l-4 18-7 8-7-8Z","#dfb2c0")
    d.line("M52 96h24M57 105h14","#b88e9a",1.7)
    d.path("M58 60 54 40h20l-4 20Z","#f3e3da",width=3)
    d.path("M53 54 33 47 21 23l14 9 9 16 13-3M75 54l20-7 12-24-14 9-9 16-13-3","#f0d2d6",width=3)
    d.path("M38 44Q21 34 22 48l12 10 10-7M90 44q17-10 16 4L94 58l-10-7","#e2b2c5",width=2.5)
    d.path("M46 31 64 18 82 31 72 41H56Z","#f0dcd7",width=3)
    d.path("M58 23 64 10 70 23","#e4c3cf",width=2.5)
    d.eye(50,30,4,True);d.eye(78,30,4,True)
    d.line("M54 23 43 13M74 23 85 13","#b58f9b",1.8,silhouette=True)
    result["orchid-mantis"]=d

    d=Drawing(features=("very long twig-like segmented body", "six spindly angular legs", "tiny head and long thin antennae"))
    d.line("M58 44 31 26 14 38M70 44 97 26 114 38M59 65 25 71 9 58M69 65 103 71 119 58M59 92 32 111 13 116M69 92 96 111 115 116","#927b56",3.5,silhouette=True)
    d.path("M59 35 57 71l3 47h8l3-47-2-36Z","#a38a61",width=3)
    d.line("M60 48h8M59 67h10M60 85h8M61 103h6","#665d43",2)
    d.detail("M60 38h3v69h-3Z","#c5af7f")
    d.ellipse(64,27,7,10,"#aa9368",width=3)
    d.line("M60 19 48 8M68 19 80 8","#7c7253",2,silhouette=True)
    d.eye(59,26,2.1);d.eye(69,26,2.1)
    d.line("M30 26 33 34M98 26l-3 8M25 71l4 7M103 71l-4 7M32 111l-2-8M96 111l2-8","#c1aa7d",1.7)
    result["stick-insect"]=d

    d=Drawing(features=("broad scalloped leaf-shaped body", "leafy lobes on all six legs", "vein network and chewed-looking edges"))
    d.line("M51 43 31 29 15 35M77 43 97 29 113 35M47 66 27 68 12 85M81 66 101 68 116 85M51 91 33 107 19 111M77 91 95 107 109 111","#829b56",3,silhouette=True)
    _pair(d,lambda p:p.path("M31 29 23 20l-9 6 5 14 14-5ZM27 66 14 66 11 78l10 9 13-13ZM35 101l-15-1-5 11 12 7 14-11Z","#a7bb71",width=2.5))
    d.path("M56 35 44 41 37 61 42 72 35 87 46 108 64 120 82 108 93 87 86 72 91 61 84 41 72 35Z","#a4bd72")
    d.line("M64 37v78M64 57 45 48M64 72 42 61M64 88 43 78M64 103 49 94M64 57 83 48M64 72 86 61M64 88 85 78M64 103 79 94","#6d9254",2)
    d.ellipse(64,28,10,11,"#a8be77",width=3)
    d.line("M59 19 52 9M69 19 76 9",width=2,silhouette=True)
    d.eye(57,27,2.6);d.eye(71,27,2.6)
    d.spot(48,87,2.5,"#c6ce8c");d.spot(79,64,2.8,"#7f9c58")
    result["leaf-insect"]=d

    d=Drawing(features=("large pointed green leaf wing", "very long fine antennae", "slender high-arched jumping legs"))
    d.line("M89 41Q57 2 10 9M96 38Q104 7 122 11","#768f51",2,silhouette=True)
    d.path("M61 77 33 43 23 52 47 82 23 111l29-16 22-15Z","#95b36d",width=3)
    d.line("M47 94 65 118M76 84 89 111 105 116M99 72 113 86 121 84","#6d9958",3,silhouette=True)
    d.path("M12 76 65 46Q86 39 98 61l-15 27-49 11Z","#9dbb6c")
    d.line("M18 77 83 61M31 72l6 20M45 65l9 22M60 59l10 22M48 65 37 57M63 60l-8-7","#668e50",2)
    d.path("M85 47 96 34 111 43 116 61 105 76 91 69Z","#a9c77a",width=3)
    d.eye(106,47,4.5,True)
    d.path("M108 69 120 76l-12 3","#d2d49a",width=2)
    result["katydid"]=d

    d=Drawing(features=("long sandy-brown striped wings", "high ridged saddle thorax", "large red-brown jumping knees"))
    d.path("M60 80 29 43 19 49 43 82 15 112l32-16 25-14Z","#ae8c57",width=3)
    d.detail("M29 48 41 71l10 9-5-14-11-19Z","#cdb477")
    d.circle(44,83,5,"#a66c49",width=2.5)
    d.line("M44 87 65 118M76 83 84 109 101 117M98 73 116 91 122 90","#9b7a4d",3.3,silhouette=True)
    d.path("M8 70 58 49 88 51l14 24-28 21-52-8Z","#b6a06a")
    d.path("M13 70 73 55 84 74 23 84Z","#c8b786",width=3)
    d.line("M19 73 78 65M27 78l48-7M45 64l-3 14M59 60l-3 15","#8a764e",1.6)
    d.path("M78 52 83 37 100 34l15 14-1 23-18 9-12-15Z","#c2a472",width=3)
    d.line("M87 37 99 44l-5 20","#8c764e",2)
    d.eye(105,46,4.7,True)
    d.line("M96 34 89 19M105 35 113 21","#81724d",2.2,silhouette=True)
    result["locust"]=d

    d=Drawing(features=("small wedge-shaped roof wings", "cream chevron markings", "short tucked jumping legs"))
    d.line("M40 77 22 85 13 102M59 87 49 109 34 115M89 76 107 89 115 108","#738a54",3,silhouette=True)
    d.path("M14 68 40 42 83 36l27 24-18 29-67 13Z","#91b163")
    d.path("M20 70 80 48l18 13-12 19-59 15Z","#bed18a",width=3)
    d.detail("M34 65 47 59 55 76 48 86 42 78ZM58 55 70 50l10 15-6 15-7-13Z","#6c9756")
    d.line("M27 88 88 65M34 69l6 16M58 59l6 18","#749c5b",1.7)
    d.path("M87 36 106 39 119 55 111 72 95 72 86 57Z","#a8c77e",width=3)
    d.eye(108,50,5,True)
    d.line("M108 38 114 26M99 37 96 25","#718853",2,silhouette=True)
    d.path("M113 64 123 70l-12 4","#d0d5a0",width=2)
    result["leafhopper"]=d

    d=Drawing(features=("large thorn-shaped horn over the back", "compact triangular insect body", "copper-green ridged shield"))
    d.line("M40 79 23 91 17 113M64 89 62 113 52 119M89 79 108 96 116 113","#876d49",3,silhouette=True)
    d.path("M21 76 49 47 49 8 67 33 94 49l19 27-24 24-55-3Z","#9e9661")
    d.detail("M49 8 56 41 80 56l-12 9-27-10Z","#c1aa6c")
    d.path("M24 77 65 58l36 16-21 23-45-3Z","#94a16b",width=3)
    d.line("M29 81 78 74M42 73l7 17M61 65l6 28","#637f58",1.8)
    d.path("M93 55 110 53 120 67l-9 17-21-3Z","#b5b278",width=3)
    d.eye(110,65,4,True)
    d.line("M109 54 116 42","#7a7c50",2,silhouette=True)
    d.line("M53 27 58 45l24 13","#e0c58c",1.6)
    result["treehopper"]=d

    d=Drawing(features=("broad toothed shovel forehands", "velvety cylindrical brown body", "long rear abdominal feelers"))
    d.line("M39 79 24 95 19 116M60 86 56 108 69 117","#987b56",3,silhouette=True)
    d.path("M14 73Q22 52 61 54l26 18-15 30-44 2Z","#a28560")
    d.detail("M18 75q11-13 30-10l8 29-25 3Z","#b89c72")
    d.path("M34 63 62 59l13 20-31 9Z","#826e50",width=3)
    d.line("M43 69 64 75M50 65l3 13","#bfab7e",1.6)
    d.ellipse(80,67,20,24,"#a18760")
    d.ellipse(101,66,15,18,"#9c7b50",width=3)
    d.path("M90 78 104 88l18 1-4 7-9 0 6 7-10 2-8-7 1 11-8-4-6-17Z","#b29b6f",width=3)
    d.path("M83 50 98 43l15 2-4 7-10 0 5 6-9 3-9-5Z","#c0aa7a",width=2.5)
    d.line("M99 48 111 23 122 18M105 51 122 37","#7a694d",2.2,silhouette=True)
    d.eye(107,60,3.7,True)
    d.line("M23 98 7 112M26 101 14 120","#826d4b",2.2,silhouette=True)
    result["mole-cricket"]=d
    return result


def _other_insects() -> dict[str, Drawing]:
    result={}
    d=Drawing(features=("wide clear roof-like wings with dense veins", "stocky green ringed abdomen", "prominent red compound eyes"))
    d.line("M49 45 30 36 22 45M45 64 26 69 21 83M49 83 31 101 27 114M79 45 98 36 106 45M83 64 102 69 107 83M79 83 97 101 101 114","#607b53",2.5,silhouette=True)
    d.path("M49 45Q43 65 49 91l15 29 15-29q6-26 0-46Z","#8ca56b")
    d.line("M48 72h32M49 83h30M53 95h22M58 107h12","#596f4e",2.3)
    def cicada(p):
        _wing(p,"M54 43C32 35 19 49 16 75L20 111 34 109 56 73Z","#dce6ce",veins="M49 50 25 103M40 53 36 77 22 84M45 60 31 64M39 74l-17 22M32 87l8 8M28 96l6 7",opacity=.9)
        p.line("M22 67 32 70M20 77 29 79M21 89l5 2M38 63l6 8M32 78l7 7","#9aa986",1)
    _pair(d,cicada)
    d.path("M43 32 51 20h26l8 12-7 22H50Z","#93af76",width=3)
    d.circle(45,29,9,"#bb785a",width=2.5);d.circle(83,29,9,"#bb785a",width=2.5)
    d.eye(44,28,4);d.eye(84,28,4)
    d.line("M56 21 52 11M72 21 76 11","#6a7952",2,silhouette=True)
    d.detail("M58 25h12l4 13-10 6-10-6Z","#bdc88d")
    result["cicada"]=d

    d=Drawing(features=("three separate body sections with pinched waist", "elbowed antennae", "large curved mandibles and six walking legs"))
    d.line("M50 70 28 86 13 116M64 75 61 102 46 119M81 70 99 88 119 98","#80573b",3.5,silhouette=True)
    d.line("M47 57 33 44 14 45M63 59 69 36 87 29M78 57 98 46 116 48","#80573b",3,silhouette=True)
    d.ellipse(28,69,22,25,"#966541")
    d.detail("M12 63q6-18 21-15l7 7q-18-1-21 17Z","#ba8858")
    d.line("M15 82q14 9 27 0M16 67q14 8 28 0","#6d4a35",2)
    d.circle(53,68,7,"#a97748",width=3)
    d.ellipse(69,65,16,14,"#a77648",width=3)
    d.path("M83 51Q95 35 111 45l7 19-10 18-19-3-9-15Z","#a67345",width=3)
    d.path("M107 77 120 80l-5 11-9-8M99 81l3 11 8-7","#c0925d",width=2.5)
    d.line("M94 45 89 25 71 15M106 43 116 26 120 10","#7c563a",2.7,silhouette=True)
    d.eye(108,54,4,True)
    d.line("M63 59l8-3","#d0a270",2)
    result["ant"]=d

    d=Drawing(features=("delicate green lace-veined wings", "very slender apple-green body", "golden compound eyes and long antennae"))
    d.line("M58 55 42 68 30 67M70 55 86 68 98 67M58 70 40 88 24 92M70 70 88 88 104 92","#7b9d63",2,silhouette=True)
    def lace(p):
        _wing(p,"M57 47C30 28 9 42 9 65 10 92 35 108 53 119L61 73Z","#deedce",veins="M54 54 49 108M50 57 18 49M52 68 12 64M51 80 17 81M50 93 28 98M36 47l-3 49M23 46 22 86M17 54l30 51M13 69 46 78M22 91l27-20",opacity=.78)
        p.line("M29 50 23 61M39 53l-5 13M15 72l15-7M26 76l13-7M21 87l12-8M35 99l4-12M44 109l2-13","#98b782",1)
    _pair(d,lace)
    d.path("M59 43h10l-2 62-3 13-3-13Z","#91b66b",width=2.8)
    d.ellipse(64,40,9,13,"#b1cb88",width=3)
    d.circle(54,28,6,"#c9b56c",width=2);d.circle(74,28,6,"#c9b56c",width=2)
    d.ellipse(64,29,9,9,"#adc981",width=2.5)
    d.eye(53,27,2.6);d.eye(75,27,2.6)
    d.line("M58 22Q47 7 34 7M70 22Q81 7 94 7","#7c925c",1.8,silhouette=True)
    d.line("M62 55v41","#cfdfa4",1.8)
    result["lacewing"]=d

    d=Drawing(features=("large upright triangular sail wings", "three very long tail filaments", "slender amber segmented abdomen"))
    _wing(d,"M63 65 39 10Q27 0 23 18L30 55 55 73Z","#e3e8d2",veins="M56 62 31 15M49 45 26 31M54 55 30 44M42 29l-8 16")
    _wing(d,"M67 62 89 8Q100 3 104 20L98 51 73 71Z","#e6ebd8",veins="M74 61 97 17M82 44l19-14M78 54l21-10M89 28l9 15")
    _wing(d,"M59 69 37 53Q25 53 31 67L57 80Z","#d8e2c8",veins="M54 73 35 60")
    _wing(d,"M71 69 91 54Q103 54 97 69L73 79Z","#d8e2c8",veins="M76 74 93 61")
    d.line("M58 76 44 86 28 79M70 76 84 86 100 79M60 67 44 47 38 28M68 67 85 47 92 28","#9b865d",2.2,silhouette=True)
    d.path("M58 65 57 88 63 104h5l5-16-3-23Z","#bea46b",width=3)
    d.line("M58 79h14M59 88h12M61 97h8","#877455",1.8)
    d.line("M63 101 39 122M65 103v21M68 101l24 21","#96835e",1.8,silhouette=True)
    d.ellipse(64,59,9,12,"#ae9965",width=2.5)
    d.circle(64,45,10,"#c4b47f",width=2.5)
    d.eye(58,43,3.3,True);d.eye(70,43,3.3,True)
    d.line("M59 36 55 27M69 36 73 27",width=1.8,silhouette=True)
    result["mayfly"]=d

    d=Drawing(features=("four equally long narrow wings extending far past the abdomen", "small head and broad unpinched pale waist", "straight bead-like antennae"))
    def termite(p):
        _wing(p,"M58 32C45 48 17 92 11 108 7 120 17 123 25 113 37 96 54 59 63 39Z","#e6e0c5",veins="M58 38 17 112M49 57 26 108M42 72l-9 3M34 88l-8 2M24 106l-5-1",opacity=.87)
        _wing(p,"M59 40C50 61 33 100 32 113 31 124 42 126 48 114 56 97 61 65 66 46Z","#e1dabe",veins="M61 46 39 118M57 65 43 111M50 87l-9 3M45 102l-8 1",opacity=.87)
        p.line("M46 59l5 4M37 78l5 3M28 96l5 2M53 78l5 2M47 97l5 1","#c7be9b",1.2)
    _pair(d,termite)
    d.line("M56 39 43 43 35 55M72 39 85 43 93 55M55 53 40 63 34 76M73 53 88 63 94 76M56 70 43 82 39 94M72 70 85 82 89 94","#a38f62",2,silhouette=True)
    d.path("M55 44Q49 66 57 87l7 9 7-9q8-21 2-43Z","#cdbb8c",width=3)
    d.line("M53 56h22M53 65h22M55 75h18M58 85h12","#9f8e67",1.8)
    d.ellipse(64,40,10,12,"#bca875",width=2.5)
    d.ellipse(64,22,10,11,"#aa8655",width=3)
    d.line("M57 14 49 6M71 14 79 6","#90734d",2.2,silhouette=True)
    for x,y in ((51,8),(54,11),(77,8),(74,11)):
        d.circle(x,y,1.8,"#a38a5f","none",silhouette=False)
    d.eye(57,22,2.6,True);d.eye(71,22,2.6,True)
    result["termite"]=d

    d=Drawing(features=("long downward-pointing beak", "male tail curled upward like a scorpion", "four clear wings with dark blotches"))
    d.path("M42 82C17 82 11 59 18 42 22 33 32 35 36 42l-9 7q-5-3-4 6 2 15 24 17Z","#ba8654",width=3)
    d.path("M25 44Q11 35 21 23 35 15 43 28l-5 16-9 4Z","#b06a4e",width=3)
    d.spot(29,29,4,"#d49c68")
    d.line("M29 58l-10 2M33 67l-11 4","#765943",2)
    _wing(d,"M66 61 30 21Q17 12 18 29L53 73Z","#e0e4cf",veins="M58 62 24 25M47 48l-9 6")
    _wing(d,"M76 60 61 17Q54 7 50 20L65 74Z","#e6e7d0",veins="M69 59 56 22M63 40l-8 7")
    _wing(d,"M59 73 26 56Q13 53 16 65L54 83Z","#d7dfc8",veins="M52 75 21 62")
    _wing(d,"M75 68 97 27Q106 19 111 33L86 78Z","#dce4cf",veins="M83 65 105 32")
    for x,y,r in ((29,31,4),(42,47,4.2),(56,26,3),(61,43,3.5),(23,62,3),(40,71,3.2),(102,38,3.5),(94,51,4)):
        d.ellipse(x,y,r,r+2,"#81775b","none",silhouette=False)
    d.line("M58 81 42 99 26 109M72 82 74 105 87 118M89 73 105 91 121 94","#8d774f",2.5,silhouette=True)
    d.path("M43 73 77 63 94 68l-5 18-42 2Z","#b89b62",width=3)
    d.line("M52 72v13M62 69v16M73 66v18","#7e6948",2)
    d.ellipse(94,64,13,15,"#b8a165",width=3)
    d.path("M102 68 114 89l-5 8-15-24Z","#b68852",width=2.5)
    d.eye(100,58,4,True)
    d.line("M94 50 92 37 86 31M102 51 114 41 122 43","#8d794f",2,silhouette=True)
    result["scorpionfly"]=d
    return result


def build() -> dict[str, Drawing]:
    return {
        "butterfly": _butterfly(),
        "honeybee": _honeybee(),
        "stag-beetle": _stag_beetle(),
        "water-strider": _water_strider(),
        **_butterflies(),
        **_moths(),
        **_pollinators(),
        **_beetles(),
        **_dragonflies(),
        **_hoppers(),
        **_other_insects(),
    }
