<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// OpenDocument text (.odt), spreadsheets (.ods) and presentations (.odp): read-only, deliberately simple.
// One plugin, three formats: view() returns a document ('html') or a workbook ('sheets') depending on the file;
// a presentation is a document with one card per slide (its text, tables and pictures, top to bottom).
require_once __DIR__ . '/../lib/sheet.php';

return new class extends Plugin {
    public array  $exts  = ['odt', 'ods', 'odp'];
    public string $label = 'OpenDocument';

    const NS_TABLE  = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    const NS_TEXT   = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    const NS_STYLE  = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    const NS_FO     = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';
    const NS_XLINK  = 'http://www.w3.org/1999/xlink';
    const NS_SVG    = 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0';
    const NS_DRAW   = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';
    const NS_META   = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';
    const NS_DC     = 'http://purl.org/dc/elements/1.1/';
    const IMAGES    = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
                       'bmp' => 'image/bmp', 'webp' => 'image/webp'];

    private array    $styles     = []; // style name => ['tags' => [...], 'css' => [...], 'align' => '']
    private array    $listStyles = []; // list style name => [level => 'ul'|'ol']
    private ?Closure $asset      = null;

    public function view(string $full, array $ctx): array {
        if ($this->isSheet($full)) {
            return ['sheets' => $this->sheets($full), 'notice' => SheetUtil::NOTICE];
        }
        $z   = self::openZip($full);
        $doc = $this->xml($z, 'content.xml') ?? throw new Fail('This is not an OpenDocument file', 422);
        $this->styles = $this->listStyles = [];
        if ($styles = $this->xml($z, 'styles.xml')) {
            $this->readStyles($styles);
        }
        $this->readStyles($doc); // automatic styles override named ones
        $this->asset = $ctx['asset'] ?? null;
        $z->close();
        if ($show = $doc->getElementsByTagNameNS(self::NS_OFFICE, 'presentation')->item(0)) {
            return ['html'   => $this->slides($show),
                    'notice' => 'Simplified preview – slide text and pictures only; layout, backgrounds and animations are not shown.'];
        }
        $text = $doc->getElementsByTagNameNS(self::NS_OFFICE, 'text')->item(0);
        return ['html'   => $text ? $this->blocks($text) : '',
                'notice' => 'Simplified preview – some formatting and layout may be missing. Download the file to see it exactly.'];
    }

    public function text(string $full): ?string {
        if ($this->isSheet($full)) {
            return SheetUtil::text($this->sheets($full));
        }
        $z   = self::openZip($full);
        $xml = self::zipEntry($z, 'content.xml');
        $z->close();
        if ($xml === null) {
            return null;
        }
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $out = [];
        for ($ok = $r->read(); $ok;) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->namespaceURI === self::NS_TEXT && ($r->localName === 'p' || $r->localName === 'h')) {
                $out[] = $r->readString();
                $ok    = $r->next();
            } else {
                $ok = $r->read();
            }
        }
        return trim(implode("\n", $out));
    }

    /** Document properties (meta.xml), plus the slides of a presentation and the sheets of a spreadsheet. */
    public function info(string $full, array $ctx): array {
        $z = self::openZip($full);
        try {
            $meta = $this->xml($z, 'meta.xml')?->getElementsByTagNameNS(self::NS_OFFICE, 'meta')->item(0);
            $body = $this->bodyCounts($z, strtolower(pathinfo($full, PATHINFO_EXTENSION)));
        } finally {
            $z->close();
        }
        $get = function (string $ns, string $name) use ($meta): ?string {
            $n = $meta?->getElementsByTagNameNS($ns, $name)->item(0);
            return $n && ($v = trim($n->textContent)) !== '' ? $v : null;
        };
        $keywords = [];
        foreach ($meta?->getElementsByTagNameNS(self::NS_META, 'keyword') ?? [] as $k) {
            $keywords[] = trim($k->textContent);
        }
        $stat = $meta?->getElementsByTagNameNS(self::NS_META, 'document-statistic')->item(0);
        $num  = fn(string $a) => $stat && ($v = (int) $stat->getAttributeNS(self::NS_META, $a)) > 0 ? $v : null;
        // "LibreOffice/7.6.4.1$Linux_X86_64 LibreOffice_project/…" → "LibreOffice 7.6.4.1"
        $gen  = ($g = $get(self::NS_META, 'generator')) ? str_replace('/', ' ', explode('$', $g)[0]) : null;
        $time = $get(self::NS_META, 'editing-duration');
        return [
            'Document'   => ['Title'       => $get(self::NS_DC, 'title'),
                             'Subject'     => $get(self::NS_DC, 'subject'),
                             'Author'      => $get(self::NS_META, 'initial-creator'),
                             'Keywords'    => implode(', ', array_filter($keywords)),
                             'Description' => $get(self::NS_DC, 'description'),
                             'Template'    => $meta?->getElementsByTagNameNS(self::NS_META, 'template')->item(0)?->getAttributeNS(self::NS_XLINK, 'title')],
            'History'    => ['Created'          => self::infoDate($get(self::NS_META, 'creation-date')),
                             'Modified'         => self::infoDate($get(self::NS_DC, 'date')),
                             'Last modified by' => $get(self::NS_DC, 'creator'),
                             'Last printed'     => self::infoDate($get(self::NS_META, 'print-date')),
                             'Printed by'       => $get(self::NS_META, 'printed-by'),
                             'Revision'         => $get(self::NS_META, 'editing-cycles'),
                             'Editing time'     => $time ? self::infoDuration(self::isoSeconds($time)) : null,
                             'Application'      => $gen],
            'Statistics' => ['Pages'      => $num('page-count'),
                             'Slides'     => $body['slides'] ?? null,
                             'Sheets'     => isset($body['sheets']) ? count($body['sheets']) : null,
                             'Sheet names' => isset($body['sheets']) ? implode("\n", array_slice($body['sheets'], 0, 100)) : null,
                             'Words'      => $num('word-count'),
                             'Characters' => $num('character-count'),
                             'Paragraphs' => $num('paragraph-count'),
                             'Tables'     => $body ? null : $num('table-count'),
                             'Pictures'   => $num('image-count'),
                             'Objects'    => $num('object-count')],
        ];
    }

    /** The page preview LibreOffice / OpenOffice save in every file (Thumbnails/thumbnail.png). */
    public function thumbnail(string $full, int $size): ?GdImage {
        $z = self::openZip($full);
        try {
            $st = $z->statName('Thumbnails/thumbnail.png');
            return $st && $st['size'] <= 8 << 20 ? self::thumbPicture((string) $z->getFromName('Thumbnails/thumbnail.png'), $size) : null;
        } finally {
            $z->close();
        }
    }

    /** ISO 8601 duration ("PT1H2M3.5S", "P1DT2H") in seconds. */
    private static function isoSeconds(string $d): float {
        if (! preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?)?$/', $d, $m)) {
            return 0;
        }
        return 86400 * (int) ($m[1] ?? 0) + 3600 * (int) ($m[2] ?? 0) + 60 * (int) ($m[3] ?? 0) + (float) ($m[4] ?? 0);
    }

    /** ['slides' => n] for a presentation, ['sheets' => names] for a spreadsheet (streamed: content.xml can be big). */
    private function bodyCounts(ZipArchive $z, string $ext): array {
        if ($ext !== 'odp' && $ext !== 'ods') {
            return [];
        }
        $xml = self::zipEntry($z, 'content.xml');
        $r   = new XMLReader();
        if ($xml === null || ! $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }
        $slides = 0;
        $sheets = [];
        for ($ok = $r->read(); $ok;) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->namespaceURI === self::NS_DRAW && $r->localName === 'page') {
                $slides++;
                $ok = $r->next(); // skip the slide's contents
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->namespaceURI === self::NS_TABLE && $r->localName === 'table') {
                $sheets[] = (string) $r->getAttributeNs('name', self::NS_TABLE);
                $ok = $r->next();
            } else {
                $ok = $r->read();
            }
        }
        return $ext === 'odp' ? ['slides' => $slides] : ['sheets' => $sheets];
    }

    /** Pictures stored in the file (Pictures/…), referenced by path. */
    public function asset(string $full, string $name): ?array {
        $mime = self::IMAGES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
        if (! $mime || ! preg_match('#^Pictures/[^/]+$#', $name)) {
            return null;
        }
        $z     = self::openZip($full);
        $bytes = self::zipEntry($z, $name, 20 << 20);
        $z->close();
        return $bytes === null ? null : [$mime, $bytes];
    }

    private function isSheet(string $full): bool {
        return strtolower(pathinfo($full, PATHINFO_EXTENSION)) === 'ods';
    }

    // ================= ODT =================

    private function readStyles(DOMDocument $doc): void {
        foreach ($doc->getElementsByTagNameNS(self::NS_STYLE, 'style') as $s) {
            $st = ['tags' => [], 'css' => [], 'align' => ''];
            foreach ($s->childNodes as $p) {
                if (! $p instanceof DOMElement) {
                    continue;
                }
                if ($p->localName === 'paragraph-properties') {
                    $a = $p->getAttributeNS(self::NS_FO, 'text-align');
                    $st['align'] = match ($a) { 'center' => 'center', 'end', 'right' => 'right', 'justify' => 'justify', default => '' };
                } elseif ($p->localName === 'text-properties') {
                    $fo = fn(string $n) => $p->getAttributeNS(self::NS_FO, $n);
                    if ($fo('font-weight') === 'bold' || (int) $fo('font-weight') >= 600) {
                        $st['tags'][] = 'b';
                    }
                    if ($fo('font-style') === 'italic') {
                        $st['tags'][] = 'i';
                    }
                    if (($u = $p->getAttributeNS(self::NS_STYLE, 'text-underline-style')) !== '' && $u !== 'none') {
                        $st['tags'][] = 'u';
                    }
                    if (($l = $p->getAttributeNS(self::NS_STYLE, 'text-line-through-style')) !== '' && $l !== 'none') {
                        $st['tags'][] = 's';
                    }
                    $pos = $p->getAttributeNS(self::NS_STYLE, 'text-position');
                    if (str_starts_with($pos, 'super') || preg_match('/^[1-9]/', $pos)) {
                        $st['tags'][] = 'sup';
                    } elseif (str_starts_with($pos, 'sub') || str_starts_with($pos, '-')) {
                        $st['tags'][] = 'sub';
                    }
                    if (preg_match('/^#[0-9a-f]{6}$/i', $fo('color'))) {
                        $st['css'][] = 'color:' . $fo('color');
                    }
                    if (preg_match('/^#[0-9a-f]{6}$/i', $fo('background-color'))) {
                        $st['css'][] = 'background-color:' . $fo('background-color');
                    }
                    if (preg_match('/^[\d.]+pt$/', $fo('font-size'))) {
                        $st['css'][] = 'font-size:' . $fo('font-size');
                    }
                }
            }
            // paragraphs styled "Title" / "Heading N" (directly or through their parent style) render as headings
            $name = $s->getAttributeNS(self::NS_STYLE, 'name');
            $base = $s->getAttributeNS(self::NS_STYLE, 'parent-style-name');
            $st['heading'] = self::headingLevel($name) ?: self::headingLevel($base) ?: ($this->styles[$base]['heading'] ?? 0);
            $this->styles[$name] = $st;
        }
        foreach ($doc->getElementsByTagNameNS(self::NS_TEXT, 'list-style') as $ls) {
            $levels = [];
            foreach ($ls->childNodes as $lvl) {
                if ($lvl instanceof DOMElement) {
                    $levels[(int) $lvl->getAttributeNS(self::NS_TEXT, 'level')] = $lvl->localName === 'list-level-style-number' ? 'ol' : 'ul';
                }
            }
            $this->listStyles[$ls->getAttributeNS(self::NS_STYLE, 'name')] = $levels;
        }
    }

    private function blocks(DOMElement $parent): string {
        $html = '';
        foreach ($parent->childNodes as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }
            $html .= match ($el->localName) {
                'p'                                          => $this->para($el, 'p'),
                'h'                                          => $this->para($el, 'h' . max(1, min(3, (int) ($el->getAttributeNS(self::NS_TEXT, 'outline-level') ?: 1)))),
                'list'                                       => $this->listHtml($el, $el->getAttributeNS(self::NS_TEXT, 'style-name'), 1),
                'table'                                      => $this->table($el),
                'section', 'index-body', 'table-of-content',
                'alphabetical-index', 'illustration-index'   => $this->blocks($el),
                default                                      => '',
            };
        }
        return $html;
    }

    private static function headingLevel(string $style): int {
        if (preg_match('/^Heading(?:_20_|\s)?([1-9])$/i', $style, $m)) {
            return min(3, (int) $m[1]);
        }
        return strcasecmp($style, 'Title') === 0 ? 1 : (strcasecmp($style, 'Subtitle') === 0 ? 2 : 0);
    }

    private function para(DOMElement $p, string $tag): string {
        $st    = $this->styles[$p->getAttributeNS(self::NS_TEXT, 'style-name')] ?? null;
        if ($tag === 'p' && ($st['heading'] ?? 0)) {
            $tag = 'h' . $st['heading'];
        }
        $inner = $this->wrap($this->runs($p), $st);
        $align = $st['align'] ?? '';
        return "<$tag" . ($align ? " style=\"text-align:$align\"" : '') . '>' . ($inner === '' ? '<br>' : $inner) . "</$tag>";
    }

    private function listHtml(DOMElement $list, string $style, int $level): string {
        $style = $list->getAttributeNS(self::NS_TEXT, 'style-name') ?: $style;
        $type  = $this->listStyles[$style][$level] ?? 'ul';
        $items = '';
        foreach ($list->childNodes as $li) {
            if (! $li instanceof DOMElement || ! in_array($li->localName, ['list-item', 'list-header'], true)) {
                continue;
            }
            $inner = '';
            foreach ($li->childNodes as $c) {
                if ($c instanceof DOMElement) {
                    $inner .= $c->localName === 'list' ? $this->listHtml($c, $style, $level + 1) : $this->blocks($this->wrapNode($c));
                }
            }
            $items .= "<li>$inner</li>";
        }
        return "<$type>$items</$type>";
    }

    /** blocks() looks at children; this lets it handle a single element. */
    private function wrapNode(DOMElement $el): DOMElement {
        $holder = $el->ownerDocument->createElement('holder');
        $holder->appendChild($el->cloneNode(true));
        return $holder;
    }

    private function table(DOMElement $t): string {
        $rows = '';
        foreach ($t->getElementsByTagNameNS(self::NS_TABLE, 'table-row') as $tr) {
            if ($tr->parentNode !== $t && $tr->parentNode->parentNode !== $t) { // skip rows of nested tables
                continue;
            }
            $rows .= '<tr>';
            foreach ($tr->childNodes as $tc) {
                if ($tc instanceof DOMElement && $tc->localName === 'table-cell') {
                    $span  = (int) $tc->getAttributeNS(self::NS_TABLE, 'number-columns-spanned');
                    $rep   = max(1, min(50, (int) $tc->getAttributeNS(self::NS_TABLE, 'number-columns-repeated')));
                    $rows .= str_repeat('<td' . ($span > 1 ? " colspan=\"$span\"" : '') . '>' . $this->blocks($tc) . '</td>', $rep);
                }
            }
            $rows .= '</tr>';
        }
        return "<table>$rows</table>";
    }

    private function runs(DOMNode $parent): string {
        $html = '';
        foreach ($parent->childNodes as $n) {
            if ($n instanceof DOMText) {
                $html .= self::h(preg_replace('/\s+/', ' ', $n->data));
                continue;
            }
            if (! $n instanceof DOMElement) {
                continue;
            }
            $html .= match ($n->localName) {
                'span'       => $this->wrap($this->runs($n), $this->styles[$n->getAttributeNS(self::NS_TEXT, 'style-name')] ?? null),
                'a'          => preg_match('#^(https?:|mailto:)#i', $href = $n->getAttributeNS(self::NS_XLINK, 'href'))
                                    ? '<a href="' . self::h($href) . '">' . $this->runs($n) . '</a>' : $this->runs($n),
                's'          => str_repeat("\u{a0}", max(1, min(100, (int) $n->getAttributeNS(self::NS_TEXT, 'c')))),
                'tab'        => "<span style=\"white-space:pre\">\t</span>",
                'line-break' => '<br>',
                'frame'      => $this->frame($n),
                'note', 'annotation', 'bookmark', 'bookmark-start', 'bookmark-end', 'soft-page-break', 'sequence-decls' => '',
                default      => $this->runs($n),
            };
        }
        return $html;
    }

    private function frame(DOMElement $f): string {
        foreach ($f->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === 'image') {
                $href = $c->getAttributeNS(self::NS_XLINK, 'href');
                if ($this->asset && preg_match('#^Pictures/[^/]+$#', $href) && isset(self::IMAGES[strtolower(pathinfo($href, PATHINFO_EXTENSION))])) {
                    $w = self::px($f->getAttributeNS(self::NS_SVG, 'width'));
                    return '<img src="' . self::h(($this->asset)($href)) . '" alt=""' . ($w ? " style=\"width:{$w}px\"" : '') . '>';
                }
                return '<span class="ph">picture</span>';
            }
            if ($c instanceof DOMElement && $c->localName === 'text-box') { // keep the text, inline
                $b = $this->blocks($c);
                return $b === '' ? '' : strip_tags(str_replace('</p>', '<br>', $b), '<b><i><u><s><sup><sub><br><span><a>');
            }
        }
        return '';
    }

    private static function px(string $len): int {
        if (! preg_match('/^([\d.]+)(cm|mm|in|pt|px)$/', $len, $m)) {
            return 0;
        }
        return (int) round((float) $m[1] * ['cm' => 37.8, 'mm' => 3.78, 'in' => 96, 'pt' => 1.333, 'px' => 1][$m[2]]);
    }

    private function wrap(string $html, ?array $st): string {
        if ($html === '' || ! $st) {
            return $html;
        }
        if ($st['css']) {
            $html = '<span style="' . implode(';', $st['css']) . "\">$html</span>";
        }
        foreach ($st['tags'] as $t) {
            $html = "<$t>$html</$t>";
        }
        return $html;
    }

    // ================= ODP =================

    private function slides(DOMElement $show): string {
        $html = '';
        $n    = 0;
        foreach ($show->childNodes as $page) {
            if ($page instanceof DOMElement && $page->localName === 'page') {
                $inner = $this->shapes($page);
                $html .= '<div class="slide"><div class="slide-no">Slide ' . ++$n . '</div>'
                       . ($inner === '' ? '<p class="slide-empty">Empty slide</p>' : $inner) . '</div>';
            }
        }
        return $html;
    }

    /** A slide's (or group's) shapes in document order; speaker notes and shapes without text are skipped. */
    private function shapes(DOMElement $parent): string {
        $html = '';
        foreach ($parent->childNodes as $el) {
            if (! $el instanceof DOMElement || $el->namespaceURI !== self::NS_DRAW) {
                continue;
            }
            if ($el->localName === 'g') {
                $html .= $this->shapes($el);
            } elseif ($el->localName === 'frame') {
                foreach ($el->childNodes as $c) { // a frame shows one thing (an image may be followed by fallbacks)
                    $part = ! $c instanceof DOMElement ? null : match ($c->localName) {
                        'image'    => '<p>' . $this->frame($el) . '</p>',
                        'text-box' => $this->blocks($c),
                        'table'    => $this->table($c),
                        default    => null,
                    };
                    if ($part !== null) {
                        $html .= $part;
                        break;
                    }
                }
            } else { // rect, ellipse, custom-shape, … : only their text
                $html .= $this->blocks($el);
            }
        }
        return $html;
    }

    // ================= ODS =================

    /** Streams content.xml: cells are taken as displayed (their text), repeated rows/columns expanded within the limits. */
    private function sheets(string $full): array {
        $z   = self::openZip($full);
        $xml = self::zipEntry($z, 'content.xml') ?? throw new Fail('This is not an OpenDocument file', 422);
        $z->close();

        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $sheets = [];
        $cur    = null;
        $row    = 0;
        $finish = function () use (&$cur, &$sheets) {
            if ($cur) {
                $sheets[] = ['name' => $cur['name'], 'rows' => SheetUtil::dense($cur['cells']), 'truncated' => $cur['truncated']];
            }
            $cur = null;
        };
        for ($ok = $r->read(); $ok;) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->namespaceURI !== self::NS_TABLE) {
                $ok = $r->read();
                continue;
            }
            if ($r->localName === 'table') {
                $finish();
                $cur = ['name' => (string) $r->getAttributeNs('name', self::NS_TABLE), 'cells' => [], 'truncated' => false];
                $row = 0;
                $ok  = $r->read();
            } elseif ($r->localName === 'table-row' && $cur) {
                $repeat = max(1, (int) $r->getAttributeNs('number-rows-repeated', self::NS_TABLE));
                if ($row < SheetUtil::MAX_ROWS) {
                    $cells = $this->rowCells($r->expand());
                    if ($cells) {
                        for ($k = 0; $k < $repeat && $row + $k < SheetUtil::MAX_ROWS; $k++) {
                            $cur['cells'][$row + $k] = $cells;
                        }
                        if ($row + $repeat > SheetUtil::MAX_ROWS) {
                            $cur['truncated'] = true;
                        }
                    }
                } elseif ($r->expand()->textContent !== '') {
                    $cur['truncated'] = true;
                }
                $row += $repeat;
                $ok   = $r->next();
            } else {
                $ok = $r->read();
            }
        }
        $finish();
        return $sheets;
    }

    /** col => display text of one row's non-empty cells */
    private function rowCells(DOMNode $tr): array {
        $out = [];
        $col = 0;
        foreach ($tr->childNodes as $tc) {
            if (! $tc instanceof DOMElement || ! in_array($tc->localName, ['table-cell', 'covered-table-cell'], true)) {
                continue;
            }
            $repeat = max(1, (int) $tc->getAttributeNS(self::NS_TABLE, 'number-columns-repeated'));
            $lines  = [];
            foreach ($tc->childNodes as $p) {
                if ($p instanceof DOMElement && $p->localName === 'p') {
                    $lines[] = $p->textContent;
                }
            }
            $v = $lines ? implode("\n", $lines) : $tc->getAttributeNS(self::NS_OFFICE, 'value');
            if ($v !== '' && $tc->localName === 'table-cell') {
                for ($k = 0; $k < $repeat && $col + $k < SheetUtil::MAX_COLS; $k++) {
                    $out[$col + $k] = $v;
                }
            }
            $col += $repeat;
            if ($col >= SheetUtil::MAX_COLS) {
                break;
            }
        }
        return $out;
    }

    private function xml(ZipArchive $z, string $name): ?DOMDocument {
        $s = self::zipEntry($z, $name);
        if ($s === null) {
            return null;
        }
        $d = new DOMDocument();
        return $d->loadXML($s, LIBXML_NONET | LIBXML_COMPACT) ? $d : null;
    }
};
