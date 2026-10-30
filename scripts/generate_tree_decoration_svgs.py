from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "src" / "DeskBox" / "Assets" / "Tree" / "Decorations"
SOURCE = ROOT / "Source"
BLUR = ROOT / "Blur"


def svg(body: str, width: int = 120, height: int = 120) -> str:
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}">
  <defs><filter id="shadow"><feDropShadow dx="0" dy="3" stdDeviation="2" flood-opacity=".22"/></filter></defs>
  <g filter="url(#shadow)" stroke-linejoin="round" stroke-linecap="round">{body}</g>
</svg>\n'''


def theme(body: str) -> str:
    return svg(body, 260, 220)


ITEMS = [
    ("red-lantern", "红灯笼", "tree", "common", svg('''<path d="M42 22h36l8 15-8 48H42l-8-48z" fill="#ef4444" stroke="#9f1239" stroke-width="4"/><path d="M47 22q13-17 26 0M45 44h30M60 85v18m-10 0h20" fill="none" stroke="#fbbf24" stroke-width="5"/>''')),
    ("wishing-ribbons", "祈愿带", "tree", "common", svg('''<path d="M31 18q28 17 58 2" fill="none" stroke="#7c3aed" stroke-width="5"/><path d="M42 27l-9 69 18-15 10 18 5-70m10-1 8 67 12-18 13 10-20-63" fill="#f472b6" stroke="#be185d" stroke-width="3"/>''')),
    ("wind-chime", "风铃", "tree", "common", svg('''<path d="M33 34h54L73 58H47z" fill="#38bdf8" stroke="#0369a1" stroke-width="4"/><path d="M60 16v18m-13 25-5 24m31-24 5 24M60 58v38" stroke="#64748b" stroke-width="4"/><circle cx="42" cy="88" r="7" fill="#fbbf24"/><circle cx="78" cy="88" r="7" fill="#fbbf24"/><path d="M49 98h22l-11 14z" fill="#fb7185"/>''')),
    ("birdhouse", "鸟屋", "tree", "common", svg('''<path d="M25 53l35-32 35 32v49H25z" fill="#f59e0b" stroke="#92400e" stroke-width="4"/><path d="M18 57l42-39 42 39" fill="none" stroke="#7c2d12" stroke-width="8"/><circle cx="60" cy="65" r="14" fill="#422006"/><path d="M47 91h26" stroke="#7c2d12" stroke-width="6"/>''')),
    ("mushroom-cluster", "蘑菇簇", "ground", "common", svg('''<path d="M27 96h18V61H27zm43 5h19V55H70z" fill="#fef3c7" stroke="#92400e" stroke-width="3"/><path d="M12 64q24-45 48 0z" fill="#ef4444" stroke="#991b1b" stroke-width="4"/><path d="M52 57q28-50 56 0z" fill="#fb7185" stroke="#9f1239" stroke-width="4"/><g fill="#fff"><circle cx="31" cy="50" r="4"/><circle cx="44" cy="57" r="3"/><circle cx="78" cy="40" r="5"/><circle cx="93" cy="51" r="4"/></g>''')),
    ("wildflower-bed", "野花圃", "ground", "common", svg('''<path d="M12 101q48-24 96 0" fill="#65a30d" stroke="#3f6212" stroke-width="6"/><g stroke="#4d7c0f" stroke-width="3"><path d="M29 91V48m29 47V34m30 59V51"/></g><g fill="#f472b6" stroke="#be185d" stroke-width="2"><circle cx="29" cy="42" r="12"/><circle cx="58" cy="28" r="13"/><circle cx="88" cy="45" r="12"/></g><g fill="#fef08a"><circle cx="29" cy="42" r="4"/><circle cx="58" cy="28" r="4"/><circle cx="88" cy="45" r="4"/></g>''')),
    ("stepping-stones", "汀步石", "ground", "common", svg('''<ellipse cx="30" cy="88" rx="25" ry="14" fill="#94a3b8" stroke="#475569" stroke-width="4"/><ellipse cx="65" cy="62" rx="23" ry="13" fill="#cbd5e1" stroke="#64748b" stroke-width="4"/><ellipse cx="94" cy="34" rx="20" ry="11" fill="#94a3b8" stroke="#475569" stroke-width="4"/>''')),
    ("watering-can", "浇水壶", "ground", "common", svg('''<path d="M29 51h58v51H29z" fill="#38bdf8" stroke="#0369a1" stroke-width="4"/><path d="M32 61Q2 50 10 83l20 4M87 62l22-20 6 9-27 26M43 51q0-28 30-15 12 6 7 19" fill="none" stroke="#0369a1" stroke-width="7"/><circle cx="101" cy="32" r="5" fill="#7dd3fc"/>''')),
    ("fruit-basket", "果篮", "ground", "common", svg('''<path d="M20 52h80l-12 51H32z" fill="#b45309" stroke="#78350f" stroke-width="4"/><path d="M33 56q2-48 27-48t27 48" fill="none" stroke="#92400e" stroke-width="6"/><g stroke="#9f1239" stroke-width="3"><circle cx="38" cy="49" r="14" fill="#ef4444"/><circle cx="61" cy="42" r="15" fill="#f59e0b"/><circle cx="83" cy="51" r="14" fill="#84cc16"/></g><path d="M28 72h64M25 87h69" stroke="#fbbf24" stroke-width="4"/>''')),
    ("stone-lantern", "石灯", "ground", "common", svg('''<path d="M33 25h54l-9 17H42zm10 22h34v30H43zM49 77h22v25H49zm-15 25h52" fill="#94a3b8" stroke="#475569" stroke-width="4"/><rect x="50" y="54" width="20" height="16" rx="3" fill="#fde68a"/>''')),
    ("low-fence", "矮栅栏", "ground", "common", svg('''<path d="M16 101V34l12-14 12 14v67m20 0V34l12-14 12 14v67m20 0V34l12-14 12 14v67M9 56h110M9 84h110" fill="#d97706" stroke="#92400e" stroke-width="5"/>''')),
    ("small-mailbox", "小邮箱", "ground", "common", svg('''<path d="M22 36h56q22 0 22 23v24H22z" fill="#60a5fa" stroke="#1d4ed8" stroke-width="4"/><path d="M48 38v45M58 83v28" stroke="#1e3a8a" stroke-width="6"/><path d="M78 42V18h23v15H82" fill="#ef4444" stroke="#991b1b" stroke-width="4"/>''')),
    ("star-lights", "星星彩灯", "tree", "rare", svg('''<path d="M8 35q52 38 104 0" fill="none" stroke="#475569" stroke-width="4"/><g fill="#fde047" stroke="#ca8a04" stroke-width="2"><path d="M26 40l4 9 10 1-8 7 2 10-8-5-9 5 2-10-7-7 10-1z"/><path d="M60 55l4 9 10 1-8 7 2 10-8-5-9 5 2-10-7-7 10-1z"/><path d="M94 40l4 9 10 1-8 7 2 10-8-5-9 5 2-10-7-7 10-1z"/></g>''')),
    ("tree-door", "树洞小门", "tree", "rare", svg('''<path d="M25 105V58q0-43 35-43t35 43v47z" fill="#854d0e" stroke="#422006" stroke-width="5"/><path d="M38 105V60q0-28 22-28t22 28v45z" fill="#f59e0b" stroke="#92400e" stroke-width="4"/><circle cx="69" cy="70" r="4" fill="#fef08a"/><path d="M50 44v61m20-61v61M38 78h44" stroke="#b45309" stroke-width="3"/>''')),
    ("evergreen-ivy", "常青藤", "tree", "rare", svg('''<path d="M56 8q22 27 0 52T52 112" fill="none" stroke="#166534" stroke-width="6"/><g fill="#22c55e" stroke="#166534" stroke-width="2"><path d="M57 25q-29-18-27 13 18 7 27-13z"/><path d="M61 47q30-14 25 16-20 5-25-16z"/><path d="M52 72q-31-12-24 18 20 2 24-18z"/><path d="M54 94q29-11 24 18-20 2-24-18z"/></g>''')),
    ("wooden-nameplate", "木制铭牌", "tree", "rare", svg('''<path d="M18 35h84l10 38-10 24H18L8 73z" fill="#b45309" stroke="#78350f" stroke-width="5"/><path d="M31 17v18m58-18v18" stroke="#475569" stroke-width="5"/><path d="M32 64h56M42 78h36" stroke="#fef3c7" stroke-width="6"/>''')),
    ("wooden-bench", "长椅", "large", "rare", svg('''<path d="M14 46h92v21H14zm7 28h78v18H21z" fill="#d97706" stroke="#78350f" stroke-width="4"/><path d="M27 91l-7 22m73-22 7 22M29 67v8m62-8v8" stroke="#475569" stroke-width="6"/>''')),
    ("branch-swing", "秋千", "large", "rare", svg('''<path d="M29 10v71m62-71v71" stroke="#a16207" stroke-width="5"/><path d="M18 80h84l-9 24H27z" fill="#d97706" stroke="#78350f" stroke-width="5"/><path d="M14 11h92" stroke="#78350f" stroke-width="8"/>''')),
    ("picnic-rug", "野餐垫", "large", "rare", svg('''<path d="M15 39l79-20 15 69-79 20z" fill="#ef4444" stroke="#991b1b" stroke-width="4"/><path d="M24 56l80-20M29 76l80-20M45 31l15 69M70 25l15 69" stroke="#fef3c7" stroke-width="5"/>''')),
    ("small-fountain", "小喷泉", "large", "rare", svg('''<ellipse cx="60" cy="97" rx="52" ry="17" fill="#38bdf8" stroke="#0369a1" stroke-width="4"/><path d="M23 84h74l-9 20H32z" fill="#94a3b8" stroke="#475569" stroke-width="4"/><path d="M48 77h24V48H48zm-14-29h52L73 67H47z" fill="#cbd5e1" stroke="#64748b" stroke-width="4"/><path d="M60 48Q35 17 21 45M60 48Q85 17 99 45M60 48V11" fill="none" stroke="#7dd3fc" stroke-width="5"/>''')),
    ("spring-garden", "春日花园", "theme", "theme", theme('''<path d="M0 170q65-32 130 0t130 0v50H0z" fill="#86efac" opacity=".55"/><g fill="#f9a8d4" opacity=".8"><circle cx="26" cy="45" r="10"/><circle cx="62" cy="24" r="8"/><circle cx="207" cy="37" r="11"/><circle cx="235" cy="70" r="7"/></g><path d="M8 199q45-38 90 0m65 0q45-38 90 0" fill="none" stroke="#f472b6" stroke-width="8"/>''')),
    ("midsummer-camp", "仲夏露营", "theme", "theme", theme('''<path d="M0 181q130-35 260 0v39H0z" fill="#4ade80" opacity=".45"/><path d="M16 35q114 40 228 0" fill="none" stroke="#334155" stroke-width="3"/><g fill="#fde047"><circle cx="32" cy="41" r="5"/><circle cx="75" cy="51" r="5"/><circle cx="120" cy="56" r="5"/><circle cx="170" cy="53" r="5"/><circle cx="224" cy="42" r="5"/></g><path d="M176 196l31-63 36 63z" fill="#fb923c" stroke="#9a3412" stroke-width="4"/>''')),
    ("autumn-harvest", "秋收庭院", "theme", "theme", theme('''<path d="M0 184q130-30 260 0v36H0z" fill="#fbbf24" opacity=".45"/><g fill="#f97316" stroke="#9a3412" stroke-width="3"><ellipse cx="32" cy="190" rx="22" ry="15"/><ellipse cx="218" cy="188" rx="25" ry="17"/></g><g fill="#dc2626" opacity=".75"><path d="M19 38l10 19-20-4z"/><path d="M225 30l17 13-19 7z"/><path d="M68 23l13 18-21 1z"/></g>''')),
    ("winter-starlight", "冬日星光", "theme", "theme", theme('''<path d="M0 184q130-30 260 0v36H0z" fill="#dbeafe" opacity=".58"/><g fill="#fef08a"><path d="M31 25l5 11 12 1-9 8 3 12-11-6-11 6 3-12-9-8 12-1z"/><path d="M222 36l4 9 10 1-8 7 2 10-8-5-9 5 2-10-7-7 10-1z"/></g><path d="M16 82q114 37 228 0" fill="none" stroke="#60a5fa" stroke-width="5"/><g fill="#fde047"><circle cx="31" cy="86" r="4"/><circle cx="77" cy="96" r="4"/><circle cx="128" cy="101" r="4"/><circle cx="181" cy="95" r="4"/><circle cx="229" cy="85" r="4"/></g>''')),
]


def silhouette(source: str) -> str:
    muted = re.sub(r'#[0-9a-fA-F]{6}', '#64748b', source)
    return muted.replace('<g filter="url(#shadow)"', '<g opacity=".62" filter="url(#shadow)"')


def main() -> None:
    SOURCE.mkdir(parents=True, exist_ok=True)
    BLUR.mkdir(parents=True, exist_ok=True)
    subcategories = [
        "lanterns", "ribbons_signs", "wind_chime", "bird_home", "flora", "flora",
        "stones", "containers", "containers", "lighting", "paths", "signs",
        "celestial", "carving_masks", "hanging_garden", "ribbons_signs",
        "seating", "seating", "seating", "water_features",
    ]
    manifest = []
    for item_id, name, category, rarity, content in ITEMS:
        (SOURCE / f"{item_id}.svg").write_text(content, encoding="utf-8")
        (BLUR / f"{item_id}.svg").write_text(silhouette(content), encoding="utf-8")
        if category == "theme":
            continue  # Historical theme artwork stays available for old scene data.
        manifest.append({"id": item_id, "name": name, "category": category,
                         "subcategory": subcategories[len(manifest)],
                         "rarity": rarity, "asset_key": item_id})
    payload = json.dumps({"catalog_version": 3, "items": manifest}, ensure_ascii=False, indent=2) + "\n"
    server = Path(__file__).resolve().parents[1] / "server" / "deskbox-api" / "config" / "tree-decorations.json"
    for destination in (ROOT / "catalog.json", server):
        temporary = destination.with_suffix(destination.suffix + ".tmp")
        with temporary.open("w", encoding="utf-8", newline="\n") as handle:
            handle.write(payload)
        temporary.replace(destination)


if __name__ == "__main__":
    main()
