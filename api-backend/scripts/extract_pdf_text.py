import sys
from pathlib import Path

try:
    from PyPDF2 import PdfReader
except Exception as exc:
    print(f"ERROR: PyPDF2 unavailable: {exc}")
    sys.exit(1)

if len(sys.argv) < 2:
    print("ERROR: missing pdf path")
    sys.exit(1)

pdf_path = Path(sys.argv[1])
if not pdf_path.exists():
    print(f"ERROR: file not found: {pdf_path}")
    sys.exit(1)

try:
    reader = PdfReader(str(pdf_path))
    parts = []
    for page in reader.pages:
        extracted = page.extract_text() or ""
        if extracted.strip():
            parts.append(extracted)
    text = "\n".join(parts).strip()
    sys.stdout.buffer.write(text.encode("utf-8", errors="ignore"))
except Exception as exc:
    print(f"ERROR: {exc}")
    sys.exit(1)
