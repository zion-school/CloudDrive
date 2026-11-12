<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
/** Shared bits of the XLS and XLSX viewers: limits, number/date display and the sheet grid. */
final class SheetUtil {
    const MAX_ROWS = 2000;
    const MAX_COLS = 100;
    const NOTICE   = 'Simplified preview – only cell values are shown (no formatting, charts or pictures). Download the file to see it exactly.';

    /** Built-in Excel number format ids that are dates or times. */
    const DATE_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    public static function isDate(int $id, ?string $code): bool {
        if (in_array($id, self::DATE_IDS, true)) {
            return true;
        }
        if ($code === null || $code === '') {
            return false;
        }
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code); // drop literals, [colours] and escapes
        return (bool) preg_match('/[dmyhs]/i', $code) && ! preg_match('/^[#0.,%\s]*$/', $code);
    }

    /** Display text for a numeric cell. */
    public static function number(float $v, bool $date, bool $date1904 = false): string {
        if ($date && $v >= 0 && $v < 2958466) {
            $days = (int) floor($v);
            $secs = (int) round(($v - $days) * 86400);
            if ($days === 0) {
                return gmdate($secs % 60 ? 'H:i:s' : 'H:i', $secs);
            }
            $ts = ($days - ($date1904 ? 24107 : 25569)) * 86400 + $secs;
            return gmdate($secs ? 'Y-m-d H:i' : 'Y-m-d', $ts);
        }
        if (floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        $s = sprintf('%.10G', $v); // 10 significant digits hides float noise such as 0.1 + 0.2
        return str_contains($s, '.') && ! str_contains($s, 'E') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** Sparse [row => [col => value]] → dense rows for the built-in sheet renderer. */
    public static function dense(array $cells): array {
        if (! $cells) {
            return [];
        }
        $rows = [];
        $last = min(max(array_keys($cells)), self::MAX_ROWS - 1);
        for ($r = 0; $r <= $last; $r++) {
            $row = $cells[$r] ?? [];
            $out = [];
            if ($row) {
                $w = min(max(array_keys($row)), self::MAX_COLS - 1);
                for ($c = 0; $c <= $w; $c++) {
                    $out[] = $row[$c] ?? '';
                }
            }
            $rows[] = $out;
        }
        return $rows;
    }

    /** Plain text of all sheets for search / snippets. */
    public static function text(array $sheets): string {
        $out = [];
        foreach ($sheets as $s) {
            $out[] = $s['name'];
            foreach ($s['rows'] as $r) {
                if (($line = trim(implode("\t", $r))) !== '') {
                    $out[] = $line;
                }
            }
        }
        return implode("\n", $out);
    }
}
