<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 06-Nov-2026
 */
// Raw file access: ?path=&owner=  [&inline=1 | &thumb=1&size=200 | &asset=<name> | &trash=1]   or   ?zip=1&owner=&items[]=…
require __DIR__ . '/lib.php';

try {
    $r     = $_REQUEST;
    $owner = (string) ($r['owner'] ?? '');

    if (isset($r['zip'])) {
        $items   = array_map('cleanPath', (array) ($r['items'] ?? []));
        $entries = [];
        foreach ($items as $p) {
            zipEntries(resolve($owner, $p), $p === '' ? 'files' : basename($p), $entries);
        }

        if (! $entries) {
            throw new Fail('Nothing to download', 400);
        }

        sendZip(count($items) === 1 ? basename($items[0] ?: 'files') . '.zip' : 'download_' . date('YmdHis') . '.zip', $entries);
    }

    [$full, $owner, $key] = fileTarget($r);

    if (isset($r['asset'])) { // a picture embedded in a document, extracted by the file type's plugin
        $p = is_file($full) ? pluginFor($full) : null;
        $a = $p && filesize($full) <= $p->maxSize ? pluginCall(fn() => $p->asset($full, (string) $r['asset'])) : null;
        if (! $a || ! in_array($a[0], ['image/png', 'image/jpeg', 'image/gif', 'image/bmp', 'image/webp'], true)) {
            throw new Fail('Not found', 404);
        }

        header("Content-Type: $a[0]");
        header('Content-Security-Policy: sandbox');
        header('Cache-Control: private, max-age=31536000'); // URLs carry the file's mtime
        header('Content-Length: ' . strlen($a[1]));
        echo $a[1];
        exit;
    }

    if (isset($r['thumb'])) {
        $thumb = is_file($full) ? thumbnail($owner, $key, $full, max(50, min(500, (int) ($r['size'] ?? 200)))) : null;
        if (! $thumb) {
            throw new Fail('No thumbnail', 404);
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=31536000'); // URLs carry the file's mtime, so this never goes stale
        readfile($thumb);
        exit;
    }

    if (is_dir($full)) {
        $entries = [];
        zipEntries($full, basename($full) ?: 'files', $entries);
        sendZip((basename($full) ?: 'files') . '.zip', $entries);
    }
    sendFile($full, isset($r['inline']));
} catch (Fail $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
