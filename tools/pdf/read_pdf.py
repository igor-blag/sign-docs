#!/usr/bin/env python
"""Extract text and basic metadata from a PDF.

Preferred backend is PyMuPDF because it handles many real-world PDFs well.
If PyMuPDF is unavailable, the script falls back to pypdf and then pdfminer.six.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any


def _compact_text(text: str) -> str:
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    text = re.sub(r"[ \t]+\n", "\n", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def _read_with_pymupdf(path: Path) -> tuple[str, dict[str, Any]]:
    import fitz  # type: ignore

    with fitz.open(path) as doc:
        pages = [page.get_text("text") for page in doc]
        meta = {
            "backend": "pymupdf",
            "pages": doc.page_count,
            "metadata": dict(doc.metadata or {}),
        }
    return _compact_text("\n\n".join(pages)), meta


def _read_with_pypdf(path: Path) -> tuple[str, dict[str, Any]]:
    from pypdf import PdfReader  # type: ignore

    reader = PdfReader(str(path))
    pages = [page.extract_text() or "" for page in reader.pages]
    meta = {
        "backend": "pypdf",
        "pages": len(reader.pages),
        "metadata": {str(k): str(v) for k, v in (reader.metadata or {}).items()},
    }
    return _compact_text("\n\n".join(pages)), meta


def _read_with_pdfminer(path: Path) -> tuple[str, dict[str, Any]]:
    from pdfminer.high_level import extract_text  # type: ignore

    text = extract_text(str(path))
    meta = {
        "backend": "pdfminer.six",
        "pages": None,
        "metadata": {},
    }
    return _compact_text(text), meta


def read_pdf(path: Path) -> tuple[str, dict[str, Any]]:
    errors: list[str] = []

    for reader in (_read_with_pymupdf, _read_with_pypdf, _read_with_pdfminer):
        try:
            return reader(path)
        except ModuleNotFoundError as exc:
            errors.append(f"{reader.__name__}: missing dependency {exc.name}")
        except Exception as exc:  # pragma: no cover - diagnostic CLI path
            errors.append(f"{reader.__name__}: {exc}")

    details = "\n".join(f"- {error}" for error in errors)
    raise RuntimeError(
        "Could not read PDF. Install dev dependencies with "
        "`python -m pip install -r requirements-dev.txt`.\n"
        f"{details}"
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Extract text from a PDF file.")
    parser.add_argument("pdf", type=Path, help="Path to PDF file")
    parser.add_argument(
        "--metadata",
        action="store_true",
        help="Print metadata as JSON before extracted text",
    )
    parser.add_argument(
        "--out",
        type=Path,
        help="Write extracted text to this file instead of stdout",
    )
    args = parser.parse_args()

    path = args.pdf
    if not path.exists():
        print(f"PDF not found: {path}", file=sys.stderr)
        return 2
    if path.suffix.lower() != ".pdf":
        print(f"Not a PDF file: {path}", file=sys.stderr)
        return 2

    try:
        text, meta = read_pdf(path)
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        return 1

    if args.metadata:
        print(json.dumps(meta, ensure_ascii=False, indent=2))
        if text:
            print()

    if args.out:
        args.out.write_text(text + ("\n" if text else ""), encoding="utf-8")
    else:
        print(text)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
