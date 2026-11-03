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
