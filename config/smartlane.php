<?php
/**
 * config/smartlane.php
 * ------------------------------------------------------------------
 * Thin wrapper around Smartlane's courier API (gcp.smartlane.dev).
 * Used by admin/commissions.php and admin/inquiries.php when admin
 * clicks "Send to Courier" on a payment_confirmed order.
 *
 * Reads SMARTLANE_API_TOKEN and SMARTLANE_TEST_MODE from .env
 * (loaded by your existing env loader — assumes getenv() works
 * because config/db.php or an autoloaded .env reader already ran).
 *
 * Does NOT touch the database. Callers pass in plain arrays and
 * get plain arrays/booleans back, then do their own DB updates so
 * this file stays a pure API client.
 * ------------------------------------------------------------------
 */
// ─── LOAD .ENV FILE ──────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
$value = trim($value);
$value = trim($value, '"\''); // ← strips surrounding quotes
putenv($key . '=' . $value);
$_ENV[$key] = $value;
        }
    }
}
// ─── END .ENV LOADER ────────────────────────────────────────────

if (!defined('SMARTLANE_BASE_URL')) {
    // Reads from .env so the base URL can be changed without touching code.
    // Smartlane moved from the old sandbox host (gcp.smartlane.dev) to a
    // production host (smartapi.pk) — set SMARTLANE_BASE_URL in .env to
    // whichever Smartlane gives you. Falls back to the new production host
    // if not set in .env.
    $envBaseUrl = getenv('SMARTLANE_BASE_URL');
    define('SMARTLANE_BASE_URL', $envBaseUrl !== false && $envBaseUrl !== ''
        ? rtrim($envBaseUrl, '/')
        : 'https://smartapi.pk/api/production');
}

/**
 * Returns true if SMARTLANE_TEST_MODE is enabled in .env.
 * In test mode, smartlane_create_consignment() does NOT call the
 * real API — it returns a fake "success" response so you can click
 * through the admin UI safely while setting things up.
 */
function smartlane_test_mode(): bool {
    $val = getenv('SMARTLANE_TEST_MODE');
    return $val === false ? true : in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
}

function smartlane_api_token(): string {
    return getenv('SMARTLANE_API_TOKEN') ?: '';
}

/**
 * Low-level HTTP call to Smartlane. Returns:
 *   ['ok' => bool, 'status' => int, 'body' => array|null, 'error' => string|null]
 */
function smartlane_request(string $method, string $path, ?array $body = null): array {
    $token = smartlane_api_token();
    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'SMARTLANE_API_TOKEN is not set in .env'];
    }

    $url = SMARTLANE_BASE_URL . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    error_log("[smartlane_request] $method $url -> HTTP $httpCode, curl_err=" . ($curlErr ?: 'none') . ", body=" . substr((string)$response, 0, 1000));

    if ($curlErr) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $curlErr];
    }

    $decoded = json_decode($response, true);
    $ok = $httpCode >= 200 && $httpCode < 300;

    return [
        'ok'     => $ok,
        'status' => $httpCode,
        'body'   => $decoded,
        'error'  => $ok ? null : ('Smartlane returned HTTP ' . $httpCode . ': ' . $response),
    ];
}

/**
 * Books a single consignment with Smartlane.
 *
 * $params keys (all required unless noted):
 *   warehouse_code   - artist's smartlane_warehouse_code (pickup point)
 *   store_order_id    - your orders.id (used to match the webhook later)
 *   consignee_name    - buyer name
 *   consignee_email   - buyer email (optional, pass '' if none)
 *   consignee_phone   - buyer phone
 *   consignee_address - shipping address
 *   consignee_city    - shipping city
 *   description       - short text describing what's in the parcel
 *   payment_method    - 'cod' or anything else maps to prepaid in Smartlane's eyes;
 *                        for our flow this is always non-cod since we only send
 *                        to courier after payment_confirmed, but the field is
 *                        still required by Smartlane so we pass it through.
 *   amount            - order total (string/number)
 *   product_count     - integer
 *   weight            - in kg, defaults to 0.5 if not provided
 *   products          - array of ['sku' => ..., 'name' => ..., 'qty' => ...]
 *
 * Returns:
 *   ['ok' => bool, 'error' => string|null, 'raw' => array|null]
 *
 * NOTE: Smartlane does NOT return the consignment_number synchronously.
 * It arrives later via webhook (see webhooks/smartlane.php), matched
 * back to your order using store_order_id.
 */
function smartlane_create_consignment(array $params): array {
    $required = ['warehouse_code', 'store_order_id', 'consignee_name', 'consignee_phone', 'consignee_address', 'consignee_city'];
    foreach ($required as $field) {
        if (empty($params[$field])) {
            return ['ok' => false, 'error' => "Missing required field: {$field}", 'raw' => null];
        }
    }

    $consignment = [
        'store_order_id'    => (string) $params['store_order_id'],
        'consignee_name'    => $params['consignee_name'],
        'consignee_email'   => $params['consignee_email'] ?? '',
        'consignee_phone'   => $params['consignee_phone'],
        'consignee_address' => $params['consignee_address'],
        'consignee_city'    => $params['consignee_city'],
        'description'       => $params['description'] ?? 'Art Bazaar order',
        'payment_method'    => $params['payment_method'] ?? 'bank_transfer',
        'amount'            => (string) ($params['amount'] ?? '0'),
        'product_count'     => (string) ($params['product_count'] ?? '1'),
        'weight'            => (string) ($params['weight'] ?? '0.5'),
        'products'          => $params['products'] ?? [],
    ];

    $body = [
        'store_warehouse_code' => $params['warehouse_code'],
        'consignments'          => [$consignment],
    ];

    if (smartlane_test_mode()) {
        // Safe dry-run: don't actually call Smartlane. Pretend it worked.
        return [
            'ok'    => true,
            'error' => null,
            'raw'   => [
                'test_mode' => true,
                'message'   => 'SMARTLANE_TEST_MODE is on — no real booking was made.',
                'sent_payload' => $body,
            ],
        ];
    }

    $result = smartlane_request('POST', '/consignment/create', $body);

    return [
        'ok'    => $result['ok'],
        'error' => $result['error'],
        'raw'   => $result['body'],
    ];
}

/**
 * Cancels a booked consignment (not wired into admin UI yet — available
 * for later if you add a "Cancel Shipment" button).
 */
function smartlane_cancel_consignment(string $storeOrderId): array {
    if (smartlane_test_mode()) {
        return ['ok' => true, 'error' => null, 'raw' => ['test_mode' => true]];
    }
    $result = smartlane_request('POST', '/consignment/cancel', ['store_order_id' => $storeOrderId]);
    return ['ok' => $result['ok'], 'error' => $result['error'], 'raw' => $result['body']];
}

/**
 * Tracks one or more consignments by store_order_id.
 * $storeOrderIds is an array of strings/ints.
 */
function smartlane_track_consignments(array $storeOrderIds): array {
    if (smartlane_test_mode()) {
        return ['ok' => true, 'error' => null, 'raw' => ['test_mode' => true]];
    }
    $qs = '';
    foreach ($storeOrderIds as $id) {
        $qs .= 'store_order_id[]=' . urlencode((string) $id) . '&';
    }
    $qs = rtrim($qs, '&');
    $result = smartlane_request('GET', '/consignment/track?' . $qs);
    return ['ok' => $result['ok'], 'error' => $result['error'], 'raw' => $result['body']];
}