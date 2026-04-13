<?php

/**
 * Smoke test for the org structure pages.
 * Run: php scripts/test-org.php
 */

$baseUrl = 'http://localhost:8080';
$cookieJar = '/tmp/sk10-org-cookies.txt';

echo "Testing org structure pages...\n\n";

// Login
$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieJar]);
$html = curl_exec($ch);
curl_close($ch);

preg_match('/value="([a-f0-9]{64})"/', $html, $m);
$csrf = $m[1] ?? '';

$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'email' => 'admin@scoutkeeper.local', 'password' => '2b065bfda51a51cd', '_csrf_token' => $csrf,
    ]),
    CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Login: HTTP $code " . ($code === 302 ? 'OK' : 'FAIL') . "\n";

// GET /admin/org
$ch = curl_init("$baseUrl/admin/org");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_COOKIEJAR => $cookieJar]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "GET /admin/org: HTTP $code\n";
echo "  Has tree view: " . (str_contains($html, 'bi-diagram-3') ? 'YES' : 'NO') . "\n";
echo "  Has Add Node: " . (str_contains($html, 'Add Node') ? 'YES' : 'NO') . "\n";

// GET /admin/org/levels
$ch = curl_init("$baseUrl/admin/org/levels");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_COOKIEJAR => $cookieJar]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "GET /admin/org/levels: HTTP $code\n";
echo "  Has Add Level: " . (str_contains($html, 'Add Level Type') ? 'YES' : 'NO') . "\n";

@unlink($cookieJar);
echo "\nDone.\n";
