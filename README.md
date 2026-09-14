# R-Stiker

Стикеры для Nextcloud Talk и Text: собственные стикерпаки, Smart Picker и полноценные
sticker-сообщения через стандартный Reference API Nextcloud. Без базы данных, без внешних
сервисов, без правок кода Talk.

```
Smart Picker → клик по стикеру → мгновенная отправка сообщения
    → в сообщении лежит абсолютный reference-URL стикера
    → Reference Provider находит rich object "r-stiker"
    → StickerReference.vue рендерит только стикер (без карточки, текста и URL)
```

## Требования

- Nextcloud 30–34, PHP 8.1+
- Nextcloud Talk — для мгновенной отправки сообщений (в Text/Notes стикер вставляется
  ссылкой в документ)
- Node.js 20+ и npm — только для сборки фронтенда

## Установка

### 1. Собрать фронтенд (нужно один раз, до копирования на сервер)

```bash
npm install
npm run build
```

В результате появляется `js/r-stiker-reference.mjs`, `js/r-stiker-picker.mjs`,
`js/r-stiker-admin.mjs` (и ленивые чанки там же).

### 2. Скопировать приложение

Папка приложения **обязана** называться `r-stiker` — так же, как `<id>` в `appinfo/info.xml`:

```bash
sudo cp -r . /var/www/nextcloud/custom_apps/r-stiker
sudo chown -R www-data:www-data /var/www/nextcloud/custom_apps/r-stiker
sudo -u www-data php /var/www/nextcloud/occ app:enable r-stiker
```

Для Nextcloud AIO:

```bash
docker cp . nextcloud-aio-nextcloud:/var/www/html/custom_apps/r-stiker
docker exec --user www-data nextcloud-aio-nextcloud php occ app:enable r-stiker
```

### 3. Наполнить стикерами

Уже готовые паки из папки `stickerpacks/` этого репозитория можно перенести одной командой:

```bash
sudo -u www-data php occ r-stiker:import            # папка stickerpacks внутри приложения
sudo -u www-data php occ r-stiker:import /путь/куда-то/ещё   # произвольная папка
```

Команда создаёт паки по папкам, проверяет настоящий MIME-тип файлов и пропускает всё,
что не является PNG/GIF/JPEG/WEBP. Повторный запуск безопасен: уже импортированные файлы
пропускаются.

Дальше паки и стикеры удобно вести в админке: **Настройки → Администрирование → R-Stiker**.

## Админ-панель

- **Паки**: создать, изменить имя папки, отображаемое имя и описание, удалить.
  В списке видно количество стикеров.
  Внимание: переименование папки меняет ссылки стикеров — уже отправленные стикеры
  перестанут отображаться.
- **Стикеры**: загрузка одного или нескольких файлов, drag & drop, предпросмотр,
  изменение названия, удаление. Поддерживаются PNG, GIF, JPEG и WEBP, до 5 МиБ на файл.
  SVG не принимается (проверяется реальный MIME-тип содержимого, а не расширение).

## Как пользоваться

1. В Talk откройте Smart Picker (кнопка со «звёздочкой» у поля ввода или наберите `/`).
2. Выберите **Стикеры**.
3. Клик по стикеру — сообщение со стикером сразу отправляется, поле ввода остаётся пустым.
   Избранные стикеры (★) хранятся локально в браузере.

В Text, Notes и других местах, где нет чата, Smart Picker вернёт абсолютную ссылку стикера
и она вставится как обычная ссылка — при предпросмотре она отрендерится тем же стикером.

## Как это работает

| Что | Где |
|---|---|
| Reference provider (`r-stiker`) | `lib/Reference/RStikerReferenceProvider.php` |
| Уведомление фронтенда | `lib/Listener/RStikerReferenceListener.php` (`RenderReferenceEvent`) |
| Виджет стикера | `src/reference.js` + `src/views/StickerReference.vue` |
| Smart Picker | `src/picker.js` + `src/views/StickerPicker.vue` |
| Админка | `src/admin.js` + `src/views/AdminSettings.vue` |
| Хранение | appdata: `r-stiker/packs/<пак>/<стикер>` + индекс `packs.json` |
| Отдача картинки | `GET /apps/r-stiker/s/{stickerId}` |
| Capabilities | `lib/Capabilities/Capabilities.php` |

- Стикеры лежат в app-папке Nextcloud (`IAppData`), база данных не используется.
- ID стикера — url-safe base64 от `имя пака:имя файла`, путь никогда не публикуется.
- В сообщение всегда попадает **абсолютный** URL вида
  `https://cloud.example.com/apps/r-stiker/s/<id>` — его генерирует сервер
  (`IURLGenerator::linkToRouteAbsolute`), поэтому сообщение можно отправлять
  в федеративные чаты: получатель не должен знать origin сервера.
- Отправка мгновенная: Smart Picker постит URL в разговор через стандартный Talk Chat API
  (`POST /ocs/v2.php/apps/spreed/api/v1/chat/{token}`), токен берётся из текущего
  разговора Talk. Никакого Markdown и вставки в поле ввода нет.

## Разработка

```
appinfo/        манифест, маршруты
lib/            PHP: контроллеры, сервисы, reference provider, capabilities, настройки, occ-команда
src/            фронтенд (Vue 3 + @nextcloud/vue), три точки входа
templates/      шаблоны страниц настроек
stickerpacks/   паки для первоначального импорта (в рантайме не используются)
```

```bash
npm run watch   # пересборка при изменении исходников
```

## Лицензия

AGPL-3.0-or-later, см. `LICENSE`.

## Происхождение

- Приложение создано с использованием ИИ (GitHub Copilot).
- Стикеры взяты из проекта <https://github.com/SaraVieira/open-source-stickers>:
  в репозитории лежат только паки `stickerpacks/gw-stickers` и
  `stickerpacks/SaraVieira-stickers`, остальные паки остаются локальными и в git
  не попадают (см. `.gitignore`).
