<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Controller;

use OCA\RStiker\Service\StickerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Sticker API.
 *
 * Reading is public (the Smart Picker and sticker widgets are used in public
 * conversations too). Everything which changes stickers requires admin rights,
 * which is enforced by the security middleware as long as no NoAdminRequired
 * attribute is set.
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly StickerService $stickerService,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getPacks(): DataResponse {
		return new DataResponse($this->stickerService->getPacks());
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getPack(string $pack): DataResponse {
		$pack = $this->stickerService->getPack($pack);
		if ($pack === null) {
			return new DataResponse(['error' => 'Pack not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($pack);
	}

	/**
	 * Revision of the whole sticker library. The Smart Picker compares it with
	 * the revision of its locally cached sticker list to decide whether the
	 * cached copy is still up to date.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function getRevision(): DataResponse {
		$response = new DataResponse(['revision' => $this->stickerService->getRevision()]);
		// The answer has to be fresh on every call, a cached revision would keep
		// the frontend on its outdated copy forever.
		$response->cacheFor(0);

		return $response;
	}

	/**
	 * Sticker page of a pack.
	 *
	 * Deliberately not HTTP-cached: the frontend keeps its own copy of the list
	 * and only reloads it when the revision changed (see getRevision), so a
	 * browser cache would hand out exactly the outdated list this mechanism was
	 * built to avoid.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function getStickers(string $pack, int $cursor = 0, int $limit = 60): DataResponse {
		return new DataResponse($this->stickerService->getStickers($pack, $cursor, $limit));
	}

	public function createPack(string $name = '', ?string $displayName = null, ?string $description = null): DataResponse {
		try {
			$pack = $this->stickerService->createPack($name, $displayName, $description);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($pack, Http::STATUS_CREATED);
	}

	public function updatePack(string $pack, ?string $name = null, ?string $displayName = null, ?string $description = null): DataResponse {
		try {
			$pack = $this->stickerService->updatePack($pack, $name, $displayName, $description);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($pack);
	}

	public function deletePack(string $pack): DataResponse {
		try {
			$this->stickerService->deletePack($pack);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['deleted' => true]);
	}

	public function addSticker(string $pack, ?string $title = null): DataResponse {
		$upload = $this->request->getUploadedFile('file');
		if (!is_array($upload)) {
			return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => $this->describeUploadError($error)], Http::STATUS_BAD_REQUEST);
		}

		$tmpName = (string)($upload['tmp_name'] ?? '');
		if ($tmpName === '' || !is_uploaded_file($tmpName)) {
			return new DataResponse(['error' => 'Invalid upload'], Http::STATUS_BAD_REQUEST);
		}

		$size = (int)($upload['size'] ?? 0);
		if ($size <= 0) {
			return new DataResponse(['error' => 'Empty file'], Http::STATUS_BAD_REQUEST);
		}
		if ($size > StickerService::MAX_FILE_SIZE) {
			return new DataResponse(
				['error' => 'The sticker is too large, the limit is ' . StickerService::MAX_FILE_SIZE . ' bytes'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$sticker = $this->stickerService->addSticker($pack, $tmpName, (string)($upload['name'] ?? ''), $title);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse($sticker, Http::STATUS_CREATED);
	}

	public function updateSticker(string $pack, string $sticker, ?string $title = null): DataResponse {
		try {
			$sticker = $this->stickerService->updateSticker($pack, $sticker, $title);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse($sticker);
	}

	public function deleteSticker(string $pack, string $sticker): DataResponse {
		try {
			$this->stickerService->deleteSticker($pack, $sticker);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse(['deleted' => true]);
	}

	private function describeUploadError(int $code): string {
		return match ($code) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The sticker is too large',
			UPLOAD_ERR_PARTIAL => 'The upload was interrupted',
			UPLOAD_ERR_NO_FILE => 'No file uploaded',
			default => 'The file could not be uploaded',
		};
	}
}
