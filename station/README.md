# DocHamster Scan Station

A small program for a computer that is always on in your home network, next to a
printer or scanner that can scan over the network (eSCL / "AirScan"; almost every
device since about 2015). It connects **out** to your DocHamster over HTTPS, so
nothing in your router has to be opened. When someone presses **Scannen** in
DocHamster, the station scans on the printer and sends the pages back; DocHamster
joins them into **one document** in the folder you choose.

**It finds the printer by itself.** You do not type an address: the station asks the
network for scanners (Bonjour, the same way phones find printers) and, if nothing
answers, looks at every address of the home network for a device that speaks eSCL.
If the printer is switched off or gets a new address, the station looks again by
itself.

You need it when DocHamster runs on a server **outside** your home. If DocHamster
runs inside your home network, connect the printer directly instead
(Einstellungen → Scanner → "Gerät direkt verbinden").

MIT licence, like the rest of DocHamster. One PHP file, no dependencies beyond PHP
with the `curl` and `SimpleXML` extensions.

## Set it up

1. In DocHamster: **Einstellungen → Scanner → Scan Station hinzufügen**. You get a
   one-time code (shown once, valid 24 hours) and the command below with your
   values filled in.
2. Put `dochamster-station.php` (download link on that page) on the always-on computer.
3. Start it. With Docker (any computer, Raspberry Pi, NAS):

   ```bash
   docker run -d --name dochamster-station --restart unless-stopped --network host \
     -e DOCHAMSTER_URL=https://dochamster.example.com \
     -e PAIRING_CODE=the-code-from-dochamster \
     -v dochamster-station:/data -v "$PWD":/app:ro -w /app \
     php:8.4-cli-alpine php dochamster-station.php
   ```

   `--network host` matters: the station has to sit in the same network as the
   printer to find it (on Docker Desktop for Windows/macOS use PHP directly, below).

   Or with PHP 8.1+ directly:

   ```bash
   DOCHAMSTER_URL=https://dochamster.example.com PAIRING_CODE=the-code \
   php dochamster-station.php
   ```

   The code is used once. The station then keeps its own key in `DATA_DIR`
   (`/data` in Docker, `./data` otherwise); afterwards `PAIRING_CODE` is not needed.

`php dochamster-station.php --list` shows the scanners found in your network and
stops; it needs no code and is a quick test.

## If it does not find the printer, or you have several

- Several devices: the first one is used, and the log lists all of them. Set
  `PRINTER_NAME=part-of-the-model-name` to choose, or `PRINTER_URL` (below).
- Nothing found: is the printer on and in the same network as this computer
  (not a guest network)? Some routers block the discovery between devices; then
  give the printer's address: `PRINTER_URL=192.168.0.20` (its network menu shows it).

## Settings

| Variable | Meaning |
|----------|---------|
| `DOCHAMSTER_URL` | `https://` address of your DocHamster |
| `PAIRING_CODE` | one-time code, first start only |
| `PRINTER_URL` | optional: the printer's address (private addresses only), skips the search |
| `PRINTER_NAME` | optional: part of the model name, to choose among several devices |
| `STATION_NAME` | name shown in DocHamster (default: the computer's name) |
| `DATA_DIR` | where the key is kept |

## What it does and does not do

- It only talks to your DocHamster (HTTPS, certificate checked) and to scanners in
  your own home network. Nothing else leaves the computer; nothing is sent to any vendor.
- The printer must be on a private address (192.168.x.x, 10.x.x.x, 172.16.x.x).
  The printer's own self-signed certificate is not checked; it is on your network.
- The search looks at the computer's own /24 network (for example 192.168.0.1 to
  192.168.0.254) and sends one small request to each address. It does not leave the
  home network.
- Remove a station in DocHamster and its key stops working at once; the station
  then tells you to link it again with a new code.
- It prints only what you send with **"Auf dem Drucker drucken"** in DocHamster
  (IPP, the open printing protocol; PDF or JPEG, whatever the printer takes) and
  never stores scanned pages or printed files: they go straight through.
