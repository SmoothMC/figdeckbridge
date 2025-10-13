# FigDeck Bridge – Changelog

## 🆕 Version 0.6.1 – (Oktober 2025)
**Cron-Integration & Stabilitäts-Update**

### ✨ Neue Funktionen
- Automatische Registrierung eines Cron-Jobs (`SyncJob`)
- Führt `poll-comments.php` regelmäßig aus (Standard: alle 15 Minuten)
- Verbesserte Admin-UI:
  - Anzeige & Steuerung des Synchronisationsmodus  
  - Figma-Reconnect-Button  
- Vorbereitung für Mapping-UI (mehrere Boards/Projekte)

### 🛠 Technische Änderungen
- Neue Datei: `appinfo/app.php` – initialisiert App und Cron-Job
- Neue Klasse: `lib/Cron/SyncJob.php`
- `CHANGELOG.md` jetzt Teil der App-Distribution
- Kleinere Refactorings in Controller und Admin-Template

---

## 🆕 Version 0.6.0 – (Oktober 2025)
**Großes Update: Nextcloud App mit Figma OAuth und Admin-UI**

### ✨ Neue Funktionen
- Vollständige Integration als Nextcloud-App
- OAuth 2.0 Verbindung zu Figma (Login + Token-Refresh)
- Admin-Bereich mit:
  - Client-ID / Client-Secret Konfiguration
  - Auswahl zwischen *Manueller* und *Cron*-Synchronisation
  - Figma-Verbindungsstatus & „Neu verbinden“-Button
  - Manuelle Synchronisation direkt aus der UI
- Automatische Erstellung von Karten aus Figma-Kommentaren
- Threads & Antworten werden als Kommentare in Deck eingefügt
- Unterstützung für mehrere Projekte durch Mapping-API
- Logging über `deck_log.txt`
- Kompatibilität getestet mit **Nextcloud Deck 1.14.x**

### 🛠 Technisches
- Refactoring der API-Logik (`deck-api.php`)
- Zentrale Tokenverwaltung (`figma_token.json`)
- Routen & Controller für OAuth (`figmaConnect`, `oauthCallback`)
- Nutzung des AppConfig-Backends statt statischer `config.php`

### 👤 Autor
**Mikka @ zzzooo Studio**  
<https://zzzooo.studio>

---

## 🧰 Geplant für Version 0.7.0
- Cron-Scheduler mit Statusanzeige  
- Letzter Sync-Zeitpunkt in der Admin-UI  
- Mapping-Editor (Figma ↔ Deck Boards) in der Oberfläche  
- Logging-Ansicht über UI
