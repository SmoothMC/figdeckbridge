<?php

namespace OCA\FigDeckBridge\Cron;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;

class SyncJob extends TimedJob {

    public function __construct(ITimeFactory $timeFactory) {
        parent::__construct($timeFactory);
        // alle 15 Minuten
        $this->setInterval(15 * 60);
    }

    protected function run($argument) {
        $baseDir = \OC::$SERVERROOT . '/apps/figdeckbridge';
        $pollScript = $baseDir . '/poll-comments.php';

        if (file_exists($pollScript)) {
            exec("php " . escapeshellarg($pollScript) . " >/dev/null 2>&1 &");
        }
    }
}
