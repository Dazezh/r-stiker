<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\AppInfo;

use OCA\RStiker\Capabilities\Capabilities;
use OCA\RStiker\Listener\RStikerReferenceListener;
use OCA\RStiker\Reference\RStikerReferenceProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Collaboration\Reference\RenderReferenceEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'r-stiker';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerReferenceProvider(RStikerReferenceProvider::class);
		$context->registerEventListener(RenderReferenceEvent::class, RStikerReferenceListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
