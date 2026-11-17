#!/usr/bin/env python3
"""One-time audited source rewrite for YSEO's WordPress.org prefix compliance.

Old metadata stays intact in WordPress databases. This script only updates
product-owned PHP references to the canonical key names; a separate runtime
LegacyMetaBridge supplies non-destructive read fallback for historical rows.
Run only on the explicit 5.87.2 compliance branch.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
BRIDGE = ROOT / "src/Core/LegacyMetaBridge.php"
PATTERN = re.compile(r"(?<![A-Za-z0-9_])_yps_")
targets = sorted((ROOT / "src").rglob("*.php")) + sorted((ROOT / "data").rglob("*.php"))
changes = []
for path in targets:
    if path == BRIDGE:
        continue
    original = path.read_text(encoding="utf-8")
    updated, count = PATTERN.subn("_yablseo_", original)
    if count:
        path.write_text(updated, encoding="utf-8")
        changes.append((str(path.relative_to(ROOT)), count))
total = sum(n for _, n in changes)
for path, number in changes:
    print(f"META_PREFIX_REWRITE {path}: {number} occurrences")
print(f"META_PREFIX_TOTAL files={len(changes)} occurrences={total}")
if not (20 <= len(changes) <= 100 and 80 <= total <= 700):
    raise SystemExit("Unexpected legacy metadata scope: manual review required")
for path in targets:
    if path == BRIDGE:
        continue
    if PATTERN.search(path.read_text(encoding="utf-8")):
        raise SystemExit(f"Legacy key survived in active code: {path}")
print("Active product PHP contains no remaining _yps_ metadata identifiers outside compatibility bridge.")
