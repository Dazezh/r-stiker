/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Registers the reference widget of the app: rich objects of type "r-stiker"
 * are rendered as a bare sticker, never as a reference card.
 *
 * This bundle is loaded on every page which renders references, so it stays as
 * small as possible - the widget component itself is imported on demand.
 */

import { registerWidget } from '@nextcloud/vue/components/NcRichText'
import { REFERENCE_TYPE } from './constants.js'
// Hides the transport URL of sticker messages and the card chrome of the
// generic reference widget.
import './styles/reference.css'

registerWidget(REFERENCE_TYPE, async (el, { richObjectType, richObject, accessible }) => {
	const { createApp } = await import('vue')
	const { default: StickerReference } = await import('./views/StickerReference.vue')

	const app = createApp(StickerReference, { richObjectType, richObject, accessible })
	app.mount(el)

	// kept on the element so the destroy callback can unmount the app
	el.__rStikerApp = app
}, (el) => {
	el.__rStikerApp?.unmount()
	delete el.__rStikerApp
}, { hasInteractiveView: false })
