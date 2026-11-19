"""Small SVG drawing primitives shared by the three illustration collections."""

from __future__ import annotations

from dataclasses import dataclass, field
from html import escape

INK = "#344044"
CREAM = "#fff4dc"
SILHOUETTE = "#8c8c8c"


def number(value: float) -> str:
    return f"{value:g}"


@dataclass
class Drawing:
    features: tuple[str, ...]
    parts: list[str] = field(default_factory=list)
    silhouettes: list[str] = field(default_factory=list)

    def add(self, markup: str, silhouette: str | None = None) -> None:
        self.parts.append(markup)
        if silhouette:
            self.silhouettes.append(silhouette)

    def path(self, data: str, fill: str = "none", stroke: str = INK,
             width: float = 4, silhouette: bool = True, opacity: float = 1) -> None:
        geometry = f'<path d="{escape(data, quote=True)}"'
        paint = f' fill="{fill}" stroke="{stroke}" stroke-width="{number(width)}"'
        alpha = f' opacity="{number(opacity)}"' if opacity != 1 else ""
        self.parts.append(geometry + paint + alpha + '/>')
        if silhouette:
            self.silhouettes.append(geometry +
                f' fill="{"none" if fill == "none" else SILHOUETTE}"'
                f' stroke="{"none" if stroke == "none" else SILHOUETTE}"'
                f' stroke-width="{number(width)}"/>')

    def ellipse(self, x: float, y: float, rx: float, ry: float, fill: str,
                stroke: str = INK, width: float = 4, silhouette: bool = True,
                opacity: float = 1) -> None:
        geometry = f'<ellipse cx="{number(x)}" cy="{number(y)}" rx="{number(rx)}" ry="{number(ry)}"'
        alpha = f' opacity="{number(opacity)}"' if opacity != 1 else ""
        self.parts.append(geometry + f' fill="{fill}" stroke="{stroke}" stroke-width="{number(width)}"' + alpha + '/>')
        if silhouette:
            self.silhouettes.append(geometry +
                f' fill="{"none" if fill == "none" else SILHOUETTE}"'
                f' stroke="{"none" if stroke == "none" else SILHOUETTE}"'
                f' stroke-width="{number(width)}"/>')

    def circle(self, x: float, y: float, radius: float, fill: str,
               stroke: str = INK, width: float = 4, silhouette: bool = True,
               opacity: float = 1) -> None:
        self.ellipse(x, y, radius, radius, fill, stroke, width, silhouette, opacity)

    def line(self, data: str, stroke: str = INK, width: float = 2,
             silhouette: bool = False, opacity: float = 1) -> None:
        self.path(data, "none", stroke, width, silhouette, opacity)

    def detail(self, data: str, fill: str, stroke: str = "none", width: float = 2,
               opacity: float = 1) -> None:
        self.path(data, fill, stroke, width, False, opacity)

    def eye(self, x: float, y: float, radius: float = 3.3, bright: bool = False) -> None:
        if bright:
            self.circle(x, y, radius + 1.8, CREAM, "none", silhouette=False)
        self.circle(x, y, radius, INK, "none", silhouette=False)
        self.circle(x - radius * .28, y - radius * .3, max(.7, radius * .3),
                    "#ffffff", "none", silhouette=False)

    def spot(self, x: float, y: float, radius: float, fill: str) -> None:
        self.circle(x, y, radius, fill, "none", silhouette=False)


def render(drawing: Drawing, *, blurred: bool = False) -> str:
    head = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
    common = 'stroke-linecap="round" stroke-linejoin="round"'
    if not blurred:
        return head + f'<g {common} transform="translate(10 10) scale(.84375)">' + ''.join(drawing.parts) + '</g></svg>\n'
    outline = ''.join(drawing.silhouettes)
    offsets = ((-3, 0), (3, 0), (0, -3), (0, 3), (-2, -2), (2, -2), (-2, 2), (2, 2))
    haze = ''.join(f'<g opacity=".065" transform="translate({x} {y})">{outline}</g>'
                   for x, y in offsets)
    return head + f'<g {common} transform="translate(12 12) scale(.8125)">' + haze + \
        '<g opacity=".35">' + outline + '</g></g></svg>\n'
