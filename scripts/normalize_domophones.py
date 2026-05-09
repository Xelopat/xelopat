from __future__ import annotations

import argparse
import json
import re
import shutil
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    import pythoncom
    import win32com.client
except ImportError as exc:  # pragma: no cover - environment guard
    raise SystemExit(
        "This converter needs pywin32 and Microsoft Office installed "
        "to read legacy .doc/.xls files."
    ) from exc


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SOURCE = ROOT / "База Домофонов"
DEFAULT_OUTPUT = ROOT / "data" / "domophones.json"

WORD_EXTENSIONS = {".doc", ".docx", ".odt"}
SHEET_EXTENSIONS = {".xls", ".xlsx", ".ods", ".xlt"}
SKIP_NAMES = {"thumbs.db"}

CONTROL_CHARS_RE = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f]")
SPACES_RE = re.compile(r"[ \t\f\v]+")
ENTRANCE_RE = re.compile(r"^\s*(\d{1,3})\s*\)\s*(.*)$")
INLINE_ENTRANCE_RE = re.compile(r"(?<!^)(?<!\n)(?<!\d)(\d{1,3}\s*\))")
CODE_RE = re.compile(
    r"(?iu)"
    r"(?:\d+\s*)?(?:реш|[#*]|[вкдd])\s*\d+"
    r"|(?:\d{3,8}\s*одн)"
    r"|\d{3,8}"
)


def clean_text(value: Any, keep_lines: bool = False) -> str:
    text = "" if value is None else str(value)
    text = text.replace("\r\n", "\n").replace("\r", "\n").replace("\xa0", " ")
    text = text.replace("\x07", "\n")
    text = CONTROL_CHARS_RE.sub("", text)
    lines = [SPACES_RE.sub(" ", line).strip() for line in text.split("\n")]
    lines = [line for line in lines if line]
    return "\n".join(lines) if keep_lines else " ".join(lines).strip()


def clean_title(value: str) -> str:
    title = clean_text(value)
    title = re.sub(r"\s+", " ", title)
    title = re.sub(r"\.+$", "", title).strip()
    return title


def rel_path(path: Path, source_dir: Path) -> str:
    return path.relative_to(source_dir).as_posix()


def location_from_path(path: Path, source_dir: Path) -> dict[str, Any]:
    parts = path.relative_to(source_dir).parts
    district = parts[0] if len(parts) > 1 else ""
    area = parts[1] if len(parts) > 2 else ""
    subarea = list(parts[2:-1]) if len(parts) > 3 else []
    return {"district": district, "area": area, "subarea": subarea}


def is_skipped(path: Path) -> bool:
    name = path.name.lower()
    return name in SKIP_NAMES or name.startswith("~$")


def zip_mimetype(path: Path) -> str:
    try:
        with zipfile.ZipFile(path) as archive:
            try:
                return archive.read("mimetype").decode("ascii", errors="ignore")
            except KeyError:
                return ""
    except zipfile.BadZipFile:
        return ""


def spreadsheet_open_path(path: Path, temp_dir: Path) -> Path:
    mimetype = zip_mimetype(path)
    if mimetype == "application/vnd.oasis.opendocument.spreadsheet" and path.suffix.lower() != ".ods":
        fixed_path = temp_dir / f"{path.stem}.ods"
        shutil.copy2(path, fixed_path)
        return fixed_path
    return path


def parse_code_lines(raw_codes: str) -> list[dict[str, Any]]:
    text = clean_text(raw_codes, keep_lines=True)
    if not text:
        return []

    text = INLINE_ENTRANCE_RE.sub(r"\n\1", text)
    parsed: list[dict[str, Any]] = []

    for line in text.split("\n"):
        line = clean_text(line)
        if not line:
            continue

        entrance: int | None = None
        code_text = line
        match = ENTRANCE_RE.match(line)
        if match:
            entrance = int(match.group(1))
            code_text = clean_text(match.group(2))

        parsed.append(
            {
                "entrance": entrance,
                "raw": line,
                "code": code_text,
                "tokens": [clean_text(match.group(0)) for match in CODE_RE.finditer(code_text)],
            }
        )

    return parsed


class WordReader:
    def __init__(self) -> None:
        self.app = win32com.client.DispatchEx("Word.Application")
        self.app.Visible = False
        self.app.DisplayAlerts = 0

    def close(self) -> None:
        self.app.Quit()

    def read(self, path: Path, source_dir: Path) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
        doc = None
        try:
            doc = self.app.Documents.Open(str(path), False, True, False)
            street = self._street_title(doc) or clean_title(path.stem)
            records: list[dict[str, Any]] = []
            notes: list[dict[str, Any]] = []

            for table_index in range(1, doc.Tables.Count + 1):
                table = doc.Tables.Item(table_index)
                for row_index in range(1, table.Rows.Count + 1):
                    try:
                        cells = [
                            clean_text(table.Rows.Item(row_index).Cells.Item(cell_index).Range.Text, keep_lines=True)
                            for cell_index in range(1, table.Rows.Item(row_index).Cells.Count + 1)
                        ]
                    except Exception:
                        cells = self._fallback_row_cells(table, row_index)

                    cells = [cell for cell in cells if cell]
                    if len(cells) < 2:
                        continue

                    house = clean_title(cells[0])
                    codes_raw = clean_text("\n".join(cells[1:]), keep_lines=True)
                    if not house and not codes_raw:
                        continue

                    location = location_from_path(path, source_dir)
                    records.append(
                        {
                            **location,
                            "street": street,
                            "house": house,
                            "entrances": parse_code_lines(codes_raw),
                            "raw_codes": codes_raw,
                            "source_file": rel_path(path, source_dir),
                            "source_table": table_index,
                            "source_row": row_index,
                        }
                    )

            if not records:
                text = clean_text(doc.Content.Text, keep_lines=True)
                if text:
                    notes.append(
                        {
                            **location_from_path(path, source_dir),
                            "title": street,
                            "text": text,
                            "source_file": rel_path(path, source_dir),
                        }
                    )

            return records, notes
        finally:
            if doc is not None:
                doc.Close(False)

    def _street_title(self, doc: Any) -> str:
        first_table_start = None
        if doc.Tables.Count:
            first_table_start = doc.Tables.Item(1).Range.Start

        for index in range(1, doc.Paragraphs.Count + 1):
            paragraph = doc.Paragraphs.Item(index)
            if first_table_start is not None and paragraph.Range.Start >= first_table_start:
                break
            text = clean_title(paragraph.Range.Text)
            if text:
                return text
        return ""

    def _fallback_row_cells(self, table: Any, row_index: int) -> list[str]:
        cells: list[str] = []
        for column_index in range(1, table.Columns.Count + 1):
            try:
                cells.append(clean_text(table.Cell(row_index, column_index).Range.Text, keep_lines=True))
            except Exception:
                continue
        return cells


class ExcelReader:
    def __init__(self) -> None:
        self.app = win32com.client.DispatchEx("Excel.Application")
        self.app.Visible = False
        self.app.DisplayAlerts = False

    def close(self) -> None:
        self.app.Quit()

    def read(self, path: Path, source_dir: Path, temp_dir: Path) -> dict[str, Any]:
        open_path = spreadsheet_open_path(path, temp_dir)
        workbook = None
        try:
            workbook = self.app.Workbooks.Open(str(open_path), None, True)
            sheets: list[dict[str, Any]] = []

            for sheet_index in range(1, workbook.Worksheets.Count + 1):
                sheet = workbook.Worksheets.Item(sheet_index)
                used = sheet.UsedRange
                rows: list[list[str]] = []

                for row_index in range(1, used.Rows.Count + 1):
                    row = [
                        clean_text(used.Cells.Item(row_index, column_index).Text)
                        for column_index in range(1, used.Columns.Count + 1)
                    ]
                    if any(row):
                        rows.append(row)

                sheets.append(
                    {
                        "name": clean_text(sheet.Name),
                        "title": first_non_empty(rows),
                        "rows": parse_index_rows(rows),
                    }
                )

            return {
                **location_from_path(path, source_dir),
                "source_file": rel_path(path, source_dir),
                "sheets": sheets,
            }
        finally:
            if workbook is not None:
                workbook.Close(False)


def first_non_empty(rows: list[list[str]]) -> str:
    for row in rows:
        for cell in row:
            if cell:
                return cell
    return ""


def parse_index_rows(rows: list[list[str]]) -> list[dict[str, Any]]:
    parsed: list[dict[str, Any]] = []
    title = first_non_empty(rows)

    for row_index, row in enumerate(rows, start=1):
        values = [cell for cell in row if cell]
        if not values:
            continue
        if row_index == 1 and values[0] == title:
            continue

        street = values[0]
        date = ""
        if len(row) >= 2 and row[1]:
            street = row[1]
        if len(row) >= 3 and row[2]:
            date = row[2]
        elif len(values) >= 2:
            date = values[-1]

        parsed.append(
            {
                "street": street,
                "date": date,
                "raw": row,
                "source_row": row_index,
            }
        )

    return parsed


def discover_files(source_dir: Path) -> list[Path]:
    files = []
    for path in source_dir.rglob("*"):
        if not path.is_file() or is_skipped(path):
            continue
        if path.suffix.lower() in WORD_EXTENSIONS | SHEET_EXTENSIONS:
            files.append(path)
    return sorted(files, key=lambda item: item.relative_to(source_dir).as_posix().lower())


def convert(source_dir: Path, offset: int = 0, limit: int | None = None, progress: int = 0) -> dict[str, Any]:
    files = discover_files(source_dir)
    if offset:
        files = files[offset:]
    if limit is not None:
        files = files[:limit]

    addresses: list[dict[str, Any]] = []
    indexes: list[dict[str, Any]] = []
    notes: list[dict[str, Any]] = []
    errors: list[dict[str, str]] = []

    pythoncom.CoInitialize()
    word = WordReader()
    excel = ExcelReader()
    try:
        with tempfile.TemporaryDirectory(prefix="domophones_") as temp_name:
            temp_dir = Path(temp_name)
            for file_index, path in enumerate(files, start=1):
                if progress and (file_index == 1 or file_index % progress == 0):
                    print(f"Processing {file_index}/{len(files)}: {rel_path(path, source_dir)}", file=sys.stderr)
                suffix = path.suffix.lower()
                try:
                    if suffix in WORD_EXTENSIONS:
                        records, file_notes = word.read(path, source_dir)
                        addresses.extend(records)
                        notes.extend(file_notes)
                    elif suffix in SHEET_EXTENSIONS:
                        indexes.append(excel.read(path, source_dir, temp_dir))
                except Exception as exc:
                    errors.append(
                        {
                            "source_file": rel_path(path, source_dir),
                            "error": str(exc),
                        }
                    )
    finally:
        excel.close()
        word.close()
        pythoncom.CoUninitialize()

    return {
        "schema_version": 1,
        "source_dir": source_dir.name,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "totals": {
            "files": len(files),
            "address_records": len(addresses),
            "index_files": len(indexes),
            "notes": len(notes),
            "errors": len(errors),
        },
        "addresses": addresses,
        "indexes": indexes,
        "notes": notes,
        "errors": errors,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Normalize intercom source files to one JSON file.")
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--offset", type=int, default=0, help="Skip the first N source files.")
    parser.add_argument("--limit", type=int, default=None, help="Process only the first N files for debugging.")
    parser.add_argument("--progress", type=int, default=50, help="Print a progress line roughly every N records.")
    args = parser.parse_args()

    source_dir = args.source.resolve()
    output_path = args.output.resolve()
    if not source_dir.exists():
        raise SystemExit(f"Source directory does not exist: {source_dir}")

    data = convert(source_dir, args.offset, args.limit, args.progress)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")

    totals = data["totals"]
    print(
        "Done: "
        f"{totals['files']} files, "
        f"{totals['address_records']} address records, "
        f"{totals['index_files']} index files, "
        f"{totals['errors']} errors -> {output_path}"
    )
    return 0 if not totals["errors"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
