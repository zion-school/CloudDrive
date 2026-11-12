/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
'use strict';
// Archive browser. Data from plugins/archive/main.php: { entries: [{ name: 'a/b.txt', size, mtime, dir, enc }], truncated }.
// Opening a file hands it to the app's viewer (the server extracts it); Back or Esc returns here, to the same folder.
(() => {
    const { esc, icon, ficon, fmtSize, fmtDate, preview } = CD.ui;
    const folders = new WeakMap();   // archive item → folder last shown in it
    const byName = (a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;

    /** Flat member list → folder tree. Folders that only appear in paths are created too. */
    function tree(entries) {
        const mk = () => ({ dirs: new Map(), files: [], mtime: 0 });
        const root = mk();
        const dirAt = segs => segs.reduce((d, s) => {
            if (!d.dirs.has(s))
                d.dirs.set(s, mk());
            return d.dirs.get(s);
        }, root);
        for (const e of entries) {
            const segs = e.name.split('/');
            if (e.dir)
                dirAt(segs).mtime = e.mtime;
            else
                dirAt(segs.slice(0, -1)).files.push({ ...e, base: segs[segs.length - 1] });
        }
        return root;
    }

    CD.viewers.archive = {
        render(el, data, item, opts = {}) {
            const root = tree(data.entries || []);
            const outer = item.within || { item, chain: [] };   // in a nested archive, members extend its chain

            const draw = path => {
                let d = root;
                const segs = [];
                for (const s of path ? path.split('/') : []) {
                    if (!d.dirs.has(s))
                        break;
                    d = d.dirs.get(s);
                    segs.push(s);
                }
                folders.set(item, segs.join('/'));
                const dirs = [...d.dirs].sort((a, b) => byName(a[0], b[0]));
                const files = [...d.files].sort((a, b) => byName(a.base, b.base));
                const crumbs = [item.name, ...segs].map((s, i) => (i < segs.length
                    ? `<a href="#" data-p="${esc(segs.slice(0, i).join('/'))}">${esc(s)}</a>` : `<span>${esc(s)}</span>`)).join('<i>/</i>');
                const row = (attr, it, name, info, mtime, lock) => `<button type="button" class="arc-it" ${attr}>${ficon(it)}
                    <span class="arc-nm" title="${esc(name)}">${esc(name)}${lock ? `<span class="arc-lock" title="Password-protected">${icon('lock')}</span>` : ''}</span>
                    <small>${info}</small><small>${mtime ? fmtDate(mtime) : ''}</small></button>`;
                const sum = [dirs.length && plural(dirs.length, 'folder'), files.length && `${plural(files.length, 'file')} · ${fmtSize(files.reduce((s, f) => s + f.size, 0))}`];

                el.innerHTML = `<div class="arc"><nav class="arc-crumbs">${crumbs}</nav><div class="arc-list">
                    ${dirs.map(([n, x]) => row(`data-d="${esc(n)}"`, { name: n, dir: true }, n, plural(x.dirs.size + x.files.length, 'item'), x.mtime)).join('')}
                    ${files.map((f, i) => row(`data-f="${i}"`, { name: f.base }, f.base, fmtSize(f.size), f.mtime, f.enc)).join('')}
                    ${dirs.length || files.length ? '' : `<p class="muted arc-empty">${segs.length ? 'This folder is empty' : 'This archive is empty'}</p>`}
                    </div><small class="muted arc-sum">${sum.filter(Boolean).join(', ')}</small></div>`;

                el.onclick = e => {
                    const crumb = e.target.closest('[data-p]'), dir = e.target.closest('[data-d]'), file = e.target.closest('[data-f]');
                    if (crumb) {
                        e.preventDefault();
                        draw(crumb.dataset.p);
                    }
                    else if (dir)
                        draw([...segs, dir.dataset.d].join('/'));
                    else if (file) {
                        // ◀ ▶ step through this folder's files; Back reopens the archive with the viewer state it had
                        const items = files.map(f => ({
                            name: f.base, size: f.size, mtime: f.mtime, dir: false, owner: outer.item.owner, path: outer.item.path,
                            within: { item: outer.item, chain: [...outer.chain, f.name] },
                        }));
                        preview(items[+file.dataset.f], { files: items, back: { label: item.name, run: () => preview(item, opts) } });
                    }
                };
            };
            draw(folders.get(item) || '');
        },
    };
})();
