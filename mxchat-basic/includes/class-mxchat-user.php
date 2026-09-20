<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MxChat_User {

    // Function to get user identifier (username, email, or session ID)
    public static function mxchat_get_user_identifier() {
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            //error_log('Current User: ' . print_r($current_user, true));
            return $current_user->user_login; // Use username as identifier
        } else {
            //error_log('User is not logged in. Using IP as identifier.');
            // Same resolver the rate limiter keys on, so the transcript
            // identifier and the rate-limit counter name the same address.
            return self::mxchat_get_client_ip();
        }
    }

    // Function to get user email
    public static function mxchat_get_user_email() {
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            return $current_user->user_email;
        }
        return null; // No email if not logged in
    }

    /**
     * The visitor's address, from a source the visitor cannot choose.
     *
     * REMOTE_ADDR is the answer. Client-IP and X-Forwarded-For are set by
     * whoever sends the request, so a counter keyed on them can be reset
     * with a header. The one exception is Cloudflare: when the connection
     * really comes from a Cloudflare edge (REMOTE_ADDR inside the ranges
     * Cloudflare publishes), CF-Connecting-IP is the visitor. Hosts behind
     * some other proxy can supply their own resolver with the
     * `mxchat_client_ip` filter; whatever comes back must still be a valid
     * address or REMOTE_ADDR is used.
     *
     * @return string A validated IP address, or 'unknown' when there is none.
     */
    public static function mxchat_get_client_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $remote = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';

        $ip = $remote;
        if ($remote !== '' && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) && self::mxchat_ip_is_cloudflare($remote)) {
            $candidate = trim((string) wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }

        /**
         * Override the resolved visitor address, e.g. for a host behind a
         * trusted proxy that is not Cloudflare. Return a valid IP.
         *
         * @param string $ip     The address MxChat resolved.
         * @param string $remote REMOTE_ADDR as received (validated, may be '').
         */
        $filtered = apply_filters('mxchat_client_ip', $ip, $remote);
        if (is_string($filtered) && filter_var(trim($filtered), FILTER_VALIDATE_IP)) {
            $ip = trim($filtered);
        }

        return $ip !== '' ? $ip : 'unknown';
    }

    /**
     * Cloudflare's published edge ranges (cloudflare.com/ips-v4, /ips-v6),
     * as of 2026-09-16. Refresh with the plugin.
     */
    public static function mxchat_cloudflare_ranges() {
        return array(
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        );
    }

    public static function mxchat_ip_is_cloudflare($ip) {
        foreach (self::mxchat_cloudflare_ranges() as $cidr) {
            if (self::mxchat_ip_in_cidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does $ip fall inside $cidr? Works for IPv4 and IPv6; families must match.
     */
    public static function mxchat_ip_in_cidr($ip, $cidr) {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        list($subnet, $bits) = explode('/', $cidr, 2);
        $ip_bin     = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin)) {
            return false;
        }
        $bits = (int) $bits;
        $max  = strlen($ip_bin) * 8;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $full_bytes = intdiv($bits, 8);
        if ($full_bytes > 0 && substr($ip_bin, 0, $full_bytes) !== substr($subnet_bin, 0, $full_bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return ((ord($ip_bin[$full_bytes]) & $mask) === (ord($subnet_bin[$full_bytes]) & $mask));
    }
}
