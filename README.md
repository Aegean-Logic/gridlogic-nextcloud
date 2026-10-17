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

- **Co-editing:** everyone with the file open edits the same workbook live (GridLogic's
  `docs/co-editing.md`). The app orders everyone's edits (`/apps/gridcraft/collab/*`, tables
  `gridcraft_sessions`, `gridcraft_ops`, `gridcraft_members`). GridLogic in each browser merges
  them. The member with the lowest client id saves the shared workbook to the file every 20 edits
  or 30 seconds, and on Save. A file changed outside the session (another app, a sync client)
  ends it: reload to pick the change up.
- **Presence:** everyone's selection shows on the grid in their colour with their name, and a
  strip above the editor lists who's editing (positions travel with each poll and are kept in
  `gridcraft_members.selection`).
- **Undo:** Undo and Redo take back a person's own edits only, never someone else's later change.
- **View-only access:** someone who can read but not write the file follows the others live; an
  edit they try is taken back with a message (and the server refuses it too). Only members who
  can edit save the shared workbook.
- **Charts, rules, notes:** two people adding a chart, a conditional-format rule or a note at
  once both keep theirs (they merge item by item).
- **Sharing:** GridLogic's Share button opens a Share dialog like Excel's: add people, groups or
  email addresses (Nextcloud's sharee search) with Can edit or Can view, add a message, and Send.
  Copy link makes or updates the file's public link, with link settings for permission, password
  and expiry. The people-with-access list changes or removes shares. The page carries the
  dialog's requests out with Nextcloud's sharing API (`src/share.js`). The GridLogic desktop app
  does the same after signing in to Nextcloud (GridLogic's `apps/gridcraft/src/nextcloud.rs`).
  Email invitations need outgoing mail set up in Nextcloud (Administration settings › Basic
  settings › Email server).

Not yet: public share links, file locking (`files_lock`), Save As into another folder.

## Layout

| Path | What |
|---|---|
| `appinfo/info.xml` | App manifest |
| `lib/` | `Application` (Files hook), `EditorController` (`/edit` page and its CSP), `CollabController` + `Service/CollabService` (the co-editing sequencer), `Migration/` (its tables), listener |
| `templates/editor.php`, `css/editor.css` | The editor page |
| `src/editor.js` | The host page: WebDAV load/save and the GridLogic postMessage bridge |
| `src/collab.js` | Co-editing: join, op push and polling, checkpoint saves |
| `src/share.js` | The Share dialog's operations, through Nextcloud's sharing API |
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
