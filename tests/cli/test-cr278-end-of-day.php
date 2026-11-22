<?php
/**
 * Standalone CLI test (no WordPress, no PHPUnit needed):
 *   php tests/cli/test-cr278-end-of-day.php
 *
 * CR278: the end time is exclusive and the dropdown stopped at 23:00, so the
 * default "all day" window (00:00-23:00) hid the button from 23:00 to 23:59
 * every night. End of day is now 24:00: offered in the End Time dropdown,
 * accepted by the sanitizer, and the default.
 *
 * Exit code 0 = pass, 1 = fail.
 */

namespace {
    define('ABSPATH', '/tmp/');
    $GLOBALS['fbcn_now'] = '12:00';
    $GLOBALS['fbcn_pro'] = null;

    function __($text, $domain = 'default') { return $text; }
    function _e($text, $domain = 'default') { echo $text; }
    function add_action() {}
    function add_settings_error() {}
    function sanitize_text_field($s) { return trim((string) $s); }
    function selected($a, $b) { if ((string) $a === (string) $b) { echo ' selected="selected"'; } }
    function checked($v) { if ($v) { echo ' checked="checked"'; } }
    function wp_date($format) { return $GLOBALS['fbcn_now']; }
    function get_option($name, $default = false) {
        if ($name === 'fbcn_pro_settings' && $GLOBALS['fbcn_pro'] !== null) {
            return $GLOBALS['fbcn_pro'];
        }
        return $default;
    }

    require __DIR__ . '/../../vendor/autoload.php';

    $failures = 0;
    $check = function ($name, $ok) use (&$failures) {
        echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . "\n";
        $failures += $ok ? 0 : 1;
    };
    $call = function ($obj, $method, ...$args) {
        $m = new \ReflectionMethod($obj, $method);
        if (PHP_VERSION_ID < 80100) {
            $m->setAccessible(true);
        }
        return $m->invoke($obj, ...$args);
    };

    // 1. the default window really is all day
    $defaults = \FBCallNow\Core\Defaults::get_pro_settings();
    $renderer = new \FBCallNow\Frontend\ButtonRenderer();
    foreach (array('00:00', '12:00', '22:59', '23:00', '23:30', '23:59') as $t) {
        $GLOBALS['fbcn_now'] = $t;
        $check("default window shows the button at $t", $call($renderer, 'is_visible_now', $defaults) === true);
    }

    // 2. a business-hours window keeps its exclusive end (no 59-minute overrun)
    $GLOBALS['fbcn_now'] = '17:00';
    $check('09:00-17:00 hides the button at 17:00',
        $call($renderer, 'is_visible_now', array('start_time' => '09:00', 'end_time' => '17:00')) === false);

    // 3. wrap to next day with end 23:00 no longer flashes the button on for one minute at 00:00
    $GLOBALS['fbcn_now'] = '00:00';
    $check('18:00-23:00 with wrap hides the button at 00:00',
        $call($renderer, 'is_visible_now', array('start_time' => '18:00', 'end_time' => '23:00', 'wrap_to_next_day' => true)) === false);
    $check('18:00-09:00 with wrap still shows the button at 00:00',
        $call($renderer, 'is_visible_now', array('start_time' => '18:00', 'end_time' => '09:00', 'wrap_to_next_day' => true)) === true);

    // 4. the sanitizer keeps 24:00 for the end time, never for the start time
    $settings = new \FBCallNow\Admin\Settings();
    $saved = $call($settings, 'sanitize_time_window', array('start_time' => '00:00', 'end_time' => '24:00'));
    $check('sanitizer keeps end_time 24:00', $saved['end_time'] === '24:00');
    $saved = $call($settings, 'sanitize_time_window', array('start_time' => '24:00', 'end_time' => '24:00'));
    $check('sanitizer refuses start_time 24:00', $saved['start_time'] === '00:00');

    // 5. the End Time dropdown offers 24:00 and selects it for the defaults; Start Time does not offer it
    $GLOBALS['fbcn_pro'] = $defaults;
    ob_start();
    $settings->time_window_field_callback();
    $html = ob_get_clean();
    $end = substr($html, strpos($html, 'id="end_time"'));
    $end = substr($end, 0, strpos($end, '</select>'));
    $start = substr($html, strpos($html, 'id="start_time"'));
    $start = substr($start, 0, strpos($start, '</select>'));
    $check('End Time dropdown offers 24:00, selected by default', (bool) preg_match('/value="24:00"\s+selected="selected"/', $end));
    $check('Start Time dropdown does not offer 24:00', strpos($start, '24:00') === false);

    exit($failures ? 1 : 0);
}
