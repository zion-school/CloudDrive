# CloudDrive plugins

A plugin adds preview (and optionally editing) support for one or more file types.
**Drop a folder in here and it is picked up automatically** – no registration, no config.

```
plugins/
  <id>/            folder name = plugin id: lower-case letters, digits, - or _
    main.php       required – returns the plugin object
    main.js        optional – a custom viewer / editor (loaded on every page)
    main.css       optional – styles for it
    *.php          optional – your own helpers, require_once'd from main.php
    *.js / *.css   optional – more of your own, e.g. loaded by main.js only when needed
  lib/             shared helpers (not a plugin: it has no main.php)
    ole.php        OLE2 / compound-file reader (legacy .doc / .xls), incl. their document properties
    ooxml.php      Office Open XML document properties and thumbnail (.docx / .xlsx)
    sheet.php      spreadsheet limits, number/date display, grid helpers
```

Removing a folder removes the support; with no plugins at all the app works as before
(images, video, audio, PDF and text are built in). Every plugin type has an **info panel** (the (i) button in the viewer)
with what the file says about itself – author, dates, statistics, slicer settings and so on.

Thumbnails use the picture a file carries when it has one: OpenDocument page previews, the Office thumbnail
(when *Save thumbnail* was on), slicer previews in 3MF / G-code, cover art in MP3 / FLAC / Ogg / Opus / M4A / MP4
(built in, no ffmpeg needed), and a big JPEG's own EXIF thumbnail when it matches the photo. Photos are turned
the way their EXIF orientation says.

## Included

| Plugin          | Types         | Mode                                         |
|-----------------|---------------|----------------------------------------------|
| `csv`           | csv, tsv      | view + edit (grid editor)                    |
| `rtf`           | rtf           | view + edit (basic WYSIWYG, warns before losing pictures/tables) |
| `docx`          | docx, docm    | view (simplified), thumbnail if saved        |
| `doc`           | doc           | view (text only)                             |
| `xlsx`          | xlsx, xlsm    | view (values only), thumbnail if saved       |
| `xls`           | xls           | view (values only)                           |
| `opendocument`  | odt, ods, odp | view (simplified; slides as cards), page thumbnails – one plugin, three formats |
| `archive`       | zip, rar, 7z, tar, tgz, gz | browse, and open the files inside (also archives in archives, password-protected ones) |
| `model`         | stl, 3mf, obj, glb, gcode, gco | 3D view (turn, zoom, move; G-code layer by layer) + thumbnails |

### Archives

ZIP is read with PHP's `ZipArchive`, tar / tar.gz / .gz with a small pure-PHP reader (`archive/tar.php`).
**RAR and 7z need the 7-Zip program** (`7zz` or `7z`, 16.02 or newer; on Debian/Ubuntu the `7zip` package –
the old `p7zip-full` needs `p7zip-rar` for RAR). Without it those files offer a download instead. Settings in
`config.php`: `SEVENZIP` (program path, or `'off'`), `ARCHIVE_MAX_ENTRY` (largest file opened from an archive)
and `ARCHIVE_CACHE` (extracted files kept in `temp/cache`). Files inside archives are read-only. Content search
matches the names inside archives (the archive is the hit). A password is kept in the visitor's session on the
server for that archive; for RAR / 7z it is passed to 7-Zip on its command line while 7-Zip runs.

### 3D models and G-code

The browser fetches the file and draws it with WebGL 2 (`model/viewer.js`, loaded by `main.js` the first time a
model is opened), so opening one costs the server nothing. No libraries.

* **Viewer:** drag to turn (the print bed stays underneath; straight down and straight up are the limits), scroll or
  pinch to zoom towards the pointer, right-drag / Shift-drag / two fingers to move, double-click or *Reset* to fit;
  *Top*, *Front*, *Side* views. Shows the size (mm for STL / 3MF / G-code, metres for GLB as glTF defines, plain
  units for OBJ) and the triangle count (also in the info panel, with what the file says about itself: 3MF title /
  designer / licence, slicer profiles and per-plate time and filament; G-code slicer, time, filament and settings;
  GLB generator and contents). Faces are flat-shaded; colours from 3MF materials / colour groups, OBJ `.mtl`
  files next to the OBJ (`Kd`, also inside archives) and vertex colours, GLB material colour, vertex colours and
  base-colour textures (rest pose; no lighting effects, animation or skinning).
* **G-code:** extrusion moves coloured by feature type (outer / inner wall, infill, support… as PrusaSlicer, Orca,
  Bambu Studio, Cura and Simplify3D name them; by height when the file doesn't say), a layer slider (the layers below
  dimmed), travel moves of the current layer on demand, and print time / filament from the slicer's comments.
  Understands G0-G3 (arcs), G90/G91, M82/M83, G92, G20/G21, G28. A sliced project (`.gcode.3mf` from Bambu Studio /
  Orca) opens as its first plate's G-code.
* **Thumbnails** (server, GD only): the preview picture the slicer saved (3MF `Metadata/…png`, G-code
  `; thumbnail begin` PNG/JPG blocks), otherwise a shaded drawing of the model or the toolpaths. Drawing is limited
  by file size (`DRAW_MAX` in `model/main.php`: 32–64 MB) – about 1 s per 150,000 triangles, once.
* Not supported: Draco / meshopt compressed GLB (a message says so), separate `.gltf` files, binary `.bgcode`,
  OBJ textures. Files over 512 MB are not opened in the browser.

## A minimal plugin

`plugins/markdown/main.php`:

```php
<?php
return new class extends Plugin {
    public array  $exts  = ['md', 'markdown'];   // one plugin can handle many extensions
    public string $label = 'Markdown';

    public function view(string $full, array $ctx): array {
        $html = '';
        foreach (preg_split('/\R{2,}/', (string) file_get_contents($full)) as $block) {
            $html .= preg_match('/^(#{1,3}) (.*)/', $block, $m)
                ? "<h" . strlen($m[1]) . ">" . self::h($m[2]) . "</h" . strlen($m[1]) . ">"
                : '<p>' . nl2br(self::h($block)) . '</p>';
        }
        return ['html' => $html, 'notice' => 'Simplified preview'];
    }
};
```

That's all: `.md` files now open in the document viewer.

## The contract (`abstract class Plugin` in `lib.php`)

| Member | Purpose |
|---|---|
| `$exts` | lower-case extensions handled. If two plugins claim one, the first folder alphabetically wins (logged). |
| `$label` | human-readable name |
| `$editable` | `true` if `save()` is implemented and `main.js` provides an editor |
| `$maxSize` | files larger than this (default 20 MB) are not parsed: no preview, search or snippet |
| `view($full, $ctx): array` | **required.** Data for the browser, see below |
| `save($full, $data): string` | returns the new file contents for what the editor posted. Quota, CSRF and ownership are checked by the app |
| `text($full): ?string` | plain text for *content search* and *thumbnail-view snippets* (cached until the file changes) |
| `thumbnail($full, $size): ?GdImage` | a picture for the thumbnail view, at most `$size` × `$size`. Saved as JPEG in `.Thumbnails` until the file changes (so draw on a background); `null` is remembered too. One request draws it while others for the same file wait. Called whatever the file's size – check it yourself |
| `info($full, $ctx): array` | details for the viewer's **info panel** (the (i) button, also *Info* in the right-click menu): `['Section' => ['Label' => value, …], …]`. Values: string, int/float, bool or `DateTimeInterface` (shown in the visitor's time zone); `null` / `''` rows and empty sections are left out. Read headers / metadata only and check the size yourself – it is called whatever the file's size. Implementing it is what shows the button. `$ctx['password']` as for `view()` |
| `asset($full, $name): ?array` | `[mime, bytes]` of something embedded, e.g. a picture. Only images (png/jpeg/gif/bmp/webp) are served |
| `extract($full, $entry, $dest, $ctx): void` | **containers** (archives): write member `$entry` to the file `$dest`, see below |

Helpers available to plugins: `self::h()` (HTML-escape), `self::openZip()`, `self::zipEntry()`
(zip-bomb-safe read), `self::copyStream($in, $dest, $limit, $length, $hash)` (copy with a size cap),
`self::cached($key, fn)` (memoise in `temp/cache` for a day – put the file's mtime in the key),
`self::thumbPicture($bytes, $size)` (a preview picture stored in the file, fitted for `thumbnail()`),
`throw new Fail('message shown to the user', 422)` and `throw new NeedPassword($wrong)`; for `info()`:
`Plugin::infoDate($s)` (ISO 8601 or unix time → a date; one without a time zone stays as written) and
`Plugin::infoDuration($seconds)` ("2 h 5 min").

### Containers: files inside files

A plugin that implements `extract()` makes the files inside its type openable like any other file. They are
addressed as the container plus one name per nesting level – `file.php?path=a.zip&entry[]=docs/b.zip&entry[]=c.pdf`
(also `api.php?action=view|entry`) – so every preview, plugin, thumbnail and download works on them unchanged.
The app extracts each member once into `temp/cache/x-<hash>/<name>` (locked, size-capped, least recently used
evicted), never uses member names as paths, and grants exactly the container's access. Nesting is limited to
4 levels; members are read-only.

* `$entry` is a name your `view()` listed (send them in your own data, e.g. `entries`). Find it, refuse folders.
* `$ctx['limit']`: never write more – use `copyStream()`, which fails past it (zip bombs stop there).
* `$ctx['password']` (also given to `view()`): `null`, or what the user typed. When one is needed or wrong, throw
  `new NeedPassword($ctx['password'] !== null)`; the browser asks and calls `api.php?action=unlock`, which keeps it
  in the session for that container (guests of public files included).

### What `view()` returns

Pick a built-in renderer – no JavaScript needed:

* `['html' => '<p>…</p>']` – shown as a document page. Build it yourself and **escape all file
  content with `self::h()`**. The browser sanitises it again (whitelist of simple tags; `style`
  without `url()`; links only http/https/mailto; images only from `$ctx['asset']`).
* `['sheets' => [['name' => 'Sheet1', 'rows' => [['A1', 'B1'], …], 'truncated' => false], …]]` – tabs + grid.

Optional keys: `'notice' => '…'` (a banner above the preview; use it to say a preview is simplified)
and `'editable' => false` (refuse editing for this particular file, e.g. because it is too big).

`$ctx['asset']($name)` returns a URL; when the browser loads it, `asset($full, $name)` is called.

### Custom viewer / editor (`main.js`)

```js
CD.viewers.<id> = {
    render(el, data, item, opts) { … },         // optional – replaces the built-in renderer
                                                // opts: { files, back } – pass to CD.ui.preview to come back here
                                                //       addInfo(title, rows) – add a section to the info panel,
                                                //       e.g. what you measured once the file is drawn
                                                // may return a function: called when the viewer moves on or closes
                                                // (stop downloads, free a WebGL context…)
    edit(el, data, item) {                      // optional – needs $editable = true
        …                                       // may be async; return null to cancel
        return { dirty: () => bool, value: () => dataForSave };
    },
};
```

`data` is what `view()` returned; `value()` is posted to `save()` as `$data`. A viewer may also ignore it and fetch
the file itself from `CD.ui.fileUrl(item)` (as `model` does) – `view()` then just returns `[]`.
`main.js` and `main.css` are loaded with `?v=` = the newest change to any `.js` / `.css` file of the plugin, so
`main.js` can load more of its own files on demand with the same `?v=` (see `model/main.js`).
`CD.ui` offers `esc, icon, ficon, fmtSize, fmtDate, toast, dialog, sanitize, renderSheets, colName`, and
`api, target, fileUrl`, `preview(item, { files, back: { label, run } })` (open an item – e.g. a member,
`{ name, size, mtime, within: { item: container, chain: [names] } }` – with its own ◀ ▶ list and Back button)
and `unlock(target, message)` (ask for a password).

## Safety notes

* Plugin PHP is never served (`.htaccess`), only the plugin's other files (`main.js`, `main.css`…).
* A plugin that throws while loading (including a syntax error) is skipped and logged. Some compile
  errors (e.g. declaring a method twice) can't be caught: the request that first loads a new or changed `main.php` may fail, then the
  plugin is skipped until `main.php` is changed again. Check the PHP error log.
* Parse errors at run time become a friendly "Could not read this file" message.
* Use `LIBXML_NONET` and never `LIBXML_NOENT` when parsing XML from files.
