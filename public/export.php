<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$format  = (string) ($_GET['format'] ?? 'tsv');
$species = isset($_GET['species']) ? (string) $_GET['species'] : null;
$rows    = Data::query(species: $species);
$release = Data::release();
$stamp   = preg_replace('/[^A-Za-z0-9._-]/', '', $release['release']);

if ($format === 'bed') {
    // BED column 4 holds one name and nothing else, so the caller chooses which
    // one: the stable accession (default), the historical OriDB name, or both
    // joined with a pipe. The accession<->name mapping is in the TSV export.
    $names = (string) ($_GET['names'] ?? 'accession');
    if (!in_array($names, ['accession', 'legacy', 'both'], true)) {
        $names = 'accession';
    }
    $label = static function (array $o) use ($names): string {
        $legacy = $o['name'] !== '' ? $o['name']
            : ($o['systematic'] !== '' ? $o['systematic'] : $o['accession']);
        return match ($names) {
            'legacy' => $legacy,
            'both'   => $o['accession'] . '|' . $legacy,
            default  => $o['accession'],
        };
    };
    $suffix = $names === 'accession' ? '' : '-' . $names . '-names';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="oridb-' . $stamp . $suffix . '.bed"');
    $assembly = $rows !== [] ? $rows[0]['assembly'] : 'unknown';
    $naming = match ($names) {
        'legacy' => 'column 4 is the historical OriDB name (ARS-style, or the systematic name where none exists)',
        'both'   => 'column 4 is accession|historical name',
        default  => 'column 4 is the OriDB accession',
    };
    echo "# OriDB {$release['release']} - Confirmed origins - assembly {$assembly}\n";
    echo "# {$naming}; both names and all legacy fields are in the TSV export\n";
    echo 'track name="OriDB Confirmed origins" description="OriDB ' . $release['release'] . '" visibility=2' . "\n";
    foreach ($rows as $o) {
        // BED is 0-based half-open; stored coordinates are 1-based inclusive.
        echo implode("\t", [$o['chromosome'], (int) $o['start'] - 1, (int) $o['end'], $label($o), 0, '.']), "\n";
    }
    exit;
}

if ($format === 'elements') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="oridb-elements-' . $stamp . '.bed"');
    echo "# OriDB {$release['release']} - origin elements (A and B2) - assembly "
        . Data::elementAssembly() . "\n";
    echo 'track name="OriDB origin elements" description="OriDB ' . $release['release'] . '" visibility=2' . "\n";
    foreach (Data::elements() as $e) {
        if ($e['start'] === '') {
            continue; // not placed in this assembly; see anchor_status in elements.tsv
        }
        echo implode("\t", [$e['chromosome'], (int) $e['start'] - 1, (int) $e['end'],
                             $e['accession'], 0, $e['strand'] ?: '.']), "\n";
    }
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['release' => $release['release'], 'origins' => []];
    foreach ($rows as $o) {
        $o['evidence_records'] = Data::evidenceFor($o['accession']);
        $o['elements'] = Data::elementsFor($o['accession']);
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
