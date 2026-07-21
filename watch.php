<?php
/**
 * Gmail "Scoreboard" watcher -> depthintranet.com poster.
 *
 * Polls the Gmail inbox over IMAP; for each new email whose subject contains
 * "scoreboard" (case-insensitive), extracts the body text and image
 * attachments (including inline images), uploads the images to the WordPress
 * media library, and creates a JetEngine CCT post in whichever of the
 * configured sections has the oldest latest post.
 *
 * Usage:
 *   php watch.php            normal run (posts + records state)
 *   php watch.php --dry-run  fetches and reports, but does not post or save state
 *
 * Exit codes: 0 = ran (post failures retry next run), 1 = fatal (config/IMAP).
 */

declare(strict_types=1);

// Cron/CLI only - never runnable through the web server.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// --- Boot guard -------------------------------------------------------------
// Runs before anything that can fail (autoload, config) so that a dead-on-
// arrival cron run still leaves evidence in logs/boot.log.
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0775, true);
}
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/boot.log');
ini_set('memory_limit', '512M'); // large photo attachments arrive base64-inflated
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents(__DIR__ . '/logs/boot.log', sprintf(
            "[%s] FATAL: %s in %s:%d (PHP %s)\n",
            date('Y-m-d H:i:s'), $e['message'], $e['file'], $e['line'], PHP_VERSION
        ), FILE_APPEND);
    }
});
if (PHP_VERSION_ID < 80200) {
    $msg = sprintf(
        "[%s] PHP %s is too old - this script needs PHP >= 8.2. Point the cron job at a newer php binary.\n",
        date('Y-m-d H:i:s'), PHP_VERSION
    );
    @file_put_contents(__DIR__ . '/logs/boot.log', $msg, FILE_APPEND);
    fwrite(STDERR, $msg);
    exit(1);
}
// -----------------------------------------------------------------------------

require __DIR__ . '/vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;

const LOG_FILE   = __DIR__ . '/logs/watcher.log';
const STATE_FILE = __DIR__ . '/state/state.json';
const LOCK_FILE  = __DIR__ . '/state/watcher.lock';
const TMP_DIR    = __DIR__ . '/tmp';
const CA_FILE    = __DIR__ . '/certs/cacert.pem'; // Mozilla CA bundle (WAMP PHP has none configured)
const MAX_LOG_BYTES = 5 * 1024 * 1024;
const MAX_IMAGES_PER_POST = 3;

// ---------------------------------------------------------------------------
// Infrastructure
// ---------------------------------------------------------------------------

function log_line(string $level, string $msg): void
{
    $line = sprintf('[%s] [%s] %s', date('Y-m-d H:i:s'), $level, $msg);
    echo $line, PHP_EOL;
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function ensure_dirs(): void
{
    foreach ([dirname(LOG_FILE), dirname(STATE_FILE), TMP_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    if (is_file(LOG_FILE) && filesize(LOG_FILE) > MAX_LOG_BYTES) {
        @rename(LOG_FILE, LOG_FILE . '.1'); // overwrites previous .1
    }
}

function load_config(bool $dryRun): array
{
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        fwrite(STDERR, "config.php not found. Copy config.example.php to config.php and fill it in.\n");
        exit(1);
    }
    $config = require $path;
    if (!is_array($config)) {
        fwrite(STDERR, "config.php must return an array.\n");
        exit(1);
    }
    if (str_contains($config['imap']['password'] ?? '', 'xxxx')) {
        log_line('ERROR', 'config.php still contains a placeholder Gmail App Password.');
        exit(1);
    }
    if (str_contains($config['wordpress']['app_password'] ?? '', 'xxxx')) {
        // Dry-run never authenticates to WordPress, so it may proceed.
        if (!$dryRun) {
            log_line('ERROR', 'config.php still contains a placeholder WP Application Password.');
            exit(1);
        }
        log_line('WARN', 'WP Application Password is still a placeholder - real runs will refuse to start.');
    }
    return $config;
}

/** @return resource */
function acquire_lock()
{
    $fh = fopen(LOCK_FILE, 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
        log_line('INFO', 'Previous run still active (lock held) - exiting.');
        exit(0);
    }
    return $fh;
}

function load_state(): array
{
    $default = ['uidvalidity' => 0, 'last_uid' => 0, 'processed_uids' => [], 'updated_at' => null];
    if (!is_file(STATE_FILE)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents(STATE_FILE), true);
    return is_array($data) ? array_merge($default, $data) : $default;
}

function save_state(array $state): void
{
    $state['processed_uids'] = array_values(array_slice($state['processed_uids'], -500));
    $state['updated_at'] = date('c');
    $tmp = STATE_FILE . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
    rename($tmp, STATE_FILE);
}

// ---------------------------------------------------------------------------
// Email extraction
// ---------------------------------------------------------------------------

function html_to_text(string $html): string
{
    $text = preg_replace('/<\s*(br|\/p|\/div|\/tr|\/li|\/h[1-6])\s*\/?\s*>/i', "\n", $html);
    $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * Strips inline-image placeholders and the sender's signature/disclaimer,
 * keeping only the actual message. Example real body:
 *   "Spanish Air Force at 2DLC DRW. Happy DAFF released!
 *    [image: IMG_20260707_172838626_HDR_AE.jpg]
 *    Best regards,
 *    *Brad Skelton ...* ... DISCLAIMER: ..."
 * -> "Spanish Air Force at 2DLC DRW. Happy DAFF released!"
 */
function clean_body_text(string $text): string
{
    // Gmail renders inline images as "[image: name.jpg]" in the text part -
    // possibly glued to surrounding text and hard-wrapped mid-placeholder,
    // so remove the spans across line breaks before any line handling.
    $text = (string) preg_replace('/\[(image|cid):.*?\]/is', '', $text);

    $lines = preg_split('/\R/', $text);
    $kept = [];
    $inForwardHeader = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Forwarded emails: drop the marker and its From/Date/Subject/To block.
        if (preg_match('/^-{2,}\s*Forwarded message\s*-{2,}$/i', $trimmed)) {
            $inForwardHeader = true;
            continue;
        }
        if ($inForwardHeader) {
            if ($trimmed === '' || preg_match('/^(From|Date|Subject|To|Cc):/i', $trimmed)) {
                continue;
            }
            $inForwardHeader = false; // first real content line
        }
        // Leftover wrapped placeholder fragments, e.g. a lone "IMG_1234.jpg]".
        if (preg_match('/^\[?(image|cid):/i', $trimmed) || preg_match('/^\S+\.(jpg|jpeg|png|gif|webp)\]$/i', $trimmed)) {
            continue;
        }
        // Signature delimiters: exact-line greetings ("Best regards,"), the RFC
        // "-- " separator, or "Sent from my ...". Cutting may empty the body
        // entirely (photo-only emails) - the title then comes from the subject.
        $isDelimiter =
            preg_match('/^(best regards|kind regards|warm regards|regards|cheers|many thanks|thanks|thank you)[,!.]?$/i', $trimmed)
            || $trimmed === '--'
            || preg_match('/^sent from my /i', $trimmed);
        if ($isDelimiter) {
            break;
        }
        $kept[] = $trimmed;
    }
    $text = implode("\n", $kept);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function extract_body_text($message): string
{
    $text = trim((string) $message->getTextBody());
    if ($text === '') {
        $html = (string) $message->getHTMLBody();
        if ($html !== '') {
            $text = html_to_text($html);
        }
    }
    return clean_body_text($text);
}

/**
 * Detects the accepted image formats from their leading magic bytes.
 * (Deliberately not finfo-based: some hosts' CLI PHP lacks ext-fileinfo.)
 */
function sniff_image_mime(string $bytes): ?string
{
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
        return 'image/gif';
    }
    if (strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}

/**
 * Saves image attachments (regular and inline) to TMP_DIR.
 *
 * @return array<int, array{path:string,name:string,mime:string,size:int}>
 */
function extract_images($message, int $uid, array $config): array
{
    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $maxBytes = (int) ($config['max_image_bytes'] ?? 10 * 1024 * 1024);
    $images = [];
    $n = 0;

    foreach ($message->getAttachments() as $attachment) {
        $n++;
        $content = $attachment->getContent();
        if ($content === null || $content === '') {
            continue;
        }
        // Sniff the real type from the bytes; headers lie (application/octet-stream etc).
        $mime = sniff_image_mime($content);
        if ($mime === null) {
            continue; // not an image we accept
        }
        // Signature logos are images too - skip anything implausibly small
        // for a photo.
        $minBytes = (int) ($config['min_image_bytes'] ?? 30 * 1024);
        if (strlen($content) < $minBytes) {
            log_line('INFO', sprintf(
                'uid %d: skipping small image "%s" (%.1f KB) - likely a signature logo.',
                $uid, (string) $attachment->getName(), strlen($content) / 1024
            ));
            continue;
        }
        if (strlen($content) > $maxBytes) {
            log_line('WARN', sprintf(
                'uid %d: skipping oversized image "%s" (%.1f MB)',
                $uid, (string) $attachment->getName(), strlen($content) / 1048576
            ));
            continue;
        }

        $name = trim((string) $attachment->getName());
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        if ($name === '' || $name === '_' || !preg_match('/\.[A-Za-z0-9]+$/', $name)) {
            $name = sprintf('scoreboard_%d_%d.%s', $uid, $n, $extByMime[$mime]);
        }

        $path = TMP_DIR . '/' . $uid . '_' . $n . '_' . $name;
        file_put_contents($path, $content);
        $images[] = ['path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($content)];
    }

    return $images;
}

/**
 * Whatever the subject says beyond the match keyword, cleaned up as a
 * headline: 'scoreboard - "Scott locked & loaded for MRF-D"' -> 'Scott
 * locked & loaded for MRF-D'. Returns '' when the subject is only the
 * keyword (plus Re:/Fwd: noise).
 */
function subject_extra_text(string $subject, string $needle): string
{
    $s = trim((string) preg_replace('/^\s*((re|fwd?)\s*:\s*)+/i', '', $subject));
    $s = trim((string) preg_replace('/' . preg_quote($needle, '/') . '/i', '', $s, 1));
    $s = trim((string) preg_replace('/^[\s\-–—:;,.]+|[\s\-–—:;,.]+$/u', '', $s));
    if (preg_match('/^["\'\x{201C}\x{2018}](.*)["\'\x{201D}\x{2019}]$/su', $s, $m)) {
        $s = trim($m[1]);
    }
    return $s;
}

function record_processed(array &$state, int $uid): void
{
    $state['processed_uids'][] = $uid;
    $state['last_uid'] = max((int) $state['last_uid'], $uid);
    save_state($state);
}

function split_title_description(string $body, string $subjectFallback): array
{
    $body = trim($body);
    if ($body === '') {
        return [trim($subjectFallback) ?: 'Scoreboard update', ''];
    }
    $lines = preg_split('/\R/', $body);
    $title = trim(array_shift($lines));
    $description = trim(implode("\n", $lines));
    return [$title, $description];
}

// ---------------------------------------------------------------------------
// WordPress REST
// ---------------------------------------------------------------------------

/**
 * @return array{code:int, body:string, error:string}
 */
function http_request(string $method, string $url, array $headers, $body, ?string $userPwd, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'PostScoreboardWatcher/1.0 (+internal)',
    ]);
    if (is_file(CA_FILE)) {
        curl_setopt($ch, CURLOPT_CAINFO, CA_FILE);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($userPwd !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $userPwd);
    }
    $responseBody = curl_exec($ch);
    $error = $responseBody === false ? curl_error($ch) : '';
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($responseBody) ? $responseBody : '', 'error' => $error];
}

function wp_auth(array $config): string
{
    $wp = $config['wordpress'];
    return $wp['username'] . ':' . $wp['app_password'];
}

/**
 * Latest schedule_date per section. $localOverrides holds dates for sections
 * we posted to earlier in this same run (defends against response caching).
 *
 * @return string the slug to post to
 */
function pick_target_slug(array $config, array $localOverrides): string
{
    $wp = $config['wordpress'];
    $latest = [];

    foreach ($wp['cct_slugs'] as $slug) {
        $url = rtrim($wp['base_url'], '/') . '/wp-json/jet-cct/' . $slug;
        $res = http_request('GET', $url, ['Accept: application/json'], null, null, (int) $wp['timeout_seconds']);
        $max = '0000-00-00';
        if ($res['code'] === 200) {
            $items = json_decode($res['body'], true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $d = substr(trim((string) ($item['schedule_date'] ?? '')), 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d > $max) {
                        $max = $d;
                    }
                }
            }
        } else {
            log_line('WARN', sprintf(
                'Rotation check GET %s failed (HTTP %d%s) - treating section as oldest.',
                $slug, $res['code'], $res['error'] !== '' ? ', ' . $res['error'] : ''
            ));
        }
        if (isset($localOverrides[$slug]) && $localOverrides[$slug] > $max) {
            $max = $localOverrides[$slug];
        }
        $latest[$slug] = $max;
    }

    // Oldest latest-date wins; array order (config order) breaks ties via <.
    $target = $wp['cct_slugs'][0];
    foreach ($latest as $slug => $date) {
        if ($date < $latest[$target]) {
            $target = $slug;
        }
    }

    log_line('INFO', 'Section latest dates: ' . json_encode($latest) . ' -> target: ' . $target);
    return $target;
}

/**
 * Approved-sender whitelist ("executives") from the intranet.
 *
 * @return array<int,string>|null lowercase email addresses, or null when the
 *         list could not be read (callers must fail safe and retry next run).
 *         An empty API result is also treated as an error - the executive
 *         list should never be empty, so an empty answer means something is
 *         wrong on the WP side, not that nobody may post.
 */
function fetch_approved_senders(array $config): ?array
{
    $wp = $config['wordpress'];
    $path = $wp['approved_senders_path'] ?? '';
    if ($path === '') {
        return []; // feature disabled - no filtering
    }
    $url = rtrim($wp['base_url'], '/') . $path;
    $res = http_request('GET', $url, ['Accept: application/json'], null, wp_auth($config), (int) $wp['timeout_seconds']);
    if ($res['code'] !== 200) {
        log_line('ERROR', sprintf('Approved-senders GET failed: HTTP %d %s', $res['code'], $res['error']));
        return null;
    }
    $users = json_decode($res['body'], true);
    if (!is_array($users)) {
        log_line('ERROR', 'Approved-senders response is not a JSON array.');
        return null;
    }
    $emails = [];
    foreach ($users as $user) {
        $email = mb_strtolower(trim((string) ($user['data']['user_email'] ?? '')));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    if ($emails === []) {
        log_line('ERROR', 'Approved-senders list came back empty - treating as an error.');
        return null;
    }
    foreach ((array) ($wp['extra_approved_senders'] ?? []) as $extra) {
        $extra = mb_strtolower(trim((string) $extra));
        if ($extra !== '' && !in_array($extra, $emails, true)) {
            $emails[] = $extra;
        }
    }
    return $emails;
}

/** @return string the uploaded file's public URL */
function wp_upload_media(array $config, array $image): string
{
    $wp = $config['wordpress'];
    $url = rtrim($wp['base_url'], '/') . '/wp-json/wp/v2/media';
    $res = http_request('POST', $url, [
        'Content-Type: ' . $image['mime'],
        'Content-Disposition: attachment; filename="' . $image['name'] . '"',
        'Accept: application/json',
    ], file_get_contents($image['path']), wp_auth($config), (int) $wp['timeout_seconds']);

    if ($res['code'] < 200 || $res['code'] >= 300) {
        throw new RuntimeException(sprintf(
            'Media upload failed for "%s": HTTP %d %s %s',
            $image['name'], $res['code'], $res['error'], substr($res['body'], 0, 500)
        ));
    }
    $json = json_decode($res['body'], true);
    $sourceUrl = $json['source_url'] ?? ($json['guid']['rendered'] ?? null);
    if (!is_string($sourceUrl) || $sourceUrl === '') {
        throw new RuntimeException('Media upload response had no source_url: ' . substr($res['body'], 0, 500));
    }
    return $sourceUrl;
}

function wp_create_cct_post(array $config, string $slug, array $fields): void
{
    $wp = $config['wordpress'];
    $url = rtrim($wp['base_url'], '/') . '/wp-json/jet-cct/' . $slug;
    $res = http_request('POST', $url, [
        'Content-Type: application/json',
        'Accept: application/json',
    ], json_encode($fields), wp_auth($config), (int) $wp['timeout_seconds']);

    if ($res['code'] < 200 || $res['code'] >= 300) {
        $hint = in_array($res['code'], [401, 403], true)
            ? ' (check the WP Application Password and the JetEngine CCT REST API access settings)'
            : '';
        throw new RuntimeException(sprintf(
            'CCT create failed for %s: HTTP %d %s %s%s',
            $slug, $res['code'], $res['error'], substr($res['body'], 0, 500), $hint
        ));
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

if (defined('POSTSCOREBOARD_NO_MAIN')) {
    return; // included for testing - expose functions only
}

$dryRun = in_array('--dry-run', $argv, true);

ensure_dirs();
$config = load_config($dryRun);
$lock = acquire_lock();

log_line('INFO', '=== Run start' . ($dryRun ? ' (DRY RUN)' : '') . ' ===');

try {
    $imapCfg = $config['imap'];
    $client = (new ClientManager())->make([
        'host'          => $imapCfg['host'],
        'port'          => (int) $imapCfg['port'],
        'encryption'    => $imapCfg['encryption'],
        'validate_cert' => true,
        'username'      => $imapCfg['username'],
        'password'      => $imapCfg['password'],
        'protocol'      => 'imap',
        'ssl_options'   => is_file(CA_FILE) ? ['cafile' => CA_FILE] : [],
    ]);
    $client->connect();
    log_line('INFO', 'IMAP connected: ' . ($client->isConnected() ? 'yes' : 'NO'));
    $wanted = $imapCfg['folder'] ?? 'INBOX';
    $folder = $client->getFolder($wanted);
    if ($folder === null) {
        // Some hosts' PHP builds cannot decode UTF7-IMAP, so every folder's
        // display name comes back empty and name-based lookup fails. The raw
        // IMAP path is unaffected - match on that instead.
        foreach ($client->getFolders(false) as $f) {
            if (strcasecmp((string) $f->path, $wanted) === 0) {
                $folder = $f;
                log_line('INFO', 'Folder matched by raw path (UTF7-IMAP name decoding unavailable on this host).');
                break;
            }
        }
    }
    if ($folder === null) {
        $names = [];
        try {
            foreach ($client->getFolders(false) as $f) {
                $names[] = (string) $f->path;
            }
        } catch (Throwable $e) {
            $names[] = '(listing failed: ' . $e->getMessage() . ')';
        }
        log_line('ERROR', sprintf(
            'IMAP folder "%s" not found. Server offered paths: %s',
            $wanted, implode(', ', $names) ?: '(none)'
        ));
        exit(1);
    }
} catch (Throwable $e) {
    log_line('ERROR', 'IMAP connection failed: ' . get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

$state = load_state();

// UIDVALIDITY guard: if Gmail renumbered UIDs, our state is meaningless.
try {
    $status = $folder->status();
    $uidValidity = (int) ($status['uidvalidity'] ?? 0);
    if ($uidValidity > 0 && (int) $state['uidvalidity'] !== $uidValidity) {
        if ((int) $state['uidvalidity'] !== 0) {
            log_line('WARN', 'UIDVALIDITY changed - resetting dedup state (a recent email may re-post once).');
            $state['last_uid'] = 0;
            $state['processed_uids'] = [];
        }
        $state['uidvalidity'] = $uidValidity;
    }
} catch (Throwable $e) {
    log_line('WARN', 'Could not read folder status: ' . $e->getMessage());
}

$needle = mb_strtolower((string) ($config['match_subject_substring'] ?? 'scoreboard'));

try {
    // Narrow server-side (Gmail SUBJECT search is fast; scanning every recent
    // header takes minutes on this busy inbox) and fetch headers only. Note:
    // Gmail matches whole words, so the configured needle should be a single
    // word. The PHP substring filter below re-verifies every hit.
    $since = new DateTime('-' . (int) ($config['lookback_days'] ?? 3) . ' days');
    $messages = $folder->query()->since($since)->subject($needle)
        ->setFetchBody(false)->leaveUnread()->get();
} catch (Throwable $e) {
    log_line('ERROR', 'IMAP search failed: ' . $e->getMessage());
    exit(1);
}

log_line('INFO', sprintf('Fetched %d message(s) since %s.', count($messages), $since->format('Y-m-d')));

// Collect matching, unprocessed messages, oldest first.
$candidates = [];
foreach ($messages as $message) {
    try {
        $uid = (int) $message->getUid();
        $subject = trim((string) $message->getSubject());
        if (mb_stripos($subject, $needle) === false) {
            continue;
        }
        if ($uid <= (int) $state['last_uid'] || in_array($uid, $state['processed_uids'], true)) {
            continue;
        }
        $candidates[$uid] = $message;
    } catch (Throwable $e) {
        log_line('WARN', 'Skipping unreadable message: ' . $e->getMessage());
    }
}
ksort($candidates);
log_line('INFO', count($candidates) . ' new matching email(s).');

// Only approved senders ("executives", per the intranet contributor API) may
// trigger posts. If the list cannot be fetched, fail safe: process nothing
// this run and retry in 15 minutes rather than posting unvetted emails.
$approvedSenders = [];
if (count($candidates) > 0) {
    $approvedSenders = fetch_approved_senders($config);
    if ($approvedSenders === null) {
        log_line('ERROR', 'Approved-senders list unavailable - deferring all emails to the next run.');
        $candidates = [];
    } elseif ($approvedSenders !== []) {
        log_line('INFO', count($approvedSenders) . ' approved sender(s) on the list.');
    }
}

$postedDates = []; // slug => date posted this run (cache-defense for rotation)
$postedCount = 0;

foreach ($candidates as $uid => $headerMessage) {
    $images = [];
    try {
        $subject = trim((string) $headerMessage->getSubject());
        $sender = $headerMessage->getFrom()->first();
        $from = ($sender && isset($sender->mail)) ? (string) $sender->mail : '(unknown)';

        if ($approvedSenders !== [] && !in_array(mb_strtolower($from), $approvedSenders, true)) {
            log_line('INFO', sprintf('uid %d: sender %s is not an approved contributor - rejected permanently.', $uid, $from));
            if (!$dryRun) {
                record_processed($state, $uid);
            }
            continue;
        }

        // Now fetch the full message (body + attachments) for this one email.
        $message = $folder->query()->leaveUnread()->getMessageByUid($uid);
        $body = extract_body_text($message);
        // Senders sometimes put the message in the subject itself
        // ('scoreboard - "Scott locked & loaded..."') with a photo-only body.
        $subjectExtra = subject_extra_text($subject, $needle);
        if ($subjectExtra !== '') {
            $title = $subjectExtra;
            $description = $body;
        } else {
            [$title, $description] = split_title_description($body, $subject);
        }
        $images = extract_images($message, $uid, $config);

        // A real scoreboard post always carries a photo. Emails that merely
        // mention "scoreboard" in a conversation subject have none - reject
        // them rather than publish chatter on the intranet.
        if ($images === []) {
            log_line('INFO', sprintf(
                'uid %d: "%s" from %s has no usable image - not a scoreboard post, rejected permanently.',
                $uid, $subject, $from
            ));
            if (!$dryRun) {
                record_processed($state, $uid);
            }
            continue;
        }

        log_line('INFO', sprintf(
            'uid %d: "%s" from %s - title "%s", %d image(s)%s',
            $uid, $subject, $from, mb_substr($title, 0, 80), count($images),
            count($images) > MAX_IMAGES_PER_POST ? ' (only first ' . MAX_IMAGES_PER_POST . ' used)' : ''
        ));

        if ($dryRun) {
            foreach ($images as $img) {
                log_line('INFO', sprintf('  dry-run image: %s (%s, %.1f KB)', $img['name'], $img['mime'], $img['size'] / 1024));
            }
            log_line('INFO', '  dry-run target: ' . pick_target_slug($config, $postedDates));
            continue;
        }

        $target = pick_target_slug($config, $postedDates);

        $urls = [];
        foreach (array_slice($images, 0, MAX_IMAGES_PER_POST) as $img) {
            $urls[] = wp_upload_media($config, $img);
        }

        $today = date('Y-m-d');
        $fields = [
            'title'          => $title,
            'description'    => $description,
            'featured_image' => $urls[0] ?? null,
            'image_2'        => $urls[1] ?? null,
            'image_3'        => $urls[2] ?? null,
            'schedule_date'  => $today,
            'cct_status'     => 'publish',
        ];
        // JetEngine declares the image params as type string - null is rejected
        // with rest_invalid_param, so absent images must be omitted entirely.
        wp_create_cct_post($config, $target, array_filter($fields, fn ($v) => $v !== null));

        log_line('INFO', sprintf('uid %d: posted to %s (%d image(s), schedule_date %s).', $uid, $target, count($urls), $today));

        $postedDates[$target] = $today;
        $postedCount++;
        record_processed($state, $uid);

        if (!empty($config['mark_processed_seen'])) {
            try {
                $message->setFlag('Seen');
            } catch (Throwable $e) {
                log_line('WARN', "uid $uid: could not mark as seen: " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        log_line('ERROR', "uid $uid: " . $e->getMessage() . ' - will retry next run.');
    } finally {
        foreach ($images as $img) {
            @unlink($img['path']);
        }
    }
}

// New posts sit behind WP Rocket's page cache until it is cleared (same
// endpoint checkwinner uses). Non-fatal: the cache expires on its own.
$cacheClearUrl = (string) ($config['cache_clear_url']
    ?? 'https://scoreboard.depthintranet.com/wp-json/custom/v1/clear-cache');
if ($postedCount > 0 && $cacheClearUrl !== '' && !$dryRun) {
    // Shared secret expected by the endpoint's permission_callback, derived
    // from the WP app password so it never has to be stored separately.
    $cacheKey = substr(sha1('cache-clear:' . (string) $config['wordpress']['app_password']), 0, 20);
    $res = http_request('POST', $cacheClearUrl, ['Accept: application/json', 'X-Cache-Key: ' . $cacheKey], null, null, 30);
    if ($res['code'] === 200) {
        log_line('INFO', 'WP Rocket cache cleared.');
    } else {
        log_line('WARN', sprintf('Cache clear failed: HTTP %d %s %s', $res['code'], $res['error'], substr($res['body'], 0, 200)));
    }
}

log_line('INFO', '=== Run end ===');
flock($lock, LOCK_UN);
exit(0);
