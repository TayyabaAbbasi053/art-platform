<?php
// ============================================================
//  cron_cleanup.php — Expired Cart Reservation Cleanup
//  Run via cPanel Cron Job — NOT accessible via browser
// ============================================================

// Block direct browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/config/db.php';

$log = [];
$log[] = "[" . date('Y-m-d H:i:s') . "] Cart cleanup started.";

// ── Step 1: Release expired artwork reservations ──────────────
$releaseResult = $conn->query("
    UPDATE artworks 
    SET reserved_by = NULL, reserved_until = NULL 
    WHERE reserved_by IS NOT NULL 
    AND reserved_until IS NOT NULL 
    AND reserved_until < NOW()
");

$releasedCount = $conn->affected_rows;
$log[] = "  Released $releasedCount expired artwork reservation(s).";

// ── Step 2: Remove those items from shopping_cart ─────────────
// Removes cart rows for artworks that are no longer reserved
$deleteResult = $conn->query("
    DELETE sc FROM shopping_cart sc
    JOIN artworks a ON sc.item_type = 'artwork' AND sc.item_id = a.id
    WHERE a.reserved_by IS NULL 
    AND a.reserved_until IS NULL
    AND NOT EXISTS (
        SELECT 1 FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE oi.item_type = 'artwork' AND oi.item_id = a.id
        AND o.order_status NOT IN ('cancelled', 'refunded')
    )
");

$deletedCount = $conn->affected_rows;
$log[] = "  Removed $deletedCount expired cart item(s).";

$log[] = "[" . date('Y-m-d H:i:s') . "] Cleanup complete.";
$log[] = str_repeat("-", 50);

// ── Output to log ─────────────────────────────────────────────
echo implode(PHP_EOL, $log) . PHP_EOL;