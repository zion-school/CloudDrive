<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// G-code for 3D printers. Slicers (PrusaSlicer, Orca, Bambu Studio, Cura, Creality Print…) usually put base64 preview
// pictures in comments at the top; otherwise the extrusion moves are drawn. Understands G0-G3, G90/G91, M82/M83, G92,
// G20/G21 and G28 – enough for the shape of a print, not a simulation of the printer.

final class ModelGcode {
    /** The largest PNG / JPEG preview in the comments before the first move, as bytes. */
    public static function picture(string $full): ?string {
        $fp = fopen($full, 'rb');
        if (! $fp) {
            return null;
        }
        $best = null;
        $area = 0;
        $cur  = null; // [area, base64 so far] while inside a picture
        for ($read = 0; ($line = fgets($fp, 1 << 16)) !== false && $read < 16 << 20; $read += strlen($line)) {
            if ($cur !== null) {
                if (preg_match('/^;\s*(thumbnail(_\w+)?|png|jpg)\s+end/i', $line)) {
                    $bytes = base64_decode($cur[1], true);
                    if ($bytes !== false && $cur[0] > $area) {
                        [$best, $area] = [$bytes, $cur[0]];
                    }
                    $cur = null;
                } else {
                    $cur[1] .= trim(ltrim($line, "; \t"));
                }
            } elseif (preg_match('/^;\s*(thumbnail(?:_(PNG|JPG|QOI))?|png|jpg)\s+begin\s+(\d+)\s*[x*]\s*(\d+)/i', $line, $m)) {
                $cur = strtoupper($m[2] ?? '') === 'QOI' ? null : [(int) $m[3] * (int) $m[4], '']; // QOI: GD can't read it
            } elseif (preg_match('/^\s*G[0-3]\s/i', $line)) {
                break; // previews come before the print
            }
        }
        fclose($fp);
        return $best;
    }

    /** Extrusion moves in batches of segments (6 coordinates each) for ModelRaster::lines(), read from the file each time. */
    public static function source(string $full): callable {
        return function () use ($full) {
            $fp = fopen($full, 'rb') ?: throw new Fail('Could not open this file', 500);
            try {
                $abs = $eAbs = true;
                $mm  = 1.0;
                $x   = $y = $z = $e = 0.0;
                $out = [];
                while (($line = fgets($fp)) !== false) {
                    $line = ltrim($line);
                    if (! preg_match('/^([GM])(\d+)([^;]*)/i', $line, $m)) {
                        continue;
                    }
                    $cmd = strtoupper($m[1]) . (int) $m[2];
                    preg_match_all('/([XYZEIJR])\s*([-+]?[\d.]+)/i', $m[3], $args, PREG_SET_ORDER);
                    $a = [];
                    foreach ($args as [, $k, $val]) {
                        $a[strtoupper($k)] = (float) $val;
                    }
                    switch ($cmd) {
                        case 'G0':
                        case 'G1':
                        case 'G2':
                        case 'G3':
                            $nx = isset($a['X']) ? ($abs ? 0 : $x) + $a['X'] * $mm : $x;
                            $ny = isset($a['Y']) ? ($abs ? 0 : $y) + $a['Y'] * $mm : $y;
                            $nz = isset($a['Z']) ? ($abs ? 0 : $z) + $a['Z'] * $mm : $z;
                            $ne = isset($a['E']) ? ($eAbs ? 0 : $e) + $a['E'] * $mm : $e;
                            if ($ne > $e + 1e-6 && ($nx != $x || $ny != $y || $cmd === 'G2' || $cmd === 'G3')) {
                                $pts = $cmd === 'G2' || $cmd === 'G3'
                                    ? self::arc($x, $y, $z, $nx, $ny, $nz, $a, $mm, $cmd === 'G2')
                                    : [[$nx, $ny, $nz]];
                                [$px, $py, $pz] = [$x, $y, $z];
                                foreach ($pts as [$qx, $qy, $qz]) {
                                    array_push($out, $px, $py, $pz, $qx, $qy, $qz);
                                    [$px, $py, $pz] = [$qx, $qy, $qz];
                                }
                                if (count($out) >= 6 * 4096) {
                                    yield $out;
                                    $out = [];
                                }
                            }
                            [$x, $y, $z, $e] = [$nx, $ny, $nz, $ne];
                            break;
                        case 'G90':
                            $abs = $eAbs = true;
                            break;
                        case 'G91':
                            $abs = $eAbs = false;
                            break;
                        case 'M82':
                            $eAbs = true;
                            break;
                        case 'M83':
                            $eAbs = false;
                            break;
                        case 'G92':
                            $x = isset($a['X']) ? $a['X'] * $mm : $x;
                            $y = isset($a['Y']) ? $a['Y'] * $mm : $y;
                            $z = isset($a['Z']) ? $a['Z'] * $mm : $z;
                            $e = isset($a['E']) ? $a['E'] * $mm : $e;
                            break;
                        case 'G20':
                            $mm = 25.4;
                            break;
                        case 'G21':
                            $mm = 1.0;
                            break;
                        case 'G28':
                            $all = ! preg_match('/[XYZ]/i', $m[3]);
                            $x   = $all || stripos($m[3], 'X') !== false ? 0.0 : $x;
                            $y   = $all || stripos($m[3], 'Y') !== false ? 0.0 : $y;
                            $z   = $all || stripos($m[3], 'Z') !== false ? 0.0 : $z;
                            break;
                    }
                }
                if ($out) {
                    yield $out;
                }
            } finally {
                fclose($fp);
            }
        };
    }

    /** Points along a G2 (clockwise) / G3 arc from (x, y) to (nx, ny), centre given by I J (relative) or radius R. */
    private static function arc(float $x, float $y, float $z, float $nx, float $ny, float $nz, array $a, float $mm, bool $cw): array {
        if (isset($a['I']) || isset($a['J'])) {
            [$cx, $cy] = [$x + ($a['I'] ?? 0) * $mm, $y + ($a['J'] ?? 0) * $mm];
        } elseif (isset($a['R'])) {
            $r  = $a['R'] * $mm;
            $dx = $nx - $x;
            $dy = $ny - $y;
            $d  = sqrt($dx * $dx + $dy * $dy);
            if ($d == 0 || abs($r) < $d / 2) {
                return [[$nx, $ny, $nz]];
            }
            $h  = sqrt($r * $r - $d * $d / 4) * (($cw xor $r < 0) ? -1 : 1); // the short way round unless R < 0
            [$cx, $cy] = [$x + $dx / 2 - $h * $dy / $d, $y + $dy / 2 + $h * $dx / $d];
        } else {
            return [[$nx, $ny, $nz]];
        }
        $r  = hypot($x - $cx, $y - $cy);
        $a0 = atan2($y - $cy, $x - $cx);
        $a1 = atan2($ny - $cy, $nx - $cx);
        $sw = $cw ? $a0 - $a1 : $a1 - $a0;
        if ($sw <= 1e-9) {
            $sw += 2 * M_PI; // same start and end: a full circle
        }
        $n   = max(1, min(64, (int) ceil($sw / (M_PI / 18))));
        $pts = [];
        for ($i = 1; $i <= $n; $i++) {
            $t     = $a0 + ($cw ? -1 : 1) * $sw * $i / $n;
            $pts[] = $i === $n ? [$nx, $ny, $nz] : [$cx + $r * cos($t), $cy + $r * sin($t), $z + ($nz - $z) * $i / $n];
        }
        return $pts;
    }
}
