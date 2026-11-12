<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
// 3D models and printer G-code: STL, 3MF, OBJ, GLB, G-code. The browser reads the file itself and draws it with
// WebGL (main.js → viewer.js), so opening one costs the server nothing. The server only makes thumbnails: the
// slicer's own preview picture when the file has one, otherwise a drawing of its own (raster.php) – no libraries.

require_once __DIR__ . '/raster.php';
require_once __DIR__ . '/stl.php';
require_once __DIR__ . '/threemf.php';
require_once __DIR__ . '/obj.php';
require_once __DIR__ . '/glb.php';
require_once __DIR__ . '/gcode.php';
require_once __DIR__ . '/info.php';

return new class extends Plugin {
    public array  $exts    = ['stl', '3mf', 'obj', 'glb', 'gcode', 'gco'];
    public string $label   = '3D model';
    public int    $maxSize = 512 << 20; // what the browser is asked to load; thumbnails have their own limits below

    // Biggest files a thumbnail is drawn for (time and the server's memory). Built-in previews work at any size.
    const DRAW_MAX = ['stl' => 64 << 20, '3mf' => 64 << 20, 'obj' => 48 << 20, 'glb' => 32 << 20, 'gcode' => 48 << 20, 'gco' => 48 << 20];

    public function view(string $full, array $ctx): array {
        return []; // main.js fetches the file and draws it
    }

    /** What the file says about itself (info.php); the viewer adds the size and triangle count. */
    public function info(string $full, array $ctx): array {
        return ModelInfo::info($full, strtolower(pathinfo($full, PATHINFO_EXTENSION)));
    }

    public function thumbnail(string $full, int $size): ?GdImage {
        $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $draw = filesize($full) <= (self::DRAW_MAX[$ext] ?? 0);
        switch ($ext) {
            case '3mf':
                $z = self::openZip($full);
                try {
                    return ModelRaster::picture(Model3mf::picture($z), $size) ?? ($draw ? ModelRaster::mesh(Model3mf::source($z), $size) : null);
                } finally {
                    $z->close();
                }
            case 'gcode':
            case 'gco':
                return ModelRaster::picture(ModelGcode::picture($full), $size) ?? ($draw ? ModelRaster::lines(ModelGcode::source($full), $size) : null);
            case 'stl':
                return $draw ? ModelRaster::mesh(ModelStl::source($full), $size) : null;
            case 'obj':
                return $draw ? ModelRaster::mesh(ModelObj::source($full), $size) : null;
            case 'glb':
                return $draw ? ModelRaster::mesh(ModelGlb::source($full), $size) : null;
        }
        return null;
    }
};
