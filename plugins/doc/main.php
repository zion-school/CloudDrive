<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// Word 97-2003 (.doc): read-only, text only – paragraphs, line breaks and (roughly) tables. No formatting or pictures.
require_once __DIR__ . '/../lib/ole.php';

return new class extends Plugin {
    public array  $exts  = ['doc'];
    public string $label = 'Word 97-2003 document';

    public function view(string $full, array $ctx): array {
        $html = '';
        foreach ($this->blocks($full) as [$kind, $v]) {
            if ($kind === 'p') {
                $html .= '<p>' . $this->inline($v) . '</p>';
            } elseif ($kind === 'hr') {
                $html .= '<hr>';
            } else {
                $html .= '<table>' . implode('', array_map(fn($row) => '<tr>' . implode('', array_map(
                    fn($cell) => '<td>' . implode('', array_map(fn($p) => '<p>' . $this->inline($p) . '</p>', $cell)) . '</td>', $row)) . '</tr>', $v)) . '</table>';
            }
        }
        return ['html'   => $html,
                'notice' => 'Simplified preview – text only, without formatting or pictures. Download the file to see it exactly.'];
    }

    /** Document properties (the OLE summary information streams). */
    public function info(string $full, array $ctx): array {
        if (filesize($full) > $this->maxSize) {
            return [];
        }
        return (new OleFile((string) file_get_contents($full)))->info();
    }

    public function text(string $full): ?string {
        $out = [];
        foreach ($this->blocks($full) as [$kind, $v]) {
            if ($kind === 'p') {
                $out[] = $v;
            } elseif ($kind === 'table') {
                foreach ($v as $row) {
                    $out[] = implode("\t", array_map(fn($c) => implode(' ', $c), $row));
                }
            }
        }
        return trim(str_replace(["\x0B", "\x01"], ["\n", ''], implode("\n", $out)));
    }

    /** Escaped paragraph text: \x0B = line break, \x01 = picture. */
    private function inline(string $s): string {
        return strtr(self::h($s), ["\x0B" => '<br>', "\x01" => '<span class="ph">picture</span>', "\t" => '<span style="white-space:pre">' . "\t" . '</span>']);
    }

    /** The main document text split into ['p', text] / ['table', rows of cells of paragraphs] / ['hr', null]. */
    private function blocks(string $full): array {
        $chars  = mb_str_split($this->mainText($full));
        $blocks = [];
        $rows   = [];  // table being collected
        $cells  = [];  // cells of the current row
        $paras  = [];  // paragraphs of the current cell (or loose ones while not in a row)
        $cur    = '';
        $fields = [];  // stack: true while inside a field's code part (hidden)
        $flushTable = function () use (&$rows, &$blocks) {
            if ($rows) {
                $blocks[] = ['table', $rows];
                $rows     = [];
            }
        };
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $ch = $chars[$i];
            if ($ch === "\x13") {          // field begin: code follows
                $fields[] = true;
                continue;
            }
            if ($ch === "\x14") {          // field separator: result follows
                if ($fields) {
                    $fields[count($fields) - 1] = false;
                }
                continue;
            }
            if ($ch === "\x15") {          // field end
                array_pop($fields);
                continue;
            }
            if (in_array(true, $fields, true)) {
                continue;
            }
            switch ($ch) {
                case "\r":                 // paragraph end
                    if ($cells) {
                        $paras[] = $cur;   // inside a row: another paragraph of this cell
                    } else {
                        $flushTable();
                        $blocks[] = ['p', $cur];
                    }
                    $cur = '';
                    break;
                case "\x07":               // cell end; directly followed by another one = row end
                    $cells[] = [...$paras, $cur];
                    $paras   = [];
                    $cur     = '';
                    if (($chars[$i + 1] ?? '') === "\x07") {
                        $rows[] = $cells;
                        $cells  = [];
                        $i++;
                    }
                    break;
                case "\x0C":               // page / section break
                    $flushTable();
                    if ($cur !== '') {
                        $blocks[] = ['p', $cur];
                        $cur      = '';
                    }
                    $blocks[] = ['hr', null];
                    break;
                case "\x0B":               // line break
                case "\x0E":               // column break
                    $cur .= "\x0B";
                    break;
                case "\x01":               // picture
                case "\x08":               // drawing object
                    $cur .= "\x01";
                    break;
                case "\x1E":
                    $cur .= "\u{2011}";    // non-breaking hyphen
                    break;
                case "\t":
                    $cur .= "\t";
                    break;
                default:
                    if ($ch !== '' && mb_ord($ch) >= 0x20) {
                        $cur .= $ch;
                    }
            }
        }
        if ($cells) {
            $rows[] = $cells;
        }
        $flushTable();
        if ($cur !== '') {
            $blocks[] = ['p', $cur];
        }
        return $blocks;
    }

    /** Main document text via the FIB and the piece table (Clx) in the table stream. */
    private function mainText(string $full): string {
        $ole  = new OleFile((string) file_get_contents($full));
        $word = $ole->stream('WordDocument') ?? throw new Fail('This is not a Word document', 422);
        if (strlen($word) < 0x200 || unpack('v', $word)[1] !== 0xA5EC) {
            throw new Fail('This is not a Word document', 422);
        }
        $nFib  = unpack('v', $word, 2)[1];
        $flags = unpack('v', $word, 0x0A)[1];
        if ($nFib < 0x65) {
            throw new Fail('Word 95 and older files can’t be previewed – please download it', 422);
        }
        if ($flags & 0x0100) {
            throw new Fail('This document is password-protected and can’t be previewed', 422);
        }

        // FIB: base (32 bytes), csw + fibRgW, cslw + fibRgLw, cbRgFcLcb + fibRgFcLcb
        $csw     = unpack('v', $word, 32)[1];
        $lw      = 34 + $csw * 2 + 2;                  // fibRgLw
        $cslw    = unpack('v', $word, $lw - 2)[1];
        $ccpText = unpack('V', $word, $lw + 12)[1];    // characters in the main document
        $fcLcb   = $lw + $cslw * 4 + 2;                // fibRgFcLcb
        ['fc' => $fcClx, 'lcb' => $lcbClx] = unpack('Vfc/Vlcb', $word, $fcLcb + 33 * 8);

        $table = $ole->stream($flags & 0x0200 ? '1Table' : '0Table');
        if ($table === null || $lcbClx === 0) { // no piece table: one 8-bit run from fcMin
            return mb_convert_encoding(substr($word, unpack('V', $word, 0x18)[1], $ccpText), 'UTF-8', 'Windows-1252');
        }

        $clx = substr($table, $fcClx, $lcbClx);
        $p   = 0;
        while ($p < strlen($clx) && $clx[$p] === "\x01") { // Prc entries (formatting) come first
            $p += 3 + unpack('v', $clx, $p + 1)[1];
        }
        if (($clx[$p] ?? '') !== "\x02") {
            throw new Fail('This document is damaged or in an unsupported format', 422);
        }
        $lcb = unpack('V', $clx, $p + 1)[1];
        $plc = substr($clx, $p + 5, $lcb);
        $n   = intdiv($lcb - 4, 12);
        $cps = array_values(unpack('V' . ($n + 1), $plc));

        $out = '';
        for ($k = 0; $k < $n && $cps[$k] < $ccpText; $k++) {
            $len = min($cps[$k + 1], $ccpText) - $cps[$k];
            $fc  = unpack('V', $plc, ($n + 1) * 4 + $k * 8 + 2)[1];
            if ($fc & 0x40000000) { // compressed: 8-bit Windows-1252 at fc/2
                $out .= mb_convert_encoding(substr($word, ($fc & 0x3FFFFFFF) >> 1, $len), 'UTF-8', 'Windows-1252');
            } else {
                $out .= mb_convert_encoding(substr($word, $fc, $len * 2), 'UTF-8', 'UTF-16LE');
            }
        }
        return $out;
    }
};
