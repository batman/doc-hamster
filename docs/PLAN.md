# docHamster – Detaillierter Projektplan

> **Status (2026-11-14):** Der Kern läuft und wird täglich auf `dev` weitergebaut (D-035). Dieser Plan wurde am 2026-11-14 mit dem Code abgeglichen (Punkt 209): Häkchen = gebaut und im Code geprüft; „(geändert)“ = bewusst anders gebaut als ursprünglich geplant; ~~durchgestrichen~~ = entfällt (mit Grund); „(prüfen)“ = unklar, ob es schon ganz erfüllt ist. Neue Entscheidungen: D-026 bis D-036.
> **Frühere Stände:** Phase 1 (Fundament) lief ab 2026-10-29; v2 des Plans mit Teil 2 abgeschlossen (2026-10-30).
> **v2, Teil 2 (P0-1c):** die übrigen vom Lead freigegebenen Punkte aus PLAN-REVIEW – B3 (Job-Queue als P2-0 nach Phase 2), B5 (Kamera-Methode, technischer Teil; Anzahl Taps → Q-18), B7–B9 (Revolut: Zeitpunkt, Datensparsamkeit, nur Lesezugriff), B12 (PDF-Benchmark), B14 (Kopplung in der installierten App), B15 + A1 (Offline-Queue auf iOS), B17 (keine räumlichen SQL-Abfragen), B22 (Backup, D-023) – sowie Folgen aus D-008 (§7 ohne `/o/{org}`), D-010 (Bildverarbeitung GD + Zusatzfunktionen) und D-017 (ULID). B10, B13, B16 und A6 brauchten keine Änderung. Die PHP-Mindestversion (§3, §5.2, §12) bleibt hier unverändert, bis der Lead D-002 nach der Umstellung auf PHP 8.3 neu festhält.
> **v2, Teil 1 (P0-1c):** eingearbeitet, was bereits freigegeben ist – PLAN-REVIEW B11 (MariaDB/MySQL), B18 (Anmeldung nach Rolle), B19 (Händlerkategorie/MCC, Status), B21 + B1 (ohne Docker, Mindestanforderungen, feste Domain) sowie die entschiedenen D-001, D-002, D-004, D-009, D-010, D-012, D-017 (B4, B6, B20) und D-020 (B2). Offen für Teil 2: Punkte, die noch auf eine Entscheidung warten (u. a. B3, B5, B7–B9, B12–B16).
> **v1.1:** PO-Entscheidungen eingearbeitet – Handy-Web-App statt Scanner (D-015), Aufnahmezeit + Standort (D-017), CSV-Import für jede Bank im Kern + Revolut Business als nachinstallierbares Modul mit einfacher Einrichtung (D-016, D-021); Lizenzregel für Fremdsoftware vorgeschlagen (D-022), Belegpaket für den Steuerberater (D-018), alles lokal ohne Cloud-KI inkl. lokaler Spracherkennung (D-019). Phasen neu geordnet um den Kernablauf (§14).
> **Vorgänger:** [`archive/plan-v0-belegbox.md`](archive/plan-v0-belegbox.md) (Arbeitstitel „Belegbox“). Alles aus v0 ist hier enthalten und ausgearbeitet.
> **Wer bearbeitet diesen Plan?** PO (Product Owner) plus zwei KI-Agenten. Regeln für Änderungen: [`collaboration/WORKFLOW.md`](collaboration/WORKFLOW.md). Offene und getroffene Entscheidungen: [`collaboration/DECISIONS.md`](collaboration/DECISIONS.md).

Lizenz: **MIT**. Code, Kommentare und Commits: **Englisch**. Oberfläche: **Deutsch** (i18n vorbereitet).

---

## Stand 2026-11-14 – was seit dem Plan dazugekommen oder anders ist

**Grundsätzliches**
- **Eine Organisation pro Installation** – kein Org-Wechsler, keine mehreren Organisationen (D-027).
- **Rechnungen schreiben gehört nicht zum Kern** (A365); höchstens später ein eigenes Modul (D-026).
- **Bankverbindungen nur als eigene Module** (D-021); im Kern gibt es Datei-Import für jede Bank (CSV, Excel, CAMT.053, Revolut-Dateien). Das Revolut-Modul ist noch nicht gebaut.
- **Keine Cloud-KI** (D-019): Texterkennung läuft auf dem Server (Tesseract) oder im Browser (tesseract.js), Übersetzung im Browser (Firefox Translations), alles lokal.
- **Anmeldung** heute für alle mit Benutzername + Passwort, „Angemeldet bleiben“ für Owner/Mitglieder; Passkeys und Gerätekopplung kommen später (D-036).
- **Schreibweise „docHamster“** in allem, was Menschen lesen (D-034). PHP-Mindestversion laut `composer.json`: **8.3**.
- **Arbeitsweise:** ein Arbeitszweig `dev`, `main` per Fast-Forward + Tag (D-035).

**Oberfläche (FRAMEWORK.md)**
- **Fenstersystem:** jedes Fenster ist eine Modul-Instanz (Ordner, Dokument, Konto, Buchung, Einstellungen …), mehrere nebeneinander, Breiten nach Inhalt („Platz folgt Aufmerksamkeit“), Minimieren/Maximieren/Schließen, Fenster-Cache und Vorladen.
- Bausteine: Umschalter, Knopfgruppe, Optionskarten, Listeneintrag, Kopierfeld, Ordner-Menü, Klappkarten, Leerer Zustand, „Bitte bestätigen“ (Sudo), Menü-Fenster, rote Zähler bis zum Ziel.
- **Home** ist ein persönliches Dashboard (Widgets, „Auf Home anheften“, Erste Schritte); das **Notizen-Board** (Spalten wie Trello) liegt unter `/notes`.

**Eingang**
- **Posteingang:** alles Neue wartet ungeordnet, Reiter pro Quelle (Upload, Drucker, E-Mail, Handy), „Erledigt“, Ablegen in Ordner.
- Wege hinein: Hochladen/Ziehen, Ordner-Import, Android-Teilen-Ziel (installierbare App), **Geräteschlüssel + Upload-API** für iPhone-Kurzbefehle und Programme, Drucker „Hamster“ für Windows, Text → PDF.

**Dokumente**
- Ordner (Explorer-Baum, Ziehen & Ablegen, Mehrfachauswahl), Ansichten Liste/Details/Kacheln/Zeitachse/Kalender, Tags mit Farben, **Eigenschaften** (typisiert, mit Vorschlägen aus der Texterkennung), GiroCode-Karte, Lesemodus mit Wortkorrekturen, Übersetzung, Text-Dokumente.
- **Belegdatum** = Eigenschaft Rechnungsdatum mit Rückfall auf EXIF-/PDF-Datum/Upload-Tag (D-030).
- **Papierkorb** mit automatischem Leeren und „Rückgängig“ nach dem Leeren (D-033).
- Freigabelink für ein einzelnes Dokument (`/s/{token}`, mit Ablauf).

**Bank**
- Konten von Hand mit Art (Bank/Kasse), IBAN-Zuordnung beim Import, **Bargeldkasse**, Abgleich mit Score (Vorschlag + 1-Klick-Bestätigung), n:m-Verknüpfungen, Verknüpfen per Ziehen mit Rückgängig, **Dauerbeleg**, Gründe für „kein Beleg nötig“, MCC-Tags, Buchungsnotizen, Zeitraum-Schnellwahl und Kalender.

**Steuerberater, Belegpaket, Sicherung**
- **Steuerberater-Zugang** mit Einmal-Passwort, Umfang (Zeitraum, Konten, Ablauf), „Zuletzt angesehen“, optional „Passwort vergessen?“ per Mail (D-031).
- **Belegpaket-PDF** (vorläufiges Layout, eigener PDF-Schreiber, eingefroren und versioniert, D-032).
- **Backup herunterladen** für Owner/Admins + `php artisan dochamster:restore` (D-028).
- **Bitte bestätigen:** frische Bestätigung (5 min) vor sensiblen Aktionen (D-029).

**Einstellungen & Betrieb**
- Personen (Mitglieder, eigene Rollen mit Rechte-Häkchen, Steuerberater), Bank (Konten, Gründe, MCC), Tags, Eigenschaften, Farben, Texterkennung, Erweiterungen (OCR-Sprachen, Übersetzungspakete), Eingang (Drucker, E-Mail, Geräte, Handy-Test), Log (Allgemein, Texterkennung, Updates), Export (ZIP, Freigabelinks, Belegpakete), Geschwindigkeit, Update (Versionen, Vorschauen), Backup.
- **Angemeldete Geräte:** eigene Sitzungen sehen, einzeln oder alle anderen abmelden.
- Web-Installer (`install.php`) und Release-Pakete, Updates aus der App, Vorschau-Pakete von `dev`, Docker-Probierumgebung (`TRY.md`).

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

**Ein Satz:** Beleg fotografieren, senden, vergessen – docHamster sammelt, liest, ordnet und findet ihn wieder, und gibt dem Steuerberater am Jahresende ein sauberes Paket.

**Problem:** Belege landen in Hosentaschen, Mail-Postfächern und Fotogalerien. Am Monats- oder Jahresende fehlt die Hälfte, und die Zuordnung zu Kontobuchungen ist mühsame Handarbeit.

**Lösung:** Eine installierbare Web-App (PWA), die im Alltag genau eine Sache schnell macht (Erfassen mit der Handykamera, inkl. Aufnahmezeit und Standort), und auf dem Server alles Weitere erledigt (Abgleich mit Revolut-Buchungen, OCR, Tags, Kategorien, Berichte).

> **Stand 2026-11-14:** Erfasst wird heute über Teilen/Kurzbefehl vom Handy, Hochladen, Drucker und Ordner-Import in den Posteingang; Aufnahmezeit kommt aus EXIF, Standort wird noch nicht erfasst. Statt Kategorien gibt es Ordner, Tags und Eigenschaften.

**Kernablauf (der wichtigste Weg durch die App):**

```
Bezahlen (Karte/Konto) ─▶ Beleg mit Handy fotografieren ─▶ App speichert Foto + Zeit + Ort
        │                                                        │
        ▼                                                        ▼
Bank-Buchung (CSV jeder Bank; Revolut zusätzlich live) ── Abgleich ──▶ Paar Buchung ↔ Foto
                                                                 │
                                                                 ▼
                                    Monat/Quartal ─▶ „Belegpaket“ (PDF) ─▶ Steuerberater
```

Alles andere (Suche, Regeln, Teams) ist nachrangig gegenüber diesem Ablauf.

**Erfolgskriterien (messbar):**

| # | Kriterium | Ziel |
|---|-----------|------|
| E1 | Zeit von „App öffnen“ bis „Gespeichert“ (1 Foto, gutes Netz) | ≤ 5 s, ≤ 2 Taps |
| E2 | Upload ohne Netz geht nicht verloren | 100 % (Offline-Queue) |
| E3 | Erkennung Datum + Betrag auf typischen deutschen Kassenbons | ≥ 80 % korrekt ohne KI |
| E4 | Automatischer Abgleich Revolut-Buchung ↔ Foto (bei vorhandenem Foto) | ≥ 85 % automatisch, Rest per 1 Klick |
| E5 | Installation auf frischem Server laut README | ≤ 30 min |
| E6 | Belegpaket für einen Monat erstellen und versenden | ≤ 3 Klicks, ≤ 1 min |

**Nicht-Ziele (bewusst ausgeschlossen):**

- Keine Steuer-App: keine Steuerberechnung, keine Steuererklärung, keine USt-Voranmeldung.
- Keine Buchhaltung: kein Kontenrahmen (SKR03/04), keine Buchungssätze, keine GoBD-Zertifizierung.
- **Keine Rechnungen schreiben** im Kern (A365, D-026) – höchstens später als eigenes Modul.
- **Eine Organisation pro Installation** (D-027): wer mehrere Datenräume braucht, installiert mehrmals.
- Kein allgemeines Banking: kein HBCI/FinTS, keine Zahlungen, nur **Lesen** von Buchungen. **Jede Bank per CSV/Excel-Import** (öffentlicher Kern). Einzige Live-Anbindung in v1: **Revolut Business** als nachinstallierbares Modul in einem eigenen Repo (D-016, D-021).
- Keine native App (iOS/Android); die PWA muss reichen.
- Kein Hardware-Scanner als Eingangskanal (D-015, PO-Entscheidung). PDF-Upload aus Dateien bleibt möglich.
- **Keine Cloud-KI, keine externen KI-Dienste** (D-019, PO-Entscheidung, ersetzt D-013): Belegdaten, Fotos, Sprachnotizen und Standorte verlassen nie den eigenen Server Richtung KI-/Cloud-Anbieter. Funktionen wie Spracherkennung laufen **lokal auf dem eigenen Server**.

---

## 2. Nutzer & Rollen (Personas)

| Persona | Beschreibung | Wichtigste Aufgabe |
|---------|--------------|--------------------|
| **Owner** | Selbstständige/r oder Haushalt, betreibt die Instanz bzw. die Organisation | Erfassen, Prüfen, Konto-Import, Berichte, Rechte vergeben |
| **Mitglied** | Partner/in, Mitarbeitende, Vereinsmitglied | Belege hochladen, eigene Belege sehen |
| **Steuerberater** | Externe Person, nur lesend | Freigegebene Belege und Berichte ansehen und herunterladen |
| **Instanz-Admin** | Technische/r Betreiber/in (oft = Owner) | Installation, Updates, Backups |

> **Stand 2026-11-14 (geändert):** Rollen legt der Owner selbst an, mit Rechte-Häkchen (Q-21). Die Rolle `owner` heißt in der Oberfläche **„Admin“** und darf immer alles; Vorlagen: Mitarbeiter (wie Admin außer Personen, Rollen, Updates) und Steuerberater (nur lesen). Eine Organisation pro Installation (D-027). Die Matrix unten ist der ursprüngliche Plan; „mit Bestätigung“ heißt heute „Bitte bestätigen“ (D-029).

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
| Mitglieder einladen, Steuerberater-Konto anlegen, Zugänge entziehen | ✅ (mit Bestätigung) | ❌ | ❌ |
| Kategorien/Regeln verwalten | ✅ | ❌ | ❌ |
| Audit-Log sehen | ✅ | ❌ | ❌ |

Legende: ✅ erlaubt · ❌ verboten · ⚙️ per Org-Einstellung · 🔶 eingeschränkt durch Freigabe (`shares`).

**Freigabe-Modell Steuerberater:** Eine Freigabe (`share`) gilt für eine Organisation und optional eingeschränkt auf Zeitraum (`from`/`to`), Kategorien oder Bankkonten, optional mit Ablaufdatum. Ohne Freigabe sieht der Steuerberater nichts.

> **Stand 2026-11-14 (geändert):** Gebaut als **Steuerberater-Zugang** (`advisor_accesses`, D-031): Zeitraum (offene Enden erlaubt), Konten inkl. Kassen, optionales Ablaufdatum; keine Kategorien. Ein Steuerberater ohne Zugangs-Zeile sieht alles nur lesend (Q-20).

---

## 3. Kernprinzipien

1. **Geschwindigkeit vor Vollständigkeit.** Upload ohne Pflichtfelder, ohne Rückfragen. Alles Weitere ist optional und nachträglich möglich.
2. **Mobile first.** Daumen-bedienbar, große Ziele (≥ 48 px), Kamera zuerst.
3. **Einmal koppeln, dann in Ruhe lassen.** Kein Passwort im Alltag, trotzdem sicher (Geräte-Token + Passkey). Ausnahme: der Steuerberater meldet sich mit Benutzername + Passwort an, ohne etwas zu installieren (D-012). *Stand 2026-11-14: noch Benutzername + Passwort mit „Angemeldet bleiben“ für alle; Handys und Programme senden mit Geräteschlüssel (D-036).*
4. **Server macht die Arbeit.** OCR, Tags, Kategorien, Abgleich laufen asynchron.
5. **Privat: alles lokal.** Spracherkennung, Texterkennung und Vorschläge laufen auf dem eigenen Server (Texterkennung und Übersetzung auch im eigenen Browser). Keine Daten an Cloud- oder KI-Anbieter. Ausgehende Verbindungen: Bank-Module, E-Mail-Versand, Updates und Erweiterungen aus festen Quellen (feste Adressen + Prüfsumme).
6. **Originale sind heilig.** Hochgeladene Dateien werden nie verändert; abgeleitete Versionen sind separat.
7. **Einfach zu hosten.** Läuft auf jedem Server oder Webhosting mit PHP 8.3+ (Plan: 8.4, `composer.json`: `^8.3`) + MariaDB/MySQL, ohne Docker; Zusatzfunktionen (Texterkennung, Spracherkennung, HEIC) je nach Umgebung; keine externen Pflichtdienste (D-010).
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

**Aufnahme-Metadaten (D-017):** Beim Auslösen merkt sich die App automatisch:
- **Aufnahmezeitpunkt** – Gerätezeit beim Auslösen inkl. Zeitzone (primär); EXIF `DateTimeOriginal` als Abgleich – die App liest es **im Browser vor dem Verkleinern**, weil beim Verkleinern alle EXIF-Daten verloren gehen; Server-Empfangszeit als letzter Fallback.
- **Falsche Geräteuhr** – beim Senden schickt die App zusätzlich die aktuelle Gerätezeit (`client_sent_at`). Der Server berechnet daraus die Abweichung der Geräteuhr (funktioniert auch bei Offline-Uploads), speichert Roh- und korrigierten Zeitpunkt und markiert Abweichungen > 2 min (D-017).
- **Standort** – über die Geolocation-API des Browsers beim Auslösen (Breite, Länge, Genauigkeit). EXIF-GPS ist **nicht** verlässlich (Browser/iOS entfernen es beim Upload oft), daher nur Fallback.
- Standort ist **Opt-in** pro Nutzer (Browser-Erlaubnis + Einstellung), kann jederzeit aus- und pro Beleg gelöscht werden. Ohne Standort funktioniert alles weiter.
- Der Server ermittelt daraus einen Ortsnamen (Stadt/Land) für Anzeige, Abgleich und Belegpaket – **offline** aus dem GeoNames-Datensatz `cities1000` (CC BY 4.0, Quellenangabe in der App), ohne externen Dienst (D-017).

Akzeptanzkriterien:
- [ ] AK-4.1.1: Ein Foto ist mit 2 Taps (Kamera, Senden) gespeichert. Die Kamera-Methode – native Kamera (`<input type="file" capture>`, beste Bildqualität, auf iOS mit Bestätigung „Foto verwenden“) oder Kamera in der Seite (`getUserMedia`, Auslöser + Senden, auf iOS geringere Auflösung) – wird in P2-2 auf einem echten iPhone und Android-Handy verglichen (Taps, Bildqualität für die Texterkennung); die bessere wird Standard, die andere bleibt Fallback (B5). Ob auf dem iPhone 3 Taps reichen, wird nach dem Gerätetest entschieden (Q-18). *(prüfen – Stand: Fotos kommen über Teilen/Kurzbefehl; ein eigener Kurzbefehl „Foto aufnehmen“ hat einen Platz in Einstellungen → Geräte, Punkt 204; Ziel „Symbol, Foto, fertig“.)*
- [ ] AK-4.1.2: Bis zu 20 Seiten können zu einem Dokument gebündelt werden. *(noch nicht gebaut: jede Datei wird ein eigenes Dokument; die Upload-API nimmt bis zu 20 Dateien pro Aufruf)*
- [ ] AK-4.1.3: Ohne Netz wird der Upload in IndexedDB gepuffert und automatisch gesendet, sobald Netz da ist: beim Öffnen der App, beim Ereignis `online` und bei `visibilitychange` – auf iOS ist das der Hauptweg, weil Safari kein Background Sync kennt; Background Sync nur als Zusatz auf Chromium (A1, B15). Die App fordert dauerhaften Speicher an (`navigator.storage.persist()`) und empfiehlt die Installation auf dem Home-Bildschirm (Safari kann Daten nicht installierter Seiten nach 7 Tagen ohne Nutzung löschen). Die Warteschlange ist sichtbar (Badge mit Anzahl). *(Teil gebaut: das Android-Teilen-Ziel hält geteilte Dateien in IndexedDB, bis der Server sie gespeichert hat; eine allgemeine Offline-Warteschlange fehlt)*
- [ ] AK-4.1.4: Ein abgebrochener Upload (App geschlossen) wird beim nächsten Öffnen fortgesetzt. Hochgeladen wird **pro Datei/Seite**; bereits übertragene Seiten werden nicht erneut gesendet (D-017). *(nur beim Teilen-Ziel, siehe AK-4.1.3)*
- [ ] AK-4.1.5: Doppeltes Senden desselben Uploads erzeugt kein Duplikat (Client-seitige `client_upload_id` als ULID, Server idempotent – D-017). *(noch nicht gebaut)*
- [x] AK-4.1.6 (geändert): Erlaubte Formate: JPEG, PNG, HEIC, WebP, PDF – geprüft an den Bytes der Datei, nicht am Namen. Max. 25 MB pro Datei. HEIC wird gespeichert; Vorschau/Texterkennung dafür nur, wo die Umgebung es kann (D-010). Dazu: geteilter Text oder Link wird zu einem kleinen PDF.
- [ ] AK-4.1.7: Bilder werden vor dem Upload clientseitig auf max. 3000 px lange Kante verkleinert (konfigurierbar, Original optional); EXIF-Zeit (und GPS, falls vorhanden) wird vorher ausgelesen und als Aufnahme-Metadaten mitgeschickt. *(noch nicht gebaut: das Original wird hochgeladen; die EXIF-Zeit liest der Server, D-030)*
- [x] AK-4.1.8 (geändert): Teilen-Ziel: In der installierten PWA kann man auf Android aus anderen Apps „Teilen → docHamster“ (Web Share Target). Auf dem iPhone senden **Kurzbefehle** mit einem **Geräteschlüssel** an die Upload-API (`POST /api/upload`, fertiger Kurzbefehl + Anleitung, Verbindungstest; Punkte 151, 152, 201–203).
- [x] AK-4.1.9 (geändert): Jedes Dokument hat ein **Belegdatum** mit Quelle (`confirmed`/`recognised`/`exif`/`pdf`/`upload`): die Eigenschaft Rechnungsdatum gewinnt, sonst das Aufnahmedatum aus EXIF, das PDF-Erstelldatum, zuletzt der Upload-Tag („Belegdatum fehlt“). D-030, Punkt 214.
- [ ] AK-4.1.10: Mit erteilter Erlaubnis wird der Standort beim Auslösen gespeichert (inkl. Genauigkeit); das Warten auf GPS verzögert das Speichern nicht (max. 3 s, sonst ohne Standort bzw. nachgereicht). *(noch nicht gebaut)*
- [ ] AK-4.1.11: Ortsname (Stadt, Land) ist in der Detailansicht sichtbar; Standort pro Beleg löschbar. *(noch nicht gebaut)*
- [ ] AK-4.1.12 (S): Ist die Revolut-Live-Anbindung aktiv, zeigt die App nach dem Speichern „Passt zu: *Händler* *Betrag* vor *n* min?“ zur 1-Tap-Bestätigung. *(wartet auf das Bank-Modul, D-021)*

Neu seit dem Plan (gebaut):
- [x] AK-4.1.13: **Posteingang** – alles Neue ohne Ordner wartet dort, neueste zuerst; Reiter pro Quelle (Alle · Upload · Drucker · E-Mail · Handy) mit roten Zählern; „Erledigt“ pro Dokument und für alle; Ablegen in Ordner aus dem Posteingang (Punkt 153, A358).
- [x] AK-4.1.14: Hochladen per Knopf im Posteingang und per Ziehen auf ein Fenster/einen Ordner (die Datei landet, wo sie fallen gelassen wird); ganze Ordner hochladen (Ordner-Import).
- [x] AK-4.1.15: **Geräteschlüssel** (Einstellungen → Eingang → Geräte): pro Gerät/Programm ein Schlüssel, einmal angezeigt, neu erzeugbar, löschbar; der Schlüssel kann nur hochladen (Punkte 152, 201–203).
- [x] AK-4.1.16: **Drucker „Hamster“** für Windows: gekoppelte Computer drucken direkt in den Posteingang (Einstellungen → Eingang → Drucker).
- [x] AK-4.1.17: **Handy-Test** (Einstellungen → Eingang): prüft Home-Bildschirm, Kamera und gespeicherten Schlüssel auf dem Handy.
- [x] AK-4.1.18: Text-Dokumente: Text oder Link wird ein PDF; eigene Text-Dokumente mit Editor (einfacher und formatierter Text).

**C – später:** E-Mail-Postfach (Online-Rechnungen per Mail weiterleiten). *Stand: Reiter „E-Mail“ im Posteingang und Seite Einstellungen → Eingang → E-Mail sind als Platzhalter da.*

### 4.2 Verarbeitung auf dem Server – M

Details zur Pipeline in [Kapitel 8](#8-verarbeitungs-pipeline-ocr-extraktion-tags).

- [x] AK-4.2.1 (geändert): Nach dem Upload ist das Dokument sofort da (mit Vorschaubild); die Texterkennung läuft im Hintergrund (`ocr_status` `pending` → fertig/fehlgeschlagen) – auf dem Server per Cron-Worker oder im Browser eines angemeldeten Nutzers, wenn der Server sie nicht kann. Das Statusmodell `uploaded → processed → reviewed` ist so nicht gebaut.
- [x] AK-4.2.2: OCR-Volltext ist durchsuchbar.
- [x] AK-4.2.3 (geändert): Der Rechnungsleser schlägt Rechnungsdatum, Fälligkeit, Betrag, Währung, IBAN, Rechnungs-/Kundennummer, Zahlungsempfänger und Skonto vor – als **Eigenschaften** mit Vorschlags-Markierung, die man bestätigt oder ändert; gelernt wird pro Absender. Nie Pflicht.
- ~~AK-4.2.4: Tags und Kategorie werden per Regeln vorgeschlagen; manuelle Korrektur erzeugt (optional) eine neue Regel („Immer so zuordnen?“).~~ – *entfällt: Kategorien und eine Regel-Engine gibt es nicht; ihre Aufgaben übernehmen Ordner, Tags und Eigenschaften mit Vorschlägen, die pro Absender lernen (AK-4.2.3) – Bank-seitig „kein Beleg nötig“-Regeln und Dauerbelege (AK-4.4.7, AK-4.4.13).*
- [ ] AK-4.2.5: Duplikate werden markiert (nicht gelöscht): exakter Datei-Hash oder gleicher Betrag + Datum ± 1 Tag + ähnlicher Händler. *(noch nicht gebaut; der SHA-256 jeder Datei wird gespeichert)*
- [x] AK-4.2.6 (geändert): Fehlgeschlagene Texterkennung wird mit Grund im OCR-Log festgehalten; „Erneut erkennen“ (Server oder Browser) pro Dokument und für alle; der Upload bleibt erhalten.

Neu seit dem Plan (gebaut):
- [x] AK-4.2.7: Texterkennung auf dem Server (Tesseract, auch ohne Root mitgeliefert, PDFs über Xpdf) **und** im Browser (tesseract.js, mehrere Durchgänge); sichtbar und testbar unter Einstellungen → Texterkennung, Anzeige mit Pause unter „Abmelden“.
- [x] AK-4.2.8: Wortpositionen für jeden Erkennungsweg; Lesemodus mit Wort-Pillen, die mit der Vorschau verbunden sind; Wortkorrekturen bleiben über neue Scans erhalten und werden pro Absender gelernt.
- [x] AK-4.2.9: Weitere OCR-Sprachen als **Erweiterung** (fester Katalog mit Prüfsumme, D-020: Download als `net`-Job); erkannt wird mit allen eingeschalteten Sprachen.
- [x] AK-4.2.10: Übersetzen des erkannten Texts **im Browser** (Firefox Translations/Bergamot als Erweiterung, nichts verlässt das Gerät, D-019); Übersetzungen sind durchsuchbar.
- [x] AK-4.2.11: **GiroCode**-Karte für jedes Dokument mit Rechnungsbetrag: QR-Code live aus den Eigenschaften, Skonto, „Als bezahlt markiert“.

Statusmodell Dokument (ursprünglicher Plan; gebaut sind der OCR-Status, Posteingang/„Erledigt“ und Papierkorb → endgültig löschen mit Rückgängig-Frist, D-033):

```
uploaded → processing → processed → reviewed
                 ↘ failed (retry → processing)
(any) → trashed → (nach 30 Tagen oder manuell) purged
```

### 4.3 Wiederfinden – M

- [x] AK-4.3.1 (geändert): Eine Suchleiste im Fenster durchsucht Name und erkannten Text (Standard), auf Wunsch Notizen, Tags und Eigenschaften („Suchen in“-Chips), auch Übersetzungen. Ohne deutsche Wortstämme (Teiltext-Suche).
- [x] AK-4.3.2 (geändert): Erweiterte Suche als Karte unter der Suchleiste: Zeitraum (mit Schnellwahl), Tags (alle/irgendeins), Eigenschaften (z. B. Betrag von/bis), Hochgeladen von, mit/ohne Buchung, gelesen/ungelesen, Dateityp, „Belegdatum fehlt“; alles steht in der Fenster-Adresse. Kategorie, Zahlungsart und „Duplikat“ entfallen (gibt es nicht).
- [x] AK-4.3.3 (geändert): Ansichten Liste, Details (Tabelle mit wählbaren Spalten, auch Eigenschaften), Kacheln, Zeitachse und Kalender; Ordner-Zeilen zählen Unterordner und Dokumente.
- [x] AK-4.3.4: Detailansicht als eigenes Fenster: Vorschau mit Zoom, Vollbild und Seitenwechsel (pdf.js, selbst gehostet), erkannter Text, Eigenschaften, Tags, GiroCode, verknüpfte Buchungen, Verlauf (Audit); Name, Ordner und Tags speichern sofort.
- ~~AK-4.3.5: „Prüfen“-Modus: Durchwischen aller `processed`-Belege, je Beleg bestätigen/korrigieren → `reviewed`.~~ – *entfällt: ersetzt durch den Posteingang mit „Erledigt“ (AK-4.1.13) und die Vorschläge in den Eigenschaften (AK-4.2.3).*
- [ ] AK-4.3.6: Suche liefert bei 10.000 Belegen Ergebnisse in < 1 s. *(prüfen: Geschwindigkeits-Seite misst Serverzeiten; ein Test mit 10.000 Belegen steht aus, P8-1)*

Neu seit dem Plan (gebaut):
- [x] AK-4.3.7: **Ordner** wie im Explorer: verschachtelt, umbenennen am Fenstertitel, Farben, Reihenfolge, Ziehen & Ablegen von Dokumenten und Ordnern (auch zwischen Fenstern), Mehrfachauswahl und „Ablegen in …“ über das Ordner-Menü (Explorer-Baum mit „Zuletzt benutzt“).
- [x] AK-4.3.8: **Tags** mit frei wählbarer Farbe (gemeinsame Farbpalette, Einstellungen → Farben).
- [x] AK-4.3.9: **Eigenschaften** (Einstellungen → Eigenschaften): typisierte Felder pro Organisation (Datum, Betrag, Text, IBAN …), deutsches Einlesen aller üblichen Formate, mehrdeutige Datumsreihenfolge umschaltbar, „Markieren als“ aus dem erkannten Text, Spalten, Suche und Export.
- [x] AK-4.3.10: **Papierkorb** als Ordner: Wiederherstellen, endgültig löschen (mit „Bitte bestätigen“), „Papierkorb leeren“ mit Rückgängig-Frist (7/14/30 Tage, Standard 14) und automatischem Leeren nach 7/30/90/365 Tagen oder nie (Punkt 207, D-033).
- [x] AK-4.3.11: **Freigabelink** für ein einzelnes Dokument ohne Anmeldung (`/s/{token}`, nur Hash gespeichert, Ablauf in Tagen einstellbar, widerrufbar; Einstellungen → Export → Freigabelinks).

### 4.4 Kontoauszug-Import für jede Bank, Revolut-Anbindung & Abgleich – M

Details in [Kapitel 9](#9-kontoauszug-import--abgleich).

**Aufteilung (D-021):**

| Teil | Wo | Für wen |
|------|----|---------|
| CSV/Excel-Import für **jede Bank**, Import-Profile, Abgleich, Belegpaket | Kern (dieses Repo), immer dabei | alle Nutzer |
| `BankConnector`-Schnittstelle für Live-Anbindungen | Kern | Bank-Module |
| Revolut-Business-Modul | **eigenes öffentliches Repo**, bei Bedarf mit einem Befehl nachinstalliert | wer Revolut Business hat, verbindet sein eigenes Konto |

docHamster muss **ohne** Revolut-Modul vollständig funktionieren: fotografieren → CSV einer beliebigen Bank importieren → abgleichen → Belegpaket. Der Kern enthält keinen Revolut-Code. Im Code stehen nirgends Zugangsdaten; jede Installation speichert ihre eigenen Revolut-Schlüssel verschlüsselt auf ihrem Server.

**Revolut live (nachinstallierbares Modul, D-016/D-021).** Ziel: zu jeder Revolut-Kartenzahlung die vollständigen Buchungsdetails – Händler, Betrag, Währung (inkl. Originalwährung und Kurs bei Fremdwährung), genauer Zeitpunkt, Händlerkategorie (MCC, als deutscher Text), Status, Händlerort falls verfügbar – und das passende Foto dazu.

Ziel-Kontotyp: **Revolut Business** (PO, Q-7) → die offizielle Business API ist der erwartete Hauptweg. Technische Umsetzung entscheidet der Lead:
1. **Revolut Business API** – offizielle API mit Transaktionsabruf (nur Business-Konten). Automatischer, regelmäßiger Abruf.
2. **Revolut-Kontoauszug als Datei** (CSV/Excel aus der App/Web) – funktioniert für Privat und Business, manueller Upload z. B. monatlich. **Immer als Fallback vorhanden.**
3. ~~Open-Banking-Dienst für Privatkonten~~ – in v1 nicht nötig (Business-Konto).

> **Stand 2026-11-14:** Das Revolut-Modul ist noch nicht gebaut; die `BankConnector`-Schnittstelle ist offen (Q-11). Erweiterungen mit Code stehen im Katalog als „kommt später“. Revolut-**Dateien** (Business und Privat) liest der Kern-Import schon (AK-4.4.1).

- [ ] AK-4.4.R1: Revolut-Buchungen (Weg 1 oder 2) landen mit Zeitpunkt (Datum + Uhrzeit), Händler, Betrag, Währung, ggf. Originalbetrag/Kurs, Händlerkategorie (MCC – die Revolut-API liefert keine eigene Ausgabenkategorie) und Status in `transactions`. Die MCC wird über eine Tabelle MCC → deutscher Text angezeigt (z. B. „Tankstelle“, „Restaurant“).
- [ ] AK-4.4.R2: Nur abgeschlossene Buchungen werden abgeglichen; ausstehende/zurückgebuchte werden angezeigt, aber nicht ins Belegpaket übernommen (Rückerstattungen als eigene Zeile); abgelehnte/fehlgeschlagene (`declined`/`failed`) werden nie abgeglichen und nie ins Belegpaket übernommen.
- [ ] AK-4.4.R3: Wiederholter Abruf/Import erzeugt keine Duplikate (Revolut-Transaktions-ID bzw. Fingerprint).
- [ ] AK-4.4.R4: Das Modul fordert nur Lesezugriff an (`scope=READ`, B9). API-Zugangsdaten (Refresh-Token, privater Schlüssel) liegen verschlüsselt auf dem Server, nie im Repo; Verbindung in den Einstellungen trennbar.
- [ ] AK-4.4.R5: **Einfache Einrichtung:** Ein Assistent führt Schritt für Schritt durch die Verbindung (Zertifikat erzeugen → in Revolut Business eintragen → Client-ID einfügen → Zustimmung → Verbindung testen → erster Import). Ziel: in ≤ 10 min ohne Doku, verständliche deutsche Texte und Fehlermeldungen.
- [ ] AK-4.4.R6: Das Modul wird mit **einem** Befehl installiert (nicht über die Web-Oberfläche) und lässt sich danach in den Einstellungen ein- und ausschalten; nicht installiert oder ausgeschaltet hat es keinerlei Wirkung (keine Jobs, keine Netzwerkverbindungen).
- [ ] AK-4.4.R7: Kein Revolut-Logo, keine Revolut-Farben, keine kopierten Revolut-Texte; Hinweis „nicht mit Revolut verbunden“ im Modul.

**Jede Bank per Datei-Import (öffentlicher Kern, Must):**

- [x] AK-4.4.1: Import von CSV (Trennzeichen, Encoding und Kopfzeile werden erkannt) und XLSX; dazu **CAMT.053** (XML) und die Revolut-Dateiformate (eine Datei mit mehreren Konten/Währungen wird aufgeteilt).
- [x] AK-4.4.2 (geändert): Spaltenzuordnung per Seite „Spalten“: bekannte Formate ohne Fragen, sonst nach Spaltennamen (Deutsch/Englisch) und Inhalt; unsichere Zuordnungen orange; das Konto merkt sich die Zuordnung nach Spaltennamen. Keine eigenen Import-Profile/Bank-Vorlagen.
- [x] AK-4.4.3: Erneuter Import überlappender Zeiträume erzeugt keine doppelten Buchungen – mit Vorschau „neu / schon da“ vor dem Speichern; importierte Zeiträume werden gemerkt, damit Lücken sichtbar sind.
- [x] AK-4.4.4 (geändert): Automatischer Abgleich mit Score (Betrag, Datum, IBAN, Rechnungsnummer, Absender) nach jedem Import und jeder Texterkennung; über der Schwelle ohne knappen Zweiten wird das Paar als **„vorgeschlagen“** verknüpft und mit einem Klick bestätigt oder abgelehnt (abgelehnt = nie wieder vorgeschlagen).
- [x] AK-4.4.5 (geändert): Konto-Fenster mit Status-Filter (offen, mit Beleg, kein Beleg nötig …), Summen und Zeitraum; Buchung als eigenes Fenster mit verknüpften Dokumenten; „Belegvorschläge“ als eigenes Fenster daneben; „Belege ohne Buchung“; Verknüpfen per Ziehen eines Dokuments auf die Buchung mit Rückgängig.
- [x] AK-4.4.6: Eine Buchung kann mehreren Belegen zugeordnet werden (Sammelbuchung) und umgekehrt (Teilzahlungen) – S. Eine bestätigte Verknüpfung setzt das Bezahlt-Datum des Dokuments.
- [x] AK-4.4.7: Buchung als „kein Beleg nötig“ markierbar mit Grund aus einer einstellbaren Liste; die Gegenpartei wird gemerkt und die nächste Buchung von ihr gleich so vorgeschlagen.
- [x] AK-4.4.8: Eine CSV einer bisher unbekannten Bank lässt sich ohne Programmierung importieren (Spalten zuordnen, Datums-/Zahlenformat, Vorzeichen). *(prüfen: der Test mit Exporten von mindestens 5 verschiedenen Banken ist nicht belegt)*
- [ ] AK-4.4.9: Enthält die CSV eine Uhrzeit, wird sie für den Abgleich genutzt; ohne Uhrzeit funktioniert der Abgleich über Datum, Betrag und Händler (mehr 1-Tap-Bestätigungen). *(prüfen: der Score nutzt heute nur das Datum)*

Neu seit dem Plan (gebaut):
- [x] AK-4.4.10: Konten **von Hand anlegen** mit Art (geschäftlich, privat, Kasse) und IBAN; ein Import ohne gewähltes Konto landet im Konto mit seiner IBAN (Punkt 189).
- [x] AK-4.4.11: **Bargeldkasse:** Konto der Art „Kasse“, Buchungen von Hand mit laufendem Saldo; Abgleich, Zähler und „kein Beleg nötig“ wie bei einer Bank (Punkt 190).
- [x] AK-4.4.12: Händlerkategorie (MCC) als Tag, Namen einstellbar (Einstellungen → Bank → MCC); Rückerstattungen hängen an ihrer ursprünglichen Buchung.
- [x] AK-4.4.13: **Dauerbeleg:** ein Dokument für alle gleichen Buchungen einer Gegenpartei (vergangene und künftige), beendbar.
- [x] AK-4.4.14: Notizen an Buchungen; Zeitraum-Schnellwahl und Kalenderansicht im Konto-Fenster; rote Zähler offener Buchungen bis zur einzelnen Buchung.

**S – später:** Import von ~~CAMT.053 (XML) und~~ MT940. *(CAMT.053 ist gebaut, AK-4.4.1)*

### 4.5 Belegpaket für den Steuerberater, Berichte & Export – M

Details in [Kapitel 10](#10-berichte--export).

**User Story:** Als Owner will ich für einen Monat/ein Quartal mit wenigen Klicks ein schönes, übersichtliches Dokument erzeugen, in dem jede Buchung mit ihrem Belegfoto steht, und es meinem Steuerberater schicken.

- [x] AK-4.5.B1 (geändert): Einstellungen → Export → Belegpakete: Zeitraum (Monat, Quartal, Jahr oder frei) + Konten (Bankkonten und Kassen) wählen → PDF; große Pakete baut ein Hintergrund-Job mit rotem Zähler. Keine eigene Vorschau-Stufe.
- [x] AK-4.5.B2: Das PDF enthält Deckblatt mit Kennzahlen, Übersicht pro Konto mit Seitenzahlen und pro Buchung eine Seite mit Buchungsdetails **und** allen Belegseiten (PDF-Belege als Bild, wo der Server sie rendern kann, sonst Hinweisseite). Layout vorläufig (D-032).
- [x] AK-4.5.B3: Fehlende Belege sind deutlich markiert und am Ende gesammelt aufgelistet.
- ~~AK-4.5.B4: Standort erscheint im Paket nur, wenn der Owner es einschaltet, und dann nur als Ortsname (Stadt), nie als Koordinaten.~~ – *entfällt: Standorte werden (noch) nicht erfasst (AK-4.1.10); kommt die Erfassung, gilt die Regel wieder.*
- [x] AK-4.5.B5 (geändert): Versand: Download und Ablage im Steuerberater-Zugang (der Steuerberater sieht die Pakete seines Zeitraums). E-Mail mit Link ist nicht gebaut.
- [x] AK-4.5.B6: Ein erstelltes Paket ist unveränderlich (Versionsnummer, Erstellzeitpunkt, Datei `Belegpaket_<Zeitraum>_v<N>.pdf`); Änderungen ergeben ein neues Paket.
- [x] AK-4.5.B7 (neu): Dokumente ohne Buchung (z. B. bar bezahlt) kommen per „Ins Belegpaket“ im ⋯-Menü des Dokuments als Abschnitt „Belege ohne Buchung“ ins Paket (A38); jede Kasse ist ein eigener Abschnitt (F30).

- [ ] AK-4.5.1: Bericht für Zeitraum + Konto(en): alle Buchungen mit verknüpften Belegen, fehlende Belege rot markiert, Summen. *(prüfen: deckt das Belegpaket ab; ein eigener Bericht ist nicht gebaut)*
- [x] AK-4.5.2 (geändert): Export als ZIP pro Zeitraum (Belegdateien nach Monat, plus `index.csv` mit `;`, UTF-8 mit BOM, aktive Eigenschaften als Spalten). PDF-Bericht, CSV- und XLSX-Übersicht einzeln sind nicht gebaut.
- [ ] AK-4.5.3: Erstellung großer Exporte asynchron (Job) mit Benachrichtigung, Download-Link mit Ablaufdatum (Standard 7 Tage). *(Belegpaket und Backup laufen als Job; der ZIP-Export nicht – prüfen)*
- [ ] AK-4.5.4: Versand per E-Mail (SMTP) als Link, nie als großer Anhang (Anhang nur ≤ 10 MB, optional). *(noch nicht gebaut)*

### 4.6 Benutzer, Organisationen, Einladungen – M

- ~~AK-4.6.1: Eine Instanz hat mehrere Organisationen; jede Person kann Mitglied mehrerer Organisationen sein (Org-Wechsler).~~ – *entfällt: eine Organisation pro Installation, kein Org-Wechsler (D-027).*
- [x] AK-4.6.2: Daten sind strikt pro Organisation getrennt (jede fachliche Tabelle hat `org_id`, globaler Org-Scope; Tests für Org-Isolation). Bleibt Pflicht, auch mit einer Organisation pro Installation.
- ~~AK-4.6.3: Einladung per Link/QR-Code mit Rolle, einmalig nutzbar, Ablauf 7 Tage.~~ – *entfällt: keine Einladungslinks – der Owner/Admin legt Personen selbst an (AK-4.6.5).*
- [x] AK-4.6.4 (geändert): Steuerberater-Zugang (Einstellungen → Personen → Steuerberater): Benutzername, Zeitraum (offene Enden), Konten inkl. Kassen, optionales Ablaufdatum, optional E-Mail. Die App erzeugt ein Einmal-Passwort (24 Zeichen), zeigt es **einmal** im Dialog; der Steuerberater setzt beim ersten Anmelden sein eigenes. Keine Selbstregistrierung. Neues Passwort und Ende des Zugangs jederzeit (mit „Bitte bestätigen“), beides wirkt sofort. Sperre nach 10 Fehlversuchen (15 min), Sitzung 8 h, kein „Angemeldet bleiben“; jede Ansicht und jeder Download im Audit-Log, „Zuletzt angesehen“ beim Zugang. „Passwort vergessen?“ nur, wenn Mail eingerichtet ist (F29). D-031.

Neu seit dem Plan (gebaut):
- [x] AK-4.6.5: Personen (Einstellungen → Personen → Mitglieder): der Admin legt Name + Benutzername + Rolle an; die App zeigt ein Passwort einmal im Dialog; Passwort neu setzen (beendet alle Sitzungen), Rolle ändern, Zugang entziehen.
- [x] AK-4.6.6: **Eigene Rollen** mit Rechte-Häkchen (Hochladen, alle sehen, bearbeiten, löschen, Bank, Export, Tags, Notizen teilen, Updates, Vorschauen); „Admin“ (`owner`) darf immer alles (Q-21).
- [x] AK-4.6.7: Read-only-Oberfläche des Steuerberaters: Belegpakete, Buchungen und Dokumente in seinem Umfang, Downloads; alle Schreibwege gesperrt.

### 4.7 Sprachnotizen & Spracherkennung (lokal) – S

> **Stand 2026-11-14:** noch nicht begonnen.

- S: Sprachnotiz aufnehmen (MediaRecorder, Opus/WebM bzw. AAC/MP4 auf iOS), abspielen.
- S: **Speech-to-Text lokal auf dem Server** (D-019): Sprachnotiz wird automatisch in Text umgewandelt (Deutsch zuerst), der Text ist durchsuchbar, editierbar und kann als Notiz übernommen werden. Die Engine wählt der Lead (lokal laufend, z. B. whisper.cpp oder Vosk); **kein** Cloud-Dienst.
- C: **Lokale Vorschläge** für Händler/Kategorie/Tags über eine austauschbare, lokal laufende Schnittstelle – zusätzlich zu den Regeln, pro Organisation abschaltbar, nie an externe Dienste.

Akzeptanzkriterien:
- [ ] AK-4.7.1: Eine Sprachnotiz von 30 s ist typischerweise innerhalb von 2 min als Text da (Hardware-abhängig, Lead legt Ziel fest).
- [ ] AK-4.7.2: Während der Transkription gibt es keine ausgehende Netzwerkverbindung (in Tests geprüft).
- [ ] AK-4.7.3: Transkription ist pro Organisation abschaltbar; ohne sie funktioniert alles weiter.

### 4.8 Einstellungen – M

- Profil, Geräte (Liste, Name, zuletzt genutzt, sperren), Passkeys verwalten.
- Organisation: Name, Kategorien, Zahlungsarten, Regeln, Mitglieder, Freigaben, Aufbewahrung, Papierkorb-Dauer.

> **Stand 2026-11-14 (geändert):** Einstellungen sind ein Menü-Fenster mit Gruppen (siehe „Stand“ oben). Statt Kategorien/Zahlungsarten/Regeln: Tags, Eigenschaften, Farben, Bank-Gründe und MCC. Papierkorb-Dauer steht in den Eigenschaften des Papierkorbs. Passkeys verwalten gibt es noch nicht (D-036).
- **Backup herunterladen** (deckt auch den Datenexport nach DSGVO Art. 20 ab, D-023) und Konto löschen.

**Backup herunterladen (D-023, B22, geändert durch D-028):** Einstellungen → Backup, für Owner/Admins (Recht `backup.create`).

- [x] AK-4.8.1 (geändert): Nur Admins (Rolle `owner`) sehen die Seite; Start und Download verlangen eine frische Bestätigung („Bitte bestätigen“, heute Passwort, 5 min, D-029) und stehen im Audit-Log. Kein Export über Installationen hinweg.
- [x] AK-4.8.2 (geändert): Auswahl „Alles“ (Standard) oder Zeitraum von/bis; „Originale“ oder „+ abgeleitete Dateien“; Häkchen – alle angehakt – für **Dokumente**, **Bankdaten**, **Notizen**, **Tags/Eigenschaften/Einstellungen**. Der Papierkorb ist immer dabei, das Audit-Log nie; GPS entfällt (keine Standorte gespeichert). D-028.
- [x] AK-4.8.3: Die erwartete Größe wird vor dem Start angezeigt und bei Änderung der Auswahl aktualisiert.
- [x] AK-4.8.4: Bis zur Grenze (`dochamster.backup.stream_limit`) startet der Download sofort als Stream; darüber baut ein Hintergrund-Job das ZIP mit Fortschritt, ein roter Zähler meldet „fertig“, die Datei ist 24 h abrufbar und wird danach gelöscht. Abrufbar nur für die angemeldete Person, die das Backup gestartet hat.
- [x] AK-4.8.5: Optional mit Passwort geschützt (AES-256, mind. 12 Zeichen, nie gespeichert); ein geschütztes Backup ist ein äußeres ZIP mit verschlüsseltem `backup.zip`, Dateinamen also nicht lesbar.
- [x] AK-4.8.6: Im ZIP stehen nie Passwörter/Hashes, Passkey-Schlüssel, Geräte-Token/-Schlüssel, Bank-Zugangsdaten oder der App-Schlüssel (nur freigegebene Tabellen, geheime Spalten entfernt).
- [x] AK-4.8.7 (geändert): Ein Voll-Backup lässt sich mit `php artisan dochamster:restore <zip>` in eine **leere** Instanz einspielen; das ZIP wird als nicht vertrauenswürdig geprüft (Pfade, Symlinks, Duplikate, Zip-Bomben-Grenzen, Manifest, Prüfsummen, Dateitypen, neueres Schema). Der Owner kommt mit dem beim Restore angegebenen Login zurück, **alle anderen Personen ohne Zugang**; die Mitglieder-Seite markiert sie, „Passwort neu setzen“ richtet sie ein (A378).

Neu seit dem Plan (gebaut):
- [x] AK-4.8.8: **Bitte bestätigen** (Sudo-Modus, Punkt 212): vor Export, Backup, endgültigem Löschen, Rollen, Personen, Steuerberater-Zugang, Geräteschlüsseln, Abmelden anderer Sitzungen und Updates fragt die App einmal nach dem Passwort; danach 5 min keine zweite Frage. Der Steuerberater wird nie gefragt. D-029.
- [x] AK-4.8.9: **Angemeldete Geräte:** eigene Sitzungen mit Gerätename, gekürzter IP und „Dieses Gerät“; einzeln oder „Alle anderen abmelden“ (Punkt 208).
- [x] AK-4.8.10: **Log** mit Logbüchern (Allgemein, Texterkennung, Updates), Stufen, Filtern, Export und Zurücksetzen; Aufbewahrung 12 Monate bzw. 30 Tage (OCR).
- [x] AK-4.8.11: **Update** aus der App: Versionen-Karte mit Aktualisieren/Wechseln, Vorschau-Pakete von `dev` für Berechtigte (D-035).
- [x] AK-4.8.12: **Erweiterungen:** fester Katalog (OCR-Sprachen, Übersetzungspakete), installieren/aktualisieren/entfernen/ein-aus; Module mit Code „kommt später“.
- [x] AK-4.8.13: **Geschwindigkeit:** gemessene Serverzeiten (Server-Timing), Tempo-Checks, Selbsttests, Optimieren nach Updates.
- [x] AK-4.8.14: Home zeigt „Letztes Backup vor N Tagen“, wenn das letzte Backup älter als 90 Tage ist oder es keins gibt.

### 4.9 Einrichtung mit Setup-Assistent – M

Ziel (PO, D-024): Wer docHamster einrichtet, hat **wenig Ahnung von Technik**. Deshalb gibt es einen **Setup-Assistenten im Browser**, der Schritt für Schritt durch die ganze Einrichtung führt und jeden Schritt in einfachem Deutsch erklärt. Eine **Installation über die Shell** gibt es zusätzlich (eigene Server, Technik-Kundige). Die Technik dahinter (Paket, Absicherung, Speicherort der Einstellungen …) entscheidet der Lead (Q-12).

**User Story:** Als Owner ohne Technik-Kenntnisse will ich docHamster auf meinem Webspace oder Server einrichten, indem ich die Dateien hochlade, die Adresse im Browser öffne und mich durch alles führen lasse – bis mein Handy verbunden ist.

Ablauf (Entwurf, Reihenfolge und Technik legt der Lead fest):
1. **Willkommen:** was man braucht (eigene Domain mit HTTPS, Datenbank-Zugangsdaten vom Hoster, Handy, ca. 20 min) und wo man es beim Hoster findet.
2. **Server prüfen:** die Prüfung aus `dochamster:doctor` im Browser – was fehlt, verständlich erklärt, mit konkretem Hinweis, was zu tun ist; Zusatzfunktionen (Texterkennung, Spracherkennung) als „verfügbar / nicht verfügbar“.
3. **Datenbank:** Zugangsdaten abfragen (Server, Port, Datenbankname, Benutzer, Passwort), Verbindung testen, Datenbank anlegen falls erlaubt, Tabellen einrichten.
4. **Owner-Konto:** Name, Passkey auf diesem Gerät anlegen (nach P1-4).
5. **E-Mail-Versand (optional):** SMTP-Daten vom Hoster, Test-Mail.
6. **Hintergrundaufgaben:** Cron-Eintrag beim Hoster erklären und prüfen, ob er läuft.
7. **Handy verbinden:** QR-Code scannen, App auf den Home-Bildschirm legen (eigene Anleitung für iPhone und Android), Kamera- und Standort-Erlaubnis erklären.
8. **Fertig:** erste Schritte – erstes Foto, Kontoauszug importieren, Steuerberater-Zugang anlegen.

Akzeptanzkriterien:
- [x] AK-4.9.1: Einrichtung **ohne Shell** möglich: `install.php` hochladen (lädt das Release-Paket inkl. `vendor/`), Adresse öffnen, der Assistent führt durch alles.
- [x] AK-4.9.2: Jeder Schritt hat eine kurze, verständliche Erklärung auf Deutsch; Fachbegriffe werden erklärt; jeder Fehler nennt die Ursache in einfachen Worten und was zu tun ist. *(Test mit echter Person: AK-4.9.8)*
- [x] AK-4.9.3: Der Assistent richtet die Datenbank selbst ein: fragt die Zugangsdaten ab, testet die Verbindung, legt die Tabellen an.
- [x] AK-4.9.4: Eine noch nicht eingerichtete Instanz kann niemand Fremdes übernehmen (Einrichtungscode); nach Abschluss ist der Assistent dauerhaft gesperrt.
- [x] AK-4.9.5: Wird die Einrichtung abgebrochen, macht der Assistent beim nächsten Aufruf beim letzten fertigen Schritt weiter (jeder Schritt ergibt sich aus dem, was schon da ist).
- [ ] AK-4.9.6: Am Ende ist das Handy verbunden und die App liegt auf dem Home-Bildschirm. *(Teil: Handy-Test und „Erste Schritte → Handy verbinden“ auf Home; nicht Teil des Assistenten)*
- [x] AK-4.9.7: Die Shell-Installation führt durch dieselben Schritte (Fragen im Terminal, inkl. Datenbank-Zugangsdaten).
- [ ] AK-4.9.8: Eine Person ohne Technik-Kenntnisse schafft die Einrichtung in ≤ 20 min, wenn Domain und Datenbank beim Hoster schon vorhanden sind (Test mit einer echten Person vor v1.0).
- [x] AK-4.9.9 (neu): Bestehende Instanz: der Installer bietet Update oder Neuinstallation (mit Wahl, was gelöscht wird); Updates danach aus der App (AK-4.8.11).

### 4.10 Oberfläche, Home & Notizen – neu (gebaut)

Alles nach [`design/FRAMEWORK.md`](design/FRAMEWORK.md).

- [x] AK-4.10.1: **Fenstersystem:** jedes Fenster ist eine Instanz eines Moduls mit einer Ressource; mehrere nebeneinander; Breiten aus dem Inhalt (min/optimal/max, Priorität, Streifen), Minimieren/Maximieren/Schließen, Breite von Hand, Zustand in der Adresse, Fenster-Cache und Vorladen; auf dem Handy ein Fenster mit Zurück-Pfeil.
- [x] AK-4.10.2: Ein UI-Framework für alle Module (eigenes CSS/JS ohne Build-Schritt, Hell/Dunkel, Styleguide): Umschalter, Knopfgruppe, Optionskarten, Listeneintrag, Kopierfeld, Ordner-Menü, Klappkarten, Dialoge/Menüs/Toasts in der obersten Ebene, Leerer Zustand, keine Doppelklick-Aktionen.
- [x] AK-4.10.3: **Home** als persönliches Dashboard: Widgets (Begrüßung, Schnellzugriff, Ablegen, Posteingang, Fälligkeiten, Buchungen, Aktivität, Texterkennung, Notizen, Foto), Layout pro Person, „Auf Home anheften“ aus jedem Fenster, **Erste Schritte** (Konto anlegen, ersten Beleg hochladen, Kontoauszug importieren, Handy verbinden).
- [x] AK-4.10.4: **Notizen-Board** (`/notes`): Spalten wie Trello mit Farben und Breiten, Notizen mit Größe, Farbe, Listen und Bearbeiten direkt auf der Notiz.
- [x] AK-4.10.5: Rote Zähler führen über jede Ebene bis zum Element (Menü → Fenster → Reiter → Zeile), bis „99+“.
- [x] AK-4.10.6: Oberfläche komplett auf Deutsch und Englisch (i18n-Dateien); Maskottchen-Hamster (Pixel-Art) für leere und wartende Zustände.

---

## 5. Architektur

### 5.1 Überblick

```
┌──────────────┐  HTTPS   ┌─────────────────────────────┐      ┌──────────────┐
│ PWA (Browser)│ ───────▶ │ PHP-App (Web)                │ ───▶ │ DB (MariaDB/ │
│ - Kamera     │          │ - Routing, Auth, Rechte      │      │  MySQL)      │
│ - Offline-   │ ◀─────── │ - Upload-Annahme             │      └──────────────┘
│   Queue (IDB)│          │ - Suche, Berichte, Admin-UI  │      ┌──────────────┐
│ - Service    │          └──────────────┬──────────────┘ ───▶ │ Datei-Storage│
│   Worker     │                         │ Jobs (DB-Queue)      │ (außerhalb   │
└──────────────┘                         ▼                      │  Webroot)    │
                          ┌─────────────────────────────┐ ───▶ └──────────────┘
                          │ Queue `local` (offline)     │ ──▶ Zusatz-Tools, falls vorhanden
                          │ - Bild-Normalisierung       │     (D-010): tesseract, ocrmypdf,
                          │ - OCR, Extraktion, Tags     │     vips, whisper.cpp, ffmpeg
                          │ - Spracherkennung, Ortsnamen│
                          │ - Abgleich, Belegpaket      │
                          ├─────────────────────────────┤
                          │ Queue `net` (nach außen)    │ ──▶ Bank-Modul (z. B. Revolut-API),
                          │ - Bank-Abruf, alle Mails    │     SMTP
                          └─────────────────────────────┘
                           Worker per Cron jede Minute, Dauer-Worker optional
```

**Hintergrundjobs (D-020, D-010):** zwei Queues. `local` (Normalisierung, OCR, Spracherkennung, Ortsnamen, Abgleich, Belegpaket-PDF) hat keinen Internetzugang; `net` (Bank-Abruf, alle Mails) ist der **einzige** Weg nach außen. Web-Requests machen selbst keine ausgehenden Aufrufe (Mails immer über die Queue). Durchgesetzt per Architektur-Tests; in CI laufen die `local`-Jobs ohne Netzwerk; auf eigenen Servern optional zusätzlich per Firewall-Regel pro Worker-Benutzer. Gestartet werden die Worker per Cron (`schedule:run` jede Minute, kurze `queue:work`-Läufe); ein Dauer-Worker (systemd/Supervisor) ist optional. Lange Jobs werden pro Seite/Datei aufgeteilt, damit sie in Zeitlimits von Webhosting passen.

### 5.2 Technik-Stack (Entscheidungen in DECISIONS.md)

> **Stand 2026-11-14:** PHP-Minimum laut `composer.json` **8.3**; Belegpaket mit **eigenem PDF-Schreiber** statt Dompdf (D-032); Passkeys noch nicht im Einsatz (D-036); Texterkennung zusätzlich im Browser (tesseract.js), PDF-Anzeige mit pdf.js, Übersetzung mit Bergamot – alles selbst gehostet.

| Bereich | Vorschlag | Alternative | Entscheidung |
|---------|-----------|-------------|--------------|
| Sprache | **PHP 8.4** (Minimum) | – | D-002 ✅ |
| Framework | **Laravel**, aktuelle stabile Version, in `composer.json` festgelegt; Blade-Templates; kein Livewire/Inertia/SPA, keine Laravel-Cloud-Dienste, kein Sanctum/Passport (eigene Geräte-Token-Anmeldung) | – | D-001 ✅ |
| DB | **MariaDB 10.11+ und MySQL 8.4+**, beide ab v1; nur gemeinsamer Funktionsumfang (InnoDB, `utf8mb4`, Query Builder); Volltext per `FULLTEXT` hinter einer `SearchIndex`-Schnittstelle | PostgreSQL später möglich; **kein SQLite** in v1 | D-003 ✅ |
| Frontend | Blade + Vanilla-JS (ES-Module) ohne Build-Schritt; strikte CSP ohne Inline-Skripte | htmx nur, wenn ein Screen es braucht (lokal eingebunden, kein CDN) | D-004 ✅ |
| CSS | Eigenes kleines CSS mit Custom Properties (Light/Dark) | – | D-004 ✅ |
| OCR | Tesseract 5 (`deu+eng`), `ocrmypdf` für PDFs – **Zusatzfunktion**, nur wenn auf dem Server vorhanden (D-010) | – (kein Cloud-OCR, D-019) | D-005 |
| Bildverarbeitung | GD (Kern, immer vorhanden); libvips (`php-vips`) schneller als **Zusatzfunktion**; HEIC über libheif als Zusatzfunktion (D-010) | – | D-005 |
| XLSX | PhpSpreadsheet (Import & Export) | OpenSpout (schneller, streaming) | – |
| PDF-Erzeugung | **Dompdf**; Fotos vorher in einem `local`-Job auf ca. 1600 px, JPEG-Qualität 80 verkleinert. Benchmark in P4-2 mit einem synthetischen Paket von 150 Seiten: bestanden = ≤ 60 s und ≤ 256 MB PHP-Speicher auf der Mindest-Hardware (D-010), PDF ≤ 50 MB (B12) | nur wenn der Benchmark scheitert: eine andere reine PHP-Bibliothek mit erlaubter Lizenz (D-022, z. B. TCPDF; mPDF ist GPL und ausgeschlossen); **kein** headless Chromium | B12 ✅ |
| Passkeys | `web-auth/webauthn-lib` | `lbuchs/WebAuthn` | D-006 |
| Verschlüsselung | Geheimnisse und personenbezogene Felder (Bank-Zugangsdaten, Koordinaten) **immer** verschlüsselt (Laravel encrypted casts); Dateien: Volume-Verschlüsselung (dringend empfohlen) + optional libsodium (`secretstream`) pro Datei | – | D-007 (Felder ✅, Dateien offen) |
| Tests | PHPUnit/Pest, Playwright (E2E) | – | – |
| Qualität | PHPStan (Level 8), PHP-CS-Fixer/Pint, ESLint | – | – |

### 5.3 Verzeichnisstruktur (Ziel, framework-neutral skizziert)

```
/app            Domain + Anwendungslogik
  /Domain       Documents, Imports, Matching, Reports, Sharing, Users (Entities, Services, Policies)
  /Http         Controller, Middleware (Auth, Org-Scope, CSRF, RateLimit)
  /Jobs         ProcessDocument, RunOcr, ExtractFields, ApplyRules, MatchTransactions, BuildExport, SendMail
  /Infrastructure  Storage, Ocr (TesseractOcr), Speech (lokale Engine), Banking (CSV/XLSX-Parser, BankConnector), Geo (Ortsnamen), Crypto
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
```

### 5.4 Modul-Schnitt (wichtig für parallele Arbeit zweier KIs)

> **Stand 2026-11-14:** Im Code heißen die Bereiche heute `app/Domain/{Advisor, Audit, Auth, Backup, Banking, Dashboard, Devices, Documents, Log, Notes, Ocr, Org, Palette, Payments, Printing, Properties, ReceiptPack, Translate}`. Die Tabelle unten ist der ursprüngliche Schnitt.

| Modul | Verantwortung | Abhängig von |
|-------|---------------|--------------|
| `Core` | Gerüst, Config, DB, Migrations, i18n, Layout | – |
| `Auth` | Gerätekopplung, Passkeys, Sitzungen, Einladungen | Core |
| `Org` | Organisationen, Mitgliedschaften, Rollen, Policies | Core, Auth |
| `Capture` | PWA, Kamera, Aufnahmezeit/Standort, Upload-API, Offline-Queue, Storage | Core, Auth, Org |
| `Processing` | Job-Queue, OCR, Spracherkennung (lokal), Extraktion, Regeln, Duplikate, Ortsnamen | Core, Capture |
| `Search` | Volltext, Filter, Detailansicht, Prüfen-Modus | Processing |
| `Banking` | CSV/XLSX-Import für jede Bank, Import-Profile, Parser, Transaktionen, Matching, `BankConnector`-Schnittstelle | Core, Org |
| `dochamster-revolut` (eigenes Repo) | Revolut-Business-Connector mit Einrichtungs-Assistent, implementiert `BankConnector` | Banking (nur über die Schnittstelle) |
| `Reports` | Belegpaket, Berichte, Exporte, Mail | Banking, Capture |
| `Sharing` | Steuerberater-Freigaben, Audit-Log | Org, Reports |

Module kommunizieren nur über Services/Interfaces und Domain-Events (z. B. `DocumentUploaded`, `DocumentProcessed`, `TransactionsImported`), nicht über fremde Tabellen direkt.

---

## 6. Datenmodell

> **Stand 2026-11-14:** Das gebaute Datenmodell weicht ab: statt `categories`, `payment_methods`, `merchants`, `rules`, `document_fields`, `document_captures`, `shares` und `invitations` gibt es u. a. `folders`, `tags`, `properties`/`document_properties`, `sender_hints`, `ocr_learned`, `advisor_accesses`, `receipt_packs`, `backups`, `device_keys`, `printers`, `notes`/`note_columns`, `user_dashboards`, `share_links`, `log_entries`, `ocr_log`. Maßgeblich sind die Migrationen in `database/migrations/`.

Konventionen: IDs als ULID (Text, sortierbar, nicht ratbar) für öffentlich sichtbare Entitäten; `created_at`/`updated_at` überall; Geldbeträge als **Integer in Cent** (`amount_minor`) + `currency` (ISO 4217); Datumsangaben ohne Uhrzeit als `DATE`; Soft-Delete über `deleted_at` wo angegeben. Jede fachliche Tabelle hat `org_id`.

### 6.1 Identität & Zugriff

| Tabelle | Felder (Auszug) | Hinweise |
|---------|-----------------|----------|
| `users` | id, username (unique, nullable – nur Steuerberater), email (unique, nullable), display_name, locale, password_hash (nullable – nur Steuerberater), created_at | Owner/Mitglied: nur Passkeys + Geräte-Token; Steuerberater: Benutzername + Passwort (Argon2id), D-012 |
| `passkeys` | id, user_id, credential_id (unique), public_key, sign_count, transports, name, last_used_at | WebAuthn |
| `devices` | id, user_id, name, user_agent, token_hash, token_family, last_used_at, last_ip_hash, revoked_at, created_at | Langlebiges Geräte-Token, Rotation |
| `organizations` | id, name, settings_json, retention_days, trash_days | |
| `memberships` | id, org_id, user_id, role (`owner`/`member`/`accountant`), created_at, revoked_at | unique(org_id,user_id) |
| `invitations` | id, org_id, role, token_hash, email (optional), expires_at, used_at, created_by | Einmal-Link/QR für Mitglieder; Steuerberater-Konten legt der Owner direkt an (D-012) |
| `shares` | id, org_id, membership_id, date_from, date_to, bank_account_ids_json, category_ids_json, expires_at, revoked_at | Umfang für Steuerberater |

### 6.2 Dokumente

| Tabelle | Felder (Auszug) | Hinweise |
|---------|-----------------|----------|
| `documents` | id, org_id, uploaded_by, client_upload_id (unique pro org), status, title, doc_date, amount_minor, currency, merchant_id, merchant_raw, category_id, payment_method_id, note, ocr_text, language, duplicate_of_id, reviewed_at, reviewed_by, deleted_at, created_at | Kern |
| `document_captures` | document_id, captured_at (UTC, korrigiert), captured_at_raw, captured_tz, captured_at_source (`device`/`exif`/`server`), client_sent_at, received_at, clock_skew_ms, lat, lon, accuracy_m, location_source (`geolocation`/`exif`), place_name, place_country, location_deleted_at | Aufnahme-Metadaten (D-017); lat/lon/accuracy verschlüsselt (D-007), nie in Logs; Abweichung > 2 min wird markiert. Folge der Verschlüsselung: keine räumlichen Abfragen in SQL – der Ortsname wird einmal beim Eingang ermittelt und im Klartext gespeichert, der Abgleich nutzt ihn (B17) |
| `document_fields` | id, document_id, field (`doc_date`/`amount`/`merchant`/`category`…), value, source (`ocr`/`rule`/`transaction`/`local_model`/`user`), confidence (0–1), created_at | Nachvollziehbarkeit jeder Erkennung; `transaction` = aus der abgeglichenen Buchung übernommen |
| `document_files` | id, document_id, kind (`original`/`normalized`/`thumbnail`/`searchable_pdf`), page_no, path, mime, size, sha256, width, height, encrypted (bool) | Originale unverändert |
| `audio_notes` | id, document_id, path, mime, duration_s, transcript, transcript_engine, transcript_status, transcribed_at | S; Transkript lokal erzeugt |
| `merchants` | id, org_id, name, normalized_name, default_category_id | Lernt aus Korrekturen |
| `categories` | id, org_id, name, color, icon, sort, archived | |
| `payment_methods` | id, org_id, name, sort | Chips |
| `tags` | id, org_id, name (`#reise`) | |
| `document_tags` | document_id, tag_id, source, confidence | |
| `rules` | id, org_id, priority, match_type (`merchant`/`text_contains`/`regex`/`amount_range`), match_value, action_category_id, action_tags_json, action_payment_method_id, created_from_document_id, enabled | Regel-Engine |
| Volltextindex | `FULLTEXT`-Index (MariaDB/MySQL, `MATCH … AGAINST`) über ocr_text, note, merchant, tags; Tabellenzuschnitt im Datenmodell (P0-18) | hinter der `SearchIndex`-Schnittstelle (D-003); keine deutsche Wortstamm-Bildung in der DB – falls nötig, Normalisierung in PHP vor dem Indexieren |

### 6.3 Banking

| Tabelle | Felder (Auszug) | Hinweise |
|---------|-----------------|----------|
| `bank_accounts` | id, org_id, name, provider (`revolut`/`file`), iban_masked, currency, import_profile_id | IBAN nur maskiert speichern |
| `bank_connections` | id, org_id, bank_account_id, provider, method (`api`/`aggregator`), credentials_enc, scopes, last_sync_at, expires_at, status | Revolut-Live-Anbindung (D-016); Zugangsdaten nur verschlüsselt |
| `import_profiles` | id, org_id, name, file_type, encoding, delimiter, decimal_sep, thousands_sep, date_format, header_row, column_map_json, sign_convention | Speicherbar, Vorlagen |
| `imports` | id, org_id, bank_account_id, file_path, file_sha256, rows_total, rows_new, rows_duplicate, rows_error, created_by, created_at | Protokoll |
| `transactions` | id, org_id, bank_account_id, import_id, external_id (z. B. Revolut-ID), booking_date, booked_at (Zeitpunkt, falls bekannt), value_date, amount_minor, currency, original_amount_minor, original_currency, fx_rate, fee_minor, counterparty, merchant_city, merchant_country, provider_category, state (`completed`/`pending`/`reverted`/`declined`/`failed`), purpose, raw_json, fingerprint (unique pro Konto), no_receipt_reason | `external_id` bzw. Fingerprint gegen Doppelimport; `provider_category` = Händlerkategorie (MCC), angezeigt als deutscher Text; `declined`/`failed` nie abgeglichen, nie im Belegpaket; `booked_at` bei Kartenzahlungen = Zeitpunkt der Autorisierung an der Kasse, nicht der Abrechnung (B7); `raw_json` ohne Name/Telefon des Karteninhabers (B8) |
| `matches` | id, org_id, transaction_id, document_id, score, reasons_json, status (`suggested`/`auto`/`confirmed`/`rejected`), decided_by, decided_at | n:m möglich |

### 6.4 System

| Tabelle | Felder (Auszug) |
|---------|-----------------|
| `jobs` | id, queue, type, payload_json, attempts, max_attempts, available_at, reserved_at, failed_at, error | (bzw. Framework-Queue) |
| `exports` | id, org_id, type (`receipt_pack`/`csv`/`xlsx`/`zip`…), params_json, version, status, file_path, sha256, expires_at, created_by |
| `download_links` | id, export_id, token_hash, expires_at, downloads, max_downloads |
| `audit_log` | id, org_id, user_id, device_id, action, subject_type, subject_id, meta_json, ip_hash, created_at (append-only) |

---

## 7. HTTP-Schnittstelle (Routen & API)

> **Stand 2026-11-14:** Maßgeblich ist `routes/web.php` (plus `bootstrap/app.php` für `/health`, `/ping`, `POST /api/upload` mit Geräteschlüssel und `/api/printer/*`). Die PWA-Upload-API pro Datei unten ist nicht gebaut; Formulare und Fenster laden per normalem POST/GET mit CSRF-Token.

Web-Oberfläche serverseitig gerendert; zusätzlich eine kleine JSON-API für die PWA (Upload, Queue, Status). Alle Routen außer Login/Einladung erfordern Sitzung; alle fachlichen Routen gelten für die **aktive Organisation in der Sitzung** (D-008; seit D-027 gibt es nur eine); es gibt keine `/o/{org}/…`-Adressen.

| Methode | Pfad | Zweck | Rolle |
|---------|------|-------|-------|
| GET | `/` | Erfassen-Screen | owner, member |
| PUT | `/api/uploads/{client_upload_id}/files/{n}` | eine Seite/Datei (Rohdaten + Content-Type); idempotent pro (`org_id`, `client_upload_id`, `n`), Wiederholung mit gleichem SHA-256 → 200 | owner, member |
| POST | `/api/uploads/{client_upload_id}/complete` | Abschluss: Seitenzahl, Aufnahme-Metadaten, `client_sent_at`; legt das Dokument an (201), Wiederholung liefert das bestehende (200) | owner, member |
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

API-Konventionen: JSON, Fehler als `{ "error": { "code": "...", "message": "..." } }`, Versionierung nicht nötig (nur eigener Client). `client_upload_id` ist eine ULID vom Handy; unfertige Uploads werden nach 7 Tagen gelöscht. **CSRF:** `/api/*` nutzt **kein** Session-CSRF-Token (Uploads aus der Offline-Queue kommen oft erst, wenn es abgelaufen ist), sondern verlangt gleiche Herkunft (`Origin`, `Sec-Fetch-Site: same-origin`) plus den eigenen Header `X-DocHamster-Client: 1`; HTML-Formulare behalten das normale CSRF-Token (D-017).

---

## 8. Verarbeitungs-Pipeline (OCR, Extraktion, Tags)

> **Stand 2026-11-14:** Gebaut ist eine kürzere Kette: Vorschaubild beim Upload (GD), Belegdatum (D-030), `RecognizeText` (Server-OCR, sonst Browser-OCR) → Rechnungsleser-Vorschläge (Eigenschaften, pro Absender gelernt) → Bank-Abgleich (`MatchAfterRecognition`). Keine Regel-Engine, keine Duplikaterkennung, keine Spracherkennung.

Job-Kette pro Dokument (jeder Schritt idempotent, einzeln wiederholbar). Alle Schritte laufen in der Queue `local` ohne Internetzugang (D-020). OCR, HEIC-Konvertierung und Spracherkennung sind Zusatzfunktionen und werden übersprungen, wenn die Umgebung sie nicht hat (D-010):

1. **`NormalizeFiles`** – MIME per Magic Bytes prüfen; bei unverkleinert hochgeladenen Dateien **EXIF-Zeit/-GPS zuerst auslesen** (als Fallback für `document_captures`; Kamerafotos kommen ohne EXIF an, die App schickt die EXIF-Zeit vorher mit, §4.1), dann HEIC→JPEG (Zusatzfunktion); abgeleitete Bilder neu kodieren (entfernt Schadcode und alle EXIF-Daten), Ausrichtung korrigieren, Thumbnails (320 px) erzeugen; PDFs: Seitenanzahl, Text-Layer prüfen. Ortsnamen zum Standort offline ermitteln (GeoNames, D-017).
2. **`RunOcr`** – Bilder: Tesseract (`deu+eng`, PSM 4/6 je nach Seitenverhältnis), Vorverarbeitung (Graustufen, Kontrast, Deskew). PDFs: `pdftotext`; wenn kein Text-Layer → `ocrmypdf` (erzeugt zusätzlich durchsuchbares PDF). Ergebnis: `ocr_text` + Sprache.
3. **`ExtractFields`** – Heuristiken (siehe unten) → `document_fields` mit Konfidenz; bester Wert pro Feld wird in `documents` übernommen, sofern kein `user`-Wert existiert.
4. **`ApplyRules`** – Regeln nach Priorität; Händler-Standardkategorie; Hashtags aus Notiz (`#reise`) und Schlüsselwort-Listen.
5. **`DetectDuplicates`** – Hash-Gleichheit, dann Fuzzy (Betrag gleich, Datum ±1 Tag, Händler-Ähnlichkeit ≥ 0,8).
6. **`IndexDocument`** – Volltextindex aktualisieren.
7. **`MatchDocument`** – gegen offene Buchungen abgleichen (Kapitel 9).
Parallel dazu: **`TranscribeAudio`** (lokal, nur wenn Sprachnotiz vorhanden) → `audio_notes.transcript`, Volltextindex. Optional (C): **`LocalSuggest`** (lokale Vorschläge, `source = local_model`).

Danach Status `processed`; Event `DocumentProcessed`. Nach einem bestätigten Abgleich übernimmt der Beleg fehlende Felder (Händler, Betrag, Datum) aus der Buchung (`source = transaction`).

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

---

## 9. Kontoauszug-Import & Abgleich

### 9.0 Revolut (nachinstallierbares Modul, eigenes Repo, D-021)

- **Live (Weg 1/3 aus §4.4):** Worker ruft neue Buchungen regelmäßig ab (z. B. alle 15–60 min, Lead entscheidet) und zusätzlich nach jedem Foto-Upload, damit der Abgleich schnell passiert. Token-Erneuerung und Ablauf werden überwacht; der Owner wird vor Ablauf benachrichtigt.
- **Datei (Weg 2):** fertiges Import-Profil „Revolut“ (Spalten mit Datum **und Uhrzeit**, Betrag, Gebühr, Währung, Status, Beschreibung). Profil **anhand einer echten, anonymisierten Exportdatei bauen**, nicht aus dem Gedächtnis.
- Gebühren und Fremdwährungsumrechnung werden getrennt gespeichert, damit Beleg-Betrag (Originalwährung) und Kontobetrag (EUR) beide abgleichbar sind.
- **Zeitpunkt (B7):** Kartenzahlungen haben `created_at` (Autorisierung an der Kasse) und `completed_at` (Abrechnung, oft am Folgetag). Nur `created_at` liegt nah am Foto – es wird als `booked_at` gespeichert und für die Zeitnähe genutzt. Der Abruf liest Buchungen erneut, bis sie einen Endstatus haben (`completed`/`declined`/`failed`/`reverted`).
- **Datensparsamkeit (B8):** Revolut liefert bei Kartenzahlungen Name und Telefonnummer des Karteninhabers. In `raw_json` wird `card` auf `id` und die maskierte Kartennummer gekürzt; diese Daten werden nie gespeichert. Testdaten nur synthetisch.
- **Zugriff (B9):** Zustimmung nur mit `scope=READ` (der Zugang könnte sonst Zahlungen auslösen); Refresh-Token und privater Schlüssel verschlüsselt; für jede Token-Erneuerung wird ein frisches, kurzlebiges JWT signiert (D-016).

### 9.1 Import (jede Bank, öffentlicher Kern)

1. Datei hochladen (CSV/XLSX, max. 10 MB).
2. Erkennung: Encoding (BOM, Heuristik), Trennzeichen, Kopfzeile; Vorschau der ersten 20 Zeilen.
3. Spaltenzuordnung: Buchungsdatum, Valuta, Betrag (oder Soll/Haben getrennt), Währung, Gegenpartei, Verwendungszweck; Datumsformat; Dezimaltrenner; Vorzeichenkonvention.
4. Profil speichern (pro Bankkonto). Vorlagen für verbreitete deutsche Banken (Sparkasse, Volksbank, DKB, ING, Commerzbank, comdirect, N26, PayPal-Export) – **Vorlagen anhand echter Beispieldateien verifizieren, nicht raten.**
5. Fingerprint pro Buchung = Hash(Konto, Buchungsdatum, Betrag, normalisierter Zweck, Gegenpartei, laufende Nummer bei identischen Zeilen am selben Tag) → keine Doppelimporte.
6. Ergebnisprotokoll: neu / bereits vorhanden / fehlerhaft (mit Zeilennummer).

### 9.2 Abgleich-Score (0–100)

> **Stand 2026-11-14 (geändert):** Gebaut ist der Score für Banken ohne Uhrzeit (`MatchScore`): Betrag 40 (±1 % → 20), Datum 15, IBAN 25, Rechnungsnummer im Zweck 25, Absender 20, höchstens 100. Über der Auto-Schwelle ohne knappen Zweiten wird **vorgeschlagen** verknüpft (nie still bestätigt); die Zeitnähe-Punkte unten kommen mit dem Bank-Modul.

Mit Revolut ist der **Zeitpunkt** das stärkste Signal: Das Foto entsteht typischerweise Sekunden bis Minuten nach der Zahlung. Der Betrag aus der OCR ist eine Bestätigung, aber nicht nötig.

| Kriterium | Punkte |
|-----------|--------|
| Zeitnähe Aufnahme ↔ Zahlungszeitpunkt `booked_at` (bei Karten die Autorisierung, B7; nur wenn beide Uhrzeiten bekannt) | ≤ 15 min: 35 · ≤ 2 h: 25 · gleicher Tag: 15 |
| Nur Datum bekannt (andere Banken) | gleicher Tag: 15 · ±1–3 Tage: 8 · ±4–7: 3 |
| Betrag exakt gleich (Beleg vs. Buchung, auch Originalwährung) | 40 |
| Betrag ±1 % (Trinkgeld, Rundung, Kurs) | 20 |
| Händler-Ähnlichkeit (Revolut-Händler/Gegenpartei ↔ OCR-Händler, Trigram/Jaro-Winkler) | bis 20 |
| Ort passt (Händlerort ↔ Ortsname der Aufnahme) | 5 |

Maximal 100. Beispiele: Foto 2 min nach Zahlung + Betrag erkannt → 75 + Händler → `auto`. Foto 2 min nach Zahlung, OCR unlesbar, aber einzige Zahlung in der Zeit → 35 + Ort 5 + Händler … → `suggested`, 1-Tap-Bestätigung.

- Score ≥ 80 und eindeutig (zweitbester ≥ 15 Punkte darunter) → `auto`.
- 40–79 → `suggested`.
- Konkurrenz um denselben Beleg → beste Paarung gewinnt (greedy nach Score), Rest bleibt Vorschlag.
- Jede Entscheidung speichert `reasons_json` (welche Kriterien wie viele Punkte), damit die UI „Warum?“ zeigen kann.
- Abgleich läuft nach jedem Import und nach jeder Belegverarbeitung inkrementell.
- Schwellen konfigurierbar; Kalibrierung mit Testdaten in Phase 5.

---

## 10. Berichte & Export

### 10.1 Belegpaket (Hauptbericht für den Steuerberater, D-018)

> **Stand 2026-11-14:** gebaut mit vorläufigem Layout (D-032): Deckblatt mit Kennzahlen, Übersicht pro Konto, eine Seite pro Buchung mit allen Belegseiten, „Belege ohne Buchung“ (A38), Liste fehlender Belege, Fußzeile „Seite n von m“ + Version + Hinweis; IBAN maskiert; Kassen als eigener Abschnitt (F30). Ort und ZIP-Verweis entfallen vorerst.

Ein PDF pro Zeitraum und Konto, schön und ruhig gestaltet (klare Typografie, viel Weißraum, A4 hoch, druckbar, auch am Bildschirm gut lesbar):

1. **Deckblatt:** Titel „Belegpaket“, Name der Organisation, Zeitraum, Konto (IBAN maskiert), Erstellt am, Version; Kennzahlen: Anzahl Buchungen, Summe Ausgaben/Einnahmen, Anzahl mit Beleg / **fehlend**.
2. **Übersicht:** Tabelle aller Buchungen – Nr. · Datum/Uhrzeit · Händler · Kategorie · Betrag (EUR, ggf. Originalbetrag) · Beleg ✓/✗ · Seite.
3. **Pro Buchung eine Seite:** oben die Buchungsdetails (Datum + Uhrzeit, Händler, Betrag, Originalwährung + Kurs, Gebühr, Händlerkategorie (MCC als Text), eigene Kategorie, Notiz, Beleg-Nr., optional Ort), darunter das **Belegfoto groß** (bei mehrseitigen Belegen Folgeseiten). Verweis auf die Originaldatei im ZIP.
4. **Fehlende Belege:** Liste zum Nachreichen; optional (C) Vorlage für einen Eigenbeleg.
5. **Fußzeile jeder Seite:** Seitenzahl, Paket-Version, Hinweis „Zusammenstellung aus docHamster – kein steuerliches Dokument“.

Zusätzlich auf Wunsch: ZIP mit Originalfotos + `index.csv` + PDF, und CSV/XLSX der Übersicht. Seitenaufbau und Gestaltung werden in P0-5 skizziert und vom PO abgenommen; die PDF-Technik wählt der Lead (§5.2).

### 10.2 Weitere Berichte

- **Bericht „Belegübersicht“** (Zeitraum, Konten): Tabelle Buchungsdatum · Gegenpartei · Zweck · Betrag · Beleg (Link/Nr.) · Kategorie · Status; Kopf mit Summen, Anzahl fehlender Belege; Fußzeile mit Erstellzeitpunkt und Hinweis „kein steuerliches Dokument“.
- **Bericht „Belege ohne Buchung“** (z. B. Barzahlungen).
- **Formate:** PDF (Dompdf, A4, Belegnummern als Verweise ins ZIP), CSV (UTF-8 mit BOM, `;`-getrennt für Excel-DE), XLSX, ZIP (Dateien + `index.csv` + PDF-Bericht).
- **Belegnummer:** fortlaufend pro Org und Jahr (`2026-0001`), vergeben beim Status `processed`; stabil, auch nach Änderungen. *(noch nicht gebaut)*
- **Asynchron** ab > 50 Belegen; Benachrichtigung in der App + optional E-Mail.
- **Download-Links:** Token, Ablauf (Standard 7 Tage), optional max. Downloads, im Audit-Log protokolliert.

---

## 11. Sicherheit, Anmeldung, Datenschutz

### 11.1 Anmeldung & Gerätekopplung

> **Stand 2026-11-14 (D-036):** Gebaut: Anmeldung mit Benutzername + Passwort (Argon2id, Rate Limit, Dummy-Prüfung bei unbekanntem Namen), „Angemeldet bleiben“ (Remember-Token, wird bei „Alle anderen abmelden“ und Passwort-Reset erneuert), Liste der angemeldeten Geräte (Punkt 208), „Bitte bestätigen“ statt Passkey-Step-up (D-029), Geräteschlüssel für Uploads von Handys/Programmen. Noch nicht gebaut: Passkeys, Kopplung per Link/QR/Code, Geräte-Token mit Rotation. Punkt 8 (Steuerberater) ist gebaut (D-031).

1. **Erstes Gerät / Instanz-Setup:** CLI-Befehl erzeugt Owner + Einmal-Link (`php artisan dochamster:setup` o. ä.).
2. **Kopplung:** Einladungslink oder QR-Code (auf bereits angemeldetem Gerät angezeigt, 10 min gültig) → auf dem neuen Gerät **Passkey anlegen** (WebAuthn). Für Owner und Mitglieder gibt es **kein** Passwort und kein TOTP (D-012). Passkey verloren → der Owner erzeugt einen neuen Kopplungslink; für den Owner selbst gibt es den Setup-CLI-Befehl. Die Kopplung muss **in der installierten Home-Bildschirm-App** abschließbar sein (App öffnen → kurzen Kopplungscode eintippen, den das andere Gerät anzeigt): Auf iOS kann die installierte App einen eigenen Cookie-Speicher haben, eine Kopplung in Safari käme dort nicht an (B14, Gerätetest P0-22).
3. **Danach:** langlebiges Geräte-Token (256 bit zufällig, nur Hash in DB) als Cookie `__Host-dh_device` (HttpOnly, Secure, SameSite=Lax – Strict würde das Cookie beim Öffnen aus Mail/QR-Link weglassen und wie eine Abmeldung wirken, D-009), Laufzeit 180 Tage gleitend.
4. **Rotation:** Token wird periodisch (z. B. alle 24 h) erneuert; Wiederverwendung eines alten Tokens derselben Familie → ganze Familie sperren (Diebstahlerkennung).
5. **Sitzung:** kurzlebige Session (z. B. 12 h) wird aus gültigem Geräte-Token stillschweigend erneuert.
6. **Step-up:** Sensible Aktionen (Export, Rollen, Einladungen, endgültig löschen, Geräte sperren) erfordern frische Bestätigung per Passkey (≤ 5 min alt). Der Steuerberater hat keine solchen Aktionen. *Gebaut als „Bitte bestätigen“ mit Passwort, bis es eine Passkey-Bestätigung gibt (D-029).*
7. **Geräteliste:** sichtbar, einzeln sperrbar; „Alle anderen Geräte abmelden“.
8. **Steuerberater:** meldet sich mit **Benutzername + Passwort** an – keine App, kein Handy, nichts zu installieren. Der Owner legt das Konto an (AK-4.6.4); Passwort zufällig erzeugt (≥ 20 Zeichen), einmal angezeigt, jederzeit zurücksetzbar (alte Sitzungen enden), vom Steuerberater änderbar. Argon2id-Hash, Rate Limit + vorübergehende Sperre nach Fehlversuchen, Sitzung 8 h **ohne** langlebiges Geräte-Token, Anmeldungen im Audit-Log. Widerruf der Freigabe/Mitgliedschaft beendet alle Sitzungen sofort. TOTP für den Steuerberater optional später (C) (D-012).

### 11.2 Bedrohungsmodell (Kurzfassung)

| Bedrohung | Gegenmaßnahme |
|-----------|---------------|
| Gestohlenes Handy | Gerät sperren per anderer Session; Token-Rotation; optional App-PIN (C) |
| Fremde Org-Daten lesen (IDOR) | Org-Scope-Middleware, Policies pro Ressource, ULIDs, Tests für jeden Endpoint |
| Bösartige Uploads (Polyglot, ZIP-Bombe, PDF-JS) | Magic-Bytes-Prüfung, Neukodierung von Bildern, Größen-/Seitenlimit, OCR-Tools im Worker mit Ressourcenlimits (timeout, ulimit), nie aus Webroot ausliefern, `Content-Disposition` + `X-Content-Type-Options: nosniff` |
| XSS über OCR-Text/Notizen | konsequentes Escaping in Templates, strikte CSP (kein inline-JS) |
| CSRF | Token für HTML-Formulare; `/api/*` gleiche Herkunft + eigener Header (D-017); Cookies SameSite=Lax (D-009) |
| Brute Force auf Login/Einladungen | Rate Limiting pro IP und Konto, vorübergehende Sperre nach Fehlversuchen beim Steuerberater-Login, lange zufällige Tokens und Passwörter |
| Shell-Injection in OCR-Aufrufen | Prozesse nur mit Argument-Arrays (`proc_open`/Symfony Process), keine Shell-Strings |
| Datenabfluss an Cloud-/KI-Dienste | Alle Verarbeitung lokal; nur Jobs der Queue `net` dürfen nach außen (Bank-Modul, SMTP); Architektur-Tests + CI-Lauf der `local`-Jobs ohne Netzwerk; auf eigenen Servern optional Firewall pro Worker-Benutzer (D-020) |
| Standortdaten verraten Bewegungsprofil | Opt-in, Koordinaten verschlüsselt, nach außen nur Ortsname, pro Beleg löschbar, nie in Logs; Ortsnamen-Ermittlung ohne Weitergabe an Dritte bevorzugt (D-017) |
| Diebstahl der Revolut-Zugangsdaten | nur Lesezugriff anfordern, verschlüsselt speichern, Widerruf in den Einstellungen, Audit-Log je Abruf |
| Verlust der Daten | dokumentierte Backups (DB + Storage), Restore-Test |

### 11.3 Weitere Maßnahmen

- Nur HTTPS, HSTS, sichere Header (CSP, Referrer-Policy, Permissions-Policy mit `camera=(self), geolocation=(self)`).
- **Öffentliches Repo:** Regeln in `AGENTS.md` („Public repository rules“). Testfotos ohne EXIF-Daten (keine echten GPS-Koordinaten!), keine echten Revolut-Exporte, keine Zugangsdaten.
- Verschlüsselung at rest: mindestens Volume-Verschlüsselung (Doku); optional App-seitig mit libsodium und Schlüssel aus `.env`/Secret-Datei (D-007). Schlüsselverlust = Datenverlust → Doku!
- Audit-Log append-only: Login, Kopplung, Upload, Ansicht durch Steuerberater, Änderungen, Exporte, Downloads, Rechteänderungen.
- DSGVO: Datenexport, Löschung (Papierkorb → endgültig), konfigurierbare Aufbewahrung; Hinweis, dass gesetzliche Aufbewahrungsfristen (z. B. 6/8/10 Jahre) in der Verantwortung der Nutzer liegen; IP-Adressen nur gehasht.
- Abhängigkeiten: `composer audit` und `npm audit` (falls npm) in CI; Dependabot/Renovate.

---

## 12. Betrieb & Deployment

**Eine App, eine Mindestanforderung (D-010).** docHamster läuft überall, wo die Mindestanforderungen unten erfüllt sind – eigener Server (VPS, Homeserver, NAS) oder Webhosting. Es gibt keine „Server-“ und „Hosting-Edition“ und **kein Docker** in v1 (ein Docker-Setup kann später als Komfort kommen, nie als Pflicht).

**Pflicht:** eine **feste Domain** mit öffentlich vertrauenswürdigem TLS-Zertifikat, aus dem Internet per HTTPS erreichbar. Grund: Passkeys hängen an der Domain (ein späterer Wechsel macht alle Passkeys ungültig), PWA/Service Worker brauchen einen sicheren Kontext, und das Handy sendet von überall. Die Domain wird vor P1-4 festgelegt und steht nie im Repo.

**Mindestanforderungen** – im README veröffentlicht, geprüft von `dochamster:doctor`:

| Bereich | Minimum | Für Zusatzfunktionen / empfohlen |
|---------|---------|-----------------------------------|
| Server | Linux-Server oder Webhosting mit SSH und Cron (jede Minute) | eigener Server für Texterkennung/Spracherkennung |
| CPU / RAM | 2 Kerne, 2 GB RAM (ohne DB) | 4 Kerne, 6 GB für lokale Spracherkennung |
| Speicher | 10 GB + Belege (≈ 2 MB pro Beleg) | SSD |
| PHP | 8.4 (laut `composer.json` heute 8.3) mit `pdo_mysql`, `mbstring`, `intl`, `sodium`, `fileinfo`, `exif`, `zip`, `gd`; `memory_limit` ≥ 256 MB; `upload_max_filesize`/`post_max_size` ≥ 26 MB | `vips`-Erweiterung; `proc_open` erlaubt (nötig für alle Zusatzfunktionen) |
| Datenbank | MariaDB 10.11 oder MySQL 8.4 (D-003) | neueste LTS |
| Netzwerk | eigene Domain, vertrauenswürdiges TLS, eingehend HTTPS; ausgehend HTTPS zur Bank-API (falls Modul), SMTP | – |
| Zusatz-Tools | – | Tesseract 5, ocrmypdf, ffmpeg, whisper.cpp, libheif |
| Handy | aktuelles iOS Safari / Android Chrome (letzte 2 Hauptversionen) | als Home-Bildschirm-App installiert |

Werte sind Schätzungen und werden in Phase 1 auf dem Testserver des PO geprüft (dessen Details bleiben außerhalb des Repos).

**Kern und Zusatzfunktionen:** Der Kern läuft immer: Erfassen + Offline-Queue, Zeit/Ort, Bank-Import und Abgleich, Belegpaket-PDF (reines PHP), Suche, Steuerberater-Zugang, Mail. Zusatzfunktionen nur, wenn die Umgebung sie hat: Texterkennung (Tesseract + ocrmypdf), lokale Spracherkennung (whisper.cpp + ffmpeg, D-019), HEIC-Konvertierung (libheif/vips), schnellere Bildverarbeitung (vips statt GD). `dochamster:doctor` (bei der Einrichtung, nach Updates und auf der Admin-Seite) prüft PHP-Version und -Erweiterungen, DB-Version, Cron-Lebenszeichen, HTTPS, Upload-Limits, `proc_open` und die Zusatz-Tools; fehlt etwas, wird die Zusatzfunktion nicht angeboten bzw. ausgegraut mit Grund („Texterkennung braucht Tesseract – auf diesem Server nicht vorhanden“).

**Datendateien:** GeoNames-Ortsnamen (D-017) lädt der Setup-/Update-Befehl (feste Version + Prüfsumme), Tesseract-Sprachdaten und das Whisper-Modell der Installationsschritt der Zusatzfunktion. Während der Verarbeitung von Belegen wird nichts heruntergeladen.

Betriebsthemen (alle in `docs/operations/` zu dokumentieren):
- Installation: **Standard ist der Setup-Assistent im Browser** (D-024, §4.9) – Dateien hochladen, Adresse öffnen, Schritt für Schritt einrichten. Alternativ über die Shell: Code klonen, `composer setup` bzw. `php artisan dochamster:setup` (fragt dieselben Dinge ab), Cron-Eintrag. Anleitungen für einen normalen Linux-Server (Debian/Ubuntu) und für typisches Webhosting.
- Worker: Cron jede Minute (`schedule:run` startet kurze `queue:work --stop-when-empty`-Läufe); Dauer-Worker (systemd/Supervisor) optional auf eigenen Servern.
- Backups (D-023, D-028): für Nutzer „Backup herunterladen“ in den Einstellungen (§4.8, funktioniert überall, **gebaut**); für Betreiber `php artisan dochamster:backup` (*noch nicht gebaut*) – konsistenter DB-Dump (`--single-transaction`, ohne Ausfallzeit) + Storage-Ordner, per Cron planbar, braucht `proc_open` und das Dump-Programm (fehlt es, zeigt `dochamster:doctor` „Server-Backup nicht verfügbar – nutze die Datenbank-Sicherung deines Hosters“). Wiederherstellung eines Voll-Backups mit `dochamster:restore` in eine leere Instanz. Hinweis: der App-Schlüssel (`.env`) muss getrennt aufbewahrt werden, ohne ihn sind verschlüsselte Felder unlesbar.
- Updates: `git pull` bzw. neue Version hochladen → `composer install` → `migrate` → `dochamster:doctor`; Migrationen immer vorwärtskompatibel.
- Monitoring: Health-Endpoint (`/health` – DB, Storage schreibbar, Queue-Länge, letzte Worker-Aktivität). *(gebaut, dazu `/ping`)*
- Updates aus der App (Release-Pakete, Vorschau-Pakete von `dev`) und eine Docker-Probierumgebung zum Ausprobieren (`TRY.md`, kein Betriebsweg).
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
- **Stand 2026-11-14:** Tests nur für Rechenlogik (OCR/Rechnungslesen, Abgleich, Import-Parser, Beträge) und Sicherheitsregeln (AGENTS.md); kein Playwright; Abdeckungsziel entfällt.
- **CI (GitHub Actions):** Lint, PHPStan, Tests auf **MariaDB 10.11 und MySQL 8.4** (D-003), Architektur-Tests für die Egress-Regel und `local`-Jobs ohne Netzwerk (D-020), Composer-Audit, Erkennungsquote-Report.
- **Definition of Done (ursprünglich; heute: AGENTS.md „How we work“):** Akzeptanzkriterien erfüllt · Tests grün · keine neuen PHPStan-Fehler · UI-Texte über i18n · Doku/CHANGELOG aktualisiert · Review durch die jeweils andere KI oder PO · Eintrag in `docs/collaboration/LOG.md`.
- **UX-Regeln:** Texte kurz und aus Nutzersicht („Beleg gespeichert“, nicht „HTTP 201“); Barrierefreiheit (Kontraste, Labels, Tastatur), Dark Mode, Touch-Ziele ≥ 48 px.

---

## 14. Umsetzungsplan: Phasen & Arbeitspakete

> **Stand 2026-11-14 (D-035):** Gearbeitet wird nicht mehr in Phasen-Paketen, sondern in nummerierten PO-Punkten auf dem Zweig `dev`; Releases sind Patch-Tags `v0.1.x` nach Fast-Forward von `main`. Erledigt aus den Phasen: P1-1…P1-3, P1-6, P1-7, P1-9, P1-10 (Grundlagen), P2-0, P2-1, P2-7, P3-2, P3-3, P3-5, P3-6, P4-1 (ohne Belegnummer), P4-2, P4-3 (ZIP), P4-5, P5-2 (Tesseract + Browser), P6-1…P6-3, P6-5, P7-1…P7-3, P8-5 (nur `dochamster:restore`). Offen bzw. anders: Passkeys/Kopplung (P1-4, P1-5), Offline-Queue (P2-4), Standort (P2-5), Revolut-Modul (P3-4), E-Mail-Versand (P4-4), Regeln/Duplikate/Sprache (P5-4, P5-6, P5-7).

Jede Phase endet mit einem **lauffähigen Stand**, einem Git-Tag (`v0.N.0`) und einer kurzen manuellen Testliste. Arbeitspakete (AP) haben IDs, damit zwei KIs sie parallel übernehmen können. **Parallelisierbar** = keine gegenseitige Abhängigkeit innerhalb der Phase. Laufender Status der Pakete: [`collaboration/TASKS.md`](collaboration/TASKS.md).

### Phase 0 – Entscheidungen & Projekt-Setup (jetzt)

| AP | Inhalt | Ergebnis |
|----|--------|----------|
| P0-1 | Plan v1 reviewen (beide KIs + PO) | kommentierter Plan, offene Punkte in DECISIONS.md |
| P0-2 | Entscheidungen D-001…D-010 treffen | DECISIONS.md: alle „Must-before-code“ entschieden |
| P0-3 | Repo-Grundlagen: README, LICENSE, AGENTS.md, Workflow, Templates | ✅ angelegt (2026-10-29) |
| P0-4 | Beispiel-Fixtures sammeln: 30 anonymisierte Belege, 3–5 echte Kontoauszug-CSV/XLSX (anonymisiert) | `tests/fixtures/` (durch PO) |
| P0-5 | UI-Skizzen der Hauptscreens (Erfassen, Abgleich, Belegpaket-Layout, Liste, Detail, Einstellungen) | `docs/design/` |

### Phase 1 – Fundament (v0.1)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P1-1 | Projektgerüst (Laravel), Config, `.env.example`, Setup-Befehl (ohne Docker, D-010), CI-Grundgerüst | – | – |
| P1-2 | Migrationen: users, organizations, memberships, devices, passkeys, invitations, audit_log | P1-1 | mit P1-3 |
| P1-3 | Layout, CSS-Basis (Light/Dark), i18n-Mechanismus, Fehlerseiten | P1-1 | mit P1-2 |
| P1-4 | Setup-CLI, Einladung, Kopplung per Link/QR **und per Kopplungscode in der installierten App** (B14), Passkey-Registrierung/Login | P1-2 | – |
| P1-5 | Geräte-Token mit Rotation, Geräteliste, Sperren, Step-up | P1-4 | – |
| P1-6 | Org-Scope-Middleware, Rollen/Policies, Rechte-Matrix-Testgerüst | P1-2 | mit P1-4 |
| P1-7 | Security-Header, CSRF, Rate Limiting, Health-Endpoint | P1-1 | mit P1-2…6 |
| P1-8 | README-Installation mit Mindestanforderungen (D-010), HTTPS-Doku (feste Domain + öffentlich vertrauenswürdiges Zertifikat), Backup-Doku v1 | P1-1 | ja |
| P1-9 | Setup-Assistent im Browser, Grundgerüst (D-024): Absicherung der frischen Instanz, Server-Prüfung, Datenbank einrichten, Sperre nach Abschluss, Fortsetzen nach Abbruch; ggf. Release-Paket ohne Composer | P1-1, P1-7, Q-12 | mit P1-10 |
| P1-10 | Shell-Installation interaktiv: dieselben Schritte im Terminal, Datenbank-Zugangsdaten abfragen (D-024, AK-4.9.7) | P1-1, Q-12 | mit P1-9 |
| P1-11 | Setup-Assistent: Owner-Konto, E-Mail, Cron-Prüfung, Handy verbinden + App auf den Home-Bildschirm, erste Schritte | P1-4, P1-9 | – |

**Testliste v0.1:** Instanz über den Setup-Assistenten im Browser einrichten (ohne Shell) · dasselbe über die Shell · Owner koppeln per Passkey · zweites Gerät per QR koppeln · Gerät sperren → ausgesperrt · Mitglied einladen · Mitglied sieht andere Org nicht.

### Phase 2 – Erfassen mit Zeit & Ort (v0.2)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P2-0 | Job-Queue + Worker (Cron-Modus, Dauer-Worker optional), Queues `local`/`net` (D-020), Retry, Fehlerstatus (Grundlage – Queue-Namen und Cron-Worker – schon in P1-1) – früher P3-1, vorgezogen, weil Thumbnails (P2-7) und Ortsnamen (P2-5) schon Hintergrund-Jobs brauchen (B3) | P1 | mit P2-1, P2-2 |
| P2-1 | Storage-Service (außerhalb Webroot, Pfadschema, optional Verschlüsselung), `document_files` | P1 | mit P2-2 |
| P2-2 | PWA: Manifest, Service Worker, Installierbarkeit, Kamera-Screen, Mehrseiten-Bündelung; Vergleich der Kamera-Methoden auf echten Geräten (B5) | P1-3 | mit P2-1 |
| P2-3 | Upload-API pro Datei + Abschluss (§7, D-017), idempotent (`client_upload_id`), Validierung, Limits | P2-1 | – |
| P2-4 | Offline-Queue (IndexedDB), Senden beim Öffnen/`online`/`visibilitychange`, Background Sync als Zusatz, `storage.persist()`, Fortsetzen, Badge (B15) | P2-2, P2-3 | – |
| P2-5 | Aufnahmezeit + Standort (Geolocation, Opt-in, EXIF-Fallback), `document_captures`, Ortsnamen (D-017) | P2-0, P2-3 | mit P2-6 |
| P2-6 | Zusatz-Schritt: Notiz, Kategorie-/Zahlungsart-Chips; Stammdaten | P2-3 | mit P2-5 |
| P2-7 | Geschützte Datei-Auslieferung (`/files/{id}`), Thumbnails, Ausrichtung | P2-0, P2-1 | ja |
| P2-8 | Sprachnotiz aufnehmen und abspielen (S) | P2-3 | ja |

**Testliste v0.2:** Foto in ≤ 2 Taps · Zeit + Ort gespeichert, Ort abschaltbar · Flugmodus → 3 Belege → online → alle da, Aufnahmezeit korrekt, keine Duplikate · PDF aus Dateien · fremde Datei-ID → 403/404.

### Phase 3 – Bank-Import & Abgleich (v0.3)

Der CSV-Import für jede Bank ist Kern und kommt zuerst. Die Revolut-Live-Anbindung entsteht als nachinstallierbares Modul in einem eigenen Repo, mit Einrichtungs-Assistent (D-021); Technik beim **Lead** (D-016).

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P3-1 | *entfällt – als P2-0 nach Phase 2 vorgezogen (B3)* | – | – |
| P3-2 | Bankkonten, `transactions`-Modell inkl. Zeitpunkt, Originalwährung, Gebühr, Status | P1 | mit P2 |
| P3-3 | CSV/XLSX-Import für **jede Bank** mit Import-Assistent und Profilen, Duplikatschutz per Fingerprint; Test mit Exporten von ≥ 5 Banken | P3-2 | mit P3-4 |
| P3-4a | `BankConnector`-Schnittstelle + Modul-Mechanismus (installieren mit einem Befehl, ein-/ausschalten) im Kern | P2-0, P3-2 | mit P3-3 |
| P3-4b | Revolut-Business-Modul im eigenen Repo (D-016/D-021): Einrichtungs-Assistent (AK-4.4.R5), Duplikatschutz über `external_id`, `booked_at` aus `created_at` + erneuter Abruf bis zum Endstatus (B7), `raw_json` ohne Karteninhaber-Daten (B8), nur `scope=READ` (B9), Tests gegen Revolut-Sandbox | P3-4a, P0-10 | – |
| P3-5 | Matching-Engine mit Zeitnähe-Score + Gründen, Kalibrierung mit Testdaten | P3-3, P2-5 | – |
| P3-6 | Abgleich-UI (Buchungen ohne Beleg · Belege ohne Buchung · Paare, 1-Tap-Bestätigung, „kein Beleg nötig“) | P3-5 | – |
| P3-7 | (S) „Passt zu …?“ direkt nach dem Foto | P3-5 | ja |

**Testliste v0.3:** CSV einer fremden Bank per Assistent importiert → Buchungen da · ohne Revolut-Modul läuft alles · Modul mit einem Befehl installiert · Revolut-Einrichtung per Assistent in ≤ 10 min · Kartenzahlung → Foto 2 min später → automatisch verknüpft · Fremdwährung korrekt · erneuter Abruf ohne Duplikate · Rückerstattung als eigene Zeile.

### Phase 4 – Belegpaket für den Steuerberater (v0.4 = Kernablauf fertig)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P4-1 | Belegnummern, Abfragen für Zeitraum/Konto | P3 | – |
| P4-2 | Belegpaket-PDF nach §10.1 (Gestaltung aus P0-5, PO-Abnahme), Fotos vorverkleinert; Benchmark mit 150 Seiten nach B12 (§5.2) | P4-1 | mit P4-3 |
| P4-3 | ZIP (Originale + `index.csv`), CSV/XLSX-Übersicht, asynchron, Download-Links | P4-1 | mit P4-2 |
| P4-4 | Versand per E-Mail-Link, Benachrichtigung | P4-3 | ja |
| P4-5 | „Backup herunterladen“ (§4.8, D-023): Auswahl, Größenschätzung, Stream bis 500 MB bzw. Hintergrund-Job, optional mit Passwort; nutzt den ZIP-Code aus P4-3 | P4-3 | mit P4-4 |

**Testliste v0.4:** Monat wählen → Paket in ≤ 3 Klicks · jede Buchung mit Foto · fehlende Belege markiert · Ort nur wenn eingeschaltet, nur Stadt · Link läuft ab.

### Phase 5 – Texterkennung & Ordnung (v0.5)

OCR ist jetzt Unterstützung, nicht Voraussetzung: Sie liefert Betrag/Händler als Bestätigung für den Abgleich, Belege ohne Kartenzahlung (bar, andere Banken) und die Volltextsuche.

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P5-1 | NormalizeFiles (HEIC, Re-Encode, EXIF entfernen in abgeleiteten Dateien) | P2-0 | mit P5-2 |
| P5-2 | RunOcr (Tesseract, pdftotext, ocrmypdf) mit Ressourcenlimits | P2-0 | mit P5-1 |
| P5-3 | ExtractFields + `document_fields` + Fixture-Benchmark in CI; Betrag fließt in den Score | P5-2 | – |
| P5-4 | Regel-Engine, Händler-Stammdaten, Hashtags | P5-3 | mit P5-5 |
| P5-5 | Volltextindex (MariaDB/MySQL `FULLTEXT` hinter `SearchIndex`, D-003) | P5-2 | mit P5-4 |
| P5-6 | Duplikaterkennung | P5-3 | ja |
| P5-7 | Speech-to-Text lokal (Engine nach Lead-Entscheidung D-019), Transkript in Suche | P2-0, P2-8 | ja |

### Phase 6 – Wiederfinden (v0.6)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P6-1 | Liste (Kacheln/Tabelle), Paginierung | P2 | mit P6-2 |
| P6-2 | Suche + Filter | P5-5 | mit P6-1 |
| P6-3 | Detailansicht mit Viewer, Bearbeiten, Verlauf, Ort | P6-1 | – |
| P6-4 | Prüfen-Modus, „Immer so zuordnen?“-Lernen | P6-3, P5-4 | – |
| P6-5 | Papierkorb, Wiederherstellen, endgültig löschen (Step-up) | P6-3 | ja |

### Phase 7 – Steuerberater-Zugang (v0.7)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P7-1 | Freigaben (`shares`) mit Umfang + Ablauf; Steuerberater-Konto mit Benutzername + Passwort, vom Owner angelegt (D-012) | P1-6, P4 | – |
| P7-2 | Read-only-Oberfläche (Belegpakete, Liste, Detail, Download), alle Schreibwege gesperrt | P7-1 | – |
| P7-3 | Widerruf sofort wirksam, Audit-Ansicht für Owner | P7-1 | ja |
| P7-4 | Review der gesamten Rechte-Matrix (Manager prüft gegen Plan, Lead technisch) | P7-2 | – |

### Phase 8 – Feinschliff & Release (v1.0)

| AP | Inhalt |
|----|--------|
| P8-0 | (C) Lokale Vorschläge für Händler/Kategorie (`LocalSuggest`), opt-in |
| P8-1 | Performance-Test 10.000 Belege, Optimierungen |
| P8-2 | Barrierefreiheit-/UX-Review, Texte |
| P8-3 | Sicherheits-Check vor Veröffentlichung, History-Scan (P0-8), Lizenz-Check aller Abhängigkeiten (D-022), Revolut-Details aus den Kern-Docs ins Modul-Repo (D-021) |
| P8-4 | Doku komplett (Nutzer, Betrieb, Entwicklung), CHANGELOG, Release v1.0 |
| P8-5 | Wiederherstellen eines Voll-Backups (`dochamster:restore`, ZIP als nicht vertrauenswürdig geprüft) und Server-Backup `dochamster:backup` (D-023) |

### Grobe Reihenfolge

```
P0 ─▶ P1 ─▶ P2 ─▶ P3 ─▶ P4  ══ Kernablauf fertig (v0.4) ══▶ P5 ─▶ P6 ─▶ P7 ─▶ P8
            └─ P3-2 kann parallel zu P2 starten (die Job-Queue ist P2-0, B3)
```
Ab v0.4 ist der wichtigste Weg nutzbar: fotografieren → Revolut-Abgleich → Belegpaket an den Steuerberater.

---

## 15. Risiken

| Risiko | Wahrscheinl. | Auswirkung | Gegenmaßnahme |
|--------|:-:|:-:|---------------|
| OCR-Qualität bei zerknitterten Thermobons schlecht | hoch | mittel | Abgleich primär über Zeitpunkt + Revolut-Daten statt OCR; Vorverarbeitung; schnelle manuelle Korrektur |
| Revolut-Zugang nicht oder nur eingeschränkt per API möglich (z. B. Privatkonto) | mittel | hoch | Datei-Import als immer verfügbarer Fallback; Lead klärt Weg früh (D-016) |
| Geräteuhr falsch / Standort ungenau | mittel | mittel | EXIF- und Server-Zeit gegenprüfen, Zeitfenster großzügig, 1-Tap-Bestätigung |
| Hosting ohne Tesseract | mittel | hoch | Texterkennung ist Zusatzfunktion; der Kernablauf (Foto → Abgleich → Belegpaket) läuft ohne; `dochamster:doctor` zeigt, was fehlt (D-010) |
| Domain wird nach dem Start geändert | niedrig | hoch | Passkeys hängen an der Domain → feste Domain mit vertrauenswürdigem Zertifikat ist Mindestanforderung, vor P1-4 festlegen (D-010) |
| iOS-PWA-Einschränkungen (Background Sync fehlt, Speicher-Eviction, eigener Cookie-Speicher der installierten App) | hoch | mittel | Queue beim Öffnen/`online`/`visibilitychange` senden, `storage.persist()`, Installation empfehlen (B15); Kopplung per Code in der installierten App (B14); deutlicher Hinweis bei ausstehenden Uploads |
| Bank-CSV-Formate ändern sich / sind uneinheitlich | hoch | mittel | Profile frei konfigurierbar, Vorlagen nur als Startpunkt |
| Passkey-UX verwirrt Nutzer | mittel | mittel | Owner erzeugt neuen Kopplungslink; Steuerberater ist nicht betroffen (Benutzername + Passwort, D-012); klare Texte, Testpersonen |
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
| Posteingang | Ort, an dem neue Dokumente ohne Ordner warten, bis sie abgelegt oder „erledigt“ sind |
| Belegdatum | Datum eines Dokuments: Rechnungsdatum, sonst EXIF-/PDF-Datum, sonst Upload-Tag (D-030) |
| Eigenschaft | Typisiertes Feld an einem Dokument (Rechnungsdatum, Betrag, IBAN …) |
| Geräteschlüssel | Schlüssel, mit dem ein Handy-Kurzbefehl oder Programm nur hochladen darf |
| Bitte bestätigen | Frische Bestätigung (5 min) vor sensiblen Aktionen, wie ein „Sudo-Modus“ (D-029) |
