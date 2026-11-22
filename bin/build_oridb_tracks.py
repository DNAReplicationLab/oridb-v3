#!/usr/bin/env python3
"""Render the OriDB records as browser tracks.

The site's own data - origin intervals, origin elements and the ARS-negative
fragments - are published as bigBed so that a reader looking at a genome-wide
profile can see OriDB's calls in the same window, and so that the embedded
viewer on an origin page has something to draw besides other people's data.

One assembly per run. Coordinates in the TSVs are 1-based and fully closed;
BED is 0-based half-open, so every start loses one. Nothing here is written by
hand that can be read from the release: the tier vocabulary, the colours and
the shading all come from the same place the website gets them.

Usage:
    python3 bin/build_oridb_tracks.py \
        --data-dir public/data --assembly sacCer3 \
        --chrom-sizes /path/to/oridb-data-hub/sacCer3.chrom.sizes \
        --out build/oridb_v3/sacCer3

Requires bedToBigBed (UCSC) on PATH.
"""
import argparse
import csv
import datetime
import hashlib
import json
import os
import subprocess
import sys

# Tier colours are the website's chip colours, so a track and a page cannot
# disagree about what Confirmed looks like. 'Not assessed' is white on the page,
# which is invisible against a browser background, so the chip's border colour
# is used instead. The score column shades the same order for browsers that
# colour by score rather than by itemRgb.
TIER_RGB = {
    'Confirmed':    '26,127,75',
    'Likely':       '47,125,155',
    'Unsupported':  '138,143,152',
    'Not assessed': '199,204,211',
}
TIER_SHADE = {'Confirmed': 1000, 'Likely': 700, 'Unsupported': 400, 'Not assessed': 200}

# Element classes are drawn in their own hues: the A element (the ACS match)
# and the B elements are different kinds of thing and should not read as one.
ELEMENT_RGB = {'A': '166,54,3', 'B2': '94,60,153', 'B3': '140,107,177'}
REFUTED_RGB = '178,34,34'

ORIGINS_AS = """table oridbOrigins
"OriDB %(release)s replication origins"
    (
    string  chrom;        "Reference sequence"
    uint    chromStart;   "Start, 0-based"
    uint    chromEnd;     "End, exclusive"
    string  name;         "OriDB accession"
    uint    score;        "Shaded by tier: Confirmed 1000 to Not assessed 200"
    char[1] strand;       "Not applicable to an origin"
    uint    thickStart;   "Same as chromStart"
    uint    thickEnd;     "Same as chromEnd"
    uint    reserved;     "Tier colour, as itemRgb"
    string  tier;         "Evidence tier"
    string  arsNegative;  "Inside a fragment that failed an ARS assay"
    uint    nSupport;     "Datasets that support this site"
    string  originName;   "Historical OriDB name, where one exists"
    string  okseqOem;     "Ok-seq firing efficiency (OEM), or '.' if unresolved"
    string  replTime;     "Median replication time in minutes, or '.' if unresolved"
    )
"""

ELEMENTS_AS = """table oridbElements
"OriDB %(release)s origin elements"
    (
    string  chrom;        "Reference sequence"
    uint    chromStart;   "Start, 0-based"
    uint    chromEnd;     "End, exclusive"
    string  name;         "OriDB element accession"
    uint    score;        "Motif score as a percentage of the matrix maximum, times 10"
    char[1] strand;       "Strand the element matches on"
    uint    thickStart;   "Same as chromStart"
    uint    thickEnd;     "Same as chromEnd"
    uint    reserved;     "Element-class colour, as itemRgb"
    string  elementClass; "A, B2 or B3"
    string  evidence;     "confirmed or predicted"
    string  origin;       "Accession of the origin this element belongs to"
    string  studyPmid;    "PubMed ID of the study that defined it"
    string  motifScore;   "Motif score as a percentage, or '.' where not scored"
    )
"""

REFUTED_AS = """table oridbRefuted
"OriDB %(release)s fragments that failed an ARS assay"
    (
    string  chrom;        "Reference sequence"
    uint    chromStart;   "Start, 0-based"
    uint    chromEnd;     "End, exclusive"
    string  name;         "Fragment identifier"
    uint    score;        "Not used"
    char[1] strand;       "Not applicable"
    uint    thickStart;   "Same as chromStart"
    uint    thickEnd;     "Same as chromEnd"
    uint    reserved;     "Colour, as itemRgb"
    string  result;       "Reported outcome of the assay"
    string  pmid;         "PubMed ID of the study"
    string  fragmentName; "Name used in the source study"
    string  otherNames;   "Other names for the same fragment"
    )
"""


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def read_tsv(path):
    with open(path, newline='') as fh:
        return list(csv.DictReader(fh, delimiter='\t'))


def dash(value):
    """BED has no null. A field that is empty in the release stays visibly empty."""
    return value if value not in ('', None) else '.'


def rgb_to_int(rgb):
    r, g, b = (int(x) for x in rgb.split(','))
    return (r << 16) + (g << 8) + b


def origins_rows(data_dir, assembly):
    rows = []
    for o in read_tsv(os.path.join(data_dir, 'origins.tsv')):
        if o['assembly'] != assembly or o['start'] == '':
            continue
        start, end = int(o['start']) - 1, int(o['end'])
        rgb = TIER_RGB.get(o['status'], TIER_RGB['Unsupported'])
        rows.append([
            o['chromosome'], start, end, o['accession'],
            TIER_SHADE.get(o['status'], 0), '.', start, end, rgb_to_int(rgb),
            o['status'], o['ars_negative'] or 'no', int(o['n_support'] or 0),
            dash(o['name'] or o['systematic']),
            dash(o['okseq_activity_oem']), dash(o['median_replication_time_composite_min']),
        ])
    return rows, ORIGINS_AS, 'bed9+6'


def elements_rows(data_dir, assembly):
    rows = []
    for e in read_tsv(os.path.join(data_dir, 'elements.tsv')):
        if e['assembly'] != assembly or e['start'] == '':
            continue   # an element that could not be placed has no position to draw
        start, end = int(e['start']) - 1, int(e['end'])
        pct = e.get('motif_score_pct', '')
        rgb = ELEMENT_RGB.get(e['element_class'], ELEMENT_RGB['A'])
        rows.append([
            e['chromosome'], start, end, e['accession'],
            int(round(float(pct) * 10)) if pct not in ('', None) else 0,
            e['strand'] if e['strand'] in ('+', '-') else '.',
            start, end, rgb_to_int(rgb),
            e['element_class'], e['element_type'], dash(e['origin_accession']),
            dash(e['study_pmid']), dash(pct),
        ])
    return rows, ELEMENTS_AS, 'bed9+5'


def refuted_rows(data_dir, assembly):
    rows = []
    for r in read_tsv(os.path.join(data_dir, 'refuted_fragments.tsv')):
        if r['assembly'] != assembly or r['start'] == '':
            continue
        start, end = int(r['start']) - 1, int(r['end'])
        rows.append([
            r['chromosome'], start, end, r['fragment_id'], 0, '.', start, end,
            rgb_to_int(REFUTED_RGB),
            dash(r['result']), dash(r['pmid']), dash(r['name']), dash(r['other_names']),
        ])
    return rows, REFUTED_AS, 'bed9+4'


BUILDERS = {
    'origins': origins_rows,
    'elements': elements_rows,
    'refuted_fragments': refuted_rows,
}


def write_track(name, rows, autosql, bedtype, chrom_sizes, out_dir, release):
    """Sort, write the BED and its .as, and convert. An empty set writes nothing:
    a bigBed with no features is a track that says something false about the data."""
    if not rows:
        print(f"{name}: no records on this assembly, no track written")
        return None
    rows.sort(key=lambda r: (r[0], r[1], r[2]))
    bed = os.path.join(out_dir, f'{name}.bed')
    asf = os.path.join(out_dir, f'{name}.as')
    bb = os.path.join(out_dir, f'{name}.bb')
    with open(bed, 'w') as fh:
        for r in rows:
            fh.write('\t'.join(str(x) for x in r) + '\n')
    with open(asf, 'w') as fh:
        fh.write(autosql % {'release': release})
    subprocess.run(['bedToBigBed', f'-as={asf}', f'-type={bedtype}', '-tab',
                    '-extraIndex=name', bed, chrom_sizes, bb], check=True)
    print(f"{name}: {len(rows)} features -> {bb} ({os.path.getsize(bb)} bytes)")
    return bb


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--data-dir', default='public/data')
    ap.add_argument('--assembly', required=True)
    ap.add_argument('--chrom-sizes', required=True)
    ap.add_argument('--out', required=True)
    a = ap.parse_args()

    release = json.load(open(os.path.join(a.data_dir, 'release.json')))
    os.makedirs(a.out, exist_ok=True)
    sizes = {l.split('\t')[0] for l in open(a.chrom_sizes) if l.strip()}

    written, counts = [], {}
    for name, builder in BUILDERS.items():
        rows, autosql, bedtype = builder(a.data_dir, a.assembly)
        unknown = sorted({r[0] for r in rows} - sizes)
        if unknown:
            sys.exit(f"{name}: sequences not in {a.chrom_sizes}: {unknown}")
        out = write_track(name, rows, autosql, bedtype, a.chrom_sizes, a.out,
                          release['release'])
        if out:
            written.append(os.path.basename(out))
            counts[os.path.basename(out)] = len(rows)

    # The hub describes every dataset with a dataset.json, and the website's Data hub
    # page is generated from those records. This one says what it is, which release it
    # came from, and the checksum of each file, so a served track can be tied back to
    # the release that produced it.
    species = {'sacCer3': 'Saccharomyces cerevisiae',
               'ASM294v2': 'Schizosaccharomyces pombe'}.get(a.assembly, '')
    record = {
        'dataset_id': f'oridb_v3-{a.assembly.lower()}',
        'title': f'OriDB {release["release"]} records on {a.assembly}',
        'organism': species,
        'assembly': a.assembly,
        'measurement': 'curated origin records, not a measurement',
        'scale': 'intervals; the score column shades by evidence tier',
        'source': {
            'repository': 'https://github.com/DNAReplicationLab/oridb-v3',
            'release': release['release'],
            'release_generated': release['generated'],
        },
        'provenance': {
            'pipeline': 'oridb-v3/bin/build_oridb_tracks.py',
            'pipeline_revision': None,
            'generated': datetime.datetime.now(datetime.timezone.utc)
                                  .strftime('%Y-%m-%dT%H:%M:%SZ'),
        },
        'tracks': [{
            'file': f,
            'features': counts[f],
            'sha256': sha256(os.path.join(a.out, f)),
        } for f in written],
    }
    with open(os.path.join(a.out, 'dataset.json'), 'w') as fh:
        json.dump(record, fh, indent=2)
        fh.write('\n')
    print(f"{a.assembly}: {len(written)} track(s) from release {release['release']}")


if __name__ == '__main__':
    main()
