# DocHamster – Detaillierter Projektplan

> **Status:** Planungsphase (v1-Entwurf, 2026-10-29). Noch kein Code.
> **Vorgänger:** [`archive/plan-v0-belegbox.md`](archive/plan-v0-belegbox.md) (Arbeitstitel „Belegbox“). Alles aus v0 ist hier enthalten und ausgearbeitet.
> **Wer bearbeitet diesen Plan?** PO (Product Owner) plus zwei KI-Agenten. Regeln für Änderungen: [`collaboration/WORKFLOW.md`](collaboration/WORKFLOW.md). Offene und getroffene Entscheidungen: [`collaboration/DECISIONS.md`](collaboration/DECISIONS.md).

Lizenz: **MIT**. Code, Kommentare und Commits: **Englisch**. Oberfläche: **Deutsch** (i18n vorbereitet).

---

## Inhalt

1. [Vision & Abgrenzung](#1-vision--abgrenzung)
2. [Nutzer & Rollen (Personas)](#2-nutzer--rollen-personas)
3. [Kernprinzipien](#3-kernprinzipien)
4. [Funktionsumfang mit Akzeptanzkriterien](#4-funktionsumfang-mit-akzeptanzkriterien)
5. [Architektur](#5-architektur)
6. [Datenmodell](#6-datenmodell)
7. [HTTP-Schnittstelle (Routen & API)](#7-http-schnittstelle-routen--api)
8. [Verarbeitungs-Pipeline (OCR, Extraktion, Tags)](#8-verarbeitungs-pipeline-ocr-extraktion-tags)
9. [Kontoauszug-Import & Abgleich](#9-kontoauszug-import--abgleich)
10. [Berichte & Export](#10-berichte--export)
11. [Sicherheit, Anmeldung, Datenschutz](#11-sicherheit-anmeldung-datenschutz)
12. [Betrieb & Deployment](#12-betrieb--deployment)
13. [Qualität & Tests](#13-qualität--tests)
14. [Umsetzungsplan: Phasen & Arbeitspakete](#14-umsetzungsplan-phasen--arbeitspakete)
15. [Risiken](#15-risiken)
16. [Glossar](#16-glossar)

---

## 1. Vision & Abgrenzung

**Ein Satz:** Beleg fotografieren, senden, vergessen – DocHamster sammelt, liest, ordnet und findet ihn wieder, und gibt dem Steuerberater am Jahresende ein sauberes Paket.

**Problem:** Belege landen in Hosentaschen, Mail-Postfächern und Fotogalerien. Am Monats- oder Jahresende fehlt die Hälfte, und die Zuordnung zu Kontobuchungen ist mühsame Handarbeit.

**Lösung:** Eine installierbare Web-App (PWA), die im Alltag genau eine Sache schnell macht (Erfassen), und auf dem Server alles Weitere erledigt (OCR, Tags, Kategorien, Abgleich mit Kontoauszügen, Berichte).

**Erfolgskriterien (messbar):**

| # | Kriterium | Ziel |
|---|-----------|------|
| E1 | Zeit von „App öffnen“ bis „Gespeichert“ (1 Foto, gutes Netz) | ≤ 5 s, ≤ 2 Taps |
| E2 | Upload ohne Netz geht nicht verloren | 100 % (Offline-Queue) |
| E3 | Erkennung Datum + Betrag auf typischen deutschen Kassenbons | ≥ 80 % korrekt ohne KI |
| E4 | Automatischer Abgleich Buchung ↔ Beleg (bei vorhandenem Beleg) | ≥ 70 % automatisch, Rest per 1 Klick |
| E5 | Installation auf frischem Server laut README | ≤ 30 min |

**Nicht-Ziele (bewusst ausgeschlossen):**

- Keine Steuer-App: keine Steuerberechnung, keine Steuererklärung, keine USt-Voranmeldung.
- Keine Buchhaltung: kein Kontenrahmen (SKR03/04), keine Buchungssätze, keine GoBD-Zertifizierung.
- Kein Banking: kein Abruf per HBCI/FinTS/PSD2 in v1 (nur Datei-Import). Kann später als Erweiterung kommen.
- Keine native App (iOS/Android); die PWA muss reichen.

---

## 2. Nutzer & Rollen (Personas)

| Persona | Beschreibung | Wichtigste Aufgabe |
|---------|--------------|--------------------|
| **Owner** | Selbstständige/r oder Haushalt, betreibt die Instanz bzw. die Organisation | Erfassen, Prüfen, Konto-Import, Berichte, Rechte vergeben |
| **Mitglied** | Partner/in, Mitarbeitende, Vereinsmitglied | Belege hochladen, eigene Belege sehen |
| **Steuerberater** | Externe Person, nur lesend | Freigegebene Belege und Berichte ansehen und herunterladen |
| **Instanz-Admin** | Technische/r Betreiber/in (oft = Owner) | Installation, Updates, Backups, Organisationen anlegen |

### 2.1 Rechte-Matrix

| Aktion | Owner | Mitglied | Steuerberater |
|--------|:-----:|:--------:|:-------------:|
| Beleg hochladen | ✅ | ✅ | ❌ |
| Eigene Belege sehen/bearbeiten | ✅ | ✅ | ❌ |
| Alle Belege der Organisation sehen | ✅ | ⚙️ (einstellbar) | 🔶 nur freigegebene |
| Belege bearbeiten (Metadaten, Tags) | ✅ | eigene | ❌ |
| Beleg löschen (Papierkorb) | ✅ | eigene, ⚙️ | ❌ |
| Endgültig löschen | ✅ (mit Bestätigung) | ❌ | ❌ |
| Kontoauszug importieren / Abgleich | ✅ | ❌ | ❌ |
| Berichte erstellen | ✅ | ❌ | 🔶 für freigegebene Zeiträume |
| Export (ZIP/PDF/CSV) | ✅ (mit Bestätigung) | ❌ | 🔶 freigegeben |
| Mitglieder/Steuerberater einladen/entziehen | ✅ (mit Bestätigung) | ❌ | ❌ |
| Kategorien/Regeln verwalten | ✅ | ❌ | ❌ |
| Audit-Log sehen | ✅ | ❌ | ❌ |

Legende: ✅ erlaubt · ❌ verboten · ⚙️ per Org-Einstellung · 🔶 eingeschränkt durch Freigabe (`shares`).

**Freigabe-Modell Steuerberater:** Eine Freigabe (`share`) gilt für eine Organisation und optional eingeschränkt auf Zeitraum (`from`/`to`), Kategorien oder Bankkonten, optional mit Ablaufdatum. Ohne Freigabe sieht der Steuerberater nichts.

---

## 3. Kernprinzipien

1. **Geschwindigkeit vor Vollständigkeit.** Upload ohne Pflichtfelder, ohne Rückfragen. Alles Weitere ist optional und nachträglich möglich.
2. **Mobile first.** Daumen-bedienbar, große Ziele (≥ 48 px), Kamera zuerst.
3. **Einmal koppeln, dann in Ruhe lassen.** Kein Passwort im Alltag, trotzdem sicher (Geräte-Token + Passkey).
4. **Server macht die Arbeit.** OCR, Tags, Kategorien, Abgleich laufen asynchron.
5. **KI-fähig, nicht KI-abhängig.** Alles funktioniert ohne KI. KI ist ein austauschbarer, abschaltbarer Provider – und standardmäßig **aus** (Datenschutz).
6. **Originale sind heilig.** Hochgeladene Dateien werden nie verändert; abgeleitete Versionen sind separat.
7. **Einfach zu hosten.** SQLite-Standard, Docker optional, keine externen Pflichtdienste.
8. **Nachvollziehbar.** Jede automatische Entscheidung (Tag, Kategorie, Match) speichert Quelle und Konfidenz; manuelle Korrekturen gewinnen immer.

---

## 4. Funktionsumfang mit Akzeptanzkriterien

Priorität: **M** = Must (v1.0), **S** = Should (v1.x), **C** = Could (später).

### 4.1 Erfassen (Hauptscreen) – M

**User Story:** Als Owner/Mitglied will ich einen Beleg mit möglichst wenigen Taps erfassen, damit ich es sofort an der Kasse erledige.

Ablauf:
1. App öffnen → großer Kamera-Button in der Mitte. Darunter klein: „Datei wählen“ (Galerie/PDF).
2. Foto aufnehmen. Danach Vorschau-Leiste: „+ Seite“ (mehrseitig bündeln) oder **Senden**.
3. Nach dem Senden erscheint ein überspringbarer Zusatz-Schritt (auch später in der Detailansicht erreichbar):
   - Notiz (Text),
   - Sprachnotiz (Audio, S),
   - Kategorie-Chips (die 6 meistgenutzten),
   - Zahlungsart-Chips (Firmenkarte / privat / bar / Überweisung – konfigurierbar).
4. Bestätigung „Gespeichert“ (bzw. „Wird gesendet, sobald du online bist“). App kann sofort geschlossen werden.

Akzeptanzkriterien:
- [ ] AK-4.1.1: Ein Foto ist mit 2 Taps (Kamera, Senden) gespeichert.
- [ ] AK-4.1.2: Bis zu 20 Seiten können zu einem Dokument gebündelt werden.
- [ ] AK-4.1.3: Ohne Netz wird der Upload in IndexedDB gepuffert und automatisch gesendet, sobald Netz da ist (Background Sync, Fallback: beim nächsten Öffnen). Die Warteschlange ist sichtbar (Badge mit Anzahl).
- [ ] AK-4.1.4: Ein abgebrochener Upload (App geschlossen) wird beim nächsten Öffnen fortgesetzt.
- [ ] AK-4.1.5: Doppeltes Senden desselben Uploads erzeugt kein Duplikat (Client-seitige Upload-UUID, Server idempotent).
- [ ] AK-4.1.6: Erlaubte Formate: JPEG, PNG, HEIC/HEIF (Server-Konvertierung), WebP, PDF. Max. 25 MB pro Datei (konfigurierbar).
- [ ] AK-4.1.7: Bilder werden vor dem Upload clientseitig auf max. 3000 px lange Kante verkleinert (konfigurierbar, Original optional).
- [ ] AK-4.1.8: Teilen-Ziel: In der installierten PWA kann man aus anderen Apps „Teilen → DocHamster“ (Web Share Target, S).

**C – später:** Weitere Eingangskanäle: E-Mail-Postfach (Belege per Mail weiterleiten), Ordner-Watch/WebDAV.

### 4.2 Verarbeitung auf dem Server – M

Details zur Pipeline in [Kapitel 8](#8-verarbeitungs-pipeline-ocr-extraktion-tags).

- [ ] AK-4.2.1: Nach Upload ist der Status `uploaded`; innerhalb von 2 min (typisch) `processed`.
- [ ] AK-4.2.2: OCR-Volltext ist durchsuchbar.
- [ ] AK-4.2.3: Datum, Gesamtbetrag, Währung, Händler werden best effort extrahiert, jeweils mit Konfidenz. Nie Pflicht.
- [ ] AK-4.2.4: Tags und Kategorie werden per Regeln vorgeschlagen; manuelle Korrektur erzeugt (optional) eine neue Regel („Immer so zuordnen?“).
- [ ] AK-4.2.5: Duplikate werden markiert (nicht gelöscht): exakter Datei-Hash oder gleicher Betrag + Datum ± 1 Tag + ähnlicher Händler.
- [ ] AK-4.2.6: Fehlgeschlagene Verarbeitung → Status `failed` mit Fehlertext, „Erneut versuchen“-Button; der Upload bleibt erhalten.

Statusmodell Dokument:

```
uploaded → processing → processed → reviewed
                 ↘ failed (retry → processing)
(any) → trashed → (nach 30 Tagen oder manuell) purged
```

### 4.3 Wiederfinden – M

- [ ] AK-4.3.1: Eine Suchleiste durchsucht OCR-Text, Notizen, Tags, Händler (Volltext, deutsche Wortstämme wenn möglich).
- [ ] AK-4.3.2: Filter: Zeitraum, Kategorie, Tag, Betrag von/bis, Zahlungsart, Status, Hochgeladen von, „ohne Buchung“, „Duplikat“.
- [ ] AK-4.3.3: Liste als Kacheln (Thumbnail, Datum, Betrag, Händler) und als Tabelle; unendliches Scrollen oder Paginierung.
- [ ] AK-4.3.4: Detailansicht: Bild/PDF mit Zoom und Seitenwechsel, erkannter Text, Metadaten editierbar, Tags, verknüpfte Buchung, Verlauf (Audit).
- [ ] AK-4.3.5: „Prüfen“-Modus: Durchwischen aller `processed`-Belege, je Beleg bestätigen/korrigieren → `reviewed`.
- [ ] AK-4.3.6: Suche liefert bei 10.000 Belegen Ergebnisse in < 1 s.

### 4.4 Kontoauszug-Import & Abgleich – M

Details in [Kapitel 9](#9-kontoauszug-import--abgleich).

- [ ] AK-4.4.1: Import von CSV (beliebiges Trennzeichen, Encoding UTF-8/ISO-8859-1/Windows-1252) und XLSX.
- [ ] AK-4.4.2: Spaltenzuordnung per Assistent, als Profil pro Bankkonto speicherbar; bekannte Banken als Vorlagen (S).
- [ ] AK-4.4.3: Erneuter Import überlappender Zeiträume erzeugt keine doppelten Buchungen.
- [ ] AK-4.4.4: Automatischer Abgleich mit Score; Vorschläge über Schwelle werden auto-verknüpft (als „automatisch“ markiert), darunter als Vorschlag angezeigt.
- [ ] AK-4.4.5: Drei Ansichten: Buchungen ohne Beleg · Belege ohne Buchung · Zugeordnete Paare. Manuelle Zuordnung per Klick bzw. Drag & Drop (Desktop).
- [ ] AK-4.4.6: Eine Buchung kann mehreren Belegen zugeordnet werden (Sammelbuchung) und umgekehrt (Teilzahlungen) – S.
- [ ] AK-4.4.7: Buchung als „braucht keinen Beleg“ markierbar (z. B. Gehalt, Umbuchung) mit Grund.

**S – später:** Import von CAMT.053 (XML) und MT940.

### 4.5 Berichte & Export – M

Details in [Kapitel 10](#10-berichte--export).

- [ ] AK-4.5.1: Bericht für Zeitraum + Konto(en): alle Buchungen mit verknüpften Belegen, fehlende Belege rot markiert, Summen.
- [ ] AK-4.5.2: Export als PDF, CSV, XLSX und ZIP (Belegdateien, sortiert nach `JJJJ-MM/Kategorie/`, Dateiname `JJJJ-MM-TT_Händler_Betrag.ext`, plus `index.csv`).
- [ ] AK-4.5.3: Erstellung großer Exporte asynchron (Job) mit Benachrichtigung, Download-Link mit Ablaufdatum (Standard 7 Tage).
- [ ] AK-4.5.4: Versand per E-Mail (SMTP) als Link, nie als großer Anhang (Anhang nur ≤ 10 MB, optional).

### 4.6 Benutzer, Organisationen, Einladungen – M

- [ ] AK-4.6.1: Eine Instanz hat mehrere Organisationen; jede Person kann Mitglied mehrerer Organisationen sein (Org-Wechsler).
- [ ] AK-4.6.2: Daten sind strikt pro Organisation getrennt (jede Abfrage ist org-gefiltert; automatisierter Test pro Repository/Endpoint).
- [ ] AK-4.6.3: Einladung per Link/QR-Code mit Rolle, einmalig nutzbar, Ablauf 7 Tage.
- [ ] AK-4.6.4: Steuerberater-Zugang: Einladung mit Freigabe-Umfang (Zeitraum, Konten, Kategorien), optional Ablaufdatum, jederzeit widerrufbar. Widerruf wirkt sofort (laufende Sitzungen enden).

### 4.7 Sprachnotizen & KI – S/C

- S: Sprachnotiz aufnehmen (MediaRecorder, Opus/WebM bzw. AAC/MP4 auf iOS), abspielen.
- C: Transkription über Provider-Schnittstelle (lokales Whisper / whisper.cpp oder API).
- C: KI-Kategorisierung/Extraktion über Provider-Schnittstelle (`AiProvider`), pro Organisation aktivierbar, mit klarer Datenschutz-Info („Belegtext wird an X gesendet“). Ergebnis wird wie eine Regel-Quelle behandelt (`source = ai`, Konfidenz).

### 4.8 Einstellungen – M

- Profil, Geräte (Liste, Name, zuletzt genutzt, sperren), Passkeys verwalten.
- Organisation: Name, Kategorien, Zahlungsarten, Regeln, Mitglieder, Freigaben, Aufbewahrung, Papierkorb-Dauer.
- Datenexport (DSGVO Art. 20) und Konto löschen.

---

## 5. Architektur

### 5.1 Überblick

```
┌──────────────┐  HTTPS   ┌─────────────────────────────┐      ┌──────────────┐
│ PWA (Browser)│ ───────▶ │ PHP-App (Web)                │ ───▶ │ DB (SQLite/  │
│ - Kamera     │          │ - Routing, Auth, Rechte      │      │  MariaDB)    │
│ - Offline-   │ ◀─────── │ - Upload-Annahme             │      └──────────────┘
│   Queue (IDB)│          │ - Suche, Berichte, Admin-UI  │      ┌──────────────┐
│ - Service    │          └──────────────┬──────────────┘ ───▶ │ Datei-Storage│
│   Worker     │                         │ Jobs (DB-Queue)      │ (außerhalb   │
└──────────────┘                         ▼                      │  Webroot)    │
                          ┌─────────────────────────────┐ ───▶ └──────────────┘
                          │ Worker (CLI, Cron/Supervisor)│
                          │ - Bild-Normalisierung        │ ──▶ tesseract, ocrmypdf,
                          │ - OCR, Extraktion, Tags      │     pdftotext, imagemagick/
                          │ - Abgleich, Exporte, Mails   │     vips, (whisper)
                          └─────────────────────────────┘
```

### 5.2 Technik-Stack (Vorschlag; Entscheidungen in DECISIONS.md)

| Bereich | Vorschlag | Alternative | Entscheidung |
|---------|-----------|-------------|--------------|
| Sprache | PHP 8.3+ (8.2 minimal) | – | D-002 |
| Framework | **Laravel** (Queue, Migrations, Mail, Storage, Tests, Policies „gratis“) | Slim 4 + Einzelpakete (schlanker, mehr Eigenbau) | **D-001 offen** |
| DB | SQLite (Standard, FTS5 für Volltext) | MariaDB/MySQL (FULLTEXT) über gleiche Abstraktion | D-003 |
| Frontend | Server-gerendertes HTML + kleines Vanilla-JS (ES-Module), kein Build-Zwang | htmx/Alpine.js als leichte Helfer | D-004 |
| CSS | Eigenes kleines CSS mit Custom Properties (Light/Dark) | Pico.css | D-004 |
| OCR | Tesseract 5 (`deu+eng`), `ocrmypdf` für PDFs | API-OCR als Provider | D-005 |
| Bildverarbeitung | libvips (`php-vips`) oder Imagick; HEIC über libheif | – | D-005 |
| XLSX | PhpSpreadsheet (Import & Export) | OpenSpout (schneller, streaming) | – |
| PDF-Erzeugung | Dompdf | wkhtmltopdf/Chromium headless | – |
| Passkeys | `web-auth/webauthn-lib` | `lbuchs/WebAuthn` | D-006 |
| Verschlüsselung | Volume-Verschlüsselung (Pflicht-Empfehlung) + optional libsodium (`secretstream`) pro Datei | – | D-007 |
| Tests | PHPUnit/Pest, Playwright (E2E) | – | – |
| Qualität | PHPStan (Level 8), PHP-CS-Fixer/Pint, ESLint | – | – |

### 5.3 Verzeichnisstruktur (Ziel, framework-neutral skizziert)

```
/app            Domain + Anwendungslogik
  /Domain       Documents, Imports, Matching, Reports, Sharing, Users (Entities, Services, Policies)
  /Http         Controller, Middleware (Auth, Org-Scope, CSRF, RateLimit)
  /Jobs         ProcessDocument, RunOcr, ExtractFields, ApplyRules, MatchTransactions, BuildExport, SendMail
  /Infrastructure  Storage, Ocr (TesseractOcr), Ai (AiProvider + NullProvider), Crypto
/config
/database/migrations
/public          index.php, manifest.webmanifest, sw.js, assets/ (einziger Webroot!)
/resources
  /views         Templates
  /lang/de, /lang/en   i18n
  /js, /css
/storage         (außerhalb Webroot) originals/, derived/, exports/, tmp/
/tests           Unit/, Feature/, E2E/, fixtures/ (anonymisierte Beispielbelege, Beispiel-CSVs)
/docs            Plan, Entscheidungen, Betriebsdoku
/docker          Dockerfile, compose.yaml
```

### 5.4 Modul-Schnitt (wichtig für parallele Arbeit zweier KIs)

| Modul | Verantwortung | Abhängig von |
|-------|---------------|--------------|
| `Core` | Gerüst, Config, DB, Migrations, i18n, Layout | – |
| `Auth` | Gerätekopplung, Passkeys, Sitzungen, Einladungen | Core |
| `Org` | Organisationen, Mitgliedschaften, Rollen, Policies | Core, Auth |
| `Capture` | PWA, Upload-API, Offline-Queue, Storage | Core, Auth, Org |
| `Processing` | Job-Queue, OCR, Extraktion, Regeln, Duplikate | Core, Capture |
| `Search` | Volltext, Filter, Detailansicht, Prüfen-Modus | Processing |
| `Banking` | Import-Profile, Parser, Transaktionen, Matching | Core, Org |
| `Reports` | Berichte, Exporte, Mail | Search, Banking |
| `Sharing` | Steuerberater-Freigaben, Audit-Log | Org, Reports |
| `Ai` | Provider-Schnittstelle, Transkription | Processing |

Module kommunizieren nur über Services/Interfaces und Domain-Events (z. B. `DocumentUploaded`, `DocumentProcessed`, `TransactionsImported`), nicht über fremde Tabellen direkt.

---

## 6. Datenmodell

Konventionen: IDs als ULID (Text, sortierbar, nicht ratbar) für öffentlich sichtbare Entitäten; `created_at`/`updated_at` überall; Geldbeträge als **Integer in Cent** (`amount_minor`) + `currency` (ISO 4217); Datumsangaben ohne Uhrzeit als `DATE`; Soft-Delete über `deleted_at` wo angegeben. Jede fachliche Tabelle hat `org_id`.

### 6.1 Identität & Zugriff

| Tabelle | Felder (Auszug) | Hinweise |
|---------|-----------------|----------|
| `users` | id, email (unique, nullable für reine Passkey-Nutzer?), display_name, locale, password_hash (nullable), totp_secret_enc (nullable), created_at | Passwort nur als Fallback |
| `passkeys` | id, user_id, credential_id (unique), public_key, sign_count, transports, name, last_used_at | WebAuthn |
| `devices` | id, user_id, name, user_agent, token_hash, token_family, last_used_at, last_ip_hash, revoked_at, created_at | Langlebiges Geräte-Token, Rotation |
| `organizations` | id, name, settings_json, retention_days, trash_days | |
| `memberships` | id, org_id, user_id, role (`owner`/`member`/`accountant`), created_at, revoked_at | unique(org_id,user_id) |
| `invitations` | id, org_id, role, token_hash, email (optional), share_scope_json, expires_at, used_at, created_by | Einmal-Link/QR |
| `shares` | id, org_id, membership_id, date_from, date_to, bank_account_ids_json, category_ids_json, expires_at, revoked_at | Umfang für Steuerberater |

### 6.2 Dokumente

| Tabelle | Felder (Auszug) | Hinweise |
|---------|-----------------|----------|
| `documents` | id, org_id, uploaded_by, client_upload_id (unique pro org), status, title, doc_date, amount_minor, currency, merchant_id, merchant_raw, category_id, payment_method_id, note, ocr_text, language, duplicate_of_id, reviewed_at, reviewed_by, deleted_at, created_at | Kern |
| `document_fields` | id, document_id, field (`doc_date`/`amount`/`merchant`/`category`…), value, source (`ocr`/`rule`/`ai`/`user`), confidence (0–1), created_at | Nachvollziehbarkeit jeder Erkennung |
| `document_files` | id, document_id, kind (`original`/`normalized`/`thumbnail`/`searchable_pdf`), page_no, path, mime, size, sha256, width, height, encrypted (bool) | Originale unverändert |
| `audio_notes` | id, document_id, path, mime, duration_s, transcript, transcript_source | S/C |
| `merchants` | id, org_id, name, normalized_name, default_category_id | Lernt aus Korrekturen |
| `categories` | id, org_id, name, color, icon, sort, archived | |
| `payment_methods` | id, org_id, name, sort | Chips |
| `tags` | id, org_id, name (`#reise`) | |
| `document_tags` | document_id, tag_id, source, confidence | |
| `rules` | id, org_id, priority, match_type (`merchant`/`text_contains`/`regex`/`amount_range`), match_value, action_category_id, action_tags_json, action_payment_method_id, created_from_document_id, enabled | Regel-Engine |
| `documents_fts` | Volltextindex (SQLite FTS5 bzw. MySQL FULLTEXT) über ocr_text, note, merchant, tags | |

### 6.3 Banking

| Tabelle | Felder (Auszug) | Hinweise |
|---------|-----------------|----------|
| `bank_accounts` | id, org_id, name, iban_masked, currency, import_profile_id | IBAN nur maskiert speichern |
| `import_profiles` | id, org_id, name, file_type, encoding, delimiter, decimal_sep, thousands_sep, date_format, header_row, column_map_json, sign_convention | Speicherbar, Vorlagen |
| `imports` | id, org_id, bank_account_id, file_path, file_sha256, rows_total, rows_new, rows_duplicate, rows_error, created_by, created_at | Protokoll |
| `transactions` | id, org_id, bank_account_id, import_id, booking_date, value_date, amount_minor, currency, counterparty, purpose, raw_json, fingerprint (unique pro Konto), no_receipt_reason | Fingerprint gegen Doppelimport |
| `matches` | id, org_id, transaction_id, document_id, score, reasons_json, status (`suggested`/`auto`/`confirmed`/`rejected`), decided_by, decided_at | n:m möglich |

### 6.4 System

| Tabelle | Felder (Auszug) |
|---------|-----------------|
| `jobs` | id, queue, type, payload_json, attempts, max_attempts, available_at, reserved_at, failed_at, error | (bzw. Framework-Queue) |
| `exports` | id, org_id, type, params_json, status, file_path, expires_at, created_by |
| `download_links` | id, export_id, token_hash, expires_at, downloads, max_downloads |
| `audit_log` | id, org_id, user_id, device_id, action, subject_type, subject_id, meta_json, ip_hash, created_at (append-only) |

---

## 7. HTTP-Schnittstelle (Routen & API)

Web-Oberfläche serverseitig gerendert; zusätzlich eine kleine JSON-API für die PWA (Upload, Queue, Status). Alle Routen außer Login/Einladung erfordern Sitzung; alle fachlichen Routen sind org-gebunden (`/o/{org}/…` oder aktive Org in Sitzung – **D-008**).

| Methode | Pfad | Zweck | Rolle |
|---------|------|-------|-------|
| GET | `/` | Erfassen-Screen | owner, member |
| POST | `/api/documents` | Upload (multipart, `client_upload_id`, Seiten), idempotent | owner, member |
| PATCH | `/api/documents/{id}` | Notiz, Kategorie, Zahlungsart, Felder | owner, member (eigene) |
| POST | `/api/documents/{id}/audio` | Sprachnotiz | owner, member |
| GET | `/api/documents/{id}/status` | Verarbeitungsstatus (Polling) | |
| GET | `/documents` | Liste, Suche, Filter | alle (gefiltert) |
| GET | `/documents/{id}` | Detail | alle (gefiltert) |
| GET | `/files/{fileId}` | Datei-Auslieferung **nur über Rechteprüfung** (X-Sendfile/X-Accel optional) | alle (gefiltert) |
| POST | `/documents/{id}/review` | als geprüft markieren | owner, member |
| DELETE | `/documents/{id}` | in Papierkorb | owner, member (eigene) |
| GET/POST | `/bank/accounts`, `/bank/imports` | Konten, Import-Assistent | owner |
| GET | `/bank/reconcile` | Abgleich-Ansichten | owner |
| POST | `/bank/matches` / `/bank/matches/{id}/confirm|reject` | Zuordnung | owner |
| GET/POST | `/reports`, `/exports` | Berichte, Exporte | owner, accountant (freigegeben) |
| GET | `/d/{token}` | Download-Link | Token |
| GET/POST | `/settings/...` | Profil, Geräte, Org, Regeln, Mitglieder, Freigaben | je nach Bereich |
| GET/POST | `/pair/{token}`, `/login`, `/webauthn/*` | Kopplung & Anmeldung | öffentlich |

API-Konventionen: JSON, Fehler als `{ "error": { "code": "...", "message": "..." } }`, CSRF-Token auch für API (gleiche Herkunft), Versionierung nicht nötig (nur eigener Client).

---

## 8. Verarbeitungs-Pipeline (OCR, Extraktion, Tags)

Job-Kette pro Dokument (jeder Schritt idempotent, einzeln wiederholbar):

1. **`NormalizeFiles`** – MIME per Magic Bytes prüfen; HEIC→JPEG; Bilder neu kodieren (entfernt Schadcode/EXIF-GPS), Ausrichtung korrigieren, Thumbnails (320 px) erzeugen; PDFs: Seitenanzahl, Text-Layer prüfen.
2. **`RunOcr`** – Bilder: Tesseract (`deu+eng`, PSM 4/6 je nach Seitenverhältnis), Vorverarbeitung (Graustufen, Kontrast, Deskew). PDFs: `pdftotext`; wenn kein Text-Layer → `ocrmypdf` (erzeugt zusätzlich durchsuchbares PDF). Ergebnis: `ocr_text` + Sprache.
3. **`ExtractFields`** – Heuristiken (siehe unten) → `document_fields` mit Konfidenz; bester Wert pro Feld wird in `documents` übernommen, sofern kein `user`-Wert existiert.
4. **`ApplyRules`** – Regeln nach Priorität; Händler-Standardkategorie; Hashtags aus Notiz (`#reise`) und Schlüsselwort-Listen.
5. **`DetectDuplicates`** – Hash-Gleichheit, dann Fuzzy (Betrag gleich, Datum ±1 Tag, Händler-Ähnlichkeit ≥ 0,8).
6. **`IndexDocument`** – Volltextindex aktualisieren.
7. **`MatchDocument`** – gegen offene Buchungen abgleichen (Kapitel 9).
8. (optional) **`AiEnrich`**, **`TranscribeAudio`**.

Danach Status `processed`; Event `DocumentProcessed`.

### 8.1 Extraktions-Heuristiken (deutsch zuerst)

- **Datum:** Muster `TT.MM.JJJJ`, `TT.MM.JJ`, `JJJJ-MM-TT`, `TT. Monat JJJJ`; Kandidaten nahe „Datum“, „Belegdatum“, „Rechnungsdatum“ bevorzugen; Plausibilität (nicht in der Zukunft > 1 Tag, nicht älter als 10 Jahre); Fallback: Upload-Datum mit niedriger Konfidenz.
- **Betrag:** Zahlen mit Dezimalkomma; Schlüsselwörter „Summe“, „Gesamt“, „Total“, „Zu zahlen“, „Betrag“, „EUR“, „Brutto“; größter Betrag in der Nähe dieser Wörter; „Gegeben“/„Rückgeld“ ausschließen; Konsistenz mit MwSt.-Zeilen prüfen (Bonus).
- **Währung:** €/EUR Standard der Org; andere ISO-Codes/Symbole erkennen.
- **Händler:** erste nicht-generische Zeilen des Bons, USt-IdNr./Steuernummer-Nähe, bekannte Händler aus `merchants` (Fuzzy-Match auf `normalized_name`).
- **Testdaten:** Ein Fixture-Set aus ≥ 30 anonymisierten echten Belegen (Supermarkt, Tankstelle, Restaurant, Onlinerechnung-PDF, Handwerkerrechnung) mit Soll-Werten; Erkennungsquote wird in CI gemessen (E3).

### 8.2 Regel-Engine

- Regeltypen: Händler ist X · Text enthält X · Regex · Betrag zwischen A und B · Zahlungsart ist X.
- Aktionen: Kategorie setzen, Tags hinzufügen, Zahlungsart setzen.
- Lernen: Bei manueller Kategorie-Änderung Angebot „Belege von *Händler* immer als *Kategorie*?“ → Regel bzw. `merchants.default_category_id`.

### 8.3 KI-Schnittstelle (vorbereitet, standardmäßig aus)

```php
interface AiProvider {
    /** @return FieldSuggestion[] */
    public function suggestFields(string $ocrText, array $context): array;
    public function transcribe(string $audioPath, string $language): ?string;
}
```

Implementierungen: `NullAiProvider` (Standard), später z. B. lokales Modell oder API-Anbieter. Vorschläge landen in `document_fields` mit `source = ai` und überschreiben nie `user`-Werte.

---

## 9. Kontoauszug-Import & Abgleich

### 9.1 Import

1. Datei hochladen (CSV/XLSX, max. 10 MB).
2. Erkennung: Encoding (BOM, Heuristik), Trennzeichen, Kopfzeile; Vorschau der ersten 20 Zeilen.
3. Spaltenzuordnung: Buchungsdatum, Valuta, Betrag (oder Soll/Haben getrennt), Währung, Gegenpartei, Verwendungszweck; Datumsformat; Dezimaltrenner; Vorzeichenkonvention.
4. Profil speichern (pro Bankkonto). Vorlagen für verbreitete deutsche Banken (Sparkasse, Volksbank, DKB, ING, Commerzbank, comdirect, N26, PayPal-Export) – **Vorlagen anhand echter Beispieldateien verifizieren, nicht raten.**
5. Fingerprint pro Buchung = Hash(Konto, Buchungsdatum, Betrag, normalisierter Zweck, Gegenpartei, laufende Nummer bei identischen Zeilen am selben Tag) → keine Doppelimporte.
6. Ergebnisprotokoll: neu / bereits vorhanden / fehlerhaft (mit Zeilennummer).

### 9.2 Abgleich-Score (0–100)

| Kriterium | Punkte |
|-----------|--------|
| Betrag exakt gleich (Vorzeichen beachtet) | 50 |
| Betrag ±1 % (Trinkgeld, Fremdwährung) | 25 |
| Datum gleich | 20; ±1–3 Tage: 15; ±4–7: 8; ±8–14: 3 |
| Händler-Ähnlichkeit (Gegenpartei/Zweck ↔ Händler, Trigram/Jaro-Winkler) | bis 25 |
| Zahlungsart passt zum Konto (z. B. Firmenkarte ↔ Firmenkonto) | 5 |

- Score ≥ 85 und eindeutig (zweitbester ≥ 15 Punkte darunter) → `auto`.
- 50–84 → `suggested`.
- Konkurrenz um denselben Beleg → beste Paarung gewinnt (greedy nach Score), Rest bleibt Vorschlag.
- Jede Entscheidung speichert `reasons_json` (welche Kriterien wie viele Punkte), damit die UI „Warum?“ zeigen kann.
- Abgleich läuft nach jedem Import und nach jeder Belegverarbeitung inkrementell.
- Schwellen konfigurierbar; Kalibrierung mit Testdaten in Phase 5.

---

## 10. Berichte & Export

- **Bericht „Belegübersicht“** (Zeitraum, Konten): Tabelle Buchungsdatum · Gegenpartei · Zweck · Betrag · Beleg (Link/Nr.) · Kategorie · Status; Kopf mit Summen, Anzahl fehlender Belege; Fußzeile mit Erstellzeitpunkt und Hinweis „kein steuerliches Dokument“.
- **Bericht „Belege ohne Buchung“** (z. B. Barzahlungen).
- **Formate:** PDF (Dompdf, A4, Belegnummern als Verweise ins ZIP), CSV (UTF-8 mit BOM, `;`-getrennt für Excel-DE), XLSX, ZIP (Dateien + `index.csv` + PDF-Bericht).
- **Belegnummer:** fortlaufend pro Org und Jahr (`2026-0001`), vergeben beim Status `processed`; stabil, auch nach Änderungen.
- **Asynchron** ab > 50 Belegen; Benachrichtigung in der App + optional E-Mail.
- **Download-Links:** Token, Ablauf (Standard 7 Tage), optional max. Downloads, im Audit-Log protokolliert.

---

## 11. Sicherheit, Anmeldung, Datenschutz

### 11.1 Anmeldung & Gerätekopplung

1. **Erstes Gerät / Instanz-Setup:** CLI-Befehl erzeugt Owner + Einmal-Link (`php artisan dochamster:setup` o. ä.).
2. **Kopplung:** Einladungslink oder QR-Code (auf bereits angemeldetem Gerät angezeigt, 10 min gültig) → auf dem neuen Gerät **Passkey anlegen** (WebAuthn, bevorzugt) **oder** Passwort + TOTP (Fallback, z. B. für alte Geräte).
3. **Danach:** langlebiges Geräte-Token (256 bit zufällig, nur Hash in DB) als Cookie `__Host-dh_device` (HttpOnly, Secure, SameSite=Lax wegen QR/Link-Einstieg – **Strict prüfen**, D-009), Laufzeit 180 Tage gleitend.
4. **Rotation:** Token wird periodisch (z. B. alle 24 h) erneuert; Wiederverwendung eines alten Tokens derselben Familie → ganze Familie sperren (Diebstahlerkennung).
5. **Sitzung:** kurzlebige Session (z. B. 12 h) wird aus gültigem Geräte-Token stillschweigend erneuert.
6. **Step-up:** Sensible Aktionen (Export, Rollen, Einladungen, endgültig löschen, Geräte sperren) erfordern frische Bestätigung per Passkey/TOTP (≤ 5 min alt).
7. **Geräteliste:** sichtbar, einzeln sperrbar; „Alle anderen Geräte abmelden“.
8. **Steuerberater:** gleicher Mechanismus, aber eigene Rolle; Widerruf der Freigabe/Mitgliedschaft beendet alle Geräte-Tokens dieser Mitgliedschaft.

### 11.2 Bedrohungsmodell (Kurzfassung)

| Bedrohung | Gegenmaßnahme |
|-----------|---------------|
| Gestohlenes Handy | Gerät sperren per anderer Session; Token-Rotation; optional App-PIN (C) |
| Fremde Org-Daten lesen (IDOR) | Org-Scope-Middleware, Policies pro Ressource, ULIDs, Tests für jeden Endpoint |
| Bösartige Uploads (Polyglot, ZIP-Bombe, PDF-JS) | Magic-Bytes-Prüfung, Neukodierung von Bildern, Größen-/Seitenlimit, OCR-Tools im Worker mit Ressourcenlimits (timeout, ulimit), nie aus Webroot ausliefern, `Content-Disposition` + `X-Content-Type-Options: nosniff` |
| XSS über OCR-Text/Notizen | konsequentes Escaping in Templates, strikte CSP (kein inline-JS) |
| CSRF | Token + SameSite-Cookies |
| Brute Force auf Login/Einladungen | Rate Limiting pro IP und Konto, lange zufällige Tokens |
| Shell-Injection in OCR-Aufrufen | Prozesse nur mit Argument-Arrays (`proc_open`/Symfony Process), keine Shell-Strings |
| Datenabfluss an KI-Anbieter | KI standardmäßig aus, Opt-in pro Org mit klarer Info |
| Verlust der Daten | dokumentierte Backups (DB + Storage), Restore-Test |

### 11.3 Weitere Maßnahmen

- Nur HTTPS, HSTS, sichere Header (CSP, Referrer-Policy, Permissions-Policy mit `camera=(self)`).
- Verschlüsselung at rest: mindestens Volume-Verschlüsselung (Doku); optional App-seitig mit libsodium und Schlüssel aus `.env`/Secret-Datei (D-007). Schlüsselverlust = Datenverlust → Doku!
- Audit-Log append-only: Login, Kopplung, Upload, Ansicht durch Steuerberater, Änderungen, Exporte, Downloads, Rechteänderungen.
- DSGVO: Datenexport, Löschung (Papierkorb → endgültig), konfigurierbare Aufbewahrung; Hinweis, dass gesetzliche Aufbewahrungsfristen (z. B. 6/8/10 Jahre) in der Verantwortung der Nutzer liegen; IP-Adressen nur gehasht.
- Abhängigkeiten: `composer audit` und `npm audit` (falls npm) in CI; Dependabot/Renovate.

---

## 12. Betrieb & Deployment

**Kernfrage D-010:** Wo läuft DocHamster? OCR braucht Kommandozeilen-Tools (Tesseract, ocrmypdf) → auf klassischem Shared Hosting oft **nicht** verfügbar.

| Variante | Eignung | Hinweise |
|----------|---------|----------|
| **A: VPS/Homeserver mit Docker Compose** (empfohlen) | ✅ voll | Images: `app` (PHP-FPM + nginx/Caddy), `worker` (gleiches Image, CLI), optional `mariadb`; Caddy für automatisches HTTPS |
| B: VPS ohne Docker | ✅ | Installationsanleitung Debian/Ubuntu, systemd-Unit für Worker |
| C: Shared Hosting | ⚠️ eingeschränkt | Nur wenn `exec` + Tesseract vorhanden; sonst OCR per externem Provider oder separatem Worker |
| D: NAS (Synology/Unraid) | ✅ via Docker | wie A |

Betriebsthemen (alle in `docs/operations/` zu dokumentieren):
- Installation: `composer install`, `.env`, `migrate`, Setup-Befehl, Worker/Cron.
- Worker: Daemon (Supervisor/systemd/Docker) oder Cron jede Minute (`--stop-when-empty`).
- Backups: DB-Dump + Storage-Ordner; Restore-Anleitung; Hinweis auf Schlüssel.
- Updates: `git pull`/neues Image → `migrate`; Migrationen immer vorwärtskompatibel.
- Monitoring: Health-Endpoint (`/health` – DB, Storage schreibbar, Queue-Länge, letzte Worker-Aktivität).
- Logs: strukturiert, ohne personenbezogene Inhalte (kein OCR-Text in Logs).

---

## 13. Qualität & Tests

- **Sprachen:** Code/Kommentare/Commits Englisch; UI-Texte Deutsch über i18n-Dateien (Englisch als zweite Sprache vorbereitet).
- **Statische Analyse:** PHPStan Level 8, Code-Style-Fixer, ESLint für JS.
- **Tests:**
  - Unit: Extraktion (Fixture-Belege), Matching-Score, CSV-Parser (Encodings, Formate), Regel-Engine, Token-Rotation.
  - Feature/Integration: Rechte-Matrix (jede Rolle × jede Route), Org-Isolation, Upload-Idempotenz, Import ohne Duplikate, Export-Inhalte.
  - E2E (Playwright): Erfassen inkl. Offline-Queue, Kopplung mit virtuellem WebAuthn-Authenticator, Abgleich-Flow, Steuerberater-Sicht.
  - Ziel-Abdeckung ≥ 80 % für `Domain/`.
- **CI (GitHub Actions):** Lint, PHPStan, Tests (SQLite + MariaDB-Matrix), Composer-Audit, Erkennungsquote-Report.
- **Definition of Done (pro Arbeitspaket):** Akzeptanzkriterien erfüllt · Tests grün · keine neuen PHPStan-Fehler · UI-Texte über i18n · Doku/CHANGELOG aktualisiert · Review durch die jeweils andere KI oder PO · Eintrag in `docs/collaboration/LOG.md`.
- **UX-Regeln:** Texte kurz und aus Nutzersicht („Beleg gespeichert“, nicht „HTTP 201“); Barrierefreiheit (Kontraste, Labels, Tastatur), Dark Mode, Touch-Ziele ≥ 48 px.

---

## 14. Umsetzungsplan: Phasen & Arbeitspakete

Jede Phase endet mit einem **lauffähigen Stand**, einem Git-Tag (`v0.N.0`) und einer kurzen manuellen Testliste. Arbeitspakete (AP) haben IDs, damit zwei KIs sie parallel übernehmen können. **Parallelisierbar** = keine gegenseitige Abhängigkeit innerhalb der Phase. Laufender Status der Pakete: [`collaboration/TASKS.md`](collaboration/TASKS.md).

### Phase 0 – Entscheidungen & Projekt-Setup (jetzt)

| AP | Inhalt | Ergebnis |
|----|--------|----------|
| P0-1 | Plan v1 reviewen (beide KIs + PO) | kommentierter Plan, offene Punkte in DECISIONS.md |
| P0-2 | Entscheidungen D-001…D-010 treffen | DECISIONS.md: alle „Must-before-code“ entschieden |
| P0-3 | Repo-Grundlagen: README, LICENSE, AGENTS.md, Workflow, Templates | ✅ angelegt (2026-10-29) |
| P0-4 | Beispiel-Fixtures sammeln: 30 anonymisierte Belege, 3–5 echte Kontoauszug-CSV/XLSX (anonymisiert) | `tests/fixtures/` (durch PO) |
| P0-5 | UI-Skizzen der 6 Hauptscreens (Erfassen, Liste, Detail, Abgleich, Bericht, Einstellungen) | `docs/design/` |

### Phase 1 – Fundament (v0.1)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P1-1 | Projektgerüst, Config, `.env.example`, Docker-Compose, CI-Grundgerüst | – | – |
| P1-2 | Migrationen: users, organizations, memberships, devices, passkeys, invitations, audit_log | P1-1 | mit P1-3 |
| P1-3 | Layout, CSS-Basis (Light/Dark), i18n-Mechanismus, Fehlerseiten | P1-1 | mit P1-2 |
| P1-4 | Setup-CLI, Einladung, Kopplung per Link/QR, Passkey-Registrierung/Login | P1-2 | – |
| P1-5 | Geräte-Token mit Rotation, Geräteliste, Sperren, Step-up | P1-4 | – |
| P1-6 | Org-Scope-Middleware, Rollen/Policies, Rechte-Matrix-Testgerüst | P1-2 | mit P1-4 |
| P1-7 | Security-Header, CSRF, Rate Limiting, Health-Endpoint | P1-1 | mit P1-2…6 |
| P1-8 | README-Installation, HTTPS-Doku (Caddy), Backup-Doku v1 | P1-1 | ja |

**Testliste v0.1:** Instanz aufsetzen · Owner koppeln per Passkey · zweites Gerät per QR koppeln · Gerät sperren → ausgesperrt · Mitglied einladen · Mitglied sieht andere Org nicht.

### Phase 2 – Erfassen (v0.2)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P2-1 | Storage-Service (außerhalb Webroot, Pfadschema, optional Verschlüsselung), `document_files` | P1 | mit P2-2 |
| P2-2 | PWA: Manifest, Service Worker, Installierbarkeit, Kamera-Screen, Mehrseiten-Bündelung | P1-3 | mit P2-1 |
| P2-3 | Upload-API, idempotent (`client_upload_id`), Validierung, Limits | P2-1 | – |
| P2-4 | Offline-Queue (IndexedDB), Background Sync, Fortsetzen, Badge | P2-2, P2-3 | – |
| P2-5 | Zusatz-Schritt: Notiz, Kategorie-/Zahlungsart-Chips; Kategorien/Zahlungsarten-Stammdaten | P2-3 | ja |
| P2-6 | Geschützte Datei-Auslieferung (`/files/{id}`), Thumbnails (synchron einfach, später Job) | P2-1 | ja |
| P2-7 | Sprachnotiz (S) | P2-3 | ja |

**Testliste v0.2:** Foto in ≤ 2 Taps · Flugmodus → 3 Belege → online → alle da, keine Duplikate · PDF aus Dateien · Mehrseitig · fremde Datei-ID → 403/404.

### Phase 3 – Verarbeitung (v0.3)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P3-1 | Job-Queue + Worker (Daemon & Cron-Modus), Retry, Fehlerstatus, Jobs-Übersicht für Owner | P2 | – |
| P3-2 | NormalizeFiles (HEIC, Re-Encode, EXIF, Thumbnails) | P3-1 | mit P3-3 |
| P3-3 | RunOcr (Tesseract, pdftotext, ocrmypdf) mit Ressourcenlimits | P3-1 | mit P3-2 |
| P3-4 | ExtractFields + `document_fields` + Fixture-Benchmark in CI | P3-3 | – |
| P3-5 | Regel-Engine, Händler-Stammdaten, Hashtags | P3-4 | mit P3-6 |
| P3-6 | Volltextindex (FTS5 / FULLTEXT) | P3-3 | mit P3-5 |
| P3-7 | Duplikaterkennung | P3-4 | ja |

**Testliste v0.3:** Supermarktbon → Datum/Betrag/Händler korrekt · Rechnungs-PDF → Text da · kaputtes Bild → `failed` + Retry · Benchmark ≥ 80 %.

### Phase 4 – Wiederfinden (v0.4)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P4-1 | Liste (Kacheln/Tabelle), Paginierung | P3 | mit P4-2 |
| P4-2 | Suche + Filter | P3-6 | mit P4-1 |
| P4-3 | Detailansicht mit Viewer, Bearbeiten, Verlauf | P4-1 | – |
| P4-4 | Prüfen-Modus, „Immer so zuordnen?“-Lernen | P4-3, P3-5 | – |
| P4-5 | Papierkorb, Wiederherstellen, endgültig löschen (Step-up) | P4-3 | ja |

### Phase 5 – Konto-Import & Abgleich (v0.5)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P5-1 | Bankkonten, Import-Profile, Parser CSV (Encoding/Trenner/Formate) | P1 | **parallel zu Phase 3/4 möglich** |
| P5-2 | XLSX-Parser, Bank-Vorlagen anhand Fixtures | P5-1 | – |
| P5-3 | Import-Assistent (Upload, Vorschau, Zuordnung, Protokoll), Fingerprint | P5-1 | – |
| P5-4 | Matching-Engine mit Score + Gründen, Kalibrierung | P5-3, P3-4 | – |
| P5-5 | Abgleich-UI (3 Ansichten, manuell zuordnen, „kein Beleg nötig“) | P5-4 | – |

### Phase 6 – Berichte & Export (v0.6)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P6-1 | Belegnummern, Bericht-Abfragen | P5 | – |
| P6-2 | PDF-Bericht | P6-1 | mit P6-3 |
| P6-3 | CSV/XLSX-Export, ZIP-Export (asynchron), Download-Links | P6-1 | mit P6-2 |
| P6-4 | SMTP-Versand, Benachrichtigungen | P6-3 | ja |

### Phase 7 – Steuerberater-Zugang (v0.7)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P7-1 | Freigaben (`shares`) mit Umfang + Ablauf, Einladung | P1-6, P6 | – |
| P7-2 | Read-only-Oberfläche (Liste, Detail, Berichte, Download), alle Schreibwege gesperrt | P7-1 | – |
| P7-3 | Widerruf sofort wirksam, Audit-Ansicht für Owner | P7-1 | ja |
| P7-4 | Security-Review der gesamten Rechte-Matrix (zweite KI als Reviewer) | P7-2 | – |

### Phase 8 – KI-Option, Feinschliff, Release (v1.0)

| AP | Inhalt |
|----|--------|
| P8-1 | AiProvider-Schnittstelle + eine Referenz-Implementierung (opt-in) |
| P8-2 | Transkription (C) |
| P8-3 | Performance-Test 10.000 Belege, Optimierungen |
| P8-4 | Barrierefreiheit-/UX-Review, Texte |
| P8-5 | Doku komplett (Nutzer, Betrieb, Entwicklung), CHANGELOG, Release v1.0 |

### Grobe Reihenfolge & Parallelität

```
P0 ─▶ P1 ─▶ P2 ─▶ P3 ─▶ P4 ─┐
              └──────▶ P5 ──┴▶ P6 ─▶ P7 ─▶ P8
```
Phase 5 (Banking) hängt fachlich kaum an OCR und eignet sich gut als **paralleler Strang für die zweite KI**, sobald Phase 1 steht.

---

## 15. Risiken

| Risiko | Wahrscheinl. | Auswirkung | Gegenmaßnahme |
|--------|:-:|:-:|---------------|
| OCR-Qualität bei zerknitterten Thermobons schlecht | hoch | mittel | Vorverarbeitung, Konfidenz anzeigen, manuelle Korrektur schnell machen; KI optional |
| Hosting ohne Tesseract | mittel | hoch | Docker als Standardweg; externer OCR-Provider als Option |
| iOS-PWA-Einschränkungen (Background Sync fehlt, Speicher-Eviction) | hoch | mittel | Fallback: Queue beim Öffnen senden; deutlicher Hinweis bei ausstehenden Uploads |
| Bank-CSV-Formate ändern sich / sind uneinheitlich | hoch | mittel | Profile frei konfigurierbar, Vorlagen nur als Startpunkt |
| Passkey-UX verwirrt Nutzer | mittel | mittel | Passwort+TOTP-Fallback, klare Texte, Testpersonen |
| Zwei KIs erzeugen widersprüchlichen Code/Stil | mittel | mittel | AGENTS.md, Modul-Schnitt, Task-Locks, Reviews, CI-Linter |
| Scope Creep Richtung Buchhaltung | mittel | hoch | Nicht-Ziele, Entscheidungen nur über DECISIONS.md |
| Verlust des Verschlüsselungsschlüssels | niedrig | sehr hoch | Doku, Setup-Assistent zeigt Backup-Hinweis |

---

## 16. Glossar

| Begriff | Bedeutung |
|---------|-----------|
| Beleg / Dokument | Jede hochgeladene Datei(-sammlung): Kassenbon, Rechnung, Vertrag … |
| Buchung / Transaktion | Zeile aus einem Kontoauszug |
| Abgleich / Match | Verknüpfung Buchung ↔ Beleg |
| Kopplung | Einmaliges Anmelden eines Geräts, danach Geräte-Token |
| Step-up | Erneute Bestätigung vor sensibler Aktion |
| Freigabe / Share | Umfang, den ein Steuerberater sehen darf |
| Org | Organisation: getrennter Datenraum (Haushalt, Firma, Verein) |
| AP | Arbeitspaket mit ID (z. B. P3-4) |
