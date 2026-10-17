# GridLogic for Nextcloud

A minimal Nextcloud app (Nextcloud 32–34) that opens spreadsheets from Files in
GridLogic (by Aegean Logic, based on [GridCraft](https://github.com/storytold/gridcraft)) and saves them back.

- **Files:** clicking an .xlsx, .xlsm, .ods, .xls, .xlsb, .csv or .tsv file opens it in GridLogic
  ("Open in GridLogic" in the file's menu).
- **Editor:** `/apps/gridcraft/edit?path=/Documents/Budget.xlsx` shows GridLogic in an iframe on
  the Nextcloud origin (`?host=parent&ack=1`). The page reads the file over WebDAV with the
  user's session and writes back what GridLogic saves.
- **Conflicts:** saves send `If-Match` with the ETag the file had when it was opened (or last
  saved). If someone else changed it meanwhile, Nextcloud answers 412 and GridLogic tells the user
  instead of overwriting. A file the page didn't open or create is never overwritten
  (`If-None-Match: *`). Nextcloud keeps a version for every save.
- **Formats:** GridLogic saves workbooks as .xlsx. Saving an .ods, .xls, .xlsb or .csv creates
  `<name>.xlsx` next to it and leaves the original untouched.

Not yet: file locking (`files_lock`), read-only shares, public share links, Save As into another
folder, autosave.

## Layout

| Path | What |
|---|---|
| `appinfo/info.xml` | App manifest |
| `lib/` | `Application` (Files hook), `EditorController` (`/edit` page and its CSP), listener |
| `templates/editor.php`, `css/editor.css` | The editor page |
| `src/editor.js` | The host page: WebDAV load/save and the GridLogic postMessage bridge |
| `src/files.js` | The Files action |
| `js/` | Bundles built from `src/` (`npm run build`) |
| `editor/` | The GridLogic web build (`trunk build --release` in `gridcraft/apps/gridcraft-web`) |

## Build

```sh
npm install
npm run build
(cd ../gridcraft/apps/gridcraft-web && trunk build --release --dist ../../../gridcraft-nextcloud/editor --public-url ./)
```

## Install (Docker)

```sh
docker cp . nextcloud-app-1:/var/www/html/custom_apps/gridcraft
docker exec nextcloud-app-1 chown -R www-data:www-data /var/www/html/custom_apps/gridcraft
docker exec -u www-data nextcloud-app-1 php occ app:enable gridcraft
```

Leave `node_modules/` and `src/` out of a production copy. The web server must serve `.wasm` as
`application/wasm` (Nextcloud's `.htaccess` and nginx sample configs do).

## Licence

AGPL-3.0-or-later (the bundled `@nextcloud/*` libraries are AGPL/GPL). The GridLogic build in
`editor/` is MIT OR Apache-2.0.
