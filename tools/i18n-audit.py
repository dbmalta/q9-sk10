#!/usr/bin/env python3
"""Find translation keys referenced in templates/PHP but absent from lang/en.json."""
import json, re, collections
from pathlib import Path

root = Path(__file__).resolve().parent.parent
twigs = list(root.rglob('app/**/*.twig')) + list(root.rglob('app/**/*.html.twig'))
phps = list(root.rglob('app/**/*.php'))

key_re_twig = re.compile(r"""t\(\s*['"]([\w][\w.]*)['"]""")
key_re_php = re.compile(r"""->t\(\s*['"]([\w][\w.]*)['"]""")

used = collections.defaultdict(list)
for f in twigs + phps:
    try:
        text = f.read_text(encoding='utf-8', errors='replace')
    except Exception:
        continue
    regex = key_re_twig if f.suffix == '.twig' else key_re_php
    for m in regex.finditer(text):
        key = m.group(1)
        if '.' not in key:
            continue
        used[key].append(str(f.relative_to(root)).replace('\\', '/'))

en = json.load(open(root / 'lang' / 'en.json', encoding='utf-8'))
missing = sorted(k for k in used if k not in en)

print(f'Keys used: {len(used)} | In en.json: {len(en)} | Missing: {len(missing)}')
print()
for k in missing:
    locs = used[k]
    print(f'  {k}')
    for loc in locs[:3]:
        print(f'      {loc}')
    if len(locs) > 3:
        print(f'      (+{len(locs)-3} more)')
