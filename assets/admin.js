/* Tracking Change Journal: CSV export, busy state and progress of the check, page counter, chart with period switch
   and tooltips, journal search and filters, copying an entry, and a confirmation dialog before deleting the journal.
   Nothing is requested; without JavaScript every value stays in the HTML. */
(function () {
    'use strict';
    if (typeof TRCJ === 'undefined') {
        return;
    }
    const still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const all = function (sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    };

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

    function fmt(text) {
        const args = Array.prototype.slice.call(arguments, 1);
        let next = 0;
        return text.replace(/%(?:(\d)\$)?[sd]/g, function (_, n) {
            return String(args[n ? n - 1 : next++]);
        });
    }

    // "Check now" can take up to a minute: show that it runs and prevent a second submit.
    const check = document.getElementById('trcj-check');
    if (check) {
        check.addEventListener('submit', function (event) {
            const submit = document.getElementById('trcj-check-button');
            if (submit.getAttribute('aria-busy') === 'true') {
                event.preventDefault();
                return;
            }
            submit.setAttribute('aria-busy', 'true');
            submit.classList.add('trcj-busy');
            submit.querySelector('.trcj-check-label').textContent = TRCJ.text.checking;
            document.getElementById('trcj-spinner').classList.add('is-active');
            // Progress card: the pages being loaded and the seconds since the start. The timer ends with the reload.
            const running = document.getElementById('trcj-running');
            const elapsed = document.getElementById('trcj-elapsed');
            if (running) {
                running.hidden = false;
                const t0 = Date.now();
                const tick = function () {
                    elapsed.textContent = fmt(TRCJ.text.elapsed, Math.round((Date.now() - t0) / 1000));
                };
                tick();
                window.setInterval(tick, 1000);
            }
        });
    }

    // Tiles count up once.
    if (!still && window.requestAnimationFrame) {
        const tiles = all('.trcj-tile-n[data-count]');
        const t0 = performance.now();
        const ends = tiles.map(function (n) {
            return parseInt(n.getAttribute('data-count'), 10) || 0;
        });
        const step = function (now) {
            const p = Math.min(1, Math.max(0, (now - t0) / 700));
            const e = 1 - Math.pow(1 - p, 3);
            tiles.forEach(function (n, i) {
                n.textContent = String(Math.round(ends[i] * e));
            });
            if (p < 1) {
                window.requestAnimationFrame(step);
            }
        };
        if (tiles.length) {
            window.requestAnimationFrame(step);
        }
    }

    // Number of entered pages next to the page list.
    const pages = document.getElementById('trcj-pages');
    const counter = document.getElementById('trcj-pages-count');
    if (pages && counter) {
        const count = function () {
            const n = pages.value.split(/\r?\n/).filter(function (line) {
                return line.trim() !== '';
            }).length;
            counter.textContent = fmt(TRCJ.text.lines, n, TRCJ.text.max);
            counter.classList.toggle('trcj-over', n > TRCJ.text.max);
        };
        pages.addEventListener('input', count);
        count();
    }

    // Journal: "only entries with changes", kind of change, a day from the chart and the search work together.
    const only = document.getElementById('trcj-only-changed');
    const list = document.getElementById('trcj-journal');
    const info = document.getElementById('trcj-journal-count');
    const search = document.getElementById('trcj-search');
    const tools = document.getElementById('trcj-jtools');
    const state = {only: false, kind: '', day: '', dayLabel: '', q: ''};
    const items = list ? all(':scope > li', list) : [];
    const kindOf = {
        added: 'li.trcj-change-added',
        removed: 'li.trcj-change-removed',
        consent: 'li.trcj-change-consent',
        bad: 'li.trcj-change-bad',
        parts: '.trcj-components li'
    };
    const texts = items.map(function (item) {
        return all('.trcj-when strong, .trcj-reason, .trcj-components li, .trcj-path, .trcj-changes li', item);
    });
    const originals = texts.map(function (nodes) {
        return nodes.map(function (n) {
            return n.innerHTML;
        });
    });

    function mark(node, q) {
        const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null);
        const hits = [];
        let n;
        while ((n = walker.nextNode())) {
            if (n.nodeValue.toLowerCase().indexOf(q) !== -1) {
                hits.push(n);
            }
        }
        hits.forEach(function (t) {
            const frag = document.createDocumentFragment();
            const low = t.nodeValue.toLowerCase();
            let i = 0;
            let at;
            while ((at = low.indexOf(q, i)) !== -1) {
                frag.appendChild(document.createTextNode(t.nodeValue.slice(i, at)));
                const m = document.createElement('mark');
                m.className = 'trcj-hit';
                m.textContent = t.nodeValue.slice(at, at + q.length);
                frag.appendChild(m);
                i = at + q.length;
            }
            frag.appendChild(document.createTextNode(t.nodeValue.slice(i)));
            t.parentNode.replaceChild(frag, t);
        });
    }

    function applyJournal() {
        const q = state.q.trim().toLowerCase();
        let shown = 0;
        items.forEach(function (item, i) {
            texts[i].forEach(function (node, k) {
                node.innerHTML = originals[i][k];
            });
            const show = (!state.only || item.classList.contains('trcj-changed')) &&
                (!state.kind || !!item.querySelector(kindOf[state.kind])) &&
                (!state.day || item.getAttribute('data-day') === state.day) &&
                (!q || item.textContent.toLowerCase().indexOf(q) !== -1);
            item.hidden = !show;
            if (show) {
                shown++;
                if (q) {
                    texts[i].forEach(function (node) {
                        mark(node, q);
                    });
                }
            }
        });
        all('.trcj-kind').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-kind') === state.kind ? 'true' : 'false');
        });
        all('.trcj-day').forEach(function (g) {
            g.classList.toggle('is-active', !!state.day && g.getAttribute('data-day') === state.day);
        });
        const chart = document.querySelector('.trcj-chart');
        if (chart) {
            chart.classList.toggle('has-day', !!state.day);
        }
        if (info) {
            const filtered = state.only || state.kind || state.day || q;
            info.textContent = !filtered ? '' : (shown ? fmt(TRCJ.text.shown, shown, items.length) + (state.day ? ' ' + fmt(TRCJ.text.day, state.dayLabel) : '') : TRCJ.text.none);
        }
    }

    if (list) {
        const changed = list.querySelectorAll(':scope > li.trcj-changed').length;
        if (only && changed && changed < items.length) {
            only.hidden = false;
        }
        if (only) {
            only.addEventListener('click', function () {
                state.only = only.getAttribute('aria-pressed') !== 'true';
                only.setAttribute('aria-pressed', state.only ? 'true' : 'false');
                applyJournal();
            });
        }
        if (tools && items.length > 1) {
            tools.hidden = false;
        }
        all('.trcj-kind').forEach(function (b) {
            const key = b.getAttribute('data-kind');
            const n = items.filter(function (item) {
                return !!item.querySelector(kindOf[key]);
            }).length;
            if (n) {
                b.hidden = false;
                b.querySelector('.trcj-kind-n').textContent = String(n);
            }
            b.addEventListener('click', function () {
                state.kind = state.kind === key ? '' : key;
                applyJournal();
            });
        });
        if (search) {
            search.addEventListener('input', function () {
                state.q = search.value;
                applyJournal();
            });
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && search.value) {
                    e.preventDefault();
                    search.value = '';
                    state.q = '';
                    applyJournal();
                }
            });
            document.addEventListener('keydown', function (e) {
                const t = e.target;
                const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
                if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey && tools && !tools.hidden && !document.querySelector('.trcj-dialog')) {
                    e.preventDefault();
                    search.focus();
                    search.select();
                }
            });
        }

        // Copy one entry as plain text, for a ticket or a message.
        items.forEach(function (item) {
            const button = item.querySelector('.trcj-copy');
            if (!button || !navigator.clipboard || !window.isSecureContext) {
                return;
            }
            button.hidden = false;
            const label = button.querySelector('.trcj-copy-label');
            button.addEventListener('click', function () {
                const lines = [];
                const when = item.querySelector('.trcj-when strong');
                const reason = item.querySelector('.trcj-reason');
                lines.push((when ? when.textContent.trim() : '') + (reason ? ' – ' + reason.textContent.trim() : ''));
                all('.trcj-components li', item).forEach(function (li) {
                    lines.push('  * ' + li.textContent.trim());
                });
                all(':scope > .trcj-path, :scope > .trcj-changes', item).forEach(function (node) {
                    if (node.classList.contains('trcj-path')) {
                        lines.push(node.textContent.trim());
                    } else {
                        all('li', node).forEach(function (li) {
                            const kind = (li.className.match(/trcj-change-(\w+)/) || [])[1];
                            const sign = kind === 'added' ? '+' : (kind === 'removed' ? '-' : '~');
                            lines.push('  ' + sign + ' ' + li.textContent.trim());
                        });
                    }
                });
                navigator.clipboard.writeText(lines.join('\n') + '\n').then(function () {
                    label.textContent = TRCJ.text.copied;
                    button.classList.remove('is-done');
                    void button.offsetWidth;
                    button.classList.add('is-done');
                    window.setTimeout(function () {
                        label.textContent = TRCJ.text.copy;
                        button.classList.remove('is-done');
                    }, 2000);
                }, function () {});
            });
        });
    }

    // Chart: switch between 7 and 30 days, tooltips on hover and keyboard focus, a day filters the journal.
    const chart = document.querySelector('.trcj-chart');
    if (chart) {
        const svg = chart.querySelector('svg.trcj-bars');
        const range = chart.querySelector('.trcj-range');
        const summaryNode = chart.querySelector('.trcj-chart-summary');
        const width = parseFloat(chart.getAttribute('data-width')) || 600;
        const stepW = parseFloat(chart.getAttribute('data-step')) || 20;
        const height = svg ? svg.viewBox.baseVal.height : 128;
        const days = all('.trcj-day', chart);
        const tip = document.createElement('div');
        tip.className = 'trcj-tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.appendChild(tip);
        const showTip = function (g) {
            tip.textContent = g.getAttribute('data-label');
            tip.hidden = false;
            const r = g.querySelector('.trcj-hit').getBoundingClientRect();
            const w = tip.offsetWidth;
            const x = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, document.documentElement.clientWidth - w - 8));
            tip.style.left = (x + window.scrollX) + 'px';
            tip.style.top = (r.top + window.scrollY - tip.offsetHeight - 8) + 'px';
        };
        const hideTip = function () {
            tip.hidden = true;
        };
        days.forEach(function (g) {
            const t = g.querySelector('title');
            if (t) {
                t.parentNode.removeChild(t);
            }
            const n = parseInt(g.getAttribute('data-n'), 10) || 0;
            g.setAttribute('aria-label', g.getAttribute('data-label'));
            if (n && list) {
                g.setAttribute('role', 'button');
                g.setAttribute('aria-controls', 'trcj-journal');
                g.classList.add('has-entries');
            }
            g.setAttribute('tabindex', '0');
            g.addEventListener('mouseenter', function () { showTip(g); });
            g.addEventListener('mouseleave', hideTip);
            g.addEventListener('focus', function () { showTip(g); });
            g.addEventListener('blur', hideTip);
            const pick = function () {
                if (!n || !list) {
                    return;
                }
                const same = state.day === g.getAttribute('data-day');
                state.day = same ? '' : g.getAttribute('data-day');
                state.dayLabel = same ? '' : g.getAttribute('data-date');
                applyJournal();
                if (!same) {
                    list.scrollIntoView({block: 'start', behavior: still ? 'auto' : 'smooth'});
                }
            };
            g.addEventListener('click', pick);
            g.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    pick();
                } else if (e.key === 'Escape') {
                    hideTip();
                }
            });
        });
        if (svg) {
            svg.setAttribute('role', 'group');
        }
        const setRange = function (count) {
            const x = width - count * stepW;
            if (svg) {
                svg.setAttribute('viewBox', x.toFixed(2) + ' 0 ' + (count * stepW).toFixed(2) + ' ' + height);
                svg.classList.toggle('is-7', count === 7);
            }
            days.forEach(function (g, i) {
                const inRange = i >= days.length - count;
                g.setAttribute('tabindex', inRange ? '0' : '-1');
                g.setAttribute('aria-hidden', inRange ? 'false' : 'true');
            });
            all('.trcj-chart-axis span[data-d7]', chart).forEach(function (span) {
                span.textContent = span.getAttribute(count === 7 ? 'data-d7' : 'data-d30');
            });
            if (summaryNode) {
                summaryNode.textContent = chart.getAttribute(count === 7 ? 'data-summary7' : 'data-summary30');
            }
            all('.trcj-range-button', chart).forEach(function (b) {
                b.setAttribute('aria-pressed', parseInt(b.getAttribute('data-days'), 10) === count ? 'true' : 'false');
            });
        };
        if (range && svg) {
            range.hidden = false;
            all('.trcj-range-button', range).forEach(function (b) {
                b.addEventListener('click', function () {
                    setRange(parseInt(b.getAttribute('data-days'), 10));
                });
            });
        }
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
