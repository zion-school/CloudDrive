<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 06-Nov-2026
 */
/** The single-page app shell, used by index.php (logged in) and public.php (read-only). */
function shell(bool $public): void {
    $me      = $public ? null : me();
    $prefs   = $me ? prefs($me) : [];
    $plugins = [];
    foreach (plugins() as $ext => $p) {
        $plugins[$p->id] ??= ['label' => $p->label, 'editable' => $p->editable, 'maxSize' => $p->maxSize,
                              'thumb' => overrides($p, 'thumbnail'), 'info' => overrides($p, 'info'), 'exts' => []];
        $plugins[$p->id]['exts'][] = $ext;
    }
    // plugins/<id>/main.css and plugins/<id>/main.js are optional and picked up automatically. ?v= changes with any
    // file of the plugin, so main.js can load more of its own files on demand with the same ?v=.
    $ver   = fn(string $id) => max(array_map('filemtime', [__DIR__ . "/plugins/$id", ...(glob(__DIR__ . "/plugins/$id/*.js") ?: []), ...(glob(__DIR__ . "/plugins/$id/*.css") ?: [])]));
    $asset = fn(string $id, string $type) => is_file(__DIR__ . "/plugins/$id/main.$type") ? "plugins/$id/main.$type?v=" . $ver($id) : null;
    $cfg   = [
        'public' => $public,
        'user'   => $me,
        'csrf'   => $_SESSION['csrf'] ?? '',
        'users'  => $me ? array_values(array_diff(array_keys(users()), [$me])) : [],
        'types'  => TYPES,
        'view'   => $prefs['view_type'] ?? 'thumbnail',
        'sort'   => $prefs['sort'] ?? 'name:asc',
        'chunk'  => CHUNK_SIZE,
        'plugins' => (object) $plugins,
    ];
    $e = fn($s) => htmlspecialchars((string) $s);
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>
    <?php echo $e($public ? 'Public files – ' . SITE_TITLE : SITE_TITLE) ?>
  </title>
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="app.css">
  <?php foreach (array_keys($plugins) as $id): if ($css = $asset($id, 'css')): ?>
  <link rel="stylesheet" href="<?php echo $e($css) ?>">
  <?php endif; endforeach?>
</head>

<body>
  <aside id="side">
    <a class="brand" href="<?php echo $public ? 'index.php#my' : '#my' ?>"><img class="logo" src="favicon.svg" alt=""><?php echo $e(SITE_TITLE) ?></a>
    <nav>
      <?php if ($public): ?>
      <a href="#public" data-view="public" data-icon="globe">Public files</a>
      <?php else: ?>
      <a href="#my" data-view="my" data-icon="home">My files</a>
      <a href="#shared" data-view="shared" data-icon="users">Shared with me</a>
      <a href="#trash" data-view="trash" data-icon="trash">Trash</a>
      <a href="public.php" data-icon="globe">Public files</a>
      <?php endif?>
    </nav>
    <div id="quota"></div>
  </aside>
  <div id="scrim"></div>
  <main>
    <header id="top">
      <button class="icon-btn" id="menuBtn" data-icon="menu" title="Menu"></button>
      <?php if ($me): ?>
      <form id="search"><input type="search" name="q" placeholder="Search in my files" autocomplete="off"></form>
      <div class="user"><span class="avatar">
          <?php echo $e(strtoupper(substr($me, 0, 1))) ?>
        </span><span class="uname">
          <?php echo $e($me) ?>
        </span>
        <a class="btn ghost" href="index.php?logout=<?php echo $e($cfg['csrf']) ?>">Log out</a>
      </div>
      <?php else: ?>
      <div class="grow"></div><a class="btn primary" href="index.php">
        <?php echo me() ? 'My files' : 'Log in' ?>
      </a>
      <?php endif?>
    </header>
    <div id="bar"></div>
    <div id="files"></div>
  </main>
  <div id="uploads" hidden></div>
  <div id="menu" hidden></div>
  <div id="toasts"></div>
  <dialog id="dlg"></dialog>
  <dialog id="viewer"></dialog>
  <input type="file" id="pickFiles" multiple hidden>
  <input type="file" id="pickFolder" webkitdirectory hidden>
  <script>window.CD = <?php echo json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <script src="app.js"></script>
  <?php foreach (array_keys($plugins) as $id): if ($js = $asset($id, 'js')): ?>
  <script src="<?php echo $e($js) ?>"></script>
  <?php endif; endforeach?>
</body>

</html>
<?php }
