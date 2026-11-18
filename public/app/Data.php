<?php
declare(strict_types=1);

/**
 * Read-only accessor over the released flat files.
 *
 * This is the ONLY place that knows where the data physically lives. When the
 * trial grows a MariaDB back end, re-implement these methods against PDO and
 * the pages do not change.
 */
final class Data
{
    /** @var array<string, list<array<string,string>>> */
    private static array $tables = [];
    /** @var array<string, array<string, list<array<string,string>>>> */
    private static array $indexes = [];

    /** @return list<array<string,string>> */
    private static function table(string $name): array
    {
        if (isset(self::$tables[$name])) {
            return self::$tables[$name];
        }
        $path = ORIDB_DATA_DIR . '/' . $name . '.tsv';
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open data file: {$name}.tsv");
        }
        $header = explode("\t", rtrim((string) fgets($fh), "\r\n"));
        $rows = [];
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $fields = explode("\t", $line);
            if (count($fields) !== count($header)) {
                continue; // malformed line: skip rather than mis-assign columns
            }
            $rows[] = array_combine($header, $fields);
        }
        fclose($fh);

        return self::$tables[$name] = $rows;
    }

    /** @return array<string, list<array<string,string>>> */
    private static function indexBy(string $name, string $column): array
    {
        $key = $name . ':' . $column;
        if (isset(self::$indexes[$key])) {
            return self::$indexes[$key];
        }
        $idx = [];
        foreach (self::table($name) as $row) {
            $idx[$row[$column]][] = $row;
        }

        return self::$indexes[$key] = $idx;
    }

    /** @return list<array<string,string>> */
    public static function origins(): array
    {
        return self::table('origins');
    }

    /** @return array<string,string>|null */
    public static function origin(string $accession): ?array
    {
        $idx = self::indexBy('origins', 'accession');

        return $idx[$accession][0] ?? null;
    }

    /** @return list<array<string,string>> */
    public static function evidenceFor(string $accession): array
    {
        return self::indexBy('evidence', 'accession')[$accession] ?? [];
    }

    /** @return list<array<string,string>> every ACS element, placed or not */
    public static function elements(): array
    {
        return self::table('elements');
    }

    /** The assembly the placed elements are on, read from the records themselves. */
    public static function elementAssembly(): string
    {
        foreach (self::elements() as $e) {
            if ($e['assembly'] !== '') {
                return $e['assembly'];
            }
        }

        return '';
    }

    /**
     * ACS elements linked to an origin, most specific relation first.
     *
     * @return list<array<string,string>>
     */
    public static function elementsFor(string $accession): array
    {
        $rows = self::indexBy('elements', 'origin_accession')[$accession] ?? [];
        usort($rows, static function (array $a, array $b): int {
            $rank = ['contained' => 0, 'overlapping' => 1];
            return [$rank[$a['relation']] ?? 2, (int) $a['start']]
                <=> [$rank[$b['relation']] ?? 2, (int) $b['start']];
        });

        return $rows;
    }

    /** @return array<string,string>|null */
    public static function study(string $pmid): ?array
    {
        if ($pmid === '') {
            return null;
        }

        return self::indexBy('studies', 'pmid')[$pmid][0] ?? null;
    }

    /** @return list<array<string,string>> */
    public static function studies(): array
    {
        return self::table('studies');
    }

    /** @return array<string,mixed> */
    public static function release(): array
    {
        static $release = null;
        if ($release === null) {
            $json = (string) file_get_contents(ORIDB_DATA_DIR . '/release.json');
            $release = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        return $release;
    }

    /**
     * Filter origins. All arguments are optional; omitted filters match everything.
     *
     * @return list<array<string,string>>
     */
    public static function query(
        ?string $species = null,
        ?string $chromosome = null,
        ?string $text = null,
        ?int $from = null,
        ?int $to = null,
    ): array {
        $text = $text !== null ? mb_strtolower(trim($text)) : null;
        $out = [];
        foreach (self::origins() as $row) {
            if ($species !== null && $species !== '' && $row['species'] !== $species) {
                continue;
            }
            if ($chromosome !== null && $chromosome !== '' && $row['chromosome'] !== $chromosome) {
                continue;
            }
            if ($from !== null && (int) $row['end'] < $from) {
                continue;
            }
            if ($to !== null && (int) $row['start'] > $to) {
                continue;
            }
            if ($text !== null && $text !== '') {
                $hay = mb_strtolower(
                    $row['accession'] . ' ' . $row['name'] . ' ' . $row['systematic'] . ' '
                    . $row['other_names'] . ' ' . $row['legacy_id']
                );
                if (!str_contains($hay, $text)) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<string> */
    public static function speciesList(): array
    {
        $s = [];
        foreach (self::origins() as $row) {
            $s[$row['species']] = true;
        }

        return array_keys($s);
    }

    /** @return list<string> chromosomes present for a species, in file order */
    public static function chromosomes(string $species): array
    {
        $c = [];
        foreach (self::origins() as $row) {
            if ($row['species'] === $species) {
                $c[$row['chromosome']] = true;
            }
        }

        return array_keys($c);
    }

    /** Outbound link to the matching genome browser view. */
    public static function browserUrl(array $origin): string
    {
        $pos = $origin['chromosome'] . ':' . $origin['start'] . '-' . $origin['end'];
        if (str_starts_with($origin['assembly'], 'sacCer')) {
            // The db follows the record's own assembly, so a link cannot point at a
            // build the coordinates do not belong to - which is what OriDB v2 did.
            return 'https://genome.ucsc.edu/cgi-bin/hgTracks?db=' . rawurlencode($origin['assembly'])
                . '&position=' . rawurlencode($pos);
        }

        return 'https://fungi.ensembl.org/Schizosaccharomyces_pombe/Location/View?r='
            . rawurlencode($origin['chromosome'] . ':' . $origin['start'] . '-' . $origin['end']);
    }
}
