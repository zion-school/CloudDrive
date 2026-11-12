<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// RTF: view as HTML and edit with a basic WYSIWYG editor (main.js).
// Keeps bold/italic/underline/strike, size, colour, highlight, super/subscript, alignment and lists.
// Pictures, tables, fields/links, headers/footers and footnotes are simplified; the editor warns before they are lost.
return new class extends Plugin {
    public array  $exts     = ['rtf'];
    public string $label    = 'Rich text (RTF)';
    public bool   $editable = true;

    /** Groups whose text is not document body. fonttbl/colortbl/pntext/listtext are read, the rest skipped. */
    const DESTS = ['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object', 'shppict', 'nonshppict', 'header', 'headerl',
                   'headerr', 'headerf', 'footer', 'footerl', 'footerr', 'footerf', 'footnote', 'pntext', 'listtext', 'fldinst',
                   'xmlnstbl', 'listtable', 'listoverridetable', 'revtbl', 'rsidtbl', 'generator', 'themedata', 'pgdsctbl',
                   'colorschememapping', 'latentstyles', 'datastore', 'filetbl', 'mmathPr', 'private', 'userprops', 'docvar', 'pn'];
    const LOSSY = ['pict' => 'pictures', 'shppict' => 'pictures', 'object' => 'embedded objects', 'fldinst' => 'links or fields',
                   'footnote' => 'footnotes', 'header' => 'headers and footers', 'headerl' => 'headers and footers',
                   'headerr' => 'headers and footers', 'headerf' => 'headers and footers', 'footer' => 'headers and footers',
                   'footerl' => 'headers and footers', 'footerr' => 'headers and footers', 'footerf' => 'headers and footers'];
    const CHARSET_CP = [77 => 10000, 128 => 932, 129 => 949, 134 => 936, 136 => 950, 161 => 1253, 162 => 1254, 163 => 1258,
                        177 => 1255, 178 => 1256, 186 => 1257, 204 => 1251, 222 => 874, 238 => 1250];
    const SYMBOLS = ['emdash' => '—', 'endash' => '–', 'lquote' => '‘', 'rquote' => '’', 'ldblquote' => '“', 'rdblquote' => '”',
                     'bullet' => '•', 'emspace' => "\u{2003}", 'enspace' => "\u{2002}", 'qmspace' => "\u{2005}"];
    const CHAR = ['b' => false, 'i' => false, 'u' => false, 's' => false, 'fs' => 24, 'cf' => 0, 'hl' => 0, 'sup' => 0, 'f' => null];

    // parser state (reset per parse)
    private array  $st, $stack, $fonts, $fontNames, $colors, $color, $lossy, $blocks, $table, $row, $cell, $para, $runProps;
    private string $line, $run, $bytes, $listText;
    private ?string $runKey;
    private int    $cp, $deff, $curFont, $skip, $high;

    // writer state
    private array $outColors = [];
    private array $outFonts  = [];
    private ?array $bullet = null;

    public function view(string $full, array $ctx): array {
        [$html, $lossy] = $this->parse((string) file_get_contents($full));
        $out = ['html' => $html, 'lossy' => $lossy];
        if ($lossy) {
            $out['notice'] = 'Simplified preview – this document contains ' . self::listing($lossy) . ', which are shown simplified or not at all.';
        }
        return $out;
    }

    public function text(string $full): ?string {
        [$html] = $this->parse((string) file_get_contents($full));
        $html = preg_replace('#<(br|/p|/li|/tr)\b[^>]*>#i', "\n", $html);
        $html = preg_replace('#</td>#i', "\t", $html);
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** The document properties in the {\info} group (title, author, dates, counts) near the start of the file. */
    public function info(string $full, array $ctx): array {
        $fp   = fopen($full, 'rb');
        $head = $fp ? (string) fread($fp, 1 << 20) : '';
        if ($fp) {
            fclose($fp);
        }
        $at = strpos($head, '{\\info');
        if ($at === false) {
            return [];
        }
        $cp = preg_match('/\\\\ansicpg(\d+)/', substr($head, 0, $at), $m) ? (int) $m[1] : 1252;
        // the group's own text: up to the brace that closes it
        for ($i = $at + 1, $depth = 1, $n = strlen($head); $i < $n && $depth > 0; $i++) {
            $c = $head[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            }
        }
        $info = substr($head, $at, $i - $at);
        $text = function (string $word) use ($info, $cp): ?string {
            if (! preg_match('/\{\\\\' . $word . '\b ?((?:[^{}\\\\]|\\\\.)*)\}/s', $info, $m)) {
                return null;
            }
            return self::infoText($m[1], $cp);
        };
        $time = function (string $word) use ($info): ?DateTimeImmutable {
            if (! preg_match('/\{\\\\' . $word . '((?:\\\\[a-z]+\d+ ?)+)\}/', $info, $m)) {
                return null;
            }
            preg_match_all('/\\\\(yr|mo|dy|hr|min|sec)(\d+)/', $m[1], $p, PREG_SET_ORDER);
            $t = array_column($p, 2, 1);
            if ((int) ($t['yr'] ?? 0) < 1971) {
                return null;
            }
            // RTF times are local to whoever saved the file; the server's zone is the best guess
            return (new DateTimeImmutable())->setDate((int) $t['yr'], (int) ($t['mo'] ?? 1), (int) ($t['dy'] ?? 1))
                ->setTime((int) ($t['hr'] ?? 0), (int) ($t['min'] ?? 0), (int) ($t['sec'] ?? 0));
        };
        $num = fn(string $word) => preg_match('/\\\\' . $word . '(\d+)/', $info, $m) && (int) $m[1] > 0 ? (int) $m[1] : null;
        $gen = preg_match('/\{\\\\\*\\\\generator ([^;}]*)/', $head, $m) ? trim(str_replace('/', ' ', explode('$', $m[1])[0])) : null; // "LibreOffice/6.2.5.2$Windows…"
        return [
            'Document'   => ['Title'    => $text('title'),
                             'Subject'  => $text('subject'),
                             'Author'   => $text('author'),
                             'Keywords' => $text('keywords'),
                             'Comments' => $text('doccomm'),
                             'Category' => $text('category'),
                             'Company'  => $text('company'),
                             'Manager'  => $text('manager')],
            'History'    => ['Created'          => $time('creatim'),
                             'Modified'         => $time('revtim'),
                             'Last modified by' => $text('operator'),
                             'Last printed'     => $time('printim'),
                             'Revision'         => $num('version'),
                             'Editing time'     => $num('edmins') ? self::infoDuration(60 * $num('edmins')) : null,
                             'Application'      => $gen],
            'Statistics' => ['Pages'      => $num('nofpages'),
                             'Words'      => $num('nofwords'),
                             'Characters' => $num('nofcharsws') ?? $num('nofchars')],
        ];
    }

    /** Text of an {\info} field: \'hh bytes in code page $cp, \uN characters (and their fallback skipped), escapes. */
    private static function infoText(string $s, int $cp): ?string {
        $out = preg_replace_callback('/\\\\u(-?\d+) ?(?:\\\\\'[0-9a-f]{2}|[^\\\\{}])?|\\\\\'([0-9a-f]{2})|\\\\([\\\\{}])|\\\\[a-z]+-?\d* ?/i', function ($m) use ($cp) {
            if (($m[1] ?? '') !== '') {
                return mb_chr(((int) $m[1] + 65536) % 65536, 'UTF-8') ?: '';
            }
            if (($m[2] ?? '') !== '') {
                $b = chr((int) hexdec($m[2]));
                return @iconv("CP$cp", 'UTF-8//IGNORE', $b) ?: mb_convert_encoding($b, 'UTF-8', 'Windows-1252');
            }
            return $m[3] ?? '';
        }, $s);
        $out = trim(preg_replace('/\s+/', ' ', mb_scrub((string) $out, 'UTF-8')));
        return $out === '' ? null : $out;
    }

    /** $data = ['html' => editor HTML]. Writes a fresh RTF document (Calibri, cp1252 + \u escapes). */
    public function save(string $full, mixed $data): string {
        $html = is_array($data) ? ($data['html'] ?? null) : null;
        if (! is_string($html) || strlen($html) > 10 << 20) {
            throw new Fail('Invalid document data', 400);
        }

        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->outColors = [];
        $this->outFonts  = ['Calibri' => 0, 'Symbol' => 1];
        $this->bullet    = null;
        $body   = $this->container($doc->getElementsByTagName('body')->item(0), ['align' => '', 'depth' => 0, 'heading' => 0]);
        $colors = implode('', array_map(fn($c) => "\\red$c[0]\\green$c[1]\\blue$c[2];", $this->outColors));
        $fonts  = '';
        foreach ($this->outFonts as $name => $i) {
            $fonts .= "{\\f$i\\fnil\\fcharset" . ($name === 'Symbol' ? 2 : 0) . " $name;}";
        }
        return "{\\rtf1\\ansi\\ansicpg1252\\deff0\\uc1{\\fonttbl$fonts}\n"
            . "{\\colortbl ;$colors}\n{\\*\\generator CloudDrive;}\\viewkind4\\pard\\f0\\fs24\n$body}\n";
    }

    private static function listing(array $items): string {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items) : $items[0];
    }

    // ================= RTF → HTML =================

    private function parse(string $rtf): array {
        if (! str_starts_with(ltrim($rtf), '{\rtf')) {
            throw new Fail('This is not a valid RTF file', 422);
        }

        $this->st     = self::CHAR + ['dest' => null, 'uc' => 1];
        $this->stack  = $this->fonts = $this->fontNames = $this->colors = $this->lossy = $this->blocks = $this->table = $this->row = $this->cell = [];
        $this->color  = [];
        $this->para   = ['align' => '', 'intbl' => false, 'list' => false];
        $this->line   = $this->run = $this->bytes = $this->listText = '';
        $this->runKey = null;
        $this->cp     = 1252;
        $this->deff   = $this->curFont = $this->skip = $this->high = 0;

        $n = strlen($rtf);
        for ($i = 0; $i < $n;) {
            $c = $rtf[$i];
            if ($c === '{' || $c === '}') {
                $this->flushBytes();
                if ($c === '{') {
                    $this->stack[] = $this->st;
                } elseif ($this->stack) {
                    $this->st = array_pop($this->stack);
                }
                $i++;
            } elseif ($c === '\\') {
                $next = $rtf[$i + 1] ?? '';
                if (ctype_alpha($next)) {
                    preg_match('/\G([a-zA-Z]{1,32})(-?\d{1,10})? ?/', $rtf, $m, 0, $i + 1);
                    $i += 1 + strlen($m[0]);
                    $this->word($m[1], isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, $i);
                } elseif ($next === "'") {
                    $byte = chr((int) hexdec(substr($rtf, $i + 2, 2)));
                    $i += 4;
                    $this->skip > 0 ? $this->skip-- : $this->bytes .= $byte;
                } else {
                    $i += 2;
                    if ($this->skip > 0 && $next !== "\n" && $next !== "\r") {
                        $this->skip--;
                        continue;
                    }
                    $this->flushBytes();
                    match ($next) {
                        '\\', '{', '}' => $this->emit($next),
                        '~'            => $this->emit("\u{a0}"),
                        '_'            => $this->emit("\u{2011}"),
                        '*'            => $this->st['dest'] = 'skip',
                        "\n", "\r"     => $this->word('par', null, $i),
                        default        => null,
                    };
                }
            } elseif ($c === "\r" || $c === "\n") {
                $i++;
            } else {
                $len = strcspn($rtf, "\\{}\r\n", $i);
                $s   = substr($rtf, $i, $len);
                $i  += $len;
                if ($this->skip > 0) {
                    $k = min($this->skip, strlen($s));
                    $s = substr($s, $k);
                    $this->skip -= $k;
                }
                $this->bytes .= $s;   // raw text is in the current code page, like \'hh bytes
            }
        }
        $this->flushBytes();
        if ($this->line !== '' || $this->run !== '') {
            $this->endPara();
        }
        $this->flushTable();

        $html = '';
        $list = null;
        foreach ($this->blocks as [$type, $h]) {
            if ($type !== $list) {
                $html .= ($list ? "</$list>" : '') . ($type ? "<$type>" : '');
                $list  = $type;
            }
            $html .= $h;
        }
        return [$html . ($list ? "</$list>" : ''), array_values(array_unique($this->lossy))];
    }

    private function word(string $w, ?int $p, int &$i): void {
        $this->flushBytes();
        $d = $this->st['dest'];
        if ($w === 'bin') { // binary picture data follows
            $i += max(0, (int) $p);
            return;
        }
        if ($w === 'u') {
            $this->skip = $this->st['uc'];
            $cp = ($p ?? 0) < 0 ? $p + 65536 : (int) $p;
            if ($cp >= 0xD800 && $cp < 0xDC00) {
                $this->high = $cp;
                return;
            }
            if ($cp >= 0xDC00 && $cp < 0xE000) {
                $cp = $this->high ? 0x10000 + (($this->high - 0xD800) << 10) + ($cp - 0xDC00) : 0xFFFD;
            }
            $this->high = 0;
            $this->emit(mb_chr($cp, 'UTF-8') ?: "\u{FFFD}");
            return;
        }
        if ($w === 'uc') {
            $this->st['uc'] = max(0, (int) $p);
            return;
        }
        if (in_array($w, self::DESTS, true)) {
            $parent = end($this->stack)['dest'] ?? null;
            if (isset(self::LOSSY[$w]) && $parent === null) {
                $this->lossy[] = self::LOSSY[$w];
                if (in_array($w, ['pict', 'shppict', 'object'], true)) {
                    $this->put('<span class="ph">picture</span>');
                }
            }
            $read = in_array($w, ['fonttbl', 'colortbl', 'pntext', 'listtext'], true) && $d === null;
            $this->st['dest'] = $read ? $w : 'skip';
            if ($read && ($w === 'pntext' || $w === 'listtext')) {
                $this->para['list'] = true;
                $this->listText     = '';
            }
            return;
        }
        if ($d === 'fonttbl') {
            if ($w === 'f') {
                $this->curFont = (int) $p;
            } elseif ($w === 'fcharset') {
                $this->fonts[$this->curFont] = (int) $p;
            }
            return;
        }
        if ($d === 'colortbl') {
            if (in_array($w, ['red', 'green', 'blue'], true)) {
                $this->color[$w] = (int) $p;
            }
            return;
        }
        if ($d !== null && ! isset(self::SYMBOLS[$w]) && $w !== 'tab') {
            return;
        }

        $on = $p === null || $p !== 0;
        switch ($w) {
            case 'ansicpg':
                $this->cp = (int) $p;
                break;
            case 'deff':
                $this->deff = (int) $p;
                break;
            case 'par':
            case 'sect':
            case 'page':
                $this->endPara();
                break;
            case 'line':
                $this->put('<br>');
                break;
            case 'tab':
                $d === null ? $this->put("<span style=\"white-space:pre\">\t</span>") : $this->emit(' ');
                break;
            case 'pard':
                $this->para = ['align' => '', 'intbl' => false, 'list' => false];
                break;
            case 'intbl':
                $this->para['intbl'] = true;
                break;
            case 'ql':
            case 'qc':
            case 'qr':
            case 'qj':
                $this->para['align'] = ['ql' => '', 'qc' => 'center', 'qr' => 'right', 'qj' => 'justify'][$w];
                break;
            case 'cell':
                $this->endCell();
                break;
            case 'row':
                $this->endRow();
                break;
            case 'plain':
                $this->st = array_merge($this->st, self::CHAR);
                break;
            case 'b':
                $this->st['b'] = $on;
                break;
            case 'i':
                $this->st['i'] = $on;
                break;
            case 'strike':
            case 'striked':
                $this->st['s'] = $on;
                break;
            case 'ul':
            case 'uld':
            case 'uldb':
            case 'ulw':
            case 'ulth':
            case 'uldash':
            case 'ulwave':
                $this->st['u'] = $on;
                break;
            case 'ulnone':
                $this->st['u'] = false;
                break;
            case 'fs':
                $this->st['fs'] = $p ?: 24;
                break;
            case 'cf':
                $this->st['cf'] = (int) $p;
                break;
            case 'highlight':
            case 'cb':
                $this->st['hl'] = (int) $p;
                break;
            case 'super':
                $this->st['sup'] = $on ? 1 : 0;
                break;
            case 'sub':
                $this->st['sup'] = $on ? -1 : 0;
                break;
            case 'nosupersub':
                $this->st['sup'] = 0;
                break;
            case 'f':
                $this->st['f'] = (int) $p;
                break;
            default:
                if (isset(self::SYMBOLS[$w])) {
                    $this->emit(self::SYMBOLS[$w]);
                }
        }
    }

    /** Decodes buffered code-page bytes (plain text and \'hh) with the current font's charset. */
    private function flushBytes(): void {
        if ($this->bytes === '') {
            return;
        }

        $b = $this->bytes;
        $this->bytes = '';
        if (preg_match('/[\x80-\xff]/', $b)) {
            $charset = $this->fonts[$this->st['f'] ?? $this->deff] ?? 0;
            $cp      = self::CHARSET_CP[$charset] ?? ($charset === 2 ? 1252 : $this->cp);
            $enc     = match ($cp) { 65001 => 'UTF-8', 10000 => 'MACINTOSH', default => "CP$cp" };
            $u       = $enc === 'UTF-8' ? $b : @iconv($enc, 'UTF-8//IGNORE', $b);
            $b       = $u !== false && $u !== '' ? $u : mb_convert_encoding($b, 'UTF-8', 'Windows-1252');
        }
        $this->emit($b);
    }

    /** Text (UTF-8) for the current destination. */
    private function emit(string $s): void {
        switch ($this->st['dest']) {
            case null:
                $this->put(self::h($s));
                break;
            case 'pntext':
            case 'listtext':
                $this->listText .= $s;
                break;
            case 'fonttbl':
                $this->fontNames[$this->curFont] = ($this->fontNames[$this->curFont] ?? '') . $s;
                break;
            case 'colortbl':
                for ($k = substr_count($s, ';'); $k > 0; $k--) {
                    $c = $this->color;
                    $this->colors[] = $c ? sprintf('#%02x%02x%02x', $c['red'] ?? 0, $c['green'] ?? 0, $c['blue'] ?? 0) : null;
                    $this->color = [];
                }
                break;
        }
    }

    private function put(string $html): void {
        $p = $this->st;
        $k = "$p[b]$p[i]$p[u]$p[s]|$p[fs]|$p[cf]|$p[hl]|$p[sup]|$p[f]";
        if ($k !== $this->runKey) {
            $this->flushRun();
            $this->runKey   = $k;
            $this->runProps = $p;
        }
        $this->run .= $html;
    }

    private function flushRun(): void {
        if ($this->run === '') {
            return;
        }

        $p    = $this->runProps;
        $html = $this->run;
        $css  = [];
        if ($font = $this->fontName($p['f'] ?? $this->deff)) {
            $css[] = "font-family:'$font'";
        }
        if ($p['fs'] !== 24) {
            $css[] = 'font-size:' . ($p['fs'] / 2) . 'pt';
        }
        if ($p['cf'] > 0 && ($c = $this->colors[$p['cf']] ?? null)) {
            $css[] = "color:$c";
        }
        if ($p['hl'] > 0 && ($c = $this->colors[$p['hl']] ?? null)) {
            $css[] = "background-color:$c";
        }
        if ($css) {
            $html = '<span style="' . implode(';', $css) . "\">$html</span>";
        }
        foreach (['b', 'i', 'u', 's'] as $t) {
            if ($p[$t]) {
                $html = "<$t>$html</$t>";
            }
        }
        if ($p['sup']) {
            $t    = $p['sup'] > 0 ? 'sup' : 'sub';
            $html = "<$t>$html</$t>";
        }
        $this->line .= $html;
        $this->run   = '';
    }

    /** Font name from the font table, reduced to characters that are safe inside CSS and RTF. */
    private function fontName(int $f): string {
        return self::safeFont(explode(';', $this->fontNames[$f] ?? '')[0]);
    }

    private static function safeFont(string $name): string {
        return trim(preg_replace('/[^\w \-]+/u', '', $name) ?? '');
    }

    private function endPara(): void {
        $this->flushRun();
        $this->runKey = null;
        $p     = $this->para;
        $style = $p['align'] ? " style=\"text-align:$p[align]\"" : '';
        $html  = $this->line === '' ? '<br>' : $this->line;
        $this->line = '';
        if ($p['intbl']) {
            $this->cell[] = "<p$style>$html</p>";
        } else {
            $this->flushTable();
            $type           = $p['list'] ? (preg_match('/^\s*[0-9a-zA-Z]{1,4}[.)]/', $this->listText) ? 'ol' : 'ul') : null;
            $this->blocks[] = [$type, $type ? "<li$style>$html</li>" : "<p$style>$html</p>"];
        }
        $this->para['list'] = false;
    }

    private function endCell(): void {
        if ($this->line !== '' || $this->run !== '' || ! $this->cell) {
            $this->para['intbl'] = true;
            $this->endPara();
        }
        $this->row[] = implode('', $this->cell);
        $this->cell  = [];
    }

    private function endRow(): void {
        if ($this->row) {
            $this->table[] = $this->row;
        }
        $this->row     = [];
        $this->lossy[] = 'tables';
    }

    private function flushTable(): void {
        if ($this->row) {
            $this->endRow();
        }
        if (! $this->table) {
            return;
        }

        $rows = array_map(fn($r) => '<tr>' . implode('', array_map(fn($c) => "<td>$c</td>", $r)) . '</tr>', $this->table);
        $this->blocks[] = [null, '<table>' . implode('', $rows) . '</table>'];
        $this->table    = [];
    }

    // ================= HTML (from the editor) → RTF =================

    const BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol', 'blockquote', 'pre', 'table', 'thead',
                    'tbody', 'tfoot', 'tr', 'td', 'th', 'hr', 'section', 'article', 'header', 'footer', 'caption'];
    const HEADING_FS = [1 => 40, 2 => 32, 3 => 28, 4 => 24, 5 => 24, 6 => 24];

    /** Children of a block: runs of inline content become paragraphs, block children recurse. */
    private function container(DOMNode $node, array $ctx): string {
        $out = '';
        $buf = '';
        foreach ($node->childNodes as $ch) {
            if ($ch instanceof DOMElement && in_array(strtolower($ch->tagName), self::BLOCKS, true)) {
                $out .= $this->para($buf, $ctx);
                $buf  = '';
                $out .= $this->block($ch, $ctx);
            } else {
                $buf .= $this->inline($ch);
            }
        }
        return $out . $this->para($buf, $ctx);
    }

    private function block(DOMElement $el, array $ctx): string {
        $tag = strtolower($el->tagName);
        if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $el->getAttribute('style'), $m)) {
            $ctx['align'] = strtolower($m[1]) === 'left' ? '' : strtolower($m[1]);
        } elseif (in_array($a = strtolower($el->getAttribute('align')), ['center', 'right', 'justify'], true)) {
            $ctx['align'] = $a;
        }

        if ($tag === 'ul' || $tag === 'ol') {
            $out = '';
            $num = 0;
            foreach ($el->childNodes as $ch) {
                if ($ch instanceof DOMElement && strtolower($ch->tagName) === 'li') {
                    $this->bullet = [$tag, ++$num];
                    $out .= $this->block($ch, ['depth' => $ctx['depth'] + 1] + $ctx);
                } elseif ($ch instanceof DOMElement) {
                    $out .= $this->block($ch, ['depth' => $ctx['depth'] + 1] + $ctx);
                }
            }
            return $out;
        }
        if ($tag === 'hr') {
            return "\\pard\\par\n";
        }
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $ctx['heading'] = (int) $m[1];
        }
        $out = $this->container($el, $ctx);
        if ($out === '' && ! in_array($tag, ['table', 'thead', 'tbody', 'tfoot', 'tr'], true)) {
            $out = $this->para('', $ctx, true); // an empty line the user typed
        }
        return $out;
    }

    private function para(string $buf, array $ctx, bool $force = false): string {
        if (! $force && trim($buf) === '') {
            return '';
        }

        $rtf = '\pard' . (['center' => '\qc', 'right' => '\qr', 'justify' => '\qj'][$ctx['align']] ?? '');
        if ($ctx['depth'] > 0) {
            $rtf .= '\fi-360\li' . (360 + 360 * $ctx['depth']);
        }
        if ($this->bullet) {
            [$type, $num] = $this->bullet;
            $this->bullet = null;
            $rtf .= $type === 'ol'
                ? "{\\pntext\\f0 $num.\\tab}{\\*\\pn\\pnlvlbody\\pnf0\\pnindent360\\pnstart1\\pndec{\\pntxta.}}"
                : "{\\pntext\\f1\\'B7\\tab}{\\*\\pn\\pnlvlblt\\pnf1\\pnindent360{\\pntxtb\\'B7}}";
        }
        if ($ctx['heading']) {
            $buf = '{\b\fs' . self::HEADING_FS[$ctx['heading']] . " $buf}";
        }
        return $rtf . (str_ends_with($rtf, '}') ? '' : ' ') . "$buf\\par\n"; // a space after '}' would be text
    }

    private function inline(DOMNode $node): string {
        if ($node instanceof DOMText) {
            return $this->escape($node->data);
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'br') { // a trailing <br> is the browser's placeholder for an empty line
            $last = ! $node->nextSibling && in_array(strtolower($node->parentNode->nodeName ?? ''), self::BLOCKS, true);
            return $last ? '' : '\line ';
        }
        if (in_array($tag, ['script', 'style', 'img', 'svg', 'object', 'iframe'], true)
            || in_array('ph', explode(' ', $node->getAttribute('class')), true)) { // .ph = "picture" placeholder from view()
            return '';
        }

        $inner = '';
        foreach ($node->childNodes as $ch) {
            $inner .= $this->inline($ch);
        }
        if (in_array($tag, self::BLOCKS, true)) { // a block nested in inline content: keep it on its own line
            return $inner . '\line ';
        }

        $cw = match ($tag) {
            'b', 'strong'       => '\b',
            'i', 'em'           => '\i',
            'u', 'ins'          => '\ul',
            's', 'strike', 'del' => '\strike',
            'sup'               => '\super',
            'sub'               => '\sub',
            default             => '',
        };
        if ($tag === 'font') {
            if (($c = $this->colorIndex($node->getAttribute('color'))) !== null) {
                $cw .= "\\cf$c";
            }
            if ($size = (int) $node->getAttribute('size')) {
                $cw .= '\fs' . 2 * ([1 => 8, 10, 12, 14, 18, 24, 36][max(1, min(7, $size))]);
            }
            if (($f = $this->fontIndex($node->getAttribute('face'))) !== null) {
                $cw .= "\\f$f";
            }
        }
        foreach (explode(';', $node->getAttribute('style')) as $decl) {
            [$k, $raw] = array_map('trim', explode(':', $decl, 2) + [1 => '']);
            $k  = strtolower($k);
            $v  = strtolower($raw);
            $cw .= match (true) {
                $k === 'font-family'                            => ($f = $this->fontIndex($raw)) !== null ? "\\f$f" : '',
                $k === 'font-weight'                            => preg_match('/bold|[6-9]00/', $v) ? '\b' : '\b0',
                $k === 'font-style'                             => str_contains($v, 'italic') ? '\i' : '\i0',
                str_starts_with($k, 'text-decoration')          => (str_contains($v, 'underline') ? '\ul' : '') . (str_contains($v, 'line-through') ? '\strike' : ''),
                $k === 'color'                                  => ($c = $this->colorIndex($v)) !== null ? "\\cf$c" : '',
                $k === 'background-color' || $k === 'background' => ($c = $this->colorIndex($v)) !== null ? "\\highlight$c" : '',
                $k === 'font-size'                              => ($pt = self::points($v)) ? '\fs' . round($pt * 2) : '',
                $k === 'vertical-align'                         => $v === 'super' ? '\super' : ($v === 'sub' ? '\sub' : ''),
                default                                         => '',
            };
        }
        return $cw === '' ? $inner : "{{$cw} $inner}";
    }

    private function escape(string $s): string {
        $s = preg_replace('/[ \r\n]+/', ' ', $s);
        return preg_replace_callback('/[^\x20-\x5b\x5d-\x7a\x7c\x7e]/u', function ($m) {
            $ch = $m[0];
            if ($ch === '\\' || $ch === '{' || $ch === '}') {
                return "\\$ch";
            }
            if ($ch === "\t") {
                return '\tab ';
            }
            $o = mb_ord($ch, 'UTF-8');
            if ($o === 0xA0) {
                return '\~';
            }
            if ($o < 0x20) {
                return '';
            }
            $u = fn($x) => '\u' . ($x > 32767 ? $x - 65536 : $x) . '?';
            if ($o < 0x10000) {
                return $u($o);
            }
            $o -= 0x10000;
            return $u(0xD800 + ($o >> 10)) . $u(0xDC00 + ($o & 0x3FF));
        }, $s);
    }

    /** CSS colour (#rgb, #rrggbb, rgb()/rgba()) → 1-based colour table index; null for none/transparent. */
    private function colorIndex(string $v): ?int {
        $v = strtolower(trim($v));
        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
            $rgb = array_map(fn($h) => hexdec($h . $h), str_split($m[1]));
        } elseif (preg_match('/^#([0-9a-f]{6})$/', $v, $m)) {
            $rgb = array_map('hexdec', str_split($m[1], 2));
        } elseif (preg_match('/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/', $v, $m)) {
            if (isset($m[4]) && (float) $m[4] === 0.0) {
                return null;
            }
            $rgb = [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];
        } else {
            return null;
        }
        $i = array_search($rgb, $this->outColors, true);
        if ($i === false) {
            $this->outColors[] = $rgb;
            $i = count($this->outColors) - 1;
        }
        return $i + 1;
    }

    /** First family of a CSS font-family list → index in the output font table (generic families are ignored). */
    private function fontIndex(string $v): ?int {
        $name = self::safeFont(explode(',', $v)[0]);
        if ($name === '' || in_array(strtolower($name), ['serif', 'sans-serif', 'monospace', 'cursive', 'system-ui', 'inherit'], true)) {
            return null;
        }
        return $this->outFonts[$name] ??= count($this->outFonts);
    }

    private static function points(string $v): ?float {
        $kw = ['xx-small' => 7, 'x-small' => 7.5, 'small' => 10, 'medium' => 12, 'large' => 13.5, 'x-large' => 18,
               'xx-large' => 24, 'xxx-large' => 36];
        if (isset($kw[$v])) {
            return $kw[$v];
        }
        if (! preg_match('/^([\d.]+)\s*(pt|px|em|rem)?$/', $v, $m)) {
            return null;
        }
        $n = (float) $m[1];
        return match ($m[2] ?? 'px') { 'pt' => $n, 'em', 'rem' => $n * 12, default => $n * 0.75 } ?: null;
    }
};
