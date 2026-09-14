<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// Absolute sticker image URL used inside messages and reference widgets.
		['name' => 'sticker#getSticker', 'url' => '/s/{stickerId}', 'verb' => 'GET'],
	],
	'ocs' => [
		// Public read API (used by the Smart Picker).
		['name' => 'api#getPacks', 'url' => '/api/v1/packs', 'verb' => 'GET'],
		['name' => 'api#getPack', 'url' => '/api/v1/packs/{pack}', 'verb' => 'GET'],
		['name' => 'api#getStickers', 'url' => '/api/v1/stickers/{pack}', 'verb' => 'GET'],

		// Administration API: admin rights are enforced by the security middleware.
		['name' => 'api#createPack', 'url' => '/api/v1/packs', 'verb' => 'POST'],
		['name' => 'api#updatePack', 'url' => '/api/v1/packs/{pack}', 'verb' => 'PUT'],
		['name' => 'api#deletePack', 'url' => '/api/v1/packs/{pack}', 'verb' => 'DELETE'],
		['name' => 'api#addSticker', 'url' => '/api/v1/stickers/{pack}', 'verb' => 'POST'],
		['name' => 'api#updateSticker', 'url' => '/api/v1/stickers/{pack}/{sticker}', 'verb' => 'PUT'],
		['name' => 'api#deleteSticker', 'url' => '/api/v1/stickers/{pack}/{sticker}', 'verb' => 'DELETE'],
	],
];
