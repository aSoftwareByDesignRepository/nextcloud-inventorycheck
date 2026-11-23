#!/usr/bin/env python3
"""Apply manually curated l10n quality fixes for inventorycheck."""
import json, subprocess, sys
from pathlib import Path
L10N = Path(__file__).parent
ROOT = L10N.parents[2]

def apply(lang, fixes):
    data = json.loads((L10N / f"{lang}.json").read_text(encoding="utf-8"))
    trans = data["translations"]
    n = 0
    for k, v in fixes.items():
        if v and k in trans:
            trans[k] = v; n += 1
    data["translations"] = trans
    (L10N / f"{lang}.json").write_text(json.dumps(data, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    return n

total = 0
for p in sorted(L10N.glob("_quality_fixes_*.json")):
    lang = p.stem.replace("_quality_fixes_", "")
    n = apply(lang, json.loads(p.read_text(encoding="utf-8")))
    print(f"{lang}: {n}"); total += n
subprocess.run(["php", str(ROOT / "scripts/l10n/regenerate-l10n-js.php"), "--app=inventorycheck"], check=True, cwd=ROOT)
print(f"Total: {total}")
