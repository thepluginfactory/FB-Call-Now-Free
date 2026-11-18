<?php
/**
 * Standalone CLI test (no WordPress, no PHPUnit needed):
 *   php tests/cli/test-frontend-script-deps.php
 *
 * CR274: assets/js/frontend.js is wrapped in (function($){...})(jQuery), so the
 * 'fbcn-frontend-script' handle must declare 'jquery' as a dependency or it
 * throws "jQuery is not defined" on themes that don't load jQuery themselves.
 *
 * Exit code 0 = pass, 1 = fail.
 */

namespace {
    define('ABSPATH', '/tmp/');
    define('FBCN_VERSION', 'test');
    define('FBCN_PLUGIN_URL', 'http://example.com/wp-content/plugins/fb-call-now/');

    $GLOBALS['fbcn_test_enqueued'] = array();

    function __($text, $domain = 'default') { return $text; }
    function add_action() {}
    function apply_filters($tag, $value) { return $value; }
    function wp_enqueue_style() {}
    function wp_localize_script() {}
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) {
        $GLOBALS['fbcn_test_enqueued'][$handle] = array('src' => $src, 'deps' => $deps);
    }
    function get_option($name, $default = false) {
        if ($name === 'fbcn_basic_settings') {
            return array_merge((array) $default, array(
                'enable'       => true,
                'phone_number' => '+1-555-123-4567',
            ));
        }
        return $default;
    }

    require __DIR__ . '/../../vendor/autoload.php';

    $renderer = new \FBCallNow\Frontend\ButtonRenderer();
    $renderer->enqueue_frontend_assets();

    $enqueued = $GLOBALS['fbcn_test_enqueued'];
    $failures = 0;

    if (!isset($enqueued['fbcn-frontend-script'])) {
        echo "FAIL: fbcn-frontend-script was not enqueued\n";
        $failures++;
    } else {
        $deps = $enqueued['fbcn-frontend-script']['deps'];
        if (in_array('jquery', (array) $deps, true)) {
            echo "PASS: fbcn-frontend-script deps = " . json_encode($deps) . "\n";
        } else {
            echo "FAIL: fbcn-frontend-script deps = " . json_encode($deps) . " (missing 'jquery'; frontend.js uses jQuery)\n";
            $failures++;
        }
    }

    exit($failures ? 1 : 0);
}
