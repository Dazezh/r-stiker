# R-Stiker

Sticker packs for Nextcloud Talk and Text. Pick a sticker in the Smart Picker and it
shows up in the chat as a sticker, not as a link or a card.

```
Smart Picker → click a sticker → the message is sent
    → the message carries an absolute reference URL
    → the reference provider recognises it as an "r-stiker" rich object
    → StickerReference.vue renders the sticker and nothing else
```

## Requirements

- Nextcloud 30–35, PHP 8.1+
- Nextcloud Talk for one-click sending. In Text and Notes a sticker is inserted as a link.
- Node.js 20+ and npm, only to build the frontend

## Installation

### 1. Build the frontend

Only needed once, before copying the app to the server:

```bash
npm install
npm run build
```

This produces `js/r-stiker-reference.mjs`, `js/r-stiker-picker.mjs`,
`js/r-stiker-admin.mjs` and the lazy chunks next to them.

### 2. Copy the app

The folder **must** be called `r-stiker`, same as the app id in `appinfo/info.xml`:

```bash
sudo cp -r . /var/www/nextcloud/custom_apps/r-stiker
sudo chown -R www-data:www-data /var/www/nextcloud/custom_apps/r-stiker
sudo -u www-data php /var/www/nextcloud/occ app:enable r-stiker
```

For Nextcloud AIO:

```bash
docker cp . nextcloud-aio-nextcloud:/var/www/html/custom_apps/r-stiker
docker exec --user www-data nextcloud-aio-nextcloud php occ app:enable r-stiker
```

### 3. Add stickers

The packs in `stickerpacks/` can be imported in one go:

```bash
sudo -u www-data php occ r-stiker:import                  # the stickerpacks folder of the app
sudo -u www-data php occ r-stiker:import /path/to/packs    # any other folder
```

One folder becomes one pack. Every file is checked by its actual MIME type, so anything
that is not PNG, GIF, JPEG or WEBP is skipped. Running the command again is safe:
stickers that already exist are left alone.

Old installations can rebuild the stored metadata (titles and image sizes) from the
files on disk:

```bash
sudo -u www-data php occ r-stiker:rescan
```

After that, packs are easiest to maintain in **Settings → Administration → R-Stiker**.

## Usage

1. Open the Smart Picker in Talk (the button next to the message field, or type `/`).
2. Pick **Stickers**.
3. Click a sticker. The message is sent right away and the input field stays empty.

Favorites (★) and the last sent stickers (Recent) are kept in the browser.

In Text, Notes and other apps without a chat, the picker returns an absolute link to the
sticker instead. It is inserted as a normal link and renders as the sticker.

## Documentation

- [DOC.md](DOC.md) — how the app works: storage, sticker ids, references, the local
  sticker cache, administration, translations and maintenance.
- [API.md](API.md) — the OCS API, the reference rich object and capabilities.

## Development

```
appinfo/        manifest and routes
lib/            PHP: controllers, services, reference provider, capabilities, settings, occ commands
src/            frontend (Vue 3 + @nextcloud/vue), three entry points
templates/      settings page template
l10n/           translations
stickerpacks/   packs for the first import (not used at runtime)
```

```bash
npm run watch   # rebuild on changes
```

## License

AGPL-3.0-or-later, see `LICENSE`.

## Credits

- Built with help from AI (GitHub Copilot).
- Stickers come from <https://github.com/SaraVieira/open-source-stickers>.
