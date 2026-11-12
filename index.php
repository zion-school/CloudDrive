<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Jan-2026
 */
define('SESSION_WRITE', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/shell.php';

if (isset($_GET['logout'])) {
    if (hash_equals($_SESSION['csrf'] ?? '', (string) $_GET['logout'])) {
        session_destroy();
    }

    header('Location: index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password';
}
if (me()) {
    session_write_close();
    shell(false);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in – <?php echo htmlspecialchars(SITE_TITLE) ?></title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="app.css">
</head>
<body class="login">
<form method="POST" class="card">
  <div class="brand"><img class="logo" src="favicon.svg" alt=""><?php echo htmlspecialchars(SITE_TITLE) ?></div>
  <?php if (isset($error)): ?><div class="error"><?php echo htmlspecialchars($error) ?></div><?php endif?>
  <label>Username<input name="username" required autofocus autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? '') ?>"></label>
  <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary">Log in</button>
  <a href="public.php">Browse public files</a>
</form>
</body>
</html>
