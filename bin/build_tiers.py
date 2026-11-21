#!/usr/bin/env python3
"""Derive the origin tier for every site, from the datasets in reference/datasets.tsv.

Step 4 of the pipeline, after build_from_v2.py, lift_to_saccer3.py and
build_elements.py. Reads the call sets committed under docs/, writes

  public/data/evidence_matrix.tsv   one row per site per dataset, supported or not
  public/data/origins.tsv           status, n_support, support_kinds filled in
  public/data/release.json          the tier block

The tier is DERIVED here and nowhere else. Nothing is stored that cannot be
recomputed from the matrix, so changing a threshold is a rerun, not a migration.

    Confirmed     direct experimental evidence of an origin at this site: a positive
                  plasmid (ARS) assay or a 2D gel. Set by build_from_v2.py from v2.
    Likely        no direct evidence, but at least two modern datasets support the
                  site and at least one of them measures LICENSING. The licensing
                  requirement is deliberate: two firing assays share a blind spot,
                  they both miss an origin that is licensed and rarely fires, so two
                  of them agreeing is weaker than one of each. Requiring one of each
                  would be worse still - it would make "likely" mean "active" and
                  write dormant origins out of the tier by definition.
    Unsupported   datasets cover this species, and fewer than that support the site.
    Not assessed  no dataset in the registry covers this species. The blank records
                  missing coverage, not a negative result.

A negative ARS assay never sets a tier. It is carried as the ars_negative flag from
build_from_v2.py, because refutation attaches to a tested interval rather than to an
origin: 76 of the 224 negative fragments contain no origin at all, and two contain an
origin that a positive assay confirms.
"""
from __future__ import annotations

import bisect
import collections
import hashlib
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'public' / 'data'
REGISTRY = ROOT / 'reference' / 'datasets.tsv'

# Two calls belong to the same novel site if their anchors are within this far.
# It is the largest support window in the registry: a cluster must be tight enough
# that every member could be supporting one origin.
CLUSTER_BP = 1160
# A novel site is published with an interval of at least this width, centred on its
# licensing calls, so that a single-base MCM call becomes a searchable interval.
MIN_NOVEL_WIDTH = 501


def read_tsv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    lines = path.read_text(encoding='utf-8').rstrip('\n').split('\n')
    header = lines[0].lstrip('#').split('\t')
    return header, [dict(zip(header, ln.split('\t'))) for ln in lines[1:]]


def write_tsv(path: Path, header: list[str], rows: list[dict[str, str]]) -> None:
    with path.open('w', encoding='utf-8') as fh:
        fh.write('\t'.join(header) + '\n')
        for r in rows:
            fh.write('\t'.join(str(r.get(c, '')).replace('\t', ' ') for c in header) + '\n')


def load_calls(spec: dict[str, str]) -> dict[str, list[tuple[int, str]]]:
    """Anchor position and call name per chromosome, sorted, after filtering."""
    path = ROOT / spec['calls_file']
    # BED with or without a leading '#' header line; without one, the standard
    # column order applies and extra columns are unnamed (none are needed).
    BED = ['chrom', 'start', 'end', 'name', 'score', 'strand', 'thickStart', 'thickEnd']
    lines = [ln for ln in path.read_text().rstrip('\n').split('\n')
             if ln.strip() and not ln.startswith('track')]
    if lines[0].startswith('#'):
        header = lines[0].lstrip('#').split('\t')
        body = lines[1:]
    else:
        header, body = BED, lines
    rows = [dict(zip(header, ln.split('\t'))) for ln in body]
    key, _, value = spec['calls_filter'].partition('=')
    rank_max = int(spec['rank_max']) if spec['rank_max'] else None
    out: dict[str, list[tuple[int, str]]] = collections.defaultdict(list)
    for r in rows:
        if key and r.get(key) != value:
            continue
        if rank_max is not None and int(r['rank']) > rank_max:
            continue
        start, end = int(r['start']), int(r['end'])          # BED, half-open
        anchor = int(r['thickStart']) + 1 if spec['anchor'] == 'thickStart' else (start + end) // 2
        out[r['chrom']].append((anchor, r['name']))
    for c in out:
        out[c].sort()
    return out


def nearest(calls: dict[str, list[tuple[int, str]]], chrom: str, start: int, end: int):
    """Nearest call to the interval, as (distance_to_interval, anchor, name)."""
    xs = calls.get(chrom)
    if not xs:
        return None
    pos = [x[0] for x in xs]
    i = bisect.bisect_left(pos, start)
    best = None
    for j in (i - 1, i, i + 1):
        if 0 <= j < len(xs):
            a, name = xs[j]
            d = 0 if start <= a <= end else min(abs(a - start), abs(a - end))
            if best is None or d < best[0]:
                best = (d, a, name)
    return best


def main() -> None:
    if not REGISTRY.exists():
        sys.exit(f'missing dataset registry: {REGISTRY}')
    _, registry = read_tsv(REGISTRY)
    header, origins = read_tsv(DATA / 'origins.tsv')

    # Every call set is on the current assembly. Refuse to score origins that are
    # not: an unlifted origins.tsv silently scores nothing and still writes a
    # complete-looking set of files.
    expected = {'Saccharomyces cerevisiae': 'sacCer3', 'Schizosaccharomyces pombe': 'ASM294v2'}
    wrong = sorted({(o['species'], o['assembly']) for o in origins
                    if o['assembly'] != expected.get(o['species'], o['assembly'])})
    if wrong:
        sys.exit('origins.tsv is not on the expected assembly: '
                 + '; '.join(f'{sp} on {asm}' for sp, asm in wrong)
                 + ' - run bin/lift_to_saccer3.py before this step')
    if 'lift_status' not in header:
        sys.exit('origins.tsv has no lift_status column: run bin/lift_to_saccer3.py first')
    for col in ('okseq_activity_oem', 'median_replication_time_composite_min'):
        if col not in header:
            sys.exit(f'origins.tsv has no {col} column: run bin/build_elements.py first')

    calls = {d['dataset_id']: load_calls(d) for d in registry}
    for d in registry:
        print(f"  {d['dataset_id']:26s} {sum(len(v) for v in calls[d['dataset_id']].values()):6d} calls"
              f"  ({d['measures']}, window {d['support_window_bp']} bp"
              f"{', rank <= ' + d['rank_max'] if d['rank_max'] else ''})")

    species_with_data = {d['species'] for d in registry if d['counts_towards_tier'] == 'yes'}
    matrix: list[dict[str, str]] = []
    claimed: dict[str, set[str]] = {d['dataset_id']: set() for d in registry}

    def assess(site: dict[str, str]) -> dict[str, tuple[bool, object]]:
        out = {}
        for d in registry:
            if d['species'] != site['species']:
                continue
            n = nearest(calls[d['dataset_id']], site['chromosome'],
                        int(site['start']), int(site['end']))
            ok = n is not None and n[0] <= int(d['support_window_bp'])
            out[d['dataset_id']] = (ok, n)
        return out

    for site in origins:
        for ds_id, (ok, n) in assess(site).items():
            spec = next(d for d in registry if d['dataset_id'] == ds_id)
            matrix.append(dict(
                accession=site['accession'], dataset_id=ds_id, measures=spec['measures'],
                counts_towards_tier=spec['counts_towards_tier'],
                supported='yes' if ok else 'no',
                call=n[2] if n else '', distance_bp=str(n[0]) if n else '',
                study_pmid=spec['study_pmid']))
            if ok:
                claimed[ds_id].add(n[2])

    # ---- novel sites: modern calls that support no site in the list ---------
    novel_clusters: list[dict] = []
    for species in sorted(species_with_data):
        pool = []
        for d in registry:
            if d['species'] != species or d['counts_towards_tier'] != 'yes':
                continue
            for chrom, xs in calls[d['dataset_id']].items():
                for anchor, name in xs:
                    if name not in claimed[d['dataset_id']]:
                        pool.append((chrom, anchor, d['dataset_id'], d['measures'], name))
        pool.sort(key=lambda t: (t[0], t[1]))
        cluster: list[tuple] = []
        def flush(cl):
            if not cl:
                return
            kinds = {c[3] for c in cl}
            if len({c[2] for c in cl}) >= 2 and 'licensing' in kinds:
                lic = [c[1] for c in cl if c[3] == 'licensing']
                lo, hi = min(lic), max(lic)
                if hi - lo + 1 < MIN_NOVEL_WIDTH:
                    mid = (lo + hi) // 2
                    lo, hi = mid - MIN_NOVEL_WIDTH // 2, mid + MIN_NOVEL_WIDTH // 2
                novel_clusters.append(dict(species=species, chromosome=cl[0][0],
                                           start=lo, end=hi, members=cl))
        for item in pool:
            if cluster and item[0] == cluster[-1][0] and item[1] - cluster[-1][1] <= CLUSTER_BP:
                cluster.append(item)
            else:
                flush(cluster)
                cluster = [item]
        flush(cluster)

    # Accessions continue the existing series in genome order among themselves;
    # nothing already published is renumbered.
    prefix = {'Saccharomyces cerevisiae': 'ODB-SC', 'Schizosaccharomyces pombe': 'ODB-SP'}
    next_num = {p: max((int(o['accession'].split('-')[-1]) for o in origins
                        if o['accession'].startswith(p)), default=0) + 1
                for p in prefix.values()}
    novel_rows = []
    for cl in sorted(novel_clusters, key=lambda c: (c['species'], c['chromosome'], c['start'])):
        p = prefix[cl['species']]
        acc = f'{p}-{next_num[p]:04d}'
        next_num[p] += 1
        row = {c: '' for c in header}
        row.update(accession=acc, species=cl['species'],
                   assembly=next(o['assembly'] for o in origins if o['species'] == cl['species']),
                   chromosome=cl['chromosome'], start=str(cl['start']), end=str(cl['end']),
                   name='', systematic='', other_names='', status='Likely',
                   v2_status='', ars_negative='no', ars_negative_fragments='',
                   evidence='', legacy_id='', lift_status='not-required',
                   source_assembly='', source_chromosome='', source_start='', source_end='')
        novel_rows.append(row)
    # Assessed the same way as every other site rather than from their own cluster
    # members, so that one run of this script and two runs of it agree exactly.
    for row in novel_rows:
        for ds_id, (ok, n) in assess(row).items():
            spec = next(d for d in registry if d['dataset_id'] == ds_id)
            matrix.append(dict(
                accession=row['accession'], dataset_id=ds_id, measures=spec['measures'],
                counts_towards_tier=spec['counts_towards_tier'],
                supported='yes' if ok else 'no',
                call=n[2] if n else '', distance_bp=str(n[0]) if n else '',
                study_pmid=spec['study_pmid']))
    origins.extend(novel_rows)

    # ---- the tier ------------------------------------------------------------
    support = collections.defaultdict(list)
    for m in matrix:
        if m['supported'] == 'yes' and m['counts_towards_tier'] == 'yes':
            support[m['accession']].append(m['measures'])
    for col in ('v2_status', 'ars_negative', 'ars_negative_fragments',
                'n_support', 'support_kinds'):
        if col not in header:
            header.append(col)
    counts = collections.Counter()
    for o in origins:
        kinds = support.get(o['accession'], [])
        o['n_support'] = str(len(kinds))
        o['support_kinds'] = ';'.join(sorted(set(kinds)))
        if o['status'] != 'Confirmed':
            if o['species'] not in species_with_data:
                o['status'] = 'Not assessed'
            elif len(kinds) >= 2 and 'licensing' in kinds:
                o['status'] = 'Likely'
            else:
                o['status'] = 'Unsupported'
        counts[(o['species'], o['status'])] += 1

    origins.sort(key=lambda o: (o['species'], o['chromosome'], int(o['start'])))
    # Sorted so that the file depends on the data and not on the order sites were
    # assessed in: a novel site is appended on the run that mints it and assessed
    # in place on every run after.
    matrix.sort(key=lambda m: (m['accession'], m['dataset_id']))
    write_tsv(DATA / 'origins.tsv', header, origins)
    write_tsv(DATA / 'evidence_matrix.tsv',
              ['accession', 'dataset_id', 'measures', 'counts_towards_tier', 'supported',
               'call', 'distance_bp', 'study_pmid'], matrix)

    novel_sites = [o for o in origins if not o['legacy_id']]

    # Counted here so that no page has to hard-code them.
    _, fragments = read_tsv(DATA / 'refuted_fragments.tsv')
    n_fragments = len(fragments)
    fragments_with_a_site = {f for o in origins if o.get('ars_negative') == 'yes'
                             for f in o['ars_negative_fragments'].split(';') if f}

    rel = json.loads((DATA / 'release.json').read_text())
    rel['tiers'] = {
        'rule': {
            'Confirmed': 'a positive plasmid (ARS) assay or a 2D gel record at this site',
            'Likely': 'at least two datasets support the site and at least one measures '
                      'licensing; firing assays share a blind spot, so two of them are not '
                      'enough, and requiring one of each would make the tier mean "active"',
            'Unsupported': 'datasets cover this species and fewer than that support the site',
            'Not assessed': 'no dataset in the registry covers this species yet',
        },
        'refutation': 'a negative ARS assay is carried as the ars_negative flag, never as a '
                      'tier: it describes a tested interval, and a site can hold a positive '
                      'and a negative assay at once',
        'datasets': [{k: d[k] for k in ('dataset_id', 'study_pmid', 'species', 'measures',
                                        'counts_towards_tier', 'support_window_bp', 'rank_max')}
                     for d in registry],
        # Every site with no v2 record behind it, not only the ones minted on this run,
        # so that re-running the step does not erase the ones it minted last time.
        'novel_sites': {'n': len(novel_sites),
                        'rule': 'a cluster of calls within %d bp from at least two datasets, '
                                'one of them licensing, matching no site carried from v2'
                                % CLUSTER_BP,
                        'accessions': [o['accession'] for o in novel_sites]},
        'counts': {f'{sp} | {st}': n for (sp, st), n in sorted(counts.items())},
        'refuted': {
            'n_fragments': n_fragments,
            'n_fragments_without_a_site': n_fragments - len(fragments_with_a_site),
            'n_sites_flagged': sum(1 for o in origins if o.get('ars_negative') == 'yes'),
        },
    }
    for name in ('origins.tsv', 'evidence_matrix.tsv'):
        rel['files'][name] = {'md5': hashlib.md5((DATA / name).read_bytes()).hexdigest(),
                              'rows': sum(1 for _ in (DATA / name).open()) - 1}
    (DATA / 'release.json').write_text(json.dumps(rel, indent=2) + '\n')

    print(f'  {len(origins)} sites, {len(novel_sites)} of them with no v2 record '
          f'({len(novel_rows)} minted on this run), {len(matrix)} matrix rows')
    for (sp, st), n in sorted(counts.items()):
        print(f'    {sp:28s} {st:14s} {n:5d}')


if __name__ == '__main__':
    main()
