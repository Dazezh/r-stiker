<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\RStiker\Listener;

use OCA\RStiker\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * The host app (Talk, Text, Collectives, …) dispatches this event right before
 * rendering references, so the sticker widget and the sticker Smart Picker
 * element can be registered in the frontend.
 *
 * Both bundles are tiny: the actual UI is loaded lazily from separate chunks.
 *
 * @template-implements IEventListener<RenderReferenceEvent>
 */
class RStikerReferenceListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}

		Util::addScript(Application::APP_ID, Application::APP_ID . '-reference');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-picker');
	}
}
