<?php
/**
 * RELEASE GUARD — AI Tools default-off invariant.
 *
 * AI Tools (function calling) MUST default to ZERO active tools on a fresh
 * install. A site that has never configured the AI Tools section has an empty
 * mxchat_function_calling_tools map, and that MUST resolve to BOTH:
 *   (a) 0 enabled tools in available_tools(), AND
 *   (b) MxChat_Tool_Registry::is_enabled() === false
 * These are SEPARATE code paths and CAN diverge — see "why both halves" below.
 *
 * Run before releasing mxchat-basic, or after ANY change to
 * class-mxchat-tool-registry.php or the function-calling defaults:
 *
 *   wp eval-file wp-content/plugins/mxchat-basic/tests/verify-aitools-default-off.php
 *
 * A build that prints FAIL is NOT releasable.
 *
 * ---------------------------------------------------------------------------
 * Why both halves (plan-mxchat-20260716-71525f)
 *
 * The 3.2.10 regression: resolve_tool_setting() defaulted an unconfigured tool
 * to !$is_cautious (every non-sensitive tool ON), so a fresh install rendered
 * ~10 "active" tools and was one accidental save from turning function calling
 * fully on. The master gate happened to read the raw (empty) map, so the loop
 * was NOT firing — the UI was wrong while the gate was right. That is the proof
 * these two halves diverge: is_enabled() -> count_enabled(enabled_map()) is a
 * different path from the count check -> available_tools($map). This guard
 * previously tested only the second one and printed "PASS ... releasable"
 * having never exercised the half that decides whether the loop runs at all.
 *
 * Why this script does NOT write the option
 *
 * is_enabled() takes NO ARGUMENTS — it reads the live option, so is_enabled(array())
 * silently ignores the argument and reports on whatever the install happens to
 * have. The obvious way to test it is to empty the real option and restore it,
 * but that makes a release guard into a mutating script (an aborted run could
 * leave a site's tools wiped, and it would be dangerous on prod). enabled_map()
 * reads through get_option(), so a read-time filter substitutes the map for the
 * duration of one call WITHOUT touching the database. Nothing is written; there
 * is nothing to restore; it is safe on any install, including production.
 *
 * Why `pre_option_` and NOT `option_` (plan-mxchat-20260717-bc8bba)
 *
 * get_option() applies `option_{$name}` ONLY when the option EXISTS in the
 * database; an absent option returns early through `default_option_{$name}`
 * and the `option_` filter never fires. On a genuinely fresh install — the
 * exact state this guard exists to assert — an `option_` substitution
 * silently no-ops: half 2 reads the real (absent) option and passes untested,
 * and the positive controls FAIL on a healthy build (the injected map never
 * arrives, so is_enabled() stays false). `pre_option_` fires unconditionally
 * and short-circuits get_option() on any non-false return — an empty array()
 * is not false, so even the empty-map substitution takes. Do NOT "simplify"
 * this back to `option_`; it only appears to work on installs where the
 * option already exists.
 * ---------------------------------------------------------------------------
 */

// Never web-accessible: this file lives under wp-content/plugins and would
// otherwise be reachable by URL.
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MxChat_Tool_Registry')) {
    echo "FAIL: MxChat_Tool_Registry not loaded — is mxchat-basic active?\n";
    return;
}

$opt  = MxChat_Tool_Registry::OPT_TOOLS;
$pass = true;

/** Run $fn with the tools option filtered to $map. Reads only — no DB write.
 *  pre_option_ (NOT option_): it fires even when the option is absent from the
 *  DB, which is precisely the fresh-install state under test. See docblock. */
$with_map = function (array $map, callable $fn) use ($opt) {
    $sub = function () use ($map) { return $map; };
    add_filter('pre_option_' . $opt, $sub, 99);
    try {
        return $fn();
    } finally {
        remove_filter('pre_option_' . $opt, $sub, 99);
    }
};

$count_enabled = function (array $tools) {
    return count(array_filter($tools, function ($t) { return !empty($t['enabled']); }));
};

echo "Site: " . home_url() . "\n";
echo "Live option is READ-ONLY here — the map is substituted via a pre_option_ filter.\n\n";

/* ---- HALF 1: the empty map must resolve to 0 enabled tools ---- */
$fresh         = MxChat_Tool_Registry::available_tools(array());
$fresh_enabled = $count_enabled($fresh);
$ok            = ($fresh_enabled === 0);
$pass          = $pass && $ok;
printf("%s  empty map -> %d of %d tools enabled  (MUST be 0)\n",
    $ok ? 'PASS' : 'FAIL', $fresh_enabled, count($fresh));

/* ---- HALF 2: the empty map must leave the MASTER GATE off ---- */
/* This is the half the guard never tested. is_enabled() reads the live option,
   so the map has to be substituted for real — passing it an argument does nothing. */
$gate = $with_map(array(), function () {
    return MxChat_Tool_Registry::is_enabled();
});
$ok   = ($gate === false);
$pass = $pass && $ok;
printf("%s  empty map -> is_enabled() === %s  (MUST be false)\n",
    $ok ? 'PASS' : 'FAIL', var_export($gate, true));

/* ---- POSITIVE CONTROLS ---- */
/* Without these, a hard-coded `return false` / `return 0` would pass everything
   above. A guard that can only ever say "off" is not a guard. */
$one         = MxChat_Tool_Registry::available_tools(array('mxchat_handle_search_request' => array('enabled' => true)));
$one_enabled = $count_enabled($one);
$ok          = ($one_enabled === 1);
$pass        = $pass && $ok;
printf("%s  one tool added -> %d enabled  (MUST be 1 — positive control)\n",
    $ok ? 'PASS' : 'FAIL', $one_enabled);

$gate_on = $with_map(array('mxchat_handle_search_request' => array('enabled' => true)), function () {
    return MxChat_Tool_Registry::is_enabled();
});
$ok   = ($gate_on === true);
$pass = $pass && $ok;
printf("%s  one tool added -> is_enabled() === %s  (MUST be true — positive control)\n",
    $ok ? 'PASS' : 'FAIL', var_export($gate_on, true));

/* ---- Proof we never wrote anything ---- */
$live = get_option($opt, array());
printf("\nLive option untouched: %d tool row(s) still stored.\n", is_array($live) ? count($live) : 0);

echo $pass
    ? "PASS: AI Tools default-off invariant holds on BOTH halves — releasable.\n"
    : "FAIL: default-off invariant BROKEN — DO NOT release.\n";
