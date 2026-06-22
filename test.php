<?php
require_once __DIR__ . '/config/smartlane.php';

echo "<pre>";

// Get API token
$token = getenv('SMARTLANE_API_TOKEN');
echo "Token: " . ($token ? "✅ Found" : "❌ MISSING") . "\n\n";

// Test API connection
$ch = curl_init('https://gcp.smartlane.dev/v1/consignment/create');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Error: " . ($error ?: "None") . "\n";
echo "Response: " . substr($response, 0, 500) . "\n";