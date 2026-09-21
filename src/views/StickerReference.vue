<!--
  - SPDX-FileCopyrightText: 2026 R-Stiker contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="stickerUrl" class="r-stiker">
		<img
			class="r-stiker__image"
			:src="stickerUrl"
			:alt="stickerAlt"
			:style="stickerStyle"
			loading="lazy"
			decoding="async">
	</div>
</template>

<script>
/**
 * Renders a sticker the way a sticker should look like: nothing but the image.
 *
 * No card, no background, no border, no shadow, no padding, no title and no
 * description - the PNG transparency, GIF animation or WEBP of the original
 * file is kept, because the browser loads the sticker URL as it is.
 */
export default {
	name: 'StickerReference',

	props: {
		richObjectType: {
			type: String,
			default: '',
		},
		richObject: {
			type: Object,
			default: null,
		},
		accessible: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			// wrapper elements whose card chrome was removed on mount
			strippedChrome: [],
		}
	},

	computed: {
		stickerUrl() {
			const sticker = this.richObject?.sticker ?? {}
			return sticker.image_url ?? sticker.thumbnail_url ?? null
		},

		stickerAlt() {
			// Only used by screen readers, never rendered next to the sticker.
			return this.richObject?.sticker?.title ?? ''
		},

		stickerWidth() {
			return this.toPositiveInt(this.richObject?.sticker?.width)
		},

		stickerHeight() {
			return this.toPositiveInt(this.richObject?.sticker?.height)
		},

		/**
		 * Reserve the exact aspect ratio of the sticker, so the box is stable
		 * before the image loads and nothing is stretched or distorted.
		 * @return {object|null}
		 */
		stickerStyle() {
			if (this.stickerWidth > 0 && this.stickerHeight > 0) {
				return { aspectRatio: `${this.stickerWidth} / ${this.stickerHeight}` }
			}
			return null
		},
	},

	mounted() {
		this.getWidgetHost()?.classList.add('r-stiker-host')
		this.stripWidgetChrome()
	},

	beforeUnmount() {
		this.restoreWidgetChrome()
		this.getWidgetHost()?.classList.remove('r-stiker-host')
	},

	methods: {
		/**
		 * @param {*} value possible dimension value
		 * @return {number} the value as a positive integer, 0 if invalid
		 */
		toPositiveInt(value) {
			const number = Number(value)
			return Number.isFinite(number) && number > 0 ? Math.floor(number) : 0
		},

		/**
		 * The wrapper which the reference widget renders around custom widgets.
		 * @return {HTMLElement|null}
		 */
		getWidgetHost() {
			return this.$el?.closest?.('.widget-custom') ?? null
		},

		/**
		 * @return {HTMLElement[]} wrapper elements between the sticker and the page
		 */
		getWrapperChain() {
			const chain = []
			let node = this.$el?.parentElement ?? null
			for (let depth = 0; node && depth < 3; depth++) {
				const className = typeof node.className === 'string' ? node.className : ''
				if (className.includes('widget-custom')) {
					chain.push(node)
				}
				node = node.parentElement
			}

			return chain
		},

		/**
		 * The generic reference widget renders a card (border, background, margins)
		 * around custom widgets. A sticker is a bare image, so that chrome is removed
		 * from the wrapper elements above it.
		 */
		stripWidgetChrome() {
			for (const element of this.getWrapperChain()) {
				this.strippedChrome.push({ element, style: element.getAttribute('style') ?? '' })
				element.style.border = 'none'
				element.style.background = 'none'
				element.style.boxShadow = 'none'
				element.style.borderRadius = '0'
				element.style.padding = '0'
				element.style.margin = '0'
				element.style.minHeight = '0'
			}
		},

		restoreWidgetChrome() {
			for (const { element, style } of this.strippedChrome) {
				if (style === '') {
					element.removeAttribute('style')
				} else {
					element.setAttribute('style', style)
				}
			}
			this.strippedChrome = []
		},
	},
}
</script>

<style scoped>
.r-stiker {
	display: flex;
	align-items: center;
	justify-content: center;
	/* Stable sticker box: fixed maximum size, aspect ratio preserved. */
	width: min(100%, var(--r-stiker-size, 240px));
	margin: 0;
	padding: 0;
	background: none;
	border: none;
	box-shadow: none;
}

.r-stiker__image {
	display: block;
	width: 100%;
	height: auto;
	max-height: 320px;
	margin: 0;
	padding: 0;
	object-fit: contain;
	background: none;
	border: none;
	border-radius: 0;
	box-shadow: none;
}
</style>
