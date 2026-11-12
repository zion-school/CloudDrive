<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// Thumbnails of 3D models with GD only: a small software z-buffer for triangles, GD lines for G-code toolpaths.
// Models are Z-up in their own units; the camera looks from the front-right and above, and fits whatever is there.
// Everything is drawn at twice the size and scaled down, which smooths the edges.

final class ModelRaster {
    const BG    = 0xeceff3; // JPEG has no transparency
    const COLOR = 0x4f8fd8; // models without colours of their own
    const PATH  = 0xf08a24; // G-code extrusions

    /**
     * Shaded triangles. $source() is called twice (bounds, then drawing) and yields batches [float[] $xyz, int|int[] $rgb]:
     * 9 coordinates per triangle, and one 0xRRGGBB for the whole batch or one per triangle.
     */
    public static function mesh(?callable $source, int $size): ?GdImage {
        if (! $source) {
            return null;
        }
        // the bounds pass keeps what it reads (packed, if not too much), so the drawing pass need not parse the file again
        $kept  = [];
        $bytes = 0;
        $box   = self::bounds(function () use ($source, &$kept, &$bytes) {
            foreach ($source() as [$v, $rgb]) {
                if ($kept !== null && ($bytes += 40 * count($v) / 9) < 48 << 20) {
                    $kept[] = [pack('g*', ...$v), is_array($rgb) ? pack('V*', ...$rgb) : $rgb];
                } else {
                    $kept = null;
                }
                yield [$v, $rgb];
            }
        });
        if (! $box) {
            return null;
        }
        $batches = $kept === null ? $source() : (function () use (&$kept) {
            foreach ($kept as $i => [$v, $rgb]) {
                unset($kept[$i]); // free as we go
                yield [array_values(unpack('g*', $v)), is_string($rgb) ? array_values(unpack('V*', $rgb)) : $rgb];
            }
        })();

        $W = $H = 2 * $size;
        [$rx, $ry, $rz, $ux, $uy, $uz, $vx, $vy, $vz, $ox, $oy, $lx, $ly, $lz] = self::camera($box, $W, [0.55, -1, 0.7]);

        $zb = array_fill(0, $W * $H, INF);
        $cb = array_fill(0, $W * $H, -1);
        foreach ($batches as [$v, $rgb]) {
            $per = is_array($rgb);
            $n   = intdiv(count($v), 9);
            for ($t = 0, $i = 0; $t < $n; $t++, $i += 9) {
                $x0 = $v[$i];
                $y0 = $v[$i + 1];
                $z0 = $v[$i + 2];
                $x1 = $v[$i + 3];
                $y1 = $v[$i + 4];
                $z1 = $v[$i + 5];
                $x2 = $v[$i + 6];
                $y2 = $v[$i + 7];
                $z2 = $v[$i + 8];

                $ax = $x1 - $x0;
                $ay = $y1 - $y0;
                $az = $z1 - $z0;
                $bx = $x2 - $x0;
                $by = $y2 - $y0;
                $bz = $z2 - $z0;
                $nx = $ay * $bz - $az * $by;
                $ny = $az * $bx - $ax * $bz;
                $nz = $ax * $by - $ay * $bx;
                $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                if ($len == 0) {
                    continue;
                }

                $s   = 0.32 + 0.68 * abs($nx * $lx + $ny * $ly + $nz * $lz) / $len;
                $c   = $per ? $rgb[$t] : $rgb;
                $col = ((int) (($c >> 16 & 255) * $s) << 16) | ((int) (($c >> 8 & 255) * $s) << 8) | (int) (($c & 255) * $s);

                $X0 = $rx * $x0 + $ry * $y0 + $rz * $z0 + $ox;
                $Y0 = $ux * $x0 + $uy * $y0 + $uz * $z0 + $oy;
                $D0 = $vx * $x0 + $vy * $y0 + $vz * $z0;
                $X1 = $rx * $x1 + $ry * $y1 + $rz * $z1 + $ox;
                $Y1 = $ux * $x1 + $uy * $y1 + $uz * $z1 + $oy;
                $D1 = $vx * $x1 + $vy * $y1 + $vz * $z1;
                $X2 = $rx * $x2 + $ry * $y2 + $rz * $z2 + $ox;
                $Y2 = $ux * $x2 + $uy * $y2 + $uz * $z2 + $oy;
                $D2 = $vx * $x2 + $vy * $y2 + $vz * $z2;

                $area = ($X1 - $X0) * ($Y2 - $Y0) - ($X2 - $X0) * ($Y1 - $Y0);
                if ($area < 0) {
                    [$X1, $Y1, $D1, $X2, $Y2, $D2] = [$X2, $Y2, $D2, $X1, $Y1, $D1];
                    $area = -$area;
                }

                $hit  = false;
                $minX = max(0, (int) min($X0, $X1, $X2));
                $maxX = min($W - 1, (int) max($X0, $X1, $X2));
                $minY = max(0, (int) min($Y0, $Y1, $Y2));
                $maxY = min($H - 1, (int) max($Y0, $Y1, $Y2));
                if ($area > 0) {
                    // edge functions, stepped per pixel: w0 is the weight of vertex 0 (edge 1→2), and so on
                    $A0  = $Y1 - $Y2;
                    $B0  = $X2 - $X1;
                    $A1  = $Y2 - $Y0;
                    $B1  = $X0 - $X2;
                    $A2  = $Y0 - $Y1;
                    $B2  = $X1 - $X0;
                    $inv = 1 / $area;
                    $sx  = $minX + 0.5;
                    for ($py = $minY; $py <= $maxY; $py++) {
                        $sy = $py + 0.5;
                        $w0 = $A0 * ($sx - $X1) + $B0 * ($sy - $Y1);
                        $w1 = $A1 * ($sx - $X2) + $B1 * ($sy - $Y2);
                        $w2 = $A2 * ($sx - $X0) + $B2 * ($sy - $Y0);
                        for ($k = $py * $W + $minX, $end = $py * $W + $maxX; $k <= $end; $k++, $w0 += $A0, $w1 += $A1, $w2 += $A2) {
                            if ($w0 >= 0 && $w1 >= 0 && $w2 >= 0) {
                                $hit = true;
                                $d   = ($w0 * $D0 + $w1 * $D1 + $w2 * $D2) * $inv;
                                if ($d < $zb[$k]) {
                                    $zb[$k] = $d;
                                    $cb[$k] = $col;
                                }
                            }
                        }
                    }
                }
                // too small to cover any pixel centre: one dot, so thin parts don't vanish – unless it's seen edge-on
                // (then it's part of a fold the faces around it show better)
                if (! $hit && abs($nx * $vx + $ny * $vy + $nz * $vz) > 0.35 * $len) {
                    $px = (int) (($X0 + $X1 + $X2) / 3);
                    $py = (int) (($Y0 + $Y1 + $Y2) / 3);
                    if ($px >= 0 && $py >= 0 && $px < $W && $py < $H) {
                        $k = $py * $W + $px;
                        $d = ($D0 + $D1 + $D2) / 3;
                        if ($d < $zb[$k]) {
                            $zb[$k] = $d;
                            $cb[$k] = $col;
                        }
                    }
                }
            }
        }
        unset($zb);

        $img = self::canvas($W, $H);
        foreach ($cb as $k => $col) {
            if ($col >= 0) {
                imagesetpixel($img, $k % $W, intdiv($k, $W), $col);
            }
        }
        return self::shrink($img, $size, $size);
    }

    /**
     * Toolpaths. $source() is called twice and yields batches of segments: 6 coordinates each (from, to), in print
     * order – so later (higher) layers are drawn over earlier ones. Lower layers are darker.
     */
    public static function lines(?callable $source, int $size): ?GdImage {
        $box = $source ? self::bounds($source) : null;
        if (! $box) {
            return null;
        }

        $W = $H = 2 * $size;
        [$rx, $ry, $rz, $ux, $uy, $uz, , , , $ox, $oy] = self::camera($box, $W, [0.5, -1, 1.1]);
        $img = self::canvas($W, $H);
        imagesetthickness($img, 2);
        [$z0, $dz] = [$box[2], max(1e-9, $box[5] - $box[2])];
        $lx = $ly = null;   // the last point drawn
        $ex = $ey = $ez = null; // the end of the last segment
        foreach ($source() as $v) {
            for ($i = 0, $n = count($v); $i + 5 < $n; $i += 6) {
                $X = $rx * $v[$i + 3] + $ry * $v[$i + 4] + $rz * $v[$i + 5] + $ox;
                $Y = $ux * $v[$i + 3] + $uy * $v[$i + 4] + $uz * $v[$i + 5] + $oy;
                $joined = $ex === $v[$i] && $ey === $v[$i + 1] && $ez === $v[$i + 2];
                [$ex, $ey, $ez] = [$v[$i + 3], $v[$i + 4], $v[$i + 5]];
                if (! $joined) {
                    $lx = $rx * $v[$i] + $ry * $v[$i + 1] + $rz * $v[$i + 2] + $ox;
                    $ly = $ux * $v[$i] + $uy * $v[$i + 1] + $uz * $v[$i + 2] + $oy;
                } elseif (abs($X - $lx) + abs($Y - $ly) < 1.5) {
                    continue; // tiny steps of a curve: join them up into one line
                }
                $s = 0.45 + 0.55 * ($ez - $z0) / $dz;
                imageline($img, (int) $lx, (int) $ly, (int) $X, (int) $Y,
                    ((int) ((self::PATH >> 16 & 255) * $s) << 16) | ((int) ((self::PATH >> 8 & 255) * $s) << 8) | (int) ((self::PATH & 255) * $s));
                [$lx, $ly] = [$X, $Y];
            }
        }
        return self::shrink($img, $size, $size);
    }

    /** A PNG / JPEG picture (e.g. a slicer's preview) fitted into $size × $size and flattened onto the background. */
    public static function picture(?string $bytes, int $size): ?GdImage {
        return fitPicture($bytes, $size, self::BG);
    }

    /** [minX, minY, minZ, maxX, maxY, maxZ] of every coordinate triple $source() yields, or null when empty. */
    private static function bounds(callable $source): ?array {
        $a = $b = $c = INF;
        $d = $e = $f = -INF;
        foreach ($source() as $batch) {
            $v = is_array($batch[0] ?? null) ? $batch[0] : $batch;
            for ($i = 0, $n = count($v) - 2; $i < $n; $i += 3) {
                $x = $v[$i];
                $y = $v[$i + 1];
                $z = $v[$i + 2];
                if ($x < $a) {
                    $a = $x;
                }
                if ($x > $d) {
                    $d = $x;
                }
                if ($y < $b) {
                    $b = $y;
                }
                if ($y > $e) {
                    $e = $y;
                }
                if ($z < $c) {
                    $c = $z;
                }
                if ($z > $f) {
                    $f = $z;
                }
            }
        }
        return $a <= $d && is_finite($a + $b + $c + $d + $e + $f) ? [$a, $b, $c, $d, $e, $f] : null;
    }

    /**
     * An orthographic camera looking at the box from $eye's direction, fitted to $W pixels with a margin:
     * [screen x per unit (3), screen y per unit (3), depth per unit (3), x offset, y offset, light direction (3)].
     * The light comes from the upper left of the camera; both sides of a face are lit (many STLs have flipped normals).
     */
    private static function camera(array $box, int $W, array $eye): array {
        $v = self::unit([-$eye[0], -$eye[1], -$eye[2]]);   // viewing direction
        $r = self::unit([$v[1], -$v[0], 0]);                // v × up (z)
        $u = [$r[1] * $v[2] - $r[2] * $v[1], $r[2] * $v[0] - $r[0] * $v[2], $r[0] * $v[1] - $r[1] * $v[0]];
        $sx = $sy = [INF, -INF];
        foreach ([0, 3] as $i) {
            foreach ([1, 4] as $j) {
                foreach ([2, 5] as $k) {
                    $p     = [$box[$i], $box[$j], $box[$k]];
                    $x     = $p[0] * $r[0] + $p[1] * $r[1] + $p[2] * $r[2];
                    $y     = $p[0] * $u[0] + $p[1] * $u[1] + $p[2] * $u[2];
                    $sx    = [min($sx[0], $x), max($sx[1], $x)];
                    $sy    = [min($sy[0], $y), max($sy[1], $y)];
                }
            }
        }
        $s = $W * 0.84 / max($sx[1] - $sx[0], $sy[1] - $sy[0], 1e-9);
        return [$r[0] * $s, $r[1] * $s, $r[2] * $s, -$u[0] * $s, -$u[1] * $s, -$u[2] * $s, $v[0], $v[1], $v[2],
                $W / 2 - ($sx[0] + $sx[1]) / 2 * $s, $W / 2 + ($sy[0] + $sy[1]) / 2 * $s,
                ...self::unit([$u[0] * 0.6 - $r[0] * 0.4 - $v[0], $u[1] * 0.6 - $r[1] * 0.4 - $v[1], $u[2] * 0.6 - $r[2] * 0.4 - $v[2]])];
    }

    private static function unit(array $a): array {
        $l = sqrt($a[0] ** 2 + $a[1] ** 2 + $a[2] ** 2) ?: 1;
        return [$a[0] / $l, $a[1] / $l, $a[2] / $l];
    }

    private static function canvas(int $w, int $h): GdImage {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, self::BG);
        return $img;
    }

    private static function shrink(GdImage $img, int $w, int $h): GdImage {
        $t = self::canvas($w, $h);
        imagealphablending($t, true);
        imagecopyresampled($t, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        return $t;
    }
}
