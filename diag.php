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

$log = __DIR__ . '/logs/watcher.log';
if (is_file($log)) {
    echo "--- last 30 log lines ---\n";
    echo implode('', array_slice(file($log), -30));
    echo "--- end log ---\n";
}

echo "\nCLI php candidates:\n";
$bins = [
    '/usr/local/bin/php', '/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php',
    '/opt/cpanel/ea-php83/root/usr/bin/php', '/opt/cpanel/ea-php84/root/usr/bin/php',
];
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
