<?php
/**
 * Standalone CLI test (no WordPress, no PHPUnit needed):
 *   php tests/cli/test-cr275-debug-log-private-capped.php
 *
 * CR275: with debug logging on, the log was uploads/fb-call-now.log (anyone could download it),
 * had no size limit, and every page view wrote the same day/time check lines twice plus an
 * "Admin menu initialized" line on every admin page. The log must:
 *   - not be a plain file in uploads/: it lives in a randomly named uploads folder with a
 *     deny-all .htaccess and an index.php, as debug.php starting with an exit guard, so a web
 *     request for it gets nothing even where .htaccess is ignored;
 *   - be capped: past the size limit it rolls over to one old file, so the total stays bounded;
 *   - not repeat the same line within one request, and not log the admin menu on every page;
 *   - still show its entries (without the guard) on the Debug Log page, report size, clear;
 *   - take over an old public uploads/fb-call-now.log on the plugin update, even with logging
 *     off, so it doesn't stay downloadable.
 *
 * Loads the real fb-call-now.php against stubs. Exit code 0 = pass, 1 = fail.
 */

namespace {
    define('ABSPATH', '/tmp/');
    define('FBCN_PRO_ACTIVE', true);
    $GLOBALS['uploads'] = sys_get_temp_dir() . '/fbcn-cr275-' . getmypid();
    @mkdir($GLOBALS['uploads'], 0777, true);
    $GLOBALS['fbcn_options'] = array(
        'fbcn_db_version'     => 1,
        'fbcn_pro_settings'   => array('debug_logging' => true, 'days_visible' => array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'), 'start_time' => '00:00', 'end_time' => '24:00'),
        'fbcn_basic_settings' => array('enable' => 1, 'phone_number' => '+1-555-123-4567'),
    );
    $GLOBALS['fbcn_actions'] = array();

    function __($text, $domain = 'default') { return $text; }
    function add_action($hook, $cb) { $GLOBALS['fbcn_actions'][$hook][] = $cb; }
    function add_filter() {}
    function register_activation_hook() {}
    function register_deactivation_hook() {}
    function load_plugin_textdomain() {}
    function plugin_dir_path($f) { return dirname($f) . '/'; }
    function plugin_dir_url($f) { return 'http://x/'; }
    function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
    function wp_date($format) { return date($format); }
    function current_time($format) { return date($format); }
    function wp_upload_dir() { return array('basedir' => $GLOBALS['uploads'], 'baseurl' => 'http://x/wp-content/uploads'); }
    function trailingslashit($s) { return rtrim($s, '/') . '/'; }
    function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0755, true); }
    function wp_generate_password($len = 12, $sp = true, $xsp = false) { return substr(str_repeat(bin2hex(random_bytes(32)), 2), 0, $len); }
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['fbcn_options']) ? $GLOBALS['fbcn_options'][$name] : $default;
    }
    function update_option($name, $value, $autoload = null) { $GLOBALS['fbcn_options'][$name] = $value; return true; }
    function add_menu_page() {}
    function add_submenu_page() {}
    function remove_submenu_page() {}

    require __DIR__ . '/../../fb-call-now.php';
    use FBCallNow\Core\Logger;

    $failures = 0;
    $check = function ($name, $ok, $detail = '') use (&$failures) {
        echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . (!$ok && $detail !== '' ? "  [$detail]" : '') . "\n";
        $failures += $ok ? 0 : 1;
    };
    // Every file under uploads/ a web server would hand out as-is (not .php, not .htaccess).
    $servable = function () {
        $out = array();
        foreach (array_merge(glob($GLOBALS['uploads'] . '/*') ?: array(), glob($GLOBALS['uploads'] . '/*/*') ?: array()) as $f) {
            if (is_file($f) && substr($f, -4) !== '.php' && strpos((string) file_get_contents($f), 'INFO:') !== false) {
                $out[] = $f;
            }
        }
        return $out;
    };
    $log_dirs = function () { return glob($GLOBALS['uploads'] . '/fbcn-logs-*', GLOB_ONLYDIR) ?: array(); };
    $cleanup = function () use ($log_dirs) {
        foreach (array_merge($log_dirs(), array($GLOBALS['uploads'])) as $d) {
            foreach (glob("$d/{,.}[!.]*", GLOB_BRACE) ?: array() as $f) { if (is_file($f)) { unlink($f); } }
        }
        foreach ($log_dirs() as $d) { @rmdir($d); }
    };
    $guard = "<?php exit; ?>";

    // 1. Where the log goes.
    Logger::info('Basic Settings saved: {"phone_number":"+1-555-123-4567"}');
    Logger::error('Second message');
    Logger::info('Third message');
    $check('nothing is written to the public uploads/fb-call-now.log', !file_exists($GLOBALS['uploads'] . '/fb-call-now.log'));
    $check('no plain-text log file a web server would serve is left in uploads/', $servable() === array(), implode(',', $servable()));
    $dirs = $log_dirs();
    $dir = $dirs[0] ?? '/nonexistent';
    $check('the log folder has a long random name', count($dirs) === 1 && preg_match('/fbcn-logs-[A-Za-z0-9]{20,}$/', $dir));
    $check('the folder has a deny-all .htaccess and an index.php', is_file("$dir/.htaccess") && stripos(file_get_contents("$dir/.htaccess"), 'denied') !== false && is_file("$dir/index.php"));
    $log = Logger::get_log_file_path();
    $check('the log is a .php file in that folder', dirname($log) === $dir && substr($log, -4) === '.php', $log);
    $check('it starts with the exit guard', is_file($log) && strpos(file_get_contents($log), $guard) === 0);
    $out = is_file($log) ? shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($log) . ' 2>&1') : 'missing';
    $check('requesting it runs it and gets nothing back', trim((string) $out) === '', (string) $out);

    // 2. The Debug Log page.
    $content = Logger::read_log(200);
    $lines = explode("\n", trim($content));
    $check('the Debug Log page shows the 3 entries in order, without the guard line',
        count($lines) === 3 && strpos($lines[0], 'Basic Settings saved') !== false && strpos($lines[2], 'Third message') !== false && strpos($content, '<?php') === false, $content);
    $check('the Debug Log page shows a size', Logger::get_log_file_size() !== '0 B');

    // 3. One request doesn't repeat itself: a page view runs should_display_button twice.
    Logger::clear_log();
    $r = new \FBCallNow\Frontend\ButtonRenderer();
    $m = new \ReflectionMethod($r, 'should_display_button');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    $m->invoke($r);
    $m->invoke($r);
    $c = Logger::read_log(200);
    $check('the day check is logged once per request, not twice', substr_count($c, 'Day check') === 1, $c);
    $check('the time check is logged once per request, not twice', substr_count($c, 'Time check') === 1, $c);
    $s = (new \ReflectionClass('FBCallNow\Admin\Settings'))->newInstanceWithoutConstructor();
    $s->add_admin_menu();
    $check('loading an admin page does not log "Admin menu initialized"', strpos(Logger::read_log(200), 'Admin menu initialized') === false);

    // 4. Size cap: write about 3 MB of lines.
    $big = str_repeat('x', 1000);
    for ($i = 0; $i < 3000; $i++) { Logger::info("line $i $big"); }
    clearstatcache();
    $files = array_values(array_filter(glob("$dir/*.php") ?: array(), function ($f) { return basename($f) !== 'index.php'; }));
    $total = 0;
    foreach ($files as $f) { $total += filesize($f); }
    $check('after ~3 MB of entries the log is capped (current + one old file, under 2.1 MB total)', count($files) === 2 && $total < 2.1 * 1048576, count($files) . ' files, ' . $total . ' bytes');
    $check('the newest entry is still in the current log', strpos(Logger::read_log(5), 'line 2999') !== false);
    $old_ok = count($files) === 2;
    foreach ($files as $f) { if (strpos(file_get_contents($f), $guard) !== 0) { $old_ok = false; } }
    $check('the rolled-over old file also starts with the exit guard', $old_ok);

    $check('Clear Log still works', Logger::clear_log() && strpos(Logger::read_log(200), 'line 2999') === false);

    // 5. Plugin update with an old public log and logging off.
    $cleanup();
    $GLOBALS['fbcn_options']['fbcn_pro_settings']['debug_logging'] = false;
    $GLOBALS['fbcn_options']['fbcn_db_version'] = 1;
    unset($GLOBALS['fbcn_options']['fbcn_log_dir_key']);
    $legacy = $GLOBALS['uploads'] . '/fb-call-now.log';
    file_put_contents($legacy, "[2026-11-01 10:00:00] INFO: Pro Settings saved: old\n");
    foreach ($GLOBALS['fbcn_actions']['plugins_loaded'] ?? array() as $cb) { call_user_func($cb); }
    $check('the old public uploads/fb-call-now.log is gone after the update, with logging off', !file_exists($legacy));
    $GLOBALS['fbcn_options']['fbcn_pro_settings']['debug_logging'] = true;
    $check('its lines were moved into the private log, not lost', strpos(Logger::read_log(200), 'Pro Settings saved: old') !== false);
    $check('still no servable log file in uploads/', $servable() === array());

    $cleanup();
    @rmdir($GLOBALS['uploads']);

    echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
    exit($failures ? 1 : 0);
}
