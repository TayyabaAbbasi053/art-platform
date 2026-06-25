<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/smartlane.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../../vendor/autoload.php';
function sendArtistCommissionEmail(mysqli $conn, int $orderId, string $type = 'payment_confirmed'): bool {
    $stmt = $conn->prepare("
        SELECT u.email AS artist_email, u.name AS artist_name,
               o.order_number, o.commission_description
        FROM orders o
        JOIN commission_requests cr ON cr.order_id = o.id
        JOIN users u ON cr.artist_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || empty($row['artist_email'])) return false;

    $desc = $row['commission_description'] ? mb_substr($row['commission_description'], 0, 120) : 'a custom artwork';

    if ($type === 'assigned') {
        $subject = "Art Bazaar — New Commission Assigned: #{$row['order_number']}";
        $heading = 'New Commission Assigned';
        $intro   = "Hi {$row['artist_name']}, you've been assigned a new commission request.";
        $altBody = "New commission assigned to you — #{$row['order_number']}. {$desc}. Log in to your dashboard to view details.";
    } else {
        $subject = "Art Bazaar — Payment Confirmed: Commission #{$row['order_number']}";
        $heading = 'Payment Confirmed';
        $intro   = "Hi {$row['artist_name']}, the buyer's payment has been confirmed. You're clear to begin work.";
        $altBody = "Payment confirmed for commission #{$row['order_number']}. {$desc}. Log in to your dashboard to begin work.";
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['BREVO_SMTP_USERNAME'];
        $mail->Password   = $_ENV['BREVO_SMTP_PASSWORD'];
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addReplyTo('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addAddress($row['artist_email'], $row['artist_name']);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $mail->Subject = $subject;
        $mail->AltBody = $altBody;
        $mail->Body    = "
        <div style='font-family:sans-serif;max-width:420px;margin:auto;padding:32px;background:#fff;border-radius:12px'>
            <p style='font-size:13px;color:#555;margin:0 0 8px'>Art Bazaar</p>
            <h2 style='font-size:24px;font-weight:400;color:#0a0a0a;margin:0 0 20px;font-family:Georgia,serif'>{$heading}</h2>
            <p style='font-size:14px;color:#444;line-height:1.6;margin:0 0 16px'>{$intro}</p>
            <p style='font-size:13px;color:#666;margin:0 0 8px'><strong>Commission #{$row['order_number']}</strong></p>
            <p style='font-size:13px;color:#666;margin:0 0 24px'>{$desc}</p>
            <p style='font-size:12px;color:#aaa;margin:0'>Log in to your dashboard to view full details.</p>
        </div>";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
function sendBuyerCommissionEmail(mysqli $conn, int $orderId): bool {
    $stmt = $conn->prepare("
        SELECT o.order_number, o.commission_description,
               COALESCE(u.name, o.guest_name) AS buyer_name,
               COALESCE(u.email, o.guest_email) AS buyer_email,
               art.name AS artist_name
        FROM orders o
        LEFT JOIN users u ON o.buyer_id = u.id
        LEFT JOIN commission_requests cr ON cr.order_id = o.id
        LEFT JOIN users art ON cr.artist_id = art.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || empty($row['buyer_email'])) return false;

    $desc = $row['commission_description'] ? mb_substr($row['commission_description'], 0, 120) : 'your custom artwork';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['BREVO_SMTP_USERNAME'];
        $mail->Password   = $_ENV['BREVO_SMTP_PASSWORD'];
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addReplyTo('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addAddress($row['buyer_email'], $row['buyer_name']);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "Art Bazaar — Payment Confirmed: Commission #{$row['order_number']}";
        $mail->AltBody = "Hi {$row['buyer_name']}, your payment for commission #{$row['order_number']} has been confirmed. The artist has been notified and will begin work shortly.";
        $mail->Body    = "
        <div style='font-family:sans-serif;max-width:420px;margin:auto;padding:32px;background:#fff;border-radius:12px'>
            <p style='font-size:13px;color:#555;margin:0 0 8px'>Art Bazaar</p>
            <h2 style='font-size:24px;font-weight:400;color:#0a0a0a;margin:0 0 20px;font-family:Georgia,serif'>Payment Confirmed</h2>
            <p style='font-size:14px;color:#444;line-height:1.6;margin:0 0 16px'>Hi {$row['buyer_name']}, your payment has been confirmed for your commission request.</p>
            <p style='font-size:13px;color:#666;margin:0 0 8px'><strong>Commission #{$row['order_number']}</strong></p>
            <p style='font-size:13px;color:#666;margin:0 0 24px'>{$desc}</p>
            " . ($row['artist_name'] ? "<p style='font-size:13px;color:#666;margin:0 0 16px'>Your artist <strong>{$row['artist_name']}</strong> has been notified and will begin work shortly.</p>" : "") . "
            <p style='font-size:12px;color:#aaa;margin:0'>— Art Bazaar Team</p>
        </div>";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Art Bazaar buyer commission email failed: ' . $e->getMessage());
        return false;
    }
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

// ── JSON endpoint for fetching messages ─────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_messages') {
    header('Content-Type: application/json');
    $orderId = (int)($_GET['order_id'] ?? 0);
    
    if ($orderId > 0) {
        $stmt = $conn->prepare("
            SELECT om.*, 
                   COALESCE(u.name, o.guest_name) as sender_name_display
            FROM order_messages om
            JOIN orders o ON om.order_id = o.id
            LEFT JOIN users u ON om.sender_id = u.id
            WHERE om.order_id = ? 
            ORDER BY om.created_at ASC
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['messages' => $messages]);
    } else {
        echo json_encode(['messages' => []]);
    }
    exit;
}

// ── JSON endpoint for fetching artists with style filter ─────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_artists') {
    header('Content-Type: application/json');
    $styleFilter = $_GET['style'] ?? '';
    
    $sql = "
        SELECT u.id, u.name, u.profile_picture, 
               ap.city, ap.art_style, ap.accepts_commissions,
               (SELECT COUNT(*) FROM artworks WHERE artist_id = u.id AND status = 'approved') AS artwork_count
        FROM users u
        LEFT JOIN artist_profiles ap ON ap.user_id = u.id
        WHERE u.role = 'artist' AND u.status = 'active'
    ";
    
    if (!empty($styleFilter)) {
        $sql .= " AND ap.art_style LIKE '%" . $conn->real_escape_string($styleFilter) . "%'";
    }
    
    $sql .= " ORDER BY u.name ASC";
    
    $artistRes = $conn->query($sql);
    $artists = [];
    while ($row = $artistRes->fetch_assoc()) {
        $artists[] = $row;
    }
    
    $styleRes = $conn->query("SELECT DISTINCT art_style FROM artist_profiles WHERE art_style IS NOT NULL AND art_style != '' ORDER BY art_style ASC");
    $artStyles = [];
    while ($row = $styleRes->fetch_assoc()) {
        $artStyles[] = $row['art_style'];
    }
    
    echo json_encode(['artists' => $artists, 'artStyles' => $artStyles]);
    exit;
}

 $adminName = $_SESSION['name'] ?? 'Admin';
 $toast = '';

// ── Contact info filter function ─────────────────────────
function containsContactInfo(string $text): bool {
    $patterns = [
        '/\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i',
        '/(\+92[-\s]?[0-9]{3}[-\s]?[0-9]{7}|(?<!\d)0[0-9]{2,3}[-\s]?[0-9]{6,8})/',
        '/\b(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i',
        '/@[a-zA-Z0-9._]{2,30}/',
        '/\b(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)\b/i',
        '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

// ── Valid enums matching new DB schema ──────────────────
 $validStatuses = ['pending','price_proposed','assigned','payment_review','payment_confirmed','processing','shipped','delivered','cancelled'];
 $validDeliveryStatuses = ['pending','quote_pending','deposit_paid','in_progress','ready_to_ship','shipped','delivered','cancelled'];
 $validPaymentMethods = ['cod','bank_transfer','easypaisa','jazzcash'];
 $validPaymentStatuses = ['pending','paid','failed','refunded'];

// ── Handle actions ──────────────────────────────────────

// Update Status (Updates Orders Table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $id = (int)($_POST['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    if ($id && in_array($newStatus, $validStatuses)) {
        $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $id);
        $stmt->execute();
        
        $adminId = (int)$_SESSION['user_id'];
        $oldRes = $conn->query("SELECT order_status FROM orders WHERE id=$id");
        $oldStatus = $oldRes->fetch_assoc()['order_status'] ?? '';
        $note = "Status updated to " . ucfirst($newStatus);
        $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, ?, ?, 'admin', ?, ?)");
        $stmtH->bind_param('issis', $id, $oldStatus, $newStatus, $adminId, $note);
        $stmtH->execute();

        $toast = 'Commission status updated to ' . str_replace('_', ' ', ucfirst($newStatus)) . '.';
    }
}

// Update Maximum Budget (Updates Orders Table) - No Save Button, handled via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_agreed_price') {
    $id = (int)($_POST['id'] ?? 0);
    $agreedPrice = $_POST['agreed_price'] ?? '';
    if ($id) {
        if ($agreedPrice === '' || $agreedPrice === null) {
            $stmt = $conn->prepare("UPDATE orders SET total = NULL WHERE id = ?");
            $stmt->bind_param('i', $id);
        } else {
            $agreedPrice = floatval($agreedPrice);
            $stmt = $conn->prepare("UPDATE orders SET total = ? WHERE id = ?");
            $stmt->bind_param('di', $agreedPrice, $id);
        }
        $stmt->execute();
        $toast = 'Maximum budget updated.';
    }
}

// Update Budgets (Auto-save via background post)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_budgets') {
    $id = (int)($_POST['id'] ?? 0);
    $budgetMin = $_POST['budget_min'] ?? '';
    $budgetMax = $_POST['budget_max'] ?? '';
    
    if ($id) {
        $minVal = ($budgetMin !== '' && $budgetMin !== null) ? floatval($budgetMin) : NULL;
        $maxVal = ($budgetMax !== '' && $budgetMax !== null) ? floatval($budgetMax) : NULL;
        
        $stmt = $conn->prepare("UPDATE orders SET budget_min = ?, budget_max = ? WHERE id = ?");
        $stmt->bind_param('ddi', $minVal, $maxVal, $id);
        $stmt->execute();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'toast' => 'Budget updated.']);
        exit;
    }
}

// Update delivery status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_delivery_status') {
    $id = (int)($_POST['id'] ?? 0);
    $deliveryStatus = $_POST['delivery_status'] ?? '';
    $map = [
        'quote_pending' => 'pending',
        'in_progress' => 'processing',
        'ready_to_ship' => 'processing',
        'shipped' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
        'deposit_paid' => 'assigned'
    ];
    $targetStatus = $map[$deliveryStatus] ?? $deliveryStatus;
    
    if ($id && in_array($targetStatus, $validStatuses)) {
        $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $stmt->bind_param('si', $targetStatus, $id);
        $stmt->execute();
        $toast = 'Status updated to ' . str_replace('_', ' ', ucfirst($deliveryStatus)) . '.';
    }
}

// Assign Artist (Updates commission_requests Table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_artist') {
    $id = (int)($_POST['id'] ?? 0);
    $artistId = (int)($_POST['artist_id'] ?? 0);
    
    if ($id) {
        $check = $conn->query("SELECT id FROM commission_requests WHERE order_id = $id");
        if ($check->num_rows > 0) {
            if ($artistId > 0) {
                $conn->query("UPDATE commission_requests SET artist_id = $artistId WHERE order_id = $id");
                $conn->query("UPDATE orders SET order_status = 'assigned' WHERE id = $id AND order_status = 'pending'");
                if ($conn->affected_rows > 0) {
                    sendArtistCommissionEmail($conn, $id, 'assigned');
                }
                $adminId = (int)$_SESSION['user_id'];
                $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'pending', 'assigned', 'admin', ?, 'Artist assigned')");
                $stmtH->bind_param('ii', $id, $adminId);
                $stmtH->execute();
                $toast = 'Artist assigned successfully.';
            } else {
                $conn->query("UPDATE commission_requests SET artist_id = NULL WHERE order_id = $id");
                $conn->query("UPDATE orders SET order_status = 'pending' WHERE id = $id AND order_status = 'assigned'");
                $toast = 'Artist unassigned.';
            }
        } else {
            if ($artistId > 0) {
                $conn->query("INSERT INTO commission_requests (order_id, artist_id) VALUES ($id, $artistId)");
                $conn->query("UPDATE orders SET order_status = 'assigned' WHERE id = $id AND order_status = 'pending'");
                if ($conn->affected_rows > 0) {
                    sendArtistCommissionEmail($conn, $id, 'assigned');
                }
                $adminId = (int)$_SESSION['user_id'];
                $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'pending', 'assigned', 'admin', ?, 'Artist assigned')");
                $stmtH->bind_param('ii', $id, $adminId);
                $stmtH->execute();
                $toast = 'Artist assigned successfully.';
            }
        }
    }
}

// Forward to Artist (Assign + Confirm)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forward_to_artist') {
    $id = (int)($_POST['id'] ?? 0);
    $artistId = (int)($_POST['artist_id'] ?? 0);
    
    if ($id && $artistId) {
        $check = $conn->query("SELECT id FROM commission_requests WHERE order_id = $id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE commission_requests SET artist_id = $artistId WHERE order_id = $id");
        } else {
            $conn->query("INSERT INTO commission_requests (order_id, artist_id) VALUES ($id, $artistId)");
        }
        
        $conn->query("UPDATE orders SET order_status = 'assigned' WHERE id = $id");
        
        $adminId = (int)$_SESSION['user_id'];
        $stmtH = $conn->prepare("INSERT INTO order_status_history ... VALUES (?, 'pending', 'assigned', 'admin', ?, 'Forwarded to artist')");
        $stmtH->bind_param('ii', $id, $adminId);
        $stmtH->execute();

        $artistNameRow = $conn->query("SELECT name FROM users WHERE id = $artistId")->fetch_assoc();
        $toast = 'Commission forwarded to ' . htmlspecialchars($artistNameRow['name'] ?? 'artist') . ' and confirmed.';
    } else {
        $toast = 'No artist selected to forward to.';
    }
}

// Save admin notes (Updates Orders Table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes') {
    $id = (int)($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');
    if ($id) {
        $stmt = $conn->prepare("UPDATE orders SET admin_notes = ? WHERE id = ?");
        $stmt->bind_param('si', $notes, $id);
        $stmt->execute();
        $toast = 'Notes saved.';
    }
}

// Mark as Paid (Updates Orders Table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', order_status = 'payment_confirmed' WHERE id = ? AND order_status = 'payment_review'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            sendArtistCommissionEmail($conn, $id);
            sendBuyerCommissionEmail($conn, $id);
        }
        $adminId = (int)$_SESSION['user_id'];
        $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'payment_review', 'payment_confirmed', 'admin', ?, 'Admin verified payment. Artist notified to begin work.')");
        $stmtH->bind_param('ii', $id, $adminId);
        $stmtH->execute();
        $toast = 'Payment confirmed. Artist has been notified to begin work.';
    }
}

// Send chat message (Inserts into order_messages)
// Approve Payment — moves commission from payment_review → processing, notifies artist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_payment') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $conn->query("UPDATE orders SET order_status = 'payment_confirmed', payment_status = 'paid' WHERE id = $id AND order_status = 'payment_review'");
        if ($conn->affected_rows > 0) {
            sendArtistCommissionEmail($conn, $id);
            sendBuyerCommissionEmail($conn, $id);
        }
        $adminId = (int)$_SESSION['user_id'];
        $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'payment_review', 'payment_confirmed', 'admin', ?, 'Admin verified payment. Artist should now begin work.')");
        $stmtH->bind_param('ii', $id, $adminId);
        $stmtH->execute();
        $toast = 'Payment confirmed. Artist has been notified to begin work.';
    }
}

// Send to Courier (Books a Smartlane consignment for a payment_confirmed commission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_courier') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $orderRow = $conn->query("
            SELECT o.id, o.order_number, o.order_status, o.total, o.proposed_weight_kg,
                   o.shipping_address, o.shipping_city, o.shipping_phone,
                   o.guest_name, o.guest_email, o.guest_phone,
                   o.commission_description,
                   u.name AS buyer_name_real, u.email AS buyer_email_real, u.phone AS buyer_phone_real,
                   ap.smartlane_warehouse_code
            FROM orders o
            LEFT JOIN users u ON o.buyer_id = u.id
            LEFT JOIN commission_requests cr ON cr.order_id = o.id
            LEFT JOIN artist_profiles ap ON ap.user_id = cr.artist_id
            WHERE o.id = $id
            LIMIT 1
        ")->fetch_assoc();

        if (!$orderRow) {
            $toast = 'Order not found.';
        } elseif ($orderRow['order_status'] !== 'ready_to_ship') {
            $toast = 'This commission must be marked Ready to Ship by the artist before sending to courier.';
        } elseif (empty($orderRow['smartlane_warehouse_code'])) {
            $toast = 'This artist has no Smartlane warehouse code set. Add it on the Artists page first.';
        } else {
            $buyerName  = $orderRow['buyer_name_real']  ?: $orderRow['guest_name'];
            $buyerEmail = $orderRow['buyer_email_real'] ?: $orderRow['guest_email'];
            $buyerPhone = $orderRow['buyer_phone_real'] ?: ($orderRow['guest_phone'] ?: $orderRow['shipping_phone']);

            $result = smartlane_create_consignment([
                'warehouse_code'    => $orderRow['smartlane_warehouse_code'],
                'store_order_id'    => $orderRow['id'],
                'consignee_name'    => $buyerName ?: 'Customer',
                'consignee_email'   => $buyerEmail ?: '',
                'consignee_phone'   => $buyerPhone ?: '',
                'consignee_address' => $orderRow['shipping_address'] ?: 'Address not provided',
                'consignee_city'    => $orderRow['shipping_city'] ?: 'Unknown',
                'description'       => $orderRow['commission_description'] ? mb_substr($orderRow['commission_description'], 0, 150) : 'Custom artwork commission',
                'payment_method'    => 'bank_transfer',
                'amount'            => $orderRow['total'] ?? 0,
                'product_count'     => 1,
                'weight'            => (float)($orderRow['proposed_weight_kg'] ?? 0.5),
                'products'          => [[
                    'sku'  => 'commission-' . $orderRow['id'],
                    'name' => 'Commission #' . $orderRow['order_number'],
                    'qty'  => '1',
                ]],
            ]);

            if ($result['ok']) {
                $conn->query("UPDATE orders SET order_status = 'processing' WHERE id = $id");
                $adminId = (int)$_SESSION['user_id'];
                $note = smartlane_test_mode()
                    ? 'Sent to courier (TEST MODE — no real booking made).'
                    : 'Sent to Smartlane courier for booking.';
                $stmtH = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'ready_to_ship', 'processing', 'admin', ?, ?)");
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

// Send chat message (Inserts into order_messages)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $orderId = (int)($_POST['commission_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if ($orderId && $message) {
        if (containsContactInfo($message)) {
            $toast = 'Message blocked: Contact information cannot be shared.';
        } else {
            $adminId = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message) VALUES (?, 'admin', ?, ?, ?)");
            $stmt->bind_param('iiss', $orderId, $adminId, $adminName, $message);
            $stmt->execute();
            $toast = 'Message sent.';
        }
    }
}

// Delete chat message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_message') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    if ($messageId) {
        $conn->query("DELETE FROM order_messages WHERE id = $messageId");
        $toast = 'Message deleted.';
    }
}

// Delete commission (Deletes Order)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $row = $conn->query("SELECT commission_reference_image FROM orders WHERE id = $id")->fetch_assoc();
        if ($row && $row['commission_reference_image']) {
            $f = __DIR__ . '/../../uploads/commissions/' . $row['commission_reference_image'];
            if (file_exists($f)) unlink($f);
        }
        
        $conn->query("DELETE FROM commission_requests WHERE order_id = $id");
        $conn->query("DELETE FROM orders WHERE id = $id");
        $toast = 'Commission request deleted.';
    }
}

// ── Build query ─────────────────────────────────────────
 $where   = ["o.order_type = 'commission'"];
 $params  = [];
 $types   = '';

 $statusFilter = $_GET['status'] ?? '';
if (in_array($statusFilter, $validStatuses)) {
    $where[] = "o.order_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

 $search = trim($_GET['q'] ?? '');
if ($search) {
    $where[] = "(o.guest_name LIKE ? OR o.guest_email LIKE ? OR o.guest_phone LIKE ? OR o.shipping_phone LIKE ? OR o.commission_description LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    $types .= 'sssssss';
}

 $artistFilter = $_GET['artist'] ?? '';
if ($artistFilter === 'unassigned') {
    $where[] = "cr.artist_id IS NULL";
} elseif ((int)$artistFilter > 0) {
    $where[] = "cr.artist_id = ?";
    $params[] = (int)$artistFilter;
    $types .= 'i';
}

 $whereSQL = implode(' AND ', $where);

 $sortMap = [
    'newest'  => 'o.created_at DESC',
    'oldest'  => 'o.created_at ASC',
    'name'    => 'COALESCE(u.name, o.guest_name) ASC',
    'deadline'=> 'o.commission_deadline ASC',
];
 $sortBy = $sortMap[$_GET['sort'] ?? ''] ?? 'o.created_at DESC';

 $page    = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 15;
 $offset  = ($page - 1) * $perPage;

 $countSQL = "SELECT COUNT(*) FROM orders o 
              LEFT JOIN users u ON o.buyer_id = u.id
              LEFT JOIN commission_requests cr ON cr.order_id = o.id
              WHERE $whereSQL";
if ($params) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResults = (int)$stmt->get_result()->fetch_row()[0];
} else {
    $totalResults = (int)$conn->query($countSQL)->fetch_row()[0];
}
 $totalPages = max(1, ceil($totalResults / $perPage));

 $dataSQL = "
    SELECT 
        o.id, 
        o.order_number,
        o.buyer_id,
        o.guest_name, o.guest_email, o.guest_phone,
        o.commission_category_id,
        o.budget_min, o.budget_max, o.commission_deadline, o.commission_description,
        o.commission_reference_image,
        o.order_status AS status, 
        o.admin_notes, 
        o.created_at,
        o.total AS agreed_price,
        o.tracking_number,
        o.courier,
        o.shipping_address, o.shipping_city, o.shipping_phone,
        o.payment_method, o.payment_status,
        o.payment_screenshot,
        u.name AS buyer_name_real,
        u.email AS buyer_email_real,
        u.phone AS buyer_phone_real,
        cr.artist_id,
        art.name AS artist_name,
        ap.smartlane_warehouse_code,
        c.name AS category_name
    FROM orders o
    LEFT JOIN users u ON o.buyer_id = u.id
    LEFT JOIN commission_requests cr ON cr.order_id = o.id
    LEFT JOIN users art ON cr.artist_id = art.id
    LEFT JOIN artist_profiles ap ON ap.user_id = cr.artist_id
    LEFT JOIN categories c ON o.commission_category_id = c.id
    WHERE $whereSQL
    ORDER BY $sortBy
    LIMIT $perPage OFFSET $offset
";
 $commissions = [];
if ($params) {
    $stmt = $conn->prepare($dataSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($dataSQL);
}
while ($row = $res->fetch_assoc()) {
    $row['buyer_name'] = $row['buyer_name_real'] ?: $row['guest_name'];
    $row['buyer_email'] = $row['buyer_email_real'] ?: $row['guest_email'];
    $row['buyer_phone'] = $row['buyer_phone_real'] ?: ($row['guest_phone'] ?: $row['shipping_phone']);
    $row['artwork_type'] = $row['category_name'] ?: 'Custom';
    $commissions[] = $row;
}

 $statusCounts = [];
foreach ($validStatuses as $s) {
    $r = $conn->query("SELECT COUNT(*) FROM orders WHERE order_type='commission' AND order_status='$s'");
    $statusCounts[$s] = (int)$r->fetch_row()[0];
}
 $statusCounts['all'] = array_sum($statusCounts);

 $artistOptions = [];
 $aoRes = $conn->query("SELECT id, name FROM users WHERE role='artist' AND status='active' ORDER BY name ASC");
while ($row = $aoRes->fetch_assoc()) $artistOptions[] = $row;

function buildQS($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}

function formatBudget($min, $max) {
    if ($min && $max) return 'PKR ' . number_format($min) . ' - ' . number_format($max);
    if ($min) return 'PKR ' . number_format($min) . '+';
    if ($max) return 'Up to PKR ' . number_format($max);
    return '-';
}

function getProfileImageUrl($imagePath) {
    if (empty($imagePath)) return null;
    $imagePath = ltrim($imagePath, './');
    if (strpos($imagePath, 'uploads/') === 0) return '../../' . $imagePath;
    return '../../uploads/profiles/' . $imagePath;
}

function getDeliveryStatusFromOrderStatus($status) {
    $map = [
        'pending' => 'pending',
        'assigned' => 'deposit_paid',
        'payment_review' => 'deposit_paid',
        'payment_confirmed' => 'deposit_paid',
        'processing' => 'in_progress',
        'shipped' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled'
    ];
    return $map[$status] ?? 'pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commissions - Art Bazaar Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--black:#0C3F30;--grey1:#F6EDDE;--grey2:#DDCDAE;--grey3:#DDCDAE;--grey4:#0C3F30;--grey5:#0C3F30;--white:#F6EDDE;--red:#0C3F30;--green:#0C3F30;--amber:#0C3F30;--blue:#0C3F30;--purple:#0C3F30;--terracotta:#0C3F30;--gold:#DDCDAE;--sidebar:240px;--top:60px}
html,body{height:100%;background:var(--grey1);color:var(--black);font-family:'DM Sans',sans-serif}
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar);height:100vh;background:#0C3F30;border-right:1px solid var(--grey2);display:flex;flex-direction:column;z-index:100;overflow-y:auto}
.sidebar-brand{padding:22px 24px 18px;border-bottom:1px solid var(--grey2)}
.sidebar-brand .logo-tag{font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#DDCDAE}
.sidebar-brand .logo-name{font-family:'Playfair Display',serif;font-size:20px;color:#F6EDDE;margin-top:2px}
.sidebar-brand .logo-badge{display:inline-block;margin-top:6px;background:#DDCDAE;color:#0C3F30;font-size:8px;letter-spacing:2px;text-transform:uppercase;padding:2px 7px;border-radius:20px}
.sidebar-section{padding:18px 16px 6px;font-size:9px;letter-spacing:2.5px;text-transform:uppercase;color:#DDCDAE;font-weight:500}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 20px;font-size:12.5px;color:#F6EDDE;text-decoration:none;font-weight:400;border-left:2px solid transparent;transition:all .15s}
.nav-item:hover{color:#0C3F30;background:#DDCDAE;border-left-color:#DDCDAE}
.nav-item.active{color:#0C3F30;background:#DDCDAE;border-left-color:#0C3F30;font-weight:500}
.nav-item .icon{width:16px;height:16px;flex-shrink:0;opacity:.8;stroke:#F6EDDE}
.nav-item.active .icon,.nav-item:hover .icon{stroke:#0C3F30;opacity:1}
.badge{margin-left:auto;background:var(--terracotta);color:#fff;font-size:9px;font-weight:600;padding:1px 6px;border-radius:20px;min-width:18px;text-align:center}
.sidebar-bottom{margin-top:auto;padding:16px;border-top:1px solid var(--grey2)}
.signout-btn{display:flex;align-items:center;gap:8px;padding:9px 12px;font-size:12px;color:#F6EDDE;text-decoration:none;border-radius:8px;transition:all .15s;width:100%;background:none;border:none;cursor:pointer;font-family:'DM Sans',sans-serif}
.signout-btn:hover{background:#DDCDAE;color:#0C3F30}
.topbar{position:fixed;top:0;left:var(--sidebar);right:0;height:var(--top);background:#0C3F30;border-bottom:1px solid #0C3F30;display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:99}
.topbar-left h1{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:#F6EDDE}
.topbar-left .sub{font-size:11px;color:#DDCDAE;margin-top:1px;opacity:0.8}
.admin-chip{display:flex;align-items:center;gap:8px;background:#DDCDAE;border:1px solid #0C3F30;padding:5px 12px 5px 5px;border-radius:30px}
.admin-chip .avatar{width:26px;height:26px;border-radius:50%;background:#F6EDDE;display:flex;align-items:center;justify-content:center;font-size:11px;color:#0C3F30;font-weight:600}
.admin-chip .name{font-size:12px;color:#0C3F30;font-weight:500}
.admin-chip .arrow{font-size:12px;color:#0C3F30;margin-left:4px;opacity:0.6}
.main{margin-left:var(--sidebar);padding-top:var(--top);min-height:100vh}
.content{padding:28px 32px}
.toast{background:#FCEEE2;color:var(--black);border:1px solid var(--grey2);padding:12px 20px;border-radius:10px;font-size:12.5px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between}
.toast.hidden{display:none}
.toast-close{background:none;border:none;color:var(--grey4);cursor:pointer;font-size:16px}
.toast-close:hover{color:var(--black)}
.tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
.tab { display: flex; align-items: center; gap: 6px; padding: 9px 18px; font-size: 12px; color: var(--black); text-decoration: none; border-radius: 999px; border: 2px solid var(--black); transition: all .15s; font-weight: 500; background: var(--white); cursor: pointer; font-family: 'DM Sans', sans-serif; }
.tab:hover { background: var(--grey2); }
.tab.active { background: var(--black); color: var(--white); border-color: var(--black); font-weight: 600; }
.tab .count { font-size: 10px; font-weight: 700; background: rgba(12,63,48,0.1); padding: 2px 8px; border-radius: 999px; color: var(--black); }
.tab.active .count { background: rgba(246,237,222,0.25); color: var(--white); }
.tab .count.hot { background: var(--grey2); color: var(--black); }
.tab-label{white-space:nowrap}
.filters{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.filters input[type="text"], .filters select { padding: 10px 20px; border: 2px solid var(--black); border-radius: 999px; font-size: 13px; font-family: 'DM Sans', sans-serif; color: var(--black); background: var(--white); outline: none; transition: border-color .15s, box-shadow .15s; font-weight: 500; }
.filters input:focus, .filters select:focus { border-color: var(--black); box-shadow: 0 0 0 3px rgba(12,63,48,0.12); }
.filters input { width: 280px; }
.filters select { min-width: 180px; cursor: pointer; }
.clear-link{font-size:11px;color:var(--grey4);text-decoration:none;cursor:pointer;background:none;border:none;font-family:'DM Sans',sans-serif}
.clear-link:hover{color:var(--terracotta)}
.results-info{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;font-size:11px;color:var(--grey4)}
.card{background:var(--white);border:1px solid var(--grey2);border-radius:14px;overflow:hidden}
.card table{border-radius:0 0 14px 14px;overflow:hidden}
table{width:100%;border-collapse:collapse}
th{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--grey4);font-weight:500;padding:11px 14px;text-align:left;border-bottom:1px solid var(--grey2);background:var(--grey1);white-space:nowrap}
td{font-size:12.5px;color:var(--grey5);padding:12px 14px;border-bottom:1px solid var(--grey2);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--grey1)}
.td-buyer{color:var(--black);font-weight:500}
.td-buyer-sub{font-size:11px;color:var(--grey4)}
.td-type{font-size:11px;color:var(--grey5)}
.td-budget{font-weight:600;color:var(--black);white-space:nowrap;font-size:12px}
.td-artist{font-size:12px}
.td-artist a{color:var(--black);text-decoration:none;font-weight:500}
.td-artist a:hover{text-decoration:underline}
.td-artist .unassigned{color:var(--grey4);font-style:italic;font-weight:400}
.td-date{font-size:11px;color:var(--grey4);white-space:nowrap}
.pill{display:inline-block;font-size:9px;letter-spacing:.5px;text-transform:uppercase;font-weight:600;padding:3px 9px;border-radius:20px;white-space:nowrap}
.pill.pending{background:#FFF0EC;color:var(--terracotta);font-weight:700}
.pill.price_proposed{background:#FFF8E1;color:var(--gold);border:1px solid #E8D5A0}
.pill.confirmed{background:#FFF4E6;color:#E48A4A}
.pill.processing{background:#EEF2F8;color:#3B7DD8}
.pill.shipped{background:#F3E5F5;color:#7B1FA2}
.pill.delivered{background:#E8F5EE;color:var(--green)}
.pill.payment_confirmed{background:#E8F5EE;color:#2E7D32;border:1px solid #A5D6A7;font-weight:700}
.pill.assigned{background:#FFF4E6;color:#E48A4A}
.ref-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:5px;background:var(--grey1);border:1px solid var(--grey2);cursor:pointer;transition:all .12s;flex-shrink:0}
.ref-icon:hover{border-color:var(--blue);background:#EEF2F8}
.ref-icon svg{width:12px;height:12px;color:var(--grey4)}
.td-actions{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.status-select{padding:5px 8px;font-size:10px;border:1px solid var(--grey2);border-radius:7px;background:var(--white);color:var(--black);font-family:'DM Sans',sans-serif;cursor:pointer;outline:none}
.status-select:focus{border-color:var(--black)}
.act-btn{padding:5px 10px;font-size:10.5px;font-weight:500;border-radius:7px;border:1px solid var(--grey2);background:var(--white);color:var(--grey5);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;white-space:nowrap}
.act-btn:hover{border-color:var(--black);color:var(--black)}
.act-btn.red:hover{border-color:var(--terracotta);color:var(--terracotta);background:#FFF0EC}
.act-btn.blue:hover{border-color:var(--blue);color:var(--blue);background:#EEF2F8}
.empty{text-align:center;padding:48px 24px;color:var(--grey4);font-size:13px}
.pagination{display:flex;align-items:center;justify-content:center;gap:4px;margin-top:20px}
.page-btn{padding:7px 13px;font-size:11.5px;border:1px solid var(--grey2);border-radius:8px;background:var(--white);color:var(--grey5);cursor:pointer;font-family:'DM Sans',sans-serif;text-decoration:none;transition:all .12s}
.page-btn:hover{border-color:var(--black);color:var(--black)}
.page-btn.active{background:var(--black);color:#fff;border-color:var(--black)}
.page-btn.disabled{opacity:.35;pointer-events:none}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-overlay.open{opacity:1;pointer-events:auto}
.modal{background:var(--white);border-radius:16px;width:740px;max-width:92vw;box-shadow:0 24px 60px rgba(0,0,0,.15);transform:translateY(12px);transition:transform .2s;max-height:90vh;overflow-y:auto}
.modal-overlay.open .modal{transform:translateY(0)}
.modal-head{padding:24px 28px 16px;display:flex;align-items:flex-start;justify-content:space-between;position:sticky;top:0;background:var(--white);z-index:1;border-bottom:1px solid var(--grey2)}
.modal-head h3{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--black)}
.modal-close{background:none;border:none;font-size:18px;color:var(--grey4);cursor:pointer;padding:0;line-height:1}
.modal-close:hover{color:var(--black)}
.modal-body{padding:20px 28px 28px}
.order-details-section{background:#F8F4F0;border:1.5px solid var(--grey2);border-radius:14px;padding:20px 22px;margin-top:20px}
.order-details-title{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:var(--grey4);font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.order-details-title svg{width:14px;height:14px;color:var(--grey4)}
.order-details-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.order-detail-item .odl{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--grey4);font-weight:500;margin-bottom:5px}
.order-detail-item .odv{font-size:13px;color:var(--black);font-weight:500}
.order-detail-item .odv.muted{color:var(--grey4);font-weight:400}
.order-detail-item input[type="number"],.order-detail-item input[type="text"],.order-detail-item textarea{padding:8px 12px;border:1.5px solid var(--grey2);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--black);outline:none;width:100%;max-width:200px;transition:border-color .15s}
.order-detail-item textarea{max-width:100%;min-height:50px;resize:vertical}
.order-detail-item input:focus,.order-detail-item textarea:focus{border-color:var(--black)}
.order-detail-item select{padding:8px 12px;border:1.5px solid var(--grey2);border-radius:8px;font-size:12px;font-family:'DM Sans',sans-serif;color:var(--black);background:var(--white);cursor:pointer;outline:none;min-width:180px}
.order-detail-item select:focus{border-color:var(--black)}
.order-detail-full{grid-column:1 / -1}
.order-detail-actions{grid-column:1 / -1;display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;padding-top:14px;border-top:1px solid var(--grey3)}
.forward-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:var(--blue);color:white;border:none;border-radius:9px;font-size:12px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s}
.forward-btn:hover{background:#0770c2}
.forward-btn:disabled{background:var(--grey3);color:var(--grey4);cursor:not-allowed}
.forward-btn svg{width:14px;height:14px}
.save-price-btn,.save-delivery-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;color:white;border:none;border-radius:8px;font-size:11px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s}
.save-price-btn{background:var(--green)}.save-price-btn:hover{background:#5a9480}
.save-delivery-btn{background:var(--black)}.save-delivery-btn:hover{background:#333}
.pill-ds{display:inline-block;font-size:8px;letter-spacing:.3px;text-transform:uppercase;font-weight:600;padding:2px 7px;border-radius:20px;white-space:nowrap}
.pill-ds.pending{background:#F4F4F4;color:#888}
.pill-ds.quote_pending{background:#FFF8E1;color:#F9A825}
.pill-ds.deposit_paid{background:#E3F2FD;color:#1565C0}
.pill-ds.in_progress{background:#EEF2F8;color:#3B7DD8}
.pill-ds.ready_to_ship{background:#E8F5E9;color:#2E7D32}
.pill-ds.shipped{background:#F3E5F5;color:#7B1FA2}
.pill-ds.delivered{background:#E8F5EE;color:var(--green)}
.pill-ds.cancelled{background:#FFEBEE;color:#C62828}
.pill-ps{display:inline-block;font-size:8px;letter-spacing:.3px;text-transform:uppercase;font-weight:600;padding:2px 7px;border-radius:20px;white-space:nowrap}
.pill-ps.pending{background:#F4F4F4;color:#888}
.pill-ps.paid{background:#E8F5EE;color:var(--green)}
.pill-ps.failed{background:#FFEBEE;color:#C62828}
.pill-ps.refunded{background:#FFF3E0;color:#E65100}
.shipping-payment-section{background:#F0F4F8;border:1.5px solid #D6E0E8;border-radius:14px;padding:20px 22px;margin-top:16px}
.shipping-payment-title{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:var(--grey4);font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.shipping-payment-title svg{width:14px;height:14px;color:var(--grey4)}
.screenshot-box{margin-top:12px;padding:12px;background:#fff;border:1px dashed var(--grey3);border-radius:8px;display:flex;align-items:center;gap:12px;}
.screenshot-thumb{width:60px;height:60px;border-radius:6px;object-fit:cover;border:1px solid var(--grey2);background:var(--grey1)}
.screenshot-info{flex:1}
.screenshot-info div{font-size:12px;color:var(--grey5);margin-bottom:2px;}
.screenshot-info strong{font-size:12px;color:var(--black);}
.mark-paid-btn{background:var(--green);color:white;border:none;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s}
.mark-paid-btn:hover{background:#5a9480}
.chat-section{margin-top:24px;padding-top:20px;border-top:2px solid var(--grey2)}
.chat-title{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--grey4);font-weight:500;margin-bottom:12px}
.chat-messages{background:var(--grey1);border-radius:12px;height:300px;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px}
.message{display:flex;flex-direction:column;max-width:80%}
.message.admin{align-self:flex-end}
.message.artist{align-self:flex-start}
.message.buyer{align-self:flex-start}
.message-bubble{padding:10px 14px;border-radius:18px;font-size:13px;line-height:1.5;word-break:break-word}
.message.admin .message-bubble{background:var(--black);color:white;border-bottom-right-radius:4px}
.message.artist .message-bubble{background:#EEF2F8;color:var(--black);border-bottom-left-radius:4px}
.message.buyer .message-bubble{background:#E8F5EE;color:var(--black);border-bottom-left-radius:4px}
.message-meta{font-size:10px;color:var(--grey4);margin-top:4px;padding:0 6px;display:flex;gap:8px;align-items:center}
.message.admin .message-meta{justify-content:flex-end}
.message .delete-msg{background:none;border:none;color:var(--terracotta);font-size:10px;cursor:pointer;opacity:0.6}
.message .delete-msg:hover{opacity:1;text-decoration:underline}
.chat-input-area{margin-top:16px;display:flex;gap:10px}
.chat-input-area input{flex:1;padding:12px 16px;border:1.5px solid var(--grey2);border-radius:30px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none}
.chat-input-area input:focus{border-color:var(--black)}
.chat-input-area button{background:var(--black);color:white;border:none;border-radius:30px;padding:0 20px;font-weight:500;cursor:pointer;transition:background .2s}
.chat-input-area button:hover{background:#333}
.chat-warning{font-size:10px;color:var(--grey4);margin-top:8px;text-align:center}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;font-size:12px;font-weight:500;border-radius:10px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--black);color:#fff}
.btn-primary:hover{background:#333}
.btn-sm{padding:6px 12px;font-size:11px}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.detail-item .dl{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--grey4);font-weight:500;margin-bottom:4px}
.detail-item .dv{font-size:13px;color:var(--black);font-weight:500}
.detail-item .dv.muted{color:var(--grey4);font-weight:400}
.detail-full{grid-column:1 / -1}
.detail-full .msg-text{font-size:13px;color:var(--grey5);line-height:1.6;background:var(--grey1);padding:14px;border-radius:10px;margin-top:4px;white-space:pre-wrap}
.ref-preview{margin-top:10px}
.ref-preview img{max-width:200px;max-height:200px;border-radius:10px;border:1px solid var(--grey2);object-fit:cover}
.notes-area{width:100%;padding:10px 14px;border:1.5px solid var(--grey2);border-radius:9px;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--black);outline:none;resize:vertical;min-height:60px;transition:border-color .15s}
.notes-area:focus{border-color:var(--black)}
.assign-row{display:flex;align-items:center;gap:10px;margin-top:16px;padding-top:16px;border-top:1px solid var(--grey2);flex-wrap:wrap}
.assign-row label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--grey4);font-weight:500;white-space:nowrap}
.assign-btn{background:var(--blue);color:white;border:none;padding:8px 16px;border-radius:8px;font-size:11px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:'DM Sans',sans-serif}
.assign-btn:hover{background:#0770c2}
.dash-footer{padding:20px 32px;border-top:1px solid #0C3F30;font-size:11px;color:#F6EDDE;margin-top:12px;background:#0C3F30}
.artist-selector-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.artist-selector-overlay.open{opacity:1;pointer-events:auto}
.artist-selector-modal{background:var(--white);border-radius:20px;width:800px;max-width:95vw;max-height:85vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.2)}
.artist-selector-header{padding:20px 24px 16px;border-bottom:1px solid var(--grey2);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--white);z-index:1}
.artist-selector-header h3{font-family:'Playfair Display',serif;font-size:18px;font-weight:400;color:var(--black)}
.artist-selector-header .sub{font-size:11px;color:var(--grey4);margin-top:2px}
.artist-selector-close{background:none;border:none;font-size:20px;color:var(--grey4);cursor:pointer}
.artist-selector-close:hover{color:var(--black)}
.artist-filter-bar{padding:12px 20px;border-bottom:1px solid var(--grey2);display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:var(--white)}
.artist-filter-bar input{flex:1;padding:8px 12px;border:1.5px solid var(--grey2);border-radius:20px;font-size:12px;font-family:'DM Sans',sans-serif;outline:none;min-width:150px}
.artist-filter-bar input:focus{border-color:var(--black)}
.artist-filter-bar select{padding:8px 12px;border:1.5px solid var(--grey2);border-radius:20px;font-size:12px;font-family:'DM Sans',sans-serif;background:var(--white);cursor:pointer;outline:none;min-width:140px}
.artist-filter-bar select:focus{border-color:var(--black)}
.filter-clear{background:var(--grey1);border:1px solid var(--grey2);padding:6px 12px;border-radius:20px;font-size:11px;cursor:pointer;color:var(--grey5)}
.filter-clear:hover{border-color:var(--terracotta);color:var(--terracotta)}
.artist-grid-modal{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;padding:20px}
.artist-card-modal{background:var(--grey1);border-radius:12px;overflow:hidden;border:2px solid transparent;transition:all .2s;position:relative}
.artist-card-modal:hover{transform:translateY(-2px);border-color:var(--terracotta);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.artist-card-modal.selected{border-color:var(--green);background:#E8F5EE}
.artist-avatar-modal{width:100%;height:120px;object-fit:cover;background:var(--grey2);cursor:pointer}
.artist-avatar-placeholder-modal{width:100%;height:120px;background:var(--terracotta);display:flex;align-items:center;justify-content:center;font-size:36px;color:white;font-weight:500;cursor:pointer}
.artist-info-modal{padding:10px 12px}
.artist-name-modal{font-size:14px;font-weight:600;color:var(--black);margin-bottom:2px}
.artist-city-modal{font-size:10px;color:var(--grey4)}
.artist-style-modal{font-size:10px;color:var(--grey5);background:var(--white);display:inline-block;padding:2px 8px;border-radius:12px;margin-top:6px}
.artist-stats-modal{display:flex;gap:10px;margin-top:8px;font-size:10px;color:var(--grey4)}
.artist-stats-modal span{display:inline-flex;align-items:center;gap:4px}
.assign-button-modal{width:100%;padding:8px;background:var(--blue);color:white;border:none;font-size:11px;font-weight:500;cursor:pointer;transition:background .15s;margin-top:8px;border-radius:6px;font-family:'DM Sans',sans-serif}
.assign-button-modal:hover{background:#0770c2}
.view-profile-link{text-decoration:none;display:block}
.no-results{text-align:center;padding:40px;color:var(--grey4)}
.saving-indicator{font-size:10px;color:var(--blue);margin-left:8px;display:none}
.saving-indicator.show{display:inline}
@media(max-width:900px){:root{--sidebar:0px}.sidebar{display:none}.topbar{left:0}.content{padding:16px}td,th{padding:8px 10px}.td-actions{flex-direction:column}.hide-mobile{display:none!important}.filters input{width:100%}.detail-grid,.order-details-grid{grid-template-columns:1fr}.message{max-width:95%}.artist-grid-modal{grid-template-columns:repeat(auto-fill,minmax(160px,1fr))}}
@media(max-width:600px){.tabs{gap:2px}.tab{padding:6px 10px;font-size:10.5px}.tab-label{display:none}.filters{flex-direction:column;align-items:stretch}.artist-grid-modal{grid-template-columns:1fr}.artist-filter-bar{flex-direction:column}.artist-filter-bar select,.artist-filter-bar input{width:100%}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand"><div class="logo-tag">Art Bazaar</div><div class="logo-name">Dashboard</div><span class="logo-badge">Admin</span></div>
    <div class="sidebar-section">Overview</div>
    <a href="index.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Overview</a>
    <div class="sidebar-section">Content</div>
    <a href="artworks.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-4 4 4"/><circle cx="8.5" cy="14.5" r="1.5"/></svg> Artworks</a>
    <a href="artists.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Artists</a>
    <a href="blogs.php" class="nav-item">
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/></svg>
    Blog Posts
</a>
    <a href="categories.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h7"/></svg> Categories</a>
    <div class="sidebar-section">Requests</div>
    <a href="inquiries.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Orders & Inquiries</a>
    <a href="commissions.php" class="nav-item active"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Commissions<?php if ($statusCounts['pending'] > 0): ?><span class="badge"><?= $statusCounts['pending'] ?></span><?php endif; ?></a>
    <a href="messages.php" class="nav-item"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v13H4z"/><path d="M4 4l8 9 8-9"/></svg> Messages</a>
    <div class="sidebar-bottom"><a href="../../logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Sign out</a></div>
</aside>

<header class="topbar">
    <div class="topbar-left"><h1>Commissions</h1><div class="sub">Manage custom artwork requests</div></div>
    <div class="topbar-right"><div class="admin-chip"><div class="avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div><span class="name"><?= htmlspecialchars($adminName) ?></span><span class="arrow">∨</span></div></div>
</header>

<main class="main">
<div class="content">
    <?php if ($toast): ?>
    <div class="toast"><span><?= htmlspecialchars($toast) ?></span><button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?<?= buildQS(['status' => null]) ?>" class="tab <?= !$statusFilter ? 'active' : '' ?>"><span class="tab-label">All</span> <span class="count"><?= $statusCounts['all'] ?></span></a>
        <?php foreach ($validStatuses as $s): ?>
        <a href="?<?= buildQS(['status' => $s]) ?>" class="tab <?= $statusFilter === $s ? 'active' : '' ?>"><span class="tab-label"><?= str_replace('_',' ',ucfirst($s)) ?></span> <span class="count <?= ($s === 'pending' && $statusCounts[$s] > 0) ? 'hot' : '' ?><?= ($s === 'price_proposed' && $statusCounts[$s] > 0) ? 'hot' : '' ?>"><?= $statusCounts[$s] ?></span></a>
        <?php endforeach; ?>
    </div>

    <div class="filters">
        <input type="text" placeholder="Search buyer, email, description..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
        <select id="artistSelect">
            <option value="">All Artists</option>
            <option value="unassigned" <?= $artistFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
            <?php foreach ($artistOptions as $ao): ?>
            <option value="<?= $ao['id'] ?>" <?= $artistFilter == $ao['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ao['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="sortSelect">
            <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' || !isset($_GET['sort']) ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= ($_GET['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            <option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Buyer name A-Z</option>
            <option value="deadline" <?= ($_GET['sort'] ?? '') === 'deadline' ? 'selected' : '' ?>>Deadline soonest</option>
        </select>
        <?php if ($statusFilter || $search || $artistFilter): ?>
            <button class="clear-link" onclick="window.location.href='commissions.php'">Clear all</button>
        <?php endif; ?>
    </div>

    <div class="results-info">
        <div>Showing <?= count($commissions) ?> of <?= $totalResults ?> commissions</div>
        <div>Page <?= $page ?> of <?= $totalPages ?></div>
    </div>

    <div class="card">
        <?php if (empty($commissions)): ?>
            <div class="empty">No commission requests found.</div>
        <?php else: ?>
        <table>
            <thead><tr><th>Buyer</th><th>Type</th><th>Budget</th><th>Artist</th><th class="hide-mobile">Date</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($commissions as $cr): ?>
                <tr>
                    <td>
                        <div class="td-buyer"><?= htmlspecialchars($cr['buyer_name']) ?></div>
                        <div class="td-buyer-sub">
                            <?php $email=$cr['buyer_email'];$phone=$cr['buyer_phone'];if($email)echo htmlspecialchars($email);if($email&&$phone)echo ' · ';if($phone)echo htmlspecialchars($phone); ?>
                        </div>
                    </td>
                    <td class="td-type"><?= htmlspecialchars($cr['artwork_type']) ?></td>
                    <td class="td-budget"><?= formatBudget($cr['budget_min'], $cr['budget_max']) ?></td>
                    <td class="td-artist">
                        <?php if (!empty($cr['artist_name'])): ?>
                            <a href="artist-view.php?id=<?= $cr['artist_id'] ?>"><?= htmlspecialchars($cr['artist_name']) ?></a>
                        <?php else: ?>
                            <span class="unassigned">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="td-date hide-mobile"><?= date('d M Y', strtotime($cr['created_at'])) ?></td>
                    <td><span class="pill <?= $cr['status'] ?>"><?= str_replace('_',' ',ucfirst($cr['status'])) ?></span></td>
                    <td>
                        <div class="td-actions">
                            <button type="button" class="act-btn blue" onclick="openDetail(<?= $cr['id'] ?>)">View &amp; Chat</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this commission request?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $cr['id'] ?>"><button type="submit" class="act-btn red">Delete</button></form>
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
        $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
        if ($start > 1) { echo '<a href="?'.buildQS(['page'=>1]).'" class="page-btn">1</a>'; if ($start > 2) echo '<span class="page-btn disabled">...</span>'; }
        for ($i = $start; $i <= $end; $i++) echo '<a href="?'.buildQS(['page'=>$i]).'" class="page-btn '.($i === $page ? 'active' : '').'">'.$i.'</a>';
        if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span class="page-btn disabled">...</span>'; echo '<a href="?'.buildQS(['page'=>$totalPages]).'" class="page-btn">'.$totalPages.'</a>'; }
        ?>
        <?php if ($page < $totalPages): ?><a href="?<?= buildQS(['page' => $page + 1]) ?>" class="page-btn">Next →</a><?php else: ?><span class="page-btn disabled">Next →</span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<div class="dash-footer">Art Bazaar Admin Panel &mdash; <?= date('Y') ?></div>
</main>

<div class="modal-overlay" id="detailModal"><div class="modal"><div class="modal-head"><h3>Commission Details &amp; Chat</h3><button class="modal-close" onclick="closeDetail()">&times;</button></div><div class="modal-body" id="detailContent"></div></div></div>

<div class="artist-selector-overlay" id="artistSelectorModal"><div class="artist-selector-modal"><div class="artist-selector-header"><div><h3>Select an Artist</h3><div class="sub">Choose an artist to assign</div></div><button class="artist-selector-close" onclick="closeArtistSelector()">&times;</button></div><div class="artist-filter-bar"><input type="text" id="artistSearchInput" placeholder="Search by name or city..." onkeyup="filterArtists()"><select id="styleFilterSelect" onchange="filterArtists()"><option value="">All Art Styles</option></select><button class="filter-clear" onclick="clearFilters()">Clear</button></div><div class="artist-grid-modal" id="artistGrid"><div style="text-align:center;padding:40px;">Loading...</div></div></div></div>

<script>
const commissionData = <?= json_encode($commissions, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
const artistList = <?= json_encode($artistOptions, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
const validStatuses = <?= json_encode($validStatuses) ?>;
let currentCommissionId = null;
let messageRefreshInterval = null;
let allArtists = [];
let allArtStyles = [];
let selectedArtistIdForAssignment = null;
let budgetSaveTimer = null;

const deliveryStatusOptions = [
    {value:'pending',label:'Pending'},{value:'quote_pending',label:'Quote Pending'},
    {value:'deposit_paid',label:'Deposit Paid'},{value:'in_progress',label:'In Progress'},
    {value:'ready_to_ship',label:'Ready to Ship'},{value:'shipped',label:'Shipped'},
    {value:'delivered',label:'Delivered'},{value:'cancelled',label:'Cancelled'}
];

function getProfileImageUrl(ip){if(!ip)return null;if(ip.startsWith('uploads/'))return '../../'+ip;if(ip.startsWith('http'))return ip;return '../../uploads/profiles/'+ip;}
function esc(s){if(!s)return '';const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function ucf(s){return s?s.charAt(0).toUpperCase()+s.slice(1):'';}

function triggerBudgetSave(id) {
    clearTimeout(budgetSaveTimer);
    budgetSaveTimer = setTimeout(() => {
        const minInput = document.getElementById('budget_min_'+id);
        const maxInput = document.getElementById('budget_max_'+id);
        const savingIndicator = document.getElementById('saving_indicator_'+id);
        
        if (!minInput || !maxInput) return;
        
        const minVal = minInput.value;
        const maxVal = maxInput.value;
        
        if(savingIndicator) savingIndicator.classList.add('show');
        
        fetch('commissions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update_budgets&id=${id}&budget_min=${encodeURIComponent(minVal)}&budget_max=${encodeURIComponent(maxVal)}`
        })
        .then(r => r.json())
        .then(data => {
            if(savingIndicator) savingIndicator.classList.remove('show');
            if(data.toast) showToast(data.toast);
        })
        .catch(e => {
            if(savingIndicator) savingIndicator.classList.remove('show');
            console.error(e);
        });
    }, 800);
}

function showToast(message) {
    let toast = document.querySelector('.content > .toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        document.querySelector('.content').prepend(toast);
    }
    toast.innerHTML = `<span>${esc(message)}</span><button class="toast-close" onclick="this.parentElement.classList.add('hidden')">&times;</button>`;
    toast.classList.remove('hidden');
    setTimeout(() => { if(toast) toast.classList.add('hidden'); }, 3000);
}

function openDetail(id){
    currentCommissionId=id;
    const cr=commissionData.find(i=>i.id==id);
    if(!cr)return;
    
    const artworkTypeLabel=cr.artwork_type||'Custom';
    
    const statusToDeliveryMap = {'pending':'pending','price_proposed':'pending','confirmed':'deposit_paid','processing':'in_progress','shipped':'shipped','delivered':'delivered','cancelled':'cancelled'};
    const currentDeliveryStatus = statusToDeliveryMap[cr.status] || 'pending';
    const deliveryStatusLabel = currentDeliveryStatus.replace(/_/g, ' ').replace(/\b\w/g,c=>c.toUpperCase());

    const currentPaymentStatus=cr.payment_status||'pending';
    const paymentStatusLabel=currentPaymentStatus.charAt(0).toUpperCase()+currentPaymentStatus.slice(1);

    let artistOptionsHtml='<option value="">— Select artist —</option>';
    artistList.forEach(artist=>{
        const isSelected = cr.artist_id==artist.id ? 'selected' : '';
        artistOptionsHtml+=`<option value="${artist.id}" ${isSelected}>${esc(artist.name)}</option>`;
    });

    let deliveryOptionsHtml='';deliveryStatusOptions.forEach(o=>{deliveryOptionsHtml+=`<option value="${o.value}" ${currentDeliveryStatus===o.value?'selected':''}>${o.label}</option>`;});

    const isOverdue=cr.commission_deadline&&new Date(cr.commission_deadline)<new Date()&&!['delivered','cancelled'].includes(cr.status);
    const deadlineDisplay=cr.commission_deadline?esc(cr.commission_deadline):'Not set';

    // Screenshot HTML generation
    let screenshotHtml = '';
    if (cr.payment_screenshot) {
        screenshotHtml = `
            <div class="screenshot-box">
                <img src="../../${cr.payment_screenshot}" class="screenshot-thumb" alt="Payment SS" style="cursor:pointer;" onclick="window.open('../../${cr.payment_screenshot}', '_blank')">
                <div class="screenshot-info">
                    <strong>Payment Screenshot Uploaded</strong>
                    <div>${cr.payment_method ? esc(cr.payment_method).replace(/_/g, ' ').toUpperCase() : ''}</div>
                </div>
                ${currentPaymentStatus !== 'paid' ? `
                <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this order as Paid?')">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="id" value="${cr.id}">
                    <button type="submit" class="mark-paid-btn">Mark as Paid</button>
                </form>
                ` : ''}
            </div>
        `;
    }

    const content=document.getElementById('detailContent');
    content.innerHTML=`
        <div class="detail-grid">
            <div class="detail-item"><div class="dl">Buyer Name</div><div class="dv">${esc(cr.buyer_name)}</div></div>
            <div class="detail-item"><div class="dl">Date</div><div class="dv">${esc(cr.created_at)}</div></div>
            <div class="detail-item"><div class="dl">Email</div><div class="dv ${!cr.buyer_email?'muted':''}">${cr.buyer_email?esc(cr.buyer_email):'Not provided'}</div></div>
            <div class="detail-item"><div class="dl">Phone / WhatsApp</div><div class="dv ${!cr.buyer_phone?'muted':''}">${cr.buyer_phone?esc(cr.buyer_phone):'Not provided'}</div></div>
            <div class="detail-item"><div class="dl">Art Type</div><div class="dv">${esc(artworkTypeLabel)}</div></div>
            <div class="detail-item"><div class="dl">Assigned Artist</div><div class="dv ${!cr.artist_name?'muted':''}">${cr.artist_name?'<a href="artist-view.php?id='+cr.artist_id+'">'+esc(cr.artist_name)+'</a>':'Not assigned yet'}</div></div>
        </div>
        <div class="order-details-section">
            <div class="order-details-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg> Order Details</div>
            <div class="order-details-grid">
                <div class="order-detail-item">
                    <div class="odl">Estimated Minimum Budget (PKR)</div>
                    <div style="display:flex;align-items:center;">
                        <input type="number" id="budget_min_${cr.id}" value="${cr.budget_min || ''}" placeholder="e.g. 5000" min="0" step="0.01" onkeyup="triggerBudgetSave(${cr.id})" onblur="triggerBudgetSave(${cr.id})">
                        <span class="saving-indicator" id="saving_indicator_${cr.id}">Saving...</span>
                    </div>
                </div>
                <div class="order-detail-item">
                    <div class="odl">Estimated Maximum Budget (PKR)</div>
                    <input type="number" id="budget_max_${cr.id}" value="${cr.budget_max || ''}" placeholder="e.g. 15000" min="0" step="0.01" onkeyup="triggerBudgetSave(${cr.id})" onblur="triggerBudgetSave(${cr.id})">
                </div>
                <div class="order-detail-item">
                    <div class="odl">Preferred Deadline</div>
                    <div class="odv ${!cr.commission_deadline ? 'muted' : ''}" style="${isOverdue ? 'color:var(--terracotta);font-weight:600;' : ''}">${deadlineDisplay}${isOverdue ? ' <span style="font-size:11px;font-weight:700;">(OVERDUE)</span>' : ''}</div>
                </div>
                <div class="order-detail-item"><div class="odl">Delivery Status</div><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <form method="POST" style="display:flex;gap:8px;align-items:center;"><input type="hidden" name="action" value="update_delivery_status"><input type="hidden" name="id" value="${cr.id}"><select name="delivery_status">${deliveryOptionsHtml}</select><button type="submit" class="save-delivery-btn"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Update</button></form>
                    <span class="pill-ds ${currentDeliveryStatus}">${deliveryStatusLabel}</span>
                </div></div>
                <div class="order-detail-actions">
                     ${!cr.artist_id?`<button type="button" class="forward-btn" style="background:var(--amber);" onclick="openArtistSelector(${cr.id})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Browse &amp; Assign Artist</button>`:''}
                    ${cr.artist_id?`<button type="button" class="forward-btn" style="background:var(--terracotta);" onclick="unassignArtist(${cr.id})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Unassign Artist</button>`:''}
${cr.status==='payment_review'?`<form method="POST" style="display:inline;" onsubmit="return confirm('Confirm payment and notify artist to begin work?')"><input type="hidden" name="action" value="approve_payment"><input type="hidden" name="id" value="${cr.id}"><button type="submit" class="forward-btn" style="background:var(--green);">✓ Confirm Payment & Notify Artist</button></form>`:''}
${cr.status==='payment_confirmed'?`
    <div style="background:#E8F5EE;border:1px solid #A5D6A7;border-radius:8px;padding:8px 14px;font-size:12px;color:#2E7D32;font-weight:500;">✓ Payment confirmed — artist notified. Waiting for artist to start and finish work.</div>
`:''}
${cr.status==='processing'?`
    <div style="background:#EEF2F8;border:1px solid #B3CDEF;border-radius:8px;padding:8px 14px;font-size:12px;color:#3B7DD8;font-weight:500;">🎨 Artist is working on this commission.</div>
`:''}
${cr.status==='ready_to_ship'?`
    <div style="background:#F3E5F5;border:1px solid #CE93D8;border-radius:8px;padding:8px 14px;font-size:12px;color:#6A1B9A;font-weight:500;">📦 Artist marked this as ready to ship.</div>
    ${!cr.smartlane_warehouse_code?`
        <div style="background:#FFF3E0;border:1px solid #FFCC80;border-radius:8px;padding:8px 14px;font-size:12px;color:#E65100;font-weight:500;">⚠ No Smartlane warehouse code set for this artist. Add it on the Artists page first.</div>
    `:`
        <form method="POST" style="display:inline;" onsubmit="return confirm('Send this order to Smartlane for courier booking?')"><input type="hidden" name="action" value="send_to_courier"><input type="hidden" name="id" value="${cr.id}"><button type="submit" class="forward-btn" style="background:var(--blue);">🚚 Send to Courier</button></form>
    `}
`:''}
${cr.status==='processing'&&cr.tracking_number?`<div style="background:#EEF2F8;border:1px solid #B3CDEF;border-radius:8px;padding:8px 14px;font-size:12px;color:#3B7DD8;font-weight:500;">📦 Tracking: ${esc(cr.tracking_number)}${cr.courier?' · '+esc(cr.courier):''}</div>`:''}
${cr.status==='processing'&&!cr.tracking_number?`<div style="background:#F4F4F4;border:1px solid #DDD;border-radius:8px;padding:8px 14px;font-size:12px;color:#888;font-weight:500;">Sent to courier — waiting for Smartlane to confirm booking and return a tracking number.</div>`:''}
                </div>
            </div>
        </div>
        <div class="shipping-payment-section">
            <div class="shipping-payment-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg> Shipping &amp; Payment</div>
            <div class="order-details-grid">
                <div class="order-detail-item order-detail-full">
                    <div class="odl">Order Number</div>
                    <div class="odv">#${esc(cr.order_number || cr.id)}</div>
                </div>
                <div class="order-detail-item order-detail-full">
                    <div class="odl">Shipping Address</div>
                    <div class="odv">${cr.shipping_address ? esc(cr.shipping_address) + (cr.shipping_city ? ', ' + esc(cr.shipping_city) : '') : '<span style="color:var(--grey4)">Not provided</span>'}</div>
                    ${cr.shipping_phone ? `<div class="odv" style="margin-top:4px;">${esc(cr.shipping_phone)}</div>` : ''}
                </div>
                <div class="order-detail-item">
                    <div class="odl">Payment Method</div>
                    <div class="odv">${cr.payment_method ? esc(cr.payment_method).replace(/_/g,' ') : '<span style="color:var(--grey4)">Not set</span>'}</div>
                </div>
                <div class="order-detail-item">
                    <div class="odl">Payment Status</div>
                    <div class="odv"><span class="pill-ps ${currentPaymentStatus}">${paymentStatusLabel}</span></div>
                </div>
                ${screenshotHtml}
            </div>
        </div>
        <div class="detail-full" style="margin-top:18px;"><div class="dl">Description</div><div class="msg-text">${cr.commission_description?esc(cr.commission_description):'<span style="color:var(--grey4)">No description.</span>'}</div></div>
        ${cr.commission_reference_image?`<div class="detail-full" style="margin-top:18px;"><div class="dl">Reference Image</div><div class="ref-preview"><img src="../../uploads/commissions/${esc(cr.commission_reference_image)}" alt="Reference"></div></div>`:''}
        <div class="detail-full" style="margin-top:18px;">
            <form method="POST"><input type="hidden" name="action" value="save_notes"><input type="hidden" name="id" value="${cr.id}">
                <div class="dl" style="margin-bottom:6px;">Admin Notes <span style="font-size:9px;background:#DDCDAE;color:#0C3F30;padding:2px 7px;border-radius:20px;letter-spacing:1px;font-weight:600;margin-left:6px;">PRIVATE</span></div>
                <textarea class="notes-area" name="admin_notes" placeholder="Track progress, conversations, payment status...">${esc(cr.admin_notes||'')}</textarea>
                <div style="margin-top:10px;text-align:right;"><button type="submit" class="btn btn-primary btn-sm">Save Notes</button></div>
            </form>
        </div>
        <div class="assign-row">
            <label>Quick Assign</label>
            <form method="POST" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;align-items:center;"><input type="hidden" name="action" value="assign_artist"><input type="hidden" name="id" value="${cr.id}"><select name="artist_id" class="status-select" style="flex:1;min-width:160px;">${artistOptionsHtml}</select><button type="submit" class="assign-btn">Assign</button></form>
        </div>
        <div class="chat-section">
            <div class="chat-title">Conversation Thread</div>
            <div class="chat-messages" id="chatMessages-${cr.id}"><div style="text-align:center;padding:20px;color:var(--grey4);">Loading messages...</div></div>
            <div class="chat-input-area">
                <input type="text" id="chatInput-${cr.id}" placeholder="Type your message..." autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();sendMessage(${cr.id})}">
                <button onclick="sendMessage(${cr.id})">Send</button>
            </div>
            <div class="chat-warning">Contact information is automatically blocked.</div>
        </div>
    `;

    document.getElementById('detailModal').classList.add('open');
    loadMessages(cr.id);
    if(messageRefreshInterval)clearInterval(messageRefreshInterval);
    messageRefreshInterval=setInterval(()=>{if(currentCommissionId&&document.getElementById('detailModal').classList.contains('open'))loadMessages(currentCommissionId);else if(!document.getElementById('detailModal').classList.contains('open')){clearInterval(messageRefreshInterval);messageRefreshInterval=null;}},5000);
}

function openArtistSelector(cid){currentCommissionId=cid;const cr=commissionData.find(i=>i.id==cid);selectedArtistIdForAssignment=cr.artist_id||null;if(allArtists.length===0){fetch('commissions.php?action=get_artists').then(r=>r.json()).then(d=>{allArtists=d.artists;allArtStyles=d.artStyles||[];populateStyleFilter();renderArtistGrid();});}else{renderArtistGrid();}document.getElementById('artistSelectorModal').classList.add('open');}
function populateStyleFilter(){const s=document.getElementById('styleFilterSelect');s.innerHTML='<option value="">All Art Styles</option>';allArtStyles.forEach(st=>{s.innerHTML+=`<option value="${esc(st)}">${esc(st)}</option>`;});}

function renderArtistGrid(){const st=document.getElementById('artistSearchInput')?.value.toLowerCase()||'';const sf=document.getElementById('styleFilterSelect')?.value||'';let f=allArtists.filter(a=>a.name.toLowerCase().includes(st)||(a.city&&a.city.toLowerCase().includes(st)));if(sf)f=f.filter(a=>a.art_style&&a.art_style.toLowerCase()===sf.toLowerCase());const g=document.getElementById('artistGrid');if(!f.length){g.innerHTML='<div class="no-results">No artists found.</div>';return;}g.innerHTML=f.map(a=>{const pp=getProfileImageUrl(a.profile_picture);const isSel=selectedArtistIdForAssignment==a.id;const ac=a.artwork_count||0;return `<div class="artist-card-modal ${isSel?'selected':''}"><a href="artist-view.php?id=${a.id}" target="_blank" class="view-profile-link">${pp?`<img class="artist-avatar-modal" src="${pp}" alt="${esc(a.name)}">`:`<div class="artist-avatar-placeholder-modal">${a.name.charAt(0).toUpperCase()}</div>`}</a><div class="artist-info-modal"><a href="artist-view.php?id=${a.id}" target="_blank" style="text-decoration:none;color:inherit;"><div class="artist-name-modal">${esc(a.name)}</div></a><div class="artist-city-modal">${a.city?esc(a.city):'Location not set'}</div>${a.art_style?`<div class="artist-style-modal">${esc(a.art_style)}</div>`:''}<div class="artist-stats-modal"><span>Artworks: ${ac}</span><span>${a.accepts_commissions?'Accepts':'Off'}</span></div><button class="assign-button-modal" onclick="selectArtist(${a.id})">Assign Artist</button></div></div>`;}).join('');}

function filterArtists(){renderArtistGrid();}
function clearFilters(){document.getElementById('artistSearchInput').value='';document.getElementById('styleFilterSelect').value='';renderArtistGrid();}

function selectArtist(aid){selectedArtistIdForAssignment=aid;const f=document.createElement('form');f.method='POST';f.style.display='none';const a1=document.createElement('input');a1.type='hidden';a1.name='action';a1.value='assign_artist';const a2=document.createElement('input');a2.type='hidden';a2.name='id';a2.value=currentCommissionId;const a3=document.createElement('input');a3.type='hidden';a3.name='artist_id';a3.value=aid;f.appendChild(a1);f.appendChild(a2);f.appendChild(a3);document.body.appendChild(f);f.submit();closeArtistSelector();}

function unassignArtist(cid){if(confirm('Remove artist?')){const f=document.createElement('form');f.method='POST';f.style.display='none';const a1=document.createElement('input');a1.type='hidden';a1.name='action';a1.value='assign_artist';const a2=document.createElement('input');a2.type='hidden';a2.name='id';a2.value=cid;const a3=document.createElement('input');a3.type='hidden';a3.name='artist_id';a3.value='';f.appendChild(a1);f.appendChild(a2);f.appendChild(a3);document.body.appendChild(f);f.submit();}}

function closeArtistSelector(){document.getElementById('artistSelectorModal').classList.remove('open');document.getElementById('artistSearchInput').value='';document.getElementById('styleFilterSelect').value='';selectedArtistIdForAssignment=null;}

function loadMessages(oid){fetch(`commissions.php?action=get_messages&order_id=${oid}`).then(r=>r.json()).then(d=>{const c=document.getElementById(`chatMessages-${oid}`);if(!c)return;if(!d.messages||!d.messages.length){c.innerHTML='<div style="text-align:center;padding:20px;color:var(--grey4);">No messages yet.</div>';return;}let h='';d.messages.forEach(m=>{const rc=m.sender_role==='admin'?'admin':(m.sender_role==='artist'?'artist':'buyer');const t=new Date(m.created_at).toLocaleString();h+=`<div class="message ${rc}" data-msg-id="${m.id}"><div class="message-bubble">${esc(m.message)}</div><div class="message-meta"><span>${esc(m.sender_name_display || m.sender_name)}</span><span>·</span><span>${t}</span><button class="delete-msg" onclick="deleteMessage(${m.id},${oid})">Delete</button></div></div>`;});c.innerHTML=h;c.scrollTop=c.scrollHeight;}).catch(e=>console.error(e));}

function sendMessage(oid){const inp=document.getElementById(`chatInput-${oid}`);const msg=inp.value.trim();if(!msg)return;fetch('commissions.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=send_message&commission_id=${oid}&message=${encodeURIComponent(msg)}`}).then(()=>{inp.value='';loadMessages(oid);}).catch(e=>console.error(e));}

function deleteMessage(mid,oid){if(!confirm('Delete this message?'))return;fetch('commissions.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=delete_message&message_id=${mid}`}).then(()=>loadMessages(oid)).catch(e=>console.error(e));}

function closeDetail(){document.getElementById('detailModal').classList.remove('open');if(messageRefreshInterval){clearInterval(messageRefreshInterval);messageRefreshInterval=null;}currentCommissionId=null;}

document.getElementById('detailModal').addEventListener('click',function(e){if(e.target===this)closeDetail();});
document.getElementById('artistSelectorModal').addEventListener('click',function(e){if(e.target===this)closeArtistSelector();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeDetail();closeArtistSelector();}});

let searchTimer;
document.getElementById('searchInput').addEventListener('keyup',function(){clearTimeout(searchTimer);searchTimer=setTimeout(applyFilters,400);});
document.getElementById('artistSelect').addEventListener('change',applyFilters);
document.getElementById('sortSelect').addEventListener('change',applyFilters);

function applyFilters(){let p=new URLSearchParams(window.location.search);let q=document.getElementById('searchInput').value.trim();let a=document.getElementById('artistSelect').value;let s=document.getElementById('sortSelect').value;if(q)p.set('q',q);else p.delete('q');if(a)p.set('artist',a);else p.delete('artist');if(s)p.set('sort',s);else p.delete('sort');p.delete('page');window.location.href='commissions.php?'+p.toString();}
</script>
</body>
</html>


 