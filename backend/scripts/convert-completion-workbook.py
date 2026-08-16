"""Convert the factory's completion workbook into a deterministic JSON fixture.

This is the sibling of `convert-product-master.py` and keeps its one rule —
cells are copied VERBATIM — but goes further, because this workbook is a
QUESTIONNAIRE rather than a master. Its answer cells hold whatever the factory
wrote, prose included ("no of trays:5,no of bottles per box 1040 nos,per
tray:208"). Turning that into figures is exactly what this pipeline must never
do behind a person's back, so THIS SCRIPT PARSES NOTHING.

It does not split a cell, divide one cell by another, normalise a name, or
decide what an answer means. It copies text, stamps each row with where it came
from, and refuses anything it does not recognise. Every judgement happens
later, in front of the owner.

## What it refuses

Guessing is the failure mode, so the converter fails closed. It refuses when:

  * a required sheet is missing, renamed, or duplicated, or the workbook
    carries a sheet this schema does not declare;
  * a header cell is missing, reworded, or duplicated within its sheet, or the
    sheet carries a header this schema does not declare;
  * a data row carries a value in a column outside the schema — silently
    dropping a column the factory added would be the quiet kind of wrong;
  * a cell holds a spreadsheet error, or a number that will not read back;
  * a cell's own reference disagrees with the row carrying it, a row number is
    not a plain positive integer, or one row number or cell reference appears
    twice — `sheet_row` is what sends a person to the right cell, so an
    ambiguous one is refused rather than resolved by whichever came last;
  * a shared-string index is not a canonical non-negative number inside the
    table. A negative index read backwards and returned a different string;
  * the output path is the input workbook, under any name a link gives it:
    the fixture would be written over the source data it was read from;
  * a cell holds a FORMULA. A formula is a calculation this converter did not
    see performed, and a cached result is Excel's answer, not the factory's. It
    is refused even when the cached value looks fine — including in a header
    row, where a computed header is not a question anybody wrote.

It never repairs any of these. It names the sheet, the cell and both strings,
and exits 2.

## No openpyxl

`convert-product-master.py` reads its workbook with openpyxl. This one uses
only the standard library — an .xlsx is a zip of XML, and `zipfile` plus
`xml.etree` read it in far less code than an install step costs. That is
deliberate: this converter's output is a COMMITTED fixture that must reproduce
byte-for-byte on any machine, and "first create a venv and install openpyxl" is
a step that makes a reproduction check fail for reasons unrelated to the data.

## What it emits

    {
      "source_workbook": "SWAASHPET-products-to-complete.xlsx",
      "source_sha256": "<64 lowercase hex characters>",
      "sheets": [
        {"sheet": "Raw material", "schema_index": 5, "header_row": 1,
         "columns": [{"key": "question", "column": "A", "header": "Question",
                      "answer": false}, ...],
         "rows": [{"sheet": "Raw material", "sheet_row": 2,
                   "cells": {"question": "...", "our_note": "...",
                             "answer": null}}]}
      ]
    }

`source_sha256` is the SHA-256 of the input workbook's exact bytes — the same
bytes this run parsed, hashed once and reused, so the digest cannot describe a
different file than the output does. `source_workbook` stays a bare basename, so
the digest is the only thing that identifies WHICH copy of that name was read:
a second workbook arriving under the same name is then visible in the diff
rather than silent.

`schema_index` is this sheet's position in the SCHEMA above (1-based), not its
position in the workbook — sheets are emitted in schema order, so a reordered
workbook does not move the fixture. It was called `sheet_index`, which read as
the workbook's own tab order and was wrong.

`sheet_row` is the SPREADSHEET's own 1-based row number — not a position in
this file — so a later finding can name the cell the factory has open in front
of them. Header rows are described in `columns` and are not emitted as data.
Every row repeats its own `sheet` name, so a row stays traceable after it is
lifted out of its sheet.

Every declared column appears in every row, `null` when the cell is blank. A
whitespace-only cell is `null` too: both mean "nothing was written here".
Strings are otherwise copied exactly — leading spaces and embedded newlines
included. Numbers and booleans keep their stored types rather than becoming
text; a type is a fact about the cell, not a reading of it.

`answer` marks a column the workbook ASKS the factory to fill in ("PLEASE
WRITE", "PLEASE ANSWER", a question in the header). It says nothing about what
any cell contains, and nothing about whether an answer is right.

There is no timestamp and no absolute path in the output: the same workbook
must produce the same bytes tomorrow, and on someone else's machine.

## Usage

    python3 backend/scripts/convert-completion-workbook.py \
        ~/Downloads/SWAASHPET-products-to-complete.xlsx \
        backend/tests/fixtures/completion-workbook-rows.json
"""
import hashlib
import io
import json
import math
import os
import re
import sys
import xml.etree.ElementTree as ET
import zipfile

MAIN = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
RELS = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'

# The workbook's shape, declared rather than discovered. Each column is
# (key, column letter, header text, is_answer). The header text is the
# workbook's own wording, byte for byte — it is what a rename is measured
# against, so it must not be tidied here.
#
# `header_row` is None for the instructions sheet: it is prose in column A,
# with no table and so no header to check.
SHEETS = (
    {
        'sheet': 'How to use',
        'header_row': None,
        'columns': (
            ('text', 'A', None, False),
        ),
    },
    {
        'sheet': 'Products to complete',
        'header_row': 1,
        'columns': (
            ('sl_no', 'A', 'Sl. No.\n(Excel)', False),
            ('product', 'B', 'Product\n(our sheet)', False),
            ('cavities', 'C', 'Cavities', False),
            ('weight_g', 'D', 'Weight\n(g)', False),
            ('cycle_time_s', 'E', 'Cycle\ntime (s)', False),
            ('what_is_missing', 'F', 'What is missing', False),
            ('tally_product_now', 'G', 'Tally product now', False),
            ('suggestion_1', 'H', 'Suggestion 1 - is this it?', False),
            ('suggestion_2', 'I', 'Suggestion 2', False),
            ('suggestion_3', 'J', 'Suggestion 3', False),
            ('answer_tally_product_name', 'K', 'PLEASE WRITE: exact Tally product name', True),
            ('answer_bottles_per_tray_or_pouch', 'L', 'PLEASE WRITE: bottles per tray/pouch', True),
            ('answer_bottles_per_box', 'M', 'PLEASE WRITE: bottles per box', True),
            ('notes', 'N', 'Notes', False),
        ),
    },
    {
        'sheet': 'Packing needed',
        'header_row': 1,
        'columns': (
            ('sl_no', 'A', 'Sl. No.', False),
            ('product', 'B', 'Product', False),
            ('cavities', 'C', 'Cavities', False),
            ('weight_g', 'D', 'Weight (g)', False),
            ('carton_spec', 'E', 'Carton spec', False),
            ('tray_spec', 'F', 'Tray spec', False),
            ('pouch_spec', 'G', 'Pouch spec', False),
            ('packing_we_already_have', 'H', 'Packing we already have', False),
            ('answer_bottles_per_tray', 'I', 'PLEASE WRITE: bottles per tray', True),
            ('answer_trays_per_box', 'J', 'PLEASE WRITE: trays per box', True),
            ('answer_bottles_per_box', 'K', 'PLEASE WRITE: bottles per box', True),
            ('notes', 'L', 'Notes', False),
        ),
    },
    {
        'sheet': 'Colour questions',
        'header_row': 1,
        'columns': (
            ('product_tally_name', 'A', 'Product (Tally name)', False),
            ('colour_we_stored', 'B', 'Colour we stored', False),
            ('colour_the_name_says', 'C', 'Colour the name says', False),
            ('answer_which_is_correct', 'D', 'PLEASE WRITE: which is correct?', True),
            # A question put to the factory, so an answer cell in everything
            # but its wording.
            ('answer_different_masterbatch', 'E', 'Is it a different masterbatch?', True),
        ),
    },
    {
        'sheet': 'Raw material',
        'header_row': 1,
        'columns': (
            ('question', 'A', 'Question', False),
            ('our_note', 'B', 'Our note', False),
            ('answer', 'C', 'PLEASE ANSWER', True),
        ),
    },
)

INTEGER = re.compile(r'[+-]?[0-9]+$')
# A row number is a POSITIVE integer written plainly: spreadsheets number rows
# from 1, and "01" or "+2" would be a second spelling of a row that already has
# one. `str.isdigit()` was not enough — it accepts superscripts and other
# non-ASCII digits that `int()` then reads differently or not at all.
COLUMN_LETTERS = re.compile(r'^([A-Z]+)([1-9][0-9]*)$')
ROW_NUMBER = re.compile(r'^[1-9][0-9]*$')
# A shared-string index is a canonical non-negative integer. `int()` alone
# accepted "-1", "+1" and " 1 "; a negative index in particular did not fail,
# it silently reached BACKWARDS into the table and returned some other string.
SHARED_INDEX = re.compile(r'^(?:0|[1-9][0-9]*)$')


class WorkbookRefused(Exception):
    """The workbook is not the one this schema describes. Refuse, never guess."""


def row_number(row, sheet):
    """The <row r="..."> number, held to a plain positive integer."""
    raw = row.get('r')
    if raw is None or ROW_NUMBER.match(raw) is None:
        raise WorkbookRefused(f'{sheet} carries a row with no usable row number ({raw!r})')
    return int(raw)


def cell_column(ref, sheet, number):
    """"B12" -> "B", and only when it is sitting in row 12.

    A cell reference this script cannot read is a refusal, and so is one that
    names a row other than the row carrying it: `sheet_row` is provenance —
    the row number a person will open the spreadsheet to — so a cell whose own
    reference disagrees with its row would file a value under a row it was
    never in.
    """
    match = COLUMN_LETTERS.match(ref or '')
    if match is None:
        raise WorkbookRefused(f'{sheet}: cell reference {ref!r} is not a plain A1 reference')
    if int(match.group(2)) != number:
        raise WorkbookRefused(
            f'{sheet}!{ref} is carried by row {number}, but its own reference names row '
            f'{match.group(2)} — a cell that disagrees with its row makes provenance a guess')
    return match.group(1)


def cell_value(cell, shared, sheet, ref):
    """The cell's value with its stored type, or None when it is blank.

    Strings come through exactly as written — no stripping, no collapsing —
    except that a whitespace-only string is None, the same as an empty cell.
    """
    # First, before any branch that could return a cached <v>: a formula cell
    # is refused outright. `<f/>` covers the shared and array forms too, which
    # carry no text of their own but are still formulas.
    if cell.find(MAIN + 'f') is not None:
        raise WorkbookRefused(
            f'{sheet}!{ref} holds a formula; this converter copies what the factory wrote, '
            'and a cached result is the spreadsheet\'s answer rather than theirs — '
            'refused even though a cached value is present')

    kind = cell.get('t')

    if kind == 'inlineStr':
        raw = ''.join(node.text or '' for node in cell.iter(MAIN + 't'))
        return raw if raw.strip() else None

    if kind == 'e':
        node = cell.find(MAIN + 'v')
        raise WorkbookRefused(
            f'{sheet}!{ref} holds the spreadsheet error {(node.text if node is not None else "?")!r}; '
            'this converter will not decide what that was meant to be')

    node = cell.find(MAIN + 'v')
    if node is None:
        return None
    raw = node.text or ''

    if kind == 's':
        if SHARED_INDEX.match(raw) is None or int(raw) >= len(shared):
            raise WorkbookRefused(f'{sheet}!{ref} points at shared string {raw!r}, which is not there')
        raw = shared[int(raw)]
        return raw if raw.strip() else None

    if kind in ('str', 'd'):
        return raw if raw.strip() else None

    if kind == 'b':
        if raw.strip() not in ('0', '1'):
            raise WorkbookRefused(f'{sheet}!{ref} is a boolean cell holding {raw!r}')
        return raw.strip() == '1'

    if kind is not None and kind != 'n':
        raise WorkbookRefused(f'{sheet}!{ref} has cell type {kind!r}, which this converter does not read')

    text = raw.strip()
    if not text:
        return None
    try:
        number = int(text) if INTEGER.match(text) else float(text)
    except ValueError:
        raise WorkbookRefused(f'{sheet}!{ref} is a number cell holding {raw!r}')
    if isinstance(number, float) and not math.isfinite(number):
        raise WorkbookRefused(f'{sheet}!{ref} holds {raw!r}, which is not a finite number')
    return number


def sheet_paths(archive):
    """{sheet name: xml path} in workbook order. Duplicate names are refused."""
    workbook = ET.fromstring(archive.read('xl/workbook.xml'))
    targets = {rel.get('Id'): rel.get('Target')
               for rel in ET.fromstring(archive.read('xl/_rels/workbook.xml.rels'))}

    paths = {}
    for sheet in workbook.find(MAIN + 'sheets'):
        name = sheet.get('name')
        if name in paths:
            raise WorkbookRefused(f'the workbook carries two sheets named {name!r}')
        target = targets[sheet.get(RELS + 'id')].lstrip('/')
        paths[name] = target if target.startswith('xl/') else 'xl/' + target

    declared = [spec['sheet'] for spec in SHEETS]
    missing = [name for name in declared if name not in paths]
    unexpected = [name for name in paths if name not in declared]
    if missing or unexpected:
        raise WorkbookRefused(
            'the workbook is not the one this converter describes — '
            f'missing {missing!r}, unexpected {unexpected!r}. A renamed sheet shows up as both; '
            'update the schema deliberately rather than letting the converter match it up')

    return paths


def read_rows(archive, path):
    """{row number: {column letter: element}} for one sheet, in file order."""
    sheet = ET.fromstring(archive.read(path))
    data = sheet.find(MAIN + 'sheetData')
    return [] if data is None else list(data)


def check_headers(spec, rows, shared):
    """Every declared header present, word for word, and nothing else."""
    name, header_row = spec['sheet'], spec['header_row']
    found = {}
    for row in rows:
        number = row_number(row, name)
        if number != header_row:
            continue
        # Refs are recorded BEFORE the blank gate below: a blank duplicate is
        # still two cells claiming one address, and hanging this off `found`
        # would let `<c r="A1"/>` followed by a filled A1 through unseen.
        refs = {}
        for cell in row:
            ref = cell.get('r')
            letter = cell_column(ref, name, number)
            if letter in refs:
                raise WorkbookRefused(
                    f'{name} row {number} carries the cell {ref!r} twice; '
                    'which of the two the sheet means would be a guess')
            refs[letter] = ref
            value = cell_value(cell, shared, name, ref)
            if value is not None:
                found[letter] = value

    for key, letter, header, _ in spec['columns']:
        actual = found.get(letter)
        if actual is None:
            raise WorkbookRefused(
                f'{name}!{letter}{header_row} should be the header {header!r}, but it is empty')
        if actual != header:
            raise WorkbookRefused(
                f'{name}!{letter}{header_row} reads {actual!r}, not {header!r} — '
                'a reworded header is a different question, so this is refused, not matched')

    declared_letters = {letter for _, letter, _, _ in spec['columns']}
    extra = sorted(letter for letter in found if letter not in declared_letters)
    if extra:
        raise WorkbookRefused(
            f'{name} row {header_row} carries headers this converter does not know: '
            + ', '.join(f'{letter}={found[letter]!r}' for letter in extra))

    seen = {}
    for letter in sorted(found):
        text = found[letter]
        if text in seen:
            raise WorkbookRefused(
                f'{name} row {header_row} uses the header {text!r} twice '
                f'({seen[text]} and {letter}); which column an answer belongs to would be a guess')
        seen[text] = letter


def read_sheet(spec, index, rows, shared):
    name = spec['sheet']
    letters = {letter: key for key, letter, _, _ in spec['columns']}
    keys = [key for key, _, _, _ in spec['columns']]

    out = []
    seen_rows = set()
    for row in rows:
        number = row_number(row, name)
        # Counted across the whole sheet, header row included: two rows sharing
        # a number would put two different sets of answers under one
        # `sheet_row`, and the fixture could no longer say which was which.
        if number in seen_rows:
            raise WorkbookRefused(
                f'{name} carries row {number} twice; a row number is this fixture\'s '
                'provenance, so two rows claiming one number is refused, not merged')
        seen_rows.add(number)
        if number == spec['header_row']:
            continue

        cells = dict.fromkeys(keys)
        refs = {}
        for cell in row:
            ref = cell.get('r')
            letter = cell_column(ref, name, number)
            if letter in refs:
                raise WorkbookRefused(
                    f'{name} row {number} carries the cell {ref!r} twice; '
                    'which of the two the sheet means would be a guess')
            refs[letter] = ref
            value = cell_value(cell, shared, name, ref)
            if letter not in letters:
                if value is not None:
                    raise WorkbookRefused(
                        f'{name}!{ref} holds {value!r} in column {letter}, which this converter '
                        'does not carry — a column added to the workbook must be added here too, '
                        'not dropped')
                continue
            cells[letters[letter]] = value

        if all(value is None for value in cells.values()):
            continue

        out.append({'sheet': name, 'sheet_row': number, 'cells': cells})

    return {
        'sheet': name,
        'schema_index': index,
        'header_row': spec['header_row'],
        'columns': [{'key': key, 'column': letter, 'header': header, 'answer': answer}
                    for key, letter, header, answer in spec['columns']],
        'rows': out,
    }


def convert(xlsx_path):
    """The whole workbook as plain data. Raises WorkbookRefused, never guesses."""
    # Read once, hash that buffer, parse that same buffer: the digest is then
    # provably of the bytes this output was built from, not of a second read.
    with open(xlsx_path, 'rb') as handle:
        source = handle.read()
    digest = hashlib.sha256(source).hexdigest()

    with zipfile.ZipFile(io.BytesIO(source)) as archive:
        shared = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            for item in ET.fromstring(archive.read('xl/sharedStrings.xml')):
                shared.append(''.join(node.text or '' for node in item.iter(MAIN + 't')))

        paths = sheet_paths(archive)
        sheets = []
        # Schema order, not workbook order: a reordered workbook is still the
        # same five sheets, and the fixture should not move because of it.
        for index, spec in enumerate(SHEETS, start=1):
            rows = read_rows(archive, paths[spec['sheet']])
            if spec['header_row'] is not None:
                check_headers(spec, rows, shared)
            sheets.append(read_sheet(spec, index, rows, shared))

    return {
        'source_workbook': os.path.basename(xlsx_path),
        'source_sha256': digest,
        'sheets': sheets,
    }


def serialize(payload):
    """The fixture's canonical bytes — as text, LF, one trailing newline.

    Kept separate from writing so a test can hold the committed file to the
    same form without an .xlsx anywhere near it.
    """
    return json.dumps(payload, indent=2, ensure_ascii=True, allow_nan=False) + '\n'


def main(argv):
    if len(argv) != 2:
        raise WorkbookRefused('usage: convert-completion-workbook.py <workbook.xlsx> <out.json>')

    xlsx_path, out_path = argv
    # Before anything is read and long before anything is opened for writing:
    # the output is truncated on open, so an out_path that lands on the source
    # workbook would destroy the factory's file. The same path is the obvious
    # case; a symlink or a hard link is the same file under a second name, and
    # `samefile` sees both.
    same = os.path.abspath(xlsx_path) == os.path.abspath(out_path)
    if not same and os.path.exists(xlsx_path) and os.path.exists(out_path):
        try:
            same = os.path.samefile(xlsx_path, out_path)
        except OSError:
            same = False
    if same:
        raise WorkbookRefused(
            f'the output {out_path!r} is the input workbook {xlsx_path!r} — writing the '
            'fixture there would overwrite the source data this converter exists to preserve')

    payload = convert(xlsx_path)

    with open(out_path, 'w', encoding='utf-8', newline='\n') as handle:
        handle.write(serialize(payload))

    for sheet in payload['sheets']:
        print(f"{len(sheet['rows']):4d} rows  {sheet['sheet']}")
    print(f'-> {out_path}')


if __name__ == '__main__':
    try:
        main(sys.argv[1:])
    except WorkbookRefused as refusal:
        print(f'REFUSED: {refusal}', file=sys.stderr)
        sys.exit(2)
