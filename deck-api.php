<?php
// ======================================================
//  FIGMA → NEXTCLOUD DECK API (kompatibel mit Deck 1.14+)
// ======================================================

// ------------------------------------------------------
// FIGMA TOKEN REFRESH
// ------------------------------------------------------
function getFigmaAccessToken($config) {
    $tokenFile = $config['token_file'] ?? (__DIR__ . '/figma_token.json');
    $clientId = $config['client_id'] ?? '';
    $clientSecret = $config['client_secret'] ?? '';
    $redirectUri = $config['redirect_uri'] ?? '';

    if (!file_exists($tokenFile)) return null;

    $data = json_decode(file_get_contents($tokenFile), true);
    if (!$data) return null;

    $expiresIn = $data['expires_in'] ?? 3600;
    $createdAt = $data['created_at'] ?? 0;

    // Noch gültig?
    if (time() < $createdAt + $expiresIn - 60) {
        return $data['access_token'];
    }

    // Token refreshen
    $ch = curl_init("https://api.figma.com/v1/oauth/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $data['refresh_token'],
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $newData = json_decode($response, true);
    if (!$newData || !isset($newData['access_token'])) return null;

    $newData['created_at'] = time();
    file_put_contents($tokenFile, json_encode($newData, JSON_PRETTY_PRINT));

    return $newData['access_token'];
}

// ------------------------------------------------------
// FILTER: Nur ungelöste Root-Kommentare + Replies
// ------------------------------------------------------
function filterUnresolvedFigmaComments(array $comments): array {
    $filtered = [];
    $roots = [];

    foreach ($comments as $c) {
        if (empty($c['parent_id']) && empty($c['resolved_at'])) {
            $roots[$c['id']] = true;
            $filtered[] = $c;
        }
    }
    foreach ($comments as $c) {
        if (!empty($c['parent_id']) && isset($roots[$c['parent_id']])) {
            $filtered[] = $c;
        }
    }
    return $filtered;
}

// ------------------------------------------------------
// API-BASIS ERMITTELN
// ------------------------------------------------------
function deck_api_base($config) {
    $base = rtrim($config['deck_url'] ?? '', '/');
    $path = $config['deck_api_path'] ?? '/index.php/apps/deck/api/v1.1';
    return $base . $path;
}

// ------------------------------------------------------
// DECK: Karte anlegen + Kommentare + Screenshot
// ------------------------------------------------------
function createDeckCard($rootUser, $rootMessage, $fileUrl, $imageUrl, $config, $children = []) {
    $deckUrl  = deck_api_base($config);
    $boardId  = $config['board_id'];
    $stackId  = $config['stack_id'];
    $username = $config['deck_user'];
    $token    = $config['deck_token'];

    // --- Titel generieren
    $title = trim(mb_substr(preg_split('/[.!?]/u', $rootMessage)[0] ?? $rootMessage, 0, 255));
    $normalizedTitle = mb_strtolower(trim(html_entity_decode($title)));

    // --- Prüfen, ob Karte bereits existiert
    $existingId = null;
    $cardsUrl = "$deckUrl/boards/$boardId/cards";
    $ch = curl_init($cardsUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "$username:$token",
        CURLOPT_HTTPHEADER => ['OCS-APIRequest: true']
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $cards = json_decode($res, true);
    if (is_array($cards)) {
        foreach ($cards as $c) {
            if (($c['stackId'] ?? null) != $stackId) continue;
            $compareTitle = mb_strtolower(trim(html_entity_decode($c['title'] ?? '')));
            if ($compareTitle === $normalizedTitle) {
                $existingId = $c['id'];
                break;
            }
        }
    }

    // --- Karte anlegen, wenn nicht vorhanden
    if ($existingId) {
        $cardId = $existingId;
    } else {
        $desc = "👤 **$rootUser**\n\n" . trim($rootMessage) . "\n\n🔗 [Figma öffnen]($fileUrl)";
        $cardUrl = "$deckUrl/boards/$boardId/stacks/$stackId/cards";
        $payload = json_encode([
            'title' => $title,
            'description' => $desc,
            'type' => 'plain'
        ]);

        $ch = curl_init($cardUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => "$username:$token",
            CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        $cardId = $data['id'] ?? null;
    }

    if (!$cardId) return false;

    // --- Kommentare hinzufügen (Root + Replies)
    $allComments = array_merge(
        [[ 'user' => ['handle' => $rootUser], 'message' => $rootMessage, 'created_at' => date('c') ]],
        $children
    );

    foreach ($allComments as $cmt) {
        $msgUser = $cmt['user']['handle'] ?? 'Unbekannt';
        $msgText = trim($cmt['message'] ?? '');
        $created = !empty($cmt['created_at'])
            ? date('d.m.Y H:i', strtotime($cmt['created_at']))
            : date('d.m.Y H:i');
        $finalMessage = "$msgText\n($created)";
        deck_add_comment($config, $boardId, $stackId, $cardId, $finalMessage, $msgUser);
    }

    // --- Screenshot anhängen
    if ($imageUrl) {
        $tmp = sys_get_temp_dir() . '/figma-comment.png';
        file_put_contents($tmp, file_get_contents($imageUrl));
        deck_add_attachment($config, $boardId, $stackId, $cardId, $tmp);
        unlink($tmp);
    }

    return $cardId;
}

// ------------------------------------------------------
// Kommentar hinzufügen (mit User-Mapping)
// ------------------------------------------------------
function deck_add_comment($config, $boardId, $stackId, $cardId, $message, $figmaUser = null) {
    $username = $config['deck_user'];
    $token    = $config['deck_token'];
    $deckUrl  = rtrim($config['deck_url'], '/');
    $apiPath  = $config['deck_api_path'] ?? '/index.php/apps/deck/api/v1.1';

    if (!empty($figmaUser) && isset($config['user_map'][$figmaUser])) {
        $username = $config['user_map'][$figmaUser]['user'];
        $token    = $config['user_map'][$figmaUser]['token'];
    }

    $payload = json_encode(['message' => $message]);
    $url = "$deckUrl/ocs/v2.php/apps/deck/api/v1.1/cards/$cardId/comments";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => "$username:$token",
        CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ------------------------------------------------------
// Datei als Attachment hochladen
// ------------------------------------------------------
function deck_add_attachment($config, $boardId, $stackId, $cardId, $filePath) {
    $deckUrl = deck_api_base($config);
    $username = $config['deck_user'];
    $token = $config['deck_token'];
    $url = "$deckUrl/cards/$cardId/attachments";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => "$username:$token",
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($filePath)],
        CURLOPT_HTTPHEADER => ['OCS-APIRequest: true']
    ]);
    curl_exec($ch);
    curl_close($ch);
}
