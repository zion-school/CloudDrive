<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// A small tar reader – POSIX ustar, GNU long names and base-256 sizes, pax headers – for plain and gzip-compressed
// tar files (read through compress.zlib://, which passes uncompressed files through as they are). Also single-file .gz.

final class Tar {
    /** Opens $full for reading, decompressing gzip on the fly. */
    public static function open(string $full) {
        $fp = @fopen((extension_loaded('zlib') ? 'compress.zlib://' : '') . $full, 'rb');
        return $fp ?: throw new Fail('Could not open this archive', 422);
    }

    /** Whether $full (e.g. a .gz) starts with a valid tar header, rather than being one compressed file. */
    public static function isTar(string $full): bool {
        $fp = self::open($full);
        $h  = self::read($fp, 512);
        fclose($fp);
        return $h !== null && trim($h, "\0") !== '' && self::checksumOk($h);
    }

    /**
     * Calls $fn(['raw' => name, 'size', 'mtime', 'dir'], $stream) for each file and folder, in order.
     * $fn either reads nothing, or reads the member's data and returns true to stop.
     */
    public static function scan(string $full, callable $fn): void {
        $fp = self::open($full);
        try {
            $long = null;
            $pax  = [];
            while (($h = self::read($fp, 512)) !== null && trim($h, "\0") !== '') { // a zero block ends the archive
                if (! self::checksumOk($h)) {
                    throw new Fail('This archive is damaged or not in the expected format', 422);
                }

                $type = $h[156];
                $size = self::num(substr($h, 124, 12));
                if (in_array($type, ['L', 'K', 'x', 'g'], true)) { // GNU long name / link, pax extended / global header
                    $data = $size <= 1 << 20 ? self::read($fp, $size + self::pad($size)) : null;
                    if ($data === null) {
                        throw new Fail('This archive is damaged or not in the expected format', 422);
                    }
                    if ($type === 'L') {
                        $long = rtrim(substr($data, 0, $size), "\0");
                    } elseif ($type === 'x') {
                        $pax = self::pax(substr($data, 0, $size));
                    }
                    continue;
                }

                $name = rtrim(substr($h, 0, 100), "\0");
                if (substr($h, 257, 6) === "ustar\0" && ($prefix = rtrim(substr($h, 345, 155), "\0")) !== '') {
                    $name = "$prefix/$name";
                }
                $name  = $pax['path'] ?? $long ?? $name;
                $size  = isset($pax['size']) ? (int) $pax['size'] : $size;
                $mtime = isset($pax['mtime']) ? (int) $pax['mtime'] : self::num(substr($h, 136, 12));
                $long  = null;
                $pax   = [];
                $dir   = $type === '5' || str_ends_with($name, '/');
                $file  = in_array($type, ['0', "\0", '7'], true);   // links, devices and fifos are skipped
                if (($dir || $file) && $fn(['raw' => $name, 'size' => $dir ? 0 : $size, 'mtime' => $mtime, 'dir' => $dir], $fp)) {
                    return;
                }
                self::skip($fp, $size + self::pad($size));
            }
        } finally {
            fclose($fp);
        }
    }

    /** A plain .gz holds one file: named like the archive without .gz; the size is from the gzip trailer. */
    public static function gzEntry(string $full): array {
        $fp = fopen($full, 'rb');
        $h  = (string) fread($fp, 10);
        fseek($fp, -4, SEEK_END);
        $tail = (string) fread($fp, 4);
        fclose($fp);
        if (strlen($h) < 10 || strlen($tail) < 4 || substr($h, 0, 2) !== "\x1f\x8b") {
            throw new Fail('This archive is damaged or not in the expected format', 422);
        }
        return ['raw'   => preg_replace('/\.gz$/i', '', basename($full)) ?: 'file',
                'size'  => unpack('V', $tail)[1],
                'mtime' => unpack('V', substr($h, 4, 4))[1] ?: filemtime($full),
                'dir'   => false];
    }

    /** Exactly $n bytes, or null at the end of the stream. */
    private static function read($fp, int $n): ?string {
        $s = '';
        while (strlen($s) < $n) {
            $chunk = @fread($fp, $n - strlen($s));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $s .= $chunk;
        }
        return $s;
    }

    private static function skip($fp, int $n): void {
        if ($n > 0 && @fseek($fp, $n, SEEK_CUR) !== 0) { // compress.zlib seeks forward by decompressing
            for (; $n > 0; $n -= 65536) {
                if (self::read($fp, min(65536, $n)) === null) {
                    return;
                }
            }
        }
    }

    private static function pad(int $size): int {
        return (512 - $size % 512) % 512;
    }

    /** Octal field, or GNU base-256 when the high bit is set. */
    private static function num(string $f): int {
        if ($f !== '' && (ord($f[0]) & 0x80)) {
            $n = ord($f[0]) & 0x7f;
            for ($i = 1; $i < strlen($f); $i++) {
                $n = $n * 256 + ord($f[$i]);
            }
            return $n;
        }
        preg_match('/^[0-7]*/', trim($f, " \0"), $m);
        return (int) octdec($m[0] ?: '0');
    }

    private static function checksumOk(string $h): bool {
        $want  = self::num(substr($h, 148, 8));
        $bytes = substr_replace($h, '        ', 148, 8); // the checksum is computed with its own field as spaces
        return $want === array_sum(unpack('C*', $bytes)) || $want === array_sum(unpack('c*', $bytes)); // some old tars summed signed bytes
    }

    /** pax records: "<length> key=value\n" … */
    private static function pax(string $d): array {
        $out = [];
        for ($i = 0; preg_match('/\G(\d+) /', $d, $m, 0, $i) && (int) $m[1] > strlen($m[0]); $i += (int) $m[1]) {
            [$k, $v]  = explode('=', substr($d, $i + strlen($m[0]), (int) $m[1] - strlen($m[0]) - 1), 2) + [1 => ''];
            $out[$k]  = $v;
        }
        return $out;
    }
}
