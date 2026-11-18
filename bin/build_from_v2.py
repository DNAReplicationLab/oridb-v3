#!/usr/bin/env python3
"""Regenerate public/data/*.tsv and public/data/release.json from the OriDB v2 dump.

The dump is not in this repository. Point this script at a copy:

    python3 bin/build_from_v2.py /path/to/nieduszy_oridb.sql

Inclusion rule
--------------
An origin enters the trial release if its interval overlaps at least one record in
sc_cloned_ori (plasmid / ARS assay) or sc_2D_gel / sp_2D_gel (2D gel electrophoresis).
Against OriDB v2 this reproduces the Confirmed tier exactly: 458 of 458, with three
non-Confirmed origins also overlapping evidence (listed in release.json as open
curation questions).
"""

from __future__ import annotations

import datetime
import decimal
import hashlib
import json
import re
import sys
from pathlib import Path

ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X',
         'XI', 'XII', 'XIII', 'XIV', 'XV', 'XVI']
ASSEMBLY = {'sc': ('Saccharomyces cerevisiae', 'sacCer1'),
            'sp': ('Schizosaccharomyces pombe', 'ASM294v2')}


def read_dump(path: Path) -> str:
    return path.read_text(encoding='latin1')


def columns(sql: str, table: str) -> list[str]:
    m = re.search(r'CREATE TABLE `%s` \((.*?)\n\) ENGINE' % re.escape(table), sql, re.S)
    if m is None:
        raise KeyError(table)
    return re.findall(r'^\s*`([^`]+)`', m.group(1), re.M)


def split_values(payload: str) -> list[list[str]]:
    """Split a VALUES payload into row tuples, respecting quoted strings."""
    rows, cur, buf = [], [], ''
    in_str = esc = False
    depth = 0
    for ch in payload:
        if in_str:
            if esc:
                buf += ch
                esc = False
            elif ch == '\\':
                buf += ch
                esc = True
            elif ch == "'":
                in_str = False
            else:
                buf += ch
            continue
        if ch == "'":
            in_str = True
        elif ch == '(':
            depth, buf, cur = 1, '', []
        elif ch == ',' and depth == 1:
            cur.append(buf)
            buf = ''
        elif ch == ')' and depth == 1:
            cur.append(buf)
            rows.append(cur)
            depth, buf = 0, ''
        elif depth == 1:
            buf += ch
    return rows


def load(sql: str, table: str) -> list[dict[str, str]]:
    out = []
    pattern = r'INSERT INTO `%s` \(([^)]*)\) VALUES\s*(.*?);\n' % re.escape(table)
    for m in re.finditer(pattern, sql, re.S):
        cols = [c.strip('` ') for c in m.group(1).split(',')]
        for row in split_values(m.group(2)):
            out.append(dict(zip(cols, [v.strip() for v in row])))
    if not out:
        columns(sql, table)  # raises if the table is absent entirely
    return out


def unescape(s: str) -> str:
    s = (s.replace("\\'", "'").replace('\\"', '"')
          .replace('\\\\', '\\').replace('\\n', '\n').replace('\\r', ''))
    for _ in range(3):
        try:
            t = s.encode('latin1').decode('utf8')
        except (UnicodeDecodeError, UnicodeEncodeError):
            break
        if t == s:
            break
        s = t
    return s.strip()


def seqname(species: str, chrom: int) -> str:
    # UCSC naming for cerevisiae, PomBase naming for pombe.
    return ('chr' if species == 'sc' else '') + ROMAN[chrom]


def systematic(chrom: int, start: int, end: int) -> str:
    """Reproduce OriDB's systematic() helper: roman(chr) + '-' + round(mid/1000)."""
    n = int(decimal.Decimal((start + end) / 2000).quantize(0, rounding=decimal.ROUND_HALF_UP))
    return f'{ROMAN[chrom]}-{n}'


def overlaps(rows, chrom: int, start: int, end: int):
    return [r for r in rows
            if int(r['chr']) == chrom and int(r['start']) <= end and int(r['end']) >= start]


def main(dump_path: str) -> None:
    sql = read_dump(Path(dump_path))
    data_dir = Path(__file__).resolve().parent.parent / 'public' / 'data'
    data_dir.mkdir(exist_ok=True)

    tables = {name: load(sql, name) for name in
              ('sc_ori', 'sp_ori', 'sc_cloned_ori', 'sc_2D_gel', 'sp_2D_gel', 'local_pubmed')}

    # Accessions are minted over the FULL v2 origin set in genome order, so that
    # adding the Likely and Dubious tiers later does not renumber anything.
    accession = {}
    for sp, key in (('sc', 'sc_ori'), ('sp', 'sp_ori')):
        ordered = sorted(tables[key], key=lambda r: (int(r['chr']), int(r['start']), int(r['end'])))
        for i, row in enumerate(ordered, start=1):
            accession[(sp, row['ori_id'])] = f'ODB-{sp.upper()}-{i:04d}'

    origins, evidence = [], []
    exceptions = []
    for sp, ori_key, sources in (
        ('sc', 'sc_ori', [('plasmid (ARS assay)', 'sc_cloned_ori', 'result'),
                          ('2D gel electrophoresis', 'sc_2D_gel', 'enzyme')]),
        ('sp', 'sp_ori', [('2D gel electrophoresis', 'sp_2D_gel', 'enzyme')]),
    ):
        for o in sorted(tables[ori_key], key=lambda r: (int(r['chr']), int(r['start']))):
            chrom, start, end = int(o['chr']), int(o['start']), int(o['end'])
            acc = accession[(sp, o['ori_id'])]
            methods, records = [], []
            for method, table, detail_col in sources:
                hits = overlaps(tables[table], chrom, start, end)
                if hits:
                    methods.append(method)
                for r in hits:
                    pmid = str(r.get('pubmed_id', '')).strip()
                    records.append(dict(
                        accession=acc, method=method, chromosome=seqname(sp, int(r['chr'])),
                        start=r['start'], end=r['end'],
                        detail=unescape(str(r.get(detail_col, '') or '')),
                        pmid='' if pmid in ('0', 'NULL', '') else pmid,
                        source_table=table, source_id=r['id']))
            if not methods:
                continue
            if o['status'] != 'Confirmed':
                exceptions.append(f"{ori_key} {o['ori_id']} ({o['status']})")
                continue
            evidence.extend(records)
            origins.append(dict(
                accession=acc, species=ASSEMBLY[sp][0], assembly=ASSEMBLY[sp][1],
                chromosome=seqname(sp, chrom), start=str(start), end=str(end),
                name=unescape(o['name']), systematic=systematic(chrom, start, end),
                other_names=unescape(o['othernames']), status='Confirmed',
                evidence=';'.join(methods), legacy_id=f"{ori_key}:{o['ori_id']}"))

    cited = sorted({e['pmid'] for e in evidence if e['pmid']})
    by_pmid = {str(r['pubmed_id']): r for r in tables['local_pubmed']}
    studies = []
    for pmid in cited:
        r = by_pmid.get(pmid)
        authors = [a.strip() for a in unescape(str(r['author_list'])).split(';') if a.strip()] if r else []
        studies.append(dict(
            pmid=pmid,
            doi=unescape(str(r['doi'])) if r else '',
            year=unescape(str(r['date']))[:4] if r else '',
            journal=unescape(str(r['journal'])) if r else '',
            first_author=authors[0] if authors else '',
            n_authors=str(len(authors)),
            title=unescape(str(r['article_title'])) if r else ''))

    def write_tsv(name: str, rows: list[dict[str, str]]) -> None:
        path = data_dir / name
        header = list(rows[0])
        with path.open('w', encoding='utf-8') as fh:
            fh.write('\t'.join(header) + '\n')
            for row in rows:
                # Tabs and newlines would break the reader; neither occurs in practice.
                fh.write('\t'.join(str(row[c]).replace('\t', ' ').replace('\n', ' ')
                                   for c in header) + '\n')

    write_tsv('origins.tsv', origins)
    write_tsv('evidence.tsv', evidence)
    write_tsv('studies.tsv', studies)

    def md5(name: str) -> str:
        return hashlib.md5((data_dir / name).read_bytes()).hexdigest()

    release = {
        'release': '3.0.0-trial.1',
        'generated': datetime.date.today().isoformat(),
        'description': 'OriDB v3.0 trial release: the Confirmed origin tier only, carried '
                       'forward from OriDB v2 with explicit evidence records.',
        'inclusion_rule': 'An origin is Confirmed if its interval overlaps at least one '
                          'plasmid (ARS) assay positive or one 2D gel electrophoresis record.',
        'assemblies': {
            'sacCer1': {'species': 'Saccharomyces cerevisiae', 'ucsc_db': 'sacCer1',
                        'sequence_naming': 'UCSC roman (chrI..chrXVI)',
                        'note': 'Coordinates are as stored in OriDB v2 and verified sacCer1 by '
                                'sequence anchoring. Lift-over to sacCer3 is NOT yet applied.'},
            'ASM294v2': {'species': 'Schizosaccharomyces pombe', 'ucsc_db': 'GCA_000002945.3',
                         'sequence_naming': 'PomBase roman (I, II, III)',
                         'note': 'Current assembly; no lift-over required.'}},
        'coordinate_convention': '1-based, fully closed intervals',
        'files': {'origins.tsv': {'md5': md5('origins.tsv'), 'rows': len(origins)},
                  'evidence.tsv': {'md5': md5('evidence.tsv'), 'rows': len(evidence)},
                  'studies.tsv': {'md5': md5('studies.tsv'), 'rows': len(studies)}},
        'provenance': {'source': 'nieduszy_oridb MySQL dump '
                                 '(OriDB v2.1.0 cerevisiae / v1.0.0 pombe)',
                       'source_tables': sorted(tables)},
        'known_issues': [
            {'text': 'Non-Confirmed v2 origins that overlap direct evidence and need curator '
                     'review: ' + ', '.join(exceptions) + '. They are Likely or Dubious in v2, '
                     'so they are not in this release and cannot be linked here.',
             'accessions': []},
            {'text': str(sum(1 for e in evidence if not e['pmid']))
                     + ' evidence records carry no PubMed ID in v2 and need a citation '
                       'assigned. The origins they support are:',
             'accessions': sorted({e['accession'] for e in evidence if not e['pmid']})},
            {'text': 'Positions will not coincide with the ARS features UCSC and SGD show '
                     'for sacCer3. OriDB intervals are the experimentally assayed regions '
                     'reported by the source studies - for the plasmid data, the PCR fragment '
                     'that conferred ARS activity - which are larger objects than an annotated '
                     'ARS element. This is expected, not an error. A coordinate-convention '
                     'offset has been excluded for the largest contributing study (Nieduszynski '
                     '2006, PMID 16847347): its PCR primers were located in sacCer1 by sequence '
                     'and the 5\' and 3\' ends of 199 of its 228 fragments sit exactly on the '
                     'recorded coordinates, confirming them as 1-based inclusive. The remaining '
                     'plasmid and 2D gel sources have not been checked the same way.',
             'accessions': []},
        ],
    }
    (data_dir / 'release.json').write_text(json.dumps(release, indent=2) + '\n', encoding='utf-8')

    print(f'{len(origins)} origins, {len(evidence)} evidence records, {len(studies)} studies')
    print(f'curation exceptions: {exceptions}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
