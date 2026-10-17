<?php

declare(strict_types=1);

namespace OCA\GridCraft\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\GridCraft\Listener\LoadFilesScripts;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'gridcraft';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// The Files app loads our "Open in GridLogic" action.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScripts::class);
	}

	public function boot(IBootContext $context): void {
	}
}
