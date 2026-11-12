<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// 3MF: a ZIP with the model as XML (3D/3dmodel.model). Objects are meshes or groups of other objects (components),
// possibly in other model files of the package (production extension: Bambu Studio, PrusaSlicer); the build places
// them with 3×4 matrices. Colours come from base materials and colour groups, per object or per triangle.

final class Model3mf {
    const PROD    = 'http://schemas.microsoft.com/3dmanufacturing/production/2015/06';
    const MAX_XML = 32 << 20; // model XML drawn at most (uncompressed, all parts together)

    /** The preview picture a slicer saved in the package, if any. */
    public static function picture(ZipArchive $z): ?string {
        $names = [];
        foreach (self::rels($z) as [$type, $target]) {
            if (str_ends_with($type, '/thumbnail')) {
                $names[] = $target;
            }
        }
        foreach ([...$names, 'Metadata/plate_1.png', 'Metadata/thumbnail.png'] as $name) {
            $st = $z->statName($name);
            if ($st && $st['size'] <= 16 << 20 && ($bytes = $z->getFromName($name)) !== false) {
                return $bytes;
            }
        }
        return null;
    }

    /** Triangles in batches for ModelRaster::mesh(), or null when the model is too big to draw here. */
    public static function source(ZipArchive $z): ?callable {
        $root = '3D/3dmodel.model';
        foreach (self::rels($z) as [$type, $target]) {
            if (str_ends_with($type, '/3dmodel')) {
                $root = $target;
            }
        }
        $parts = [];
        $budget = self::MAX_XML;
        $load = function (string $path) use ($z, &$parts, &$budget): ?array {
            if (! array_key_exists($path, $parts)) {
                $st            = $z->statName($path);
                $budget       -= $st ? $st['size'] : 0;
                $parts[$path]  = $st && $budget >= 0 ? self::parse((string) $z->getFromName($path)) : null;
            }
            return $parts[$path];
        };
        if (! $load($root) || $budget < 0) {
            return null;
        }

        // the triangles of object $id of part $path, placed by matrix $m; components recurse (cycles are cut off)
        $emit = function (string $path, string $id, array $m, int $depth) use (&$emit, $load) {
            $obj = $load($path)['objects'][$id] ?? null;
            if (! $obj || $depth > 16) {
                return;
            }
            foreach ($obj['comp'] as [$cid, $cpath, $cm]) {
                yield from $emit($cpath ?? $path, $cid, self::mul($cm, $m), $depth + 1);
            }
            $v  = $obj['v'];
            $nv = intdiv(strlen($v), 12);
            $xyz = $rgb = [];
            for ($o = 0, $n = strlen($obj['t']); $o < $n; $o += 16) {
                [, $a, $b, $c, $col] = unpack('V4', $obj['t'], $o);
                if ($a >= $nv || $b >= $nv || $c >= $nv) {
                    continue;
                }
                foreach ([$a, $b, $c] as $k) {
                    [, $x, $y, $zz] = unpack('g3', $v, 12 * $k);
                    array_push($xyz, $x * $m[0] + $y * $m[3] + $zz * $m[6] + $m[9],
                        $x * $m[1] + $y * $m[4] + $zz * $m[7] + $m[10], $x * $m[2] + $y * $m[5] + $zz * $m[8] + $m[11]);
                }
                $rgb[] = $col;
                if (count($rgb) >= 4096) {
                    yield [$xyz, $rgb];
                    $xyz = $rgb = [];
                }
            }
            if ($rgb) {
                yield [$xyz, $rgb];
            }
        };

        return function () use ($load, $root, $emit) {
            foreach ($load($root)['items'] as [$id, $path, $m]) {
                yield from $emit($path ?? $root, $id, $m, 0);
            }
        };
    }

    /** [type, target without leading /] of each package relationship. */
    public static function rels(ZipArchive $z): array {
        $out = [];
        preg_match_all('/<Relationship\b[^>]*>/i', (string) $z->getFromName('_rels/.rels'), $m);
        foreach ($m[0] as $tag) {
            if (preg_match('/\bType\s*=\s*"([^"]*)"/', $tag, $t) && preg_match('/\bTarget\s*=\s*"\/?([^"]*)"/', $tag, $g)) {
                $out[] = [$t[1], $g[1]];
            }
        }
        return $out;
    }

    /**
     * One model file: ['objects' => [id => ['v' => packed float xyz, 't' => packed (v1, v2, v3, rgb), 'comp' => [[id, path, m]]]],
     *                  'items' => [[id, path, m]]]. Vertices and triangles are kept packed: 12 / 16 bytes each.
     */
    private static function parse(string $xml): ?array {
        $r = new XMLReader();
        if ($xml === '' || ! $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return null;
        }

        $objects = $groups = $items = [];
        $id      = $gid = null;
        $v       = $t = '';
        $comp    = [];
        $col     = ModelRaster::COLOR;
        $opid    = $oidx = null; // the object's own property group and index
        $flush   = function () use (&$objects, &$id, &$v, &$t, &$comp) {
            if ($id !== null) {
                $objects[$id] = ['v' => $v, 't' => $t, 'comp' => $comp];
            }
            [$id, $v, $t, $comp] = [null, '', '', []];
        };
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'object') {
                $flush();
            }
            if ($r->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            switch ($r->localName) {
                case 'object':
                    $flush();
                    $id  = (string) $r->getAttribute('id');
                    $opid = $r->getAttribute('pid');
                    $oidx = $r->getAttribute('pindex');
                    $col  = $groups[$opid ?? ''][(int) $oidx] ?? ModelRaster::COLOR;
                    if ($r->isEmptyElement) {
                        $flush();
                    }
                    break;
                case 'vertex':
                    $v .= pack('g3', (float) $r->getAttribute('x'), (float) $r->getAttribute('y'), (float) $r->getAttribute('z'));
                    break;
                case 'triangle':
                    $pid = $r->getAttribute('pid');
                    $p1  = $r->getAttribute('p1');
                    $t  .= pack('V4', (int) $r->getAttribute('v1'), (int) $r->getAttribute('v2'), (int) $r->getAttribute('v3'),
                        $pid !== null || $p1 !== null ? $groups[$pid ?? $opid ?? ''][(int) ($p1 ?? $oidx)] ?? $col : $col);
                    break;
                case 'component':
                    $comp[] = [(string) $r->getAttribute('objectid'), self::path($r), self::matrix($r->getAttribute('transform'))];
                    break;
                case 'item':
                    $items[] = [(string) $r->getAttribute('objectid'), self::path($r), self::matrix($r->getAttribute('transform'))];
                    break;
                case 'basematerials':
                case 'colorgroup':
                    $gid          = (string) $r->getAttribute('id');
                    $groups[$gid] = [];
                    break;
                case 'base':
                case 'color':
                    if ($gid !== null) {
                        $groups[$gid][] = self::rgb((string) ($r->getAttribute('displaycolor') ?? $r->getAttribute('color')));
                    }
                    break;
            }
        }
        $flush();
        return ['objects' => $objects, 'items' => $items];
    }

    /** The part a component / build item points into (production extension), or null for the same file. */
    private static function path(XMLReader $r): ?string {
        $p = $r->getAttributeNs('path', self::PROD);
        return $p ? ltrim($p, '/') : null;
    }

    /** "m00 m01 m02 m10 … m32" (row vectors: x' = x·m00 + y·m10 + z·m20 + m30) → 12 numbers; identity when absent. */
    private static function matrix(?string $s): array {
        $m = $s ? array_map('floatval', preg_split('/\s+/', trim($s))) : [];
        return count($m) === 12 ? $m : [1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0];
    }

    /** $a applied first, then $b. */
    private static function mul(array $a, array $b): array {
        $o = [];
        for ($r = 0; $r < 4; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $o[] = $a[$r * 3] * $b[$c] + $a[$r * 3 + 1] * $b[3 + $c] + $a[$r * 3 + 2] * $b[6 + $c] + ($r === 3 ? $b[9 + $c] : 0);
            }
        }
        return $o;
    }

    /** "#RRGGBB[AA]" → 0xRRGGBB. */
    private static function rgb(string $s): int {
        return preg_match('/^#([0-9a-f]{6})/i', $s, $m) ? hexdec($m[1]) : ModelRaster::COLOR;
    }
}
