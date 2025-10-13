<?php
use OCP\AppConfig;

// ------------------------------------------------------
// CONFIG LADEN
// ------------------------------------------------------
$config = require __DIR__ . '/config.php';
require __DIR__ . '/deck-api.php';

// Wenn Nextcloud läuft, AppConfig lesen
if (class_exists('\OC')) {
    $appConfig = \OC::$server->getAppConfig();
    $config['client_id'] = $appConfig->getValue('figdeckbridge', 'client_id', '');
    $config['client_secret'] = $appConfig->getValue('figdeckbridge', 'client_secret', '');
    $config['mode'] = $appConfig->getValue('figdeckbridge', 'mode', 'manual');
}

// ------------------------------------------------------
// FIGMA-KOMMENTARE LADEN
// ------------------------------------------------------
$accessToken = getFigmaAccessToken($config);
if (!$accessToken) die("❌ Kein gültiger Access Token.\n");

$ch = curl_init("https://api.figma.com/v1/files/{$config['file_key']}/comments");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"]
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$comments = $data['comments'] ?? [];
$comments = filterUnresolvedFigmaComments($comments);

if (!$comments) exit("Keine offenen Kommentare gefunden.\n");

// ------------------------------------------------------
// Gespeicherte IDs laden
// ------------------------------------------------------
$stored = [];
if (file_exists($config['data_file'])) {
    $stored = json_decode(file_get_contents($config['data_file']), true) ?: [];
}

// Nur neue Comments verarbeiten
$newComments = array_filter($comments, fn($c) => !in_array($c['id'], $stored));

if (empty($newComments)) exit("Keine neuen Kommentare.\n");

// ------------------------------------------------------
// Neue Kommentare nach Deck übertragen
// ------------------------------------------------------
foreach ($newComments as $comment) {
    $message  = $comment['message'] ?? '';
    $user     = $comment['user']['handle'] ?? 'Unbekannt';
    $fileUrl  = $comment['file_url'] ?? '';
    $imageUrl = $comment['client_meta']['snapshot_url'] ?? '';

    createDeckCard($user, $message, $fileUrl, $imageUrl, $config);
    $stored[] = $comment['id'];
}

// ------------------------------------------------------
// IDs speichern
// ------------------------------------------------------
file_put_contents($config['data_file'], json_encode($stored, JSON_PRETTY_PRINT));
echo "✅ Sync abgeschlossen.\n";
