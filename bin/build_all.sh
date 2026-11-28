#!/usr/bin/env bash
# Run the whole data pipeline, in order, stopping at the first failure.
#
# The five steps depend on each other's output and each one silently produces a
# complete-looking set of files from the wrong input: a release once shipped with
# unlifted sacCer1 coordinates, no per-origin values and no element links, because
# step 2 did not run and nothing downstream noticed. Use this script rather than
# the five commands, and never redirect its stderr away.
#
#   bin/build_all.sh
#
# It takes no arguments: every input is in this repository. The v2 records are the
# frozen extract in reference/v2/, and the two assemblies are fetched from UCSC into
# reference/genomes/ on first use and verified against reference/genomes.tsv.
set -euo pipefail

if [ "$#" -ne 0 ]; then
    sed -n '2,16p' "$0" >&2
    exit 64
fi
cd "$(dirname "$0")/.."
sac1=reference/genomes/sacCer1; sac3=reference/genomes/sacCer3

echo "== 0/5 fetch_genomes"   >&2; python3 bin/fetch_genomes.py
echo "== 1/5 build_from_v2"   >&2; python3 bin/build_from_v2.py
echo "== 2/5 lift_to_saccer3" >&2; python3 bin/lift_to_saccer3.py --sacCer1 "$sac1" --sacCer3 "$sac3"
echo "== 3/5 build_elements"  >&2; python3 bin/build_elements.py --sacCer3 "$sac3"
echo "== 4/5 build_tiers"     >&2; python3 bin/build_tiers.py
echo "== 5/5 compare_sgd"     >&2; python3 bin/compare_sgd.py
# refuted_fragments.tsv has its md5 recorded by step 1 and is then rewritten by the
# lift in step 2, which refreshes the files it lifts but not that one. The entry went
# stale on every build, and was corrected by hand afterwards or not at all. Refreshing
# here closes it, and costs nothing when every checksum is already right.
echo "== checksums"           >&2; php bin/refresh_release_checksums.php
echo "== smoke test"          >&2; php bin/smoke_test.php
