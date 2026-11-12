<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// STL, binary or ASCII. Binary files often start with "solid" too, so the size decides: 84 + 50 bytes per triangle.

final class ModelStl {
    /** Triangles in batches for ModelRaster::mesh(), read straight from the file each time. */
    public static function source(string $full): callable {
        return function () use ($full) {
            $fp = fopen($full, 'rb') ?: throw new Fail('Could not open this file', 500);
            try {
                $size = filesize($full);
                $head = (string) fread($fp, 84);
                $n    = strlen($head) === 84 ? unpack('V', $head, 80)[1] : 0;
                $text = 84 + 50 * $n !== $size && preg_match('/^\s*solid\b/', $head) && str_contains($head . fread($fp, 1024), 'facet');
                if ($text) {
                    fseek($fp, 0);
                    yield from self::ascii($fp);
                } else {
                    yield from self::binary($fp, min($n, intdiv(max(0, $size - 84), 50)));
                }
            } finally {
                fclose($fp);
            }
        };
    }

    private static function binary($fp, int $n) {
        fseek($fp, 84);
        while ($n > 0) {
            $k   = min($n, 4096);
            $buf = (string) fread($fp, 50 * $k);
            $k   = intdiv(strlen($buf), 50);
            if (! $k) {
                break;
            }
            $v = [];
            for ($i = 0, $o = 12; $i < $k; $i++, $o += 50) { // skip the stored normal: it is computed anyway
                array_push($v, ...unpack('g9', $buf, $o));
            }
            yield [$v, ModelRaster::COLOR];
            $n -= $k;
        }
    }

    private static function ascii($fp) {
        $v = [];
        while (($line = fgets($fp)) !== false) {
            if (preg_match('/^\s*vertex\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $m)) {
                array_push($v, (float) $m[1], (float) $m[2], (float) $m[3]);
                if (count($v) >= 9 * 4096) {
                    yield [$v, ModelRaster::COLOR];
                    $v = [];
                }
            }
        }
        if ($n = count($v) - count($v) % 9) {
            yield [array_slice($v, 0, $n), ModelRaster::COLOR];
        }
    }
}
