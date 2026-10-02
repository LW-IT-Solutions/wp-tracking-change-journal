/* Tracking Change Journal: CSV export of the journal and a confirmation before deleting it. */
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

    const clear = document.getElementById('trcj-clear');
    if (clear) {
        clear.addEventListener('submit', function (event) {
            if (!window.confirm(TRCJ.confirm)) {
                event.preventDefault();
            }
        });
    }
})();
