/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 08-Nov-2026
 */
'use strict';
// RTF editor: a contenteditable page with a small toolbar. Viewing uses the built-in HTML renderer.
// Data from plugins/rtf/main.php: { html, lossy: ['pictures', …] }. Saves { html }; the server writes RTF.
(() => {
    const { esc, dialog, sanitize } = CD.ui;
    const SIZES = [8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36];
    const TOOLS = [
        ['bold', '<b>B</b>', 'Bold (Ctrl+B)'], ['italic', '<i>I</i>', 'Italic (Ctrl+I)'], ['underline', '<u>U</u>', 'Underline (Ctrl+U)'],
        ['strikeThrough', '<s>S</s>', 'Strikethrough'], ['superscript', 'x²', 'Superscript'], ['subscript', 'x₂', 'Subscript'], '|',
        ['justifyLeft', '⯇', 'Align left'], ['justifyCenter', '≡', 'Centre'], ['justifyRight', '⯈', 'Align right'], ['justifyFull', '☰', 'Justify'], '|',
        ['insertUnorderedList', '•', 'Bulleted list'], ['insertOrderedList', '1.', 'Numbered list'], '|',
        ['removeFormat', '⌫', 'Clear formatting'],
    ];

    CD.viewers.rtf = {
        async edit(el, data) {
            if (data.lossy?.length) {
                const list = data.lossy.join(', ');
                const ok = await dialog({
                    title: 'Edit this document?', ok: 'Edit anyway',
                    body: `<p>This document contains <b>${esc(list)}</b>. The editor can’t keep these: they will be removed or turned into plain paragraphs when you save.</p>
                        <p class="muted">Tip: make a copy first (Copy to…) if you need the original.</p>`,
                });
                if (!ok)
                    return null;
            }

            const tb = TOOLS.map(t => (t === '|' ? '<i class="sep"></i>' : `<button type="button" data-cmd="${t[0]}" title="${t[2]}">${t[1]}</button>`)).join('');
            el.innerHTML = `<div class="rtf-edit"><div class="rtf-tools">${tb}<i class="sep"></i>
                <select data-size title="Font size"><option value="">Size</option>${SIZES.map(s => `<option>${s}</option>`).join('')}</select>
                <label class="rtf-color" title="Text colour">A<input type="color" data-color value="#c00000"></label>
                <label class="rtf-color hl" title="Highlight">H<input type="color" data-hl value="#ffff00"></label></div>
                <div class="pbody"><div class="doc" contenteditable="true" spellcheck="true"></div></div></div>`;
            const page = el.querySelector('.doc'), tools = el.querySelector('.rtf-tools');
            page.append(sanitize(data.html || '<p><br></p>'));
            if (!page.firstChild)
                page.innerHTML = '<p><br></p>';
            let changed = false, range = null;

            const exec = (cmd, value = null) => {
                if (range) {
                    const s = getSelection();
                    s.removeAllRanges();
                    s.addRange(range);
                }
                page.focus();
                document.execCommand('styleWithCSS', false, cmd === 'hiliteColor');
                document.execCommand(cmd, false, value);
                changed = true;
            };
            const keep = () => {   // remember the selection so toolbar inputs (which take focus) apply to it
                if (!page.isConnected)
                    return document.removeEventListener('selectionchange', keep);   // viewer closed
                const s = getSelection();
                if (s.rangeCount && page.contains(s.getRangeAt(0).commonAncestorContainer))
                    range = s.getRangeAt(0).cloneRange();
            };
            document.addEventListener('selectionchange', keep);

            document.execCommand('defaultParagraphSeparator', false, 'p');
            tools.addEventListener('mousedown', e => {
                if (e.target.closest('button'))
                    e.preventDefault();   // keep the selection in the page
            });
            tools.addEventListener('click', e => {
                const b = e.target.closest('[data-cmd]');
                if (b)
                    exec(b.dataset.cmd);
            });
            tools.querySelector('[data-size]').addEventListener('change', e => {
                const pt = e.target.value;
                e.target.value = '';
                if (!pt)
                    return;
                exec('fontSize', '7');   // then swap the <font size=7> it creates for an exact point size
                for (const f of page.querySelectorAll('font[size="7"]')) {
                    const span = document.createElement('span');
                    span.style.fontSize = `${pt}pt`;
                    span.append(...f.childNodes);
                    f.replaceWith(span);
                }
            });
            tools.querySelector('[data-color]').addEventListener('input', e => exec('foreColor', e.target.value));
            tools.querySelector('[data-hl]').addEventListener('input', e => exec('hiliteColor', e.target.value));
            page.addEventListener('input', () => {
                changed = true;
            });
            page.addEventListener('paste', e => {   // plain text only: foreign HTML can't be written back as RTF
                e.preventDefault();
                document.execCommand('insertText', false, e.clipboardData?.getData('text/plain') ?? '');
            });
            page.focus();

            return { dirty: () => changed, value: () => ({ html: page.innerHTML }) };
        },
    };
})();
