"""
One-off: cut a vitest fixture out of a real Pooja "Employee day wise
master report" workbook. Employee blocks are copied cell for cell — the
header text, the summary line, the blank rows and every day row across
every day column — with the employee name and ID replaced, so a fixture
has the real file's SHAPE and none of its people.

The report has printed two shapes, and both are pinned:

  python3 build-punch-fixture.py <july.xlsx> SPP-a,SPP-b
      → punchReport.fixture.json — a flat row list off the first sheet
        (July 2026: one "employee-master" sheet, 31 day columns in one band)

  python3 build-punch-fixture.py <august.xlsx> Staff:SPP-a,SPP-b Ladies:SPP-c
      → punchReport.august.fixture.json — {"sheets": [{"name", "rows"}]}
        (August 2026: four sheets, days 1-15 then an unlabelled 16-31 band)
"""
import json
import os
import re
import sys

import openpyxl

source, selectors = sys.argv[1], sys.argv[2:]
here = os.path.dirname(os.path.abspath(__file__))
wrapped = any(':' in selector for selector in selectors)
target = os.path.join(here, 'punchReport.august.fixture.json' if wrapped else 'punchReport.fixture.json')

workbook = openpyxl.load_workbook(source, read_only=True)
counter = 0


def cut(sheet_name, codes):
    """The named employees' blocks off one sheet, anonymised in place."""
    global counter
    ws = workbook[sheet_name] if sheet_name else workbook.worksheets[0]
    rows = [list(r) for r in ws.iter_rows(values_only=True)]
    starts = [i for i, r in enumerate(rows) if r[0] and str(r[0]).startswith('From:')]

    out = []
    for code in codes:
        start = next(i for i in starts if f'Employee ID: {code}' in str(rows[i][0]))
        end = next((j for j in starts if j > start), len(rows))
        block = [list(r) for r in rows[start:end]]
        counter += 1
        header = str(block[0][0])
        header = re.sub(r'Employee Name: .*', f'Employee Name: EMPLOYEE {counter:02d} ', header)
        header = re.sub(r'Employee ID: .*', f'Employee ID: TST-{counter:02d}', header)
        block[0][0] = header
        out.extend(block)

    return out


if wrapped:
    sheets = []
    for selector in selectors:
        name, codes = selector.split(':', 1)
        sheets.append({'name': name, 'rows': cut(name, codes.split(','))})
    payload = {'sheets': sheets}
    print(sum(len(s['rows']) for s in sheets), 'rows,', counter, 'blocks,', len(sheets), 'sheets')
else:
    payload = cut(None, selectors[0].split(','))
    print(len(payload), 'rows,', counter, 'blocks')

with open(target, 'w', encoding='utf-8') as fh:
    json.dump(payload, fh, ensure_ascii=False)
    fh.write('\n')
