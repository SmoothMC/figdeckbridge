<?php
/**
 * FigDeck Bridge – Standardkonfiguration (Fallback)
 * Wird nur genutzt, wenn keine Werte aus Nextcloud-AppConfig vorhanden sind.
 *
 * 🔹 Innerhalb von Nextcloud werden diese Werte dynamisch überschrieben.
 * 🔹 Du kannst diese Datei für lokale Tests oder CLI-Syncs verwenden.
 */

return [
    // ---------------------------------------------
    // 🔐 Nextcloud Deck Zugriff
    // ---------------------------------------------
    'deck_url'     => 'https://cloud.zzzooo.studio',  // Basis-URL deiner Nextcloud
    'deck_user'    => 'm0551b90',                      // Standard-User oder App-Passwort
    'deck_token'   => 'dfT3Y-gbGqL-S2yZS-iEmod-JaaAo',               // App-spezifisches Passwort
    'deck_api_path'=> '/index.php/apps/deck/api/v1.1',

    // ---------------------------------------------
    // 🎨 Figma API Zugangsdaten (werden meist aus AppConfig gelesen)
    // ---------------------------------------------
    'client_id'     => '', // wird über Admin-UI gesetzt
    'client_secret' => '',
    'redirect_uri'  => '', // wird automatisch berechnet → siehe ApiController
    'token_file'    => __DIR__ . '/figma_token.json',

    // ---------------------------------------------
    // 🗂️ Projekt-/Board-Zuordnung (Mapping)
    // ---------------------------------------------
    // Diese Datei speichert, welches Figma-Projekt welchem Deck-Board zugeordnet ist.
    // Sie wird automatisch durch die Admin- oder API-Funktion geschrieben.
    'mapping_file' => __DIR__ . '/mappings.json',

    // ---------------------------------------------
    // 📁 Lokale Datenspeicher
    // ---------------------------------------------
    'data_file'  => __DIR__ . '/last_comments.json', // speichert bereits importierte Figma-Kommentare
    'log_file'   => __DIR__ . '/deck_log.txt',

    // ---------------------------------------------
    // ⚙️ Synchronisationsoptionen
    // ---------------------------------------------
    'mode' => 'manual', // oder 'cron' (automatisch durch Nextcloud Cron)
];
