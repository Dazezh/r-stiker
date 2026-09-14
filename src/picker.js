/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Registers the Smart Picker element of the app.
 *
 * Clicking a sticker sends it right away (one click = one sticker message) when
 * the picker runs inside Nextcloud Talk, otherwise the absolute sticker URL is
 * handed back to the Smart Picker (Text, Notes, …).
 *
 * This bundle is small on purpose: the picker UI is loaded on demand.
 */

import { NcCustomPickerRenderResult, registerCustomPickerElement } from '@nextcloud/vue/components/NcRichText'
import '@nextcloud/dialogs/style.css'
import { REFERENCE_TYPE } from './constants.js'

registerCustomPickerElement(REFERENCE_TYPE, async (el) => {
	const { createApp } = await import('vue')
	const { default: StickerPicker } = await import('./views/StickerPicker.vue')

	const app = createApp(StickerPicker)
	app.mount(el)

	return new NcCustomPickerRenderResult(el, app)
}, (el, renderResult) => {
	renderResult?.object?.unmount?.()
})
