import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAT = re.compile(
    r'(<script\s+src=["\']([^"\']*?)localStorage-service\.js)(["\'][^>]*>\s*</script>)',
    re.IGNORECASE,
)

updated = 0
for path in ROOT.rglob('*.html'):
    if 'storage\\mail-outbox' in str(path).lower():
        continue
    text = path.read_text(encoding='utf-8')
    if 'localStorage-service.js' not in text or 'rdomms-security.js' in text:
        continue

    def repl(match: re.Match[str]) -> str:
        sec_src = match.group(1).replace('localStorage-service.js', 'rdomms-security.js') + match.group(3)
        return sec_src + '\n' + match.group(0)

    new_text, count = PAT.subn(repl, text, count=1)
    if count:
        path.write_text(new_text, encoding='utf-8')
        updated += 1
        print(path.relative_to(ROOT))

print(f'updated {updated} files')
