# Belegbox – Projektbeschreibung & Plan

Arbeitstitel: **Belegbox**. Open Source auf GitHub unter der **MIT-Lizenz**.

## 1. Ziel

Eine schlanke Web-App zum **Sammeln von Belegen und Dokumenten** (privat und geschäftlich). Foto machen, senden, fertig. Der Server übernimmt Texterkennung, Verschlagwortung und Ablage. Später lassen sich alle Dokumente leicht wiederfinden und z. B. an den Steuerberater weitergeben.

**Nicht-Ziele:** keine Steuer-App, keine Steuerberechnung, keine Steuervorbereitung, keine Buchhaltung im Sinne von Kontenrahmen/Buchungssätzen. Nur Dokumente sammeln, finden, zuordnen, berichten.

## 2. Kernprinzipien

- **Geschwindigkeit vor Vollständigkeit:** Upload ohne Pflichtfelder, ohne Rückfragen.
- **Mobile first:** Oberfläche für Smartphone-Kamera optimiert (PWA, installierbar).
- **Kein Passwort bei jedem Öffnen:** Gerät wird einmalig gekoppelt, danach dauerhaft angemeldet – trotzdem sicher.
- **Server macht die Arbeit:** OCR, Tags, Kategorisierung laufen im Hintergrund.
- **KI-fähig, nicht KI-abhängig:** Datenmodell und Schnittstelle erlauben später eine KI-Kategorisierung (austauschbarer Provider), Grundfunktion läuft auch ohne.

## 3. Technik

- **Backend:** PHP 8.2+, schlankes Framework (Vorschlag: Slim oder Laravel; Entscheidung siehe Fragen), Composer.
- **Datenbank:** SQLite (Standard, einfach zu hosten) oder MySQL/MariaDB über PDO; Migrationen.
- **Frontend:** schlichtes HTML/CSS/JS als PWA (Service Worker, Manifest), keine schweren Frameworks. Kamera über `<input type="file" accept="image/*" capture="environment">`.
- **OCR:** Tesseract (Sprachen deu+eng) per Hintergrundjob; PDFs über `pdftotext`/`ocrmypdf`.
- **Hintergrundjobs:** einfache Job-Queue in der DB + Cron-Worker.
- **Speicher:** Dateien außerhalb des Webroots, Originale unverändert, zusätzlich Thumbnail und optimierte Version.
- **Audio/Sprache (optional):** Aufnahme im Browser (MediaRecorder), Speicherung als Audiodatei; optionale Transkription per austauschbarem Provider (z. B. lokales Whisper oder API).

## 4. Funktionen

### 4.1 Erfassen (Hauptscreen)
1. App öffnen → Kamera-Button groß im Zentrum.
2. Foto (auch mehrere Seiten zu einem Dokument bündeln) → **Senden**.
3. Optional direkt danach (überspringbar, nach dem Upload erreichbar): 
   - Notiz als Text, 
   - Sprachnotiz (Audio), 
   - Kategorie-Schnellauswahl (Chips), 
   - Zahlungsart-Chips (z. B. Firmenkarte / privat / bar).
4. Upload funktioniert **offline-tolerant**: Warteschlange im Browser, automatischer Versand bei Verbindung.
5. Bestätigung („Gespeichert“), App kann sofort geschlossen werden.

Auch Upload von PDF/Bilddateien aus der Galerie.

### 4.2 Verarbeitung auf dem Server
- OCR des gesamten Textes → Volltext in der DB (Volltextsuche).
- Automatische Extraktion (best effort, nie Pflicht): Datum, Gesamtbetrag, Währung, Händler/Aussteller.
- **Schlagworte & Hashtags** automatisch aus OCR-Text und Notizen (Regeln/Keywords; optional KI).
- **Kategorisierung** per Regeln (Händler → Kategorie, lernt aus manuellen Korrekturen); optional KI-Vorschlag.
- Duplikaterkennung (Datei-Hash, ähnlicher Betrag+Datum+Händler).
- Status je Dokument: `neu → verarbeitet → geprüft`.

### 4.3 Wiederfinden
- Volltextsuche über OCR-Text, Notizen, Tags.
- Filter: Zeitraum, Kategorie, Tag, Betrag, Zahlungsart, Status, „unverknüpft“.
- Dokumentdetail: Bild/PDF, erkannter Text, Tags, Metadaten, manuell korrigierbar.

### 4.4 Kontoauszug-Import & Abgleich
- Import von **CSV und Excel (XLSX)**; Spaltenzuordnung einmalig konfigurierbar und speicherbar (pro Bank/Konto).
- Automatischer Abgleich Buchung ↔ Beleg (Betrag, Datum ±Toleranz, Händlername).
- Übersicht: Buchungen **ohne Beleg**, Belege **ohne Buchung**, zugeordnete Paare; manuelle Zuordnung per Klick.

### 4.5 Berichte / Export
- Bericht für wählbaren Zeitraum/Konto: Liste aller Buchungen mit verknüpften Belegen, fehlende Belege markiert.
- Export: **PDF-Bericht**, **CSV/XLSX**, **ZIP mit allen Belegbildern** (sortiert nach Monat/Kategorie).
- Versand per E-Mail (SMTP) oder Download-Link mit Ablaufdatum.

### 4.6 Benutzer & Rollen
- **Owner/Admin:** voller Zugriff.
- **Mitglied (optional, Gemeinschaftsprojekt/Team):** kann hochladen und eigene Dokumente sehen.
- **Steuerberater (Read-only):** Einsicht in freigegebene Dokumente, Suche, Berichte, Download. **Kein Upload, keine Löschung, keine Änderung.** Zugang per Einladungslink, jederzeit widerrufbar, optional zeitlich begrenzt.
- Mandantenfähigkeit: Daten sind pro Konto/Organisation getrennt.

## 5. Sicherheit & Anmeldung

- Ausschließlich **HTTPS** (HSTS).
- **Gerätekopplung:** Erstanmeldung per Einladungslink/QR-Code + Passkey (WebAuthn) oder Passwort+TOTP. Danach langlebiges, widerrufbares Geräte-Token (HttpOnly, Secure, SameSite=Strict Cookie), Rotation bei Nutzung. Kein erneutes Passwort im Alltag.
- Geräteliste in den Einstellungen, einzelne Geräte sperrbar.
- Sensible Aktionen (Export, Rollen ändern, Steuerberater einladen) verlangen erneute Bestätigung.
- CSRF-Schutz, Rate Limiting, Prepared Statements, strikte Upload-Validierung (MIME, Größe, Bildneuberechnung), keine direkte Dateiauslieferung ohne Rechteprüfung.
- Dateien verschlüsselt ablegen (at rest) – mindestens Dateisystem/Volume-Verschlüsselung, optional App-seitig (libsodium).
- Audit-Log (wer hat wann was gesehen/geändert/exportiert).
- DSGVO: Löschfunktion, Datenexport, Aufbewahrung konfigurierbar (Hinweis: gesetzliche Aufbewahrungsfristen liegen in Verantwortung der Nutzer).

## 6. Datenmodell (Kern)

- `users`, `organizations`, `memberships(role)`, `devices(token_hash, last_used, revoked)`
- `documents(id, org_id, uploaded_by, file_path, mime, hash, status, doc_date, amount, currency, merchant, category_id, payment_method, note, ocr_text, created_at)`
- `document_pages` (mehrseitig), `attachments_audio`
- `tags`, `document_tags`, `categories`, `rules(match → category/tags)`
- `bank_accounts`, `import_profiles`, `transactions(booking_date, amount, text, account_id)`, `matches(transaction_id, document_id, confidence, confirmed)`
- `reports`, `shares(accountant invites)`, `jobs`, `audit_log`

## 7. Umsetzungsplan

**Phase 1 – Fundament:** Projektgerüst, DB-Migrationen, Gerätekopplung/Login, Rollen, HTTPS-Setup-Doku, MIT-Lizenz, README.
**Phase 2 – Erfassen:** PWA mit Kamera-Upload, Offline-Queue, Speicherung, Thumbnails, optionale Notiz/Sprachnotiz.
**Phase 3 – Verarbeitung:** Job-Queue, OCR, Tag-/Kategorie-Regeln, Datums-/Betrags-/Händler-Extraktion, Volltextsuche.
**Phase 4 – Wiederfinden:** Suche/Filter, Detailansicht, manuelle Korrektur.
**Phase 5 – Konto-Import:** CSV/XLSX-Import, Profile, automatischer Abgleich, Übersicht „fehlende Belege“.
**Phase 6 – Berichte:** PDF/CSV/XLSX/ZIP-Export, E-Mail-Versand.
**Phase 7 – Steuerberater-Zugang:** Read-only-Rolle, Einladung, Widerruf, Audit.
**Phase 8 – KI-Option & Feinschliff:** KI-Provider-Schnittstelle (optional), Duplikate, Tests, Doku, Release.

Jede Phase endet mit lauffähigem Stand und kurzer Testliste.

## 8. Qualität

- Code, Variablen, Kommentare, Commit-Messages: **Englisch**. Oberfläche: **Deutsch**, Mehrsprachigkeit vorbereitet (i18n-Dateien).
- Automatisierte Tests (PHPUnit) für Rechte, Upload, Import-Abgleich; manuelle Testliste je Phase.
- Texte in der Oberfläche aus Nutzersicht, kurz, nicht technisch.
- Einfache Installation (Composer + `.env` + Migrations-Befehl), Docker-Compose optional.

## 9. Offene Entscheidungen

Siehe Fragen F1–F4 im Chat.
