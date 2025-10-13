<?php

namespace OCA\FigDeckBridge\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\BackgroundJob\IJobList;
use OCA\FigDeckBridge\Cron\SyncJob;
use OCP\Util;

/**
 * Nextcloud 27–31 kompatibles Bootstrap für FigDeckBridge
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'figdeckbridge';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    /**
     * Registrierung (Dienste, Events, Cronjobs etc.)
     */
    public function register(IRegistrationContext $context): void {
        // Nichts statisch nötig — Cronjob wird dynamisch geprüft
    }

    /**
     * Wird beim Booten der App ausgeführt
     */
    public function boot(IBootContext $context): void {
        $server = \OC::$server;
    
        // 🧩 Cronjob einmalig sicherstellen
        /** @var IJobList $jobList */
        $jobList = $server->get(IJobList::class);
        if (!$jobList->has(SyncJob::class, null)) {
            $jobList->add(SyncJob::class, null);
        }
    
        // 🧠 Optional: JS & CSS für Admin-UI laden
        \OCP\Util::addScript('figdeckbridge', 'admin-settings');
        \OCP\Util::addStyle('figdeckbridge', 'style');
    
        // Log-Eintrag zur Bestätigung
        $server->getLogger()->info('[FigDeckBridge] Application booted successfully.', ['app' => self::APP_ID]);
    }
}
