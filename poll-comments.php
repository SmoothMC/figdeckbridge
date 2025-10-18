<?php

// ------------------------------------------------------
// CONFIG LADEN
// ------------------------------------------------------
$config = require __DIR__ . '/config.php';
require __DIR__ . '/deck-api.php';

// ------------------------------------------------------
// Hilfsfunktionen
// ------------------------------------------------------
function loadAppConfig(array $config): array {
    if (class_exists('\\OC')) {
        $appConfig = \OC::$server->getAppConfig();
        $config['client_id'] = $appConfig->getValue('figdeckbridge', 'client_id', '');
        $config['client_secret'] = $appConfig->getValue('figdeckbridge', 'client_secret', '');
        $config['mode'] = $appConfig->getValue('figdeckbridge', 'mode', 'manual');
    }

    return $config;
}

function loadMappings(array $config): array {
    $mappings = [];

    if (class_exists('\\OC')) {
        try {
            $factory = \OC::$server->get(\OCP\Files\IAppDataFactory::class);
            $folder = $factory->get('figdeckbridge');

            if ($folder->fileExists('mappings.json')) {
                $stored = json_decode($folder->getFile('mappings.json')->getContent(), true);
                if (isset($stored['mappings']) && is_array($stored['mappings'])) {
                    $mappings = $stored['mappings'];
                } elseif (is_array($stored)) {
                    $mappings = $stored;
                }
            }
        } catch (\Throwable $e) {
            // Fallback erfolgt weiter unten über mapping_file
        }
    }

    if (!empty($config['file_mappings']) && is_array($config['file_mappings'])) {
        $mappings = $config['file_mappings'];
    } elseif (!empty($config['mapping_file']) && file_exists($config['mapping_file'])) {
        $data = json_decode(file_get_contents($config['mapping_file']), true);
        if (isset($data['mappings']) && is_array($data['mappings'])) {
            $mappings = $data['mappings'];
        } elseif (is_array($data)) {
            $mappings = isset($data[0]) ? $data : [$data];
        }
    }

    if (empty($mappings) && !empty($config['file_key']) && isset($config['board_id'], $config['stack_id'])) {
        $mappings = [[
            'file_key' => $config['file_key'],
            'board_id' => $config['board_id'],
            'stack_id' => $config['stack_id'],
        ]];
    }

    return array_values(array_filter(array_map(function ($entry) {
        if (!is_array($entry)) {
            return null;
        }
        if (empty($entry['file_key']) || !isset($entry['board_id'], $entry['stack_id'])) {
            return null;
        }

        $boardId = (int)$entry['board_id'];
        $stackId = (int)$entry['stack_id'];
        if ($boardId <= 0 || $stackId <= 0) {
            return null;
        }

        return [
            'file_key' => $entry['file_key'],
            'board_id' => $boardId,
            'stack_id' => $stackId,
        ];
    }, $mappings)));
}

function loadStoredIds(string $file): array {
    if (!file_exists($file)) {
        return [];
    }

    $raw = json_decode(file_get_contents($file), true);
    if (!is_array($raw)) {
        return [];
    }

    if (array_keys($raw) === range(0, count($raw) - 1)) {
        return ['__default__' => $raw];
    }

    return $raw;
}

function saveStoredIds(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function buildThreads(array $comments, array $storedIds): array {
    $threads = [];

    foreach ($comments as $comment) {
        $id = $comment['id'] ?? null;
        if (!$id) {
            continue;
        }

        $parent = $comment['parent_id'] ?? null;
        if (!$parent) {
            if (!isset($threads[$id])) {
                $threads[$id] = [
                    'root' => $comment,
                    'children' => [],
                    'newRoot' => !in_array($id, $storedIds, true),
                    'newReplies' => [],
                ];
            } else {
                $threads[$id]['root'] = $comment;
                $threads[$id]['newRoot'] = !in_array($id, $storedIds, true);
            }
        } else {
            if (!isset($threads[$parent])) {
                $threads[$parent] = [
                    'root' => null,
                    'children' => [],
                    'newRoot' => false,
                    'newReplies' => [],
                ];
            }

            $threads[$parent]['children'][] = $comment;
            if (!in_array($id, $storedIds, true)) {
                $threads[$parent]['newReplies'][] = $comment;
            }
        }
    }

    foreach ($threads as $key => $thread) {
        if (empty($thread['root'])) {
            unset($threads[$key]);
            continue;
        }

        if (!empty($thread['newRoot'])) {
            $threads[$key]['newReplies'] = $thread['children'];
        }
    }

    return $threads;
}

// ------------------------------------------------------
// Laufzeit starten
// ------------------------------------------------------
$config = loadAppConfig($config);
$mappings = loadMappings($config);

if (empty($mappings)) {
    die("❌ Keine gültigen Zuordnungen vorhanden.\n");
}

$storedAll = loadStoredIds($config['data_file']);

$accessToken = getFigmaAccessToken($config);
if (!$accessToken) {
    die("❌ Kein gültiger Access Token.\n");
}

$changes = 0;

foreach ($mappings as $mapping) {
    $fileKey = $mapping['file_key'];
    $configForFile = array_merge($config, $mapping);

    $storedForFile = $storedAll[$fileKey] ?? ($storedAll['__default__'] ?? []);
    if (!is_array($storedForFile)) {
        $storedForFile = [];
    }

    $url = "https://api.figma.com/v1/files/{$fileKey}/comments";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $comments = $data['comments'] ?? [];
    $comments = filterUnresolvedFigmaComments($comments);

    if (!$comments) {
        continue;
    }

    $threads = buildThreads($comments, $storedForFile);

    foreach ($threads as $rootId => $thread) {
        if (empty($thread['newRoot']) && empty($thread['newReplies'])) {
            continue;
        }

        if (!syncDeckThread($configForFile, $thread)) {
            continue;
        }

        $storedForFile[] = $rootId;
        foreach ($thread['children'] as $child) {
            $childId = $child['id'] ?? null;
            if ($childId) {
                $storedForFile[] = $childId;
            }
        }
        $changes++;
    }

    $storedForFile = array_values(array_unique(array_filter($storedForFile)));
    $storedAll[$fileKey] = $storedForFile;
    unset($storedAll['__default__']);
}

if ($changes > 0) {
    saveStoredIds($config['data_file'], $storedAll);
    echo "✅ $changes Kommentar-Threads aktualisiert.\n";
} else {
    echo "Keine neuen Kommentare.\n";
}
