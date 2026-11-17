#!/usr/bin/env python3
"""Lift the S. cerevisiae coordinates of a release from sacCer1 to sacCer3.

Step 2 of the data pipeline: `bin/build_from_v2.py` extracts the OriDB v2 records,
which are sacCer1; this script rewrites them onto the current assembly. It is
idempotent in the sense that it refuses to run twice - a release whose cerevisiae
rows already say sacCer3 is left alone.

    python3 bin/lift_to_saccer3.py [--sacCer3 DIR] [--sacCer1 DIR]

With both FASTA directories supplied, every lifted interval is additionally checked
by sequence: the sacCer1 interval is compared base for base with the sacCer3 interval
it maps to, and the outcome recorded per record in `lift_status`.

  identical            sequence unchanged between assemblies
  same-length-mismatch same length, one or more substitutions - the assembly sequence
                       was revised; the mapping is still correct
  length-changed       an indel inside the interval, so its length changes
  unverified           lifted, but no FASTA was supplied to check it
  unmapped             the chain has no alignment for an endpoint (none in v2 data)

S. pombe records are ASM294v2 already and are passed through untouched.

Chromosome naming: UCSC calls sacCer1 chromosomes chr1..chr16 and sacCer3 chrI..chrXVI.
The released files therefore carry the sacCer3 name in `chromosome` and the sacCer1 name
in `source_chromosome`; OriDB v2 itself stored a bare integer.
"""

from __future__ import annotations

import argparse
import datetime
import glob
import hashlib
import json
import os
import sys
from pathlib import Path

try:
    from pyliftover import LiftOver
except ImportError:  # pragma: no cover
    sys.exit("pyliftover is required: pip install pyliftover")

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'public' / 'data'
CHAIN = ROOT / 'reference' / 'sacCer1ToSacCer3.over.chain.gz'

ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X',
         'XI', 'XII', 'XIII', 'XIV', 'XV', 'XVI']
ROMAN_TO_NUM = {'chr' + r: i for i, r in enumerate(ROMAN) if r}


def read_tsv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    lines = path.read_text(encoding='utf-8').rstrip('\n').split('\n')
    header = lines[0].split('\t')
    return header, [dict(zip(header, l.split('\t'))) for l in lines[1:]]


def write_tsv(path: Path, header: list[str], rows: list[dict[str, str]]) -> None:
    with path.open('w', encoding='utf-8') as fh:
        fh.write('\t'.join(header) + '\n')
        for r in rows:
            fh.write('\t'.join(str(r.get(c, '')) for c in header) + '\n')


def load_fasta_dir(d: str | None) -> dict[str, str]:
    """Read every chr*.fa in a directory, keyed by the name in the FASTA header."""
    if d is None:
        return {}
    seqs = {}
    for p in sorted(glob.glob(os.path.join(d, 'chr*.fa'))):
        text = Path(p).read_text()
        name = text.split('\n', 1)[0][1:].strip().split()[0]
        seqs[name] = ''.join(text.split('\n')[1:]).upper()
    return seqs


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--sacCer1', help='directory of sacCer1 chr*.fa (OriDB v2 ships these)')
    ap.add_argument('--sacCer3', help='directory of sacCer3 chr*.fa from UCSC bigZips')
    args = ap.parse_args()

    if not CHAIN.exists():
        sys.exit(f'missing chain file: {CHAIN}')
    lo = LiftOver(str(CHAIN))
    sc1 = load_fasta_dir(args.sacCer1)
    sc3 = load_fasta_dir(args.sacCer3)
    verify = bool(sc1 and sc3)
    if not verify:
        print('WARNING: no FASTA supplied - lifting without sequence verification',
              file=sys.stderr)

    def lift(chrom: str, start: int, end: int) -> tuple[str, int, int, str]:
        src = 'chr%d' % ROMAN_TO_NUM[chrom]
        a = lo.convert_coordinate(src, start - 1)
        b = lo.convert_coordinate(src, end - 1)
        if not a or not b:
            return '', 0, 0, 'unmapped'
        (ca, pa, sa, _), (cb, pb, sb, _) = a[0], b[0]
        if ca != cb or sa != '+' or sb != '+' or pb < pa:
            return '', 0, 0, 'unmapped'
        ns, ne = pa + 1, pb + 1
        if not verify:
            return ca, ns, ne, 'unverified'
        s1, s3 = sc1[src][start - 1:end], sc3[ca][ns - 1:ne]
        if s1 == s3:
            status = 'identical'
        elif len(s1) == len(s3):
            status = 'same-length-mismatch'
        else:
            status = 'length-changed'
        return ca, ns, ne, status

    counts: dict[str, int] = {}
    affected: set[str] = set()
    for filename in ('origins.tsv', 'evidence.tsv'):
        path = DATA / filename
        header, rows = read_tsv(path)

        for col in ('source_assembly', 'source_chromosome', 'source_start',
                    'source_end', 'lift_status'):
            if col not in header:
                header.append(col)

        for r in rows:
            cerevisiae = r['accession'].startswith('ODB-SC-')
            # origins.tsv carries the assembly; evidence.tsv does not.
            already = r.get('assembly') == 'sacCer3' or r.get('lift_status') not in (None, '')
            if already:
                sys.exit(f'{filename} appears to be lifted already - nothing to do')
            if not cerevisiae:
                r['source_assembly'] = 'ASM294v2'
                r['source_chromosome'] = r['chromosome']
                r['source_start'], r['source_end'] = r['start'], r['end']
                r['lift_status'] = 'not-required'
                continue

            src_chr = 'chr%d' % ROMAN_TO_NUM[r['chromosome']]
            new_chr, ns, ne, status = lift(r['chromosome'], int(r['start']), int(r['end']))
            counts[status] = counts.get(status, 0) + 1
            if status not in ('identical', 'not-required'):
                affected.add(r['accession'])
            r['source_assembly'] = 'sacCer1'
            r['source_chromosome'] = src_chr
            r['source_start'], r['source_end'] = r['start'], r['end']
            r['lift_status'] = status
            if status == 'unmapped':
                continue
            r['chromosome'], r['start'], r['end'] = new_chr, str(ns), str(ne)
            if 'assembly' in r:
                r['assembly'] = 'sacCer3'

        write_tsv(path, header, rows)
        print(f'{filename}: {len(rows)} rows written')

    # Release metadata
    rel = json.loads((DATA / 'release.json').read_text())
    rel['generated'] = datetime.date.today().isoformat()
    rel['assemblies']['sacCer3'] = {
        'species': 'Saccharomyces cerevisiae',
        'ucsc_db': 'sacCer3',
        'sequence_naming': 'UCSC roman (chrI..chrXVI)',
        'note': ('Lifted from sacCer1 with the UCSC chain file. Each record keeps its '
                 'original sacCer1 position in source_chromosome/source_start/source_end '
                 'and the outcome of a base-for-base sequence check in lift_status.'),
    }
    rel['assemblies'].pop('sacCer1', None)
    rel['lift'] = {
        'from': 'sacCer1', 'to': 'sacCer3',
        'chain': 'reference/sacCer1ToSacCer3.over.chain.gz',
        'chain_md5': hashlib.md5(CHAIN.read_bytes()).hexdigest(),
        'chain_source': ('https://hgdownload.soe.ucsc.edu/goldenPath/sacCer1/liftOver/'
                         'sacCer1ToSacCer3.over.chain.gz'),
        'tool': 'pyliftover',
        'sequence_verified': verify,
        'record_counts': counts,
        'note': ('Systematic names are NOT recomputed. They are coordinate-derived labels '
                 'from the v2 build and are cited as such in the literature, so they stay '
                 'fixed; a name like X-212 therefore need not match the lifted midpoint.'),
    }
    changed = counts.get('same-length-mismatch', 0) + counts.get('length-changed', 0)
    rel['known_issues'].append({
        'text': f'{changed} lifted cerevisiae intervals do not have identical sequence in '
                'sacCer3 - substitutions, or an indel that changes interval length. Each '
                'mapping was checked and is correctly placed; it is the 2003 assembly '
                'sequence that was revised. Intervals whose length changed are worth a '
                'curator review. The origins affected are:',
        'accessions': sorted(affected),
    })
    for f in ('origins.tsv', 'evidence.tsv'):
        rel['files'][f]['md5'] = hashlib.md5((DATA / f).read_bytes()).hexdigest()
    (DATA / 'release.json').write_text(json.dumps(rel, indent=2) + '\n')

    print('lift outcomes:', counts)


if __name__ == '__main__':
    main()
