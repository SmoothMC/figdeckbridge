<?php

namespace OCA\FigDeckBridge\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;

// 🧩 Prüfen, welche Nextcloud-Version läuft, um das richtige Interface zu laden
if (interface_exists('\OCP\Settings\Admin\ISection')) {
    // NC 30+
    use OCP\Settings\Admin\ISection;
} else {
    // NC <30
    use OCP\Settings\ISection;
}

class Section implements ISection {

    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * ID der Sektion (z. B. figdeckbridge)
     */
    public function getID(): string {
        return 'figdeckbridge';
    }

    /**
     * Titel im Menü
     */
    public function getName(): string {
        return $this->l->t('FigDeck Bridge');
    }

    /**
     * Icon im Menü
     */
    public function getIcon(): string {
        return $this->urlGenerator->imagePath('figdeckbridge', 'app-dark.svg');
    }

    /**
     * Position der Sektion
     */
    public function getPriority(): int {
        return 50;
    }
}
