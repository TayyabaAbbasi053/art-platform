<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/smartlane.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../../vendor/autoload.php';
function sendArtistOrderEmail(mysqli $conn, int $orderId, string $orderType): string {
    $stmt = $conn->prepare("
        SELECT u.email AS artist_email, u.name AS artist_name,
               a.title AS artwork_title, o.order_number
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
        JOIN users u ON a.artist_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) return 'DEBUG: no matching order/artwork/artist row for order_id ' . $orderId;
    if (empty($row['artist_email'])) return 'DEBUG: row found but artist_email is empty';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'teamartbazaar.pk@gmail.com';
        $mail->Password   = 'REMOVED';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addReplyTo('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addAddress($row['artist_email'], $row['artist_name']);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $label = $orderType === 'cod' ? 'New COD Order' : 'Payment Confirmed';
        $mail->Subject = "Art Bazaar — {$label}: Order #{$row['order_number']}";
        $mail->AltBody = "New order ({$label}) for \"{$row['artwork_title']}\" — Order #{$row['order_number']}.";
        $mail->Body    = "<p>Hi {$row['artist_name']}, new order for <strong>{$row['artwork_title']}</strong>. Order #{$row['order_number']}.</p>";
        $mail->send();
        return 'OK: sent to ' . $row['artist_email'];
    } catch (Exception $e) {
        return 'DEBUG: PHPMailer error — ' . $mail->ErrorInfo;
    }
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $toast = $_SESSION['toast'] ?? '';
 unset($_SESSION['toast']);

function getArtworkImageUrl($imagePath) {
    if (empty($imagePath)) return null;
    $imagePath = ltrim($imagePath, './');
    if (strpos($imagePath, 'uploads/') === 0) return '../../' . $imagePath;
    return '../../uploads/artworks/' . $imagePath;
}

// ── Handle actions ──────────────────────────────────────

// Mark as Paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $methodRes = $conn->query("SELECT payment_method FROM orders WHERE id = $id");
        $methodRow = $methodRes ? $methodRes->fetch_assoc() : null;

        if ($methodRow && $methodRow['payment_method'] === 'cod') {
            $toast = 'This order is Cash on Delivery — there is no screenshot to verify.';
        } else {
            // Check if this order's artwork is Digital Art — if so, it's delivered immediately
            $digitalRes = $conn->query("
                SELECT c.slug AS category_slug
                FROM order_items oi
                JOIN artworks a ON oi.item_id = a.id AND oi.item_type = 'artwork'
                JOIN categories c ON a.category_id = c.id
                WHERE oi.order_id = $id
                LIMIT 1
            ");
            $digitalRow = $digitalRes ? $digitalRes->fetch_assoc() : null;
            $isDigitalOrder = $digitalRow && $digitalRow['category_slug'] === 'digital-art';

            $adminId = (int)$_SESSION['user_id'];

            if ($isDigitalOrder) {
                // Digital artwork — nothing to ship, mark paid AND delivered in one step
                $stmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', order_status = 'delivered' WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();

                $note = 'Payment verified by admin. Digital artwork auto-delivered.';
                $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'pending', 'delivered', 'admin', ?, ?)");
                $stmtH->bind_param('iis', $id, $adminId, $note);
                $stmtH->execute();

                $toast = 'Order marked as paid. Digital artwork delivered to buyer.';
            } else {
                // Update payment status and move order to confirmed
                $stmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', order_status = 'payment_confirmed' WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $emailDebug = sendArtistOrderEmail($conn, $id, 'payment_confirmed');

                $note = 'Payment verified by admin. Order confirmed.';
                $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'pending', 'payment_confirmed', 'admin', ?, ?)");
                $stmtH->bind_param('iis', $id, $adminId, $note);
                $stmtH->execute();

                $toast = 'Order marked as paid and confirmed. [' . $emailDebug . ']';
            }
        }
    }
}

// Update order status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $id = (int)($_POST['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $validStatuses = ['pending', 'payment_confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
    
    if ($id && in_array($newStatus, $validStatuses)) {
        $oldRes = $conn->query("SELECT order_status, buyer_id, payment_method FROM orders WHERE id = $id");
        if ($oldRow = $oldRes->fetch_assoc()) {
            $oldStatus = $oldRow['order_status'];
            
            $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $id);
            $stmt->execute();

            // COD orders have no screenshot to verify — cash is confirmed collected on delivery
            if ($newStatus === 'delivered' && $oldRow['payment_method'] === 'cod') {
                $conn->query("UPDATE orders SET payment_status = 'cod_collected' WHERE id = $id");
            }

            // If cancelled via dropdown, release artworks same as cancel button
            if ($newStatus === 'cancelled') {
                $conn->query("UPDATE artworks SET status = 'approved', reserved_by = NULL WHERE id IN (SELECT item_id FROM order_items WHERE order_id = $id AND item_type = 'artwork')");
            }

            $itemRes = $conn->query("SELECT item_id FROM order_items WHERE order_id = $id AND item_type = 'artwork' LIMIT 1");
            if ($itemRow = $itemRes->fetch_assoc()) {
                $artId = $itemRow['item_id'];
                $deliveryStatusMap = [
                    'pending' => 'not_applicable',
                    'confirmed' => 'pending',
                    'processing' => 'processing',
                    'shipped' => 'shipped',
                    'delivered' => 'delivered',
                    'cancelled' => 'not_applicable'
                ];
                $newDeliveryStatus = $deliveryStatusMap[$newStatus] ?? 'not_applicable';
                
                $artStmt = $conn->prepare("UPDATE artworks SET delivery_status = ? WHERE id = ?");
                $artStmt->bind_param('si', $newDeliveryStatus, $artId);
                $artStmt->execute();
            }

            $adminId = (int)$_SESSION['user_id'];
            $note = 'Status updated by admin to ' . ucfirst($newStatus);
            $stmtH = $conn->prepare("
                INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes)
                VALUES (?, ?, ?, 'admin', ?, ?)
            ");
            $stmtH->bind_param('issis', $id, $oldStatus, $newStatus, $adminId, $note);
            $stmtH->execute();
            
            $toast = 'Order status updated to ' . ucfirst($newStatus) . '.';
        }
    }
}

// Confirm/Forward to Artist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forward_to_artist') {
    $id = (int)($_POST['id'] ?? 0);
    $deliveryStatus = $_POST['delivery_status'] ?? 'pending';

    if ($id) {
        $orderRow = $conn->query("SELECT payment_method, order_status FROM orders WHERE id = $id")->fetch_assoc();
        $oldOrderStatus = $orderRow['order_status'] ?? '';
        $isCod = ($orderRow && $orderRow['payment_method'] === 'cod');
$newForwardStatus = $isCod ? 'cod' : 'payment_confirmed';

        $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $stmt->bind_param('si', $newForwardStatus, $id);
        $stmt->execute();

        if (!in_array($oldOrderStatus, ['cod', 'payment_confirmed'], true)) {
            sendArtistOrderEmail($conn, $id, $newForwardStatus);
        }

        $itemRes = $conn->query("SELECT item_id FROM order_items WHERE order_id = $id AND item_type = 'artwork' LIMIT 1");
        if ($itemRow = $itemRes->fetch_assoc()) {
            $artId = $itemRow['item_id'];
            $artUpdate = $conn->prepare("UPDATE artworks SET delivery_status = ? WHERE id = ?");
            $artUpdate->bind_param('si', $deliveryStatus, $artId);
            $artUpdate->execute();
        }

        $adminId = (int)$_SESSION['user_id'];
        $histNote = $isCod
            ? 'COD order forwarded to artist. Awaiting artist confirmation.'
            : 'Payment confirmed. Order forwarded to artist to start work.';

        $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'pending', ?, 'admin', ?, ?)");
        $stmtH->bind_param('isis', $id, $newForwardStatus, $adminId, $histNote);
        $stmtH->execute();

        $toast = 'Order forwarded to artist.';
    }
}

// Save admin notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes') {
    $id = (int)($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');
    
    $stmt = $conn->prepare("UPDATE orders SET admin_notes = ? WHERE id = ?");
    $stmt->bind_param('si', $notes, $id);
    $stmt->execute();
    $toast = 'Notes saved.';
}

// Cancel Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE artworks SET status = 'approved', reserved_by = NULL WHERE id IN (SELECT item_id FROM order_items WHERE order_id = $id AND item_type = 'artwork')");
        $stmt = $conn->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $adminId = (int)$_SESSION['user_id'];
        $note = 'Order cancelled by admin. Artworks released back to available.';
        $oldStatusRes = $conn->query("SELECT order_status FROM orders WHERE id = $id");
        $oldStatus = $oldStatusRes ? ($oldStatusRes->fetch_assoc()['order_status'] ?? 'pending') : 'pending';
        
        $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, ?, 'cancelled', 'admin', ?, ?)");
        $stmtH->bind_param('isis', $id, $oldStatus, $adminId, $note);
        $stmtH->execute();
        $toast = 'Order cancelled and artworks released.';
    }
}

// Delete Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("DELETE FROM order_items WHERE order_id = $id");
        $conn->query("DELETE FROM order_status_history WHERE order_id = $id");
        $conn->query("DELETE FROM order_messages WHERE order_id = $id");
        $conn->query("DELETE FROM orders WHERE id = $id");
        $toast = 'Order deleted.';
    }
}

// Update tracking number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_tracking') {
    $id = (int)($_POST['id'] ?? 0);
    $tracking = trim($_POST['tracking_number'] ?? '');
    $courier = trim($_POST['courier'] ?? '');
    if ($id) {
        $stmt = $conn->prepare("UPDATE orders SET tracking_number = ?, courier = ? WHERE id = ?");
        $stmt->bind_param('ssi', $tracking, $courier, $id);
        $stmt->execute();
        $toast = 'Shipping info updated.';
    }
}

// Send to Courier (Books a Smartlane consignment for a payment_confirmed artwork order)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_courier') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $orderRow = $conn->query("
            SELECT o.id, o.order_number, o.order_status, o.payment_method, o.total,
                   o.shipping_address, o.shipping_city, o.shipping_phone,
                   o.guest_name, o.guest_email, o.guest_phone,
                   COALESCE(u.name, o.guest_name) AS buyer_name_resolved,
                   COALESCE(u.email, o.guest_email) AS buyer_email_resolved,
                   COALESCE(u.phone, o.guest_phone, o.shipping_phone) AS buyer_phone_resolved,
                   a.title AS artwork_title,
                   ap.smartlane_warehouse_code
            FROM orders o
            LEFT JOIN users u ON o.buyer_id = u.id
            LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.item_type = 'artwork'
            LEFT JOIN artworks a ON a.id = oi.item_id
            LEFT JOIN artist_profiles ap ON ap.user_id = a.artist_id
            WHERE o.id = $id
            LIMIT 1
        ")->fetch_assoc();

        if (!$orderRow) {
            $toast = 'Order not found.';
        } elseif ($orderRow['order_status'] !== 'payment_confirmed') {
            $toast = 'This order must be in Payment Confirmed status before sending to courier.';
        } elseif (empty($orderRow['smartlane_warehouse_code'])) {
            $toast = 'This artist has no Smartlane warehouse code set. Add it on the Artists page first.';
        } else {
            error_log("[send_to_courier] order_id=$id warehouse_code={$orderRow['smartlane_warehouse_code']} test_mode=" . (smartlane_test_mode() ? '1' : '0'));
            $result = smartlane_create_consignment([
                'warehouse_code'    => $orderRow['smartlane_warehouse_code'],
                'store_order_id'    => $orderRow['id'],
                'consignee_name'    => $orderRow['buyer_name_resolved'] ?: 'Customer',
                'consignee_email'   => $orderRow['buyer_email_resolved'] ?: '',
                'consignee_phone'   => $orderRow['buyer_phone_resolved'] ?: '',
                'consignee_address' => $orderRow['shipping_address'] ?: 'Address not provided',
                'consignee_city'    => $orderRow['shipping_city'] ?: 'Unknown',
                'description'       => $orderRow['artwork_title'] ?: 'Art Bazaar artwork order',
                'payment_method'    => $orderRow['payment_method'] ?: 'bank_transfer',
                'amount'            => $orderRow['total'] ?? 0,
                'product_count'     => 1,
                'weight'            => 0.5,
                'products'          => [[
                    'sku'  => 'order-' . $orderRow['id'],
                    'name' => $orderRow['artwork_title'] ?: ('Order #' . $orderRow['order_number']),
                    'qty'  => '1',
                ]],
            ]);

            error_log("[send_to_courier] order_id=$id smartlane result: " . json_encode($result));

            if ($result['ok']) {
                $conn->query("UPDATE orders SET order_status = 'processing' WHERE id = $id");
                $adminId = (int)$_SESSION['user_id'];
                $note = smartlane_test_mode()
                    ? 'Sent to courier (TEST MODE — no real booking made).'
                    : 'Sent to Smartlane courier for booking.';
                $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'payment_confirmed', 'processing', 'admin', ?, ?)");
                $stmtH->bind_param('iis', $id, $adminId, $note);
                $stmtH->execute();
                $toast = smartlane_test_mode()
                    ? 'Sent to courier (test mode — no real booking made yet).'
                    : 'Sent to courier. Tracking number will appear once Smartlane confirms booking.';
            } else {
                $toast = 'Smartlane booking failed: ' . ($result['error'] ?? 'Unknown error');
            }
        }
    }
}

// ── Fetch Data ──────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['toast'] = $toast;
    header('Location: inquiries.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

$statusFilter = $_GET['status'] ?? '';
 $search = trim($_GET['q'] ?? '');
 $sort = $_GET['sort'] ?? 'newest';
 $page = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 15;
 $offset = ($page - 1) * $perPage;

 $validStatuses = ['pending','payment_confirmed','processing','cod','shipped','delivered','cancelled'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = '';

 $where = ["o.order_type = 'artwork'"];
 $params = [];
 $types = '';

if ($statusFilter) {
    $where[] = "o.order_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

if ($search) {
    $like = "%$search%";
    $where[] = "(o.guest_name LIKE ? OR o.guest_email LIKE ? OR o.guest_phone LIKE ? OR o.shipping_phone LIKE ? OR o.shipping_address LIKE ? OR o.order_number LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR a.title LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    $types .= 'sssssssss';
}

 $whereSQL = implode(' AND ', $where);

 $sortMap = [
    'newest' => 'o.created_at DESC',
    'oldest' => 'o.created_at ASC',
    'name' => 'COALESCE(u.name, o.guest_name) ASC',
    'amount_high' => 'o.total DESC',
    'amount_low' => 'o.total ASC',
];
 $sortBy = $sortMap[$sort] ?? 'o.created_at DESC';

 $countSQL = "SELECT COUNT(*) FROM orders o 
             LEFT JOIN users u ON o.buyer_id = u.id 
             LEFT JOIN order_items oi ON o.id = oi.order_id AND oi.item_type = 'artwork'
             LEFT JOIN artworks a ON oi.item_id = a.id
             WHERE $whereSQL";
             
 $stmt = $conn->prepare($countSQL);
if ($params) $stmt->bind_param($types, ...$params);
 $stmt->execute();
 $totalResults = (int)$stmt->get_result()->fetch_row()[0];
 $totalPages = max(1, ceil($totalResults / $perPage));

 // CHANGE 1: Added payment_screenshot to SELECT
 $dataSQL = "SELECT 
            o.id,
            o.order_number,
            o.order_type,
            o.order_status AS item_status,
            o.payment_status,
            o.payment_screenshot,
            o.payment_method,
            o.created_at,
            o.admin_notes,
            o.shipping_address,
            o.shipping_city,
            o.shipping_phone,
            o.tracking_number,
            o.courier,
            o.total AS total_price,
            o.buyer_notes AS message,
            COALESCE(u.name, o.guest_name) AS buyer_name,
            COALESCE(u.email, o.guest_email) AS buyer_email,
            COALESCE(u.phone, o.guest_phone, o.shipping_phone) AS buyer_phone
          FROM orders o
          LEFT JOIN users u ON o.buyer_id = u.id
          LEFT JOIN order_items oi2 ON oi2.order_id = o.id AND oi2.item_type = 'artwork'
          LEFT JOIN artworks a2 ON a2.id = oi2.item_id
          LEFT JOIN artist_profiles ap2 ON ap2.user_id = a2.artist_id
          WHERE $whereSQL
          ORDER BY $sortBy
          LIMIT $perPage OFFSET $offset";

 $stmt = $conn->prepare($dataSQL);
if ($params) $stmt->bind_param($types, ...$params);
 $stmt->execute();
 $res = $stmt->get_result();

 // CHANGE 2: Added nested artwork fetch
 $items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}

foreach ($items as &$item) {
    $oid = (int)$item['id'];
    $artRes = $conn->query("
        SELECT a.id, a.title, a.price, a.delivery_status, a.artist_id,
               u.name AS artist_name,
               ap.smartlane_warehouse_code,
               (SELECT image_path FROM artwork_images
                WHERE artwork_id = a.id
                ORDER BY is_cover DESC, sort_order ASC LIMIT 1) AS image_path
        FROM order_items oi
        JOIN artworks a ON oi.item_id = a.id
        JOIN users u ON a.artist_id = u.id
        LEFT JOIN artist_profiles ap ON ap.user_id = a.artist_id
        WHERE oi.order_id = $oid AND oi.item_type = 'artwork'
    ");
    $item['artworks'] = $artRes ? $artRes->fetch_all(MYSQLI_ASSOC) : [];
    $item['artwork_title'] = !empty($item['artworks']) ? $item['artworks'][0]['title'] : 'Artwork Item(s)';
    $item['artwork_image'] = !empty($item['artworks']) ? $item['artworks'][0]['image_path'] : '';
    $item['artist_name']   = !empty($item['artworks']) ? $item['artworks'][0]['artist_name'] : '';
    $item['smartlane_warehouse_code'] = !empty($item['artworks']) ? $item['artworks'][0]['smartlane_warehouse_code'] : null;
}
unset($item);

 $statusCounts = ['all' => 0];
foreach ($validStatuses as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM orders WHERE order_type='artwork' AND order_status='$s'");
    $statusCounts[$s] = (int)$r->fetch_row()[0];
}
 $statusCounts['all'] = array_sum($statusCounts);

function buildQS($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders & Inquiries — Art Bazaar Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg: #F6EDDE; --card: #F6EDDE; --sand: #DDCDAE; --border: #0C3F30;
            --ink: #0C3F30; --body: #0C3F30; --muted: #0C3F30; --light: #0C3F30;
            --r: 16px;
            --sidebar: 240px; --top: 60px;
        }
        html, body { height: 100%; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; }
        
        /* ── Sidebar ───────────────────────────────────────── */
        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar); height: 100vh; background: var(--ink); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
        .sidebar-brand { padding: 22px 24px 18px; border-bottom: 1px solid var(--border); }
        .sidebar-brand .logo-tag { font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--bg); }
        .sidebar-brand .logo-name { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--bg); margin-top: 2px; }
        .sidebar-brand .logo-badge { display: inline-block; margin-top: 6px; background: var(--sand); color: var(--ink); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
        .sidebar-section { padding: 18px 16px 6px; font-size: 9px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--sand); font-weight: 500; }
        .nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 12.5px; color: var(--bg); text-decoration: none; font-weight: 400; border-left: 2px solid transparent; transition: all .15s; }
        .nav-item:hover { color: var(--ink); background: rgba(255,255,255,0.3); border-left-color: var(--sand); }
        .nav-item.active { color: var(--ink); background: var(--sand); font-weight: 500; border-left-color: var(--sand); }
        .nav-item .icon { width: 16px; height: 16px; flex-shrink: 0; opacity: .55; }
        .nav-item.active .icon, .nav-item:hover .icon { opacity: 1; }
        .badge { margin-left: auto; background: var(--sand); color: var(--ink); font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
        .sidebar-bottom { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
        .signout-btn { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 12px; color: var(--bg); text-decoration: none; border-radius: 8px; transition: all .15s; width: 100%; background: none; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
        .signout-btn:hover { background: rgba(255,255,255,0.1); color: var(--sand); }
        
        /* ── Topbar ──────────────────────────────────────────── */
        .topbar { position: fixed; top: 0; left: var(--sidebar); right: 0; height: var(--top); background: var(--ink); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; z-index: 99; }
        .topbar-left h1 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--bg); }
        .topbar-left .sub { font-size: 11px; color: var(--sand); margin-top: 1px; }
        .admin-chip { display: flex; align-items: center; gap: 8px; background: var(--sand); border: 1px solid var(--border); padding: 5px 12px 5px 5px; border-radius: 30px; }
        .admin-chip .avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--bg); font-weight: 600; }
        .admin-chip .name { font-size: 12px; color: var(--ink); font-weight: 500; }
        .admin-chip .arrow { font-size: 12px; color: var(--ink); margin-left: 4px; }
        
        /* ── Main ────────────────────────────────────────────── */
        .main { margin-left: var(--sidebar); padding-top: var(--top); min-height: 100vh; }
        .content { padding: 28px 32px; }
        
        /* ── Toast ───────────────────────────────────────────── */
        .toast { background: var(--sand); color: var(--ink); border: 1px solid var(--border); padding: 12px 20px; border-radius: 10px; font-size: 12.5px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
        .toast.hidden { display: none; }
        .toast-close { background: none; border: none; color: var(--ink); cursor: pointer; font-size: 16px; }
        
        /* ── Tabs ────────────────────────────────────────────── */
        .tabs { display: flex; gap: 4px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { display: flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 11.5px; color: var(--ink); text-decoration: none; border-radius: 10px; border: 1px solid transparent; transition: all .15s; font-weight: 400; background: none; cursor: pointer; font-family: 'DM Sans', sans-serif; }
        .tab:hover { background: var(--sand); border-color: var(--border); color: var(--ink); }
        .tab.active { background: var(--ink); color: var(--bg); font-weight: 500; }
        .tab .count { font-size: 10px; font-weight: 600; background: var(--sand); padding: 1px 7px; border-radius: 20px; color: var(--ink); }
        .tab.active .count { background: var(--bg); color: var(--ink); }
        .tab .count.hot { background: var(--sand); color: var(--ink); font-weight: 700; }
        
        /* ── Filters ─────────────────────────────────────────── */
        .filters { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .filters input[type="text"], .filters select { padding: 8px 14px; border: 1.5px solid var(--sand); border-radius: 9px; font-size: 12px; font-family: 'DM Sans', sans-serif; color: var(--ink); background: var(--bg); outline: none; transition: border-color .15s; }
        .filters input:focus, .filters select:focus { border-color: var(--ink); }
        .filters input { width: 240px; }
        .filters select { min-width: 160px; cursor: pointer; }
        .clear-link { font-size: 11px; color: var(--ink); text-decoration: none; cursor: pointer; background: none; border: none; font-family: 'DM Sans', sans-serif; }
        .clear-link:hover { color: var(--ink); }
        .results-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; font-size: 11px; color: var(--muted); }
        
        /* ── Card & Table ────────────────────────────────────── */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--r); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--border); background: var(--sand); white-space: nowrap; }
        td { font-size: 12.5px; color: var(--body); padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--bg); box-shadow: 0 4px 12px rgba(12,63,48,.06); }
        .td-buyer { color: var(--ink); font-weight: 500; }
        .td-buyer-sub { font-size: 11px; color: var(--muted); }
        .td-artwork { display: flex; align-items: center; gap: 10px; }
        .td-artwork-img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; background: var(--sand); border: 1px solid var(--border); flex-shrink: 0; }
        .td-artwork-img-placeholder { width: 40px; height: 40px; border-radius: 8px; background: var(--sand); display: flex; align-items: center; justify-content: center; font-size: 9px; color: var(--ink); flex-shrink: 0; }
        .td-artwork-title { color: var(--ink); font-weight: 500; font-size: 12px; max-width: 130px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .td-artwork-sub { font-size: 10px; color: var(--muted); }
        .td-price { font-weight: 600; color: var(--ink); white-space: nowrap; font-size: 12px; }
        
        /* ── Pills ───────────────────────────────────────────── */
        .pill { display: inline-block; font-size: 9px; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; background: var(--sand); color: var(--ink); }
        .pill.pending { background: var(--sand); color: var(--ink); }
.pill.cod-pending { background: #fff3cd; color: #856404; border: 1px solid #ffc107; font-weight: 700; }        
        /* ── Actions ─────────────────────────────────────────── */
        .td-actions { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
        .status-select { padding: 5px 8px; font-size: 10px; border: 1.5px solid var(--sand); border-radius: 7px; background: var(--bg); color: var(--ink); font-family: 'DM Sans', sans-serif; cursor: pointer; outline: none; }
        .status-select:focus { border-color: var(--ink); }
        .act-btn { padding: 5px 10px; font-size: 10.5px; font-weight: 500; border-radius: 7px; border: 1px solid var(--border); background: var(--sand); color: var(--ink); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; white-space: nowrap; }
        .act-btn:hover { border-color: var(--ink); color: var(--ink); background: #c4b69e; }
        .act-btn.red:hover { border-color: var(--border); background: var(--sand); color: var(--ink); }
        .act-btn.blue { background: var(--sand); color: var(--ink); border: 1px solid var(--border); }
        .act-btn.blue:hover { background: #c4b69e; }
        
        /* ── Modal & Pagination ─────────────────────────────── */
        .empty { text-align: center; padding: 48px 24px; color: var(--muted); font-size: 13px; }
        .pagination { display: flex; align-items: center; justify-content: center; gap: 4px; margin-top: 20px; }
        .page-btn { padding: 7px 13px; font-size: 11.5px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: var(--ink); cursor: pointer; font-family: 'DM Sans', sans-serif; text-decoration: none; transition: all .12s; }
        .page-btn:hover { border-color: var(--ink); color: var(--ink); }
        .page-btn.active { background: var(--ink); color: var(--bg); border-color: var(--ink); }
        .page-btn.disabled { opacity: .35; pointer-events: none; }
        
        .modal-overlay { position: fixed; inset: 0; background: rgba(12,63,48,.4); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
        .modal-overlay.open { opacity: 1; pointer-events: auto; }
        .modal { background: var(--card); border-radius: 16px; width: 620px; max-width: 92vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 60px rgba(0,0,0,.15); transform: translateY(12px); transition: transform .2s; border: 1px solid var(--border); }
        .modal-overlay.open .modal { transform: translateY(0); }
        .modal-head { padding: 24px 28px 0; display: flex; align-items: flex-start; justify-content: space-between; }
        .modal-head h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 400; color: var(--ink); }
        .modal-close { background: none; border: none; font-size: 18px; color: var(--muted); cursor: pointer; padding: 0; line-height: 1; }
        .modal-close:hover { color: var(--ink); }
        .modal-body { padding: 20px 28px; }
        .modal-foot { padding: 0 28px 24px; display: flex; gap: 10px; justify-content: flex-end; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; font-weight: 500; border-radius: 10px; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .15s; text-decoration: none; }
        .btn-ghost { background: transparent; color: var(--ink); border: 1px solid var(--border); }
        .btn-ghost:hover { border-color: var(--ink); color: var(--ink); }
        .btn-primary { background: var(--sand); color: var(--ink); }
        .btn-primary:hover { background: #c4b69e; }
        .btn-success { background: var(--ink); color: var(--bg); }
        .btn-success:hover { background: #1a4d3e; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .detail-item .dl { font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); font-weight: 500; margin-bottom: 4px; }
        .detail-item .dv { font-size: 13px; color: var(--ink); font-weight: 500; }
        .detail-item .dv.muted { color: var(--muted); font-weight: 400; }
        .detail-full { grid-column: 1 / -1; }
        .detail-full .msg-text { font-size: 13px; color: var(--body); line-height: 1.6; background: var(--sand); padding: 14px; border-radius: 10px; margin-top: 4px; border: 1px solid var(--border); }
        .artwork-preview { display: flex; gap: 16px; background: var(--bg); padding: 14px; border-radius: 12px; margin-top: 16px; border: 1px solid var(--border); }
        .artwork-preview img { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }
        .artwork-preview-info { flex: 1; }
        .shipping-box { margin-top: 16px; background: var(--bg); padding: 16px; border-radius: 12px; border: 1px solid var(--border); }
        .shipping-box h5 { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: var(--ink); margin-bottom: 12px; }
        .shipping-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .shipping-item .sl { font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; }
        .shipping-item .sv { font-size: 13px; font-weight: 500; color: var(--ink); }
        .forward-form { display: flex; flex-direction: column; gap: 12px; background: var(--bg); border: 1px solid var(--border); padding: 16px; border-radius: 12px; margin-top: 16px; }
        .forward-form select { width: 100%; padding: 8px 14px; border: 1.5px solid var(--sand); border-radius: 9px; font-size: 12px; font-family: 'DM Sans', sans-serif; color: var(--ink); background: var(--card); outline: none; }
        .notes-area { width: 100%; padding: 10px 14px; border: 1.5px solid var(--sand); border-radius: 9px; font-size: 12.5px; font-family: 'DM Sans', sans-serif; color: var(--ink); outline: none; resize: vertical; min-height: 60px; transition: border-color .15s; background: var(--bg); }
        .notes-area:focus { border-color: var(--ink); }
        
        .screenshot-box { margin-top: 16px; padding: 12px; background: var(--bg); border: 1px dashed var(--border); border-radius: 8px; display: flex; align-items: center; gap: 12px; }
        .screenshot-thumb { width: 60px; height: 60px; border-radius: 6px; object-fit: cover; border: 1px solid var(--border); background: var(--sand); }
        .screenshot-info { flex: 1; }
        .screenshot-info div { font-size: 12px; color: var(--body); margin-bottom: 2px; }
        .screenshot-info strong { font-size: 12px; color: var(--ink); }
        .mark-paid-btn { background: var(--ink); color: var(--bg); border: none; padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: 500; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .15s; }
        .mark-paid-btn:hover { background: #1a4d3e; }
        
        /* ── Footer ──────────────────────────────────────────── */
        .dash-footer { padding: 20px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--bg); margin-top: 12px; background: var(--ink); }

        /* ── Drawer & Responsive ───────────────────────────────── */
        #nav-drawer{display:none;}
        #nav-overlay{display:none;}
        .ham-btn{display:none;}
        
        @media(max-width:1080px){
            .content { padding: 24px; }
        }

        @media(max-width:768px){
            :root { --sidebar: 0px; }
            .sidebar { display: none; }
            .topbar { left: 0; }
            .content { padding: 16px; }
            
            .ham-btn{display:inline-block;width:30px;height:24px;position:relative;background:none;border:none;cursor:pointer;z-index:2000;}
            .ham-btn span{position:absolute;display:block;width:100%;height:2px;background:var(--bg);border-radius:2px;transition:all .3s;opacity:1;left:0;}
            .ham-btn span:nth-child(1){top:2px;}
            .ham-btn span:nth-child(2){top:10px;}
            .ham-btn span:nth-child(3){top:18px;}
            
            .open #nav-drawer{display:block;position:fixed;top:0;right:0;width:80%;height:100%;background:var(--ink);z-index:1001;padding:40px 20px;box-shadow:-5px 0 15px rgba(0,0,0,0.1);transition:right 0.3s ease;}
            .open #nav-overlay{display:block;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;}
            
            #nav-drawer a { display: block; padding: 15px 0; color: var(--bg); font-size: 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }

            .filters input { width: 100%; }
            .filters { flex-direction: column; align-items: stretch; }
            .tabs { overflow-x: auto; flex-wrap: nowrap; white-space: nowrap; }
            
            /* Table to Cards */
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
            td { padding: 8px 0; border: none; display: flex; justify-content: space-between; align-items: center; }
            td:before { content: attr(data-label); font-weight: 600; font-size: 11px; text-transform: uppercase; color: var(--muted); flex: 1; }
            .hide-mobile { display: none !important; }
            .td-actions { flex-direction: column; width: 100%; margin-top: 10px; gap: 8px; }
            .td-actions button, .td-actions form { width: 100%; }
            .td-actions .act-btn { width: 100%; justify-content: center; }
            .td-actions .status-select { width: 100%; }
        }
    </style>
</head>
<body>
    <!-- ══════════════ SIDEBAR ══════════════ -->
    <aside class="sidebar">
        <div class="sidebar-brand"><div class="logo-tag">Art Bazaar</div><div class="logo-name">Dashboard</div><span class="logo-badge">Admin</span></div>
        <div class="sidebar-section">Overview</div>
        <a href="index.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Overview</a>
        <div class="sidebar-section">Content</div>
        <a href="artworks.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="14.5" r="1.5"/></svg> Artworks</a>
        <a href="artists.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Artists</a>
        <a href="blogs.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
    Blog Posts
</a>
        <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg> Categories</a>
        <div class="sidebar-section">Requests</div>
        <a href="inquiries.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Orders & Inquiries<?php if (($statusCounts['pending'] ?? 0) > 0): ?><span class="badge"><?= $statusCounts['pending'] ?></span><?php endif; ?></a>
        <a href="commissions.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Commissions</a>
        <a href="messages.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg> Messages</a>
        <div class="sidebar-bottom"><a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out</a></div>
    </aside>

    <header class="topbar">
        <div class="topbar-left"><h1>Orders & Inquiries</h1><div class="sub">Manage purchase inquiries and checkout orders</div></div>
        <div class="topbar-right"><div class="admin-chip"><div class="avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div><span class="name"><?= htmlspecialchars($adminName) ?></span><span class="arrow">∨</span></div></div>
    </header>

    <main class="main">
        <div class="content">
            <?php if ($toast): ?><div class="toast"><span><?= htmlspecialchars($toast) ?></span><button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button></div><?php endif; ?>

            <!-- Status tabs -->
            <div class="tabs">
                <a href="?<?= buildQS(['status' => null]) ?>" class="tab <?= !$statusFilter ? 'active' : '' ?>">All <span class="count"><?= $statusCounts['all'] ?></span></a>
                <?php 
$tabLabels = [
    'pending'           => 'Pending',
    'payment_confirmed' => 'Payment Confirmed',
    'cod'               => 'COD — In Progress',
    'processing'        => 'Processing',
    'shipped'           => 'Shipped',
    'delivered'         => 'Delivered',
    'cancelled'         => 'Cancelled',
];
foreach ($tabLabels as $s => $label): ?>
<a href="?<?= buildQS(['status' => $s]) ?>" class="tab <?= $statusFilter === $s ? 'active' : '' ?>"><?= $label ?> <span class="count <?= ($s === 'pending' && ($statusCounts[$s] ?? 0) > 0) ? 'hot' : '' ?>"><?= $statusCounts[$s] ?? 0 ?></span></a>
<?php endforeach; ?>
            </div>

            <div class="filters">
                <input type="text" placeholder="Search buyer, email, phone, artwork, order #..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
                <select id="sortSelect">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Buyer name A–Z</option>
                    <option value="amount_high" <?= $sort === 'amount_high' ? 'selected' : '' ?>>Amount: High → Low</option>
                    <option value="amount_low" <?= $sort === 'amount_low' ? 'selected' : '' ?>>Amount: Low → High</option>
                </select>
                <?php if ($statusFilter || $search): ?><button class="clear-link" onclick="window.location.href='inquiries.php'">Clear all</button><?php endif; ?>
            </div>

            <div class="results-info"><div>Showing <?= count($items) ?> of <?= $totalResults ?> results</div><div>Page <?= $page ?> of <?= $totalPages ?></div></div>

            <div class="card">
                <?php if (empty($items)): ?>
                <div class="empty">No results found matching your filters.</div>
                <?php else: ?>
                <table>
                    <thead><tr>
                        <th>Type</th>
                        <th>Buyer</th>
                        <th>Artwork / Order</th>
                        <th class="hide-mobile">Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): 
                        $imageUrl = getArtworkImageUrl($item['artwork_image'] ?? '');
                    ?>
                    <tr>
                        <td data-label="">
                            <span class="source-pill order" style="font-size:9px;background:var(--sand);color:var(--ink);padding:2px 6px;border-radius:4px;">Order</span>
                        </td>
                        <td data-label="Buyer">
                            <div class="td-buyer"><?= htmlspecialchars($item['buyer_name']) ?></div>
                            <div class="td-buyer-sub">
                                <?php if ($item['buyer_email']) echo htmlspecialchars($item['buyer_email']); ?>
                                <?php if ($item['buyer_email'] && $item['buyer_phone']) echo ' · '; ?>
                                <?php if ($item['buyer_phone']) echo htmlspecialchars($item['buyer_phone']); ?>
                            </div>
                        </td>
                        <td data-label="Artwork / Order">
                            <div class="td-artwork">
                                <?php if ($imageUrl): ?>
                                    <img class="td-artwork-img" src="<?= htmlspecialchars($imageUrl) ?>" alt="">
                                <?php else: ?>
                                    <div class="td-artwork-img-placeholder">No img</div>
                                <?php endif; ?>
                                <div>
                                    <div class="td-artwork-title">
                                        #<?= htmlspecialchars($item['order_number'] ?: $item['id']) ?> — <?= htmlspecialchars($item['artwork_title']) ?>
                                    </div>
                                    <div class="td-artwork-sub">
                                        <?php if ($item['artist_name']): ?>
                                            by <?= htmlspecialchars($item['artist_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="td-price hide-mobile" data-label="Amount">
                            PKR <?= number_format($item['total_price']) ?>
                        </td>
                        <td data-label="Status">
    <?php 
    $pillClass = $item['item_status'];
$pillLabel = ucfirst($item['item_status']);
if ($item['item_status'] === 'pending' && $item['payment_method'] === 'cod') {
    $pillClass = 'cod-pending';
    $pillLabel = 'Awaiting Forward (COD)';
} elseif ($item['item_status'] === 'cod') {
    $pillClass = 'cod-pending';
    $pillLabel = 'COD — In Progress';
}
    ?>
    <span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span>
</td>
                        <td data-label="Actions">
                            <div class="td-actions">
                                <button type="button" class="act-btn blue" onclick="openDetail(<?= $item['id'] ?>)">View</button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this order?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="act-btn red">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a href="?<?= buildQS(['page' => $page - 1]) ?>" class="page-btn">← Prev</a><?php else: ?><span class="page-btn disabled">← Prev</span><?php endif; ?>
                <?php 
                $start = max(1, $page - 2); 
                $end = min($totalPages, $page + 2); 
                if ($start > 1) { echo '<a href="?' . buildQS(['page' => 1]) . '" class="page-btn">1</a>'; if ($start > 2) echo '<span class="page-btn disabled">...</span>'; } 
                for ($i = $start; $i <= $end; $i++) echo '<a href="?' . buildQS(['page' => $i]) . '" class="page-btn ' . ($i === $page ? 'active' : '') . '">' . $i . '</a>'; 
                if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span class="page-btn disabled">...</span>'; echo '<a href="?' . buildQS(['page' => $totalPages]) . '" class="page-btn">' . $totalPages . '</a>'; } 
                ?>
                <?php if ($page < $totalPages): ?><a href="?<?= buildQS(['page' => $page + 1]) ?>" class="page-btn">Next →</a><?php else: ?><span class="page-btn disabled">Next →</span><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
    </main>

    <!-- Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal">
            <div class="modal-head"><h3 id="modalTitle">Order Details</h3><button class="modal-close" onclick="closeDetail()">&times;</button></div>
            <div class="modal-body" id="detailContent"></div>
            <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="closeDetail()">Close</button></div>
        </div>
    </div>

    <!-- MOBILE DRAWER & OVERLAY -->
    <div id="nav-drawer">
        <div style="margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 20px;">
            <h2 style="color:var(--bg); font-family:'Playfair Display',serif;">Menu</h2>
        </div>
        <a href="index.php">Overview</a>
        <a href="artworks.php">Artworks</a>
        <a href="artists.php">Artists</a>
        <a href="blogs.php">Blog Posts</a> 
        <a href="categories.php">Categories</a>
        <a href="inquiries.php">Orders & Inquiries</a>
        <a href="commissions.php">Commissions</a>
        <a href="messages.php">Messages</a>
        <div style="margin-top: 40px;">
            <a href="../../logout.php" style="display:inline-block; padding: 10px 20px; background:var(--sand); color:var(--ink); border-radius:30px; font-weight:600;">Sign Out</a>
        </div>
    </div>
    <div id="nav-overlay"></div>

    <script>
        const itemData = <?= json_encode($items, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function openDetail(id) {
            const item = itemData.find(i => i.id == id);
            if (!item) return;
            
            document.getElementById('modalTitle').textContent = 'Order Details';
            
            const statusClass = item.item_status;

            let html = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                    <span class="source-pill order" style="font-size:9px;background:var(--sand);color:var(--ink);padding:2px 6px;border-radius:4px;">Order</span>
                    <span class="pill ${statusClass}">${ucf(item.item_status)}</span>
                    <span style="font-size:11px;color:var(--muted);margin-left:auto;">Order #${item.order_number || item.id}</span>
                </div>
                
                <div class="detail-grid">
                    <div class="detail-item"><div class="dl">Buyer Name</div><div class="dv">${esc(item.buyer_name)}</div></div>
                    <div class="detail-item"><div class="dl">Date</div><div class="dv">${esc(item.created_at)}</div></div>
                    <div class="detail-item"><div class="dl">Email</div><div class="dv ${!item.buyer_email ? 'muted' : ''}">${item.buyer_email ? esc(item.buyer_email) : 'Not provided'}</div></div>
                    <div class="detail-item"><div class="dl">Phone / WhatsApp</div><div class="dv ${!item.buyer_phone ? 'muted' : ''}">${item.buyer_phone ? esc(item.buyer_phone) : 'Not provided'}</div></div>
                    <div class="detail-item"><div class="dl">Payment Method</div><div class="dv ${!item.payment_method ? 'muted' : ''}">${item.payment_method ? esc(item.payment_method).replace(/_/g, ' ') : 'Not set'}</div></div>
                    <div class="detail-item"><div class="dl">Payment Status</div><div class="dv ${!item.payment_status ? 'muted' : ''}">${codStatusLabel(item)}</div></div>
                </div>
            `;

            // Message / Notes
            if (item.message) {
                html += `
                    <div class="detail-full" style="margin-top:16px;">
                        <div class="dl">Buyer Notes</div>
                        <div class="msg-text">${esc(item.message)}</div>
                    </div>
                `;
            }
            
            // Payment verification box — screenshot for wallet payments, info note for COD
            if (item.payment_method === 'cod') {
    html += `
        <div class="screenshot-box">
            <div class="screenshot-info">
                <strong>💵 Cash on Delivery</strong>
                <div>${item.payment_status === 'cod_collected' ? 'Cash collected on delivery.' : 'No screenshot needed — buyer will pay cash on delivery.'}</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;">
            ${item.item_status === 'pending' ? `
                <form method="POST" style="display:inline;" onsubmit="return confirm('Forward this COD order to the artist?')">
                    <input type="hidden" name="action" value="forward_to_artist">
                    <input type="hidden" name="id" value="${item.id}">
                    <input type="hidden" name="delivery_status" value="pending">
                    <button type="submit" class="mark-paid-btn">Forward to Artist</button>
                </form>
            ` : ''}
            ${item.item_status !== 'cancelled' && item.item_status !== 'delivered' ? `
                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this order and release artworks back to available?')">
                    <input type="hidden" name="action" value="cancel_order">
                    <input type="hidden" name="id" value="${item.id}">
                    <button type="submit" class="mark-paid-btn" style="background:#8B2020;">Cancel Order</button>
                </form>
            ` : ''}
            </div>
        </div>
    `;
            } else if (item.payment_screenshot) {
                html += `
                    <div class="screenshot-box">
                        <img src="../../${item.payment_screenshot}" class="screenshot-thumb" alt="Payment SS" style="cursor:pointer;" onclick="window.open('../../${item.payment_screenshot}', '_blank')">
                        <div class="screenshot-info">
                            <strong>Payment Screenshot</strong>
                            <div>${item.payment_method ? esc(item.payment_method).replace(/_/g, ' ').toUpperCase() : ''}</div>
                        </div>
                        ${item.payment_status !== 'paid' ? `
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this order as Paid?')">
                            <input type="hidden" name="action" value="mark_paid">
                            <input type="hidden" name="id" value="${item.id}">
                            <button type="submit" class="mark-paid-btn">Mark as Paid</button>
                        </form>
                        ` : ''}
            ${item.item_status !== 'cancelled' && item.item_status !== 'delivered' ? `
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this order and release artworks back to available?')">
                            <input type="hidden" name="action" value="cancel_order">
                            <input type="hidden" name="id" value="${item.id}">
                            <button type="submit" class="mark-paid-btn" style="background:#8B2020;">Cancel Order</button>
                        </form>
                        ` : ''}
                    </div>
                `;
            }

            // CHANGE 3: Updated artwork preview to loop through all items
            // Artwork previews — all items in this order
            if (item.artworks && item.artworks.length > 0) {
                html += `<div style="margin-top:16px;"><div class="dl" style="margin-bottom:8px;">Order Items (${item.artworks.length})</div>`;
                item.artworks.forEach(aw => {
                    const awImg = aw.image_path ? `../../uploads/artworks/${aw.image_path.replace(/^.*uploads\/artworks\//, '')}` : '';
                    html += `
                        <div class="artwork-preview" style="margin-bottom:10px;">
                            ${awImg
                                ? `<img src="${esc(awImg)}" alt="">`
                                : `<div style="width:100px;height:100px;background:var(--sand);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--ink);font-size:10px;flex-shrink:0;">No Image</div>`
                            }
                            <div class="artwork-preview-info">
                                <div class="dv">${esc(aw.title)}</div>
                                <div style="margin-top:6px;display:flex;gap:16px;flex-wrap:wrap;">
                                    <div><div class="dl">Price</div><div class="dv">PKR ${Number(aw.price).toLocaleString()}</div></div>
                                    <div><div class="dl">Artist</div><div class="dv">${esc(aw.artist_name)}</div></div>
                                </div>
                            </div>
                        </div>`;
                });
                html += `</div>`;
            } else {
                html += `
                    <div class="artwork-preview">
                        <div style="width:100px;height:100px;background:var(--sand);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--ink);font-size:10px;flex-shrink:0;">No Image</div>
                        <div class="artwork-preview-info">
                            <div class="dl">Order Item</div>
                            <div class="dv">Artwork Item(s)</div>
                            <div style="margin-top:8px;"><div class="dl">Total</div><div class="dv">PKR ${Number(item.total_price).toLocaleString()}</div></div>
                        </div>
                    </div>`;
            }

            // Shipping details
            if (item.shipping_address) {
                html += `
                    <div class="shipping-box">
                        <h5>📦 Shipping Details</h5>
                        <div class="shipping-grid">
                            <div class="shipping-item"><div class="sl">Address</div><div class="sv">${esc(item.shipping_address)}</div></div>
                            <div class="shipping-item"><div class="sl">City</div><div class="sv">${esc(item.shipping_city || 'N/A')}</div></div>
                            <div class="shipping-item"><div class="sl">Phone</div><div class="sv">${esc(item.shipping_phone || 'N/A')}</div></div>
                            <div class="shipping-item" style="grid-column: 1 / -1;">
                                <div class="sl">Order Number</div>
                                <div class="sv">#${esc(item.order_number || item.id)}</div>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Forward to Artist (Only if confirmed)
            if (item.item_status === 'payment_confirmed') {
                const currentDeliveryStatus = item.delivery_status || 'not_applicable';
                html += `
                    <form method="POST" class="forward-form">
                        <input type="hidden" name="action" value="forward_to_artist">
                        <input type="hidden" name="id" value="${item.id}">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div style="font-size:13px;font-weight:500;color:var(--ink);">Forward to Artist</div>
                            <button type="submit" class="btn btn-success" style="padding:7px 14px;font-size:11px;" onclick="return confirm('Forward order to artist to start work?')">Forward</button>
                        </div>
                        <div>
                            <div class="dl">Update Artwork Delivery Status</div>
                            <select name="delivery_status">
                                <option value="not_applicable" ${currentDeliveryStatus === 'not_applicable' ? 'selected' : ''}>Not Applicable</option>
                                <option value="pending" ${currentDeliveryStatus === 'pending' ? 'selected' : ''}>Pending</option>
                                <option value="processing" ${currentDeliveryStatus === 'processing' ? 'selected' : ''}>Processing</option>
                                <option value="shipped" ${currentDeliveryStatus === 'shipped' ? 'selected' : ''}>Shipped</option>
                                <option value="delivered" ${currentDeliveryStatus === 'delivered' ? 'selected' : ''}>Delivered</option>
                            </select>
                        </div>
                    </form>
                `;

                if (!item.smartlane_warehouse_code) {
                    html += `
                        <div class="screenshot-box" style="background:#FFF3E0;border:1px dashed #FFCC80;margin-top:12px;">
                            <div class="screenshot-info">
                                <strong>⚠ No Smartlane warehouse code</strong>
                                <div>Add a warehouse code for this artist on the Artists page before sending to courier.</div>
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <form method="POST" style="margin-top:12px;" onsubmit="return confirm('Send this order to Smartlane for courier booking?')">
                            <input type="hidden" name="action" value="send_to_courier">
                            <input type="hidden" name="id" value="${item.id}">
                            <button type="submit" class="mark-paid-btn" style="background:#1565C0;">🚚 Send to Courier</button>
                        </form>
                    `;
                }
            }

            if (item.item_status === 'processing') {
                html += item.tracking_number
                    ? `<div class="screenshot-box" style="background:#EEF2F8;border:1px solid #B3CDEF;margin-top:12px;"><div class="screenshot-info"><strong>📦 Tracking: ${esc(item.tracking_number)}</strong><div>${item.courier ? esc(item.courier) : ''}</div></div></div>`
                    : `<div class="screenshot-box" style="margin-top:12px;"><div class="screenshot-info"><strong>Sent to courier</strong><div>Waiting for Smartlane to confirm booking and return a tracking number.</div></div></div>`;
            }

            // Status update dropdown
            if (item.item_status !== 'delivered' && item.item_status !== 'cancelled') {
                html += `
                    <form method="POST" style="margin-top:16px;background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="${item.id}">
                        <div style="font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-bottom:8px;">Update Order Status</div>
                        <select name="new_status" style="width:100%;padding:9px 14px;border:1.5px solid var(--sand);border-radius:9px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--card);outline:none;margin-bottom:12px;">
                            <option value="pending" ${item.item_status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="payment_confirmed" ${item.item_status === 'payment_confirmed' ? 'selected' : ''}>Payment Confirmed</option>
                            <option value="processing" ${item.item_status === 'processing' ? 'selected' : ''}>Processing</option>
                            <option value="shipped" ${item.item_status === 'shipped' ? 'selected' : ''}>Shipped</option>
                            <option value="delivered" ${item.item_status === 'delivered' ? 'selected' : ''}>Delivered</option>
                            <option value="cancelled" ${item.item_status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                        <div style="text-align:right;">
                            <button type="submit" class="btn btn-success" style="padding:7px 14px;font-size:11px;" onclick="return confirm('Update order status?')">Update Status</button>
                        </div>
                    </form>
                `;
            }

            // Admin notes
            html += `
                <div class="detail-full" style="margin-top:18px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_notes">
                        <input type="hidden" name="id" value="${item.id}">
                        <div class="dl" style="margin-bottom:6px;">Admin Notes</div>
                        <textarea class="notes-area" name="admin_notes" placeholder="Add internal notes...">${esc(item.admin_notes || '')}</textarea>
                        <div style="margin-top:10px;text-align:right;">
                            <button type="submit" class="btn btn-primary" style="padding:7px 14px;font-size:11px;">Save Notes</button>
                        </div>
                    </form>
                </div>
            `;

            document.getElementById('detailContent').innerHTML = html;
            document.getElementById('detailModal').classList.add('open');
        }

        function closeDetail() { document.getElementById('detailModal').classList.remove('open'); }
        document.getElementById('detailModal').addEventListener('click', function(e) { if (e.target === this) closeDetail(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeDetail(); });

        function esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
        function ucf(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }
        function codStatusLabel(item) {
            if (item.payment_status === 'cod_pending') return 'Cash on Delivery — awaiting delivery';
            if (item.payment_status === 'cod_collected') return 'Cash Collected on Delivery';
            return item.payment_status ? esc(item.payment_status) : 'Pending';
        }

        let searchTimer;
        document.getElementById('searchInput').addEventListener('keyup', function() { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400); });
        document.getElementById('sortSelect').addEventListener('change', applyFilters);

        function applyFilters() {
            let params = new URLSearchParams(window.location.search);
            let q = document.getElementById('searchInput').value.trim();
            let sort = document.getElementById('sortSelect').value;
            if (q) params.set('q', q); else params.delete('q');
            if (sort) params.set('sort', sort); else params.delete('sort');
            params.delete('page');
            window.location.href = 'inquiries.php?' + params.toString();
        }

        // ── Drawer Logic ───────────────────────────────────────
        const drawer = document.querySelector('body');
        const overlay = document.getElementById('nav-overlay');

        // Inject Hamburger if not present (Mobile only)
        if(window.innerWidth <= 768 && !document.querySelector('.ham-btn')){
            const topbarRight = document.querySelector('.topbar-right');
            if(topbarRight){
                const btn = document.createElement('button');
                btn.className = 'ham-btn';
                btn.innerHTML = '<span></span><span></span><span></span>';
                topbarRight.insertBefore(btn, topbarRight.firstChild);
                
                btn.addEventListener('click', () => {
                    drawer.classList.toggle('open');
                });
            }
        }

        overlay.addEventListener('click', () => {
            drawer.classList.remove('open');
        });
    </script>
</body>
</html>