# API — r-stiker

Nextcloud base URL: `https://cloud.example.com`

- Reading: `GET /ocs/v2.php/apps/r-stiker/api/v1/...` — open to everyone (used by the
  Smart Picker, also works in public conversations).
- Changes: `POST/PUT/DELETE` — administrators only (standard Nextcloud and CSRF checks).

Every response uses the OCS envelope: `{"ocs": {"meta": {...}, "data": ...}}`.

---

## 1. Pack list

```
GET /ocs/v2.php/apps/r-stiker/api/v1/packs
```

**200 OK**

```json
{
  "ocs": {
    "meta": { "status": "ok", "statuscode": 200, "message": "OK" },
    "data": [
      {
        "name": "mochicat",
        "displayName": "Mochi Cat",
        "description": "Cats",
        "stickerCount": 42
      }
    ]
  }
}
```

| Field | Type | Description |
|---|---|---|
| `name` | string | Pack name (the storage folder, part of every sticker id) |
| `displayName` | string | Display name |
| `description` | string | Description of the pack |
| `stickerCount` | int | Number of stickers in the pack |

---

## 2. Single pack

```
GET /ocs/v2.php/apps/r-stiker/api/v1/packs/{pack}
```

**200 OK** — a pack object as above. **404** — pack not found.

---

## 3. Stickers of a pack

```
GET /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}?cursor=0&limit=60
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `cursor` | int | `0` | Offset for pagination |
| `limit` | int | `60` | Page size (maximum 200) |

**200 OK**

```json
{
  "ocs": {
    "meta": { "status": "ok", "statuscode": 200, "message": "OK" },
    "data": {
      "entries": [
        {
          "id": "bW9jaGljYXQ6MC53ZWJw",
          "name": "0.webp",
          "title": "0",
          "width": 512,
          "height": 512,
          "thumbnailUrl": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw",
          "resourceUrl": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw"
        }
      ],
      "cursor": 60,
      "total": 42
    }
  }
}
```

| Field | Type | Description |
|---|---|---|
| `entries[].id` | string | Sticker id (url-safe base64 of `pack:file`) |
| `entries[].name` | string | File name in the storage |
| `entries[].title` | string | Sticker description, also used as the image alt text (defaults to the file name without extension) |
| `entries[].width` | int | Real width of the sticker in pixels (0 when unknown) |
| `entries[].height` | int | Real height of the sticker in pixels (0 when unknown) |
| `entries[].thumbnailUrl` | string | Absolute image URL |
| `entries[].resourceUrl` | string | Absolute URL that is inserted into or sent as a message |
| `cursor` | int \| null | Offset of the next page, `null` when there is none |
| `total` | int | Total number of stickers in the pack |

The response is not cached by the browser: the client keeps the list itself (see section 4)
and refreshes it only when the library revision changed. When the pack does not exist,
`entries: []` and `cursor: null`.

### 3.1 Check sticker ids

```
POST /ocs/v2.php/apps/r-stiker/api/v1/stickers/check
Content-Type: application/json
```

Body:

```json
{ "ids": ["bW9jaGljYXQ6MC53ZWJw", "bW9jaGljYXQ6MS53ZWJw"] }
```

| Field | Type | Description |
|---|---|---|
| `ids` | string[] | Sticker ids to check (at most 200) |

**200 OK**

```json
{
  "ocs": {
    "meta": { "status": "ok", "statuscode": 200, "message": "OK" },
    "data": { "existing": ["bW9jaGljYXQ6MC53ZWJw"] }
  }
}
```

Returns only the ids that still exist, in the order they were sent. The **Recent** tab
uses this after a revision change to drop stickers that were deleted in the meantime.
The response is not cached.

---

## 4. List revision

```
GET /ocs/v2.php/apps/r-stiker/api/v1/revision
```

A fingerprint of the whole sticker library: which packs exist, how they are named, which
stickers they contain and what those stickers are called and how large they are. It changes
on any modification (add, delete, rename, replace a file).

**200 OK**

```json
{
  "ocs": {
    "meta": { "status": "ok", "statuscode": 200, "message": "OK" },
    "data": { "revision": "b9f1c0d3a7e24f5c8b6d0a1e2f3c4b5d6a7e8f9012345678abcdef012345678" }
  }
}
```

| Field | Type | Description |
|---|---|---|
| `revision` | string | sha256 fingerprint of the library |

The response is deliberately not cached (`Cache-Control: no-cache, no-store, must-revalidate`).

**How clients use it.** The list of packs and stickers is cached locally together with the
revision it was fetched at. On open, the interface is painted from the cache and then asks
this endpoint:

- revision matches — no stickers are requested at all;
- revision differs — the pack list and the open pack are fetched again and the cache is
  replaced;
- the request fails — the cached copy stays in use.

---

## 5. Sticker image

```
GET /apps/r-stiker/s/{stickerId}
```

Returns the sticker file as it is (PNG/GIF/JPEG/WEBP), without conversion.

Response headers:

- `Content-Type`: `image/png`, `image/gif`, `image/jpeg` or `image/webp`
- `ETag`: stable version identifier of the file
- `Cache-Control`: `public, max-age=604800, immutable`

**404 Not Found** — invalid id, missing file, or an attempt to leave the pack (both the
decoded parts of the id and the actual storage path are checked).

### stickerId format

`stickerId` is url-safe base64 of `packName:fileName`, without the padding `=`:

| Step | Value |
|---|---|
| Source string | `mochicat:0.webp` |
| base64 | `bW9jaGljYXQ6MC53ZWJw` |
| URL-safe | `bW9jaGljYXQ6MC53ZWJw` |

---

## 6. Administration (admin only)

### 6.1 Create a pack

```
POST /ocs/v2.php/apps/r-stiker/api/v1/packs
```

Body (JSON or form data):

| Field | Type | Description |
|---|---|---|
| `name` | string | Pack folder name (required) |
| `displayName` | string | Display name |
| `description` | string | Description of the pack |

**201 Created** — the created pack. **400** — the name is taken or not allowed.

Letters (including Cyrillic), digits, space, `-`, `_` and `.` are allowed; 64 characters
at most.

### 6.2 Update a pack

```
PUT /ocs/v2.php/apps/r-stiker/api/v1/packs/{pack}
```

Body: `name` (renames the folder), `displayName`, `description` — only the fields that
change have to be sent. Renaming changes the sticker links (the id contains the pack name).

**200 OK** — the updated pack.

### 6.3 Delete a pack

```
DELETE /ocs/v2.php/apps/r-stiker/api/v1/packs/{pack}
```

**200 OK** — `{"deleted": true}` (all stickers of the pack are deleted with it).

### 6.4 Upload a sticker

```
POST /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}
Content-Type: multipart/form-data
```

| Field | Type | Description |
|---|---|---|
| `file` | file | Sticker file (required), up to 5 MiB |
| `title` | string | Sticker description (optional) |

The real MIME type is detected from the content (`getimagesize` + `finfo`).
`image/png`, `image/gif`, `image/jpeg` and `image/webp` are accepted. SVG and any other
format are rejected with **400**. The file name is sanitised and gets a suffix when it is
already taken.

**201 Created** — the created sticker (same shape as in the sticker list).

### 6.5 Change the description of a sticker

```
PUT /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}/{sticker}
```

Body: `title`. An empty value resets the description to the file name.

**200 OK** — the updated sticker. **404** — sticker not found in the pack.

### 6.6 Delete a sticker

```
DELETE /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}/{sticker}
```

**200 OK** — `{"deleted": true}`.

---

## 7. Capabilities

```
GET /ocs/v2.php/cloud/capabilities
```

```json
{
  "r-stiker": {
    "version": 1,
    "protocol": 1,
    "reference": { "type": "r-stiker", "version": 1 },
    "stickers": {
      "formats": ["image/png", "image/gif", "image/jpeg", "image/webp"],
      "revision": true
    },
    "smart-picker": true
  }
}
```

`stickers.revision: true` means that `GET /api/v1/revision` is available (section 4).

---

## 8. Sticker messages

A message stores nothing but the absolute sticker URL:

```
https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw
```

From there the standard Reference API takes over: the client requests
`GET /ocs/v2.php/references/resolve?reference=<url>` and receives

```json
{
  "richObjectType": "r-stiker",
  "richObject": {
    "version": 1,
    "id": "bW9jaGljYXQ6MC53ZWJw",
    "pack": { "name": "Mochi Cat" },
    "sticker": {
      "name": "0.webp",
      "title": "0",
      "width": 512,
      "height": 512,
      "image_url": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw",
      "thumbnail_url": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw"
    },
    "image_webp": true
  },
  "accessible": true
}
```

The `image_png`, `image_gif`, `image_jpeg` or `image_webp` field is set to `true`
depending on the real MIME type of the sticker. Clients without the custom `r-stiker`
widget (mobile and desktop Talk, other apps) use it to render the sticker as an image
from `image_url`. The generic reference fields `title` (the sticker description) and
`description` (the pack name) give those clients a readable label.

The web interface renders the same link with the `r-stiker` widget — no card, background,
heading or URL.

For links to another instance (`https://other.example.com/apps/r-stiker/s/...`) the
provider does not look for the file locally but uses the URL itself as the image source.
That is what makes federation work.
