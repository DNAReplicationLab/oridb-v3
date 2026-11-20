<?php
declare(strict_types=1);

/**
 * Describe the OriDB track hub for the site, from the hub repository itself.
 *
 *     php bin/build_datahub.php [--hub-root DIR] [--hub-url URL]
 *
 * The hub is a separate repository (oridb-data-hub) with its own release cycle, so the
 * Data hub page cannot be written by hand: it would start lying the first time a track
 * was added or renamed. This reads the hub's own hub.txt, genomes.txt, trackDb chain and
 * per-dataset dataset.json, and writes public/data/datahub.json, which the page renders.
 *
 * The public URL is derived from the hub repository's git remote, because GitHub Pages
 * serves it: git@github.com:ORG/REPO.git -> https://org.github.io/repo/hub.txt. Override
 * with --hub-url when the hub moves to a custom domain.
 */

$root = dirname(__DIR__);
$opts = ['hub-root' => dirname($root) . '/oridb-data-hub', 'hub-url' => null];
for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argv[$i], $m)) {
        $opts[$m[1]] = ($m[2] ?? '') !== '' ? $m[2] : ($argv[++$i] ?? '');
    }
}
$hub = rtrim((string) $opts['hub-root'], '/');
if (!is_file($hub . '/hub.txt')) {
    fwrite(STDERR, "No hub.txt under {$hub}. Pass --hub-root DIR.\n");
    exit(1);
}

/** Parse a UCSC stanza file into a list of key => value maps, following `include`. */
function stanzas(string $path): array
{
    $out = [];
    $cur = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $raw) {
        $line = trim($raw);
        if (str_starts_with($line, 'include ')) {
            if ($cur !== []) {
                $out[] = $cur;
                $cur = [];
            }
            // An include path is relative to the file containing it, unlike a
            // bigDataUrl, which is relative to the genome's trackDb.
            $out = array_merge($out, stanzas(dirname($path) . '/' . substr($line, 8)));
            continue;
        }
        if ($line === '' || str_starts_with($line, '#')) {
            if ($cur !== []) {
                $out[] = $cur;
                $cur = [];
            }
            continue;
        }
        [$key, $value] = array_pad(explode(' ', $line, 2), 2, '');
        if ($key === 'track' && $cur !== []) {
            $out[] = $cur;
            $cur = [];
        }
        $cur[$key] = trim($value);
    }
    if ($cur !== []) {
        $out[] = $cur;
    }

    return $out;
}

$hubMeta = stanzas($hub . '/hub.txt')[0] ?? [];
$genomeStanzas = stanzas($hub . '/genomes.txt');

$remote = '';
if (is_dir($hub . '/.git')) {
    $remote = trim((string) shell_exec('git -C ' . escapeshellarg($hub) . ' remote get-url origin 2>/dev/null'));
}
$repoUrl = '';
$baseUrl = '';
if (preg_match('#[:/]([^/:]+)/([^/]+?)(?:\.git)?$#', $remote, $m)) {
    $repoUrl = 'https://github.com/' . $m[1] . '/' . $m[2];
    $baseUrl = 'https://' . strtolower($m[1]) . '.github.io/' . $m[2] . '/';
}
if (($opts['hub-url'] ?? '') !== '' && $opts['hub-url'] !== null) {
    $hubUrl = (string) $opts['hub-url'];
    $baseUrl = (string) preg_replace('#hub\.txt$#', '', $hubUrl);
} else {
    $hubUrl = $baseUrl === '' ? '' : $baseUrl . 'hub.txt';
}

$genomes = [];
$nFiles = 0;
$nTracks = 0;
foreach ($genomeStanzas as $g) {
    if (!isset($g['genome'], $g['trackDb'])) {
        continue;
    }
    $tdb = $hub . '/' . $g['trackDb'];
    $served = $baseUrl === '' ? '' : $baseUrl . rtrim(dirname($g['trackDb']), '.') . '/';
    $all = stanzas($tdb);
    $byName = [];
    foreach ($all as $s) {
        if (isset($s['track'])) {
            $byName[$s['track']] = $s;
        }
    }

    // A track's dataset is the directory its bigDataUrl points into, and a container
    // is described through the subtracks that name it as parent. That is the hub's own
    // grouping rather than a guess from filenames.
    $datasets = [];
    foreach ($all as $s) {
        $url = $s['bigDataUrl'] ?? '';
        if (!isset($s['track']) || $url === '') {
            continue;
        }
        $nTracks++;
        $group = isset($s['parent']) ? explode(' ', $s['parent'])[0] : $s['track'];
        $dataset = str_contains($url, '/') ? explode('/', $url)[0] : '';
        $path = dirname($tdb) . '/' . $url;
        $entry = [
            'track' => $s['track'],
            'shortLabel' => $s['shortLabel'] ?? $s['track'],
            'longLabel' => $s['longLabel'] ?? '',
            'type' => $s['type'] ?? '',
            'file' => basename($url),
            'url' => $served === '' ? '' : $served . $url,
            'bytes' => is_file($path) ? filesize($path) : 0,
        ];
        if (is_file($path)) {
            $nFiles++;
        }
        // A call set ships a plain BED beside its indexed form; the number of calls is
        // that file's row count, which is not something a page should be told by hand.
        $bed = (string) preg_replace('/\.bb$/', '.bed', $url);
        if ($bed !== $url && is_file(dirname($tdb) . '/' . $bed)) {
            $entry['bed'] = basename($bed);
            $entry['bed_url'] = $served === '' ? '' : $served . $bed;
            $entry['rows'] = count(file(
                dirname($tdb) . '/' . $bed,
                FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
            ));
        }
        $datasets[$dataset]['groups'][$group]['tracks'][] = $entry;
        $datasets[$dataset]['groups'][$group]['shortLabel'] = $byName[$group]['shortLabel'] ?? $entry['shortLabel'];
        $datasets[$dataset]['groups'][$group]['longLabel'] = $byName[$group]['longLabel'] ?? $entry['longLabel'];
        $datasets[$dataset]['groups'][$group]['container'] = $byName[$group]['container'] ?? '';
    }

    $out = [];
    foreach ($datasets as $id => $d) {
        $record = [];
        $json = dirname($tdb) . '/' . $id . '/dataset.json';
        if (is_file($json)) {
            $record = json_decode((string) file_get_contents($json), true) ?: [];
        }
        $groups = [];
        foreach ($d['groups'] as $name => $grp) {
            $groups[] = [
                'track' => $name,
                'shortLabel' => $grp['shortLabel'],
                'longLabel' => $grp['longLabel'],
                'container' => $grp['container'],
                'tracks' => $grp['tracks'],
            ];
        }
        $out[] = [
            'id' => $id,
            'title' => $record['title'] ?? $id,
            'organism' => $record['organism'] ?? '',
            'assembly' => $record['assembly'] ?? $g['genome'],
            'bin_size_bp' => $record['bin_size_bp'] ?? null,
            'measurement' => $record['measurement'] ?? '',
            'scale' => $record['scale'] ?? '',
            'source' => [
                'doi' => $record['source']['doi'] ?? '',
                'pubmed' => $record['source']['pubmed'] ?? '',
                'archive_series' => $record['source']['archive_series'] ?? [],
                'n_runs' => count($record['source']['runs'] ?? []),
            ],
            'n_samples' => count($record['samples'] ?? []),
            'groups' => $groups,
        ];
    }
    $genomes[] = ['genome' => $g['genome'], 'datasets' => $out];
}

$payload = [
    'generated' => gmdate('Y-m-d\TH:i:s\Z'),
    'hub' => [
        'name' => $hubMeta['hub'] ?? '',
        'shortLabel' => $hubMeta['shortLabel'] ?? '',
        'longLabel' => $hubMeta['longLabel'] ?? '',
        'url' => $hubUrl,
        'base_url' => $baseUrl,
        'repo_url' => $repoUrl,
    ],
    'genomes' => $genomes,
    'counts' => [
        'genomes' => count($genomes),
        'datasets' => array_sum(array_map(static fn (array $g): int => count($g['datasets']), $genomes)),
        'tracks' => $nTracks,
        'files' => $nFiles,
    ],
];

$dest = $root . '/public/data/datahub.json';
file_put_contents($dest, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf(
    "%s: %d genome(s), %d dataset(s), %d track(s) over %d file(s) -> %s\n",
    $payload['hub']['name'],
    $payload['counts']['genomes'],
    $payload['counts']['datasets'],
    $payload['counts']['tracks'],
    $payload['counts']['files'],
    $dest
);
