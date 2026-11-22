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

ARS assays published since v2
-----------------------------
The dump stops in 2012. Individual ARS assays published after it are curated in
reference/curated_ars_assays.tsv, on the assembly they were published on, and are
attached here exactly as the v2 records are: a positive makes the site Confirmed, a
negative is recorded and raises the ars_negative flag. Two things differ from v2,
both deliberate:

  * a negative flags a site only when the tested fragment covers the WHOLE site
    interval. A failed sub-fragment is recorded against the site but is not a test
    of the origin. (v2 negatives flag on any overlap, as before.)
  * a row may name the site it belongs to (`site`), or be held back (`hold`), when
    overlap alone would attach it wrongly. A positive that reaches no site stops
    the build: it must be assigned, held, or given a site, never silently dropped.

Assay strength
--------------
Where a study reports how well the plasmid replicated, that grade is kept in the
`strength` column (strong, weak, very weak). Curated assays carry their own;
reference/ars_assay_strength.tsv supplies it for v2 records whose publication
reported one. Blank means the study reported no grade, not that the assay was weak.
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

REFERENCE = Path(__file__).resolve().parent.parent / 'reference'
CURATED_ASSAYS = REFERENCE / 'curated_ars_assays.tsv'
ASSAY_STRENGTH = REFERENCE / 'ars_assay_strength.tsv'
PUBMED_CACHE = REFERENCE / 'pubmed_cache.tsv'
STRENGTHS = ('strong', 'weak', 'very weak')
POSITIVE, NEGATIVE = 'plasmid (ARS assay)', 'plasmid (ARS assay, negative)'


def read_reference(path: Path) -> list[dict[str, str]]:
    """A committed reference table; an absent file is an empty table."""
    if not path.exists():
        return []
    lines = path.read_text(encoding='utf-8').rstrip('\n').split('\n')
    header = lines[0].split('\t')
    return [dict(zip(header, ln.split('\t'))) for ln in lines[1:] if ln.strip()]


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

    # One sc_cloned_no_ori record (chrXII 778-1,006,599) is 1,005,821 bp wide and
    # cannot be a cloned fragment; the other 224 have a median width of 4,685 bp.
    # Left in, it would mark every origin on chrXII as ARS-negative. It is dropped
    # here and reported as a known issue rather than silently corrected.
    MAX_FRAGMENT_BP = 50_000

    tables = {name: load(sql, name) for name in
              ('sc_ori', 'sp_ori', 'sc_cloned_ori', 'sc_cloned_no_ori', 'sc_2D_gel',
               'sp_2D_gel', 'local_pubmed')}

    oversized = [r for r in tables['sc_cloned_no_ori']
                 if int(r['end']) - int(r['start']) + 1 > MAX_FRAGMENT_BP]
    tables['sc_cloned_no_ori'] = [r for r in tables['sc_cloned_no_ori'] if r not in oversized]

    # Accessions are minted over the FULL v2 origin set in genome order, so that
    # adding the Likely and Dubious tiers later does not renumber anything.
    accession = {}
    for sp, key in (('sc', 'sc_ori'), ('sp', 'sp_ori')):
        ordered = sorted(tables[key], key=lambda r: (int(r['chr']), int(r['start']), int(r['end'])))
        for i, row in enumerate(ordered, start=1):
            accession[(sp, row['ori_id'])] = f'ODB-{sp.upper()}-{i:04d}'

    # ---- ARS assays published since v2, and assay strength ------------------
    roman_to_num = {'chr' + r: i for i, r in enumerate(ROMAN) if r}
    sc_accessions = {acc for (sp, _), acc in accession.items() if sp == 'sc'}
    curated = []
    for c in read_reference(CURATED_ASSAYS):
        label = f"{c['source']}:{c['source_id']}"
        if c['assembly'] != ASSEMBLY['sc'][1]:
            sys.exit(f"{CURATED_ASSAYS.name} {label}: assembly {c['assembly']!r}, expected "
                     f"{ASSEMBLY['sc'][1]} - curated assays are lifted with the v2 records")
        if (c['result'] not in ('positive', 'negative')
                or c['strength'] not in ('',) + STRENGTHS
                or (c['result'] == 'negative' and c['strength'])):
            sys.exit(f"{CURATED_ASSAYS.name} {label}: bad result/strength "
                     f"{c['result']!r}/{c['strength']!r}")
        sites = [a for a in c['site'].split(';') if a]
        unknown = [a for a in sites if a not in sc_accessions]
        if unknown:
            sys.exit(f"{CURATED_ASSAYS.name} {label}: no such site {unknown}")
        curated.append(dict(c, label=label, chr=roman_to_num[c['chromosome']],
                            start=int(c['start']), end=int(c['end']), sites=sites,
                            held=c['hold'] == 'yes', attached=[]))
    if len({c['label'] for c in curated}) != len(curated):
        sys.exit(f'{CURATED_ASSAYS.name}: duplicate source/source_id')

    def curated_hits(acc: str, chrom: int, start: int, end: int, result: str) -> list[dict]:
        """Curated assays that belong to this site: named for it, or overlapping it."""
        out = []
        for c in curated:
            if c['result'] != result or c['held']:
                continue
            if (acc in c['sites'] if c['sites'] else
                    c['chr'] == chrom and c['start'] <= end and c['end'] >= start):
                out.append(c)
        return out

    strength_of = {}
    for g in read_reference(ASSAY_STRENGTH):
        if g['strength'] not in STRENGTHS:
            sys.exit(f"{ASSAY_STRENGTH.name}: bad strength {g['strength']!r}")
        strength_of[(g['source_table'], g['source_id'])] = g
    strength_used = set()

    origins, evidence = [], []
    exceptions = []
    curated_confirmed, retested_negative = [], []
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
                    pmid = '' if pmid in ('0', 'NULL', '') else pmid
                    # The grade the publication reported for this very record. The
                    # annotation repeats the record's position and citation so that
                    # a renumbered or re-cited v2 row cannot pick up another's grade.
                    grade = strength_of.get((table, str(r['id'])))
                    if grade is not None:
                        if (grade['chromosome'], grade['start'], grade['end'], grade['pmid']) != (
                                seqname(sp, int(r['chr'])), str(r['start']), str(r['end']), pmid):
                            sys.exit(f"{ASSAY_STRENGTH.name}: {table}:{r['id']} does not match "
                                     'the v2 record it annotates')
                        strength_used.add((table, str(r['id'])))
                    records.append(dict(
                        accession=acc, method=method, chromosome=seqname(sp, int(r['chr'])),
                        start=r['start'], end=r['end'],
                        detail=unescape(str(r.get(detail_col, '') or '')),
                        strength=grade['strength'] if grade else '',
                        pmid=pmid,
                        source_table=table, source_id=r['id']))
            from_v2 = bool(methods)
            for c in (curated_hits(acc, chrom, start, end, 'positive') if sp == 'sc' else []):
                if POSITIVE not in methods:
                    methods.insert(0, POSITIVE)
                records.append(dict(
                    accession=acc, method=POSITIVE, chromosome=c['chromosome'],
                    start=str(c['start']), end=str(c['end']), detail='+',
                    strength=c['strength'], pmid=c['pmid'],
                    source_table=c['source'], source_id=c['source_id']))
                c['attached'].append(acc)
            if methods and not from_v2:
                curated_confirmed.append(acc)
            # A fragment that was cloned and did NOT support replication is a result,
            # not an absence of one. It is recorded as evidence and raised as a flag on
            # the site; it never sets the tier, because a site can carry a positive and
            # a negative ARS assay at once (ARS735 and ARS302 do).
            negatives = overlaps(tables['sc_cloned_no_ori'], chrom, start, end) if sp == 'sc' else []
            for r in negatives:
                pmid = str(r.get('pubmed_id', '')).strip()
                records.append(dict(
                    accession=acc, method=NEGATIVE,
                    chromosome=seqname(sp, int(r['chr'])), start=r['start'], end=r['end'],
                    detail='no autonomous replication', strength='',
                    pmid='' if pmid in ('0', 'NULL', '') else pmid,
                    source_table='sc_cloned_no_ori', source_id=r['id']))
            # A curated negative is recorded against every site it belongs to, but
            # it only speaks for the site if the fragment tested covers all of it:
            # a 32 bp ACS-only construct that failed is not a failed origin.
            flagging = []
            for c in (curated_hits(acc, chrom, start, end, 'negative') if sp == 'sc' else []):
                whole = c['chr'] == chrom and c['start'] <= start and c['end'] >= end
                records.append(dict(
                    accession=acc, method=NEGATIVE, chromosome=c['chromosome'],
                    start=str(c['start']), end=str(c['end']),
                    detail='no autonomous replication' if whole else
                           'no autonomous replication; the fragment covers only part of this site',
                    strength='', pmid=c['pmid'],
                    source_table=c['source'], source_id=c['source_id']))
                c['attached'].append(acc)
                if whole:
                    flagging.append(c['label'])
            if flagging and methods:
                retested_negative.append(acc)
            if o['status'] != 'Confirmed' and from_v2:
                exceptions.append(f"{ori_key} {o['ori_id']} ({o['status']})")
            evidence.extend(records)
            origins.append(dict(
                accession=acc, species=ASSEMBLY[sp][0], assembly=ASSEMBLY[sp][1],
                chromosome=seqname(sp, chrom), start=str(start), end=str(end),
                name=unescape(o['name']), systematic=systematic(chrom, start, end),
                other_names=unescape(o['othernames']),
                # The tier is derived in bin/build_tiers.py from the evidence matrix.
                # Direct experimental evidence is the one tier this step can settle.
                status='Confirmed' if methods else 'Unassigned',
                v2_status=o['status'],
                ars_negative='yes' if negatives or flagging else 'no',
                ars_negative_fragments=';'.join(
                    [f"sc_cloned_no_ori:{r['id']}" for r in negatives] + flagging),
                evidence=';'.join(methods), legacy_id=f"{ori_key}:{o['ori_id']}"))

    # Nothing curated may fall through. A positive that reached no site has to be
    # assigned, held or given a site; an annotation that matched no record is stale.
    stray = [c['label'] for c in curated
             if c['result'] == 'positive' and not c['held'] and not c['attached']]
    if stray:
        sys.exit(f'{CURATED_ASSAYS.name}: positive assay(s) attached to no site: {stray} - '
                 'set `site` or `hold`')
    stale = sorted(f'{t}:{i}' for t, i in set(strength_of) - strength_used)
    if stale:
        sys.exit(f'{ASSAY_STRENGTH.name}: no v2 evidence record for {stale}')
    held = [c for c in curated if c['held']]

    cited = sorted({e['pmid'] for e in evidence if e['pmid']})
    by_pmid = {str(r['pubmed_id']): r for r in tables['local_pubmed']}
    # v2's cached PubMed table stops in 2012; later studies come from the committed cache.
    cache = {r['pmid']: r for r in read_reference(PUBMED_CACHE)}
    studies = []
    for pmid in cited:
        if pmid not in by_pmid and pmid in cache:
            studies.append({k: cache[pmid][k] for k in
                            ('pmid', 'doi', 'year', 'journal', 'first_author',
                             'n_authors', 'title')})
            continue
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

    # Every fragment that was cloned and failed to support replication, whether or not
    # it contains a known origin. 76 of them contain none: they are assay results about
    # an interval, so they are published as such rather than invented as origins.
    refuted = [dict(fragment_id=f"sc_cloned_no_ori:{r['id']}",
                    assembly=ASSEMBLY['sc'][1],
                    chromosome=seqname('sc', int(r['chr'])), start=r['start'], end=r['end'],
                    name=unescape(str(r['name'] or '')),
                    other_names=unescape(str(r['othernames'] or '')),
                    result='no autonomous replication',
                    pmid='' if str(r.get('pubmed_id', '')).strip() in ('0', 'NULL', '') else
                         str(r['pubmed_id']).strip())
               for r in sorted(tables['sc_cloned_no_ori'],
                               key=lambda r: (int(r['chr']), int(r['start'])))]
    # Curated negatives join them, each in full and whether or not a site holds it.
    refuted += [dict(fragment_id=c['label'], assembly=c['assembly'],
                     chromosome=c['chromosome'], start=str(c['start']), end=str(c['end']),
                     name='', other_names='', result='no autonomous replication',
                     pmid=c['pmid'])
                for c in curated if c['result'] == 'negative' and not c['held']]
    # Stable, so v2 fragments keep the order they have always been published in.
    refuted.sort(key=lambda f: (roman_to_num[f['chromosome']], int(f['start'])))

    write_tsv('origins.tsv', origins)
    write_tsv('refuted_fragments.tsv', refuted)
    write_tsv('evidence.tsv', evidence)
    write_tsv('studies.tsv', studies)

    def md5(name: str) -> str:
        return hashlib.md5((data_dir / name).read_bytes()).hexdigest()

    release = {
        'release': '3.0.0-trial.1',
        'generated': datetime.date.today().isoformat(),
        'description': 'OriDB v3.0: every origin OriDB v2 held, carried forward with its '
                       'evidence records, with ARS assays published since added, and '
                       'with the tier recomputed from modern data.',
        'inclusion_rule': 'An origin is Confirmed if its interval overlaps at least one '
                          'plasmid (ARS) assay positive or one 2D gel electrophoresis '
                          'record, whether that record comes from OriDB v2 or from the '
                          'assays curated since. The remaining tiers are set by '
                          'bin/build_tiers.py from the evidence matrix; see '
                          'docs/tier-rebuild.md.',
        # Counted here so that no page or document has to hard-code them.
        'curated_assays': {
            'file': 'reference/' + CURATED_ASSAYS.name,
            'n': len(curated),
            'n_positive': sum(1 for c in curated if c['result'] == 'positive'),
            'n_negative': sum(1 for c in curated if c['result'] == 'negative'),
            'n_attached_to_a_site': sum(1 for c in curated if c['attached']),
            'n_negative_without_a_site': sum(1 for c in curated if c['result'] == 'negative'
                                             and not c['attached']),
            'held': [c['label'] for c in held],
            'sites_confirmed': sorted(curated_confirmed),
            'sites_retested_negative': sorted(retested_negative),
            'strength': {'file': 'reference/' + ASSAY_STRENGTH.name,
                         'values': list(STRENGTHS),
                         'n_v2_records_annotated': len(strength_used),
                         'n_records_with_a_strength': sum(1 for e in evidence if e['strength'])},
        },
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
                  'studies.tsv': {'md5': md5('studies.tsv'), 'rows': len(studies)},
                  'refuted_fragments.tsv': {'md5': md5('refuted_fragments.tsv'),
                                            'rows': len(refuted)}},
        'provenance': {'source': 'nieduszy_oridb MySQL dump '
                                 '(OriDB v2.1.0 cerevisiae / v1.0.0 pombe)',
                       'source_tables': sorted(tables),
                       'curated_files': ['reference/' + CURATED_ASSAYS.name,
                                         'reference/' + ASSAY_STRENGTH.name]},
        'known_issues': [
            {'text': ', '.join(f"sc_cloned_no_ori:{r['id']} ({r['chr']}:{r['start']}-{r['end']}, "
                                f"{int(r['end']) - int(r['start']) + 1} bp)" for r in oversized)
                     + ' is too wide to be a cloned fragment - the other 224 records have a '
                       'median width of 4,685 bp - so it is excluded from the refuted layer. '
                       'Left in, it would mark every origin on that chromosome as '
                       'ARS-negative. It needs correction or withdrawal at source.',
             'accessions': []},
            {'text': 'v2 origins that overlap direct experimental evidence yet were not '
                     'Confirmed in v2, so v3 now promotes them: ' + ', '.join(exceptions)
                     + '. Each needs curator review against the source publication.',
             'accessions': []},
            {'text': str(sum(1 for e in evidence if not e['pmid']))
                     + ' evidence records carry no PubMed ID in v2 and need a citation '
                       'assigned. The origins they support are:',
             'accessions': sorted({e['accession'] for e in evidence if not e['pmid']})},
            {'text': str(len(retested_negative)) + ' Confirmed origins were re-tested on a fragment '
                     'covering the whole site and did not support plasmid replication. The '
                     'earlier positive and the later negative are both recorded, the site '
                     'carries the ARS-negative flag, and the tier is unchanged: whether such '
                     'a site should remain Confirmed has not been decided. The assays differ '
                     '(vector, host strain), and several of the earlier positives were '
                     'themselves graded weak or very weak. The origins are:',
             'accessions': sorted(retested_negative)},
            *([{'text': 'Held back from ' + CURATED_ASSAYS.name + ': '
                        + '; '.join(f"{c['label']} ({c['chromosome']}:{c['start']}-{c['end']} on "
                                    f"{c['assembly']}, {c['result']}"
                                    + (f", {c['strength']}" if c['strength'] else '')
                                    + f") - {c['note']}" for c in held),
                'accessions': []}] if held else []),
            # The OriDB-versus-SGD coordinate comparison used to be written out here as a
            # paragraph of fixed numbers, which went stale the moment the tiers were
            # rebuilt and the Confirmed set changed size. It is now computed by
            # bin/compare_sgd.py, which runs after build_tiers.py - the comparison is over
            # the Confirmed tier, and nothing at this point in the pipeline knows which
            # sites those are - and appends its own entry, keyed 'sgd-coordinates'.
        ],
    }
    (data_dir / 'release.json').write_text(json.dumps(release, indent=2) + '\n', encoding='utf-8')

    print(f'{len(origins)} origins, {len(evidence)} evidence records, {len(studies)} studies')
    print(f'curation exceptions: {exceptions}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
