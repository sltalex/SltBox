"""Append missing localization keys using the English text as a fallback.

This preserves existing translations and line ordering. It is intentionally
additive: translators can replace the fallback strings independently later.
"""

from __future__ import annotations

import json
from pathlib import Path


STRINGS = Path(__file__).resolve().parents[1] / "src" / "DeskBox" / "Strings"
ENGLISH = json.loads((STRINGS / "en-US.json").read_text(encoding="utf-8"))

for path in sorted(STRINGS.glob("*.json")):
    if path.name == "en-US.json":
        continue
    content = path.read_text(encoding="utf-8")
    existing = json.loads(content)
    extra = set(existing) - set(ENGLISH)
    if extra:
        raise ValueError(f"{path.name} has keys absent from en-US: {sorted(extra)}")
    missing = [key for key in ENGLISH if key not in existing]
    if not missing:
        continue
    suffix = "".join(
        "\n  ," + json.dumps(key, ensure_ascii=False) + ":" +
        json.dumps(ENGLISH[key], ensure_ascii=False)
        for key in missing
    )
    close = content.rfind("}")
    if close < 0:
        raise ValueError(f"{path.name} is not a JSON object")
    updated = content[:close].rstrip() + suffix + "\n}" + content[close + 1:]
    if set(json.loads(updated)) != set(ENGLISH):
        raise ValueError(f"{path.name} still differs from en-US")
    path.write_text(updated, encoding="utf-8")
    print(f"{path.name}: added {len(missing)} fallback translations")
