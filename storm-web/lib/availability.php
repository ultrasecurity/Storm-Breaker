<?php
/**
 * lib/availability.php — RDAP domain availability check using curl_multi.
 *
 * Queries RDAP servers to determine if a domain is registered.
 * Uses curl_multi for parallel lookups.
 */

define('RDAP_SERVERS', [
    'com'  => 'https://rdap.verisign.com/com/v1/domain/',
    'net'  => 'https://rdap.verisign.com/net/v1/domain/',
    'org'  => 'https://rdap.org.foundation/v1/domain/',
    'io'   => 'https://rdap.nic.io/domain/',
    'dev'  => 'https://rdap.nic.google/domain/',
    'app'  => 'https://rdap.nic.google/domain/',
    'co'   => 'https://rdap.nic.co/domain/',
    'info' => 'https://rdap.afilias.net/rdap/info/domain/',
    'biz'  => 'https://rdap.afilias.net/rdap/biz/domain/',
]);

define('RDAP_BOOTSTRAP', 'https://rdap.org/domain/');

/**
 * Check domain availability for multiple domains in parallel.
 *
 * @param array $domains Array of domain strings
 * @return array Keyed by domain => {available: bool|null, status: int, error: string|null}
 */
function availability_check(array $domains): array {
    if (empty($domains)) return [];

    $mh = curl_multi_init();
    $handles = [];

    foreach ($domains as $domain) {
        $domain = strtolower(trim($domain));
        if ($domain === '') continue;

        $tld = strtolower(pathinfo($domain, PATHINFO_EXTENSION));
        $rdap_url = isset(RDAP_SERVERS[$tld])
            ? RDAP_SERVERS[$tld] . $domain
            : RDAP_BOOTSTRAP . $domain;

        $ch = curl_init($rdap_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_NOBODY         => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/rdap+json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $handles[$domain] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    // Execute all in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $domain => $ch) {
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);

        if ($error !== '') {
            $results[$domain] = ['available' => null, 'status' => 0, 'error' => $error];
        } elseif ($status === 200) {
            $results[$domain] = ['available' => false, 'status' => 200, 'error' => null];
        } elseif ($status === 404) {
            $results[$domain] = ['available' => true, 'status' => 404, 'error' => null];
        } else {
            $results[$domain] = ['available' => null, 'status' => $status, 'error' => "Unexpected HTTP {$status}"];
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}
