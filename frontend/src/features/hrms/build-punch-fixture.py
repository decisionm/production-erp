"""
One-off: cut a vitest fixture out of a real Pooja "Employee day wise
master report" workbook. Four employee blocks are copied cell for cell —
the header text, the summary line, the blank rows and the eight day rows
across every day column — with the employee name and ID replaced, so the
fixture has the real file's SHAPE and none of its people.

Usage: python3 build-punch-fixture.py <punch.xlsx> <SPP-a,SPP-b,...>
Writes punchReport.fixture.json beside this script.
"""
import json
import os
import re
import sys

import openpyxl

source, wanted = sys.argv[1], sys.argv[2].split(',')
target = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'punchReport.fixture.json')

ws = openpyxl.load_workbook(source, read_only=True).worksheets[0]
rows = [list(r) for r in ws.iter_rows(values_only=True)]
starts = [i for i, r in enumerate(rows) if r[0] and str(r[0]).startswith('From:')]

out = []
for n, code in enumerate(wanted, start=1):
    start = next(i for i in starts if f'Employee ID: {code}' in str(rows[i][0]))
    end = next((j for j in starts if j > start), len(rows))
    block = [list(r) for r in rows[start:end]]
    header = str(block[0][0])
    header = re.sub(r'Employee Name: .*', f'Employee Name: EMPLOYEE {n:02d} ', header)
    header = re.sub(r'Employee ID: .*', f'Employee ID: TST-{n:02d}', header)
    block[0][0] = header
    out.extend(block)

with open(target, 'w', encoding='utf-8') as fh:
    json.dump(out, fh, ensure_ascii=False)
    fh.write('\n')

print(len(out), 'rows,', len(wanted), 'blocks')
