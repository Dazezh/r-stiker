/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Local copy of the sticker list.
 *
 * The Smart Picker paints this copy right away and verifies it in the
 * background, so the list does not have to be downloaded on every open. The
 * verification is a single request for the library revision (see the `revision`
 * API); the stickers themselves are only fetched when the revision changed.
 *
 * Every cached page remembers the revision it was stored at. As soon as the
 * server reports another revision, pages of the old revision are ignored and
 * dropped - a cached list is only ever used while it is known to be current.
 */

import { STICKER_CACHE_KEY } from '../constants.js'

/** Shape version of the cache. Caches of other versions are thrown away. */
const CACHE_VERSION = 1

/** Only the packs which were opened last are cached, not the whole library. */
const MAX_CACHED_PACKS = 12

/** Upper bound for the cached stickers of one pack (storage is limited). */
const MAX_CACHED_STICKERS = 240

/** A cache which was not refreshed for that long is not trusted anymore. */
const MAX_CACHE_AGE = 7 * 24 * 60 * 60 * 1000

let cache = null

/**
 * @return {object} an empty cache
 */
function emptyCache() {
	return { version: CACHE_VERSION, revision: null, packs: [], pages: {}, savedAt: 0 }
}

/**
 * @param {*} pack possible pack entry
 * @return {boolean}
 */
function isPack(pack) {
	return Boolean(pack)
		&& typeof pack.name === 'string'
		&& typeof pack.displayName === 'string'
}

/**
 * Cached URLs are absolute and were built for the origin the sticker list was
 * downloaded from. Another domain or a proxy of the same instance would make
 * them unreachable, so they are re-pointed at the current origin.
 *
 * @param {*} url cached URL
 * @return {string}
 */
function currentOrigin(url) {
	if (typeof url !== 'string' || url === '') {
		return ''
	}

	try {
		const parsed = new URL(url, window.location.href)
		if (parsed.origin === window.location.origin) {
			return url
		}
		return window.location.origin + parsed.pathname + parsed.search
	} catch (error) {
		return url
	}
}

/**
 * @param {*} entry possible sticker entry
 * @return {object|null} the entry with reachable URLs, null when unusable
 */
function normalizeSticker(entry) {
	if (!entry || typeof entry.id !== 'string' || typeof entry.resourceUrl !== 'string') {
		return null
	}

	const resourceUrl = currentOrigin(entry.resourceUrl)
	return {
		...entry,
		thumbnailUrl: currentOrigin(entry.thumbnailUrl) || resourceUrl,
		resourceUrl,
	}
}

/**
 * @param {*} pages stored pages
 * @return {object} pages which can be used, keyed by pack name
 */
function readPages(pages) {
	const result = {}
	if (!pages || typeof pages !== 'object') {
		return result
	}

	Object.entries(pages).forEach(([pack, page]) => {
		if (typeof pack !== 'string' || !page || !Array.isArray(page.entries)) {
			return
		}

		const entries = page.entries
			.map(normalizeSticker)
			.filter((entry) => entry !== null)
			.slice(0, MAX_CACHED_STICKERS)
		if (entries.length === 0) {
			return
		}

		result[pack] = {
			revision: typeof page.revision === 'string' ? page.revision : null,
			entries,
			cursor: page.cursor === null ? null : Number(page.cursor) || 0,
			at: Number(page.at) || 0,
		}
	})

	return result
}

/**
 * @param {*} stored parsed content of the cache key
 * @return {boolean} true when it may be used
 */
function isUsable(stored) {
	return Boolean(stored)
		&& stored.version === CACHE_VERSION
		&& Array.isArray(stored.packs)
		&& Date.now() - (Number(stored.savedAt) || 0) < MAX_CACHE_AGE
}

/**
 * The parsed cache, read from the storage on first use.
 *
 * @return {object}
 */
function getCache() {
	if (cache !== null) {
		return cache
	}

	cache = emptyCache()
	try {
		const raw = localStorage.getItem(STICKER_CACHE_KEY)
		const stored = raw === null ? null : JSON.parse(raw)
		if (isUsable(stored)) {
			cache.revision = typeof stored.revision === 'string' ? stored.revision : null
			cache.packs = stored.packs.filter(isPack)
			cache.pages = readPages(stored.pages)
			cache.savedAt = Number(stored.savedAt) || 0
		}
	} catch (error) {
		console.debug('r-stiker: could not read the sticker cache', error)
		cache = emptyCache()
	}

	return cache
}

/**
 * @return {boolean} true when the cache could be stored
 */
function writeCache() {
	cache.savedAt = Date.now()
	try {
		localStorage.setItem(STICKER_CACHE_KEY, JSON.stringify(cache))
		return true
	} catch (error) {
		console.debug('r-stiker: could not store the sticker cache', error)
		return false
	}
}

/**
 * Keep the cache small: the pages which were opened last stay.
 *
 * @param {number} keep how many pack pages to keep
 */
function trimPages(keep) {
	cache.pages = Object.fromEntries(
		Object.entries(cache.pages)
			.sort((a, b) => b[1].at - a[1].at)
			.slice(0, keep)
	)
}

function persist() {
	trimPages(MAX_CACHED_PACKS)
	if (writeCache()) {
		return
	}

	// Most likely the storage quota is full: shrink the cache and retry once.
	trimPages(2)
	if (writeCache()) {
		return
	}

	clearStickerCache()
}

/**
 * @return {Array} the cached packs, empty when nothing is cached
 */
export function getCachedPacks() {
	return getCache().packs.map((pack) => ({ ...pack }))
}

/**
 * @return {string|null} revision the cached list was stored at
 */
export function getCachedRevision() {
	return getCache().revision
}

/**
 * Cached stickers of a pack. Pages which were stored at another revision are
 * ignored: they describe a state of the library which does not exist anymore.
 *
 * @param {string} pack pack name
 * @return {{entries: Array, cursor: number|null}|null} null when there is no usable copy
 */
export function getCachedStickers(pack) {
	const state = getCache()
	const page = state.pages[pack]
	if (!page || page.revision !== state.revision) {
		return null
	}

	return {
		entries: page.entries.map((entry) => ({ ...entry })),
		cursor: page.cursor,
	}
}

/**
 * Remember the packs and the revision they were read at. Pages of an older
 * revision are worthless from now on and are dropped.
 *
 * @param {Array} packs packs as delivered by the API
 * @param {string|null} revision library revision, null when it is unavailable
 */
export function storePacks(packs, revision) {
	const state = getCache()
	state.packs = packs.filter(isPack)

	if (revision !== null && revision !== state.revision) {
		state.pages = {}
	}
	state.revision = revision

	persist()
}

/**
 * Remember the loaded stickers of a pack.
 *
 * @param {string} pack pack name
 * @param {Array} entries sticker entries
 * @param {number|null} cursor offset of the next page, null when all are loaded
 */
export function storeStickers(pack, entries, cursor) {
	if (pack === '' || !Array.isArray(entries) || entries.length === 0) {
		return
	}

	const state = getCache()
	const kept = entries
		.map(normalizeSticker)
		.filter((entry) => entry !== null)
		.slice(0, MAX_CACHED_STICKERS)

	state.pages[pack] = {
		revision: state.revision,
		entries: kept,
		// A shortened list has to continue behind its last cached sticker,
		// otherwise the stickers in between would never be loaded.
		cursor: kept.length < entries.length ? kept.length : cursor,
		at: Date.now(),
	}

	persist()
}

/**
 * Drop the whole cache. Used when it turns out to be unusable.
 */
export function clearStickerCache() {
	cache = emptyCache()
	try {
		localStorage.removeItem(STICKER_CACHE_KEY)
	} catch (error) {
		console.debug('r-stiker: could not clear the sticker cache', error)
	}
}
