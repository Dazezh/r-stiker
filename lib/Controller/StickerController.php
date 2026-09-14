<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Controller;

use OCA\RStiker\Service\StickerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves the sticker image itself: /apps/r-stiker/s/{stickerId}
 */
class StickerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StickerService $stickerService,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getSticker(string $stickerId): Response {
		$sticker = $this->stickerService->getStickerContent($stickerId);
		if ($sticker === null) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse(
			$sticker['content'],
			Http::STATUS_OK,
			[
				'Content-Type' => $sticker['mime'],
				'Content-Length' => (string)strlen($sticker['content']),
			]
		);
		$response->setETag($sticker['etag']);
		// Sticker URLs are immutable: replacing an image creates a new sticker id.
		$response->cacheFor(60 * 60 * 24 * 7, true, true);

		return $response;
	}
}
