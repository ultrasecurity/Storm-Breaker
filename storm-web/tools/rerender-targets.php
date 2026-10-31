<?php
/**
 * tools/rerender-targets.php — re-splice the payload block into clones that
 * were generated before the consent gate existed.
 *
 * Those clones carry a payload block that runs on load with nothing in
 * front of it. This rewrites that block in place so each existing clone
 * picks up consent.js and the gated modules — no re-cloning, so captures/,
 * audio/, and the rest of meta.json are left exactly as they are.
 *
 * Usage:
 *   php tools/rerender-targets.php                 # every target
 *   php tools/rerender-targets.php spa react vue   # named targets only
 *   php tools/rerender-targets.php --dry-run
 *   php tools/rerender-targets.php --force         # re-render even if gated
 *
 * Each index.html is backed up once to index.html.bak before the first
 * rewrite. The tool verifies the last line of work: a rewritten page that
 * does not contain the gate is reported as a failure rather than a success.
 */

require_once __DIR__ . '/../lib/cloner.php';

$argv     = $argv ?? [];
$dry_run  = in_array('--dry-run', $argv, true);
$force    = in_array('--force',   $argv, true);
$only     = array_values(array_filter(
    array_slice($argv, 1),
    fn($a) => $a !== '' && $a[0] !== '-'
));

$targets_root = realpath(__DIR__ . '/../targets');
if ($targets_root === false) {
    fwrite(STDERR, "targets/ not found\n");
    exit(1);
}

$names = [];
foreach (scandir($targets_root) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    if (!is_dir("$targets_root/$name")) continue;
    if (!is_file("$targets_root/$name/meta.json")) continue;
    if ($only && !in_array($name, $only, true)) continue;
    $names[] = $name;
}

if (!$names) {
    echo "No targets to process.\n";
    exit(0);
}

echo ($dry_run ? "[dry-run] " : '') . count($names) . " target(s)\n\n";

$ok = 0;
$failed = 0;

foreach ($names as $name) {
    $dir       = "$targets_root/$name";
    $meta_path = "$dir/meta.json";
    $index     = "$dir/index.html";

    $meta = json_decode((string) @file_get_contents($meta_path), true);
    if (!is_array($meta)) {
        echo "  FAIL  $name — meta.json unreadable or corrupt\n";
        $failed++;
        continue;
    }
    if (!is_file($index)) {
        echo "  FAIL  $name — no index.html\n";
        $failed++;
        continue;
    }

    $payloads = is_array($meta['payloads'] ?? null) ? $meta['payloads'] : [];
    $url      = (string) ($meta['url'] ?? '');
    $js       = (string) ($meta['custom_js'] ?? '');
    $consent  = cloner_consent_config(is_array($meta['consent'] ?? null) ? $meta['consent'] : []);

    $before = (string) file_get_contents($index);
    $had_gate = str_contains($before, '__SB_CONSENT_CONFIG');
    $had_block = str_contains($before, '<!-- storm-breaker payloads (inlined) -->');

    if ($had_gate && !$force) {
        echo "  skip  $name — gate already present (use --force to re-render)\n";
        $ok++;
        continue;
    }

    $note = $had_block ? 'replacing payload block' : 'no block found — splicing a new one';

    if ($dry_run) {
        echo "  would $name — $note\n";
        $ok++;
        continue;
    }

    // Back up once. Never clobber an existing backup: if this runs twice,
    // the original pre-migration page is what we want to keep.
    $backup = "$dir/index.html.bak";
    if (!is_file($backup)) {
        file_put_contents($backup, $before, LOCK_EX);
    }

    if (!cloner_replace_payload_block($dir, $payloads, $url, $js, $consent)) {
        echo "  FAIL  $name — write failed\n";
        $failed++;
        continue;
    }

    $after = (string) file_get_contents($index);
    if (!str_contains($after, '__SB_CONSENT_CONFIG') || !str_contains($after, '__SB_CONSENT.register')) {
        echo "  FAIL  $name — rewrite landed but the gate is missing (restore index.html.bak)\n";
        $failed++;
        continue;
    }

    $payload_note = $payloads ? implode(',', $payloads) : 'none';
    echo "  ok    $name — $note · modules: $payload_note\n";
    $ok++;
}

echo "\n$ok ok, $failed failed\n";
exit($failed > 0 ? 1 : 0);
