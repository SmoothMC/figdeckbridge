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
