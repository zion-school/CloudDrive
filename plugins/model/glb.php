<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// GLB (binary glTF 2.0): a JSON chunk describing scenes, nodes and meshes, and a binary chunk with the data.
// Drawn in the rest pose with material colours, vertex colours and one texture sample per triangle; Y-up becomes Z-up.
// Draco / meshopt compressed meshes need decoders and are not drawn.

final class ModelGlb {
    const COMPRESSED = ['KHR_draco_mesh_compression', 'EXT_meshopt_compression'];

    /** [glTF JSON, binary chunk], or a Fail with a message for the viewer's errors as well. */
    public static function open(string $data): array {
        $h = strlen($data) >= 20 ? unpack('Vmagic/Vversion/Vlength/Vjlen/Vjtype', $data) : null;
        if (! $h || $h['magic'] !== 0x46546C67 || $h['jtype'] !== 0x4E4F534A) {
            throw new Fail('This is not a GLB file', 422);
        }
        if ($h['version'] !== 2) {
            throw new Fail('Only glTF 2.0 is supported', 422);
        }
        $json = json_decode(substr($data, 20, $h['jlen']), true);
        $o    = 20 + $h['jlen'];
        $bin  = '';
        if (strlen($data) >= $o + 8) {
            $c = unpack('Vlen/Vtype', $data, $o);
            if ($c['type'] === 0x004E4942) {
                $bin = substr($data, $o + 8, $c['len']);
            }
        }
        return [is_array($json) ? $json : throw new Fail('This GLB file is damaged', 422), $bin];
    }

    /** Triangles in batches for ModelRaster::mesh(), or null when there is nothing this can draw. */
    public static function source(string $full): ?callable {
        [$j, $bin] = self::open((string) file_get_contents($full));
        if (array_intersect(self::COMPRESSED, $j['extensionsRequired'] ?? [])) {
            return null;
        }

        $roots = $j['scenes'][$j['scene'] ?? 0]['nodes'] ?? null;
        if ($roots === null) { // no scene: every node that is nobody's child
            $kids  = array_merge(...array_map(fn($n) => $n['children'] ?? [], $j['nodes'] ?? [[]]));
            $roots = array_values(array_diff(array_keys($j['nodes'] ?? []), $kids));
        }

        return function () use ($j, $bin, $roots) {
            $textures = [];
            $stack    = array_map(fn($n) => [$n, [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1], 0], $roots);
            while ($stack) {
                [$n, $parent, $depth] = array_pop($stack);
                $node = $j['nodes'][$n] ?? null;
                if (! is_array($node) || $depth > 64) {
                    continue;
                }
                $m = self::mul($parent, self::local($node));
                foreach ($node['children'] ?? [] as $c) {
                    $stack[] = [$c, $m, $depth + 1];
                }
                foreach ($j['meshes'][$node['mesh'] ?? -1]['primitives'] ?? [] as $p) {
                    yield from self::primitive($j, $bin, $p, $m, $textures);
                }
            }
        };
    }

    private static function primitive(array $j, string $bin, array $p, array $m, array &$textures) {
        $mode = $p['mode'] ?? 4;
        $pa   = in_array($mode, [4, 5, 6], true) ? self::accessor($j, $bin, $p['attributes']['POSITION'] ?? null) : null;
        if (! $pa || $pa[1] !== 3) {
            return;
        }
        $nv = $pa[4];
        // world position (column-major matrix), then Y-up → Z-up: (x, y, z) → (x, -z, y); packed, 12 bytes a vertex
        $pos = self::packed($bin, $pa, function (array $v) use ($m) {
            $o = [];
            for ($i = 0, $n = count($v); $i < $n; $i += 3) {
                [$x, $y, $z] = [$v[$i], $v[$i + 1], $v[$i + 2]];
                array_push($o, $m[0] * $x + $m[4] * $y + $m[8] * $z + $m[12],
                    -($m[2] * $x + $m[6] * $y + $m[10] * $z + $m[14]), $m[1] * $x + $m[5] * $y + $m[9] * $z + $m[13]);
            }
            return $o;
        });

        $ia = isset($p['indices']) ? self::accessor($j, $bin, $p['indices']) : null;
        if (isset($p['indices']) && (! $ia || $ia[1] !== 1)) {
            return;
        }
        $list = null; // strips and fans become a list; plain triangles are read straight from the buffer
        if ($mode !== 4) {
            $list = self::triangles($ia ? self::values($bin, $ia, 0, $ia[4]) : range(0, $nv - 1), $mode);
        }
        $nt = intdiv($list !== null ? count($list) : ($ia ? $ia[4] : $nv), 3);

        $mat  = $j['materials'][$p['material'] ?? -1]['pbrMetallicRoughness'] ?? [];
        $f    = isset($p['material']) ? array_map(fn($c) => (float) $c ** (1 / 2.2), array_slice(($mat['baseColorFactor'] ?? []) + [1, 1, 1], 0, 3))
              : [(ModelRaster::COLOR >> 16) / 255, (ModelRaster::COLOR >> 8 & 255) / 255, (ModelRaster::COLOR & 255) / 255]; // no material at all
        $ca   = self::accessor($j, $bin, $p['attributes']['COLOR_0'] ?? null);
        $col  = $ca && $ca[4] === $nv && $ca[1] >= 3 ? self::packed($bin, $ca, function (array $v) use ($ca) {
            $o = [];
            for ($i = 0, $n = count($v); $i < $n; $i += $ca[1]) {
                array_push($o, $v[$i], $v[$i + 1], $v[$i + 2]);
            }
            return $o;
        }) : null;
        $tex  = $mat['baseColorTexture'] ?? null;
        $ua   = $tex ? self::accessor($j, $bin, $p['attributes']['TEXCOORD_' . (int) ($tex['texCoord'] ?? 0)] ?? null) : null;
        $img  = $ua && $ua[1] === 2 && $ua[4] === $nv ? ($textures[(int) $tex['index']] ??= self::texture($j, $bin, (int) $tex['index'])) : null;
        $uv   = $img ? self::packed($bin, $ua, fn(array $v) => $v) : null;
        [$tw, $th] = $img ? [imagesx($img), imagesy($img)] : [0, 0];

        $xyz = $rgb = [];
        for ($t = 0; $t < $nt; $t++) {
            if ($list !== null) {
                [$a, $b, $c] = [$list[3 * $t], $list[3 * $t + 1], $list[3 * $t + 2]];
            } elseif ($ia) {
                [, $a, $b, $c] = unpack($ia[0] . '3', $bin, $ia[2] + 3 * $t * $ia[3]);
            } else {
                [$a, $b, $c] = [3 * $t, 3 * $t + 1, 3 * $t + 2];
            }
            if ($a >= $nv || $b >= $nv || $c >= $nv) {
                continue;
            }
            [$r, $g, $bl] = $f;
            if ($col) {
                [, $r0, $g0, $b0] = unpack('g3', $col, 12 * $a);
                [, $r1, $g1, $b1] = unpack('g3', $col, 12 * $b);
                [, $r2, $g2, $b2] = unpack('g3', $col, 12 * $c);
                $r  *= ($r0 + $r1 + $r2) / 3;
                $g  *= ($g0 + $g1 + $g2) / 3;
                $bl *= ($b0 + $b1 + $b2) / 3;
            }
            if ($uv) { // the texture at the triangle's centre
                [, $u0, $w0] = unpack('g2', $uv, 8 * $a);
                [, $u1, $w1] = unpack('g2', $uv, 8 * $b);
                [, $u2, $w2] = unpack('g2', $uv, 8 * $c);
                $u  = ($u0 + $u1 + $u2) / 3;
                $w  = ($w0 + $w1 + $w2) / 3;
                $tc = imagecolorat($img, (int) (($u - floor($u)) * $tw) % $tw, (int) (($w - floor($w)) * $th) % $th);
                $r  *= ($tc >> 16 & 255) / 255;
                $g  *= ($tc >> 8 & 255) / 255;
                $bl *= ($tc & 255) / 255;
            }
            array_push($xyz, ...unpack('g3', $pos, 12 * $a), ...unpack('g3', $pos, 12 * $b), ...unpack('g3', $pos, 12 * $c));
            $rgb[] = (min(255, (int) ($r * 255)) << 16) | (min(255, (int) ($g * 255)) << 8) | min(255, (int) ($bl * 255));
            if (count($rgb) >= 4096) {
                yield [$xyz, $rgb];
                $xyz = $rgb = [];
            }
        }
        if ($rgb) {
            yield [$xyz, $rgb];
        }
    }

    /** Triangle strips (5) and fans (6) as a plain triangle list. */
    private static function triangles(array $idx, int $mode): array {
        $out = [];
        for ($i = 2, $n = count($idx); $i < $n; $i++) {
            array_push($out, ...($mode === 6 ? [$idx[0], $idx[$i - 1], $idx[$i]]
                : ($i % 2 ? [$idx[$i - 1], $idx[$i - 2], $idx[$i]] : [$idx[$i - 2], $idx[$i - 1], $idx[$i]])));
        }
        return $out;
    }

    /**
     * An accessor as [unpack format, components, byte offset, stride, count, component type, normalised],
     * or null when it isn't usable (unknown type, outside the binary chunk, external buffer).
     */
    private static function accessor(array $j, string $bin, $i): ?array {
        $a = is_int($i) ? $j['accessors'][$i] ?? null : null;
        $n = ['SCALAR' => 1, 'VEC2' => 2, 'VEC3' => 3, 'VEC4' => 4][$a['type'] ?? ''] ?? 0;
        $t = $a['componentType'] ?? 0;
        [$fmt, $size] = [5120 => ['c', 1], 5121 => ['C', 1], 5122 => ['v', 2], 5123 => ['v', 2], 5125 => ['V', 4], 5126 => ['g', 4]][$t] ?? [null, 0];
        $count = (int) ($a['count'] ?? 0);
        $bv    = $j['bufferViews'][$a['bufferView'] ?? -1] ?? null;
        if (! $n || ! $fmt || $count <= 0 || ! $bv || ($bv['buffer'] ?? 0) !== 0) {
            return null; // also accessors without data (all zeros), which are not used for geometry
        }
        $stride = (int) ($bv['byteStride'] ?? 0) ?: $n * $size;
        $off    = (int) ($bv['byteOffset'] ?? 0) + (int) ($a['byteOffset'] ?? 0);
        if ($off < 0 || $off + ($count - 1) * $stride + $n * $size > strlen($bin)) {
            return null;
        }
        return [$fmt, $n, $off, $stride, $count, $t, ! empty($a['normalized'])];
    }

    /** Elements $from … $from + $count - 1 of accessor $a as a flat list of numbers (normalised integers → 0…1 / -1…1). */
    private static function values(string $bin, array $a, int $from, int $count): array {
        [$fmt, $n, $off, $stride, , $t, $norm] = $a;
        if ($stride === $n * ['c' => 1, 'C' => 1, 'v' => 2, 'V' => 4, 'g' => 4][$fmt]) {
            $v = array_values(unpack($fmt . ($count * $n), $bin, $off + $from * $stride));
        } else {
            $v = [];
            for ($k = $from, $end = $from + $count; $k < $end; $k++) {
                array_push($v, ...array_values(unpack($fmt . $n, $bin, $off + $k * $stride)));
            }
        }
        if ($t === 5122) { // 'v' is unsigned
            $v = array_map(fn($x) => $x >= 32768 ? $x - 65536 : $x, $v);
        }
        $max = [5120 => 127, 5121 => 255, 5122 => 32767, 5123 => 65535][$t] ?? 0;
        return $norm && $max ? array_map(fn($x) => max(-1, $x / $max), $v) : $v;
    }

    /** Every element of accessor $a, mapped by $fn a chunk at a time, packed as 32-bit floats. */
    private static function packed(string $bin, array $a, callable $fn): string {
        $out = '';
        for ($k = 0; $k < $a[4]; $k += 8192) {
            $out .= pack('g*', ...$fn(self::values($bin, $a, $k, min(8192, $a[4] - $k))));
        }
        return $out;
    }

    /** The base-colour texture as a GD image, if it is embedded and not huge. */
    private static function texture(array $j, string $bin, int $t): ?GdImage {
        $bv = $j['bufferViews'][$j['images'][$j['textures'][$t]['source'] ?? -1]['bufferView'] ?? -1] ?? null;
        if (! $bv || ($bv['buffer'] ?? 0) !== 0) {
            return null;
        }
        $bytes = substr($bin, (int) ($bv['byteOffset'] ?? 0), (int) $bv['byteLength']);
        $info  = @getimagesizefromstring($bytes);
        return $info && $info[0] * $info[1] <= 4096 * 2048 ? (@imagecreatefromstring($bytes) ?: null) : null;
    }

    /** A node's own transform as a column-major 4×4 matrix (matrix, or translation · rotation · scale). */
    private static function local(array $n): array {
        if (isset($n['matrix']) && count($n['matrix']) === 16) {
            return array_map('floatval', $n['matrix']);
        }
        [$x, $y, $z, $w] = array_map('floatval', ($n['rotation'] ?? []) + [0, 0, 0, 1]);
        [$sx, $sy, $sz]  = array_map('floatval', ($n['scale'] ?? []) + [1, 1, 1]);
        [$tx, $ty, $tz]  = array_map('floatval', ($n['translation'] ?? []) + [0, 0, 0]);
        return [(1 - 2 * ($y * $y + $z * $z)) * $sx, 2 * ($x * $y + $z * $w) * $sx, 2 * ($x * $z - $y * $w) * $sx, 0,
                2 * ($x * $y - $z * $w) * $sy, (1 - 2 * ($x * $x + $z * $z)) * $sy, 2 * ($y * $z + $x * $w) * $sy, 0,
                2 * ($x * $z + $y * $w) * $sz, 2 * ($y * $z - $x * $w) * $sz, (1 - 2 * ($x * $x + $y * $y)) * $sz, 0,
                $tx, $ty, $tz, 1];
    }

    /** Column-major $a · $b. */
    private static function mul(array $a, array $b): array {
        $o = [];
        for ($c = 0; $c < 4; $c++) {
            for ($r = 0; $r < 4; $r++) {
                $o[] = $a[$r] * $b[$c * 4] + $a[4 + $r] * $b[$c * 4 + 1] + $a[8 + $r] * $b[$c * 4 + 2] + $a[12 + $r] * $b[$c * 4 + 3];
            }
        }
        return $o;
    }
}
