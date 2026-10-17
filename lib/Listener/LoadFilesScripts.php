<?php

declare(strict_types=1);

namespace OCA\GridCraft\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\GridCraft\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesScripts implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		Util::addInitScript(Application::APP_ID, 'gridcraft-files');
	}
}
