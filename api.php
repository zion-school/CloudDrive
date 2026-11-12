<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 06-Nov-2026
 */
require __DIR__ . '/lib.php';
ini_set('display_errors', '0'); // a PHP warning printed before the JSON makes the reply unreadable; it still goes to the error log
header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? '';
    $post   = $_SERVER['REQUEST_METHOD'] === 'POST';
    $in     = $post ? (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json') ? json_decode(file_get_contents('php://input'), true) : $_POST) : $_GET;
    $in     = is_array($in) ? $in : [];
    if (! $post && ! in_array($action, ['list', 'search', 'shares', 'view', 'info', 'entry'], true)) {
        throw new Fail('Use POST', 405);
    }

    if ($post) {
        $u = $action === 'unlock' ? me() : needUser(); // visitors of public files may unlock archives too
        if (! hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
            throw new Fail('Session expired, please reload the page', 403);
        }
    }
    $paths  = fn() => array_map('cleanPath', (array) ($in['items'] ?? []));
    $result = match ($action) {
        'list'          => listView($in),
        'search'        => search(needUser(), trim($in['q'] ?? '')),
        'shares'        => ['users' => shares(needUser())[cleanPath($in['path'] ?? '')] ?? []],
        'view'          => viewFile($in),
        'info'          => infoFile($in),
        'entry'         => ['size' => filesize(fileTarget($in + ['entry' => []])[0])], // opens a file inside an archive (extracts it once)
        'unlock'        => unlock($in),

        'mkdir'         => mkItem($u, $in, true),
        'mkfile'        => mkItem($u, $in, false),
        'save'          => save($u, cleanPath($in['path'] ?? ''), $in),
        'rename'        => renameItem($u, cleanPath($in['path'] ?? ''), cleanName($in['name'] ?? '')),
        'move'          => transfer($u, $u, $paths(), cleanPath($in['dest'] ?? ''), true),
        'copy'          => transfer($u, (string) ($in['owner'] ?? '') ?: $u, $paths(), cleanPath($in['dest'] ?? ''), false),
        'share'         => share($u, cleanPath($in['path'] ?? ''), (array) ($in['users'] ?? [])),
        'pref'          => prefAction($u, (string) ($in['key'] ?? ''), (string) ($in['value'] ?? '')),

        'trash'         => forEachItem($paths(), fn($p) => moveToTrash($u, $p), 'moved to trash'),
        'delete'        => forEachItem($paths(), fn($p) => deleteForever($u, $p), 'permanently deleted'),
        'restore'       => forEachItem((array) ($in['items'] ?? []), fn($n) => restoreFromTrash($u, $n), 'restored'),
        'purge'         => forEachItem((array) ($in['items'] ?? []), fn($n) => [rmTree(trashEntry($u, $n)), forgetTrash($u, [$n])], 'permanently deleted'),
        'empty'         => emptyTrash($u),

        'upload_init'   => uploadInit($u, $in),
        'upload_chunk'  => uploadChunk($u, (string) ($in['id'] ?? ''), (int) ($in['index'] ?? -1)),
        'upload_cancel' => uploadCancel($u, (string) ($in['id'] ?? '')),
        default         => throw new Fail('Unknown action', 400),
    };
    echo json_encode(['ok' => true] + $result);
} catch (Throwable $e) {
    if (! $e instanceof Fail) {
        error_log((string) $e);
    }

    http_response_code($e instanceof Fail && $e->getCode() >= 400 ? $e->getCode() : 500);
    echo json_encode(['error' => $e instanceof Fail ? $e->getMessage() : 'Server error'] + ($e instanceof NeedPassword ? ['password' => true] : []));
}

/** Runs $fn for every item, collecting per-item errors instead of aborting the whole batch. */
function forEachItem(array $items, callable $fn, string $verb): array {
    $done   = 0;
    $errors = [];
    foreach ($items as $it) {
        try {
            $fn($it);
            $done++;
        } catch (Fail $e) {
            $errors[] = basename((string) $it) . ': ' . $e->getMessage();
        }
    }
    if (! $done && $errors) {
        throw new Fail(implode("\n", $errors), 400);
    }
    return ['message' => "$done item(s) $verb" . ($errors ? "\n" . implode("\n", $errors) : '')];
}

function listView(array $in): array {
    $view  = $in['view'] ?? 'my';
    $owner = (string) ($in['owner'] ?? '');
    $path  = cleanPath($in['path'] ?? '');
    $me    = me();
    if ($view === 'public' && $owner === '') { // public root: one folder per user who shares something
        $owners = [];
        foreach (sharedItems('public') as $it) {
            $owners[$it['owner']] = max($owners[$it['owner']] ?? 0, $it['mtime']);
        }

        $items = [];
        foreach ($owners as $o => $t) {
            $items[] = ['name'   => $o,
                        'path'   => '',
                        'owner'  => $o,
                        'dir'    => true,
                        'size'   => 0,
                        'mtime'  => $t,
                        'person' => true];
        }
        return ['items' => $items];
    }
    if ($view === 'shared' || $view === 'public') {
        $who = $view === 'public' ? 'public' : needUser();
        if ($path === '') {
            return ['items' => sharedItems($who, $owner ?: null)];
        }

        resolve($owner, $path);
        $base = $view === 'public' ? shareBase(null, $owner, $path) : shareBase($me, $owner, $path);
        if ($base === null || ! is_dir($full = DATA_DIR . "/$owner/$path")) {
            throw new Fail('Not found', 404);
        }
        return ['items' => listDir($full, $path, $owner), 'base' => $base];
    }
    $u = needUser();
    if ($view === 'trash') {
        return ['items' => trashItems($u), 'quota' => quota($u)];
    }

    $full = resolve($u, $path);
    if (! is_dir($full)) {
        throw new Fail('Not a folder', 400);
    }

    $shared = shares($u);
    $items  = listDir($full, $path, $u);
    foreach ($items as &$it) {
        $it['shared'] = isset($shared[$it['path']]);
    }
    return ['items' => $items, 'quota' => quota($u)];
}

function search(string $u, string $q): array {
    if ($q === '') {
        return ['items' => []];
    }

    $root  = userDir($u);
    $items = [];
    $walk  = function (string $dir, string $rel) use (&$walk, &$items, $q, $u) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || in_array($f, RESERVED, true) || count($items) >= 500) {
                continue;
            }

            $full = "$dir/$f";
            $path = join_($rel, $f);
            $hit  = mb_stripos($f, $q) !== false
                || (is_file($full) && filesize($full) < 1048576 && isText($f) && mb_stripos(file_get_contents($full), $q) !== false)
                || (is_file($full) && ! isText($f) && mb_stripos(pluginText($u, $full) ?? '', $q) !== false);
            if ($hit) {
                $items[] = item($full, $f, $path, $u);
            }

            if (is_dir($full) && ! is_link($full)) {
                $walk($full, $path);
            }
        }
    };
    $walk($root, '');
    return ['items' => $items];
}

function mkItem(string $u, array $in, bool $dir): array {
    $parent = resolve($u, $in['path'] ?? '', true);
    $name   = cleanName($in['name'] ?? '');
    if (file_exists("$parent/$name")) {
        throw new Fail("“{$name}” already exists", 409);
    }

    if ($dir) {
        mkdir("$parent/$name", 0755);
        return ['message' => 'Folder created'];
    }
    $content = (string) ($in['content'] ?? '');
    needSpace($u, strlen($content));
    file_put_contents("$parent/$name", $content);
    return ['message' => 'File created'];
}

/** Preview data from the plugin handling this file type (see Plugin::view). */
function viewFile(array $in): array {
    [$full, $owner, $key] = fileTarget($in);
    $p = is_file($full) ? pluginFor($full) : null;
    if (! $p) {
        throw new Fail('No preview available for this file', 404);
    }
    if (filesize($full) > $p->maxSize) {
        throw new Fail('This file is too large to preview – please download it', 413);
    }

    $trash = isset($in['trash']);
    $inner = isset($in['entry']); // a file inside an archive: read-only
    $q     = ($trash ? ['trash' => 1, 'path' => (string) ($in['path'] ?? '')]
                     : ['owner' => (string) ($in['owner'] ?? ''), 'path' => cleanPath($in['path'] ?? '')])
           + ($inner ? ['entry' => array_values((array) $in['entry'])] : []);
    $v     = filemtime($full);
    $ctx   = ['asset'    => fn(string $name): string => 'file.php?' . http_build_query($q + ['asset' => $name, 'v' => $v]),
              'password' => archivePassword($owner, $key)];
    $out             = pluginCall(fn() => $p->view($full, $ctx));
    $out['editable'] = $p->editable && ! $trash && ! $inner && $owner === me() && ($out['editable'] ?? true); // a plugin may refuse per file
    return ['plugin' => $p->id] + $out;
}

/**
 * A plugin's info() about a file (see plugins/README.md) as [[section, [[label, value], …]], …] – lists, so the order
 * survives JSON. Values are text or numbers; a date is {t: unix time} for the browser to show in the visitor's time zone.
 */
function infoFile(array $in): array {
    [$full, $owner, $key] = fileTarget($in);
    $p = is_file($full) ? pluginFor($full) : null;
    if (! $p || ! overrides($p, 'info')) {
        throw new Fail('No details available for this file', 404);
    }

    $ctx = ['password' => archivePassword($owner, $key)];
    $out = [];
    foreach (pluginCall(fn() => $p->info($full, $ctx)) as $title => $rows) {
        $list = [];
        foreach ((array) $rows as $label => $v) {
            $v = match (true) {
                $v instanceof DateTimeInterface => ['t' => $v->getTimestamp()],
                is_bool($v)                     => $v ? 'Yes' : 'No',
                is_int($v), is_float($v)        => is_finite((float) $v) ? $v : null,
                is_string($v)                   => trim(mb_strimwidth(mb_scrub($v, 'UTF-8'), 0, 2000, '…', 'UTF-8')),
                default                         => null,
            };
            if ($v !== null && $v !== '') {
                $list[] = [(string) $label, $v];
            }
        }
        if ($list) {
            $out[] = [(string) $title, $list];
        }
    }
    return ['sections' => $out];
}

/** Remembers the password for the archive the request points at (which may itself be inside another archive). */
function unlock(array $in): array {
    $pw = (string) ($in['password'] ?? '');
    if ($pw === '' || strlen($pw) > 1024) {
        throw new Fail('Please enter the password', 400);
    }

    [$full, $owner, $key] = fileTarget($in);
    $p = is_file($full) ? pluginFor($full) : null;
    if (! $p || ! overrides($p, 'extract')) {
        throw new Fail('This is not an archive', 400);
    }
    rememberPassword($owner, $key, $pw);
    return [];
}

/** Saves ['content' => text] as is, or ['data' => …] from a plugin editor through that file type's plugin. */
function save(string $u, string $path, array $in): array {
    $full = resolve($u, $path, true);
    if (! is_file($full)) {
        throw new Fail('Not a file', 400);
    }

    if (array_key_exists('data', $in)) {
        $p = pluginFor($full);
        if (! $p || ! $p->editable) {
            throw new Fail('This file type cannot be edited', 400);
        }
        $content = pluginCall(fn() => $p->save($full, $in['data']));
    } else {
        $content = (string) ($in['content'] ?? '');
    }

    needSpace($u, strlen($content) - filesize($full));
    file_put_contents($full, $content, LOCK_EX);
    return ['message' => 'Saved'];
}

function renameItem(string $u, string $path, string $name): array {
    $full = resolve($u, $path, true);
    if ($path === '') {
        throw new Fail('Invalid path', 400);
    }

    $newPath = join_(dirname($path) === '.' ? '' : dirname($path), $name);
    if ($newPath === $path) {
        return ['message' => 'Unchanged'];
    }

    // allow changing only letter case on case-insensitive file systems
    if (file_exists(dirname($full) . "/$name") && strcasecmp($newPath, $path) !== 0) {
        throw new Fail("“{$name}” already exists", 409);
    }

    if (! rename($full, dirname($full) . "/$name")) {
        throw new Fail('Rename failed', 500);
    }

    remapShares($u, $path, $newPath);
    return ['message' => 'Renamed'];
}

/** Move (own files only) or copy (also from files shared with me) into one of my folders. */
function transfer(string $u, string $owner, array $items, string $dest, bool $move): array {
    $destFull = resolve($u, $dest, true);
    if (! is_dir($destFull)) {
        throw new Fail('Destination is not a folder', 400);
    }

    if (! $move) {
        $bytes = 0;
        foreach ($items as $p) {
            try {
                $bytes += treeSize(resolve($owner, $p));
            } catch (Fail) {
            }
        }
        needSpace($u, $bytes);
    }
    return forEachItem($items, function ($p) use ($u, $owner, $dest, $destFull, $move) {
        $src = resolve($owner, $p, $move);
        if ($p === '') {
            throw new Fail('Invalid item');
        }

        if ($owner === $u && ($dest === $p || str_starts_with($dest, "$p/"))) {
            throw new Fail('Cannot put a folder inside itself');
        }

        $name = basename($p);
        if ($move) {
            if (dirname("$destFull/$name") === dirname($src)) {
                return; // already there
            }
            if (file_exists("$destFull/$name")) {
                throw new Fail('An item with this name already exists there');
            }

            if (! moveTree($src, "$destFull/$name")) {
                throw new Fail('Move failed');
            }

            remapShares($u, $p, join_($dest, $name));
        } else {
            copyTree($src, "$destFull/" . uniqueName($destFull, $name));
        }
    }, $move ? 'moved' : 'copied');
}

function share(string $u, string $path, array $users): array {
    resolve($u, $path, true);
    if ($path === '') {
        throw new Fail('Cannot share your whole drive', 400);
    }

    $valid = array_values(array_unique(array_filter($users, fn($x) => $x === 'public' || ($x !== $u && isset(users()[$x])))));
    updateShares($u, function ($s) use ($path, $valid) {
        $s[$path] = implode(' ', $valid);
        return $s;
    });
    return ['message' => $valid ? 'Sharing updated' : 'Sharing removed'];
}

function prefAction(string $u, string $k, string $v): array {
    if (! in_array($k, ['view_type', 'sort'], true) || strlen($v) > 50) {
        throw new Fail('Invalid preference', 400);
    }

    setPref($u, $k, $v);
    return [];
}

function deleteForever(string $u, string $p): void {
    $full = resolve($u, $p, true);
    if ($p === '' || ! rmTree($full)) {
        throw new Fail('Could not delete');
    }

    remapShares($u, $p, null);
}

function emptyTrash(string $u): array {
    $n = 0;
    foreach (scandir($d = trashDir($u)) as $f) {
        if ($f !== '.' && $f !== '..' && $f !== '.info.csv' && rmTree("$d/$f")) {
            $n++;
        }
    }

    csvUpdate(trashInfoFile($u), TRASH_HEADER, fn() => []);
    return ['message' => "$n item(s) permanently deleted"];
}

// ---------- Chunked upload: init → chunks (any order, in parallel) → finalised on the last one ----------

function uploadMeta(string $id): string {
    if (! preg_match('/^[a-f0-9]{32}$/', $id)) {
        throw new Fail('Invalid upload', 400);
    }
    return TEMP_DIR . "/$id.json";
}

function uploadInit(string $u, array $in): array {
    foreach (glob(TEMP_DIR . '/*') as $f) { // stale sessions
        if (filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }

    resolve($u, $in['path'] ?? '', true);
    $rel = cleanPath(preg_replace('#^/+#', '', (string) ($in['rel'] ?? '')));
    cleanName(basename($rel));
    $size = (int) ($in['size'] ?? -1);
    if ($size < 0 || $size > MAX_UPLOAD_SIZE) {
        throw new Fail('File is too large (max ' . fmtSize(MAX_UPLOAD_SIZE) . ')', 413);
    }

    needSpace($u, $size);
    $id   = bin2hex(random_bytes(16));
    $meta = ['user'   => $u,
             'path'   => cleanPath($in['path'] ?? ''),
             'rel'    => $rel,
             'size'   => $size,
             'chunks' => max(1, (int) ceil($size / CHUNK_SIZE)),
             'got'    => []];
    file_put_contents(uploadMeta($id), json_encode($meta));
    touch(TEMP_DIR . "/$id.part");
    return ['id' => $id, 'chunk' => CHUNK_SIZE] + ($size === 0 ? finishUpload($u, $id, $meta) : []);
}

function uploadChunk(string $u, string $id, int $index): array {
    $fp = @fopen($metaFile = uploadMeta($id), 'r+') ?: throw new Fail('Upload not found', 404);
    flock($fp, LOCK_EX); // serialises chunks of one file; the network transfer itself already happened in parallel
    try {
        $meta = json_decode(stream_get_contents($fp), true);
        if (($meta['user'] ?? '') !== $u) {
            throw new Fail('Access denied', 403);
        }

        $expect = $index === $meta['chunks'] - 1 ? $meta['size'] - $index * CHUNK_SIZE : CHUNK_SIZE;
        $tmp    = $_FILES['chunk']['tmp_name'] ?? '';
        if ($index < 0 || $index >= $meta['chunks'] || ! is_uploaded_file($tmp) || filesize($tmp) !== $expect) {
            throw new Fail('Invalid chunk', 400);
        }

        $part = fopen(TEMP_DIR . "/$id.part", 'c+b');
        fseek($part, $index * CHUNK_SIZE);
        stream_copy_to_stream(fopen($tmp, 'rb'), $part);
        fclose($part);
        $meta['got'][$index] = 1;
        if (count($meta['got']) < $meta['chunks']) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($meta));
            return [];
        }
        return finishUpload($u, $id, $meta);
    } finally {
        fclose($fp);
    }
}

function finishUpload(string $u, string $id, array $meta): array {
    $part = TEMP_DIR . "/$id.part";
    try {
        clearstatcache();
        if (filesize($part) !== $meta['size']) {
            throw new Fail('Upload incomplete', 400);
        }

        needSpace($u, $meta['size']);
        $dir = resolve($u, join_($meta['path'], dirname($meta['rel']) === '.' ? '' : dirname($meta['rel'])), true, false);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new Fail('Could not create folder', 500);
        }

        $name = uniqueName($dir, basename($meta['rel']));
        if (! rename($part, "$dir/$name")) {
            throw new Fail('Could not save file', 500);
        }
        return ['done' => true, 'name' => $name];
    } finally {
        @unlink($part);
        @unlink(TEMP_DIR . "/$id.json");
    }
}

function uploadCancel(string $u, string $id): array {
    $meta = json_decode(@file_get_contents(uploadMeta($id)) ?: '{}', true);
    if (($meta['user'] ?? '') === $u) {
        @unlink(TEMP_DIR . "/$id.json");
        @unlink(TEMP_DIR . "/$id.part");
    }
    return [];
}
