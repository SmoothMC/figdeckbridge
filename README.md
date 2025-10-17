# FigDeck Bridge 🧩

**FigDeck Bridge** verbindet [Figma](https://figma.com) mit [Nextcloud Deck](https://apps.nextcloud.com/apps/deck)
und synchronisiert Kommentare, Threads und Webhooks automatisch.

---

## 🚀 Funktionen
- 🔁 Synchronisation von Figma-Kommentaren mit Deck-Karten
- 🔐 OAuth 2.0 Figma-Integration
- 🛠 Admin-Oberfläche mit API- und Sync-Steuerung
- 🕑 Automatische Cron-Synchronisation

---

## 🧱 Voraussetzungen
- Nextcloud 27 – 30
- PHP ≥ 8.1
- Aktivierte App: **Deck**

---

## ⚙️ Installation

```bash
cd apps
git clone https://github.com/zzzooo-studio/figdeckbridge.git
cd figdeckbridge
php occ app:enable figdeckbridge
```

---

## 🛠 Konfiguration

1. **Figma OAuth** – Trage Client-ID und Secret in den Admin-Einstellungen ein und verbinde die App mit Figma.
2. **Deck-Zugriff** – Hinterlege in `config.php` die URL deiner Nextcloud sowie Benutzername und App-Passwort des Deck-Accounts.
3. **Zuordnungen** – Weise Figma-Dateien den passenden Deck-Boards & -Stacks zu. Auf der App-Startseite kannst du per Drop-down Deck-Boards, Listen und Figma-Projekte verknüpfen (die Zuordnung landet in `mappings.json`). Alternativ funktioniert weiterhin die Pflege direkt in `config.php` über `file_mappings`.
4. **Synchronisation** – Starte eine manuelle Synchronisation oder aktiviere den Cron-Modus.

> 💡 Die Datei `poll-comments.php` kann auch auf der Kommandozeile ausgeführt werden. Sie synchronisiert mehrere Figma-Dateien in einem Lauf und erkennt neue Kommentare sowie Antworten automatisch.

---

## 🔄 Wie die Synchronisation funktioniert

1. **Kommentare abrufen** – Für jede konfigurierte Figma-Datei werden offene, ungelöste Kommentar-Threads geladen.
2. **Thread-Verarbeitung** – Neue Kommentare oder Antworten werden erkannt; bereits importierte IDs speichert die App in `last_comments.json`.
3. **Deck aktualisieren** – Für jeden neuen Thread wird eine Karte angelegt (oder wiederverwendet) und alle neuen Beiträge werden als Kommentare in Deck abgelegt. Screenshots aus Figma werden automatisch als Anhang hochgeladen.
4. **Status speichern** – Nach erfolgreicher Synchronisation aktualisiert die App die lokale Statusdatei, sodass Kommentare nicht doppelt übertragen werden.

---

## 🧪 Tests

- Manuelle Synchronisation über die Admin-Oberfläche (`📦 Manuelle Synchronisation`).
- CLI: `php poll-comments.php`
- Syntax-Checks: `./dev/run-tests.sh`

---

## 🧪 Nextcloud-Testumgebung

Für lokale Experimente steht eine Docker-Compose-Umgebung bereit, die Nextcloud samt MariaDB und vorinstallierter Deck-App startet.

1. Stelle sicher, dass [Docker](https://docs.docker.com/get-docker/) und Docker Compose ≥ v2.20 installiert sind.
2. Starte die Umgebung:
   ```bash
   docker compose -f dev/docker-compose.nextcloud.yml up -d
   ```
3. Führe das Setup-Skript aus, um Deck zu aktivieren, ein Demo-Board anzulegen und die Umgebung zu prüfen:
   ```bash
   ./dev/nextcloud-init.sh
   ```
4. Öffne [http://localhost:8080](http://localhost:8080) und melde dich mit `admin` / `admin` an. Lege unter **Persönliche Einstellungen → Sicherheit** ein App-Passwort für FigDeck Bridge an.

> ℹ️ Das Skript kann beliebig oft ausgeführt werden; es ist idempotent und prüft automatisch, ob Deck oder das Demo-Board bereits existieren.

---

## 📄 Lizenz

Veröffentlicht unter der [MIT-Lizenz](LICENSE).
