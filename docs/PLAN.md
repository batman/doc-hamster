# DocHamster – Detaillierter Projektplan

> **Status:** Planungsphase (v2-Entwurf in Arbeit, 2026-10-29). Noch kein Code.
> **v2, Teil 1 (P0-1c):** eingearbeitet, was bereits freigegeben ist – PLAN-REVIEW B11 (MariaDB/MySQL), B18 (Anmeldung nach Rolle), B19 (Händlerkategorie/MCC, Status), B21 + B1 (ohne Docker, Mindestanforderungen, feste Domain) sowie die entschiedenen D-001, D-002, D-004, D-009, D-010, D-012, D-017 (B4, B6, B20) und D-020 (B2). Offen für Teil 2: Punkte, die noch auf eine Entscheidung warten (u. a. B3, B5, B7–B9, B12–B16).
> **v1.1:** PO-Entscheidungen eingearbeitet – Handy-Web-App statt Scanner (D-015), Aufnahmezeit + Standort (D-017), CSV-Import für jede Bank im Kern + Revolut Business als nachinstallierbares Modul mit einfacher Einrichtung (D-016, D-021); Lizenzregel für Fremdsoftware vorgeschlagen (D-022), Belegpaket für den Steuerberater (D-018), alles lokal ohne Cloud-KI inkl. lokaler Spracherkennung (D-019). Phasen neu geordnet um den Kernablauf (§14).
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

**Lösung:** Eine installierbare Web-App (PWA), die im Alltag genau eine Sache schnell macht (Erfassen mit der Handykamera, inkl. Aufnahmezeit und Standort), und auf dem Server alles Weitere erledigt (Abgleich mit Revolut-Buchungen, OCR, Tags, Kategorien, Berichte).

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
| Mitglieder einladen, Steuerberater-Konto anlegen, Zugänge entziehen | ✅ (mit Bestätigung) | ❌ | ❌ |
| Kategorien/Regeln verwalten | ✅ | ❌ | ❌ |
| Audit-Log sehen | ✅ | ❌ | ❌ |

Legende: ✅ erlaubt · ❌ verboten · ⚙️ per Org-Einstellung · 🔶 eingeschränkt durch Freigabe (`shares`).

**Freigabe-Modell Steuerberater:** Eine Freigabe (`share`) gilt für eine Organisation und optional eingeschränkt auf Zeitraum (`from`/`to`), Kategorien oder Bankkonten, optional mit Ablaufdatum. Ohne Freigabe sieht der Steuerberater nichts.

---

## 3. Kernprinzipien

1. **Geschwindigkeit vor Vollständigkeit.** Upload ohne Pflichtfelder, ohne Rückfragen. Alles Weitere ist optional und nachträglich möglich.
2. **Mobile first.** Daumen-bedienbar, große Ziele (≥ 48 px), Kamera zuerst.
3. **Einmal koppeln, dann in Ruhe lassen.** Kein Passwort im Alltag, trotzdem sicher (Geräte-Token + Passkey). Ausnahme: der Steuerberater meldet sich mit Benutzername + Passwort an, ohne etwas zu installieren (D-012).
4. **Server macht die Arbeit.** OCR, Tags, Kategorien, Abgleich laufen asynchron.
5. **Privat: alles lokal.** Spracherkennung, Texterkennung und Vorschläge laufen auf dem eigenen Server. Keine Daten an Cloud- oder KI-Anbieter. Einzige ausgehende Verbindungen: Revolut-API und E-Mail-Versand.
6. **Originale sind heilig.** Hochgeladene Dateien werden nie verändert; abgeleitete Versionen sind separat.
7. **Einfach zu hosten.** Läuft auf jedem Server oder Webhosting mit PHP 8.4 + MariaDB/MySQL, ohne Docker; Zusatzfunktionen (Texterkennung, Spracherkennung, HEIC) je nach Umgebung; keine externen Pflichtdienste (D-010).
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
- [ ] AK-4.1.1: Ein Foto ist mit 2 Taps (Kamera, Senden) gespeichert.
- [ ] AK-4.1.2: Bis zu 20 Seiten können zu einem Dokument gebündelt werden.
- [ ] AK-4.1.3: Ohne Netz wird der Upload in IndexedDB gepuffert und automatisch gesendet, sobald Netz da ist (Background Sync, Fallback: beim nächsten Öffnen). Die Warteschlange ist sichtbar (Badge mit Anzahl).
- [ ] AK-4.1.4: Ein abgebrochener Upload (App geschlossen) wird beim nächsten Öffnen fortgesetzt. Hochgeladen wird **pro Datei/Seite**; bereits übertragene Seiten werden nicht erneut gesendet (D-017).
- [ ] AK-4.1.5: Doppeltes Senden desselben Uploads erzeugt kein Duplikat (Client-seitige Upload-UUID, Server idempotent).
- [ ] AK-4.1.6: Erlaubte Formate: JPEG, PNG, HEIC/HEIF (Server-Konvertierung als Zusatzfunktion, nur wenn die Umgebung sie hat – D-010), WebP, PDF. Max. 25 MB pro Datei (konfigurierbar).
- [ ] AK-4.1.7: Bilder werden vor dem Upload clientseitig auf max. 3000 px lange Kante verkleinert (konfigurierbar, Original optional); EXIF-Zeit (und GPS, falls vorhanden) wird vorher ausgelesen und als Aufnahme-Metadaten mitgeschickt.
- [ ] AK-4.1.8: Teilen-Ziel: In der installierten PWA kann man aus anderen Apps „Teilen → DocHamster“ (Web Share Target, S).
- [ ] AK-4.1.9: Jeder Beleg hat einen Aufnahmezeitpunkt mit Quelle (`device`/`exif`/`server`); Offline-Uploads behalten den Zeitpunkt der Aufnahme, nicht den des Sendens.
- [ ] AK-4.1.10: Mit erteilter Erlaubnis wird der Standort beim Auslösen gespeichert (inkl. Genauigkeit); das Warten auf GPS verzögert das Speichern nicht (max. 3 s, sonst ohne Standort bzw. nachgereicht).
- [ ] AK-4.1.11: Ortsname (Stadt, Land) ist in der Detailansicht sichtbar; Standort pro Beleg löschbar.
- [ ] AK-4.1.12 (S): Ist die Revolut-Live-Anbindung aktiv, zeigt die App nach dem Speichern „Passt zu: *Händler* *Betrag* vor *n* min?“ zur 1-Tap-Bestätigung.

**C – später:** E-Mail-Postfach (Online-Rechnungen per Mail weiterleiten).

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

### 4.4 Kontoauszug-Import für jede Bank, Revolut-Anbindung & Abgleich – M

Details in [Kapitel 9](#9-kontoauszug-import--abgleich).

**Aufteilung (D-021):**

| Teil | Wo | Für wen |
|------|----|---------|
| CSV/Excel-Import für **jede Bank**, Import-Profile, Abgleich, Belegpaket | Kern (dieses Repo), immer dabei | alle Nutzer |
| `BankConnector`-Schnittstelle für Live-Anbindungen | Kern | Bank-Module |
| Revolut-Business-Modul | **eigenes öffentliches Repo**, bei Bedarf mit einem Befehl nachinstalliert | wer Revolut Business hat, verbindet sein eigenes Konto |

DocHamster muss **ohne** Revolut-Modul vollständig funktionieren: fotografieren → CSV einer beliebigen Bank importieren → abgleichen → Belegpaket. Der Kern enthält keinen Revolut-Code. Im Code stehen nirgends Zugangsdaten; jede Installation speichert ihre eigenen Revolut-Schlüssel verschlüsselt auf ihrem Server.

**Revolut live (nachinstallierbares Modul, D-016/D-021).** Ziel: zu jeder Revolut-Kartenzahlung die vollständigen Buchungsdetails – Händler, Betrag, Währung (inkl. Originalwährung und Kurs bei Fremdwährung), genauer Zeitpunkt, Händlerkategorie (MCC, als deutscher Text), Status, Händlerort falls verfügbar – und das passende Foto dazu.

Ziel-Kontotyp: **Revolut Business** (PO, Q-7) → die offizielle Business API ist der erwartete Hauptweg. Technische Umsetzung entscheidet der Lead:
1. **Revolut Business API** – offizielle API mit Transaktionsabruf (nur Business-Konten). Automatischer, regelmäßiger Abruf.
2. **Revolut-Kontoauszug als Datei** (CSV/Excel aus der App/Web) – funktioniert für Privat und Business, manueller Upload z. B. monatlich. **Immer als Fallback vorhanden.**
3. ~~Open-Banking-Dienst für Privatkonten~~ – in v1 nicht nötig (Business-Konto).

- [ ] AK-4.4.R1: Revolut-Buchungen (Weg 1 oder 2) landen mit Zeitpunkt (Datum + Uhrzeit), Händler, Betrag, Währung, ggf. Originalbetrag/Kurs, Händlerkategorie (MCC – die Revolut-API liefert keine eigene Ausgabenkategorie) und Status in `transactions`. Die MCC wird über eine Tabelle MCC → deutscher Text angezeigt (z. B. „Tankstelle“, „Restaurant“).
- [ ] AK-4.4.R2: Nur abgeschlossene Buchungen werden abgeglichen; ausstehende/zurückgebuchte werden angezeigt, aber nicht ins Belegpaket übernommen (Rückerstattungen als eigene Zeile); abgelehnte/fehlgeschlagene (`declined`/`failed`) werden nie abgeglichen und nie ins Belegpaket übernommen.
- [ ] AK-4.4.R3: Wiederholter Abruf/Import erzeugt keine Duplikate (Revolut-Transaktions-ID bzw. Fingerprint).
- [ ] AK-4.4.R4: API-Zugangsdaten liegen verschlüsselt auf dem Server, nie im Repo; Verbindung in den Einstellungen trennbar.
- [ ] AK-4.4.R5: **Einfache Einrichtung:** Ein Assistent führt Schritt für Schritt durch die Verbindung (Zertifikat erzeugen → in Revolut Business eintragen → Client-ID einfügen → Zustimmung → Verbindung testen → erster Import). Ziel: in ≤ 10 min ohne Doku, verständliche deutsche Texte und Fehlermeldungen.
- [ ] AK-4.4.R6: Das Modul wird mit **einem** Befehl installiert (nicht über die Web-Oberfläche) und lässt sich danach in den Einstellungen ein- und ausschalten; nicht installiert oder ausgeschaltet hat es keinerlei Wirkung (keine Jobs, keine Netzwerkverbindungen).
- [ ] AK-4.4.R7: Kein Revolut-Logo, keine Revolut-Farben, keine kopierten Revolut-Texte; Hinweis „nicht mit Revolut verbunden“ im Modul.

**Jede Bank per Datei-Import (öffentlicher Kern, Must):**

- [ ] AK-4.4.1: Import von CSV (beliebiges Trennzeichen, Encoding UTF-8/ISO-8859-1/Windows-1252) und XLSX.
- [ ] AK-4.4.2: Spaltenzuordnung per Assistent, als Profil pro Bankkonto speicherbar; bekannte Banken als Vorlagen (S).
- [ ] AK-4.4.3: Erneuter Import überlappender Zeiträume erzeugt keine doppelten Buchungen.
- [ ] AK-4.4.4: Automatischer Abgleich mit Score; Vorschläge über Schwelle werden auto-verknüpft (als „automatisch“ markiert), darunter als Vorschlag angezeigt.
- [ ] AK-4.4.5: Drei Ansichten: Buchungen ohne Beleg · Belege ohne Buchung · Zugeordnete Paare. Manuelle Zuordnung per Klick bzw. Drag & Drop (Desktop).
- [ ] AK-4.4.6: Eine Buchung kann mehreren Belegen zugeordnet werden (Sammelbuchung) und umgekehrt (Teilzahlungen) – S.
- [ ] AK-4.4.7: Buchung als „braucht keinen Beleg“ markierbar (z. B. Gehalt, Umbuchung) mit Grund.
- [ ] AK-4.4.8: Eine CSV einer bisher unbekannten Bank lässt sich ohne Programmierung importieren (Assistent: Spalten zuordnen, Datums-/Zahlenformat, Vorzeichen) – geprüft mit Exporten von mindestens 5 verschiedenen Banken.
- [ ] AK-4.4.9: Enthält die CSV eine Uhrzeit, wird sie für den Abgleich genutzt; ohne Uhrzeit funktioniert der Abgleich über Datum, Betrag und Händler (mehr 1-Tap-Bestätigungen).

**S – später:** Import von CAMT.053 (XML) und MT940.

### 4.5 Belegpaket für den Steuerberater, Berichte & Export – M

Details in [Kapitel 10](#10-berichte--export).

**User Story:** Als Owner will ich für einen Monat/ein Quartal mit wenigen Klicks ein schönes, übersichtliches Dokument erzeugen, in dem jede Buchung mit ihrem Belegfoto steht, und es meinem Steuerberater schicken.

- [ ] AK-4.5.B1: „Belegpaket erstellen“: Zeitraum + Konto wählen → Vorschau → PDF. Höchstens 3 Klicks (E6).
- [ ] AK-4.5.B2: Das PDF enthält Deckblatt, Übersichtstabelle und pro Buchung eine Seite mit Buchungsdetails **und** dem Belegfoto (Aufbau §10.1).
- [ ] AK-4.5.B3: Fehlende Belege sind deutlich markiert und am Ende gesammelt aufgelistet.
- [ ] AK-4.5.B4: Standort erscheint im Paket nur, wenn der Owner es einschaltet, und dann nur als Ortsname (Stadt), nie als Koordinaten.
- [ ] AK-4.5.B5: Versand: Download, E-Mail mit Link (Ablaufdatum) oder Ablage im Steuerberater-Zugang (§4.6).
- [ ] AK-4.5.B6: Ein erstelltes Paket ist unveränderlich (Versionsnummer, Erstellzeitpunkt); Änderungen ergeben ein neues Paket.

- [ ] AK-4.5.1: Bericht für Zeitraum + Konto(en): alle Buchungen mit verknüpften Belegen, fehlende Belege rot markiert, Summen.
- [ ] AK-4.5.2: Export als PDF, CSV, XLSX und ZIP (Belegdateien, sortiert nach `JJJJ-MM/Kategorie/`, Dateiname `JJJJ-MM-TT_Händler_Betrag.ext`, plus `index.csv`).
- [ ] AK-4.5.3: Erstellung großer Exporte asynchron (Job) mit Benachrichtigung, Download-Link mit Ablaufdatum (Standard 7 Tage).
- [ ] AK-4.5.4: Versand per E-Mail (SMTP) als Link, nie als großer Anhang (Anhang nur ≤ 10 MB, optional).

### 4.6 Benutzer, Organisationen, Einladungen – M

- [ ] AK-4.6.1: Eine Instanz hat mehrere Organisationen; jede Person kann Mitglied mehrerer Organisationen sein (Org-Wechsler).
- [ ] AK-4.6.2: Daten sind strikt pro Organisation getrennt (jede Abfrage ist org-gefiltert; automatisierter Test pro Repository/Endpoint).
- [ ] AK-4.6.3: Einladung per Link/QR-Code mit Rolle, einmalig nutzbar, Ablauf 7 Tage.
- [ ] AK-4.6.4: Steuerberater-Zugang: Der Owner legt das Konto an (Benutzername, Freigabe-Umfang: Zeitraum, Konten, Kategorien, optional Ablaufdatum). Die App erzeugt ein starkes Zufallspasswort (≥ 20 Zeichen) und zeigt es dem Owner **einmal** zum Weitergeben. Keine Selbstregistrierung. Passwort jederzeit zurücksetzbar, Zugang jederzeit widerrufbar; beides wirkt sofort (laufende Sitzungen enden) (D-012).

### 4.7 Sprachnotizen & Spracherkennung (lokal) – S

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
- Datenexport (DSGVO Art. 20) und Konto löschen.

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

| Bereich | Vorschlag | Alternative | Entscheidung |
|---------|-----------|-------------|--------------|
| Sprache | **PHP 8.4** (Minimum) | – | D-002 ✅ |
| Framework | **Laravel**, aktuelle stabile Version, in `composer.json` festgelegt; Blade-Templates; kein Livewire/Inertia/SPA, keine Laravel-Cloud-Dienste, kein Sanctum/Passport (eigene Geräte-Token-Anmeldung) | – | D-001 ✅ |
| DB | **MariaDB 10.11+ und MySQL 8.4+**, beide ab v1; nur gemeinsamer Funktionsumfang (InnoDB, `utf8mb4`, Query Builder); Volltext per `FULLTEXT` hinter einer `SearchIndex`-Schnittstelle | PostgreSQL später möglich; **kein SQLite** in v1 | D-003 ✅ |
| Frontend | Blade + Vanilla-JS (ES-Module) ohne Build-Schritt; strikte CSP ohne Inline-Skripte | htmx nur, wenn ein Screen es braucht (lokal eingebunden, kein CDN) | D-004 ✅ |
| CSS | Eigenes kleines CSS mit Custom Properties (Light/Dark) | – | D-004 ✅ |
| OCR | Tesseract 5 (`deu+eng`), `ocrmypdf` für PDFs – **Zusatzfunktion**, nur wenn auf dem Server vorhanden (D-010) | – (kein Cloud-OCR, D-019) | D-005 |
| Bildverarbeitung | libvips (`php-vips`) oder Imagick; HEIC über libheif | – | D-005 |
| XLSX | PhpSpreadsheet (Import & Export) | OpenSpout (schneller, streaming) | – |
| PDF-Erzeugung | Dompdf | wkhtmltopdf/Chromium headless | – |
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
| `document_captures` | document_id, captured_at (UTC, korrigiert), captured_at_raw, captured_tz, captured_at_source (`device`/`exif`/`server`), client_sent_at, received_at, clock_skew_ms, lat, lon, accuracy_m, location_source (`geolocation`/`exif`), place_name, place_country, location_deleted_at | Aufnahme-Metadaten (D-017); lat/lon/accuracy verschlüsselt (D-007), nie in Logs; Abweichung > 2 min wird markiert |
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
| `transactions` | id, org_id, bank_account_id, import_id, external_id (z. B. Revolut-ID), booking_date, booked_at (Zeitpunkt, falls bekannt), value_date, amount_minor, currency, original_amount_minor, original_currency, fx_rate, fee_minor, counterparty, merchant_city, merchant_country, provider_category, state (`completed`/`pending`/`reverted`/`declined`/`failed`), purpose, raw_json, fingerprint (unique pro Konto), no_receipt_reason | `external_id` bzw. Fingerprint gegen Doppelimport; `provider_category` = Händlerkategorie (MCC), angezeigt als deutscher Text; `declined`/`failed` nie abgeglichen, nie im Belegpaket |
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

Web-Oberfläche serverseitig gerendert; zusätzlich eine kleine JSON-API für die PWA (Upload, Queue, Status). Alle Routen außer Login/Einladung erfordern Sitzung; alle fachlichen Routen sind org-gebunden (`/o/{org}/…` oder aktive Org in Sitzung – **D-008**).

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

### 9.1 Import (jede Bank, öffentlicher Kern)

1. Datei hochladen (CSV/XLSX, max. 10 MB).
2. Erkennung: Encoding (BOM, Heuristik), Trennzeichen, Kopfzeile; Vorschau der ersten 20 Zeilen.
3. Spaltenzuordnung: Buchungsdatum, Valuta, Betrag (oder Soll/Haben getrennt), Währung, Gegenpartei, Verwendungszweck; Datumsformat; Dezimaltrenner; Vorzeichenkonvention.
4. Profil speichern (pro Bankkonto). Vorlagen für verbreitete deutsche Banken (Sparkasse, Volksbank, DKB, ING, Commerzbank, comdirect, N26, PayPal-Export) – **Vorlagen anhand echter Beispieldateien verifizieren, nicht raten.**
5. Fingerprint pro Buchung = Hash(Konto, Buchungsdatum, Betrag, normalisierter Zweck, Gegenpartei, laufende Nummer bei identischen Zeilen am selben Tag) → keine Doppelimporte.
6. Ergebnisprotokoll: neu / bereits vorhanden / fehlerhaft (mit Zeilennummer).

### 9.2 Abgleich-Score (0–100)

Mit Revolut ist der **Zeitpunkt** das stärkste Signal: Das Foto entsteht typischerweise Sekunden bis Minuten nach der Zahlung. Der Betrag aus der OCR ist eine Bestätigung, aber nicht nötig.

| Kriterium | Punkte |
|-----------|--------|
| Zeitnähe Aufnahme ↔ Zahlungszeitpunkt (nur wenn beide Uhrzeiten bekannt) | ≤ 15 min: 35 · ≤ 2 h: 25 · gleicher Tag: 15 |
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

Ein PDF pro Zeitraum und Konto, schön und ruhig gestaltet (klare Typografie, viel Weißraum, A4 hoch, druckbar, auch am Bildschirm gut lesbar):

1. **Deckblatt:** Titel „Belegpaket“, Name der Organisation, Zeitraum, Konto (IBAN maskiert), Erstellt am, Version; Kennzahlen: Anzahl Buchungen, Summe Ausgaben/Einnahmen, Anzahl mit Beleg / **fehlend**.
2. **Übersicht:** Tabelle aller Buchungen – Nr. · Datum/Uhrzeit · Händler · Kategorie · Betrag (EUR, ggf. Originalbetrag) · Beleg ✓/✗ · Seite.
3. **Pro Buchung eine Seite:** oben die Buchungsdetails (Datum + Uhrzeit, Händler, Betrag, Originalwährung + Kurs, Gebühr, Händlerkategorie (MCC als Text), eigene Kategorie, Notiz, Beleg-Nr., optional Ort), darunter das **Belegfoto groß** (bei mehrseitigen Belegen Folgeseiten). Verweis auf die Originaldatei im ZIP.
4. **Fehlende Belege:** Liste zum Nachreichen; optional (C) Vorlage für einen Eigenbeleg.
5. **Fußzeile jeder Seite:** Seitenzahl, Paket-Version, Hinweis „Zusammenstellung aus DocHamster – kein steuerliches Dokument“.

Zusätzlich auf Wunsch: ZIP mit Originalfotos + `index.csv` + PDF, und CSV/XLSX der Übersicht. Seitenaufbau und Gestaltung werden in P0-5 skizziert und vom PO abgenommen; die PDF-Technik wählt der Lead (§5.2).

### 10.2 Weitere Berichte

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
2. **Kopplung:** Einladungslink oder QR-Code (auf bereits angemeldetem Gerät angezeigt, 10 min gültig) → auf dem neuen Gerät **Passkey anlegen** (WebAuthn). Für Owner und Mitglieder gibt es **kein** Passwort und kein TOTP (D-012). Passkey verloren → der Owner erzeugt einen neuen Kopplungslink; für den Owner selbst gibt es den Setup-CLI-Befehl.
3. **Danach:** langlebiges Geräte-Token (256 bit zufällig, nur Hash in DB) als Cookie `__Host-dh_device` (HttpOnly, Secure, SameSite=Lax – Strict würde das Cookie beim Öffnen aus Mail/QR-Link weglassen und wie eine Abmeldung wirken, D-009), Laufzeit 180 Tage gleitend.
4. **Rotation:** Token wird periodisch (z. B. alle 24 h) erneuert; Wiederverwendung eines alten Tokens derselben Familie → ganze Familie sperren (Diebstahlerkennung).
5. **Sitzung:** kurzlebige Session (z. B. 12 h) wird aus gültigem Geräte-Token stillschweigend erneuert.
6. **Step-up:** Sensible Aktionen (Export, Rollen, Einladungen, endgültig löschen, Geräte sperren) erfordern frische Bestätigung per Passkey (≤ 5 min alt). Der Steuerberater hat keine solchen Aktionen.
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

**Eine App, eine Mindestanforderung (D-010).** DocHamster läuft überall, wo die Mindestanforderungen unten erfüllt sind – eigener Server (VPS, Homeserver, NAS) oder Webhosting. Es gibt keine „Server-“ und „Hosting-Edition“ und **kein Docker** in v1 (ein Docker-Setup kann später als Komfort kommen, nie als Pflicht).

**Pflicht:** eine **feste Domain** mit öffentlich vertrauenswürdigem TLS-Zertifikat, aus dem Internet per HTTPS erreichbar. Grund: Passkeys hängen an der Domain (ein späterer Wechsel macht alle Passkeys ungültig), PWA/Service Worker brauchen einen sicheren Kontext, und das Handy sendet von überall. Die Domain wird vor P1-4 festgelegt und steht nie im Repo.

**Mindestanforderungen** – im README veröffentlicht, geprüft von `dochamster:doctor`:

| Bereich | Minimum | Für Zusatzfunktionen / empfohlen |
|---------|---------|-----------------------------------|
| Server | Linux-Server oder Webhosting mit SSH und Cron (jede Minute) | eigener Server für Texterkennung/Spracherkennung |
| CPU / RAM | 2 Kerne, 2 GB RAM (ohne DB) | 4 Kerne, 6 GB für lokale Spracherkennung |
| Speicher | 10 GB + Belege (≈ 2 MB pro Beleg) | SSD |
| PHP | 8.4 mit `pdo_mysql`, `mbstring`, `intl`, `sodium`, `fileinfo`, `exif`, `zip`, `gd`; `memory_limit` ≥ 256 MB; `upload_max_filesize`/`post_max_size` ≥ 26 MB | `vips`-Erweiterung; `proc_open` erlaubt (nötig für alle Zusatzfunktionen) |
| Datenbank | MariaDB 10.11 oder MySQL 8.4 (D-003) | neueste LTS |
| Netzwerk | eigene Domain, vertrauenswürdiges TLS, eingehend HTTPS; ausgehend HTTPS zur Bank-API (falls Modul), SMTP | – |
| Zusatz-Tools | – | Tesseract 5, ocrmypdf, ffmpeg, whisper.cpp, libheif |
| Handy | aktuelles iOS Safari / Android Chrome (letzte 2 Hauptversionen) | als Home-Bildschirm-App installiert |

Werte sind Schätzungen und werden in Phase 1 auf dem Testserver des PO geprüft (dessen Details bleiben außerhalb des Repos).

**Kern und Zusatzfunktionen:** Der Kern läuft immer: Erfassen + Offline-Queue, Zeit/Ort, Bank-Import und Abgleich, Belegpaket-PDF (reines PHP), Suche, Steuerberater-Zugang, Mail. Zusatzfunktionen nur, wenn die Umgebung sie hat: Texterkennung (Tesseract + ocrmypdf), lokale Spracherkennung (whisper.cpp + ffmpeg, D-019), HEIC-Konvertierung (libheif/vips), schnellere Bildverarbeitung (vips statt GD). `dochamster:doctor` (bei der Einrichtung, nach Updates und auf der Admin-Seite) prüft PHP-Version und -Erweiterungen, DB-Version, Cron-Lebenszeichen, HTTPS, Upload-Limits, `proc_open` und die Zusatz-Tools; fehlt etwas, wird die Zusatzfunktion nicht angeboten bzw. ausgegraut mit Grund („Texterkennung braucht Tesseract – auf diesem Server nicht vorhanden“).

**Datendateien:** GeoNames-Ortsnamen (D-017) lädt der Setup-/Update-Befehl (feste Version + Prüfsumme), Tesseract-Sprachdaten und das Whisper-Modell der Installationsschritt der Zusatzfunktion. Während der Verarbeitung von Belegen wird nichts heruntergeladen.

Betriebsthemen (alle in `docs/operations/` zu dokumentieren):
- Installation: Code hochladen/klonen, `composer install`, `.env`, `php artisan dochamster:setup`, Cron-Eintrag. Anleitungen für einen normalen Linux-Server (Debian/Ubuntu) und für typisches Webhosting.
- Worker: Cron jede Minute (`schedule:run` startet kurze `queue:work --stop-when-empty`-Läufe); Dauer-Worker (systemd/Supervisor) optional auf eigenen Servern.
- Backups: DB-Dump + Storage-Ordner; Restore-Anleitung; Hinweis auf Schlüssel.
- Updates: `git pull` bzw. neue Version hochladen → `composer install` → `migrate` → `dochamster:doctor`; Migrationen immer vorwärtskompatibel.
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
- **CI (GitHub Actions):** Lint, PHPStan, Tests auf **MariaDB 10.11 und MySQL 8.4** (D-003), Architektur-Tests für die Egress-Regel und `local`-Jobs ohne Netzwerk (D-020), Composer-Audit, Erkennungsquote-Report.
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
| P0-5 | UI-Skizzen der Hauptscreens (Erfassen, Abgleich, Belegpaket-Layout, Liste, Detail, Einstellungen) | `docs/design/` |

### Phase 1 – Fundament (v0.1)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P1-1 | Projektgerüst (Laravel), Config, `.env.example`, Setup-Befehl (ohne Docker, D-010), CI-Grundgerüst | – | – |
| P1-2 | Migrationen: users, organizations, memberships, devices, passkeys, invitations, audit_log | P1-1 | mit P1-3 |
| P1-3 | Layout, CSS-Basis (Light/Dark), i18n-Mechanismus, Fehlerseiten | P1-1 | mit P1-2 |
| P1-4 | Setup-CLI, Einladung, Kopplung per Link/QR, Passkey-Registrierung/Login | P1-2 | – |
| P1-5 | Geräte-Token mit Rotation, Geräteliste, Sperren, Step-up | P1-4 | – |
| P1-6 | Org-Scope-Middleware, Rollen/Policies, Rechte-Matrix-Testgerüst | P1-2 | mit P1-4 |
| P1-7 | Security-Header, CSRF, Rate Limiting, Health-Endpoint | P1-1 | mit P1-2…6 |
| P1-8 | README-Installation mit Mindestanforderungen (D-010), HTTPS-Doku (feste Domain + öffentlich vertrauenswürdiges Zertifikat), Backup-Doku v1 | P1-1 | ja |

**Testliste v0.1:** Instanz aufsetzen · Owner koppeln per Passkey · zweites Gerät per QR koppeln · Gerät sperren → ausgesperrt · Mitglied einladen · Mitglied sieht andere Org nicht.

### Phase 2 – Erfassen mit Zeit & Ort (v0.2)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P2-1 | Storage-Service (außerhalb Webroot, Pfadschema, optional Verschlüsselung), `document_files` | P1 | mit P2-2 |
| P2-2 | PWA: Manifest, Service Worker, Installierbarkeit, Kamera-Screen, Mehrseiten-Bündelung | P1-3 | mit P2-1 |
| P2-3 | Upload-API pro Datei + Abschluss (§7, D-017), idempotent (`client_upload_id`), Validierung, Limits | P2-1 | – |
| P2-4 | Offline-Queue (IndexedDB), Background Sync, Fortsetzen, Badge | P2-2, P2-3 | – |
| P2-5 | Aufnahmezeit + Standort (Geolocation, Opt-in, EXIF-Fallback), `document_captures`, Ortsnamen (D-017) | P2-3 | mit P2-6 |
| P2-6 | Zusatz-Schritt: Notiz, Kategorie-/Zahlungsart-Chips; Stammdaten | P2-3 | mit P2-5 |
| P2-7 | Geschützte Datei-Auslieferung (`/files/{id}`), Thumbnails, Ausrichtung | P2-1 | ja |
| P2-8 | Sprachnotiz aufnehmen und abspielen (S) | P2-3 | ja |

**Testliste v0.2:** Foto in ≤ 2 Taps · Zeit + Ort gespeichert, Ort abschaltbar · Flugmodus → 3 Belege → online → alle da, Aufnahmezeit korrekt, keine Duplikate · PDF aus Dateien · fremde Datei-ID → 403/404.

### Phase 3 – Bank-Import & Abgleich (v0.3)

Der CSV-Import für jede Bank ist Kern und kommt zuerst. Die Revolut-Live-Anbindung entsteht als nachinstallierbares Modul in einem eigenen Repo, mit Einrichtungs-Assistent (D-021); Technik beim **Lead** (D-016).

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P3-1 | Job-Queue + Worker (Daemon & Cron-Modus), Retry, Fehlerstatus | P1 | mit P2 |
| P3-2 | Bankkonten, `transactions`-Modell inkl. Zeitpunkt, Originalwährung, Gebühr, Status | P1 | mit P3-1 |
| P3-3 | CSV/XLSX-Import für **jede Bank** mit Import-Assistent und Profilen, Duplikatschutz per Fingerprint; Test mit Exporten von ≥ 5 Banken | P3-2 | mit P3-4 |
| P3-4a | `BankConnector`-Schnittstelle + Modul-Mechanismus (installieren mit einem Befehl, ein-/ausschalten) im Kern | P3-1, P3-2 | mit P3-3 |
| P3-4b | Revolut-Business-Modul im eigenen Repo (D-016/D-021): Einrichtungs-Assistent (AK-4.4.R5), Duplikatschutz über `external_id`, Tests gegen Revolut-Sandbox | P3-4a, P0-10 | – |
| P3-5 | Matching-Engine mit Zeitnähe-Score + Gründen, Kalibrierung mit Testdaten | P3-3, P2-5 | – |
| P3-6 | Abgleich-UI (Buchungen ohne Beleg · Belege ohne Buchung · Paare, 1-Tap-Bestätigung, „kein Beleg nötig“) | P3-5 | – |
| P3-7 | (S) „Passt zu …?“ direkt nach dem Foto | P3-5 | ja |

**Testliste v0.3:** CSV einer fremden Bank per Assistent importiert → Buchungen da · ohne Revolut-Modul läuft alles · Modul mit einem Befehl installiert · Revolut-Einrichtung per Assistent in ≤ 10 min · Kartenzahlung → Foto 2 min später → automatisch verknüpft · Fremdwährung korrekt · erneuter Abruf ohne Duplikate · Rückerstattung als eigene Zeile.

### Phase 4 – Belegpaket für den Steuerberater (v0.4 = Kernablauf fertig)

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P4-1 | Belegnummern, Abfragen für Zeitraum/Konto | P3 | – |
| P4-2 | Belegpaket-PDF nach §10.1 (Gestaltung aus P0-5, PO-Abnahme) | P4-1 | mit P4-3 |
| P4-3 | ZIP (Originale + `index.csv`), CSV/XLSX-Übersicht, asynchron, Download-Links | P4-1 | mit P4-2 |
| P4-4 | Versand per E-Mail-Link, Benachrichtigung | P4-3 | ja |

**Testliste v0.4:** Monat wählen → Paket in ≤ 3 Klicks · jede Buchung mit Foto · fehlende Belege markiert · Ort nur wenn eingeschaltet, nur Stadt · Link läuft ab.

### Phase 5 – Texterkennung & Ordnung (v0.5)

OCR ist jetzt Unterstützung, nicht Voraussetzung: Sie liefert Betrag/Händler als Bestätigung für den Abgleich, Belege ohne Kartenzahlung (bar, andere Banken) und die Volltextsuche.

| AP | Inhalt | Abh. | Parallel |
|----|--------|------|----------|
| P5-1 | NormalizeFiles (HEIC, Re-Encode, EXIF entfernen in abgeleiteten Dateien) | P3-1 | mit P5-2 |
| P5-2 | RunOcr (Tesseract, pdftotext, ocrmypdf) mit Ressourcenlimits | P3-1 | mit P5-1 |
| P5-3 | ExtractFields + `document_fields` + Fixture-Benchmark in CI; Betrag fließt in den Score | P5-2 | – |
| P5-4 | Regel-Engine, Händler-Stammdaten, Hashtags | P5-3 | mit P5-5 |
| P5-5 | Volltextindex (MariaDB/MySQL `FULLTEXT` hinter `SearchIndex`, D-003) | P5-2 | mit P5-4 |
| P5-6 | Duplikaterkennung | P5-3 | ja |
| P5-7 | Speech-to-Text lokal (Engine nach Lead-Entscheidung D-019), Transkript in Suche | P3-1, P2-8 | ja |

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

### Grobe Reihenfolge

```
P0 ─▶ P1 ─▶ P2 ─▶ P3 ─▶ P4  ══ Kernablauf fertig (v0.4) ══▶ P5 ─▶ P6 ─▶ P7 ─▶ P8
            └─ P3-1/P3-2 können parallel zu P2 starten
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
| iOS-PWA-Einschränkungen (Background Sync fehlt, Speicher-Eviction) | hoch | mittel | Fallback: Queue beim Öffnen senden; deutlicher Hinweis bei ausstehenden Uploads |
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
