<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

$format  = (string) ($_GET['format'] ?? 'tsv');
$species = isset($_GET['species']) ? (string) $_GET['species'] : null;
$rows    = Data::query(species: $species);
$release = Data::release();
$stamp   = preg_replace('/[^A-Za-z0-9._-]/', '', $release['release']);

if ($format === 'bed') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="oridb-' . $stamp . '.bed"');
    $assembly = $rows !== [] ? $rows[0]['assembly'] : 'unknown';
    echo "# OriDB {$release['release']} - Confirmed origins - assembly {$assembly}\n";
    echo 'track name="OriDB Confirmed origins" description="OriDB ' . $release['release'] . '" visibility=2' . "\n";
    foreach ($rows as $o) {
        // BED is 0-based half-open; stored coordinates are 1-based inclusive.
        echo implode("\t", [$o['chromosome'], (int) $o['start'] - 1, (int) $o['end'], $o['accession'], 0, '.']), "\n";
    }
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['release' => $release['release'], 'origins' => []];
    foreach ($rows as $o) {
        $o['evidence_records'] = Data::evidenceFor($o['accession']);
        $out['origins'][] = $o;
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/tab-separated-values; charset=utf-8');
header('Content-Disposition: attachment; filename="oridb-' . $stamp . '.tsv"');
if ($rows !== []) {
    echo implode("\t", array_keys($rows[0])), "\n";
    foreach ($rows as $o) {
        echo implode("\t", array_values($o)), "\n";
    }
}
