<?php
declare(strict_types=1);

/**
 * Render the trial site to flat HTML in build/static/.
 *
 * Purpose: a no-PHP preview that can be served from GitHub Pages while the
 * PHP site is being reviewed. The HTML is produced by the SAME templates that
 * run on the server, so the preview cannot drift from the live site.
 *
 *     php bin/build_static.php
 */

$root = dirname(__DIR__);
require_once $root . '/public/app/bootstrap.php';

$out = $root . '/build/static';
if (!is_dir($out)) {
    mkdir($out, 0o755, true);
}

/** Map a dynamic URL onto a flat filename. */
function static_name(string $page, array $query): string
{
    $query = array_filter($query, static fn ($v): bool => (string) $v !== '');
    if ($query === []) {
        return $page === 'index.php' ? 'index.html' : basename($page, '.php') . '.html';
    }
    $bits = [];
    foreach ($query as $k => $v) {
        $bits[] = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $v);
    }

    return basename($page, '.php') . '-' . trim(strtolower(implode('-', $bits)), '-') . '.html';
}

/** Render one page in an isolated process and return its output. */
function render(string $root, string $page, array $query): string
{
    $script = $root . '/public/' . $page;
    $code = '$_GET = ' . var_export($query, true) . ';'
        . '$_SERVER["REQUEST_METHOD"]="GET";'
        . 'define("ORIDB_STATIC", true);'
        . 'require ' . var_export($script, true) . ';';

    return (string) shell_exec('php -r ' . escapeshellarg($code) . ' 2>&1');
}

$targets = [
    ['index.php', []],
    ['about.php', []],
    ['development.php', []],
    ['downloads.php', []],
    ['datahub.php', []],
    ['studies.php', []],
    ['search.php', []],
    ['browse.php', []],
];

foreach (Data::speciesList() as $species) {
    $n = count(Data::query(species: $species));
    $pages = (int) ceil($n / 100);
    for ($p = 1; $p <= $pages; $p++) {
        $targets[] = ['browse.php', ['species' => $species, 'page' => $p]];
    }
}
foreach (Data::origins() as $o) {
    $targets[] = ['origin.php', ['id' => $o['accession']]];
}

// Dynamic endpoints with no PHP in a static mirror: alias them to pre-rendered files.
$map = [];
foreach (Data::origins() as $o) {
    $map['api/origin.php?id=' . $o['accession']] = 'api/origin-' . $o['accession'] . '.json';
}
// Every export the Downloads page offers, declared once and used twice: to alias the
// link and to write the file. They were two separate lists, and the per-species TSV
// and the legacy-name BEDs were in the page but not in the mirror, so the mirror
// carried links to files it had never written. One list cannot drift from itself.
$exports = [];
foreach (Data::speciesList() as $species) {
    $slug = strtolower(str_replace(' ', '-', $species));
    $exports["oridb-origins-{$slug}.bed"] = ['format' => 'bed', 'species' => $species];
    $exports["oridb-origins-{$slug}-legacy-names.bed"] =
        ['format' => 'bed', 'species' => $species, 'names' => 'legacy'];
    $exports["oridb-origins-{$slug}-both-names.bed"] =
        ['format' => 'bed', 'species' => $species, 'names' => 'both'];
    $exports["oridb-origins-{$slug}.tsv"] = ['format' => 'tsv', 'species' => $species];
    $exports["oridb-origins-{$slug}.json"] = ['format' => 'json', 'species' => $species];
}
$exports['oridb-elements.bed'] = ['format' => 'elements'];
$exports['oridb-evidence-matrix.tsv'] = ['format' => 'matrix'];
$exports['oridb-refuted-fragments.tsv'] = ['format' => 'refuted'];
$exports['oridb-origins.tsv'] = ['format' => 'tsv'];
$exports['oridb-origins.json'] = ['format' => 'json'];
foreach ($exports as $file => $query) {
    $map['export.php?' . http_build_query($query)] = $file;
}

foreach ($targets as [$page, $query]) {
    $map[$page . '?' . http_build_query(array_filter($query, static fn ($v): bool => (string) $v !== ''))]
        = static_name($page, $query);
}

$written = 0;
foreach ($targets as [$page, $query]) {
    $html = render($root, $page, $query);

    // Rewrite internal links onto the flat filenames.
    // A fragment is carried across unchanged: pages link to named sections of each
    // other, and dropping the #anchor would silently land the reader at the top.
    $html = preg_replace_callback(
        '/(href=")([a-z0-9_\/]+\.php)(\?[^"#]*)?(#[^"]*)?(")/i',
        static function (array $m) use ($map): string {
            $query = [];
            if (($m[3] ?? '') !== '') {
                parse_str(html_entity_decode(ltrim($m[3], '?'), ENT_QUOTES, 'UTF-8'), $query);
            }
            $query = array_filter($query, static fn ($v): bool => (string) $v !== '');
            $key = $m[2] . '?' . http_build_query($query);
            $file = $map[$key] ?? static_name($m[2], $query);

            return $m[1] . $file . ($m[4] ?? '') . $m[5];
        },
        $html
    );

    file_put_contents($out . '/' . static_name($page, $query), $html);
    $written++;
}

// Per-origin JSON, standing in for the live API.
if (!is_dir($out . '/api')) {
    mkdir($out . '/api', 0o755, true);
}
foreach (Data::origins() as $o) {
    file_put_contents(
        $out . '/api/origin-' . $o['accession'] . '.json',
        render($root, 'api/origin.php', ['id' => $o['accession']])
    );
}

// Assets and bulk exports.
if (!is_dir($out . '/assets')) {
    mkdir($out . '/assets', 0o755, true);
}
// Recursive: assets/ gained a subdirectory when the browser bundle was vendored,
// and a flat copy() silently left the static build without it.
$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    if (!is_dir($to)) {
        mkdir($to, 0o755, true);
    }
    foreach (glob($from . '/*') ?: [] as $item) {
        $target = $to . '/' . basename($item);
        is_dir($item) ? $copyTree($item, $target) : copy($item, $target);
    }
};
$copyTree($root . '/public/assets', $out . '/assets');
foreach ($exports as $file => $query) {
    file_put_contents($out . '/' . $file, render($root, 'export.php', $query));
}
file_put_contents($out . '/.nojekyll', '');

// Every internal link in the mirror must resolve to something written. This is the
// check that would have caught an export listed on a page but never rendered, and a
// page linked from the nav but not in $targets. External links, anchors and mailto
// are not ours to verify.
$missing = [];
foreach (glob($out . '/*.html') ?: [] as $file) {
    preg_match_all('/href="([^"#:]+)(?:#[^"]*)?"/', (string) file_get_contents($file), $m);
    foreach ($m[1] as $href) {
        // Assets carry a ?v=<hash> for cache busting; the file on disk does not.
        $href = explode('?', $href)[0];
        if ($href === '' || str_starts_with($href, '//')) {
            continue;
        }
        if (!file_exists($out . '/' . $href)) {
            $missing[$href][] = basename($file);
        }
    }
}
if ($missing !== []) {
    foreach ($missing as $href => $pages) {
        fwrite(STDERR, "broken link: {$href} (from " . implode(', ', array_unique($pages)) . ")\n");
    }
    fwrite(STDERR, count($missing) . " unresolved internal link target(s)\n");
    exit(1);
}

printf("Wrote %d HTML pages plus %d exports and assets to %s; every internal link resolves\n",
       $written, count($exports), $out);
