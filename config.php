<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Jan-2026
 */
// CloudDrive configuration.
// CLOUDDRIVE_ROOT may point at another directory holding users.csv, quotas.csv, data/ and temp/.

define('SITE_TITLE', 'Zion School CloudDrive'); // shown in the browser tab and next to the logo

$root = getenv('CLOUDDRIVE_ROOT') ?: __DIR__;

define('USERS_FILE', $root . '/users.csv');
define('QUOTAS_FILE', $root . '/quotas.csv');
define('DATA_DIR', $root . '/data');
define('TEMP_DIR', $root . '/temp');

define('MAX_UPLOAD_SIZE', 1024 * 1024 * 1024); // 1 GB per file
define('DEFAULT_QUOTA', 100 * 1024 * 1024);    // 100 MB when user is not in quotas.csv
define('CHUNK_SIZE', 1835008);                 // 1.75 MB, stays under PHP's default 2M upload limit

// Opening files inside archives (plugins/archive): each opened file is extracted once into temp/cache.
define('ARCHIVE_MAX_ENTRY', 512 * 1024 * 1024); // largest single file that can be opened from inside an archive
define('ARCHIVE_CACHE', 1024 * 1024 * 1024);    // extracted files kept; least recently used are removed beyond this (and after a day)
define('SEVENZIP', '');                         // 7-Zip program for RAR / 7z: '' = find 7zz or 7z on the PATH, 'off' = never use it
