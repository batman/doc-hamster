# Building a docHamster module

This guide is written so that an AI assistant (e.g. Claude) can build a module from it alone. A person who wants a
feature says: *"Build me a docHamster module that … – follow docs/modules/BUILDING.md of the docHamster project."*
Copy the template in [`template/`](template/) and change it.

## What a module is (D-041)

- Program code that is **not** part of docHamster, installed on purpose by the owner of an installation – like a trailer
  on a car. It lives in its **own GitHub project** and can be offered by anyone.
- It may only **add**: its own windows and pages, an Einstellungen page, main menu entries, ⋯ entries of documents and
  bookings. It never changes or replaces docHamster's own pages.
- It talks to docHamster only through the `ModuleContext` it is handed, with the **rights** the owner granted.
- docHamster is German in the interface ("Sie"), English in code and comments. No cloud AI (D-019): a module must not
  send documents to an AI service.

## The project layout

```
dochamster-module.json      the Steckbrief (required)
src/Module.php              the entry class (required)
src/…                       more classes, PSR-4 below the module's namespace
resources/views/…           Blade views, used as view('m-<key>::name')
lang/de/<file>.php          texts, used as __('m-<key>::<file>.<key>')
database/migrations/…       migrations; every table starts with m_<key>_ (dashes become underscores)
public/…                    CSS, JS, images – served at /modules/<key>/…
.github/workflows/release.yml   packs and publishes the module (copy from the template)
```

## The Steckbrief: `dochamster-module.json`

| Field | Rule |
|-------|------|
| `key` | 3–40 characters, `a-z`, `0-9`, `-`, starting with a letter. Unique; it names folders, routes and tables. |
| `name`, `description`, `provider` | What the owner reads before installing (German). |
| `version` | `MAJOR.MINOR.PATCH`; the release tag is `v<version>`. |
| `homepage` | `https://…` of the project (optional). |
| `api` | The docHamster module interface it is built for: **1**. A module whose `api` does not fit is refused or switched off. |
| `rights` | What it needs, a subset of: `documents_read`, `documents_write`, `bookings_read`, `bookings_write`, `network`. Ask for as little as possible – the owner sees every right before installing; a new right in an update is asked again. |

The release workflow adds `zip_sha256` to the published copy; docHamster refuses a zip that does not match it.

## The entry class

Namespace: `DocHamsterModules\<Key in StudlyCase>\` (key `kassen-buch` → `DocHamsterModules\KassenBuch\`). Classes are
loaded only from this namespace and only from `src/`. The entry class is `<namespace>Module` in `src/Module.php` and
implements `App\Modules\Module`:

```php
final class Module implements \App\Modules\Module
{
    public function register(\App\Modules\ModuleContext $context): void { /* plug in here */ }
}
```

`register()` runs once per request – keep it cheap (no queries, no files). If it throws, docHamster switches the module
off and shows the reason on the Software page; docHamster itself keeps running.

## What the context offers (`App\Modules\ModuleContext`)

| Method | Does | Right |
|--------|------|-------|
| `routes(fn () => Route::get(...)->name('x'))` | Pages under `/m/<key>/…`, names `m.<key>.x`, for signed-in people | – |
| `settingsPage($title, $text, $icon, 'x')` | An entry under Einstellungen → System → Module (owner only) | – |
| `menuEntry($label, $icon, 'x')` | An entry in the main menu | – |
| `documentAction($label, $icon, 'x', fn () => bool)` | An entry in a document's ⋯ menu; the route gets `{id}`; the optional check decides per request whether it shows | – |
| `bookingAction($label, $icon, 'x')` | An entry in a booking's ⋯ menu; the route gets `{id}` | – |
| `documents()` | Query of the documents (never those in private folders) | `documents_read` |
| `bookings()` | Query of the bookings | `bookings_read` |
| `write('documents' \| 'bookings', fn () => …)` | Change them, in one transaction | `…_write` |
| `on('document.created' \| 'document.recognised' \| 'booking.created' \| 'link.confirmed', fn ($model) => …)` | React to events | `documents_read` / `bookings_read` |
| `bankAccount($sourceId, $name, $currency, $iban)` | An own bank account for an outside account (`source_key` = `<key>:<sourceId>`), made once | `bookings_write` |
| `adoptBankAccount($account, $sourceId)` / `findBankAccount($sourceId)` | Take over an existing account (e.g. one filled by CSV before) / find the own one | `bookings_write` / `bookings_read` |
| `planBookings($account, $lines)` | A dry run: per line `new`, `known` or `update` – nothing stored (for a Probelauf) | `bookings_read` |
| `importBookings($account, $lines)` | Import booking lines like a CSV import – no doubles by `booking_id`/`fingerprint`, then matching and suggestions | `bookings_write` |
| `attachReceipt($booking, $content, $filename, $title)` | Store a file as a document and link it to the booking (same file twice → once) | `documents_write` + `bookings_write` |
| `every($minutes, fn () => …)` | A recurring task (at least every 5 minutes); a task that throws switches the module off | – |
| `netJob($job)` | Anything that talks to the internet runs as a queued job on the `net` queue | `network` |
| `allows($right)` | Ask whether a right was granted | – |

Icons: names of the [Tabler icons](https://tabler.io/icons) that docHamster ships (`resources/icons/tabler`); others
become a puzzle piece.

A booking line for `importBookings` is an array with `booking_date` (Y-m-d), `amount_minor`, `currency`,
`counterparty`, `purpose`, `booking_id` (the bank's own id – this keeps it from being doubled, also against a CSV import
of the same bank) and `fingerprint`; optional `kind`, `bank_state`, `card_last4`, `mcc`, `orig_amount_minor`,
`orig_currency`, `fee_minor`, `balance_minor`, `related_id`. The Revolut module (`modules/revolut`) is a full example. Route names may be given short (`'x'`) – the context adds `m.<key>.`.

The rights make plain what a module wants and keep it in its lane through these methods. A module is still PHP code on
the owner's server; write it honestly, and use only the context for docHamster's data.

## Pages and the look

- Use only docHamster's building blocks – the styleguide (`/design/styleguide.html`) and `docs/design/FRAMEWORK.md`:
  `<x-window>`, cards (`section.card`), `pick-list` rows, buttons, `<x-pager>`, `<x-empty>` … No inline `style`
  attributes (the Content Security Policy blocks them), no own variants of buttons or fields; own CSS only in
  `public/` for things the framework does not have.
- An Einstellungen page: `@extends('layouts.settings')` with `@section('page')`.
- A window of its own: `@extends('layouts.app')`, `@section('main_class', 'page-windows')` and one `<x-window>` inside
  `<div class="windows" data-windows>`. Links with `data-window` open the next window on the right.
- Every text through `lang/de/*.php`; address the user with "Sie".

## Data

- Own tables only, each starting with `m_<key>_`; refer to docHamster records by their ULID (`document_id`,
  `transaction_id`). Money as integer minor units plus ISO currency.
- Removing a module keeps its tables unless the owner ticks "Daten des Moduls auch löschen" – then every `m_<key>_`
  table and its migration rows go.

## Publishing

1. Put the project on GitHub (public), with the template's `.github/workflows/release.yml`.
2. Set `version` in `dochamster-module.json`, then create a GitHub release with the tag `v<version>`.
3. The workflow attaches `module.zip` and `dochamster-module.json` (with `zip_sha256`) to the release.
4. In docHamster: Einstellungen → System → Software → Module → "Modul hinzufügen", enter the project address,
   "Suchen", "Installieren".
5. Updates: a new release; owners see it after "Update" on their Software page.

A project may hold several modules: the address `owner/repo#tag` takes the release with that tag (docHamster's own
modules live in `modules/<key>` of the docHamster project and are published as releases `module-<key>`).

To be suggested to every docHamster owner, ask for your project to be added to the public partner list
[`modules/partners.json`](../../modules/partners.json) of the docHamster project (a pull request with `"owner/repo"`).

## Checklist before a release

- `api` is 1, `key` and namespace match, `src/Module.php` exists.
- Only the rights that are really used.
- No secrets, no real people's data, no server addresses in the project (it is public).
- Every table starts with `m_<key>_`; every migration has a `down()`.
- Pages built from the styleguide's blocks, texts in German with "Sie".
