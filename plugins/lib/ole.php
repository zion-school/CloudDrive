<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
/**
 * Minimal reader for OLE2 / Compound File Binary containers (legacy .doc, .xls, .ppt):
 * just enough to get a named stream's bytes. Read-only, no write support.
 */
final class OleFile {
    const END = 0xFFFFFFFA; // sector ids at or above this are markers (end of chain, free, FAT, DIFAT)

    private int    $sectorSize;
    private int    $miniSize;
    private int    $cutoff;
    private array  $fat     = [];
    private array  $miniFat = [];
    private array  $entries = [];
    private ?string $mini   = null;

    public function __construct(private string $data) {
        if (strlen($data) < 512 || ! str_starts_with($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new Fail('This file is damaged or not in the expected format', 422);
        }

        $h = unpack('vshift/vminiShift', $data, 0x1E)
           + unpack('VdirCount/VfatCount/VdirStart/Vtx/Vcutoff/VminiStart/VminiCount/VdifStart/VdifCount', $data, 0x28);
        if (! in_array($h['shift'], [9, 12], true) || $h['miniShift'] !== 6) {
            throw new Fail('This file is damaged or not in the expected format', 422);
        }
        $this->sectorSize = 1 << $h['shift'];
        $this->miniSize   = 1 << $h['miniShift'];
        $this->cutoff     = $h['cutoff'];

        // FAT sector list: 109 entries in the header, the rest in a chain of DIFAT sectors
        $difat = array_values(unpack('V109', $data, 0x4C));
        for ($s = $h['difStart'], $k = 0; $s < self::END && $k < $h['difCount']; $k++) {
            $ids = array_values(unpack('V*', $this->sector($s)));
            $s   = array_pop($ids);
            array_push($difat, ...$ids);
        }
        foreach ($difat as $s) {
            if ($s < self::END) {
                array_push($this->fat, ...array_values(unpack('V*', $this->sector($s))));
            }
        }

        $dir = $this->chain($h['dirStart']);
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $len = unpack('v', $dir, $o + 64)[1];
            $this->entries[] = ['name' => mb_convert_encoding(substr($dir, $o, max(0, min(64, $len) - 2)), 'UTF-8', 'UTF-16LE')]
                             + unpack('Ctype', $dir, $o + 66) + unpack('Vstart/Vsize', $dir, $o + 116);
        }
        if ($h['miniStart'] < self::END) {
            $this->miniFat = array_values(unpack('V*', $this->chain($h['miniStart'])));
        }
    }

    /** Bytes of the stream called $name (case-insensitive), or null. */
    public function stream(string $name): ?string {
        foreach ($this->entries as $e) {
            if ($e['type'] === 2 && strcasecmp($e['name'], $name) === 0) {
                return $e['size'] < $this->cutoff ? $this->miniChain($e['start'], $e['size']) : $this->chain($e['start'], $e['size']);
            }
        }
        return null;
    }

    /**
     * The document properties Office keeps in the "\x05SummaryInformation" and "\x05DocumentSummaryInformation"
     * streams, as sections for Plugin::info(). Empty when the file has none.
     */
    public function info(): array {
        $si  = $this->properties($this->stream("\x05SummaryInformation"));
        $dsi = $this->properties($this->stream("\x05DocumentSummaryInformation"));
        $num = fn(array $p, int $id) => is_int($p[$id] ?? null) && $p[$id] > 0 ? $p[$id] : null;
        $str = fn(array $p, int $id) => is_string($p[$id] ?? null) ? $p[$id] : null;
        $at  = fn(int $id) => is_int($si[$id] ?? null) ? Plugin::infoDate(self::unixTime($si[$id])) : null;
        $edit = is_int($si[10] ?? null) && $si[10] > 0 ? Plugin::infoDuration($si[10] / 1e7) : null; // a FILETIME holding a length
        return [
            'Document'   => ['Title'    => $str($si, 2),
                             'Subject'  => $str($si, 3),
                             'Author'   => $str($si, 4),
                             'Keywords' => $str($si, 5),
                             'Comments' => $str($si, 6),
                             'Category' => $str($dsi, 2),
                             'Company'  => $str($dsi, 15),
                             'Manager'  => $str($dsi, 14),
                             'Template' => $str($si, 7)],
            'History'    => ['Created'          => $at(12),
                             'Modified'         => $at(13),
                             'Last modified by' => $str($si, 8),
                             'Last printed'     => $at(11),
                             'Revision'         => $str($si, 9),
                             'Editing time'     => $edit,
                             'Application'      => $str($si, 18)],
            'Statistics' => ['Pages'      => $num($si, 14),
                             'Slides'     => $num($dsi, 7),
                             'Words'      => $num($si, 15),
                             'Characters' => $num($si, 16),
                             'Lines'      => $num($dsi, 5),
                             'Paragraphs' => $num($dsi, 6)],
        ];
    }

    /**
     * The first property set of a property set stream (MS-OLEPS): id => string / int (FILETIMEs as 100 ns ticks).
     * Strings use the set's code page (property 1). Types other than text, integers, booleans and times are skipped.
     */
    private function properties(?string $d): array {
        if ($d === null || strlen($d) < 48 || unpack('v', $d)[1] !== 0xFFFE) {
            return [];
        }
        $base = unpack('V', $d, 44)[1];
        if ($base + 8 > strlen($d)) {
            return [];
        }
        $n   = min(unpack('V', $d, $base + 4)[1], 1000);
        $raw = [];
        for ($i = 0; $i < $n && $base + 16 + 8 * $i <= strlen($d); $i++) {
            ['id' => $id, 'at' => $at] = unpack('Vid/Vat', $d, $base + 8 + 8 * $i);
            $o = $base + $at;
            if ($o + 8 > strlen($d)) {
                continue;
            }
            $type = unpack('v', $d, $o)[1];
            $raw[$id] = match ($type) {
                0x02, 0x0B => unpack('s', $d, $o + 4)[1],                   // VT_I2, VT_BOOL
                0x03, 0x13 => unpack('V', $d, $o + 4)[1],                   // VT_I4, VT_UI4
                0x40       => $o + 12 <= strlen($d) ? unpack('P', $d, $o + 4)[1] : null, // VT_FILETIME
                0x1E       => [false, substr($d, $o + 8, min(unpack('V', $d, $o + 4)[1], 1 << 16))], // VT_LPSTR: code page
                0x1F       => [true, substr($d, $o + 8, 2 * min(unpack('V', $d, $o + 4)[1], 1 << 15))], // VT_LPWSTR: UTF-16
                default    => null,
            };
        }
        $cp  = is_int($raw[1] ?? null) ? $raw[1] & 0xFFFF : 1252;
        $out = [];
        foreach ($raw as $id => $v) {
            if (is_array($v)) {
                [$wide, $s] = $v;
                $s = $wide || $cp === 1200 ? mb_convert_encoding($s, 'UTF-8', 'UTF-16LE')
                   : ($cp === 65001 ? $s : (@iconv("CP$cp", 'UTF-8//IGNORE', $s) ?: mb_convert_encoding($s, 'UTF-8', 'Windows-1252')));
                $v = trim(mb_scrub(explode("\0", $s)[0], 'UTF-8'));
            }
            if ($v !== null && $v !== '' && $id !== 1) {
                $out[$id] = $v;
            }
        }
        return $out;
    }

    /** FILETIME ticks (100 ns since 1601) as unix time. */
    private static function unixTime(int $ticks): int {
        return intdiv($ticks, 10000000) - 11644473600;
    }

    private function sector(int $n): string {
        $s = substr($this->data, ($n + 1) * $this->sectorSize, $this->sectorSize);
        if (strlen($s) !== $this->sectorSize) {
            throw new Fail('This file is damaged (truncated)', 422);
        }
        return $s;
    }

    private function chain(int $start, ?int $size = null): string {
        $out = [];
        for ($s = $start, $guard = count($this->fat) + 1; $s < self::END && $guard-- > 0; $s = $this->fat[$s] ?? self::END) {
            $out[] = $this->sector($s);
            if ($size !== null && count($out) * $this->sectorSize >= $size) {
                break;
            }
        }
        $out = implode('', $out);
        return $size === null ? $out : substr($out, 0, $size);
    }

    private function miniChain(int $start, int $size): string {
        $root = $this->entries[0] ?? ['start' => self::END, 'size' => 0];
        $this->mini ??= $this->chain($root['start'], $root['size']);
        $out = [];
        for ($s = $start, $guard = count($this->miniFat) + 1; $s < self::END && $guard-- > 0; $s = $this->miniFat[$s] ?? self::END) {
            $out[] = substr($this->mini, $s * $this->miniSize, $this->miniSize);
            if (count($out) * $this->miniSize >= $size) {
                break;
            }
        }
        return substr(implode('', $out), 0, $size);
    }
}
