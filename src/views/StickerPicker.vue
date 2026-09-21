<!--
  - SPDX-FileCopyrightText: 2026 R-Stiker contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sticker-picker">
		<div class="sticker-picker__tabs">
			<button
				v-for="tab in tabs"
				:key="tab.key"
				type="button"
				class="sticker-picker__tab"
				:class="{ 'sticker-picker__tab--active': tab.key === activeTab }"
				@click="selectTab(tab.key)">
				{{ tab.label }}
			</button>
		</div>

		<p v-if="refreshing" class="sticker-picker__notice">
			<NcLoadingIcon :size="16" />
			{{ t('r-stiker', 'Обновление списка стикеров…') }}
		</p>

		<div v-if="loadingPacks || loading" class="sticker-picker__status">
			<NcLoadingIcon :size="32" />
		</div>

		<p v-else-if="visibleStickers.length === 0" class="sticker-picker__status">
			{{ emptyMessage }}
		</p>

		<div v-else class="sticker-picker__grid">
			<div v-for="sticker in visibleStickers" :key="sticker.id" class="sticker-picker__cell">
				<button
					type="button"
					class="sticker-picker__sticker"
					:title="sticker.title"
					@click="sendSticker(sticker)">
					<img
						class="sticker-picker__image"
						:src="sticker.thumbnailUrl"
						:alt="sticker.title"
						loading="lazy"
						decoding="async">
				</button>
				<button
					type="button"
					class="sticker-picker__favorite"
					:class="{ 'sticker-picker__favorite--active': isFavorite(sticker) }"
					:title="isFavorite(sticker) ? t('r-stiker', 'Убрать из избранного') : t('r-stiker', 'В избранное')"
					@click.stop="toggleFavorite(sticker)">
					{{ isFavorite(sticker) ? '★' : '☆' }}
				</button>
			</div>
		</div>

		<div ref="sentinel" class="sticker-picker__sentinel" />

		<div v-if="loadingMore" class="sticker-picker__status">
			<NcLoadingIcon :size="24" />
		</div>
		<div v-else-if="hasMore" class="sticker-picker__more">
			<NcButton variant="secondary" @click="loadMore()">
				{{ t('r-stiker', 'Загрузить ещё') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { FAVORITES_STORAGE_KEY, STICKERS_PER_PAGE } from '../constants.js'
import { fetchPacks, fetchRevision, fetchStickers, messageFromError } from '../utils/api.js'
import {
	getCachedPacks,
	getCachedRevision,
	getCachedStickers,
	storePacks,
	storeStickers,
} from '../utils/cache.js'
import { getTalkToken, sendTalkMessage } from '../utils/talk.js'

const FAVORITES_TAB = 'favorites'

/**
 * Smart Picker UI of the app.
 *
 * A sticker is sent immediately: inside Talk the absolute sticker URL is posted
 * through the Talk chat API, everywhere else the URL is returned to the Smart
 * Picker so it can be inserted into the document.
 *
 * The sticker list is kept in localStorage. The picker opens with that copy and
 * checks the library revision in the background: the list is only rebuilt (and
 * the user told about it) when something really changed on the server.
 */
export default {
	name: 'StickerPicker',

	components: {
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			packs: [],
			stickers: [],
			favorites: [],
			activeTab: FAVORITES_TAB,
			cursor: null,
			hasMore: false,
			/** pack the displayed stickers belong to, null while nothing is loaded */
			loadedPack: null,
			/** revision of the cached sticker list, null when there is no cache */
			revision: null,
			/** nothing to show yet: the first paint waits for the server */
			loadingPacks: true,
			/** the list is loaded and there is nothing to display yet */
			loading: false,
			/** the list is refreshed while the old copy stays on screen */
			refreshing: false,
			loadingMore: false,
			/** the cached list is compared with the server */
			syncing: false,
			sending: false,
			observer: null,
		}
	},

	computed: {
		tabs() {
			return [
				{ key: FAVORITES_TAB, label: t('r-stiker', '★ Избранное') },
				...this.packs
					.filter((pack) => (pack.stickerCount ?? 0) > 0)
					.map((pack) => ({ key: pack.name, label: pack.displayName || pack.name })),
			]
		},

		isFavoritesTab() {
			return this.activeTab === FAVORITES_TAB
		},

		visibleStickers() {
			return this.isFavoritesTab ? this.favorites : this.stickers
		},

		emptyMessage() {
			return this.isFavoritesTab
				? t('r-stiker', 'Нет избранных стикеров')
				: t('r-stiker', 'В этом паке нет стикеров')
		},
	},

	mounted() {
		this.loadFavorites()
		// Show the cached copy at once, then check it against the server.
		this.sync(!this.restoreFromCache())
	},

	beforeUnmount() {
		this.disconnectObserver()
	},

	methods: {
		t,

		/**
		 * Paint the sticker list of the last visit. Nothing is requested here:
		 * the copy is only shown when it belongs to the current revision, the
		 * background sync takes care of everything else.
		 *
		 * @return {boolean} true when a cached copy is on screen now
		 */
		restoreFromCache() {
			const packs = getCachedPacks()
			if (packs.length === 0) {
				return false
			}

			this.packs = packs
			this.revision = getCachedRevision()
			this.loadingPacks = false

			this.activeTab = this.openTab()
			if (!this.isFavoritesTab) {
				this.showCachedStickers(this.activeTab)
			}
			this.$nextTick(() => this.observeSentinel())

			return true
		},

		/**
		 * Bring the sticker list in line with the server.
		 *
		 * With a cached list on screen this runs in the background and the list
		 * is only rebuilt when the library revision changed. Without a cache
		 * there is nothing to show, so the first paint waits for this request.
		 *
		 * @param {boolean} blocking true when the list has to be loaded first
		 */
		async sync(blocking = false) {
			if (this.syncing) {
				return
			}
			this.syncing = true
			if (blocking) {
				this.loadingPacks = true
			}

			try {
				const [packs, revision] = await Promise.all([fetchPacks(), fetchRevision()])
				const packsChanged = JSON.stringify(packs) !== JSON.stringify(this.packs)
				// The revision is the reliable signal. Without it (endpoint not
				// available) the pack list is all we can compare.
				const outdated = revision !== null ? revision !== this.revision : packsChanged
				const cached = this.isFavoritesTab || this.loadedPack === this.activeTab

				if (!blocking && !outdated && cached) {
					return
				}

				this.packs = packs
				storePacks(packs, revision)
				this.revision = revision

				// The pack of the open tab may have been renamed or removed.
				const tab = this.openTab()
				if (tab !== this.activeTab) {
					this.activeTab = tab
					this.stickers = []
					this.cursor = null
					this.hasMore = false
					this.loadedPack = null
				}

				if (!this.isFavoritesTab) {
					if (this.loadedPack === this.activeTab) {
						this.refreshing = true
					} else {
						this.loading = true
					}
					this.applyStickers(await this.requestStickers(true))
					this.loading = false
					this.refreshing = false
				}

				if (!blocking && outdated) {
					showSuccess(t('r-stiker', 'Список стикеров обновлён'))
				}
			} catch (error) {
				console.debug('r-stiker: could not update the sticker list', error)
				if (blocking) {
					showError(messageFromError(error))
					this.packs = []
				}
			} finally {
				this.loadingPacks = false
				this.loading = false
				this.refreshing = false
				this.syncing = false
				this.$nextTick(() => {
					this.observeSentinel()
					// The user may have opened a pack which is still missing while
					// the sync was running.
					if (!this.isFavoritesTab && this.loadedPack !== this.activeTab) {
						this.loadStickers(true)
					}
				})
			}
		},

		/**
		 * @return {string} tab which should be open
		 */
		openTab() {
			const known = this.tabs.some((tab) => tab.key === this.activeTab)
			if (known && (!this.isFavoritesTab || this.favorites.length > 0)) {
				return this.activeTab
			}

			return this.defaultTab()
		},

		/**
		 * @return {string} tab a fresh picker starts with
		 */
		defaultTab() {
			if (this.favorites.length > 0) {
				return FAVORITES_TAB
			}

			const pack = this.tabs.find((tab) => tab.key !== FAVORITES_TAB)
			return pack ? pack.key : FAVORITES_TAB
		},

		/**
		 * Show the cached stickers of a pack.
		 *
		 * @param {string} pack pack name
		 * @return {boolean} true when a cached page was found
		 */
		showCachedStickers(pack) {
			const cached = getCachedStickers(pack)
			if (cached === null) {
				return false
			}

			this.stickers = cached.entries
			this.cursor = cached.cursor
			this.hasMore = cached.cursor !== null
			this.loadedPack = pack

			return true
		},

		/**
		 * Fetch one page of the open pack.
		 *
		 * @param {boolean} reset start at the beginning instead of appending
		 * @return {Promise<object|null>} the page, null when the request failed
		 */
		async requestStickers(reset) {
			if (this.isFavoritesTab) {
				return null
			}

			const pack = this.activeTab
			const cursor = reset ? 0 : (this.cursor ?? 0)

			try {
				const data = await fetchStickers(pack, cursor, STICKERS_PER_PAGE)
				return {
					pack,
					reset,
					entries: Array.isArray(data?.entries) ? data.entries : [],
					cursor: data?.cursor ?? null,
				}
			} catch (error) {
				console.debug('r-stiker: could not load stickers', error)
				showError(messageFromError(error))
				return null
			}
		},

		/**
		 * Show a fetched page and remember it for the next visit.
		 *
		 * @param {object|null} result page as returned by requestStickers()
		 */
		applyStickers(result) {
			// A page which arrived after the user switched to another pack is
			// dropped instead of being shown in the wrong tab.
			if (result === null || result.pack !== this.activeTab) {
				return
			}

			this.stickers = result.reset ? result.entries : [...this.stickers, ...result.entries]
			this.cursor = result.cursor
			this.hasMore = result.cursor !== null
			this.loadedPack = result.pack

			storeStickers(result.pack, this.stickers, this.cursor)
			this.syncFavorites(result.entries)
		},

		/**
		 * Replace stored favourites with the fresh data of a loaded page, so a
		 * renamed sticker keeps its current title and URL.
		 *
		 * @param {Array} entries freshly loaded sticker entries
		 */
		syncFavorites(entries) {
			if (this.favorites.length === 0 || entries.length === 0) {
				return
			}

			const fresh = new Map(entries.map((entry) => [entry.id, entry]))
			let changed = false
			const favorites = this.favorites.map((favorite) => {
				const entry = fresh.get(favorite.id)
				if (!entry || JSON.stringify(entry) === JSON.stringify(favorite)) {
					return favorite
				}
				changed = true
				return entry
			})

			if (changed) {
				this.favorites = favorites
				this.saveFavorites()
			}
		},

		selectTab(key) {
			if (key === this.activeTab) {
				return
			}

			this.activeTab = key
			this.stickers = []
			this.cursor = null
			this.hasMore = false
			this.loadedPack = null

			if (this.isFavoritesTab) {
				this.$nextTick(() => this.observeSentinel())
				return
			}

			// Known packs open instantly from the cache and are only reloaded
			// when the background sync has not confirmed the copy yet.
			if (this.showCachedStickers(key)) {
				this.$nextTick(() => this.observeSentinel())
			} else {
				this.loadStickers(true)
			}
		},

		/**
		 * @param {boolean} reset replace the list instead of appending
		 */
		async loadStickers(reset = false) {
			if (this.isFavoritesTab || this.loading || this.loadingMore || this.refreshing) {
				return
			}

			if (reset) {
				// A list which is already on screen stays visible while it is
				// replaced, so the picker is usable during the reload.
				if (this.stickers.length > 0) {
					this.refreshing = true
				} else {
					this.loading = true
				}
			} else {
				this.loadingMore = true
			}

			this.applyStickers(await this.requestStickers(reset))

			this.loading = false
			this.loadingMore = false
			this.refreshing = false
			this.$nextTick(() => this.observeSentinel())
		},

		loadMore() {
			this.loadStickers(false)
		},

		/**
		 * Load the next page as soon as the user scrolls to the end of the grid.
		 */
		observeSentinel() {
			this.disconnectObserver()

			const sentinel = this.$refs.sentinel
			if (!sentinel || typeof IntersectionObserver === 'undefined') {
				return
			}

			this.observer = new IntersectionObserver((entries) => {
				if (entries.some((entry) => entry.isIntersecting) && this.hasMore && !this.loadingMore) {
					this.loadStickers(false)
				}
			}, { rootMargin: '200px' })
			this.observer.observe(sentinel)
		},

		disconnectObserver() {
			if (this.observer) {
				this.observer.disconnect()
				this.observer = null
			}
		},

		/**
		 * One click: the sticker is sent right away.
		 *
		 * @param {object} sticker sticker entry
		 */
		async sendSticker(sticker) {
			if (this.sending) {
				return
			}
			this.sending = true

			try {
				const token = getTalkToken()
				if (token === null) {
					// Not inside Talk: hand the absolute sticker URL to the Smart
					// Picker (Text, Notes, …).
					this.returnToPicker(sticker.resourceUrl)
					return
				}

				await sendTalkMessage(token, sticker.resourceUrl)
				showSuccess(t('r-stiker', 'Стикер отправлен'))
				// Close the picker without inserting anything into the composer:
				// the sticker already is a message of its own.
				this.returnToPicker('')
			} catch (error) {
				console.debug('r-stiker: could not send the sticker', error)
				showError(t('r-stiker', 'Не удалось отправить стикер: {error}', { error: messageFromError(error) }))
			} finally {
				this.sending = false
			}
		},

		/**
		 * Hand the result back to the Smart Picker (this closes the picker).
		 *
		 * @param {string} result text which should be inserted into the editor
		 */
		returnToPicker(result) {
			this.disconnectObserver()
			this.$el.dispatchEvent(new CustomEvent('submit', { detail: result, bubbles: true }))
		},

		loadFavorites() {
			try {
				const raw = localStorage.getItem(FAVORITES_STORAGE_KEY)
				const entries = raw ? JSON.parse(raw) : []
				this.favorites = Array.isArray(entries)
					? entries.filter((entry) => entry && typeof entry.id === 'string' && typeof entry.resourceUrl === 'string')
					: []
			} catch (error) {
				console.debug('r-stiker: could not read the favorites', error)
				this.favorites = []
			}
		},

		saveFavorites() {
			try {
				localStorage.setItem(FAVORITES_STORAGE_KEY, JSON.stringify(this.favorites))
			} catch (error) {
				console.debug('r-stiker: could not store the favorites', error)
			}
		},

		isFavorite(sticker) {
			return this.favorites.some((entry) => entry.id === sticker.id)
		},

		toggleFavorite(sticker) {
			if (this.isFavorite(sticker)) {
				this.favorites = this.favorites.filter((entry) => entry.id !== sticker.id)
			} else {
				this.favorites = [sticker, ...this.favorites]
			}
			this.saveFavorites()
		},
	},
}
</script>

<style scoped>
.sticker-picker {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 100%;
	max-height: 65vh;
	overflow-y: auto;
	padding: 12px 16px;
	background: var(--color-main-background);
}

.sticker-picker__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	width: 100%;
	margin-bottom: 12px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.sticker-picker__tab {
	padding: 6px 14px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.sticker-picker__tab:hover {
	background: var(--color-background-hover);
}

.sticker-picker__tab--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.sticker-picker__notice {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 100%;
	margin: -4px 0 8px;
	color: var(--color-text-lighter);
	font-size: 13px;
}

.sticker-picker__status {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 120px;
	color: var(--color-text-lighter);
}

.sticker-picker__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
	gap: 8px;
	width: 100%;
}

.sticker-picker__cell {
	position: relative;
	aspect-ratio: 1;
}

.sticker-picker__sticker {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	padding: 4px;
	border: none;
	border-radius: var(--border-radius-large);
	background: none;
	cursor: pointer;
}

.sticker-picker__sticker:hover {
	background: var(--color-background-hover);
}

.sticker-picker__image {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
}

.sticker-picker__favorite {
	position: absolute;
	top: 0;
	right: 0;
	width: 26px;
	height: 26px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: rgba(0, 0, 0, 0.35);
	color: #ffd700;
	font-size: 15px;
	line-height: 1;
	cursor: pointer;
	opacity: 0;
	transition: opacity 0.15s ease;
}

.sticker-picker__cell:hover .sticker-picker__favorite,
.sticker-picker__favorite--active,
.sticker-picker__favorite:focus {
	opacity: 1;
}

.sticker-picker__sentinel {
	width: 100%;
	height: 1px;
}

.sticker-picker__more {
	margin-top: 12px;
}
</style>
