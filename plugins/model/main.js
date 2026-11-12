/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
'use strict';
// 3D models and G-code. This file is loaded on every page, so it only loads the viewer (viewer.js) the first time a
// model is opened. The viewer fetches the file and draws it itself; plugins/model/main.php only makes thumbnails.
(() => {
    const src = document.currentScript.src.replace(/\/main\.js(?=\?|$)/, '/viewer.js');   // same ?v=, see shell.php
    let loading = null;
    const load = () => (loading ??= new Promise((ok, fail) => {
        const s = document.createElement('script');
        s.src = src;
        s.onload = ok;
        s.onerror = () => {
            loading = null;
            s.remove();
            fail(new Error('Could not load the 3D viewer – please try again'));
        };
        document.head.append(s);
    }));

    CD.viewers.model = {
        render(el, data, item, opts = {}) {
            let view = null, gone = false;
            el.innerHTML = '<div class="m3d"><p class="m3d-msg">Loading…</p></div>';
            load().then(() => {
                if (!gone)
                    view = CD.model3d.open(el.firstChild, item, opts.addInfo);
            }, e => {
                if (!gone)
                    el.firstChild.innerHTML = `<p class="m3d-msg">${CD.ui.esc(e.message)}</p>`;
            });
            return () => {   // the app calls this when the viewer moves on or closes: frees the WebGL context
                gone = true;
                view?.dispose();
            };
        },
    };
})();
