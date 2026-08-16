"""Tests for `backend/scripts/convert-completion-workbook.py`.

The converter's whole promise is that it PARSES NOTHING and REFUSES anything
it does not recognise, and that its output is a committed fixture which
reproduces byte for byte. These tests hold it to exactly that, and nothing
else — they assert no reading of what any answer means.

No .xlsx anywhere. A clean CI checkout does not have the factory's workbook
(it lives in somebody's Downloads), so every test here works from either the
COMMITTED fixture or a few lines of constructed spreadsheet XML. The refusal
tests build their own cells rather than mutating a real workbook, so they stay
small enough to read and cannot depend on a file that is not in the repo.

Standard library only, and the converter is imported through importlib because
its filename is hyphenated and so is not a legal module name.
"""
import contextlib
import importlib.util
import io
import json
import os
import tempfile
import unittest
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CONVERTER_PATH = ROOT / 'backend' / 'scripts' / 'convert-completion-workbook.py'
FIXTURE_DIR = ROOT / 'backend' / 'tests' / 'fixtures'
FIXTURE_PATH = FIXTURE_DIR / 'completion-workbook-rows.json'


def load_converter():
    spec = importlib.util.spec_from_file_location('convert_completion_workbook', CONVERTER_PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


converter = load_converter()

# The spreadsheet namespace, taken from the converter's own constant with the
# {braces} stripped, so constructed XML cannot drift away from what the code
# looks for. A cell built in the wrong namespace would silently miss
# `find(MAIN + 'f')` and the formula test would pass for the wrong reason.
NS = converter.MAIN[1:-1]


def cell_xml(body):
    """One <c> element in the spreadsheet namespace."""
    return ET.fromstring(f'<c xmlns="{NS}" {body}</c>')


def text_cell(ref, text):
    """An inline-string cell — no shared-strings table needed."""
    return f'<c r="{ref}" t="inlineStr"><is><t>{text}</t></is></c>'


def row_xml(number, cells):
    return ET.fromstring(f'<row xmlns="{NS}" r="{number}">{"".join(cells)}</row>')


class FixtureIsCanonical(unittest.TestCase):
    """The committed fixture is exactly what the converter would write today."""

    def test_serialize_reproduces_the_committed_bytes(self):
        raw = FIXTURE_PATH.read_bytes()
        # read_bytes, not read_text: universal-newline translation would let a
        # CRLF checkout pass a test that claims byte-for-byte reproduction.
        decoded = json.loads(raw)
        self.assertEqual(converter.serialize(decoded), raw.decode('utf-8'))
        self.assertTrue(raw.endswith(b'\n'))
        self.assertNotIn(b'\r', raw)


class Metadata(unittest.TestCase):
    def setUp(self):
        self.payload = json.loads(FIXTURE_PATH.read_bytes())

    def test_source_workbook_is_the_bare_basename(self):
        self.assertEqual(self.payload['source_workbook'], 'SWAASHPET-products-to-complete.xlsx')

    def test_source_sha256_identifies_which_copy_was_read(self):
        self.assertEqual(
            self.payload['source_sha256'],
            '2847af266bc2c0ba04fac9b906533565d8ef262457a776b338aff6564da07df3')


class SheetShape(unittest.TestCase):
    def setUp(self):
        self.payload = json.loads(FIXTURE_PATH.read_bytes())

    def test_row_counts_by_named_sheet_and_schema_index(self):
        # schema_index is the sheet's position in SHEETS, not the workbook's
        # tab order, so it is pinned alongside the name.
        self.assertEqual(
            [(sheet['sheet'], sheet['schema_index'], len(sheet['rows']))
             for sheet in self.payload['sheets']],
            [('How to use', 1, 13),
             ('Products to complete', 2, 72),
             ('Packing needed', 3, 40),
             ('Colour questions', 4, 5),
             ('Raw material', 5, 4)])


class AnswerCounts(unittest.TestCase):
    """How much of the questionnaire is filled in — counted, never read."""

    def setUp(self):
        self.payload = json.loads(FIXTURE_PATH.read_bytes())

    def populated(self, sheet_name):
        sheet = next(s for s in self.payload['sheets'] if s['sheet'] == sheet_name)
        return {column['key']: sum(1 for row in sheet['rows']
                                   if row['cells'][column['key']] is not None)
                for column in sheet['columns'] if column['answer']}

    def test_products_to_complete(self):
        self.assertEqual(self.populated('Products to complete'), {
            'answer_tally_product_name': 60,
            'answer_bottles_per_tray_or_pouch': 38,
            'answer_bottles_per_box': 15,
        })

    def test_packing_needed_is_entirely_unanswered(self):
        self.assertEqual(self.populated('Packing needed'), {
            'answer_bottles_per_tray': 0,
            'answer_trays_per_box': 0,
            'answer_bottles_per_box': 0,
        })

    def test_colour_questions_are_entirely_unanswered(self):
        self.assertEqual(self.populated('Colour questions'), {
            'answer_which_is_correct': 0,
            'answer_different_masterbatch': 0,
        })

    def test_raw_material_is_entirely_unanswered(self):
        self.assertEqual(self.populated('Raw material'), {'answer': 0})


class SuggestionsAreEvidenceNotAnswers(unittest.TestCase):
    """The suggestion columns are what WE guessed; they are not the factory's word."""

    def setUp(self):
        self.payload = json.loads(FIXTURE_PATH.read_bytes())

    def test_suggestions_are_not_answer_columns(self):
        sheet = next(s for s in self.payload['sheets'] if s['sheet'] == 'Products to complete')
        columns = {column['key']: column for column in sheet['columns']}
        for key in ('suggestion_1', 'suggestion_2', 'suggestion_3'):
            self.assertIn(key, columns)
            self.assertIs(columns[key]['answer'], False)

    def test_no_catalogue_or_master_is_emitted(self):
        self.assertEqual(set(self.payload), {'source_workbook', 'source_sha256', 'sheets'})

    def test_no_catalogue_fixture_exists(self):
        self.assertFalse((FIXTURE_DIR / 'completion-workbook-catalogue.json').exists())


class FormulaCellsAreRefused(unittest.TestCase):
    def test_a_cached_value_is_still_refused_and_the_cell_is_named(self):
        cell = cell_xml('r="K7" t="n"><f>SUM(A1:A2)</f><v>208</v>')
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.cell_value(cell, [], 'Products to complete', 'K7')
        message = str(caught.exception)
        self.assertIn('Products to complete', message)
        self.assertIn('K7', message)
        self.assertIn('formula', message)


class SchemaIsStrict(unittest.TestCase):
    """Raw material — the smallest declared sheet — stands in for all five."""

    def setUp(self):
        self.spec = converter.SHEETS[4]
        self.assertEqual(self.spec['sheet'], 'Raw material')

    def test_a_reworded_header_is_refused_rather_than_matched(self):
        header = row_xml(1, [
            text_cell('A1', 'Question'),
            text_cell('B1', 'Our note'),
            text_cell('C1', 'PLEASE ANSWER THIS'),
        ])
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.check_headers(self.spec, [header], [])
        message = str(caught.exception)
        self.assertIn('Raw material!C1', message)
        self.assertIn('PLEASE ANSWER THIS', message)

    def test_a_populated_unknown_column_is_refused_rather_than_dropped(self):
        rows = [
            row_xml(1, [text_cell('A1', 'Question'),
                        text_cell('B1', 'Our note'),
                        text_cell('C1', 'PLEASE ANSWER')]),
            row_xml(2, [text_cell('A2', 'Which grade?'),
                        text_cell('D2', 'added by the factory')]),
        ]
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.read_sheet(self.spec, 5, rows, [])
        message = str(caught.exception)
        self.assertIn('Raw material!D2', message)
        self.assertIn('added by the factory', message)

    def test_the_declared_shape_still_reads(self):
        # The same construction WITHOUT the stray column must pass, so the two
        # refusals above are about the schema and not about broken XML. Several
        # rows, each with correct references, because the reference and
        # row-number checks below are the kind that can over-refuse: an
        # ordinary sheet has to keep reading.
        rows = [
            row_xml(1, [text_cell('A1', 'Question'),
                        text_cell('B1', 'Our note'),
                        text_cell('C1', 'PLEASE ANSWER')]),
            row_xml(2, [text_cell('A2', 'Which grade?'), text_cell('B2', 'ours')]),
            row_xml(7, [text_cell('A7', 'Which supplier?')]),
        ]
        converter.check_headers(self.spec, rows, [])
        sheet = converter.read_sheet(self.spec, 5, rows, [])
        self.assertEqual(sheet['rows'], [
            {'sheet': 'Raw material', 'sheet_row': 2,
             'cells': {'question': 'Which grade?', 'our_note': 'ours', 'answer': None}},
            {'sheet': 'Raw material', 'sheet_row': 7,
             'cells': {'question': 'Which supplier?', 'our_note': None, 'answer': None}},
        ])


class SharedStringIndexesAreCanonical(unittest.TestCase):
    """A shared-string index is a position in the table, or it is a refusal."""

    def test_a_negative_index_does_not_read_backwards(self):
        # int("-1") is a legal int, and shared[-1] is the LAST string — so this
        # cell used to come back as 'LAST' rather than being refused.
        cell = cell_xml('r="A2" t="s"><v>-1</v>')
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.cell_value(cell, ['FIRST', 'LAST'], 'Raw material', 'A2')
        message = str(caught.exception)
        self.assertIn('A2', message)
        self.assertNotIn('LAST', message)

    def test_out_of_range_and_non_canonical_indexes_are_refused(self):
        for raw in ('2', '+1', ' 1 ', '01', '1.0', ''):
            with self.subTest(raw=raw):
                cell = cell_xml(f'r="A2" t="s"><v>{raw}</v>')
                with self.assertRaises(converter.WorkbookRefused):
                    converter.cell_value(cell, ['FIRST', 'LAST'], 'Raw material', 'A2')

    def test_an_index_inside_the_table_still_reads(self):
        cell = cell_xml('r="A2" t="s"><v>1</v>')
        self.assertEqual(converter.cell_value(cell, ['FIRST', 'LAST'], 'Raw material', 'A2'), 'LAST')


class ReferencesAndRowNumbers(unittest.TestCase):
    """`sheet_row` is provenance, so an ambiguous one is refused, not resolved."""

    def setUp(self):
        self.spec = converter.SHEETS[4]

    def test_cell_column_holds_a_reference_to_its_own_row(self):
        self.assertEqual(converter.cell_column('B12', 'Raw material', 12), 'B')
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.cell_column('A3', 'Raw material', 2)
        message = str(caught.exception)
        self.assertIn('Raw material', message)
        self.assertIn('A3', message)
        self.assertIn('2', message)

    def test_cell_column_still_refuses_a_reference_it_cannot_read(self):
        for ref in (None, '', 'A', '12', 'a1', '$A$1', 'A0', 'A01'):
            with self.subTest(ref=ref):
                with self.assertRaises(converter.WorkbookRefused):
                    converter.cell_column(ref, 'Raw material', 1)

    def test_row_number_must_be_a_plain_positive_integer(self):
        self.assertEqual(converter.row_number(row_xml(4, []), 'Raw material'), 4)
        for number in ('0', '-1', '+2', '01', '', 'x', '²'):
            with self.subTest(number=number):
                row = ET.fromstring(f'<row xmlns="{NS}" r="{number}"/>')
                with self.assertRaises(converter.WorkbookRefused):
                    converter.row_number(row, 'Raw material')
        with self.assertRaises(converter.WorkbookRefused):
            converter.row_number(ET.fromstring(f'<row xmlns="{NS}"/>'), 'Raw material')

    def test_a_cell_naming_another_row_is_refused_with_sheet_ref_and_row(self):
        # Row 2 carrying A3 used to be emitted as sheet_row 2 holding A3's
        # value — the value of a cell the row never contained.
        rows = [row_xml(2, [text_cell('A3', 'Which grade?')])]
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.read_sheet(self.spec, 5, rows, [])
        message = str(caught.exception)
        self.assertIn('Raw material', message)
        self.assertIn('A3', message)
        self.assertIn('2', message)

    def test_a_duplicate_cell_reference_is_refused_rather_than_taking_the_last(self):
        rows = [row_xml(2, [text_cell('A2', 'FIRST'), text_cell('A2', 'SECOND')])]
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.read_sheet(self.spec, 5, rows, [])
        self.assertIn('A2', str(caught.exception))

    def test_a_duplicate_cell_reference_is_refused_in_the_header_row_too(self):
        # The first A1 is BLANK: recording refs only for filled cells would let
        # this pair through.
        header = row_xml(1, [
            '<c r="A1"/>',
            text_cell('A1', 'Question'),
            text_cell('B1', 'Our note'),
            text_cell('C1', 'PLEASE ANSWER'),
        ])
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.check_headers(self.spec, [header], [])
        self.assertIn('A1', str(caught.exception))

    def test_a_duplicate_row_number_is_refused(self):
        rows = [
            row_xml(2, [text_cell('A2', 'Which grade?')]),
            row_xml(2, [text_cell('A2', 'Which supplier?')]),
        ]
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.read_sheet(self.spec, 5, rows, [])
        self.assertIn('2', str(caught.exception))

    def test_a_row_number_that_is_not_a_positive_integer_is_refused(self):
        row = ET.fromstring(f'<row xmlns="{NS}" r="0">{text_cell("A1", "x")}</row>')
        with self.assertRaises(converter.WorkbookRefused):
            converter.read_sheet(self.spec, 5, [row], [])


class TheSourceWorkbookIsNotOverwritten(unittest.TestCase):
    """The output may never land on the input: the .xlsx is the only copy.

    Everything here happens inside a tempfile directory — the factory's
    workbook in somebody's Downloads is never named, opened or written.
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.workbook = Path(self.tmp.name) / 'book.xlsx'
        self.workbook.write_bytes(b'PK\x03\x04 not really a workbook')

    def assert_refused_and_source_intact(self, out_path):
        before = self.workbook.read_bytes()
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.main([str(self.workbook), str(out_path)])
        self.assertEqual(self.workbook.read_bytes(), before)
        return str(caught.exception)

    def test_the_same_path_is_refused_and_names_the_conflict(self):
        message = self.assert_refused_and_source_intact(self.workbook)
        self.assertIn(str(self.workbook), message)

    def test_the_same_file_reached_through_a_symlink_is_refused(self):
        link = Path(self.tmp.name) / 'out.json'
        link.symlink_to(self.workbook)
        message = self.assert_refused_and_source_intact(link)
        self.assertIn('out.json', message)

    def test_the_same_file_reached_through_a_hard_link_is_refused(self):
        link = Path(self.tmp.name) / 'hard.json'
        os.link(self.workbook, link)
        message = self.assert_refused_and_source_intact(link)
        self.assertIn('hard.json', message)

    def test_a_different_output_path_is_not_refused_for_this_reason(self):
        # It still fails — the dummy input is not a zip — but as a parse
        # failure, not as the same-file refusal. The guard must not swallow
        # every run.
        out = Path(self.tmp.name) / 'rows.json'
        with self.assertRaises(Exception) as caught:
            converter.main([str(self.workbook), str(out)])
        self.assertNotIsInstance(caught.exception, converter.WorkbookRefused)


def build_workbook(path, sheet_rows):
    """A minimal .xlsx carrying every declared sheet. Inline strings only.

    The refusal tests above call the converter's functions directly; this
    builds a whole archive so the reading layer — `sheet_paths`, `read_rows`
    and the header/data passes together — is exercised end to end without the
    factory's workbook, which a CI checkout does not have.
    """
    names = [spec['sheet'] for spec in converter.SHEETS]
    with zipfile.ZipFile(path, 'w') as archive:
        archive.writestr('xl/workbook.xml',
                         f'<workbook xmlns="{NS}" xmlns:r="{converter.RELS[1:-1]}"><sheets>'
                         + ''.join(f'<sheet name="{name}" r:id="rId{i}"/>'
                                   for i, name in enumerate(names, start=1))
                         + '</sheets></workbook>')
        archive.writestr(
            'xl/_rels/workbook.xml.rels',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            + ''.join(f'<Relationship Id="rId{i}" Target="worksheets/sheet{i}.xml"/>'
                      for i in range(1, len(names) + 1))
            + '</Relationships>')
        for i, name in enumerate(names, start=1):
            body = ''.join(f'<row r="{number}">{"".join(cells)}</row>'
                           for number, cells in sheet_rows[name])
            archive.writestr(f'xl/worksheets/sheet{i}.xml',
                             f'<worksheet xmlns="{NS}"><sheetData>{body}</sheetData></worksheet>')


def declared_rows():
    """Header rows straight from the schema, plus one data row per sheet."""
    rows = {}
    for spec in converter.SHEETS:
        name = spec['sheet']
        if spec['header_row'] is None:
            rows[name] = [(1, [text_cell('A1', 'Fill in the answer columns.')])]
            continue
        header = [text_cell(f'{letter}1', xml_escape(text))
                  for _, letter, text, _ in spec['columns']]
        first_letter = spec['columns'][0][1]
        rows[name] = [(1, header), (2, [text_cell(f'{first_letter}2', 'a value')])]
    return rows


def xml_escape(text):
    return text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


class TheWholeArchiveStillReads(unittest.TestCase):
    """A constructed workbook of the declared shape converts and writes.

    The committed fixture is reproduced from the real .xlsx, which is not in
    the repo; this is what stands in for it here. It proves the new reference
    and row-number checks do not refuse an ordinary workbook.
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.workbook = Path(self.tmp.name) / 'book.xlsx'

    def test_convert_reads_every_declared_sheet(self):
        build_workbook(self.workbook, declared_rows())
        payload = converter.convert(str(self.workbook))
        self.assertEqual(payload['source_workbook'], 'book.xlsx')
        self.assertEqual(len(payload['source_sha256']), 64)
        self.assertEqual(
            [(sheet['sheet'], sheet['schema_index'], len(sheet['rows']))
             for sheet in payload['sheets']],
            [(spec['sheet'], index, 1)
             for index, spec in enumerate(converter.SHEETS, start=1)])

    def test_main_writes_the_canonical_bytes(self):
        build_workbook(self.workbook, declared_rows())
        out = Path(self.tmp.name) / 'rows.json'
        with contextlib.redirect_stdout(io.StringIO()):  # main() reports row counts
            converter.main([str(self.workbook), str(out)])
        raw = out.read_bytes()
        self.assertEqual(converter.serialize(json.loads(raw)), raw.decode('utf-8'))

    def test_a_mismatched_reference_inside_a_real_archive_is_refused(self):
        rows = declared_rows()
        rows['Raw material'] = [(1, [text_cell('A1', 'Question'),
                                     text_cell('B1', 'Our note'),
                                     text_cell('C1', 'PLEASE ANSWER')]),
                                (2, [text_cell('A3', 'a value')])]
        build_workbook(self.workbook, rows)
        with self.assertRaises(converter.WorkbookRefused) as caught:
            converter.convert(str(self.workbook))
        self.assertIn('A3', str(caught.exception))


class SerializeIsCanonical(unittest.TestCase):
    PAYLOAD = {'source_workbook': 'w.xlsx', 'source_sha256': 'a' * 64,
               'sheets': [{'sheet': 'Raw material', 'schema_index': 5, 'rows': []}]}

    def test_the_same_payload_gives_the_same_bytes(self):
        first = converter.serialize(self.PAYLOAD)
        self.assertEqual(first, converter.serialize(self.PAYLOAD))
        first.encode('ascii')  # ensure_ascii=True: no encoding-dependent bytes
        self.assertTrue(first.endswith('\n'))
        self.assertFalse(first.endswith('\n\n'))

    def test_a_non_finite_number_is_rejected(self):
        # allow_nan=False. NaN and Infinity are not JSON, and a fixture that
        # carried them would not read back.
        for value in (float('nan'), float('inf'), float('-inf')):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    converter.serialize({'sheets': [value]})


if __name__ == '__main__':
    unittest.main()
