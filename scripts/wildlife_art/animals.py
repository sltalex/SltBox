"""Natural cartoon portraits of the sixty small wildlife species.

Profiles encode anatomy and field marks explicitly; motion codes never select art.
"""

from __future__ import annotations

from .common import Drawing, INK, CREAM


def panda() -> Drawing:
    d = Drawing(("round black ears", "separate teardrop eye patches", "black limbs and short tail"))
    d.circle(30, 98, 10, "#e6e3d6")
    d.ellipse(63, 83, 34, 31, "#eeeee4")
    d.path("M40 66Q29 77 28 102q8 13 22 2l7-24", "#35434a")
    d.ellipse(51, 108, 19, 11, "#35434a")
    d.ellipse(89, 108, 21, 12, "#35434a")
    d.detail("M61 76q18-9 28 6l1 18q-15 10-30 0Z", "#fffdf2")
    d.circle(49, 26, 13, "#35434a")
    d.circle(95, 25, 13, "#35434a")
    d.path("M36 47q0-24 33-26 36 0 40 28 4 28-31 32-37 0-42-23Z", "#f5f4e9")
    d.detail("M44 49q2-12 13-13 9 2 8 11-3 16-14 15-9-2-7-13Z", "#344149")
    d.detail("M79 46q2-12 13-10 12 5 9 16-3 12-13 8-10-3-9-14Z", "#344149")
    d.eye(57, 48, 3.1, True)
    d.eye(90, 47, 3.1, True)
    d.ellipse(75, 61, 12, 8, "#fffdf2", "none", silhouette=False)
    d.detail("M68 59q8-6 15 0l-7 6Z", INK)
    d.line("M76 65v5m0 0q-7 4-12-1m12 1q6 4 11-2")
    d.path("M98 77q17 10 9 20-7 8-23-2l-10-9", "#35434a")
    d.line("M41 107l4 5m6-7 3 6m29-4 4 6m7-8 5 5", "#748084")
    return d


def koala() -> Drawing:
    d = Drawing(("large fluffy round ears", "long oval black nose", "curled grasping forearms"))
    d.ellipse(60, 87, 30, 31, "#92a29e")
    d.ellipse(58, 91, 19, 24, "#dce2d7", "none", silhouette=False)
    d.path("M37 94q-15 7-14 19 7 8 25 0l9-9m26-12q16 2 20 16-1 9-25 5l-10-8", "#92a29e")
    d.path("M34 53Q8 53 10 34l-4-5 7-5-1-7 10 1 4-8 8 6 8-3q17 15 5 33Z", "#acbbb5")
    d.path("M89 49q25 4 28-15l6-4-7-6 1-7-9 1-5-8-8 6-8-2q-16 15-9 30Z", "#acbbb5")
    d.ellipse(29, 32, 11, 13, "#d4d8cb", "none", silhouette=False)
    d.ellipse(99, 31, 11, 13, "#d4d8cb", "none", silhouette=False)
    d.path("M33 39q1-19 31-19t33 23q7 29-30 33-35-1-34-37Z", "#9caca6")
    d.detail("M42 53q7 23 26 21 23-2 26-19-12 5-25 3-12 6-27-5Z", "#c7d1c5")
    d.eye(48, 44, 3.2)
    d.eye(83, 44, 3.2)
    d.ellipse(67, 49, 9, 14, "#3a4548", width=2, silhouette=False)
    d.detail("M63 39q5-4 8 1l-1 6-7-1Z", "#718181")
    d.line("M67 64v4m0 0q-5 3-9 0m9 0q5 3 9-1")
    d.path("M36 78q6-9 13-1l13 14q9 0 13-7 6-6 11 1 7 12-12 20-13 4-26-6Z", "#9caca6")
    d.line("M57 89l-3 8m10-4-1 8m16-12 4 7", "#526665")
    d.line("M20 24l5-4m-7 17 5 3m77-19 6 3m1 13 6-1", "#e2e6db")
    return d


def axolotl() -> Drawing:
    d = Drawing(("three external gill branches per side", "long translucent finned tail", "wide smiling head and four small limbs"))
    d.path("M56 69C39 49 25 50 7 37 5 67 16 90 49 92L66 82Z", "#eab0b4")
    d.detail("M51 72Q25 67 9 46q7 32 38 40Z", "#f8d7d4")
    d.path("M54 81q-12 5-18 18l-11 2m37-11-6 16 11 2", "none", "#d9949e", 7)
    d.path("M72 62 64 39l-7-9m15 25-19-9m19 3-2-22M100 59l9-23 9-8m-14 22 19-7m-21 0 3-22", "none", "#cf7d94", 6)
    d.line("M60 34l-7 3m5-6-4-6m6 19-7 4m17-18-5-5m44 11 7 4m1-8 6-2m-2 11 5 4m-18-15 5-7", "#ed9aad", 3, True)
    d.path("M43 74q11-19 37-16l15 11-5 22q-26 13-44-3Z", "#efb8bc")
    d.path("M75 54q11-12 28-4 20 11 16 28-4 15-25 15-23-2-29-19Z", "#f1c6c5")
    d.path("M81 88q-3 12-14 15l-6 7m39-20 6 15 12 2", "none", "#dda3ae", 6)
    d.eye(91, 66, 3.2)
    d.eye(111, 66, 2.7)
    d.line("M87 78q13 10 25-1", "#a76878")
    d.spot(82, 76, 4, "#e99aa6")
    d.spot(115, 75, 3, "#e99aa6")
    d.line("M53 77q9 6 16 3", "#f9e0da", 3)
    return d


def flying_squirrel() -> Drawing:
    d = Drawing(("wide wrist-to-ankle gliding membrane", "large dark nocturnal eyes", "flat feathered tail"))
    d.path("M43 83Q13 101 5 76q5-19 25-6l21 9Z", "#9e7c5b")
    d.line("M12 79q10 9 28 1", "#d9bf96", 3)
    d.path("M37 38Q23 68 15 88l29 22q19-9 38-6l32-24q-17-19-21-45l-29 23Z", "#b6a07c")
    d.detail("M37 44Q31 69 23 86l24 15 20-8 33-14-12-29-23 17Z", "#e2d4ae")
    d.line("M36 43 48 82 45 104m45-57L78 83l27-2", "#927a5d", 2)
    d.path("M48 59q9-16 26-12 16 9 8 32l-15 24-21-10Z", "#a88b65")
    d.detail("M55 65q12-5 17 5l-7 28-13-8Z", "#f0e2c3")
    d.path("M49 30 45 16q8-9 16 5m19 5 7-16q12 0 10 15", "#a88b65")
    d.ellipse(71, 37, 23, 22, "#b69a70")
    d.detail("M53 44q15 17 37-1-2 19-20 18-13-1-17-17Z", "#e9d8b7")
    d.eye(63, 35, 6.2, True)
    d.eye(85, 34, 5.4, True)
    d.detail("M75 43 82 45l-4 5-5-3Z", "#5e4c43")
    d.path("M37 39 31 28l-7-2m66 11 13-10 7 1M43 106l-8 8m47-8 8 8", "none", "#705d4d", 5)
    d.line("M28 24l-4-5m5 6 2-7m73 9 6-3m-6 3 4 5", INK, 2)
    return d


def rabbit(kind: str) -> Drawing:
    configs = {
        "rabbit": ("#cdbba5", "#f7eddb", 22, 18, 39, 30, 28),
        "hare": ("#b99a72", "#e7d2ac", 15, 14, 44, 24, 37),
        "cottontail": ("#ad8a66", "#ede2c9", 28, 23, 34, 28, 23),
        "snow-hare": ("#e9eee7", "#ffffff", 22, 19, 42, 29, 32),
        "pika": ("#b69e7d", "#dfcfac", 35, 35, 33, 26, 12),
    }
    body, belly, ea, eb, rx, ry, ear = configs[kind]
    traits = {
        "rabbit": ("long upright ears", "rounded haunch and large hind feet", "small white pom tail"),
        "hare": ("very long black-tipped ears", "lean arched back", "long powerful hind legs"),
        "cottontail": ("cotton-white raised tail", "shorter broad ears", "russet neck and compact body"),
        "snow-hare": ("white winter fur", "black ear tips", "oversized furry snowshoe feet"),
        "pika": ("short round ears", "egg-shaped tailless body", "small round muzzle and short legs"),
    }
    d = Drawing(traits[kind])
    if kind != "pika":
        d.circle(19, 85, 10 if kind != "cottontail" else 13, "#faf7e8")
    d.ellipse(54, 83, rx, ry, body)
    d.detail("M30 91q25 17 49-3l5 11q-24 15-48 4Z", belly)
    d.ellipse(40, 91, 19 if kind != "hare" else 23, 21, body, width=3)
    feet = 25 if kind == "snow-hare" else 22 if kind == "hare" else 18
    d.path(f"M32 100q-7 10-4 13h{feet+12}q7-6-9-10m44-12-1 18h24q5-4-12-9", body)
    if kind == "pika":
        d.ellipse(83, 42, 10, 13, body)
        d.ellipse(104, 40, 9, 12, body)
        d.ellipse(84, 41, 5, 7, "#d5b18e", "none", silhouette=False)
    else:
        d.path(f"M81 59Q{ea+42} {ear-21} 77 {ear-20}q13-5 18 32l1 30m-1-8q1-{69-ear} 15-{65-ear} 12 4-4 {70-ear}", body)
        d.line(f"M84 42 79 {ear-9}m23 52 6-{46-ear//2}", "#dbafa3", 5)
        if kind in {"hare", "snow-hare"}:
            d.path(f"M73 {ear-18}q8-5 13 9m19-2q4-10 10-6", "none", "#43504b", 5, False)
    d.ellipse(96, 63 if kind != "pika" else 66, 23, 21, body)
    d.ellipse(107, 73, 13, 9, belly, "none", silhouette=False)
    d.eye(103, 58 if kind != "pika" else 63, 3.6)
    d.detail("M117 69q9 0 6 6l-7 1Z", "#a77776")
    d.line("M115 78q-7 4-11 0m9 0-2 5m-3-10-11-1m12 7-11 3")
    d.line("M45 72q-6 4-6 9m20-16-4 5m-7 44 7-1m34-5 6 1", "#7f725f")
    if kind == "hare":
        d.detail("M56 62q13-8 24 2l-11 9Z", "#806e53")
    elif kind == "cottontail":
        d.detail("M78 61q-9 10-6 23l12-6 3-13Z", "#b7734c")
    elif kind == "snow-hare":
        d.line("M32 71l7-4m8-1 5-3m-19 41 7 3", "#bbc9c1")
    return d


def squirrel(kind: str) -> Drawing:
    red = kind == "red-squirrel"
    striped = kind == "chipmunk"
    body = "#b96938" if red else "#a67d54" if striped else "#bc824c"
    light = "#f2dfbc" if striped else "#f1d6b2"
    d = Drawing({
        "squirrel": ("large curled bushy tail", "upright seated haunch", "cream belly and folded forepaws"),
        "red-squirrel": ("long red ear tufts", "tall flame-shaped tail", "rust-red coat and white belly"),
        "chipmunk": ("five dark dorsal stripes", "striped cheek and eye", "slender trailing bushy tail"),
    }[kind])
    if striped:
        d.path("M42 87C16 101 3 84 8 66 12 56 20 57 22 68q-3 14 21 10Z", "#a4774e")
        d.ellipse(61, 85, 33, 23, body)
        d.path("M41 100 32 111h25m22-10 5 12h20", body)
        d.detail("M38 73q23-14 45-6l-2 5q-26-8-41 10Z", "#493d32")
        d.detail("M37 81q23-15 48-7v4q-23-5-45 10Z", "#f8e5c3")
        d.detail("M42 89q20-12 42-9l-1 5-36 12Z", "#4a3c30")
        d.circle(94, 52, 9, body)
        d.ellipse(98, 68, 21, 19, body)
        d.detail("M81 63q18-9 32-4l-1 4-30 6Z", "#4a3c30")
        d.detail("M85 72q19-11 29-7l2 4-28 9Z", "#f5dfb5")
    else:
        d.path("M42 87C7 101 2 76 11 66 25 56 36 62 25 47 13 28 28 6 43 9 60 12 60 30 45 41 35 50 51 54 57 66Z", "#b86e3b" if red else "#a87142")
        d.detail("M29 20q16-4 14 7-24 14-16 30-12-9-6-24Z", "#d59454")
        d.line("M15 77q11 8 23 0M26 17l-3 9m20-14 4 8", "#e1b077")
        d.ellipse(65, 85, 28, 29, body)
        d.detail("M66 67q18 7 17 30-12 15-24 2-9-13 7-32Z", light)
        d.ellipse(52, 103, 18, 13, body)
        d.path("M48 112h29m-1-11 5 13h23", body)
        if red:
            d.path("M79 48 75 18l12 16m12 8 7-28 7 29", body)
        else:
            d.circle(84, 38, 9, body)
            d.circle(105, 39, 8, body)
        d.ellipse(94, 55, 23, 22, body)
        d.detail("M83 61q16-3 29 0-5 15-20 12Z", light)
        d.path("M76 79q13 4 19 12m-22-6q7 8 17 9", "none", body, 8)
        d.line("M83 86l5-2m0 8 7-3", "#684d36")
    d.eye(103, 53 if not striped else 64, 3.7)
    d.detail("M116 62q8 0 6 5l-7 1Z" if not striped else "M116 73l7 2-6 5Z", "#5b4940")
    d.line("M108 69l-7 3m-1-5-9-1" if not striped else "M110 79l-10 3m8-7-12-1", "#6e533d")
    return d


def fox(kind: str) -> Drawing:
    arctic = kind == "arctic-fox"
    silver = kind == "silver-fox"
    body = "#d78142" if kind == "fox" else "#dfe6df" if arctic else "#65716f"
    accent = "#f7e8cc" if not silver else "#d8ded5"
    dark = "#6b4d3b" if kind == "fox" else "#899995" if arctic else "#344343"
    d = Drawing({
        "fox": ("tall triangular ears", "white cheek bib and white tail tip", "long pointed muzzle and black stockings"),
        "arctic-fox": ("small rounded ears", "deep white winter coat", "short muzzle and dense curved plume"),
        "silver-fox": ("silver-tipped charcoal fur", "dark pointed muzzle and legs", "large dark plume with white tip"),
    }[kind])
    if arctic:
        d.path("M48 76C22 57 7 62 7 81q1 25 35 21l17-12Z", body)
        d.ellipse(61, 82, 34, 25, body)
        d.path("M35 96q-7 19 1 20h18l6-16m18-3 5 18h24l-9-21", body)
        d.circle(83, 41, 10, body)
        d.circle(106, 39, 9, body)
        d.path("M73 58q0-22 23-19 16 1 19 22l9 9-17 17-22-1-15-16Z", body)
    else:
        d.path("M47 78C24 53 7 59 5 88q0 20 27 17l27-17Z" if not silver else
               "M49 79C28 49 9 57 5 79l4 1-4 9 6 0-2 11 9-1 5 9 34-18Z", body)
        d.detail("M8 81q9-11 18 1L16 101Q5 98 8 81Z", accent)
        d.ellipse(61, 78, 34 if not silver else 32, 23 if not silver else 25, body)
        d.path("M34 91 30 114h17l9-20m23-3 5 23h18l-9-26", dark)
        d.path("M75 53 76 22l19 17 15-21 6 35 10 14-18 20-25-5-16-17Z", body)
        d.detail("m81 42 1-11 9 10m12-1 6-12 2 17", "#ab7568")
    d.detail("M80 65q11 5 19 2l23 4-13 13-18 4-13-14Z", accent)
    d.detail("M78 74q-3 9-12 16l16 11 10-18Z", accent)
    d.eye(103, 55, 3.4)
    d.detail("M119 66q9 0 6 6l-7 2Z", INK)
    d.line("M111 77q-7 3-12-1m7 0 1 5")
    if arctic:
        d.line("M30 78l8 3m8-14 7 3m-6 18 7 3m30-17 5 4", "#b8c8bf")
        d.detail("M11 79q15-8 27 6l-4 9Q15 94 11 79Z", "#f5faf0")
    elif silver:
        d.line("M31 70l8 4m6-10 8 4m6-4 8 4m-25 9 7 3m8-4 8 3m8-5 6 2M13 76l5 5m4-7 6 3", "#bec8be", 2)
    else:
        d.line("M46 68q9-3 18-1M91 48l5 1", "#efb16b", 2)
    return d


def rodent(kind: str) -> Drawing:
    traits = {
        "field-mouse": ("large thin round ears", "long fine curved tail", "warm brown back and white underside"),
        "hamster": ("round cheek pouches", "tiny nearly hidden tail", "golden saddle and cream cheeks"),
        "bank-vole": ("russet red dorsal saddle", "small furry ears", "short blunt tail and rounded muzzle"),
        "dormouse": ("large black eye rims", "long soft curled tail", "cream belly and round ears"),
        "jerboa": ("very long jumping hind feet", "huge upright ears", "long tuft-ended balancing tail"),
        "shrew": ("elongated pointed flexible snout", "tiny bead eyes and concealed ears", "low narrow body and whiskers"),
        "mole": ("large outward-facing shovel palms", "velvet low dark body", "pink star-like nose and tiny hidden eyes"),
        "muskrat": ("side-flattened long tail", "rounded dark aquatic body", "small ears and partially webbed feet"),
        "water-rat": ("long cylindrical tail", "coarse brown coat and cream throat", "broad muzzle and long whiskers"),
    }
    d = Drawing(traits[kind])
    if kind == "jerboa":
        d.path("M53 91C29 114 11 103 10 81 8 62 25 61 20 46", "none", "#b89a73", 5)
        d.path("M16 43q-10-9-11 1 0 10 12 7l8-5Z", "#63523e")
        d.ellipse(66, 76, 22, 27, "#c3a177")
        d.detail("M69 57q19 7 15 30-3 15-14 14-11-21-1-44Z", "#f5e4c2")
        d.path("M62 95 46 117l35-2m-4-17 10 18 28-2", "none", "#c3a177", 8)
        d.path("M79 46Q65 3 78 5q13 1 15 35m3 2Q98 3 110 6q12 7-1 43", "#c3a177")
        d.line("M82 14 86 37m20-21-3 22", "#e0b3a0", 5)
        d.ellipse(96, 57, 21, 19, "#c3a177")
        d.detail("M99 61q15-3 22 5-6 14-24 6Z", "#f5e4c2")
        d.eye(105, 53, 4.6)
        d.spot(121, 65, 3, "#976e61")
        d.path("M78 77 92 84l7-2", "none", "#c3a177", 6)
        d.line("M112 70l-11 5m12-9-10 1")
        return d
    if kind == "mole":
        d.path("M32 84Q14 73 8 87", "none", "#776b63", 5)
        d.ellipse(57, 81, 39, 26, "#5d6263")
        d.detail("M24 75q18-20 49-12-22-2-37 22Z", "#7f8885")
        d.path("M78 65q18-8 34 11l12 9-21 12-27-9Z", "#5d6263")
        d.path("M37 88q-16-7-23 4l-9 11 9-2-4 9 11-5-1 8 11-8 8-3Z", "#caa39b")
        d.path("M83 89q15-5 23 3l10 11-11-1 3 9-10-6-2 9-8-9-12-3Z", "#caa39b")
        d.line("M18 99l6-5m-3 10 6-5m63-3 7 6m-11-2 4 8", "#775f5c")
        d.eye(101, 76, 1.9)
        d.detail("M117 80 126 84l-6 7-7-4Z", "#d795a0")
        d.line("M113 88l-15 5m14-7-14 0", "#a9afab")
        d.path("M46 100 40 113h14m17-11 7 12h12", "#777777")
        return d
    if kind == "shrew":
        d.path("M32 84C12 94 8 83 9 72", "none", "#b3a49a", 4)
        d.path("M22 75q14-24 47-17 17 4 26 18l29 11-24 8-23 4q-33 15-53-4-9-10-2-20Z", "#8c8475")
        d.detail("M31 90q36 14 72-1l-7 10q-42 17-64 0Z", "#cfc7ad")
        d.circle(82, 63, 5, "#8c8475")
        d.path("M41 99 35 111h13m28-12 6 12h16", "#b6a395")
        d.eye(100, 78, 2.1)
        d.spot(123, 86, 3, "#b58482")
        d.line("M112 89l-13 6m13-9-15-3m-68-7 9-4m5-4 8-2", "#cbc4b3")
        return d
    configs = {
        "field-mouse": ("#a48a6c", "#efe2c5", 32, 24, 12, 10, 77),
        "hamster": ("#c39758", "#f4e8cf", 34, 30, 8, 7, 83),
        "bank-vole": ("#a17a62", "#d4c6ab", 36, 23, 7, 7, 83),
        "dormouse": ("#aa9779", "#eee1c3", 30, 26, 11, 10, 77),
        "muskrat": ("#776451", "#b8a17e", 39, 25, 5, 5, 80),
        "water-rat": ("#8a755b", "#d5c5a5", 35, 22, 8, 6, 82),
    }
    body, belly, rx, ry, ear1, ear2, hy = configs[kind]
    if kind == "dormouse":
        d.path("M41 93C14 115 3 82 10 61q7-13 18-6 8 8-2 18-12 19 20 14Z", "#9c8767")
        d.line("M17 65q-7 19 5 27", "#cbb691", 3)
    elif kind == "hamster":
        d.circle(23, 95, 5, "#ddba8a")
    elif kind == "bank-vole":
        d.path("M30 88Q10 92 7 79", "none", "#a9927b", 5)
    elif kind == "muskrat":
        d.path("M33 86Q10 69 5 82l8 27q15 0 28-14Z", "#655a4e")
        d.line("M12 87 25 100", "#928373", 2)
    else:
        d.path("M33 89C5 109 0 82 12 67q7-10-2-17" if kind == "field-mouse" else
               "M29 89Q4 108 4 88q-1-16 14-21", "none", "#bd9d87", 5)
    d.ellipse(59, 85, rx, ry, body)
    d.detail("M32 94q26 15 55-4l1 12q-29 16-54 1Z", belly)
    if kind == "bank-vole":
        d.detail("M27 77Q44 51 73 65l8 12q-24-9-54 0Z", "#a95d42")
        d.line("M39 68l6-2m5-2 7 0m9 2 6 2", "#d29361")
    d.path("M33 101 28 112h22m29-13 5 13h23", body)
    if kind in {"muskrat", "water-rat"}:
        d.path("M32 105 21 114l17-3 7 5 3-9m39-2 12 11 12-5-16-6", "#b79c80", width=2)
        d.line("M28 112l7-4m65 4-6-4", "#6a5849")
    d.circle(83, hy - 30, ear1, body)
    d.circle(104, hy - 29, ear2, body)
    d.circle(83, hy - 30, max(3, ear1-4), "#cbab9b", "none", silhouette=False)
    d.ellipse(96, hy - 8, 23, 20 if kind != "hamster" else 24, body)
    if kind == "hamster":
        d.ellipse(86, 84, 14, 13, belly, "none", silhouette=False)
        d.ellipse(111, 82, 12, 13, belly, "none", silhouette=False)
        d.path("M71 87q4 12 15 10m-2-8q9 4 12 1", "none", body, 5)
    else:
        d.ellipse(109, hy+1, 13, 9, belly, "none", silhouette=False)
    if kind == "dormouse":
        d.ellipse(102, hy-13, 8, 9, "#665948", "none", silhouette=False)
    d.eye(103, hy-13, 4 if kind in {"field-mouse", "dormouse"} else 3.1)
    d.spot(120, hy-2, 3, "#b68680")
    d.line(f"M113 {hy+4}l-12 4m12-8-14-1m14 0 1 5", "#705f4e")
    d.line("M43 77l5-3m5 1 5-2m-18 13 6-2", "#d5b995")
    return d


def mustelid(kind: str) -> Drawing:
    settings = {
        "weasel": ("#bb8b4c", "#f1dec0", 22, 83, 28, 18, 27),
        "stoat": ("#e9eee5", "#fffbed", 32, 79, 32, 18, 26),
        "ferret": ("#c8baa0", "#ede0c4", 27, 82, 34, 22, 30),
        "marten": ("#775a3f", "#edc36f", 34, 76, 33, 22, 32),
        "mink": ("#665247", "#c6b492", 31, 87, 37, 18, 26),
    }
    color, cream, tail, cy, rx, ry, h = settings[kind]
    traits = {
        "weasel": ("very slender golden body", "cream underbelly", "short tapered tail"),
        "stoat": ("long black-tipped tail", "white winter coat with cool grey fur shadows", "small rounded ears"),
        "ferret": ("dark bandit eye mask", "pale rounded forehead", "arched flexible body and dark feet"),
        "marten": ("golden yellow throat bib", "large rounded ears", "long fluffy dark tail"),
        "mink": ("low glossy chocolate body", "short ears and pale chin", "thick tapered swimming tail"),
    }
    d = Drawing(traits[kind])
    if kind == "marten":
        d.path("M35 83Q2 108 5 76q3-13 12-6l22 8Z", "#69513e")
    elif kind == "stoat":
        d.path("M35 86Q9 104 5 82q-1-13 7-16", "none", INK, 10)
        d.path("M35 86Q9 104 5 82q-1-13 7-16", "none", color, 6, False)
        d.path("M5 78q-3-15 8-21", "none", "#39423c", 8)
    elif kind == "ferret":
        d.path("M33 88Q10 100 7 80", "none", "#766753", 10)
    elif kind == "mink":
        d.path("M35 90Q14 107 4 96l17-14 16-3Z", "#5b4a41")
    else:
        d.path("M34 87Q16 93 10 79l8-3 21 5Z", color)
    d.path(f"M27 {cy-10}Q42 {cy-ry-10} 67 {cy-ry}q24-1 32 21l-6 18q-31 21-62 4-11-7-4-22Z", color)
    d.detail(f"M32 {cy+6}q33 12 59-9l2 10q-25 17-58 8Z", cream)
    dark = "#5e5142" if kind in {"ferret", "marten", "mink"} else color
    d.path(f"M39 {cy+11} 31 114h17l8-19m22-3 8 21h19l-13-24", dark)
    d.path(f"M81 {cy-11}q-3-{h} 16-{h+3} 19 2 20 22l8 8-16 12-24-3Z", color)
    d.circle(90, cy-h-6, 7 if kind != "marten" else 10, color)
    d.circle(90, cy-h-6, 3.5 if kind != "marten" else 5,
             "#d3c9c1" if kind == "stoat" else "#c9a983", "none", silhouette=False)
    if kind == "ferret":
        d.detail("M84 59q16-10 29 4l1 8-14-1-14-5Z", "#625344")
        d.detail("M92 52q9-4 17 4l-6 6-11-2Z", "#f2e6c9")
    elif kind == "marten":
        d.detail("M83 77q9 4 19-1l-3 22-18-7Z", cream)
    else:
        d.detail(f"M94 {cy-5} 120 {cy-3}q-5 13-27 8Z", cream)
    d.eye(106, cy-17, 3)
    d.spot(122, cy-7, 3, INK)
    d.line(f"M114 {cy+1}l-12 3m8-7-12-2")
    if kind == "mink":
        d.line("M36 74q18-8 32-2m-25 7 15-2", "#a08d79", 3)
    elif kind == "marten":
        d.line("M13 81l8 0m-4 6 8-2m22-21 8-2", "#a58b65")
    elif kind == "stoat":
        d.detail("M31 85q27 11 58-5l-1 10q-30 14-55 3Z", "#cad4c8")
        d.line("M43 65q11-4 22-1m29-10 5-1", "#ffffff", 2)
    else:
        d.line(f"M41 {cy-12}q9-4 19-2", "#dfc399", 2)
    return d


def deer() -> Drawing:
    d = Drawing(("slender long legs with cloven hooves", "branched little antlers and large ears", "white spotted fawn coat"))
    d.path("M31 67 23 105l9 12h10l-4-13 10-30m29 0 7 39h12l-5-39", "#aa784c")
    d.path("M30 107v10h12m43-10 1 10h13", "none", "#4e4940", 5)
    d.ellipse(59, 67, 37, 22, "#c8955e")
    d.path("M25 64 12 55q-4 11 12 20", "#e8d5b1")
    d.path("M79 63 82 39q-5-19 9-23 12-4 17 12l-2 39-15 18-12-3Z", "#c8955e")
    d.path("M88 29Q65 30 67 14q15-3 21 15m13-7q2-16 17-17 6 17-13 24", "#c8955e")
    d.detail("M73 18q10 2 11 8m25-4 4-10", "#deb28a", INK)
    d.path("M89 19 84 5m0 6-8-4m26 13 4-15m0 7 9-5", "none", "#84613e", 4)
    d.path("M88 33q23-8 30 9l8 12-16 12-23-11Z", "#c8955e")
    d.detail("M95 54q11-2 22 3-3 9-17 9Z", "#f4e4c1")
    d.eye(109, 40, 3.2)
    d.spot(123, 52, 3, INK)
    d.detail("M83 55 90 75l-11 10-3-20Z", "#f0dfbb")
    for x, y, r in ((37, 58, 3.5), (49, 53, 3), (61, 56, 3.5), (74, 55, 3), (43, 68, 3), (57, 67, 3), (69, 68, 3)):
        d.spot(x, y, r, "#f5e6c3")
    d.line("M39 45q13-6 29-2", "#ddaf77", 2)
    return d


def masked_mammal(kind: str) -> Drawing:
    traits = {
        "raccoon": ("black bandit mask", "four dark tail rings", "dexterous long fingered paws"),
        "red-panda": ("short round white face and russet brows", "long ringed bushy tail", "round white-lined ears and dark legs"),
        "badger": ("two broad dark facial stripes", "low stocky grey body", "long pale digging claws"),
        "skunk": ("high arched bushy plume", "two bold white dorsal stripes", "black body and small white forehead blaze"),
        "civet": ("black eye mask and broad white forehead stripe", "unspotted grey-brown long body", "long dark tail and short black legs"),
        "opossum": ("long naked curling pink tail", "white pointed face with pink nose", "bare dark ears and opposable hind toes"),
    }
    d = Drawing(traits[kind])
    if kind == "raccoon":
        d.path("M36 84C17 65 3 72 7 89q3 22 38 11Z", "#a5a99c")
        d.detail("M8 79l10 1 8 19-9 0Zm17-3 9 4 8 18-9 3Z", "#525b55")
        d.ellipse(59, 82, 32, 26, "#a5a99c")
        d.detail("M35 67q23-17 45 1l-5 8q-22-10-40 2Z", "#c4c9b8")
        d.path("M34 98 29 114h19l7-16m27-1 5 17h20l-11-19", "#67736b")
        d.circle(79, 39, 10, "#7d887c")
        d.circle(104, 37, 10, "#7d887c")
        d.path("M69 56q0-24 26-24 27 3 28 27l-17 26-24-2-16-16Z", "#bdc2af")
        d.detail("M73 50q23-13 46 1l-5 18-16-9-15 11-12-7Z", "#424e49")
        d.detail("M88 65q13 1 28 6l-10 10-17-5Z", "#e8e8d3")
        d.eye(87, 55, 3.5, True)
        d.eye(108, 53, 3, True)
        d.spot(114, 68, 4, INK)
        d.line("M100 77q7 3 13-2M33 112l3-5m4 6 3-5m48 5-1-5m7 5-1-5")
    elif kind == "red-panda":
        d.path("M45 89C23 115 2 94 9 63q4-15 13-11 11 8 0 24-5 13 22 3Z", "#d58449")
        d.detail("M9 64l14 5-2 9-13-4m1 10 15 3 6 10-16-1m12 5 12-7 7-9 1 13Z", "#8b523a")
        d.ellipse(61, 83, 32, 25, "#bd6c3d")
        d.path("M35 94 28 113h21l9-17m22-1 5 19h23l-12-22", "#604b3c")
        d.circle(78, 33, 12, "#d1844a")
        d.circle(108, 31, 12, "#d1844a")
        d.circle(78, 33, 7, "#f1e3c4", "none", silhouette=False)
        d.circle(108, 31, 7, "#f1e3c4", "none", silhouette=False)
        d.path("M67 57q0-23 29-23 28 1 28 25l-5 12-8 9-15-2-13 4-12-10-7-7Z", "#d1844a")
        d.detail("M73 49q8-12 19-5l-2 13-14 8Z", "#f5e6cc")
        d.detail("M103 43q12-2 16 13l-13 6-8-10Z", "#f5e6cc")
        d.detail("M68 64l15-5 13 5 13-5 14 5-9 13-17-2-13 4Z", "#f5e6cc")
        d.line("M79 52q6-4 11-1m15-1q6-4 12 2", "#a35d39", 3)
        d.eye(85, 57, 3.4)
        d.eye(111, 56, 3.1)
        d.line("M81 62l5 5m22-6-4 6", "#976045", 3)
        d.ellipse(99, 69, 14, 9, "#f5e6cc", "none", silhouette=False)
        d.detail("M94 65q6-3 11 0l-6 6Z", INK)
        d.line("M99 71v4m0 0q-6 3-10-1m10 1q6 2 10-2")
        d.line("M46 64l6-3m2 3 6-3m5 4 6-1", "#eaa568")
    elif kind == "badger":
        d.path("M29 86Q10 79 7 95l20 6Z", "#69716c")
        d.path("M23 77q9-27 45-25 27 1 35 25l-9 24q-36 17-65-1-13-7-6-23Z", "#8e9388")
        d.detail("M30 68q25-18 53-2l-3 11q-29-12-50 1Z", "#b7baaa")
        d.path("M31 96 25 111h23l9-12m29-2 5 15h23l-13-17", "#525d56")
        d.path("M82 62q-2-20 16-20 18 3 19 23l9 11-24 16-23-16Z", "#d7d8bf")
        d.circle(85, 46, 7, "#525d56")
        d.circle(105, 43, 6, "#525d56")
        d.detail("M86 48q7 1 8 9l-7 21-9-5Zm18-2q8 6 10 17l7 9-8 7-12-18Z", "#414d46")
        d.eye(107, 59, 3.1, True)
        d.spot(122, 75, 4, INK)
        d.line("M30 109l-7 6m13-5-5 6m12-6-4 6m57-5 4 5m3-5 4 5m3-6 6 4", "#dacdb2", 2.5)
        d.line("M35 63l9-2m0 5 8-2m9-4 8 2", "#d9d9c8")
    elif kind == "skunk":
        d.path("M42 81C17 76 6 52 14 22 20 5 36 6 41 16 47 26 27 39 36 56l18 21Z", "#414948")
        d.detail("M22 15q11-7 12 4-19 22-4 49l-9-8q-13-26 1-45Z", "#e8e8d8")
        d.ellipse(61, 84, 33, 24, "#414948")
        d.path("M35 99 31 114h18l9-15m22-1 5 16h20l-10-20", "#414948")
        d.detail("M32 68q27-20 52 1l-6 6q-24-14-41 2Z", "#eeeede")
        d.detail("M40 78q19-10 35-4l4 6-34 6Z", "#eeeede")
        d.circle(88, 51, 8, "#414948")
        d.path("M79 67q3-24 22-20 16 5 14 20l11 11-22 14-22-10Z", "#414948")
        d.detail("M96 48q6-1 10 4l-9 20-9 7 0-11Z", "#e8e8d8")
        d.eye(108, 64, 3.2, True)
        d.spot(123, 77, 3, "#a58a80")
        d.line("M112 83l-11 4m-59 19-3 7m51-7 3 7", "#aeb7ac")
    elif kind == "civet":
        d.path("M39 91C13 89 1 68 9 45 13 28 30 27 35 39q-20-9-17 13 1 21 27 27Z", "#786f5d")
        d.line("M12 45q-6 24 15 36", "#a69b83", 2)
        d.path("M24 78q17-22 47-17 24 3 30 24l-6 16q-39 11-68-1-12-7-3-22Z", "#aa9c7c")
        d.detail("M29 77q20-15 49-7l7 10q-29-8-52 5Z", "#c1b397")
        d.path("M34 98 29 114h17l10-15m27-1 3 16h20l-10-17", "#504f43")
        d.path("M79 66 87 42q6-13 19-7l8 16 12 16-19 16-21-2Z", "#b5a88b")
        d.path("M87 45 85 31l12 8m8 1 7-11 4 20", "#6a6250")
        d.detail("M85 49q18-11 34 11l-8 12-13-11-16 7Z", "#394a43")
        d.detail("M99 39q7 2 9 8l11 16-9 2-11-14-5-6Z", "#f2e7ce")
        d.detail("M86 70q9 0 16 8l-9 3-10-5Z", "#e4dac0")
        d.eye(109, 56, 3.1, True)
        d.spot(123, 66, 3, INK)
        d.line("M35 65q19-10 32-3", "#d4c59f", 3)
    else:
        d.path("M32 88C7 82 2 60 11 38q9-17 21-7 11 10 1 18-8 6-12-2", "none", INK, 8)
        d.path("M32 88C7 82 2 60 11 38q9-17 21-7 11 10 1 18-8 6-12-2", "none", "#d1a7a0", 5, False)
        d.line("M9 55l4 1m-3 6 5 1m0 6 5 1", "#b98d89", 1.5)
        d.path("M25 77q13-22 43-18 28 3 33 26l-5 18q-40 11-68-3-12-7-3-23Z", "#979e92")
        d.detail("M28 78q15-17 37-13l-2 8q-21 0-32 13Z", "#bcc3b1")
        d.path("M35 99 28 113h17l9-14m26 1 9 13h20l-13-15", "#b6aaa1")
        d.circle(84, 48, 10, "#535d58")
        d.circle(105, 44, 8, "#535d58")
        d.path("M79 60q7-23 24-12l12 18 12 12-23 15-22-17Z", "#e1dfcf")
        d.eye(106, 65, 3.3)
        d.spot(124, 77, 4, "#d4989d")
        d.line("M113 82l-14 4m12-8-13-2m-63 36 5-7m52 8 4-7", "#6b675b")
        d.path("M87 113 78 118l-2-5", "none", "#b6aaa1", 4)
    return d


def otter(kind: str) -> Drawing:
    sea = kind == "sea-otter"
    d = Drawing(("reclining on its back", "pale round face and thick chest fur", "large webbed hind flippers") if sea else
                ("long muscular tapering tail", "low flexible chestnut body", "small round ears and webbed paws"))
    if sea:
        d.path("M40 90Q11 117 8 101l20-23Z", "#6b5544")
        d.path("M25 66q14-14 35-4 26 10 40 36l-16 15q-36 7-57-14-17-16-2-33Z", "#80664c")
        d.detail("M36 68q25 0 45 30l-13 9Q33 101 28 79Z", "#c1a27a")
        d.path("M32 89 13 73q-9-1-9 6l15 20 16 7m13-8-17 14q-6 9 6 9l25-16", "#5c4f43")
        d.line("M10 78l11 13m16 23 15-12", "#a99272")
        d.path("M68 84q-8 8-1 17l16 1q6-4 1-9l-6-5m10-4q10 9 1 18", "#80664c", width=3)
        d.circle(87, 51, 8, "#b9a17f")
        d.circle(109, 57, 7, "#b9a17f")
        d.path("M78 58q13-17 29-7 17 12 12 30-4 15-23 12-22-5-23-19Z", "#c3ad87")
        d.detail("M78 71q21-14 37 8-4 10-20 7Z", "#eadabb")
        d.eye(99, 65, 3.6)
        d.spot(116, 74, 4, "#655043")
        d.line("M104 82l-12 2m15-8-13-3m-7 15 5 2")
        d.line("M31 71l5 3m3-1 5 4m0 6 5 3m9 7 6 5", "#e0c59c")
    else:
        d.path("M37 84Q15 81 4 104q20 1 42-9Z", "#8e6b47")
        d.path("M28 78q13-22 44-16 29 6 35 28l-9 12q-31 12-64-1-15-7-6-23Z", "#9c7851")
        d.detail("M32 90q30 15 64-4l4 11q-30 14-61 5Z", "#cfb186")
        d.path("M40 95 31 110l19-1 7-10m29-2 6 13 23-4-14-10", "#846545")
        d.line("M36 108l7-7m55 6 3-7", "#b6a07b")
        d.circle(92, 54, 7, "#9c7851")
        d.circle(111, 53, 6, "#9c7851")
        d.ellipse(103, 70, 22, 19, "#9c7851")
        d.ellipse(112, 78, 14, 10, "#e0c8a2", "none", silhouette=False)
        d.eye(109, 64, 3.7)
        d.spot(124, 74, 4, "#5c493d")
        d.line("M115 83q-7 4-12-1m9-5-16-3m14 7-14 5")
        d.line("M41 71q14-6 27-2", "#cda578", 3)
    return d


def spiny(kind: str) -> Drawing:
    traits = {
        "hedgehog": ("rounded dome of short spines", "cream pointed face", "tiny ears and short feet"),
        "long-eared-hedgehog": ("very large pointed ears", "short sandy spines", "slender pale muzzle and long legs"),
        "porcupine": ("long black and cream quills", "large bristly body", "blunt muzzle and short quilled tail"),
        "tenrec": ("yellow-striped black bristles", "elongated flexible snout", "golden spiny crown and small ears"),
    }
    d = Drawing(traits[kind])
    if kind == "porcupine":
        d.path("M32 89 7 77l6 18-8 11 25-4", "#594f43")
        d.path("M17 89 7 60l16 9-9-34 19 19-2-37 16 29L56 7l9 32L82 6l-1 39 25-25-7 38 19-6-15 38-21 17H35Z", "#6b5f4c")
        d.path("M19 59 32 78m-3-38 14 34m1-40 10 37m9-43-1 42m18-40-9 43m21-29-12 32", "none", "#e0d4ae", 4, False)
        d.path("M37 86q29-18 54-5l9 24-13 11H37q-17-10 0-30Z", "#766c58")
        d.path("M38 103 34 116h19m28-14 6 14h21", "#5c5548")
        d.circle(91, 74, 7, "#766c58")
        d.ellipse(104, 88, 21, 18, "#766c58")
        d.eye(111, 82, 3.1)
        d.spot(124, 92, 3.2, INK)
        d.line("M115 99l-10 2m-60-9 6 3m8-4 5 3", "#c5b996")
    else:
        long_ear = kind == "long-eared-hedgehog"
        tenrec = kind == "tenrec"
        quill = "#584f43" if tenrec else "#9c8a63" if long_ear else "#806d4f"
        body = "#bbad84" if long_ear else "#b7a37a" if tenrec else "#cbb78c"
        data = ("M20 90 9 76l13-5-7-17 16 4-2-21 15 8 8-24 10 18 16-19 3 23 19-10-4 23 16 1-13 28-8 16H30Z" if not tenrec else
                "M14 92 9 71l12-5-5-20 16 3 3-20 14 10 10-25 10 20 16-17 5 24 18-7-4 23 13 9-15 27-8 11H26Z")
        d.path(data, quill)
        if tenrec:
            d.detail("M44 40 53 26l9 20-6 41-13 4 4-32Z", "#e0be52")
            d.detail("M79 41 88 33l-3 30-6 23-11 5 4-31Z", "#e0be52")
        else:
            d.line("M25 71l8 5m-3-19 9 8m-2-19 10 9m6-17 4 11m13-10-2 12m14-8-5 10m13 8-7 8m-40 9 7 4m8-15 5 7", "#c8b58b", 2.5)
        d.path("M36 99 28 113h20m31-13 7 13h21", body)
        d.path("M51 85q9-24 30-21 16 1 25 19l19 11-20 13-34-1q-28 1-20-21Z", body)
        if long_ear:
            d.path("M76 74Q61 34 80 35q15 7 14 35m0 1q2-31 17-28 13 12-6 33", body)
            d.detail("M78 45q9 4 10 22l-7 0Z", "#d5a99a")
            d.detail("M105 50q4 9-5 20l-1-5Z", "#d5a99a")
        else:
            d.circle(83, 66, 8 if not tenrec else 6, body)
            d.circle(83, 66, 4 if not tenrec else 2.5, "#d3b699", "none", silhouette=False)
        if tenrec:
            d.detail("M91 70q14 7 20 18l-9 5-17-11Z", "#514c41")
        d.eye(102, 81, 3.1)
        d.spot(122, 94, 3.2, "#615144")
        d.line("M112 101l-12 1m8-7-10-1M63 92l6 3", "#7b694d")
    return d


def beaver() -> Drawing:
    d = Drawing(("large flattened crosshatched paddle tail", "orange front incisors", "webbed hind feet and small rounded ears"))
    d.path("M35 84Q10 71 4 88q-3 22 23 26l23-17Z", "#796047")
    d.line("M10 87 30 107m-19-9 22-9m-17 18 17-15m-9-11 14 14", "#aa8c65")
    d.ellipse(61, 82, 35, 29, "#a17b50")
    d.detail("M35 70q20-19 43-3-26-1-40 16Z", "#c09c68")
    d.path("M37 102 24 114l20 1 8-5m26-7 4 15 27-4-15-12", "#826142")
    d.line("M32 113l7-7m46 9 5-8m5 5 3-7", "#c4a580")
    d.circle(90, 49, 9, "#a17b50")
    d.circle(111, 49, 8, "#a17b50")
    d.ellipse(101, 70, 24, 23, "#a17b50")
    d.ellipse(110, 81, 16, 11, "#cfb48a", "none", silhouette=False)
    d.path("M106 84v14h8V84m0 0v14h7V84", "#e5bc6a", width=2, silhouette=False)
    d.eye(109, 64, 3.5)
    d.spot(123, 77, 4, "#594c3e")
    d.line("M113 85l-15 3m14-9-14-4")
    d.path("M80 88q8 6 20 4", "none", "#a17b50", 7)
    d.line("M88 93l3-5m5 5 3-5")
    return d


def bat(kind: str) -> Drawing:
    fruit = kind == "fruit-bat"
    d = Drawing(("long foxlike muzzle", "broad pointed dark wings", "golden furry collar") if fruit else
                ("very large listening ears", "scalloped membranous wings", "short muzzle and tiny hanging feet"))
    wing = "#65554c" if fruit else "#686878"
    fur = "#956e43" if fruit else "#8b8792"
    if fruit:
        d.path("M56 56Q30 19 5 31l7 45 15-10 14 24 17-9m14-25q26-37 51-25l-7 45-15-10-14 24-17-9", wing)
        d.line("M56 61 13 38l14 28m29-5L41 85m31-24 43-23-14 28m-29-5 15 24", "#b49a7a", 2)
        d.ellipse(64, 75, 16, 28, fur)
        d.detail("M49 56q16-10 30 1l-1 12q-14-6-28 0Z", "#cbab6f")
        d.path("M52 43 46 21l16 12 12-12 6 25", "#886344")
        d.path("M51 40q15-17 28 2l15 13-20 12-22-8Z", "#9b754d")
        d.eye(74, 43, 4)
        d.spot(91, 54, 3, INK)
        d.line("M77 61q6 1 11-2")
    else:
        d.path("M54 58Q32 23 6 36l3 34 15-7 15 20 14-8m21-17q22-35 48-22l-3 34-15-7-15 20-14-8", wing)
        d.line("M53 61 13 40l11 23m29-2-14 22m36-22 40-21-11 23m-29-2 14 22", "#a9a2b2", 2)
        d.ellipse(64, 77, 16, 25, fur)
        d.path("M50 45Q34 4 50 10q11 4 14 27 3-23 14-27 16-6 0 35", fur)
        d.detail("M50 19q8 8 9 21l-5 0Zm28 0q-8 8-9 21l5 0Z", "#bea5ac")
        d.ellipse(64, 48, 20, 18, fur)
        d.eye(56, 46, 4)
        d.eye(74, 46, 4)
        d.detail("M59 54q5-4 10 0l-5 5Z", "#bb9098")
        d.line("M58 61q6 4 13 0")
        d.detail("M52 73q13-8 24 0l-5 21H58Z", "#b9adb4")
    d.path("M56 97 51 109l7-2 6 4m8-14 5 12-7-2-6 4", "none", "#655750", 4)
    d.path("M60 101 64 116l4-15", wing)
    return d


def turtle(kind: str) -> Drawing:
    traits = {
        "turtle": ("flat olive oval shell", "striped long neck", "small webbed feet and pointed tail"),
        "box-turtle": ("high domed dark shell", "bright golden scute markings", "hinged belly rim and orange speckled head"),
        "tortoise": ("very high brown shell", "thick elephantlike scaled legs", "blunt beaked head and pale hexagonal scutes"),
    }
    d = Drawing(traits[kind])
    tort = kind == "tortoise"
    box = kind == "box-turtle"
    skin = "#a99d6e" if tort else "#a88e55" if box else "#93ad66"
    shell = "#9d8451" if tort else "#5d6851" if box else "#759a66"
    d.path("M29 86 9 92l20 7", skin)
    if tort:
        d.path("M33 89q-9 14-4 28h17l4-23m28-1q-1 18 8 24h19l-6-25", skin)
        d.line("M32 102h10m-12 6h12m44-4h11m-7 6h10", "#6d684e", 2)
    else:
        d.path("M34 87 18 105l18-2 5 7 8-16m31-1 5 17 21-6-16-13", skin)
        d.line("M24 102l8-7m55 12 2-9m4 6 2-8", "#596b49", 2)
    top = 27 if tort else 34 if box else 48
    d.path(f"M24 82Q23 {top} 61 {top-3}q37 0 39 {84-top}l-8 14q-35 14-64 0Z", shell)
    d.detail(f"M33 70Q35 {top+2} 60 {top+4}q25-4 32 32l-11-6-16-13-19 9Z", "#bca56a" if tort else "#d7b763" if box else "#a3bc7c")
    d.path("M28 88q32 13 67-1l-3 10q-30 14-61 0Z", "#bca56e" if tort else "#cead62" if box else "#c2c58b", width=3, silhouette=False)
    d.line(f"M60 {top+1} 46 62l9 22 23-1 12-22-30-{61-top}ZM46 62 29 71m61-10 9 12M55 84l-9 13m32-14 7 13", "#665f43" if tort else "#465e44", 2.6)
    if box:
        d.detail("m40 58 5-7 2 12-6 9-4-2Zm21-13 5 9-5 12-5-8Zm18 15 6 3-7 10-4-5Z", "#f4cf6b")
        d.line("M39 93h39", "#766343", 2)
    elif tort:
        d.line("M53 48l9-4 10 5m-20 4 10-4 8 5m-26 20 7 6m28-4-7 6", "#f0dab1", 2)
    else:
        d.line("M38 71l7 3m18-15 4 8m13 6-5 7", "#d5d6a3", 2)
    d.path("M91 78q5-21 23-16 16 8 11 23-7 12-23 6l-10-2Z", skin)
    d.eye(116, 71, 3.2)
    d.line("M113 84q7 1 12-4", "#596247")
    if tort:
        d.line("M102 66l5 6m-3 3 5 4m-8 4 5 4", "#d7c395")
        d.detail("M119 79l8 1-5 6Z", "#8a7852")
    elif box:
        for x, y in ((104, 69), (108, 77), (99, 83), (115, 85)):
            d.spot(x, y, 2, "#edbf66")
    else:
        d.line("M95 80q10-11 20-3m-18 8 17-3", "#d9d9a7", 2)
    return d


def frog(kind: str) -> Drawing:
    tree = kind == "tree-frog"
    toad = kind == "toad"
    d = Drawing({
        "frog": ("long folded athletic hind legs", "smooth green striped back", "raised golden eyes and pale belly"),
        "tree-frog": ("round suction pads on long toes", "bright leaf green body", "large golden eyes and dark flank stripe"),
        "toad": ("broad squat brown body", "bumpy warty skin and parotoid glands", "short stout hind legs"),
    }[kind])
    body = "#80b965" if not tree and not toad else "#82c76a" if tree else "#a9956b"
    belly = "#dae2ac" if not toad else "#d3bf8e"
    d.path("M40 86Q15 72 8 104l28-5 15-10m25 2q13-5 30 14l-25 2-13-9", "#6d9951" if not toad else "#96835d")
    d.ellipse(58, 82, 36 if not toad else 40, 26 if not tree else 23, body)
    d.detail("M39 88q26 18 51-2l-3 16q-26 15-46 0Z", belly)
    if tree:
        d.path("M48 88 31 109l-11 2m58-21 14 19 17 2", "none", body, 7)
        for x, y in ((17, 111), (23, 118), (31, 113), (110, 111), (118, 108), (118, 116)):
            d.circle(x, y, 3.3, "#b4d77f", width=2)
        d.line("M26 109 24 116m76-6 14 5", "#5c8e4c", 2, True)
        d.detail("M30 77q23-13 50-5l8 7q-29-10-55 4Z", "#466b4a")
    else:
        d.path("M48 92 34 114H15m65-23 15 23h25", "none", body, 7)
        d.line("M16 114l-5 5m11-5-2 6m94-6 6 5m-2-5 8 0", "#6b7f4f" if not toad else "#7a6d4d", 2, True)
    d.circle(80, 49, 12 if not toad else 11, body)
    d.circle(108, 48, 12 if not toad else 10, body)
    d.path("M68 63q2-19 25-18 28-2 31 24-4 21-30 23-25-4-26-29Z", body)
    d.detail("M75 73q23 8 43-4-1 20-23 19-16 0-20-15Z", belly)
    d.circle(81, 49, 7, "#e9c76d", width=2, silhouette=False)
    d.circle(110, 48, 7, "#e9c76d", width=2, silhouette=False)
    d.eye(83, 49, 3.5)
    d.eye(112, 48, 3.5)
    d.line("M84 76q16 8 31-1", "#58714b" if not toad else "#796344")
    d.spot(119, 65, 1.8, "#6a8250")
    if toad:
        d.ellipse(75, 65, 8, 5, "#c1ac7c", width=2, silhouette=False)
        for x, y, r in ((30, 75, 3), (43, 65, 4), (54, 73, 3), (38, 89, 3), (62, 63, 2.5), (57, 86, 2.4), (19, 98, 2)):
            d.circle(x, y, r, "#877951", "#c8b988", 1, False)
    elif not tree:
        d.line("M33 66q19-13 36-2", "#d5d28b", 4)
        d.spot(45, 79, 4, "#5e8b4a")
        d.spot(59, 73, 3.5, "#5e8b4a")
    else:
        d.line("M44 65q11-5 21-1", "#c8e599", 3)
    return d


def reptile(kind: str) -> Drawing:
    traits = {
        "gecko": ("broad round adhesive toe pads", "large lidless eyes", "flattened spotted body and soft thick tail"),
        "lizard": ("long tapering whip tail", "pointed narrow head", "paired cream dorsal stripes and clawed toes"),
        "chameleon": ("high helmet crest", "spiral curled grasping tail", "turret eye and opposing grasping toes"),
        "salamander": ("bold yellow patches on black skin", "broad rounded head", "stout limbs and thick tapering tail"),
        "newt": ("wavy dorsal and tail crest", "bright orange belly", "slender spotted body and small delicate limbs"),
    }
    d = Drawing(traits[kind])
    if kind == "chameleon":
        d.path("M39 85C18 71 4 82 8 99q6 20 23 9 11-8 1-17-7-3-10 4 0 7 7 6", "none", "#7eae63", 9)
        d.path("M32 76q4-25 37-25 26 1 33 30l-8 16q-45 13-62-7Z", "#80b76a")
        d.detail("M40 70q21-17 45 5l-4 9-36-2Z", "#b1ce79")
        d.path("M87 65 91 27l21 27 13 24-16 15-23-7Z", "#80b76a")
        d.line("M90 61 95 40l10 15", "#b1ce79", 3)
        d.circle(108, 64, 10, "#b2cb7b", width=3, silhouette=False)
        d.eye(112, 63, 3.8)
        d.line("M107 82q9 1 17-5", "#567544")
        d.path("M45 88 37 110l12 2 6-6m24-15 7 21 11-3 5-5", "none", "#6a9c54", 7)
        d.line("M43 108l3 7m47-6 3 6", "#536e42", 3, True)
        for x, y in ((47, 77), (57, 72), (67, 78), (76, 71), (85, 83)):
            d.spot(x, y, 2, "#648a4e")
        return d
    gecko = kind == "gecko"
    sal = kind == "salamander"
    newt = kind == "newt"
    color = "#a9b883" if gecko else "#9aab61" if kind == "lizard" else "#536964" if sal else "#788b72"
    if gecko:
        d.path("M41 81Q19 70 7 86q-10 17 9 18l23-17Z", "#a9b883")
    elif newt:
        d.path("M45 75Q20 48 3 59l13 23-9 11 35-5Z", "#b0b68b")
        d.detail("M39 78Q17 66 8 63l11 16-6 8 27-4Z", "#d29c62")
    else:
        d.path("M41 80C23 71 23 93 6 94 3 99 15 107 27 94l22-5Z", color)
    leg_stroke = "#d7a15e" if newt else color
    d.path("M47 72 30 53l-16 3m31 30-12 19-18 2m66-35 12-21 15-1m-20 38 14 19 17 1", "none", leg_stroke, 7 if not newt else 5)
    if gecko:
        for x, y in ((14, 52), (10, 58), (19, 60), (12, 104), (12, 111), (22, 111), (106, 47), (114, 51), (110, 57), (118, 105), (123, 112), (113, 114)):
            d.circle(x, y, 3, "#c8cf97", width=1.5)
    else:
        d.line("M15 56l-7-4m7 4-6 5m8 47-7-3m7 3-7 5m99-62 8-5m-8 5 10 2m0 58 7 5m-7-5 7-2", INK, 1.7, True)
    d.ellipse(63, 77, 31, 16 if not gecko else 18, color)
    if newt:
        d.path("M36 65 44 56l7 5 9-9 7 8 9-7 8 9 8-2 1 13", "#9ea97c", width=2)
        d.detail("M39 83q21 9 49-1l-6 9q-25 7-42 0Z", "#e3a568")
    if gecko:
        d.path("M82 67q18-16 32-6l12 15-12 19-30-9Z", color)
        d.circle(105, 68, 8, "#d5c88a", width=2, silhouette=False)
        d.eye(108, 68, 4.5)
        d.line("M108 85q8 3 14-2")
        for x, y, r in ((45, 73, 4), (56, 80, 3), (66, 69, 3), (75, 81, 4), (22, 90, 3), (93, 81, 2.5)):
            d.spot(x, y, r, "#718864")
    elif sal:
        d.path("M83 65q18-10 34 0 13 10 5 22-10 13-35 0Z", color)
        d.eye(111, 68, 3.5)
        for data in ("M42 65q12-7 15 4l-6 8-13-5Z", "M63 73q8-9 16 0l-1 8-11 3Z", "M92 61q12-2 14 5l-10 8-10-5Z", "M28 91l7-5 6 5-7 5Z"):
            d.detail(data, "#e6c85d")
        d.line("M111 84q6 2 12-3", "#b2be9d")
    elif newt:
        d.ellipse(104, 74, 19, 14, color)
        d.eye(113, 67, 3.3)
        d.line("M109 82q7 1 12-3")
        for x, y in ((45, 73), (58, 70), (70, 75), (84, 72), (99, 74)):
            d.spot(x, y, 2.8, "#4e655e")
            d.spot(x, y, 1.4, "#d89e60")
    else:
        d.path("M84 68q13-17 25-11l17 17-20 15-22-6Z", color)
        d.eye(109, 65, 3.2)
        d.detail("M35 69q31-8 65-8l12 3q-34 0-73 10Z", "#e3d399")
        d.detail("M37 81q30-4 59-7l15 2-14 4-55 7Z", "#5b774e")
        d.line("M111 80l11-3")
        d.line("M54 74l3 3m10-4 3 3m10-4 3 3", "#c0c481")
    return d


def snake(kind: str) -> Drawing:
    grass = kind == "grass-snake"
    d = Drawing(("yellow collar behind head", "olive green scales", "long narrow head and curled body") if grass else
                ("sinuous double S coil", "brown diamond saddle markings", "rounded head and forked tongue"))
    base = "#8fa76c" if grass else "#ad9568"
    d.path("M9 96C20 77 30 80 45 94q16 15 31 0C91 79 58 76 58 60q2-18 20-12 12 4 15 17l-9 11q-14 0-15-12-7 7 6 12 28 12 17 29-13 19-38 7-21-11-29-7-16 7-16-9Z" if not grass else
           "M6 103q13-23 34-10 23 15 35-1 8-12-8-16-17-5-12-21 5-17 21-9 14 5 17 20l-10 9q-12-1-14-14-5 7 7 12 26 10 17 29-16 24-45 6-19-12-27-1-9 10-15-4Z", base)
    d.detail("M12 99q14-12 35 4 20 13 37-2l-1 7q-17 14-36 2-21-15-35-6Z", "#d6cf96" if grass else "#ddc69b")
    d.path("M77 51q1-19 21-20 24 0 28 20-3 17-27 17-19-1-22-17Z" if not grass else
           "M78 51q3-17 23-16 20 0 25 16-9 14-27 12-17-1-21-12Z", base)
    if grass:
        d.detail("M81 43q-3 11 5 18l8-2-4-18Z", "#e5d888")
        d.detail("M78 46q-2 12 5 20l5-3-5-16Z", "#485a43")
        d.line("M24 93l3 3m8-3 3 3m10 7 3 3m11 0 3 2m10-8 2 2M100 38l4-2m7 2 4 1", "#536d49")
    else:
        d.detail("M19 91l7-4 7 5-7 5Zm23 5 7 2 3 7-8-1Zm26 2 8-3 5 4-7 5Zm-3-35 7-6 7 5-6 5Z", "#786b4b")
        d.line("M91 38l7-3 8 3", "#dac796", 2)
    d.eye(112, 44, 3.5)
    d.line("M109 57q9 2 15-2", "#5c6849")
    d.path("M124 55 126 63m0 0-4 3m4-3 1 4", "none", "#b77777", 2)
    return d


def snail(kind: str) -> Drawing:
    slug = kind == "slug"
    d = Drawing(("shell-free elongated soft body", "oval mantle shield and breathing pore", "two tall eye stalks and ruffled foot") if slug else
                ("large spiral shell", "two long eye stalks", "broad cream gliding foot"))
    body = "#b6a18b" if slug else "#b5ba87"
    if slug:
        d.path("M7 99q8-16 24-19 18-8 39-2l23-9q26-6 31 15 3 17-18 24H17L5 105Z", body)
        d.ellipse(65, 84, 25, 14, "#938575", width=2, silhouette=False)
        d.detail("M10 101q46 10 103-2l-11 11H16Z", "#d2bfa2")
        d.line("M17 102v5m9-4v5m10-4v4m10-3v3m10-3v3m10-4v4m10-5v4m10-5v3m10-5v4", "#998775", 1.5)
        d.spot(82, 85, 2.5, "#655e54")
        d.line("M48 78q12-7 24-1M21 90l3-1m7-2 3-1m58-6 3-1", "#c5b197", 2)
        d.path("M99 72 94 39m16 31 9-35", "none", body, 6)
        d.circle(94, 36, 4.5, "#c8bca0", width=2)
        d.circle(120, 32, 4.5, "#c8bca0", width=2)
        d.eye(95, 36, 2)
        d.eye(121, 32, 2)
        d.path("M109 87 123 79m-12 16 14-1", "none", body, 3)
    else:
        d.path("M10 96q22-8 42-6 29 8 44-12 19-10 28 8 7 18-15 24H17q-12-3-7-14Z", body)
        d.detail("M14 100q48 8 99-1l-9 8H20Z", "#d9d6ac")
        d.circle(53, 65, 35, "#bb9263")
        d.detail("M25 55q6-25 34-22 24 3 27 26-12-15-32-12-18-1-29 8Z", "#d3b37e")
        d.line("M57 44c-28-7-42 26-22 42 19 16 45 0 34-19-8-15-28-5-20 7 4 6 11 1 7-3", "#785e45", 4)
        d.line("M28 74l5 7m5-39 7-2m31 40-3 6", "#e6cfa5", 2)
        d.path("M96 78 94 42m15 33 10-37", "none", body, 6)
        d.circle(94, 38, 5, "#d9d7b1", width=2)
        d.circle(120, 34, 5, "#d9d7b1", width=2)
        d.eye(95, 38, 2.5)
        d.eye(121, 34, 2.5)
        d.line("M111 91q6 3 11-1", "#7a8261")
    return d


def build() -> dict[str, Drawing]:
    return {
        "panda": panda(), "koala": koala(), "axolotl": axolotl(),
        "flying-squirrel": flying_squirrel(),
        "rabbit": rabbit("rabbit"), "hare": rabbit("hare"),
        "cottontail": rabbit("cottontail"), "snow-hare": rabbit("snow-hare"), "pika": rabbit("pika"),
        "squirrel": squirrel("squirrel"), "red-squirrel": squirrel("red-squirrel"), "chipmunk": squirrel("chipmunk"),
        "fox": fox("fox"), "arctic-fox": fox("arctic-fox"), "silver-fox": fox("silver-fox"),
        "field-mouse": rodent("field-mouse"), "hamster": rodent("hamster"),
        "bank-vole": rodent("bank-vole"), "dormouse": rodent("dormouse"),
        "jerboa": rodent("jerboa"), "shrew": rodent("shrew"), "mole": rodent("mole"),
        "muskrat": rodent("muskrat"), "water-rat": rodent("water-rat"),
        "weasel": mustelid("weasel"), "stoat": mustelid("stoat"), "ferret": mustelid("ferret"),
        "marten": mustelid("marten"), "mink": mustelid("mink"), "deer": deer(),
        "raccoon": masked_mammal("raccoon"), "red-panda": masked_mammal("red-panda"),
        "badger": masked_mammal("badger"), "skunk": masked_mammal("skunk"),
        "civet": masked_mammal("civet"), "opossum": masked_mammal("opossum"),
        "otter": otter("otter"), "sea-otter": otter("sea-otter"),
        "hedgehog": spiny("hedgehog"), "long-eared-hedgehog": spiny("long-eared-hedgehog"),
        "porcupine": spiny("porcupine"), "tenrec": spiny("tenrec"), "beaver": beaver(),
        "bat": bat("bat"), "fruit-bat": bat("fruit-bat"),
        "turtle": turtle("turtle"), "box-turtle": turtle("box-turtle"), "tortoise": turtle("tortoise"),
        "frog": frog("frog"), "tree-frog": frog("tree-frog"), "toad": frog("toad"),
        "gecko": reptile("gecko"), "lizard": reptile("lizard"), "chameleon": reptile("chameleon"),
        "salamander": reptile("salamander"), "newt": reptile("newt"),
        "snake": snake("snake"), "grass-snake": snake("grass-snake"),
        "snail": snail("snail"), "slug": snail("slug"),
    }
