/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/** Conversation tokens are short lowercase alphanumeric strings. */
const TOKEN_PATTERN = /^[a-z0-9]{1,64}$/i

/**
 * Find the conversation token of the Talk instance the picker was opened in.
 *
 * Talk exposes its app instance (window.OCA.Talk.instance) - the router of that
 * instance knows the current conversation for both the full Talk app and the
 * Talk sidebar in Files. The URL is used as a fallback.
 *
 * @return {string|null} conversation token or null if this is not Talk
 */
export function getTalkToken() {
	return getTokenFromTalkRouter() ?? getTokenFromLocation()
}

/**
 * Send the sticker into a Talk conversation. This is the standard Talk chat API,
 * so no Talk code has to be patched: the sticker URL is posted as message.
 *
 * @param {string} token conversation token
 * @param {string} message message content (the absolute sticker URL)
 * @return {Promise<void>}
 */
export async function sendTalkMessage(token, message) {
	await axios.post(
		generateOcsUrl('apps/spreed/api/v1/chat/{token}', { token }),
		{ message }
	)
}

/**
 * @return {string|null}
 */
function getTokenFromTalkRouter() {
	try {
		const router = window.OCA?.Talk?.instance?.config?.globalProperties?.$router
		const route = router?.currentRoute?.value
		const candidates = [route?.params?.token, route?.query?.token]

		for (const candidate of candidates) {
			if (typeof candidate === 'string' && TOKEN_PATTERN.test(candidate)) {
				return candidate
			}
		}
	} catch (error) {
		// Talk is not open or its public API changed: fall back to the URL
		console.debug('r-stiker: could not read the Talk router', error)
	}

	return null
}

/**
 * @return {string|null}
 */
function getTokenFromLocation() {
	const sources = [window.location.pathname, window.location.hash, window.location.search]

	for (const source of sources) {
		const match = /\/call\/([a-z0-9]+)/i.exec(source) ?? /[?&]token=([a-z0-9]+)/i.exec(source)
		if (match && TOKEN_PATTERN.test(match[1])) {
			return match[1]
		}
	}

	return null
}
