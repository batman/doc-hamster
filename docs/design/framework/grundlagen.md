# UI framework – Grundlagen

Colours and surfaces, pills, badges, links, tooltips, icons. Part of the UI framework: read the core rules in [`../FRAMEWORK.md`](../FRAMEWORK.md) first. Live samples: `/design/styleguide-grundlagen.html`.

## Colours and surfaces

Tokens on `:root` in `css/core/base.css`, light and dark follow the system setting.

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

## Building blocks

- **Links** – never underlined, except inside running text (`p`, `.card-text`, hints) – and even there only in rare cases. Lists, heads, menus and tabs never show a link look.
- **Pill (task 110)** – `<span class="pill">`: the one small round label for states and counts, not clickable. Quiet framed by default ("Vorschau", OCR state, the count in a tab); `pill-ok` green ("installiert"), `pill-new` orange tint ("verfügbar"; also the count of the active tab). Replaces `.tag-outline` and `.tab-count`. `pill-warning` orange (e.g. "unsicher"); in the log (point 115, batch 16) `pill-hint` yellow marks a "Hinweis" (harmless, no badge) and `pill-error` red a "Kritisch" entry (something failed for the person; only these count for the log badge).
- **Count badge** – `<span class="badge">3</span>`: a round number. Orange; on an `icon-button` it sits on the top right corner (filter count). `badge badge-alert` red, for new things in the main menu (A8–A11). Replaces `.nav-badge`.
- **Tooltip** – `data-tooltip="…"`, to the right with `.tooltip-right`. Tooltips lie **above everything** (one layer on the page, drawn by app.js), so cards, columns and windows never cover or cut them; below the element, above it when there is no room. Mouse and keyboard only. A CSS rule that sets the element's `::after` to `display: none` still switches its tooltip off.
- **Icons** – Tabler 3.49.0 outline, `<x-icon name="…" />`, files in `resources/icons/tabler/`.
