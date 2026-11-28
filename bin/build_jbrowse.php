<?php
declare(strict_types=1);

/**
 * Describe the hub to JBrowse, from the description the site already has.
 *
 *     php bin/build_jbrowse.php [--hub-root DIR]
 *
 * The browser panel on an origin page needs the same facts the Data hub page needs -
 * which assemblies exist, which tracks they carry and where the files are - so this
 * reads public/data/datahub.json rather than parsing the hub a second time. Run
 * bin/build_datahub.php first; this is the step after it.
 *
 * Two things the UCSC hub does not have to say, and JBrowse does:
 *
 *   - the reference sequence. UCSC fetches sacCer3 from its own servers; a browser
 *     running in the reader's page has to be told where the .2bit is.
 *   - which tracks to draw. The hub answers that already: its `overview` section is
 *     one reference track per measurement type, drawn by default, so the panel's
 *     default set is taken from there rather than listed here by hand.
 *
 * Output: public/assets/jbrowse.json, read by assets/origin-browser.js. It goes in
 * assets/ rather than data/ because the panel fetches it over HTTP from the reader's
 * browser, and public/data carries a deny-all .htaccess.
 */

$root = dirname(__DIR__);
$opts = ['hub-root' => dirname($root) . '/oridb-data-hub'];
for ($i = 1; $i < $argc; $i++) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argv[$i], $m)) {
        $opts[$m[1]] = ($m[2] ?? '') !== '' ? $m[2] : ($argv[++$i] ?? '');
    }
}

$hubFile = $root . '/public/data/datahub.json';
if (!is_readable($hubFile)) {
    fwrite(STDERR, "no public/data/datahub.json; run bin/build_datahub.php first\n");
    exit(1);
}
$hub = json_decode((string) file_get_contents($hubFile), true, 512, JSON_THROW_ON_ERROR);
$base = rtrim((string) $hub['hub']['base_url'], '/') . '/';

/** A hub track's `type` line decides which JBrowse track and adapter it becomes. */
/**
 * The lower bound of a trackDb `viewLimits`, in the values the file actually holds.
 *
 * A negated track is stored the right way up and turned over for display, so UCSC's
 * limits are written in the negated space: `viewLimits -85:-25` on a replication time
 * means the file runs from 25 to 85 minutes, and the floor is 25, not -85.
 */
function view_limit_floor(string $viewLimits, bool $negated): ?float
{
    if (!preg_match('/^\s*(-?[\d.]+)\s*:\s*(-?[\d.]+)\s*$/', $viewLimits, $m)) {
        return null;
    }
    [$low, $high] = [(float) $m[1], (float) $m[2]];
    return $negated ? -max($low, $high) : min($low, $high);
}

function adapter_for(string $type, string $url): ?array
{
    if (str_starts_with($type, 'bigWig')) {
        return ['track' => 'QuantitativeTrack',
                'adapter' => ['type' => 'BigWigAdapter', 'bigWigLocation' => ['uri' => $url]]];
    }
    if (str_starts_with($type, 'bigBed')) {
        return ['track' => 'FeatureTrack',
                'adapter' => ['type' => 'BigBedAdapter', 'bigBedLocation' => ['uri' => $url]]];
    }
    return null;   // a type JBrowse has no adapter for is left out rather than guessed at
}

// Origin features are drawn in the tier colours the website uses, read from the
// bigBed's own `tier` field. This is the one display rule written here: the hub
// carries the colours as itemRgb, which JBrowse's feature renderer does not read.
const TIER_COLOUR_JEXL = "jexl:get(feature,'tier')=='Confirmed'?'#1a7f4b'"
    . ":get(feature,'tier')=='Likely'?'#2f7d9b'"
    . ":get(feature,'tier')=='Unsupported'?'#8a8f98':'#c7ccd3'";

$out = [
    'generated' => gmdate('c'),
    'hub' => ['base_url' => $base, 'url' => $hub['hub']['url']],
    'assemblies' => [],
    'tracks' => [],
    'defaults' => [],
];

$missing = [];
foreach ($hub['genomes'] as $g) {
    // Two names, and they are not always the same. $genome is what the hub calls the
    // assembly - for pombe, UCSC's GenArk accession - and so where the files live.
    // $assembly is the assembly the records are curated on, which is what an origin
    // page asks for when it looks up its own assembly field. Keying the config on the
    // curated name is what lets the hub rename a genome without breaking the panel.
    $genome = $g['genome'];
    $assembly = $g['assembly'] ?? $genome;
    $twoBit = "{$genome}/{$genome}.2bit";
    $alias  = "{$genome}/{$genome}.refNameAliases.txt";
    foreach ([$twoBit, $alias, "{$genome}.chrom.sizes"] as $rel) {
        if (!is_readable($opts['hub-root'] . '/' . $rel)) {
            $missing[] = $rel;
        }
    }

    $out['assemblies'][$assembly] = [
        'name' => $assembly,
        'sequence' => [
            'type' => 'ReferenceSequenceTrack',
            'trackId' => $assembly . '-ref',
            'adapter' => [
                'type' => 'TwoBitAdapter',
                'twoBitLocation' => ['uri' => $base . $twoBit],
                // Optional, and worth giving: with it JBrowse reads the sequence
                // names from a 40-byte text file instead of the 2bit's header.
                'chromSizesLocation' => ['uri' => $base . $genome . '.chrom.sizes'],
            ],
        ],
        'refNameAliases' => [
            'adapter' => ['type' => 'RefNameAliasAdapter', 'location' => ['uri' => $base . $alias]],
        ],
    ];

    // One JBrowse track per FILE. The hub deliberately shows the same file in more
    // than one place - a profile appears in its own section and again in the
    // overview - and two tracks over one file would just be the same picture twice.
    $byUrl = [];
    $defaults = [];
    $priority = [];
    $multi = [];
    foreach ($g['datasets'] as $ds) {
        foreach ($ds['groups'] as $group) {
            // An overview container of several bigWigs becomes one overlaid track,
            // so its files are not added to the default list individually below.
            $overlay = ($group['container'] ?? '') === 'multiWig'
                && (($group['section'] ?? $group['track']) === 'oridb_overview')
                && count($group['tracks']) > 1;
            foreach ($group['tracks'] as $t) {
                $a = adapter_for((string) $t['type'], (string) $t['url']);
                if ($a === null) {
                    continue;
                }
                // The hub says which tracks it means to be drawn by default by putting
                // them in the overview supertrack. An overview entry is usually a
                // stand-alone track, and is then its own group; one that overlays
                // several files is a multiWig container whose group is the container,
                // so the test is the section the group sits in, not the group's name.
                $isOverview = ($group['section'] ?? $group['track']) === 'oridb_overview';
                if (!isset($byUrl[$t['url']])) {
                    // How a quantitative track is drawn. `autoscale: local` is
                    // JBrowse's default and is stated anyway, because it is the
                    // behaviour the panel depends on: the y-axis takes the range of
                    // the data in the window, not of the whole genome, so a 5 kb view
                    // of one origin is not flattened by a peak on another chromosome.
                    // `inverted` turns the axis over for a track the hub marked
                    // negateValues, so an early replication time is a peak and an
                    // origin reads as one.
                    //
                    // Two further settings come from the hub rather than from a
                    // judgement made here. A floor, because JBrowse pulls a linear
                    // y-axis down to zero whenever the data are all positive, and a
                    // replication time that runs from 27 to 83 minutes is then drawn
                    // in the top two thirds of the panel with the origins flattened
                    // against the frame: the hub's `viewLimits` already states the
                    // range the profile is worth reading over, so its lower bound is
                    // used as `minScore`. The upper bound is deliberately NOT used -
                    // the maximum stays on local autoscale, so a window still fills
                    // the panel. And `graphTypeDefault points` is a trackDb saying
                    // this profile is a continuous trace rather than a
                    // bar chart, and is drawn here by turning the fill off: a filled
                    // area under a replication time is a block of colour whose top
                    // edge is the only part carrying information.
                    //
                    // The fill is turned off on the XY plot rather than by asking for
                    // JBrowse's own line renderer, which cannot be used on a track
                    // that is drawn upside down. In 4.3.0 LinePlotRenderer computes
                    // its own pixel position, `height - (value - min) * scale`, and
                    // never reads `inverted`; the y-axis labels are drawn by the
                    // display, which does read it, so the two disagree and an early
                    // replication time is drawn as a trough against an axis that says
                    // it is a peak. The XY plot takes `inverted` as a render prop and
                    // honours it.
                    $display = null;
                    if ($a['track'] === 'QuantitativeTrack') {
                        $display = [
                            'type' => 'LinearWiggleDisplay',
                            'displayId' => $assembly . '-' . $t['track'] . '-display',
                            'autoscale' => 'local',
                        ];
                        $inverted = (bool) ($t['negated'] ?? false);
                        if ($inverted) {
                            $display['inverted'] = true;
                        }
                        $floor = view_limit_floor((string) ($t['viewLimits'] ?? ''), $inverted);
                        if ($floor !== null) {
                            $display['minScore'] = $floor;
                        }
                        if (($t['graphType'] ?? '') === 'points') {
                            $display['renderers'] = ['XYPlotRenderer' => ['filled' => false]];
                        }
                    }
                    $byUrl[$t['url']] = [
                        'type' => $a['track'],
                        'trackId' => $assembly . '-' . $t['track'],
                        'name' => $t['shortLabel'],
                        'description' => $t['longLabel'],
                        'assemblyNames' => [$assembly],
                        'category' => [$ds['title']],
                        'adapter' => $a['adapter'],
                    ];
                    if ($display !== null) {
                        $byUrl[$t['url']]['displays'] = [$display];
                    }
                    if (str_ends_with((string) $t['file'], 'origins.bb')) {
                        $byUrl[$t['url']]['displays'] = [[
                            'type' => 'LinearBasicDisplay',
                            'displayId' => $assembly . '-' . $t['track'] . '-display',
                            'renderer' => ['type' => 'SvgFeatureRenderer',
                                           'color1' => TIER_COLOUR_JEXL],
                        ]];
                    }
                }
                if (($isOverview && !$overlay) || ($t['panel_default'] ?? false)) {
                    $defaults[] = $byUrl[$t['url']]['trackId'];
                    $priority[$byUrl[$t['url']]['trackId']] = $t['priority'] ?? 99;
                }
            }
            // An overview container holding more than one bigWig is drawn as ONE
            // overlaid track rather than a row per file, which is what the hub's
            // `aggregate solidOverlay` means and the only way a strand pair reads as a
            // single picture: plus above the axis, minus below, on a shared y-axis.
            // MultiXYPlotRenderer is JBrowse's overlay; MultiRowXYPlotRenderer would
            // stack them and lose the comparison. The per-file tracks stay in the list
            // as well - they are the same URLs, already deduplicated above, and a
            // reader may still want one strand on its own.
            if ($overlay) {
                $subadapters = [];
                foreach ($group['tracks'] as $t) {
                    if (!str_starts_with((string) $t['type'], 'bigWig')) {
                        continue;
                    }
                    $subadapters[] = [
                        'type' => 'BigWigAdapter',
                        'name' => $t['shortLabel'],
                        'bigWigLocation' => ['uri' => $t['url']],
                    ];
                }
                if (count($subadapters) > 1) {
                    $id = $assembly . '-' . $group['track'];
                    $multi[] = [
                        'type' => 'MultiQuantitativeTrack',
                        'trackId' => $id,
                        'name' => $group['shortLabel'],
                        'description' => $group['longLabel'],
                        'assemblyNames' => [$assembly],
                        'category' => [$ds['title']],
                        'adapter' => ['type' => 'MultiWiggleAdapter',
                                      'subadapters' => $subadapters],
                        'displays' => [[
                            'type' => 'MultiLinearWiggleDisplay',
                            'displayId' => $id . '-display',
                            'defaultRendering' => 'xyplot',
                            'renderers' => ['MultiXYPlotRenderer' => ['filled' => true]],
                            'autoscale' => 'local',
                        ]],
                    ];
                    $defaults[] = $id;
                    $priority[$id] = $group['tracks'][0]['priority'] ?? 99;
                }
            }
        }
    }
    // Overlaid tracks first: they are the ones the panel draws, and a reader opening
    // the selector should meet them before the eighteen single files behind them.
    $out['tracks'][$assembly] = array_merge($multi, array_values($byUrl));
    // The panel draws its default tracks in the order of this list, so it is sorted
    // by the hub's own `priority` rather than by the order the datasets happened to
    // be read in. Annotation takes the first priorities, so genes head the panel and
    // the measurements follow in the order the events they capture occur.
    uasort($defaults, fn($a, $b) => $priority[$a] <=> $priority[$b]);
    $out['defaults'][$assembly] = array_values(array_unique($defaults));
}

if ($missing !== []) {
    fwrite(STDERR, "sequence files not found in {$opts['hub-root']}: "
        . implode(', ', $missing) . "\n"
        . "The panel cannot draw an assembly whose sequence it cannot read.\n");
    exit(1);
}

file_put_contents($root . '/public/assets/jbrowse.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

foreach ($out['tracks'] as $assembly => $tracks) {
    printf("%s: %d track(s), %d drawn by default\n",
        $assembly, count($tracks), count($out['defaults'][$assembly]));
}
echo "-> public/assets/jbrowse.json\n";
