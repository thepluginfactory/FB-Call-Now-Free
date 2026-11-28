<?php
/**
 * Standalone CLI test (no WordPress, no PHPUnit needed):
 *   php tests/cli/test-cr385-wrap-2300-upgrade.php
 *
 * CR385: before CR278 the End Time dropdown stopped at 23:00, so sites that wanted the button
 * up until midnight saved e.g. 18:00-23:00 with Wrap to Next Day on, and a special case kept it
 * visible through 23:59. CR278 removed that case, so those saved settings now hide the button
 * at 23:00. A one-time upgrade must rewrite saved end_time 23:00 -> 24:00 when wrap is on, and
 * leave everything else alone (including the untouched 00:00-23:00 no-wrap default, which
 * already hid the button from 23:00 before CR278).
 *
 * Loads the real fb-call-now.php against stubs and fires its plugins_loaded hooks, the way
 * WordPress would after the plugin update.
 *
 * Exit code 0 = pass, 1 = fail.
 */

namespace {
    define('ABSPATH', '/tmp/');
    $GLOBALS['fbcn_now'] = '12:00';
    $GLOBALS['fbcn_options'] = array();
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
    function wp_date($format) { return $GLOBALS['fbcn_now']; }
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['fbcn_options']) ? $GLOBALS['fbcn_options'][$name] : $default;
    }
    function update_option($name, $value, $autoload = null) { $GLOBALS['fbcn_options'][$name] = $value; return true; }
    // The upgrade also looks for the old public log (CR275); point it at an empty folder.
    function wp_upload_dir() { return array('basedir' => sys_get_temp_dir() . '/fbcn-cr385-none'); }
    function trailingslashit($s) { return rtrim($s, '/') . '/'; }

    require __DIR__ . '/../../fb-call-now.php';

    $failures = 0;
    $check = function ($name, $ok, $detail = '') use (&$failures) {
        echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . (!$ok && $detail !== '' ? "  [$detail]" : '') . "\n";
        $failures += $ok ? 0 : 1;
    };
    $plugins_loaded = function () {
        foreach ($GLOBALS['fbcn_actions']['plugins_loaded'] ?? array() as $cb) {
            call_user_func($cb);
        }
    };
    $visible_at = function ($time) {
        $GLOBALS['fbcn_now'] = $time;
        $m = new \ReflectionMethod('FBCallNow\Frontend\ButtonRenderer', 'is_visible_now');
        if (PHP_VERSION_ID < 80100) {
            $m->setAccessible(true);
        }
        return $m->invoke(new \FBCallNow\Frontend\ButtonRenderer(), get_option('fbcn_pro_settings'));
    };
    $pro = function ($start, $end, $wrap) {
        return array('days_visible' => array('monday'), 'start_time' => $start, 'end_time' => $end, 'wrap_to_next_day' => $wrap);
    };

    // 1. A site saved 18:00-23:00 + wrap before CR278 to show the button until midnight.
    $GLOBALS['fbcn_options'] = array('fbcn_pro_settings' => $pro('18:00', '23:00', true));
    $plugins_loaded();
    $saved = get_option('fbcn_pro_settings');
    $check('18:00-23:00 + wrap: end_time upgraded to 24:00', $saved['end_time'] === '24:00', $saved['end_time']);
    $check('18:00-23:00 + wrap: button still visible at 23:30 after the update', $visible_at('23:30') === true);
    $check('18:00-23:00 + wrap: button still visible at 23:59', $visible_at('23:59') === true);
    $check('18:00-23:00 + wrap: button still hidden at 17:59', $visible_at('17:59') === false);
    $check('18:00-23:00 + wrap: other settings untouched',
        $saved['start_time'] === '18:00' && $saved['wrap_to_next_day'] === true && $saved['days_visible'] === array('monday'));

    // 2. It runs once: an admin who later deliberately saves 23:00 with wrap keeps 23:00.
    $GLOBALS['fbcn_options']['fbcn_pro_settings'] = $pro('18:00', '23:00', true);
    $plugins_loaded();
    $check('upgrade does not run again on the next load', get_option('fbcn_pro_settings')['end_time'] === '23:00',
        get_option('fbcn_pro_settings')['end_time']);

    // 3. Settings that do not match are left alone.
    $cases = array(
        'untouched 00:00-23:00 default (no wrap)' => $pro('00:00', '23:00', false),
        '22:00-23:00 without wrap' => $pro('22:00', '23:00', false),
        '18:00-09:00 with wrap' => $pro('18:00', '09:00', true),
        '09:00-17:00 with wrap' => $pro('09:00', '17:00', true),
    );
    foreach ($cases as $label => $settings) {
        $GLOBALS['fbcn_options'] = array('fbcn_pro_settings' => $settings);
        $plugins_loaded();
        $check("$label: left unchanged", get_option('fbcn_pro_settings') === $settings,
            json_encode(get_option('fbcn_pro_settings')));
    }

    // 4. A fresh site with no saved Pro settings gets none created.
    $GLOBALS['fbcn_options'] = array();
    $plugins_loaded();
    $check('no saved Pro settings: none are created', get_option('fbcn_pro_settings') === false);

    exit($failures ? 1 : 0);
}
