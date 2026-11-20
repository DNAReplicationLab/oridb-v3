#!/usr/bin/env python3
"""Build the origin element layer (A and B2): public/data/elements.tsv.

Step 3 of the data pipeline, after build_from_v2.py and lift_to_saccer3.py.

    python3 bin/build_elements.py /path/to/nieduszy_oridb.sql --sacCer3 DIR

OriDB v2 holds four A-element tables, all registered in sc_elements_studies:

    sc_confirmed_ACS       68 rows   experimentally confirmed
    Nieduszynski2006_ACS  196 rows   predicted (published as proACS), PMID 16847347
    Eaton2010_ACS         253 rows   predicted, PMID 20351051
    Xu2006_ACS            505 rows   predicted (published as nimACS), PMID 17067396

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

This step has two further jobs, both of which touch files other than elements.tsv.

  studies.tsv   Every study the element layer cites must have a citation record.
                Those the dump's cached PubMed table predates - Reuter et al.
                2024, for one - are looked up in reference/pubmed_cache.tsv and,
                failing that, fetched from NCBI and written into that cache.

  origins.tsv   Two per-origin values from modern call sets committed in docs/:
                Ok-seq activity (the fraction of cells in which the origin fires)
                and a COMPOSITE median replication time, each with the distance
                to the call that supplied it. An origin whose nearest call is not
                unambiguously its own gets an empty value, never a zero. The rule
                and its justification are in docs/origin_activity_timing.md.

Elements are linked to origins by position, not by any v2 identifier: an element
is `contained` if it lies wholly inside a released origin, `overlapping` if it
straddles a boundary, and `unlinked` otherwise. Most unlinked elements belong to
Likely or Dubious origins, which this release does not yet carry; they are kept
so the layer is complete and will attach when those tiers are added.
"""

from __future__ import annotations

import argparse
import csv
import glob
import math
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
    'Nieduszynski2006_ACS': ('predicted', '16847347', 'sacCer1'),
    'Eaton2010_ACS':        ('predicted', '20351051', 'sacCer1'),
    'Xu2006_ACS':           ('predicted', '17067396', 'sacCer2'),
}
ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X',
         'XI', 'XII', 'XIII', 'XIV', 'XV', 'XVI']
PROXIMITY_LIMIT = 5000  # bp; builds differ by at most a few hundred bp

# --- ACS motif ------------------------------------------------------------
# v2 records an `offset` per confirmed element and per study in
# sc_elements_studies. It is a coordinate in the alignment frame of Chang,
# Theis & Newlon (2008, PMID 18573888) figure 6, in which every stored element
# begins at position -9. The core therefore starts at index 9 - offset within
# the stored sequence; that reproduces the best match to the canonical ACS for
# 62 of the 68 confirmed elements.
STUDY_OFFSET = {'Nieduszynski2006_ACS': 7, 'Eaton2010_ACS': 6, 'Xu2006_ACS': 6,
                'Reuter2024_A': 6}

# Reuter et al. 2024 (PMID 39181881, GSE240779) contributes two element classes.
# "ACS" names the MOTIF; the element itself is the A element, which is the
# functionally defined, ARS-essential element the motif matches. B2 is a second,
# distinct element with its own motif, lying 3' of the A element.
REUTER_PMID = '39181881'
REUTER_A = 'reuter2024_A_elements.tsv'
REUTER_B2 = 'reuter2024_B2_elements.tsv'
# Accessions are frozen: adding a source must never renumber an existing element.
ACCESSION_MAP = 'element_accessions.tsv'
B2_CORE_INDEX = 1
# Roman chromosome NAME -> number. Deliberately not called ROMAN: that name is
# already the number -> numeral list above, and shadowing it breaks anchoring.
CHR_NUM = {'chr' + n: i for i, n in enumerate(ROMAN) if n}
FRAME_ORIGIN = 9

# Stable key for the one known issue this step contributes to release.json.
ISSUE_ID = 'elements-unplaced'

# --- citations ------------------------------------------------------------
# studies.tsv is filled from the v2 dump's cached PubMed table, which stops at
# the papers v2 knew about: a study the element layer cites but v2 never saw -
# Reuter et al. 2024, for one - would otherwise be rendered as a bare table
# name. Any such PMID is looked up: first in a committed cache, then at NCBI.
# Fetched records are written back into the cache, so the next run - and a run
# with no network at all - reproduces this build without asking NCBI again.
PUBMED_CACHE = 'pubmed_cache.tsv'
EUTILS = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi'
STUDY_COLUMNS = ['pmid', 'doi', 'year', 'journal', 'first_author', 'n_authors', 'title']

# --- per-origin activity and replication time ------------------------------
# Two modern call sets, both committed so the build is reproducible offline.
# Each call carries the anchor point against which that dataset's positional
# error was measured in docs/study_resolution.csv, because the cap below is
# taken from that measurement and the two must mean the same thing:
#   Ok-seq  - the derivative peak (BED thickStart) of the initiation zone
#   timing  - the midpoint of the band within 1 min of the Trep trough
# Column 9 of each BED carries the value: raw OEM efficiency, and Trep at the
# summit in minutes.
OKSEQ_CALLS = 'mcguffee2013_okseq_initiation_sites.bed'
TREP_CALLS = 'muller2014_trep_peaks.bed'
# Cap on origin-to-call distance: the 75th percentile of that assay's measured
# positional error (docs/study_resolution.csv). Not a round number and not a
# guess - the median is the typical displacement of a true pairing and the p90
# is dominated by origins the assay does not detect at all, so the p75 is the
# distance within which a call still plausibly belongs to this origin.
OKSEQ_PMID = '23562327'  # McGuffee, Smith & Whitehouse 2013
TREP_PMID = '24089142'   # Muller et al. 2014
OKSEQ_CAP_BP = 1160    # GSE40696_Okseq_initiation, err_p75_bp
TREP_CAP_BP = 5520     # Muller2014_Trep_peaks, err_p75_bp

# Curation: four sc_confirmed_ACS records carry offset 8 where their study uses 7.
# All four reproduce their Nieduszynski 2006 (PMID 16847347) table S1 proACS
# sequence and position exactly, and across all 228 table S1 proACS the 11 bp core
# starts at index 2 of the 15-mer - offset 7 - as the best or tied-best consensus
# match in 221 cases. So these are transcription errors, not a different frame.
# The uncorrected value stays in the source_offset column of elements.tsv.
OFFSET_CORRECTIONS = {('sc_confirmed_ACS', '60'): '7',   # ODB-EL-0590, ARS1021
                      ('sc_confirmed_ACS', '63'): '7',   # ODB-EL-0929, ARS1526
                      ('sc_confirmed_ACS', '65'): '7',   # ODB-EL-0938, ARS1529.5
                      ('sc_confirmed_ACS', '66'): '7'}   # ODB-EL-1001, ARS1626.5

# Window taken forward, measured from the first base of the 11 bp core. Chosen
# by information content over the confirmed set rather than assumed: columns -4
# and below sit at background (~0.2 bits), -3 to -1 rise, the core runs +0..+10,
# +11..+13 carry residual signal, +14..+26 are background again, and +27..+29
# form a second peak - the WTW motif that Chang et al. place 17-19 bp from the
# ACS. -3..+29 is 33 columns, and is exactly the span the Eaton and Xu 33-mers
# already occupy under their declared offset of 6.
MOTIF_UP, MOTIF_CORE, MOTIF_DOWN = 3, 11, 19
MOTIF_WIDTH = MOTIF_UP + MOTIF_CORE + MOTIF_DOWN
ACS11 = 'WTTTAYRTTTW'
IUPAC = {'W': 'AT', 'Y': 'CT', 'R': 'AG', 'A': 'A', 'C': 'C', 'G': 'G', 'T': 'T'}
# Every confirmed element that can be placed in the assembly trains the matrix.
# Dropping weak-scoring ones would bias the matrix towards its own consensus.


def load_builder():
    """Reuse the dump parser from build_from_v2.py rather than duplicating it."""
    spec = importlib.util.spec_from_file_location('b', ROOT / 'bin' / 'build_from_v2.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def rc(s: str) -> str:
    return s.translate(str.maketrans('ACGT', 'TGCA'))[::-1]


def core_score(window: str) -> int:
    """How many of the 11 core bases satisfy the canonical ACS."""
    if len(window) < MOTIF_CORE:
        return -1
    return sum(1 for b, code in zip(window, ACS11) if b in IUPAC[code])


def motif_window(seqs: dict[str, str], chrom: str, start: int, end: int,
                 strand: str, core_i: int) -> str | None:
    """The 33 bp motif window on the element's own (T-rich) strand."""
    chrseq = seqs.get(chrom)
    if chrseq is None:
        return None
    if strand == '+':
        cs = start + core_i
        a, b = cs - MOTIF_UP, cs + MOTIF_CORE - 1 + MOTIF_DOWN
        if a < 1 or b > len(chrseq):
            return None
        w = chrseq[a - 1:b]
    else:
        ce = end - core_i
        a, b = ce - MOTIF_CORE + 1 - MOTIF_DOWN, ce + MOTIF_UP
        if a < 1 or b > len(chrseq):
            return None
        w = rc(chrseq[a - 1:b])
    return w if set(w) <= set('ACGT') and len(w) == MOTIF_WIDTH else None


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


def read_pubmed_cache(path: Path) -> dict[str, dict[str, str]]:
    if not path.exists():
        return {}
    return {r['pmid']: r for r in read_tsv(path)}


def fetch_pubmed(pmids: list[str]) -> dict[str, dict[str, str]]:
    """Citation metadata for PMIDs from NCBI E-utilities, in studies.tsv shape.

    Returns {} if NCBI cannot be reached, so a build without a network still
    completes; the caller reports what it could not resolve.
    """
    import urllib.parse
    import urllib.request
    import xml.etree.ElementTree as ET

    query = urllib.parse.urlencode({'db': 'pubmed', 'retmode': 'xml',
                                    'id': ','.join(pmids)})
    try:
        with urllib.request.urlopen(f'{EUTILS}?{query}', timeout=60) as fh:
            root = ET.fromstring(fh.read())
    except Exception as exc:                                  # pragma: no cover
        print(f'  WARNING: PubMed lookup failed ({exc})', file=sys.stderr)
        return {}

    out: dict[str, dict[str, str]] = {}
    for art in root.findall('.//PubmedArticle'):
        pmid = art.findtext('.//MedlineCitation/PMID') or ''
        if not pmid:
            continue
        # The article's own DOI only: ArticleIdList under PubmedData. A bare
        # .//ArticleId search also returns every DOI in the reference list.
        doi = ''
        for ident in art.findall('./PubmedData/ArticleIdList/ArticleId'):
            if ident.get('IdType') == 'doi':
                doi = (ident.text or '').strip()
                break
        pub = art.find('.//Journal/JournalIssue/PubDate')
        year = (pub.findtext('Year') if pub is not None else '') or ''
        if not year and pub is not None:
            year = (pub.findtext('MedlineDate') or '')[:4]
        authors = art.findall('.//AuthorList/Author')
        first = ''
        if authors:
            last = authors[0].findtext('LastName') or authors[0].findtext('CollectiveName') or ''
            initials = authors[0].findtext('Initials') or ''
            # v2 stored authors as "Theis, J F" - surname, then spaced initials.
            first = (f'{last}, ' + ' '.join(initials)) if initials else last
        out[pmid] = {
            'pmid': pmid,
            'doi': doi,
            'year': year,
            'journal': art.findtext('.//Journal/ISOAbbreviation') or
                       art.findtext('.//MedlineTA') or '',
            'first_author': first,
            'n_authors': str(len(authors)),
            'title': ''.join(art.find('.//ArticleTitle').itertext()).strip()
                     if art.find('.//ArticleTitle') is not None else '',
        }
    return out


def read_bed(path: Path) -> list[dict[str, str]]:
    """A BED8+ call set. Track lines and a `#chrom` header row are ignored."""
    calls = []
    for line in path.read_text(encoding='utf-8').split('\n'):
        if not line.strip() or line.startswith(('#', 'track', 'browser')):
            continue
        f = line.split('\t')
        calls.append({'chrom': f[0], 'start': int(f[1]), 'end': int(f[2]),
                      'name': f[3], 'thick': int(f[6]), 'value': f[8]})
    return calls


def assign_calls(origin_rows: list[dict[str, str]], calls: list[dict[str, str]],
                 anchor: str, cap_bp: int) -> tuple[dict[str, tuple[dict[str, str], int]],
                                                    dict[str, int]]:
    """Give each origin the value of its nearest call, or nothing.

    Distance is measured from the origin midpoint to the call's anchor point,
    the same way the positional error of these datasets was measured. A pairing
    is kept only if it is MUTUALLY nearest - the call is this origin's nearest
    call and this origin is that call's nearest origin - and the distance is
    within cap_bp. The mutual test is what stops a value being attributed from
    a call that in fact belongs to a neighbouring origin; the cap stops a value
    being carried across a gap where the assay simply detected nothing.
    """
    def point(call: dict[str, str]) -> int:
        return call['thick'] if anchor == 'thick' else (call['start'] + call['end']) // 2

    calls_by_chr: dict[str, list[dict[str, str]]] = {}
    for call in calls:
        calls_by_chr.setdefault(call['chrom'], []).append(call)
    origins_by_chr: dict[str, list[dict[str, str]]] = {}
    for orow in origin_rows:
        origins_by_chr.setdefault(orow['chromosome'], []).append(orow)

    # each call's nearest origin
    nearest_origin = {}
    for call in calls:
        cands = origins_by_chr.get(call['chrom'], [])
        if cands:
            nearest_origin[call['name']] = min(
                cands, key=lambda orow: abs(midpoint(orow) - point(call)))['accession']

    hits: dict[str, tuple[dict[str, str], int]] = {}
    tally = {'assigned': 0, 'rejected_by_cap': 0, 'rejected_by_mutual_test': 0,
             'rejected_by_both': 0, 'no_call_on_sequence': 0}
    for orow in origin_rows:
        cands = calls_by_chr.get(orow['chromosome'], [])
        if not cands:
            tally['no_call_on_sequence'] += 1
            continue
        call = min(cands, key=lambda c: abs(midpoint(orow) - point(c)))
        dist = abs(midpoint(orow) - point(call))
        mutual = nearest_origin.get(call['name']) == orow['accession']
        within = dist <= cap_bp
        if mutual and within:
            hits[orow['accession']] = (call, dist)
            tally['assigned'] += 1
        elif not mutual and not within:
            tally['rejected_by_both'] += 1
        elif not mutual:
            tally['rejected_by_mutual_test'] += 1
        else:
            tally['rejected_by_cap'] += 1
    return hits, tally


def midpoint(origin_row: dict[str, str]) -> int:
    return (int(origin_row['start']) + int(origin_row['end'])) // 2


# Logo geometry, in CSS pixels. Published in release.json so that the element
# alignment on an origin page can put its columns under the logo's columns
# without either side hard-coding the other's numbers.
LOGO_COL_W = 18
LOGO_PAD_L = 62
# Logo y-axis ceiling, in bits relative to the sacCer3 base composition.
LOGO_MAX_BITS = 2.0


def write_logo(path: Path, freqs: list[dict[str, float]], info: list[float],
               bases: str) -> None:
    """A sequence logo as plain SVG: one stacked column per position, letter
    height proportional to its share of that column's information content."""
    colour = {'A': '#1a7f4b', 'C': '#0b5fa5', 'G': '#d08b00', 'T': '#c0392b'}
    cw, h, pad_l, pad_b = LOGO_COL_W, 90, LOGO_PAD_L, 18
    width = pad_l + cw * len(freqs) + 6
    out = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{h + pad_b + 14}" '
           f'viewBox="0 0 {width} {h + pad_b + 14}" role="img" '
           'aria-label="Sequence logo of the OriDB ACS motif">',
           '<style>text{font-family:ui-monospace,Menlo,monospace;font-weight:700}'
           '.ax{font-weight:400;font-size:9px;fill:#5b6671}</style>',
           f'<line x1="{pad_l - 4}" y1="{h}" x2="{width - 4}" y2="{h}" stroke="#d8dee4"/>',
           f'<text class="ax" x="{pad_l - 6}" y="12" text-anchor="end">{LOGO_MAX_BITS:g} bits</text>',
           f'<text class="ax" x="{pad_l - 6}" y="23" text-anchor="end">vs genome</text>',
           f'<text class="ax" x="{pad_l - 6}" y="{h}" text-anchor="end">0</text>']
    for i, (f, ic) in enumerate(zip(freqs, info)):
        x = pad_l + i * cw
        y = h
        for b in sorted(bases, key=lambda b: f[b]):
            bh = (h - 10) * (f[b] * max(ic, 0) / LOGO_MAX_BITS)
            if bh < 0.6:
                continue
            y -= bh
            out.append(f'<g transform="translate({x},{y + bh}) scale({cw / 10:.3f},{bh / 10:.3f})">'
                       f'<text x="0" y="0" font-size="10" fill="{colour[b]}">{b}</text></g>')
        if (i + 1) % 5 == 0 or i == 0:
            out.append(f'<text class="ax" x="{x + 2}" y="{h + 12}">{i + 1}</text>')
    out.append('</svg>')
    path.write_text('\n'.join(out) + '\n')


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('dump', help='path to nieduszy_oridb.sql')
    ap.add_argument('--sacCer3', required=True, help='directory of sacCer3 chr*.fa')
    args = ap.parse_args()

    v2 = load_builder()
    sql = v2.read_dump(Path(args.dump))
    sc3 = load_fasta_dir(args.sacCer3)
    nuclear = {k: v for k, v in sc3.items() if k != 'chrM'}

    # ---- collect the four element tables -------------------------------------
    records = []
    for table, (kind, pmid, src_asm) in TABLES.items():
        for row in v2.load(sql, table):
            seq_field = 'sequence' if 'sequence' in row else 'seq'
            seq = v2.unescape(str(row.get(seq_field, ''))).lstrip('.').upper()
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
                'source_name': v2.unescape(str(row.get('name', ''))),
                'score': str(row.get('score', '')).strip(),
                # v2 records an integer per confirmed element in an `offset` column.
                # Its meaning is not documented in the schema, so it is carried
                # through unchanged rather than interpreted.
                'source_offset': str(row.get('offset', '') or '').strip(),
                'chrom_num': chrom,
                'element_class': 'A',
            })

    # ---- Reuter et al. 2024: A elements and B2 elements -----------------------
    for cls, fname in (('A', REUTER_A), ('B2', REUTER_B2)):
        path = ROOT / 'reference' / fname
        with path.open(newline='') as fh:
            for i, row in enumerate(csv.DictReader(fh, delimiter='\t'), start=1):
                seq = (row.get('sequence') or '').upper()
                if not re.fullmatch(r'[ACGT]+', seq or ''):
                    continue
                records.append({
                    'sequence': seq,
                    # Keep the agreed vocabulary: element_type is evidence
                    # (confirmed | predicted); the class lives in element_class.
                    'element_type': ((row.get('element_type') or '').strip()
                                     if (row.get('element_type') or '').strip()
                                     in ('confirmed', 'predicted') else 'predicted'),
                    'element_class': cls,
                    'study_pmid': REUTER_PMID,
                    'source_table': 'Reuter2024_' + cls,
                    'source_id': str(i),
                    'source_assembly': 'sacCer3',
                    'source_chromosome': row['chromosome'],
                    'source_start': row['start'], 'source_end': row['end'],
                    'source_strand': (row.get('strand') or '').strip(),
                    'source_name': (row.get('source_name') or '').strip(),
                    'score': '',
                    'source_offset': '',
                    'chrom_num': CHR_NUM[row['chromosome']],
                    # B2 carries the 33 bp window the published interval sits in;
                    # it is checked against the genome below rather than trusted.
                    'given_window': (row.get('motif_window') or '').strip().upper(),
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
    # Accessions are frozen by (source_table, source_id) in reference/element_accessions.tsv.
    # A new source appends after the highest existing number; nothing is renumbered,
    # so a published ODB-EL accession keeps meaning the same element for ever.
    frozen = {}
    acc_path = ROOT / 'reference' / ACCESSION_MAP
    if acc_path.exists():
        with acc_path.open(newline='') as fh:
            for row in csv.DictReader(fh, delimiter='\t'):
                frozen[(row['source_table'], row['source_id'])] = row['accession']
    next_n = max([int(a.rsplit('-', 1)[1]) for a in frozen.values()] or [0]) + 1
    for r in placed + unplaced:
        key = (r['source_table'], str(r['source_id']))
        if key in frozen:
            r['accession'] = frozen[key]
        else:
            r['accession'] = f'ODB-EL-{next_n:04d}'
            next_n += 1
        r['species'] = 'Saccharomyces cerevisiae'
        r['assembly'] = 'sacCer3' if r['start'] else ''
        r['length'] = str(len(r['sequence']))

    # ---- link to released origins by position --------------------------------
    origin_header = (DATA / 'origins.tsv').read_text(encoding='utf-8').split('\n')[0].split('\t')
    origin_rows = read_tsv(DATA / 'origins.tsv')
    origins = [o for o in origin_rows if o['assembly'] == 'sacCer3']
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

    # ---- anchor each element, take its motif window ---------------------------
    for r in placed + unplaced:
        off = OFFSET_CORRECTIONS.get((r['source_table'], r['source_id']),
                                     r['source_offset'])
        if off == '':
            off = STUDY_OFFSET.get(r['source_table'], '')
        r['acs_offset'] = str(off)
        r['core_index'] = str(FRAME_ORIGIN - int(off)) if off != '' else ''
        r['motif_window'] = ''
        r['core_match'] = ''
        r['published_start'] = ''
        r['published_end'] = ''
        if r['element_class'] == 'B2':
            # B2 has its own motif; the ACS core index does not apply. Take the
            # 33 bp window around the published interval and VERIFY it against
            # sacCer3 rather than trusting the supplied string.
            if r['start']:
                w = motif_window(nuclear, r['chromosome'], int(r['start']),
                                 int(r['end']), r['strand'], B2_CORE_INDEX)
                if w:
                    r['motif_window'] = w
                    r['published_start'] = str(MOTIF_UP - B2_CORE_INDEX + 1)
                    r['published_end'] = str(int(r['published_start']) + len(r['sequence']) - 1)
                    given = r.get('given_window') or ''
                    if given and given != w:
                        r['window_disagrees'] = '1'
            continue
        if r['start'] and r['core_index'] != '' and int(r['core_index']) >= 0:
            w = motif_window(nuclear, r['chromosome'], int(r['start']), int(r['end']),
                             r['strand'], int(r['core_index']))
            if w:
                r['motif_window'] = w
                r['core_match'] = str(core_score(w[MOTIF_UP:MOTIF_UP + MOTIF_CORE]))
                # Where this study's own published sequence sits in the window,
                # as 1-based column numbers. Elements are 10 to 33 bp, so the
                # span differs per record; the rest of the window is genomic
                # context this build added.
                first = MOTIF_UP - int(r['core_index']) + 1
                last = first + len(r['sequence']) - 1
                r['published_start'] = str(max(1, first))
                r['published_end'] = str(min(MOTIF_WIDTH, last))

    # ---- derive the matrix from the well-anchored confirmed elements ----------
    train = [r for r in placed
             if r['element_class'] == 'A' and r['element_type'] == 'confirmed'
             and r['motif_window']]
    excluded = [r['accession'] for r in placed + unplaced
                if r['element_class'] == 'A' and r['element_type'] == 'confirmed'
                and not r['motif_window']]
    bases = 'ACGT'
    background = {b: 0.0 for b in bases}
    total = 0
    for chrseq in nuclear.values():
        for b in bases:
            background[b] += chrseq.count(b)
        total += len(chrseq)
    background = {b: background[b] / total for b in bases}

    col_counts = [{b: 0.25 for b in bases} for _ in range(MOTIF_WIDTH)]
    for r in train:
        for i, b in enumerate(r['motif_window']):
            col_counts[i][b] += 1
    freqs, weights, info = [], [], []
    for col in col_counts:
        tot = sum(col.values())
        f = {b: col[b] / tot for b in bases}
        freqs.append(f)
        weights.append({b: math.log2(f[b] / background[b]) for b in bases})
        # Information content relative to the sacCer3 base composition, not to a
        # uniform background: the genome is 62% AT, so an all-T column is far less
        # surprising here than 2 bits. Logo heights and the reported total use this.
        info.append(sum(f[b] * math.log2(f[b] / background[b]) for b in bases))

    def consensus_letter(f: dict[str, float]) -> str:
        order = sorted(bases, key=lambda b: -f[b])
        if f[order[0]] >= 0.75:
            return order[0]
        pairs = {frozenset('AT'): 'W', frozenset('CG'): 'S', frozenset('AC'): 'M',
                 frozenset('GT'): 'K', frozenset('AG'): 'R', frozenset('CT'): 'Y'}
        if f[order[0]] + f[order[1]] >= 0.85:
            return pairs[frozenset(order[0] + order[1])]
        return 'n'
    consensus = ''.join(consensus_letter(f) for f in freqs)

    best = sum(max(w.values()) for w in weights)
    worst = sum(min(w.values()) for w in weights)
    for r in placed + unplaced:
        r['motif_score'] = ''
        r['motif_score_pct'] = ''
        if r['element_class'] != 'A':
            continue
        if r['motif_window']:
            sc = sum(weights[i][b] for i, b in enumerate(r['motif_window']))
            r['motif_score'] = f'{sc:.2f}'
            r['motif_score_pct'] = str(int(round(100 * (sc - worst) / (best - worst))))

    write_logo(ROOT / 'public' / 'assets' / 'acs_logo.svg', freqs, info, bases)

    # ---- the B2 motif: its own matrix, derived the same way ------------------
    # B2 is a different element with a different motif, so scoring it against the
    # ACS matrix would be meaningless. Every placed B2 trains its own matrix.
    b2 = [r for r in placed if r['element_class'] == 'B2' and r['motif_window']]
    b2_cols = [{b: 0.25 for b in bases} for _ in range(MOTIF_WIDTH)]
    for r in b2:
        for i, base in enumerate(r['motif_window']):
            b2_cols[i][base] += 1
    b2_freqs, b2_weights, b2_info = [], [], []
    for col in b2_cols:
        tot = sum(col.values())
        f = {b: col[b] / tot for b in bases}
        b2_freqs.append(f)
        b2_weights.append({b: math.log2(f[b] / background[b]) for b in bases})
        b2_info.append(sum(f[b] * math.log2(f[b] / background[b]) for b in bases))
    b2_consensus = ''.join(consensus_letter(f) for f in b2_freqs)
    b2_best = sum(max(w.values()) for w in b2_weights)
    b2_worst = sum(min(w.values()) for w in b2_weights)
    for r in placed + unplaced:
        if r['element_class'] != 'B2' or not r['motif_window']:
            continue
        sc = sum(b2_weights[i][base] for i, base in enumerate(r['motif_window']))
        r['motif_score'] = f'{sc:.2f}'
        r['motif_score_pct'] = str(int(round(100 * (sc - b2_worst) / (b2_best - b2_worst))))
    if b2:
        write_logo(ROOT / 'public' / 'assets' / 'b2_logo.svg', b2_freqs, b2_info, bases)

    header = ['accession', 'species', 'assembly', 'chromosome', 'start', 'end', 'strand',
              'sequence', 'length', 'element_class', 'element_type', 'study_pmid',
              'origin_accession',
              'relation', 'anchor_status', 'source_table', 'source_id', 'source_assembly',
              'source_chromosome', 'source_start', 'source_end', 'source_strand',
              'source_name', 'score', 'source_offset', 'acs_offset', 'core_index',
              'published_start', 'published_end',
              'core_match', 'motif_score', 'motif_score_pct', 'motif_window']
    write_tsv(DATA / 'elements.tsv', header, placed + unplaced)

    # ---- make sure every element study is cited in studies.tsv ---------------
    studies = read_tsv(DATA / 'studies.tsv')
    known = {s['pmid'] for s in studies}
    pm = {str(r['pubmed_id']): r for r in v2.load(sql, 'local_pubmed')}
    added = []
    # The two per-origin value datasets are cited on every origin page that
    # carries an activity or a replication time, so they need citation records
    # even though no element record points at them.
    cited = {r['study_pmid'] for r in records if r['study_pmid']} | {OKSEQ_PMID, TREP_PMID}
    for pmid in sorted(cited):
        if pmid and pmid not in known and pmid in pm:
            r = pm[pmid]
            known.add(pmid)
            authors = [a.strip() for a in v2.unescape(str(r['author_list'])).split(';') if a.strip()]
            studies.append({'pmid': pmid, 'doi': v2.unescape(str(r['doi'])),
                            'year': v2.unescape(str(r['date']))[:4],
                            'journal': v2.unescape(str(r['journal'])),
                            'first_author': authors[0] if authors else '',
                            'n_authors': str(len(authors)),
                            'title': v2.unescape(str(r['article_title']))})
            added.append(pmid)
    # Anything the element layer cites that v2's cached PubMed table never held
    # is resolved from the committed cache, and failing that from NCBI. Without
    # this a study renders as a raw table name, which is what PMID 39181881
    # (Reuter et al. 2024) did: it postdates the dump.
    cache_path = ROOT / 'reference' / PUBMED_CACHE
    cached = read_pubmed_cache(cache_path)
    outstanding = sorted(p for p in cited if p and p not in known)
    fetched: dict[str, dict[str, str]] = {}
    if outstanding:
        from_cache = [p for p in outstanding if p in cached]
        to_fetch = [p for p in outstanding if p not in cached]
        if to_fetch:
            fetched = fetch_pubmed(to_fetch)
            if fetched:
                merged = dict(cached)
                merged.update(fetched)
                write_tsv(cache_path, STUDY_COLUMNS,
                          [merged[p] for p in sorted(merged)])
        for pmid in outstanding:
            rec = fetched.get(pmid) or cached.get(pmid)
            if rec:
                studies.append({c: rec.get(c, '') for c in STUDY_COLUMNS})
                known.add(pmid)
                added.append(pmid)
        unresolved_pmids = [p for p in outstanding if p not in known]
        print(f'  citations: {len(from_cache)} from cache, {len(fetched)} fetched from '
              f'PubMed, {len(unresolved_pmids)} unresolved')
        if unresolved_pmids:
            print(f'  WARNING: no citation metadata for {unresolved_pmids} - the element '
                  'layer cites them but studies.tsv cannot name them', file=sys.stderr)
    if added:
        studies.sort(key=lambda s: s['pmid'])
        write_tsv(DATA / 'studies.tsv', list(studies[0]), studies)

    # ---- per-origin Ok-seq activity and replication time ---------------------
    # Both values are read off modern call sets and attached to an origin only
    # when the pairing is unambiguous (see assign_calls). An origin with no
    # qualifying call gets an EMPTY value, never a zero: for a firing assay,
    # no call means the origin was not detected firing, which is a real state -
    # it may be licensed and rarely activated - and is not a measured zero.
    okseq_calls = read_bed(ROOT / 'docs' / OKSEQ_CALLS)
    trep_calls = read_bed(ROOT / 'docs' / TREP_CALLS)
    okseq_hits, okseq_tally = assign_calls(origins, okseq_calls, 'thick', OKSEQ_CAP_BP)
    trep_hits, trep_tally = assign_calls(origins, trep_calls, 'mid', TREP_CAP_BP)
    value_columns = ['okseq_activity_oem', 'okseq_call', 'okseq_distance_bp',
                     'median_replication_time_composite_min',
                     'replication_time_call', 'replication_time_distance_bp']
    for column in value_columns:
        if column not in origin_header:
            origin_header.append(column)
    for orow in origin_rows:
        okseq_hit = okseq_hits.get(orow['accession'])
        trep_hit = trep_hits.get(orow['accession'])
        orow['okseq_activity_oem'] = okseq_hit[0]['value'] if okseq_hit else ''
        orow['okseq_call'] = okseq_hit[0]['name'] if okseq_hit else ''
        orow['okseq_distance_bp'] = str(okseq_hit[1]) if okseq_hit else ''
        orow['median_replication_time_composite_min'] = trep_hit[0]['value'] if trep_hit else ''
        orow['replication_time_call'] = trep_hit[0]['name'] if trep_hit else ''
        orow['replication_time_distance_bp'] = str(trep_hit[1]) if trep_hit else ''
    write_tsv(DATA / 'origins.tsv', origin_header, origin_rows)

    # ---- release metadata ----------------------------------------------------
    rel = json.loads((DATA / 'release.json').read_text())
    rel['acs_motif'] = {
        'width': MOTIF_WIDTH,
        'window': f'-{MOTIF_UP} to +{MOTIF_UP + MOTIF_CORE - 1 + MOTIF_DOWN - MOTIF_UP}',
        'core_columns': [MOTIF_UP + 1, MOTIF_UP + MOTIF_CORE],
        'consensus': consensus,
        'derivation': 'Position weight matrix built from the experimentally confirmed A '
                      'elements, extended into sacCer3 either side of the 11 bp core. The '
                      'window was chosen by information content, not assumed: it runs from '
                      '3 bp upstream of the core to 19 bp downstream, and includes the WTW '
                      'motif that Chang et al. (PMID 18573888) place 17-19 bp from the ACS, '
                      'which this matrix reproduces independently. Information content is '
                      'measured against the sacCer3 base composition, not a uniform '
                      'background, and the logo is drawn on the same scale.',
        'training_elements': len(train),
        'excluded_elements': excluded,
        'information_content_bits': round(sum(info), 2),
        'information_by_column': [round(v, 2) for v in info],
        'background': {b: round(background[b], 4) for b in bases},
        'logo': 'assets/acs_logo.svg',
        'logo_column_width': LOGO_COL_W,
        'logo_left_pad': LOGO_PAD_L,
        'applies_to_element_class': 'A',
    }
    if b2:
        rel['b2_motif'] = {
            'width': MOTIF_WIDTH,
            'applies_to_element_class': 'B2',
            'consensus': b2_consensus,
            'derivation': 'Position weight matrix built from the placed B2 elements of '
                          'Reuter et al. 2024 (PMID 39181881), extended into sacCer3 '
                          'around the published interval and measured against the sacCer3 '
                          'base composition, by the same recipe as the ACS matrix. B2 is '
                          'a distinct element from the A element and is scored against '
                          'this matrix, not against the ACS matrix.',
            'training_elements': len(b2),
            'information_content_bits': round(sum(b2_info), 2),
            'information_by_column': [round(v, 2) for v in b2_info],
            'background': {b: round(background[b], 4) for b in bases},
            'logo': 'assets/b2_logo.svg',
            'logo_column_width': LOGO_COL_W,
            'logo_left_pad': LOGO_PAD_L,
        }
    rel['elements'] = {
        'description': 'A and B2 elements from the four OriDB v2 element tables and '
                       'from Reuter et al. 2024, each located in sacCer3 by its stored '
                       'sequence rather than by its stored coordinates.',
        'studies': {t: {'element_type': k, 'pmid': p, 'source_assembly': a}
                    for t, (k, p, a) in TABLES.items()},
        'anchor_status_counts': counts,
        'link_counts': link_counts,
    }
    rel['per_origin_values'] = {
        'description': 'Two per-origin values read off modern genome-wide call sets: how '
                       'often an origin fires, and when the DNA at it is replicated. They '
                       'apply to Saccharomyces cerevisiae origins on sacCer3 only; S. pombe '
                       'rows carry no value.',
        'columns': {
            'okseq_activity_oem': {
                'meaning': 'Origin efficiency (OEM) of the Okazaki-fragment initiation zone '
                           'assigned to this origin: the fraction of cells in which the '
                           'origin fires.',
                'units': 'fraction of cells, 0 to 1',
                'caveat': 'Raw OEM; the deconvolution correction of the original paper is '
                          'not applied, so values near termination zones are under-estimates.',
            },
            'okseq_call': {'meaning': 'Identifier of the initiation zone the value came from.'},
            'okseq_distance_bp': {
                'meaning': 'Distance from the origin midpoint to that zone\'s derivative '
                           'peak, so a user can see how well supported the value is.'},
            'median_replication_time_composite_min': {
                'meaning': 'MEDIAN REPLICATION TIME at the timing peak assigned to this '
                           'origin - the time by which half the cells have replicated the '
                           'DNA there.',
                'units': 'minutes after release from alpha-factor arrest',
                'caveat': 'THIS IS NOT THE FIRING TIME of the origin. It is a composite of '
                          'the firing-time distribution of this origin and the '
                          'passive-replication-time distribution produced by forks from '
                          'neighbouring origins, and must be labelled as such wherever it '
                          'is shown.',
            },
            'replication_time_call': {'meaning': 'Identifier of the timing peak the value came from.'},
            'replication_time_distance_bp': {
                'meaning': 'Distance from the origin midpoint to the midpoint of that '
                           'timing peak.'},
        },
        'empty_is_not_zero': 'An origin with no qualifying call has an EMPTY value, never a '
                             'zero. Both are firing assays: no call means the origin was not '
                             'detected firing in that experiment, which is a real and '
                             'interesting state - it may be licensed but rarely activated - '
                             'and is not a measurement of zero activity or of a replication '
                             'time of zero.',
        'assignment_rule': {
            'summary': 'An origin takes the value of its nearest call only when the pairing '
                       'is mutually nearest and within a distance cap set by that assay\'s '
                       'measured positional error.',
            'distance': 'From the origin midpoint to the call\'s anchor point, which is the '
                        'same point against which that dataset\'s positional error was '
                        'measured in docs/study_resolution.csv: the derivative peak for an '
                        'Ok-seq initiation zone, and the midpoint of the 1-minute band for a '
                        'timing peak.',
            'mutual_nearest': 'The call must be the origin\'s nearest call AND the origin '
                              'must be that call\'s nearest origin. This is what stops a '
                              'value being attributed from a call that in fact belongs to a '
                              'neighbouring origin; it also means no call ever supplies two '
                              'origins.',
            'cap_basis': 'The 75th percentile of the assay\'s measured positional error '
                         '(docs/study_resolution.csv). The median is the typical displacement '
                         'of a true pairing and the p90 is dominated by origins the assay '
                         'does not detect at all, so the p75 is the distance within which a '
                         'call still plausibly belongs to this origin. Both caps are well '
                         'inside half the median spacing between neighbouring origins '
                         '(16,671 bp), so a capped call cannot reach past a neighbour.',
            'caps_bp': {'okseq': OKSEQ_CAP_BP, 'replication_time': TREP_CAP_BP},
            'documentation': 'docs/origin_activity_timing.md',
        },
        'datasets': {
            'okseq': {
                'calls': 'docs/' + OKSEQ_CALLS,
                'n_calls': len(okseq_calls),
                'dataset_id': 'mcguffee2013-saccer3',
                'title': 'Okazaki-fragment sequencing (McGuffee, Smith & Whitehouse 2013), '
                         're-analysed on sacCer3',
                'pmid': '23562327',
                'doi': '10.1016/j.molcel.2013.03.004',
                'archive_series': ['GSE40696'],
                'hub_track': 'sacCer3/mcguffee2013/okseq.initiation_sites.bb',
                'call_definition': 'maxima of the derivative of fork directionality, OEM > 0.05',
            },
            'replication_time': {
                'calls': 'docs/' + TREP_CALLS,
                'n_calls': len(trep_calls),
                'dataset_id': 'muller2014-saccer3',
                'title': 'The dynamics of genome replication using deep sequencing '
                         '(Muller et al. 2014), reprocessed on sacCer3',
                'pmid': '24089142',
                'doi': '10.1093/nar/gkt878',
                'archive_series': ['GSE42243', 'GSE48212'],
                'hub_track': 'sacCer3/muller2014/syncseq.trep.bw',
                'call_definition': 'local minima of the smoothed sync-seq Trep profile; the '
                                   'called interval is the band within 1 minute of the trough',
            },
        },
        'counts': {
            'cerevisiae_origins': len(origins),
            'okseq': okseq_tally,
            'replication_time': trep_tally,
        },
    }
    rel['files']['origins.tsv'] = {
        'md5': hashlib.md5((DATA / 'origins.tsv').read_bytes()).hexdigest(),
        'rows': len(origin_rows),
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
    # This step can be rerun on its own, so drop any copy a previous run left
    # before appending: an unconditional append duplicates the issue each time.
    rel['known_issues'] = [i for i in rel['known_issues']
                           if not (isinstance(i, dict) and i.get('id') == ISSUE_ID)]
    if unresolved:
        rel['known_issues'].append({
            'id': ISSUE_ID,
            'text': f'{unresolved} of {len(records)} origin elements could not be placed in '
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
        print(f'  studies.tsv: added PMIDs {sorted(added)}')
    print(f'origins.tsv: {len(origins)} cerevisiae origins annotated')
    print(f'  Ok-seq activity (cap {OKSEQ_CAP_BP} bp):      {okseq_tally}')
    print(f'  replication time (cap {TREP_CAP_BP} bp):     {trep_tally}')


if __name__ == '__main__':
    main()
