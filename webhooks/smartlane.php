<?php
/**
 * webhooks/smartlane.php
 * ------------------------------------------------------------------
 * Public endpoint Smartlane calls after a consignment is successfully
 * booked. Register this URL on the Smartlane Portal's webhook setting:
 *
 *     https://yourdomain.com/webhooks/smartlane.php
 *
 * This file is NOT behind admin login — Smartlane's server hits it
 * directly. Do not add session_start()/role checks here.
 *
 * What it does:
 *   1. Reads the incoming JSON payload.
 *   2. Finds store_order_id + consignment_number in it.
 *   3. Matches store_order_id to orders.id.
 *   4. Saves tracking_number = consignment_number.
 *   5. Flips order_status to 'shipped' (only if it isn't already
 *      shipped/delivered/cancelled, so we don't clobber a later state).
 *   6. Logs the change to order_status_history.
 *   7. Always responds 200 OK with a small JSON body — Smartlane may
 *      retry the webhook if it doesn't get a 2xx response.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// ── Optional shared-secret check ─────────────────────────────────
// If Smartlane lets you configure a static token/secret that they
// send back on every webhook call (header or query param), verify
// it here before trusting the payload. Uncomment and adjust once
// you confirm with Smartlane what they send.
//
// $expected = getenv('SMARTLANE_WEBHOOK_SECRET');
// $provided = $_GET['secret'] ?? ($_SERVER['HTTP_X_SMARTLANE_SECRET'] ?? '');
// if ($expected && $provided !== $expected) {
//     http_response_code(401);
//     echo json_encode(['ok' => false, 'error' => 'Invalid webhook secret']);
//     exit;
// }

// ── Read payload ──────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

// Smartlane's exact webhook shape isn't documented in the PDF beyond
// "Consignment Number will be returned through webhook after successful
// booking" — so we accept a few likely shapes defensively.
$storeOrderId = $payload['store_order_id']
    ?? $payload['data']['store_order_id']
    ?? null;

$consignmentNumber = $payload['consignment_number']
    ?? $payload['data']['consignment_number']
    ?? null;

// Log every incoming call (raw) so you can see the real shape Smartlane
// sends the first time this fires, and adjust the lookups above if needed.
$logLine = date('Y-m-d H:i:s') . ' | ' . $rawBody . "\n";
@file_put_contents(__DIR__ . '/smartlane_webhook.log', $logLine, FILE_APPEND);

if (!$storeOrderId || !$consignmentNumber) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'Missing store_order_id or consignment_number in payload',
    ]);
    exit;
}

$orderId = (int) $storeOrderId;

// ── Find the order ────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, order_status FROM orders WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "No order found with id {$orderId}"]);
    exit;
}

$oldStatus = $order['order_status'];

// Don't downgrade an order that's already further along or terminal.
$skipStatusUpdate = in_array($oldStatus, ['shipped', 'delivered', 'cancelled'], true);

// ── Save tracking number (always) ────────────────────────────────
$consignmentNumber = (string) $consignmentNumber;
if ($skipStatusUpdate) {
    $stmt = $conn->prepare("UPDATE orders SET tracking_number = ? WHERE id = ?");
    $stmt->bind_param('si', $consignmentNumber, $orderId);
} else {
    $stmt = $conn->prepare("UPDATE orders SET tracking_number = ?, order_status = 'shipped' WHERE id = ?");
    $stmt->bind_param('si', $consignmentNumber, $orderId);
}
$stmt->execute();

// ── Log to order_status_history ──────────────────────────────────
if (!$skipStatusUpdate) {
    $note = "Smartlane webhook: consignment booked, tracking number {$consignmentNumber}.";
    $stmtH = $conn->prepare("
        INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, notes)
        VALUES (?, ?, 'shipped', 'system', ?)
    ");
    $stmtH->bind_param('iss', $orderId, $oldStatus, $note);
    $stmtH->execute();
} else {
    $note = "Smartlane webhook: tracking number {$consignmentNumber} saved (order already {$oldStatus}, status not changed).";
    $stmtH = $conn->prepare("
        INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, notes)
        VALUES (?, ?, ?, 'system', ?)
    ");
    $stmtH->bind_param('isss', $orderId, $oldStatus, $oldStatus, $note);
    $stmtH->execute();
}

http_response_code(200);
echo json_encode([
    'ok'                  => true,
    'order_id'            => $orderId,
    'tracking_number'     => $consignmentNumber,
    'order_status_before' => $oldStatus,
    'order_status_after'  => $skipStatusUpdate ? $oldStatus : 'shipped',
]);