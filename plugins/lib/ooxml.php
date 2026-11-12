<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
/**
 * Office Open XML packages (.docx, .xlsx, .pptx): the document properties (docProps/core.xml, docProps/app.xml) for
 * the info panel, and the thumbnail Office saves when "Save thumbnail" / "Save preview picture" is on.
 */
final class Ooxml {
    const REL_CORE  = '/metadata/core-properties';
    const REL_APP   = '/extended-properties';
    const REL_THUMB = '/metadata/thumbnail';

    /** Sections for Plugin::info(). */
    public static function info(ZipArchive $z): array {
        $date = [Plugin::class, 'infoDate'];
        $core = self::props($z, self::part($z, self::REL_CORE) ?? 'docProps/core.xml');
        $app  = self::props($z, self::part($z, self::REL_APP) ?? 'docProps/app.xml');
        $num  = fn(string $k) => isset($app[$k]) && is_numeric($app[$k]) && (int) $app[$k] > 0 ? (int) $app[$k] : null;
        $ver  = $app['AppVersion'] ?? '';
        return [
            'Document'   => ['Title'       => $core['title'] ?? null,
                             'Subject'     => $core['subject'] ?? null,
                             'Author'      => $core['creator'] ?? null,
                             'Keywords'    => $core['keywords'] ?? null,
                             'Description' => $core['description'] ?? null,
                             'Category'    => $core['category'] ?? null,
                             'Status'      => $core['contentStatus'] ?? null,
                             'Company'     => $app['Company'] ?? null,
                             'Manager'     => $app['Manager'] ?? null,
                             'Template'    => $app['Template'] ?? null],
            'History'    => ['Created'          => $date($core['created'] ?? null),
                             'Modified'         => $date($core['modified'] ?? null),
                             'Last modified by' => $core['lastModifiedBy'] ?? null,
                             'Last printed'     => $date($core['lastPrinted'] ?? null),
                             'Revision'         => ($core['revision'] ?? '0') !== '0' ? $core['revision'] : null,
                             'Editing time'     => $num('TotalTime') ? Plugin::infoDuration(60 * $num('TotalTime')) : null,
                             'Application'      => trim(($app['Application'] ?? '') . ($ver !== '' ? " $ver" : '')) ?: null],
            'Statistics' => ['Pages'      => $num('Pages'),
                             'Slides'     => $num('Slides'),
                             'Words'      => $num('Words'),
                             'Characters' => $num('CharactersWithSpaces') ?? $num('Characters'),
                             'Lines'      => $num('Lines'),
                             'Paragraphs' => $num('Paragraphs')],
        ];
    }

    /** Bytes of the package thumbnail if GD can read it (JPEG / PNG / GIF; Office also writes WMF / EMF), or null. */
    public static function thumbnail(ZipArchive $z): ?string {
        $name = self::part($z, self::REL_THUMB) ?? 'docProps/thumbnail.jpeg';
        if (! preg_match('/\.(jpe?g|png|gif)$/i', $name) || ! ($st = $z->statName($name)) || $st['size'] > 8 << 20) {
            return null;
        }
        $bytes = $z->getFromName($name);
        return $bytes === false ? null : $bytes;
    }

    /** The package part a root relationship of type …$type points at (without the leading /), or null. */
    private static function part(ZipArchive $z, string $type): ?string {
        $st = $z->statName('_rels/.rels');
        if (! $st || $st['size'] > 1 << 20) {
            return null;
        }
        preg_match_all('/<Relationship\b[^>]*>/i', (string) $z->getFromName('_rels/.rels'), $m);
        foreach ($m[0] as $tag) {
            if (preg_match('/\bType\s*=\s*"([^"]*)"/', $tag, $t) && str_ends_with($t[1], $type)
                && preg_match('/\bTarget\s*=\s*"\/?([^"]+)"/', $tag, $g) && ! str_contains($g[1], '..')) {
                return $g[1];
            }
        }
        return null;
    }

    /** Local name => text of the root element's children (core.xml / app.xml are flat). */
    private static function props(ZipArchive $z, string $name): array {
        $st = $z->statName($name);
        if (! $st || $st['size'] > 4 << 20) {
            return [];
        }
        $d = new DOMDocument();
        if (! $d->loadXML((string) $z->getFromName($name), LIBXML_NONET | LIBXML_COMPACT) || ! $d->documentElement) {
            return [];
        }
        $out = [];
        foreach ($d->documentElement->childNodes as $n) {
            if ($n instanceof DOMElement && ! isset($out[$n->localName]) && ($v = trim($n->textContent)) !== '') {
                $out[$n->localName] = $v;
            }
        }
        return $out;
    }
}
