<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// Archives. Lists the members for the archive browser (main.js) and extracts single members, which the app then opens
// like any other file – including archives inside archives. ZIP: PHP's ZipArchive (7-Zip for methods it lacks, e.g.
// Deflate64). RAR, 7z: the 7-Zip program. tar, tar.gz / tgz, .gz: pure PHP (tar.php).
require_once __DIR__ . '/tar.php';
require_once __DIR__ . '/sevenzip.php';

return new class extends Plugin {
    public array  $exts    = ['zip', 'rar', '7z', 'tar', 'gz', 'tgz'];
    public string $label   = 'Archive';
    public int    $maxSize = 4 << 30; // listing reads the archive's index, not the whole file (tar.gz excepted)

    private const MAX_ENTRIES = 20000;

    public function view(string $full, array $ctx): array {
        $list = $this->entries($full, $ctx['password'] ?? null);
        return ['entries'   => array_map(fn($e) => array_diff_key($e, ['ref' => 0]), $list['entries']),
                'truncated' => $list['truncated']]
            + ($list['truncated'] ? ['notice' => 'This archive is very large – only the first ' . number_format(self::MAX_ENTRIES) . ' items are listed'] : []);
    }

    /** The names inside, for search and grid snippets. */
    public function text(string $full): ?string {
        if ($this->kind($full) === 'tar' && filesize($full) > 100 << 20) {
            return null; // listing a big tar.gz means decompressing all of it
        }
        return implode("\n", array_column($this->entries($full, null)['entries'], 'name'));
    }

    /** What is inside: counts, sizes, compression and encryption (from the cached listing). */
    public function info(string $full, array $ctx): array {
        $ext    = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $kind   = $this->kind($full);
        $format = match (true) {
            $ext === 'zip'  => 'ZIP',
            $ext === 'rar'  => 'RAR',
            $ext === '7z'   => '7-Zip',
            $kind === 'tar' => $ext === 'tar' ? 'tar' : 'tar, gzip-compressed',
            default         => 'gzip (one compressed file)',
        };
        if ($kind === 'tar' && filesize($full) > 100 << 20 && ! $this->listed($full)) {
            return ['Archive' => ['Format' => $format, 'Contents' => 'Open the archive once to count what is inside']];
        }
        try {
            $list = $this->entries($full, $ctx['password'] ?? null);
        } catch (NeedPassword) {
            return ['Archive' => ['Format' => $format, 'Contents' => 'The file names are encrypted – open the archive to enter the password']];
        } catch (Fail $e) {
            return ['Archive' => ['Format' => $format, 'Contents' => $e->getMessage()]];
        }
        $files  = array_filter($list['entries'], fn($e) => ! $e['dir']);
        $size   = array_sum(array_column($files, 'size'));
        $enc    = count(array_filter($files, fn($e) => $e['enc']));
        $times  = array_filter(array_column($files, 'mtime'));
        $packed = filesize($full);
        $out    = ['Format'        => $format,
                   'Files'         => count($files) . ($list['truncated'] ? '+' : ''),
                   'Folders'       => count($list['entries']) - count($files) ?: null,
                   'Unpacked size' => $size ? fmtSize($size) . ($size >= 1024 ? ' (' . number_format($size) . ' bytes)' : '') : null,
                   'Compressed to' => $size > $packed && $kind !== 'gz' ? max(1, (int) round(100 * $packed / $size)) . ' %' : null,
                   'Encrypted'     => $enc ? ($enc === count($files) ? 'Yes' : "$enc of the files") : 'No',
                   'Newest file'   => $times ? self::infoDate(max($times)) : null,
                   'Oldest file'   => $times && min($times) !== max($times) ? self::infoDate(min($times)) : null];
        if ($kind === 'zip') {
            $z = self::openZip($full);
            $out['Comment'] = (string) $z->getArchiveComment();
            $z->close();
        }
        return ['Archive' => $out];
    }

    /** True when the listing of a tar is cached already (see entries()), so info() can afford a big tar.gz. */
    private function listed(string $full): bool {
        $key = implode("\0", [realpath($full), filemtime($full), filesize($full), $this->kind($full), '']);
        return is_file(cacheDir() . '/' . sha1(static::class . "\0$key") . '.dat');
    }

    public function extract(string $full, string $entry, string $dest, array $ctx): void {
        [$pw, $limit] = [$ctx['password'], $ctx['limit']];
        $e = null;
        foreach ($this->entries($full, $pw)['entries'] as $x) {
            if ($x['name'] === $entry) {
                $e = $x;
                break;
            }
        }
        if (! $e || $e['dir']) {
            throw new Fail('Not found in the archive', 404);
        }
        if ($e['size'] > $limit) {
            throw new Fail('This file is too large to open from inside the archive – please download the archive', 413);
        }

        switch ($this->kind($full)) {
            case 'zip':
                $this->zipExtract($full, $e, $dest, $pw, $limit);
                return;
            case '7z':
                SevenZip::extract($full, $e['ref'], $pw, $dest, $limit);
                break;
            case 'tar':
                $found = false;
                Tar::scan($full, function (array $m, $fp) use ($e, $dest, $limit, &$found) {
                    if ($m['dir'] || $m['raw'] !== $e['ref']) {
                        return false;
                    }
                    $found = self::copyStream($fp, $dest, $limit, $m['size']) === $m['size'];
                    return true;
                });
                if (! $found) {
                    throw new Fail('Could not read this file from the archive – it may be damaged', 422);
                }
                return;
            default: // single-file .gz: the trailer's size is only modulo 4 GB, so it isn't checked
                $fp = Tar::open($full);
                try {
                    self::copyStream($fp, $dest, $limit);
                } finally {
                    fclose($fp);
                }
                return;
        }
        clearstatcache();
        if (filesize($dest) !== $e['size']) { // 7-Zip says nothing when a name doesn't match
            throw $e['enc'] ? new NeedPassword($pw !== null) : new Fail('Could not read this file from the archive – it may be damaged', 422);
        }
    }

    /** 'zip' | '7z' (the program) | 'tar' (also compressed) | 'gz' (a single compressed file) */
    private function kind(string $full): string {
        static $memo = [];
        return $memo[$full] ??= match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
            'zip'        => class_exists('ZipArchive') ? 'zip' : '7z',
            'rar', '7z'  => '7z',
            'tar', 'tgz' => 'tar',
            default      => preg_match('/\.tar\.gz$/i', $full) || Tar::isTar($full) ? 'tar' : 'gz',
        };
    }

    /**
     * ['entries' => [['name', 'size', 'mtime', 'dir', 'enc', 'ref'], …], 'truncated' => bool], cached until the file changes.
     * 'name' is cleaned up for display (no '..', '/'-separated, valid UTF-8); 'ref' is what the backend needs to extract it.
     */
    private function entries(string $full, ?string $pw): array {
        $kind = $this->kind($full);
        $key  = implode("\0", [realpath($full), filemtime($full), filesize($full), $kind, $kind === '7z' ? sha1($pw ?? '') : '']); // 7z can encrypt names
        return self::cached($key, function () use ($full, $pw, $kind) {
            $list = match ($kind) {
                'zip'   => $this->zipList($full),
                '7z'    => SevenZip::list($full, $pw, self::MAX_ENTRIES),
                'tar'   => $this->tarList($full),
                default => ['entries' => [Tar::gzEntry($full)], 'truncated' => false],
            };

            $out  = [];
            $seen = [];
            foreach ($list['entries'] as $e) {
                $parts = [];
                foreach (explode('/', str_replace('\\', '/', mb_scrub((string) $e['raw'], 'UTF-8'))) as $s) {
                    if ($s !== '' && $s !== '.' && $s !== '..') {
                        $parts[] = preg_replace('/[\x00-\x1f\x7f]/', '_', $s);
                    }
                }
                $name = implode('/', $parts);
                if ($name !== '' && ! isset($seen[$name])) {
                    $seen[$name] = true;
                    $out[]       = ['name'  => $name,
                                    'size'  => (int) $e['size'],
                                    'mtime' => (int) $e['mtime'],
                                    'dir'   => (bool) $e['dir'],
                                    'enc'   => (bool) ($e['enc'] ?? false),
                                    'ref'   => $e['ref'] ?? $e['raw']];
                }
            }
            return ['entries' => $out, 'truncated' => $list['truncated']];
        });
    }

    private function tarList(string $full): array {
        $out = [];
        $cut = false;
        Tar::scan($full, function (array $e) use (&$out, &$cut) {
            if (count($out) >= self::MAX_ENTRIES) {
                return $cut = true;
            }
            $out[] = $e;
            return false;
        });
        return ['entries' => $out, 'truncated' => $cut];
    }

    private function zipList(string $full): array {
        $z   = self::openZip($full);
        $out = [];
        for ($i = 0, $n = $z->numFiles; $i < $n && count($out) < self::MAX_ENTRIES; $i++) {
            if ($st = $z->statIndex($i)) { // names come back as UTF-8, converted from CP437 when needed
                $out[] = ['raw'   => $st['name'],
                          'size'  => $st['size'],
                          'mtime' => $st['mtime'],
                          'dir'   => str_ends_with($st['name'], '/'),
                          'enc'   => ($st['encryption_method'] ?? 0) !== 0,
                          'ref'   => $i];
            }
        }
        $z->close();
        return ['entries' => $out, 'truncated' => $i < $n];
    }

    private function zipExtract(string $full, array $e, string $dest, ?string $pw, int $limit): void {
        $z = self::openZip($full);
        try {
            $st = $z->statIndex($e['ref']) ?: throw new Fail('Not found in the archive', 404);
            if (method_exists('ZipArchive', 'isCompressionMethodSupported') && ! ZipArchive::isCompressionMethodSupported($st['comp_method'], false)) {
                if (! SevenZip::bin()) { // e.g. Deflate64, which Windows uses for big archives
                    throw new Fail('This file is compressed in a way the server cannot read – please download the archive', 422);
                }
                SevenZip::extract($full, $st['name'], $pw, $dest, $limit);
                clearstatcache();
                $ok = filesize($dest) === $st['size'];
            } else {
                if ($pw !== null) {
                    $z->setPassword($pw);
                }
                $in = @(method_exists($z, 'getStreamIndex') ? $z->getStreamIndex($e['ref']) : $z->getStream($st['name']));
                if (! $in) {
                    throw $e['enc'] ? new NeedPassword($pw !== null) : new Fail('Could not read this file from the archive – it may be damaged', 422);
                }
                $crc = hash_init('crc32b');
                $aes = in_array($st['encryption_method'] ?? 0, [257, 258, 259], true); // WinZip AES (AE-2) stores no CRC; libzip checks its HMAC instead
                $ok  = self::copyStream($in, $dest, $limit, null, $crc) === $st['size'] && ($aes || hexdec(hash_final($crc)) === $st['crc']);
                fclose($in);
            }
            if (! $ok) { // a ZipCrypto password can pass the quick check and still be wrong: the CRC tells
                throw $e['enc'] ? new NeedPassword(true) : new Fail('Could not read this file from the archive – it may be damaged', 422);
            }
        } finally {
            $z->close();
        }
    }
};
