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
    $lines = preg_split('/\R/', $text);
    $kept = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Gmail renders inline images as "[image: name.jpg]" in the text part.
        if (preg_match('/^\[(image|cid):[^\]]*\]$/i', $trimmed)) {
            continue;
        }
        // Signature delimiters: exact-line greetings ("Best regards,"), the RFC
        // "-- " separator, or "Sent from my ...". Never cut on the first content
        // line, so a message like "Cheers!" or "Thanks Tim!" survives.
        $isDelimiter =
            preg_match('/^(best regards|kind regards|warm regards|regards|cheers|many thanks|thanks|thank you)[,!.]?$/i', $trimmed)
            || $trimmed === '--'
            || preg_match('/^sent from my /i', $trimmed);
        if ($isDelimiter && count(array_filter($kept, 'strlen')) > 0) {
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
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $images = [];
    $n = 0;

    foreach ($message->getAttachments() as $attachment) {
        $n++;
        $content = $attachment->getContent();
        if ($content === null || $content === '') {
            continue;
        }
        // Sniff the real type from the bytes; headers lie (application/octet-stream etc).
        $mime = (string) $finfo->buffer($content);
        if (!isset($extByMime[$mime])) {
            continue; // not an image we accept
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
    $folder = $client->getFolder($imapCfg['folder'] ?? 'INBOX');
} catch (Throwable $e) {
    log_line('ERROR', 'IMAP connection failed: ' . $e->getMessage());
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

$postedDates = []; // slug => date posted this run (cache-defense for rotation)

foreach ($candidates as $uid => $headerMessage) {
    $images = [];
    try {
        $subject = trim((string) $headerMessage->getSubject());
        $sender = $headerMessage->getFrom()->first();
        $from = ($sender && isset($sender->mail)) ? (string) $sender->mail : '(unknown)';

        // Now fetch the full message (body + attachments) for this one email.
        $message = $folder->query()->leaveUnread()->getMessageByUid($uid);
        $body = extract_body_text($message);
        [$title, $description] = split_title_description($body, $subject);
        $images = extract_images($message, $uid, $config);

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
        $state['processed_uids'][] = $uid;
        $state['last_uid'] = max((int) $state['last_uid'], $uid);
        save_state($state);

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

log_line('INFO', '=== Run end ===');
flock($lock, LOCK_UN);
exit(0);
