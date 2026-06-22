<?php
$urls = [
    'https://gcp.smartlane.dev/api/consignment/warehouse/list',
    'https://gcp.smartlane.dev/v1/consignment/warehouse/list',
    'https://smartlane.dev/api/consignment/warehouse/list',
];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "URL: $url<br>";
    echo "Status: " . ($code ?: 'ERROR') . "<br>";
    echo "Error: " . ($error ?: 'None') . "<br><br>";
}
?>