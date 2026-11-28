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
 * Two sources, same output:
 *
 *   --hub-root DIR   read a checkout on disk (the default: ../oridb-data-hub). The public
 *                    URL is derived from that checkout's git remote, because GitHub Pages
 *                    serves it: git@github.com:ORG/REPO.git -> https://org.github.io/repo/.
 *   --hub-url URL    read the PUBLISHED hub over HTTPS, following the same include chain
 *                    (hub.txt, genomes.txt, trackDb, dataset.json). Nothing needs checking
 *                    out, so this can run in CI as a staleness check:
 *
 *                        php bin/build_datahub.php --hub-url https://host/path/hub.txt \
 *                          && git diff --exit-code -- public/data/datahub.json
 *
 * Giving both reads the files locally and writes the given public URL, which is what a
 * checkout with no git remote needs. The network is never the default. If any part of a
 * remote hub cannot be fetched the build fails with a message and writes nothing, so a
 * half-described hub can never reach the page: the page already degrades when the file is
 * absent, but not when it is present and wrong.
 */

$root = dirname(__DIR__);
$given = [];
$opts = ['hub-root' => dirname($root) . '/oridb-data-hub', 'hub-url' => null];
for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argv[$i], $m)) {
        $opts[$m[1]] = ($m[2] ?? '') !== '' ? $m[2] : ($argv[++$i] ?? '');
        $given[$m[1]] = true;
    }
}
$hubUrlOpt = (string) ($opts['hub-url'] ?? '');
$remote = isset($given['hub-url']) && $hubUrlOpt !== '' && !isset($given['hub-root']);

/** Where the hub is read from: a directory on disk, or a base URL. */
$src = ['mode' => 'local', 'base' => rtrim((string) $opts['hub-root'], '/')];
if ($remote) {
    $base = (string) preg_replace('#hub\.txt$#', '', $hubUrlOpt);
    $src = ['mode' => 'http', 'base' => rtrim($base, '/') . '/'];
}

/** Collected fetch failures; any one of them aborts the build before it writes. */
$errors = [];

/**
 * One HTTP request, through cURL when the extension is there and the stream
 * wrapper otherwise.
 *
 * cURL is preferred because it honours the http_proxy / https_proxy environment,
 * which PHP's stream wrapper does not: behind a proxy - CI runners and managed
 * networks commonly are - the wrapper simply fails to connect.
 *
 * @return array{status:int,body:?string,length:int}
 */
function http_request(string $url, bool $headOnly = false): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_NOBODY => $headOnly,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $length = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : null,
            'length' => $length > 0 ? $length : (is_string($body) ? strlen($body) : 0),
        ];
    }

    $ctx = stream_context_create(['http' => [
        'method' => $headOnly ? 'HEAD' : 'GET',
        'timeout' => 120,
        'follow_location' => 1,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    $length = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1]; // the last one wins, after any redirect
        }
        if (preg_match('/^content-length:\s*(\d+)/i', $h, $m)) {
            $length = (int) $m[1];
        }
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : null,
        'length' => $length > 0 ? $length : (is_string($body) ? strlen($body) : 0),
    ];
}

/** The text of one file in the hub, or null if it is not there. */
function hub_get(array $src, string $rel): ?string
{
    global $errors;
    if ($src['mode'] === 'local') {
        $path = $src['base'] . '/' . $rel;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }
    $res = http_request($src['base'] . $rel);
    if ($res['status'] !== 200 || $res['body'] === null) {
        if ($res['status'] !== 404) {
            $errors[] = $rel . ': HTTP ' . ($res['status'] > 0 ? (string) $res['status'] : 'no response');
        }

        return null;
    }

    return $res['body'];
}

/** The size of one file in the hub in bytes, or 0 when it cannot be established. */
function hub_size(array $src, string $rel): int
{
    if ($src['mode'] === 'local') {
        $path = $src['base'] . '/' . $rel;

        return is_file($path) ? (int) filesize($path) : 0;
    }
    $res = http_request($src['base'] . $rel, true);

    return $res['status'] === 200 ? $res['length'] : 0;
}

/** Join a hub-relative directory and a path, as a hub-relative path. */
function hub_join(string $dir, string $rel): string
{
    $dir = trim($dir, '.');
    $dir = trim($dir, '/');

    return $dir === '' ? $rel : $dir . '/' . $rel;
}

/**
 * Parse a UCSC stanza file into a list of key => value maps, following `include`.
 *
 * @return list<array<string,string>>
 */
function stanzas(array $src, string $rel): array
{
    global $errors;
    $text = hub_get($src, $rel);
    if ($text === null) {
        $errors[] = $rel . ': not found';

        return [];
    }
    $out = [];
    $cur = [];
    foreach (preg_split('/\R/', $text) as $raw) {
        $line = trim($raw);
        if (str_starts_with($line, 'include ')) {
            if ($cur !== []) {
                $out[] = $cur;
                $cur = [];
            }
            // An include path is relative to the file containing it, unlike a
            // bigDataUrl, which is relative to the genome's trackDb.
            $out = array_merge($out, stanzas($src, hub_join(dirname($rel), substr($line, 8))));
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

if ($src['mode'] === 'local' && !is_file($src['base'] . '/hub.txt')) {
    fwrite(STDERR, "No hub.txt under {$src['base']}. Pass --hub-root DIR or --hub-url URL.\n");
    exit(1);
}

$hubMeta = stanzas($src, 'hub.txt')[0] ?? [];
$genomeStanzas = stanzas($src, 'genomes.txt');

$gitRemote = '';
if ($src['mode'] === 'local' && is_dir($src['base'] . '/.git')) {
    $gitRemote = trim((string) shell_exec(
        'git -C ' . escapeshellarg($src['base']) . ' remote get-url origin 2>/dev/null'
    ));
}
$repoUrl = '';
$baseUrl = '';
if (preg_match('#[:/]([^/:]+)/([^/]+?)(?:\.git)?$#', $gitRemote, $m)) {
    // Lower-cased deliberately. Read from the published hub there is no git remote
    // to ask, only the Pages hostname, which DNS has already lower-cased; GitHub
    // resolves owner and repository case-insensitively. Normalising here is what
    // lets a local build and a remote build produce the same file, which is the
    // whole point of being able to diff one against the other in CI.
    $repoUrl = 'https://github.com/' . strtolower($m[1]) . '/' . strtolower($m[2]);
    $baseUrl = 'https://' . strtolower($m[1]) . '.github.io/' . $m[2] . '/';
}
if ($hubUrlOpt !== '') {
    $hubUrl = str_ends_with($hubUrlOpt, '.txt') ? $hubUrlOpt : rtrim($hubUrlOpt, '/') . '/hub.txt';
    $baseUrl = (string) preg_replace('#hub\.txt$#', '', $hubUrl);
    // A GitHub Pages URL names its repository, so the source link survives the
    // remote mode, where there is no checkout to ask git about.
    if ($repoUrl === '' && preg_match('#^https?://([^.]+)\.github\.io/([^/]+)/#', $baseUrl, $m)) {
        $repoUrl = 'https://github.com/' . $m[1] . '/' . $m[2];
    }
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
    $tdb = $g['trackDb'];
    $tdbDir = trim(dirname($tdb), '.');
    $served = $baseUrl === '' ? '' : $baseUrl . rtrim($tdbDir, '/') . '/';
    $all = stanzas($src, $tdb);
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
        $rel = hub_join($tdbDir, $url);
        $size = hub_size($src, $rel);
        // `negateValues on` is how a trackDb says this profile reads better upside
        // down: a replication time, where early is a small number and should plot
        // high. The setting may sit on the subtrack or on the container it belongs
        // to, and an embedded viewer has to be told the same thing, so the flag
        // travels with the track instead of being guessed at from its name.
        $container = $byName[$group] ?? [];
        $negated = ($s['negateValues'] ?? $container['negateValues'] ?? 'off') === 'on';
        // Two more settings travel with the track for the same reason, so that the
        // hub stays the one place a profile's appearance is decided. `viewLimits`
        // is the range this profile is worth reading over - a replication time
        // that never falls below 25 minutes should not be drawn from zero - and
        // `graphTypeDefault points` is how a trackDb says this is a continuous
        // trace rather than a bar chart.
        $viewLimits = (string) ($s['viewLimits'] ?? $container['viewLimits'] ?? '');
        $graphType = (string) ($s['graphTypeDefault'] ?? $container['graphTypeDefault'] ?? '');
        $entry = [
            'track' => $s['track'],
            'shortLabel' => $s['shortLabel'] ?? $s['track'],
            'longLabel' => $s['longLabel'] ?? '',
            'type' => $s['type'] ?? '',
            'file' => basename($url),
            'url' => $served === '' ? '' : $served . $url,
            'bytes' => $size,
            'negated' => $negated,
            'viewLimits' => $viewLimits,
            'graphType' => $graphType,
            // The hub marks a track the embedded panel should draw by default with a
            // free-form `metadata panelDefault=on`, which UCSC shows in the track
            // description and otherwise ignores. It exists for annotation that UCSC
            // serves natively and the panel does not, so the hub can ask for it in
            // one browser without changing the other.
            'panel_default' => str_contains((string) ($s['metadata'] ?? ''), 'panelDefault=on'),
            // trackDb `priority` is how the hub states display order, and the panel
            // has to honour the same order or the two browsers disagree about which
            // track comes first. A subtrack inherits its container's priority.
            'priority' => (float) ($s['priority'] ?? $container['priority'] ?? 99),
        ];
        if ($size > 0) {
            $nFiles++;
        }
        // A call set ships a plain BED beside its indexed form; the number of calls is
        // that file's row count, which is not something a page should be told by hand.
        // The row count is of DATA lines: these BEDs carry a column header comment, and
        // counting every line reported one call more than the file holds.
        $bedUrl = (string) preg_replace('/\.bb$/', '.bed', $url);
        if ($bedUrl !== $url) {
            $bedText = hub_get($src, hub_join($tdbDir, $bedUrl));
            if ($bedText !== null) {
                $rows = 0;
                foreach (preg_split('/\R/', $bedText) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#'
                        || str_starts_with($line, 'track ') || str_starts_with($line, 'browser ')) {
                        continue;
                    }
                    $rows++;
                }
                $entry['bed'] = basename($bedUrl);
                $entry['bed_url'] = $served === '' ? '' : $served . $bedUrl;
                $entry['rows'] = $rows;
            }
        }
        $datasets[$dataset]['groups'][$group]['tracks'][] = $entry;
        $datasets[$dataset]['groups'][$group]['shortLabel'] = $byName[$group]['shortLabel'] ?? $entry['shortLabel'];
        $datasets[$dataset]['groups'][$group]['longLabel'] = $byName[$group]['longLabel'] ?? $entry['longLabel'];
        $datasets[$dataset]['groups'][$group]['container'] = $byName[$group]['container'] ?? '';
        // Which supertrack this group sits in. A group is usually a container, whose
        // own stanza names the section as its parent; a stand-alone track is its own
        // group and the section is then the group name itself. A consumer cannot work
        // this out from the group name - an overview entry rendered as a multiWig is
        // called ov_mcm, not oridb_overview - and the panel needs it to know which
        // tracks the hub means to be drawn by default.
        $parent = $byName[$group]['parent'] ?? '';
        $datasets[$dataset]['groups'][$group]['section'] =
            $parent !== '' ? explode(' ', $parent)[0] : $group;
    }

    $out = [];
    foreach ($datasets as $id => $d) {
        $record = [];
        $json = hub_get($src, hub_join($tdbDir, $id . '/dataset.json'));
        if ($json !== null) {
            $record = json_decode($json, true) ?: [];
        }
        $groups = [];
        foreach ($d['groups'] as $name => $grp) {
            $groups[] = [
                'track' => $name,
                'shortLabel' => $grp['shortLabel'],
                'longLabel' => $grp['longLabel'],
                'container' => $grp['container'],
                'section' => $grp['section'],
                'tracks' => $grp['tracks'],
            ];
        }
        // A dataset describes its sequencing input either as per-sample records or, when
        // the samples are not individually tracked, as the archive runs it was built
        // from. Carry both counts so the page can say what there is without assuming.
        $source = $record['source'] ?? [];
        $out[] = [
            'id' => $id,
            'title' => $record['title'] ?? $id,
            'organism' => $record['organism'] ?? '',
            'assembly' => $record['assembly'] ?? $g['genome'],
            'bin_size_bp' => $record['bin_size_bp'] ?? null,
            'measurement' => $record['measurement'] ?? '',
            'scale' => $record['scale'] ?? '',
            'source' => [
                'doi' => $source['doi'] ?? '',
                'pubmed' => $source['pubmed'] ?? '',
                'archive_series' => $source['archive_series'] ?? [],
                'n_runs' => count($source['runs'] ?? []),
                'n_samples_used' => count($source['samples_used'] ?? []),
                'n_samples_excluded' => count($source['samples_excluded'] ?? []),
                'exclusion_reason' => $source['exclusion_reason'] ?? '',
            ],
            'n_samples' => count($record['samples'] ?? []),
            // A dataset that calls features from its own profile says so here: what the
            // calls are and what they are not. The count is read from the file itself.
            'calls' => isset($record['calls']) ? [
                'file' => $record['calls']['file'] ?? '',
                'definition' => $record['calls']['definition'] ?? '',
                'caveat' => $record['calls']['caveat'] ?? '',
            ] : null,
            'groups' => $groups,
        ];
    }
    // Which URL parameter opens this genome at UCSC. `db=` names a UCSC database and
    // nothing else; an assembly the hub supplies itself, and an accession-named GenArk
    // assembly that UCSC hosts but does not have a database for, are both addressed as
    // `genome=`. Sending db= for either loads no assembly and shows no tracks, silently.
    // The distinction is recorded here rather than guessed at by the page.
    $isAccession = (bool) preg_match('/^GC[AF]_/', $g['genome']);
    $genomes[] = [
        'genome' => $g['genome'],
        'assembly_hub' => isset($g['twoBitPath']),
        'genark' => $isAccession && !isset($g['twoBitPath']),
        'ucsc_param' => (isset($g['twoBitPath']) || $isAccession) ? 'genome' : 'db',
        // The assembly these data are curated on, which is not always what UCSC calls
        // the genome: OriDB's pombe records say ASM294v2, UCSC's GenArk copy of the same
        // assembly is GCF_000002945.1. Taken from the datasets themselves.
        'assembly' => $out[0]['assembly'] ?? $g['genome'],
        'datasets' => $out,
    ];
}

if ($errors !== []) {
    fwrite(STDERR, "Could not read the hub from {$src['base']}:\n  " . implode("\n  ", $errors) . "\n");
    fwrite(STDERR, "Nothing written; public/data/datahub.json is unchanged.\n");
    exit(1);
}
if ($genomes === [] || $nTracks === 0) {
    fwrite(STDERR, "No tracks found in the hub at {$src['base']}; nothing written.\n");
    exit(1);
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
        'read_from' => $src['mode'] === 'http' ? $src['base'] : 'local checkout',
    ],
    'genomes' => $genomes,
    'counts' => [
        'genomes' => count($genomes),
        'datasets' => array_sum(array_map(static fn (array $g): int => count($g['datasets']), $genomes)),
        'tracks' => $nTracks,
        'files' => $nFiles,
    ],
];

// Written in one move, so an interrupted run cannot leave a truncated file behind.
$dest = $root . '/public/data/datahub.json';
$tmp = $dest . '.tmp';
file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
rename($tmp, $dest);
printf(
    "%s: %d genome(s), %d dataset(s), %d track(s) over %d file(s), read from %s -> %s\n",
    $payload['hub']['name'],
    $payload['counts']['genomes'],
    $payload['counts']['datasets'],
    $payload['counts']['tracks'],
    $payload['counts']['files'],
    $src['mode'] === 'http' ? $src['base'] : $src['base'] . '/',
    $dest
);
