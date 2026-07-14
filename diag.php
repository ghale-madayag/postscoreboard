<?php
/**
 * TEMPORARY server diagnostic - delete after troubleshooting.
 *
 * Access requires ?key=<first 16 hex chars of sha1(WP app password)> so only
 * someone who already knows the credentials in config.php can view it.
 */

declare(strict_types=1);

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : null;
$expected = is_array($config) ? substr(sha1((string) ($config['wordpress']['app_password'] ?? '')), 0, 16) : null;
if ($expected === null || !hash_equals($expected, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

if (isset($_GET['skipuids'])) {
    // Mark comma-separated message UIDs as already processed so the watcher
    // never posts them (used to clear duplicate test emails from the queue).
    $stateFile = __DIR__ . '/state/state.json';
    $state = is_file($stateFile) ? (array) json_decode((string) file_get_contents($stateFile), true) : [];
    $state += ['uidvalidity' => 1, 'last_uid' => 0, 'processed_uids' => []];
    $added = [];
    foreach (explode(',', (string) $_GET['skipuids']) as $uid) {
        $uid = (int) trim($uid);
        if ($uid > 0 && !in_array($uid, $state['processed_uids'], true)) {
            $state['processed_uids'][] = $uid;
            $state['last_uid'] = max((int) $state['last_uid'], $uid);
            $added[] = $uid;
        }
    }
    $state['updated_at'] = date('c');
    file_put_contents($stateFile . '.tmp', json_encode($state, JSON_PRETTY_PRINT));
    rename($stateFile . '.tmp', $stateFile);
    echo 'skipuids: added [' . implode(', ', $added) . "], last_uid={$state['last_uid']}\n\n";
}

if (isset($_GET['fixperms'])) {
    // Repair permissions broken by zip extraction: dirs 0755, files 0644.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $dirs = $files = $errors = 0;
    @chmod(__DIR__, 0755);
    foreach ($iterator as $path => $info) {
        if (str_contains(str_replace('\\', '/', (string) $path), '/.git/')) {
            continue;
        }
        if ($info->isDir()) {
            @chmod($path, 0755) ? $dirs++ : $errors++;
        } else {
            @chmod($path, 0644) ? $files++ : $errors++;
        }
    }
    echo "fixperms done: dirs=$dirs files=$files errors=$errors\n\n";
}

echo "PHP (web): " . PHP_VERSION . " sapi=" . PHP_SAPI . "\n";
foreach (['curl', 'openssl', 'mbstring', 'iconv', 'fileinfo'] as $ext) {
    echo "ext $ext: " . (extension_loaded($ext) ? 'yes' : 'NO') . "\n";
}
echo "dir: " . __DIR__ . "\n";
echo "dir writable: " . (is_writable(__DIR__) ? 'yes' : 'NO') . "\n";
echo "vendor/autoload.php: " . (is_file(__DIR__ . '/vendor/autoload.php') ? 'present' : 'MISSING') . "\n";
echo "config.php: present (this page loaded it)\n";
echo "state/state.json: " . (is_file(__DIR__ . '/state/state.json') ? 'present' : 'missing') . "\n";
echo "logs/: " . (is_dir(__DIR__ . '/logs') ? 'present' : 'missing (script has never run)') . "\n";

foreach (['watcher.log', 'boot.log'] as $name) {
    $log = __DIR__ . '/logs/' . $name;
    if (is_file($log)) {
        echo "--- last 30 lines of $name ---\n";
        echo implode('', array_slice(file($log), -30));
        echo "--- end $name ---\n";
    }
}

echo "\nCLI php candidates:\n";
$bins = [
    '/usr/local/bin/php', '/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php',
    '/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/cpanel/ea-php84/root/usr/bin/php',
];
if (is_dir('/opt/alt')) {
    foreach ((array) scandir('/opt/alt') as $entry) {
        if (preg_match('/^php\d+$/', (string) $entry)) {
            $bins[] = "/opt/alt/$entry/usr/bin/php";
        }
    }
}
foreach ($bins as $bin) {
    echo "  $bin: " . (is_file($bin) ? 'exists' : '-') . "\n";
}

$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$canExec = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
echo "shell_exec: " . ($canExec ? 'available' : 'disabled') . "\n";

if ($canExec) {
    foreach ($bins as $bin) {
        if (is_file($bin)) {
            echo "$ $bin -v => " . trim((string) shell_exec(escapeshellarg($bin) . ' -v 2>&1')) . "\n\n";
        }
    }
    if (isset($_GET['run'])) {
        echo "--- watch.php --dry-run output ---\n";
        $bin = is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
        echo (string) shell_exec(
            escapeshellarg($bin) . ' ' . escapeshellarg(__DIR__ . '/watch.php') . ' --dry-run 2>&1'
        );
        echo "--- end dry-run ---\n";
    } else {
        echo "(append &run=1 to also execute watch.php --dry-run)\n";
    }
}
