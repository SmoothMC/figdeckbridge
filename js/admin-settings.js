/* global OC */
console.log('[FigDeckBridge] admin-settings.js loaded');

const base = OC.generateUrl('/apps/figdeckbridge/api');
const token = OC.requestToken;

// ---------------------------------------------------------
// Hilfsfunktion: Nextcloud-sicheres Fetch mit CSRF-Token
// ---------------------------------------------------------
async function ncFetch(url, options = {}) {
    options.headers = {
        ...(options.headers || {}),
        'requesttoken': token
    };
    return fetch(url, options);
}

// ---------------------------------------------------------
// UI Statusanzeige
// ---------------------------------------------------------
const statusEl = document.querySelector('#figma-status .status');

function setStatus(connected) {
    if (!statusEl) return;
    if (connected) {
        statusEl.textContent = '✅ Verbunden – Zugriffstoken vorhanden.';
        statusEl.classList.remove('error');
        statusEl.classList.add('success');
    } else {
        statusEl.textContent = '❌ Nicht verbunden';
        statusEl.classList.remove('success');
        statusEl.classList.add('error');
    }
}

// ---------------------------------------------------------
// Figma OAuth Redirect Feedback
// ---------------------------------------------------------
if (window.location.search.includes('connected=1')) {
    alert('✅ Figma wurde erfolgreich verbunden!');
    setStatus(true);
    window.history.replaceState({}, document.title, window.location.pathname);
}
if (window.location.search.includes('error=')) {
    alert('❌ Figma-Verbindung fehlgeschlagen. Bitte überprüfe Client ID und Secret.');
    setStatus(false);
    window.history.replaceState({}, document.title, window.location.pathname);
}

// ---------------------------------------------------------
// Figma-Einstellungen speichern
// ---------------------------------------------------------
document.getElementById('saveFigmaSettings')?.addEventListener('click', async (e) => {
    e.preventDefault();

    const data = {
        client_id: document.getElementById('client_id').value.trim(),
        client_secret: document.getElementById('client_secret').value.trim(),
        mode: document.getElementById('mode')?.value || 'manual'
    };

    try {
        const res = await ncFetch(base + '/save-config', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        if (res.ok) {
            alert('Figma-Einstellungen gespeichert ✅');
        } else {
            alert('Fehler beim Speichern (HTTP ' + res.status + ')');
        }
    } catch (err) {
        alert('Netzwerkfehler: ' + err.message);
    }
});

// ---------------------------------------------------------
// Connect / Reconnect Figma (neues Fenster mit Live-Status)
// ---------------------------------------------------------
// Connect / Reconnect Figma (neues Fenster mit Live-Status)
document.querySelectorAll('#connectFigma, #reconnectFigma').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        e.preventDefault();

        // ✅ OC.generateUrl() liefert nur relative Pfade → wir bauen absolute URL:
        const relative = OC.generateUrl('apps/figdeckbridge/api/figma/oauth/start');
        const absolute = window.location.origin + relative;

        console.log('[FigDeckBridge] Opening OAuth URL:', absolute);

        // Neues Fenster öffnen
        const popup = window.open(absolute, '_blank', 'width=800,height=700,noopener,noreferrer');

        if (!popup) {
            alert('Bitte Pop-ups erlauben, um die Figma-Verbindung zu starten.');
            return;
        }

        // Zeige im UI "Verbindung läuft..."
        const statusEl = document.querySelector('#figma-status .status');
        if (statusEl) {
            statusEl.textContent = '⏳ Verbindung zu Figma wird hergestellt...';
            statusEl.classList.remove('success', 'error');
        }

        // Überprüfe alle 3 Sekunden, ob die Verbindung steht
        const checkInterval = setInterval(async () => {
            try {
                const res = await ncFetch(base + '/get-config');
                if (!res.ok) return;
                const data = await res.json();

                if (data.connected) {
                    clearInterval(checkInterval);
                    if (!popup.closed) popup.close();
                    alert('✅ Figma erfolgreich verbunden!');
                    setStatus(true);
                    location.reload();
                }
            } catch (err) {
                console.warn('[FigDeckBridge] Status-Check fehlgeschlagen:', err);
            }
        }, 3000);
    });
});

// ---------------------------------------------------------
// Disconnect Figma
// ---------------------------------------------------------
document.getElementById('disconnectFigma')?.addEventListener('click', async () => {
    if (!confirm('Figma-Verbindung wirklich trennen?')) return;
    await ncFetch(base + '/figma/disconnect', {method: 'POST'});
    alert('🔌 Verbindung getrennt.');
    setStatus(false);
});

// ---------------------------------------------------------
// Modus speichern
// ---------------------------------------------------------
document.getElementById('saveMode')?.addEventListener('click', async (e) => {
    e.preventDefault();
    const mode = document.getElementById('mode').value;
    const res = await ncFetch(base + '/save-config', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({mode})
    });
    alert(res.ok ? 'Synchronisationsmodus gespeichert ✅' : 'Fehler beim Speichern.');
});

// ---------------------------------------------------------
// Manuelle Synchronisation
// ---------------------------------------------------------
document.getElementById('manualSync')?.addEventListener('click', async () => {
    const res = await ncFetch(base + '/manual-sync', {method: 'POST'});
    alert(res.ok ? 'Synchronisation gestartet ✅' : 'Fehler beim Starten.');
});

// ---------------------------------------------------------
// Initiale Einstellungen + Verbindungsstatus abrufen
// ---------------------------------------------------------
(async () => {
    try {
        const res = await ncFetch(base + '/get-config');
        if (!res.ok) throw new Error('HTTP ' + res.status);

        const data = await res.json();

        // 🟢 Fülle die Felder im UI
        if (data.client_id !== undefined) {
            document.getElementById('client_id').value = data.client_id;
        }
        if (data.client_secret !== undefined && data.client_secret !== '••••••••') {
            document.getElementById('client_secret').value = data.client_secret;
        }
        if (data.mode !== undefined && document.getElementById('mode')) {
            document.getElementById('mode').value = data.mode;
        }

        // 🔵 Aktualisiere Statusanzeige
        setStatus(!!data.connected);

        console.log('[FigDeckBridge] Loaded config:', data);

    } catch (e) {
        console.warn('[FigDeckBridge] get-config fehlgeschlagen:', e);
        setStatus(false);
    }
})();
