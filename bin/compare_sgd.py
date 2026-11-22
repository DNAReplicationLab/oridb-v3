#!/usr/bin/env python3
"""Compare OriDB's Confirmed cerevisiae intervals with SGD's ARS features.

OriDB intervals and the ARS features SGD and UCSC show for sacCer3 should
describe the same object - the fragment that was assayed - and mostly they do
not. The difference is systematic rather than biological, which is why it is
published as a known issue rather than quietly corrected: a reader comparing
the two sets at base resolution needs to know which way the offset runs.

This is step 5 of the pipeline and must run after build_tiers.py, because the
comparison is over the Confirmed tier and nothing before that step knows which
sites are Confirmed. It rewrites one entry in release.json, keyed by its id, so
the numbers on the Development page cannot drift from the data the way they did
when the paragraph was written out by hand in build_from_v2.py.

The SGD features are a committed snapshot, reference/sgd_saccer3_ars_features.bed,
so the comparison is reproducible without a network call; refresh it from UCSC
when SGD's annotation changes.

Usage:
    python3 bin/compare_sgd.py            # report and update release.json
    python3 bin/compare_sgd.py --dry-run  # report only
"""
import argparse
import collections
import csv
import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
DATA = ROOT / 'public' / 'data'
FEATURES = ROOT / 'reference' / 'sgd_saccer3_ars_features.bed'
ISSUE_ID = 'sgd-coordinates'


def load_features(path):
    """BED, 0-based half-open, to 1-based fully closed - OriDB's convention."""
    out = collections.defaultdict(list)
    with path.open() as fh:
        for line in fh:
            if line.startswith('#') or not line.strip():
                continue
            chrom, start, end, name = line.rstrip('\n').split('\t')[:4]
            out[chrom].append({'start': int(start) + 1, 'end': int(end), 'name': name})
    return out


def compare(origins, features):
    pairs = []
    matched = set()
    with_feature = 0
    for o in origins:
        s, e = int(o['start']), int(o['end'])
        hits = [f for f in features.get(o['chromosome'], []) if f['start'] <= e and f['end'] >= s]
        if hits:
            with_feature += 1
        for f in hits:
            pairs.append((o, f))
            matched.add((o['chromosome'], f['start'], f['end'], f['name']))

    same_end = [(o, f) for o, f in pairs if f['end'] == int(o['end'])]
    inside = [(o, f) for o, f in same_end if f['start'] > int(o['start'])]
    offsets = collections.Counter(f['start'] - int(o['start']) for o, f in inside)
    n_features = sum(len(v) for v in features.values())
    return {
        'n_confirmed': len(origins),
        'n_features': n_features,
        'n_with_feature': with_feature,
        'n_pairs': len(pairs),
        'n_same_end': len(same_end),
        'n_same_end_inside': len(inside),
        'n_offset_2': offsets[2],
        'n_offset_1': offsets[1],
        'n_without_feature': len(origins) - with_feature,
        'n_features_unmatched': n_features - len(matched),
    }


def text(c):
    """The known-issue paragraph. Every number is computed; the primer finding is
    not, and says so - it was established against the OriDB v2 source records,
    which are not in this repository."""
    return (
        'OriDB intervals and the ARS features SGD and UCSC show for sacCer3 should describe '
        'the same object - the assayed fragment - and mostly they differ in a way that looks '
        'like a coordinate error rather than biology. Of the {n_confirmed} Confirmed '
        'cerevisiae origins, {n_with_feature} overlap one of the {n_features} ARS features in '
        "UCSC's sgdOther table for sacCer3, in {n_pairs} origin-feature pairs. {n_same_end} of "
        'those pairs share an IDENTICAL end coordinate and differ only at the start, and in '
        '{n_same_end_inside} of them the SGD feature begins inside the OriDB interval: '
        '{n_offset_2} by exactly 2 bp and {n_offset_1} by 1 bp. When the v2 source records were '
        'examined, 194 of the 2 bp cases had an OriDB start that is the position of a PCR '
        'primer located in the genome by its own sequence, so the SGD feature begins two bases '
        'inside the primer that defined the fragment; that figure has not been recomputed '
        'against this release. None of this has been confirmed with SGD and it should be raised '
        'with them before either set is treated as authoritative at base resolution. Separately, '
        '{n_without_feature} Confirmed origins overlap no SGD ARS feature and '
        '{n_features_unmatched} SGD ARS features overlap no Confirmed origin.'
    ).format(**c)


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--dry-run', action='store_true', help='report without writing release.json')
    args = ap.parse_args(argv)

    if not FEATURES.exists():
        sys.exit(f'no SGD feature file at {FEATURES}')
    features = load_features(FEATURES)

    with (DATA / 'origins.tsv').open(newline='') as fh:
        origins = [o for o in csv.DictReader(fh, delimiter='\t')
                   if o['status'] == 'Confirmed' and 'cerevisiae' in o['species']]
    if not origins:
        sys.exit('no Confirmed cerevisiae origins: run build_tiers.py first')

    counts = compare(origins, features)
    body = text(counts)
    for k, v in counts.items():
        print(f'{k:24} {v}')
    print()
    print(body)
    if args.dry_run:
        return

    release = json.loads((DATA / 'release.json').read_text())
    issues = release.setdefault('known_issues', [])
    entry = next((i for i in issues if isinstance(i, dict) and i.get('id') == ISSUE_ID), None)
    if entry is None:
        entry = {'id': ISSUE_ID, 'accessions': []}
        issues.append(entry)
    entry['text'] = body
    entry['accessions'] = []
    release['sgd_comparison'] = {
        'features': str(FEATURES.relative_to(ROOT)),
        'feature_source': 'UCSC sgdOther for sacCer3, features named ARS*',
        'counts': counts,
    }
    (DATA / 'release.json').write_text(json.dumps(release, indent=2) + '\n', encoding='utf-8')
    print(f'\nrelease.json: known issue "{ISSUE_ID}" updated')


if __name__ == '__main__':
    main()
