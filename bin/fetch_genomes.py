#!/usr/bin/env python3
"""Fetch the sacCer1 and sacCer3 chromosome FASTA the build needs, and verify them.

    python3 bin/fetch_genomes.py [--dir reference/genomes] [--check]

Two steps of the pipeline read sequence: lift_to_saccer3.py checks every lifted
interval base for base, and build_elements.py locates each element in sacCer3 by
its stored sequence rather than trusting a coordinate. Both assemblies therefore
have to be present for a build to mean anything, and until now they were two
directory paths on the maintainer's laptop - which is what made a clean clone
unbuildable by anyone else.

The sequences are not committed: they are 24 MB of third-party data that UCSC
already archives, and this repository is otherwise a few MB of text. They are
downloaded into reference/genomes/ (git-ignored) on first use and reused after.

Verification
------------
reference/genomes.tsv holds, per chromosome, the length and the md5 of the
UPPERCASED sequence with newlines removed - that is, of exactly the string
load_fasta_dir() hands to the build. Checking the sequence rather than the
archive is deliberate: it is indifferent to how UCSC packs the files and to
soft-masking, and it still catches a truncated download, a wrong assembly, or a
chromosome silently swapped. A sequence present in the download but absent from
the manifest (sacCer1's chrM, which no step reads) is left alone.

--check verifies what is already on disk and downloads nothing.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import re
import shutil
import ssl
import subprocess
import sys
import tarfile
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MANIFEST = ROOT / 'reference' / 'genomes.tsv'
DEFAULT_DIR = ROOT / 'reference' / 'genomes'

# UCSC publishes chromFa as .tar.gz for recent assemblies and .zip for older ones,
# and has moved which is which before now. Both are tried; the format is decided by
# the bytes that arrive, not by the file extension. Confirmed 2026-11-28: sacCer1 is
# served as chromFa.zip and sacCer3 as chromFa.tar.gz, about 3.7 MB each.
BIGZIPS = 'https://hgdownload.soe.ucsc.edu/goldenPath/{asm}/bigZips/'
ARCHIVES = ('chromFa.tar.gz', 'chromFa.zip')

CERT_HINT = """
This is a certificate-store problem on this machine, not a problem with UCSC.
A python.org build of Python on macOS ships with no CA store until you run
    /Applications/Python\\ 3.x/Install\\ Certificates.command
Installing certifi (`python3 -m pip install certifi`) also fixes it: this script
uses certifi's bundle when it can import it.
"""


def download(url: str) -> bytes:
    """Fetch a URL, verifying the certificate against the best store available.

    urllib verifies against OpenSSL's default store, which on a python.org build
    of Python for macOS is empty until Install Certificates.command has been run -
    so a perfectly good URL fails with CERTIFICATE_VERIFY_FAILED. certifi's bundle
    is used when it is importable, and curl, which has its own store, is tried
    after that. Verification is never disabled: what arrives is checked against
    reference/genomes.tsv either way, but a silent downgrade to an unverified
    transport is not something a build script should decide on its own.
    """
    context = None
    try:
        import certifi
        context = ssl.create_default_context(cafile=certifi.where())
    except ImportError:
        pass
    try:
        with urllib.request.urlopen(url, timeout=300, context=context) as fh:
            return fh.read()
    except (urllib.error.URLError, OSError) as exc:
        first = exc
    curl = shutil.which('curl')
    if curl is None:
        raise first
    done = subprocess.run([curl, '-fsSL', '--max-time', '300', url],
                          capture_output=True)
    if done.returncode != 0:
        raise OSError(f'{first}; curl also failed '
                      f'({done.returncode}: {done.stderr.decode("utf-8", "replace").strip()})')
    return done.stdout


def read_manifest() -> dict[str, dict[str, tuple[int, str]]]:
    lines = MANIFEST.read_text(encoding='utf-8').rstrip('\n').split('\n')
    header = lines[0].split('\t')
    want = ['assembly', 'sequence', 'length', 'md5_uppercase_sequence']
    if header != want:
        sys.exit(f'{MANIFEST.name}: expected columns {want}, found {header}')
    out: dict[str, dict[str, tuple[int, str]]] = {}
    for ln in lines[1:]:
        asm, seq, length, md5 = ln.split('\t')
        out.setdefault(asm, {})[seq] = (int(length), md5)
    return out


def sequence_of(text: str) -> tuple[str, str]:
    """A single-record FASTA as (name, uppercase sequence), as the build reads it."""
    head, _, body = text.partition('\n')
    return head[1:].strip().split()[0], ''.join(body.split()).upper()


def write_chromosomes(blob: bytes, dest: Path) -> int:
    """Unpack a UCSC chromFa archive into dest as one chr*.fa per sequence."""
    dest.mkdir(parents=True, exist_ok=True)
    members: list[tuple[str, bytes]] = []
    if blob[:2] == b'\x1f\x8b' or blob[:4] == b'ustar':
        with tarfile.open(fileobj=io.BytesIO(blob), mode='r:*') as tf:
            for m in tf.getmembers():
                if m.isfile() and m.name.endswith(('.fa', '.fasta')) \
                        and not Path(m.name).name.startswith('.'):
                    members.append((m.name, tf.extractfile(m).read()))
    elif blob[:2] == b'PK':
        with zipfile.ZipFile(io.BytesIO(blob)) as zf:
            for n in zf.namelist():
                if n.endswith(('.fa', '.fasta')) and not Path(n).name.startswith('.'):
                    members.append((n, zf.read(n)))
    else:
        sys.exit('downloaded archive is neither a tar nor a zip')
    if not members:
        sys.exit('downloaded archive contains no FASTA')

    written = 0
    for name, data in members:
        text = data.decode('ascii', 'strict')
        # A whole-genome FASTA and a per-chromosome one both occur; split either way.
        for record in filter(None, text.split('\n>')):
            record = record if record.startswith('>') else '>' + record
            seq_name = record.split('\n', 1)[0][1:].strip().split()[0]
            if not re.fullmatch(r'chr[A-Za-z0-9]+', seq_name):
                continue
            (dest / f'{seq_name}.fa').write_text(record.rstrip('\n') + '\n')
            written += 1
    return written


def verify(asm: str, dest: Path, expected: dict[str, tuple[int, str]]) -> list[str]:
    problems = []
    for seq, (length, md5) in sorted(expected.items()):
        path = dest / f'{seq}.fa'
        if not path.exists():
            problems.append(f'{asm}/{seq}: missing')
            continue
        name, body = sequence_of(path.read_text())
        if name != seq:
            problems.append(f'{asm}/{seq}: FASTA header says {name}')
        elif len(body) != length:
            problems.append(f'{asm}/{seq}: {len(body)} bp, expected {length}')
        elif hashlib.md5(body.encode()).hexdigest() != md5:
            problems.append(f'{asm}/{seq}: sequence md5 differs from reference/genomes.tsv')
    return problems


def fetch(asm: str, dest: Path) -> None:
    errors = []
    for archive in ARCHIVES:
        url = BIGZIPS.format(asm=asm) + archive
        try:
            blob = download(url)
        except (urllib.error.URLError, OSError) as exc:
            errors.append(f'  {url}\n    {exc}')
            continue
        print(f'{asm}: {len(blob) / 1e6:.1f} MB from {url}', file=sys.stderr)
        n = write_chromosomes(blob, dest)
        print(f'{asm}: {n} sequences written to {dest}', file=sys.stderr)
        return
    sys.exit(f'could not download {asm} chromFa from UCSC:\n' + '\n'.join(errors)
             + (CERT_HINT if any('CERTIFICATE_VERIFY_FAILED' in e for e in errors) else '')
             + f'\nIf this machine has no route to hgdownload.soe.ucsc.edu, unpack the '
               f'chromosomes into {dest} by hand (one chr*.fa per sequence) and re-run '
               f'with --check.')


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--dir', default=str(DEFAULT_DIR),
                    help='where the assemblies live (default reference/genomes)')
    ap.add_argument('--check', action='store_true',
                    help='verify what is on disk; download nothing')
    args = ap.parse_args()

    root = Path(args.dir)
    expected = read_manifest()
    problems: list[str] = []
    for asm, seqs in expected.items():
        dest = root / asm
        if not args.check and verify(asm, dest, seqs):
            if dest.exists():
                shutil.rmtree(dest)
            fetch(asm, dest)
        found = verify(asm, dest, seqs)
        problems += found
        if not found:
            print(f'{asm}: {len(seqs)} sequences verified against '
                  f'{MANIFEST.name}', file=sys.stderr)
    if problems:
        sys.exit('genome check failed:\n  ' + '\n  '.join(problems))


if __name__ == '__main__':
    main()
