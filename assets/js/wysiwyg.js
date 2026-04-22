/**
 * Minimal WYSIWYG editor.
 *
 * Usage: add `data-wysiwyg` to any <textarea>. The textarea is hidden and
 * replaced with a contenteditable div + toolbar; the textarea's value is
 * kept in sync on input so normal form submission just works.
 */
(function () {
    'use strict';

    var BUTTONS = [
        { cmd: 'bold',           icon: 'bi-type-bold',        title: 'Bold' },
        { cmd: 'italic',         icon: 'bi-type-italic',      title: 'Italic' },
        { cmd: 'underline',      icon: 'bi-type-underline',   title: 'Underline' },
        { sep: true },
        { cmd: 'formatBlock', arg: 'H2', icon: 'bi-type-h2',  title: 'Heading 2' },
        { cmd: 'formatBlock', arg: 'H3', icon: 'bi-type-h3',  title: 'Heading 3' },
        { cmd: 'formatBlock', arg: 'P',  icon: 'bi-paragraph',title: 'Paragraph' },
        { sep: true },
        { cmd: 'insertUnorderedList', icon: 'bi-list-ul',     title: 'Bulleted list' },
        { cmd: 'insertOrderedList',   icon: 'bi-list-ol',     title: 'Numbered list' },
        { sep: true },
        { cmd: 'createLink',     icon: 'bi-link-45deg',       title: 'Insert link',  prompt: 'URL:' },
        { cmd: 'unlink',         icon: 'bi-link',             title: 'Remove link' },
        { sep: true },
        { cmd: 'removeFormat',   icon: 'bi-eraser',           title: 'Clear formatting' },
    ];

    function makeToolbar(editor) {
        var bar = document.createElement('div');
        bar.className = 'wysiwyg-toolbar btn-toolbar border rounded-top bg-body-tertiary p-1 d-flex flex-wrap gap-1';
        bar.setAttribute('role', 'toolbar');

        BUTTONS.forEach(function (b) {
            if (b.sep) {
                var s = document.createElement('span');
                s.className = 'vr mx-1';
                bar.appendChild(s);
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary border-0';
            btn.title = b.title;
            btn.innerHTML = '<i class="bi ' + b.icon + '"></i>';
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function () {
                editor.focus();
                var arg = b.arg || null;
                if (b.prompt) {
                    var v = window.prompt(b.prompt, 'https://');
                    if (!v) return;
                    arg = v;
                }
                try { document.execCommand(b.cmd, false, arg); } catch (e) { /* noop */ }
                syncTextarea(editor);
            });
            bar.appendChild(btn);
        });
        return bar;
    }

    function syncTextarea(editor) {
        var ta = editor._textarea;
        if (ta) ta.value = editor.innerHTML;
    }

    function init(ta) {
        if (ta._wysiwygInit) return;
        ta._wysiwygInit = true;

        var wrap = document.createElement('div');
        wrap.className = 'wysiwyg-wrap';

        var editor = document.createElement('div');
        editor.className = 'wysiwyg-editor form-control border-top-0 rounded-top-0';
        editor.contentEditable = 'true';
        editor.innerHTML = ta.value || '';
        editor._textarea = ta;

        var minHeight = (parseInt(ta.getAttribute('rows') || '10', 10) * 1.5) + 'rem';
        editor.style.minHeight = minHeight;

        var toolbar = makeToolbar(editor);

        editor.addEventListener('input', function () { syncTextarea(editor); });
        editor.addEventListener('blur',  function () { syncTextarea(editor); });

        // Keep native validation working: if textarea is required, make sure
        // we clear it when the editor is empty so the browser blocks submit.
        var form = ta.form;
        if (form) {
            form.addEventListener('submit', function () { syncTextarea(editor); });
        }

        ta.style.display = 'none';
        ta.parentNode.insertBefore(wrap, ta);
        wrap.appendChild(toolbar);
        wrap.appendChild(editor);
        wrap.appendChild(ta);
    }

    function boot(root) {
        (root || document).querySelectorAll('textarea[data-wysiwyg]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(); });
    } else {
        boot();
    }

    // Re-scan after HTMX swaps content in.
    document.body && document.body.addEventListener('htmx:afterSwap', function (e) { boot(e.target); });

    window.ScoutKeeperWysiwyg = { init: init, boot: boot };
})();
