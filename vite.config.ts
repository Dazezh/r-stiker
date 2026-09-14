import { createAppConfig } from '@nextcloud/vite-config'

/**
 * Three independent bundles:
 *  - reference: registers the sticker reference widget (rendered in messages)
 *  - picker:    registers the sticker Smart Picker element
 *  - admin:     administration settings UI
 *
 * The entry files themselves stay tiny, the actual UI is loaded from lazy
 * chunks, so rendering a sticker never pulls in the picker UI.
 *
 * Output (see @nextcloud/vite-config): js/r-stiker-reference.mjs,
 * js/r-stiker-picker.mjs and js/r-stiker-admin.mjs
 */
export default createAppConfig({
	reference: 'src/reference.js',
	picker: 'src/picker.js',
	admin: 'src/admin.js',
}, {
	// styles are injected by the bundles, no extra css/ files are needed
	inlineCSS: { relativeCSSInjection: true },
})
