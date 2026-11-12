<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// What the info panel shows about a model or a G-code file: what the file says about itself – titles, the program
// that made it, the slicer's settings and estimates. Only headers / metadata are read; the size and triangle count
// come from the viewer, which has the whole model in hand anyway.

final class ModelInfo {
    public static function info(string $full, string $ext): array {
        return match ($ext) {
            '3mf'          => self::threemf($full),
            'gcode', 'gco' => self::gcode($full),
            'stl'          => self::stl($full),
            'obj'          => self::obj($full),
            'glb'          => self::glb($full),
            default        => [],
        };
    }

    // ---------- 3MF: <metadata> of the model, plus the slicer's project / slicing results ----------

    private static function threemf(string $full): array {
        $z = new ZipArchive();
        if ($z->open($full, ZipArchive::RDONLY) !== true) {
            throw new Fail('This file is damaged or not in the expected format', 422);
        }
        try {
            $root = '3D/3dmodel.model';
            foreach (Model3mf::rels($z) as [$type, $target]) {
                if (str_ends_with($type, '/3dmodel')) {
                    $root = $target;
                }
            }
            $head = self::zipHead($z, $root, 1 << 20);
            $head = explode('<resources', $head, 2)[0]; // metadata comes before the (big) mesh data
            $meta = [];
            preg_match_all('#<(?:\w+:)?metadata\b([^>]*?)(?:/>|>(.*?)</(?:\w+:)?metadata>)#s', $head, $mm, PREG_SET_ORDER);
            foreach ($mm as $m) {
                if (preg_match('/\bname\s*=\s*"([^"]*)"/', $m[1], $n)) {
                    $meta[strtolower(preg_replace('/^\w+:/', '', $n[1]))] ??= self::xmlText($m[2] ?? '');
                }
            }
            $unit = preg_match('/<model\b[^>]*\bunit\s*=\s*"(\w+)"/', $head, $u) ? $u[1] : 'millimeter';

            $print = self::slicerSettings($z);
            $plates = self::slicedPlates($z);
            $count  = preg_match_all('/<plate\b/', self::zipHead($z, 'Metadata/model_settings.config', 4 << 20));
        } finally {
            $z->close();
        }

        $out = [
            'Model'   => ['Title'       => $meta['title'] ?? null,
                          'Designer'    => $meta['designer'] ?? null,
                          'Description' => isset($meta['description']) ? self::plain($meta['description']) : null,
                          'Copyright'   => $meta['copyright'] ?? null,
                          'License'     => $meta['licenseterms'] ?? $meta['license'] ?? null,
                          'Origin'      => $meta['origin'] ?? null,
                          'Units'       => ['micron' => 'micrometres', 'centimeter' => 'centimetres', 'inch' => 'inches',
                                              'foot' => 'feet', 'meter' => 'metres'][$unit] ?? null],
            'History' => ['Created'     => Plugin::infoDate($meta['creationdate'] ?? null),
                          'Modified'    => Plugin::infoDate($meta['modificationdate'] ?? null),
                          'Application' => $meta['application'] ?? null],
            'Print'   => $print + ['Plates' => max($count, count($plates)) > 1 ? max($count, count($plates)) : null],
        ];
        foreach ($plates as $i => $p) {
            $what = array_filter([Plugin::infoDuration($p['time']), $p['grams'] ? round($p['grams'], 1) . ' g' : null,
                                  $p['metres'] ? round($p['metres'], 2) . ' m' : null, implode(', ', $p['types'])]);
            $out['Print'][count($plates) > 1 ? 'Plate ' . $i : 'Sliced'] = implode(' · ', $what);
        }
        return $out;
    }

    /** Printer, profiles and main settings from Bambu Studio / Orca (project_settings.config) or PrusaSlicer (Slic3r_PE.config). */
    private static function slicerSettings(ZipArchive $z): array {
        $s = [];
        if (($json = self::zipHead($z, 'Metadata/project_settings.config', 4 << 20)) !== '' && is_array($j = json_decode($json, true))) {
            foreach ($j as $k => $v) {
                $s[strtolower($k)] = is_array($v) ? implode(';', array_map('strval', array_filter($v, 'is_scalar'))) : (is_scalar($v) ? (string) $v : '');
            }
        } elseif (($ini = self::zipHead($z, 'Metadata/Slic3r_PE.config', 4 << 20)) !== '') {
            $s = self::comments($ini);
        }
        return self::settings($s);
    }

    /** Bambu Studio / Orca "sliced" results per plate (Metadata/slice_info.config): time, filament used. */
    private static function slicedPlates(ZipArchive $z): array {
        $xml = self::zipHead($z, 'Metadata/slice_info.config', 4 << 20);
        $d   = new DOMDocument();
        if ($xml === '' || ! $d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }
        $out = [];
        foreach ($d->getElementsByTagName('plate') as $n => $p) {
            $m = [];
            foreach ($p->getElementsByTagName('metadata') as $md) {
                $m[$md->getAttribute('key')] = $md->getAttribute('value');
            }
            $types = $grams = $metres = [];
            foreach ($p->getElementsByTagName('filament') as $f) {
                $types[]  = $f->getAttribute('type');
                $grams[]  = (float) $f->getAttribute('used_g');
                $metres[] = (float) $f->getAttribute('used_m');
            }
            $out[(int) ($m['index'] ?? $n + 1)] = ['time'   => (float) ($m['prediction'] ?? 0),
                                                   'grams'  => (float) ($m['weight'] ?? 0) ?: array_sum($grams),
                                                   'metres' => array_sum($metres),
                                                   'types'  => array_values(array_unique(array_filter($types)))];
        }
        return $out;
    }

    // ---------- G-code: the slicer's comments at the top and bottom of the file ----------

    private static function gcode(string $full): array {
        $fp = fopen($full, 'rb') ?: throw new Fail('Could not open this file', 500);
        try {
            $head = '';
            $pic  = false;
            for ($read = 0; ($line = fgets($fp, 1 << 16)) !== false && $read < 4 << 20; $read += strlen($line)) {
                if (preg_match('/^;\s*(thumbnail(?:_\w+)?|png|jpg)\s+(begin|end)/i', $line, $m)) {
                    $pic = strtolower($m[2]) === 'begin'; // skip the pictures' base64
                } elseif (! $pic && preg_match('/^\s*G[0-3]\s/i', $line) && $read > 1 << 16) {
                    break; // the print has started: settings at the top are over
                } elseif (! $pic && ($line[0] ?? '') === ';') {
                    $head .= $line;
                }
            }
            $size = filesize($full);
            fseek($fp, max($read, $size - (256 << 10)));
            $tail = (string) stream_get_contents($fp);
        } finally {
            fclose($fp);
        }
        $c = self::comments($tail) + self::comments($head);
        $text = $head . "\n" . $tail;

        $slicer = null;
        $made   = null;
        if (preg_match('/^;\s*(?:G-Code )?generated (?:by|with)\s+(.+?)(?:\s+on\s+(\d{4}-\d\d-\d\d)(?:\s+at\s+(\d\d:\d\d:\d\d))?\s*(UTC)?)?\s*$/mi', $text, $m)) {
            $slicer = str_replace(['Cura_SteamEngine', '(R)'], ['Cura', ''], $m[1]);
            $made   = ! empty($m[2]) ? Plugin::infoDate($m[2] . (! empty($m[3]) ? "T$m[3]" : '') . (! empty($m[4]) ? 'Z' : '')) : null;
        } elseif (preg_match('/^;\s*((?:BambuStudio|OrcaSlicer|Creality ?Print|ElegooSlicer|AnycubicSlicer|SuperSlicer|QIDISlicer)[^\r\n]*)/mi', $text, $m)) {
            $slicer = trim($m[1]);
        }
        $first = fn(string ...$keys) => array_values(array_filter(array_map(fn($k) => $c[$k] ?? null, $keys), fn($v) => $v !== null && $v !== ''))[0] ?? null;

        $time = $first('estimated printing time (normal mode)', 'total estimated time', 'model printing time', 'build time', 'print time');
        if ($time === null && is_numeric($c['time'] ?? null)) {
            $time = Plugin::infoDuration((float) $c['time']); // Cura: seconds
        }
        $mm = $first('filament used [mm]', 'total filament length [mm]');
        $m  = $mm !== null ? self::sum($mm) / 1000 : (preg_match('/([\d.]+)\s*m\b/', $c['filament used'] ?? '', $x) ? (float) $x[1]
            : (preg_match('/([\d.]+)\s*mm/', $c['filament length'] ?? '', $x) ? (float) $x[1] / 1000 : null));
        $g  = $first('filament used [g]', 'total filament weight [g]', 'plastic weight');

        return [
            'G-code' => ['Slicer'      => $slicer,
                         'Sliced on'   => $made,
                         'Print time'  => $time !== null ? preg_replace_callback('/\b(\d+)([dhms])\b/', fn($x) => "$x[1] " . ['d' => 'd', 'h' => 'h', 'm' => 'min', 's' => 's'][$x[2]], $time) : null,
                         'Filament'    => $m ? round($m, 2) . ' m' : null,
                         'Weight'      => $g !== null && self::sum($g) > 0 ? round(self::sum($g), 1) . ' g' : null,
                         'Layers'      => $first('total layer number', 'total layers count', 'layer_count'),
                         'Height'      => ($h = $first('max_z_height', 'maxz')) !== null ? round((float) $h, 2) . ' mm' : null,
                         'Flavor'      => $first('flavor', 'gcode_flavor')],
            'Print'  => self::settings($c),
        ];
    }

    /** The common slicer settings, from "key = value" maps of PrusaSlicer, Orca, Bambu Studio and Cura. */
    private static function settings(array $c): array {
        $first = fn(string ...$keys) => array_values(array_filter(array_map(fn($k) => isset($c[$k]) ? self::list($c[$k]) : null, $keys)))[0] ?? null;
        $mm    = fn(?string $v) => $v === null ? null : "$v mm";
        $deg   = fn(?string $v) => $v === null || $v === '0' ? null : "$v °C";
        return ['Printer'         => $first('printer_model', 'printer_settings_id', 'target_machine.name', 'machine type'),
                'Print profile'   => $first('print_settings_id'),
                'Filament'        => $first('filament_settings_id'),
                'Filament type'   => $first('filament_type', 'material type'),
                'Nozzle'          => $mm($first('nozzle_diameter', 'printer_variant')),
                'Layer height'    => $mm($first('layer_height', 'layer height')),
                'First layer'     => $mm($first('first_layer_height', 'initial_layer_print_height')),
                'Infill'          => $first('fill_density', 'sparse_infill_density'),
                'Supports'        => isset($c['support_material']) ? ($c['support_material'] === '1' ? 'Yes' : 'No')
                                   : (isset($c['enable_support']) ? ($c['enable_support'] === '1' ? 'Yes' : 'No') : null),
                'Nozzle temp.'    => $deg($first('temperature', 'nozzle_temperature')),
                'Bed temp.'       => $deg($first('bed_temperature', 'hot_plate_temp', 'textured_plate_temp', 'cool_plate_temp'))];
    }

    // ---------- STL, OBJ, GLB: what the header says ----------

    private static function stl(string $full): array {
        $fp   = fopen($full, 'rb') ?: throw new Fail('Could not open this file', 500);
        $head = (string) fread($fp, 84 + 1024);
        fclose($fp);
        $n     = strlen($head) >= 84 ? unpack('V', $head, 80)[1] : 0;
        $ascii = 84 + 50 * $n !== filesize($full) && preg_match('/^\s*solid\b/', $head) && str_contains($head, 'facet');
        $name  = $ascii ? (preg_match('/^\s*solid[ \t]+([^\r\n]*)/', $head, $m) ? $m[1] : '')
                        : preg_replace('/^solid\s*/', '', preg_replace('/[^\x20-\x7e]+.*$/s', '', substr($head, 0, 80)));
        return ['Model' => ['Format' => $ascii ? 'STL (text)' : 'STL (binary)', 'Name' => trim(mb_scrub($name, 'UTF-8'))]];
    }

    private static function obj(string $full): array {
        $fp   = fopen($full, 'rb') ?: throw new Fail('Could not open this file', 500);
        $head = (string) fread($fp, 1 << 16);
        fclose($fp);
        preg_match_all('/^[ \t]*#[ \t]*(\S[^\r\n]*)/m', $head, $notes);
        preg_match_all('/^[ \t]*mtllib[ \t]+([^\r\n]+)/m', $head, $libs);
        return ['Model' => ['Format'    => 'Wavefront OBJ',
                            'Made with' => trim(mb_scrub($notes[1][0] ?? '', 'UTF-8')),
                            'Materials' => trim(mb_scrub(implode(', ', $libs[1]), 'UTF-8'))]];
    }

    private static function glb(string $full): array {
        $fp = fopen($full, 'rb') ?: throw new Fail('Could not open this file', 500);
        try {
            $h = unpack('Vmagic/Vversion/Vlength/Vjlen/Vjtype', (string) fread($fp, 20) . str_repeat("\0", 20));
            if ($h['magic'] !== 0x46546C67 || $h['jtype'] !== 0x4E4F534A || $h['jlen'] > 64 << 20) {
                throw new Fail('This is not a GLB file', 422);
            }
            $j = json_decode((string) fread($fp, $h['jlen']), true);
        } finally {
            fclose($fp);
        }
        if (! is_array($j)) {
            throw new Fail('This GLB file is damaged', 422);
        }
        $a     = is_array($j['asset'] ?? null) ? $j['asset'] : [];
        $x     = is_array($a['extras'] ?? null) ? $a['extras'] : []; // e.g. Sketchfab: title, author, license, source
        $str   = fn($v) => is_string($v) ? self::plain($v) : null;
        $count = fn(string $k) => is_array($j[$k] ?? null) && $j[$k] ? count($j[$k]) : null;
        $comp  = array_intersect(ModelGlb::COMPRESSED, (array) ($j['extensionsRequired'] ?? []));
        return [
            'Model' => ['Format'     => 'glTF ' . ($str($a['version'] ?? null) ?? '?') . ' binary (GLB)',
                        'Title'      => $str($x['title'] ?? null),
                        'Author'     => $str($x['author'] ?? null),
                        'License'    => $str($x['license'] ?? null),
                        'Source'     => $str($x['source'] ?? null),
                        'Copyright'  => $str($a['copyright'] ?? null),
                        'Made with'  => $str($a['generator'] ?? null)],
            'Contents' => ['Scenes'     => $count('scenes'),
                           'Nodes'      => $count('nodes'),
                           'Meshes'     => $count('meshes'),
                           'Materials'  => $count('materials'),
                           'Textures'   => $count('images'),
                           'Animations' => $count('animations'),
                           'Skins'      => $count('skins'),
                           'Extensions' => implode(', ', array_filter((array) ($j['extensionsUsed'] ?? []), 'is_string')),
                           'Compressed' => $comp ? implode(', ', $comp) . ' – can’t be shown here' : null],
        ];
    }

    // ---------- helpers ----------

    /** "; key = value" / ";KEY:value" comment lines as lower-case key => value (the first one of each key wins). */
    private static function comments(string $text): array {
        $out  = [];
        $text = preg_replace('/(?<=\S); (?=[a-z][a-z ]{2,40}:\s)/i', "\n; ", $text); // "; a: 1; b: 2" (Bambu Studio's header)
        preg_match_all('/^;[ \t]*([A-Za-z][\w .()\[\]\/-]{0,48}?)[ \t]*[=:][ \t]*([^\r\n]*)/m', $text, $m, PREG_SET_ORDER);
        foreach ($m as [, $k, $v]) {
            $out[strtolower(trim($k))] ??= trim(mb_scrub($v, 'UTF-8'), " \t\"'");
        }
        return $out;
    }

    /** "PLA;PLA;PETG" / "220,220" (one value per extruder) → "PLA, PETG" / "220". */
    private static function list(string $v): ?string {
        $v = trim($v, " \t\"'");
        if ($v === '' || strlen($v) > 300) {
            return null;
        }
        $parts = array_values(array_unique(array_filter(array_map(fn($s) => trim($s, " \t\"'"), preg_split('/[;,]/', $v)), 'strlen')));
        return $parts ? implode(', ', $parts) : null;
    }

    /** Sum of "12.3, 4.5" (one value per extruder). */
    private static function sum(string $v): float {
        return array_sum(array_map('floatval', preg_split('/[;,]/', $v)));
    }

    /** At most $max bytes from the start of a ZIP entry ('' when it is missing). */
    private static function zipHead(ZipArchive $z, string $name, int $max): string {
        $in = $z->statName($name) ? $z->getStream($name) : false;
        if (! $in) {
            return '';
        }
        $out = '';
        while (strlen($out) < $max && ($chunk = fread($in, min(65536, $max - strlen($out)))) !== false && $chunk !== '') {
            $out .= $chunk;
        }
        fclose($in);
        return $out;
    }

    private static function xmlText(string $s): string {
        $s = preg_replace('/^<!\[CDATA\[(.*)\]\]>$/s', '$1', trim($s));
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
    }

    /** Text without HTML (3MF descriptions are often HTML from MakerWorld / Printables). */
    private static function plain(string $s): string {
        $s = preg_replace('#<(br|/p|/div|/li|/h\d)\b[^>]*>#i', "\n", $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n\s*\n+/", "\n", $s)));
    }
}
