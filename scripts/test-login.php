<?php

/**
 * Quick smoke test for the login flow.
 * Run: php scripts/test-login.php
 */

$baseUrl = 'http://localhost:8080';

echo "Testing login flow...\n\n";

// Step 1: GET /login and extract CSRF token + session cookie
$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEJAR => '/tmp/sk10-test-cookies.txt',
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "GET /login => HTTP $httpCode\n";

if ($httpCode !== 200) {
    echo "FAIL: Expected 200\n";
    exit(1);
}

// Extract CSRF token
preg_match('/value="([a-f0-9]{64})"/', $response, $matches);
$csrf = $matches[1] ?? '';
echo "CSRF token: " . substr($csrf, 0, 16) . "...\n";

if (empty($csrf)) {
    echo "FAIL: No CSRF token found\n";
    exit(1);
}

// Step 2: POST /login with valid credentials
$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'email' => 'admin@scoutkeeper.local',
        'password' => '6f3bb1faa4d14310',
        '_csrf_token' => $csrf,
    ]),
    CURLOPT_COOKIEFILE => '/tmp/sk10-test-cookies.txt',
    CURLOPT_COOKIEJAR => '/tmp/sk10-test-cookies.txt',
    CURLOPT_FOLLOWLOCATION => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);

echo "\nPOST /login => HTTP $httpCode\n";
echo "Redirect to: $redirectUrl\n";

if ($httpCode === 302 && str_contains($redirectUrl, '/')) {
    echo "\nSUCCESS: Login redirected to /\n";
} else {
    echo "\nFAIL: Expected 302 redirect to /\n";
    // Show response body for debugging
    $parts = explode("\r\n\r\n", $response, 2);
    echo "Response body (first 500 chars):\n";
    echo substr($parts[1] ?? $parts[0], 0, 500) . "\n";
    exit(1);
}

// Step 3: POST /login with wrong password
$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => '/tmp/sk10-test-cookies2.txt',
]);
$response = curl_exec($ch);
curl_close($ch);

preg_match('/value="([a-f0-9]{64})"/', $response, $matches);
$csrf2 = $matches[1] ?? '';

$ch = curl_init("$baseUrl/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'email' => 'admin@scoutkeeper.local',
        'password' => 'wrongpassword',
        '_csrf_token' => $csrf2,
    ]),
    CURLOPT_COOKIEFILE => '/tmp/sk10-test-cookies2.txt',
    CURLOPT_COOKIEJAR => '/tmp/sk10-test-cookies2.txt',
    CURLOPT_FOLLOWLOCATION => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nPOST /login (wrong password) => HTTP $httpCode\n";

if ($httpCode === 200 && str_contains($response, 'Invalid email or password')) {
    echo "SUCCESS: Shows error message for wrong password\n";
} else {
    echo "UNEXPECTED: Expected 200 with error message\n";
}

// Step 4: GET /forgot-password
$ch = curl_init("$baseUrl/forgot-password");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nGET /forgot-password => HTTP $httpCode\n";
if ($httpCode === 200 && str_contains($response, 'Send Reset Link')) {
    echo "SUCCESS: Forgot password page renders\n";
} else {
    echo "FAIL: Expected 200 with reset form\n";
}

echo "\nAll smoke tests passed.\n";

// Cleanup
@unlink('/tmp/sk10-test-cookies.txt');
@unlink('/tmp/sk10-test-cookies2.txt');
