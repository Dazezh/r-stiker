# API — r-stiker

Базовый URL Nextcloud: `https://cloud.example.com`

- Чтение: `GET /ocs/v2.php/apps/r-stiker/api/v1/...` — доступно всем (используется Smart Picker,
  работает и в публичных разговорах).
- Изменение: `POST/PUT/DELETE` — только администратор (стандартная проверка Nextcloud + CSRF).

Все ответы — OCS-конверт: `{"ocs": {"meta": {...}, "data": ...}}`.

---

## 1. Список паков

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
        "description": "Кошки",
        "stickerCount": 42
      }
    ]
  }
}
```

| Поле | Тип | Описание |
|---|---|---|
| `name` | string | Имя пака (папка в хранилище, входит в ID стикера) |
| `displayName` | string | Отображаемое имя |
| `description` | string | Описание |
| `stickerCount` | int | Количество стикеров в паке |

---

## 2. Один пак

```
GET /ocs/v2.php/apps/r-stiker/api/v1/packs/{pack}
```

**200 OK** — объект пака как выше. **404** — пак не найден.

---

## 3. Стикеры пака

```
GET /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}?cursor=0&limit=60
```

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `cursor` | int | `0` | Смещение для пагинации |
| `limit` | int | `60` | Размер страницы (максимум 200) |

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

| Поле | Тип | Описание |
|---|---|---|
| `entries[].id` | string | ID стикера (url-safe base64 от `pack:file`) |
| `entries[].name` | string | Имя файла в хранилище |
| `entries[].title` | string | Название (по умолчанию — имя файла без расширения) |
| `entries[].thumbnailUrl` | string | Абсолютный URL картинки |
| `entries[].resourceUrl` | string | Абсолютный URL, который вставляется/отправляется в сообщение |
| `cursor` | int \| null | Смещение следующей страницы, `null` — страниц больше нет |
| `total` | int | Всего стикеров в паке |

Ответ кэшируется на 1 час. Если пака нет — `entries: []`, `cursor: null`.

---

## 4. Картинка стикера

```
GET /apps/r-stiker/s/{stickerId}
```

Отдаёт файл стикера как есть (PNG/GIF/JPEG/WEBP), без конвертации.

Заголовки ответа:

- `Content-Type`: `image/png`, `image/gif`, `image/jpeg` или `image/webp`
- `ETag`: стабильный идентификатор версии файла
- `Cache-Control`: `public, max-age=604800, immutable`

**404 Not Found** — невалидный ID, отсутствующий файл или попытка выйти за пределы пака
(проверяются и декодированные части ID, и фактический путь в хранилище).

### Формат stickerId

`stickerId` — url-safe base64 от строки `packName:fileName`, без выравнивающих `=`:

| Шаг | Значение |
|---|---|
| Исходная строка | `mochicat:0.webp` |
| base64 | `bW9jaGljYXQ6MC53ZWJw` |
| URL-safe | `bW9jaGljYXQ6MC53ZWJw` |

---

## 5. Администрирование (только админ)

### 5.1 Создать пак

```
POST /ocs/v2.php/apps/r-stiker/api/v1/packs
```

Тело (JSON или form-data):

| Поле | Тип | Описание |
|---|---|---|
| `name` | string | Имя папки пака (обязательно) |
| `displayName` | string | Отображаемое имя |
| `description` | string | Описание |

**201 Created** — созданный пак. **400** — имя занято или недопустимо.

Допустимы буквы (включая кириллицу), цифры, пробел, `-`, `_`, `.`; максимум 64 символа.

### 5.2 Изменить пак

```
PUT /ocs/v2.php/apps/r-stiker/api/v1/packs/{pack}
```

Тело: `name` (переименование папки), `displayName`, `description` — передаются только
изменяемые поля. Переименование меняет ссылки стикеров (ID содержит имя пака).

**200 OK** — обновлённый пак.

### 5.3 Удалить пак

```
DELETE /ocs/v2.php/apps/r-stiker/api/v1/packs/{pack}
```

**200 OK** — `{"deleted": true}` (вместе с паком удаляются все его стикеры).

### 5.4 Загрузить стикер

```
POST /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}
Content-Type: multipart/form-data
```

| Поле | Тип | Описание |
|---|---|---|
| `file` | file | Файл стикера (обязательно), до 5 МиБ |
| `title` | string | Название (необязательно) |

Настоящий MIME-тип определяется по содержимому (`getimagesize` + `finfo`).
Принимаются `image/png`, `image/gif`, `image/jpeg`, `image/webp`. SVG и любой другой
формат отклоняются с **400**. Имя файла санитизируется, при совпадении добавляется суффикс.

**201 Created** — созданный стикер (как в списке стикеров).

### 5.5 Изменить название стикера

```
PUT /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}/{sticker}
```

Тело: `title`. Пустое значение сбрасывает название на имя файла.

**200 OK** — обновлённый стикер. **404** — стикер не найден в паке.

### 5.6 Удалить стикер

```
DELETE /ocs/v2.php/apps/r-stiker/api/v1/stickers/{pack}/{sticker}
```

**200 OK** — `{"deleted": true}`.

---

## 6. Capabilities

```
GET /ocs/v2.php/cloud/capabilities
```

```json
{
  "r-stiker": {
    "version": 1,
    "protocol": 1,
    "reference": { "type": "r-stiker", "version": 1 },
    "stickers": { "formats": ["image/png", "image/gif", "image/jpeg", "image/webp"] },
    "smart-picker": true
  }
}
```

---

## 7. Сообщения со стикерами

В сообщении хранится только абсолютный URL стикера:

```
https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw
```

Дальше работает стандартный Reference API: клиент запрашивает
`GET /ocs/v2.php/references/resolve?reference=<url>`, получает объект

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
      "image_url": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw",
      "thumbnail_url": "https://cloud.example.com/apps/r-stiker/s/bW9jaGljYXQ6MC53ZWJw"
    }
  },
  "accessible": true
}
```

и рендерит его кастомным виджетом `r-stiker` — без карточки, фона, заголовка и URL.

Для ссылок на другой инстанс (`https://other.example.com/apps/r-stiker/s/...`) провайдер
не проверяет наличие файла локально, а использует сам URL как источник картинки — так
работает федерация.
