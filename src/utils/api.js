/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { APP_ID } from '../constants.js'

/**
 * Pull the payload out of an OCS response.
 *
 * @param {object} response axios response
 * @return {any}
 */
function payload(response) {
	return response.data?.ocs?.data ?? null
}

/**
 * Human readable message of a failed request.
 *
 * @param {any} error the caught error
 * @return {string}
 */
export function messageFromError(error) {
	return error?.response?.data?.ocs?.meta?.message
		?? error?.response?.data?.error
		?? error?.message
		?? 'Unknown error'
}

/** Public API */

/**
 * @return {Promise<Array>} all sticker packs
 */
export async function fetchPacks() {
	return payload(await axios.get(generateOcsUrl(`apps/${APP_ID}/api/v1/packs`))) ?? []
}

/**
 * Revision of the whole sticker library.
 *
 * The Smart Picker keeps a local copy of the sticker list and compares the
 * revision of that copy with this one: they are equal as long as nothing was
 * added, removed or renamed, so the stickers themselves do not have to be
 * downloaded at all.
 *
 * @return {Promise<string|null>} null when the revision is not available
 */
export async function fetchRevision() {
	try {
		const data = payload(await axios.get(generateOcsUrl(`apps/${APP_ID}/api/v1/revision`)))
		return typeof data?.revision === 'string' && data.revision !== '' ? data.revision : null
	} catch (error) {
		// A missing revision is not an error: the cached list is used as it is
		// and the caller falls back to comparing the pack list.
		console.debug('r-stiker: could not read the sticker revision', error)
		return null
	}
}

/**
 * @param {string} pack pack name
 * @param {number} cursor offset
 * @param {number} limit page size
 * @return {Promise<object>} sticker page
 */
export async function fetchStickers(pack, cursor = 0, limit = 60) {
	return payload(await axios.get(
		generateOcsUrl(
			`apps/${APP_ID}/api/v1/stickers/{pack}?cursor={cursor}&limit={limit}`,
			{ pack, cursor, limit }
		)
	)) ?? { entries: [], cursor: null, total: 0 }
}

/** Administration API */

/**
 * @param {{name: string, displayName?: string, description?: string}} pack pack data
 * @return {Promise<object>} the created pack
 */
export async function createPack(pack) {
	return payload(await axios.post(generateOcsUrl(`apps/${APP_ID}/api/v1/packs`), pack))
}

/**
 * @param {string} pack current pack name
 * @param {{name?: string, displayName?: string, description?: string}} data changed data
 * @return {Promise<object>} the updated pack
 */
export async function updatePack(pack, data) {
	return payload(await axios.put(generateOcsUrl(`apps/${APP_ID}/api/v1/packs/{pack}`, { pack }), data))
}

/**
 * @param {string} pack pack name
 */
export async function deletePack(pack) {
	return payload(await axios.delete(generateOcsUrl(`apps/${APP_ID}/api/v1/packs/{pack}`, { pack })))
}

/**
 * Upload a single sticker.
 *
 * @param {string} pack pack name
 * @param {File} file the sticker file
 * @return {Promise<object>} the new sticker entry
 */
export async function uploadSticker(pack, file) {
	const formData = new FormData()
	formData.append('file', file, file.name)

	return payload(await axios.post(
		generateOcsUrl(`apps/${APP_ID}/api/v1/stickers/{pack}`, { pack }),
		formData
	))
}

/**
 * @param {string} pack pack name
 * @param {string} stickerId sticker id
 * @param {string} title new title
 * @return {Promise<object>} the updated sticker entry
 */
export async function updateSticker(pack, stickerId, title) {
	return payload(await axios.put(
		generateOcsUrl(`apps/${APP_ID}/api/v1/stickers/{pack}/{sticker}`, { pack, sticker: stickerId }),
		{ title }
	))
}

/**
 * @param {string} pack pack name
 * @param {string} stickerId sticker id
 */
export async function deleteSticker(pack, stickerId) {
	return payload(await axios.delete(
		generateOcsUrl(`apps/${APP_ID}/api/v1/stickers/{pack}/{sticker}`, { pack, sticker: stickerId })
	))
}
