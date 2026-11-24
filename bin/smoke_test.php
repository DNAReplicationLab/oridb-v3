<?php
declare(strict_types=1);

/**
 * Smoke test: render every page with representative parameters and fail on any
 * PHP diagnostic or empty output. Run from the repository root:
 *
 *     php bin/smoke_test.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$cases = [
    ['index.php', []],
    ['browse.php', []],
    ['browse.php', ['species' => 'Schizosaccharomyces pombe']],
    ['browse.php', ['page' => '3']],
    ['browse.php', ['species' => '<script>', 'chr' => '../../etc/passwd', 'page' => '-5']],
    ['origin.php', ['id' => 'ODB-SC-0001']],
    ['origin.php', ['id' => 'ODB-SP-0014']],
    ['origin.php', ['id' => '"><script>alert(1)</script>']],
    ['search.php', ['q' => 'ARS305']],
    ['search.php', ['q' => "' OR 1=1 --"]],
    ['search.php', ['region' => 'chrIV:100000-200000']],
    ['search.php', ['region' => 'nonsense((']],
    ['studies.php', []],
    ['downloads.php', []],
    ['datahub.php', []],
    ['about.php', []],
    ['export.php', ['format' => 'bed', 'species' => 'Saccharomyces cerevisiae']],
    ['export.php', ['format' => 'tsv']],
    ['export.php', ['format' => 'json']],
    ['api/origins.php', ['chr' => 'chrI']],
    ['api/origin.php', ['id' => 'ODB-SC-0003']],
    ['api/origin.php', ['id' => 'MISSING']],
    ['404.php', []],
];

$root = dirname(__DIR__);
$failures = 0;

foreach ($cases as [$page, $query]) {
    $script = $root . '/public/' . $page;
    $cmd = sprintf(
        'php -d display_errors=1 -d error_reporting=E_ALL -r %s 2>&1',
        escapeshellarg(
            '$_GET = ' . var_export($query, true) . ';'
            . '$_SERVER["REQUEST_METHOD"]="GET";'
            . 'require ' . var_export($script, true) . ';'
        )
    );
    $out = (string) shell_exec($cmd);
    $label = $page . ($query ? '?' . http_build_query($query) : '');

    $bad = preg_match('/(Fatal error|Parse error|Warning:|Deprecated:|Notice:|Uncaught)/', $out) === 1;
    $empty = strlen(trim($out)) < 20;

    if ($bad || $empty) {
        $failures++;
        printf("FAIL  %-52s %s\n", $label, $bad ? trim(explode("\n", $out)[0]) : 'empty output');
    } else {
        printf("ok    %-52s %6d bytes\n", $label, strlen($out));
    }
}

// Staleness check 2: a page must not hard-code a count that the data supplies.
// "458 origins" was written into search.php by hand and would have gone wrong the
// day the Likely and Dubious tiers were added.
$release = json_decode((string) file_get_contents($root . '/public/data/release.json'), true);
$facts = [];
foreach ($release['files'] as $meta) {
    $facts[(string) $meta['rows']] = true;
}
$perSpecies = [];
foreach (file($root . '/public/data/origins.tsv') as $i => $line) {
    if ($i === 0) {
        continue;
    }
    $f = explode("\t", $line);
    $perSpecies[$f[1]] = ($perSpecies[$f[1]] ?? 0) + 1;
}
foreach ($perSpecies as $n) {
    $facts[(string) $n] = true;
}
$offenders = [];
foreach (array_merge(glob($root . '/public/*.php'), glob($root . '/public/app/*.php')) as $file) {
    $body = (string) file_get_contents($file);
    foreach (array_keys($facts) as $n) {
        $n = (string) $n;
        if (preg_match('/(?<![\d>])' . preg_quote($n, '/') . '(?![\d<])/', $body) === 1) {
            $offenders[] = basename($file) . ' contains ' . $n;
        }
    }
}
if ($offenders !== []) {
    $failures++;
    echo "FAIL  hard-coded count: " . implode('; ', $offenders) . "\n";
} else {
    echo "ok    no page hard-codes a record count\n";
}

// Staleness check: a page must not assert an assembly name of its own. The banner
// once claimed coordinates were "not lifted" and went silently wrong the day they
// were. Anything assembly-specific belongs in data/release.json, which the pages read.
$offenders = [];
foreach (glob($root . '/public/*.php') as $file) {
    $body = (string) file_get_contents($file);
    if (preg_match('/\b(sacCer[123]|ASM294v2)\b/', $body) === 1) {
        $offenders[] = basename($file);
    }
}
foreach (glob($root . '/public/app/*.php') as $file) {
    if (basename($file) === 'Data.php') {
        continue; // the storage layer may branch on assembly; it states no facts
    }
    $body = (string) file_get_contents($file);
    if (preg_match('/\b(sacCer[123]|ASM294v2)\b/', $body) === 1) {
        $offenders[] = 'app/' . basename($file);
    }
}
if ($offenders !== []) {
    $failures++;
    echo "FAIL  hard-coded assembly name in: " . implode(', ', $offenders) . "\n";
} else {
    echo "ok    no page hard-codes an assembly name\n";
}

// Jail check: many shared hosts confine PHP to the document root, where anything
// above it does not exist at all. Simulating that with open_basedir catches a
// reintroduced read from outside public/ before it reaches a server.
$jail = $root . '/public';
$cmd = sprintf(
    'php -d display_errors=1 -d open_basedir=%s -r %s 2>&1',
    escapeshellarg($jail . ':/tmp'),
    escapeshellarg('$_GET=[];$_SERVER["REQUEST_METHOD"]="GET";'
        . 'require ' . var_export($root . '/public/index.php', true) . ';')
);
$out = (string) shell_exec($cmd);
if (str_contains($out, 'open_basedir') || !str_contains($out, '<!DOCTYPE html>')) {
    $failures++;
    echo "FAIL  index.php reads outside the web root (would 500 on a jailed host)\n";
} else {
    echo "ok    renders with PHP confined to the web root\n";
}

// Output-escaping check: hostile input must never appear unescaped.
$cmd = sprintf(
    'php -r %s 2>&1',
    escapeshellarg(
        '$_GET = ["q" => "<img src=x onerror=alert(1)>"];'
        . 'require ' . var_export($root . '/public/search.php', true) . ';'
    )
);
$out = (string) shell_exec($cmd);
if (str_contains($out, '<img src=x')) {
    $failures++;
    echo "FAIL  search.php reflected unescaped HTML\n";
} else {
    echo "ok    search.php escapes reflected input\n";
}

// The element alignment on an origin page must sit under the logo's columns.
// Both derive from release.json; the label column has to be at least as wide as
// the logo's left gutter, or the motif and the elements under it will drift.
$rel = json_decode((string) file_get_contents($root . '/public/data/release.json'), true);
$css = (string) file_get_contents($root . '/public/assets/style.css');
$php = (string) file_get_contents($root . '/public/origin.php');
$motif = $rel['acs_motif'] ?? [];
preg_match('/--lblw:\s*(\d+)px/', $css, $m);
$lblw = isset($m[1]) ? (int) $m[1] : -1;
$colw = (int) ($motif['logo_column_width'] ?? 0);
$padl = (int) ($motif['logo_left_pad'] ?? 0);
if ($colw <= 0 || $padl <= 0 || $lblw < $padl) {
    $failures++;
    echo "FAIL  alignment geometry is missing or the label column is narrower than the logo gutter\n";
} elseif (!str_contains($php, "logo_column_width") || !str_contains($php, "logo_left_pad")
          || preg_match('/--colw:\s*\d/', $php)) {
    $failures++;
    echo "FAIL  origin.php hard-codes the alignment column width instead of reading it\n";
} else {
    echo "ok    element alignment is pinned to the logo geometry\n";
}

// Every static asset must be referenced through Data::asset(), which appends a
// content hash. A bare assets/ URL means a changed stylesheet or logo sits in
// browser and edge caches until they expire - which is how the alignment and
// the motif once went stale on the live site while the HTML was current.
$bare = [];
foreach (glob($root . '/public/*.php') + glob($root . '/public/app/*.php') as $tpl) {
    $src = (string) file_get_contents($tpl);
    if (preg_match('/(?:href|src)="assets\/[^"?]+"/', $src)) {
        $bare[] = basename($tpl);
    }
}
if ($bare !== []) {
    $failures++;
    echo 'FAIL  unversioned asset reference in ' . implode(', ', $bare) . "\n";
} else {
    echo "ok    assets are referenced with a content hash\n";
}

// release.json must not accumulate the same known issue twice. Build steps that
// append to it can be rerun individually, and an unconditional append duplicates.
$issues = $rel['known_issues'] ?? [];
$texts = array_map(static fn ($i) => is_array($i) ? ($i['text'] ?? '') : (string) $i, $issues);
if (count($texts) !== count(array_unique($texts))) {
    $failures++;
    echo "FAIL  release.json lists the same known issue more than once\n";
} else {
    printf("ok    %d known issue(s), none duplicated\n", count($texts));
}

// ---------------------------------------------------------------------------
// Pipeline integrity. None of this was checked until a release shipped with
// unlifted sacCer1 coordinates, no per-origin values and no element links: every
// page rendered, every count looked plausible, and the data was wrong. A build
// step that does not run must fail here rather than look fine.
$readTsv = static function (string $name): array {
    $path = dirname(__DIR__) . '/public/data/' . $name;
    $path = str_contains($name, '..') ? realpath($path) ?: $path : $path;
    if (!is_file($path)) {
        return [];
    }
    $lines = explode("\n", rtrim((string) file_get_contents($path), "\n"));
    $header = explode("\t", array_shift($lines));
    return array_map(
        static fn (string $l): array => array_combine($header, array_pad(explode("\t", $l), count($header), '')),
        $lines
    );
};

$origins = $readTsv('origins.tsv');
$expectedAssembly = [
    'Saccharomyces cerevisiae' => 'sacCer3',
    'Schizosaccharomyces pombe' => 'ASM294v2',
];
$wrongAssembly = [];
$noLift = 0;
foreach ($origins as $o) {
    $want = $expectedAssembly[$o['species']] ?? null;
    if ($want !== null && $o['assembly'] !== $want) {
        $wrongAssembly[$o['species'] . ' on ' . $o['assembly']] = true;
    }
    if (($o['lift_status'] ?? '') === '') {
        $noLift++;
    }
}
if ($wrongAssembly !== [] || $noLift > 0) {
    echo 'FAIL  origins are not on the current assembly: '
       . implode('; ', array_keys($wrongAssembly))
       . ($noLift > 0 ? "; {$noLift} without a lift_status" : '')
       . " - bin/lift_to_saccer3.py did not run\n";
    $failures++;
} else {
    echo "ok    every origin is on the current assembly, with a lift status\n";
}

// The refuted fragments were published on sacCer1 for one release because the
// lift step did not know about the file. Coordinates without an assembly are
// worse than no coordinates.
$fragments = $readTsv('refuted_fragments.tsv');
$wrongFragments = [];
foreach ($fragments as $f) {
    if (($f['assembly'] ?? '') !== 'sacCer3') {
        $wrongFragments[$f['assembly'] ?? '(no assembly column)'] = true;
    }
}
if ($fragments !== [] && $wrongFragments !== []) {
    echo 'FAIL  refuted_fragments.tsv is on ' . implode(', ', array_keys($wrongFragments))
       . ", not the current assembly\n";
    $failures++;
} else {
    printf("ok    %d refuted fragment(s) on the current assembly\n", count($fragments));
}

// Evidence records: direct evidence must show in the tier, an assay strength is
// one of three grades or blank, and a negative assay cited by a site or recorded
// against one must exist as a published fragment.
$evidenceRows = $readTsv('evidence.tsv');
$statusOf = [];
foreach ($origins as $o) {
    $statusOf[$o['accession']] = $o['status'];
}
$fragmentIds = [];
foreach ($fragments as $f) {
    $fragmentIds[$f['fragment_id']] = true;
}
$badStrength = 0;
$unconfirmed = [];
$orphanNegative = [];
foreach ($evidenceRows as $e) {
    $negative = $e['method'] === 'plasmid (ARS assay, negative)';
    $strength = $e['strength'] ?? null;
    if ($strength === null
        || !in_array($strength, ['', 'strong', 'weak', 'very weak'], true)
        || ($strength !== '' && $e['method'] !== 'plasmid (ARS assay)')) {
        $badStrength++;
    }
    if (!$negative && ($statusOf[$e['accession']] ?? '') !== 'Confirmed') {
        $unconfirmed[$e['accession']] = true;
    }
    if ($negative && !isset($fragmentIds[$e['source_table'] . ':' . $e['source_id']])) {
        $orphanNegative[$e['source_table'] . ':' . $e['source_id']] = true;
    }
}
foreach ($origins as $o) {
    foreach (array_filter(explode(';', $o['ars_negative_fragments'] ?? '')) as $id) {
        if (!isset($fragmentIds[$id])) {
            $orphanNegative[$id] = true;
        }
    }
}
if ($badStrength > 0) {
    echo "FAIL  {$badStrength} evidence record(s) with a missing or invalid assay strength\n";
    $failures++;
} else {
    $graded = count(array_filter($evidenceRows, static fn(array $e): bool => $e['strength'] !== ''));
    printf("ok    %d of %d evidence record(s) carry an assay strength, all valid\n",
        $graded, count($evidenceRows));
}
if ($unconfirmed !== []) {
    echo 'FAIL  direct evidence at a site that is not Confirmed: '
       . implode(', ', array_keys($unconfirmed)) . "\n";
    $failures++;
} else {
    echo "ok    every site with a positive ARS assay or a 2D gel is Confirmed\n";
}
if ($orphanNegative !== []) {
    echo 'FAIL  negative assay(s) absent from refuted_fragments.tsv: '
       . implode(', ', array_keys($orphanNegative)) . "\n";
    $failures++;
} else {
    echo "ok    every negative assay recorded against a site is a published fragment\n";
}

// The element layer is useless unless its records reach an origin page.
$linked = 0;
foreach ($readTsv('elements.tsv') as $e) {
    if (($e['origin_accession'] ?? '') !== '') {
        $linked++;
    }
}
if ($linked === 0) {
    echo "FAIL  no element is linked to an origin - the element layer is orphaned\n";
    $failures++;
} else {
    printf("ok    %d element record(s) linked to an origin\n", $linked);
}

// Per-origin values: empty for one origin is a result, empty for all is a bug.
$withValue = 0;
foreach ($origins as $o) {
    if (($o['okseq_activity_oem'] ?? '') !== ''
        || ($o['median_replication_time_composite_min'] ?? '') !== '') {
        $withValue++;
    }
}
if ($withValue === 0) {
    echo "FAIL  no origin carries an activity or a replication time - "
       . "bin/build_elements.py did not annotate\n";
    $failures++;
} else {
    printf("ok    %d origin(s) carry an activity or a replication time\n", $withValue);
}

// A rank is derived from the values, so it can be left behind by a rebuild and
// go on looking plausible: a stale rank is wrong in a way no reader can see.
// Check the two that matter - every value has a rank and no blank does - and
// that the best-ranked origin really is the best one.
foreach ([['okseq_activity_oem', 'okseq_activity', 'high'],
          ['median_replication_time_composite_min', 'replication_time', 'low']] as [$valueCol, $prefix, $better]) {
    $values = [];
    $best = null;
    $mismatched = 0;
    foreach ($origins as $o) {
        $hasValue = ($o[$valueCol] ?? '') !== '';
        $hasRank = ($o[$prefix . '_rank'] ?? '') !== '';
        if ($hasValue !== $hasRank) {
            $mismatched++;
        } elseif ($hasValue) {
            // Ties share a rank, so the ranks are counted as a list rather than
            // keyed by rank, which would quietly drop every origin after a tie.
            $values[] = (float) $o[$valueCol];
            if ((int) $o[$prefix . '_rank'] === 1) {
                $best = (float) $o[$valueCol];
            }
        }
    }
    if ($mismatched > 0) {
        printf("FAIL  %d origin(s) have a %s but no rank, or the other way about - "
             . "run bin/build_origin_ranks.py\n", $mismatched, $valueCol);
        $failures++;
        continue;
    }
    $extreme = $values === [] ? null : ($better === 'high' ? max($values) : min($values));
    if ($best === null || $best !== $extreme) {
        printf("FAIL  the origin ranked 1 by %s is not the %s one - "
             . "run bin/build_origin_ranks.py\n", $prefix, $better === 'high' ? 'highest' : 'lowest');
        $failures++;
    } else {
        printf("ok    %d origin(s) ranked by %s, best = %s\n", count($values), $prefix, (string) $best);
    }
}

// The tier is derived from the matrix, so every site of a species some dataset
// covers must appear in it. A species with no dataset has no rows by definition.
$inMatrix = [];
foreach ($readTsv('evidence_matrix.tsv') as $m) {
    $inMatrix[$m['accession']] = true;
}
$covered = [];
$registry = dirname(__DIR__) . '/reference/datasets.tsv';
if (is_file($registry)) {
    $lines = explode("\n", rtrim((string) file_get_contents($registry), "\n"));
    $head = explode("\t", array_shift($lines));
    foreach ($lines as $line) {
        $row = array_combine($head, array_pad(explode("\t", $line), count($head), ''));
        $covered[$row['species']] = true;
    }
}
$missing = 0;
foreach ($origins as $o) {
    if (isset($covered[$o['species']]) && !isset($inMatrix[$o['accession']])) {
        $missing++;
    }
}
if ($missing > 0) {
    echo "FAIL  {$missing} tiered site(s) absent from evidence_matrix.tsv\n";
    $failures++;
} else {
    echo "ok    every tiered site appears in the evidence matrix\n";
}

// The BED export announced "Confirmed origins" for a release in which it held
// every tier. An export that does not say what it contains is worse than one
// that is merely incomplete, so the header and the rows are checked against
// each other.
$species = 'Saccharomyces cerevisiae';
$expectedTiers = [];
foreach ($origins as $o) {
    if ($o['species'] === $species) {
        $expectedTiers[$o['status']] = true;
    }
}
$cmd = sprintf(
    'php -d display_errors=1 -r %s 2>&1',
    escapeshellarg(
        '$_GET = ' . var_export(['format' => 'bed', 'species' => $species], true) . ';'
        . '$_SERVER["REQUEST_METHOD"]="GET";'
        . 'require ' . var_export($root . '/public/export.php', true) . ';'
    )
);
$bed = (string) shell_exec($cmd);
$bedTiers = [];
$widths = [];
foreach (explode("\n", trim($bed)) as $line) {
    if ($line === '' || $line[0] === '#' || str_starts_with($line, 'track')) {
        continue;
    }
    $cols = explode("\t", $line);
    $widths[count($cols)] = true;
    $bedTiers[$cols[6] ?? ''] = true;
}
$missingTiers = array_diff_key($expectedTiers, $bedTiers);
if ($missingTiers !== [] || array_keys($widths) !== [7]) {
    echo 'FAIL  the BED export does not carry a tier per row: columns '
       . implode('/', array_keys($widths)) . ', missing '
       . (implode(', ', array_keys($missingTiers)) ?: 'none') . "\n";
    $failures++;
} elseif (preg_match('/^#.*\bConfirmed origins\b/m', $bed) === 1) {
    echo "FAIL  the BED export header still claims Confirmed origins only\n";
    $failures++;
} else {
    printf("ok    the BED export names every tier it contains (%s)\n",
           implode(', ', array_keys($bedTiers)));
}

// Every registered dataset must be citable. Without this, a dataset renders on
// every origin page as a bare "PMID nnnn (citation not yet in the study table)",
// which is what happened to Foss 2024 when a commit on another branch carried an
// older bin/build_elements.py forward and dropped the step that adds them.
$studyPmids = [];
foreach ($readTsv('studies.tsv') as $st) {
    $studyPmids[$st['pmid']] = true;
}
$uncited = [];
foreach ($readTsv('../reference/datasets.tsv') as $d) {
    if (($d['study_pmid'] ?? '') !== '' && !isset($studyPmids[$d['study_pmid']])) {
        $uncited[$d['dataset_id']] = $d['study_pmid'];
    }
}
if ($uncited !== []) {
    $parts = [];
    foreach ($uncited as $id => $pmid) {
        $parts[] = $id . ' (PMID ' . $pmid . ')';
    }
    echo 'FAIL  registered dataset(s) with no citation in studies.tsv: '
       . implode(', ', $parts) . "\n";
    $failures++;
} else {
    echo "ok    every registered dataset has a citation record\n";
}

// Repeated builds have appended to studies.tsv before now.
$seen = [];
$dupes = 0;
foreach ($readTsv('studies.tsv') as $st) {
    if (isset($seen[$st['pmid']])) {
        $dupes++;
    }
    $seen[$st['pmid']] = true;
}
if ($dupes > 0) {
    echo "FAIL  studies.tsv has {$dupes} duplicate PMID row(s)\n";
    $failures++;
} else {
    echo "ok    studies.tsv has no duplicate PMIDs\n";
}

printf("\n%d case(s) failed.\n", $failures);
exit($failures === 0 ? 0 : 1);
