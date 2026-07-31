<?php

declare(strict_types=1);

/**
 * Checks every Subresource Integrity hash in the layouts against the real file
 * on the CDN.
 *
 * This exists because a wrong hash is a silent, total failure with no error
 * message anywhere near the cause. A live deployment shipped a Bootstrap CSS
 * hash whose first fourteen characters were correct and whose remainder was
 * from something else. The browser therefore refused to apply the stylesheet at
 * all, and the visible symptom was "the profile menu never closes" - because
 * `.dropdown-menu { display: none }` is Bootstrap's, and it was never applied.
 *
 * Nothing server-side can catch that: the HTML is correct, the tag is correct,
 * the panel's own 130 smoke checks pass. Only comparing the hash to the bytes
 * the browser will actually fetch catches it.
 *
 *   php tools/verify-cdn-integrity.php
 *
 * Needs outbound HTTPS. Exits 0 when every hash matches, 1 otherwise, and 2 when
 * the network is unavailable - a missing network is not a broken hash.
 */

$root = dirname(__DIR__);
$files = glob($root . '/admin/views/layouts/*.php') ?: [];

$checked = 0;
$failed = 0;
$unreachable = 0;

foreach ($files as $file) {
    $html = (string) file_get_contents($file);

    // Pair every href/src with the integrity attribute of the same tag.
    if (preg_match_all('/<(?:link|script)\b[^>]*>/i', $html, $tags) === false) {
        continue;
    }

    foreach ($tags[0] as $tag) {
        if (!preg_match('/integrity="([^"]+)"/i', $tag, $integrityMatch)) {
            continue;
        }
        if (!preg_match('/(?:href|src)="(https:\/\/[^"]+)"/i', $tag, $urlMatch)) {
            continue;
        }

        $declared = trim($integrityMatch[1]);
        $url = $urlMatch[1];
        $checked++;

        [$algo, $expected] = array_pad(explode('-', $declared, 2), 2, '');
        if (!in_array($algo, ['sha256', 'sha384', 'sha512'], true)) {
            printf("  FAIL  %s\n        unknown hash algorithm '%s'\n", $url, $algo);
            $failed++;
            continue;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'LRMS-integrity-check'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            printf("  SKIP  %s\n        could not be fetched\n", $url);
            $unreachable++;
            continue;
        }

        $actual = base64_encode(hash($algo, $body, true));

        if (hash_equals($expected, $actual)) {
            printf("  PASS  %s (%s, %d bytes)\n", basename(parse_url($url, PHP_URL_PATH) ?: $url), $algo, strlen($body));
            continue;
        }

        $failed++;
        printf(
            "  FAIL  %s\n        declared: %s-%s\n        actual  : %s-%s\n"
            . "        The browser will refuse to load this file entirely.\n",
            $url,
            $algo,
            $expected,
            $algo,
            $actual
        );
    }
}

echo str_repeat('-', 60), "\n";
printf("  CDN INTEGRITY: %d checked, %d failed, %d unreachable\n", $checked, $failed, $unreachable);
echo str_repeat('-', 60), "\n";

if ($checked === 0) {
    echo "No integrity attributes found - nothing to verify.\n";
    exit(0);
}
if ($failed > 0) {
    exit(1);
}
if ($unreachable > 0) {
    echo "Some files could not be fetched; hashes were not verified.\n";
    exit(2);
}
echo "Every hash matches the file the browser will fetch.\n";
exit(0);
