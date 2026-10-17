<?php

declare(strict_types=1);

namespace OCA\GridCraft\Controller;

use OCA\GridCraft\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class EditorController extends Controller {
	public function __construct(
		IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The editor page: GridLogic in an iframe on this origin. The page's script reads and
	 * writes the file over WebDAV with the user's session; `path` is relative to their files.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/edit')]
	public function edit(string $path = ''): TemplateResponse {
		Util::addScript(Application::APP_ID, 'gridcraft-editor');
		Util::addStyle(Application::APP_ID, 'editor');
		$response = new TemplateResponse(Application::APP_ID, 'editor', [
			'path' => $path,
			'editorUrl' => $this->urlGenerator->linkTo(Application::APP_ID, 'editor/index.html'),
		]);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
