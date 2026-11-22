<?php
declare(strict_types=1);

/**
 * Rendering helpers for origin elements: the citation of a study, the marked
 * regions of a motif, the motif/alignment panel, and the overview figure.
 *
 * These are presentation only. Everything they draw comes from the element
 * records and from release.json, so nothing here states a fact of its own: a
 * motif whose geometry or consensus changes redraws, and a motif that is not in
 * release.json is simply not drawn.
 */

/**
 * A study citation, degrading gracefully when the study is not in studies.tsv.
 *
 * An element or an evidence record may cite a publication that the study table
 * does not carry yet. The record is still real, so the PubMed identifier is
 * shown and linked rather than the internal table name, and the gap is named.
 */
function cite_pmid(string $pmid, string $fallback = ''): string
{
    $study = $pmid !== '' ? Data::study($pmid) : null;
    if ($study !== null) {
        $author = explode(',', $study['first_author'])[0];
        $label = h($author) . ((int) $study['n_authors'] > 1 ? ' <em>et al.</em>' : '')
               . ' ' . h($study['year']);

        return '<a href="https://pubmed.ncbi.nlm.nih.gov/' . h($study['pmid'])
             . '/" rel="noopener">' . $label . '</a>';
    }
    if ($pmid !== '') {
        return '<a href="https://pubmed.ncbi.nlm.nih.gov/' . h($pmid) . '/" rel="noopener">PMID&nbsp;'
             . h($pmid) . '</a> <span class="small">(citation not yet in the study table)</span>';
    }

    return '<span class="small">'
         . ($fallback !== '' ? h($fallback) . ' &mdash; no citation recorded in v2' : 'no citation recorded in v2')
         . '</span>';
}

/**
 * The regions of a motif window that are worth marking, as 1-based inclusive
 * column spans. Derived from release.json, never written down here.
 *
 * A motif that declares `core_columns` (the ACS matrix does) is marked at that
 * core, and a second region is taken as the 3'-most run of columns whose
 * information content exceeds the mean of the columns outside the core. For the
 * ACS window that recovers the B1-region WTW signal, which sits past a trough at
 * background level - the point of the rule is that the span follows the matrix
 * rather than a number typed into a page.
 *
 * A motif without a declared core (the B2 matrix) is marked at its informative
 * block: the run containing the strongest column, bounded where information
 * falls below the window mean.
 *
 * @return list<array{start:int,end:int,key:string,label:string}>
 */
function motif_regions(array $motif): array
{
    $ic = array_map('floatval', $motif['information_by_column'] ?? []);
    $n = count($ic);
    if ($n === 0) {
        return [];
    }
    $regions = [];
    $core = $motif['core_columns'] ?? null;

    if (is_array($core) && count($core) === 2) {
        $cs = (int) $core[0];
        $ce = (int) $core[1];
        $regions[] = ['start' => $cs, 'end' => $ce, 'key' => 'core', 'label' => 'A core'];

        $outside = [];
        foreach ($ic as $i => $bits) {
            $col = $i + 1;
            if ($col < $cs || $col > $ce) {
                $outside[] = $bits;
            }
        }
        if ($outside !== []) {
            $cut = array_sum($outside) / count($outside);
            $end = $n;
            while ($end > $ce && $ic[$end - 1] <= $cut) {
                $end--;
            }
            $start = $end;
            while ($start - 1 > $ce && $ic[$start - 2] > $cut) {
                $start--;
            }
            if ($end > $ce && $start > $ce + 1) {
                $regions[] = ['start' => $start, 'end' => $end, 'key' => 'b1', 'label' => 'B1'];
            }
        }

        return $regions;
    }

    $cut = array_sum($ic) / $n;
    $peak = 0;
    foreach ($ic as $i => $bits) {
        if ($bits > $ic[$peak]) {
            $peak = $i;
        }
    }
    $start = $peak + 1;
    while ($start > 1 && $ic[$start - 2] > $cut) {
        $start--;
    }
    $end = $peak + 1;
    while ($end < $n && $ic[$end] > $cut) {
        $end++;
    }
    $regions[] = ['start' => $start, 'end' => $end, 'key' => 'core', 'label' => 'conserved block'];

    return $regions;
}

/** The region of a given key, or null. */
function motif_region(array $regions, string $key): ?array
{
    foreach ($regions as $r) {
        if ($r['key'] === $key) {
            return $r;
        }
    }

    return null;
}

/** The IUPAC code of a consensus column expanded to the bases it accepts. */
function motif_code_bases(string $code): string
{
    $map = ['W' => 'AT', 'Y' => 'CT', 'R' => 'AG', 'S' => 'CG', 'M' => 'AC',
            'K' => 'GT', 'B' => 'CGT', 'D' => 'AGT', 'H' => 'ACT', 'V' => 'ACG',
            'N' => 'ACGT', 'n' => 'ACGT'];

    return $map[$code] ?? $code;
}

/**
 * The logo, the consensus, the marked regions and one row per element, each base
 * tinted by agreement with the consensus.
 *
 * The column width and the logo's left gutter are passed in by the caller, which
 * reads them from release.json; nothing here knows a pixel geometry of its own.
 *
 * @param list<array<string,string>> $elements elements of this class, already placed
 */
function motif_panel(array $motif, array $elements, int $colw, int $padl, string $logoAlt): void
{
    $consensus = (string) $motif['consensus'];
    $width = strlen($consensus);
    $regions = motif_regions($motif);
    $colClass = [];
    foreach ($regions as $r) {
        for ($c = $r['start']; $c <= $r['end']; $c++) {
            $colClass[$c] = $r['key'];
        }
    }
    ?>
  <div class="alnbox" style="--colw: <?= h((string) $colw) ?>px; --padl: <?= h((string) $padl) ?>px">
    <img src="<?= h(Data::asset((string) $motif['logo'])) ?>" alt="<?= h($logoAlt) ?>" class="logo">
    <table class="aln">
      <tbody>
        <tr class="cons">
          <td class="lbl">consensus</td>
          <?php for ($c = 1; $c <= $width; $c++):
              $key = $colClass[$c] ?? '';
              $cls = 'b' . ($key !== '' ? ' ' . $key : '')
                   . (($key !== '' && ($colClass[$c - 1] ?? '') !== $key) ? ' cs' : '')
                   . (($key !== '' && ($colClass[$c + 1] ?? '') !== $key) ? ' ce' : ''); ?>
            <td class="<?= h($cls) ?>"><?= h($consensus[$c - 1]) ?></td>
          <?php endfor; ?>
          <td class="sc">score</td>
        </tr>
        <tr class="reg">
          <td class="lbl">region</td>
          <?php
          $c = 1;
          while ($c <= $width):
              $here = null;
              foreach ($regions as $r) {
                  if ($r['start'] === $c) {
                      $here = $r;
                  }
              }
              if ($here !== null):
                  $span = $here['end'] - $here['start'] + 1; ?>
            <td class="rg rg-<?= h($here['key']) ?>" colspan="<?= h((string) $span) ?>"><?= h($here['label']) ?></td>
              <?php $c += $span;
              else: ?>
            <td class="rg"></td>
              <?php $c++;
              endif;
          endwhile; ?>
          <td class="sc"></td>
        </tr>
        <?php foreach ($elements as $e):
            $confirmed = $e['element_type'] === 'confirmed';
            $window = (string) $e['motif_window']; ?>
          <tr class="<?= $confirmed ? 'conf' : 'pred' ?>">
            <td class="lbl mono"><?= h($e['accession']) ?></td>
            <?php for ($c = 1; $c <= $width; $c++):
                $base = $window[$c - 1] ?? ' ';
                $ok = str_contains(motif_code_bases($consensus[$c - 1]), $base);
                $key = $colClass[$c] ?? '';
                $cls = 'b ' . ($ok ? 'm' : 'x')
                     . ($c >= (int) $e['published_start'] && $c <= (int) $e['published_end'] ? ' pub' : '')
                     . ($key !== '' ? ' ' . $key : '')
                     . (($key !== '' && ($colClass[$c - 1] ?? '') !== $key) ? ' cs' : '')
                     . (($key !== '' && ($colClass[$c + 1] ?? '') !== $key) ? ' ce' : ''); ?>
              <td class="<?= h($cls) ?>"><?= h($base) ?></td>
            <?php endfor; ?>
            <td class="sc"><?= h($e['motif_score']) ?> bits<br>
              <span class="small"><?= h($e['motif_score_pct']) ?>%, <?= h($e['length']) ?> bp,
              <span class="ev"><?= h($e['element_type']) ?></span></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
    <?php
}

/**
 * Where the elements sit inside the origin, to scale.
 *
 * Geometry: one horizontal axis, the origin interval drawn on it as a box, A
 * elements in lanes above it and B2 elements in lanes below. The two classes get
 * their own side because they overlap each other by tens of bases - B2 sits a
 * median 74 bp from the A element - and at origin scale that is a few pixels.
 * Within a lane, marks that would collide are stacked onto further rows instead
 * of being drawn on top of one another.
 *
 * The drawing window is the origin interval widened to contain every element, so
 * an element that extends past the origin is drawn outside the box rather than
 * clipped to it. Marks are drawn to scale, with a floor of a few pixels so that a
 * short element in a wide origin is still visible.
 *
 * Returns SVG markup; the caller supplies the caption.
 */
/**
 * Which side of the origin line an element is drawn on.
 *
 * A is the ORC-binding element; B1, B2 and B3 are the B elements 3' of it. Only
 * the side is collapsed here - the class itself is kept for the colour, the
 * label and the tooltip, because a B3 is not a B2. Anything unrecognised is
 * treated as a B element rather than silently joining the A lane, which is how
 * the one B3 record in the database was drawn as a confirmed A element.
 */
function element_lane(string $class): string
{
    return $class === 'A' || $class === '' ? 'A' : 'B';
}

/**
 * Fill and edge colours. Colour carries the side of the origin, not the class:
 * every B element is drawn in the same colour, whether it is a B2 or a B3, and
 * only A has a colour of its own. Which B class a mark is comes from the lane
 * label, the tooltip and the records table. Giving each class its own hue made
 * a B3 read as a near-A, which is the confusion worth avoiding.
 */
function element_colours(string $class): array
{
    return element_lane($class) === 'A'
        ? ['confirmed' => '#15467f', 'predicted' => '#b7d0e8', 'edge' => '#15467f']
        : ['confirmed' => '#8a4a12', 'predicted' => '#f0cea4', 'edge' => '#8a4a12'];
}

function element_overview_svg(array $origin, array $elements, string $domId): string
{
    $placed = array_values(array_filter(
        $elements,
        static fn (array $e): bool => $e['start'] !== '' && $e['end'] !== ''
    ));
    if ($placed === []) {
        return '';
    }

    $oStart = (int) $origin['start'];
    $oEnd = (int) $origin['end'];
    $winStart = $oStart;
    $winEnd = $oEnd;
    foreach ($placed as $e) {
        $winStart = min($winStart, (int) $e['start']);
        $winEnd = max($winEnd, (int) $e['end']);
    }
    $pad = max(5, (int) ceil(($winEnd - $winStart + 1) * 0.05));
    $winStart -= $pad;
    $winEnd += $pad;
    $winSpan = max(1, $winEnd - $winStart + 1);

    $viewW = 860;
    $gutter = 76;            // left margin, for the lane labels
    $plotW = $viewW - $gutter - 14;
    $x = static fn (int $bp): float => $gutter + ($bp - $winStart) / $winSpan * $plotW;

    // Lane packing: a mark needs its own row unless it clears everything already
    // on that row by a few pixels, so that two elements 20 bp apart stay readable.
    // Packed by side, not by class: B2 and B3 share the lower lane and stack
    // against each other, so two B elements 20 bp apart still read separately.
    $lanes = ['A' => [], 'B' => []];
    foreach ($placed as $e) {
        $class = (string) ($e['element_class'] ?? 'A');
        $lane = element_lane($class);
        $x1 = $x((int) $e['start']);
        $x2 = max($x1 + 3.0, $x((int) $e['end'] + 1));
        $row = 0;
        while (isset($lanes[$lane][$row]) && $lanes[$lane][$row]['until'] > $x1 - 4.0) {
            $row++;
        }
        $lanes[$lane][$row]['until'] = $x2;
        $lanes[$lane][$row]['marks'][] = ['e' => $e, 'class' => $class, 'x1' => $x1, 'x2' => $x2];
    }
    $rowsA = count($lanes['A']);
    $rowsB = count($lanes['B']);

    $rowH = 13.0;
    $markH = 9.0;
    $topPad = 20.0;
    $axisH = 12.0;
    $laneAH = $rowsA * $rowH;
    // The gap above the axis also carries the two coordinate labels, so it has to
    // clear a mark sitting on the lowest A row.
    $axisY = $topPad + $laneAH + 15.0;
    $laneBY = $axisY + $axisH + 6.0;
    $scaleY = $laneBY + $rowsB * $rowH + 20.0;
    $legendY = $scaleY + 22.0;
    $viewH = (int) ceil($legendY + 14.0);

    // Counted per class, so a class that is present gets a legend entry and one
    // that is not stays out of the figure entirely.
    $counts = [];
    $laneTotals = ['A' => 0, 'B' => 0];
    $outside = 0;
    foreach ($placed as $e) {
        $class = (string) ($e['element_class'] ?? 'A');
        $type = $e['element_type'] === 'confirmed' ? 'confirmed' : 'predicted';
        $counts[$class][$type] = ($counts[$class][$type] ?? 0) + 1;
        $laneTotals[element_lane($class)]++;
        if ((int) $e['start'] < $oStart || (int) $e['end'] > $oEnd) {
            $outside++;
        }
    }
    uksort($counts, static fn (string $a, string $b): int => strcmp($a, $b));

    // A round number for the scale bar: the 1-2-5 step nearest a fifth of the view.
    $target = $winSpan / 5;
    $pow = 10 ** (int) floor(log10(max(1.0, $target)));
    $step = $pow;
    foreach ([1, 2, 5, 10] as $mult) {
        if ($mult * $pow <= $target) {
            $step = $mult * $pow;
        }
    }
    $step = (int) max(1, $step);

    $titleId = $domId . '-t';
    $descId = $domId . '-d';
    $parts = [];
    foreach ($counts as $class => $byType) {
        $conf = $byType['confirmed'] ?? 0;
        $pred = $byType['predicted'] ?? 0;
        $n = $conf + $pred;
        if ($n > 0) {
            $parts[] = $n . ' ' . $class . ' element' . ($n === 1 ? '' : 's')
                . ' (' . $conf . ' confirmed, ' . $pred . ' predicted)';
        }
    }
    $title = 'Positions of the elements in ' . ($origin['name'] !== '' ? $origin['name'] : $origin['accession']);
    $desc = implode(' and ', $parts) . ' drawn to scale on '
          . $origin['chromosome'] . ':' . bp($oStart) . '-' . bp($oEnd)
          . ' (' . bp($oEnd - $oStart + 1) . ' bp). A elements are drawn above the origin interval '
          . 'and B elements below it, each stacked onto its own row where marks would overlap. '
          . ($outside > 0
              ? $outside . ($outside === 1 ? ' element extends' : ' elements extend') . ' beyond the origin interval. '
              : 'All of them lie inside the origin interval. ')
          . 'Every element is listed with its exact coordinates in the table below.';

    $svg = [];
    $svg[] = '<svg class="elfig" viewBox="0 0 ' . $viewW . ' ' . $viewH . '" role="img" '
           . 'aria-labelledby="' . h($titleId) . ' ' . h($descId) . '" xmlns="http://www.w3.org/2000/svg">';
    $svg[] = '<title id="' . h($titleId) . '">' . h($title) . '</title>';
    $svg[] = '<desc id="' . h($descId) . '">' . h($desc) . '</desc>';

    // The origin's own edges, carried through the whole figure so that an element
    // outside the interval reads as outside at a glance.
    foreach ([$oStart, $oEnd + 1] as $edgeBp) {
        $svg[] = sprintf(
            '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#9bb0c4" stroke-width="1" stroke-dasharray="3 3"/>',
            $x($edgeBp),
            $topPad - 6.0,
            $x($edgeBp),
            $laneBY + $rowsB * $rowH + 2.0
        );
    }

    $svg[] = sprintf(
        '<text x="%.1f" y="12" font-size="11" fill="#5b6671">%s</text>',
        (float) $gutter,
        h($origin['chromosome'] . ':' . bp($oStart) . '-' . bp($oEnd)
          . ' (' . bp($oEnd - $oStart + 1) . ' bp, ' . $origin['assembly'] . ')')
    );

    // Lanes.
    foreach (['A' => $topPad, 'B' => $laneBY] as $lane => $laneTop) {
        $rows = $lanes[$lane] ?? [];
        if ($rows === []) {
            continue;
        }
        // The lane is labelled by the side it draws, not by the classes in it:
        // the lower lane holds every B element, and labelling it "B2" or
        // "B2, B3" made the lane read as a class. Which B class a mark is comes
        // from the tooltip and the records table, as with the colours.
        $svg[] = sprintf(
            '<text x="%.1f" y="%.1f" font-size="11" text-anchor="end" fill="#1b1f23">%s</text>',
            $gutter - 8.0,
            $laneTop + $markH,
            h($lane . ' (' . $laneTotals[$lane] . ')')
        );
        foreach ($rows as $r => $row) {
            $y = $laneTop + $r * $rowH;
            foreach ($row['marks'] as $m) {
                $e = $m['e'];
                $class = $m['class'];
                $colour = element_colours($class);
                $type = $e['element_type'] === 'confirmed' ? 'confirmed' : 'predicted';
                $w = $m['x2'] - $m['x1'];
                $tip = $e['accession'] . ': ' . $class . ' element, ' . $type . ', '
                     . $e['chromosome'] . ':' . bp($e['start']) . '-' . bp($e['end'])
                     . ' (' . $e['length'] . ' bp, ' . ($e['strand'] !== '' ? $e['strand'] : '?') . ' strand)';
                // A pointed end shows the strand when there is room for one.
                if ($w >= 11.0 && ($e['strand'] === '+' || $e['strand'] === '-')) {
                    $tipw = 4.0;
                    $pts = $e['strand'] === '+'
                        ? sprintf(
                            '%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
                            $m['x1'], $y, $m['x2'] - $tipw, $y, $m['x2'], $y + $markH / 2,
                            $m['x2'] - $tipw, $y + $markH, $m['x1'], $y + $markH
                        )
                        : sprintf(
                            '%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
                            $m['x2'], $y, $m['x1'] + $tipw, $y, $m['x1'], $y + $markH / 2,
                            $m['x1'] + $tipw, $y + $markH, $m['x2'], $y + $markH
                        );
                    $svg[] = '<polygon points="' . $pts . '" fill="' . $colour[$type]
                           . '" stroke="' . $colour['edge'] . '" stroke-width="1"><title>' . h($tip) . '</title></polygon>';
                } else {
                    $svg[] = sprintf(
                        '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="1.5" fill="%s" stroke="%s" stroke-width="1"><title>%s</title></rect>',
                        $m['x1'],
                        $y,
                        $w,
                        $markH,
                        $colour[$type],
                        $colour['edge'],
                        h($tip)
                    );
                }
            }
        }
    }

    // The origin interval itself.
    $ox1 = $x($oStart);
    $ox2 = $x($oEnd + 1);
    $svg[] = sprintf(
        '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#eef1f4" stroke="#5b6671" stroke-width="1"/>',
        $ox1,
        $axisY,
        max(2.0, $ox2 - $ox1),
        $axisH
    );
    $svg[] = sprintf(
        '<text x="%.1f" y="%.1f" font-size="11" text-anchor="end" fill="#1b1f23">origin</text>',
        $gutter - 8.0,
        $axisY + $axisH - 2.0
    );
    $svg[] = sprintf(
        '<text x="%.1f" y="%.1f" font-size="10" text-anchor="start" fill="#5b6671">%s</text>',
        $ox1,
        $axisY - 3.0,
        h(bp($oStart))
    );
    $svg[] = sprintf(
        '<text x="%.1f" y="%.1f" font-size="10" text-anchor="end" fill="#5b6671">%s</text>',
        $ox2,
        $axisY - 3.0,
        h(bp($oEnd))
    );

    // Scale bar.
    $sx1 = (float) $gutter;
    $sx2 = $sx1 + $step / $winSpan * $plotW;
    $svg[] = sprintf(
        '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#1b1f23" stroke-width="1"/>'
        . '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#1b1f23" stroke-width="1"/>'
        . '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#1b1f23" stroke-width="1"/>',
        $sx1,
        $scaleY,
        $sx2,
        $scaleY,
        $sx1,
        $scaleY - 3.0,
        $sx1,
        $scaleY + 3.0,
        $sx2,
        $scaleY - 3.0,
        $sx2,
        $scaleY + 3.0
    );
    $svg[] = sprintf(
        '<text x="%.1f" y="%.1f" font-size="10" fill="#5b6671">%s bp</text>',
        $sx2 + 6.0,
        $scaleY + 3.5,
        h(bp($step))
    );

    // Legend, drawn only for the combinations that occur.
    $lx = (float) $gutter;
    // One entry per side, not per class: two swatches of the same colour labelled
    // B2 and B3 would say that the colour distinguishes them, and it does not.
    $laneTypes = ['A' => [], 'B' => []];
    foreach ($counts as $class => $byType) {
        foreach (['confirmed', 'predicted'] as $type) {
            if (($byType[$type] ?? 0) > 0) {
                $laneTypes[element_lane($class)][$type] = true;
            }
        }
    }
    foreach ($laneTypes as $class => $byType) {
        $colour = element_colours($class);
        foreach (['confirmed', 'predicted'] as $type) {
            if (!isset($byType[$type])) {
                continue;
            }
            $svg[] = sprintf(
                '<rect x="%.1f" y="%.1f" width="14" height="9" rx="1.5" fill="%s" stroke="%s" stroke-width="1"/>'
                . '<text x="%.1f" y="%.1f" font-size="10" fill="#1b1f23">%s</text>',
                $lx,
                $legendY - 7.0,
                $colour[$type],
                $colour['edge'],
                $lx + 19.0,
                $legendY,
                h($class . ' ' . $type)
            );
            $lx += 19.0 + 6.6 * strlen($class . ' ' . $type) + 16.0;
        }
    }
    $svg[] = sprintf(
        '<text x="%.1f" y="%.1f" font-size="10" fill="#5b6671">%s</text>',
        $lx,
        $legendY,
        'pointed end = 3\' end of the element'
    );
    $svg[] = '</svg>';

    return implode("\n", $svg);
}
