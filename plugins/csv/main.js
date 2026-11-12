/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
'use strict';
// CSV viewer + grid editor. Data from plugins/csv/main.php: { rows: [[cell, …], …], delimiter }.
(() => {
    const { esc, icon, renderSheets, colName } = CD.ui;

    CD.viewers.csv = {
        render(el, data) {
            renderSheets(el, [{ name: 'CSV', rows: data.rows }]);
        },

        edit(el, data) {
            let rows = data.rows.map(r => [...r]), cur = { r: 0, c: 0 }, changed = false;
            if (!rows.length)
                rows = [['']];

            const width = () => rows.reduce((m, r) => Math.max(m, r.length), 1);
            const cell = (r, c) => el.querySelector(`td[data-r="${r}"][data-c="${c}"]`);
            const sync = () => el.querySelectorAll('td[data-r]').forEach(td => {   // DOM → rows
                rows[td.dataset.r][td.dataset.c] = td.innerText.replace(/\n$/, '');
            });
            const focus = (r, c) => {
                const td = cell(r, c);
                if (!td)
                    return;
                td.focus();
                getSelection().selectAllChildren(td);
                getSelection().collapseToEnd();
            };
            const draw = () => {
                const w = width();
                rows.forEach(r => {
                    while (r.length < w)
                        r.push('');
                });
                const head = [...Array(w).keys()].map(c => `<th>${colName(c)}</th>`).join('');
                const body = rows.map((r, ri) => `<tr><th>${ri + 1}</th>${r.map((v, c) => `<td data-r="${ri}" data-c="${c}">${esc(v)}</td>`).join('')}</tr>`).join('');
                $table.innerHTML = `<thead><tr><th></th>${head}</tr></thead><tbody>${body}</tbody>`;
                for (const td of $table.querySelectorAll('td'))
                    td.contentEditable = editableMode;
            };
            const change = fn => {
                sync();
                fn();
                changed = true;
                draw();
                focus(Math.min(cur.r, rows.length - 1), Math.min(cur.c, width() - 1));
            };

            // 'plaintext-only' keeps pasted formatting out of cells; older browsers fall back to 'true'
            const probe = document.createElement('div');
            probe.contentEditable = 'plaintext-only';
            const editableMode = probe.contentEditable === 'plaintext-only' ? 'plaintext-only' : 'true';

            const btn = (act, ic, label) => `<button type="button" class="btn ghost sm" data-csv="${act}" title="${label}">${icon(ic)}<span>${label}</span></button>`;
            el.innerHTML = `<div class="csv-edit"><div class="csv-tools">${btn('row', 'plus', 'Row below')}${btn('col', 'plus', 'Column right')}
                ${btn('delrow', 'x', 'Delete row')}${btn('delcol', 'x', 'Delete column')}
                <small class="muted">Enter: next row · Shift+Enter: new line in cell · Tab: next cell</small></div>
                <div class="sheet"><table class="grid-table csv-grid"></table></div></div>`;
            const $table = el.querySelector('table');
            draw();

            el.querySelector('.csv-tools').onclick = e => {
                const act = e.target.closest('[data-csv]')?.dataset.csv, { r, c } = cur;
                if (act === 'row')
                    change(() => {
                        rows.splice(r + 1, 0, Array(width()).fill(''));
                        cur.r = r + 1;
                    });
                else if (act === 'col')
                    change(() => {
                        rows.forEach(x => x.splice(c + 1, 0, ''));
                        cur.c = c + 1;
                    });
                else if (act === 'delrow')
                    change(() => {
                        rows.splice(r, 1);
                        if (!rows.length)
                            rows.push(['']);
                    });
                else if (act === 'delcol')
                    change(() => {
                        if (width() > 1)
                            rows.forEach(x => x.splice(c, 1));
                    });
            };
            $table.addEventListener('focusin', e => {
                const td = e.target.closest('td[data-r]');
                if (td)
                    cur = { r: +td.dataset.r, c: +td.dataset.c };
            });
            $table.addEventListener('input', () => {
                changed = true;
            });
            $table.addEventListener('keydown', e => {
                const td = e.target.closest('td[data-r]');
                if (!td)
                    return;
                const r = +td.dataset.r, c = +td.dataset.c, multi = td.innerText.trim().includes('\n');
                const go = (dr, dc) => {
                    e.preventDefault();
                    focus(r + dr, c + dc);
                };
                if (e.key === 'Enter' && !e.shiftKey)
                    r + 1 < rows.length ? go(1, 0) : change(() => {   // Enter on the last row adds a row
                        e.preventDefault();
                        rows.push(Array(width()).fill(''));
                        cur = { r: r + 1, c };
                    });
                else if (e.key === 'ArrowUp' && !multi)
                    go(-1, 0);
                else if (e.key === 'ArrowDown' && !multi)
                    go(1, 0);
            });
            // Pasting a block copied from Excel / another sheet (tabs and newlines) fills several cells
            $table.addEventListener('paste', e => {
                const td = e.target.closest('td[data-r]'), text = e.clipboardData?.getData('text/plain') ?? '';
                e.preventDefault();
                if (!td)
                    return;
                if (!/[\t\n]/.test(text.replace(/\r?\n$/, '')))
                    return document.execCommand('insertText', false, text);
                const block = text.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n').map(l => l.split('\t'));
                const r0 = +td.dataset.r, c0 = +td.dataset.c;
                change(() => {
                    block.forEach((line, i) => {
                        while (rows.length <= r0 + i)
                            rows.push([]);
                        line.forEach((v, j) => {
                            const row = rows[r0 + i];
                            while (row.length < c0 + j)
                                row.push('');
                            row[c0 + j] = v;
                        });
                    });
                });
            });
            focus(0, 0);

            return {
                dirty: () => changed,
                value: () => {
                    sync();
                    return { rows };
                },
            };
        },
    };
})();
