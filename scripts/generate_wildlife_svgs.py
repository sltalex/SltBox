"""Build 180 individual, replaceable cartoon wildlife SVGs.

The drawings deliberately use strong silhouettes and few internal details so
they remain recognizable in the compact tree widget. No raster/emoji fallback.
"""

from __future__ import annotations

import hashlib
from pathlib import Path

from build_wildlife_catalog import added_species


INK = "#26383b"


def full_body_rabbit(body: str, accent: str, a: int = 0, b: int = 0) -> tuple[str, str]:
    art = (f'<circle cx="20" cy="86" r="9" fill="{accent}"/>'
           f'<path d="M85 50 78 {10+a//3}q-1-14 9-12 8 1 9 13l3 25M99 48l5-{36+b//3}q3-13 12-10 8 3 4 15l-8 31" fill="{body}"/>'
           f'<ellipse cx="57" cy="83" rx="38" ry="27" fill="{body}"/>'
           f'<path d="M29 99q-11 13-4 17h29q7-4-2-13M79 99q-3 16 5 17h23q6-3-5-11" fill="{accent}"/>'
           f'<ellipse cx="96" cy="61" rx="20" ry="19" fill="{body}"/>'
           '<circle cx="103" cy="58" r="3.5" fill="#26383b" stroke="none"/>'
           '<path d="m114 66 12 4-12 5m-14 1q7 4 13-1" fill="none"/>')
    silhouette = ('<circle cx="20" cy="86" r="12"/><ellipse cx="57" cy="83" rx="41" ry="31"/>'
                  '<path d="M79 53 73 6Q72-9 86-8q12 2 13 17l2 21 2-24q2-14 14-11 13 3 9 18l-10 43Z"/>'
                  '<ellipse cx="98" cy="61" rx="23" ry="22"/>'
                  '<path d="M25 96v28h31V99m25-2v27h30l-6-22"/>')
    return art, silhouette


def full_body_fox(body: str, accent: str, a: int = 0, b: int = 0) -> tuple[str, str]:
    art = (f'<path d="M32 83C11 69 1 82 3 112c12-11 27-9 44-15" fill="{accent}"/>'
           f'<ellipse cx="56" cy="83" rx="38" ry="25" fill="{body}"/>'
           f'<path d="M29 99 26 121h17l7-16m22-3 2 19h17l-2-22" fill="{body}"/>'
           f'<path d="m76 53 4-29 16 14 14-16 8 32 9 12-14 19-31-4-14-17Z" fill="{body}"/>'
           '<path d="m84 72 28 8 15-14-7 23-20 5-20-10Z" fill="#f9e7ca"/>'
           '<circle cx="103" cy="57" r="3.5" fill="#26383b" stroke="none"/>'
           '<path d="m124 70 4 5-6 2" fill="#26383b"/>')
    silhouette = ('<path d="M33 77C8 60-4 80 0 124c14-15 31-13 48-19Z"/>'
                  '<ellipse cx="55" cy="83" rx="42" ry="29"/>'
                  '<path d="M22 95v33h26l8-21m11-11 1 32h28l-2-31M69 53l5-42 22 20 19-20 10 40 13 16-18 36H82L64 69Z"/>')
    return art, silhouette


def full_body_raccoon(body: str, accent: str, kind: str = "raccoon") -> tuple[str, str]:
    if kind == "panda":
        body, accent = "#f2f0e8", "#3d4a49"
    elif kind == "red-panda":
        body, accent = "#d88a50", "#684f43"
    elif kind == "koala":
        body, accent = "#9baaa7", "#5c7172"
    art = (f'<path d="M29 85C8 74 1 88 5 107c10-9 21-4 32-9" fill="{accent}"/>'
           f'<ellipse cx="57" cy="84" rx="37" ry="26" fill="{body}"/>'
           f'<path d="M29 102 26 120h18l7-16m24-1 3 17h19l-5-17" fill="{accent}"/>'
           f'<path d="m82 51-2-20 15 9 17-12 8 25" fill="{body}"/>'
           f'<ellipse cx="98" cy="62" rx="23" ry="20" fill="{body}"/>'
           f'<path d="M80 62q17-18 37-3-5 18-21 17-13 0-16-14Z" fill="{accent}"/>'
           '<circle cx="104" cy="61" r="4" fill="#fff" stroke="none"/>'
           '<path d="m116 73 12 3-12 6m-11-1 6 3 6-3" fill="none"/>')
    if kind == "panda":
        art += '<path d="M39 68q23-10 40 1" fill="none" stroke="#3d4a49" stroke-width="10"/>'
    if kind in {"raccoon", "red-panda"}:
        art += '<path d="M7 91 28 97" fill="none" stroke="#f2e5cc" stroke-width="5"/>'
    silhouette = ('<path d="M32 78C6 67-5 85 2 114l38-8Z"/><ellipse cx="57" cy="84" rx="41" ry="30"/>'
                  '<path d="M23 96v32h25l8-22m15-10 1 32h30l-6-27M76 53l-3-32 22 11 22-13 9 33Z"/>'
                  '<ellipse cx="99" cy="62" rx="27" ry="23"/>')
    return art, silhouette


def full_body_rodent(body: str, accent: str, bushy_tail: bool = False,
                     a: int = 0, b: int = 0) -> tuple[str, str]:
    tail = (f'<path d="M29 80C10 77 1 60 8 39c7-17 22-7 18 8-3 11-10 14-16 14 12 9 26 9 36 14" fill="{accent}"/>'
            if bushy_tail else
            f'<path d="M25 86C5 83 4 65 8 {48-b//3}" fill="none" stroke="{accent}" stroke-width="6"/>')
    art = (tail + f'<ellipse cx="57" cy="84" rx="{36+a//6}" ry="25" fill="{body}"/>'
           f'<path d="M30 98 27 119h17l7-15m22-4 4 19h18l-5-18" fill="{body}"/>'
           f'<circle cx="88" cy="47" r="{10+a//8}" fill="{accent}"/>'
           f'<circle cx="107" cy="45" r="{9+b//8}" fill="{accent}"/>'
           f'<ellipse cx="99" cy="65" rx="23" ry="20" fill="{body}"/>'
           '<circle cx="105" cy="61" r="3" fill="#26383b" stroke="none"/>'
           '<path d="m117 72 11 4-10 4m-7 4 9 3m-11-2 1 8" fill="none"/>')
    silhouette_tail = ('<path d="M31 79C5 72-3 47 5 32c14-21 37-4 25 20l-12 8 28 17Z"/>' if bushy_tail else
                       '<path d="M27 89C0 92-3 57 5 40l12 3C13 63 16 69 33 77Z"/>')
    silhouette = (silhouette_tail + '<ellipse cx="57" cy="84" rx="40" ry="29"/>'
                  '<path d="M23 96v32h26l7-23m13-9 1 32h30l-7-25"/>'
                  '<circle cx="87" cy="46" r="14"/><circle cx="108" cy="45" r="13"/>'
                  '<ellipse cx="100" cy="65" rx="26" ry="23"/>')
    return art, silhouette


def full_body_frog(body: str, accent: str, a: int = 0) -> tuple[str, str]:
    art = (f'<path d="M37 92Q17 90 10 113l30-5M75 95q9 19 32 18l-14-18" fill="{accent}"/>'
           f'<ellipse cx="57" cy="86" rx="{36+a//6}" ry="27" fill="{body}"/>'
           f'<path d="M78 90q12 18 22 25h20m-78-8-10 13H17" fill="none" stroke="{body}" stroke-width="8"/>'
           f'<ellipse cx="95" cy="62" rx="25" ry="21" fill="{body}"/>'
           f'<circle cx="81" cy="43" r="12" fill="{body}"/><circle cx="108" cy="42" r="12" fill="{body}"/>'
           '<circle cx="84" cy="42" r="6" fill="#fff"/><circle cx="110" cy="41" r="6" fill="#fff"/>'
           '<circle cx="86" cy="42" r="3" fill="#26383b" stroke="none"/>'
           '<circle cx="112" cy="41" r="3" fill="#26383b" stroke="none"/>'
           '<path d="m108 76 10 2m-12 5q7 4 13 0" fill="none"/>')
    silhouette = ('<path d="M34 78 0 115l40 3 36-9 36 19h16l-24-39Z"/>'
                  '<ellipse cx="57" cy="86" rx="41" ry="31"/>'
                  '<ellipse cx="96" cy="63" rx="28" ry="24"/>'
                  '<circle cx="81" cy="42" r="15"/><circle cx="109" cy="41" r="15"/>')
    return art, silhouette


ART = {
    "butterfly": (
        '<path d="M62 54C49 21 22 19 19 38c-2 11 7 18 24 23-23 0-31 12-24 26 8 16 30 10 43-16" fill="#f49aab"/>'
        '<path d="M66 54c13-33 40-35 43-16 2 11-7 18-24 23 23 0 31 12 24 26-8 16-30 10-43-16" fill="#f4b55f"/>'
        '<path d="M59 47c-4-13-6-19-13-23m23 23c4-13 6-19 13-23" fill="none"/>'
        '<path d="M64 47c-9 2-10 14-8 28 2 18 14 18 16 0 2-14 1-26-8-28Z" fill="#4e7378"/>'
        '<circle cx="35" cy="43" r="6" fill="#ffe8b8" stroke="none"/><circle cx="93" cy="43" r="6" fill="#ffe8b8" stroke="none"/>'
    , '<path d="M64 54C40 8 9 25 20 49c-24 9-18 39 3 45 17 5 31-11 41-23 10 12 24 28 41 23 21-6 27-36 3-45 11-24-20-41-44 5Z"/>'),
    "honeybee": (
        '<path d="M56 47C42 14 22 23 29 43c5 13 17 17 28 16m15-12c14-33 34-24 27-4-5 13-17 17-28 16" fill="#d8f3f1"/>'
        '<path d="M34 66c0-23 18-34 38-30 22 4 36 20 35 35-1 19-19 32-42 27-20-4-31-15-31-32Z" fill="#f8c64c"/>'
        '<path d="M59 39c-6 16-8 35-3 53m20-50c-6 18-6 34-2 55" fill="none" stroke-width="10"/>'
        '<path d="m102 78 15 6-15 5M38 55 27 48m13 29-13 7" fill="#d8e3e2"/>'
        '<circle cx="42" cy="62" r="3" fill="#26383b" stroke="none"/><path d="M39 36 32 25m20 11 2-14" fill="none"/>'
    , '<path d="M48 46C27 4 9 28 31 56c-20 35 5 53 39 50 33-2 52-23 37-45 18-29-6-51-31-15Z"/>'),
    "ladybug": (
        '<path d="M38 67 22 55m13 28-18 6m74-22 16-12M93 83l18 6" fill="none"/>'
        '<path d="M32 62c0-26 14-40 32-40s32 14 32 40c0 30-14 43-32 43S32 92 32 62Z" fill="#ef655c"/>'
        '<path d="M64 23v81" fill="none"/><path d="M48 25c3-15 10-20 16-20s13 5 16 20" fill="#344951"/>'
        '<circle cx="48" cy="51" r="7" fill="#26383b" stroke="none"/><circle cx="83" cy="55" r="7" fill="#26383b" stroke="none"/>'
        '<circle cx="47" cy="78" r="6" fill="#26383b" stroke="none"/><circle cx="81" cy="82" r="6" fill="#26383b" stroke="none"/>'
        '<path d="M55 8 48 1m18 7 7-7" fill="none"/>'
    , '<path d="M64 13C22 10 21 43 26 77c4 30 21 42 38 42s34-12 38-42c5-34 4-67-38-64Z"/>'),
    "swallow": (
        '<path d="m55 74-33 42 9-41m40 0 35 41-8-45" fill="#235c78"/>'
        '<path d="M60 59C41 32 19 27 3 37l37 28-15 21 38-8m5-19c19-27 41-32 57-22L88 65l15 21-38-8" fill="#28738b"/>'
        '<path d="M48 56c4-15 12-22 23-22s18 10 18 23c0 23-16 35-25 35-10 0-20-17-16-36Z" fill="#f2ede3"/>'
        '<path d="M50 44c8-21 27-23 38-8 7 9 6 19-1 26-4-12-17-19-37-18Z" fill="#255166"/>'
        '<path d="m87 46 20 7-19 7" fill="#e7a65e"/><circle cx="79" cy="43" r="3" fill="#fff" stroke="none"/>'
    , '<path d="M64 26C43 12 26 20 0 35l30 35-14 21 29-10-24 43 43-31 43 31-23-43 28 10-14-21 30-35C102 20 85 12 64 26Z"/>'),
    "dragonfly": (
        '<path d="M61 57C38 23 9 22 10 39c1 13 20 23 50 25-29 0-43 8-39 21 5 15 26 12 43-14m3-14c23-34 52-35 51-18-1 13-20 23-50 25 29 0 43 8 39 21-5 15-26 12-43-14" fill="#a8e2df"/>'
        '<path d="M64 27c-10 0-14 10-11 20 3 10 18 10 21 0 3-10-1-20-10-20Z" fill="#4c9da1"/>'
        '<path d="M64 51v59m-8-34h16m-15 14h14m-13 14h12" fill="none" stroke="#4c9da1" stroke-width="9"/>'
        '<circle cx="58" cy="30" r="3" fill="#fff" stroke="none"/><circle cx="70" cy="30" r="3" fill="#fff" stroke="none"/>'
    , '<path d="M64 24C45 11 25 10 7 31c-9 11-2 25 19 34-18 17-10 33 5 37 12 3 23-7 27-16l2 34h8l2-34c4 9 15 19 27 16 15-4 23-20 5-37 21-9 28-23 19-34C103 10 83 11 64 24Z"/>'),
    "firefly": (
        '<circle cx="76" cy="82" r="35" fill="#f8e38f" stroke="none" opacity=".38"/>'
        '<path d="M59 45C43 21 25 26 27 44c1 11 13 18 31 18m14-17c16-24 34-19 32-1-1 11-13 18-31 18" fill="#d6e5c7"/>'
        '<path d="M62 28c-11 0-18 14-18 34 0 24 11 39 27 40 17 1 27-14 27-38 0-23-11-36-36-36Z" fill="#6e8972"/>'
        '<path d="M48 71c5 21 16 31 28 30 13-1 20-12 21-30-14 7-33 9-49 0Z" fill="#ffeb8b"/>'
        '<path d="M52 39 45 26m24 4 6-16" fill="none"/><circle cx="62" cy="47" r="3" fill="#26383b" stroke="none"/>'
    , '<path d="M63 22C41 9 19 23 24 47c-13 31 2 64 33 67 35 4 54-25 47-58 12-26-16-49-41-34Z"/>'),
    "frog": full_body_frog("#75c982", "#6dbb75"),
    "cricket": (
        '<path d="M48 31C29 13 20 11 11 11m53 18C67 5 78 2 100 7" fill="none"/>'
        '<path d="M35 69 12 97l18-4 12-13m52-6 24 31-28-10-8-13" fill="none" stroke-width="8"/>'
        '<path d="M38 55c4-21 21-29 42-22 19 6 26 23 19 39-7 16-29 24-47 17-17-7-19-20-14-34Z" fill="#82ab6c"/>'
        '<path d="M55 45c17 1 29 9 39 25m-42 20-4 21m30-22 12 24" fill="none"/>'
        '<circle cx="48" cy="44" r="5" fill="#fff"/><circle cx="49" cy="44" r="2.5" fill="#26383b" stroke="none"/>'
    , '<path d="M39 26 5 5 29 47 7 108l32-12c13 15 40 17 60-3l27 20-20-50C105 34 83 20 65 25L77 0 57 23Z"/>'),
    "squirrel": full_body_rodent("#c97945", "#ce8650", bushy_tail=True),
    "hedgehog": (
        '<path d="M22 83 9 74l15-11-8-17 19 2 1-19 18 8 9-20 14 15 15-13 6 20 18-3-7 21 16 13-17 15-8 20H30Z" fill="#9b765c"/>'
        '<path d="M38 63c9-15 36-20 55-9 13 8 15 27 2 42-11 13-40 15-55 2-11-10-12-23-2-35Z" fill="#ddbd93"/>'
        '<circle cx="74" cy="69" r="4" fill="#26383b" stroke="none"/><path d="m95 77 15 7-15 5" fill="#424b4b"/>'
        '<circle cx="55" cy="79" r="6" fill="#e59d9b" stroke="none"/><path d="M36 105h20m26 0h19" fill="none"/>'
    , '<path d="M12 104 7 74l14-13-7-25 20 6 1-22 19 10 8-23 17 15 18-14 8 24 18-4-8 22 15 16-17 15-8 26Z"/>'),
    "bullfinch": (
        '<path d="m36 89-20 15 26-2" fill="#61757e"/>'
        '<path d="M36 54c4-23 23-37 46-30 21 6 30 31 21 52-10 23-37 32-56 19-15-10-16-24-11-41Z" fill="#87969a"/>'
        '<path d="M47 56c8-8 29-8 40 5 13 15 7 31-9 37-19 7-35-11-31-42Z" fill="#e76d64"/>'
        '<path d="M43 45c5-19 23-28 39-21 11 5 16 14 14 24-14-9-35-9-53-3Z" fill="#34454e"/>'
        '<path d="m92 48 20 6-18 8" fill="#343b3d"/><circle cx="79" cy="42" r="3" fill="#fff" stroke="none"/>'
        '<path d="M48 74 27 92m49 6-1 16m12-20 4 20" fill="none"/>'
    , '<path d="M43 31C18 46 16 69 28 84L7 108l39-5c12 15 43 16 58-8 10-16 8-28 2-37l19-8-23-16C82 8 58 14 43 31Z"/>'),
    "fox": full_body_fox("#e9914d", "#d97940"),
    "sparrow": (
        '<path d="m39 81-27 16 29-4" fill="#8b7463"/>'
        '<path d="M33 53c6-22 27-32 47-25 20 6 29 26 23 47-5 20-22 31-42 26-22-5-34-25-28-48Z" fill="#9f8066"/>'
        '<path d="M47 66c6-14 23-20 40-12-1 22-13 36-29 34-9-1-14-9-11-22Z" fill="#d6b38d"/>'
        '<path d="M43 44c11-16 32-21 46-9-17-1-29 6-38 16" fill="#5f514a"/>'
        '<path d="m101 55 18 9-20 6" fill="#e4a85f"/><circle cx="83" cy="48" r="3" fill="#26383b" stroke="none"/>'
        '<path d="M57 99v16m19-17 4 16" fill="none"/>'
    , '<path d="M34 32C14 46 17 68 27 80L5 103l38-4c20 22 55 14 68-15l17-19-25-17C95 20 57 10 34 32Z"/>'),
    "magpie": (
        '<path d="M50 81 21 119l39-23m8-14 10 39-2-41" fill="#315c68"/>'
        '<path d="M31 50c8-25 33-36 56-24 21 11 25 38 12 57-15 22-42 22-58 7-11-11-15-25-10-40Z" fill="#303f4a"/>'
        '<path d="M43 61c15-7 27-2 39 10 9 9 14 10 22 8-13 19-42 25-60 8-8-8-9-17-1-26Z" fill="#f1f2ed"/>'
        '<path d="M42 39c11-22 31-26 46-13 10 9 11 22 6 33-16-10-32-16-52-20Z" fill="#344454"/>'
        '<path d="m93 43 25 8-25 8" fill="#e1b36c"/><circle cx="78" cy="42" r="3" fill="#fff" stroke="none"/>'
    , '<path d="M39 19C16 31 15 61 29 81L8 128l48-30 22 30 7-34c28-13 33-35 23-52l20-4-24-13C86 8 57 8 39 19Z"/>'),
    "owl": (
        '<path d="M32 48 26 17l25 14 13-13 13 13 25-14-6 31" fill="#9c8066"/>'
        '<path d="M30 56c0-29 14-44 34-44s34 15 34 44v27c0 21-14 32-34 32S30 104 30 83Z" fill="#ae9174"/>'
        '<path d="M39 52c8-11 21-13 25-1 4-12 17-10 25 1-1 17-9 28-25 29-16-1-24-12-25-29Z" fill="#ead6ab"/>'
        '<circle cx="50" cy="56" r="9" fill="#fff"/><circle cx="78" cy="56" r="9" fill="#fff"/>'
        '<circle cx="50" cy="56" r="4" fill="#26383b" stroke="none"/><circle cx="78" cy="56" r="4" fill="#26383b" stroke="none"/>'
        '<path d="m64 65-7 9 7 5 7-5Z" fill="#e2a760"/><path d="M47 112v10m34-10v10" fill="none"/>'
    , '<path d="M22 8 50 25 64 12l14 13 28-17-7 38c12 30 9 72-35 78-44-6-47-48-35-78Z"/>'),
    "rabbit": full_body_rabbit("#eee6d8", "#fff9ed"),
}

ART.update({
    "mantis": (
        '<path d="M61 43 37 14m31 28 20-29" fill="none"/>'
        '<path d="M45 48c3-18 17-24 29-12 11 12 3 27-12 27-12 0-19-5-17-15Z" fill="#8cc76b"/>'
        '<path d="M58 59 47 98l19 15 16-17-14-38" fill="#6eae5a"/>'
        '<path d="m49 66-24 8-11-20 19 5m37 8 28 7 13-20-21 5M48 91 27 116m52-25 23 25" fill="none" stroke-width="7"/>'
        '<circle cx="53" cy="44" r="4" fill="#fff"/><circle cx="70" cy="44" r="4" fill="#fff"/>'
    , '<path d="M40 46 27 8l35 24L98 8 82 45l31 4-12 34-20-7 8 47-26-15-26 15 6-47-20 7-12-34Z"/>'),
    "cicada": (
        '<path d="M57 52C35 15 13 25 20 53c5 18 20 26 38 28m12-29c22-37 44-27 37 1-5 18-20 26-38 28" fill="#cfe9db"/>'
        '<path d="M48 36c9-13 25-14 34 0 9 15 6 39-1 58l-17 24-17-24c-7-19-10-43 1-58Z" fill="#83ad72"/>'
        '<path d="M49 69h31M52 84h25M57 99h14" fill="none" stroke="#52715f"/>'
        '<circle cx="45" cy="42" r="7" fill="#d97a63"/><circle cx="83" cy="42" r="7" fill="#d97a63"/>'
    , '<path d="M64 27C34 5 8 24 15 58c2 14 16 29 35 33l14 32 14-32c19-4 33-19 35-33 7-34-19-53-49-31Z"/>'),
    "moth": (
        '<path d="M60 47C43 17 12 21 18 49c-19 4-14 30 9 37 10 24 31 25 39-7m2-32c17-30 48-26 42 2 19 4 14 30-9 37-10 24-31 25-39-7" fill="#c7a985"/>'
        '<circle cx="36" cy="58" r="11" fill="#e8d0a3"/><circle cx="92" cy="58" r="11" fill="#e8d0a3"/>'
        '<path d="M57 41 42 15m29 26 16-26" fill="none" stroke-width="8"/>'
        '<path d="M64 43c-11 0-14 15-12 34 2 20 22 20 24 0 2-19-1-34-12-34Z" fill="#947c72"/>'
    , '<path d="M64 42C39 5 7 18 12 47-5 63 9 95 29 98c8 27 27 29 35-3 8 32 27 30 35 3 20-3 34-35 17-51 5-29-27-42-52-5Z"/>'),
    "grasshopper": (
        '<path d="M36 45 9 11m35 33L39 4M36 78 7 110m75-34 37 37-34-12M57 84l-17 34" fill="none" stroke-width="7"/>'
        '<path d="M29 58c9-24 41-30 69-14 16 9 14 28-6 38-26 13-57 3-63-24Z" fill="#8dc66d"/>'
        '<path d="M47 53c22-6 43 0 57 14M52 77l33 34 26 7" fill="none"/>'
        '<circle cx="36" cy="53" r="6" fill="#fff"/><circle cx="37" cy="53" r="3" fill="#26383b"/>'
    , '<path d="M27 41 2 3l41 24L45 0l18 34c34-6 57 11 54 35l-18 16 27 43-44-17-29 17-5-35-46 27 22-48Z"/>'),
    "robin": (
        '<path d="m37 82-29 17 31-5" fill="#856b58"/>'
        '<path d="M34 53c6-26 31-39 54-26 22 12 26 42 10 62-17 22-48 19-60-2-7-11-9-23-4-34Z" fill="#927767"/>'
        '<path d="M46 50c13-12 32-9 42 5 13 19 1 42-19 45-20 2-33-24-23-50Z" fill="#e58b59"/>'
        '<path d="m99 50 22 10-22 8" fill="#dda55e"/><circle cx="82" cy="43" r="4" fill="#26383b"/>'
        '<path d="M51 101v15m29-17 4 17" fill="none"/>'
    , '<path d="M38 28C15 40 17 68 28 83L2 104l39-5c22 27 63 16 71-24l16-16-27-15C89 13 57 12 38 28Z"/>'),
    "kingfisher": (
        '<path d="m38 84-19 29 32-20" fill="#19788d"/>'
        '<path d="M31 57c7-29 32-42 58-29 22 11 25 40 9 61-17 22-47 18-60-1-7-10-10-21-7-31Z" fill="#288fa1"/>'
        '<path d="M45 61c10-12 28-14 44-4 7 17-1 34-18 40-18 6-32-10-26-36Z" fill="#eaa25d"/>'
        '<path d="m99 48 28 7-28 7" fill="#3b4c4c"/><circle cx="81" cy="43" r="4" fill="#fff"/>'
        '<path d="M50 39c10-15 27-19 42-9" fill="none" stroke="#96d5d0"/>'
    , '<path d="M37 25C10 43 18 72 29 83L8 125l46-30c25 21 55 3 58-29l16-11-23-14C95 11 57 8 37 25Z"/>'),
    "woodpecker": (
        '<path d="M46 82 30 121l25-20m25-13 11 36-23-24" fill="#4a5155"/>'
        '<path d="M34 47c9-21 32-27 51-14 21 14 24 42 8 61-17 19-46 13-56-6-7-13-9-28-3-41Z" fill="#353f45"/>'
        '<path d="M44 59c10-8 24-8 34 2 8 12 4 26-9 32-16 7-28-12-25-34Z" fill="#f1eee3"/>'
        '<path d="m52 34 7-22 23 13-3 13" fill="#dc5d51"/><path d="m91 45 36 4-34 9" fill="#596063"/>'
        '<circle cx="78" cy="41" r="4" fill="#fff"/>'
    , '<path d="M50 13 59 2l30 25c16 3 18 16 16 28l23-8-22 22c3 33-8 48-27 51l18 8-29-8-41 8 12-37C19 70 22 31 50 13Z"/>'),
    "heron": (
        '<path d="M62 81 55 125m28-43 17 43M35 58 25 101" fill="none" stroke-width="6"/>'
        '<path d="M29 61c6-21 28-29 52-17 20 11 20 35 4 47-20 15-51 1-56-30Z" fill="#91a4a4"/>'
        '<path d="M75 51c19-24 7-37-5-38 17-4 31 8 29 24-1 16-7 27-18 34" fill="none" stroke="#91a4a4" stroke-width="12"/>'
        '<path d="m94 30 33 7-33 7" fill="#dfad69"/><circle cx="88" cy="25" r="3" fill="#26383b"/>'
        '<path d="M43 66c11-4 22 0 34 13" fill="none" stroke="#d8e0d8"/>'
    , '<path d="M29 40 68 36c-7-19 8-37 24-35 17 1 21 12 17 26l19 7-20 13c-4 15-8 26-17 33l17 48H44l7-36C25 86 14 66 29 40Z"/>'),
    "crow": (
        '<path d="m35 85-30 22 38-8m32-12 13 32-27-24" fill="#303943"/>'
        '<path d="M29 55c6-25 30-38 55-30 23 7 32 33 18 57-15 27-48 28-65 7-9-10-11-22-8-34Z" fill="#344049"/>'
        '<path d="M40 63c15-9 32-7 48 5-9 19-25 28-43 21-10-4-11-14-5-26Z" fill="#47535a"/>'
        '<path d="m100 50 27 9-27 8" fill="#303943"/><circle cx="84" cy="44" r="4" fill="#dce5de"/>'
    , '<path d="M37 24C16 36 16 66 29 84L0 111l43-9 26 26 10-30c32-8 39-36 25-49l24 8-23-22C88 14 58 8 37 24Z"/>'),
    "deer": (
        '<path d="M41 67 31 116m21-37-6 37m30-37 3 37m16-53 9 53" fill="none" stroke-width="8"/>'
        '<path d="M25 61c7-18 32-23 57-13 18 7 20 24 8 37-16 17-56 12-65-7-3-6-3-11 0-17Z" fill="#c68d62"/>'
        '<path d="M72 51c-2-20 8-31 21-29 18 3 21 27 4 35" fill="#c68d62"/>'
        '<path d="M90 23 80 5m11 18 12-18M78 12 69 3m35 10 9-10" fill="none" stroke-width="5"/>'
        '<path d="M81 36 72 24l15 2m15 9 11-11-1 16" fill="#d9a47a"/>'
        '<circle cx="100" cy="35" r="3" fill="#26383b"/><circle cx="28" cy="59" r="5" fill="#f4d8b6"/>'
    , '<path d="M65 10 61 0l25 18L100 0l17 18-9 17 20 9-19 22-4 58H24l-7-49 14-26 37-8Z"/>'),
    "raccoon": full_body_raccoon("#aeb7b0", "#5b6769"),
    "turtle": (
        '<path d="M32 78 13 91l20 9m56-22 19 13-20 9M45 94l-7 20m45-20 7 20" fill="#78ad75"/>'
        '<path d="M30 72c0-29 16-47 39-47 22 0 37 18 37 47 0 23-17 32-38 32S30 95 30 72Z" fill="#669b69"/>'
        '<path d="m68 28-19 22 10 21 26 1 10-22-27-22ZM49 50l-17 14m63-14 11 14M59 71l-10 29m36-28 10 28" fill="none" stroke="#a6c78c"/>'
        '<path d="M99 62c15-9 29 1 23 15-5 11-18 14-27 7" fill="#8dbd85"/>'
        '<circle cx="112" cy="69" r="3" fill="#26383b"/>'
    , '<path d="M26 37C42 7 83 8 104 38l24 16-9 38-22 12 7 24H28l6-24L0 91l19-35Z"/>'),
    "field-mouse": full_body_rodent("#afa096", "#aa867b"),
    "badger": (
        '<path d="M16 75c2-25 21-39 49-40 28-1 48 14 48 38 0 27-20 34-50 34S14 99 16 75Z" fill="#8a9290"/>'
        '<path d="M79 39c16-8 34-1 40 15l-8 22-33-11Z" fill="#d5d9cf"/>'
        '<path d="m85 45 14 31m12-23-2 22" fill="none" stroke="#424f53" stroke-width="10"/>'
        '<path d="M20 95 14 117m31-12-2 13m44-13 3 13m20-20 9 18" fill="none" stroke-width="8"/>'
        '<circle cx="104" cy="65" r="3" fill="#26383b"/>'
    , '<path d="M10 52C17 19 55 24 77 29c26-18 49 2 51 37l-15 36 10 26H7l5-30Z"/>'),
    "otter": (
        '<path d="M26 85C10 88 4 107 12 117c8-10 19-10 35-14" fill="#916946"/>'
        '<path d="M22 70c8-22 39-33 65-20 24 12 27 37 9 50-20 14-63 5-73-16-2-5-3-10-1-14Z" fill="#a7784d"/>'
        '<path d="M82 49c12-18 29-17 39-2 12 18 1 39-19 42" fill="#a7784d"/>'
        '<path d="M78 75c18 8 31 6 43-7" fill="none" stroke="#e3c39d" stroke-width="10"/>'
        '<path d="M41 97 30 118m46-14 4 15m12-13 14 12" fill="none"/>'
        '<circle cx="106" cy="54" r="4" fill="#26383b"/><path d="m116 70 8 1m-5 7 8 4" fill="none"/>'
    , '<path d="M14 57C25 37 57 38 75 43c9-21 31-31 47-11 14 18 5 43-13 58l19 38H21l-15-18Z"/>'),
    "snail": (
        '<path d="M79 77 92 29m6 48 17-48" fill="none" stroke="#81ac7c" stroke-width="8"/>'
        '<circle cx="92" cy="26" r="7" fill="#fff"/><circle cx="115" cy="26" r="7" fill="#fff"/>'
        '<circle cx="94" cy="26" r="3" fill="#26383b"/><circle cx="117" cy="26" r="3" fill="#26383b"/>'
        '<path d="M18 88c4-18 17-24 31-21 15 3 24 13 36 7 16-8 25-1 27 15 2 16-15 28-45 28H22C10 117 7 99 18 88Z" fill="#9cc18a"/>'
        '<path d="M20 75c-3-28 17-48 43-48 28 0 45 20 41 45-4 25-21 37-44 35-24-2-37-13-40-32Z" fill="#c99d71"/>'
        '<path d="M63 48c-17-8-29 4-27 20 2 15 20 22 33 12 9-7 7-19-1-23-7-3-13 1-12 8" fill="none" stroke="#8f654f" stroke-width="8"/>'
    , '<path d="M8 70C4 35 28 13 61 14c15 0 26 5 34 16L91 5l16 23L115 5l13 26-11 51 10 34H10Z"/>'),
})

def generated_art(species: dict) -> tuple[str, str]:
    """Small anatomy-led cartoons for the expanded catalogue, never text glyphs.

    A stable pair of geometry variations changes the silhouette as well as the
    palette. The early face-forward mammals and frog use the same full-body
    construction as their expanded families.
    """
    species_id, shape = species["id"], species["motion"]
    seed = 2166136261
    for character in species_id:
        seed = ((seed ^ ord(character)) * 16777619) & 0xffffffff
    a, b = seed % 17, (seed >> 5) % 13
    palette = ("#90b977", "#d6a27c", "#8ab9c2", "#e8bd70", "#ac9bbd", "#87afa3")
    body = palette[seed % len(palette)]
    accent = palette[(seed // 7 + 2) % len(palette)]

    if species["category"] == "飞鸟":
        return generated_bird(shape, a, b, body, accent)
    if species["category"] == "小动物":
        return generated_small_animal(species_id, shape, a, b, body, accent)

    if shape in {"butterfly", "moth"}:
        span, lower = 24 + a, 21 + b
        wings = (f'<path d="M62 59C{54-span} 14 12 17 15 43c2 13 20 20 43 21C21 65 9 86 27 98c15 10 28-3 35-25" fill="{body}"/>'
                 f'<path d="M66 59C{74+span} 14 116 17 113 43c-2 13-20 20-43 21 37 1 49 22 31 34-15 10-28-3-35-25" fill="{accent}"/>')
        details = (f'<path d="M59 50 45 {17+b//3}m24 33 14-{31+a//4}" fill="none"/>'
                   f'<ellipse cx="{35+a//3}" cy="47" rx="{5+b//4}" ry="7" fill="#fff1ca" stroke="none"/>'
                   f'<ellipse cx="{93-a//3}" cy="47" rx="{5+b//4}" ry="7" fill="#fff1ca" stroke="none"/>'
                   '<path d="M64 46c-10 4-10 31 0 42 10-11 10-38 0-42Z" fill="#4b6668"/>')
        silhouette = f'<path d="M64 51C{34-a} 7 0 22 13 49 0 69 13 {104+b} 42 107c12 1 18-11 22-24 4 13 10 25 22 24 29-3 42-38 29-58C128 22 {94+a} 7 64 51Z"/>'
        return wings + details, silhouette

    if shape in {"bee", "fly"}:
        long_wings = shape == "fly"
        wings = (f'<ellipse cx="45" cy="42" rx="{17+a//3}" ry="{26 if long_wings else 20}" transform="rotate(-36 45 42)" fill="#c9e4e5"/>'
                 f'<ellipse cx="83" cy="42" rx="{17+b//3}" ry="{26 if long_wings else 20}" transform="rotate(36 83 42)" fill="#c9e4e5"/>')
        abdomen = (f'<ellipse cx="64" cy="77" rx="{26+a//3}" ry="{27+b//3}" fill="{body}"/>'
                   f'<path d="M47 58c4 18 4 34 0 46m17-50c3 19 3 36 0 50m17-47c-3 18-3 32 0 43" fill="none" stroke="{accent}" stroke-width="{7 if not long_wings else 4}"/>')
        detail = ('<circle cx="53" cy="64" r="3" fill="#26383b" stroke="none"/>'
                  '<path d="M44 92 28 105m29-6-7 16m34-16 7 16m-2-23 16 13" fill="none"/>')
        silhouette = f'<path d="M64 31C{26-a//2} 5 9 34 30 57 5 84 24 120 64 118c40 2 59-34 34-61 21-23 4-52-{34+b//2}-26Z"/>'
        return wings + abdomen + detail, silhouette

    if shape in {"beetle", "waterbug"}:
        horn = (f'<path d="M64 38 56 {8+b}m8 30 12-{29+a}" fill="none" stroke-width="5"/>'
                if species_id in {"stag-beetle", "rhinoceros-beetle", "longhorn-beetle"} else
                '<path d="M55 40 45 26m28 14 10-14" fill="none"/>')
        art = (f'<path d="M34 65 13 48m20 35-22 8m83-26 21-17M95 83l22 8" fill="none"/>'
               f'<ellipse cx="64" cy="72" rx="{30+a//3}" ry="{38+b//3}" fill="{body}"/>'
               f'<path d="M64 37v73" fill="none"/><path d="M44 51c10-13 30-13 40 0" fill="none" stroke="{accent}" stroke-width="8"/>'
               f'<circle cx="{43+a//4}" cy="72" r="5" fill="{accent}" stroke="none"/>'
               f'<circle cx="{85-b//4}" cy="82" r="5" fill="{accent}" stroke="none"/>' + horn)
        silhouette = f'<path d="M64 {22-b//3}C{25-a//3} 16 22 46 27 77L7 91l26 16c17 22 45 22 62 0l26-16-20-14c5-31 2-61-37-{55+a//3}Z"/>'
        return art, silhouette

    if shape in {"dragonfly", "waterbug"}:
        art = (f'<path d="M63 61C{21-a} 14 0 24 17 47l40 19C8 64 8 88 32 91l31-21M65 61C{107+a} 14 128 24 111 47L71 66c49-2 49 22 25 25L65 70" fill="{body}"/>'
               f'<circle cx="64" cy="37" r="{11+b//5}" fill="{accent}"/>'
               f'<path d="M64 50v62m-8-36h16m-13 13h10m-9 13h8" fill="none" stroke="{accent}" stroke-width="7"/>')
        silhouette = f'<path d="M64 20C{40-a} 5 8 21 7 42c-2 11 11 20 33 25C13 70 11 96 32 104l26-19 3 39h6l3-39 26 19c21-8 19-34-8-37 22-5 35-14 33-25C120 21 {88+a} 5 64 20Z"/>'
        return art, silhouette

    if shape in {"mantis", "stick", "grasshopper", "ant"}:
        if shape == "ant":
            art = (f'<circle cx="40" cy="71" r="{15+a//5}" fill="{body}"/>'
                   f'<circle cx="68" cy="68" r="{14+b//5}" fill="{accent}"/>'
                   f'<circle cx="96" cy="64" r="12" fill="{body}"/>'
                   '<path d="M32 85 15 108m42-24-8 30m21-31 8 31m24-38 17 22M99 52l-4-25m10 26 9-21" fill="none"/>')
            silhouette = f'<path d="M29 48C7 47 4 78 21 91L2 128h126l-18-41c24-27-10-59-28-33-9-12-22-14-34-3-5-4-11-5-19-3Z"/>'
        else:
            art = (f'<path d="M61 29 46 5m21 23 21-22M45 74 15 51m31 39-25 29m58-46 31-23M83 91l23 28" fill="none" stroke-width="6"/>'
                   f'<path d="M48 50c6-19 22-22 32-4l-7 17 14 34-21 19-22-20 13-33Z" fill="{body}"/>'
                   f'<ellipse cx="64" cy="42" rx="{13+a//4}" ry="{11+b//4}" fill="{accent}"/>'
                   '<circle cx="58" cy="40" r="3" fill="#fff" stroke="none"/>')
            silhouette = f'<path d="M40 35 20 0l34 29C68 {10+a} 78 19 83 30L111 2l-15 40 32 7-35 37 27 42H9l29-42L0 49Z"/>'
        return art, silhouette

    raise ValueError(f"No drawing family for {species_id}: {shape}")


def generated_bird(shape: str, a: int, b: int, body: str, accent: str) -> tuple[str, str]:
    eye = '<circle cx="96" cy="50" r="3" fill="#26383b" stroke="none"/>'
    if shape == "owl":
        art = (f'<path d="M31 46 23 16l24 16 17-14 17 14 24-16-8 30" fill="{body}"/>'
               f'<ellipse cx="64" cy="70" rx="{37+a//4}" ry="47" fill="{body}"/>'
               f'<path d="M35 61c5-16 22-18 29-4 7-14 24-12 29 4-3 25-15 37-29 37S38 86 35 61Z" fill="{accent}"/>'
               '<circle cx="49" cy="63" r="12" fill="#f6f3df"/><circle cx="79" cy="63" r="12" fill="#f6f3df"/>'
               '<circle cx="49" cy="63" r="5" fill="#26383b"/><circle cx="79" cy="63" r="5" fill="#26383b"/>'
               '<path d="m64 75-8 9 8 6 8-6Z" fill="#dfb06c"/><path d="M48 113v11m32-11v11" fill="none"/>')
        silhouette = f'<path d="M17 7 47 25 64 {12+a//4} 81 25l30-18-8 40c16 43 1 81-39 81S9 90 25 47Z"/>'
        return art, silhouette
    if shape == "wader":
        art = (f'<path d="M47 75 40 125m29-43 9 43" fill="none" stroke-width="6"/>'
               f'<ellipse cx="50" cy="72" rx="{28+a//5}" ry="22" fill="{body}"/>'
               f'<path d="M68 74c10-13 13-31 9-45-5-20 20-22 23-9 5 12-4 20-14 22-1 18-4 31-15 43" fill="none" stroke="{body}" stroke-width="12"/>'
               f'<path d="m96 28 {27+b//3} 7-{27+b//3} 7" fill="{accent}"/>'
               '<circle cx="91" cy="25" r="3" fill="#26383b" stroke="none"/>')
        silhouette = f'<path d="M73 9C90-7 110 3 106 23l22 7-20 16c-6 18-14 32-26 42l10 40H34l-7-38C2 64 22 43 60 47 63 26 61 18 73 9Z"/>'
        return art, silhouette
    if shape == "duck":
        art = (f'<path d="M22 74c1-27 30-37 56-27 25 10 27 40 3 53-27 15-58 4-59-26Z" fill="{body}"/>'
               f'<path d="M35 72c15-12 34-7 43 10-11 15-32 18-43 4Z" fill="{accent}"/>'
               f'<circle cx="89" cy="47" r="{15+b//5}" fill="{body}"/>'
               '<path d="m102 47 25 6-25 8" fill="#e7ad60"/><path d="M46 102v16m23-16v16" fill="none"/>'
               '<circle cx="93" cy="42" r="3" fill="#26383b" stroke="none"/>')
        silhouette = f'<path d="M79 30C45 19 13 38 15 72 5 100 34 122 73 112c27-7 37-25 34-43l21-16-23-16C99 27 90 27 79 30Z"/>'
        return art, silhouette
    if shape == "raptor":
        art = (f'<path d="M48 69 4 {29+b}l13 55 33 1m30-17 44-{40+a//3}-10 54-34-3" fill="{accent}"/>'
               f'<ellipse cx="64" cy="73" rx="{24+a//6}" ry="32" fill="{body}"/>'
               f'<circle cx="77" cy="44" r="18" fill="{body}"/>'
               '<path d="m94 43 24 8-15 14-8-5 9-8" fill="#e8b65e"/>'
               '<circle cx="83" cy="41" r="4" fill="#26383b" stroke="none"/>'
               '<path d="M50 101 38 125m34-24 10 24" fill="none"/>')
        silhouette = f'<path d="M60 22C39 5 15 22 0 {28+b}l18 69 31-7-8 38h53l-9-39 27 8 16-69c-30-11-44-13-68-6Z"/>'
        return art, silhouette
    if shape == "nectar":
        art = (f'<path d="M49 68 10 {23+b}l12 44 35 9m19-9 35-{39+a//3}-6 43-33 9" fill="{accent}"/>'
               f'<ellipse cx="66" cy="77" rx="{17+a//7}" ry="27" fill="{body}"/>'
               f'<circle cx="78" cy="50" r="15" fill="{body}"/>'
               '<path d="m90 46 38-11-36 19" fill="#d9a764"/><circle cx="82" cy="47" r="3" fill="#26383b" stroke="none"/>'
               '<path d="M61 99 44 121m28-23 14 22" fill="none"/>')
        silhouette = f'<path d="M67 24C46 12 26 8 0 {25+b}l24 58 27-3-17 48h71L82 81l25 2 21-58c-23-15-43-18-61-1Z"/>'
        return art, silhouette
    if shape == "groundbird":
        art = (f'<ellipse cx="56" cy="79" rx="{38+a//5}" ry="32" fill="{body}"/>'
               f'<path d="M41 62 20 37l25 7m27 16 12-29 12 18" fill="{accent}"/>'
               f'<circle cx="89" cy="52" r="17" fill="{body}"/>'
               '<path d="m102 55 18 7-18 7" fill="#dfac62"/><circle cx="94" cy="49" r="3" fill="#26383b" stroke="none"/>'
               '<path d="M45 103 39 124m37-21 10 21" fill="none"/>')
        silhouette = f'<path d="M25 28 48 44l27-{22+b//2} 8 24c28-8 45 12 45 39 0 22-12 35-29 41l8 18H21l9-20C-2 75 7 47 25 28Z"/>'
        return art, silhouette
    # Perching birds: tail length, head crest and beak shape vary by family.
    long_tail = shape == "longtail"
    crest = shape == "crested"
    dark = shape == "corvid"
    art = (f'<path d="M38 82 6 {117 if long_tail else 98+b//2}l42-8" fill="{accent}"/>'
           f'<ellipse cx="58" cy="72" rx="{31+a//5}" ry="{29+b//6}" fill="{body}"/>'
           f'<path d="M39 65c13-10 28-5 41 10-8 17-24 22-37 12-8-6-10-15-4-22Z" fill="{accent}"/>'
           f'<circle cx="86" cy="47" r="{16+b//5}" fill="{body}"/>'
           f'<path d="m98 47 {23 if dark else 16+b//3} 8-{23 if dark else 16+b//3} 7" fill="#d9a764"/>'
           '<circle cx="89" cy="44" r="3" fill="#26383b" stroke="none"/>'
           '<path d="M53 98v23m23-24 4 24" fill="none"/>')
    if crest:
        art += f'<path d="m73 35-7-18 14 8 6-20 7 28" fill="{accent}"/>'
    silhouette = (f'<path d="M73 {17 if crest else 27}C43 {14+a//4} 23 30 22 59L0 {121 if long_tail else 104+b//3}l45-12-4 36h53l-9-35 43-14-25-24C105 31 92 20 73 {17 if crest else 27}Z"/>')
    return art, silhouette


def generated_small_animal(species_id: str, shape: str, a: int, b: int, body: str, accent: str) -> tuple[str, str]:
    if shape == "rabbit":
        return full_body_rabbit(body, accent, a, b)
    if shape == "bat":
        art = (f'<path d="M64 57 6 {26+a}l10 45 17-10 13 19 18-10 18 10 13-19 17 10 10-45Z" fill="{body}"/>'
               f'<path d="m51 51-4-24 16 12 17-12-4 24" fill="{accent}"/>'
               '<circle cx="58" cy="55" r="3" fill="#fff"/><circle cx="70" cy="55" r="3" fill="#fff"/>')
        silhouette = f'<path d="M64 34 43 17l-1 29L0 {24+a}l14 65 21-15 29 25 29-25 21 15 14-65-42 22-1-29Z"/>'
        return art, silhouette
    if shape == "deer":
        art = (f'<path d="M35 81 28 123m24-40-3 40m33-39 5 39m17-51 7 51" fill="none" stroke-width="7"/>'
               f'<ellipse cx="61" cy="73" rx="{37+a//6}" ry="23" fill="{body}"/>'
               f'<path d="M87 69 83 35c-1-17 25-21 29-5l-4 32" fill="{body}"/>'
               f'<path d="M97 35 83 8m15 26 14-27M85 17 72 8m37 9 13-8" fill="none" stroke="{accent}" stroke-width="5"/>'
               '<circle cx="105" cy="41" r="3" fill="#26383b" stroke="none"/>')
        silhouette = f'<path d="M78 24 68 0l20 13 12-13 12 14 16-14-8 29 8 26-16 22 13 51H23l4-43C-1 50 21 36 75 46Z"/>'
        return art, silhouette
    if shape == "hedgehog":
        art = (f'<path d="m20 78-13-13 17-9-8-17 20 2 4-21 18 9 12-21 13 20 19-11 2 21 18 3-7 18 14 11-13 28H28Z" fill="{accent}"/>'
               f'<ellipse cx="69" cy="79" rx="{35+a//6}" ry="25" fill="{body}"/>'
               '<circle cx="85" cy="69" r="3" fill="#26383b"/><path d="m104 78 17 7-17 7" fill="#3f4a4a"/>')
        silhouette = f'<path d="M20 78 2 63l20-10-9-19 22 1 5-24 20 10L73 0l14 20 22-10 3 23 16 4-7 20 9 11-13 45H20Z"/>'
        return art, silhouette
    if shape == "turtle":
        art = (f'<path d="M31 87 10 99l23 7m55-19 22 12-23 7M47 98l-9 21m42-21 9 21" fill="{body}"/>'
               f'<path d="M30 77c0-34 17-53 39-53s39 19 39 53c0 23-17 29-39 29S30 100 30 77Z" fill="{accent}"/>'
               f'<path d="m69 27-20 24 12 22 24 1 12-23-28-24ZM49 51 30 68m67-17 11 17M61 73l-12 30m36-29 10 29" fill="none" stroke="{body}"/>'
               '<circle cx="111" cy="71" r="14" fill="' + body + '"/><circle cx="115" cy="68" r="3" fill="#26383b"/>')
        silhouette = f'<path d="M30 32C49 {3+a//3} 92 5 108 32l20 29-10 47-17 7 3 13H23l3-13-22-6-4-47Z"/>'
        return art, silhouette
    if shape == "frog":
        return full_body_frog(body, accent, a)
    if shape == "snail":
        art = (f'<path d="M89 74 94 29m13 43 11-44" fill="none" stroke="{body}" stroke-width="8"/>'
               '<circle cx="95" cy="26" r="7" fill="#fff"/><circle cx="117" cy="25" r="7" fill="#fff"/>'
               f'<path d="M11 90c9-20 24-19 37-12 14 8 32 8 50-4 23-8 34 20 20 35-11 12-85 14-102 2-7-5-9-12-5-21Z" fill="{body}"/>'
               f'<circle cx="52" cy="69" r="{33+a//7}" fill="{accent}"/>'
               '<path d="M50 52c-22 0-24 34-2 36 17 2 22-18 7-22-9-2-13 7-7 11" fill="none"/>')
        silhouette = f'<path d="M12 79C0 43 29 13 63 25l24 26 3-48 17 46 9-46 12 49-2 65H5Z"/>'
        return art, silhouette
    if shape == "snake":
        art = (f'<path d="M10 93c14-42 38 19 60-23 10-20 29-24 44-12 18 15-4 36-21 22-8-6-17-5-25 5-22 28-54 12-58 8Z" fill="{body}"/>'
               f'<path d="M18 93c15-18 30 16 54-11 20-22 30-14 37-18" fill="none" stroke="{accent}" stroke-width="8"/>'
               '<circle cx="109" cy="56" r="3" fill="#26383b"/><path d="m119 69 9 7-11 2" fill="none"/>')
        silhouette = f'<path d="M1 88C9 {35+a} 39 74 56 70c22-6 28-48 56-31 20 12 19 45-2 53-13 5-25-5-34 8-26 38-69 27-75-12Z"/>'
        return art, silhouette
    if shape == "lizard":
        art = (f'<path d="M43 78Q19 75 2 {97+b}m28-34-16-16m24 39-18 19m69-38 20-21m-17 43 17 18" fill="none" stroke="{body}" stroke-width="8"/>'
               f'<ellipse cx="65" cy="76" rx="{29+a//5}" ry="19" fill="{body}"/>'
               f'<path d="M84 68c18-13 33-8 39 8-6 17-23 18-39 6" fill="{accent}"/>'
               '<circle cx="109" cy="70" r="3" fill="#26383b" stroke="none"/>')
        silhouette = f'<path d="M35 48 1 29l27 38L0 {114+b//2}l44-24c17 11 35 10 48-1l36 27-23-38 23-26-38 15C74 53 55 50 35 48Z"/>'
        return art, silhouette
    if shape == "beaver":
        art = (f'<path d="M25 80 1 64 8 103l27-2" fill="{accent}"/>'
               f'<ellipse cx="62" cy="79" rx="{38+a//5}" ry="29" fill="{body}"/>'
               f'<circle cx="96" cy="65" r="19" fill="{body}"/>'
               '<path d="M98 79v13h11V79" fill="#fff4dc"/><circle cx="102" cy="59" r="3" fill="#26383b"/>'
               '<path d="M43 102 39 119m35-17 5 17" fill="none"/>')
        silhouette = f'<path d="M34 44C10 31 0 47 3 84l-3 26 38 4c39 20 77-3 76-28 23-27-16-61-36-37-17-8-31-8-44-5Z"/>'
        return art, silhouette
    if shape == "mustelid":
        art = (f'<path d="M29 78 2 91l28 6" fill="{accent}"/>'
               f'<path d="M23 72c9-20 43-26 73-15 20 8 23 28 5 39-22 15-69 12-78-9-2-5-2-10 0-15Z" fill="{body}"/>'
               '<path d="M45 97 38 120m49-21 7 21" fill="none"/><circle cx="103" cy="63" r="3" fill="#26383b"/>')
        silhouette = f'<path d="M14 54C35 {33+a//4} 70 40 93 47c33-15 45 25 25 52l9 29H2l12-25L0 88Z"/>'
        return art, silhouette
    if shape == "fox":
        return full_body_fox(body, accent, a, b)
    if shape == "raccoon":
        return full_body_raccoon(body, accent, species_id)
    # Squirrels keep a bushy tail; other rodents retain a thin curved tail.
    bushy = species_id in {"chipmunk", "flying-squirrel", "red-squirrel"}
    return full_body_rodent(body, accent, bushy_tail=bushy, a=a, b=b)

def render(source: str, silhouette: str | None = None, species: str = "") -> str:
    head = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
    if silhouette is None:
        return (head + f'<g stroke="{INK}" stroke-width="5" stroke-linecap="round" '
                f'stroke-linejoin="round">{source}</g></svg>')
    # WinUI's SVG renderer does not consistently honor feGaussianBlur. Several
    # translucent offset outlines give a soft edge without filter support.
    offsets = ((-5, 0), (5, 0), (0, -5), (0, 5), (-3, -3), (3, -3), (-3, 3), (3, 3))
    haze = ''.join(f'<g opacity=".065" transform="translate({x} {y})">{silhouette}</g>'
                   for x, y in offsets)
    variation = hashlib.sha256(species.encode("utf-8")).digest()
    angle = (int.from_bytes(variation[:2], "big") % 901 - 450) / 100
    scale_x = .94 + (variation[2] % 13) / 100
    scale_y = .94 + (variation[3] % 13) / 100
    transform = f'translate(64 64) rotate({angle:.2f}) scale({scale_x:.2f} {scale_y:.2f}) translate(-64 -64)'
    return head + f'<g fill="#879098" stroke="none" transform="{transform}">' + haze + \
        '<g opacity=".35">' + silhouette + '</g></g></svg>'


def main() -> None:
    for species in added_species():
        ART[species["id"]] = generated_art(species)
    target = Path(__file__).resolve().parents[1] / "src" / "DeskBox" / "Assets" / "Tree" / "Wildlife"
    for folder in (target / "Source", target / "Blur"):
        folder.mkdir(parents=True, exist_ok=True)
    for species, (source, silhouette) in ART.items():
        (target / "Source" / f"{species}.svg").write_text(render(source), encoding="utf-8")
        (target / "Blur" / f"{species}.svg").write_text(render(source, silhouette, species), encoding="utf-8")
    print(f"Generated {len(ART)} individual animal SVGs and {len(ART)} blurred silhouettes.")


if __name__ == "__main__":
    main()
