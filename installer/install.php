<?php

declare(strict_types=1);

/*
 * DocHamster web installer – upload only this file to your web space and open it in the browser.
 *
 * It checks the server, downloads the newest release that fits your PHP version from GitHub,
 * checks its SHA-256 against corruption, unpacks it (outside the web root where possible),
 * wires the web root, hands over to the setup wizard (/setup) and deletes itself.
 *
 * Plain PHP without dependencies; syntax kept compatible with old PHP versions so it can still
 * say "PHP too old" instead of failing silently.
 */

const DH_REPO = 'batman/doc-hamster';
const DH_INSTALLER_VERSION = 'dev'; // set by .github/workflows/release.yml
const DH_MAX_AGE_SECONDS = 7200;
const DH_USER_INI = "memory_limit = 256M\nupload_max_filesize = 32M\npost_max_size = 32M\n";
const DH_WIPE_GROUPS = [
    'documents' => ['label' => 'Belege (alle Fotos und PDFs)', 'tables' => ['matches', 'document_files', 'documents']],
    'people' => ['label' => 'Benutzer und Rollen (danach legen Sie ein neues Inhaber-Konto an)', 'tables' => ['sessions', 'invitations', 'passkeys', 'devices', 'memberships', 'roles', 'users']],
    'banking' => ['label' => 'Bankdaten (Konten, Buchungen, Verknüpfungen mit Belegen)', 'tables' => ['matches', 'transactions', 'imports', 'import_profiles', 'bank_accounts']],
];
const DH_EXTENSIONS = ['pdo_mysql', 'mbstring', 'intl', 'sodium', 'fileinfo', 'exif', 'zip', 'gd', 'curl'];

$https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $https]);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$webRoot = __DIR__;
$outside = @is_writable(dirname($webRoot));
// The web folder itself may be called "dochamster" (e.g. a subdomain) – never use it as the app folder.
$appDir = ($outside ? dirname($webRoot) : $webRoot).'/dochamster';
if ($appDir === $webRoot) {
    $appDir .= '-app';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<meta name="robots" content="noindex"><title>'.h($title).' – DocHamster</title><style>'
        .'body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem}'
        .'input{width:100%;padding:.6rem;font:inherit;box-sizing:border-box}input[type=checkbox]{width:auto}button,.button{display:inline-block;margin-top:1rem;padding:.7rem 1.4rem;'
        .'border:0;border-radius:6px;background:#2b6cb0;color:#fff;font:inherit;text-decoration:none;cursor:pointer}'
        .'.ok{color:#2f855a}.fail{color:#c53030}code{background:#eee;padding:.1rem .3rem;word-break:break-all}</style></head><body>'
        .'<p>DocHamster · Installation · Version '.h(DH_INSTALLER_VERSION).'</p><h1>'.h($title).'</h1>'.$body.'</body></html>';
    exit;
}

function codeFile(string $webRoot, bool $outside): string
{
    if ($outside) {
        return dirname($webRoot).'/dochamster-install-code.txt';
    }
    // Inside the web root: unguessable file name.
    $existing = glob($webRoot.'/dochamster-install-code-*.txt') ?: [];

    return $existing[0] ?? $webRoot.'/dochamster-install-code-'.bin2hex(random_bytes(8)).'.txt';
}

/** GET a URL; into $file if given (streamed, no big strings in memory), else returned. */
function http(string $url, ?string $file = null): string
{
    $ch = curl_init($url);
    $out = $file === null ? null : fopen($file, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'DocHamster-Installer',
        CURLOPT_HTTPHEADER => ['Accept: application/json, application/octet-stream'],
    ]);
    $out === null ? curl_setopt($ch, CURLOPT_RETURNTRANSFER, true) : curl_setopt($ch, CURLOPT_FILE, $out);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($out !== null) {
        fclose($out);
    }
    if ($status === 403 || $status === 429) {
        throw new RuntimeException('GitHub hat gerade zu viele Anfragen von diesem Server bekommen. Bitte in einer Stunde erneut versuchen.');
    }
    if ($body === false || $status !== 200) {
        throw new RuntimeException('Download fehlgeschlagen (HTTP '.$status.'). Bitte später erneut versuchen.');
    }

    return $out === null ? (string) $body : '';
}

/** Newest release whose manifest fits this PHP version. */
function chooseRelease(): array
{
    $releases = json_decode(http('https://api.github.com/repos/'.DH_REPO.'/releases?per_page=20'), true);
    foreach (is_array($releases) ? $releases : [] as $release) {
        if (! empty($release['draft']) || ! empty($release['prerelease'])) {
            continue;
        }
        $assets = [];
        foreach ($release['assets'] ?? [] as $asset) {
            $assets[$asset['name']] = $asset['browser_download_url'];
        }
        if (! isset($assets['manifest.json'])) {
            continue;
        }
        $manifest = json_decode(http($assets['manifest.json']), true);
        if (is_array($manifest) && isset($assets[$manifest['zip'] ?? '']) && version_compare(PHP_VERSION, (string) $manifest['php'], '>=')) {
            $manifest['url'] = $assets[$manifest['zip']];

            return $manifest;
        }
    }
    throw new RuntimeException('Keine passende DocHamster-Version für PHP '.PHP_VERSION.' gefunden.');
}

function removeTree(string $dir): void
{
    if (! is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($dir.'/'.$entry);
        }
    }
    @rmdir($dir);
}

/** Write $content to $path; an existing different file is kept as <name>.bak-<time>. */
function placeFile(string $path, string $content, bool $keepOld): void
{
    if ($keepOld && is_file($path) && (string) file_get_contents($path) !== $content) {
        rename($path, $path.'.bak-'.date('YmdHis'));
    }
    file_put_contents($path, $content);
}

/** Puts everything from public/ into the web root; index.php gets the path to the app. */
function wireWebRoot(string $appDir, string $webRoot, bool $keepOld): void
{
    $copy = static function (string $from, string $to) use (&$copy, $keepOld): void {
        @mkdir($to);
        foreach (scandir($from) ?: [] as $entry) {
            if (in_array($entry, ['.', '..', 'index.php', '.user.ini'], true)) {
                continue;
            }
            is_dir($from.'/'.$entry)
                ? $copy($from.'/'.$entry, $to.'/'.$entry)
                : placeFile($to.'/'.$entry, (string) file_get_contents($from.'/'.$entry), $keepOld);
        }
    };
    $copy($appDir.'/public', $webRoot);

    $index = (string) file_get_contents($appDir.'/public/index.php');
    placeFile($webRoot.'/index.php', str_replace("__DIR__.'/../", var_export($appDir.'/', true).".'", $index), $keepOld);
}

/** Downloads and checks the release, returns the unpacked folder. */
function unpackRelease(array $release, string $tmpDir, string $zipFile): string
{
    removeTree($tmpDir);
    http($release['url'], $zipFile);
    if (! hash_equals((string) $release['sha256'], (string) hash_file('sha256', $zipFile))) {
        throw new RuntimeException('Die heruntergeladene Datei ist beschädigt (Prüfsumme stimmt nicht). Bitte erneut versuchen.');
    }

    $zip = new ZipArchive;
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('ZIP-Datei lässt sich nicht öffnen.');
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (strpos($name, 'dochamster/') !== 0 || strpos($name, '..') !== false) {
            throw new RuntimeException('Unerwarteter Inhalt im Paket.');
        }
    }
    if (! $zip->extractTo($tmpDir)) {
        throw new RuntimeException('Entpacken fehlgeschlagen (Speicherplatz?).');
    }
    $zip->close();
    @unlink($zipFile);

    return $tmpDir.'/dochamster';
}

/** Swaps the program files; .env and storage/ (receipts) move into the new version unchanged. */
function swapApp(string $appDir, string $new): void
{
    removeTree($new.'/storage');
    if (! rename($appDir.'/storage', $new.'/storage')) {
        throw new RuntimeException('Der Datenordner konnte nicht übernommen werden. Es wurde nichts verändert.');
    }
    if (! rename($appDir.'/.env', $new.'/.env')) {
        rename($new.'/storage', $appDir.'/storage');
        throw new RuntimeException('Die Einstellungen konnten nicht übernommen werden. Es wurde nichts verändert.');
    }
    $old = $appDir.'.old';
    removeTree($old);
    if (! rename($appDir, $old) || ! rename($new, $appDir)) {
        if (is_dir($old)) {
            rename($old, $appDir);
        }
        rename($new.'/.env', $appDir.'/.env');
        rename($new.'/storage', $appDir.'/storage');
        throw new RuntimeException('Die neue Version konnte nicht an ihren Platz verschoben werden. Es wurde nichts verändert.');
    }
    removeTree($old);
    // Compiled pages live in storage/ and would survive. Unpacked files keep the ZIP's date,
    // so Laravel could take the old pages for newer than the new templates.
    foreach (glob($appDir.'/storage/framework/views/*.php') ?: [] as $compiled) {
        @unlink($compiled);
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

/** Runs the database migrations of the new version in this PHP process (no shell on simple hosting). */
function migrate(string $appDir): void
{
    require $appDir.'/vendor/autoload.php';
    $app = require $appDir.'/bootstrap/app.php';
    $kernel = $app->make('Illuminate\Contracts\Console\Kernel');
    $kernel->bootstrap();
    if ($kernel->call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Die Datenbank konnte nicht angepasst werden.');
    }
}

/** @return array<string, string> */
function readEnv(string $file): array
{
    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m) === 1) {
            $value = trim($m[2]);
            $quoted = strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"';
            $values[$m[1]] = $quoted ? (string) preg_replace('/\\\\(.)/s', '$1', substr($value, 1, -1)) : $value;
        }
    }

    return $values;
}

/**
 * Empties the chosen groups, after the user ticked them and typed LÖSCHEN. Tables stay, the database access
 * in .env is never touched. Tables that don't exist yet are skipped. All groups = the organisation goes too.
 *
 * @param  list<string>  $groups
 */
function wipeSelected(string $appDir, array $groups): void
{
    $env = readEnv($appDir.'/.env');
    $pdo = new PDO(
        'mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.($env['DB_DATABASE'] ?? '').';charset=utf8mb4',
        $env['DB_USERNAME'] ?? '',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
    $existing = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

    $tables = [];
    foreach ($groups as $group) {
        $tables = array_merge($tables, DH_WIPE_GROUPS[$group]['tables']);
    }
    if (count($groups) === count(DH_WIPE_GROUPS)) {
        $tables = array_merge($tables, ['organizations', 'audit_log']);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_unique($tables) as $table) {
        if (in_array($table, $existing, true)) {
            $pdo->exec('DELETE FROM `'.$table.'`');
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    if (in_array('documents', $groups, true)) {
        // Receipts live in one folder per organisation, named by its ULID.
        foreach (glob($appDir.'/storage/app/private/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^[0-9a-z]{26}$/', basename($dir)) === 1 || basename($dir) === 'exports') {
                removeTree($dir);
            }
        }
    }
}

// The installer stays usable for two hours after its first run (not after upload: FTP clients may keep old file dates).
$codeFile = codeFile($webRoot, $outside);
if (is_file($codeFile) && time() - (int) filemtime($codeFile) > DH_MAX_AGE_SECONDS) {
    page('Abgelaufen', '<p class="fail">Diese Installation wurde vor mehr als zwei Stunden begonnen und ist aus Sicherheitsgründen gesperrt. Bitte löschen Sie die Datei <code>'.h($codeFile).'</code> und laden Sie install.php neu.</p>');
}
// An existing copy: what happens to it is decided after the code step. "Finished" means it holds real data.
$existing = is_file($appDir.'/artisan');
$finished = $existing && preg_match('/^DOCHAMSTER_INSTALLED=true/m', (string) @file_get_contents($appDir.'/.env')) === 1;
if (! is_file($codeFile)) {
    $code = strtoupper(substr(bin2hex(random_bytes(10)), 0, 16));
    if (@file_put_contents($codeFile, implode('-', str_split($code, 4))."\n") === false) {
        page('Keine Schreibrechte', '<p class="fail">install.php darf in diesem Ordner keine Dateien anlegen. Bitte geben Sie dem Webserver Schreibrechte und laden Sie die Seite neu.</p>');
    }
    // PHP limits for receipts. PHP re-reads .user.ini only every few minutes, so write it now, not at the end.
    if ($outside && ! is_file($webRoot.'/.user.ini')) {
        @file_put_contents($webRoot.'/.user.ini', DH_USER_INI);
    }
}
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$csrf = '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
$httpsWarning = $https ? '' : '<p class="fail">Achtung: Diese Seite läuft ohne HTTPS. DocHamster braucht ein gültiges Zertifikat – bitte zuerst in der Verwaltung Ihres Webhosters HTTPS einschalten.</p>';

// Step 1: prove access to the web space with the code from the file.
if (empty($_SESSION['unlocked'])) {
    $error = '';
    // The app's own update button links here with the code it just wrote.
    $given = $posted ? ($_POST['code'] ?? null) : ($_GET['code'] ?? null);
    if (is_string($given)) {
        $norm = static function (string $s): string {
            return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
        };
        if (hash_equals($norm((string) file_get_contents($codeFile)), $norm($given))) {
            session_regenerate_id(true);
            $_SESSION['unlocked'] = true;
            header('Location: '.basename(__FILE__));
            exit;
        }
        $error = '<p class="fail">Der Code stimmt nicht.</p>';
        sleep(2);
    }
    page('Willkommen', $httpsWarning.'<p>Zuerst zeigen Sie, dass Ihnen dieser Webspace gehört. Öffnen Sie mit Ihrem FTP-Programm oder dem Dateimanager Ihres Webhosters diese Datei und tippen Sie den Code ein:</p>'
        .'<p><code>'.h($codeFile).'</code></p>'.$error.'<form method="post">'.$csrf.'<input name="code" autocomplete="off" required autofocus><button>Weiter</button></form>');
}

// Never touch the folder this script is served from.
if ($existing && strpos($webRoot.'/', $appDir.'/') === 0) {
    page('Bereits installiert', '<p class="fail">Ihr Webordner liegt in <code>'.h($appDir).'</code>. Bitte richten Sie die Domain auf einen eigenen Webordner aus und starten Sie install.php dort.</p>');
}

// Step 2: check the server and pick the release (once; the same one is installed).
$checks = ['PHP '.PHP_VERSION => true, 'HTTPS' => $https];
foreach (DH_EXTENSIONS as $ext) {
    $checks['Erweiterung '.$ext] = extension_loaded($ext);
}
$checks['Schreibrechte für '.dirname($appDir)] = @is_writable(dirname($appDir));
if (empty($_SESSION['release']) && extension_loaded('curl')) {
    try {
        $_SESSION['release'] = chooseRelease();
    } catch (Throwable $e) {
        $checks[$e->getMessage()] = false;
    }
}
$release = $_SESSION['release'] ?? null;
$allOk = $release !== null && ! in_array(false, $checks, true);
$tmpDir = dirname($appDir).'/dochamster-install-tmp';
$zipFile = dirname($appDir).'/dochamster-download.zip';

// Step 2b: an existing copy – the user decides: update (data stays) or fresh install (data gone).
if ($existing) {
    $action = $posted ? (string) ($_POST['action'] ?? '') : '';

    if ($action === '') {
        $installed = is_file($appDir.'/VERSION') ? trim((string) file_get_contents($appDir.'/VERSION')) : 'unbekannt';
        $available = $release ? '<p>Installierte Version: <strong>'.h($installed).'</strong> · Verfügbar: <strong>'.h($release['version']).'</strong></p>' : '';
        $blocked = '<p class="fail">Der Server erfüllt gerade nicht alle Voraussetzungen'.($release ? '' : ' oder die neue Version ist nicht erreichbar').'. Bitte laden Sie die Seite später neu.</p>';
        if (! $finished) {
            page('Installation gefunden', '<p>In <code>'.h($appDir).'</code> liegt schon eine DocHamster-Kopie, deren Einrichtung nie abgeschlossen wurde.</p><p>Soll ich sie löschen und neu installieren?</p>'
                .($allOk ? '<form method="post">'.$csrf.'<input type="hidden" name="action" value="fresh"><button>Ja, überschreiben</button></form>' : $blocked));
        }
        $boxes = '';
        foreach (DH_WIPE_GROUPS as $key => $group) {
            $boxes .= '<p><label><input type="checkbox" name="wipe[]" value="'.h($key).'"> '.h($group['label']).'</label></p>';
        }
        page('DocHamster ist bereits installiert', '<p>In <code>'.h($appDir).'</code> läuft DocHamster mit Ihren Daten.</p>'.$available.(! $allOk ? $blocked
            : '<h2>Aktualisieren</h2><p>Alle Belege, Konten und Einstellungen bleiben erhalten.</p>'
            .'<form method="post">'.$csrf.'<input type="hidden" name="action" value="update"><button>Jetzt aktualisieren</button></form>'
            .'<h2>Neu installieren</h2><p>Das Programm wird frisch installiert. Die Zugangsdaten zur Datenbank bleiben immer erhalten.</p>'
            .'<p class="fail"><strong>Haken Sie an, was gelöscht werden soll.</strong> Das lässt sich nicht rückgängig machen. Alles ohne Haken bleibt erhalten.</p>'
            .'<form method="post">'.$csrf.'<input type="hidden" name="action" value="fresh">'.$boxes.'<label for="confirm">Tippen Sie zur Bestätigung LÖSCHEN ein:</label>'
            .'<input id="confirm" name="confirm" autocomplete="off"><button>Neu installieren</button></form>'));
    }

    @set_time_limit(300);
    if (! $allOk) {
        page('Abgebrochen', '<p class="fail">Der Server erfüllt nicht alle Voraussetzungen. Es wurde nichts verändert.</p>');
    }

    // A copy that was never set up holds no data: replace it completely.
    if ($action === 'fresh' && ! $finished) {
        removeTree($appDir);
        header('Location: '.basename(__FILE__));
        exit;
    }

    if (! in_array($action, ['update', 'fresh'], true)) {
        page('Abgebrochen', '<p class="fail">Unbekannte Auswahl, es wurde nichts verändert.</p>');
    }

    $wipe = [];
    if ($action === 'fresh') {
        $wipe = array_values(array_intersect(array_keys(DH_WIPE_GROUPS), (array) ($_POST['wipe'] ?? [])));
        $back = '<a class="button" href="'.h(basename(__FILE__)).'">Zurück</a>';
        if (mb_strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'LÖSCHEN') {
            page('Nicht bestätigt', '<p>Sie haben nicht LÖSCHEN eingetippt, es wurde nichts verändert.</p>'.$back);
        }
        try {
            wipeSelected($appDir, $wipe);
        } catch (Throwable $e) {
            page('Fehler', '<p class="fail">Die Datenbank konnte nicht erreicht werden, deshalb wurde nichts gelöscht. Stimmen die Zugangsdaten noch?</p>'.$back);
        }
    }

    try {
        swapApp($appDir, unpackRelease($release, $tmpDir, $zipFile));
        removeTree($tmpDir);
        if ($outside) {
            wireWebRoot($appDir, $webRoot, false);
        } else {
            file_put_contents($appDir.'/public/.user.ini', DH_USER_INI);
            file_put_contents($appDir.'/.htaccess', "Require all denied\n");
            file_put_contents($appDir.'/public/.htaccess', "Require all granted\n".(string) file_get_contents($appDir.'/public/.htaccess'));
        }
        migrate($appDir);

        // Without users nobody can log in: back to the wizard, which then only asks for the new owner.
        $needsOwner = in_array('people', $wipe, true);
        $code = trim((string) file_get_contents($codeFile));
        if ($needsOwner) {
            $env = (string) file_get_contents($appDir.'/.env');
            file_put_contents($appDir.'/.env', (string) preg_replace('/^DOCHAMSTER_INSTALLED=.*$/m', 'DOCHAMSTER_INSTALLED=false', $env));
            file_put_contents($appDir.'/storage/app/private/setup-code.txt', $code."\n");
        }
    } catch (Throwable $e) {
        @unlink($zipFile);
        removeTree($tmpDir);
        page('Fehler', '<p class="fail">'.h($e->getMessage()).'</p><p>Ihre Einstellungen sind erhalten. Laden Sie die Seite neu, um es noch einmal zu versuchen.</p>');
    }

    @unlink($codeFile);
    @unlink(__FILE__);
    session_destroy();
    $selfDeleted = is_file(__FILE__) ? ' <strong class="fail">install.php konnte sich nicht selbst löschen, bitte löschen Sie sie per FTP!</strong>' : '';
    if ($needsOwner) {
        page('Fast fertig', '<p class="ok">DocHamster '.h($release['version']).' ist installiert.'.$selfDeleted.'</p><p>Jetzt legen Sie noch Ihr Inhaber-Konto an.</p>'
            .'<a class="button" href="/setup?code='.rawurlencode($code).'">Weiter</a>');
    }
    page($action === 'update' ? 'Aktualisiert' : 'Neu installiert', '<p class="ok">DocHamster ist jetzt auf Version '.h($release['version']).'.'.$selfDeleted.'</p><a class="button" href="/">Zu DocHamster</a>');
}

if (! $posted || ! isset($_POST['install'])) {
    $list = '';
    foreach ($checks as $label => $ok) {
        $list .= '<li class="'.($ok ? 'ok' : 'fail').'">'.h($label).($ok ? ' ✔' : ' ✘').'</li>';
    }
    $where = $outside ? '<p>DocHamster wird außerhalb des Webordners installiert (sicher), der Webordner bekommt nur den Einstieg. Dateien, die dort schon liegen und ersetzt werden, bleiben als <code>.bak-…</code> erhalten.</p>'
        : '<p class="fail">Der Ordner über dem Webordner ist nicht beschreibbar. DocHamster wird in <code>'.h($appDir).'</code> installiert. Danach müssen Sie in der Verwaltung Ihres Webhosters den Dokumentenstamm (Zielverzeichnis der Domain) auf <code>'.h($appDir.'/public').'</code> setzen, bevor Sie die Einrichtung starten.</p>';
    page('Server prüfen', '<ul>'.$list.'</ul>'.($release ? '<p>Passende Version: <strong>'.h($release['version']).'</strong> (ab PHP '.h($release['php']).')</p>'.$where : '')
        .($allOk ? '<form method="post">'.$csrf.'<input type="hidden" name="install" value="1"><button>Installieren</button></form>'
            : '<p class="fail">Bitte beheben Sie die rot markierten Punkte (meist in den PHP- bzw. SSL-Einstellungen Ihres Webhosters) und laden Sie die Seite neu.</p>'));
}

// Step 3: download, check, unpack into a temp folder, move into place, wire, hand over.
@set_time_limit(300);
if (! $allOk) {
    page('Abgebrochen', '<p class="fail">Der Server erfüllt nicht alle Voraussetzungen.</p>');
}
try {
    if (! rename(unpackRelease($release, $tmpDir, $zipFile), $appDir)) {
        throw new RuntimeException('Der Ordner konnte nicht an seinen Platz verschoben werden.');
    }
    removeTree($tmpDir);

    if ($outside) {
        // Same code for the wizard's first step – the user already proved access. Outside the web root, so not downloadable.
        @mkdir($appDir.'/storage/app/private', 0700, true);
        file_put_contents($appDir.'/storage/app/private/setup-code.txt', trim((string) file_get_contents($codeFile))."\n");

        foreach (['index.html', 'index.htm'] as $default) {
            if (is_file($webRoot.'/'.$default)) {
                rename($webRoot.'/'.$default, $webRoot.'/'.$default.'.bak-'.date('YmdHis'));
            }
        }
        wireWebRoot($appDir, $webRoot, true);
    } else {
        // Inside the web root: block direct access to everything but public/ (Apache), and no setup code yet –
        // the wizard creates its own once the document root points to public/.
        file_put_contents($appDir.'/public/.user.ini', DH_USER_INI);
        file_put_contents($appDir.'/.htaccess', "Require all denied\n");
        file_put_contents($appDir.'/public/.htaccess', "Require all granted\n".(string) file_get_contents($appDir.'/public/.htaccess'));
    }

    $code = $outside ? trim((string) file_get_contents($codeFile)) : '';
    @unlink($codeFile);
    @unlink(__FILE__);
    session_destroy();
} catch (Throwable $e) {
    @unlink($zipFile);
    removeTree($tmpDir);
    page('Fehler', '<p class="fail">'.h($e->getMessage()).'</p><p>Laden Sie die Seite neu, um es noch einmal zu versuchen.</p>');
}

$next = $outside
    ? '<a class="button" href="/setup?code='.rawurlencode($code).'">Weiter zur Einrichtung</a>'
    : '<p class="fail">Setzen Sie jetzt in der Verwaltung Ihres Webhosters den Dokumentenstamm (Zielverzeichnis der Domain) auf <code>'.h($appDir.'/public').'</code>. Erst danach <code>/setup</code> öffnen – der Assistent zeigt Ihnen dann, wo sein Code liegt.</p>';
page('Dateien installiert', '<p class="ok">DocHamster '.h($release['version']).' ist entpackt. install.php hat sich selbst gelöscht'
    .(is_file(__FILE__) ? ' <strong class="fail">– das hat nicht geklappt, bitte löschen Sie install.php per FTP!</strong>' : '').'.</p>'.$next);
