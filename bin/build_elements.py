#!/usr/bin/env python3
"""Build the ACS element layer: public/data/elements.tsv.

Step 3 of the data pipeline, after build_from_v2.py and lift_to_saccer3.py.

    python3 bin/build_elements.py /path/to/nieduszy_oridb.sql --sacCer3 DIR

OriDB v2 holds four ACS element tables, all registered in sc_elements_studies:

    sc_confirmed_ACS       68 rows   experimentally confirmed
    Nieduszynski2006_ACS  196 rows   proposed (proACS), PMID 16847347
    Eaton2010_ACS         253 rows   predicted, PMID 20351051
    Xu2006_ACS            505 rows   predicted (nimACS), PMID 17067396

They do not share a genome build - the v2 assembly audit calls the first three
sacCer1 and Xu2006_ACS sacCer2 - and the coordinates they do carry are unreliable
(Nieduszynski2006_ACS stores 16 bp intervals for 15 bp sequences and is a base to
the left of the true position in three quarters of rows). Every record does carry
its sequence, so this script ignores the stored coordinates and locates each
element in sacCer3 by its sequence. That sidesteps the mixed builds entirely: no
chain file, no per-table lift, and a position that can be re-derived at any time.

anchor_status per record:

  unique                  the sequence occurs once in the sacCer3 nuclear genome
  resolved-by-proximity   several occurrences; the one nearest the stored
                          coordinate on the stored chromosome was taken
  ambiguous               several occurrences, none near the stored coordinate
  unanchored              the sequence does not occur in sacCer3

Elements are linked to origins by position, not by any v2 identifier: an element
is `contained` if it lies wholly inside a released origin, `overlapping` if it
straddles a boundary, and `unlinked` otherwise. Most unlinked elements belong to
Likely or Dubious origins, which this release does not yet carry; they are kept
so the layer is complete and will attach when those tiers are added.
"""

from __future__ import annotations

import argparse
import glob
import hashlib
import importlib.util
import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'public' / 'data'

# element table -> (evidence class, study-level PubMed ID, source assembly per the v2 audit)
# sc_confirmed_ACS is the exception: it cites a study PER RECORD, in its own
# pubmed_id column, across 18 different papers. Those citations are used in
# preference to any table-level one.
TABLES = {
    'sc_confirmed_ACS':     ('confirmed', '',         'sacCer1'),
    'Nieduszynski2006_ACS': ('proposed',  '16847347', 'sacCer1'),
    'Eaton2010_ACS':        ('predicted', '20351051', 'sacCer1'),
    'Xu2006_ACS':           ('predicted', '17067396', 'sacCer2'),
}
ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X',
         'XI', 'XII', 'XIII', 'XIV', 'XV', 'XVI']
PROXIMITY_LIMIT = 5000  # bp; builds differ by at most a few hundred bp


def load_builder():
    """Reuse the dump parser from build_from_v2.py rather than duplicating it."""
    spec = importlib.util.spec_from_file_location('b', ROOT / 'bin' / 'build_from_v2.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def rc(s: str) -> str:
    return s.translate(str.maketrans('ACGT', 'TGCA'))[::-1]


def load_fasta_dir(d: str) -> dict[str, str]:
    seqs = {}
    for p in sorted(glob.glob(os.path.join(d, 'chr*.fa'))):
        text = Path(p).read_text()
        name = text.split('\n', 1)[0][1:].strip().split()[0]
        seqs[name] = ''.join(text.split('\n')[1:]).upper()
    return seqs


def read_tsv(path: Path) -> list[dict[str, str]]:
    lines = path.read_text(encoding='utf-8').rstrip('\n').split('\n')
    header = lines[0].split('\t')
    return [dict(zip(header, l.split('\t'))) for l in lines[1:]]


def write_tsv(path: Path, header: list[str], rows: list[dict[str, str]]) -> None:
    with path.open('w', encoding='utf-8') as fh:
        fh.write('\t'.join(header) + '\n')
        for r in rows:
            fh.write('\t'.join(str(r.get(c, '')).replace('\t', ' ').replace('\n', ' ')
                               for c in header) + '\n')


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('dump', help='path to nieduszy_oridb.sql')
    ap.add_argument('--sacCer3', required=True, help='directory of sacCer3 chr*.fa')
    args = ap.parse_args()

    b = load_builder()
    sql = b.read_dump(Path(args.dump))
    sc3 = load_fasta_dir(args.sacCer3)
    nuclear = {k: v for k, v in sc3.items() if k != 'chrM'}

    # ---- collect the four element tables -------------------------------------
    records = []
    for table, (kind, pmid, src_asm) in TABLES.items():
        for row in b.load(sql, table):
            seq_field = 'sequence' if 'sequence' in row else 'seq'
            seq = b.unescape(str(row.get(seq_field, ''))).lstrip('.').upper()
            if not re.fullmatch(r'[ACGT]+', seq or ''):
                continue
            chrom = int(row['chr'])
            row_pmid = str(row.get('pubmed_id', '') or '').strip()
            if row_pmid in ('', '0', 'NULL'):
                row_pmid = pmid
            records.append({
                'sequence': seq, 'element_type': kind, 'study_pmid': row_pmid,
                'source_table': table, 'source_id': row.get('id') or row.get('ID', ''),
                'source_assembly': src_asm,
                'source_chromosome': ('chr%d' % chrom) if src_asm == 'sacCer2'
                                     else ('chr%d' % chrom),
                'source_start': row['start'], 'source_end': row['end'],
                'source_strand': (row.get('strand') or '').strip(),
                'source_name': b.unescape(str(row.get('name', ''))),
                'score': str(row.get('score', '')).strip(),
                # v2 records an integer per confirmed element in an `offset` column.
                # Its meaning is not documented in the schema, so it is carried
                # through unchanged rather than interpreted.
                'source_offset': str(row.get('offset', '') or '').strip(),
                'chrom_num': chrom,
            })

    # ---- locate every distinct sequence in sacCer3 ---------------------------
    index: dict[str, list[tuple[str, int, int, str]]] = {}
    for seq in {r['sequence'] for r in records}:
        found = []
        for name, chrseq in nuclear.items():
            for strand, probe in (('+', seq), ('-', rc(seq))):
                i = chrseq.find(probe)
                while i != -1:
                    found.append((name, i + 1, i + len(probe), strand))
                    i = chrseq.find(probe, i + 1)
        index[seq] = found

    counts = {'unique': 0, 'resolved-by-proximity': 0, 'ambiguous': 0, 'unanchored': 0}
    for r in records:
        found = index[r['sequence']]
        expect_chr = 'chr' + ROMAN[r['chrom_num']]
        if len(found) == 1:
            hit, status = found[0], 'unique'
        elif not found:
            hit, status = None, 'unanchored'
        else:
            same = [h for h in found if h[0] == expect_chr]
            near = sorted(same, key=lambda h: abs(h[1] - int(r['source_start'])))
            if near and abs(near[0][1] - int(r['source_start'])) <= PROXIMITY_LIMIT:
                hit, status = near[0], 'resolved-by-proximity'
            else:
                hit, status = None, 'ambiguous'
        counts[status] += 1
        r['anchor_status'] = status
        if hit is None:
            r.update(chromosome='', start='', end='', strand='')
        else:
            r.update(chromosome=hit[0], start=str(hit[1]), end=str(hit[2]), strand=hit[3])

    # ---- accessions, in genome order; unplaced records last ------------------
    placed = sorted([r for r in records if r['start']],
                    key=lambda r: (ROMAN.index(r['chromosome'][3:]), int(r['start'])))
    unplaced = sorted([r for r in records if not r['start']],
                      key=lambda r: (r['chrom_num'], int(r['source_start'])))
    for i, r in enumerate(placed + unplaced, start=1):
        r['accession'] = f'ODB-EL-{i:04d}'
        r['species'] = 'Saccharomyces cerevisiae'
        r['assembly'] = 'sacCer3' if r['start'] else ''
        r['length'] = str(len(r['sequence']))

    # ---- link to released origins by position --------------------------------
    origins = [o for o in read_tsv(DATA / 'origins.tsv') if o['assembly'] == 'sacCer3']
    by_chr: dict[str, list[dict[str, str]]] = {}
    for o in origins:
        by_chr.setdefault(o['chromosome'], []).append(o)
    link_counts = {'contained': 0, 'overlapping': 0, 'unlinked': 0}
    for r in placed + unplaced:
        r['origin_accession'], r['relation'] = '', 'unlinked'
        if not r['start']:
            link_counts['unlinked'] += 1
            continue
        s, e = int(r['start']), int(r['end'])
        for o in by_chr.get(r['chromosome'], []):
            os_, oe = int(o['start']), int(o['end'])
            if os_ <= s and e <= oe:
                r['origin_accession'], r['relation'] = o['accession'], 'contained'
                break
            if s <= oe and e >= os_:
                r['origin_accession'], r['relation'] = o['accession'], 'overlapping'
        link_counts[r['relation']] += 1

    header = ['accession', 'species', 'assembly', 'chromosome', 'start', 'end', 'strand',
              'sequence', 'length', 'element_type', 'study_pmid', 'origin_accession',
              'relation', 'anchor_status', 'source_table', 'source_id', 'source_assembly',
              'source_chromosome', 'source_start', 'source_end', 'source_strand',
              'source_name', 'score', 'source_offset']
    write_tsv(DATA / 'elements.tsv', header, placed + unplaced)

    # ---- make sure every element study is cited in studies.tsv ---------------
    studies = read_tsv(DATA / 'studies.tsv')
    known = {s['pmid'] for s in studies}
    pm = {str(r['pubmed_id']): r for r in b.load(sql, 'local_pubmed')}
    added = []
    cited = {r['study_pmid'] for r in records if r['study_pmid']}
    for pmid in sorted(cited):
        if pmid and pmid not in known and pmid in pm:
            r = pm[pmid]
            known.add(pmid)
            authors = [a.strip() for a in b.unescape(str(r['author_list'])).split(';') if a.strip()]
            studies.append({'pmid': pmid, 'doi': b.unescape(str(r['doi'])),
                            'year': b.unescape(str(r['date']))[:4],
                            'journal': b.unescape(str(r['journal'])),
                            'first_author': authors[0] if authors else '',
                            'n_authors': str(len(authors)),
                            'title': b.unescape(str(r['article_title']))})
            added.append(pmid)
    if added:
        studies.sort(key=lambda s: s['pmid'])
        write_tsv(DATA / 'studies.tsv', list(studies[0]), studies)

    # ---- release metadata ----------------------------------------------------
    rel = json.loads((DATA / 'release.json').read_text())
    rel['elements'] = {
        'description': 'ACS elements from the four OriDB v2 element studies, each located '
                       'in sacCer3 by its stored sequence rather than by its stored '
                       'coordinates.',
        'studies': {t: {'element_type': k, 'pmid': p, 'source_assembly': a}
                    for t, (k, p, a) in TABLES.items()},
        'anchor_status_counts': counts,
        'link_counts': link_counts,
    }
    rel['files']['elements.tsv'] = {
        'md5': hashlib.md5((DATA / 'elements.tsv').read_bytes()).hexdigest(),
        'rows': len(records),
    }
    rel['files']['studies.tsv'] = {
        'md5': hashlib.md5((DATA / 'studies.tsv').read_bytes()).hexdigest(),
        'rows': len(studies),
    }
    unresolved = counts['ambiguous'] + counts['unanchored']
    if unresolved:
        rel['known_issues'].append({
            'text': f'{unresolved} of {len(records)} ACS elements could not be placed in '
                    'sacCer3 from their sequence alone - either the sequence does not occur '
                    'in the current assembly, or it occurs several times with none near the '
                    'coordinate v2 recorded. They are published without coordinates rather '
                    'than placed on a guess; see anchor_status in elements.tsv.',
            'accessions': []})
    (DATA / 'release.json').write_text(json.dumps(rel, indent=2) + '\n')

    print(f'elements.tsv: {len(records)} records')
    print(f'  anchoring: {counts}')
    print(f'  linking:   {link_counts}')
    if added:
        print(f'  studies.tsv: added PMIDs {added}')


if __name__ == '__main__':
    main()
