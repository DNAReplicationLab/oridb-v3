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

printf("\n%d case(s) failed.\n", $failures);
exit($failures === 0 ? 0 : 1);
