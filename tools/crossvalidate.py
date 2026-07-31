#!/usr/bin/env python3
"""
Independently validates the artefacts produced by the hand-rolled PHP writers,
using parsers that know nothing about our implementation.

  php tools/crossvalidate.php /tmp && python3 tools/crossvalidate.py /tmp

openpyxl must open the workbook and see the header/data/totals; pypdf must open
the PDF, count pages and extract text.
"""
import sys

out_dir = sys.argv[1] if len(sys.argv) > 1 else "/tmp"
failures = []


def check(label, ok, detail=""):
    print(f"  {'PASS' if ok else 'FAIL'}  {label}" + (f" -> {detail}" if detail and not ok else ""))
    if not ok:
        failures.append(label)


print("== openpyxl reads the generated XLSX")
try:
    import openpyxl

    wb = openpyxl.load_workbook(f"{out_dir}/lrms_sample.xlsx")
    ws = wb.active
    check("workbook opens", True)
    check("sheet title", ws.title == "Branch Report", ws.title)

    grid = [[c.value for c in row] for row in ws.iter_rows()]
    flat = [[("" if v is None else v) for v in row] for row in grid]

    check("title cell present", flat[0][0] == "Branch-wise Report", str(flat[0][0]))

    header_row = next(
        (i for i, r in enumerate(flat) if r and r[0] == "Loan Account"), None
    )
    check("header row found", header_row is not None, str(flat[:5]))

    if header_row is not None:
        hdr = flat[header_row]
        check(
            "headings match",
            hdr[:6]
            == ["Loan Account", "Customer Name", "Village", "Outstanding", "Overdue", "Status"],
            str(hdr[:6]),
        )
        first = flat[header_row + 1]
        check("first data row account", first[0] == "LN00000001", str(first[0]))
        check(
            "numeric cell is a real number",
            isinstance(first[3], (int, float)) and abs(float(first[3]) - 1234.5) < 0.01,
            f"{first[3]!r} ({type(first[3]).__name__})",
        )
        last = flat[header_row + 61]
        check("totals row label", last[0] == "TOTAL", str(last[0]))
        check(
            "totals numeric",
            isinstance(last[3], (int, float)) and abs(float(last[3]) - 2258235.0) < 0.01,
            f"{last[3]!r}",
        )
        check("row count = 1 header + 60 data + 1 total", len(flat) - header_row == 62, str(len(flat) - header_row))

    check("freeze pane set", ws.freeze_panes is not None, str(ws.freeze_panes))
    check("autofilter set", ws.auto_filter.ref is not None, str(ws.auto_filter.ref))
except Exception as exc:  # noqa: BLE001
    check(f"openpyxl raised {type(exc).__name__}: {exc}", False)

print("\n== pypdf reads the generated PDF")
try:
    from pypdf import PdfReader

    reader = PdfReader(f"{out_dir}/lrms_sample.pdf")
    check("pdf opens", True)
    check("has pages", len(reader.pages) >= 2, f"pages={len(reader.pages)}")

    page = reader.pages[0]
    box = page.mediabox
    check(
        "landscape A4 geometry",
        abs(float(box.width) - 841.89) < 1 and abs(float(box.height) - 595.28) < 1,
        f"{float(box.width)}x{float(box.height)}",
    )

    text = "\n".join(p.extract_text() or "" for p in reader.pages)
    check("title text extracted", "Branch-wise Recovery Report" in text)
    check("header text extracted", "Loan Account" in text)
    check("data row extracted", "LN00000001" in text)
    check("last data row extracted", "LN00000060" in text)
    check("totals extracted", "TOTAL" in text)
    check("page number rendered", "Page 1" in text)
    check("rupee transliterated", "Rs. 1,25,000" in text, text[-400:].replace("\n", " ")[:200])
    check("no mojibake question marks in body", "????" not in text)
except Exception as exc:  # noqa: BLE001
    check(f"pypdf raised {type(exc).__name__}: {exc}", False)

print("\n" + "-" * 52)
print(f"  {'ALL CHECKS PASSED' if not failures else str(len(failures)) + ' FAILED: ' + ', '.join(failures)}")
print("-" * 52)
sys.exit(1 if failures else 0)
