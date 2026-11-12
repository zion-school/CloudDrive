<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// Wavefront OBJ: "v x y z" vertices and "f a b c …" polygons (1-based, negative = counted back). Colours: "Kd" of the
// materials in a .mtl file next to it (mtllib / usemtl). OBJ is usually Y-up: turned Z-up like the other formats.

final class ModelObj {
    /** Triangles in batches for ModelRaster::mesh(), read straight from the file each time. */
    public static function source(string $full): callable {
        return function () use ($full) {
            $fp = fopen($full, 'rb') ?: throw new Fail('Could not open this file', 500);
            try {
                $v   = '';    // vertices so far, packed: 12 bytes each
                $nv  = 0;
                $mtl = [];
                $col = ModelRaster::COLOR;
                $xyz = $rgb = [];
                while (($line = fgets($fp)) !== false) {
                    $line = ltrim($line);
                    $c    = $line[0] ?? '';
                    if ($c === 'v' && in_array($line[1] ?? '', [' ', "\t"], true)) {
                        $p = sscanf($line, 'v %f %f %f');
                        $v .= pack('g3', (float) $p[0], -(float) $p[2], (float) $p[1]);
                        $nv++;
                    } elseif ($c === 'f' && in_array($line[1] ?? '', [' ', "\t"], true)) {
                        $ids = [];
                        foreach (preg_split('/\s+/', trim(substr($line, 2))) as $tok) {
                            $i = (int) $tok; // "7/3/1" → 7
                            $i = $i < 0 ? $nv + $i : $i - 1;
                            if ($i < 0 || $i >= $nv) {
                                continue 2;
                            }
                            $ids[] = 12 * $i;
                        }
                        for ($k = 2, $n = count($ids); $k < $n; $k++) { // polygons as a fan of triangles
                            array_push($xyz, ...unpack('g3', $v, $ids[0]), ...unpack('g3', $v, $ids[$k - 1]), ...unpack('g3', $v, $ids[$k]));
                            $rgb[] = $col;
                        }
                        if (count($rgb) >= 4096) {
                            yield [$xyz, $rgb];
                            $xyz = $rgb = [];
                        }
                    } elseif (str_starts_with($line, 'usemtl')) {
                        $col = $mtl[trim(substr($line, 6))] ?? ModelRaster::COLOR;
                    } elseif (str_starts_with($line, 'mtllib')) {
                        $mtl += self::materials(dirname($full), trim(substr($line, 6)));
                    }
                }
                if ($rgb) {
                    yield [$xyz, $rgb];
                }
            } finally {
                fclose($fp);
            }
        };
    }

    /** name => 0xRRGGBB from a .mtl file in the OBJ's folder (or below it). */
    private static function materials(string $dir, string $name): array {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || $name[0] === '/' || in_array('..', explode('/', $name), true) || ! is_file("$dir/$name")
            || filesize("$dir/$name") > 4 << 20) {
            return [];
        }
        $out = [];
        $cur = null;
        foreach (file("$dir/$name", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'newmtl')) {
                $cur = trim(substr($line, 6));
            } elseif ($cur !== null && preg_match('/^Kd\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $m)) {
                $out[$cur] = self::rgb((float) $m[1], (float) $m[2], (float) $m[3]);
            }
        }
        return $out;
    }

    private static function rgb(float $r, float $g, float $b): int {
        $c = fn($x) => max(0, min(255, (int) round($x * 255)));
        return ($c($r) << 16) | ($c($g) << 8) | $c($b);
    }
}
