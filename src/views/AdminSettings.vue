<!--
  - SPDX-FileCopyrightText: 2026 R-Stiker contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('r-stiker', 'Стикеры (R-Stiker)')"
		:description="t('r-stiker', 'Стикерпаки для Talk и Text. Паки и стикеры лежат в appdata приложения (r-stiker/packs), база данных не используется.')">
		<form class="r-stiker-admin__create" @submit.prevent="createPack()">
			<h3>{{ t('r-stiker', 'Новый стикерпак') }}</h3>
			<div class="r-stiker-admin__fields">
				<NcTextField
					v-model="newPack.name"
					:label="t('r-stiker', 'Имя папки')"
					placeholder="My pack" />
				<NcTextField
					v-model="newPack.displayName"
					:label="t('r-stiker', 'Отображаемое имя')" />
				<NcTextField
					v-model="newPack.description"
					:label="t('r-stiker', 'Описание')" />
				<NcButton
					variant="primary"
					:disabled="creating || newPack.name.trim() === ''"
					@click="createPack()">
					{{ t('r-stiker', 'Создать пак') }}
				</NcButton>
			</div>
		</form>

		<input
			ref="fileInput"
			class="r-stiker-admin__file-input"
			type="file"
			multiple
			accept="image/png,image/gif,image/jpeg,image/webp"
			@change="onFilePicked">

		<div v-if="loading" class="r-stiker-admin__status">
			<NcLoadingIcon :size="32" />
		</div>

		<p v-else-if="packs.length === 0" class="r-stiker-admin__status">
			{{ t('r-stiker', 'Пока нет ни одного стикерпака') }}
		</p>

		<template v-else>
		<article v-for="pack in packs" :key="pack.name" class="r-stiker-admin__pack">
			<header class="r-stiker-admin__header">
				<h3 class="r-stiker-admin__title">
					{{ pack.displayName || pack.name }}
					<span class="r-stiker-admin__slug">({{ pack.name }})</span>
				</h3>
				<span class="r-stiker-admin__badge">
					{{ n('r-stiker', '%n стикер', '%n стикеров', pack.stickerCount) }}
				</span>
				<div class="r-stiker-admin__actions">
					<NcButton variant="secondary" @click="toggleStickers(pack)">
						{{ expandedPack === pack.name ? t('r-stiker', 'Скрыть стикеры') : t('r-stiker', 'Стикеры') }}
					</NcButton>
					<NcButton variant="secondary" @click="toggleEdit(pack)">
						{{ t('r-stiker', 'Изменить') }}
					</NcButton>
					<NcButton variant="error" @click="requestDelete(pack)">
						{{ deleteRequested === pack.name ? t('r-stiker', 'Подтвердить удаление') : t('r-stiker', 'Удалить') }}
					</NcButton>
				</div>
			</header>

			<div v-if="editPack === pack.name" class="r-stiker-admin__edit">
				<div class="r-stiker-admin__fields">
					<NcTextField v-model="editForm.name" :label="t('r-stiker', 'Имя папки')" />
					<NcTextField v-model="editForm.displayName" :label="t('r-stiker', 'Отображаемое имя')" />
					<NcTextArea v-model="editForm.description" :label="t('r-stiker', 'Описание')" />
				</div>
				<p class="r-stiker-admin__hint">
					{{ t('r-stiker', 'Переименование папки меняет ссылки стикеров: уже отправленные стикеры перестанут отображаться.') }}
				</p>
				<div class="r-stiker-admin__actions">
					<NcButton variant="primary" :disabled="savingPack" @click="savePack(pack)">
						{{ t('r-stiker', 'Сохранить') }}
					</NcButton>
					<NcButton variant="tertiary" @click="closeEdit()">
						{{ t('r-stiker', 'Отмена') }}
					</NcButton>
				</div>
			</div>

			<div v-if="expandedPack === pack.name" class="r-stiker-admin__content">
				<div
					class="r-stiker-admin__drop"
					:class="{ 'r-stiker-admin__drop--active': dragTarget === pack.name }"
					@dragover.prevent="dragTarget = pack.name"
					@dragleave="dragTarget = null"
					@drop.prevent="onDrop(pack, $event)">
					<p>{{ t('r-stiker', 'Перетащите PNG, GIF, JPEG или WEBP сюда') }}</p>
					<NcButton variant="secondary" :disabled="uploading" @click="openFileDialog(pack)">
						{{ t('r-stiker', 'Выбрать файлы') }}
					</NcButton>
					<p v-if="uploadStatus" class="r-stiker-admin__hint">{{ uploadStatus }}</p>
					<p class="r-stiker-admin__hint">
						{{ t('r-stiker', 'SVG не поддерживается. До 5 МиБ на файл.') }}
					</p>
				</div>

				<div v-if="loadingStickers" class="r-stiker-admin__status">
					<NcLoadingIcon :size="24" />
				</div>

				<p v-else-if="stickers.length === 0" class="r-stiker-admin__status">
					{{ t('r-stiker', 'В этом паке ещё нет стикеров') }}
				</p>

				<div v-else class="r-stiker-admin__grid">
					<div v-for="sticker in stickers" :key="sticker.id" class="r-stiker-admin__cell">
						<img
							class="r-stiker-admin__image"
							:src="sticker.thumbnailUrl"
							:alt="sticker.title"
							loading="lazy">
						<input
							class="r-stiker-admin__title-input"
							type="text"
							:value="sticker.title"
							:aria-label="t('r-stiker', 'Название стикера')"
							@change="renameSticker(sticker, $event.target.value)">
						<NcButton variant="tertiary" @click="removeSticker(sticker)">
							{{ t('r-stiker', 'Удалить') }}
						</NcButton>
					</div>
				</div>
			</div>
		</article>
		</template>
	</NcSettingsSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	createPack,
	deletePack,
	deleteSticker,
	fetchPacks,
	fetchStickers,
	messageFromError,
	updatePack,
	updateSticker,
	uploadSticker,
} from '../utils/api.js'

const STICKER_PAGE_SIZE = 200
const MAX_PAGES = 50

/**
 * Administration UI: packs and stickers of the app.
 */
export default {
	name: 'AdminSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcSettingsSection,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			packs: [],
			stickers: [],
			loading: true,
			loadingStickers: false,
			creating: false,
			savingPack: false,
			uploading: false,
			uploadStatus: null,
			uploadTargetPack: null,
			expandedPack: null,
			editPack: null,
			deleteRequested: null,
			dragTarget: null,
			newPack: { name: '', displayName: '', description: '' },
			editForm: { name: '', displayName: '', description: '' },
		}
	},

	mounted() {
		this.loadPacks()
	},

	methods: {
		t,
		n,

		async loadPacks() {
			this.loading = true
			try {
				this.packs = await fetchPacks()
			} catch (error) {
				showError(messageFromError(error))
			} finally {
				this.loading = false
			}
		},

		async createPack() {
			const name = this.newPack.name.trim()
			if (name === '' || this.creating) {
				return
			}

			this.creating = true
			try {
				await createPack({
					name,
					displayName: this.newPack.displayName.trim(),
					description: this.newPack.description.trim(),
				})
				this.newPack = { name: '', displayName: '', description: '' }
				await this.loadPacks()
				showSuccess(t('r-stiker', 'Стикерпак создан'))
			} catch (error) {
				showError(messageFromError(error))
			} finally {
				this.creating = false
			}
		},

		toggleEdit(pack) {
			if (this.editPack === pack.name) {
				this.closeEdit()
				return
			}

			this.editPack = pack.name
			this.editForm = {
				name: pack.name,
				displayName: pack.displayName ?? '',
				description: pack.description ?? '',
			}
		},

		closeEdit() {
			this.editPack = null
		},

		async savePack(pack) {
			const newName = this.editForm.name.trim()
			this.savingPack = true

			try {
				await updatePack(pack.name, {
					name: newName,
					displayName: this.editForm.displayName.trim(),
					description: this.editForm.description.trim(),
				})
				if (this.expandedPack === pack.name) {
					this.expandedPack = newName
				}
				this.editPack = null
				await this.loadPacks()
				showSuccess(t('r-stiker', 'Пак сохранён'))
			} catch (error) {
				showError(messageFromError(error))
			} finally {
				this.savingPack = false
			}
		},

		requestDelete(pack) {
			if (this.deleteRequested !== pack.name) {
				this.deleteRequested = pack.name
				return
			}

			this.deleteRequested = null
			this.removePack(pack)
		},

		async removePack(pack) {
			try {
				await deletePack(pack.name)
				if (this.expandedPack === pack.name) {
					this.expandedPack = null
					this.stickers = []
				}
				await this.loadPacks()
				showSuccess(t('r-stiker', 'Пак удалён'))
			} catch (error) {
				showError(messageFromError(error))
			}
		},

		async toggleStickers(pack) {
			if (this.expandedPack === pack.name) {
				this.expandedPack = null
				this.stickers = []
				return
			}

			this.expandedPack = pack.name
			this.stickers = []
			await this.loadStickers(pack.name)
		},

		async loadStickers(packName) {
			this.loadingStickers = true
			try {
				const entries = []
				let cursor = 0
				for (let page = 0; page < MAX_PAGES; page++) {
					const data = await fetchStickers(packName, cursor, STICKER_PAGE_SIZE)
					entries.push(...(data?.entries ?? []))
					if (data?.cursor === null || data?.cursor === undefined) {
						break
					}
					cursor = data.cursor
				}
				this.stickers = entries
			} catch (error) {
				showError(messageFromError(error))
			} finally {
				this.loadingStickers = false
			}
		},

		openFileDialog(pack) {
			this.uploadTargetPack = pack.name
			this.$refs.fileInput?.click()
		},

		onFilePicked(event) {
			const files = event.target?.files ?? []
			const packName = this.uploadTargetPack
			// allow selecting the same file again
			event.target.value = ''
			if (packName) {
				this.uploadFiles(packName, files)
			}
		},

		onDrop(pack, event) {
			this.dragTarget = null
			this.uploadFiles(pack.name, event.dataTransfer?.files ?? [])
		},

		/**
		 * Uploads the files one by one, so every sticker gets its own response.
		 *
		 * @param {string} packName target pack
		 * @param {FileList|Array} files selected files
		 */
		async uploadFiles(packName, files) {
			const list = Array.from(files ?? [])
			if (list.length === 0 || this.uploading) {
				return
			}

			this.uploading = true
			const failures = []
			let done = 0

			for (const file of list) {
				try {
					await uploadSticker(packName, file)
					done++
				} catch (error) {
					failures.push(`${file.name}: ${messageFromError(error)}`)
				}
				this.uploadStatus = t('r-stiker', 'Загружено {done} из {total}…', { done, total: list.length })
			}

			this.uploadStatus = null
			this.uploading = false

			if (done > 0) {
				showSuccess(n('r-stiker', 'Загружен %n стикер', 'Загружено %n стикеров', done))
			}
			if (failures.length > 0) {
				showError(t('r-stiker', 'Не загружено: {list}', { list: failures.join('; ') }))
			}

			await this.loadPacks()
			if (this.expandedPack === packName) {
				await this.loadStickers(packName)
			}
		},

		async renameSticker(sticker, title) {
			const packName = this.expandedPack
			if (!packName) {
				return
			}

			try {
				const updated = await updateSticker(packName, sticker.id, title)
				sticker.title = updated?.title ?? title
			} catch (error) {
				showError(messageFromError(error))
			}
		},

		async removeSticker(sticker) {
			const packName = this.expandedPack
			if (!packName) {
				return
			}

			try {
				await deleteSticker(packName, sticker.id)
				await this.loadStickers(packName)
				await this.loadPacks()
			} catch (error) {
				showError(messageFromError(error))
			}
		},
	},
}
</script>

<style scoped>
.r-stiker-admin__create {
	max-width: 640px;
	margin-bottom: 24px;
}

.r-stiker-admin__fields {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.r-stiker-admin__file-input {
	display: none;
}

.r-stiker-admin__status {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 80px;
	color: var(--color-text-lighter);
}

.r-stiker-admin__pack {
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.r-stiker-admin__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.r-stiker-admin__title {
	margin: 0;
}

.r-stiker-admin__slug {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	font-weight: normal;
}

.r-stiker-admin__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.r-stiker-admin__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-inline-start: auto;
}

.r-stiker-admin__edit {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.r-stiker-admin__hint {
	margin: 8px 0 0;
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.r-stiker-admin__content {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.r-stiker-admin__drop {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 16px;
	border: 2px dashed var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	text-align: center;
}

.r-stiker-admin__drop--active {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.r-stiker-admin__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 12px;
	margin-top: 16px;
}

.r-stiker-admin__cell {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.r-stiker-admin__image {
	max-width: 100%;
	max-height: 96px;
	object-fit: contain;
}

.r-stiker-admin__title-input {
	width: 100%;
	padding: 4px 6px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: center;
}
</style>
