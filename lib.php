<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 06-Nov-2026
 */
require_once __DIR__ . '/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
// Sessions are read-only (no lock held) except where a page opts in, e.g. login/logout.
session_start(defined('SESSION_WRITE') ? [] : ['read_and_close' => true]);
foreach ([DATA_DIR, TEMP_DIR] as $d) {
    if (! is_dir($d)) {
        mkdir($d, 0755, true);
    }
}

const RESERVED = ['.share.csv', '.preferences.csv', '.Trash', '.Thumbnails'];

// Extension → category. Text/code files are searchable and editable.
const TYPES = [
    'image'   => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff'],
    'video'   => ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv'],
    'audio'   => ['mp3', 'wav', 'ogg', 'oga', 'opus', 'm4a', 'flac', 'aac'],
    'pdf'     => ['pdf'],
    'text'    => ['txt', 'md', 'log', 'csv', 'tsv'],
    'code'    => ['html', 'htm', 'css', 'js', 'ts', 'json', 'xml', 'yaml', 'yml', 'php', 'c', 'cpp', 'cc', 'cxx',
                  'h', 'hpp', 'java', 'py', 'rs', 'kt', 'go', 'swift', 'rb', 'sh', 'bat', 'bash', 'ino', 'sql',
                  'r', 'm', 'lua', 'dart', 'toml', 'ini', 'conf', 'cfg'],
    'archive' => ['zip', 'rar', 'tar', 'gz', 'tgz', '7z'],
    'doc'     => ['doc', 'docx', 'odt', 'rtf'],
    'sheet'   => ['xls', 'xlsx', 'ods'],
    'slides'  => ['ppt', 'pptx', 'odp'],
    'model'   => ['stl', '3mf', 'obj', 'glb', 'gcode', 'gco'],
];
// Only these are ever served inline; everything else is plain text or a download.
const INLINE_MIME = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'bmp'  => 'image/bmp',
    'webp' => 'image/webp',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mov'  => 'video/quicktime',
    'mkv'  => 'video/x-matroska',
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'oga'  => 'audio/ogg',
    'opus' => 'audio/ogg',
    'm4a'  => 'audio/mp4',
    'flac' => 'audio/flac',
    'aac'  => 'audio/aac',
    'pdf'  => 'application/pdf',
];

class Fail extends Exception {} // message is shown to the user, code is the HTTP status

/** An archive needs a password ($wrong: the one given didn't work). The browser asks for it and calls api.php?action=unlock. */
class NeedPassword extends Fail {
    public function __construct(bool $wrong = false) {
        parent::__construct($wrong ? 'Wrong password – please try again' : 'This file is password-protected', 423); // not 401: that means "log in again"
    }
}

function category(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    foreach (TYPES as $cat => $exts) {
        if (in_array($ext, $exts, true)) {
            return $cat;
        }
    }
    return 'other';
}

function isText(string $name): bool {
    return in_array(category($name), ['text', 'code'], true);
}

// ---------- CSV ----------

function csvRead(string $file): array {
    if (! is_file($file) || ! ($fp = fopen($file, 'r'))) {
        return [];
    }

    fgetcsv($fp, 0, ',', '"', '\\');
    $rows = [];
    while (($r = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        if ($r !== [null]) {
            $rows[] = array_map('trim', $r);
        }
    }

    fclose($fp);
    return $rows;
}

/** Locked read-modify-write: $fn receives the data rows and returns the new rows. */
function csvUpdate(string $file, array $header, callable $fn): void {
    $fp = fopen($file, 'c+');
    flock($fp, LOCK_EX);
    $rows = [];
    if (fgetcsv($fp, 0, ',', '"', '\\') !== false) {
        while (($r = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            if ($r !== [null]) {
                $rows[] = $r;
            }
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fputcsv($fp, $header, ',', '"', '\\');
    foreach ($fn($rows) as $r) {
        fputcsv($fp, $r, ',', '"', '\\');
    }

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ---------- Users & auth ----------

function users(): array { // username => row
    static $u;
    if ($u === null) {
        $u = [];
        foreach (csvRead(USERS_FILE) as $r) {
            if (($r[1] ?? '') !== '') {
                $u[$r[1]] = $r;
            }
        }
    }
    return $u;
}

function login(string $user, string $pass): bool {
    $stored = users()[$user][2] ?? null;
    if ($stored === null || $pass === '') {
        return false;
    }

    $ok = str_starts_with($stored, '$') ? password_verify($pass, $stored) : hash_equals($stored, $pass);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $ok;
}

function me(): ?string {
    return isset($_SESSION['user'], users()[$_SESSION['user']]) ? $_SESSION['user'] : null;
}

function needUser(): string {
    return me() ?? throw new Fail('Please log in again', 401);
}

// ---------- Paths ----------

function userDir(string $u): string {
    if (! isset(users()[$u])) {
        throw new Fail('Unknown user', 404);
    }

    $d = DATA_DIR . '/' . $u;
    if (! is_dir($d)) {
        mkdir($d, 0755, true);
    }
    return $d;
}

/** Normalise a relative path; rejects traversal and the app's reserved names. */
function cleanPath(?string $p): string {
    $out = [];
    foreach (explode('/', (string) $p) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..' || str_contains($part, "\0") || in_array($part, RESERVED, true)) {
            throw new Fail('Invalid path', 400);
        }

        $out[] = $part;
    }
    return implode('/', $out);
}

function cleanName(?string $n): string {
    $n = trim((string) $n);
    if ($n === '' || $n === '.' || $n === '..' || strlen($n) > 255 || preg_match('#[/\\\\\0]#', $n) || in_array($n, RESERVED, true)) {
        throw new Fail('Invalid name', 400);
    }
    return $n;
}

function join_(string $a, string $b): string {
    return $a === '' ? $b : ($b === '' ? $a : "$a/$b");
}

/** Returns the share path that grants $viewer (null = guest) read access to $owner's $path, or null. */
function shareBase(?string $viewer, string $owner, string $path): ?string {
    foreach (shares($owner) as $sp => $who) {
        if ((in_array('public', $who, true) || ($viewer !== null && in_array($viewer, $who, true)))
            && ($path === $sp || str_starts_with($path, "$sp/"))) {
            return $sp;
        }
    }
    return null;
}

/**
 * The single access check. Resolves $owner/$path to an absolute path the current
 * visitor may read (or write, which only the owner may do).
 */
function resolve(?string $owner, ?string $path, bool $write = false, bool $mustExist = true): string {
    $me    = me();
    $owner = ($owner ?? '') === '' ? ($me ?? throw new Fail('Please log in', 401)) : $owner;
    $path  = cleanPath($path);
    $root  = userDir($owner);
    if ($owner !== $me && ($write || shareBase($me, $owner, $path) === null)) {
        throw new Fail('Access denied', 403);
    }

    $full = $path === '' ? $root : "$root/$path";
    if (! file_exists($full)) {
        if ($mustExist) {
            throw new Fail('Not found', 404);
        }
        return $full;
    }
    // Symlinks are followed on purpose (e.g. ~/storage/shared linked into a user's folder). cleanPath() rejects '..', so
    // a path can only leave the user's folder through a link, and only an admin with shell access can create one.
    return $full;
}

function uniqueName(string $dir, string $name, string $fmt = '%s (%d)'): string {
    if (! file_exists("$dir/$name")) {
        return $name;
    }

    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' && $ext !== ltrim($name, '.') ? substr($name, 0, -strlen($ext) - 1) : $name;
    $ext  = $base === $name ? '' : ".$ext";
    for ($i = 1; file_exists($dir . '/' . ($n = sprintf($fmt, $base, $i) . $ext)); $i++);
    return $n;
}

function rmTree(string $p): bool {
    if (is_dir($p) && ! is_link($p)) {
        foreach (scandir($p) as $f) {
            if ($f !== '.' && $f !== '..') {
                rmTree("$p/$f");
            }
        }
        return rmdir($p);
    }
    return unlink($p);
}

function copyTree(string $src, string $dst): void {
    if (! is_dir($src)) {
        if (! copy($src, $dst)) {
            throw new Fail('Copy failed', 500);
        }
        return;
    }
    mkdir($dst, 0755, true);
    foreach (scandir($src) as $f) {
        if ($f !== '.' && $f !== '..' && ! in_array($f, RESERVED, true)) {
            copyTree("$src/$f", "$dst/$f");
        }
    }
}

/** rename() that also works between disks, e.g. a folder in a symlinked SD card going to the trash: PHP renames files
 *  across disks but not folders, so those are copied and then removed. */
function moveTree(string $src, string $dst): bool {
    if (@rename($src, $dst)) {
        return true;
    }
    if (is_link($src) || ! is_dir($src) || file_exists($dst)) {
        return false;
    }
    try {
        copyTree($src, $dst);
    } catch (Fail) {
        rmTree($dst);
        return false;
    }
    return rmTree($src);
}

/** Bytes stored under $p. Symlinks count as themselves only: following one (e.g. Termux's ~/storage → /sdcard) would walk storage outside the drive. */
function treeSize(string $p, bool $skipReserved = true): int {
    if (is_link($p)) {
        return 0;
    }
    if (! is_dir($p)) {
        return (int) @filesize($p);
    }

    $s = 0;
    foreach (@scandir($p) ?: [] as $f) { // unreadable folders (Android/data, Android/obb) count as empty
        if ($f !== '.' && $f !== '..' && ! ($skipReserved && in_array($f, RESERVED, true))) {
            $s += treeSize("$p/$f", $skipReserved);
        }
    }
    return $s;
}

function item(string $full, string $name, string $path, string $owner): array {
    $dir = is_dir($full);
    $it  = ['name'  => $name,
            'path'  => $path,
            'owner' => $owner,
            'dir'   => $dir,
            'size'  => $dir ? 0 : filesize($full),
            'mtime' => filemtime($full)];
    if (! $dir && $it['size'] < 5000 && isText($name)) {
        $it['snippet'] = mb_strcut(file_get_contents($full), 0, 200);
    } elseif (! $dir && ($t = pluginText($owner, $full)) !== null && $t !== '') {
        $it['snippet'] = mb_strcut($t, 0, 200);
    }
    return $it;
}

function listDir(string $full, string $path, string $owner): array {
    $items = [];
    foreach (@scandir($full) ?: [] as $f) { // an unreadable folder lists as empty instead of breaking the JSON reply
        if ($f !== '.' && $f !== '..' && ! in_array($f, RESERVED, true)) {
            $items[] = item("$full/$f", $f, join_($path, $f), $owner);
        }
    }
    return $items;
}

// ---------- Shares: data/<user>/.share.csv  (file_path, shared_with "u1 u2 public") ----------

function shares(string $u): array {
    $s = [];
    foreach (csvRead(DATA_DIR . "/$u/.share.csv") as $r) {
        if (($r[0] ?? '') !== '') {
            $s[$r[0]] = array_values(array_filter(explode(' ', $r[1] ?? '')));
        }
    }
    return $s;
}

/** $fn(array $shares): array — locked update of a user's share list. */
function updateShares(string $u, callable $fn): void {
    csvUpdate(userDir($u) . '/.share.csv', ['file_path', 'shared_with'], function ($rows) use ($fn) {
        $s = [];
        foreach ($rows as $r) {
            if (($r[0] ?? '') !== '') {
                $s[$r[0]] = $r[1] ?? '';
            }
        }

        $out = [];
        foreach ($fn($s) as $p => $w) {
            if ($w !== '') {
                $out[] = [$p, $w];
            }
        }
        return $out;
    });
}

/** Re-point shares after a rename/move ($to = new path) or drop them after a delete ($to = null). */
function remapShares(string $u, string $from, ?string $to): void {
    if (! is_file(DATA_DIR . "/$u/.share.csv")) {
        return;
    }

    updateShares($u, function ($s) use ($from, $to) {
        $out = [];
        foreach ($s as $p => $w) {
            if ($p === $from || str_starts_with($p, "$from/")) {
                if ($to === null) {
                    continue;
                }

                $p = $to . substr($p, strlen($from));
            }
            $out[$p] = $w;
        }
        return $out;
    });
}

/** Top-level items shared with $who (a username or 'public') by other users. */
function sharedItems(string $who, ?string $onlyOwner = null): array {
    $items = [];
    foreach (array_keys(users()) as $owner) {
        if ($owner === $who || ($onlyOwner !== null && $owner !== $onlyOwner)) { // never list my own files as shared with me
            continue;
        }
        foreach (shares($owner) as $p => $list) {
            if (! in_array($who, $list, true)) {
                continue;
            }

            try {
                $path = cleanPath($p);
            } catch (Fail) {
                continue;
            }
            $full = DATA_DIR . "/$owner/$path";
            if ($path !== '' && file_exists($full)) {
                $items[] = item($full, basename($path), $path, $owner);
            }
        }
    }
    return $items;
}

// ---------- Trash: data/<user>/.Trash + .info.csv (name, original_path, deleted_time) ----------

function trashDir(string $u): string {
    $d = userDir($u) . '/.Trash';
    if (! is_dir($d)) {
        mkdir($d, 0755, true);
    }
    return $d;
}

function trashInfoFile(string $u): string {
    return trashDir($u) . '/.info.csv';
}

const TRASH_HEADER = ['name', 'original_path', 'deleted_time'];

function trashItems(string $u): array {
    $info = [];
    foreach (csvRead(trashInfoFile($u)) as $r) {
        $info[$r[0]] = $r;
    }

    $items = [];
    $dir   = trashDir($u);
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f === '.info.csv') {
            continue;
        }

        $it = item("$dir/$f", $f, $f, $u);
        $it['orig'] = $info[$f][1] ?? $f;
        if (isset($info[$f][2]) && ($t = strtotime($info[$f][2]))) {
            $it['mtime'] = $t;
        }

        $items[] = $it;
    }
    return $items;
}

function moveToTrash(string $u, string $path): void {
    $full = resolve($u, $path, true);
    if ($path === '') {
        throw new Fail('Cannot delete the root folder', 400);
    }

    $name = uniqueName(trashDir($u), basename($path), '%s_%d');
    if (! moveTree($full, trashDir($u) . "/$name")) {
        throw new Fail("Could not delete $path", 500);
    }

    csvUpdate(trashInfoFile($u), TRASH_HEADER, fn($rows) => [...$rows, [$name, $path, date('Y-m-d H:i:s')]]);
    remapShares($u, $path, null);
}

function trashEntry(string $u, string $name): string {
    $name = cleanName($name);
    if ($name === '.info.csv' || ! file_exists($p = trashDir($u) . "/$name")) {
        throw new Fail('Not found in trash', 404);
    }
    return $p;
}

function forgetTrash(string $u, array $names): void {
    csvUpdate(trashInfoFile($u), TRASH_HEADER, fn($rows) => array_filter($rows, fn($r) => ! in_array($r[0], $names, true)));
}

function restoreFromTrash(string $u, string $name): string {
    $src  = trashEntry($u, $name);
    $orig = $name;
    foreach (csvRead(trashInfoFile($u)) as $r) {
        if ($r[0] === $name) {
            $orig = $r[1];
        }
    }

    try {
        $orig = cleanPath($orig) ?: $name;
    } catch (Fail) {
        $orig = $name;
    }
    $parent = dirname(userDir($u) . "/$orig");
    if (! is_dir($parent)) {
        mkdir($parent, 0755, true);
    }

    $final = uniqueName($parent, basename($orig), '%s_restored_%d');
    if (! moveTree($src, "$parent/$final")) {
        throw new Fail("Could not restore $name", 500);
    }

    forgetTrash($u, [$name]);
    return $final;
}

// ---------- Preferences: data/<user>/.preferences.csv (pref, value) ----------

function prefs(string $u): array {
    $p = [];
    foreach (csvRead(DATA_DIR . "/$u/.preferences.csv") as $r) {
        $p[$r[0]] = $r[1] ?? '';
    }
    return $p;
}

function setPref(string $u, string $k, string $v): void {
    csvUpdate(userDir($u) . '/.preferences.csv', ['pref', 'value'], function ($rows) use ($k, $v) {
        $rows   = array_filter($rows, fn($r) => $r[0] !== $k);
        $rows[] = [$k, $v];
        return $rows;
    });
}

// ---------- Quota: quotas.csv (username, quota) ----------

function parseSize(string $s): int {
    $s = strtoupper(trim($s));
    if ($s === '-1') {
        return -1;
    }

    $mul = ['K' => 1 << 10, 'M' => 1 << 20, 'G' => 1 << 30, 'T' => 1 << 40][substr($s, -1)] ?? 1;
    return (int) ((float) $s * $mul);
}

function quota(string $u): array {
    $limit = DEFAULT_QUOTA;
    foreach (csvRead(QUOTAS_FILE) as $r) {
        if ($r[0] === $u && isset($r[1])) {
            $limit = parseSize($r[1]);
        }
    }

    $used = 0; // everything the user stores, including trash; thumbnails are a cache and don't count
    foreach (scandir($d = userDir($u)) as $f) {
        if ($f !== '.' && $f !== '..' && $f !== '.Thumbnails') {
            $used += treeSize("$d/$f", false);
        }
    }
    return ['used' => $used, 'limit' => $limit];
}

function needSpace(string $u, int $bytes): void {
    $q = quota($u);
    if ($q['limit'] >= 0 && $q['used'] + $bytes > $q['limit']) {
        throw new Fail(sprintf('Storage quota exceeded: needs %s, only %s free', fmtSize($bytes), fmtSize(max(0, $q['limit'] - $q['used']))), 413);
    }
}

function fmtSize(int $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $b >= 1024 && $i < 4; $i++) {
        $b /= 1024;
    }
    return round($b, 1) . ' ' . $u[$i];
}

// ---------- Thumbnails: data/<owner>/.Thumbnails/xx/md5(path_size).jpg ----------

function thumbnail(string $owner, string $key, string $src, int $size): ?string {
    $hash = md5($key . '_' . $size);
    $dst  = userDir($owner) . '/.Thumbnails/' . substr($hash, 0, 2) . "/$hash.jpg";
    if (is_file($dst) && filemtime($dst) >= filemtime($src)) {
        return filesize($dst) ? $dst : null; // empty: known to have no thumbnail (see drawThumbnail)
    }

    if (! is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0755, true);
    }

    $gd  = function_exists('imagejpeg');
    $p   = pluginFor($src);
    $cat = category($src);
    if ($p && overrides($p, 'thumbnail')) {
        return $gd ? drawThumbnail(fn() => $p->thumbnail($src, $size), $src, $dst) : null;
    }
    if ($cat === 'image') {
        return $gd ? drawThumbnail(fn() => imageThumbnail($src, $size), $src, $dst) : null;
    }
    if ($cat === 'audio') { // the cover picture in the tags, if any
        return $gd ? drawThumbnail(fn() => fitPicture(Covers::find($src), $size), $src, $dst) : null;
    }
    if ($cat === 'video') {
        if ($gd && ($cover = Covers::find($src))) { // MP4 / MOV cover art: no need for ffmpeg
            return drawThumbnail(fn() => fitPicture($cover, $size), $src, $dst);
        }
        if (trim((string) shell_exec('command -v ffmpeg'))) {
            foreach (['-ss 1 ', ''] as $seek) { // 1s in, or the first frame for very short clips
                exec(sprintf('ffmpeg -y -loglevel error %s-i %s -frames:v 1 -vf scale=%d:-2 -f image2 %s 2>&1',
                    $seek, escapeshellarg($src), $size, escapeshellarg($dst)), $o, $rc);
                if ($rc === 0 && is_file($dst) && filesize($dst) > 0) {
                    return $dst;
                }
            }
        }
    }
    return null;
}

/**
 * $draw()'s picture as a JPEG at $dst. Drawing one can take a while, so one request draws it while others for the
 * same file wait, and a file that has none is remembered (an empty $dst) until it changes.
 */
function drawThumbnail(callable $draw, string $src, string $dst): ?string {
    $lock = @fopen("$dst.lock", 'c');
    if (! $lock || ! flock($lock, LOCK_EX)) {
        return null;
    }
    try {
        clearstatcache(true, $dst);
        if (is_file($dst) && filemtime($dst) >= filemtime($src)) { // drawn by another request while we waited
            return filesize($dst) ? $dst : null;
        }

        set_time_limit(120);
        try {
            $img = pluginCall($draw);
        } catch (Fail) {
            $img = null;
        }
        $ok = $img && imagejpeg($img, "$dst.tmp", 85) && rename("$dst.tmp", $dst);
        if (! $ok) {
            @unlink("$dst.tmp");
            file_put_contents($dst, '');
        }
        return $ok ? $dst : null;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink("$dst.lock");
    }
}

/**
 * A picture (bytes GD can read, or an image) scaled to fit $size × $size and flattened onto $bg – JPEG has no
 * transparency. $grow: also enlarge smaller pictures (embedded previews are often small). Null when unreadable.
 */
function fitPicture(string|GdImage|null $pic, int $size, int $bg = 0xffffff, bool $grow = true): ?GdImage {
    $img = is_string($pic) && $pic !== '' ? @imagecreatefromstring($pic) : $pic;
    if (! $img instanceof GdImage) {
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $r = $size / max($w, $h);
    $r = $grow ? $r : min(1, $r);
    $t = imagecreatetruecolor(max(1, (int) round($w * $r)), max(1, (int) round($h * $r)));
    imagefilledrectangle($t, 0, 0, imagesx($t) - 1, imagesy($t) - 1, $bg);
    imagealphablending($t, true);
    imagecopyresampled($t, $img, 0, 0, 0, 0, imagesx($t), imagesy($t), $w, $h);
    return $t;
}

/**
 * A photo's thumbnail, turned the way its EXIF Orientation says. A big JPEG's own EXIF thumbnail (usually 160 px) is
 * used instead of decoding the whole photo when it has the photo's shape – an old one, or one padded with black
 * bars, doesn't.
 */
function imageThumbnail(string $src, int $size): ?GdImage {
    $info = @getimagesize($src);
    if (! $info) {
        return null;
    }
    [$w, $h, $type] = $info;
    $exif   = $type === IMAGETYPE_JPEG && function_exists('exif_read_data') ? @exif_read_data($src) : false;
    $orient = (int) ($exif['Orientation'] ?? 1);
    $img    = null;
    if ($type === IMAGETYPE_JPEG && filesize($src) > 4 << 20 && function_exists('exif_thumbnail')) {
        $t     = @exif_thumbnail($src);
        $small = $t ? @imagecreatefromstring($t) : false;
        if ($small && max(imagesx($small), imagesy($small)) >= 160 && $w && $h
            && abs(imagesx($small) / imagesy($small) - $w / $h) <= 0.02 * $w / $h) {
            $img = $small;
        }
    }
    $img = fitPicture($img ?? @imagecreatefromstring((string) file_get_contents($src)), $size, 0xffffff, false);
    if (! $img) {
        return null;
    }
    // [degrees anticlockwise, then flip] that undo each EXIF orientation
    [$turn, $flip] = [2 => [0, IMG_FLIP_HORIZONTAL], 3 => [180, 0], 4 => [0, IMG_FLIP_VERTICAL], 5 => [270, IMG_FLIP_HORIZONTAL],
                      6 => [270, 0], 7 => [270, IMG_FLIP_VERTICAL], 8 => [90, 0]][$orient] ?? [0, 0];
    if ($turn) {
        $img = imagerotate($img, $turn, 0);
    }
    if ($flip) {
        imageflip($img, $flip);
    }
    return $img;
}

/**
 * Cover pictures stored in audio and video files: ID3v2 APIC / PIC (MP3), FLAC PICTURE blocks, Vorbis / Opus
 * METADATA_BLOCK_PICTURE comments (Ogg) and the iTunes "covr" atom (M4A, MP4, MOV). Only the tags are read.
 */
final class Covers {
    const MAX = 16 << 20; // biggest tag / picture read

    /** The picture's bytes (the front cover when the file says which), or null. */
    public static function find(string $full): ?string {
        $fp = @fopen($full, 'rb');
        if (! $fp) {
            return null;
        }
        try {
            $head = (string) fread($fp, 12);
            return match (true) {
                str_starts_with($head, 'ID3')  => self::id3($fp),
                str_starts_with($head, 'fLaC') => self::flac($fp),
                str_starts_with($head, 'OggS') => self::ogg($fp),
                substr($head, 4, 4) === 'ftyp' => self::mp4($fp),
                default                        => null,
            };
        } finally {
            fclose($fp);
        }
    }

    private static function id3($fp): ?string {
        fseek($fp, 0);
        $h = unpack('a3id/Cmajor/Crev/Cflags/C4s', (string) fread($fp, 10));
        $v = $h['major'];
        $n = self::syncsafe($h['s1'], $h['s2'], $h['s3'], $h['s4']);
        if ($v < 2 || $v > 4 || $n > self::MAX) {
            return null;
        }
        $tag = (string) fread($fp, $n);
        if ($h['flags'] & 0x80 && $v < 4) { // whole tag unsynchronised (2.4 does it per frame)
            $tag = str_replace("\xFF\x00", "\xFF", $tag);
        }
        $o = 0;
        if ($h['flags'] & 0x40 && $v > 2) { // extended header
            $o = $v === 3 ? 4 + unpack('N', $tag)[1] : self::syncsafe(...array_values(unpack('C4', $tag)));
        }
        $best = null;
        $idLen = $v === 2 ? 3 : 4;
        while ($o + ($v === 2 ? 6 : 10) <= strlen($tag)) {
            $id = substr($tag, $o, $idLen);
            if (! preg_match('/^[A-Z0-9]+$/', $id)) {
                break; // padding
            }
            if ($v === 2) {
                $len   = unpack('N', "\0" . substr($tag, $o + 3, 3))[1];
                $flags = 0;
                $o    += 6;
            } else {
                $len   = $v === 4 ? self::syncsafe(...array_values(unpack('C4', $tag, $o + 4))) : unpack('N', $tag, $o + 4)[1];
                $flags = unpack('n', $tag, $o + 8)[1];
                $o    += 10;
            }
            $data = substr($tag, $o, $len);
            $o   += $len;
            if ($id !== 'APIC' && $id !== 'PIC') {
                continue;
            }
            if ($v === 4) {
                if ($flags & 0x000C) { // compressed or encrypted
                    continue;
                }
                if ($flags & 0x0001) { // data length indicator
                    $data = substr($data, 4);
                }
                if ($flags & 0x0002) {
                    $data = str_replace("\xFF\x00", "\xFF", $data);
                }
            }
            $enc = ord($data[0] ?? "\0");
            if ($id === 'PIC') { // encoding, "JPG" / "PNG", type, description, data
                $p = 4;
            } else {             // encoding, MIME type\0, type, description, data
                $p = strpos($data, "\0", 1);
                if ($p === false) {
                    continue;
                }
                $p++;
            }
            $type = ord($data[$p] ?? "\0");
            $p++;
            if ($enc === 1 || $enc === 2) { // UTF-16: a two-byte terminator on an even offset
                for ($q = $p; $q + 1 < strlen($data) && substr($data, $q, 2) !== "\0\0"; $q += 2);
                $p = $q + 2;
            } else {
                $p = (($q = strpos($data, "\0", $p)) === false ? strlen($data) : $q) + 1;
            }
            $pic = substr($data, $p);
            if ($pic !== '' && ($best === null || $type === 3)) {
                $best = $pic;
                if ($type === 3) {
                    break; // the front cover
                }
            }
        }
        return $best;
    }

    private static function flac($fp): ?string {
        fseek($fp, 4);
        $best = null;
        for ($i = 0; $i < 64; $i++) {
            $h = (string) fread($fp, 4);
            if (strlen($h) < 4) {
                break;
            }
            $type = ord($h[0]) & 0x7F;
            $len  = unpack('N', "\0" . substr($h, 1))[1];
            if ($type === 6 && $len <= self::MAX) {
                $pic = self::pictureBlock((string) fread($fp, $len));
                if ($pic && ($best === null || $pic[0] === 3)) {
                    $best = $pic[1];
                }
            } else {
                fseek($fp, $len, SEEK_CUR);
            }
            if (ord($h[0]) & 0x80) { // last metadata block
                break;
            }
        }
        return $best;
    }

    /** Vorbis / Opus: a picture is base64 in the comment header, the stream's 2nd packet (split over pages). */
    private static function ogg($fp): ?string {
        fseek($fp, 0);
        $packets = 0;
        $cur     = '';
        $serial  = null;
        while (($h = (string) fread($fp, 27)) !== '' && str_starts_with($h, 'OggS')) {
            $lace = array_values(unpack('C*', (string) fread($fp, ord($h[26]))) ?: []);
            $body = (string) fread($fp, array_sum($lace));
            if (($serial ??= unpack('V', $h, 14)[1]) !== unpack('V', $h, 14)[1]) {
                continue; // another stream interleaved
            }
            for ($i = 0, $at = 0; $i < count($lace); $at += $lace[$i++]) {
                $cur .= substr($body, $at, $lace[$i]);
                if (strlen($cur) > self::MAX) {
                    return null;
                }
                if ($lace[$i] < 255 && ++$packets === 2) { // a packet ends at a segment shorter than 255
                    return self::vorbisComments($cur);
                }
                if ($lace[$i] < 255) {
                    $cur = '';
                }
            }
        }
        return null;
    }

    /** The picture in a Vorbis comment packet ("\x03vorbis" or "OpusTags", vendor, then length-prefixed key=value). */
    private static function vorbisComments(string $d): ?string {
        $o = str_starts_with($d, 'OpusTags') ? 8 : (str_starts_with($d, "\x03vorbis") ? 7 : null);
        if ($o === null || $o + 8 > strlen($d)) {
            return null;
        }
        $o += 4 + unpack('V', $d, $o)[1]; // vendor
        $n  = $o + 4 <= strlen($d) ? unpack('V', $d, $o)[1] : 0;
        $o += 4;
        $best = null;
        for ($i = 0; $i < $n && $o + 4 <= strlen($d); $i++) {
            $len = unpack('V', $d, $o)[1];
            $c   = substr($d, $o + 4, $len);
            $o  += 4 + $len;
            [$key, $val] = explode('=', $c, 2) + [1 => ''];
            $raw = in_array(strtoupper($key), ['METADATA_BLOCK_PICTURE', 'COVERART'], true) ? base64_decode($val, true) : false;
            if ($raw === false) {
                continue;
            }
            $pic = strtoupper($key) === 'COVERART' ? [3, $raw] : self::pictureBlock($raw);
            if ($pic && ($best === null || $pic[0] === 3)) {
                $best = $pic[1];
            }
        }
        return $best;
    }

    /** MP4 / M4A / MOV: moov › udta › meta › ilst › covr › data. Only box headers are read on the way. */
    private static function mp4($fp): ?string {
        $size = fstat($fp)['size'];
        $box  = function (int $from, int $to, string $want) use ($fp): ?array { // [start of contents, end] of the first $want
            for ($o = $from; $o + 8 <= $to;) {
                fseek($fp, $o);
                $h = unpack('Nlen/a4type', (string) fread($fp, 8) . str_repeat("\0", 8));
                $head = 8;
                $len  = $h['len'];
                if ($len === 1) {
                    $len  = unpack('J', (string) fread($fp, 8) . str_repeat("\0", 8))[1];
                    $head = 16;
                } elseif ($len === 0) {
                    $len = $to - $o;
                }
                if ($len < $head || $o + $len > $to) {
                    return null;
                }
                if ($h['type'] === $want) {
                    return [$o + $head, $o + $len];
                }
                $o += $len;
            }
            return null;
        };
        $r = [0, $size];
        foreach (['moov', 'udta', 'meta', 'ilst', 'covr', 'data'] as $name) {
            $r = $box($r[0], $r[1], $name);
            if (! $r) {
                return null;
            }
            if ($name === 'meta') {
                $r[0] += 4; // a full box: version and flags first
            }
        }
        $len = $r[1] - $r[0] - 8; // type indicator and locale, then the picture
        if ($len <= 0 || $len > self::MAX) {
            return null;
        }
        fseek($fp, $r[0] + 8);
        return (string) fread($fp, $len);
    }

    /** A FLAC PICTURE block: [picture type, bytes]. */
    private static function pictureBlock(string $b): ?array {
        if (strlen($b) < 32) {
            return null;
        }
        $type = unpack('N', $b)[1];
        $o    = 4 + 4 + unpack('N', $b, 4)[1]; // MIME type
        if ($o + 4 > strlen($b)) {
            return null;
        }
        $o += 4 + unpack('N', $b, $o)[1] + 16; // description; width, height, depth, colours
        if ($o + 4 > strlen($b)) {
            return null;
        }
        $len = unpack('N', $b, $o)[1];
        $pic = substr($b, $o + 4, $len);
        return $pic === '' ? null : [$type, $pic];
    }

    private static function syncsafe(int $a, int $b, int $c, int $d): int {
        return ($a & 0x7F) << 21 | ($b & 0x7F) << 14 | ($c & 0x7F) << 7 | ($d & 0x7F);
    }
}

// ---------- Sending files ----------

/**
 * Resolves the file a file.php / api.php request points at: ?owner=&path=  or  ?trash=1&path=<name>,
 * plus &entry[]=<name in archive>[&entry[]=<name in that nested archive>…] for a file inside an archive.
 */
function fileTarget(array $r): array { // [absolute path, owner, cache key]
    if (isset($r['trash'])) {
        $full = trashEntry(needUser(), (string) ($r['path'] ?? ''));
        $t    = [$full, me(), '.Trash/' . basename($full)];
    } else {
        $owner = (string) ($r['owner'] ?? '');
        $full  = resolve($owner, $r['path'] ?? '');
        $t     = [$full, $owner ?: me(), cleanPath($r['path'] ?? '')];
    }
    return isset($r['entry']) ? archiveEntry($t, (array) $r['entry']) : $t;
}

function dispositionHeader(string $type, string $name): string {
    $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name) ?? 'download';
    return "Content-Disposition: $type; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name);
}

/** Streams a file with HTTP Range support. Inline only for safe media types (no HTML/SVG on our origin). */
function sendFile(string $full, bool $inline): never {
    $size = filesize($full);
    $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if ($inline) {
        $mime = INLINE_MIME[$ext] ?? (isText($full) ? 'text/plain; charset=utf-8' : 'application/octet-stream');
        if ($mime !== 'application/pdf') {
            header("Content-Security-Policy: sandbox");
        }

        header("Content-Type: $mime");
        header(dispositionHeader('inline', basename($full)));
    } else {
        header('Content-Type: application/octet-stream');
        header(dispositionHeader('attachment', basename($full)));
    }
    header('Accept-Ranges: bytes');
    [$start, $end] = [0, $size - 1];
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
        }
        if ($start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fp = fopen($full, 'rb');
    fseek($fp, $start);
    for ($left = $end - $start + 1; $left > 0 && ! feof($fp); $left -= 65536) {
        echo fread($fp, min(65536, $left));
        flush();
    }
    exit;
}

/**
 * Streams an uncompressed ZIP of [zip-name => absolute path] entries (folders get a trailing '/').
 * Sizes are known up front, so Content-Length is exact and the browser shows real progress.
 */
function sendZip(string $zipName, array $entries): never {
    $total = 22;
    $count = 0;
    foreach ($entries as $n => $p) {
        $total += 76 + 2 * strlen($n) + (is_dir($p) ? 0 : filesize($p));
        $count++;
    }
    if ($total >= 0xFFFFFFFF || $count >= 0xFFFF) {
        throw new Fail('Too much to download at once (4 GB limit) – please download in smaller parts', 413);
    }

    set_time_limit(0);
    header('Content-Type: application/zip');
    header(dispositionHeader('attachment', $zipName));
    header("Content-Length: $total");
    $cd     = '';
    $offset = 0;
    foreach ($entries as $name => $path) {
        $dir    = is_dir($path);
        $size   = $dir ? 0 : filesize($path);
        $crc    = $dir ? 0 : hexdec(hash_file('crc32b', $path));
        $t      = getdate(filemtime($path));
        $dosT   = ($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1);
        $dosD   = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $common = pack('vvvvvVVVvv', 20, 0x0800, 0, $dosT, $dosD, $crc, $size, $size, strlen($name), 0);
        echo pack('V', 0x04034b50), $common, $name;
        if (! $dir) {
            $fp = fopen($path, 'rb');
            while (! feof($fp)) {
                echo fread($fp, 65536);
                flush();
            }
            fclose($fp);
        }
        $cd     .= pack('Vv', 0x02014b50, 20) . $common . pack('vvvVV', 0, 0, 0, $dir ? 0x10 : 0, $offset) . $name;
        $offset += 30 + strlen($name) + $size;
    }
    echo $cd, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($cd), $offset, 0);
    exit;
}

/** Adds $full (file or folder, recursively) to a zip entry list under $prefix. */
function zipEntries(string $full, string $prefix, array &$out): void {
    if (! is_dir($full)) {
        $out[$prefix] = $full;
        return;
    }
    $out["$prefix/"] = $full;
    foreach (scandir($full) as $f) {
        if ($f !== '.' && $f !== '..' && ! in_array($f, RESERVED, true)) {
            zipEntries("$full/$f", "$prefix/$f", $out);
        }
    }
}

// ---------- Plugins: every plugins/<id>/main.php returns an object extending Plugin (see plugins/README.md) ----------

abstract class Plugin {
    public string $id       = '';       // set by the loader: the plugin's folder name
    public array  $exts     = [];       // lower-case extensions handled, e.g. ['csv', 'tsv']
    public string $label    = '';       // human readable, e.g. 'Spreadsheet (CSV)'
    public bool   $editable = false;    // true: save() turns the client editor's data back into file bytes
    public int    $maxSize  = 20 << 20; // bigger files are not parsed (no preview, no search, no snippet)

    /**
     * Preview data sent to the browser. Use one of the built-in renderers
     *   ['html' => '<p>…</p>']                                 (sanitised again in the browser)
     *   ['sheets' => [['name' => 'Sheet1', 'rows' => [['a', 'b'], …], 'truncated' => false], …]]
     * or any data your plugins/<id>/main.js understands. Optional 'notice' => 'shown above the preview'.
     * $ctx['asset']($name) returns a URL that file.php answers with asset($full, $name).
     */
    abstract public function view(string $full, array $ctx): array;

    /** Returns the new file contents for the data posted by the editor. */
    public function save(string $full, mixed $data): string {
        throw new Fail('This file type is view-only', 400);
    }

    /** Plain text of the document, used for content search and thumbnail-view snippets. */
    public function text(string $full): ?string {
        return null;
    }

    /**
     * A picture of the file for the thumbnail view, at most $size × $size pixels, or null for none. It is saved as a
     * JPEG (so draw on a background) in .Thumbnails until the file changes; null is remembered too. Check the file's
     * size yourself: this is called whatever it is. Implementing this is what gives the type thumbnails.
     */
    public function thumbnail(string $full, int $size): ?GdImage {
        return null;
    }

    /**
     * Details about the file for the viewer's info panel: ['Section' => ['Label' => value, …], …]. Values are strings,
     * numbers, booleans or DateTimeInterface (shown in the visitor's time zone); null and '' rows are left out, as are
     * empty sections. Read only what you need (headers, metadata parts) and check the file's size yourself: this is
     * called whatever it is. $ctx: 'password' => ?string (containers). Implementing this is what shows the (i) button.
     */
    public function info(string $full, array $ctx): array {
        return [];
    }

    /** An embedded file (e.g. a picture in a document) as [mime type, bytes]. Only images are served. */
    public function asset(string $full, string $name): ?array {
        return null;
    }

    /**
     * Containers (archives): writes the member $entry – a name your view() listed – to the file $dest, so it can be
     * opened like any other file (?entry[]=…). $ctx: 'password' => ?string (throw NeedPassword when one is needed
     * or wrong), 'limit' => the most bytes you may write (use copyStream(), which enforces it).
     * Implementing this is what makes a plugin a container; view() also gets $ctx['password'].
     */
    public function extract(string $full, string $entry, string $dest, array $ctx): void {
        throw new Fail('Files inside this type cannot be opened', 400);
    }

    protected static function h(?string $s): string {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected static function openZip(string $full): ZipArchive {
        $z = new ZipArchive();
        if ($z->open($full, ZipArchive::RDONLY) !== true) {
            throw new Fail('This file is damaged or not in the expected format', 422);
        }
        return $z;
    }

    /** Reads one entry of a ZIP-based document, refusing suspiciously large (zip bomb) entries. */
    protected static function zipEntry(ZipArchive $z, string $name, int $max = 50 << 20): ?string {
        $st = $z->statName($name);
        if ($st === false) {
            return null;
        }
        if ($st['size'] > $max) {
            throw new Fail('This file is too large to preview', 413);
        }
        $s = $z->getFromName($name);
        return $s === false ? null : $s;
    }

    /**
     * Copies stream $in to the file $dest – all of it, or $length bytes – and returns the bytes written.
     * Fails once more than $limit bytes come out, so a zip bomb stops there. $hash is updated with the data.
     */
    protected static function copyStream($in, string $dest, int $limit, ?int $length = null, ?HashContext $hash = null): int {
        $out = fopen($dest, 'wb') ?: throw new Fail('Could not open this file', 500);
        $n   = 0;
        try {
            while ($length === null || $n < $length) {
                $chunk = @fread($in, $length === null ? 65536 : min(65536, $length - $n)); // read errors: the caller checks size / CRC
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $n += strlen($chunk);
                if ($n > $limit) {
                    throw new Fail('This file is too large to open from inside the archive – please download the archive', 413);
                }
                if ($hash) {
                    hash_update($hash, $chunk);
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new Fail('The server is out of space', 507);
                }
            }
        } finally {
            fclose($out);
        }
        return $n;
    }

    /**
     * A date from a document for info(): with a time zone (ISO 8601 "…Z" / "+05:30", or unix time) a DateTimeImmutable,
     * shown in the visitor's zone; without one (a "floating" local time, e.g. OpenDocument's) the text as written.
     */
    public static function infoDate(string|int|null $s): DateTimeImmutable|string|null {
        if (is_int($s)) {
            return $s > 0 ? (new DateTimeImmutable())->setTimestamp($s) : null;
        }
        $s = trim((string) $s);
        if (! preg_match('/^(\d{4})-(\d\d)-(\d\d)(?:[T ](\d\d):(\d\d)(?::(\d\d))?(?:\.\d+)?)?\s*(Z|[+-]\d\d:?\d\d)?$/i', $s, $m)
            || (int) $m[1] < 1971) { // 1601 / 1899 / 0000: "not set" in several formats
            return null;
        }
        if (! empty($m[7])) {
            return new DateTimeImmutable(sprintf('%s-%s-%sT%s:%s:%s%s', $m[1], $m[2], $m[3], $m[4] ?: '00', $m[5] ?: '00', $m[6] ?: '00', strtoupper($m[7])));
        }
        return isset($m[4]) && $m[4] !== '' ? "$m[1]-$m[2]-$m[3] $m[4]:$m[5]" : "$m[1]-$m[2]-$m[3]";
    }

    /** Seconds as "2 h 5 min" / "45 s" for info(). */
    public static function infoDuration(float $s): ?string {
        if ($s <= 0) {
            return null;
        }
        $d = intdiv((int) round($s), 86400);
        $h = intdiv((int) round($s) % 86400, 3600);
        $m = intdiv((int) round($s) % 3600, 60);
        $parts = array_filter([$d ? "$d d" : '', $h ? "$h h" : '', $m ? "$m min" : '', $s < 3600 && (int) round($s) % 60 ? ((int) round($s) % 60) . ' s' : '']);
        return implode(' ', $parts);
    }

    /** A preview picture stored in the file (PNG / JPEG / GIF / WebP / BMP bytes) for thumbnail(), fitted to $size. */
    protected static function thumbPicture(?string $bytes, int $size, int $bg = 0xffffff): ?GdImage {
        return fitPicture($bytes, $size, $bg);
    }

    /** Memoises $make() in temp/cache for a day. Put everything the result depends on (file mtime, password…) in $key. */
    protected static function cached(string $key, callable $make): mixed {
        $f = cacheDir() . '/' . sha1(static::class . "\0$key") . '.dat';
        if (is_file($f) && filemtime($f) > time() - 86400) {
            $v = @unserialize((string) file_get_contents($f), ['allowed_classes' => false]);
            if ($v !== false) {
                return $v;
            }
        }
        $v = $make();
        file_put_contents("$f.tmp", serialize($v));
        rename("$f.tmp", $f);
        return $v;
    }
}

/** True when $p implements $method itself (rather than inheriting the Plugin default). */
function overrides(Plugin $p, string $method): bool {
    return (new ReflectionMethod($p, $method))->getDeclaringClass()->getName() !== Plugin::class;
}

/**
 * extension => Plugin, loaded once per request. A plugin that throws while loading is logged and skipped.
 * Compile errors can't be caught, so the first load of each new/changed main.php leaves a marker behind
 * while it runs: if that request dies, later requests skip the plugin until main.php changes again.
 */
function plugins(): array {
    static $map;
    if ($map !== null) {
        return $map;
    }

    $map   = [];
    $dir   = TEMP_DIR . '/plugins';
    $file  = "$dir/loaded.json"; // id => mtime of main.php: positive = loads fine, negative = broken (logged once)
    $state = json_decode((string) @file_get_contents($file), true) ?: [];
    $known = $state;
    foreach (glob(__DIR__ . '/plugins/*/main.php') ?: [] as $main) {
        $id = basename(dirname($main));
        if (! preg_match('/^[a-z0-9_-]+$/', $id)) {
            continue;
        }

        $ver = filemtime($main);
        if (($state[$id] ?? null) === -$ver) { // known broken until main.php changes
            continue;
        }
        $mark = ($state[$id] ?? null) === $ver ? null : "$dir/$id.loading";
        if ($mark) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $since = is_file($mark) ? filemtime($mark) : 0;
            if ($since >= $ver && $since < time() - 5) { // older than any load could take: that request died
                error_log("CloudDrive plugin '$id' crashed while loading (see earlier errors) – disabled until plugins/$id/main.php changes");
                @unlink($mark);
                $state[$id] = -$ver;
                continue;
            }
            if ($since < $ver) {
                touch($mark);
            }
            if ($state !== $known) { // keep what we learnt so far, in case this load kills the request
                file_put_contents($file, json_encode($state), LOCK_EX);
                $known = $state;
            }
        }

        try {
            $p = (static fn() => require $main)();
        } catch (Throwable $e) {
            $p = $e;
        }
        $error = match (true) {
            $p instanceof Throwable => "failed to load: $p",
            ! $p instanceof Plugin  => 'must return an object extending Plugin',
            default                 => null,
        };
        if ($mark) {
            @unlink($mark);
            $state[$id] = $error ? -$ver : $ver;
        }
        if ($error) {
            error_log("CloudDrive plugin '$id' $error – disabled until plugins/$id/main.php changes");
            continue;
        }

        $p->id = $id;
        foreach ($p->exts as $ext) {
            $ext = strtolower($ext);
            if (isset($map[$ext])) {
                error_log("CloudDrive plugin '$id': .$ext is already handled by '{$map[$ext]->id}'");
            } else {
                $map[$ext] = $p;
            }
        }
    }
    if ($state !== $known) {
        file_put_contents($file, json_encode($state), LOCK_EX);
    }
    return $map;
}

function pluginFor(string $name): ?Plugin {
    return plugins()[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
}

/** Runs a plugin hook, turning unexpected errors (malformed files) into a friendly message. */
function pluginCall(callable $fn): mixed {
    try {
        return $fn();
    } catch (Fail $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log((string) $e);
        throw new Fail('Could not read this file – it may be damaged or in an unsupported format', 422);
    }
}

/** A plugin's text() for $full, cached next to the thumbnails until the file changes. */
function pluginText(string $owner, string $full): ?string {
    $p = pluginFor($full);
    if (! $p || ! is_file($full) || filesize($full) > $p->maxSize || ! overrides($p, 'text')) {
        return null;
    }

    $hash = md5('text_' . $full);
    $dst  = DATA_DIR . "/$owner/.Thumbnails/" . substr($hash, 0, 2) . "/$hash.txt";
    if (is_file($dst) && filemtime($dst) >= filemtime($full)) {
        return file_get_contents($dst);
    }

    try {
        $text = mb_strcut((string) pluginCall(fn() => $p->text($full)), 0, 1 << 20);
    } catch (Fail) {
        $text = ''; // unreadable: remember that too, so it isn't re-parsed on every listing
    }
    if (! is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0755, true);
    }
    file_put_contents($dst, $text);
    return $text;
}

// ---------- Files inside archives: extracted on first use to temp/cache/x-<hash>/<name>, then served like any file ----------

const ARCHIVE_DEPTH = 4; // an archive inside an archive inside …

function cacheDir(): string {
    $d = TEMP_DIR . '/cache';
    if (! is_dir($d)) {
        mkdir($d, 0755, true);
    }
    return $d;
}

/**
 * Turns [archive, owner, key] into the extracted file for $chain (one member name per nesting level).
 * Access is whatever the archive's is; member names are never used as paths, only hashed.
 */
function archiveEntry(array $t, array $chain): array {
    [$full, $owner, $key] = $t;
    if (! $chain || count($chain) > ARCHIVE_DEPTH) {
        throw new Fail($chain ? 'Archives nested this deep cannot be opened' : 'Invalid path', 400);
    }

    foreach ($chain as $entry) {
        $entry = (string) $entry;
        $p     = is_file($full) ? pluginFor($full) : null;
        if ($entry === '' || str_contains($entry, "\0") || ! $p || ! overrides($p, 'extract')) {
            throw new Fail('Not found', 404);
        }
        if (filesize($full) > $p->maxSize) {
            throw new Fail('This archive is too large to open – please download it', 413);
        }

        $pw   = archivePassword($owner, $key);
        $hash = sha1(implode("\0", [realpath($full), filemtime($full), filesize($full), $entry, $pw ?? ''])); // a cached copy needs the same password
        $dir  = cacheDir() . "/x-$hash";
        $dest = "$dir/" . safeBase($entry); // the real name keeps type detection, previews and downloads working
        if (is_file($dest)) {
            touch($dir); // least recently used goes first
        } else {
            extractEntry($p, $full, $entry, $dir, $dest, $pw);
        }
        $key .= "\0$entry";
        $full = $dest;
    }
    return [$full, $owner, $key];
}

function extractEntry(Plugin $p, string $full, string $entry, string $dir, string $dest, ?string $pw): void {
    $lock = fopen("$dir.lock", 'c') ?: throw new Fail('Could not open this file', 500);
    flock($lock, LOCK_EX); // one extraction per member, however many requests (e.g. video ranges) arrive at once
    try {
        if (is_file($dest)) {
            return;
        }
        sweepCache();
        $free  = @disk_free_space(cacheDir());
        $limit = min(ARCHIVE_MAX_ENTRY, $free === false ? PHP_INT_MAX : (int) $free - (256 << 20));
        if ($limit <= 0) {
            throw new Fail('The server is out of space', 507);
        }

        set_time_limit(0);
        $part = "$dir.part";
        try {
            pluginCall(fn() => $p->extract($full, $entry, $part, ['password' => $pw, 'limit' => $limit]));
            if (! is_file($part)) {
                throw new Fail('Not found in the archive', 404);
            }
            if (! is_dir($dir)) {
                mkdir($dir, 0755);
            }
            touch($part, filemtime($full)); // thumbnail / text caches compare against this: they follow the archive
            rename($part, $dest);
        } catch (Throwable $e) {
            @unlink($part);
            throw $e;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** A file name for a member: its last path segment, made safe. */
function safeBase(string $entry): string {
    $e = str_replace('\\', '/', rtrim($entry, '/\\'));
    $b = preg_replace('/[\x00-\x1f\x7f]/', '_', substr($e, (int) strrpos("/$e", '/')));
    if ($b === '' || $b === '.' || $b === '..') {
        return 'file';
    }
    if (strlen($b) > 200) {
        $ext = pathinfo($b, PATHINFO_EXTENSION);
        $b   = mb_strcut($b, 0, 190) . (strlen($ext) <= 8 ? ".$ext" : '');
    }
    return $b;
}

/** Keeps temp/cache under ARCHIVE_CACHE (least recently used first) and drops anything not used for a day. */
function sweepCache(): void {
    $old   = time() - 86400;
    $dirs  = [];
    $total = 0;
    foreach (scandir($c = cacheDir()) as $f) {
        $path = "$c/$f";
        if ($f === '.' || $f === '..' || ($t = @filemtime($path)) === false) {
            continue;
        }
        if (! is_dir($path)) {
            if ($t < $old) {
                @unlink($path);
            }
        } elseif ($t < $old) {
            rmTree($path);
        } else {
            $dirs[$path] = [$t, $size = treeSize($path, false)];
            $total      += $size;
        }
    }
    uasort($dirs, fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($dirs as $path => [, $size]) {
        if ($total <= ARCHIVE_CACHE) {
            break;
        }
        rmTree($path);
        $total -= $size;
    }
}

/** The password this browser session gave for the archive at $key (see fileTarget), if any. */
function archivePassword(string $owner, string $key): ?string {
    return $_SESSION['archive_pw'][sha1("$owner\0$key")] ?? null;
}

/** Remembers an archive password for this session only – kept on the server, never in a URL. */
function rememberPassword(string $owner, string $key, string $pw): void {
    session_start(); // sessions are opened read-only; reopen to write
    $all = $_SESSION['archive_pw'] ?? [];
    unset($all[$k = sha1("$owner\0$key")]);
    $all[$k]                 = $pw;
    $_SESSION['archive_pw'] = array_slice($all, -50, null, true);
    session_write_close();
}
