<?php
require_once __DIR__ . '/config/smartlane.php';

echo "<h2>Smartlane API Test</h2>";

// Check if token exists
$token = smartlane_api_token();
echo "Token: " . ($token ? "✅ Found (starts with: " . substr($token, 0, 10) . "...)" : "❌ MISSING") . "<br>";

// Check test mode
echo "Test Mode: " . (smartlane_test_mode() ? "ON (simulated)" : "OFF (real API)") . "<br><br>";

// Test connection
echo "<h3>Testing API connection...</h3>";

$result = smartlane_request('GET', '/api/consignment/warehouse/list');

echo "Status Code: " . ($result['status'] ?? 'N/A') . "<br>";
echo "OK: " . ($result['ok'] ? '✅' : '❌') . "<br>";

if ($result['error']) {
    echo "Error: " . $result['error'] . "<br>";
}

if ($result['body']) {
    echo "<pre>";
    print_r($result['body']);
    echo "</pre>";
}
?>