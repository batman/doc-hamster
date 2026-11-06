# Proposal: DocHamster Scan Station (printer and scanner module)

Status: **draft for the Lead** (issue #77, Q-30). Written by the Manager on the PO's request.
Scope change: amends D-015 ("no hardware scanner") – needs a `plan` PR and the Lead's decision.

## 1. Goal (PO)

Scan and print with DocHamster from a normal home printer:

- scan one page, or several pages as **one document**;
- save into **folders**;
- print a document straight from DocHamster;
- easy to install for **any** printer, no vendor software, no e-mail, open source (MIT).

## 2. What was tested on a real printer (a home inkjet printer)

| Test | Result |
|------|--------|
| Printer reachable on the LAN, driverless scan interface (eSCL, "AirScan") | Works. A real scan job returned a PDF (blank-glass test, 150 dpi colour). |
| Feeder and flatbed, PDF/JPEG/TIFF, up to 300 dpi (1200 optical) | Reported by the printer. |
| Display menu "Scan" | Only "Web Scan" (a pull feature). No e-mail, folder or cloud scan. |
| Windows scan service (WS-Scan): register a custom destination "DocHamster Test" | Accepted by the printer (token issued), but **never shown** on the display; selecting "Scan to Computer" printed an "install the vendor software" sheet. |
| Admin session: shortcut list | Only the 15 built-in apps of the printer; no user or cloud shortcuts. |
| Admin session: scan destination setting | Is the "digital send" feature switch, empty on this model. The list of computers for "Scan to Computer" is a separate setting that the vendor's own software owns (admin token gets 403). |
| Local approval by pressing OK on the display | Disabled in the printer settings; PIN only. |

**Conclusion:** on this model nothing we can install or configure *inside* the printer will add a "DocHamster" entry to its display. The vendor firmware is closed and signed, and the consumer line has no scan-to-folder/e-mail destination we could point at us. We therefore build the receiving side, not a printer program.

## 3. Design: one module, two ways in, one way out

Module repo: `DocHamster-scanner` (MIT), installed on demand like bank modules (D-021). The core only gets the generic interfaces, folders and a "scan" document source.

### 3.1 Way in A – scan started from DocHamster (works with every printer that speaks eSCL)

eSCL is an open Mopria/PWG-style protocol; almost every printer and scanner made since ~2015 supports it (HP, Brother, Canon, Epson, Xerox, Ricoh, Samsung, Kyocera).

1. **Scan page** in DocHamster: choose folder, colour, resolution, "Scan page" or "Scan multiple pages".
2. Feeder stack: one job, pages are fetched until the printer says "no more". Flatbed: after each page "Scan next page?" / "Done".
3. Pages are merged into **one PDF** (pure PHP) and go through the normal pipeline (OCR, tags, folder).
4. **Phone web app (PO request 2026-11-06):** when a printer or Scan Station is reachable, the capture screen shows "Scan from printer/scanner" next to the camera. It uses the same screen as the scan page (folder, one page or several), and the result continues in the same flow as a camera photo. Ingestion reuses the INBOX upload interface with a device key (#36).
5. Triggers that need no PC:
   - the DocHamster page on the phone (PWA icon);
   - a **QR or NFC sticker on the printer lid** that opens "Scan now" with the folder preset (one tap);
   - a physical or smart button (webhook URL per folder) – works with any home-automation button.

### 3.2 Way in B – printer pushes (printers that can scan to folder)

Many printers (Epson, Brother, Canon, Xerox, Ricoh, Kyocera, and most office models) have "scan to network folder/FTP" on the display. The module runs a small **receiver** (FTP/FTPS/SFTP first; SMB only if a permissive implementation exists). The wizard shows the exact values to type into the printer once. The printer then offers "DocHamster" (or "DocHamster – Invoices") as a normal display entry; the directory name maps to the DocHamster folder; files arriving within a short window from one job are merged.
This is the only way to get a true on-printer button, and it needs a printer that has the feature. The home printer tested here does not.

### 3.3 Way out – printing

Print a document from the DocHamster document view over **IPP** (port 631, standard in AirPrint/Mopria printers). If the printer rejects PDF, convert to an accepted raster/JPEG format. Fallback needing nothing: the browser print dialog.

## 4. Easy install (no PC left on, no vendor software)

Same code, three ways to run it, the wizard picks what fits:

1. **Built into DocHamster** when DocHamster runs on the same network as the printer (home server/NAS): `php artisan scanner:install` – no extra device.
2. **Scan Station** for a DocHamster on a remote server: a tiny always-on device on the home network (Raspberry Pi image on an SD card or USB stick, or a Docker one-liner on a NAS/router). It connects **out** to DocHamster over HTTPS (no open ports, no router changes), pairs with a code like devices do (P1-4), finds printers by mDNS and shows them in a list.
3. **Desktop helper** (optional, later): the same Scan Station as an installer for Windows/macOS for people who would rather run it on a computer they already have.

A USB stick is only a way to ship the Pi image; nothing is ever installed *on* the printer.

## 5. Folders

Core feature, not module: `folders` table (ULID, `org_id`, parent, name), `folder_id` on documents, create/rename/move/delete UI, folder filter in search, audit log. Scan profiles in the module point at a folder. Folders are needed by uploads and the receipt pack too.

## 6. Open source and licence plan (MIT)

- Everything we ship is MIT. We write our own eSCL and IPP clients from the **public** specifications (Mopria eSCL spec, PWG IPP, RFC 8011).
- **Do not copy** GPL code: sane-airscan, AirSane, scanservjs, Samba and similar are references for behaviour only, never linked or vendored (D-022: no GPL/AGPL in what we ship).
- Dependencies only permissive (MIT/BSD/Apache/ISC); each one listed in `docs/DEPENDENCIES.md`; `composer check-licenses` in CI.
- Do **not** use the vendor's private, reverse-engineered interfaces or tokens. They are undocumented, tied to the admin PIN and may break with firmware; the module relies on open protocols only.
- Brand: describe compatibility in words ("works with printers that support eSCL, tested with the models named in the README"); no vendor logo or copied vendor text; "not affiliated" note (same rule as for Revolut, D-021).
- Check the Mopria spec's licence terms before the first release and note the result in the module's README.

## 7. Phases (all need the Lead's decisions first, AGENTS rule 4/6)

0. Decisions: amend D-015; interface shape (`ScanSource`, `PrintTarget`, like Q-11); Scan Station pairing and security; folders in core.
1. Core: folders (data model, UI, tests).
2. Core: module hooks – scan document source, job queue, Scan Station pairing API, install command.
3. Module: eSCL client, scan session (single, feeder, flatbed loop), merge to PDF, scan page, QR/NFC and button links.
4. Module: Scan Station (Docker + Pi image), mDNS discovery, wizard.
5. Module: receiver for scan-to-folder printers (FTP/SFTP), brand-specific setup hints.
6. Module: IPP printing.
7. Docs, licence check, secret scan, tests with fixtures from a fake eSCL server (no real scans in the repo).

Complexity: high. Roughly 5–6 PRs in core and the module repo.

## 8. Risks

- No on-printer button on printers without a scan-to-folder feature (the printer tested here). Mitigation: QR/NFC sticker, smart button, phone icon.
- A remote DocHamster needs the small always-on Scan Station; home-network installs do not.
- eSCL implementations differ per vendor; needs tests against several printers.
- Receiver for push printers needs hardening (auth, quotas, file-type checks, no path traversal).
- Mopria spec licence terms still to be read.

## 9. Test list for the PO (once built)

1. Install the module with one command; the wizard lists the printer.
2. Scan one page into a folder; it appears with OCR.
3. Put a stack in the feeder; one document with all pages.
4. Scan from the sticker/button; it lands in the preset folder.
5. Print a document from DocHamster.
