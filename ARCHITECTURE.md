# FigDeck Bridge Architekturüberblick

Dieser Überblick beschreibt den aktuellen Stand der Synchronisations-Skripte und
wichtige Beobachtungen zur Struktur.

## Komponentenübersicht

- `poll-comments.php`
  - Kümmert sich um das Laden der Konfiguration, das Einlesen der gespeicherten
    Kommentar-IDs und iteriert über alle gemappten Figma-Dateien.
  - Ruft die Figma API ab, filtert ungelöste Threads und delegiert die
    Synchronisation an `syncDeckThread()`.
- `deck-api.php`
  - Enthält Hilfsfunktionen für Authentifizierung, das Filtern von Kommentaren
    sowie sämtliche Deck-spezifischen API-Aufrufe.
  - `deck_ensure_card()` legt pro Thread entweder eine neue Karte an oder findet
    eine bestehende anhand des Kartentitels.
  - `deck_add_figma_comment()` und `deck_add_attachment()` übertragen Inhalte in
    Deck und berücksichtigen optionale User-Mappings.

## Positives

- Die Trennung zwischen Polling-Logik und Deck-Hilfsfunktionen erleichtert das
  Verständnis und vermeidet Dopplungen bei API-Aufrufen.
- `buildThreads()` bildet die Figma-Struktur in ein klar lesbares
  Datenmodell ab, das Root-Kommentare und Antworten voneinander trennt.
- Mehrere Mappings in `poll-comments.php` erlauben den Einsatz in komplexeren
  Workflows mit verschiedenen Boards.

## Verbesserungspotenziale

- **Konfigurationsquellen bündeln:** Die Priorisierung zwischen statischer
  Konfiguration, Mapping-Datei und App-Konfiguration ist aktuell im Code
  eingebettet. Eine eigene Klasse oder Utility-Funktion, die Validierung und
  Zusammenführen übernimmt, könnte die Einstiegspunkte schlanker machen.
- **API-Aufrufe cachen/paginieren:** `deck_ensure_card()` lädt für jeden Thread
  die komplette Kartenliste. Das funktioniert bei wenigen Karten, wird aber bei
  größeren Boards langsam. Langfristig wäre ein Lookup-Index oder eine direkte
  Suche per API sinnvoll.
- **Error Handling & Logging:** Viele Funktionen verlassen sich auf `die()` oder
  geben `false` zurück, ohne Details zu loggen. Eine strukturierte Fehler- und
  Logging-Strategie (z. B. PSR-3 Logger) würde den Betrieb vereinfachen.
- **Testbarkeit erhöhen:** Aktuell sind die Funktionen stark von globaler
  Konfiguration abhängig. Eine weitere Entkopplung (z. B. durch kleine Klassen
  oder Dependency Injection) würde Unit-Tests erleichtern.

## Fazit

Die bestehende Struktur liefert eine funktionierende Synchronisation, ist aber
noch stark skriptbasiert. Durch zusätzliche Abstraktion der Konfiguration und
gezieltes Caching der Deck-API ließe sich die Wartbarkeit weiter verbessern.
