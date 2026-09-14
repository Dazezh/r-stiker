<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Reference;

use OCA\RStiker\AppInfo\Application;
use OCA\RStiker\Service\StickerService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IPublicReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Resolves sticker URLs like
 *   https://cloud.example.com/apps/r-stiker/s/<stickerId>
 *
 * The URL is the transport format: it is always absolute, so it stays valid
 * when a message is federated to another Nextcloud instance. The sticker itself
 * is rendered by the custom "r-stiker" reference widget in the web UI.
 */
class RStikerReferenceProvider extends ADiscoverableReferenceProvider implements IPublicReferenceProvider {
	public const REFERENCE_TYPE = 'r-stiker';

	/**
	 * Matches absolute sticker URLs of any instance as well as relative ones
	 * (also when Nextcloud runs in a sub folder or with index.php).
	 * Sticker ids are url-safe base64, so they only contain [A-Za-z0-9_-].
	 */
	private const STICKER_URL_PATTERN = '#^(?:https?://[^\s]+)?(?:/[^\s/]+)*/apps/r-stiker/s/(?P<id>[A-Za-z0-9_-]{4,512})/?$#';

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly IRequest $request,
		private readonly StickerService $stickerService,
	) {
	}

	public function getId(): string {
		return self::REFERENCE_TYPE;
	}

	public function getTitle(): string {
		return $this->l10n->t('Stickers');
	}

	public function getOrder(): int {
		return 10;
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
		);
	}

	public function matchReference(string $referenceText): bool {
		return $this->getStickerId($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$stickerId = $this->getStickerId($referenceText);
		if ($stickerId === null) {
			return null;
		}

		$decoded = $this->stickerService->decodeStickerId($stickerId);
		if ($decoded === null) {
			return null;
		}
		[$packName, $fileName] = $decoded;

		if ($this->isLocalReference($referenceText)) {
			$sticker = $this->stickerService->getSticker($stickerId);
			if ($sticker === null) {
				return null;
			}
			$imageUrl = $sticker['resourceUrl'];
			$title = $sticker['title'];
			$packLabel = $sticker['packDisplayName'];
		} else {
			// The sticker is hosted by the instance the message came from.
			// Its absolute URL is self contained, so it is used as it is.
			$imageUrl = $referenceText;
			$title = pathinfo($fileName, PATHINFO_FILENAME);
			$packLabel = $packName;
		}

		$reference = new Reference($referenceText);
		// Generic information for clients and for contexts without the custom widget.
		$reference->setTitle($title);
		$reference->setDescription($packLabel);
		$reference->setImageUrl($imageUrl);
		$reference->setRichObject(self::REFERENCE_TYPE, [
			'version' => 1,
			'id' => $stickerId,
			'pack' => [
				'name' => $packLabel,
			],
			'sticker' => [
				'name' => $fileName,
				'title' => $title,
				'image_url' => $imageUrl,
				'thumbnail_url' => $imageUrl,
			],
		]);

		return $reference;
	}

	public function resolveReferencePublic(string $referenceText, string $sharingToken): ?IReference {
		// Sticker images are public, they can be resolved from public shares too.
		return $this->resolveReference($referenceText);
	}

	public function getCachePrefix(string $referenceId): string {
		return self::REFERENCE_TYPE;
	}

	public function getCacheKey(string $referenceId): ?string {
		// Sticker URLs are identical for every user.
		return null;
	}

	public function getCacheKeyPublic(string $referenceId, string $sharingToken): ?string {
		return null;
	}

	private function getStickerId(string $referenceText): ?string {
		if (preg_match(self::STICKER_URL_PATTERN, trim($referenceText), $matches) !== 1) {
			return null;
		}

		return $matches['id'];
	}

	/**
	 * Is the reference URL pointing at this Nextcloud instance?
	 */
	private function isLocalReference(string $referenceText): bool {
		if (!str_starts_with($referenceText, 'http://') && !str_starts_with($referenceText, 'https://')) {
			return true;
		}

		$host = parse_url($referenceText, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return false;
		}
		$port = parse_url($referenceText, PHP_URL_PORT);
		if (is_int($port)) {
			$host .= ':' . $port;
		}

		return strcasecmp($host, $this->request->getServerHost()) === 0;
	}
}
