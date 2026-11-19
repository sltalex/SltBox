"""Species-led, full-body cartoon birds for the tree and animal encyclopedia.

The silhouette, bill, tail and major plumage patches are specified per species;
shared helpers only paint the recurring SVG primitives.
"""

from __future__ import annotations

from .common import CREAM, INK, Drawing


def _feet(d: Drawing, data: str, color: str = "#a38256", width: float = 3) -> None:
    d.line(data, color, width, silhouette=True)


def _wing(d: Drawing, outline: str, color: str, feathers: tuple[str, ...] = (),
          feather_color: str = INK) -> None:
    d.path(outline, color, width=3)
    for curve in feathers:
        d.line(curve, feather_color, 2)


def _perching(*, features: tuple[str, ...], outline: str, color: str,
              tail: str, tail_color: str, wing: str, wing_color: str,
              bill: str, bill_color: str, eye: tuple[float, float],
              patches: tuple[tuple[str, str], ...] = (),
              feathers: tuple[str, ...] = (), feet: str = "M56 97 54 115m0 0-9 3m9-3 8 3M78 98 81 114m0 0-8 4m8-4 8 2",
              bright_eye: bool = False) -> Drawing:
    d = Drawing(features)
    d.path(tail, tail_color)
    _feet(d, feet)
    d.path(outline, color)
    for path, fill in patches:
        d.detail(path, fill)
    _wing(d, wing, wing_color, feathers)
    d.path(bill, bill_color, width=3)
    d.eye(*eye, bright=bright_eye)
    return d


def _sparrow() -> Drawing:
    d = _perching(
        features=("chestnut crown", "black cheek spot and throat bib", "streaked brown back and short conical bill"),
        tail="M37 80 7 103l30-4 13-14Z", tail_color="#816447",
        outline="M30 68C30 49 43 45 52 40 51 23 65 13 80 16c16 3 24 16 19 29 17 8 17 28 3 43-14 17-44 22-62 7-9-7-12-17-10-27Z",
        color="#bd9a70", wing="M38 60c16-11 39-10 52 3-10 22-24 33-43 28-8-5-11-19-9-31Z",
        wing_color="#927150", bill="m98 37 22 9-22 6Z", bill_color="#c3a168", eye=(85, 34),
        patches=(("M53 32c2-18 23-22 36-12 7 5 10 11 10 17-16-8-30-8-46-5Z", "#80543b"),
                 ("M63 41c9-9 25-7 35 2l-3 17-18-3-12-8Z", "#efe5c7"),
                 ("m91 51 9 2-9 14-13-8Z", "#3c3d37"),
                 ("M46 92q25 15 48-3-20 25-45 11Z", "#e6ceb0")),
        feathers=("M43 65q21 0 37-2M42 75q15 1 29-4M46 84l13-5", "M45 57 55 66m1-11 9 10m2-9 9 7"))
    d.spot(81, 48, 4, "#3e3e36")
    d.line("M12 101 34 91m-13 10 17-7", "#ceb58b", 2)
    d.detail("M65 24q8-6 17-3l-13 5Z", "#ac7d54")
    return d


def _kingfisher() -> Drawing:
    d = Drawing(("oversized dagger bill", "turquoise crown and blue mantle", "orange breast and white neck patch"))
    d.path("M38 83 17 110l23-7 13-20Z", "#247f9c")
    _feet(d, "M51 99 48 114l-8 4m8-4 8 3M73 100 75 112l9 3", "#dc7546")
    d.path("M29 66c1-15 15-22 25-25-2-20 10-31 28-29 20 2 27 17 19 34 14 10 14 30 1 45-11 15-38 21-56 10-14-8-19-20-17-35Z", "#2da1b1")
    d.detail("M31 63c5-18 17-21 27-21-4-16 3-27 16-29-5 20 6 31 22 37l-11 18Z", "#246d95")
    d.detail("M56 48c15-9 31-4 43 6 14 14 7 34-8 44-11 7-25 8-36 2-12-12-12-34 1-52Z", "#eaa25a")
    d.detail("m61 45 14-4 12 9-10 8-17-2Z", "#fff5dc")
    _wing(d, "M32 59c14-6 30-5 41 5-6 21-13 29-29 32-11-8-15-21-12-37Z", "#278ba2",
          ("M39 63q11 4 21 3M38 73l18 3M43 84l8-2"), "#1d6684")
    d.path("m96 34 29 10-28 7-6-7Z", "#44545a", width=3)
    d.line("m100 43 21 1", "#a9c5c6", 2)
    d.detail("M58 25q12-14 29-4l-16 1-10 9Z", "#77d1cb")
    for x, y in ((61, 24), (69, 20), (78, 21), (43, 58), (39, 67)):
        d.spot(x, y, 1.7, "#a6e0ce")
    d.eye(87, 32, 3.6)
    return d


def _crane() -> Drawing:
    d = Drawing(("red bare crown", "black throat and long white neck", "long dark legs and drooping black wing plumes"))
    _feet(d, "M58 84 58 115l-13 7m13-7 11 6M77 83 79 108l13 10m-13-10-7 11", "#4f5555", 3.5)
    d.path("M28 72c-11-8-9-21 6-28 16-7 34-4 48 6l14 22-27 17-25-2Z", "#f7f4e8")
    d.path("M81 58c12-9 9-22 7-34-2-13 7-22 19-19 12 4 13 17 4 23l-6 3 2 28-6 18-18-4Z", "#f5f3e8")
    d.detail("M96 21c0-10 7-14 14-10 4 4 3 11-3 15l-6 3 3 28-9 11-5-6c10-16 4-26 6-41Z", "#343f42")
    d.detail("M99 7q11-6 16 4l-4 6-13-1Z", "#d64c43")
    d.path("m113 18 13 6-15 5Z", "#c1a266", width=2.5)
    _wing(d, "M31 48c15-7 37-3 50 10 4 16-8 25-14 32l-7-12-7 10-6-12-9 8-6-15Z", "#ecede4",
          ("M38 54q22-5 35 11M41 62q13 0 25 8"), "#aebbbc")
    d.detail("M42 64q20-4 36 3l-7 26-9-13-7 13-6-15-10 10Z", "#3e4c50")
    d.line("M50 70 55 83m7-12 4 12", "#7b8b8d", 2)
    d.eye(108, 19, 2.4)
    return d


def _owl() -> Drawing:
    d = Drawing(("paired ear tufts", "large golden eyes inside broad facial discs", "rounded body with scalloped feather breast"))
    d.path("m42 90-7 24 21-8 9 13 7-15 19 9-8-24Z", "#80664f")
    _feet(d, "M44 104v13m0 0-9 4m9-4 7 4M83 105v12m0 0-7 4m7-4 8 3", "#b99b66", 3.5)
    d.path("M25 48 22 13l28 18c8-4 20-5 29 0l26-18-4 35c7 15 8 36 1 50-14 27-59 28-75 1-8-14-9-36-2-51Z", "#a0805e")
    d.detail("M33 77c16-12 44-13 61 0l-2 24c-14 18-40 19-55 1Z", "#cfb68a")
    _wing(d, "M29 58q-11 26 3 43l13-15-5-25Z", "#79614d", ("m30 73 7 17m-9-4 6 13",))
    _wing(d, "M97 57q12 26-2 44L82 86l6-26Z", "#79614d", ("m96 72-7 18m9-5-6 13",))
    d.detail("M31 47c8-14 24-16 32-2 8-14 24-12 33 2 0 22-15 31-33 30-19 1-34-9-32-30Z", "#ead5ac")
    d.circle(47, 53, 12, "#e6b84e", width=2.5, silhouette=False)
    d.circle(80, 53, 12, "#e6b84e", width=2.5, silhouette=False)
    d.eye(48, 53, 6)
    d.eye(79, 53, 6)
    d.detail("m63 64-6 10 6 8 6-8Z", "#bc8b43", INK, 2)
    d.line("m39 87 5 5 5-5m10 3 5 5 5-5m9-2 5 5 5-5M46 101l5 4 5-4m13 1 5 4 5-4", "#8f7455", 2.3)
    d.line("m30 27 9 9m57-9-8 9", "#dcc59b", 3)
    return d


def _songbirds() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    birds["bullfinch"] = _perching(
        features=("glossy black cap and stout bill", "deep coral breast", "slate mantle with white rump"),
        tail="M40 80 15 104l29-5 12-15Z", tail_color="#43515d",
        outline="M30 58c3-17 14-23 24-25 3-17 21-24 34-15 12 8 14 17 11 29 17 16 14 40-3 52-15 12-39 13-54 0-14-12-17-28-12-41Z",
        color="#e58375", wing="M33 53c15-7 34-3 43 13-2 17-14 31-28 29-13-11-19-25-15-42Z", wing_color="#758591",
        bill="m98 33 18 9-19 8-6-7Z", bill_color="#42474a", eye=(86, 31),
        patches=(("M51 31c2-18 23-24 37-14 9 7 13 18 10 31L84 53 71 40 51 39Z", "#35434a"),
                 ("M31 76 48 87 44 97 31 87Z", "#f2ead5")),
        feathers=("M39 65q14 0 27 9M42 75l18 9M48 86l9 3",), bright_eye=True)
    birds["robin"] = _perching(
        features=("orange-red face and breast", "round olive-brown body", "fine pointed bill and upright stance"),
        tail="M35 76 11 97l23 1 18-14Z", tail_color="#796a57",
        outline="M31 62c1-16 10-23 22-29 0-17 12-29 28-27 17 2 27 16 22 32 14 13 13 37-1 54-13 16-37 21-55 11-16-9-22-24-16-41Z",
        color="#9f8c6e", wing="M33 57c17-4 29 6 34 22-9 16-18 22-28 13-9-10-11-24-6-35Z", wing_color="#81705a",
        bill="m101 28 21 8-20 5Z", bill_color="#53524a", eye=(88, 27),
        patches=(("M67 16c14-9 29 0 32 14l-2 9c20 17 13 40-6 51-16-1-29-12-32-28-7-16-7-36 8-46Z", "#dd8357"),
                 ("M48 89q26 16 48-4c-11 25-37 31-52 12Z", "#e8d8b9")),
        feathers=("M38 63q11 5 18 16M38 75l12 12",), feet="M55 101 54 119l-12 4m12-4 8 4M77 101 79 118l10 3")
    birds["blue-tit"] = _perching(
        features=("blue skull cap and wings", "white cheeks crossed by black eye stripe", "yellow belly with green shoulder"),
        tail="M35 78 6 102l26-4 16-15Z", tail_color="#4482a3",
        outline="M28 64c2-18 15-25 28-27 2-16 15-25 30-21 13 3 20 14 16 26 12 11 13 31 1 46-13 16-34 22-52 14-18-8-25-22-23-38Z",
        color="#e8ce68", wing="M30 57c15-7 35 0 41 15-8 17-16 24-31 22-10-11-15-26-10-37Z", wing_color="#5e9ab1",
        bill="m100 31 17 7-17 5Z", bill_color="#47545b", eye=(87, 31),
        patches=(("M55 24q11-17 28-10 14 5 19 18L86 33 69 29Z", "#5d9ebc"),
                 ("M55 32q23-9 45 5l-2 18-24 8-18-13Z", "#f5f0d9"),
                 ("m57 36 30-3 17 5-2 7-18-6-23 4Z", "#374951"),
                 ("M30 56q8-18 24-17l11 16-22 10Z", "#9baa66")),
        feathers=("M36 66 61 69M39 77l17 2M43 86l8-1",))
    birds["great-tit"] = _perching(
        features=("large glossy black head", "white cheek and broad black belly stripe", "yellow body and olive back"),
        tail="M32 78 5 110l29-10 15-18Z", tail_color="#52666d",
        outline="M29 62c3-18 14-26 25-30 3-17 19-28 35-22 16 6 21 21 14 35 10 15 9 35-5 49-14 14-36 17-51 7-17-11-23-23-18-39Z",
        color="#dcca5e", wing="M31 53c17-5 36 3 43 21-10 15-20 22-32 18-12-10-16-25-11-39Z", wing_color="#7c8a69",
        bill="m102 29 19 8-20 6Z", bill_color="#3c4749", eye=(90, 29),
        patches=(("M53 30c2-18 21-28 36-20 17 8 21 22 12 39L83 61 63 48Z", "#34454b"),
                 ("M71 36q14-7 26 5l-5 14-15 2-10-9Z", "#f4efdb"),
                 ("m83 54 12 3-8 21-8 27-10 1 7-28Z", "#3b4748")),
        feathers=("M38 64 65 69M41 74l18 5M46 85l10 1",), bright_eye=True)
    birds["goldfinch"] = _perching(
        features=("scarlet face mask", "black and white head", "bold golden wing bar and forked dark tail"),
        tail="M36 78 9 110l17-6 5 9 20-28Z", tail_color="#3f4549",
        outline="M31 65c3-18 12-26 25-28 1-16 11-27 26-25 17 2 25 15 21 29 13 12 13 31 2 46-14 19-40 25-58 14-15-9-20-22-16-36Z",
        color="#c3a278", wing="M31 55c18-5 39 3 44 22-8 19-20 25-34 17-11-10-14-26-10-39Z", wing_color="#454b4d",
        bill="m102 29 19 10-20 6Z", bill_color="#d9be8b", eye=(91, 29),
        patches=(("M55 25q16-21 34-11l9 11-9 18-23 2-14-9Z", "#eee7d3"),
                 ("M69 15q16-10 28 7l-10 3-13-6Z", "#3d4244"),
                 ("M83 24q12-6 20 5l-1 17-13 8-8-12Z", "#c95446")),
        feathers=("M36 66 68 76M42 86l14 3",))
    birds["goldfinch"].detail("M36 62 66 66l5 10-32-4Z", "#edc64e")
    birds["goldfinch"].line("m16 105 18-14M29 104l11-12", "#e2d7bb", 2)
    birds["chaffinch"] = _perching(
        features=("blue-grey cap and nape", "warm rose face and breast", "two ivory wing bars"),
        tail="M35 79 6 104l28-1 18-18Z", tail_color="#626661",
        outline="M29 67c2-16 12-27 26-30 0-15 14-26 29-23 16 4 24 16 19 31 12 12 11 32-2 47-14 17-37 21-54 12-16-8-22-22-18-37Z",
        color="#c68d77", wing="M30 56c15-4 35 1 45 20-9 19-24 27-38 16-9-12-11-25-7-36Z", wing_color="#747f75",
        bill="m102 31 18 9-19 5Z", bill_color="#a3a499", eye=(89, 32),
        patches=(("M55 29c7-19 26-21 39-10 8 6 11 15 8 24l-17-6-20 2-12-5Z", "#84979a"),
                 ("M31 58q6-16 20-17l7 12-15 13Z", "#9ca67b"),
                 ("M44 91q27 15 49-3l-7 17-29 5Z", "#e0b799")),
        feathers=("M36 63 66 69M39 76l20 6M46 89l11-2",))
    birds["chaffinch"].line("M35 64 64 70m-24 7 22 6", "#f5ead3", 4)
    birds["wagtail"] = _perching(
        features=("very long narrow black tail", "white face with black crown and bib", "slender legs and contrasting white wing bars"),
        tail="M40 75 3 111l8 3 44-29Z", tail_color="#414b50",
        outline="M36 65c4-15 17-20 29-23 1-17 13-26 26-21 13 4 18 15 13 27 11 10 11 24-1 36-13 15-35 20-52 12-14-7-20-18-15-31Z",
        color="#e9e9df", wing="M38 57c17-4 35 2 46 17-9 17-22 23-38 16-9-10-13-21-8-33Z", wing_color="#65727a",
        bill="m104 36 20 7-20 5Z", bill_color="#3e494e", eye=(92, 37),
        patches=(("M64 34q10-20 26-15 15 4 15 20l-16-2-10-9Z", "#3c484d"),
                 ("m74 49 29 1 4 10-21 14-15-12Z", "#3b474c")),
        feathers=("M44 66 73 72M47 77l21 5",), feet="M62 94 63 118l-12 5m12-5 8 4M87 92 91 114l12 5m-12-5-7 7")
    birds["wagtail"].line("M45 63 70 69m-22 7 18 5", "#f7f3df", 4)
    birds["lark"] = _perching(
        features=("small pointed crest", "sandy streaked body", "long hind claw and short stout bill"),
        tail="M34 82 10 106l28-3 14-15Z", tail_color="#96734e",
        outline="M29 68c0-16 11-27 27-30l2-13 7 5 8-16 4 11 10-5 5 13c15 4 20 20 13 32 4 14-3 28-17 36-15 9-39 8-51-5-9-8-11-17-8-28Z",
        color="#c0a174", wing="M30 60c18-6 35 1 45 18-9 17-21 22-34 14-10-9-14-21-11-32Z", wing_color="#9f805a",
        bill="m104 44 17 8-19 5Z", bill_color="#bd9a62", eye=(91, 43),
        patches=(("M71 53q19-2 29 9c6 22-13 39-31 38L57 86Z", "#e6d3ac"),
                 ("m72 37 24-2-1 4-23 4Z", "#eddbb3")),
        feathers=("M37 66 62 74M37 76l19 9M43 86l11 4",), feet="M52 99 50 117l-9 4m9-4 8 4m-8-4-13-2M80 99 83 117l11 4")
    birds["lark"].line("m70 65 3 7m10-9 2 8m9-4 1 7m-26 3 4 8m10-5 3 7", "#97704e", 2.5)
    birds["nightingale"] = _perching(
        features=("upright plain warm-brown body", "rich rufous fanned tail", "fine dark bill and pale eye ring"),
        tail="M40 79 9 92l-1 11 16-2 14 8 17-25Z", tail_color="#b47750",
        outline="M34 65c3-15 12-22 25-26-2-18 10-30 25-29 15 2 25 15 21 29 11 13 9 35-3 50-13 18-34 23-50 15-18-9-22-23-18-39Z",
        color="#ad8c69", wing="M35 57c16-6 31 2 41 19-7 17-18 27-32 19-10-11-13-25-9-38Z", wing_color="#917353",
        bill="m103 30 19 9-19 5Z", bill_color="#655b4c", eye=(90, 29),
        patches=(("M69 51q18-3 30 10c6 22-10 41-26 44L54 94Z", "#d6bea0"),),
        feathers=("M40 65q12 4 23 14M42 78l13 10",), feet="M58 102 57 120l-12 3m12-3 9 3M81 101 85 118l10 3", bright_eye=True)
    birds["nightingale"].line("M14 96 39 86m-17 16 20-13", "#dba572", 2)
    birds["thrush"] = _perching(
        features=("bold pale eyebrow", "buff belly with dark teardrop spots", "long warm-brown tail and strong legs"),
        tail="M39 77 9 101l27 2 19-18Z", tail_color="#876348",
        outline="M30 66c1-16 13-24 25-28-1-17 11-27 27-25 16 1 25 15 20 30 13 14 12 34-1 50-15 18-40 22-57 10-14-9-18-22-14-37Z",
        color="#a68560", wing="M31 55c18-4 38 5 45 24-10 17-22 25-37 15-11-13-13-26-8-39Z", wing_color="#94704f",
        bill="m102 31 20 10-20 5Z", bill_color="#bd9b5e", eye=(89, 32),
        patches=(("M67 49q25-1 35 18c2 22-16 39-36 39L51 93Z", "#e4cca3"),
                 ("M67 29q13-7 28-1l3 5-29 3Z", "#efe1bd")),
        feathers=("M38 65 63 74M40 78l18 10M47 89l9 3",), feet="M52 101 50 119l-12 3m12-3 8 4M82 101 88 118l11 2")
    for x, y in ((73, 64), (87, 66), (65, 77), (80, 79), (94, 78), (72, 93), (88, 91)):
        birds["thrush"].detail(f"M{x} {y}q-4 5 0 8 4-3 0-8Z", "#7a5d43")
    birds["blackbird"] = _perching(
        features=("uniform charcoal-black plumage", "bright orange-yellow bill and eye ring", "long rounded tail and lifted head"),
        tail="M36 77 5 106l27-2 20-22Z", tail_color="#3f4749",
        outline="M29 62c4-15 15-24 27-26 0-20 14-31 30-26 15 4 22 18 16 31 14 16 13 37-1 53-15 16-39 20-56 8-16-10-21-23-16-40Z",
        color="#465052", wing="M31 55c18-4 37 3 46 22-11 20-25 25-38 14-9-10-13-23-8-36Z", wing_color="#465257",
        bill="m101 28 22 10-23 6Z", bill_color="#e2ab45", eye=(89, 29),
        patches=(("M62 17q13-12 26-2l-20 6-9 12Z", "#63706f"),
                 ("M49 92q28 13 48-8c-7 22-35 29-48 14Z", "#586262")),
        feathers=("M38 64q16 1 29 12M40 77l18 8M46 88l10 1",), feet="M53 102 50 120l-11 3m11-3 8 3M82 99 87 116l11 4")
    birds["blackbird"].circle(89, 29, 5.7, "none", "#d5a547", 2, silhouette=False)
    birds["canary"] = _perching(
        features=("lemon-yellow plumage", "small pale conical bill", "olive-gold streaked wings and narrow notched tail"),
        tail="M38 82 12 111l15-5 9 7 17-27Z", tail_color="#baa447",
        outline="M30 64c3-17 15-25 27-28 1-18 15-29 30-24 14 4 21 17 16 30 11 13 9 34-4 50-13 16-35 21-51 11-17-10-22-23-18-39Z",
        color="#efd36b", wing="M32 57c14-5 31 3 41 21-9 18-20 24-33 14-9-9-13-23-8-35Z", wing_color="#c7b355",
        bill="m102 29 18 10-18 5Z", bill_color="#d9bd8a", eye=(89, 29),
        patches=(("M62 17q14-12 27 1l-18 1-11 16Z", "#fff0a1"),
                 ("M68 51q22-2 32 15c3 20-10 36-27 40L57 91Z", "#f6df77")),
        feathers=("M38 64 62 74M39 77l16 9M45 88l10 1",))
    birds["sunbird"] = _perching(
        features=("long down-curved nectar bill", "iridescent green crown and purple throat", "yellow belly and narrow swept tail"),
        tail="M36 74 7 112l14-4 7 8 24-35Z", tail_color="#3d5e64",
        outline="M34 61c4-14 14-22 25-25 1-19 15-29 29-23 13 5 18 19 11 31 11 12 12 29 1 44-12 18-36 22-52 12-14-9-19-23-14-39Z",
        color="#dabd4e", wing="M35 54c16-4 34 4 41 20-8 19-20 26-34 16-11-9-13-24-7-36Z", wing_color="#536e69",
        bill="M99 29q18 1 24 19-12-9-25-9Z", bill_color="#455358", eye=(88, 29),
        patches=(("M58 24q10-19 27-12 16 6 14 23l-21 8-19-7Z", "#3f9389"),
                 ("M74 39q13-4 26 4l2 21-23 9-13-17Z", "#756582"),
                 ("M76 63q14 4 24 0l-2 10-18 7-9-7Z", "#b16555")),
        feathers=("M40 63q14 3 24 11M43 77l14 10",), feet="M58 98 54 117l-10 4m10-4 9 4M79 97 84 115l10 4")
    birds["oriole"] = _perching(
        features=("golden yellow body", "black eye stripe and black wings", "long rose-coloured bill and dark tail tip"),
        tail="M34 79 6 110l28-4 22-20Z", tail_color="#41474a",
        outline="M26 66c2-18 17-28 32-31 3-17 17-25 31-19 16 6 20 18 14 32 12 13 11 32-3 47-15 16-43 19-60 7-13-9-17-22-14-36Z",
        color="#e6be4b", wing="M29 55c19-6 40 3 50 22-10 20-25 27-42 16-11-11-14-26-8-38Z", wing_color="#3f4848",
        bill="m103 31 22 10-22 7Z", bill_color="#c98272", eye=(90, 31),
        patches=(("M57 31q17-13 40-1l6 9-16-4-18 6-12-2Z", "#3c4546"),
                 ("M43 91q28 14 52-5l-7 18-31 6Z", "#f5d46c")),
        feathers=("M35 65 69 76M40 78l19 10",))
    birds["oriole"].detail("M34 59 50 59l-6 11-12-3Z", "#edcb59")
    birds["pigeon"] = _perching(
        features=("small head and full rounded breast", "green-purple iridescent neck", "paired dark wing bars and orange eye"),
        tail="M29 82 5 105l31 3 18-19Z", tail_color="#606d75",
        outline="M26 68c2-17 14-27 31-31 2-15 11-24 24-22 15 2 22 15 18 27 13 15 18 31 7 47-13 21-43 26-63 16-16-8-20-21-17-37Z",
        color="#99a5aa", wing="M27 56c23-4 45 10 53 29-15 19-34 20-48 6-9-11-11-23-5-35Z", wing_color="#9da8ad",
        bill="m99 31 17 8-19 6Z", bill_color="#71757a", eye=(88, 30),
        patches=(("M66 38q17-5 30 5l7 24-19 16-19-17Z", "#668d86"),
                 ("M79 42q15 7 18 20l-9 12-10-5Z", "#88778f"),
                 ("m97 31 8 3-1 6-9-1Z", "#ece6d6")),
        feathers=("M34 67q19 3 35 17M35 79l21 10",), feet="M51 103 47 119l-12 3m12-3 9 4M82 102 86 118l12 3")
    birds["pigeon"].line("M40 61 62 82m-7-22 19 21", "#53626c", 5)
    birds["pigeon"].circle(88, 30, 5.3, "none", "#dca35a", 2, silhouette=False)
    birds["dove"] = _perching(
        features=("slender pink-grey body", "spotted black and white neck collar", "long graduated tail with pale tips"),
        tail="M34 75 4 110l16-2 6 9 31-31Z", tail_color="#837a73",
        outline="M31 65c5-17 19-23 34-28-1-15 11-27 24-24 14 3 22 15 17 27 9 13 9 30-3 46-14 18-41 25-59 15-14-8-18-22-13-36Z",
        color="#b6a59a", wing="M33 56c17-5 37 4 48 22-11 17-25 25-41 15-11-11-13-24-7-37Z", wing_color="#9c8d7c",
        bill="m105 30 19 8-21 5Z", bill_color="#58605e", eye=(93, 30),
        patches=(("M63 43q17-7 36 5l2 14-25 3-13-11Z", "#454d4b"),
                 ("M68 65q21 0 30 15-4 23-30 25L54 92Z", "#c7b2a2")),
        feathers=("M39 64 68 75M40 77l21 11M47 88l10 3",), feet="M58 102 54 119l-12 3m12-3 8 4M82 100 87 116l12 5")
    for x, y in ((71, 47), (81, 47), (91, 50), (75, 55), (87, 57)):
        birds["dove"].spot(x, y, 2.2, "#eee7d3")
    birds["dove"].line("M8 110 18 109m4 4 7-3", "#e7dec9", 4)
    birds["cuckoo"] = _perching(
        features=("slim slate head and long graduated tail", "white belly barred dark grey", "yellow eye ring and slightly curved bill"),
        tail="M39 75 3 116l20-3 7 8 30-40Z", tail_color="#63727a",
        outline="M29 62c4-16 16-22 31-25 4-18 18-27 31-21 14 6 17 19 10 31 13 13 12 31-1 45-15 16-39 20-56 8-15-10-20-24-15-38Z",
        color="#8b9a9e", wing="M30 52c22-5 44 5 53 25-11 20-29 27-45 15-11-12-14-27-8-40Z", wing_color="#71848b",
        bill="M101 31q18 2 22 12l-24 2Z", bill_color="#59645d", eye=(90, 30),
        patches=(("M69 49q19 1 31 15c3 21-15 38-35 40L48 89Z", "#e5e2cf"),),
        feathers=("M38 63 71 77M40 77l24 12M49 90l12 1",), feet="M58 101 55 117l-11 5m11-5 8 3M85 97 91 115l10 3")
    birds["cuckoo"].line("M79 60 99 65M72 69l29 5M66 79l31 4M63 89l27 3", "#6b7472", 2.5)
    birds["cuckoo"].circle(90, 30, 5.6, "none", "#d6b65b", 2, silhouette=False)
    birds["cuckoo"].line("M12 108 24 110m-5-11 14 7m-6-16 14 9", "#d4d7ca", 3)
    return birds


def _special_birds() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    d = Drawing(("long deeply forked tail", "pointed swept-back wings", "navy back with cream belly and red throat"))
    d.path("M58 78 17 121l15-40-22 7 40-32Z", "#314e64")
    d.path("M56 57C41 35 22 18 4 17l21 29-10 5 31 25Z", "#3b657b")
    d.path("M64 58C73 33 87 13 104 5l-4 31 12 1-31 33Z", "#426e83")
    d.path("M45 60c0-14 10-23 21-24 3-13 13-19 25-15 13 5 15 17 8 27 9 16 4 35-8 46-11 11-30 11-40-1-8-8-10-22-6-33Z", "#e4e3d4")
    d.detail("M47 54c6-12 14-16 22-16 3-15 16-21 27-13 10 6 11 17 3 25L82 57 65 49Z", "#34566f")
    d.detail("M83 45q12-3 18 6l-6 16-14-4-7-11Z", "#bf6953")
    d.path("m99 33 19 5-18 7Z", "#45545a", width=2.5)
    d.line("M19 30 43 59M92 21 76 52M35 83 25 108", "#80a7b3", 2)
    d.eye(91, 33, 2.8, bright=True)
    birds["swallow"] = d

    d = Drawing(("needle-like nectar bill", "iridescent green body and ruby gorget", "long narrow wings in hovering pose"))
    d.path("M53 75 22 108l19-7 5 12 23-29Z", "#3c726d")
    d.path("M57 62C34 58 8 35 5 13c22 9 40 26 59 42Z", "#88a79c")
    d.path("M60 57C51 37 58 14 70 3l5 45Z", "#bad0bd")
    d.path("M42 72c4-16 18-22 29-25 1-16 10-26 23-22 11 3 15 14 9 23 7 12 5 29-6 39-16 14-35 16-47 6-7-6-11-13-8-21Z", "#4d9e89")
    d.detail("M69 53q18-11 33 0l-4 18-17 10-15-10Z", "#b95b6d")
    d.detail("M62 77q20 13 34-1-9 25-32 20L53 86Z", "#d9e2c6")
    d.path("m101 36 25 2-25 5Z", "#485958", width=2.5)
    d.line("M14 24 54 57M20 37l29 24M66 15l1 32M28 103l19-20", "#5e807a", 2)
    _feet(d, "M75 92 71 101l7-2M84 91l-1 10 7-3", "#5e6a60", 2.5)
    d.detail("M78 33q10-8 20 1l-15 1Z", "#99c6a0")
    d.eye(95, 37, 3.1)
    birds["hummingbird"] = d

    d = Drawing(("two very long ribbon tail feathers", "black crested head and blue eye ring", "white body with dark-edged wing feathers"))
    d.path("M57 63C41 82 22 106 5 124c17-8 37-24 55-49Z", "#ecebe0")
    d.path("M65 66C49 85 39 108 29 125c14-9 26-26 41-47Z", "#f4efe1")
    _feet(d, "M63 82 59 97l-9 4m9-4 7 4M80 79 83 94l10 3", "#66716b")
    d.path("M45 55c2-13 15-20 25-22l6-15 8 5 6-14 3 15c16 4 20 17 11 30 4 12-5 24-17 29-18 7-36 1-42-11-2-5-3-11 0-17Z", "#f2efdf")
    d.detail("M70 32 76 18l8 5 6-14 3 15c17 4 20 18 10 30l-18 8-17-12Z", "#3c4d50")
    _wing(d, "M47 50c18-3 33 5 38 20L65 82 48 72Z", "#e8e5d7", ("M52 58 76 70M53 66l14 9",), "#6e7a78")
    d.path("m104 35 18 7-20 5Z", "#496b74", width=2.5)
    d.circle(94, 35, 5, "none", "#71afba", 2, silhouette=False)
    d.eye(94, 35, 2.7)
    d.line("M52 76 12 118M62 79 35 116", "#a5b2ae", 2)
    birds["paradise-flycatcher"] = d

    d = Drawing(("slim pointed wings", "deeply forked white tail", "black cap and sharp red bill"))
    d.path("M52 73 7 108l15-30-17-4 42-15Z", "#e3e7e4")
    d.path("M53 56C35 39 18 17 13 5l28 13 27 31Z", "#b7c6cb")
    d.path("M67 55C78 32 98 11 119 3l-17 25 9 4-32 33Z", "#cbd4d4")
    d.path("M43 61c7-14 21-21 34-19 3-14 15-21 26-15 11 6 12 17 3 25 6 13-3 28-17 36-16 9-36 8-44-4-5-6-6-16-2-23Z", "#f2f2e8")
    d.detail("M78 42c4-15 17-21 27-14 8 5 10 11 6 19l-18-6-14 9Z", "#3c4a4f")
    d.path("m108 38 18 7-20 5Z", "#cc7356", width=2.5)
    d.detail("M14 5 29 14l29 35-9 2Z", "#758c99")
    d.line("M22 20 53 53M109 14 80 54M14 100l32-29", "#97abb4", 2)
    _feet(d, "M77 85 80 94l-8 1M86 83l5 9-8 1", "#c57759", 2.5)
    d.eye(101, 38, 2.6, bright=True)
    birds["tern"] = d

    d = Drawing(("fan-shaped cinnamon crest with black tips", "very long curved bill", "bold black and ivory striped wings"))
    d.path("M33 80 5 108l29-2 18-22Z", "#494b45")
    _feet(d, "M54 99 51 116l-12 5m12-5 9 4M78 97 83 114l10 5", "#8d7660")
    d.path("M27 65c3-15 15-23 29-26 1-12 10-21 22-20 14 1 23 14 19 28 14 15 11 36-5 49-17 13-39 15-54 4-11-8-15-21-11-35Z", "#c28e65")
    d.path("M63 27 54 8l10-1 6 13L69 3l10 2 1 16L86 5l9 6-7 15 13-10 6 9-17 11Z", "#d69c6a", width=3)
    d.detail("m54 8 10-1 3 7-10 2Zm15-5 10 2v7l-9-1Zm17 2 9 6-4 7-8-5Zm15 11 6 9-8 5-5-6Z", "#414b49")
    _wing(d, "M29 54c21-3 43 8 50 28-12 20-31 22-45 10-10-11-12-25-5-38Z", "#efe4ca",
          ("M34 64 63 75M37 77l19 11",), "#7b7769")
    d.detail("M32 59 57 65l7 9-32-6Zm3 15 35 8-5 8-29-7Zm9 18 15 1-8 8-8-4Z", "#424a49")
    d.path("M95 35q24 4 29 26-14-14-29-17Z", "#5c5b50", width=2.5)
    d.eye(86, 35, 3)
    d.line("M12 105 34 89", "#f3e7cd", 3)
    birds["hoopoe"] = d

    birds["waxwing"] = _perching(
        features=("swept-back pointed crest", "black eye mask and throat", "yellow tail tip with red waxy wing marks"),
        tail="M34 81 12 108l27 2 17-23Z", tail_color="#696e6c",
        outline="M30 64c3-17 16-23 28-26L48 21l22 7c6-15 24-19 36-6 9 9 9 22 2 31 11 14 6 33-9 46-16 14-40 15-56 4-14-10-18-24-13-39Z",
        color="#b7a797", wing="M32 56c18-3 37 6 44 24-10 18-24 23-38 13-10-11-12-24-6-37Z", wing_color="#7e8583",
        bill="m108 34 16 9-18 5Z", bill_color="#4d5554", eye=(96, 34),
        patches=(("m71 32 27-3 11 10-6 9-16-9-16 1Z", "#465153"),
                 ("m88 49 18 1-10 17-15-7Z", "#485153"),
                 ("M48 96q23 12 46-5l-6 15-22 4Z", "#d8c9b1")),
        feathers=("M38 65 64 76M41 78l19 9",), bright_eye=True)
    birds["waxwing"].detail("m13 102 25 2-3 7-23-2Z", "#e9c656")
    birds["waxwing"].line("M49 79 57 86m1-13 7 9", "#c86555", 4)
    birds["waxwing"].line("M38 63 60 70", "#e9debd", 3)

    birds["blue-jay"] = _perching(
        features=("tall angular blue crest", "black necklace and white face", "blue barred wings and long pale-tipped tail"),
        tail="M35 79 4 114l27-5 25-24Z", tail_color="#5c8caa",
        outline="M29 63c4-16 16-24 30-27l8-23 13 12 9-13 7 18c14 5 21 18 13 31 8 15 2 32-13 43-18 12-40 11-54-1-14-11-18-25-13-40Z",
        color="#cfd8d3", wing="M31 54c19-3 40 6 48 25-11 20-26 25-42 13-11-11-13-25-6-38Z", wing_color="#648fb1",
        bill="m109 41 16 8-19 4Z", bill_color="#4b585d", eye=(97, 40),
        patches=(("m59 36 8-23 13 12 9-13 7 18-11 13-14-3Z", "#6c9eb9"),
                 ("M74 46q13-7 29 5l2 9-19 7-14-10Z", "#eef0df"),
                 ("M68 43q2 21 29 20l2 10c-25 3-40-9-40-24Z", "#424f55")),
        feathers=("M38 63 67 75M39 75l23 11M47 87l12 2",), feet="M56 102 52 120l-12 3m12-3 10 4M82 99 87 117l11 4")
    birds["blue-jay"].line("M45 62 52 72m3-6 7 13m-14 0 6 10M13 107l13 1m-5-10 12 3m-4-11 13 4", "#3b607b", 3)
    birds["blue-jay"].line("M37 60 49 64M40 72l9 3M11 110l10 1", "#f3ebd3", 3)

    d = Drawing(("red pointed crown", "black and white barred back", "chisel bill and stiff prop tail for climbing"))
    d.path("M45 76 28 120l19-7 10-26Z", "#424d50")
    _feet(d, "M72 83 86 93l12-4m-12 4 6 8M64 95l17 10 13-3m-13 3 6 9", "#7c8174", 3)
    d.path("M39 58c3-15 16-21 27-24 0-15 11-25 25-22 15 3 23 17 16 31 7 14 4 31-7 44-13 17-32 23-48 13-14-9-18-25-13-42Z", "#ece8d8")
    d.detail("M66 32c1-15 14-24 27-19l12 12-21 3-17 15Z", "#c84c47")
    d.detail("M40 54q14-17 32-13l4 21-14 40-15-6Z", "#3a4b51")
    _wing(d, "M42 51c13-5 27 2 34 17L61 98 45 89Z", "#3f5158", ("M48 62 65 69M48 74l13 5M49 84l8 3",), "#ebead7")
    d.detail("M72 38q16-7 31 4l-7 14-16-4Z", "#f9efda")
    d.line("M82 43 98 46M58 88l5 11", "#3b4a4e", 3)
    d.path("m106 29 20 8-21 8Z", "#7d897f", width=3)
    d.eye(95, 31, 3)
    birds["woodpecker"] = d
    return birds


def _corvids() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    birds["magpie"] = _perching(
        features=("very long stepped tail", "white shoulder and belly panels", "dark head with blue-green wing sheen"),
        tail="M38 74 3 119l13-5 7 9 35-42Z", tail_color="#3d6370",
        outline="M29 60c4-16 18-23 32-25 2-17 16-25 30-19 15 5 21 19 13 33 13 15 11 33-4 46-16 14-41 18-57 6-16-11-20-25-14-41Z",
        color="#40565d", wing="M29 52c20-3 41 8 48 26-10 20-27 27-42 15-11-11-13-27-6-41Z", wing_color="#416d7a",
        bill="m105 30 21 10-23 6Z", bill_color="#46555a", eye=(93, 30),
        patches=(("M61 48q21-5 36 8 11 22-10 44-20 13-35-2Z", "#eceee3"),
                 ("M30 53q11-12 24-10l-6 22-19 4Z", "#eeeedd"),
                 ("M70 17q10-5 19 1l-16 6-9 12Z", "#6a858c")),
        feathers=("M37 64 67 76M39 78l22 10M45 89l12 2",), bright_eye=True)
    birds["magpie"].line("M12 112 43 85m-20 29 26-28", "#7c9f9c", 2)
    birds["crow"] = _perching(
        features=("heavy straight black bill", "rounded crown and broad chest", "layered slate wing feathers and fan tail"),
        tail="M32 79 5 103l15 8 21-8 16-19Z", tail_color="#465961",
        outline="M24 66c2-20 17-30 34-31 1-18 15-30 33-25 16 5 23 19 17 34 13 15 13 36-3 51-17 17-43 20-63 8-15-9-22-22-18-37Z",
        color="#4c5b60", wing="M27 53c22-4 45 9 51 31-15 21-32 25-47 10-11-12-14-27-4-41Z", wing_color="#4a606a",
        bill="m107 28 19 11-20 9-8-5Z", bill_color="#3e4c50", eye=(94, 28),
        patches=(("M63 22q16-16 30-4l-18 4-13 18Z", "#68797c"),
                 ("M61 50q14-6 31 5l7 17-25 15-17-19Z", "#52666b")),
        feathers=("M35 65q17 1 34 18M34 78l24 13M41 92l13 4",), feet="M49 103 45 119l-13 3m13-3 10 4M83 101 89 118l12 3", bright_eye=True)
    birds["jackdaw"] = _perching(
        features=("silver-grey nape and short thick neck", "pale blue-grey iris", "small compact body with short blunt bill"),
        tail="M34 84 11 105l30 2 17-17Z", tail_color="#485b61",
        outline="M27 69c1-17 13-27 26-32-3-20 11-34 28-32 19 2 29 19 21 37 10 15 10 34-4 49-15 17-41 20-58 8-12-8-17-18-13-30Z",
        color="#52666a", wing="M29 59c19-6 38 2 47 23-11 17-27 24-42 13-11-11-13-25-5-36Z", wing_color="#41565f",
        bill="m101 28 18 9-19 7Z", bill_color="#414f52", eye=(89, 28),
        patches=(("M52 29q-2-23 18-25l8 14-2 19-15 13-12-7Z", "#9daaa6"),
                 ("M68 10q19-12 32 10l-13 4-19-2Z", "#4b5d62"),
                 ("M52 89q24 16 43 0-12 22-34 16Z", "#637779")),
        feathers=("M36 68 65 80M39 80l19 11M46 93l10 1",), feet="M49 104 46 119l-11 3m11-3 8 4M79 102 84 117l12 4")
    birds["jackdaw"].circle(89, 28, 5.4, "none", "#cad8cd", 2.5, silhouette=False)
    birds["raven"] = _perching(
        features=("large arched bill with heavy upper ridge", "shaggy pointed throat feathers", "wedge-shaped long tail and blue-grey glossy plumage"),
        tail="M34 77 4 111l20 11 32-38Z", tail_color="#3d5561",
        outline="M24 62c3-19 19-30 35-30 5-20 21-27 37-19 16 8 19 22 11 37l5 13-11-2 2 12-13-2c-2 19-19 33-39 32-23 0-34-19-27-41Z",
        color="#465e65", wing="M26 50c23-3 46 13 51 36-14 21-35 25-48 8-11-14-12-29-3-44Z", wing_color="#456070",
        bill="M105 29q17-3 21 12l-19 10-8-9Z", bill_color="#3c4e53", eye=(94, 29),
        patches=(("M65 19q16-13 28-2l-17 5-13 15Z", "#748a8e"),
                 ("m88 51 18 1 6 11-11-2 2 12-13-2-8-12Z", "#526b70"),
                 ("M44 92q22 15 44-2-14 26-35 15Z", "#617c81")),
        feathers=("M34 62q21 4 34 22M34 76l25 17M39 91l14 7",), feet="M45 101 39 118l-14 4m14-4 9 5M81 100 88 116l14 5", bright_eye=True)
    birds["raven"].line("m93 57 5 6m-10 0 4 5M10 109l18 5", "#809697", 2)
    birds["rook"] = _perching(
        features=("pale bare skin at long pointed bill base", "steep forehead and narrow crown", "loose feathered trousers and dark layered wings"),
        tail="M32 80 7 112l31-4 16-21Z", tail_color="#405760",
        outline="M27 67c1-18 14-27 28-31 1-22 13-34 28-31 17 3 27 18 20 34 12 13 14 31 3 47l-7 20-11-5-4 10-13-9-18 3c-20-1-31-18-26-38Z",
        color="#49636b", wing="M29 55c21-5 43 7 49 28-12 20-29 25-43 13-12-10-15-26-6-41Z", wing_color="#3c5562",
        bill="m99 23 27 16-25 8-9-13Z", bill_color="#86918a", eye=(89, 23),
        patches=(("M89 20q13 1 15 13l-4 14-12-2-5-12Z", "#c2c7ba"),
                 ("M62 15q13-15 25-6L70 20l-8 16Z", "#748c8d"),
                 ("m60 93 24-2 17 10-13-1-4 11-13-9-10 3Z", "#526e72")),
        feathers=("M36 66 68 82M37 79l23 13M43 92l11 4",), feet="M51 105 48 119l-11 3m11-3 9 4M86 106 91 118l12 3", bright_eye=True)
    return birds


def _raptors() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    d = Drawing(("rusty spotted back", "slate-grey head with dark moustache", "long narrow tail and yellow hooked bill base"))
    d.path("M43 76 22 120l19-4 9 8 15-43Z", "#a58464")
    _feet(d, "M60 100 56 118l-12 3m12-3 9 4M83 100 87 116l12 5", "#d8ac53", 3.5)
    d.path("M34 59c4-15 18-22 30-25 0-17 12-29 27-25 14 4 21 17 16 29 11 17 8 37-5 52-12 16-35 22-50 12-17-10-24-26-18-43Z", "#d0b18b")
    d.detail("M66 31c0-18 13-27 28-21 13 5 18 17 12 30l-17 12-18-9Z", "#92a0a0")
    d.detail("m91 40 10-2-3 20-8-2Z", "#424e51")
    _wing(d, "M36 51c18-3 36 5 43 23-9 21-22 29-36 18-13-11-15-27-7-41Z", "#b9845b", ("M43 61 67 74M44 75l15 11",))
    for x, y in ((45, 57), (54, 61), (62, 65), (48, 70), (57, 77), (71, 67)):
        d.detail(f"m{x} {y} 4 2-3 4-3-2Z", "#5f5b4d")
    d.path("M105 28q16 0 17 11l-9 10-3-8-9-4Z", "#d4ae53", width=3)
    d.detail("m114 36 8 3-9 10-3-8Z", "#4c5350")
    d.line("M29 111 45 115m-11-15 16 5M68 67l2 5m12-3 1 5m-15 8 3 5m13-3 1 5", "#786b52", 2)
    d.eye(95, 27, 3.5)
    birds["kestrel"] = d

    d = Drawing(("blue-grey falcon hood", "broad black cheek moustache", "cream barred breast with long pointed folded wings"))
    d.path("M42 78 22 117l29-6 12-26Z", "#6b8291")
    _feet(d, "M59 101 55 118l-12 4m12-4 9 4M83 99 88 116l12 5", "#d6b75d", 3.5)
    d.path("M33 59c5-16 20-23 33-26 0-18 14-29 30-23 15 5 19 19 11 32 10 18 6 40-9 54-14 14-35 17-50 6-17-12-22-27-15-43Z", "#dcd4b6")
    d.detail("M65 32c0-18 15-30 31-22 13 7 18 21 9 34L84 49 72 40Z", "#516b7b")
    d.detail("m88 37 14 1-7 26-12-4Z", "#354c58")
    _wing(d, "M35 48c24-5 43 10 46 29l-24 28-16-14c-10-12-13-27-6-43Z", "#5d7789", ("M42 60 69 77M43 73l19 15M48 87l9 4",), "#879b9f")
    d.path("M106 28q16 0 15 11l-9 10-3-9-8-4Z", "#d6b553", width=3)
    d.detail("m114 36 7 2-9 11-3-9Z", "#4b5353")
    d.line("M79 70 98 74M73 80l25 5M68 90l22 5M30 107l21-3", "#7d8170", 2)
    d.circle(97, 27, 5.2, "none", "#e2c65f", 2, silhouette=False)
    d.eye(97, 27, 3)
    birds["peregrine-falcon"] = d

    d = Drawing(("slender long-legged hawk", "rust-orange breast barring", "very long banded tail and yellow watchful eye"))
    d.path("M39 75 5 113l17 4 41-33Z", "#7c9199")
    _feet(d, "M60 93 57 119l-12 4m12-4 9 4M86 92 92 115l12 6", "#d8b85f", 3.2)
    d.path("M31 60c5-15 20-22 36-23 5-17 18-23 31-16 12 6 14 19 6 30 10 13 7 29-7 42-16 15-40 16-55 5-14-10-18-23-11-38Z", "#e0d2ae")
    d.detail("M64 36c4-19 19-24 33-15 13 7 15 19 6 29l-20-2-19-7Z", "#6f8893")
    _wing(d, "M32 51c23-3 43 9 49 28-13 19-29 24-42 13-12-11-16-25-7-41Z", "#718b99", ("M40 62 70 78M40 75l22 13M47 88l11 3",), "#aab8b4")
    d.path("M104 34q17 1 16 11l-8 9-3-8-9-5Z", "#b8a65b", width=3)
    d.detail("m113 42 7 3-8 9-3-8Z", "#4a5555")
    d.line("M82 57 101 63M75 67l26 5M70 79l24 4M65 90l19 3", "#ba805b", 3)
    d.line("m13 105 15 3m-7-11 17 3m-8-12 16 3", "#526a74", 4)
    d.circle(95, 32, 5.3, "#d9bf61", "none", silhouette=False)
    d.eye(95, 32, 2.8)
    birds["sparrowhawk"] = d

    d = Drawing(("broad rounded wings", "dark brown body and pale horseshoe chest", "short broad barred tail and hooked bill"))
    d.path("m49 79-15 35 30 7 22-34Z", "#87715b")
    _feet(d, "M52 99 46 116l-13 5m13-5 9 6M82 99 89 114l12 5", "#c2a45b", 3.5)
    d.path("M40 47C22 30 8 37 4 52l12 8-7 9 20 4 17 13Z", "#7f6953")
    d.path("M83 48c15-20 29-20 38-8l-7 11 10 6-16 10-18 20Z", "#92795d")
    d.path("M35 61c4-16 18-22 30-24 2-18 16-28 30-22 13 5 18 18 11 29 9 17 7 36-6 52-13 15-36 19-51 8-16-11-21-27-14-43Z", "#ac9270")
    d.detail("M52 56q24-18 46 1l5 19-18 5-14-12-17 15-15-12Z", "#e6d6b3")
    _wing(d, "M36 58q13-9 27 3l-7 33-15-3Z", "#715e4e", ("M41 66 52 75M42 79l7 8",), "#ae9471")
    d.path("M105 29q14 1 14 12l-8 9-3-8-8-4Z", "#c2a75a", width=3)
    d.detail("m112 37 7 4-8 9-3-8Z", "#4b514b")
    d.line("M42 102 72 110M48 94l28 8M64 83l2 8m11-9 1 9m11-10-1 8M12 51l18 7m76-10 9-6", "#5d5949", 2.5)
    d.eye(96, 29, 3.1, bright=True)
    birds["buzzard"] = d

    d = Drawing(("broad soaring wings with fingered primary feathers", "golden nape and heavy hooked bill", "strong feathered legs with powerful yellow talons"))
    d.path("M51 83 43 116l21 9 21-9-8-32Z", "#8d7156")
    d.path("M51 60 27 23 15 7l-1 23-10-7 8 29-9 1 20 23 24 10Z", "#8e7255")
    d.path("M78 59 96 26l6-23 6 23 10-12-2 30 10-4-13 29-25 18Z", "#a0825f")
    _feet(d, "M54 94 47 107l-13 5m13-5 5 10M78 94 85 108l14 5m-14-5-4 10", "#d0ad52", 4)
    d.path("M44 64c4-14 17-21 28-22 1-17 12-26 26-22 15 4 20 17 13 29 5 15-2 35-16 46-16 13-36 12-47-2-7-9-9-20-4-29Z", "#9c805e")
    d.detail("M72 41c0-16 10-25 24-22l10 9-22 10-9 18-13-4Z", "#c6a174")
    d.detail("M59 68q23-12 38 2l-5 20-19 10-19-13Z", "#80664f")
    d.path("M110 31q16 0 15 12l-10 11-3-10-9-4Z", "#d2b55a", width=3)
    d.detail("m117 40 8 3-10 11-3-10Z", "#53584d")
    d.line("M19 30 43 67M17 45l23 27M26 64l15 12M104 29 87 67M112 43 90 73M56 112l17 3", "#5e584b", 3)
    d.line("M56 74 62 84m7-15 5 15m9-13 1 13", "#c5a477", 2)
    d.eye(101, 31, 3.3)
    birds["eagle"] = d

    d = Drawing(("white head crossed by dark eye stripe", "long brown angular folded wings", "white breast with dark necklace and curved fishing talons"))
    d.path("M42 75 20 118l26-4 18-33Z", "#7f7866")
    _feet(d, "M60 98 53 114l-12 6m12-6 8 7M85 96 92 112l12 5m-12-5-2 10", "#92a09b", 4)
    d.path("M34 59c4-16 17-23 32-24 1-17 14-29 29-24 15 5 20 19 12 32 10 17 7 38-8 53-14 14-35 18-50 7-16-12-22-27-15-44Z", "#ede8d7")
    d.detail("M69 27q16-12 32 1l4 11-20-6-16 8Z", "#eff1e6")
    d.detail("m77 29 19-4 12 11-4 10-16-11-12 1Z", "#485b60")
    d.detail("M64 55q23-7 38 8l-7 9-16-9-17 6-9-8Z", "#8b7960")
    _wing(d, "M35 49c23-2 44 10 47 32L56 107 41 91c-12-11-15-27-6-42Z", "#756e5c", ("M42 62 69 79M42 76l20 15M48 91l10 3",), "#b7ad8b")
    d.path("M106 29q17 0 15 12l-9 11-3-11-9-3Z", "#687870", width=3)
    d.detail("m113 38 8 3-9 11-3-11Z", "#3c4d4e")
    d.line("M28 109 50 105m-17-8 21-2", "#c3bb9e", 3)
    d.circle(97, 29, 4.8, "#e7ce6a", "none", silhouette=False)
    d.eye(97, 29, 2.8)
    birds["osprey"] = d
    return birds


def _other_owls() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    d = Drawing(("white heart-shaped face", "long pale legs and narrow upright body", "golden buff back with tiny dark freckles"))
    d.path("m45 86-7 28 22-8 13 13 13-12-5-23Z", "#b39672")
    _feet(d, "M51 98 47 119l-11 3m11-3 9 4M77 98 82 118l12 3", "#c3ac87", 3.2)
    d.path("M34 51c-5-19 6-37 25-40 21-4 38 10 36 30 8 15 12 35 5 53-10 25-42 28-59 10-12-13-15-34-7-53Z", "#c8ad81")
    d.detail("M43 58q22-13 42 0l5 36c-10 17-29 21-42 6Z", "#f2ead4")
    _wing(d, "M34 55q-10 32 6 48l11-23-5-22Z", "#b3936c", ("M34 69 42 90m-9-6 6 13",), "#89765e")
    _wing(d, "M91 51q20 27 7 48L82 82l-2-26Z", "#b59c75", ("M91 65 94 88m-6-9 7 16",), "#89765e")
    d.detail("M35 34c3-18 24-22 31-8 11-14 31-7 29 10-2 19-17 35-30 43-15-10-30-25-30-45Z", "#f9f1db", "#9d8c70", 2)
    d.detail("M64 34q-5 14-1 28l4 6 3-9-3-26Z", "#d7c7a7")
    d.eye(49, 39, 4.4)
    d.eye(81, 40, 4.4)
    d.detail("m64 54-5 8 6 7 5-8Z", "#b9a17b", INK, 1.5)
    for x, y in ((36, 60), (36, 76), (43, 53), (89, 59), (95, 75), (52, 83), (63, 94), (78, 86)):
        d.spot(x, y, 1.5, "#8b795e")
    birds["barn-owl"] = d

    d = Drawing(("small squat flat-topped head", "white angled eyebrows and yellow eyes", "short spotted wings and sturdy short legs"))
    d.path("m37 83-16 23 26 2 17-17 19 16 18-5-14-19Z", "#877454")
    _feet(d, "M45 98 42 117l-12 4m12-4 9 4M83 97 87 116l11 4", "#bdac73", 3.5)
    d.path("M21 40c2-17 19-26 41-24 22-3 41 5 44 22 5 15 2 28-5 37 5 20-9 35-34 36-27 2-44-14-40-34-10-9-11-24-6-37Z", "#9c8660")
    _wing(d, "M25 66q-6 27 16 36l9-23-9-16Z", "#776b51", ("M30 75 39 91m-10-5 8 12",))
    _wing(d, "M98 64q10 26-9 37L77 79l9-15Z", "#7c6f51", ("M94 75 85 90m11-5-9 13",))
    d.detail("M30 41q16-18 32-5 17-14 33 3l-1 22c-19 18-47 17-62 1Z", "#cbb78d")
    d.line("M29 37 51 40m24-1 21-5", "#f2e7c9", 5)
    d.circle(47, 49, 10, "#d8bc56", width=2.5, silhouette=False)
    d.circle(81, 48, 10, "#d8bc56", width=2.5, silhouette=False)
    d.eye(47, 49, 4.4)
    d.eye(81, 48, 4.4)
    d.detail("m64 58-5 8 6 7 5-8Z", "#b89b51", INK, 1.5)
    for x, y in ((29, 30), (42, 25), (57, 25), (75, 25), (91, 29), (48, 84), (61, 91), (76, 84), (84, 95)):
        d.detail(f"m{x} {y} 4 1-2 5-4-1Z", "#eee0b9")
    birds["little-owl"] = d

    d = Drawing(("very tall ear tufts", "large orange eyes and stern brows", "heavy upright body with bold dark breast streaks"))
    d.path("m39 87-8 28 25-9 11 16 13-16 21 8-12-28Z", "#78674f")
    _feet(d, "M45 105 42 119l-13 3m13-3 10 4M86 104 90 119l12 2", "#c0a36d", 4)
    d.path("M23 44 20 3l30 24c9-4 21-4 30 0L108 3l-4 41c7 15 10 37 2 55-14 29-61 30-79 4-11-16-12-40-4-59Z", "#aa8c63")
    d.detail("M39 70q24-14 48 0l11 26c-11 24-47 29-64 5Z", "#cfb087")
    _wing(d, "M25 58q-13 29 6 48l18-25-8-23Z", "#7a6955", ("M28 73 38 92m-11-6 8 14",), "#b79a72")
    _wing(d, "M102 57q13 29-4 49L80 82l8-24Z", "#806d54", ("M100 72 89 93m12-6-8 13",), "#b79a72")
    d.detail("M30 44q15-13 34 0 19-15 34-1l-4 26-30 12-31-12Z", "#d6bd8e")
    d.circle(46, 52, 12, "#df9845", width=2.5, silhouette=False)
    d.circle(82, 52, 12, "#df9845", width=2.5, silhouette=False)
    d.eye(47, 53, 5.5)
    d.eye(81, 53, 5.5)
    d.line("M29 39 54 45m19 0 26-7", "#5f5a49", 4)
    d.detail("m64 64-6 10 6 8 7-9Z", "#8f7954", INK, 2)
    d.line("M45 83 49 96m12-15 3 19m13-18-2 17m-18 6 5 9M87 87l-4 15", "#6e604a", 3)
    d.line("m25 15 14 15m64-15L90 30", "#dec296", 3)
    birds["eagle-owl"] = d

    d = Drawing(("round tuftless head", "dark eyes in circular russet facial discs", "mottled warm-brown body and broad short wings"))
    d.path("m44 90-10 23 23-4 10 13 13-14 18 5-10-24Z", "#9a7654")
    _feet(d, "M47 103 44 119l-12 3m12-3 9 4M82 104 87 119l11 2", "#bda378", 3.5)
    d.path("M23 47c-2-25 17-42 41-40 25-2 44 15 42 39 10 18 11 38 0 55-16 26-59 27-79 3-12-15-14-38-4-57Z", "#b28c67")
    d.detail("M34 71q29-13 59 1l7 26c-18 20-49 23-67 1Z", "#ceb18a")
    _wing(d, "M27 62q-13 25 5 43l18-23-10-21Z", "#927153", ("M28 75 38 90m-9-4 8 14",), "#cfaa78")
    _wing(d, "M99 61q15 24-2 43L79 82l10-20Z", "#967655", ("M99 74 88 92m13-7-9 14",), "#cfaa78")
    d.ellipse(46, 44, 23, 25, "#d5b18b", "#876e56", 2, silhouette=False)
    d.ellipse(82, 44, 23, 25, "#d5b18b", "#876e56", 2, silhouette=False)
    d.detail("M59 21q5-8 11 0l-2 41-4 8-5-9Z", "#9b7658")
    d.eye(46, 44, 6.3)
    d.eye(82, 44, 6.3)
    d.detail("m63 58-5 9 6 8 6-9Z", "#b7a075", INK, 1.7)
    d.line("m38 80 4 8 5-4m10 0 4 11 5-5m11-8 3 8 5-4M49 101l5 8m22-8-4 9", "#81644b", 2.5)
    d.line("m39 15 4 5m12-8 3 5m14-4-1 5m13-1-2 5", "#e5c99f", 2.5)
    birds["tawny-owl"] = d
    return birds


def _waterbirds() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    d = Drawing(("bottle-green head and narrow white neck ring", "chestnut chest with blue wing speculum", "broad yellow bill and curled black tail feathers"))
    d.path("M33 72 5 65l13 20 19 5Z", "#4a625e")
    d.path("M18 75q-9-10 2-16 8-2 8 6", "none", width=3)
    _feet(d, "M52 94 50 108l-13 10 22-2-1-12M81 95l6 12-5 12 20-5-12-11", "#d3974c", 3.5)
    d.path("M20 73c7-18 34-23 58-18l3-18c-5-16 2-29 16-30 17-1 26 13 20 26l-8 10-2 28c-1 19-24 32-48 31-24-1-39-12-39-29Z", "#b5b4a0")
    d.detail("M81 37c-4-16 3-29 17-30 17-1 25 13 18 27l-9 9-2 22-23-1Z", "#4d8b72")
    d.detail("M82 59 107 59l-1 7-24-1Z", "#f1ead5")
    d.detail("M83 65 106 66c-1 18-13 28-28 32L65 79Z", "#92674e")
    _wing(d, "M28 66c19-9 42-5 57 10-12 20-34 26-51 14-8-6-11-15-6-24Z", "#9da398", ("M36 69 69 77M37 80l25 5M45 89l14 1",), "#6c7e77")
    d.detail("m39 78 26-2 9 8-30 5Z", "#4d6d9d")
    d.line("m43 89 29-4", "#ece7cf", 2.7)
    d.path("m114 20 12 4-1 11-15 2Z", "#d8b95b", width=3)
    d.spot(123, 27, 1.4, "#7b784d")
    d.eye(106, 20, 2.9)
    birds["mallard"] = d

    d = Drawing(("orange upright sail feathers", "white eye crescent and cinnamon cheek whiskers", "red bill with purple chest and green crown"))
    d.path("M31 78 6 74l15 21 18-5Z", "#4c706b")
    _feet(d, "M53 98 48 112l-11 8 22-2-1-12M82 99l6 12-2 10 19-7-14-7", "#d09853", 3)
    d.path("M22 76c8-18 34-24 57-19l3-18c-5-15 2-27 16-26 17 0 23 15 15 27l-7 11 1 25c-2 17-25 30-48 29-23-2-39-12-37-29Z", "#c28c61")
    d.detail("M82 36c-3-16 9-28 22-22l10 12-13 10-9 21-13-4Z", "#496e65")
    d.detail("M87 28q11-9 23 0l-4 10-14 5-9-4Z", "#f4ebd4")
    d.detail("M84 40q12-7 23 1L97 68 79 57Z", "#d59654")
    d.line("M86 47 97 54m-13 2 9 6", "#8c5c3f", 2)
    d.detail("M77 62 101 62l1 20-22 12-14-18Z", "#80677c")
    _wing(d, "M26 68c17-8 38-4 50 13-10 18-29 20-43 10-8-6-11-14-7-23Z", "#c3a477", ("M33 72 62 83M38 84l16 7",), "#8e7357")
    d.path("M55 85 57 42Q76 53 81 81L65 96Z", "#dc9b4d", width=3)
    d.detail("M59 49q14 11 18 29l-12 10Z", "#f0bb68")
    d.line("M61 58 65 82M68 68l4 13", "#b67d43", 2)
    d.line("M78 68 96 71m-19 6 20 2", "#f3e9d1", 3)
    d.path("m112 27 14 4-2 10-16-1Z", "#c96c5b", width=3)
    d.eye(104, 26, 3)
    birds["mandarin-duck"] = d

    d = Drawing(("chestnut head crossed by broad green eye stripe", "small compact duck body", "green wing mirror and creamy yellow tail patch"))
    d.path("M30 78 4 75l19 18 18-4Z", "#65746d")
    _feet(d, "M52 97 48 111l-11 7 22-2-2-11M82 97l5 11-3 10 18-7-13-6", "#879575", 3)
    d.path("M21 78c6-17 31-24 56-20l5-15c-7-13-2-25 11-28 16-4 29 8 23 22l-11 13 2 27c-3 17-25 27-47 27-22-1-40-11-39-26Z", "#b5b5a0")
    d.detail("M81 43c-6-14-1-25 12-28 17-3 28 10 21 24l-12 15-20 4Z", "#a66b4c")
    d.detail("M91 25q13-7 22 4l-6 11-16 6-5-7Z", "#426f63")
    d.line("M87 26q12-11 24 0M87 40l15 7", "#e2c38b", 2)
    d.detail("M23 81 37 88l-2 11-12-8Z", "#e3d39a")
    _wing(d, "M30 69c18-8 39-4 53 11-11 18-30 23-47 12-8-6-11-15-6-23Z", "#969f92", ("M37 74 70 80M38 84l25 5",), "#697d75")
    d.detail("m42 77 26-1 8 8-28 6Z", "#4a8768")
    d.line("m46 90 28-5", "#ece7ca", 2.5)
    d.path("m114 25 12 5-1 9-17-1Z", "#60716a", width=3)
    d.eye(105, 24, 2.8)
    d.line("M65 94h7m5-4h7M52 99h7", "#6e7e72", 1.8)
    birds["teal"] = d

    d = Drawing(("long upright grey neck", "strong orange-pink bill", "pale-edged layered grey back and broad webbed feet"))
    d.path("M29 75 5 67l15 22 20 1Z", "#727d77")
    _feet(d, "M51 96 46 114l-14 8 25-2 2-12M79 96l8 14-3 12 23-8-17-9", "#ca9866", 3.5)
    d.path("M20 74c7-17 30-23 53-18 8-7 10-16 8-29-2-13 7-23 20-21 14 2 20 14 13 25l-10 8 3 32c0 20-24 34-49 32-24-1-41-13-38-29Z", "#b4b8aa")
    d.detail("M82 28c-2-13 8-23 20-21l10 6-13 6-5 21 5 25-12 7-7-14Z", "#d0cdb8")
    _wing(d, "M25 65c20-9 43-4 58 13-11 21-35 27-52 14-10-7-11-18-6-27Z", "#8e9a93",
          ("M32 69q18 3 38 12M33 78l27 10M39 89l19 6",), "#d6d6bf")
    d.detail("M28 72 37 73l30 21-10 5-23-10Z", "#6a7974")
    d.path("m112 15 14 6-1 11-17 2Z", "#d69d70", width=3)
    d.spot(123, 24, 1.5, "#8d765c")
    d.eye(105, 15, 2.7)
    birds["goose"] = d

    d = Drawing(("long elegant S-shaped neck", "arched white wings with layered feathers", "orange bill with black basal knob"))
    d.path("M29 82 5 73l15 22 20 1Z", "#e3e6db")
    _feet(d, "M56 99 52 111l-13 9 24-3-1-10M79 99l6 10-3 9 20-6-13-6", "#6d7971", 3)
    d.path("M20 80c5-18 28-27 50-20 9 3 18 7 22 0 7-10-5-18-10-29-5-13 3-26 16-27 14-2 24 10 20 22-2 8-10 10-13 15-2 5 8 13 10 24 4 22-23 42-54 41-24-1-43-11-41-26Z", "#f1f0e3")
    d.detail("M101 12q13-2 13 10c-1 8-11 11-14 18-6 13 12 20 9 31l-9 7c10-21-12-28-9-42 2-10 12-12 10-24Z", "#d2ddd8")
    _wing(d, "M29 69C34 45 51 38 69 46l-7 14 13-2-2 15 14 4c-12 23-36 28-52 18-10-6-12-17-6-26Z", "#f7f4e7",
          ("M39 60q12 2 22 9M34 74q16 0 31 12M39 88l19 7",), "#b5c7c4")
    d.path("m116 15 10 6-1 11-15-4Z", "#d49b62", width=3)
    d.detail("m113 13 7 3-2 9-8-3Z", "#414e50")
    d.eye(108, 15, 2.8)
    birds["swan"] = d

    d = Drawing(("chalk-white frontal shield and bill", "rounded slate-black body", "large green lobed toes instead of duck webs"))
    d.path("M31 78 10 71l10 22 18-2Z", "#465d65")
    _feet(d, "M50 97 44 111l-15 8m15-8 4 12m-4-12-8 1M80 97 87 110l16 7m-16-7-1 12m1-12 9-1", "#9aa581", 4)
    d.path("M21 75c7-18 30-24 52-19l7-15c-4-14 4-25 17-23 15 2 23 15 16 27l-7 7 1 24c-3 18-25 28-48 27-23-1-40-13-38-28Z", "#536970")
    d.detail("M81 39c-3-14 6-24 19-20 13 4 18 16 10 27l-12 8-15-3Z", "#3e535d")
    _wing(d, "M28 65c19-6 40-1 51 16-12 17-29 23-44 12-10-8-12-18-7-28Z", "#607a80", ("M35 72q18 2 32 12M37 83l19 6",), "#91a3a0")
    d.path("m107 31 19 7-2 10-20-2Z", "#e8e7cf", width=3)
    d.detail("M98 23q7-5 11 1l1 14-11 2Z", "#f3eed4")
    d.spot(101, 28, 2.8, "#4f4d44")
    d.eye(101, 28, 2.1)
    birds["coot"] = d

    d = Drawing(("red frontal shield with yellow-tipped bill", "white flank stripe and raised tail", "very long green toes and legs"))
    d.path("M30 73 8 59l2 27 25 9Z", "#4b6269")
    d.detail("m10 66 12 13-11 8Z", "#e7e7ce")
    _feet(d, "M52 96 46 111l-20 10m20-10 7 12m-7-12-13-2M80 96 88 108l20 8m-20-8 2 14m-2-14 10-4", "#a8b779", 3.7)
    d.path("M21 74c7-17 29-22 51-18l7-16c-2-14 6-24 19-21 14 4 20 17 12 28l-8 9 1 21c-5 17-25 27-47 25-22-2-38-13-35-28Z", "#4d656e")
    d.detail("M79 39c-1-15 9-24 22-19 13 6 17 19 7 28L92 57 78 50Z", "#405762")
    _wing(d, "M27 63c19-6 41 3 50 19-12 18-30 22-44 10-10-9-12-19-6-29Z", "#687a76", ("M34 71q18 3 31 12M37 82l18 7",), "#8c9c8f")
    d.line("M38 94q25 7 47-7", "#e9e8cf", 3.5)
    d.path("m106 32 20 6-2 11-22-3Z", "#ca6b52", width=3)
    d.detail("m119 37 7 1-2 11-7-1Z", "#e5c65d")
    d.detail("M96 23q6-5 10 1l2 14-10 2Z", "#c8584d")
    d.eye(99, 29, 2.8)
    birds["moorhen"] = d

    d = Drawing(("white head and breast", "pale grey wings with black-and-white tips", "strong yellow bill and pink webbed feet"))
    d.path("M30 78 5 99l30 3 19-17Z", "#d8e1df")
    _feet(d, "M58 99 54 115l-14 8 25-2-3-12M82 97l6 14-4 12 23-9-16-8", "#c49c83", 3.2)
    d.path("M23 69c4-18 23-26 42-25 4-18 16-29 30-25 15 3 21 17 14 29 10 14 10 32-4 46-15 16-43 21-62 9-17-10-24-23-20-34Z", "#f0efe1")
    _wing(d, "M27 57c24-4 48 7 58 26-15 20-36 25-52 12-12-11-15-25-6-38Z", "#a0b3ba", ("M35 67 73 82M36 81l24 10",), "#718b98")
    d.detail("M34 85q16 7 36 0L59 101 42 98Z", "#455f6e")
    d.line("M42 93 48 94m5-1 6-1", "#eff0e0", 3)
    d.path("m108 31 18 7-1 11-20-2Z", "#dbb65a", width=3)
    d.spot(119, 43, 2, "#b76e51")
    d.eye(100, 31, 3)
    birds["seagull"] = d
    return birds


def _waders() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    d = Drawing(("folded S-shaped grey neck", "long straight yellow dagger bill", "black eyebrow plume and long trailing legs"))
    _feet(d, "M53 83 49 115l-14 7m14-7 11 6M76 83 81 108l14 11m-14-11-7 12", "#9c9d6d", 3.5)
    d.path("M27 69c-8-11-3-24 10-30 16-8 33-2 44 9l10 25-23 17-24-1Z", "#9daeb3")
    d.path("M80 54c14-5 15-16 6-24-12-10-8-24 5-28 12-4 24 4 22 15-1 7-8 10-13 12 15 17 12 36 0 48l-16 2-8-14Z", "#ccd1c3")
    d.detail("M93 9q10-5 16 2l-8 7-12 2-2-5Z", "#f0ead3")
    d.path("M91 8q-9-5-17-3l12 9 17 2 7-4Z", "#465e67", width=2.5)
    d.line("m85 10-15 8", "#465e67", 3)
    d.path("m111 13 15 6-18 6Z", "#d5b46a", width=2.5)
    _wing(d, "M29 43c15-8 35-1 46 17l-7 27-12-11-8 10-9-12-8 2Z", "#8299a6",
          ("M37 51q16 3 25 16M38 63l14 13M48 49l12 7",), "#b6c6c3")
    d.line("M90 40q11 13 3 27M94 46l4 5M43 42l16-4", "#6f8b93", 2.5)
    d.eye(104, 13, 2.2)
    birds["heron"] = d

    d = Drawing(("slender white body and sinuous long neck", "fine black bill and backward head plumes", "black legs ending in bright yellow feet"))
    _feet(d, "M51 82 48 111M74 82l6 26", "#4e6267", 3.2)
    _feet(d, "M48 111 34 120m14-9 11 10M80 108l15 12m-15-12-8 13", "#d7b95c", 3.3)
    d.path("M25 67c-6-13 4-25 19-29 16-4 34 4 42 17l4 20-26 15-24-3Z", "#eef0e4")
    d.path("M81 57c15-6 15-17 7-27-8-11-3-25 10-27 14-2 22 11 16 21l-9 6c8 15 9 32-2 45l-17 4-10-12Z", "#f8f5e6")
    d.path("M99 6Q85 1 74 7l18 6M99 10Q82 6 70 15l24 2", "none", "#dfe7dc", 2.5)
    _wing(d, "M29 43c14-7 32-1 44 15 2 14-5 23-13 31l-7-13-10 10-5-13-8 3Z", "#f8f6e8",
          ("M35 52q16 2 27 15M36 62l16 13M44 45l13 5",), "#b2c6c3")
    d.path("m112 13 14 5-18 7Z", "#4b6266", width=2.5)
    d.detail("M96 31q15 17 7 35l-7 8c6-21-9-27-7-40Z", "#d9e3da")
    d.eye(106, 13, 2.4)
    birds["egret"] = d

    d = Drawing(("long downward-curved bill", "pink-white wings and rosy feather edges", "red bare face and legs with shaggy nape crest"))
    _feet(d, "M52 82 48 112l-13 9m13-9 10 9M75 80 82 108l14 11m-14-11-6 13", "#b87566", 3.5)
    d.path("M25 66c-4-15 7-26 23-27 16-2 32 5 39 18l2 21-28 14-25-6Z", "#f0d7ca")
    d.path("M80 57c10-8 12-18 11-31-1-15 9-24 20-19 10 5 9 16 2 22l-6 6-1 31-13 15-14-10Z", "#f6e6d6")
    d.path("M97 10Q87 6 81 16l11 5-14 3 13 7-12 5 17 4 10-16Z", "#f0e4d1", width=2.5)
    d.detail("M101 12q13-3 16 8l-9 15-11-7Z", "#cc7c69")
    d.path("M113 21q16 6 12 27-2 7-7 10 4-17-6-26Z", "#766461", width=2.5)
    _wing(d, "M28 45c18-7 36 4 45 22-3 14-15 23-30 19-12-7-17-24-15-41Z", "#edc2b2",
          ("M36 53q16 2 25 16M35 64l18 14M43 77l12 7",), "#c78b7d")
    d.detail("M30 66q17 13 36 8l-10 13-17-2Z", "#dc9a90")
    d.eye(109, 18, 2.6)
    birds["ibis"] = d

    d = Drawing(("long straight red bill", "upright white neck", "broad black flight feathers and long red legs"))
    _feet(d, "M52 81 49 116l-14 6m14-6 12 6M76 80 81 111l14 10m-14-10-6 12", "#c57e66", 3.7)
    d.path("M27 65c-4-14 7-24 23-27 16-2 31 5 39 18l2 22-27 14-26-6Z", "#efeddf")
    d.path("M80 57 83 24c-2-13 6-23 18-21 14 2 20 15 13 25l-9 7-1 30-10 14-16-7Z", "#f5f1e1")
    _wing(d, "M29 43c18-6 36 5 44 23l4 15-13 10-9-12-8 10-7-13-9 2Z", "#4b626b",
          ("M36 52q17 5 27 18M35 66l16 11M44 77l6 7",), "#859da2")
    d.detail("M94 34q13 9 10 29l-7 9-5-18Z", "#d4ded5")
    d.path("m113 13 13 6-1 8-18-4Z", "#c17b61", width=2.5)
    d.eye(107, 13, 2.4)
    birds["stork"] = d

    d = Drawing(("long slim probing bill", "mottled brown back over white belly", "thin olive legs and low forward-leaning posture"))
    d.path("M29 71 6 87l28 2 16-15Z", "#8f8368")
    _feet(d, "M52 92 48 116l-15 6m15-6 11 6M78 91 84 112l14 7m-14-7-8 10", "#9a9d75", 3.2)
    d.path("M24 63c5-15 23-23 41-21 7-12 17-20 30-17 13 2 20 14 14 25 8 12 7 25-6 35-15 12-39 16-58 9-19-6-27-19-21-31Z", "#b7a587")
    d.detail("M51 72q28-18 54-5c4 17-21 31-47 29L36 83Z", "#f1e9d1")
    _wing(d, "M27 54c22-6 45 3 56 19-10 16-31 23-47 14-11-7-15-20-9-33Z", "#9e9072",
          ("M35 62 69 73M36 74l23 8M44 83l15 4",), "#6f7762")
    d.detail("M77 33q12-11 27-2l-1 6-24 2Z", "#ede3c6")
    d.path("m109 38 17 8-18 5-5-5Z", "#6d7567", width=2.5)
    d.line("m32 58 5 3m6-4 5 3m6 0 5 3m-16 4 4 3m13 0 5 3", "#dccdaa", 2)
    d.eye(100, 36, 2.8)
    birds["sandpiper"] = d

    d = Drawing(("bold black chest collar", "large round eye and short orange bill", "compact sandy back and white underside"))
    d.path("M31 79 8 94l26 3 16-15Z", "#988e73")
    _feet(d, "M52 96 49 116l-12 6m12-6 9 6M78 94 85 114l13 7m-13-7-7 8", "#c8a163", 3.2)
    d.path("M26 67c1-17 15-28 33-28 1-17 15-27 29-21 14 5 19 18 12 31 12 12 15 27 4 39-14 16-41 22-59 11-15-8-22-19-19-32Z", "#c0b18e")
    d.detail("M60 42q15-15 36-1l6 12-22 11-22-10Z", "#f6efd7")
    d.detail("M57 56q22 9 42-5l8 13c-18 15-42 16-57 5Z", "#485b5c")
    d.detail("M40 76q27 9 63-8 8 19-15 31-29 15-48-2Z", "#f1e9d1")
    _wing(d, "M29 56c17-4 34 4 42 18-10 18-24 23-35 12-9-9-12-18-7-30Z", "#a89d7c",
          ("M36 64 58 74M38 77l13 8",), "#797c64")
    d.detail("M60 27q15-14 31-5l6 10-19-5-15 8Z", "#5d6760")
    d.path("m101 32 21 8-21 6Z", "#c59b54", width=2.5)
    d.detail("m114 37 8 3-10 4Z", "#4b5a55")
    d.circle(88, 33, 5.6, "none", "#e8d4a0", 2, silhouette=False)
    d.eye(88, 33, 3.6)
    birds["plover"] = d
    return birds


def _groundbirds() -> dict[str, Drawing]:
    birds: dict[str, Drawing] = {}
    d = Drawing(("round compact body with tiny tail", "cream crown stripe and dark cheek bands", "streaked chestnut back and pale scalloped flanks"))
    d.path("M30 77 10 70l8 24 20-2Z", "#9b7955")
    _feet(d, "M51 101 46 117l-13 5m13-5 10 5M80 101 85 117l12 4", "#b99a68", 3.5)
    d.path("M20 70c2-19 19-31 40-31 4-16 17-25 31-21 15 4 23 18 17 31 11 16 8 36-8 49-15 13-43 17-62 5-15-9-20-21-18-33Z", "#b5936a")
    d.detail("M59 35q4-18 20-18l7 6-10 19-16 7Z", "#e3cba2")
    d.detail("M65 40q14-13 33-5l8 12-24 9-18-6Z", "#d8bc8e")
    d.detail("M70 26q15-7 27 3l3 8-18-5-13 4Z", "#7f6549")
    d.line("m78 45 23 1m-17 8 13 6", "#715943", 3.5)
    _wing(d, "M24 55c19-6 39 4 47 23-11 20-28 24-41 12-10-9-13-22-6-35Z", "#96714f",
          ("M31 63 59 76M31 76l20 11M40 88l10 3",), "#d4b385")
    d.path("m107 32 17 10-19 5Z", "#a98b5c", width=3)
    d.eye(95, 32, 3)
    d.line("M30 56 40 64m4-5 8 8m2-3 9 9M76 74l2 9m10-12 2 9m-20 7 2 8m13-7 1 8", "#ead3a7", 2.5)
    birds["quail"] = d

    d = Drawing(("very long barred pointed tail", "iridescent green head and red facial skin", "white neck ring with copper scaled body"))
    d.path("M59 74C37 75 15 96 3 121c23-11 47-24 65-38Z", "#ae8f5f")
    d.line("M15 111 24 112m0-12 13 4m-1-12 12 4m0-12 12 3", "#71664c", 4)
    _feet(d, "M65 96 59 114l-12 8m12-8 10 8M88 92l7 18 13 7m-13-7-5 11", "#b69b68", 3.5)
    d.path("M43 65c5-16 23-22 40-18 1-13 4-25 3-31-1-10 8-14 17-9 9 6 12 16 6 24l-6 7 6 25c7 15-2 30-18 37-19 9-42 1-49-14-3-7-4-14 1-21Z", "#bd8755")
    d.detail("M85 17c-1-11 10-15 19-9 10 7 11 16 4 25l-5 6 3 14-19 4-4-16Z", "#426f65")
    d.detail("M97 15q11-3 15 8l-4 12-13-4-4-8Z", "#c15e4d")
    d.detail("m84 44 21-2 3 8-23 5Z", "#f4ead1")
    _wing(d, "M47 56c19-5 34 6 39 22-10 19-25 23-36 10-8-10-10-20-3-32Z", "#b69d71",
          ("M54 63 75 77M53 76l15 11M58 87l8 3",), "#766c50")
    d.detail("M88 59q17 0 21 13-1 17-20 26l-9-15Z", "#b07548")
    d.line("m91 64 6 3-5 5m7 1 6 3-5 5m-10 2 6 3-5 5M62 53l4 4m9-3 4 4", "#754f3d", 2)
    d.path("m110 18 16 8-17 6Z", "#c6ae78", width=2.5)
    d.eye(103, 18, 2.7)
    birds["pheasant"] = d
    return birds


def build() -> dict[str, Drawing]:
    birds = {
        "sparrow": _sparrow(),
        "kingfisher": _kingfisher(),
        "crane": _crane(),
        "owl": _owl(),
    }
    birds.update(_songbirds())
    birds.update(_special_birds())
    birds.update(_corvids())
    birds.update(_raptors())
    birds.update(_other_owls())
    birds.update(_waterbirds())
    birds.update(_waders())
    birds.update(_groundbirds())
    return birds
