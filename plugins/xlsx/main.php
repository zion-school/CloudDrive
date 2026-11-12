<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
// Excel 2007+ (.xlsx / .xlsm): read-only, cell values only. Deliberately simple – see SheetUtil::NOTICE.
require_once __DIR__ . '/../lib/sheet.php';
require_once __DIR__ . '/../lib/ooxml.php';

return new class extends Plugin {
    public array  $exts  = ['xlsx', 'xlsm'];
    public string $label = 'Excel workbook';

    public function view(string $full, array $ctx): array {
        return ['sheets' => $this->sheets($full), 'notice' => SheetUtil::NOTICE];
    }

    public function text(string $full): ?string {
        return SheetUtil::text($this->sheets($full));
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

    /** Document properties (docProps/) and the sheet names. */
    public function info(string $full, array $ctx): array {
        $z = self::openZip($full);
        try {
            $out    = Ooxml::info($z);
            $names  = [];
            $hidden = 0;
            foreach ($this->xml($z, 'xl/workbook.xml')?->getElementsByTagName('sheet') ?? [] as $s) {
                in_array($s->getAttribute('state'), ['hidden', 'veryHidden'], true) ? $hidden++ : $names[] = $s->getAttribute('name');
            }
            $out['Workbook'] = ['Sheets' => count($names), 'Hidden sheets' => $hidden ?: null, 'Names' => implode("\n", array_slice($names, 0, 100))];
            return $out;
        } finally {
            $z->close();
        }
    }

    private function sheets(string $full): array {
        $z  = self::openZip($full);
        $wb = $this->xml($z, 'xl/workbook.xml') ?? throw new Fail('This is not an Excel workbook', 422);

        $rels = [];
        foreach ($this->xml($z, 'xl/_rels/workbook.xml.rels')?->getElementsByTagName('Relationship') ?? [] as $r) {
            $rels[$r->getAttribute('Id')] = $this->path('xl', $r->getAttribute('Target'));
        }
        $pr       = $wb->getElementsByTagName('workbookPr')->item(0);
        $date1904 = $pr && in_array(strtolower($pr->getAttribute('date1904')), ['1', 'true'], true);
        $strings  = $this->sharedStrings($z);
        $dates    = $this->dateStyles($z);

        $out = [];
        foreach ($wb->getElementsByTagName('sheet') as $s) {
            $target = $rels[$s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? null;
            if ($s->getAttribute('state') === 'hidden' || $s->getAttribute('state') === 'veryHidden' || ! $target) {
                continue;
            }
            $xml = self::zipEntry($z, $target);
            if ($xml !== null) {
                $out[] = ['name' => $s->getAttribute('name')] + $this->sheet($xml, $strings, $dates, $date1904);
            }
        }
        $z->close();
        return $out;
    }

    /** Streams one worksheet; stops at the row/column limits. */
    private function sheet(string $xml, array $strings, array $dates, bool $date1904): array {
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $cells     = [];
        $truncated = false;
        $row       = -1;
        $col       = -1;
        for ($ok = $r->read(); $ok; $ok = $skipped ? $ok : $r->read()) {
            $skipped = false;
            if ($r->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'row') {
                $row = $r->getAttribute('r') ? (int) $r->getAttribute('r') - 1 : $row + 1;
                $col = -1;
                if ($row >= SheetUtil::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
            } elseif ($r->localName === 'c') {
                $ref = (string) $r->getAttribute('r');
                $col = $ref !== '' ? $this->colIndex($ref) : $col + 1;
                if ($col >= SheetUtil::MAX_COLS) {
                    $truncated = true;
                } else {
                    $type  = (string) $r->getAttribute('t');
                    $style = (int) $r->getAttribute('s');
                    $v     = null;
                    $t     = null;
                    foreach ($r->expand()->childNodes as $ch) {
                        if ($ch->localName === 'v') {
                            $v = $ch->textContent;
                        } elseif ($ch->localName === 'is') {
                            $t = $this->richText($ch);
                        }
                    }
                    $value = match ($type) {
                        's'         => $strings[(int) $v] ?? '',
                        'inlineStr' => $t ?? '',
                        'b'         => $v === '1' ? 'TRUE' : 'FALSE',
                        'str', 'e'  => (string) $v,
                        default     => $v === null || $v === '' ? '' : (is_numeric($v) ? SheetUtil::number((float) $v, $dates[$style] ?? false, $date1904) : $v),
                    };
                    if ($value !== '') {
                        $cells[$row][$col] = $value;
                    }
                }
                $ok      = $r->next(); // past this cell's subtree, onto the next cell: don't read() past it
                $skipped = true;
            }
        }
        return ['rows' => SheetUtil::dense($cells), 'truncated' => $truncated];
    }

    private function sharedStrings(ZipArchive $z): array {
        $xml = self::zipEntry($z, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $out = [];
        for ($ok = $r->read(); $ok;) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                $out[] = $this->richText($r->expand());
                $ok    = $r->next(); // already on the next <si>: don't read() past it
            } else {
                $ok = $r->read();
            }
        }
        return $out;
    }

    /** Text of an <si>/<is>: its <t> pieces, without phonetic (<rPh>) runs. */
    private function richText(DOMNode $n): string {
        $s = '';
        foreach ($n->childNodes as $ch) {
            if ($ch->localName === 't') {
                $s .= $ch->textContent;
            } elseif ($ch->localName === 'r') {
                $s .= $this->richText($ch);
            }
        }
        return $s;
    }

    /** cellXfs index → true when its number format is a date/time. */
    private function dateStyles(ZipArchive $z): array {
        $doc = $this->xml($z, 'xl/styles.xml');
        if (! $doc) {
            return [];
        }
        $codes = [];
        foreach ($doc->getElementsByTagName('numFmt') as $f) {
            $codes[(int) $f->getAttribute('numFmtId')] = $f->getAttribute('formatCode');
        }
        $out = [];
        $xfs = $doc->getElementsByTagName('cellXfs')->item(0);
        foreach ($xfs ? $xfs->getElementsByTagName('xf') : [] as $i => $xf) {
            $id      = (int) $xf->getAttribute('numFmtId');
            $out[$i] = SheetUtil::isDate($id, $codes[$id] ?? null);
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

    /** Resolves a relationship target ("worksheets/sheet1.xml" or "/xl/worksheets/sheet1.xml") to a zip path. */
    private function path(string $base, string $target): string {
        $parts = str_starts_with($target, '/') ? [] : explode('/', $base);
        foreach (explode('/', ltrim($target, '/')) as $p) {
            if ($p === '..') {
                array_pop($parts);
            } elseif ($p !== '' && $p !== '.') {
                $parts[] = $p;
            }
        }
        return implode('/', $parts);
    }

    private function colIndex(string $ref): int {
        $n = 0;
        for ($i = 0; $i < strlen($ref) && ctype_alpha($ref[$i]); $i++) {
            $n = $n * 26 + (ord(strtoupper($ref[$i])) - 64);
        }
        return $n - 1;
    }
};
