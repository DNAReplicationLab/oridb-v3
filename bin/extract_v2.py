#!/usr/bin/env python3
"""Freeze the OriDB v2 tables the build reads into reference/v2/, once.

    python3 bin/extract_v2.py /path/to/nieduszy_oridb.sql

This script is NOT part of a normal build. It exists so that the one-off
conversion from a 48 MB MySQL dump into 273 KB of committed TSV is auditable and
repeatable, rather than something that happened in a shell on someone's laptop.
Having run, its output is the build's input and the dump is no longer needed:
see reference/v2/README.md.

Why freeze rather than keep parsing the dump
--------------------------------------------
The dump is a fixed historical object. It will never gain a row, and the eleven
tables below are 4,049 rows between them. Re-parsing 48 MB of INSERT statements
on every build bought nothing except a dependency on a file that cannot be
committed - it still holds live v2 credentials - and that therefore made a clean
clone unbuildable by anyone but its author.

What is preserved
-----------------
The RAW value of every field, exactly as the dump's INSERT statements carry it:
MySQL escape sequences (\\' and \\\\) unresolved, and the latin1-decoded bytes of
the mis-encoded UTF-8 in local_pubmed left as they are. build_from_v2.unescape()
resolves both downstream, and it must see the same strings it saw when reading
the dump directly, or 87 author names and titles would change. The extract is
written UTF-8, which round-trips those strings exactly.

Row ORDER is preserved too. Several downstream steps sort with a stable sort, so
dump order is visible in the released files.

local_pubmed is the one table trimmed: it is cut to the six columns the build
reads, which takes it from 600 KB to 127 KB. The dropped columns are abstract
text, PMC id, volume, issue and page.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'reference' / 'v2'
MANIFEST = OUT / 'manifest.json'

# Every v2 table the pipeline reads, and the columns to keep ('*' = all of them).
TABLES: dict[str, tuple[str, ...] | str] = {
    # build_from_v2.py: origins, the assays that confirm them, the fragments that refute them
    'sc_ori': '*',
    'sp_ori': '*',
    'sc_cloned_ori': '*',
    'sc_cloned_no_ori': '*',
    'sc_2D_gel': '*',
    'sp_2D_gel': '*',
    # build_from_v2.py and build_elements.py: cached citations
    'local_pubmed': ('pubmed_id', 'doi', 'date', 'journal', 'author_list', 'article_title'),
    # build_elements.py: the element layer
    'sc_confirmed_ACS': '*',
    'Nieduszynski2006_ACS': '*',
    'Eaton2010_ACS': '*',
    'Xu2006_ACS': '*',
}


def read_dump(path: Path) -> str:
    """The dump is latin1; its UTF-8 content is mis-encoded and stays that way here."""
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


def main(dump_path: str) -> None:
    dump = Path(dump_path)
    sql = read_dump(dump)
    OUT.mkdir(parents=True, exist_ok=True)

    manifest: dict[str, object] = {
        'description': 'Frozen extract of the OriDB v2 tables the v3 build reads. '
                       'Written once by bin/extract_v2.py; immutable thereafter. A '
                       'correction to a v2 record belongs in an overlay table under '
                       'reference/, not in these files - see reference/v2/README.md.',
        'source_dump': {'filename': dump.name,
                        'bytes': dump.stat().st_size,
                        'sha256': hashlib.sha256(dump.read_bytes()).hexdigest(),
                        'note': 'OriDB v2.1.0 cerevisiae / v1.0.0 pombe. Held offline: it '
                                'contains live credentials and is not in this repository.'},
        'extracted': datetime.date.today().isoformat(),
        'extracted_by': 'bin/extract_v2.py',
        'tables': {},
    }

    total = 0
    for table, keep in TABLES.items():
        rows = load(sql, table)
        if not rows:
            sys.exit(f'{table}: no rows in the dump')
        keysets = {tuple(r) for r in rows}
        if len(keysets) != 1:
            sys.exit(f'{table}: INSERT statements disagree on the column list')
        cols = list(rows[0]) if keep == '*' else list(keep)
        missing = [c for c in cols if c not in rows[0]]
        if missing:
            sys.exit(f'{table}: no such column: {missing}')

        # A literal tab or newline inside a value would make the TSV unreadable, and
        # replacing it would silently alter the record. There are none; stop if that
        # ever changes rather than shipping a corrupted extract.
        for i, r in enumerate(rows):
            for c in cols:
                if any(ch in r[c] for ch in '\t\r\n'):
                    sys.exit(f'{table} row {i} column {c}: contains a tab or newline')

        path = OUT / f'{table}.tsv'
        with path.open('w', encoding='utf-8', newline='\n') as fh:
            fh.write('\t'.join(cols) + '\n')
            for r in rows:
                fh.write('\t'.join(r[c] for c in cols) + '\n')

        manifest['tables'][table] = {
            'file': f'{table}.tsv',
            'rows': len(rows),
            'columns': cols,
            'columns_dropped': [] if keep == '*' else [c for c in rows[0] if c not in cols],
            'md5': hashlib.md5(path.read_bytes()).hexdigest(),
        }
        total += len(rows)
        print(f'{table:22s} {len(rows):6d} rows  {path.stat().st_size / 1024:8.1f} KB')

    MANIFEST.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    print(f'{len(TABLES)} tables, {total} rows -> {OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
