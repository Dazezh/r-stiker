# How R-Stiker works

This document explains what happens behind the scenes. The HTTP endpoints themselves are
described in [API.md](API.md); here we talk about the ideas and the moving parts.

## In one picture

```
Smart Picker → click a sticker → Talk chat API → message with an absolute URL
    → Reference API → rich object "r-stiker" → sticker widget
```

- A sticker is a file in the Nextcloud app data folder.
- A message contains nothing but the absolute URL of that file.
- The Reference API turns the URL into a rich object and the app's widget draws the image.

No database, no external service, no Talk modifications.

## Packs and stickers

### Where things live

```
<appdata>/r-stiker/packs/packs.json          index: names, descriptions, titles, sizes
<appdata>/r-stiker/packs/<pack>/<file>       the sticker files
```

`IAppData` is used instead of a database because a pack is really just a folder. The app
data API can list the files inside a folder, but not the sub-folders of a folder, so the
index file `packs.json` keeps the list of packs and their metadata.

### What a pack is

A pack is a folder plus an entry in `packs.json`:

| Field | Meaning |
|---|---|
| `name` | Folder name. It is part of every sticker id, so renaming it changes all links of the pack. |
| `displayName` | The name shown in the picker tabs and in the reference tooltip. |
| `description` | Free text about the pack. It is stored and returned by the API, but the web Smart Picker does not display it yet; it is there for context and for other clients. |
| `titles` | Per file: the sticker description (its `title`). Falls back to the file name without extension and is used as the image alt text. |
| `sizes` | Per file: the intrinsic width and height in pixels. |

### What a sticker title (its description) is

The title is the human readable description of a single sticker. The picker shows it as
a tooltip, the reference object exposes it as `sticker.title`, and it is used as the
`alt` text of the sticker image everywhere the image appears. Screen readers announce it
instead of the file name. It is not drawn on top of the image. An empty description falls
back to the file name without extension, so the alt text is never empty.

### What the description is for

`description` belongs to the pack, not to a sticker: a short note about its theme
("Cats", "Work reactions", …). It is stored in `packs.json` and returned by
`GET /ocs/v2.php/apps/r-stiker/api/v1/packs`, so other clients can show it. The web
Smart Picker currently uses only `displayName` in its tab bar.

### File rules

- PNG, GIF, JPEG and WEBP, detected by the actual content (`finfo` + `getimagesize`),
  never by the file extension.
- SVG is rejected on purpose: it is an active document format, not just an image.
- Admin uploads are limited to 5 MiB per file. `occ r-stiker:import` accepts up to 25 MiB.
- File names are sanitised; a duplicate name gets a numeric suffix.

### Sticker ids

A sticker id is url-safe base64 of `pack name:file name`:

```
mochicat:0.webp  →  bW9jaGljYXQ6MC53ZWJw
```

The id is opaque for clients. Physical paths never leave the server.

## How a sticker message is built

1. The Smart Picker knows the absolute URL of the sticker
   (`<server>/apps/r-stiker/s/<id>`, built with `linkToRouteAbsolute`).
2. In Talk the picker posts that URL through the Talk chat API. In Text and Notes the URL
   is handed back to the editor and inserted as a link.
3. When a message is rendered, Nextcloud asks the reference providers whether they know
   the link; `RStikerReferenceProvider` matches `/apps/r-stiker/s/<id>`.
4. The provider returns a rich object of type `r-stiker` with the image URL, title,
   dimensions and pack name.
5. `reference.js` registers the widget for that type; `StickerReference.vue` renders the
   image and removes the generic reference card chrome.

The URL is absolute and self-contained, so the message still renders when it is federated
to another instance: the other server resolves the link through its own reference
pipeline.

## The local sticker cache

The Smart Picker opens from a local copy and checks the server in the background.

### What is stored

`localStorage["r-stiker-stickers"]`:

```json
{
  "version": 1,
  "revision": "the server revision this copy belongs to",
  "packs": ["… GET /api/v1/packs …"],
  "pages": {
    "mochicat": {
      "revision": "the server revision this page belongs to",
      "entries": ["… sticker objects …"],
      "cursor": 60,
      "at": 1690000000000
    }
  },
  "savedAt": 1690000000000
}
```

| Limit | Value | Why |
|---|---|---|
| Cached packs | 12, least recently opened are dropped | keep the entry small |
| Stickers per pack | 240 | localStorage is limited (about 5 MB) |
| Max age | 7 days | last resort if the revision endpoint is unavailable |

`pages[pack].cursor` is the offset of the next page, so "Load more" continues exactly
where the cached copy ends. If a copy had to be shortened, the cursor is moved behind the
last cached sticker; no sticker is skipped.

Sticker objects are stored with their absolute image URLs. When the cache is read, URLs
of another origin are rewritten to the current one, so changing the domain or putting the
instance behind a proxy does not break the copy.

### Revisions

The server calculates a revision over the pack list, display names, descriptions, titles,
sizes and files (name, size, modification time) and returns it from
`GET /ocs/v2.php/apps/r-stiker/api/v1/revision` (see API.md). It is one small request.

### First open

1. There is no cache, so the picker shows a spinner.
2. It loads the pack list, the revision and the first page of the first pack.
3. All of it is written to localStorage.

### Later opens

1. The cached pack list and the cached page of the default tab are painted immediately.
2. In the background the picker loads the revision and the pack list.
3. Revision unchanged: nothing else happens, no sticker list is downloaded.
4. Revision changed: "Updating stickers…" appears, the pack list and the visible pack are
   reloaded, the copy is replaced and "Stickers updated" is shown. Pages of the old
   revision are dropped; other packs are reloaded on demand. Recent stickers that no
   longer exist are removed at this point (see below).
5. Server unreachable: the cached copy stays on screen and no error is shown.

Switching to a pack that is not cached loads it normally. Switching back to a cached pack
shows the copy at once. Favorites are updated with fresh titles and URLs whenever a page
that contains them is loaded.

If the revision endpoint is missing (an older server), the picker falls back to comparing
the pack list. Without a revision it cannot notice changes that only touch sticker titles.

### Why the sticker list is not HTTP-cached

`GET /api/v1/stickers/{pack}` is deliberately served without cache headers. The app owns
the caching layer and invalidates it by revision, and a browser cache would hand out
exactly the stale list the revision check is supposed to catch. Sticker images are a
different story: they are immutable and cached for a week.

## What the browser stores

The app uses three localStorage keys. None of them is required for stickers to work;
they only make the picker faster and remember what the user did. All three are plain
JSON arrays or objects and can be cleared at any time.

### `r-stiker-favorites`

The stickers marked with ★: a JSON array of sticker objects, newest first. There is no
explicit limit — it only grows when the user adds a favorite. Whenever a page that
contains a favorite is loaded, the stored copy is refreshed with the current description
and URL.

```json
[
  {
    "id": "bW9jaGljYXQ6MC53ZWJw",
    "name": "0.webp",
    "title": "hello",
    "width": 512,
    "height": 512,
    "thumbnailUrl": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw",
    "resourceUrl": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw"
  }
]
```

(`title` is the sticker description; see above.)

### `r-stiker-recent`

The **Recent** tab: the last 100 stickers that were sent — their absolute links plus the
display data needed to render them — newest first. It has the same shape as the favorites
list. Sending a sticker that is already in the list does not duplicate it, it moves to the
top; the 101st sticker pushes the oldest one out.

After a sync that detected a new library revision, the picker asks the server which of
these ids still exist (`POST /api/v1/stickers/check`, see [API.md](API.md)) and drops the
deleted ones, so the tab never points at a sticker that is gone. If the check cannot be
performed — offline, or an older server without the endpoint — the list stays as it is
until the next successful sync.

### `r-stiker-stickers`

The cached sticker list (packs and the pages that were opened), described in
[The local sticker cache](#the-local-sticker-cache). It also carries the library revision
which is used to decide whether the copy is still valid.

## Accessibility and compatibility

The app tries to behave like a regular Nextcloud app instead of working around it.

### Alt text

Every sticker has a description: the `title` field of the sticker, which an administrator
can edit in the admin panel. It is used as the `alt` text of the image in all three
places where a sticker is shown:

- the Smart Picker grid,
- the stickers list in the admin panel,
- the rendered reference widget in messages.

The description also becomes the tooltip of the sticker in the picker. If it is empty,
the file name without extension is used, so the alt text is never empty. Alt text is only
exposed to assistive technology; it is never drawn next to the sticker, and the image
keeps its original proportions.

### Semantics and keyboard

All controls are native `<button>` and `<input>` elements, so they can be focused and
activated without extra code, and the Smart Picker dialog itself is provided by
Nextcloud. Stickers carry their real width and height in the metadata; the widget
reserves the aspect ratio before the image loads, so the layout does not jump.

### The URL renders without the widget

A sticker message is nothing but an absolute URL, which keeps it usable outside the web
interface:

- Clients without the custom `r-stiker` widget — mobile and desktop Talk, other apps —
  receive the `image_png` / `image_gif` / `image_jpeg` / `image_webp` flag from the
  Reference API and render the sticker as a normal image.
- The generic reference fields `title` and `description` carry the sticker description
  and the pack name, so even a plain link preview has a readable label.
- If reference resolution is not available at all, the link still points to the image
  itself, so it can be opened or previewed by the client.

Because the URL is absolute and self-contained, the same message also renders in a
federated chat, where the receiving instance uses the link as the image source (see
[API.md](API.md), section 8).

### Text and labels

All interface strings are translated; English is the source language and Russian is
included (see [Translations](#translations)). Server-side error messages are translated
too, so a failure is reported in the language the user reads the interface in.

## Administration

**Settings → Administration → R-Stiker**

- **Packs**: create, change folder name, display name and description, delete.
- **Stickers**: upload with the file dialog or drag & drop, rename, delete.

Renaming a pack folder changes every sticker id of that pack, so stickers that were sent
before keep the old id and stop rendering. If you only want another label, change the
display name instead.

## occ commands

| Command | What it does |
|---|---|
| `occ r-stiker:import [path]` | Import folders of images as packs. Existing stickers are skipped. |
| `occ r-stiker:rescan` | Rebuild titles and dimensions from the actual files. |

`occ r-stiker:import --verbose` also lists skipped files with the reason.

## Translations

English is the source language; Russian is included.

- `l10n/<lang>.json` is used by PHP (`IL10N::t()`).
- `l10n/<lang>.js` registers the same strings in the browser (`OC.L10N.register`).
- Nextcloud loads the files for the language of the user automatically, the app does not
  have to request them.

To add a language, copy `l10n/en.json` and `l10n/en.js` to the new language code (for
example `de`), translate the values and leave the keys untouched. Plural keys have the
form `_singular_::_plural_` and their value is an array of plural forms; the
`pluralForm` field has to match the language.

## Development notes

- PHP lives in `lib/`, the frontend in `src/`, three Vite entry points build
  `js/r-stiker-*.mjs`.
- The picker and reference bundles are intentionally small; the UI itself is imported
  lazily.
- The app does not use a database and never touches user files.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Stickers sent earlier stopped rendering | The pack folder was renamed or the file was deleted. |
| The picker keeps showing old stickers | The revision endpoint does not answer, or localStorage is not writable. |
| An upload is rejected | SVG, a file that is not a real PNG/GIF/JPEG/WEBP, or a file over 5 MiB. |
| A pack is empty | It has no stickers, or all files were rejected during the import. |
