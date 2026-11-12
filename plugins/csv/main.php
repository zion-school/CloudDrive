<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// CSV / TSV: table view and a simple grid editor (main.js). Delimiter, line endings and BOM are kept.
return new class extends Plugin {
    public array  $exts     = ['csv', 'tsv'];
    public string $label    = 'Spreadsheet (CSV)';
    public bool   $editable = true;
    public int    $maxSize  = 5 << 20;

    const MAX_ROWS = 5000; // bigger files are shown in part and can't be edited (the editor would drop the rest)

    public function view(string $full, array $ctx): array {
        $f    = $this->read($full);
        $rows = $this->parse($f['text'], $f['delimiter']);
        $out  = ['rows' => $rows, 'delimiter' => $f['delimiter']];
        if (count($rows) > self::MAX_ROWS) {
            $out['rows']     = array_slice($rows, 0, self::MAX_ROWS);
            $out['editable'] = false;
            $out['notice']   = sprintf('This file has %s rows. Only the first %s are shown, and it can’t be edited here – download it to see everything.',
                number_format(count($rows)), number_format(self::MAX_ROWS));
        }
        return $out;
    }

    /** Size of the table and how the file is written. */
    public function info(string $full, array $ctx): array {
        if (filesize($full) > $this->maxSize) {
            return [];
        }
        $f    = $this->read($full);
        $rows = $this->parse($f['text'], $f['delimiter']);
        $raw  = (string) file_get_contents($full, false, null, 0, 3);
        return [
            'Table'  => ['Rows'    => count($rows),
                         'Columns' => $rows ? max(array_map('count', $rows)) : 0,
                         'First row' => $rows ? mb_strimwidth(implode(' · ', array_slice($rows[0], 0, 30)), 0, 500, '…', 'UTF-8') : null],
            'Format' => ['Delimiter'    => [',' => 'Comma (,)', ';' => 'Semicolon (;)', "\t" => 'Tab', '|' => 'Pipe (|)'][$f['delimiter']] ?? $f['delimiter'],
                         'Encoding'     => str_starts_with($raw, "\xEF\xBB\xBF") ? 'UTF-8 with BOM' : ($f['bom'] ? 'Windows-1252' : 'UTF-8'), // see read()
                         'Line endings' => $f['eol'] === "\r\n" ? 'Windows (CRLF)' : 'Unix (LF)'],
        ];
    }

    /** $data = ['rows' => [[cell, …], …]]. The file keeps its delimiter, line endings and BOM; it is written as UTF-8. */
    public function save(string $full, mixed $data): string {
        if (! is_array($data) || ! is_array($data['rows'] ?? null)) {
            throw new Fail('Invalid table data', 400);
        }

        $f  = $this->read($full);
        $fp = fopen('php://temp', 'w+');
        foreach ($data['rows'] as $r) {
            if (! is_array($r)) {
                throw new Fail('Invalid table data', 400);
            }
            $cells = array_map(fn($c) => is_scalar($c) ? (string) $c : '', array_values($r));
            if (implode('', $cells) === '') {
                fwrite($fp, $f['eol']); // a blank line (fputcsv would write "" or ;;;)
            } else {
                fputcsv($fp, $cells, $f['delimiter'], '"', '', $f['eol']);
            }
        }
        rewind($fp);
        return ($f['bom'] ? "\xEF\xBB\xBF" : '') . stream_get_contents($fp);
    }

    /** Text as UTF-8 plus the file's format details. Non-UTF-8 files are assumed to be Windows-1252 (Excel's default). */
    private function read(string $full): array {
        $raw  = (string) file_get_contents($full);
        $bom  = str_starts_with($raw, "\xEF\xBB\xBF");
        $raw  = $bom ? substr($raw, 3) : $raw;
        $utf8 = mb_check_encoding($raw, 'UTF-8');
        $text = $utf8 ? $raw : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        return ['text'      => $text,
                'bom'       => $bom || ! $utf8, // re-encoded files get a BOM so Excel reads them as UTF-8
                'eol'       => str_contains($raw, "\r\n") ? "\r\n" : "\n",
                'delimiter' => strtolower(pathinfo($full, PATHINFO_EXTENSION)) === 'tsv' ? "\t" : $this->sniff($text)];
    }

    /** RFC 4180 parsing (quotes doubled, no backslash escapes), quoted fields may span lines. */
    private function parse(string $text, string $delimiter): array {
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, $text);
        rewind($fp);
        $rows = [];
        while (($r = fgetcsv($fp, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $r === [null] ? [''] : $r;
        }
        fclose($fp);
        return $rows;
    }

    /** Picks the delimiter that appears most consistently over the first lines (quoted text ignored). */
    private function sniff(string $text): string {
        $sample = preg_replace('/"(?:[^"]|"")*"/', '', substr($text, 0, 20000)) ?? '';
        $lines  = array_slice(array_values(array_filter(preg_split('/\r\n|\n|\r/', $sample), fn($l) => trim($l) !== '')), 0, 20);
        $best   = [',', 0];
        foreach ([',', ';', "\t", '|'] as $d) {
            $counts = array_map(fn($l) => substr_count($l, $d), $lines);
            $score  = $counts ? min($counts) * 1000 + array_sum($counts) : 0; // present on every line beats frequent
            if ($score > $best[1]) {
                $best = [$d, $score];
            }
        }
        return $best[0];
    }
};
