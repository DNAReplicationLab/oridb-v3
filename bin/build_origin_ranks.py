#!/usr/bin/env python3
"""Rank each origin against the others that carry the same measurement.

    python3 bin/build_origin_ranks.py          # rewrite public/data/origins.tsv
    python3 bin/build_origin_ranks.py --check  # fail if the stored ranks are stale

A value on its own does not tell a reader much: an OEM of 0.31 is a middling
origin and a Trep of 31 minutes is an early one, and nothing on the page says
so. The rank does, and it is a property of the whole release rather than of one
origin, so it is computed here, written into public/data/origins.tsv and
published with the rest of the release instead of being worked out again by
every page, export and API client.

Two rules fix what a rank means:

  population   An origin is ranked only against origins OF THE SAME SPECIES that
               carry the same measurement. Ranking an origin against sites the
               experiment never resolved would turn a silent assay into a low
               score, and ranking across species would compare two different
               experiments. Both populations are therefore smaller than the
               release, and the denominator travels with the rank so a reader
               can see how small.

  direction    Rank 1 is the most interesting end of each measurement: the
               HIGHEST firing efficiency, and the EARLIEST replication time.
               Ties share the better rank (competition ranking), so two origins
               at rank 4 are followed by rank 6.

This module is imported by bin/build_elements.py, which writes the values
themselves, so the two cannot drift apart; it also runs on its own against a
committed origins.tsv, which is how the ranks are refreshed without rebuilding
the element layer.
"""

from __future__ import annotations

import argparse
import json
import sys
from bisect import bisect_left, bisect_right
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'public' / 'data'

# value column -> (column prefix for the derived columns, which end ranks first)
MEASUREMENTS = (
    ('okseq_activity_oem', 'okseq_activity', 'high'),
    ('median_replication_time_composite_min', 'replication_time', 'low'),
)
SUFFIXES = ('rank', 'rank_of', 'percentile')

RANK_DOC = {
    'description': 'Where this origin sits among the origins that carry the same '
                   'measurement. Derived from the value columns by '
                   'bin/build_origin_ranks.py; no new measurement is involved.',
    'population': 'Origins OF THE SAME SPECIES that carry a value for that measurement, '
                  'which is fewer than the origins in the release. An origin the assay '
                  'did not resolve is NOT ranked last - it is not ranked at all, and its '
                  'rank columns are empty.',
    'direction': 'Rank 1 is the highest firing efficiency, and the earliest replication '
                 'time. Ties share the better rank, so two origins at rank 4 are '
                 'followed by rank 6.',
    'columns': {
        'okseq_activity_rank': 'Rank by firing efficiency, 1 = most efficient.',
        'okseq_activity_rank_of': 'Size of the population that rank is out of.',
        'okseq_activity_percentile': 'That rank as a percentage of the population, rounded '
                                     'up to a whole percent: 13 means this origin is among '
                                     'the top 13% for firing efficiency.',
        'replication_time_rank': 'Rank by median replication time, 1 = earliest.',
        'replication_time_rank_of': 'Size of the population that rank is out of.',
        'replication_time_percentile': 'That rank as a percentage of the population, '
                                       'rounded up to a whole percent: 13 means this '
                                       'origin is among the earliest 13%.',
    },
}


def rank_columns() -> list[str]:
    return [f'{prefix}_{s}' for _, prefix, _ in MEASUREMENTS for s in SUFFIXES]


def apply_ranks(header: list[str], rows: list[dict[str, str]]) -> dict[str, dict[str, int]]:
    """Fill the rank columns in `rows`, extending `header` where needed.

    Returns the population size per measurement per species, which the caller
    publishes so a reader does not have to count the rows to learn it.
    """
    counts: dict[str, dict[str, int]] = {}
    for value_col, prefix, better in MEASUREMENTS:
        if value_col not in header:
            sys.exit(f'origins.tsv has no {value_col} column: '
                     'run bin/build_elements.py before this step')
        for suffix in SUFFIXES:
            if f'{prefix}_{suffix}' not in header:
                header.append(f'{prefix}_{suffix}')

        population: dict[str, list[tuple[float, dict[str, str]]]] = {}
        for row in rows:
            for suffix in SUFFIXES:
                row[f'{prefix}_{suffix}'] = ''
            raw = (row.get(value_col) or '').strip()
            if raw == '':
                continue
            population.setdefault(row.get('species', ''), []).append((float(raw), row))

        counts[prefix] = {}
        for species, members in sorted(population.items()):
            n = len(members)
            counts[prefix][species] = n
            ordered = sorted(v for v, _ in members)
            for value, row in members:
                # Competition ranking: one more than the number of origins strictly
                # ahead of this one, so ties share a rank and the count of origins
                # ahead stays true whichever way the measurement reads.
                ahead = n - bisect_right(ordered, value) if better == 'high' \
                    else bisect_left(ordered, value)
                rank = ahead + 1
                row[f'{prefix}_rank'] = str(rank)
                row[f'{prefix}_rank_of'] = str(n)
                # Rounded UP, so the best origin of 283 reads "top 1%" rather than
                # "top 0%", and no origin is ever reported better than it is.
                row[f'{prefix}_percentile'] = str(-(-100 * rank // n))
    return counts


def document(release: dict, counts: dict[str, dict[str, int]]) -> None:
    """Record the rank columns and their populations in release.json."""
    block = dict(RANK_DOC)
    block['population_sizes'] = {p: dict(sorted(c.items())) for p, c in sorted(counts.items())}
    release.setdefault('per_origin_values', {})['ranks'] = block


def read_tsv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    lines = path.read_text(encoding='utf-8').rstrip('\n').split('\n')
    header = lines[0].split('\t')
    return header, [dict(zip(header, l.split('\t'))) for l in lines[1:]]


def write_tsv(path: Path, header: list[str], rows: list[dict[str, str]]) -> None:
    with path.open('w', encoding='utf-8') as fh:
        fh.write('\t'.join(header) + '\n')
        for r in rows:
            fh.write('\t'.join(str(r.get(c, '')).replace('\t', ' ').replace('\n', ' ')
                               for c in header) + '\n')


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--check', action='store_true',
                    help='report whether the stored ranks match the stored values, '
                         'and write nothing')
    args = ap.parse_args()

    path = DATA / 'origins.tsv'
    header, rows = read_tsv(path)
    before = [{c: r.get(c, '') for c in rank_columns()} for r in rows]
    counts = apply_ranks(header, rows)
    after = [{c: r.get(c, '') for c in rank_columns()} for r in rows]

    for prefix, per_species in sorted(counts.items()):
        for species, n in per_species.items():
            print(f'  {prefix:18s} {n:5d} {species} origin(s) ranked')

    stale = sum(1 for b, a in zip(before, after) if b != a)
    if args.check:
        if stale:
            sys.exit(f'FAIL  {stale} origin(s) carry a stale rank: '
                     'run bin/build_origin_ranks.py')
        print('ok    the stored ranks match the stored values')
        return

    write_tsv(path, header, rows)
    release_path = DATA / 'release.json'
    release = json.loads(release_path.read_text(encoding='utf-8'))
    document(release, counts)
    release_path.write_text(json.dumps(release, indent=4) + '\n', encoding='utf-8')
    print(f'  wrote {path.relative_to(ROOT)} ({stale} row(s) changed) and the release note')
    print('  now run: php bin/refresh_release_checksums.php')


if __name__ == '__main__':
    main()
