/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 06-Nov-2026
 */
'use strict';
// CloudDrive front end. One page for My files / Shared / Trash / Search / Public, driven by the URL hash.
const C = window.CD;
const $ = (s, el = document) => el.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const isMac = /Mac|iPhone|iPad/.test(navigator.platform);

const ICONS = {
    home: 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z', globe: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
    user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
    trash: 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6', menu: 'M3 6h18M3 12h18M3 18h18',
    upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12', download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
    plus: 'M12 5v14M5 12h14', folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
    newfolder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM12 11v6M9 14h6', file: 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6',
    share: 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6M8.6 13.5l6.8 4M15.4 6.5l-6.8 4',
    edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z', eye: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6',
    copy: 'M20 9h-9a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
    move: 'M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20', restore: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5',
    link: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
    x: 'M18 6 6 18M6 6l12 12', grid: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z', list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    left: 'M15 18l-6-6 6-6', right: 'M9 18l6-6-6-6', paste: 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6v4H9z',
    updown: 'M7 4v16M3 8l4-4 4 4M17 20V4M13 16l4 4 4-4', check: 'M20 6 9 17l-5-5', search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16M21 21l-4.3-4.3',
    lock: 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4', info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20M12 16v-4M12 8h.01',
};
const icon = n => `<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICONS[n]}"/></svg>`;

const EXT = {};
for (const [c, list] of Object.entries(C.types))
    for (const e of list)
        EXT[e] = c;
const ext = n => (n.lastIndexOf('.') > 0 ? n.slice(n.lastIndexOf('.') + 1).toLowerCase() : '');
const cat = it => (it.person ? 'person' : it.dir ? 'folder' : EXT[ext(it.name)] || 'other');
const ficon = it => {
    const c = cat(it);
    return `<span class="ficon c-${c}">${c === 'folder' ? icon('folder') : c === 'person' ? icon('user') : `<b>${esc(ext(it.name).slice(0, 4) || 'file')}</b>`}</span>`;
};
const fmtSize = b => {
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1024 && i < 4) {
        b /= 1024;
        i++;
    }
    return `${+b.toFixed(1)} ${u[i]}`;
};
const fmtDate = t => new Date(t * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const parentOf = p => (p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '');

// ---------- State & routing ----------

const S = {
    view: 'my', owner: '', path: '', q: '', base: '', items: [], list: [], sel: new Set(), anchor: null, clip: null,
    layout: C.view === 'list' ? 'list' : 'grid', sort: C.sort, drag: null, touch: false, longPressAt: 0
};
if (C.public)
    try {
        S.layout = localStorage.getItem('layout') || 'grid';
    }
    catch {
    }

function href(view, owner = '', path = '') {
    const parts = [view];
    if (owner && view !== 'my')
        parts.push(owner);
    if (path)
        parts.push(...path.split('/'));
    return '#' + parts.map(encodeURIComponent).join('/');
}

function parseHash() {
    const [view, ...rest] = location.hash.slice(1).split('/').map(decodeURIComponent);
    const views = C.public ? ['public'] : ['my', 'shared', 'trash', 'search'];
    Object.assign(S, { view: views.includes(view) ? view : views[0], owner: '', path: '', q: '' });
    if (S.view === 'my')
        S.path = rest.join('/');
    else if (S.view === 'search')
        S.q = rest.join('/');
    else if (S.view !== 'trash') {
        S.owner = rest[0] || '';
        S.path = rest.slice(1).join('/');
    }
}

const mine = () => S.view === 'my' || S.view === 'search';
const ownerParam = it => (it.owner === C.user ? '' : it.owner);
const sameOwner = items => items.every(i => i.owner === items[0].owner) || (toast('Please select items from one person at a time', true), false);

// A file inside an archive is { name, size, mtime, within: { item: <the archive in the file list>, chain: [member, …] } }:
// one member name per nesting level. It is addressed as the archive plus entry[]=… and is read-only.
const target = it => (it.within ? { ...target(it.within.item), entry: it.within.chain }
    : S.view === 'trash' ? { trash: 1, path: it.name } : { owner: ownerParam(it), path: it.path });
/** URLSearchParams that sends arrays PHP-style (entry[]=a&entry[]=b). */
const qs = o => {
    const u = new URLSearchParams();
    for (const [k, v] of Object.entries(o))
        Array.isArray(v) ? v.forEach(x => u.append(`${k}[]`, x)) : u.append(k, v);
    return u;
};
const fileUrl = (it, extra = {}) => 'file.php?' + qs({ ...target(it), ...extra });

async function api(action, data = {}, method = 'POST', signal) {
    const get = method === 'GET', form = data instanceof FormData;
    const opt = get ? { signal } : {
        method, signal, body: form ? data : JSON.stringify(data),
        headers: { 'X-CSRF': C.csrf, ...(form ? {} : { 'Content-Type': 'application/json' }) }
    };
    const r = await fetch(`api.php?action=${action}${get ? '&' + qs(data) : ''}`, opt);
    const j = await r.json().catch(() => ({ error: `Server error (${r.status})` }));
    if (r.status === 401 && C.user)
        location.href = 'index.php';
    if (!r.ok || j.error)
        throw Object.assign(new Error(j.error || r.statusText), { password: !!j.password });   // password: ask for it, see unlock()
    return j;
}

async function load() {
    parseHash();
    S.sel.clear();
    closeMenu();
    try {
        const r = S.view === 'search' ? await api('search', { q: S.q }, 'GET')
            : await api('list', { view: S.view, owner: S.owner, path: S.path }, 'GET');
        S.items = r.items;
        S.base = r.base || '';
        if (r.quota)
            renderQuota(r.quota);
    }
    catch (e) {
        S.items = [];
        toast(e.message, true);
    }
    render();
}

async function mutate(action, data) {
    try {
        const r = await api(action, data);
        if (r.message)
            toast(r.message);
    }
    catch (e) {
        toast(e.message, true);
    }
    await load();
}

// ---------- Rendering ----------

function sorted() {
    const [key, dir] = S.sort.split(':');
    const val = { size: i => i.size, mtime: i => i.mtime, type: i => ext(i.name) + '\0' + i.name.toLowerCase() }[key] || (i => i.name.toLowerCase());
    const cmp = (a, b) => (typeof a === 'number' ? a - b : a.localeCompare(b, undefined, { numeric: true }));
    return [...S.items].sort((a, b) => (b.dir - a.dir) || (dir === 'desc' ? -1 : 1) * cmp(val(a), val(b)));
}

function render() {
    S.list = sorted();
    document.querySelectorAll('#side [data-view]').forEach(a => a.classList.toggle('on', a.dataset.view === (S.view === 'search' ? 'my' : S.view)));
    const s = $('#search input');
    if (s && document.activeElement !== s)
        s.value = S.q;
    renderBar();
    renderFiles();
}

function renderQuota(q) {
    const pct = q.limit > 0 ? Math.min(100, (q.used / q.limit) * 100) : 0;
    $('#quota').innerHTML = q.limit < 0 ? `<small>${fmtSize(q.used)} used</small>`
        : `<div class="qbar"><i class="${pct >= 90 ? 'crit' : pct >= 75 ? 'warn' : ''}" style="width:${Math.max(pct, 1)}%"></i></div>
       <small>${fmtSize(q.used)} of ${fmtSize(q.limit)} used</small>`;
}

function crumbs() {
    const root = { my: 'My files', shared: 'Shared with me', trash: 'Trash', public: 'Public files', search: 'My files' }[S.view];
    const out = [[root, href(S.view === 'search' ? 'my' : S.view)]];
    if (S.view === 'search')
        out.push([`Search: “${S.q}”`]);
    if (S.owner)
        out.push([S.owner, href(S.view, S.owner)]);
    let p = '';
    for (const seg of S.path ? S.path.split('/') : []) {
        p = p ? `${p}/${seg}` : seg;
        if (S.view === 'my' || p === S.base || p.startsWith(S.base + '/'))
            out.push([seg, href(S.view, S.owner, p)]);
    }
    return out.map(([n, h], i) => (i < out.length - 1 && h ? `<a href="${esc(h)}">${esc(n)}</a>` : `<span>${esc(n)}</span>`)).join('<i class="sep">/</i>');
}

function renderBar() {
    const bar = $('#bar'), n = S.sel.size;
    if (n) {
        bar.innerHTML = `<div class="selbar"><button class="icon-btn" data-b="clear" title="Clear selection">${icon('x')}</button><b>${n} selected</b><div class="grow"></div>
      ${actionsFor([...S.sel]).map((a, i) => `<button class="icon-btn${a.danger ? ' danger' : ''}" data-a="${i}" title="${esc(a.label)}" aria-label="${esc(a.label)}">${icon(a.ic)}</button>`).join('')}</div>`;
        return;
    }
    const [key, dir] = S.sort.split(':');
    bar.innerHTML = `<nav class="crumbs">${crumbs()}</nav><div class="tools">
    ${S.clip && S.view === 'my' ? `<span class="chip">${icon(S.clip.move ? 'move' : 'copy')}${S.clip.items.length} ${S.clip.move ? 'cut' : 'copied'}
      <button class="btn primary sm" data-b="paste">Paste here</button><button class="icon-btn sm" data-b="unclip" title="Clear">${icon('x')}</button></span>` : ''}
    ${S.view === 'my' ? `<button class="btn primary" data-b="new">${icon('plus')}<span>New</span></button>` : ''}
    ${S.view === 'trash' && S.items.length ? `<button class="btn danger-ghost" data-b="empty">${icon('trash')}<span>Empty trash</span></button>` : ''}
    <label class="sort">${icon('updown')}<select data-b="sort">${[['name', 'Name'], ['size', 'Size'], ['mtime', S.view === 'trash' ? 'Deleted' : 'Modified'], ['type', 'Type']]
            .map(([k, l]) => `<option value="${k}"${k === key ? ' selected' : ''}>${l}</option>`).join('')}</select>
      <button class="icon-btn sm" data-b="dir" title="Reverse order">${dir === 'desc' ? '↓' : '↑'}</button></label>
    <button class="icon-btn" data-b="layout" title="${S.layout === 'grid' ? 'List view' : 'Thumbnail view'}">${icon(S.layout === 'grid' ? 'list' : 'grid')}</button></div>`;
}

const extraCol = () => ({ trash: 'Original location', shared: 'Owner', search: 'Folder' }[S.view] || '');

function renderFiles() {
    const f = $('#files'), extra = extraCol();
    f.className = `${S.layout}${extra ? ' extra' : ''}`;
    if (!S.list.length) {
        const msg = { trash: 'Trash is empty', shared: 'Nothing has been shared with you yet', public: 'No public files yet', search: 'No matching files' }[S.view] || 'This folder is empty';
        f.innerHTML = `<div class="empty">${icon(S.view === 'trash' ? 'trash' : S.view === 'search' ? 'search' : 'folder')}<p>${msg}</p>
      ${S.view === 'my' ? '<small>Drag files here, or use <b>New → Upload</b></small>' : ''}</div>`;
        return;
    }
    const [key, dir] = S.sort.split(':');
    const th = (k, l) => `<span data-sort="${k}" class="${k === key ? 'on' : ''}">${l}${k === key ? (dir === 'desc' ? ' ↓' : ' ↑') : ''}</span>`;
    const head = S.layout === 'list' ? `<div class="hdr"><span></span>${th('name', 'Name')}${extra ? `<span>${extra}</span>` : ''}${th('size', 'Size')}${th('mtime', S.view === 'trash' ? 'Deleted' : 'Modified')}</div>` : '';
    f.innerHTML = head + S.list.map(row).join('');
}

function row(it, i) {
    const c = cat(it), on = S.sel.has(it);
    const extra = S.view === 'trash' ? it.orig : S.view === 'search' ? parentOf(it.path) || 'My files' : S.view === 'shared' ? it.owner : '';
    const kind = it.person ? 'Shared files' : it.dir ? 'Folder' : fmtSize(it.size);
    const name = `${esc(it.name)}${it.shared ? `<span class="badge" title="Shared">${icon('link')}</span>` : ''}`;
    const attrs = `class="it${on ? ' sel' : ''}" data-i="${i}" draggable="${S.view === 'my'}"`;
    const ck = `<input type="checkbox" class="ck"${on ? ' checked' : ''} aria-label="Select">`;
    if (S.layout === 'list') {
        return `<div ${attrs}>${ck}<span class="nm">${ficon(it)}<span class="tx"><span class="name">${name}</span><small class="sub">${kind} · ${fmtDate(it.mtime)}</small></span></span>
      ${extraCol() ? `<span class="ex" title="${esc(extra)}">${esc(extra)}</span>` : ''}<span class="sz">${it.dir ? '—' : fmtSize(it.size)}</span><span class="dt">${fmtDate(it.mtime)}</span></div>`;
    }
    // the picture covers the text snippet; files without one (onerror) show the snippet, or the icon
    const media = (it.snippet ? `<pre class="snip">${esc(it.snippet)}</pre>` : '')
        + (c === 'image' || c === 'video' || c === 'audio' || PLUG[ext(it.name)]?.thumb
            ? `<img loading="lazy" draggable="false" alt="" src="${esc(fileUrl(it, { thumb: 1, size: 240, v: it.mtime }))}" onerror="this.remove()">` : '');
    return `<div ${attrs}>${ck}<div class="th">${ficon(it)}${media}${c === 'video' ? '<span class="play">▶</span>' : ''}</div>
    <div class="name" title="${esc(it.name)}">${name}</div><small class="sub">${kind}${extra ? ' · ' + esc(extra) : ''}</small></div>`;
}

function renderSel() {
    document.querySelectorAll('#files .it').forEach(el => {
        const on = S.sel.has(S.list[el.dataset.i]);
        el.classList.toggle('sel', on);
        el.querySelector('.ck').checked = on;
    });
    renderBar();
}

// ---------- Actions ----------

function actionsFor(items) {
    const one = items.length === 1 ? items[0] : null, A = [];
    const add = (label, ic, run, danger) => A.push({ label, ic, run, danger });
    if (S.view === 'trash') {
        if (one && !one.dir)
            add('Preview', 'eye', () => preview(one));
        if (one && hasInfo(one))
            add('Info', 'info', () => preview(one, { info: true }));
        add('Restore', 'restore', () => mutate('restore', { items: items.map(i => i.name) }));
        add('Delete forever', 'trash', () => purge(items), true);
        return A;
    }
    if (items.some(i => i.person))
        return one ? [{ label: 'Open', ic: 'folder', run: () => open(one) }] : [];
    if (one && !one.dir)
        add('Preview', 'eye', () => preview(one));
    if (one && hasInfo(one))
        add('Info', 'info', () => preview(one, { info: true }));
    add('Download', 'download', () => download(items));
    if (mine()) {
        if (one)
            add('Share', 'share', () => shareDialog(one));
        if (one)
            add('Rename', 'edit', () => rename(one));
        add('Move to…', 'move', () => moveCopy(items, true));
        add('Copy to…', 'copy', () => moveCopy(items, false));
        if (one)
            add('Copy path', 'link', () => copyText(one.path));
        add('Move to trash', 'trash', () => mutate('trash', { items: items.map(i => i.path) }), true);
    }
    else if (S.view === 'shared')
        add('Copy to my files', 'copy', () => moveCopy(items, false));
    return A;
}

function open(it) {
    if (!it.dir)
        return preview(it);
    if (S.view === 'trash')
        return;
    if (it.person)
        location.hash = href('public', it.owner);
    else
        location.hash = mine() ? href('my', '', it.path) : href(S.view, it.owner, it.path);
}

function download(items) {
    if (items.length === 1 && !items[0].dir) {
        location.href = fileUrl(items[0]);
        return;
    }
    if (!sameOwner(items))
        return;
    const f = Object.assign(document.createElement('form'), { method: 'POST', action: 'file.php' });
    const add = (name, value) => f.append(Object.assign(document.createElement('input'), { type: 'hidden', name, value }));
    add('zip', 1);
    add('owner', ownerParam(items[0]));
    items.forEach(i => add('items[]', i.path));
    document.body.append(f);
    f.submit();
    f.remove();
    toast('Preparing ZIP download…');
}

async function rename(it) {
    const r = await dialog({ title: 'Rename', input: it.name, ok: 'Rename' });
    if (r && r.v !== it.name)
        mutate('rename', { path: it.path, name: r.v });
}

async function purge(items) {
    if (await dialog({ title: 'Delete forever?', body: `<p>${items.length} item(s) will be permanently deleted. This cannot be undone.</p>`, ok: 'Delete forever', danger: true }))
        mutate(S.view === 'trash' ? 'purge' : 'delete', { items: items.map(i => (S.view === 'trash' ? i.name : i.path)) });
}

async function moveCopy(items, move) {
    if (!sameOwner(items))
        return;
    const dest = await pickFolder(move ? 'Move to' : 'Copy to', move ? parentOf(items[0].path) : '');
    if (dest !== null)
        mutate(move ? 'move' : 'copy', { items: items.map(i => i.path), owner: ownerParam(items[0]), dest });
}

function paste() {
    const c = S.clip;
    if (c.move)
        S.clip = null;
    mutate(c.move ? 'move' : 'copy', { items: c.items, owner: c.owner, dest: S.path });
}

function copyText(t) {
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject())
        .then(() => toast('Copied to clipboard'), () => dialog({ title: 'Copy this', input: t, ok: 'Done', cancel: '' }));
}

function newMenu(x, y) {
    menu([
        { label: 'New folder', ic: 'newfolder', run: async () => { const r = await dialog({ title: 'New folder', input: '', ok: 'Create' }); if (r) mutate('mkdir', { path: S.path, name: r.v }); } },
        {
            label: 'New text file', ic: 'file', run: async () => {
                const r = await dialog({
                    title: 'New file', ok: 'Create', wide: true,
                    body: '<input name="v" placeholder="File name, e.g. notes.txt" required><textarea name="c" rows="10" placeholder="Content (optional)"></textarea>'
                });
                if (r) mutate('mkfile', { path: S.path, name: r.v, content: r.c });
            }
        },
        { label: 'Upload files', ic: 'upload', run: () => $('#pickFiles').click() },
        { label: 'Upload folder', ic: 'folder', run: () => $('#pickFolder').click() },
    ], x, y);
}

// ---------- Dialogs ----------

/** Generic modal. Resolves to the form's fields ({v: input value, …}) on OK, or null. */
function dialog({ title, body = '', input, ok = 'OK', cancel = 'Cancel', danger, wide }) {
    const d = $('#dlg');
    d.className = wide ? 'wide' : '';
    d.returnValue = '';
    d.innerHTML = `<form method="dialog"><h3>${esc(title)}</h3>${body}${input !== undefined ? `<input name="v" value="${esc(input)}" required>` : ''}
    <div class="acts">${cancel ? `<button type="button" class="btn ghost" data-cancel>${cancel}</button>` : ''}<button value="ok" class="btn ${danger ? 'danger' : 'primary'}">${ok}</button></div></form>`;
    d.showModal();
    // Cancel is not a submit button: Enter submits with the form's first submit button, which must be OK
    $('[data-cancel]', d)?.addEventListener('click', () => d.close(''));
    const form = $('form', d), first = $('input:not([type=checkbox]):not([readonly])', d);
    if (first) {
        first.focus();
        const dot = first.value.lastIndexOf('.');
        first.setSelectionRange(0, dot > 0 ? dot : first.value.length);
    }
    return new Promise(res => d.addEventListener('close', () => res(d.returnValue === 'ok' ? Object.fromEntries(new FormData(form)) : null), { once: true }));
}

async function pickFolder(title, start) {
    let cur = start;
    const done = dialog({ title, body: '<div class="picker"></div>', ok: 'Select this folder', wide: true });
    const box = $('#dlg .picker');
    const show = async () => {
        const items = (await api('list', { view: 'my', path: cur }, 'GET').catch(() => ({ items: [] }))).items.filter(i => i.dir);
        const segs = cur ? cur.split('/') : [];
        box.innerHTML = `<div class="crumbs">${['My files', ...segs].map((s, i) => `<a data-p="${esc(segs.slice(0, i).join('/'))}">${esc(s)}</a>`).join('<i class="sep">/</i>')}</div>
      <div class="plist">${items.map(i => `<a data-p="${esc(i.path)}">${icon('folder')}${esc(i.name)}</a>`).join('') || '<p class="muted">No folders inside</p>'}</div>`;
    };
    box.onclick = e => {
        const a = e.target.closest('[data-p]');
        if (a) {
            cur = a.dataset.p;
            show();
        }
    };
    show();
    return (await done) ? cur : null;
}

async function shareDialog(it) {
    let users;
    try {
        users = (await api('shares', { path: it.path }, 'GET')).users;
    }
    catch (e) {
        return toast(e.message, true);
    }
    const pub = users.includes('public');
    users = users.filter(u => u !== 'public');
    const link = new URL(it.dir ? 'public.php' + href('public', C.user, it.path) : 'file.php?' + new URLSearchParams({ owner: C.user, path: it.path }), location.href).href;
    const done = dialog({
        title: `Share “${it.name}”`, ok: 'Save', body: `
    <label class="check"><input type="checkbox" name="pub"${pub ? ' checked' : ''}>${icon('globe')} Public – anyone with the link can view</label>
    <div class="publink"${pub ? '' : ' hidden'}><input readonly value="${esc(link)}"><button type="button" class="btn ghost" data-copy>Copy link</button></div>
    <label>People with access</label><div class="tags"></div>
    <input class="adduser" list="userlist" placeholder="Add a username and press Enter" autocomplete="off">
    <datalist id="userlist">${C.users.map(u => `<option value="${esc(u)}">`).join('')}</datalist>`
    });
    const d = $('#dlg'), tags = $('.tags', d), add = $('.adduser', d);
    const draw = () => {
        tags.innerHTML = users.map((u, i) => `<span class="tag">${esc(u)}<button type="button" data-rm="${i}" title="Remove">×</button></span>`).join('') || '<small class="muted">Only you</small>';
    };
    const addUser = () => {
        const v = add.value.trim();
        add.value = '';
        if (!v || users.includes(v))
            return;
        if (C.users.includes(v)) {
            users.push(v);
            draw();
        }
        else
            toast(`There is no user “${v}”`, true);
    };
    draw();
    tags.onclick = e => {
        if (e.target.dataset.rm) {
            users.splice(+e.target.dataset.rm, 1);
            draw();
        }
    };
    add.onkeydown = e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addUser();
        }
    };
    add.onchange = addUser;
    $('[name=pub]', d).onchange = e => {
        $('.publink', d).hidden = !e.target.checked;
    };
    $('[data-copy]', d).onclick = () => copyText(link);
    const r = await done;
    if (r)
        mutate('share', { path: it.path, users: [...(r.pub ? ['public'] : []), ...users] });
}

// ---------- Plugins (plugins/<id>/main.php, optional main.js / main.css — see plugins/README.md) ----------

const PLUG = {};   // extension → { id, label, editable, maxSize, thumb, info }
for (const [id, p] of Object.entries(C.plugins || {}))
    for (const e of p.exts)
        PLUG[e] = { id, ...p };
const hasInfo = it => !it.dir && !it.person && !!PLUG[ext(it.name)]?.info;
// plugins/<id>/main.js registers: CD.viewers.<id> = { render(el, data, item, opts) → optional cleanup function,
//                                                    edit?(el, data, item) → { value(), dirty() } }
C.viewers = {};

const SAFE_TAGS = new Set(('P BR B STRONG I EM U S STRIKE DEL INS SUB SUP SMALL MARK SPAN DIV FONT H1 H2 H3 H4 H5 H6 UL OL LI '
    + 'TABLE CAPTION COLGROUP COL THEAD TBODY TFOOT TR TD TH A IMG HR BLOCKQUOTE PRE CODE').split(' '));
const DROP_TAGS = /^(SCRIPT|STYLE|IFRAME|FRAME|OBJECT|EMBED|TEMPLATE|SVG|MATH|LINK|META|BASE|FORM|INPUT|BUTTON|TEXTAREA|SELECT|NOSCRIPT)$/;

/** Whitelist sanitiser for plugin HTML; returns a DocumentFragment (never re-parsed, so no mutation XSS). */
function sanitize(html) {
    const doc = new DOMParser().parseFromString(`<!DOCTYPE html><body>${html}`, 'text/html');   // inert: nothing runs or loads
    const clean = el => {
        for (const n of [...el.children]) {
            clean(n);
            if (!SAFE_TAGS.has(n.tagName)) {
                DROP_TAGS.test(n.tagName) ? n.remove() : n.replaceWith(...n.childNodes);
                continue;
            }
            for (const { name, value } of [...n.attributes]) {
                const ok = name === 'style' ? !/url\s*\(|expression|@import|behavior/i.test(value)
                    : name === 'href' ? n.tagName === 'A' && /^(https?:|mailto:)/i.test(value.trim())
                        : name === 'src' ? n.tagName === 'IMG' && /^file\.php\?/.test(value)
                            : ['class', 'alt', 'title', 'colspan', 'rowspan', 'align', 'color', 'size', 'start', 'type'].includes(name);
                if (!ok)
                    n.removeAttribute(name);
            }
            if (n.tagName === 'A' && n.hasAttribute('href'))
                Object.assign(n, { target: '_blank', rel: 'noopener noreferrer' });
            if (n.tagName === 'IMG')
                n.loading = 'lazy';
        }
    };
    clean(doc.body);
    const frag = document.createDocumentFragment();
    frag.append(...doc.body.childNodes);
    return frag;
}

const colName = c => (c >= 26 ? colName(Math.floor(c / 26) - 1) : '') + String.fromCharCode(65 + (c % 26));

/** Built-in read-only spreadsheet view: [{ name, rows: [[cell, …], …], truncated }] with one tab per sheet. */
function renderSheets(el, sheets) {
    el.innerHTML = `<div class="sheets">${sheets.length > 1 ? `<nav class="tabs">${sheets.map((s, i) => `<button type="button" data-sheet="${i}">${esc(s.name)}</button>`).join('')}</nav>` : ''}<div class="sheet"></div></div>`;
    const show = i => {
        const { rows = [], truncated } = sheets[i] || {}, cols = rows.reduce((m, r) => Math.max(m, r.length), 0);
        const keys = [...Array(cols).keys()];
        el.querySelectorAll('[data-sheet]').forEach(b => b.classList.toggle('on', +b.dataset.sheet === i));
        $('.sheet', el).innerHTML = !cols ? '<p class="muted">This sheet is empty</p>'
            : `<table class="grid-table"><thead><tr><th></th>${keys.map(c => `<th>${colName(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((r, ri) => `<tr><th>${ri + 1}</th>${keys.map(c => `<td>${esc(r[c] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table>`
            + (truncated ? '<p class="muted">Only the first part of this sheet is shown – download the file to see everything.</p>' : '');
    };
    el.onclick = e => {
        const b = e.target.closest('[data-sheet]');
        if (b)
            show(+b.dataset.sheet);
    };
    show(0);
}

C.ui = { esc, icon, ficon, fmtSize, fmtDate, toast, dialog, sanitize, renderSheets, colName, api, target, fileUrl, preview, unlock };

/** Asks for the password of the archive at target t and remembers it on the server for this session. */
async function unlock(t, msg) {
    const r = await dialog({
        title: 'Password required', ok: 'Open',
        body: `<p>${esc(msg)}</p><input type="password" name="v" required autocomplete="off" placeholder="Password">`
    });
    if (!r)
        return false;
    try {
        await api('unlock', { ...t, password: r.v });
        return true;
    }
    catch (e) {
        toast(e.message, true);
        return false;
    }
}

// ---------- Preview / editor ----------

// V.files: what ◀ ▶ step through; V.back: { label, run } returns to where this was opened from (an archive);
// V.edit: starts editing (set when the file can be edited); V.editor: the active { dirty(), payload() }.
const V = { files: [], idx: 0, back: null, edit: null, editor: null, dispose: null, info: false, about: null, extra: [] };

/** Stops what the viewer is playing or holding before it shows something else: a plugin viewer's cleanup (e.g. its
 *  WebGL context), and media – removing a <video>/<audio> from the DOM doesn't stop its download (it lives on until
 *  GC, holding a connection and a server worker); dropping the src and calling load() aborts the request right away. */
function releaseMedia(root) {
    const dispose = V.dispose;
    V.dispose = null;
    try {
        dispose?.();
    }
    catch (e) {
        console.error(e);
    }
    for (const m of root.querySelectorAll('video, audio')) {
        m.pause();
        m.removeAttribute('src');
        m.load();
    }
}

/** Opens the viewer on it. opts: { files: what ◀ ▶ step through (default: the folder's files), back: { label, run } }. */
async function preview(it, opts = {}) {
    const v = $('#viewer'), c = cat(it), url = fileUrl(it, { inline: 1 }), plug = PLUG[ext(it.name)];
    releaseMedia(v);   // when stepping from one video to the next
    if (!v.open)
        V.info = store.get('cd.info') === '1';   // the details panel stays open (or shut) while stepping through files
    if (opts.info)
        V.info = true;
    Object.assign(V, { files: opts.files || S.list.filter(i => !i.dir), back: opts.back || null, edit: null, editor: null, about: null, extra: [] });
    V.idx = V.files.indexOf(it);
    v.innerHTML = `<header>${V.back ? `<button class="btn ghost back" data-v="back" title="Back to ${esc(V.back.label)} (Esc)">${icon('left')}<span>${esc(V.back.label)}</span></button>` : ''}
      <span class="ttl">${ficon(it)}<b>${esc(it.name)}</b></span><small class="muted">${V.files.length > 1 ? `${V.idx + 1} / ${V.files.length}` : ''}</small><div class="grow"></div>
      <span class="vedit"></span><a class="btn ghost" href="${esc(fileUrl(it))}">${icon('download')}<span>Download</span></a>
      ${hasInfo(it) ? `<button class="icon-btn" data-v="info" title="Info (I)" aria-pressed="false">${icon('info')}</button>` : ''}
      <button class="icon-btn" data-v="close" title="Close">${icon('x')}</button></header>
    <div class="stage">${V.files.length > 1 ? `<button class="nav prev" data-v="prev" title="Previous">${icon('left')}</button><button class="nav next" data-v="next" title="Next">${icon('right')}</button>` : ''}<div class="content"></div>
      ${hasInfo(it) ? '<aside class="vinfo" hidden></aside>' : ''}</div>`;
    if (!v.open)
        v.showModal();
    const box = $('.content', v), current = () => V.files[V.idx] === it;   // false once the user moved on
    const canEdit = start => {
        V.edit = start;
        $('.vedit', v).innerHTML = `<button class="btn ghost" data-v="edit">${icon('edit')}<span>Edit</span></button>`;
    };
    if (it.within) {   // inside an archive: have the server extract it first, so errors and passwords can be handled here
        box.innerHTML = '<p class="muted">Opening…</p>';
        try {
            await api('entry', target(it), 'GET');
        }
        catch (e) {
            if (!current())
                return;
            const { entry, ...archive } = target(it);   // the password belongs to the (innermost) archive holding it
            if (e.password && await unlock(entry.length > 1 ? { ...archive, entry: entry.slice(0, -1) } : archive, e.message))
                return current() && preview(it, { files: V.files, back: V.back });
            return noPreview(box, it, e.message);
        }
        if (!current())
            return;
    }
    showInfo(it);   // after the archive member is extracted: its password, if any, is known by now
    if (plug)
        return pluginPreview(it, plug, box, current, canEdit);
    if (c === 'image')
        box.innerHTML = `<img src="${esc(url)}" alt="">`;
    else if (c === 'video')
        box.innerHTML = `<video controls autoplay playsinline src="${esc(url)}"></video>`;
    else if (c === 'audio')
        box.innerHTML = `<audio controls autoplay src="${esc(url)}"></audio>`;
    else if (c === 'pdf')
        box.innerHTML = `<iframe src="${esc(url)}" title="PDF"></iframe>`;
    else if ((c === 'text' || c === 'code') && it.size <= 1 << 20) {
        box.innerHTML = '<p class="muted">Loading…</p>';
        const text = await fetch(url).then(r => (r.ok ? r.text() : Promise.reject())).catch(() => null);
        if (!current())
            return;
        const html = ['html', 'htm'].includes(ext(it.name));   // rendered in a sandbox: own origin, no scripts, forms or popups
        box.innerHTML = text === null ? '<p class="muted">Could not load file</p>'
            : html ? '<iframe sandbox title="HTML preview"></iframe>' : '<pre class="code"></pre>';
        if (text === null)
            return;
        if (html)
            $('iframe', box).srcdoc = text;
        else
            $('pre', box).textContent = text;
        if (mine() && !it.within)
            canEdit(() => {
                box.innerHTML = '<textarea class="code" spellcheck="false"></textarea>';
                const ta = $('textarea', box);
                ta.value = text;
                ta.focus();
                return { dirty: () => ta.value !== text, payload: () => ({ content: ta.value }) };
            });
    }
    else
        noPreview(box, it);
}

function noPreview(box, it, msg = 'No preview available for this file') {
    box.innerHTML = `<div class="none">${ficon(it)}<p>${esc(msg)}</p><a class="btn primary" href="${esc(fileUrl(it))}">Download</a></div>`;
}

/** Preview through plugins/<id>/main.php (data from api.php?action=view), drawn by its main.js or a built-in renderer. */
async function pluginPreview(it, plug, box, current, canEdit) {
    if (it.size > plug.maxSize)
        return noPreview(box, it, 'This file is too large to preview');
    box.innerHTML = '<p class="muted">Loading…</p>';
    let r;
    try {
        r = await api('view', target(it), 'GET');
    }
    catch (e) {
        if (current() && e.password && await unlock(target(it), e.message))   // e.g. a 7z archive with encrypted names
            return current() && preview(it, { files: V.files, back: V.back });
        return current() && noPreview(box, it, e.message);
    }
    if (!current())
        return;
    const viewer = C.viewers[plug.id] || {};
    box.innerHTML = `<div class="pview">${r.notice ? `<div class="notice">${esc(r.notice)}</div>` : ''}<div class="pbody"></div></div>`;
    const body = $('.pbody', box);
    if (viewer.render) {
        const opts = { files: V.files, back: V.back, addInfo: (title, rows) => current() && addInfo(it, title, rows) };   // files, back: to come back here with CD.ui.preview
        const dispose = viewer.render(body, r, it, opts);
        if (typeof dispose === 'function')
            V.dispose = dispose;   // called when the viewer moves on or closes
    }
    else if (r.sheets)
        renderSheets(body, r.sheets);
    else {
        body.innerHTML = '<div class="doc"></div>';
        body.firstChild.append(sanitize(r.html || ''));
    }
    if (r.editable && viewer.edit && mine() && !it.within)
        canEdit(async () => {
            const ed = await viewer.edit(body, r, it);   // may return null, e.g. when the user cancels a warning
            return ed && { dirty: ed.dirty, payload: () => ({ data: ed.value() }) };
        });
}

/** Per-browser conveniences (e.g. whether the details panel is open): may be unavailable, so never relied upon. */
const store = {
    get(k) {
        try {
            return localStorage.getItem(k);
        }
        catch {
            return null;
        }
    },
    set(k, v) {
        try {
            localStorage.setItem(k, v);
        }
        catch {}
    },
};

/** Shows / hides the details panel of the viewer for it: the file's own facts, then plugin info() (api.php?action=info). */
function showInfo(it) {
    const v = $('#viewer'), aside = $('.vinfo', v), btn = $(':scope > header [data-v=info]', v);
    if (!aside)
        return;
    aside.hidden = !V.info;
    btn.classList.toggle('on', V.info);
    btn.setAttribute('aria-pressed', V.info);
    if (!V.info)
        return;
    if (!V.about) {   // fetched once per file shown, the first time the panel is open for it
        const rec = V.about = { sections: null, error: null };
        api('info', target(it), 'GET').then(r => (rec.sections = r.sections), e => (rec.error = e.message))
            .then(() => V.about === rec && showInfo(it));
    }
    const val = x => (typeof x === 'number' ? x.toLocaleString() : x && typeof x === 'object' ? fmtDate(x.t) : String(x));
    const sec = (title, rows) => `<section><h3>${esc(title)}</h3><dl>${rows.map(([k, x]) => `<dt>${esc(k)}</dt><dd>${esc(val(x))}</dd>`).join('')}</dl></section>`;
    const w = it.within;
    const file = [
        ['Type', `${PLUG[ext(it.name)].label} (.${ext(it.name)})`],
        ['Size', it.size >= 1024 ? `${fmtSize(it.size)} (${it.size.toLocaleString()} bytes)` : fmtSize(it.size)],
        ['Modified', fmtDate(it.mtime)],
        w ? ['Inside', [w.item.name, ...w.chain.slice(0, -1), parentOf(w.chain[w.chain.length - 1])].filter(Boolean).join(' › ')]
            : S.view === 'trash' ? ['Deleted from', it.orig] : ['Folder', '/' + parentOf(it.path)],
        !w && it.owner && it.owner !== C.user && ['Owner', it.owner],
    ].filter(r => r && r[1]);
    const { sections, error } = V.about;
    aside.innerHTML = `<header><b>Info</b><button class="icon-btn sm" data-v="info" title="Close info">${icon('x')}</button></header>`
        + sec('File', file) + (sections ? sections.map(([t, rows]) => sec(t, rows)).join('') : `<p class="muted">${esc(error || 'Loading…')}</p>`)
        + V.extra.map(([t, rows]) => sec(t, rows)).join('');
}

/** A viewer's own facts about the file (opts.addInfo in render()), e.g. a model's size once it is drawn: shown last. */
function addInfo(it, title, rows) {
    const list = Object.entries(rows || {}).filter(([, x]) => x !== null && x !== undefined && x !== '');
    V.extra = [...V.extra.filter(s => s[0] !== title), ...(list.length ? [[String(title), list]] : [])];
    showInfo(it);
}

function step(d) {
    if (V.files.length > 1 && confirmDiscard())
        preview(V.files[(V.idx + d + V.files.length) % V.files.length], { files: V.files, back: V.back });
}
const dirty = () => !!V.editor?.dirty();
const confirmDiscard = () => !dirty() || confirm('Discard unsaved changes?');

$('#viewer').addEventListener('click', async e => {
    const v = $('#viewer'), b = e.target.closest('[data-v]');
    if (e.target === v)
        return confirmDiscard() && v.close();   // click on the backdrop
    if (!b)
        return;
    const it = V.files[V.idx], act = b.dataset.v;
    if (act === 'close')
        confirmDiscard() && v.close();
    else if (act === 'back' && V.back)
        confirmDiscard() && V.back.run();
    else if (act === 'prev' || act === 'next')
        step(act === 'prev' ? -1 : 1);
    else if (act === 'info')
        toggleInfo(it);
    else if (act === 'edit' && V.edit) {
        const ed = await V.edit();
        if (!ed || V.files[V.idx] !== it)
            return;
        V.editor = ed;
        b.dataset.v = 'save';
        b.className = 'btn primary';
        b.innerHTML = `${icon('check')}<span>Save</span>`;
    }
    else if (act === 'save' && V.editor) {
        try {
            await api('save', { path: it.path, ...V.editor.payload() });
            toast('Saved');
            V.editor = null;
            preview(it).then(load); // back to read mode and refresh sizes behind the viewer
        }
        catch (err) {
            toast(err.message, true);
        }
    }
});
function toggleInfo(it) {
    V.info = !V.info;
    store.set('cd.info', V.info ? '1' : '0');
    showInfo(it);
}

$('#viewer').addEventListener('cancel', e => {   // Esc: back to the archive a file was opened from, else close
    if (V.back || !confirmDiscard())
        e.preventDefault();
    if (V.back)
        V.back.run();   // files inside archives are never edited, so nothing to discard
});
$('#viewer').addEventListener('close', () => {
    releaseMedia($('#viewer'));
    $('#viewer').innerHTML = '';
});

// ---------- Upload ----------

/** Android's storage picker reports paths like tree/<uri>/document/<uri>; turn them back into folder/file. */
function cleanAndroidPath(rel) {
    const p = rel.split('/');
    if (p.length === 4 && p[0] === 'tree' && p[2] === 'document') {
        const tree = decodeURIComponent(p[1]).match(/^[^:]+:(.+)$/), doc = decodeURIComponent(p[3]).match(/^[^:]+:(.+)$/);
        if (tree && doc)
            return doc[1].startsWith(tree[1] + '/') ? tree[1].split('/').pop() + '/' + doc[1].slice(tree[1].length + 1) : doc[1];
    }
    return rel;
}

async function pool(n, items, fn) {
    let next = 0;
    await Promise.all(Array.from({ length: Math.min(n, items.length) }, async () => {
        while (next < items.length)
            await fn(items[next++]);
    }));
}

async function upload(list, dest) {
    if (!list.length)
        return;
    const ctl = new AbortController(), panel = $('#uploads'), errors = [];
    const total = list.reduce((s, x) => s + x.file.size, 0) || 1;
    let sent = 0, done = 0;
    const draw = () => {
        const pct = Math.floor((sent / total) * 100);
        panel.innerHTML = `<div class="row"><b>Uploading ${done} of ${list.length}</b><span>${pct}%</span><button class="btn ghost sm" data-cancel>Cancel</button></div><div class="prog"><i style="width:${pct}%"></i></div>`;
    };
    panel.hidden = false;
    panel.onclick = e => {
        if (e.target.closest('[data-cancel]'))
            ctl.abort();
    };
    draw();
    await pool(2, list, async ({ file, rel }) => {
        let id;
        try {
            const init = await api('upload_init', { path: dest, rel: cleanAndroidPath(rel), size: file.size }, 'POST', ctl.signal);
            id = init.id;
            if (!init.done) {
                await pool(3, [...Array(Math.ceil(file.size / init.chunk)).keys()], async i => {
                    const fd = new FormData();
                    fd.append('id', id);
                    fd.append('index', i);
                    fd.append('chunk', file.slice(i * init.chunk, (i + 1) * init.chunk));
                    await api('upload_chunk', fd, 'POST', ctl.signal);
                    sent += Math.min(init.chunk, file.size - i * init.chunk);
                    draw();
                });
            }
        }
        catch (e) {
            if (id)
                api('upload_cancel', { id }).catch(() => {
                });
            if (!ctl.signal.aborted)
                errors.push(`${rel}: ${e.message}`);
        }
        done++;
        draw();
    });
    panel.hidden = true;
    if (ctl.signal.aborted)
        toast('Upload cancelled', true);
    else if (errors.length)
        dialog({ title: `${errors.length} of ${list.length} upload(s) failed`, body: `<pre class="errors">${esc(errors.join('\n'))}</pre>`, cancel: '' });
    else
        toast(`${list.length} file(s) uploaded`);
    load();
}

async function droppedFiles(dt) {
    const entries = [...dt.items].map(i => i.webkitGetAsEntry?.()).filter(Boolean); // must be read synchronously
    if (!entries.length)
        return [...dt.files].map(file => ({ file, rel: file.name }));
    const out = [];
    const walk = async (en, prefix) => {
        if (en.isFile)
            return out.push({ file: await new Promise((ok, no) => en.file(ok, no)), rel: prefix + en.name });
        const reader = en.createReader();
        for (let batch; (batch = await new Promise((ok, no) => reader.readEntries(ok, no))).length;)
            for (const child of batch)
                await walk(child, `${prefix}${en.name}/`);
    };
    for (const en of entries)
        await walk(en, '');
    return out;
}

for (const id of ['pickFiles', 'pickFolder'])
    $('#' + id).addEventListener('change', e => {
        upload([...e.target.files].map(file => ({ file, rel: file.webkitRelativePath || file.name })), S.path);
        e.target.value = '';
    });

// ---------- Menus, toasts ----------

function menu(acts, x, y) {
    const m = $('#menu');
    if (!acts.length)
        return;
    m.innerHTML = acts.map((a, i) => `<button data-m="${i}" class="${a.danger ? 'danger' : ''}">${icon(a.ic)}${esc(a.label)}</button>`).join('');
    m.hidden = false;
    m.onclick = e => {
        const b = e.target.closest('[data-m]');
        if (b) {
            closeMenu();
            acts[b.dataset.m].run();
        }
    };
    const r = m.getBoundingClientRect();
    m.style.left = `${Math.max(8, Math.min(x, innerWidth - r.width - 8))}px`;
    m.style.top = `${Math.max(8, Math.min(y, innerHeight - r.height - 8))}px`;
}
function closeMenu() {
    $('#menu').hidden = true;
}

function toast(msg, bad) {
    const t = Object.assign(document.createElement('div'), { className: `toast${bad ? ' bad' : ''}`, textContent: msg });
    $('#toasts').append(t);
    setTimeout(() => t.remove(), bad ? 6000 : 3000);
}

// ---------- Events ----------

const files = $('#files');
const itemAt = el => {
    const e = el.closest?.('.it');
    return e ? S.list[e.dataset.i] : null;
};

function toggle(it) {
    S.sel.has(it) ? S.sel.delete(it) : S.sel.add(it);
    S.anchor = it;
    renderSel();
}

files.addEventListener('click', e => {
    const h = e.target.closest('.hdr [data-sort]');
    if (h)
        return setSort(h.dataset.sort);
    const it = itemAt(e.target);
    if (!it || Date.now() - S.longPressAt < 700)
        return;
    if (e.shiftKey && S.anchor && S.list.includes(S.anchor)) {
        const [a, b] = [S.list.indexOf(S.anchor), S.list.indexOf(it)].sort((x, y) => x - y);
        S.list.slice(a, b + 1).forEach(i => S.sel.add(i));
        renderSel();
    }
    else if (e.target.classList.contains('ck') || e.ctrlKey || e.metaKey || (S.touch && S.sel.size)) {
        if (!e.target.classList.contains('ck'))
            e.preventDefault();
        toggle(it);
    }
    else
        open(it);
});

files.addEventListener('contextmenu', e => {
    e.preventDefault();
    if (S.touch)
        return;   // long-press selects instead
    const it = itemAt(e.target);
    if (!it)
        return S.view === 'my' && newMenu(e.clientX, e.clientY);
    if (!S.sel.has(it)) {
        S.sel.clear();
        S.sel.add(it);
        S.anchor = it;
        renderSel();
    }
    menu(actionsFor([...S.sel]), e.clientX, e.clientY);
});

let press = null;   // long press → select (touch devices)
files.addEventListener('pointerdown', e => {
    S.touch = e.pointerType === 'touch';
    const it = itemAt(e.target);
    if (!S.touch || !it || e.target.classList.contains('ck'))
        return;
    press = { x: e.clientX, y: e.clientY, t: setTimeout(() => { press = null; S.longPressAt = Date.now(); navigator.vibrate?.(40); toggle(it); }, 500) };
});
files.addEventListener('pointermove', e => {
    if (press && Math.hypot(e.clientX - press.x, e.clientY - press.y) > 10) {
        clearTimeout(press.t);
        press = null;
    }
});
for (const t of ['pointerup', 'pointercancel'])
    files.addEventListener(t, () => {
        if (press) {
            clearTimeout(press.t);
            press = null;
        }
    });

// Drag items onto a folder to move them; drop files from the computer to upload.
const clearDrop = () => document.querySelectorAll('.drop-target').forEach(el => el.classList.remove('drop-target'));
files.addEventListener('dragstart', e => {
    const it = itemAt(e.target);
    if (!it || S.view !== 'my')
        return e.preventDefault();
    if (!S.sel.has(it)) {
        S.sel.clear();
        S.sel.add(it);
        renderSel();
    }
    S.drag = [...S.sel];
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', it.name);
});
files.addEventListener('dragend', () => {
    S.drag = null;
    clearDrop();
});
files.addEventListener('dragover', e => {
    const it = itemAt(e.target), el = e.target.closest('.it');
    clearDrop();
    if (S.drag) {
        if (it?.dir && !S.drag.includes(it)) {
            e.preventDefault();
            el.classList.add('drop-target');
        }
        return;
    }
    if (S.view !== 'my' || !e.dataTransfer.types.includes('Files'))
        return;
    e.preventDefault();
    (it?.dir ? el : files).classList.add('drop-target');
});
files.addEventListener('dragleave', e => {
    if (!files.contains(e.relatedTarget))
        clearDrop();
});
files.addEventListener('drop', async e => {
    e.preventDefault();
    clearDrop();
    const it = itemAt(e.target), drag = S.drag;
    S.drag = null;
    if (drag) {
        if (it?.dir)
            mutate('move', { items: drag.map(i => i.path), dest: it.path });
        return;
    }
    if (S.view === 'my')
        upload(await droppedFiles(e.dataTransfer), it?.dir ? it.path : S.path);
});

function setSort(key) {
    const [k, d] = S.sort.split(':');
    S.sort = `${key}:${k === key && d === 'asc' ? 'desc' : 'asc'}`;
    savePref('sort', S.sort);
    render();
}
function savePref(key, value) {
    if (C.user)
        api('pref', { key, value: key === 'view_type' ? (value === 'grid' ? 'thumbnail' : 'list') : value }).catch(() => {
        });
    else
        try {
            localStorage.setItem(key === 'view_type' ? 'layout' : key, value);
        }
        catch {
        }
}

$('#bar').addEventListener('click', e => {
    const a = e.target.closest('[data-a]'), b = e.target.closest('[data-b]');
    if (a)
        return actionsFor([...S.sel])[a.dataset.a].run();
    const act = b?.dataset.b;
    if (act === 'clear') {
        S.sel.clear();
        renderSel();
    }
    else if (act === 'new') {
        const r = b.getBoundingClientRect();
        newMenu(r.left, r.bottom + 4);
    }
    else if (act === 'paste')
        paste();
    else if (act === 'unclip') {
        S.clip = null;
        renderBar();
    }
    else if (act === 'empty')
        dialog({ title: 'Empty trash?', body: '<p>Everything in the trash will be permanently deleted.</p>', ok: 'Empty trash', danger: true }).then(r => r && mutate('empty', {}));
    else if (act === 'dir')
        setSort(S.sort.split(':')[0]);
    else if (act === 'layout') {
        S.layout = S.layout === 'grid' ? 'list' : 'grid';
        savePref('view_type', S.layout);
        render();
    }
});
$('#bar').addEventListener('change', e => {
    if (e.target.dataset.b === 'sort') {
        S.sort = `${e.target.value}:asc`;
        savePref('sort', S.sort);
        render();
    }
});

document.addEventListener('keydown', e => {
    if ($('#viewer').open) {
        if (e.target.closest('input, textarea, select, [contenteditable]') || e.ctrlKey || e.metaKey || e.altKey)
            return;
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            e.preventDefault();
            step(e.key === 'ArrowLeft' ? -1 : 1);
        }
        else if (e.key.toLowerCase() === 'i' && $('#viewer header [data-v=info]'))
            toggleInfo(V.files[V.idx]);
        return;
    }
    if ($('#dlg').open || /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName))
        return;
    const mod = e.ctrlKey || e.metaKey, key = e.key.toLowerCase(), sel = [...S.sel];
    if (mod && key === 'a') {
        e.preventDefault();
        S.list.forEach(i => S.sel.add(i));
        renderSel();
    }
    else if (mod && (key === 'c' || key === 'x') && sel.length && (mine() || (key === 'c' && S.view === 'shared')) && sameOwner(sel)) {
        S.clip = { move: key === 'x', owner: ownerParam(sel[0]), items: sel.map(i => i.path) };
        toast(`${sel.length} item(s) ${key === 'x' ? 'cut' : 'copied'} – open a folder and press ${isMac ? '⌘' : 'Ctrl'}+V`);
        renderBar();
    }
    else if (mod && key === 'v' && S.clip && S.view === 'my') {
        e.preventDefault();
        paste();
    }
    else if (e.key === 'Delete' && sel.length) {
        if (S.view === 'trash' || (e.shiftKey && mine()))
            purge(sel);
        else if (mine())
            mutate('trash', { items: sel.map(i => i.path) });
    }
    else if (e.key === 'Escape') {
        S.sel.clear();
        S.clip = null;
        closeMenu();
        renderSel();
    }
    else if (e.key === 'Enter' && sel.length === 1)
        open(sel[0]);
});

document.addEventListener('pointerdown', e => {
    if (!e.target.closest('#menu'))
        closeMenu();
});
addEventListener('resize', closeMenu);
files.addEventListener('scroll', closeMenu);
addEventListener('hashchange', () => {
    document.body.classList.remove('nav-open');
    load();
});
$('#menuBtn').onclick = () => document.body.classList.toggle('nav-open');
$('#scrim').onclick = () => document.body.classList.remove('nav-open');
$('#search')?.addEventListener('submit', e => {
    e.preventDefault();
    const q = e.target.q.value.trim();
    location.hash = q ? href('search', '', q) : href('my');
});

document.querySelectorAll('[data-icon]').forEach(el => el.insertAdjacentHTML('afterbegin', icon(el.dataset.icon)));
if (!location.hash)
    history.replaceState(null, '', href(C.public ? 'public' : 'my'));
load();
