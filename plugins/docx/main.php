<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// Word 2007+ (.docx): read-only. Paragraphs, headings, basic character formatting, lists, tables, links and pictures.
// Deliberately simple: no page layout, styles beyond headings, text boxes, headers/footers or footnotes.
require_once __DIR__ . '/../lib/ooxml.php';

return new class extends Plugin {
    public array  $exts  = ['docx', 'docm'];
    public string $label = 'Word document';

    const R      = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const IMAGES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
                    'bmp' => 'image/bmp', 'webp' => 'image/webp'];
    const HIGHLIGHT = ['yellow' => '#ffff00', 'green' => '#00ff00', 'cyan' => '#00ffff', 'magenta' => '#ff00ff', 'blue' => '#0000ff',
                       'red' => '#ff0000', 'darkBlue' => '#000080', 'darkCyan' => '#008080', 'darkGreen' => '#008000',
                       'darkMagenta' => '#800080', 'darkRed' => '#800000', 'darkYellow' => '#808000', 'darkGray' => '#808080',
                       'lightGray' => '#c0c0c0', 'black' => '#000000', 'white' => '#ffffff'];

    private array    $rels     = [];
    private array    $headings = [];
    private array    $numFmt   = [];
    private ?Closure $asset    = null;

    public function view(string $full, array $ctx): array {
        $z   = self::openZip($full);
        $doc = $this->xml($z, 'word/document.xml') ?? throw new Fail('This is not a Word document', 422);
        $this->rels     = $this->relationships($z);
        $this->headings = $this->headingStyles($z);
        $this->numFmt   = $this->numbering($z);
        $this->asset    = $ctx['asset'] ?? null;
        $body = $doc->getElementsByTagNameNS('*', 'body')->item(0);
        $z->close();
        return ['html'   => $body ? $this->blocks($body) : '',
                'notice' => 'Simplified preview – some formatting and layout may be missing. Download the file to see it exactly.'];
    }

    public function text(string $full): ?string {
        $z   = self::openZip($full);
        $xml = self::zipEntry($z, 'word/document.xml');
        $z->close();
        if ($xml === null) {
            return null;
        }
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $out = '';
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 't') {
                $out .= $r->readString();
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'tab') {
                $out .= "\t";
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'p') {
                $out .= "\n";
            }
        }
        return trim($out);
    }

    /** The preview Office saves when "Save thumbnail" is on (docProps/thumbnail.jpeg); none otherwise. */
    public function thumbnail(string $full, int $size): ?GdImage {
        $z = self::openZip($full);
        try {
            return self::thumbPicture(Ooxml::thumbnail($z), $size);
        } finally {
            $z->close();
        }
    }

    /** Document properties (docProps/). */
    public function info(string $full, array $ctx): array {
        $z = self::openZip($full);
        try {
            return Ooxml::info($z);
        } finally {
            $z->close();
        }
    }

    /** Pictures, by relationship id: only image parts listed in the document's relationships are served. */
    public function asset(string $full, string $name): ?array {
        $z   = self::openZip($full);
        $rel = $this->relationships($z)[$name] ?? null;
        $out = null;
        if ($rel && ! $rel['external'] && str_ends_with($rel['type'], '/image') && ($mime = self::IMAGES[strtolower(pathinfo($rel['target'], PATHINFO_EXTENSION))] ?? null)) {
            $bytes = self::zipEntry($z, $rel['target'], 20 << 20);
            $out   = $bytes === null ? null : [$mime, $bytes];
        }
        $z->close();
        return $out;
    }

    // ---------- body ----------

    /** Block content (body, table cell, sdtContent): paragraphs grouped into lists, and tables. */
    private function blocks(DOMElement $parent): string {
        $html = '';
        $list = null;
        foreach ($this->children($parent) as $el) {
            $name = $el->localName;
            if ($name === 'sdt') { // content control: use what's inside
                $content = $this->child($el, 'sdtContent');
                $html   .= ($list ? "</$list>" : '') . ($content ? $this->blocks($content) : '');
                $list    = null;
                continue;
            }
            [$type, $h] = match ($name) {
                'p'     => $this->paragraph($el),
                'tbl'   => [null, $this->table($el)],
                default => [null, ''],
            };
            if ($type !== $list) {
                $html .= ($list ? "</$list>" : '') . ($type ? "<$type>" : '');
                $list  = $type;
            }
            $html .= $h;
        }
        return $html . ($list ? "</$list>" : '');
    }

    /** [list type or null, html] */
    private function paragraph(DOMElement $p): array {
        $pPr   = $this->child($p, 'pPr');
        $style = $pPr ? $this->val($this->child($pPr, 'pStyle')) : null;
        $align = $pPr ? $this->val($this->child($pPr, 'jc')) : null;
        $css   = match ($align) { 'center' => 'text-align:center', 'right', 'end' => 'text-align:right', 'both', 'distribute' => 'text-align:justify', default => '' };
        $num   = $pPr ? $this->child($pPr, 'numPr') : null;
        $inner = $this->runs($p);

        $level = $this->headings[$style] ?? 0;
        if ($level) {
            return [null, "<h$level" . ($css ? " style=\"$css\"" : '') . ">$inner</h$level>"];
        }
        if ($num) {
            $id   = $this->val($this->child($num, 'numId'));
            $lvl  = (int) $this->val($this->child($num, 'ilvl'));
            $fmt  = $this->numFmt[$id][$lvl] ?? 'bullet';
            $css .= ($css ? ';' : '') . ($lvl ? 'margin-left:' . (24 * $lvl) . 'px' : '');
            if ($id !== '0') {
                return [$fmt === 'bullet' || $fmt === 'none' ? 'ul' : 'ol', '<li' . ($css ? " style=\"$css\"" : '') . ">$inner</li>"];
            }
        }
        return [null, '<p' . ($css ? " style=\"$css\"" : '') . '>' . ($inner === '' ? '<br>' : $inner) . '</p>'];
    }

    /** Inline content of a paragraph (also of hyperlinks, insertions, smart tags, fields…). */
    private function runs(DOMElement $parent): string {
        $html = '';
        foreach ($this->children($parent) as $el) {
            switch ($el->localName) {
                case 'r':
                    $html .= $this->run($el);
                    break;
                case 'hyperlink':
                    $inner = $this->runs($el);
                    $rel   = $this->rels[$el->getAttributeNS(self::R, 'id')] ?? null;
                    $url   = $rel['target'] ?? '';
                    $html .= preg_match('#^(https?:|mailto:)#i', $url) ? '<a href="' . self::h($url) . "\">$inner</a>" : $inner;
                    break;
                case 'ins':
                case 'smartTag':
                case 'fldSimple':
                case 'customXml':
                case 'bdo':
                case 'dir':
                    $html .= $this->runs($el);
                    break;
                case 'sdt':
                    $c     = $this->child($el, 'sdtContent');
                    $html .= $c ? $this->runs($c) : '';
                    break;
            }
        }
        return $html;
    }

    private function run(DOMElement $r): string {
        $html = '';
        foreach ($this->children($r) as $el) {
            $html .= match ($el->localName) {
                't'              => self::h($el->textContent),
                'tab'            => "<span style=\"white-space:pre\">\t</span>",
                'br', 'cr'       => $el->getAttributeNS($el->namespaceURI, 'type') === 'page' ? '<hr>' : '<br>',
                'noBreakHyphen'  => "\u{2011}",
                'drawing', 'pict', 'object' => $this->picture($el),
                default          => '', // instrText (field codes), deleted text, footnote references, …
            };
        }
        if ($html === '') {
            return '';
        }

        $pr = $this->child($r, 'rPr');
        if (! $pr) {
            return $html;
        }
        $on  = fn(string $n) => ($e = $this->child($pr, $n)) && ! in_array($this->val($e), ['0', 'false', 'none'], true);
        $css = [];
        if (($c = $this->val($this->child($pr, 'color'))) && preg_match('/^[0-9a-f]{6}$/i', $c)) {
            $css[] = "color:#$c";
        }
        if ($sz = (int) $this->val($this->child($pr, 'sz'))) {
            $css[] = 'font-size:' . ($sz / 2) . 'pt';
        }
        if ($hl = self::HIGHLIGHT[$this->val($this->child($pr, 'highlight'))] ?? null) {
            $css[] = "background-color:$hl";
        }
        if ($css) {
            $html = '<span style="' . implode(';', $css) . "\">$html</span>";
        }
        foreach (['b' => 'b', 'i' => 'i', 'u' => 'u', 'strike' => 's', 'dstrike' => 's'] as $n => $tag) {
            if ($on($n)) {
                $html = "<$tag>$html</$tag>";
            }
        }
        $va = $this->val($this->child($pr, 'vertAlign'));
        return $va === 'superscript' ? "<sup>$html</sup>" : ($va === 'subscript' ? "<sub>$html</sub>" : $html);
    }

    /** <img> for the first picture inside a drawing / VML shape; a placeholder when it can't be shown. */
    private function picture(DOMElement $el): string {
        $id = '';
        foreach ($el->getElementsByTagNameNS('*', 'blip') as $b) {
            $id = $b->getAttributeNS(self::R, 'embed');
            break;
        }
        if ($id === '') {
            foreach ($el->getElementsByTagNameNS('*', 'imagedata') as $b) {
                $id = $b->getAttributeNS(self::R, 'id');
                break;
            }
        }
        $rel = $this->rels[$id] ?? null;
        if (! $rel || $rel['external'] || ! $this->asset || ! isset(self::IMAGES[strtolower(pathinfo($rel['target'], PATHINFO_EXTENSION))])) {
            return '<span class="ph">picture</span>';
        }
        $style = '';
        foreach ($el->getElementsByTagNameNS('*', 'extent') as $ext) { // EMU: 9525 per CSS pixel
            $style = ' style="width:' . round((int) $ext->getAttribute('cx') / 9525) . 'px"';
            break;
        }
        return '<img src="' . self::h(($this->asset)($id)) . "\" alt=\"\"$style>";
    }

    private function table(DOMElement $tbl): string {
        $html = '';
        foreach ($this->children($tbl) as $tr) {
            if ($tr->localName !== 'tr') {
                continue;
            }
            $html .= '<tr>';
            foreach ($this->children($tr) as $tc) {
                if ($tc->localName !== 'tc') {
                    continue;
                }
                $pr   = $this->child($tc, 'tcPr');
                $span = $pr ? (int) $this->val($this->child($pr, 'gridSpan')) : 0;
                $html .= '<td' . ($span > 1 ? " colspan=\"$span\"" : '') . '>' . $this->blocks($tc) . '</td>';
            }
            $html .= '</tr>';
        }
        return "<table>$html</table>";
    }

    // ---------- parts ----------

    /** rId => ['type' => …, 'target' => zip path or external URL] */
    private function relationships(ZipArchive $z): array {
        $out = [];
        foreach ($this->xml($z, 'word/_rels/document.xml.rels')?->getElementsByTagName('Relationship') ?? [] as $r) {
            $t = $r->getAttribute('Target');
            $ext = $r->getAttribute('TargetMode') === 'External';
            $out[$r->getAttribute('Id')] = [
                'type'     => $r->getAttribute('Type'),
                'target'   => $ext ? $t : $this->path($t),
                'external' => $ext, // never fetched: pictures are only served from inside the file
            ];
        }
        return $out;
    }

    /** styleId => heading level (Title → 1, Heading N → N, capped at 3). */
    private function headingStyles(ZipArchive $z): array {
        $out = [];
        foreach ($this->xml($z, 'word/styles.xml')?->getElementsByTagNameNS('*', 'style') ?? [] as $s) {
            $name = strtolower((string) $this->val($this->child($s, 'name')));
            $id   = $s->getAttributeNS($s->namespaceURI, 'styleId');
            if ($name === 'title') {
                $out[$id] = 1;
            } elseif ($name === 'subtitle') {
                $out[$id] = 2;
            } elseif (preg_match('/^heading ([1-9])$/', $name, $m)) {
                $out[$id] = min(3, (int) $m[1]);
            }
        }
        return $out;
    }

    /** numId => [level => numFmt] */
    private function numbering(ZipArchive $z): array {
        $doc = $this->xml($z, 'word/numbering.xml');
        if (! $doc) {
            return [];
        }
        $abstract = [];
        foreach ($doc->getElementsByTagNameNS('*', 'abstractNum') as $a) {
            foreach ($this->children($a) as $lvl) {
                if ($lvl->localName === 'lvl') {
                    $abstract[$a->getAttributeNS($a->namespaceURI, 'abstractNumId')][(int) $lvl->getAttributeNS($lvl->namespaceURI, 'ilvl')]
                        = $this->val($this->child($lvl, 'numFmt')) ?? 'bullet';
                }
            }
        }
        $out = [];
        foreach ($doc->getElementsByTagNameNS('*', 'num') as $n) {
            $out[$n->getAttributeNS($n->namespaceURI, 'numId')] = $abstract[$this->val($this->child($n, 'abstractNumId'))] ?? [];
        }
        return $out;
    }

    // ---------- helpers ----------

    private function xml(ZipArchive $z, string $name): ?DOMDocument {
        $s = self::zipEntry($z, $name);
        if ($s === null) {
            return null;
        }
        $d = new DOMDocument();
        return $d->loadXML($s, LIBXML_NONET | LIBXML_COMPACT) ? $d : null;
    }

    /** "media/image1.png" (relative to word/) or "/word/media/image1.png" → zip path */
    private function path(string $target): string {
        $parts = str_starts_with($target, '/') ? [] : ['word'];
        foreach (explode('/', ltrim($target, '/')) as $p) {
            if ($p === '..') {
                array_pop($parts);
            } elseif ($p !== '' && $p !== '.') {
                $parts[] = $p;
            }
        }
        return implode('/', $parts);
    }

    /** @return DOMElement[] */
    private function children(DOMNode $n): array {
        $out = [];
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private function child(DOMNode $n, string $local): ?DOMElement {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $local) {
                return $c;
            }
        }
        return null;
    }

    /** w:val of an element (any namespace prefix). */
    private function val(?DOMElement $e): ?string {
        if (! $e) {
            return null;
        }
        $v = $e->getAttributeNS($e->namespaceURI, 'val');
        return $v !== '' ? $v : null;
    }
};
