<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// Excel 97-2003 (.xls, BIFF8): read-only, cell values only. Deliberately simple – see SheetUtil::NOTICE.
require_once __DIR__ . '/../lib/ole.php';
require_once __DIR__ . '/../lib/sheet.php';

return new class extends Plugin {
    public array  $exts  = ['xls'];
    public string $label = 'Excel 97-2003 workbook';

    private array  $sst     = [];
    private array  $formats = [];
    private array  $xfs     = [];
    private bool   $d1904   = false;

    public function view(string $full, array $ctx): array {
        return ['sheets' => $this->sheets($full), 'notice' => SheetUtil::NOTICE];
    }

    /** Document properties (the OLE summary information streams). */
    public function info(string $full, array $ctx): array {
        if (filesize($full) > $this->maxSize) {
            return [];
        }
        return (new OleFile((string) file_get_contents($full)))->info();
    }

    public function text(string $full): ?string {
        return SheetUtil::text($this->sheets($full));
    }

    private function sheets(string $full): array {
        $ole = new OleFile((string) file_get_contents($full));
        $wb  = $ole->stream('Workbook');
        if ($wb === null) {
            throw new Fail($ole->stream('Book') !== null ? 'Excel 95 and older files can’t be previewed – please download it'
                                                        : 'This is not an Excel workbook', 422);
        }

        $this->sst     = $this->formats = $this->xfs = [];
        $this->d1904   = false;
        $sheets        = [];
        $recs          = $this->records($wb, 0);
        $first         = $recs->current();
        if (($first[0] ?? 0) !== 0x0809 || unpack('v', $first[1])[1] !== 0x0600) {
            throw new Fail('Only Excel 97 and newer .xls files can be previewed', 422);
        }

        $sstData = null;
        foreach ($recs as [$type, $data]) {
            switch ($type) {
                case 0x002F: // FILEPASS
                    throw new Fail('This workbook is password-protected and can’t be previewed', 422);
                case 0x0022: // DATEMODE
                    $this->d1904 = unpack('v', $data)[1] === 1;
                    break;
                case 0x0085: // BOUNDSHEET8: offset, visibility, type, name
                    $h = unpack('Vpos/Cstate/Ctype', $data);
                    if ($h['type'] === 0 && $h['state'] === 0) {
                        $p        = 6;
                        $sheets[] = ['name' => $this->str($data, $p, 1), 'pos' => $h['pos']];
                    }
                    break;
                case 0x041E: // FORMAT
                    $p = 2;
                    $this->formats[unpack('v', $data)[1]] = $this->str($data, $p, 2);
                    break;
                case 0x00E0: // XF
                    $this->xfs[] = unpack('v', $data, 2)[1];
                    break;
                case 0x00FC: // SST (+ CONTINUE records, handled by records())
                    $sstData = $data;
                    break;
                case 0x000A: // EOF of the globals
                    break 2;
            }
        }
        if ($sstData !== null) {
            $this->readSst($sstData);
        }

        $out = [];
        foreach ($sheets as $s) {
            $out[] = ['name' => $s['name']] + $this->sheet($wb, $s['pos']);
        }
        return $out;
    }

    /** Yields [type, data] records from $pos; CONTINUE payloads are appended to their record, with boundaries kept for the SST. */
    private function records(string $wb, int $pos): Generator {
        $n = strlen($wb);
        while ($pos + 4 <= $n) {
            ['t' => $t, 'l' => $l] = unpack('vt/vl', $wb, $pos);
            $data = substr($wb, $pos + 4, $l);
            $pos += 4 + $l;
            if ($t === 0x00FC) {
                $this->sstBreaks = [];
                while ($pos + 4 <= $n && unpack('v', $wb, $pos)[1] === 0x003C) {
                    $cl                = unpack('v', $wb, $pos + 2)[1];
                    $this->sstBreaks[] = strlen($data);
                    $data             .= substr($wb, $pos + 4, $cl);
                    $pos              += 4 + $cl;
                }
            }
            yield [$t, $data];
        }
    }

    private array $sstBreaks = [];

    /** Shared strings. A string's characters may continue in the next CONTINUE record, which then starts with a fresh flags byte. */
    private function readSst(string $d): void {
        $count = unpack('V', $d, 4)[1];
        $p     = 8;
        $len   = strlen($d);
        for ($k = 0; $k < $count && $p + 3 <= $len; $k++) {
            $cch   = unpack('v', $d, $p)[1];
            $flags = ord($d[$p + 2]);
            $p    += 3;
            $runs  = $flags & 0x08 ? unpack('v', $d, $p)[1] : 0;
            $p    += $flags & 0x08 ? 2 : 0;
            $ext   = $flags & 0x04 ? unpack('V', $d, $p)[1] : 0;
            $p    += $flags & 0x04 ? 4 : 0;
            $this->sst[] = $this->chars($d, $p, $cch, (bool) ($flags & 0x01), true);
            $p += 4 * $runs + $ext;
        }
    }

    /** $cch characters at $p (8-bit Latin-1 or UTF-16LE), switching width at CONTINUE boundaries when $sst. */
    private function chars(string $d, int &$p, int $cch, bool $wide, bool $sst = false): string {
        $out = '';
        while ($cch > 0 && $p < strlen($d)) {
            $next = PHP_INT_MAX;
            if ($sst) {
                foreach ($this->sstBreaks as $b) {
                    if ($b > $p) {
                        $next = $b;
                        break;
                    }
                }
            }
            $take  = min($cch, intdiv(min($next, strlen($d)) - $p, $wide ? 2 : 1));
            $bytes = substr($d, $p, $take * ($wide ? 2 : 1));
            $out  .= $wide ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
            $p    += strlen($bytes);
            $cch  -= $take;
            if ($cch > 0 && $p === $next) { // continued: new flags byte decides the width of the rest
                $wide = (bool) (ord($d[$p]) & 0x01);
                $p++;
            } elseif ($take === 0) {
                break;
            }
        }
        return $out;
    }

    /** XLUnicodeString with a 1- or 2-byte length prefix. */
    private function str(string $d, int &$p, int $lenBytes): string {
        if ($p + $lenBytes + 1 > strlen($d)) {
            return '';
        }
        $cch = $lenBytes === 1 ? ord($d[$p]) : unpack('v', $d, $p)[1];
        $p  += $lenBytes;
        $wide = (bool) (ord($d[$p]) & 0x01);
        $p++;
        return $this->chars($d, $p, $cch, $wide);
    }

    private function sheet(string $wb, int $pos): array {
        $cells     = [];
        $truncated = false;
        $pending   = null; // FORMULA with a string result: the text follows in a STRING record
        $put       = function (int $r, int $c, string $v) use (&$cells, &$truncated) {
            if ($r >= SheetUtil::MAX_ROWS || $c >= SheetUtil::MAX_COLS) {
                $truncated = true;
            } elseif ($v !== '') {
                $cells[$r][$c] = $v;
            }
        };
        $num = fn(float $v, int $xf) => SheetUtil::number($v, SheetUtil::isDate($id = $this->xfs[$xf] ?? 0, $this->formats[$id] ?? null), $this->d1904);

        foreach ($this->records($wb, $pos) as $i => [$type, $d]) {
            if ($i > 0 && $type === 0x0809) { // BOF of an embedded chart: stop
                break;
            }
            if (strlen($d) < 6 && $type !== 0x000A && $type !== 0x0207) {
                continue;
            }
            switch ($type) {
                case 0x000A: // EOF
                    break 2;
                case 0x00FD: // LABELSST
                    $h = unpack('vr/vc/vxf/Vi', $d);
                    $put($h['r'], $h['c'], $this->sst[$h['i']] ?? '');
                    break;
                case 0x0204: // LABEL
                    $h = unpack('vr/vc', $d);
                    $p = 6;
                    $put($h['r'], $h['c'], $this->str($d, $p, 2));
                    break;
                case 0x0203: // NUMBER
                    $h = unpack('vr/vc/vxf/ev', $d);
                    $put($h['r'], $h['c'], $num($h['v'], $h['xf']));
                    break;
                case 0x027E: // RK
                    $h = unpack('vr/vc/vxf/Vrk', $d);
                    $put($h['r'], $h['c'], $num($this->rk($h['rk']), $h['xf']));
                    break;
                case 0x00BD: // MULRK: row, first col, (xf, rk)×n, last col
                    $h = unpack('vr/vc', $d);
                    for ($k = 0, $p = 4; $p + 6 <= strlen($d) - 2; $k++, $p += 6) {
                        $x = unpack('vxf/Vrk', $d, $p);
                        $put($h['r'], $h['c'] + $k, $num($this->rk($x['rk']), $x['xf']));
                    }
                    break;
                case 0x0205: // BOOLERR
                    $h = unpack('vr/vc/vxf/Cv/Cerr', $d);
                    $put($h['r'], $h['c'], $h['err'] ? self::error($h['v']) : ($h['v'] ? 'TRUE' : 'FALSE'));
                    break;
                case 0x0006: // FORMULA: the cached result
                    $h = unpack('vr/vc/vxf', $d);
                    if (substr($d, 12, 2) === "\xFF\xFF") {
                        $kind = ord($d[6]);
                        if ($kind === 0) {
                            $pending = [$h['r'], $h['c']];
                        } elseif ($kind === 1) {
                            $put($h['r'], $h['c'], ord($d[8]) ? 'TRUE' : 'FALSE');
                        } elseif ($kind === 2) {
                            $put($h['r'], $h['c'], self::error(ord($d[8])));
                        }
                    } else {
                        $put($h['r'], $h['c'], $num(unpack('e', $d, 6)[1], $h['xf']));
                    }
                    break;
                case 0x0207: // STRING (result of the preceding formula)
                    if ($pending) {
                        $p = 0;
                        $put($pending[0], $pending[1], $this->str($d, $p, 2));
                        $pending = null;
                    }
                    break;
            }
        }
        return ['rows' => SheetUtil::dense($cells), 'truncated' => $truncated];
    }

    private static function error(int $code): string {
        return [0x00 => '#NULL!', 0x07 => '#DIV/0!', 0x0F => '#VALUE!', 0x17 => '#REF!', 0x1D => '#NAME?', 0x24 => '#NUM!',
                0x2A => '#N/A'][$code] ?? '#ERROR';
    }

    /** RK: a compressed number (30-bit integer or the top 30 bits of a double, optionally ÷100). */
    private function rk(int $rk): float {
        if ($rk & 0x02) {
            $v = $rk >> 2;
            if ($rk & 0x80000000) {
                $v -= 1 << 30;
            }
            $v = (float) $v;
        } else {
            $v = unpack('e', pack('VV', 0, $rk & 0xFFFFFFFC))[1];
        }
        return $rk & 0x01 ? $v / 100 : $v;
    }
};
