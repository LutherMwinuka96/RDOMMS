import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COUNTDOWN = '<link rel="stylesheet" href="../assets/css/rdomms-session-countdown.css?v=2">'
OFFLINE = '<link rel="stylesheet" href="../assets/css/rdomms-icons-offline.css">'

updated = 0
for path in ROOT.rglob('*.html'):
    if path.name == 'RDOMMS.html':
        continue
    text = path.read_text(encoding='utf-8')
    if 'localStorage-service.js' not in text or 'rdomms-session-countdown.css' in text:
        continue
    if OFFLINE not in text:
        continue
    new_text = text.replace(OFFLINE, OFFLINE + '\n' + COUNTDOWN, 1)
    if new_text != text:
        path.write_text(new_text, encoding='utf-8')
        updated += 1
        print(path.relative_to(ROOT))

print(f'updated {updated} files')
