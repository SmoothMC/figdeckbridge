<?php
return [
    'routes' => [
        // 🏠 Standard-Page
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // 🔧 API routes
        ['name' => 'api#getFigmaFiles', 'url' => '/api/figma/files', 'verb' => 'GET'],
        ['name' => 'api#getDeckBoards', 'url' => '/api/deck/boards', 'verb' => 'GET'],
        ['name' => 'api#getDeckStacks', 'url' => '/api/deck/stacks/{boardId}', 'verb' => 'GET'],
        ['name' => 'api#getMappings', 'url' => '/api/mapping', 'verb' => 'GET'],
        ['name' => 'api#saveMapping', 'url' => '/api/mapping', 'verb' => 'POST'],
        ['name' => 'api#saveConfig', 'url' => '/api/save-config', 'verb' => 'POST'],
        ['name' => 'api#getConfig', 'url' => '/api/get-config', 'verb' => 'GET'],
        ['name' => 'api#manualSync', 'url' => '/api/manual-sync', 'verb' => 'POST'],

        // ✨ OAuth flow
        ['name' => 'api#oauthStart', 'url' => '/api/figma/oauth/start', 'verb' => 'GET'],
        ['name' => 'api#oauthCallback', 'url' => '/api/figma/oauth/callback', 'verb' => 'GET'],
        ['name' => 'api#disconnectFigma', 'url' => '/api/figma/disconnect', 'verb' => 'POST'],
    ]
];
