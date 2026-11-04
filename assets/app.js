/*
 * Wunsch-Details im Modal-Dialog.
 * Ohne JavaScript bleibt alles bedienbar: die Karten verlinken ganz normal
 * auf gift.php, die Formulare dort werden klassisch abgeschickt.
 */
(function () {
    'use strict';

    var modal = document.getElementById('gift-modal');
    var body  = document.getElementById('gift-modal-body');
    if (!modal || !body || typeof modal.showModal !== 'function') {
        return; // Alter Browser: normale Seiten-Navigation
    }

    var needsRefresh = false;   // nach einer Reservation die Liste neu laden
    var currentUrl   = '';
    var pollTimer    = null;
    var POLL_MS      = 10000;   // alle 10 Sekunden nachfragen, ob der Wunsch noch frei ist

    function partialUrl(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'partial=1';
    }

    function setContent(html) {
        body.innerHTML = html;
        body.scrollTop = 0;
        var focusable = body.querySelector('input, button, a');
        if (focusable) {
            focusable.focus({ preventScroll: true });
        }
        syncPolling();
    }

    /* ----- Überwachen, ob der Wunsch noch frei ist ----- */

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function syncPolling() {
        stopPolling();
        var inner = body.querySelector('.detail-inner');
        if (inner && inner.getAttribute('data-available') === '1') {
            pollTimer = setInterval(checkAvailability, POLL_MS);
        }
    }

    function checkUrl(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'check=1';
    }

    function checkAvailability() {
        if (!modal.open || !currentUrl) {
            stopPolling();
            return;
        }
        if (document.hidden) {
            return;                 // im Hintergrund keine Anfragen
        }
        fetch(checkUrl(currentUrl), {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (daten) {
                if (!daten) {
                    return;
                }
                if (!daten.available) {
                    stopPolling();
                    needsRefresh = true;
                    zeigeVergriffen();
                } else {
                    aktualisiereAnzahl(daten);
                }
            })
            .catch(function () { /* Netz weg: beim nächsten Versuch wieder */ });
    }

    /* Jemand anderes war schneller: Inhalt neu zeichnen und sagen, was los ist. */
    function zeigeVergriffen() {
        fetch(partialUrl(currentUrl), {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                setContent('<div class="flash flash-error"><strong>Gerade zu spät:</strong> '
                    + 'Jemand anderes hat diesen Wunsch soeben übernommen.</div>' + html);
            })
            .catch(function () {});
    }

    /* Bei mehrfach schenkbaren Wünschen die Zahl mitführen. */
    function aktualisiereAnzahl(daten) {
        if (!daten.quantity || daten.quantity <= 1) {
            return;
        }
        var tags = body.querySelectorAll('.meta-tag');
        for (var i = 0; i < tags.length; i++) {
            if (tags[i].textContent.indexOf('reserviert') !== -1) {
                tags[i].textContent = daten.taken + ' von ' + daten.quantity + ' reserviert';
            }
        }
    }

    function showLoading(title) {
        body.innerHTML = '<p class="modal-loading">' + (title ? escapeHtml(title) + ' … ' : '') + '🎁</p>';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function openGift(url, title) {
        currentUrl = url;
        showLoading(title);
        if (!modal.open) {
            modal.showModal();
        }
        fetch(partialUrl(url), { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then(function (res) { return res.text(); })
            .then(setContent)
            .catch(function () { window.location.href = url; });
    }

    /* Karten öffnen den Dialog statt der Detailseite */
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-gift-link]') : null;
        if (!link) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return; // in neuem Tab öffnen weiterhin möglich
        }
        event.preventDefault();
        openGift(link.getAttribute('href'), link.getAttribute('data-title') || '');
    });

    /* Formulare im Dialog ohne Seitenwechsel abschicken */
    body.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }
        event.preventDefault();
        stopPolling();

        var data = new FormData(form);
        data.append('partial', '1');
        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Einen Moment …';
        }

        fetch(form.getAttribute('action') || currentUrl, {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                needsRefresh = true;
                setContent(html);
            })
            .catch(function () { form.submit(); });
    });

    /* Schliessen: X, Klick auf den Hintergrund, Escape (macht <dialog> selbst) */
    modal.addEventListener('click', function (event) {
        if (event.target.closest('[data-modal-close]') || event.target === modal) {
            modal.close();
        }
    });

    modal.addEventListener('close', function () {
        stopPolling();
        body.innerHTML = '';
        if (needsRefresh) {
            window.location.reload();   // reservierte Wünsche in der Liste nachführen
        }
    });
})();

/*
 * Suche: tippt man, wird die Trefferliste nach 200 ms nachgeladen,
 * ohne Seitenwechsel. Ohne JavaScript bleibt der Knopf «Suchen».
 */
(function () {
    'use strict';

    var form    = document.getElementById('filter-form');
    var input   = form && form.querySelector('input[name="q"]');
    var results = document.getElementById('results');
    var clear   = form && form.querySelector('[data-search-clear]');
    if (!form || !input || !results || !window.fetch) {
        return;
    }

    document.body.classList.add('js-search');   // blendet den Suchen-Knopf aus

    var timer   = null;
    var running = null;
    var DELAY   = 200;

    function params() {
        var p = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
            if (String(value) !== '') {
                p.append(key, value);
            }
        });
        return p;
    }

    function update() {
        var p = params();
        if (running) {
            running.abort();
        }
        running = typeof AbortController === 'function' ? new AbortController() : null;
        results.classList.add('is-loading');

        var query = p.toString();
        fetch('index.php?' + query + (query ? '&' : '') + 'partial=list', {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
            signal: running ? running.signal : undefined,
        })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                results.innerHTML = html;
                results.classList.remove('is-loading');
                if (window.history && history.replaceState) {
                    history.replaceState(null, '', 'index.php' + (query ? '?' + query : ''));
                }
            })
            .catch(function (err) {
                if (!err || err.name !== 'AbortError') {
                    results.classList.remove('is-loading');
                    form.submit();          // im Zweifel ganz normal abschicken
                }
            });
    }

    var reset = document.getElementById('filter-reset');

    function toggleClear() {
        if (clear) {
            clear.hidden = input.value === '';
        }
        if (reset) {
            // sichtbar, sobald irgendetwas gefiltert ist
            var p = params();
            p.delete('l');
            if (p.get('status') === 'available') {
                p.delete('status');
            }
            reset.hidden = p.toString() === '';
        }
    }

    input.addEventListener('input', function () {
        toggleClear();
        clearTimeout(timer);
        timer = setTimeout(update, DELAY);
    });

    // Das kleine x im Feld (Safari/Chrome) löst 'search' aus, sofort aktualisieren.
    input.addEventListener('search', function () {
        toggleClear();
        clearTimeout(timer);
        update();
    });

    if (clear) {
        clear.addEventListener('click', function () {
            input.value = '';
            toggleClear();
            clearTimeout(timer);
            update();
            input.focus();
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearTimeout(timer);
        update();
        input.blur();              // schliesst die Tastatur auf dem Handy
    });

    toggleClear();
})();

/*
 * Bild aus der Zwischenablage ins Wunsch-Formular einfügen.
 * Praktisch bei Shops, die keine automatische Abfrage zulassen:
 * Bild im Shop kopieren, hier einfügen, fertig.
 */
(function () {
    'use strict';

    var datei = document.getElementById('image_file');
    if (!datei || typeof DataTransfer !== 'function') {
        return;
    }
    var hinweis  = document.querySelector('[data-paste-hint]');
    var vorschau = document.querySelector('[data-paste-preview]');
    if (hinweis) {
        hinweis.hidden = false;
    }

    document.addEventListener('paste', function (event) {
        var daten = event.clipboardData;
        if (!daten || !daten.items) {
            return;
        }
        for (var i = 0; i < daten.items.length; i++) {
            var eintrag = daten.items[i];
            if (eintrag.kind !== 'file' || eintrag.type.indexOf('image/') !== 0) {
                continue;
            }
            var bild = eintrag.getAsFile();
            if (!bild) {
                continue;
            }
            var endung = (eintrag.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
            var liste  = new DataTransfer();
            liste.items.add(new File([bild], 'eingefuegt.' + endung, { type: eintrag.type }));
            datei.files = liste.files;

            if (vorschau) {
                var img = vorschau.querySelector('img');
                if (img) {
                    img.src = URL.createObjectURL(bild);
                }
                vorschau.hidden = false;
            }
            event.preventDefault();
            return;
        }
    });
})();
