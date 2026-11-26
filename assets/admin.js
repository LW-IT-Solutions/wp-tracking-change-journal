/* Tracking Change Journal: CSV export of the journal and a confirmation dialog before deleting it. */
(function () {
    'use strict';
    if (typeof TRCJ === 'undefined') {
        return;
    }

    // Quote every cell; a leading = + - @ would start a formula in spreadsheet programs.
    function cell(value) {
        let text = String(value === null || value === undefined ? '' : value);
        if (/^[=+\-@\t\r]/.test(text)) {
            text = "'" + text;
        }
        return '"' + text.replace(/"/g, '""') + '"';
    }

    const button = document.getElementById('trcj-csv');
    if (button) {
        button.addEventListener('click', function () {
            const lines = [TRCJ.head].concat(TRCJ.rows).map(function (row) {
                return row.map(cell).join(',');
            });
            const blob = new Blob(['﻿' + lines.join('\r\n')], {type: 'text/csv;charset=utf-8'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'tracking-change-journal.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () {
                URL.revokeObjectURL(url);
            }, 1000);
        });
    }

    /* Confirmation in a modal dialog of the page. The native dialog keeps the focus inside, closes on Escape and
       returns the focus to the button that opened it; Enter activates the focused button. */
    function confirmDialog(texts, onConfirm) {
        const dialog = document.createElement('dialog');
        dialog.className = 'trcj-dialog';
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'trcj-dialog-title');
        dialog.setAttribute('aria-describedby', 'trcj-dialog-text');
        const heading = document.createElement('h2');
        heading.id = 'trcj-dialog-title';
        heading.textContent = texts.title;
        const message = document.createElement('p');
        message.id = 'trcj-dialog-text';
        message.textContent = texts.text;
        const actions = document.createElement('p');
        actions.className = 'trcj-dialog-actions';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'button';
        cancel.textContent = texts.cancel;
        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'button button-primary';
        ok.textContent = texts.ok;
        actions.append(cancel, ok);
        dialog.append(heading, message, actions);
        cancel.addEventListener('click', function () {
            dialog.close('cancel');
        });
        ok.addEventListener('click', function () {
            dialog.close('ok');
        });
        dialog.addEventListener('close', function () {
            dialog.remove();
            if (dialog.returnValue === 'ok') {
                onConfirm();
            }
        });
        document.body.appendChild(dialog);
        dialog.showModal();
        ok.focus();
    }

    const clear = document.getElementById('trcj-clear');
    if (clear) {
        clear.addEventListener('submit', function (event) {
            event.preventDefault();
            confirmDialog(TRCJ.dialog, function () {
                // The form has a field named "submit", which hides form.submit(); call the method directly.
                HTMLFormElement.prototype.submit.call(clear);
            });
        });
    }
})();
