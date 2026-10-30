# Receipt pack – layout draft (P0-5, D-018)

Mock-up: [`receipt-pack.html`](receipt-pack.html) – open it in a browser; the print preview shows the A4 pages. All data is made up.

Status: **draft by the Manager.** The Lead signs off and asks its operator Q-19 ("How should the receipt pack look?") with this draft. PDF technology is Dompdf (B12), so the real template uses only simple CSS (tables, no grid/flexbox); the mock-up follows that.

## Pages (PLAN.md §10.1)

1. **Deckblatt** – title, period, account; organisation, account with masked IBAN, created at/by, version (and which version it replaces); four key figures: transactions, expenses, income, **missing receipts** (in red, with the page of the list).
2. **Übersicht** – one row per transaction: no. · date/time · merchant · category · amount (foreign currency underneath) · receipt (✓ receipt number / ✗ missing / "kein Beleg nötig: Grund") · page. Missing rows have a light red background. Totals at the end.
3. **Eine Seite pro Buchung** – merchant and amount large at the top, transaction no. and receipt number on the right; a two-column detail table (payment time, photo time, merchant category with MCC, own category, payment method, place – only if switched on, fee, how it was matched, note); below the **receipt photo large** with a caption that names the file's folder in the ZIP. Multi-page receipts continue on the next page.
4. **Fehlende Belege** – list with totals; placeholder for the later self-made receipt template (C).

Every page has the footer "Zusammenstellung aus DocHamster – kein steuerliches Dokument" plus "Belegpaket Okt 2026 · v2 · Seite x von y".

## Choices made in the draft

- Transactions that need no receipt (salary, transfers) appear in the overview with their reason but get **no page of their own** – keeps the pack short.
- The photo time is shown next to the payment time, so the tax advisor sees why a receipt belongs to a transaction.
- Place is off by default (AK-4.5.B4) and, when on, only the city.
- A4 portrait, black/grey with one red for "missing"; prints well in black and white (the red rows stay distinguishable by the ✗).

## Open questions (Q-19, for the Lead / operator)

1. **One page per transaction, or two small receipts per page?** One page is calmer and easier to reference; two per page halves the page count for a month with many small receipts (bakery, parking).
2. **Notes and own categories in the pack?** They help the tax advisor but may contain private remarks – show always, never, or as an option when creating the pack.
3. **Order:** by date (as drafted) or grouped by category?
4. **Overview on the cover page** when there are only a few transactions (e.g. ≤ 10), to save a page?
