# UI redesign: hand-over from the PO's design session

The PO went through the old styleguide page by page and collected twelve points. The result is a **new styleguide** and a **new stylesheet**. Both live next to the running app and change nothing in it yet:

| File | What it is |
|------|------------|
| `public/design/styleguide.html` | All components and **every app page** with the new markup (open it at `/design/styleguide.html`). It is the reference for the markup. The "Belege" page there works: filter, six views, month switch. |
| `public/design/app.css` | The new stylesheet. It replaces `public/css/app.css` **together with** the markup changes below (CSS alone would break the current layout). |
| `resources/icons/tabler/*.svg` | New Tabler icons (outline, MIT): filter, filter-check, list-details, list, layout-grid, layout-cards, timeline, calendar, settings, device-mobile, chevron-down, chevron-right, check, file-text. |

The old `public/styleguide.html` still matches the current `app.css` and stays until the new CSS is applied.

## The twelve points (decided by the PO)

1. **File picker** is a button in our style (the filename shows beside it). Done in CSS (`input[type="file"]::file-selector-button`). The orange "Beleg hochladen" button in the menu stays.
2. **No full-width black header.** The dark block sits only above the menu and is as wide as the menu: wide menu = hamburger + logo, narrow menu = hamburger only, closed menu = only the dark button, top left.
3. **Pages without a menu** (login, setup wizard) show only the logo in the dark block, top left.
4. **Content uses the full width** next to the menu, left aligned, no 40rem column. Inputs stay at 32rem and rights groups at 40rem.
5. **Filter** is an icon button with a dropdown of checkboxes (our checkbox style). Active filter: `filter-check` icon, orange border and a count badge.
6. **The filter applies immediately** with every tick, no "apply" button. "Filter zurücksetzen" sits inside the dropdown.
7. **Filtering happens in the browser** where possible (no server round trip). With paging the server still has to filter; keep the query parameters as a fallback.
8. **Icons come from Tabler only.** Own icons only in real exceptions and after asking the PO.
9. **No hamster in the app for now.** Remove it from the empty list, the drop overlay and the layout (`js/hamster.js` include, the carrot/"eat" mood in `js/app.js`). Keep `public/js/hamster.js` in the repo; the PO will set rules for it later.
10. **Receipt views like the Windows Explorer** on the Belege page: Details, Liste, Kleine Symbole, Große Symbole, plus Zeitstrahl (by month, newest first) and Kalender (month grid). Date = upload date for now (there is no receipt date yet).
11. **View button** (icon + dropdown, like the filter). Tooltips "Ansicht" and "Filter" use the existing `data-tooltip` style (no browser `title`). The chosen view is remembered per person.
12. **New menu**: Beleg hochladen, Belege, Bank (bookings and matching), Einstellungen, Abmelden. **Einstellungen** is a new page with groups: Zugang (Personen, Rollen), Belege (Tags), Bank (Konten und Import), Export, System (Update, Handy-Test).

## What the app code has to change

- `resources/views/layouts/app.blade.php`: new frame `div.shell > aside.side (header.site-header + nav.sidebar) + div.content`; pages without menu use `aside.side.side-plain`. Menu state classes (`nav-rail`, `nav-closed`, `nav-open`) work on `<html>` (as today) or on `.shell`. Remove the `hamster.js` include and the drop-overlay hamster.
- `public/js/app.js`: keep toggle/collapse logic for the new structure; remove the hamster mood code; add the dropdown (open/close, Escape, click outside), instant filtering and the view switch (remember per person: server side).
- Menu: three entries (Belege, Bank, Einstellungen). New route and page "Einstellungen" (`.settings-groups`, see the styleguide). Personen, Rollen, Tags, Export, Update, Handy-Test and the bank account/import pages sit under it; the Bank page loses the "Kontoauszug importieren" link.
- `documents/index.blade.php`: `.page-head` with `h1` and `.toolbar` (filter and view dropdowns); six views as in the styleguide (`.view-details`, `.view-list`, `.view-tiles.view-small|view-large`, `.timeline`, `.calendar`). The tag chips (`.chips`) remain for "Tags ändern" and the upload.
- Empty list and drop overlay: text only.
- Phone test lines: `.check-state` and `.check-detail` now have CSS.
- New UI strings (German, via `lang/de`): Filter, Ansicht, the six view names, Einstellungen and its group names/descriptions, "Filter zurücksetzen", month names via the locale.
- Replace `public/css/app.css` with `public/design/app.css`, delete `public/design/` and the old `public/styleguide.html`, and update tests that look for the old markup.

## Decided without asking (for the Lead to veto)

Phone: only the menu button top left, the menu is a drawer. The filter combines tags with AND (as today). Date for timeline and calendar is the upload date.

---

# Round 2: points 13 to 23 (PO design session, 2026-11-03)

Reference for the markup: **`public/design/styleguide.html`** (open it at `/design/styleguide.html`; it uses `public/css/app.css` and `public/design/styleguide.js`). All new styles are **added at the end of `public/css/app.css`**, existing rules are unchanged, so the running app keeps working until the views are changed. The sample pages in the styleguide work: Belege (list left, details right, six views, filter with "Anwenden"), Tags, Einstellungen (column menu).

## The points (decided by the PO)

13. **Icons, not thumbnails, in Details, Liste and Kleine Symbole.** The file type icon (image = `photo`, PDF = `file-type-pdf`, text = `file-text`). Only **Große Symbole**, the **Zeitstrahl** and the receipt view show thumbnails.
14. **Thumbnails are made once, at upload, and stored.** The list loads the small stored image, never the original. Loading must be clearly faster than today. (Not visible in the styleguide.)
15. **Documents are never cropped**, not by rounded corners, not by `object-fit: cover`. The whole page sits in a frame (`.thumb-frame`: our colour, border, rounded corners, padding). The rounding belongs to the frame.
16. **No "Tags ändern" button in the table.** The Tags column only shows the pills. Tags are changed in the receipt view (17).
17. **Receipt view**: a click on a receipt opens it next to the list (see 20), not in a new window. It shows the whole document, the actions download, edit, send, delete (icon buttons with tooltip), the tags (pills to toggle), a note, and the history (uploaded, created, last opened, last downloaded, file type, booking). Delete asks first in our own dialog (`.dialog`), not a browser dialog.
18. **Tags are pills** (`.tag`, colours `.tag-c1` to `.tag-c6`). Always a "#" in front (drawn by CSS, the markup holds only the name), never a bare word, always side by side and wrapping like text, never one below the other. Selected (`.is-selected`, filled) and not selected (only border) have the **same size**. Ticking several pills in a list changes nothing yet: the filter dropdown collects the choice, **"Anwenden"** applies it, "Zurücksetzen" clears it.
19. **Tags page** (under Einstellungen): search field on top (filters the pills while typing, any letter inside the name), pills as running text. Two panes: pills left, editor right. The editor shows "Neuen Tag anlegen" until a pill is clicked. Then: name, colour (six choices), number of receipts, delete (with dialog). The number: **under 100 receipts** it is a link to the receipt list filtered by this tag; **from 100** it unfolds first (`details`) into counts per type (images, PDFs, texts), each linking to the filtered list. A X at the top right closes the editor and shows "Neuen Tag anlegen" again.
20. **Master-detail everywhere.** A click on a list entry splits the window: list left, details right (`.page-split`, `.split-main`, `.split-detail`). Linked records open in the right pane, with a way back. Below **80rem** window width the right pane opens as a dialog over the list (same content). Applies to receipts, tags, people, roles, bank and every other list.
21. **Einstellungen as column menu.** First only the group names with their own icon. A click splits the window: the submenu on the right. A click on an entry splits again: the settings page on the right. **At most two panes are open** (the menu counts separately); a further split folds the left pane to a strip.
22. **Folded strips** (`.col-strip`): icon and text run top to bottom and are rotated like the text. The Einstellungen strip reads: gear, "Einstellungen", dash, group icon, group name. The strip of the submenu reads: entry icon and entry name. Every open pane (except the page) has an arrow (chevron left, "nach innen") to fold it. Folding the submenu gives the page the whole width. Folding is by `.col-collapse` buttons, unfolding by a click on the strip.
23. **Accordion.** A click on the strip of "Einstellungen" opens it again and folds the submenu to a strip; the page on the right stays open. So every step back is visible without leaving the page. Rule: opening a strip closes the open pane directly to its right; the page (rightmost) always stays.

## Decided without asking (for the Lead to veto)

- Small symbols show only the type icon (the PO named only the large ones).
- Tag colours: six fixed colours, one per tag (`tags.color` 1 to 6, default 1). Contrast: selected pills use dark text on the colour.
- Tags page is a "leaf group": it has no submenu, so the second pane is the tag list and the third the editor.
- Dialog breakpoint 80rem (menu plus list plus details need room).
- Pill selection in dropdowns uses a real checkbox hidden inside the label (`.tag-choice`), so keyboard and screen readers work.

## What the app code has to change (UI-APPLY-2)

1. **Views** (`resources/views/documents/views/*`): Details, Liste, Kleine Symbole use type icons (`_thumb` only where thumbnails are allowed). Tiles (large), timeline and receipt view wrap the image in `.thumb-frame`; the image uses `object-fit: contain`. Tags column and all tag lists use `.tags` and `.tag.tag-cN`. Remove the "Tags ändern" `details` from the table.
2. **Thumbnails (point 14)**: generate at upload (images: scaled copy, aspect ratio kept; PDF: first page; text: none, show the icon) and store them next to the document; serve them with cache headers; the list uses only them. Existing documents: a one-time command or job to create the missing thumbnails. If a thumbnail is missing, show the type icon, never a broken image. Choice of library and size is the Lead's.
3. **Receipt view** in the right pane (and as dialog below 80rem): the content listed in 17. New data per receipt: a note, "last opened" and "last downloaded" timestamps (data model change: PR labelled `plan`). Also its own URL so that a receipt can be linked.
4. **Filter dropdown**: tags as `.tag-choice` pills, buttons "Zurücksetzen" and "Anwenden" (same height). The view dropdown stays as it is.
5. **Tags**: new column `color` on `tags` (data model change, label `plan`). New Tags page as in 19, search in the browser.
6. **Einstellungen**: the column menu as in 21 to 23 (see `mountSettings` in `public/design/styleguide.js` for the exact behaviour; the folding logic is a few lines). The groups: Personen & Rollen, Bank, Tags, Export, Update.
7. **Delete dialogs** use `.dialog`, not `confirm()`.
8. Tests: update tests that look for the old markup.

## Open for the Lead (product question to the PO, not decided)

- **"Versenden"** on a receipt: to whom and how (email address, link, advisor)? Show the button disabled until this is settled.

## Point 24: new logo

`public/img/logo.png` is the new logo for dark surfaces (white document symbol and "DOC", "Hamster" in the orange gradient); the dark block at the top left is always dark, so the app uses this file everywhere. `public/img/logo-on-light.png` is the same logo with black parts for light surfaces outside the app (README, social preview). Both are 476 x 186 px (ratio about 2.6 : 1, the old one was 3.9 : 1), so the logo in the dark block is now `3rem` high (`.brand img`) and the `width`/`height` attributes are 476 x 186.

