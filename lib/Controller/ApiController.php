<?php

namespace OCA\FigDeckBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

class ApiController extends Controller {
    private IConfig $configService;
    private IURLGenerator $urlGen;
    private IAppData $appData;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $configService,
        IURLGenerator $urlGen,
        IAppData $appData // ✅ injiziert
    ) {
        parent::__construct($appName, $request);
        $this->configService = $configService;
        $this->urlGen = $urlGen;
        $this->appData = $appData;
    }

    // ------------------------------------------------------
    // 🗂 AppData-Ordner holen oder erstellen
    // ------------------------------------------------------
    private function getOrCreateAppFolder() {
        try {
            return $this->appData->getAppDataFolder('figdeckbridge');
        } catch (NotFoundException $e) {
            \OC::$server->getLogger()->warning('[FigDeckBridge] AppData folder missing, creating via newFolder()', ['app' => 'figdeckbridge']);
            return $this->appData->newFolder('figdeckbridge');
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
