#!/usr/bin/env bash
# Run the whole data pipeline, in order, stopping at the first failure.
#
# The five steps depend on each other's output and each one silently produces a
# complete-looking set of files from the wrong input: a release once shipped with
# unlifted sacCer1 coordinates, no per-origin values and no element links, because
# step 2 did not run and nothing downstream noticed. Use this script rather than
# the five commands, and never redirect its stderr away.
#
#   bin/build_all.sh DUMP.sql SACCER1_FASTA_DIR SACCER3_FASTA_DIR
set -euo pipefail

if [ "$#" -ne 3 ]; then
    sed -n '2,12p' "$0" >&2
    exit 64
fi
dump=$1; sac1=$2; sac3=$3
for p in "$dump" "$sac1" "$sac3"; do
    [ -e "$p" ] || { echo "no such path: $p" >&2; exit 66; }
done
cd "$(dirname "$0")/.."

echo "== 1/5 build_from_v2"   >&2; python3 bin/build_from_v2.py "$dump"
echo "== 2/5 lift_to_saccer3" >&2; python3 bin/lift_to_saccer3.py --sacCer1 "$sac1" --sacCer3 "$sac3"
echo "== 3/5 build_elements"  >&2; python3 bin/build_elements.py "$dump" --sacCer3 "$sac3"
echo "== 4/5 build_tiers"     >&2; python3 bin/build_tiers.py
echo "== 5/5 compare_sgd"     >&2; python3 bin/compare_sgd.py
echo "== smoke test"          >&2; php bin/smoke_test.php
