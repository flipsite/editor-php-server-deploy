<?php
/**
 * Flipsite deploy script
 * https://github.com/flipsite/editor-php-server-deploy
 *
 * Publishes a Flipsite site to this folder of a PHP web hotel. Flipsite sends a signed request
 * with the address of the site as a ZIP file, this script downloads it, checks it and replaces
 * the files of the site with the new ones.
 *
 * Install: put this file in the folder of your site (the web root, often public_html or www),
 * then click Test connection in the Flipsite editor, in Settings > Publish.
 *
 * Needs PHP 7.4 or newer with the zip extension (ZipArchive), and curl or allow_url_fopen.
 */

// --- SETTINGS -------------------------------------------------------------------------------

// The secret of your site, from the Flipsite editor (Settings > Publish > PHP Server). The script
// downloaded from the editor has it filled in. Only Flipsite knows it, keep it that way.
$flipsiteSecret = '';

// Files and folders in this folder that publishing never deletes, for example the files of your
// web hotel. Add your own. A kept file is not replaced by the one of the site, the files of the
// site are added to a kept folder. Remove .htaccess if Flipsite should publish its redirects in
// .htaccess (Redirects: Apache in Settings > Publish).
$flipsiteKeep = [
    '.htaccess',
    '.htpasswd',
    '.user.ini',
    '.well-known',
    'cgi-bin',
];

// --- END OF SETTINGS ------------------------------------------------------------------------

const FLIPSITE_DEPLOY_VERSION = '1.0.0';

// How old a request can be, in seconds, so a request that was listened in on can't be sent again
const FLIPSITE_DEPLOY_MAX_AGE = 300;

// The folder where the script works, removed after each publish
const FLIPSITE_DEPLOY_WORK_DIR = '.flipsite-deploy';

// Files in the ZIP that are never unpacked: a static site has no server code
const FLIPSITE_DEPLOY_SKIPPED_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh'];

class FlipsiteDeployException extends Exception
{
}

/**
 * Checks the signature and the age of a request. The signature is the HMAC-SHA256 of
 * "{timestamp}.{body}" with the secret, in hex.
 *
 * @throws FlipsiteDeployException
 */
function flipsite_deploy_verify(string $secret, string $body, string $timestamp, string $signature, ?int $now = null): void
{
    if ('' === $secret) {
        throw new FlipsiteDeployException('The secret is not set in flipsite-deploy.php. Download the script again from the Flipsite editor, in Settings > Publish.', 500);
    }
    if ('' === $signature || '' === $timestamp || !ctype_digit($timestamp)) {
        throw new FlipsiteDeployException('The request is not signed.', 401);
    }
    $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    if (!hash_equals($expected, strtolower(preg_replace('/^sha256=/i', '', $signature)))) {
        throw new FlipsiteDeployException('The secret does not match. Download the script again from the Flipsite editor and upload it.', 401);
    }
    if (abs(($now ?? time()) - (int)$timestamp) > FLIPSITE_DEPLOY_MAX_AGE) {
        throw new FlipsiteDeployException('The request is too old. Check that the clock of the server is right.', 401);
    }
}

/**
 * Downloads a URL to a file, or its first bytes when $maxBytes is set
 *
 * @throws FlipsiteDeployException
 */
function flipsite_deploy_download(string $url, string $file, ?int $maxBytes = null): void
{
    if (!preg_match('#^https?://#i', $url)) {
        throw new FlipsiteDeployException('The address of the ZIP file is not valid.', 400);
    }
    if (function_exists('curl_init')) {
        $handle = fopen($file, 'wb');
        if (false === $handle) {
            throw new FlipsiteDeployException('Could not write in the folder of the site.', 500);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FAILONERROR    => true,
        ]);
        if (null !== $maxBytes) {
            curl_setopt($ch, CURLOPT_RANGE, '0-'.($maxBytes - 1));
        }
        $ok    = curl_exec($ch);
        $error = curl_error($ch);
        // Needed on PHP 7, deprecated since PHP 8.5
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        fclose($handle);
        if (!$ok) {
            throw new FlipsiteDeployException('Could not download the site from Flipsite: '.$error, 502);
        }
        return;
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new FlipsiteDeployException('The server can not download files: neither curl nor allow_url_fopen is turned on. Ask your web hotel to turn one of them on.', 500);
    }
    $context = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => 1]]);
    $content = null === $maxBytes ? @file_get_contents($url, false, $context) : @file_get_contents($url, false, $context, 0, $maxBytes);
    if (false === $content || false === @file_put_contents($file, $content)) {
        throw new FlipsiteDeployException('Could not download the site from Flipsite.', 502);
    }
}

/**
 * Whether a file of the ZIP is unpacked. Never outside the folder, never server code, never the
 * script itself.
 */
function flipsite_deploy_allowed_entry(string $name, string $scriptName): bool
{
    if ('' === $name || false !== strpos($name, '\\') || false !== strpos($name, "\0")) {
        return false;
    }
    if ('/' === $name[0] || preg_match('#^[a-z]:#i', $name)) {
        return false;
    }
    foreach (explode('/', rtrim($name, '/')) as $part) {
        if ('..' === $part || '.' === $part || '' === $part) {
            return false;
        }
    }
    if (rtrim($name, '/') === $scriptName || 0 === strpos($name, FLIPSITE_DEPLOY_WORK_DIR.'/')) {
        return false;
    }
    if ('/' === substr($name, -1)) {
        return true;
    }
    return !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), FLIPSITE_DEPLOY_SKIPPED_EXTENSIONS, true);
}

/**
 * Deletes a file or a folder with everything in it
 */
function flipsite_deploy_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

/**
 * Moves a file or folder of the new site into place. A folder that is there (a kept one) gets the
 * files added to it.
 *
 * @throws FlipsiteDeployException
 */
function flipsite_deploy_move(string $from, string $to): void
{
    if (is_dir($from) && is_dir($to) && !is_link($to)) {
        foreach (scandir($from) ?: [] as $name) {
            if ('.' !== $name && '..' !== $name) {
                flipsite_deploy_move($from.'/'.$name, $to.'/'.$name);
            }
        }
        return;
    }
    if (file_exists($to) || is_link($to)) {
        flipsite_deploy_remove($to);
    }
    if (!@rename($from, $to)) {
        throw new FlipsiteDeployException('Could not move '.basename($from).' into the folder of the site.', 500);
    }
}

/**
 * Whether a top-level file or folder of the site is left alone
 *
 * @param array<string> $keep
 */
function flipsite_deploy_is_kept(string $name, array $keep, string $scriptName): bool
{
    return $name === $scriptName || FLIPSITE_DEPLOY_WORK_DIR === $name || in_array($name, $keep, true);
}

/**
 * Publishes the ZIP file to the folder: unpacks it next to the site first, and only when that
 * worked replaces the files of the site, except the kept ones
 *
 * @param array<string, mixed> $download The download of the request: url, md5
 * @param array<string>        $keep
 *
 * @return array<string, mixed> What was done
 *
 * @throws FlipsiteDeployException
 */
function flipsite_deploy_publish(array $download, string $dir, array $keep, string $scriptName): array
{
    $url = (string)($download['url'] ?? '');
    $md5 = strtolower((string)($download['md5'] ?? ''));
    if ('' === $url) {
        throw new FlipsiteDeployException('The request has no ZIP file.', 400);
    }
    $work = $dir.'/'.FLIPSITE_DEPLOY_WORK_DIR;
    flipsite_deploy_remove($work);
    if (!@mkdir($work, 0755) || !@mkdir($work.'/site', 0755)) {
        throw new FlipsiteDeployException('Could not write in the folder of the site. Ask your web hotel to let PHP write in it.', 500);
    }
    try {
        $zipFile = $work.'/site.zip';
        flipsite_deploy_download($url, $zipFile);
        if ('' !== $md5 && md5_file($zipFile) !== $md5) {
            throw new FlipsiteDeployException('The downloaded ZIP file is not complete. Publish again.', 502);
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($zipFile)) {
            throw new FlipsiteDeployException('Could not open the ZIP file.', 502);
        }
        $entries = [];
        $skipped = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            // A kept file stays, the files in a kept folder are added to it
            $keptFile = in_array(rtrim($name, '/'), $keep, true) && is_file($dir.'/'.rtrim($name, '/'));
            if (!flipsite_deploy_allowed_entry($name, $scriptName) || $keptFile) {
                $skipped[] = $name;
                continue;
            }
            $entries[] = $name;
        }
        if (!$entries) {
            $zip->close();
            throw new FlipsiteDeployException('The ZIP file has no files to publish.', 502);
        }
        if (!$zip->extractTo($work.'/site', $entries)) {
            $zip->close();
            throw new FlipsiteDeployException('Could not unpack the ZIP file. Check that there is space left on the server.', 500);
        }
        $zip->close();

        // The new site is ready, replace the old one
        $removed = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if ('.' === $name || '..' === $name || flipsite_deploy_is_kept($name, $keep, $scriptName)) {
                continue;
            }
            flipsite_deploy_remove($dir.'/'.$name);
            $removed++;
        }
        $added = 0;
        foreach (scandir($work.'/site') ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            flipsite_deploy_move($work.'/site/'.$name, $dir.'/'.$name);
            $added++;
        }
    } finally {
        flipsite_deploy_remove($work);
    }

    return ['added' => $added, 'removed' => $removed, 'skipped' => $skipped];
}

/**
 * What the server has and needs, for Test connection in the editor
 *
 * @param array<string, mixed> $download Optional, a ZIP file to try to download
 *
 * @return array<string, mixed>
 */
function flipsite_deploy_ping(array $download, string $dir): array
{
    $problems = [];
    $zip      = class_exists('ZipArchive');
    if (!$zip) {
        $problems[] = 'PHP on the server has no zip extension (ZipArchive). Ask your web hotel to turn it on.';
    }
    $writable = is_writable($dir);
    if (!$writable) {
        $problems[] = 'PHP can not write in the folder of the site. Ask your web hotel to let PHP write in it.';
    }
    $canDownload = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    if (!$canDownload) {
        $problems[] = 'The server can not download files: neither curl nor allow_url_fopen is turned on. Ask your web hotel to turn one of them on.';
    } elseif ($writable && '' !== (string)($download['url'] ?? '')) {
        $file = $dir.'/'.FLIPSITE_DEPLOY_WORK_DIR.'-ping';
        try {
            flipsite_deploy_download((string)$download['url'], $file, 1024);
        } catch (FlipsiteDeployException $e) {
            $canDownload = false;
            $problems[]  = $e->getMessage().' The web hotel may block downloads from other servers.';
        }
        @unlink($file);
    }
    return [
        'success'  => [] === $problems,
        'script'   => 'flipsite-deploy',
        'version'  => FLIPSITE_DEPLOY_VERSION,
        'php'      => PHP_VERSION,
        'checks'   => ['zip' => $zip, 'writable' => $writable, 'download' => $canDownload],
        'problems' => $problems,
    ];
}

/**
 * Answers a request
 *
 * @param array<string> $keep
 *
 * @return array{0: int, 1: array<string, mixed>} The HTTP status and the JSON
 */
function flipsite_deploy_handle(string $method, string $body, array $headers, string $secret, string $dir, array $keep, string $scriptName): array
{
    try {
        if ('POST' !== $method) {
            throw new FlipsiteDeployException('This is the Flipsite deploy script. Flipsite publishes to it, it has nothing to show.', 405);
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        flipsite_deploy_verify($secret, $body, (string)($headers['x-flipsite-timestamp'] ?? ''), (string)($headers['x-flipsite-signature'] ?? ''));
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new FlipsiteDeployException('The request is not valid.', 400);
        }
        $download = is_array($payload['download'] ?? null) ? $payload['download'] : [];
        switch ($payload['event'] ?? '') {
            case 'flipsite.ping':
                $answer = flipsite_deploy_ping($download, $dir);
                return [200, $answer];
            case 'flipsite.publish':
                if (!class_exists('ZipArchive')) {
                    throw new FlipsiteDeployException('PHP on the server has no zip extension (ZipArchive). Ask your web hotel to turn it on.', 500);
                }
                $result = flipsite_deploy_publish($download, $dir, $keep, $scriptName);
                return [200, ['success' => true, 'version' => FLIPSITE_DEPLOY_VERSION, 'published' => $download['version'] ?? null] + $result];
        }
        throw new FlipsiteDeployException('Unknown event.', 400);
    } catch (FlipsiteDeployException $e) {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        return [$status, ['error' => $e->getMessage(), 'version' => FLIPSITE_DEPLOY_VERSION]];
    } catch (Throwable $e) {
        return [500, ['error' => 'The deploy script failed: '.$e->getMessage(), 'version' => FLIPSITE_DEPLOY_VERSION]];
    }
}

// Tests include the script for its functions
if (!defined('FLIPSITE_DEPLOY_TEST')) {
    @set_time_limit(300);
    $flipsiteHeaders = [
        'X-Flipsite-Timestamp' => $_SERVER['HTTP_X_FLIPSITE_TIMESTAMP'] ?? '',
        'X-Flipsite-Signature' => $_SERVER['HTTP_X_FLIPSITE_SIGNATURE'] ?? '',
    ];
    [$flipsiteStatus, $flipsiteAnswer] = flipsite_deploy_handle(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string)file_get_contents('php://input'),
        $flipsiteHeaders,
        $flipsiteSecret,
        __DIR__,
        $flipsiteKeep,
        basename(__FILE__)
    );
    http_response_code($flipsiteStatus);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($flipsiteAnswer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
