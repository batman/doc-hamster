# UI sketches – main screens (P0-5)

Wireframes for the main screens of PLAN.md §4. They show **what is on a screen and in which order**, not the final look (colours, type and spacing come from `public/css/app.css`, P1-3). UI text is German, as in the app; all data is made up.

Status: **draft by the Manager, sign-off by the Lead** (P0-5, P0-24). Product questions that come up here are listed at the end and go to the Lead, not decided here.

Conventions: `[ Button ]` primary action · `( Chip )` toggle chip · `‹ ›` navigation · `▢` checkbox · `⋯` more menu. Phone sketches are ~360 px wide, desktop sketches ~1100 px.

---

## 1. Erfassen – the home screen on the phone (§4.1)

The app opens straight into this screen. One big target; everything else is small.

```
┌──────────────────────────────────┐
│ DocHamster        ⟳ 2   Mustermann▾│  ← queue badge (2 waiting), org switcher
│                                  │
│                                  │
│          ┌──────────────┐        │
│          │              │        │
│          │    📷        │        │  ← camera button, ≥ 40 % of the width
│          │   Beleg      │        │
│          │ fotografieren│        │
│          └──────────────┘        │
│                                  │
│        Datei wählen (PDF, Galerie)│  ← small text link
│                                  │
│──────────────────────────────────│
│  Zuletzt: Bäckerei Beispiel      │
│  12,40 € · vor 3 min · ✓ gesendet│
│──────────────────────────────────│
│  ⌂ Erfassen   ☰ Belege   ⇄ Abgleich │  ← bottom bar; owner sees "Abgleich"
└──────────────────────────────────┘
```

After the photo – preview bar (step 2):

```
┌──────────────────────────────────┐
│ ‹ Zurück                Seite 1/1 │
│ ┌──────────────────────────────┐ │
│ │                              │ │
│ │        (photo preview)       │ │
│ │                              │ │
│ └──────────────────────────────┘ │
│ ┌────┐                           │
│ │ p1 │  [ + Seite ]              │  ← thumbnails of bundled pages
│ └────┘                           │
│                                  │
│ [           Senden             ] │  ← full-width primary button
└──────────────────────────────────┘
```

After sending – optional extra step (step 3), can be skipped with one tap:

```
┌──────────────────────────────────┐
│ ✓ Gespeichert                    │  or: "Wird gesendet, sobald du online bist"
│                                  │
│ Notiz                            │
│ ┌──────────────────────────────┐ │
│ │ Kaffee mit Kunde             │ │
│ └──────────────────────────────┘ │
│ 🎤 Sprachnotiz (S)               │
│                                  │
│ Kategorie                        │
│ (Bewirtung) (Tanken) (Büro)      │
│ (Reise) (Software) (Sonstiges)   │  ← 6 most used
│                                  │
│ Zahlungsart                      │
│ (Firmenkarte) (privat) (bar)     │
│                                  │
│ [ Fertig ]        Überspringen   │
└──────────────────────────────────┘
```

Notes
- The capture time and, if allowed, the location are taken at the shutter press (D-017); nothing about them is shown here except a small "📍 Musterstadt" once the place name is known.
- Camera method (native camera vs. in-page camera) is decided after the device test (B5, Q-18) – the layout works for both.
- Queue badge: tapping it opens a list "Warten auf Netz: 2 Belege" with retry.
- Member vs. owner: members see only "Erfassen" and "Belege" in the bottom bar.

---

## 2. Belege – list and search (§4.3)

Phone: cards. Desktop: the same as a table with filters on the left.

```
┌──────────────────────────────────┐
│ 🔍 Suchen …               ⚙ Filter│
│ (Alle) (ohne Buchung 3) (Prüfen 5)│  ← quick filters with counts
│──────────────────────────────────│
│ ┌────┐ Bäckerei Beispiel          │
│ │img │ 12,40 € · 28.10.2026 08:14 │
│ └────┘ Bewirtung · ✓ Buchung      │
│──────────────────────────────────│
│ ┌────┐ Tankstelle Musterstadt     │
│ │img │ 68,02 € · 27.10.2026 17:40 │
│ └────┘ Tanken · ⚠ ohne Buchung    │
│──────────────────────────────────│
│ ┌────┐ Wird verarbeitet …         │  ← status "processing"
│ │img │ 27.10.2026 12:03           │
│ └────┘                            │
└──────────────────────────────────┘
```

```
Desktop
┌───────────────┬──────────────────────────────────────────────────────────────────────┐
│ Filter        │ 🔍 Suchen in Text, Notizen, Händler …                     ▦ Kacheln ☰ Tabelle │
│               │──────────────────────────────────────────────────────────────────────│
│ Zeitraum      │ Datum        Händler                 Betrag   Kategorie  Buchung  Von │
│ [Okt 2026 ▾]  │ 28.10. 08:14 Bäckerei Beispiel       12,40 €  Bewirtung  ✓        MM  │
│ Kategorie     │ 27.10. 17:40 Tankstelle Musterstadt  68,02 €  Tanken     ⚠ fehlt  MM  │
│ ▢ Bewirtung   │ 25.10. 10:02 Bürobedarf Beispiel     23,99 €  Büro       ✓        EM  │
│ ▢ Tanken      │ …                                                                    │
│ Betrag von/bis│                                                                      │
│ Zahlungsart   │                                             ‹ 1 2 3 … ›              │
│ Status        │                                                                      │
│ ▢ ohne Buchung│                                                                      │
│ ▢ Duplikat    │                                                                      │
└───────────────┴──────────────────────────────────────────────────────────────────────┘
```

---

## 3. Beleg – detail (§4.3 AK-4.3.4)

```
Desktop
┌──────────────────────────────────────────────┬───────────────────────────────────────┐
│ ‹ Belege        Beleg 2026-0042               │                          ⋯ (Papierkorb)│
│ ┌──────────────────────────────────────────┐ │ Händler   [Bäckerei Beispiel      ]    │
│ │                                          │ │ Betrag    [12,40] [EUR▾]              │
│ │            (photo, zoomable)             │ │ Datum     [28.10.2026]  08:14 (Gerät) │
│ │                                          │ │ Ort       Musterstadt  ✕ löschen      │
│ │                                          │ │ Kategorie [Bewirtung ▾]               │
│ └──────────────────────────────────────────┘ │ Zahlungsart [Firmenkarte ▾]           │
│   ‹ Seite 1 / 1 ›     ⤢ Original öffnen      │ Tags      #kunde  + Tag               │
│                                              │ Notiz     [Kaffee mit Kunde        ]  │
│ Erkannter Text ▾ (aufklappbar)               │───────────────────────────────────────│
│                                              │ Buchung                               │
│                                              │ ✓ 28.10. 08:13 · −12,40 € · Firmenkonto│
│                                              │   automatisch zugeordnet · Warum? ▾    │
│                                              │   [ Zuordnung lösen ]                 │
│                                              │───────────────────────────────────────│
│                                              │ Verlauf ▾  (hochgeladen, erkannt, …)   │
│                                              │ [ Als geprüft markieren ]             │
└──────────────────────────────────────────────┴───────────────────────────────────────┘
```

Notes
- Each field shows its source on hover/tap (`OCR`, `Regel`, `Buchung`, `von dir`), from `document_fields` (§6.2).
- "Warum?" opens the score reasons (`reasons_json`, §9.2): "Zeit: 1 min (35) · Betrag gleich (40) · Händler ähnlich (15)".
- The capture time source is shown in brackets: `Gerät`, `EXIF` or `Server` (AK-4.1.9); a clock skew > 2 min shows "⚠ Uhr des Handys ging 6 min vor – korrigiert".

On the phone the same content is one column: photo on top, fields below, actions at the end.

---

## 4. Abgleich – matching (§4.4 AK-4.4.5, owner only)

Three tabs with counts. The most common action – confirming a suggestion – is one click.

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ Abgleich    Konto [Firmenkonto ▾]   Zeitraum [Oktober 2026 ▾]       [ Kontoauszug importieren ] │
│ ( Buchungen ohne Beleg 4 )  ( Belege ohne Buchung 3 )  ( Zugeordnet 51 )             │
│──────────────────────────────────────────────────────────────────────────────────────│
│ 27.10. 17:38  Tankstelle Musterstadt   −68,02 €                                      │
│   Vorschlag:  ┌────┐ Beleg 27.10. 17:40 · 68,02 € · 74 Punkte  [ ✓ Passt ] [ ✕ ]     │
│               │img │ Warum? Zeit 2 min · Betrag gleich                               │
│               └────┘                                                                 │
│──────────────────────────────────────────────────────────────────────────────────────│
│ 26.10.        Software Beispiel GmbH   −29,00 €                                      │
│   Kein Vorschlag.   [ Beleg suchen … ]   [ Braucht keinen Beleg ▾ ]                  │
│──────────────────────────────────────────────────────────────────────────────────────│
│ 25.10.        Gehalt                   +2.400,00 €                                   │
│   [ Braucht keinen Beleg ▾ ]  (Grund: Gehalt / Umbuchung / Gebühr / Sonstiges)       │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

- Desktop: a receipt can be dragged from the right-hand "Belege ohne Buchung" panel onto a transaction (AK-4.4.5).
- Phone: the same list without drag & drop; "Beleg suchen …" opens a picker sorted by score.
- Pending transactions are shown greyed out with "ausstehend"; declined/failed ones are hidden behind a "abgelehnte anzeigen" link (AK-4.4.R2).

Import wizard (AK-4.4.2/4.4.8) – four steps on one page each: **Datei** → **Spalten zuordnen** (preview of the first 20 rows, a dropdown above each column) → **Format** (date format, decimal comma, sign) → **Ergebnis** (neu 48 · schon vorhanden 12 · fehlerhaft 0 with row numbers).

---

## 5. Belegpaket erstellen (§4.5, max. 3 clicks)

```
┌──────────────────────────────────────────────┐
│ Belegpaket erstellen                         │
│ Zeitraum  ( Okt 2026 ) ( Q3 2026 ) [ von–bis ]│  ← click 1: last month preselected
│ Konto     [ Firmenkonto ▾ ]                  │
│ ▢ Ort der Aufnahme zeigen (nur Stadt)        │  ← off by default (AK-4.5.B4)
│                                              │
│ 55 Buchungen · 51 mit Beleg · 4 fehlen        │  ← live count
│ [ Vorschau ]            [ PDF erstellen ]    │  ← click 2 / 3
└──────────────────────────────────────────────┘
```

The PDF itself: see [`receipt-pack.html`](receipt-pack.html).

---

## 6. Einstellungen (§4.8)

Settings are a list of sections; each opens its own page. Only what the role may use is shown (§2.1).

```
┌──────────────────────────────────┐
│ Einstellungen                    │
│ Mein Profil                    › │  Name, Sprache
│ Meine Geräte                   › │  list, "zuletzt genutzt", Sperren
│ Passkeys                       › │
│──── Organisation (Owner) ────────│
│ Allgemein                      › │  Name, Aufbewahrung, Papierkorb-Dauer
│ Kategorien & Zahlungsarten     › │
│ Regeln                         › │
│ Mitglieder & Einladungen       › │
│ Steuerberater-Zugang           › │  account + share scope (AK-4.6.4)
│ Bankkonten & Import-Profile    › │
│ Backup herunterladen           › │  §4.8, D-023
│ Protokoll                      › │  audit log
│──────────────────────────────────│
│ Abmelden                         │
└──────────────────────────────────┘
```

Backup herunterladen (D-023, AK-4.8.1–7):

```
┌──────────────────────────────────────────────┐
│ Backup herunterladen                         │
│ ( Alles )  ( Zeitraum: von [  ] bis [  ] )   │
│ ▢ Originale + abgeleitete Dateien            │
│ ☑ Genaue Aufnahmeorte (GPS)  ⓘ               │  ← all ticked by default
│ ☑ Papierkorb                 ⓘ               │
│ ☑ Protokoll (Audit-Log)      ⓘ               │
│ ▢ ZIP mit Passwort schützen (empfohlen)      │
│                                              │
│ Erwartete Größe: ca. 1,8 GB                  │
│ Ab 500 MB wird das Backup im Hintergrund     │
│ erstellt – wir sagen Bescheid, wenn es fertig│
│ ist.                                         │
│ [ Backup erstellen ]   (Passkey-Bestätigung) │
└──────────────────────────────────────────────┘
```

Steuerberater-Zugang anlegen (AK-4.6.4): fields Benutzername, Zeitraum, Konten, Kategorien, Ablaufdatum → [ Zugang anlegen ] → a one-time box "Passwort: `xxxx-xxxx-xxxx-xxxx-xxxx` – jetzt kopieren und weitergeben, es wird nicht noch einmal angezeigt."

---

## 7. Steuerberater-Ansicht (§4.6, Phase 7)

Read-only; no capture, no matching, no settings except the own password.

```
┌──────────────────────────────────────────────────────────────┐
│ DocHamster · Mustermann GmbH (freigegeben bis 31.12.2026)     │
│ ( Belegpakete )  ( Belege )                       Abmelden   │
│──────────────────────────────────────────────────────────────│
│ Belegpaket Oktober 2026 · Firmenkonto · Version 2   ⬇ PDF ⬇ ZIP│
│ Belegpaket September 2026 · Firmenkonto · Version 1 ⬇ PDF ⬇ ZIP│
└──────────────────────────────────────────────────────────────┘
```

---

## Open product questions (for the Lead)

These came up while sketching; they are **not** decided here.

1. **Bottom bar on the phone for owners:** three entries (Erfassen, Belege, Abgleich) or keep matching desktop-only? Matching on the phone is usable for 1-tap confirmations, drag & drop is desktop-only.
2. **Where the queue badge lives:** in the header (as sketched) or on the camera button itself.
3. **Extra step after sending:** shown every time (skippable) or only when the user taps "Details" – fewer taps vs. more categorised receipts.
4. **Receipt pack defaults:** last full month preselected (as sketched) or the period after the last created pack.
