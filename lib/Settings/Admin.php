<?php

namespace OCA\FigDeckBridge\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Files\NotFoundException;

class Admin implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm() {
        $connected = false;

        try {
            // Zugriff auf AppData über Server-Container (kompatibel mit NC 27–30)
            $appData = \OC::$server->get(\OCP\Files\IAppData::class);
            $folder = $appData->getFolder('figdeckbridge');
            $connected = $folder->fileExists('figma_token.json');
        } catch (NotFoundException $e) {
            $connected = false;
        } catch (\Throwable $e) {
            \OC::$server->getLogger()->warning('[FigDeckBridge] Fehler beim Zugriff auf AppData: ' . $e->getMessage(), ['app' => 'figdeckbridge']);
        }

        // Konfiguration laden
        $params = [
            'client_id'     => $this->config->getAppValue('figdeckbridge', 'client_id', ''),
            'client_secret' => $this->config->getAppValue('figdeckbridge', 'client_secret', ''),
            'mode'          => $this->config->getAppValue('figdeckbridge', 'mode', 'manual'),
            'connected'     => $connected
        ];

        return new TemplateResponse('figdeckbridge', 'admin', $params, 'blank');
    }

    public function getSection(): string {
        // So heißt die Section (entspricht URL /settings/admin/figdeckbridge)
        return 'figdeckbridge';
    }

    public function getPriority(): int {
        return 50;
    }
}
