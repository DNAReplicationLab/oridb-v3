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
    // Shading by tier, so a browser track reads at a glance; the tier itself is
    // column 7, because BED has no field for it and silently dropping it would
    // leave a file whose rows cannot be told apart.
    $shade = ['Confirmed' => 1000, 'Likely' => 700, 'Unsupported' => 400, 'Not assessed' => 200];
    $tiers = [];
    foreach ($rows as $o) {
        $tiers[$o['status']] = ($tiers[$o['status']] ?? 0) + 1;
    }
    ksort($tiers);
    $breakdown = [];
    foreach ($tiers as $tier => $n) {
        $breakdown[] = $n . ' ' . $tier;
    }
    echo "# OriDB {$release['release']} - all origin tiers - assembly {$assembly}\n";
    echo '# ' . implode(', ', $breakdown) . "\n";
    echo "# {$naming}; column 5 shades by tier and column 7 names it; both names and all\n";
    echo "# legacy fields are in the TSV export\n";
    echo 'track name="OriDB origins" description="OriDB ' . $release['release']
       . ' - all tiers" visibility=2 useScore=1' . "\n";
    foreach ($rows as $o) {
        // BED is 0-based half-open; stored coordinates are 1-based inclusive.
        echo implode("\t", [
            $o['chromosome'],
            (int) $o['start'] - 1,
            (int) $o['end'],
            $label($o),
            $shade[$o['status']] ?? 0,
            '.',
            $o['status'],
        ]), "\n";
    }
    exit;
}

// The evidence matrix and the refuted fragments are served as they are stored: both
// are already flat tables and reshaping them would only lose the column names.
if ($format === 'matrix' || $format === 'refuted') {
    $file = $format === 'matrix' ? 'evidence_matrix' : 'refuted_fragments';
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="oridb-' . $file . '-' . $stamp . '.tsv"');
    readfile(ORIDB_DATA_DIR . '/' . $file . '.tsv');
    exit;
}

if ($format === 'elements') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="oridb-elements-' . $stamp . '.bed"');
    // A bare accession in column 4 said nothing about what the feature is, and the
    // score was a hard zero. The class is appended to the name, where a browser shows
    // it as the feature label, and the score carries the evidence, so confirmed and
    // predicted elements shade apart under useScore. Both are also named columns in
    // elements.tsv, for anything that would rather parse than read.
    $elementShade = ['confirmed' => 1000, 'predicted' => 500];
    // Not placed in this assembly; see anchor_status in elements.tsv.
    $placed = array_values(array_filter(
        Data::elements(),
        static fn (array $e): bool => $e['start'] !== ''
    ));
    $byClass = [];
    foreach ($placed as $e) {
        $key = $e['element_class'] . ' ' . $e['element_type'];
        $byClass[$key] = ($byClass[$key] ?? 0) + 1;
    }
    ksort($byClass);
    $breakdown = [];
    foreach ($byClass as $key => $n) {
        $breakdown[] = $n . ' ' . $key;
    }
    echo "# OriDB {$release['release']} - origin elements (A, B2 and B3) - assembly "
        . Data::elementAssembly() . "\n";
    echo '# ' . implode(', ', $breakdown) . "\n";
    echo "# column 4 is the element accession with its class appended; column 5 is 1000 for a\n";
    echo "# confirmed element and 500 for a predicted one. Class, evidence, the origin it lies\n";
    echo "# in and the motif score are named columns in elements.tsv.\n";
    echo 'track name="OriDB origin elements" description="OriDB ' . $release['release']
       . ' - origin elements" visibility=2 useScore=1' . "\n";
    foreach ($placed as $e) {
        echo implode("\t", [$e['chromosome'], (int) $e['start'] - 1, (int) $e['end'],
                            $e['accession'] . '_' . $e['element_class'],
                            $elementShade[$e['element_type']] ?? 500,
                            $e['strand'] ?: '.']), "\n";
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
