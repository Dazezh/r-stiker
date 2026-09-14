/**
 * SPDX-FileCopyrightText: 2026 R-Stiker contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Mounts the administration UI into the settings template.
 */

import { createApp } from 'vue'
import '@nextcloud/dialogs/style.css'
import AdminSettings from './views/AdminSettings.vue'

const mountPoint = document.getElementById('r-stiker-admin')
if (mountPoint) {
	createApp(AdminSettings).mount(mountPoint)
}
