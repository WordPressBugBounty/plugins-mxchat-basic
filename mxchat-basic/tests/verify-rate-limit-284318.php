<?php
/**
 * RELEASE GUARD — per-visitor rate limiting (plan 284318).
 *
 * Three things must hold, or a logged-out cap does not hold either:
 *   (a) the counter is keyed on an address the visitor cannot choose —
 *       REMOTE_ADDR, or CF-Connecting-IP only when the request really came
 *       from a Cloudflare edge; Client-IP / X-Forwarded-For are never read;
 *   (b) the hourly reset parses counter names correctly and deletes a
 *       counter only once its window has elapsed — the old split-on-'_'
 *       parser mis-read every name and deleted every counter every hour;
 *   (c) counters are written without autoload and the number of visitor
 *       counters per bot is bounded.
 *
 * Run on a test site (it writes and removes its own rate-limit options and
 * briefly rewrites mxchat_options.rate_limits, restoring the original):
 *
 *   wp eval-file wp-content/plugins/mxchat-basic/tests/verify-rate-limit-284318.php
 *
 * A build that prints FAIL is NOT releasable.
 */
if (!defined('ABSPATH')) {
    exit;
}

global $mxchat_integrator, $wpdb;
$integrator = ($mxchat_integrator instanceof MxChat_Integrator) ? $mxchat_integrator : new MxChat_Integrator();

$results = array();
$check = function ($name, $pass, $detail = '') use (&$results) {
    $results[] = array($name, (bool) $pass, (string) $detail);
};
$call_private = function ($obj, $method, ...$args) {
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
};

// ---------------------------------------------------------------- (a) client IP
$saved_server = $_SERVER;
$set = function ($remote, $extra = array()) {
    foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR') as $k) {
        unset($_SERVER[$k]);
    }
    if ($remote !== null) {
        $_SERVER['REMOTE_ADDR'] = $remote;
    }
    foreach ($extra as $k => $v) {
        $_SERVER[$k] = $v;
    }
};

$set('198.51.100.7', array('HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.7', 'HTTP_CLIENT_IP' => '203.0.113.8'));
$check('a1 forged X-Forwarded-For / Client-IP ignored', MxChat_User::mxchat_get_client_ip() === '198.51.100.7', MxChat_User::mxchat_get_client_ip());
$check('a1b transcript identifier agrees', MxChat_User::mxchat_get_user_identifier() === '198.51.100.7', MxChat_User::mxchat_get_user_identifier());
$check('a1c integrator get_client_ip agrees', $call_private($integrator, 'get_client_ip') === '198.51.100.7');

$set('173.245.48.10', array('HTTP_CF_CONNECTING_IP' => '203.0.113.9'));
$check('a2 CF-Connecting-IP honoured from a Cloudflare IPv4 edge', MxChat_User::mxchat_get_client_ip() === '203.0.113.9', MxChat_User::mxchat_get_client_ip());

$set('2606:4700::1234', array('HTTP_CF_CONNECTING_IP' => '2001:db8::7'));
$check('a3 CF-Connecting-IP honoured from a Cloudflare IPv6 edge', MxChat_User::mxchat_get_client_ip() === '2001:db8::7', MxChat_User::mxchat_get_client_ip());

$set('198.51.100.7', array('HTTP_CF_CONNECTING_IP' => '203.0.113.9'));
$check('a4 CF-Connecting-IP ignored when not from Cloudflare', MxChat_User::mxchat_get_client_ip() === '198.51.100.7', MxChat_User::mxchat_get_client_ip());

$set('173.245.48.10', array('HTTP_CF_CONNECTING_IP' => 'not-an-ip"onclick'));
$check('a5 invalid CF-Connecting-IP falls back to REMOTE_ADDR', MxChat_User::mxchat_get_client_ip() === '173.245.48.10', MxChat_User::mxchat_get_client_ip());

$set('198.51.100.7');
$junk = function () { return 'garbage'; };
add_filter('mxchat_client_ip', $junk);
$check('a6 filter returning junk is ignored', MxChat_User::mxchat_get_client_ip() === '198.51.100.7', MxChat_User::mxchat_get_client_ip());
remove_filter('mxchat_client_ip', $junk);
$own = function () { return '203.0.113.77'; };
add_filter('mxchat_client_ip', $own);
$check('a7 filter returning a valid address is used', MxChat_User::mxchat_get_client_ip() === '203.0.113.77', MxChat_User::mxchat_get_client_ip());
remove_filter('mxchat_client_ip', $own);

$set(null);
$check('a8 no REMOTE_ADDR at all -> unknown', MxChat_User::mxchat_get_client_ip() === 'unknown', MxChat_User::mxchat_get_client_ip());

$check('a9 cidr: 104.23.255.255 in 104.16.0.0/13', MxChat_User::mxchat_ip_in_cidr('104.23.255.255', '104.16.0.0/13'));
$check('a10 cidr: 104.28.0.0 not in 104.16.0.0/13', !MxChat_User::mxchat_ip_in_cidr('104.28.0.0', '104.16.0.0/13'));
$check('a11 cidr: 172.71.1.1 is Cloudflare, 172.72.0.0 is not', MxChat_User::mxchat_ip_is_cloudflare('172.71.1.1') && !MxChat_User::mxchat_ip_is_cloudflare('172.72.0.0'));
$check('a12 cidr: 2a06:98c0::/29 covers 2a06:98c7::1, not 2a06:98c8::1', MxChat_User::mxchat_ip_in_cidr('2a06:98c7::1', '2a06:98c0::/29') && !MxChat_User::mxchat_ip_in_cidr('2a06:98c8::1', '2a06:98c0::/29'));
$check('a13 cidr: family mismatch is false', !MxChat_User::mxchat_ip_in_cidr('2606:4700::1', '104.16.0.0/13'));

$_SERVER = $saved_server;

// ---------------------------------------------------------------- name parser
$p = $integrator->mxchat_parse_rate_counter_name('mxchat_chat_limit_default_logged_out_203_0_113_1');
$check('p1 parse logged_out visitor', $p && $p['bot'] === 'default' && $p['role'] === 'logged_out' && $p['identifier'] === '203_0_113_1', json_encode($p));
$p = $integrator->mxchat_parse_rate_counter_name('mxchat_chat_limit_default_author_42');
$check('p2 parse author 42', $p && $p['bot'] === 'default' && $p['role'] === 'author' && $p['identifier'] === '42', json_encode($p));
$p = $integrator->mxchat_parse_rate_counter_name('mxchat_chat_limit_default_global');
$check('p3 parse global pool', $p && $p['bot'] === 'default' && $p['role'] === 'global', json_encode($p));
$p = $integrator->mxchat_parse_rate_counter_name('mxchat_chat_limit_sales_bot_2_logged_out_2001_db8__1');
$check('p4 parse bot id with underscores + IPv6 identifier', $p && $p['bot'] === 'sales_bot_2' && $p['role'] === 'logged_out' && $p['identifier'] === '2001_db8__1', json_encode($p));
$p = $integrator->mxchat_parse_rate_counter_name('mxchat_chat_limit_default_subscriber_7');
$check('p5 parse subscriber 7', $p && $p['role'] === 'subscriber' && $p['identifier'] === '7', json_encode($p));
$p = $integrator->mxchat_parse_rate_counter_name('mxchat_chat_limit_junk');
$check('p6 unparseable name -> null', $p === null, json_encode($p));

// ---------------------------------------------------------------- (b) the reset
$opts_before = get_option('mxchat_options', array());
$opts = $opts_before;
$opts['rate_limits'] = array(
    'logged_out' => array('limit' => '50', 'timeframe' => 'daily'),
    'author'     => array('limit' => '100', 'timeframe' => 'daily'),
);
$opts['rate_limits_global'] = array('limit' => '1000', 'timeframe' => 'daily');
update_option('mxchat_options', $opts);
wp_cache_flush();

$now = time();
$rows = array(
    'mxchat_chat_limit_default_logged_out_203_0_113_1' => array('count' => 3, 'timestamp' => $now - 60),
    'mxchat_chat_limit_default_logged_out_203_0_113_2' => array('count' => 3, 'timestamp' => $now - 25 * 3600),
    'mxchat_chat_limit_default_author_42'              => array('count' => 1, 'timestamp' => $now - 60),
    'mxchat_chat_limit_default_global'                 => array('count' => 9, 'timestamp' => $now - 60),
    'mxchat_chat_limit_default_editor_5'               => array('count' => 1, 'timestamp' => $now - 60), // role not configured -> orphan
);
foreach ($rows as $name => $data) {
    delete_option($name);
    $call_private($integrator, 'mxchat_save_rate_counter', $name, $data);
}
$integrator->mxchat_reset_rate_limits();
wp_cache_flush();

$check('b1 fresh logged_out counter survives the reset', get_option('mxchat_chat_limit_default_logged_out_203_0_113_1') !== false);
$check('b2 25h-old logged_out counter is removed', get_option('mxchat_chat_limit_default_logged_out_203_0_113_2') === false);
$check('b3 fresh author counter survives', get_option('mxchat_chat_limit_default_author_42') !== false);
$check('b4 fresh global pool survives', get_option('mxchat_chat_limit_default_global') !== false);
$check('b5 counter for an unconfigured role is the only orphan removed', get_option('mxchat_chat_limit_default_editor_5') === false);

// ---------------------------------------------------------------- (c) autoload + cap
$autoload = $wpdb->get_var($wpdb->prepare(
    "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
    'mxchat_chat_limit_default_logged_out_203_0_113_1'
));
$check('c1 counters are stored with autoload off', in_array($autoload, array('no', 'off'), true), var_export($autoload, true));

foreach (array_keys($rows) as $name) {
    delete_option($name);
}
$cap = function () { return 5; };
add_filter('mxchat_rate_limit_max_visitor_counters', $cap);
for ($i = 1; $i <= 5; $i++) {
    $call_private($integrator, 'mxchat_save_rate_counter', 'mxchat_chat_limit_default_logged_out_10_0_0_' . $i, array('count' => 1, 'timestamp' => $now));
}
$call_private($integrator, 'mxchat_cap_visitor_counters', 'default', 'logged_out');
remove_filter('mxchat_rate_limit_max_visitor_counters', $cap);
$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'mxchat\\_chat\\_limit\\_default\\_logged\\_out\\_10\\_0\\_0\\_%'");
$oldest_gone = get_option('mxchat_chat_limit_default_logged_out_10_0_0_1') === false;
$newest_kept = get_option('mxchat_chat_limit_default_logged_out_10_0_0_5') !== false;
$check('c2 at the cap the oldest visitor counter is evicted, the newest kept', $left === 4 && $oldest_gone && $newest_kept, "left=$left");
for ($i = 1; $i <= 5; $i++) {
    delete_option('mxchat_chat_limit_default_logged_out_10_0_0_' . $i);
}

// ---------------------------------------------------------------- restore
update_option('mxchat_options', $opts_before);
wp_cache_flush();
$check('z restore: mxchat_options back to the original', get_option('mxchat_options') === $opts_before);

// ---------------------------------------------------------------- report
$fail = 0;
foreach ($results as $r) {
    if (!$r[1]) {
        $fail++;
    }
    echo ($r[1] ? 'PASS  ' : 'FAIL  ') . $r[0] . ($r[1] || $r[2] === '' ? '' : "  -> " . $r[2]) . "\n";
}
echo "\n" . (count($results) - $fail) . '/' . count($results) . ' passed' . ($fail ? ' — NOT releasable' : ' — releasable') . "\n";
