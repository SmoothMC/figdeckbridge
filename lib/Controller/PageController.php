<?php

namespace OCA\FigDeckBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    /**
     * Hauptseite im Nextcloud-UI
     */
    public function index(): TemplateResponse {
        return new TemplateResponse('figdeckbridge', 'main', []);
    }
}
