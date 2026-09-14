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
import { fetchPacks, fetchStickers, messageFromError } from '../utils/api.js'
import { getTalkToken, sendTalkMessage } from '../utils/talk.js'

const FAVORITES_TAB = 'favorites'

/**
 * Smart Picker UI of the app.
 *
 * A sticker is sent immediately: inside Talk the absolute sticker URL is posted
 * through the Talk chat API, everywhere else the URL is returned to the Smart
 * Picker so it can be inserted into the document.
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
			loadingPacks: true,
			loading: false,
			loadingMore: false,
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
		this.loadPacks()
	},

	beforeUnmount() {
		this.disconnectObserver()
	},

	methods: {
		t,

		async loadPacks() {
			this.loadingPacks = true
			try {
				this.packs = await fetchPacks()
			} catch (error) {
				console.debug('r-stiker: could not load sticker packs', error)
				showError(messageFromError(error))
				this.packs = []
			} finally {
				this.loadingPacks = false
			}

			if (this.favorites.length > 0) {
				this.activeTab = FAVORITES_TAB
				this.observeSentinel()
				return
			}

			const firstPack = this.tabs.find((tab) => tab.key !== FAVORITES_TAB)
			if (firstPack) {
				this.selectTab(firstPack.key)
			} else {
				this.observeSentinel()
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

			if (this.isFavoritesTab) {
				this.$nextTick(() => this.observeSentinel())
			} else {
				this.loadStickers(true)
			}
		},

		/**
		 * @param {boolean} reset replace the list instead of appending
		 */
		async loadStickers(reset = false) {
			if (this.isFavoritesTab) {
				return
			}
			if (this.loadingMore || (this.loading && !reset)) {
				return
			}

			const packName = this.activeTab
			const cursor = reset ? 0 : (this.cursor ?? 0)
			if (reset) {
				this.loading = true
			} else {
				this.loadingMore = true
			}

			try {
				const data = await fetchStickers(packName, cursor, STICKERS_PER_PAGE)
				const entries = Array.isArray(data?.entries) ? data.entries : []
				this.stickers = reset ? entries : [...this.stickers, ...entries]
				this.cursor = data?.cursor ?? null
				this.hasMore = this.cursor !== null
			} catch (error) {
				console.debug('r-stiker: could not load stickers', error)
				showError(messageFromError(error))
				if (reset) {
					this.stickers = []
					this.hasMore = false
				}
			} finally {
				this.loading = false
				this.loadingMore = false
				this.$nextTick(() => this.observeSentinel())
			}
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
