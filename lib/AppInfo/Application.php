<?php

declare(strict_types=1);

namespace OCA\GridCraft\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCA\GridCraft\Listener\LoadFilesScripts;
use OCA\GridCraft\Listener\LoadPublicFolderScripts;
use OCA\GridCraft\Share\EditorPageProvider;
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
		// A shared link to a spreadsheet opens in GridLogic.
		$context->registerPublicShareTemplateProvider(EditorPageProvider::class);
		// A link to a folder: its spreadsheets open in GridLogic too.
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, LoadPublicFolderScripts::class);
	}

	public function boot(IBootContext $context): void {
	}
}
