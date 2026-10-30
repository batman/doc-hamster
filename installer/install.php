<?php

declare(strict_types=1);

/*
 * DocHamster web installer – upload only this file to your web space and open it in the browser.
 *
 * It checks the server, downloads the newest release that fits your PHP version from GitHub,
 * verifies its SHA-256, unpacks it (outside the web root where possible), wires the web root,
 * hands over to the setup wizard (/setup) and deletes itself.
 *
 * Plain PHP without dependencies; syntax kept compatible with old PHP versions so it can still
 * say "PHP too old" instead of failing silently.
 */

const DH_REPO = 'batman/doc-hamster';
const DH_MAX_AGE_SECONDS = 7200;
const DH_EXTENSIONS = ['pdo_mysql', 'mbstring', 'intl', 'sodium', 'fileinfo', 'exif', 'zip', 'gd', 'curl'];

session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$webRoot = __DIR__;
$outside = @is_writable(dirname($webRoot));
$appDir = ($outside ? dirname($webRoot) : $webRoot).'/dochamster';

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

function http(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'DocHamster-Installer', CURLOPT_HTTPHEADER => ['Accept: application/json, application/octet-stream'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (! is_string($body) || $status !== 200) {
        throw new RuntimeException('Download fehlgeschlagen: '.$url.' (HTTP '.$status.')');
    }

    return $body;
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

if (time() - (int) filemtime(__FILE__) > DH_MAX_AGE_SECONDS) {
    page('Abgelaufen', '<p class="fail">Diese Installationsdatei ist älter als zwei Stunden und aus Sicherheitsgründen gesperrt. Bitte laden Sie install.php neu hoch.</p>');
}
if (is_file($appDir.'/artisan')) {
    page('Bereits installiert', '<p>DocHamster liegt schon in <code>'.h($appDir).'</code>. Bitte löschen Sie install.php und öffnen Sie <a href="/setup">/setup</a>.</p>');
}

$codeFile = codeFile($webRoot, $outside);
if (! is_file($codeFile)) {
    $code = strtoupper(substr(bin2hex(random_bytes(10)), 0, 16));
    if (@file_put_contents($codeFile, implode('-', str_split($code, 4))."\n") === false) {
        page('Keine Schreibrechte', '<p class="fail">install.php darf in diesem Ordner keine Dateien anlegen. Bitte geben Sie dem Webserver Schreibrechte und laden Sie die Seite neu.</p>');
    }
}
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$csrf = '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));

// Step 1: prove access to the server with the code from the file.
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
    page('Willkommen', '<p>Zuerst zeigen Sie, dass Ihnen dieser Webspace gehört. Öffnen Sie mit Ihrem FTP-Programm oder dem Dateimanager diese Datei und tippen Sie den Code ein:</p>'
        .'<p><code>'.h($codeFile).'</code></p>'.$error.'<form method="post">'.$csrf.'<input name="code" autocomplete="off" required autofocus><button>Weiter</button></form>');
}

// Step 2: check the server and pick the release.
$checks = ['PHP '.PHP_VERSION => true];
foreach (DH_EXTENSIONS as $ext) {
    $checks['Erweiterung '.$ext] = extension_loaded($ext);
}
$checks['Schreibrechte für '.dirname($appDir)] = @is_writable(dirname($appDir));
try {
    $release = extension_loaded('curl') ? chooseRelease() : null;
} catch (Throwable $e) {
    $release = null;
    $checks[$e->getMessage()] = false;
}
$allOk = $release !== null && ! in_array(false, $checks, true);

if (! $posted || ! isset($_POST['install'])) {
    $list = '';
    foreach ($checks as $label => $ok) {
        $list .= '<li class="'.($ok ? 'ok' : 'fail').'">'.h($label).($ok ? ' ✔' : ' ✘').'</li>';
    }
    $where = $outside ? '<p>DocHamster wird außerhalb des Webordners installiert (sicher), der Webordner bekommt nur den Einstieg.</p>'
        : '<p class="fail">Der Ordner über dem Webordner ist nicht beschreibbar. DocHamster wird in <code>'.h($appDir).'</code> installiert; danach müssen Sie in Plesk den Dokumentenstamm auf <code>'.h($appDir.'/public').'</code> setzen.</p>';
    page('Server prüfen', '<ul>'.$list.'</ul>'.($release ? '<p>Passende Version: <strong>'.h($release['version']).'</strong> (ab PHP '.h($release['php']).')</p>'.$where : '')
        .($allOk ? '<form method="post">'.$csrf.'<input type="hidden" name="install" value="1"><button>Installieren</button></form>'
            : '<p class="fail">Bitte beheben Sie die rot markierten Punkte (meist in den PHP-Einstellungen bei Plesk) und laden Sie die Seite neu.</p>'));
}

// Step 3: download, verify, unpack, wire, hand over.
@set_time_limit(300);
if (! $allOk) {
    page('Abgebrochen', '<p class="fail">Der Server erfüllt nicht alle Voraussetzungen.</p>');
}
try {
    $zipData = http($release['url']);
    if (! hash_equals((string) $release['sha256'], hash('sha256', $zipData))) {
        throw new RuntimeException('Prüfsumme stimmt nicht – Download beschädigt oder manipuliert.');
    }
    $zipFile = dirname($appDir).'/dochamster-download.zip';
    file_put_contents($zipFile, $zipData);
    unset($zipData);

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
    $zip->extractTo(dirname($appDir));
    $zip->close();
    @unlink($zipFile);

    // Same code for the setup wizard's first step – the user already proved access.
    @mkdir($appDir.'/storage/app/private', 0700, true);
    $code = trim((string) file_get_contents($codeFile));
    file_put_contents($appDir.'/storage/app/private/setup-code.txt', $code."\n");

    if ($outside) {
        foreach (['index.html', 'index.htm'] as $default) {
            if (is_file($webRoot.'/'.$default)) {
                @rename($webRoot.'/'.$default, $webRoot.'/'.$default.'.bak');
            }
        }
        foreach (['.htaccess', 'favicon.ico', 'robots.txt'] as $file) {
            @copy($appDir.'/public/'.$file, $webRoot.'/'.$file);
        }
        @mkdir($webRoot.'/css');
        foreach (glob($appDir.'/public/css/*') ?: [] as $css) {
            @copy($css, $webRoot.'/css/'.basename($css));
        }
        $index = (string) file_get_contents($appDir.'/public/index.php');
        file_put_contents($webRoot.'/index.php', str_replace("__DIR__.'/../", var_export($appDir.'/', true).".'", $index));
    }

    @unlink($codeFile);
    @unlink(__FILE__);
    session_destroy();
} catch (Throwable $e) {
    page('Fehler', '<p class="fail">'.h($e->getMessage()).'</p><p>Laden Sie die Seite neu, um es noch einmal zu versuchen.</p>');
}

$next = $outside ? '<a class="button" href="/setup">Weiter zur Einrichtung</a>'
    : '<p>Setzen Sie jetzt in Plesk den Dokumentenstamm auf <code>'.h($appDir.'/public').'</code> und öffnen Sie danach <code>/setup</code>.</p>';
page('Dateien installiert', '<p class="ok">DocHamster '.h($release['version']).' ist entpackt. install.php hat sich selbst gelöscht'
    .(is_file(__FILE__) ? ' <strong class="fail">– das hat nicht geklappt, bitte löschen Sie install.php per FTP!</strong>' : '').'.</p>'
    .'<p>Im nächsten Schritt fragt der Assistent noch einmal nach dem Code. Er lautet: <code>'.h($code ?? '').'</code></p>'.$next);
