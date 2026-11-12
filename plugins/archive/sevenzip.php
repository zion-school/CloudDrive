<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// Runs the 7-Zip program (for RAR and 7z) without a shell. Every call passes -p (an empty one when there is no
// password) and has no stdin: otherwise 7-Zip would wait for someone to type a password.

final class SevenZip {
    /** Path of 7zz / 7z (SEVENZIP in config.php), or null. 7za is not used: it cannot read RAR. */
    public static function bin(): ?string {
        static $bin = false;
        if ($bin !== false) {
            return $bin;
        }

        $bin = null;
        $cfg = defined('SEVENZIP') ? (string) SEVENZIP : '';
        if ($cfg === 'off' || ! function_exists('proc_open')) {
            return null;
        }
        $dirs = array_unique(array_filter([...explode(PATH_SEPARATOR, (string) getenv('PATH')), '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']));
        foreach ($cfg !== '' ? [$cfg] : ['7zz', '7z'] as $name) {
            foreach (str_contains($name, '/') ? [$name] : array_map(fn($d) => "$d/$name", $dirs) as $path) {
                if (@is_file($path) && @is_executable($path)) {
                    return $bin = $path;
                }
            }
        }
        return null;
    }

    /** Members as ['raw', 'size', 'mtime', 'dir', 'enc'], at most $max of them. */
    public static function list(string $full, ?string $pw, int $max): array {
        $out = '';
        [$code, $err, $cut] = self::run(['l', '-slt', '-ba', '-sccUTF-8', '-p' . ($pw ?? ''), '--', $full], $out, 64 << 20);
        if (! $cut) {
            self::check($code, $err, $pw);
        }

        $blocks = preg_split('/\R\R+/', $out);
        if ($cut) {
            array_pop($blocks); // cut off mid-way
        }
        $entries = [];
        foreach ($blocks as $block) {
            $f = [];
            foreach (preg_split('/\R/', $block) as $line) {
                if (($i = strpos($line, ' = ')) !== false) {
                    $f[substr($line, 0, $i)] ??= substr($line, $i + 3);
                }
            }
            if (! isset($f['Path'])) {
                continue;
            }
            if (count($entries) >= $max) {
                $cut = true;
                break;
            }
            $entries[] = ['raw'   => $f['Path'],
                          'size'  => (int) ($f['Size'] ?? 0),
                          'mtime' => (int) strtotime(substr($f['Modified'] ?? '', 0, 19)),
                          'dir'   => ($f['Folder'] ?? '') === '+' || str_starts_with($f['Attributes'] ?? '', 'D'),
                          'enc'   => ($f['Encrypted'] ?? '') === '+'];
        }
        return ['entries' => $entries, 'truncated' => $cut];
    }

    /** Writes member $name (exactly as list() reported it) to $dest. */
    public static function extract(string $full, string $name, ?string $pw, string $dest, int $limit): void {
        $fp = fopen($dest, 'wb') ?: throw new Fail('Could not open this file', 500);
        try {
            // -spd: names are literal, not wildcards; -- : a name starting with - or @ is not a switch / list file
            [$code, $err, $cut] = self::run(['e', '-so', '-spd', '-bd', '-sccUTF-8', '-p' . ($pw ?? ''), '--', $full, $name], $fp, $limit);
        } finally {
            fclose($fp);
        }
        if ($cut) {
            throw new Fail('This file is too large to open from inside the archive – please download the archive', 413);
        }
        self::check($code, $err, $pw);
    }

    private static function check(int $code, string $err, ?string $pw): void {
        if (stripos($err, 'wrong password') !== false) { // "Cannot open encrypted archive. Wrong password?", "Data Error in encrypted file. Wrong password?"
            throw new NeedPassword($pw !== null);
        }
        if ($code >= 2) {
            error_log("CloudDrive archive: 7-Zip exit code $code: " . trim($err));
            throw new Fail(preg_match('/can ?not open the file as archive/i', $err)
                ? 'This archive is damaged or in a format the server cannot read'
                : 'Could not read this archive – it may be damaged', 422);
        }
    }

    /** Runs 7-Zip. Its output goes to $out (a string it is appended to, or an open file). Returns [exit code, stderr, cut off at $limit]. */
    private static function run(array $args, &$out, int $limit, int $timeout = 600): array {
        $bin = self::bin() ?? throw new Fail('RAR and 7z archives cannot be opened on this server – please download it', 501);
        $p   = proc_open([$bin, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                         ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8']);
        if (! is_resource($p)) {
            throw new Fail('Could not run 7-Zip', 500);
        }

        fclose($pipes[0]);
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }
        [$err, $n, $cut, $fail, $end] = ['', 0, false, null, time() + $timeout];
        while ($open && ! $cut && ! $fail) {
            $r = array_values($open);
            $w = $x = null;
            if (@stream_select($r, $w, $x, 1) === false) {
                $fail = new Fail('Could not read this archive', 500);
                break;
            }
            foreach ($open as $i => $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($open[$i]);
                    }
                    continue;
                }
                if ($i === 2) {
                    $err .= strlen($err) < 65536 ? $chunk : '';
                } elseif (($n += strlen($chunk)) > $limit) {
                    $cut = true;
                    break;
                } elseif (is_string($out)) {
                    $out .= $chunk;
                } elseif (fwrite($out, $chunk) !== strlen($chunk)) {
                    $fail = new Fail('The server is out of space', 507);
                    break;
                }
            }
            if (time() > $end) {
                $fail = new Fail('Opening this archive took too long', 504);
            }
        }
        foreach ($open as $pipe) {
            fclose($pipe);
        }
        if ($open) {
            proc_terminate($p, 9);
        }
        $code = proc_close($p);
        if ($fail) {
            throw $fail;
        }
        return [$code, $err, $cut];
    }
}
