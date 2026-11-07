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

48 px high (small 40 px), 10 px corners, semi-bold, no letter spacing, light shadow that presses in on click, orange focus ring for the keyboard, faded when disabled. A link stays underlined text and never looks like a button.

## 3. Cards, lists, toasts, dialogs

- **Card** – a framed block with a head: `<section class="card"><h2 class="card-head"><x-icon name="…" />Title <form class="card-action">…icon button…</form></h2> … </section>`. Text in a card: `.card-text` (readable line length), small print `.card-note`.
- **List with rows** – `<ul class="pick-list">` with `<li class="pick-row is-static">` (icon, `<span><strong>…</strong><small>…</small></span>`, optional button or `<span class="tag-outline">` label on the right). An empty or not yet loaded list shows `<li class="list-empty">Keine Einträge</li>`; an error `list-empty list-error`.
- **Ajax into a card** – `<form data-ajax="#target">`: sends in the background, the controller answers JSON `{html}` with a partial, which replaces `#target`. Without JavaScript the form posts as usual.
- **Label** – `.tag-outline`: quiet, framed, not clickable ("Vorschau", "Installiert").
- **Toast** – `<div class="toast" role="status" data-toast>…</div>`: top right (on phones at the bottom), closes itself after 5 s.
- **Dialog** – `<div class="dialog-backdrop" data-dialog="name" hidden><div class="dialog" role="dialog" aria-modal="true">…</div></div>`, opened by any `[data-dialog-open="name"]` (also in windows loaded later). `data-dialog-title` on the opener sets `[data-dialog-heading]`. Close: `[data-dialog-close]`, the backdrop, Escape. Names must be unique on the page – put the record's id in them. Asking for the password happens only in a dialog.
- **Tooltip** – `data-tooltip="…"`, to the right with `.tooltip-right`.
- **Icons** – Tabler 3.49.0 outline, `<x-icon name="…" />`, files in `resources/icons/tabler/`.

## 4. Layout

- Left: the main menu. Its wall can be dragged: wide = open, narrow = icons only, too narrow = hidden (then a floating menu button). Phones: a drawer.
- The content starts below a gap as high as the dark bar, so the floating menu button never covers a title; scrolling takes everything to the very top.
- Width too small: the content area scrolls sideways (minimum width of the active window 30rem), with a shade at the edge.

## 5. Window system

The general logic of the app: **new windows open on the right, all earlier ones collect on the left as the history.**

- Full: the **active** window and the one it was opened from (**previous**). All others are **strips** (icon and title turned by 90°).
- A click on a strip opens that window again; windows right of it only minimize, nothing closes.
- The **X** in a window's head closes it; the small X on a strip removes it from the history. A window without a close target (`close` prop) can't be closed – the root of a module.
- The browser address always shows the active window. Browser back goes to the window opened before. A reload keeps the history of this tab (`sessionStorage`); windows not shown yet come back as strips and load when opened.
- Changes glide (view transitions where the browser has them; none with "reduce motion").
- Phones: one window at a time, with a back arrow in its head.
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

**Links that open a window:** `<a href="…" data-window>`. The framework loads the target in the background with the header `X-DocHamster-Window: 1`; the layout then returns only the content (no menu, no frame), and the framework takes the page's last `section[data-window]`. If the target is already in the history, it is opened again instead of loaded twice. Modules need no extra route or controller code.

**`<x-window>` props:** `title`, `icon` (Tabler name), `url` (this window's address; default the current one), `close` (where the X leads without JavaScript; leave out for a window that can't be closed), slot `actions` (buttons in the head), extra classes via `class="…"`.

**JavaScript of a module:** windows arrive after the page has loaded, so "run on DOMContentLoaded" is not enough. Register instead:

```js
DocHamster.on('window:open', root => {
    root.querySelectorAll('[data-my-thing]').forEach(setUp);
});
DocHamster.on('window:close', root => { /* clean up timers etc. */ });
```

`window:open` runs for every window of the first page and for each window loaded later (`root` is that window; on pages without windows it is `document`). Prefer delegated listeners on `document` where you can.

**IDs:** a window can be open twice with different records – element ids, `for` attributes and dialog names carry the record's id.

### Reference module

Belege (`resources/views/documents/index.blade.php`, `_detail.blade.php`): the list is the root window, a receipt opens as a window to its right.

### Not yet on the window system

The settings columns (`.cols`, `layouts/settings.blade.php`) still run on their own column logic (fold to strip, drag walls, icons only). They move onto the window system in the next round; until then don't copy that logic into new modules.
