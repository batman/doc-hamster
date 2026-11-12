# DocHamster 🐹

<p align="center">
  <img src="docs/assets/hamster-wave.gif" width="168" alt="DocHamster mascot waving hello">
</p>

Snap a receipt, send it, forget it. DocHamster takes the photo with time and (optional) location, matches it to your bank transactions (CSV/Excel import from any bank), and builds a clean receipt pack (PDF) for your tax advisor.

> **Status: early version (v0.1).** Upload, receipt list, bank CSV import, tags, notes and export work; more is coming. The plan is in [`docs/PLAN.md`](docs/PLAN.md) (German).

## How to install

DocHamster installs itself from one file, `install.php`. You need no command line, no Composer and no Docker – only FTP (or your host's file manager) and a browser.

### What you need

- Web hosting or a server with **PHP 8.3 or newer** and the PHP extensions `pdo_mysql`, `mbstring`, `intl`, `sodium`, `fileinfo`, `exif`, `zip`, `gd`, `curl`
- A **MariaDB 10.11+ or MySQL 8.4+** database (empty, with its host, name, user and password at hand)
- A **fixed domain or subdomain with HTTPS**, e.g. `https://dochamster.example.com`. Choose it carefully: passkeys and the phone app are bound to it, so it can't be changed later.
- Best: a domain whose web folder is empty, and a parent folder the web server may write to (DocHamster then keeps its code outside the web folder)

### Step by step

1. **Download `install.php`** from the [latest release](https://github.com/batman/doc-hamster/releases/latest) (file `install.php` under "Assets").
2. **Upload it** with FTP or your host's file manager into the web folder of your domain (the folder the domain points to).
3. **Open it in the browser:** `https://dochamster.example.com/install.php`.
4. **Prove the web space is yours.** The page names a small text file that `install.php` has just created next to it. Open that file with FTP or the file manager, copy the code (`XXXX-XXXX-XXXX-XXXX`) and type it into the page. This stops strangers from taking over your installation.
5. **Server check.** The page lists every requirement with ✔ or ✘. Fix the red ones in your host's control panel (mostly PHP version, PHP extensions or HTTPS) and reload the page. When all are green, it shows the version it will install.
6. **Click "Installieren".** `install.php` downloads the release from GitHub, checks its SHA-256 checksum, unpacks it and connects it to your web folder. Files already in the web folder are kept as `….bak-<date>`. This takes about a minute.
   - If the parent folder isn't writable, DocHamster goes into a `dochamster` folder inside the web folder instead. The page then tells you to set your domain's document root to `…/dochamster/public` in your host's control panel – do that before you go on.
7. **`install.php` deletes itself.** If the page says it couldn't, delete it with FTP.
8. **Click "Weiter zur Einrichtung".** The setup wizard opens at `/setup` and walks you through:
   1. **Server** – a second, closer check. Missing PHP limits for uploads can be fixed with one click ("Automatisch einstellen"); the page reloads itself within five minutes.
   2. **Database** – enter host, port, database name, user and password. DocHamster checks the connection and creates its tables.
   3. **Finish** – create your **owner account** (organisation name, user name, password of at least 12 characters). You log in with it afterwards.
   4. **Background jobs** – the wizard shows one command line. Add it in your host's control panel as a cron job that runs **every minute**. You can do this later.
9. **Done.** Log in at `https://dochamster.example.com`. The wizard locks itself.

The installer stays usable for two hours after its first start. After that it locks for safety: delete the code file it names and upload `install.php` again.

### Updates

In DocHamster go to **Einstellungen → Update**. When a new version is available, click the button: it runs `install.php` in the background, keeps all receipts, accounts and settings, updates the database and brings you back. If an update fails halfway, the old version keeps running.

You can also upload `install.php` again by hand. It finds the existing installation and offers two choices:

- **Aktualisieren** (update) – everything is kept.
- **Neu installieren** (reinstall) – tick what to delete (receipts, users and roles, bank data, tags) and type `LÖSCHEN` to confirm. The database login is always kept. If you delete the users, the wizard asks for a new owner account.

There is no backup function in DocHamster yet. Use your host's backups for the files and the database.

Security note for operators: where PHP's Imagick extension is installed, DocHamster uses it (via Ghostscript) to thumbnail the first page of uploaded PDFs. Ghostscript has a history of code-execution CVEs — lock it down in ImageMagick's `policy.xml`, or disable PDF coders there if you want no PDF thumbnails at all.

## Try it in 2 minutes (Docker)

<p align="center">
  <img src="docs/assets/hamster-eat.gif" width="140" alt="Hamster happily munching receipts">
</p>

The fastest way to see DocHamster running. You need only Docker — no PHP, no Composer, no database, no hosting.

```sh
git clone https://github.com/batman/doc-hamster
cd doc-hamster
docker compose up -d --build
```

The first boot provisions itself (a few minutes): it writes the config, installs dependencies, sets up the database, and creates a demo owner. Then open http://localhost:8080 and log in as `owner` / `hamster-demo-01`.

`docker compose down` stops it and keeps your test data; `docker compose down -v` wipes everything for a fresh start. Custom demo logins and details: [`TRY.md`](TRY.md).

This is only for trying out DocHamster — real installations use `install.php` on hosting (see above).

## Planned features

- Mobile-first web app (PWA): camera → send, records capture time and optional location, works offline
- Bank statement import for **any bank** (CSV/Excel, mapping wizard), matched automatically to the photos
- Interface for live bank modules – e.g. an installable **Revolut Business** module with a step-by-step setup
- Receipt pack for your tax advisor: one PDF with every transaction and its receipt photo
- Server-side OCR (Tesseract), tags, categories, full-text search
- Voice notes with local speech-to-text
- Read-only access for your tax advisor
- Passkey device pairing – no password every time

<p align="center">
  <img src="docs/assets/hamster-sleep.gif" width="140" alt="Hamster dreaming of what's next">
</p>

DocHamster is **not** a tax or bookkeeping app, and it uses **no cloud AI**: speech-to-text and everything else runs on your own server.

## Tech

PHP 8.3+, Laravel, MariaDB 10.11+ or MySQL 8.4+, Blade + small vanilla JS PWA (no build step). No Docker needed: runs on any Linux server or web hosting that meets the minimum requirements; optional extras (OCR with Tesseract/ocrmypdf, local speech-to-text) only where the server has them. Final choices: [`docs/collaboration/DECISIONS.md`](docs/collaboration/DECISIONS.md).

The mascot is drawn by [`public/js/hamster-mascot.js`](public/js/hamster-mascot.js); the GIFs above are rendered from that same engine — see [`scripts/hamster`](scripts/hamster).

## Contributing

This project is built by a human product owner together with two AI agents. Rules for everyone (humans and AIs): [`AGENTS.md`](AGENTS.md) and [`docs/collaboration/WORKFLOW.md`](docs/collaboration/WORKFLOW.md). To run the code locally, use the Docker try-out above.

## License

[MIT](LICENSE)
