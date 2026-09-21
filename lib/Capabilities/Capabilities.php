<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Capabilities;

use OCA\RStiker\Reference\RStikerReferenceProvider;
use OCA\RStiker\Service\StickerService;
use OCP\Capabilities\ICapability;

/**
 * Describes the sticker protocol for other clients (web, desktop, mobile).
 */
class Capabilities implements ICapability {
	public function getCapabilities(): array {
		return [
			RStikerReferenceProvider::REFERENCE_TYPE => [
				'version' => 1,
				'protocol' => 1,
				'reference' => [
					'type' => RStikerReferenceProvider::REFERENCE_TYPE,
					'version' => 1,
				],
				'stickers' => [
					'formats' => StickerService::SUPPORTED_MIME_TYPES,
					// GET /api/v1/revision: clients can compare it with the
					// revision of their cached sticker list.
					'revision' => true,
				],
				'smart-picker' => true,
			],
		];
	}
}
