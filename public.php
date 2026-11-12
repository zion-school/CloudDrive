<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 04-Feb-2026
 */
define('SESSION_WRITE', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/shell.php';
$_SESSION['csrf'] ??= bin2hex(random_bytes(16)); // visitors post only one thing: passwords of password-protected archives
session_write_close();
shell(true);
