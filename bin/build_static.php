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
foreach (Data::speciesList() as $species) {
    $slug = strtolower(str_replace(' ', '-', $species));
    $map['export.php?' . http_build_query(['format' => 'bed', 'species' => $species])]
        = "oridb-origins-{$slug}.bed";
}
$map['export.php?format=elements'] = 'oridb-elements.bed';
$map['export.php?format=json'] = 'oridb-origins.json';
$map['export.php?format=tsv'] = 'oridb-origins.tsv';

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
foreach (Data::speciesList() as $species) {
    $slug = strtolower(str_replace(' ', '-', $species));
    file_put_contents($out . "/oridb-origins-{$slug}.bed", render($root, 'export.php', ['format' => 'bed', 'species' => $species]));
}
file_put_contents($out . '/oridb-elements.bed', render($root, 'export.php', ['format' => 'elements']));
file_put_contents($out . '/oridb-origins.json', render($root, 'export.php', ['format' => 'json']));
file_put_contents($out . '/oridb-origins.tsv', render($root, 'export.php', ['format' => 'tsv']));
file_put_contents($out . '/.nojekyll', '');

printf("Wrote %d HTML pages plus assets and exports to %s\n", $written, $out);
