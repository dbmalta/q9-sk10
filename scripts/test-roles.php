<?php

/**
 * Smoke test for the roles page (requires login).
 * Run: php scripts/test-roles.php
 */

$baseUrl = 'http://localhost:8080';
$cookieJar = '/tmp/sk10-roles-cookies.txt';

echo "Testing roles page...\n\n";

// Step 1: Login
$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
]);
$html = curl_exec($ch);
curl_close($ch);

preg_match('/value="([a-f0-9]{64})"/', $html, $m);
$csrf = $m[1] ?? '';

$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'email' => 'admin@scoutkeeper.local',
        'password' => '5e0dcc4cb1349804',
        '_csrf_token' => $csrf,
    ]),
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_FOLLOWLOCATION => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login: HTTP $httpCode " . ($httpCode === 302 ? 'OK' : 'FAIL') . "\n";

// Step 2: GET /admin/roles
$ch = curl_init("$baseUrl/admin/roles");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_COOKIEJAR => $cookieJar,
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "GET /admin/roles: HTTP $httpCode\n";

if ($httpCode === 200) {
    // Check for expected content
    if (str_contains($html, 'Super Admin')) {
        echo "  Contains 'Super Admin' role: YES\n";
    } else {
        echo "  Contains 'Super Admin' role: NO\n";
    }
    if (str_contains($html, 'Group Leader')) {
        echo "  Contains 'Group Leader' role: YES\n";
    }
    if (str_contains($html, 'Section Leader')) {
        echo "  Contains 'Section Leader' role: YES\n";
    }
    if (str_contains($html, 'bi-shield-lock')) {
        echo "  Has shield icon: YES\n";
    }
} else {
    echo "  FAIL: Expected 200\n";
    echo "  First 500 chars: " . substr($html, 0, 500) . "\n";
}

@unlink($cookieJar);
echo "\nDone.\n";
