"""
One-off: turn the owner's cross-match CSV (paper lists × Pooja punch app)
into backend/database/data/employees-2026-09.json, applying the rules of the
approved design (docs/superpowers/specs/2026-09-03-hrms-attendance-and-ask-erp-design.md,
Track 1):

  - name is the Pooja spelling where one exists, else the paper spelling;
  - department / designation are the Pooja app's (B. Suresh: app wins,
    the paper's "Plant Manager" stays in the note);
  - a paper name with no Pooja ID gets TMP-<serial>, active;
  - a Pooja ID absent from the paper list is imported inactive.

Usage: python3 build-employees-2026-09.py <cross-match.csv>
"""
import csv
import json
import os
import sys

source = sys.argv[1]
target = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'employees-2026-09.json')

out = []
for r in csv.DictReader(open(source, newline='', encoding='utf-8')):
    serial = int(r['serial']) if r['serial'] else None
    code = r['employee_code']
    note = r['note'].strip()
    status = 'active'
    designation = r['designation']
    if serial is not None and not code:
        code = f'TMP-{serial}'
        note = 'no Pooja ID yet — set the real code when HR assigns one'
    if serial is None:
        status = 'inactive'
        note = 'in Pooja app, not on the September list'
    if r['list_name'] == 'B.SURESH':
        designation = 'Production Supervisor'
    out.append({
        'serial': serial,
        'list_name': r['list_name'] or None,
        'employee_code': code,
        'name': r['punch_name'] or r['list_name'],
        'department': r['department'],
        'designation': designation,
        'status': status,
        'note': note or None,
    })

with open(target, 'w', encoding='utf-8') as fh:
    json.dump(out, fh, indent=2, ensure_ascii=False)
    fh.write('\n')

codes = [o['employee_code'] for o in out]
print(len(out), 'people;', sum(1 for o in out if o['status'] == 'inactive'), 'inactive;',
      sum(1 for o in out if o['employee_code'].startswith('TMP-')), 'TMP codes;',
      len(codes) - len(set(codes)), 'duplicate codes')
