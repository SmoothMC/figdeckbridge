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

    $data = null;
    $saveToken = null;

    if (is_readable($tokenFile)) {
        $contents = file_get_contents($tokenFile);
        $data = $contents !== false ? json_decode($contents, true) : null;
        $saveToken = function (array $payload) use ($tokenFile): void {
            file_put_contents($tokenFile, json_encode($payload, JSON_PRETTY_PRINT));
        };
    }

    if (!$data && class_exists('\\OC')) {
        try {
            $factory = \OC::$server->get(\OCP\Files\IAppDataFactory::class);
            $folder = $factory->get('figdeckbridge');

            if ($folder->fileExists('figma_token.json')) {
                $file = $folder->getFile('figma_token.json');
                $data = json_decode($file->getContent(), true);
            }

            if ($data) {
                $saveToken = function (array $payload) use ($folder): void {
                    $json = json_encode($payload, JSON_PRETTY_PRINT);
                    if ($folder->fileExists('figma_token.json')) {
                        $folder->getFile('figma_token.json')->putContent($json);
                    } else {
                        $folder->newFile('figma_token.json')->putContent($json);
                    }
                };
            }
        } catch (\Throwable $e) {
            // ignore – we'll fall back to filesystem lookups below
        }

        if (!$data) {
            try {
                $configService = \OC::$server->getConfig();
                $dataDir = $configService->getSystemValue('datadirectory', '');
                $instanceId = \OC::$server->getSystemConfig()->getValue('instanceid', '');
                if ($dataDir && $instanceId) {
                    $fallbackPath = rtrim($dataDir, '/') . "/appdata_{$instanceId}/figdeckbridge/figma_token.json";
                    if (is_readable($fallbackPath)) {
                        $contents = file_get_contents($fallbackPath);
                        $data = $contents !== false ? json_decode($contents, true) : null;
                        if ($data) {
                            $saveToken = function (array $payload) use ($fallbackPath): void {
                                if (!is_dir(dirname($fallbackPath))) {
                                    mkdir(dirname($fallbackPath), 0770, true);
                                }
                                file_put_contents($fallbackPath, json_encode($payload, JSON_PRETTY_PRINT));
                            };
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore and keep default behaviour
            }
        }
    }

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

    if ($saveToken) {
        $saveToken($newData);
    } else {
        file_put_contents($tokenFile, json_encode($newData, JSON_PRETTY_PRINT));
    }

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
// Hilfsfunktion: Deck-Karte anhand des Root-Kommentars ermitteln
// ------------------------------------------------------
function deck_ensure_card(array $config, array $rootComment) {
    $deckUrl  = deck_api_base($config);
    $boardId  = $config['board_id'];
    $stackId  = $config['stack_id'];
    $username = $config['deck_user'];
    $token    = $config['deck_token'];

    $rootMessage = (string)($rootComment['message'] ?? '');
    $title = trim(mb_substr(preg_split('/[.!?]/u', $rootMessage)[0] ?? $rootMessage, 0, 255));
    if ($title === '') {
        $title = 'Figma-Kommentar';
    }
    $normalizedTitle = mb_strtolower(trim(html_entity_decode($title)));

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
            if (($c['stackId'] ?? null) != $stackId) {
                continue;
            }
            $compareTitle = mb_strtolower(trim(html_entity_decode($c['title'] ?? '')));
            if ($compareTitle === $normalizedTitle) {
                $existingId = $c['id'];
                break;
            }
        }
    }

    if ($existingId) {
        return [$existingId, false, $title];
    }

    $fileUrl  = $rootComment['file_url'] ?? '';
    $rootUser = $rootComment['user']['handle'] ?? 'Unbekannt';
    $desc = "👤 **$rootUser**\n\n" . trim($rootMessage);
    if ($fileUrl) {
        $desc .= "\n\n🔗 [Figma öffnen]($fileUrl)";
    }

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

    if (!$cardId) {
        return false;
    }

    return [$cardId, true, $title];
}

// ------------------------------------------------------
// Kommentar aus Figma nach Deck übertragen
// ------------------------------------------------------
function deck_add_figma_comment(array $config, int $cardId, array $comment) {
    $msgUser = $comment['user']['handle'] ?? 'Unbekannt';
    $msgText = trim($comment['message'] ?? '');
    if ($msgText === '') {
        return;
    }

    $created = !empty($comment['created_at'])
        ? date('d.m.Y H:i', strtotime($comment['created_at']))
        : date('d.m.Y H:i');

    $finalMessage = "$msgText\n($created)";
    deck_add_comment($config, $config['board_id'], $config['stack_id'], $cardId, $finalMessage, $msgUser);
}

// ------------------------------------------------------
// Vollständigen Kommentar-Thread synchronisieren
// ------------------------------------------------------
function syncDeckThread(array $config, array $thread) {
    if (empty($thread['root'])) {
        return false;
    }

    $ensure = deck_ensure_card($config, $thread['root']);
    if (!$ensure) {
        return false;
    }

    [$cardId] = $ensure;

    if (!empty($thread['newRoot']) && $thread['newRoot']) {
        deck_add_figma_comment($config, $cardId, $thread['root']);

        foreach ($thread['children'] as $child) {
            deck_add_figma_comment($config, $cardId, $child);
        }

        $imageUrl = $thread['root']['client_meta']['snapshot_url'] ?? '';
        if ($imageUrl) {
            $tmp = tempnam(sys_get_temp_dir(), 'figma_');
            if ($tmp && file_put_contents($tmp, @file_get_contents($imageUrl)) !== false) {
                deck_add_attachment($config, $config['board_id'], $config['stack_id'], $cardId, $tmp);
            }
            if ($tmp && file_exists($tmp)) {
                unlink($tmp);
            }
        }
    } else {
        foreach ($thread['newReplies'] as $reply) {
            deck_add_figma_comment($config, $cardId, $reply);
        }
    }

    return true;
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
