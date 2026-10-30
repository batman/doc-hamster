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
const DH_MAX_AGE_SECONDS = 7200;
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
        .'input{width:100%;padding:.6rem;font:inherit;box-sizing:border-box}button,.button{display:inline-block;margin-top:1rem;padding:.7rem 1.4rem;'
        .'border:0;border-radius:6px;background:#2b6cb0;color:#fff;font:inherit;text-decoration:none;cursor:pointer}'
        .'.ok{color:#2f855a}.fail{color:#c53030}code{background:#eee;padding:.1rem .3rem;word-break:break-all}</style></head><body>'
        .'<p>DocHamster · Installation</p><h1>'.h($title).'</h1>'.$body.'</body></html>';
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
function placeFile(string $path, string $content): void
{
    if (is_file($path) && (string) file_get_contents($path) !== $content) {
        rename($path, $path.'.bak-'.date('YmdHis'));
    }
    file_put_contents($path, $content);
}

// The installer stays usable for two hours after its first run (not after upload: FTP clients may keep old file dates).
$codeFile = codeFile($webRoot, $outside);
if (is_file($codeFile) && time() - (int) filemtime($codeFile) > DH_MAX_AGE_SECONDS) {
    page('Abgelaufen', '<p class="fail">Diese Installation wurde vor mehr als zwei Stunden begonnen und ist aus Sicherheitsgründen gesperrt. Bitte löschen Sie die Datei <code>'.h($codeFile).'</code> und laden Sie install.php neu.</p>');
}
// The app folder only appears after a complete unpack (rename at the end), so it marks a finished install.
if (is_file($appDir.'/artisan')) {
    page('Bereits installiert', '<p>DocHamster liegt schon in <code>'.h($appDir).'</code>. Bitte löschen Sie install.php per FTP und öffnen Sie <code>/setup</code>.</p>');
}
if (! is_file($codeFile)) {
    $code = strtoupper(substr(bin2hex(random_bytes(10)), 0, 16));
    if (@file_put_contents($codeFile, implode('-', str_split($code, 4))."\n") === false) {
        page('Keine Schreibrechte', '<p class="fail">install.php darf in diesem Ordner keine Dateien anlegen. Bitte geben Sie dem Webserver Schreibrechte und laden Sie die Seite neu.</p>');
    }
}
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$csrf = '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
$httpsWarning = $https ? '' : '<p class="fail">Achtung: Diese Seite läuft ohne HTTPS. DocHamster braucht ein gültiges Zertifikat – bitte zuerst in der Verwaltung Ihres Webhosters HTTPS einschalten.</p>';

// Step 1: prove access to the web space with the code from the file.
if (empty($_SESSION['unlocked'])) {
    $error = '';
    if ($posted && isset($_POST['code'])) {
        $norm = static function (string $s): string {
            return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
        };
        if (hash_equals($norm((string) file_get_contents($codeFile)), $norm((string) $_POST['code']))) {
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
$tmpDir = dirname($appDir).'/dochamster-install-tmp';
$zipFile = dirname($appDir).'/dochamster-download.zip';
try {
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
    if (! rename($tmpDir.'/dochamster', $appDir)) {
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
        foreach (['.htaccess', 'favicon.ico', 'robots.txt'] as $file) {
            placeFile($webRoot.'/'.$file, (string) file_get_contents($appDir.'/public/'.$file));
        }
        @mkdir($webRoot.'/css');
        foreach (glob($appDir.'/public/css/*') ?: [] as $css) {
            placeFile($webRoot.'/css/'.basename($css), (string) file_get_contents($css));
        }
        $index = (string) file_get_contents($appDir.'/public/index.php');
        placeFile($webRoot.'/index.php', str_replace("__DIR__.'/../", var_export($appDir.'/', true).".'", $index));
    } else {
        // Inside the web root: block direct access to everything but public/ (Apache), and no setup code yet –
        // the wizard creates its own once the document root points to public/.
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
    ? '<p>Im nächsten Schritt fragt der Assistent noch einmal nach dem Code. Er lautet: <code>'.h($code).'</code></p><a class="button" href="/setup">Weiter zur Einrichtung</a>'
    : '<p class="fail">Setzen Sie jetzt in der Verwaltung Ihres Webhosters den Dokumentenstamm (Zielverzeichnis der Domain) auf <code>'.h($appDir.'/public').'</code>. Erst danach <code>/setup</code> öffnen – der Assistent zeigt Ihnen dann, wo sein Code liegt.</p>';
page('Dateien installiert', '<p class="ok">DocHamster '.h($release['version']).' ist entpackt. install.php hat sich selbst gelöscht'
    .(is_file(__FILE__) ? ' <strong class="fail">– das hat nicht geklappt, bitte löschen Sie install.php per FTP!</strong>' : '').'.</p>'.$next);
