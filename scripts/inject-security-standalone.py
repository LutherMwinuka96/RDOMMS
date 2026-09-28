import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = [
    'users/permissions.html',
    'beneficiaries/beneficiary-history.html',
    'notifications/messages.html',
    'notifications/system-alerts.html',
    'notifications/approval-requests.html',
    'help/video-tutorials.html',
    'help/user-manual.html',
    'index.html',
    'settings/email-smtp.html',
]

for rel in FILES:
    path = ROOT / rel.replace('/', '\\')
    if not path.exists():
        continue
    text = path.read_text(encoding='utf-8')
    if 'rdomms-security.js' in text:
        continue
    depth = len(Path(rel).parts) - 1
    prefix = '../' * depth if depth else ''
    tag = f'<script src="{prefix}assets/js/rdomms-security.js"></script>\n'
    if re.search(r'<head[^>]*>', text, re.I):
        text = re.sub(r'(<head[^>]*>)', r'\1\n' + tag, text, count=1, flags=re.I)
    elif '</head>' in text:
        text = text.replace('</head>', tag + '</head>', 1)
    else:
        continue
    path.write_text(text, encoding='utf-8')
    print('standalone', rel)
