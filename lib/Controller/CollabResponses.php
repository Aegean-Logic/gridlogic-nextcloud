<?php

declare(strict_types=1);

namespace OCA\GridCraft\Controller;

use OCA\GridCraft\Service\NotMemberException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/** How the co-editing endpoints answer: what a call returns or throws, as JSON. */
trait CollabResponses {
	/** Runs `$f`, turning what it returns or throws into a JSON response. */
	private function guard(callable $f): DataResponse {
		try {
			$out = $f();
			return $out instanceof DataResponse ? $out : new DataResponse($out);
		} catch (NotMemberException $e) {
			return new DataResponse($e->outdated ? ['rejoin' => true, 'outdated' => true] : ['rejoin' => true], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotAllowed $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	private static function viewOnly(): DataResponse {
		return new DataResponse(['error' => 'You can view this file but not edit it.'], Http::STATUS_FORBIDDEN);
	}
}
