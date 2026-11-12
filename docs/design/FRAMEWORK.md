# docHamster UI framework

The one source for how docHamster looks and behaves. Every page and every module (Belege, Notizen, Ordner, Bank, Einstellungen, later the bank modules from their own repos) is built **only** from the building blocks below. Live samples: `/design/styleguide.html` (section "Framework").

Rules for agents (also in `AGENTS.md`):

1. Read this file before you build UI.
2. Use only these classes and components. No own colours, shadows, button looks or column/window logic in a module.
3. A new building block is made on the long-running branch `lead/framework`, documented here first, then used.
4. Plain CSS and JS without a build step (D-004): `public/css/app.css`, `public/js/app.js`. UI strings via `lang/`.

## 1. Colours and surfaces

Tokens on `:root` in `app.css`, light and dark follow the system setting.

| Token | Use |
|-------|-----|
| `--primary` `#ff7622` | Main buttons, active marks, icons in heads. **Filled orange is only ever a button.** |
| `--primary-hover` | Main button on hover |
| `--primary-ink` | Orange text and links on the page background |
| `--secondary` | The dark block behind logo and menu button |
| `--bg`, `--surface` | Page and slightly darker surfaces (menu, strips, rows) |
| `--text`, `--muted`, `--border` | Text, quieter text, lines |
| `--danger`, `--ok`, `--error` | Delete buttons, success, errors |
| `--shadow` | One soft dark shadow for everything that floats: tooltips, menus, dialogs, toasts |
| `--button-shadow` | The shadow under buttons |

Sticky notes keep their own 20 colours (`.note-*`); they are not app colours.

## 2. Buttons

Every `<button>` and `.button` is a button by default. Do not reuse its look for anything else.

| Kind | Markup | When |
|------|--------|------|
| Main | `<button>` | The one main action of an area |
| Side | `<button class="button-quiet">` | Cancel and side actions; outlined, no fill |
| Delete | `<button class="button-danger">` | Delete only |
| Small | add `button-small` | 40 px instead of 48 px, e.g. in list rows |
| Icon | `<button class="icon-button" aria-label="…" data-tooltip="…">` | Square, icon only, always with tooltip |
| Group | `<div class="button-group" role="group" aria-label="…">` around buttons | Buttons side by side (e.g. view choice); rounded only outside, the chosen one `aria-pressed="true"` |
| Control | `<button type="button" class="control-button" aria-label="…" data-tooltip="…">` | Window and column controls only (minimise ‹, unfold ›, maximise, close): **no frame**, icon in text colour, orange on hover; 36 px in window heads. The settings fold arrows (`.col-fold`) and the menu arrow are its 48 px flavour (points 70, A116) |

48 px high (small 40 px), 10 px corners (icon buttons too, link or button), semi-bold, no letter spacing, light shadow that presses in on click, orange focus ring for the keyboard, faded when disabled. A link stays underlined text and never looks like a button.

## 3. Form fields, cards, lists, toasts, dialogs

- **Form field** – label **above** the field (orange, semi-bold), a quiet thin frame, 8 px corners, 48 px high; while typing an orange frame with a soft orange ring; an error gives a thin red frame. The same look for text, password, search, date, number, `select` and `textarea` (grows with its text). Checkboxes, radios, colour and range keep their own look. Saved logins (autofill) look like typed text.
  ```html
  <div class="field">
      <label for="username">Benutzername</label>
      <input id="username" name="username" autocomplete="username">
      <p class="field-hint">Optional: a hint below the field.</p>
  </div>
  ```
  An error: `class="field has-error"` (red frame, red hint) or `aria-invalid="true"` on the field. Older forms with the label right before or around the field are drawn the same way; new code uses `.field`.
- **Small field** – `class="input-small"` on an `input` or `select`: 40 px high like small buttons, for tight spots (a search above a list, a field in a row). No label above, a placeholder says what goes in (and `aria-label`). Every `type="search"` field is small by itself.
- **Frame field** – `<fieldset><legend>Tags</legend>…</fieldset>` for a group (tags, checkboxes, colours): no browser frame, the legend stands above like a field's label.

- **Card** – a framed block with a head: `<section class="card"><h2 class="card-head"><x-icon name="…" />Title <form class="card-action">…icon button…</form></h2> … </section>`. Text in a card: `.card-text` (readable line length), small print `.card-note`.
- **List with rows** – `<ul class="pick-list">` with `<li class="pick-row is-static">` (icon, `<span><strong>…</strong><small>…</small></span>`, optional button or `<span class="tag-outline">` label on the right). An empty or not yet loaded list shows `<li class="list-empty">Keine Einträge</li>`; an error `list-empty list-error`. A row that opens something has its link around the title (`<strong><a href="…" data-window>Name</a></strong>`): **the whole row is the click area**, nothing is underlined or orange, hover darkens the row, the keyboard shows an orange frame around it. Buttons in the row stay clickable on their own.
- **Links** – never underlined, except inside running text (`p`, `.card-text`, hints) – and even there only in rare cases. Lists, heads, menus and tabs never show a link look.
- **Ajax into a card** – `<form data-ajax="#target">`: sends in the background, the controller answers JSON `{html}` with a partial, which replaces `#target`. Without JavaScript the form posts as usual.
- **Label** – `.tag-outline`: quiet, framed, not clickable ("Vorschau", "Installiert").
- **Toast** – `<div class="toast" role="status" data-toast>…</div>`: top right (on phones at the bottom), closes itself after 5 s.
- **Dialog** – `<div class="dialog-backdrop" data-dialog="name" hidden><div class="dialog" role="dialog" aria-modal="true">…</div></div>`, opened by any `[data-dialog-open="name"]` (also in windows loaded later). `data-dialog-title` on the opener sets `[data-dialog-heading]`. Close: `[data-dialog-close]`, the backdrop, Escape. Names must be unique on the page – put the record's id in them. Asking for the password happens only in a dialog.
- **Top layer (point 53)** – while shown, dialogs, dropdown panels, toasts and the tooltip lie in the browser's top layer above every window (app.js lifts them as `popover="manual"` when `hidden` goes away): never cut off at a window edge, a dialog's backdrop greys the whole app, later shown = on top. They stay in their place in the DOM, so forms, `closest('section[data-window]')` and per-window names keep working. Just toggle `hidden`; never set `popover` yourself.
- **Dropdown** – `<div class="dropdown"><button type="button" class="icon-button" aria-expanded="false">…</button><div class="dropdown-panel" hidden>…</div></div>`. Works in every window, also in windows loaded later and several side by side; a click outside, on an entry or Escape closes it. A tag filter (`form.dropdown[data-kind="filter"]`) only filters the list of its own window. The open panel hangs fixed below its button (right edges flush), turns rightwards or upwards when there is no room, follows scrolling and resizing, and scrolls when longer than the space.
- **"⋯" menu** – `<x-more-menu><a class="menu-item" href="…" data-window><x-icon name="pencil" /><span>Bearbeiten</span></a>…</x-more-menu>`: entries with icon and text; an entry with `data-window` opens its target as a window on the right. Fits in a window's `actions`. A line `<hr class="menu-separator">` sets entries apart; a destructive entry (Löschen) is `menu-item menu-danger`, red, always last below a separator. Entries never wrap; a menu is as wide as its longest entry. **A module's own ⋯ menu** is not written by the module: `<x-window>` draws it at the right end of the toolbar with the framework entry **"Notizen daneben öffnen"** on top; the module puts its entries in the slot `menu` (they come below a separator). A separator with nothing visible below it hides.
- **Column menu ⋮ (points 65–67)** – a column inside a module (e.g. a notes column) puts all its actions into `<x-more-menu class="kanban-menu" icon="dots-vertical" label="Spalte">` at the right of its head – no loose buttons below or beside the column. The button shows only while the column is hovered or the menu has focus or is open; on touch devices (`hover: none`) always. Order: add, rename (`data-focus="<edit-in-place id>"` starts click-to-edit), colour dialogs (`<x-color-picker>`), move left/right (`disabled` at the ends; `.menu-item:disabled` is greyed), a `.dropdown-title` group of `role="menuitemradio"` entries (sorting), bulk actions, and the red delete entry last. Column colour: class `has-color` + `c-rrggbb` tints the column (no inline styles).
- **Click-to-edit** – `<x-edit-in-place name="note" :action="route(…)" :value="…" multiline placeholder="…" label="…" />`: shows plain text with a quiet hover frame (grey placeholder when empty); click, or Tab + Enter, turns it into a field of the same size; blur or Ctrl+Enter saves (JSON POST, toast "Gespeichert."), Esc restores. Sticky notes are the model.
- **Sortable list** – `<ul class="pick-list" data-sortable>` with rows `<li class="pick-row is-static" data-id="…"><button type="button" class="pick-handle" aria-label="Verschieben"><x-icon name="grip-vertical" /></button>…</li>`. The **whole row** is the grip: with the mouse it lifts after a few pixels, with a finger after a short hold (a quick swipe still scrolls; the handle lifts at once). The row hangs on the pointer, a dashed placeholder keeps its old place (nothing jumps), an orange bar shows where it lands, near the edge the list scrolls along; on release it glides to the bar, Escape puts it back. A short click still opens the row. Keyboard on the handle: Space picks up, arrow keys move, Space drops, Escape puts back. Afterwards `DocHamster.on('list:sorted', ({ list, ids }) => …)` gets the list and the row ids in their new order; **the module saves**.
- **Icon tile** – `<span class="icon-tile"><x-icon … /></span>` in a row: 38 px square, 8 px corners, 22 px icon, spans both lines; light tint of the row colour (`--folder`), otherwise orange.
- **Row hover** – every list row (pick rows, table rows, menu and nav items) highlights with rounded corners (`--row-radius`, 8 px).
- **Colour picker** – `<x-color-picker name="color" :value="…" :allow-none :allow-own />`: the organisation's palette (Einstellungen → Farben, 20 colours to start), optionally "keine Farbe" and an own colour. Notes, folders and tags all use it; an item keeps its hex when the palette changes.
- **Row colour** – a row's icon takes the record's colour: set `--row-color` on the row (or a class that sets `--folder`), otherwise orange.
- **Tabs** – switch between views of one area:
  ```blade
  <nav class="tab-bar" aria-label="Bank">
      <a class="tab" href="…" aria-current="page"><x-icon name="receipt-off" /><span>Buchungen ohne Beleg</span><span class="tab-count">3</span><span class="tab-mark"></span></a>
      <a class="tab" href="…"><x-icon name="link" /><span>Zugeordnete Buchungen</span><span class="tab-count">12</span></a>
  </nav>
  ```
  Tabs over a thin line, the active one in text colour with an orange bar (`.tab-mark`, only in the active tab) that glides to the next tab; the others quiet, darker on hover. Icon and count are optional. Too narrow: the bar scrolls sideways, no wrapping. Arrow keys, Home and End move between tabs. One tab bar per page (the gliding bar has one name). An empty view below shows `<p class="list-empty">…</p>`.
- **Tooltip** – `data-tooltip="…"`, to the right with `.tooltip-right`. Tooltips lie **above everything** (one layer on the page, drawn by app.js), so cards, columns and windows never cover or cut them; below the element, above it when there is no room. Mouse and keyboard only. A CSS rule that sets the element's `::after` to `display: none` still switches its tooltip off.
- **Icons** – Tabler 3.49.0 outline, `<x-icon name="…" />`, files in `resources/icons/tabler/`.

## 4. Layout

- **The main menu has no sub-items** (PO, 2026-11-07). Every sub-area, entry and record opens on the right as a window.
- Left: the main menu. Its wall can be dragged: wide = open, narrow = icons only, too narrow = hidden (then a floating menu button). Phones: a drawer.
- The content starts below a gap as high as the dark bar, so the floating menu button never covers a title; scrolling takes everything to the very top.
- Width too small: the window row never scrolls sideways (point 64); windows shrink and become strips instead (§5).

## 5. Window system

- **Search in the toolbar** – `<x-window search="Placeholder">`: a small search field in the window toolbar; typing reloads the window body with `?q=…`, the module reads `q`.
- **Progress dialog** – a dialog without `data-dialog` (not closable) with a `<progress>` and a step line, for long server work (update install).

The general logic of the app (points 22, 24, 40, PO 2026-11-08): **every window is an area of its own** – it scrolls by itself and closes by itself, without touching the others.

**Principle (PO, 2026-11-11, must hold for every module):** every window in the row is **one instance of a module bound to one resource** (folder window = folder module for folder X, detail window = detail module for file Y), like Explorer windows: same code, look and functions per instance, each works on its own inside its column. Several windows of the same module side by side must work – **including the same folder or document twice**: a click always opens a **new** window to the right, never just focuses an existing one. The row is a path/history: a **main-menu choice drops the whole row**; opening something in window N closes everything right of N and opens the new window at N+1.

**Modules (PO, 2026-11-12, points 71, 73):** folder windows, document details, the settings levels and pages, and the **whole notes board** are modules. A module's inner parts (e.g. the notes columns) are plain content, never modules, and never fold. Every module window behaves like a Windows window: **minimise, maximise, close** – nothing else (point 74: no pin; maximise does its job). Windows stay in a row (no free desktop) and are loaded via fetch.

- A link with `data-window` in a window **closes every window right of that window** and opens the new window on the right – always a new instance, also when the same address is open already. Which windows become strips decides the width rules below.
- **Widths like Apple's split view (NSSplitView; points 54, 55, 64, A91–A94).** Every window declares **min, optimal, max** width and a **priority** (`<x-window>` props `min`, `optimal`, `max`, `priority`; defaults per `kind`: `folder` 20/26/34rem prio 1 · `trash`, `mail` 22/30/40rem prio 1 · `notes` 22/40/96rem prio 1 · `detail` 30/44/64rem prio 2 · `text` 32/48/72rem prio 2 · others 24/32/48rem prio 1). The row **never scrolls sideways**: the windows always share exactly the width there is. A window keeps its width from one layout to the next; only the difference is shared out (open, close, minimise, restore, browser/menu resize):
  - too little room → windows shrink towards their min, **lowest priority first, among equals from the left**; still too little → the lowest-priority window becomes a **strip** – never the newest or the one just opened/opened again (that one keeps its width, at most its preferred, while the others make room);
  - more room → a strip the layout made opens again as soon as the wide windows **at their min** leave room for its **min + 4rem** (A96: hysteresis, almost where it collapsed; a window that keeps its width or was sized by hand counts with that width, at most its preferred); then windows grow back to their preferred width, then to their max; the rest goes to the last wide window;
  - a click on a strip opens it again at its preferred width; the others make room by the same rules.
- **Dragging an edge (A91–A94):** computed from the state at drag start plus the whole distance, so the edge stays under the pointer; windows left of it never move. Wider: the windows right of it give way, lowest priority first (among equals from the left), each down to its min; then the edge stops, and **3rem** further the lowest-priority one snaps to a strip (never the newest). Dragging back until its min fits snaps it (and strips the layout made on the right) open again at its min – a 3rem band in between. Narrower: the right neighbour takes the room. During a drag nothing else lays out; on release the width is stored for **that window only**. The last window has no edge. Each window lies above the next one (descending `z-index`), so the edge's right half over the neighbour stays grabbable (A95).
- **Measuring jumps (point 63 / A90):** `?debug=layout` switches a measurement on for the tab (`?debug=off` ends it): user actions, window size changes and strips, layout shifts and elements gone and back within 300 ms; a small panel bottom right copies the log ("Messung kopieren") or empties it.
- **Strip:** like the settings strips – the unfold arrow **›** (`chevron-right`) on top on the head line opens it again, then icon and title turned by 90°, the small X.
- **Head (like Windows, points 42, 70, 72, A116–A124):** icon, title (always visible, ellipsis), then on the right the three **window controls**, always in this order and look (frameless `control-button`s, 36 px): **minimise** ‹ (`chevron-left`, "Minimieren"), **maximise** (`arrows-maximize`, "Maximieren"; while maximised `arrows-minimize`, "Wiederherstellen", `aria-pressed="true"`), **close** X (`x`, only with a `close` target). The line of the settings column heads.
  - Minimise makes the window a strip and it **stays** one until it is clicked.
  - Maximise: this module fills the row, **all others become strips** (`data-maxed` on the window, `is-maxed` on the row, no drag edges). A second click, or a **double-click on the title**, restores every window's strip state and width from before. Opening a window, clicking a strip, minimising or closing ends the maximised state; a reload keeps it.
  - **Phones (< 64rem):** only the back arrow (left) and X; no minimise, no maximise.
- **Toolbar (point 41):** the search field, the module's buttons (`actions`, small 36 px icon buttons) and, at the right end, the module's ⋯ menu stand in a row below the head. It follows **the window's own width** (container queries on `.win-frame`, container `win`), not the screen: wide = one row (search left, buttons right); below 44rem the search takes its own row; below 28rem the window is **narrow**: elements with `win-wide-only` hide, elements with `win-narrow-only` show. Use that to turn a button group into one dropdown button and extra buttons into entries of the module's ⋯ menu (slot `menu`) (reference: the view switcher and "Neues Dokument"/"Neuer Ordner" in `documents/index.blade.php`). The toolbar wraps before anything is cut at the window edge.
- The window just opened or opened again scrolls into view.
- **Width by hand (points 44, 54.5):** every wide window has a drag edge on its right (`.win-edge`, same look as the wall right of the menu): drag or arrow keys (Shift = bigger steps) set its width by the drag rules above – also below its min (at least 12rem); double-click goes back to the module's optimal. The width belongs to **that one window** (A97: `data-window-hand`, kept in the tab's window history in `sessionStorage`, so it survives a reload); for the layout it **is** that window's preferred width. New windows always start at their kind's optimal width. Not on phones.
- **Window state lives in its address (point 52):** a module setting that belongs to one window (e.g. the list view `?view=timeline`) is a query field of the window URL, so each instance keeps its own and reload keeps it; changing it reloads only that window (`DocHamster.reloadWindow(win, url, '.win-frame')`). A window without it starts with the person's last choice.
- The **X** in a window's head closes just that window; the small X on a strip removes it from the history. If no wide window is left, the nearest strip opens. A window without a close target (`close` prop) can't be closed – the root of a module.
- The **active** window is the one opened last or clicked into; the browser address and the title follow it. Browser back opens the window of the entry before (the entry names the window, so two windows with one address work). A reload keeps the history of this tab with which windows were minimised or maximised (`sessionStorage`); windows not loaded yet load when they are shown. A link in the main menu drops that history.
- Changes glide (view transitions where the browser has them; none with "reduce motion").
- Phones: one window at a time, with a back arrow in its head (opens the window left of it).
- Without JavaScript every link is a normal page; the page shows its windows side by side.

### How a module uses it

**Page:** wrap the windows of a page in `<div class="windows" data-windows>` and set `@section('main_class', 'page-windows')`. The page's **last** window is the page itself.

```blade
@section('main_class', 'page-windows')
@section('content')
    <div class="windows" data-windows>
        <x-window :title="__('documents.title')" icon="receipt" :url="route('documents.index')">
            <x-slot:actions>…filter, view…</x-slot:actions>
            …the list…
        </x-window>
        @if ($open)
            <x-window :title="$open->title" icon="file-text" :url="route('documents.show', $open->id)" :close="route('documents.index')">
                …the receipt…
            </x-window>
        @endif
    </div>
@endsection
```

**Links that open a window:** `<a href="…" data-window>`. The framework loads the target in the background with the header `X-DocHamster-Window: 1`; the layout then returns only the content (no menu, no frame), and the framework takes the page's last `section[data-window]`. Every click loads a new instance, even for an address that is open already. Modules need no extra route or controller code.

**`<x-window>` props:** `title`, `icon` (Tabler name), `url` (this window's address; default the current one), `close` (where the X leads without JavaScript; leave out for a window that can't be closed), `upload` (where an upload belongs while this window is active, as form fields, e.g. `upload="folder=01H…"`), `min`, `optimal`, `max` (widths for the automatic layout, e.g. `min="36rem"`) and `priority` (higher = becomes a strip later), defaults per `kind` (see the width rules above), `kind` (module type, e.g. `folder`, `detail`; default the route name), `search` (placeholder of a search field in the toolbar, see below), slot `actions` (buttons in the toolbar), slot `menu` (the module's entries of its ⋯ menu, below the framework's "Notizen daneben öffnen"), extra classes via `class="…"`. Every window has the toolbar row (at least the ⋯ menu).

**Notes beside any module (points 71, 73, A121, A122):** "Notizen daneben öffnen" is a plain `data-window` link to the notes module (`route('home')`), so it follows the open rules: a new notes window to the right, the rest of the path closes. The notes module can be open several times; the main menu "Home" opens it as the first module.

**Search field (A104):** `<x-window search="Ordner und Belege suchen">` puts a small search field (`input-small`) in the toolbar; in a window below 44rem it gets its own row. While typing (300 ms pause) the framework loads the window's address with `?q=…` (same `X-DocHamster-Window: 1` fetch as window links) and swaps **only the body** (`.win-body`), so the field keeps focus and caret; the address follows with `replaceState` (no history entry per key), reload and the stored history keep `q`. A new search drops `page`. The field's X or Escape clears it and loads without `q`. Every window has its own search; the new body gets `window:open`. **What is found is the module's job:** its controller reads `q` (and must render the search field's window again with the filtered body). Without JavaScript the field is a normal GET form.

**Uploads:** "Beleg hochladen" in the menu and files dropped on the page take the `upload` fields of the **active** window and send them along. Which fields a window sets and what the server does with them is up to the module.

**JavaScript of a module:** windows arrive after the page has loaded, so "run on DOMContentLoaded" is not enough. Register instead:

```js
DocHamster.on('window:open', root => {
    root.querySelectorAll('[data-my-thing]').forEach(setUp);
});
DocHamster.on('window:close', root => { /* clean up timers etc. */ });
```

`window:open` runs for every window of the first page and for each window loaded later (`root` is that window; on pages without windows it is `document`). Prefer delegated listeners on `document` where you can.

**IDs (multi-instance):** a window can be open twice – also with the **same** record. Write ids, `for`, `form`, `aria-*` references and dialog names as usual (with the record's id where it helps reading); the framework gives every id in a window a per-window suffix and rewrites the references in that window (`for`, `form`, `list`, `aria-labelledby/-describedby/-controls/…`, `href="#…"`, `data-copy`, `data-focus`, `data-dialog`/`data-dialog-open`). A dialog opener finds its dialog in its own window first. In module JavaScript never look up window content with `document.getElementById` or `document.querySelector` – start from the window (`root` of `window:open`, or `el.closest('section[data-window]')`) and keep state per element (`WeakMap`), not per page.

### Reference module

Belege (`resources/views/documents/index.blade.php`, `_detail.blade.php`): the list is the root window, a receipt opens as a window to its right. Notizen (`resources/views/home/index.blade.php`, `kind="notes"`): one window for the whole board; its JavaScript (`setupBoard` in app.js) runs per board on `window:open` and only looks inside its own window, so several boards side by side work (drag & drop, dialogs, column titles, tag filter reloading only its own window).

### Not yet on the window system

The settings columns (`.cols`, `layouts/settings.blade.php`) still run on their own column logic (fold to strip, drag walls, icons only). They move onto the window system in the next round; until then don't copy that logic into new modules.
