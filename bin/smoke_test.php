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

printf("\n%d case(s) failed.\n", $failures);
exit($failures === 0 ? 0 : 1);
