# DocHamster Scan Station

A small program for a computer that is always on in your home network, next to a
printer or scanner that can scan over the network (eSCL / "AirScan"; almost every
device since about 2015). It connects **out** to your DocHamster over HTTPS, so
nothing in your router has to be opened. When someone presses **Scannen** in
DocHamster, the station scans on the printer and sends the pages back; DocHamster
joins them into **one document** in the folder you choose.

You need it when DocHamster runs on a server **outside** your home. If DocHamster
runs inside your home network, connect the printer directly instead
(Einstellungen → Scanner → "Gerät direkt verbinden").

MIT licence, like the rest of DocHamster. One PHP file, no dependencies beyond PHP
with the `curl` and `SimpleXML` extensions.

## Set it up

1. In DocHamster: **Einstellungen → Scanner → Scan Station hinzufügen**. You get a
   one-time code (shown once, valid 24 hours) and the commands below with your
   values filled in.
2. Put `dochamster-station.php` (download link on that page) on the always-on computer.
3. Start it. With Docker (any computer, Raspberry Pi, NAS):

   ```bash
   docker run -d --name dochamster-station --restart unless-stopped --network host \
     -e DOCHAMSTER_URL=https://dochamster.example.com \
     -e PAIRING_CODE=the-code-from-dochamster \
     -e PRINTER_URL=192.168.0.20 \
     -v dochamster-station:/data -v "$PWD":/app:ro -w /app \
     php:8.4-cli-alpine php dochamster-station.php
   ```

   Or with PHP 8.1+ directly:

   ```bash
   DOCHAMSTER_URL=https://dochamster.example.com PAIRING_CODE=the-code \
   PRINTER_URL=192.168.0.20 php dochamster-station.php
   ```

   The code is used once. The station then keeps its own key in `DATA_DIR`
   (`/data` in Docker, `./data` otherwise); afterwards `PAIRING_CODE` is not needed.

`PRINTER_URL` is the address of the printer in your home network (its network
menu shows it). `php dochamster-station.php --check` only talks to the printer and
stops, which is a quick way to test the address.

## Settings

| Variable | Meaning |
|----------|---------|
| `DOCHAMSTER_URL` | `https://` address of your DocHamster |
| `PRINTER_URL` | printer in the home network (private addresses only) |
| `PAIRING_CODE` | one-time code, first start only |
| `STATION_NAME` | name shown in DocHamster (default: the computer's name) |
| `DATA_DIR` | where the key is kept |

## What it does and does not do

- It only talks to your DocHamster (HTTPS, certificate checked) and to the printer
  you named. Nothing else leaves the computer; nothing is sent to any vendor.
- The printer must be on a private address (192.168.x.x, 10.x.x.x, 172.16.x.x).
  The printer's own self-signed certificate is not checked; it is on your network.
- Remove a station in DocHamster and its key stops working at once; the station
  then tells you to link it again with a new code.
- It never prints and never stores scanned pages: they go straight to DocHamster.
