<?php

namespace OCA\FigDeckBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\Files\Folder;
use OCP\Files\IAppDataFactory;
use OCP\Http\Client\IClient;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;

class ApiController extends Controller {
    private IConfig $configService;
    private IURLGenerator $urlGen;
    private IAppDataFactory $appDataFactory;
    private ILogger $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $configService,
        IURLGenerator $urlGen,
        IAppDataFactory $appDataFactory,
        ILogger $logger
    ) {
        parent::__construct($appName, $request);
        $this->configService = $configService;
        $this->urlGen = $urlGen;
        $this->appDataFactory = $appDataFactory;
        $this->logger = $logger;
    }

    // ------------------------------------------------------
    // 🗂 AppData-Ordner holen oder erstellen
    // ------------------------------------------------------
    private function getOrCreateAppFolder() {
        try {
            return $this->appDataFactory->get('figdeckbridge');
        } catch (\Throwable $e) {
            // 🧩 Fallback: direkt im Dateisystem anlegen
            $dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory', '');
            $instanceId = \OC::$server->getSystemConfig()->getValue('instanceid', '');
            $fallbackPath = rtrim($dataDir, '/') . "/appdata_$instanceId/figdeckbridge";

            if (!is_dir($fallbackPath)) {
                mkdir($fallbackPath, 0770, true);
            }

            \OC::$server->getLogger()->info('[FigDeckBridge] AppData fallback used: ' . $fallbackPath, ['app' => 'figdeckbridge']);
            return $fallbackPath;
        }
    }

    private function loadBaseConfig(): array {
        $configPath = __DIR__ . '/../../config.php';
        $config = [];

        if (file_exists($configPath)) {
            $config = require $configPath;
        }

        $keys = [
            'client_id',
            'client_secret',
            'mode',
            'deck_url',
            'deck_user',
            'deck_token',
            'deck_api_path',
            'mapping_file'
        ];

        foreach ($keys as $key) {
            $value = $this->configService->getAppValue('figdeckbridge', $key, null);
            if ($value !== null && $value !== '') {
                $config[$key] = $value;
            }
        }

        $config['redirect_uri'] = $this->urlGen->linkToRouteAbsolute('figdeckbridge.api.oauthCallback');

        return $config;
    }

    private function normalizeMappings(array $entries): array {
        $normalized = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $boardId = isset($entry['board_id']) ? (int)$entry['board_id'] : 0;
            $stackId = isset($entry['stack_id']) ? (int)$entry['stack_id'] : 0;
            $fileKey = isset($entry['file_key']) ? trim((string)$entry['file_key']) : '';

            if ($boardId <= 0 || $stackId <= 0 || $fileKey === '') {
                continue;
            }

            $normalized[] = [
                'board_id' => $boardId,
                'stack_id' => $stackId,
                'file_key' => $fileKey,
            ];
        }

        return $normalized;
    }

    private function readMappings(array $config): array {
        $entries = [];

        try {
            $folder = $this->getOrCreateAppFolder();
            if (is_string($folder)) {
                $file = $folder . '/mappings.json';
                if (file_exists($file)) {
                    $entries = json_decode(file_get_contents($file), true) ?: [];
                }
            } else {
                if ($folder->fileExists('mappings.json')) {
                    $entries = json_decode($folder->getFile('mappings.json')->getContent(), true) ?: [];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[FigDeckBridge] Could not read mappings from AppData: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
        }

        if (empty($entries) && !empty($config['mapping_file']) && file_exists($config['mapping_file'])) {
            $entries = json_decode(file_get_contents($config['mapping_file']), true) ?: [];
        }

        if (isset($entries['mappings'])) {
            $entries = $entries['mappings'];
        }

        if (!is_array($entries)) {
            $entries = [];
        }

        return $this->normalizeMappings($entries);
    }

    private function writeMappings(array $mappings): void {
        $payload = json_encode(['mappings' => $mappings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $folder = $this->getOrCreateAppFolder();

        if ($folder instanceof Folder) {
            try {
                if ($folder->fileExists('mappings.json')) {
                    $folder->getFile('mappings.json')->putContent($payload);
                } else {
                    $folder->newFile('mappings.json')->putContent($payload);
                }
                return;
            } catch (\Throwable $e) {
                $this->logger->warning('[FigDeckBridge] Failed to write mappings to AppData folder: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            }
        }

        if (is_string($folder)) {
            file_put_contents(rtrim($folder, '/') . '/mappings.json', $payload);
        }
    }

    private function createHttpClient(): IClient {
        return \OC::$server->getHTTPClientService()->newClient();
    }

    // ------------------------------------------------------
    // 🔧 Konfiguration abrufen
    // ------------------------------------------------------
    public function getConfig(): DataResponse {
        try {
            $connected = false;

            try {
                $appFolder = $this->getOrCreateAppFolder();

                if (is_string($appFolder)) {
                    $connected = file_exists($appFolder . '/figma_token.json');
                } else {
                    $connected = $appFolder->fileExists('figma_token.json');
                }
            } catch (\Throwable $e) {
                $connected = file_exists(__DIR__ . '/../../figma_token.json');
            }

            $clientId = $this->configService->getAppValue('figdeckbridge', 'client_id', '');
            $clientSecret = $this->configService->getAppValue('figdeckbridge', 'client_secret', '');
            $mode = $this->configService->getAppValue('figdeckbridge', 'mode', 'manual');

            return new DataResponse([
                'ok' => true,
                'client_id' => $clientId,
                'client_secret' => $clientSecret ? '••••••••' : '',
                'mode' => $mode,
                'connected' => $connected
            ]);
        } catch (\Throwable $e) {
            \OC::$server->getLogger()->error('[FigDeckBridge] getConfig failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Liste aller gespeicherten Zuordnungen abrufen
     */
    public function getMappings(): DataResponse {
        try {
            $config = $this->loadBaseConfig();
            $mappings = $this->readMappings($config);

            return new DataResponse([
                'ok' => true,
                'mappings' => $mappings,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[FigDeckBridge] getMappings failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ------------------------------------------------------
    // 💾 Konfiguration speichern
    // ------------------------------------------------------
    public function saveConfig(): DataResponse {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            if (isset($input['client_id'])) {
                $this->configService->setAppValue('figdeckbridge', 'client_id', trim($input['client_id']));
            }
            if (isset($input['client_secret'])) {
                $this->configService->setAppValue('figdeckbridge', 'client_secret', trim($input['client_secret']));
            }
            if (isset($input['mode'])) {
                $this->configService->setAppValue('figdeckbridge', 'mode', $input['mode']);
            }

            \OC::$server->getLogger()->info('[FigDeckBridge] Configuration saved.', ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            \OC::$server->getLogger()->error('[FigDeckBridge] saveConfig failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Zuordnungen speichern (Liste von Board ↔︎ Figma)
     */
    public function saveMapping(): DataResponse {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $mappings = $this->normalizeMappings($input['mappings'] ?? []);

            $this->writeMappings($mappings);

            return new DataResponse(['ok' => true, 'mappings' => $mappings]);
        } catch (\Throwable $e) {
            $this->logger->error('[FigDeckBridge] saveMapping failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Deck-Boards abrufen (Name & ID)
     */
    public function getDeckBoards(): DataResponse {
        try {
            $config = $this->loadBaseConfig();
            $base = rtrim($config['deck_url'] ?? '', '/');
            $user = $config['deck_user'] ?? '';
            $token = $config['deck_token'] ?? '';

            if ($base === '' || $user === '' || $token === '') {
                return new DataResponse([
                    'ok' => false,
                    'error' => 'Deck Zugangsdaten fehlen.',
                ], 400);
            }

            $apiPath = $config['deck_api_path'] ?? '/index.php/apps/deck/api/v1.1';
            $url = $base . $apiPath . '/boards';

            $client = $this->createHttpClient();
            $response = $client->get($url, [
                'auth' => [$user, $token],
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            $data = json_decode($response->getBody(), true);
            $boards = [];

            if (is_array($data)) {
                foreach ($data as $board) {
                    $boards[] = [
                        'id' => (int)($board['id'] ?? 0),
                        'title' => $board['title'] ?? 'Unbenanntes Board',
                    ];
                }
            }

            return new DataResponse(['ok' => true, 'boards' => $boards]);
        } catch (\Throwable $e) {
            $this->logger->error('[FigDeckBridge] getDeckBoards failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Stacks für ein bestimmtes Board abrufen
     */
    public function getDeckStacks(int $boardId): DataResponse {
        try {
            $config = $this->loadBaseConfig();
            $base = rtrim($config['deck_url'] ?? '', '/');
            $user = $config['deck_user'] ?? '';
            $token = $config['deck_token'] ?? '';

            if ($base === '' || $user === '' || $token === '') {
                return new DataResponse([
                    'ok' => false,
                    'error' => 'Deck Zugangsdaten fehlen.',
                ], 400);
            }

            $apiPath = $config['deck_api_path'] ?? '/index.php/apps/deck/api/v1.1';
            $url = $base . $apiPath . '/boards/' . $boardId . '/stacks';

            $client = $this->createHttpClient();
            $response = $client->get($url, [
                'auth' => [$user, $token],
                'headers' => [
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            $data = json_decode($response->getBody(), true);
            $stacks = [];

            if (is_array($data)) {
                foreach ($data as $stack) {
                    $stacks[] = [
                        'id' => (int)($stack['id'] ?? 0),
                        'title' => $stack['title'] ?? 'Unbenannter Stack',
                    ];
                }
            }

            return new DataResponse(['ok' => true, 'stacks' => $stacks]);
        } catch (\Throwable $e) {
            $this->logger->error('[FigDeckBridge] getDeckStacks failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Figma-Projekte und -Dateien abrufen
     */
    public function getFigmaFiles(): DataResponse {
        try {
            $config = $this->loadBaseConfig();

            require_once __DIR__ . '/../../deck-api.php';
            $token = getFigmaAccessToken($config);

            if (!$token) {
                return new DataResponse([
                    'ok' => false,
                    'error' => 'Figma ist nicht verbunden.',
                ], 401);
            }

            $client = $this->createHttpClient();

            $teamsRes = $client->get('https://api.figma.com/v1/me/teams', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 20,
            ]);

            $teamsData = json_decode($teamsRes->getBody(), true);
            $projects = [];

            if (isset($teamsData['teams']) && is_array($teamsData['teams'])) {
                foreach ($teamsData['teams'] as $team) {
                    $teamId = $team['team_id'] ?? $team['id'] ?? null;
                    if (!$teamId) {
                        continue;
                    }

                    $teamName = $team['name'] ?? 'Team ' . $teamId;

                    try {
                        $projRes = $client->get('https://api.figma.com/v1/teams/' . $teamId . '/projects', [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $token,
                                'Accept' => 'application/json',
                            ],
                            'timeout' => 20,
                        ]);

                        $projData = json_decode($projRes->getBody(), true);
                        if (!isset($projData['projects']) || !is_array($projData['projects'])) {
                            continue;
                        }

                        foreach ($projData['projects'] as $project) {
                            $projectId = $project['id'] ?? null;
                            if (!$projectId) {
                                continue;
                            }

                            $files = [];

                            try {
                                $filesRes = $client->get('https://api.figma.com/v1/projects/' . $projectId . '/files', [
                                    'headers' => [
                                        'Authorization' => 'Bearer ' . $token,
                                        'Accept' => 'application/json',
                                    ],
                                    'timeout' => 20,
                                ]);
                                $filesData = json_decode($filesRes->getBody(), true);
                                if (isset($filesData['files']) && is_array($filesData['files'])) {
                                    foreach ($filesData['files'] as $file) {
                                        $files[] = [
                                            'key' => $file['key'] ?? '',
                                            'name' => $file['name'] ?? 'Unbenannte Datei',
                                            'last_modified' => $file['last_modified'] ?? null,
                                        ];
                                    }
                                }
                            } catch (\Throwable $inner) {
                                $this->logger->warning('[FigDeckBridge] Failed to fetch files for project ' . $projectId . ': ' . $inner->getMessage(), ['app' => 'figdeckbridge']);
                            }

                            $projects[] = [
                                'id' => (string)$projectId,
                                'name' => $project['name'] ?? 'Unbenanntes Projekt',
                                'teamId' => (string)$teamId,
                                'teamName' => $teamName,
                                'files' => $files,
                            ];
                        }
                    } catch (\Throwable $inner) {
                        $this->logger->warning('[FigDeckBridge] Failed to fetch projects for team ' . $teamId . ': ' . $inner->getMessage(), ['app' => 'figdeckbridge']);
                    }
                }
            }

            return new DataResponse(['ok' => true, 'projects' => $projects]);
        } catch (\Throwable $e) {
            $this->logger->error('[FigDeckBridge] getFigmaFiles failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ------------------------------------------------------
    // 🚀 Figma OAuth Start
    // ------------------------------------------------------
    #[NoCSRFRequired]
    public function oauthStart(): RedirectResponse {
        $clientId = $this->configService->getAppValue('figdeckbridge', 'client_id', '');
        if (empty($clientId)) {
            return new RedirectResponse('/settings/admin/figdeckbridge?error=missing_client_id');
        }

        $scopes = 'current_user:read file_content:read file_comments:read file_comments:write projects:read webhooks:read webhooks:write';
        $redirectUri = $this->urlGen->linkToRouteAbsolute('figdeckbridge.api.oauthCallback');

        $url = sprintf(
            'https://www.figma.com/oauth?client_id=%s&redirect_uri=%s&scope=%s&state=figma_bridge&response_type=code',
            urlencode($clientId),
            urlencode($redirectUri),
            urlencode($scopes)
        );

        \OC::$server->getLogger()->info('[FigDeckBridge] Redirecting to Figma OAuth', ['app' => 'figdeckbridge']);
        return new RedirectResponse($url);
    }

    // ------------------------------------------------------
    // 🔑 OAuth Callback
    // ------------------------------------------------------
    #[NoCSRFRequired]
    public function oauthCallback(): RedirectResponse {
        $code = $this->request->getParam('code');
        $state = $this->request->getParam('state');

        if (empty($code) || $state !== 'figma_bridge') {
            return new RedirectResponse('/settings/admin/figdeckbridge?error=oauth_failed');
        }

        $clientId = $this->configService->getAppValue('figdeckbridge', 'client_id', '');
        $clientSecret = $this->configService->getAppValue('figdeckbridge', 'client_secret', '');
        $redirectUri = $this->urlGen->linkToRouteAbsolute('figdeckbridge.api.oauthCallback');

        $ch = curl_init('https://api.figma.com/v1/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
                'grant_type' => 'authorization_code'
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            \OC::$server->getLogger()->error('[FigDeckBridge] Figma token retrieval failed.', ['response' => $response, 'app' => 'figdeckbridge']);
            return new RedirectResponse('/settings/admin/figdeckbridge?error=token');
        }

        // 🗂 AppData holen oder fallback
        $folder = $this->getOrCreateAppFolder();

        if (is_string($folder)) {
            // manuelles Dateisystem
            file_put_contents($folder . '/figma_token.json', json_encode($data, JSON_PRETTY_PRINT));
        } else {
            if ($folder->fileExists('figma_token.json')) {
                $folder->getFile('figma_token.json')->delete();
            }
            $file = $folder->newFile('figma_token.json');
            $file->putContent(json_encode($data, JSON_PRETTY_PRINT));
        }

        \OC::$server->getLogger()->info('[FigDeckBridge] Figma OAuth successful (token saved).', ['app' => 'figdeckbridge']);
        return new RedirectResponse('/settings/admin/figdeckbridge?connected=1');
    }

    // ------------------------------------------------------
    // 🔌 Figma Disconnect
    // ------------------------------------------------------
    public function disconnectFigma(): DataResponse {
        try {
            $folder = $this->getOrCreateAppFolder();
            if (is_string($folder)) {
                if (file_exists($folder . '/figma_token.json')) unlink($folder . '/figma_token.json');
            } else {
                if ($folder->fileExists('figma_token.json')) $folder->getFile('figma_token.json')->delete();
            }

            \OC::$server->getLogger()->info('[FigDeckBridge] Token removed.', ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ------------------------------------------------------
    // 🔁 Manuelle Synchronisation
    // ------------------------------------------------------
    public function manualSync(): DataResponse {
        try {
            require_once __DIR__ . '/../../../poll-comments.php';
            exec('php ' . escapeshellarg(__DIR__ . '/../../../poll-comments.php'));
            \OC::$server->getLogger()->info('[FigDeckBridge] Manual sync triggered.', ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => true]);
        } catch (\Throwable $e) {
            \OC::$server->getLogger()->error('[FigDeckBridge] Manual sync failed: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
