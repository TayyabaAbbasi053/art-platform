<?php
session_start();
require_once __DIR__ . '/config/db.php';

// ── Auth guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

 $buyerId = (int)$_SESSION['user_id'];
 $buyerEmail = $_SESSION['email'] ?? '';
 $buyerName = $_SESSION['name'] ?? '';

// ── Determine checkout flow ──────────────────────────────
 $commissionOrderId = isset($_GET['order_id']) && ($_GET['type'] ?? '') === 'commission' ? (int)$_GET['order_id'] : 0;
 $isCommissionCheckout = $commissionOrderId > 0;
 $isDigitalItem = false;

 $cartItems = [];
 $subtotal = 0;
 $shippingFee = 0;
 $total = 0;
 $existingOrder = null;
 $commissionCategoryName = null;

// Ensure upload directory exists
 $uploadDir = __DIR__ . '/uploads/payment_screenshots/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── Helper: Dynamic Shipping Calculation (Server-Side) ─────
function calculateShippingServerSide($conn, $buyerCity, $cartItems): int {
    if (empty($buyerCity) || empty($cartItems)) return 0;

    $ids = [];
    foreach ($cartItems as $it) {
        if ($it['type'] === 'artwork') $ids[] = (int)$it['id'];
    }

    if (empty($ids)) return 0;

    $idsStr = implode(',', $ids);
    $q = "SELECT a.id, a.weight_kg,
                 (SELECT ap.city FROM artist_profiles ap WHERE ap.user_id = a.artist_id LIMIT 1) AS artist_city
          FROM artworks a
          WHERE a.id IN ($idsStr)";
    $res = $conn->query($q);

    $totalFee = 0;

    while ($row = $res->fetch_assoc()) {
        $artistCity = $row['artist_city'] ?? '';
        $weightKg   = (float)($row['weight_kg'] ?? 1.00);

        // Base fee: same city = 350, different city = 500
        $base = (!empty($artistCity) && strcasecmp(trim($buyerCity), trim($artistCity)) === 0) ? 300 : 500;

        // Weight surcharge: +100 per kg above 1kg
        $weightSurcharge = (int)(max(0, ceil($weightKg - 1)) * 100);

        $totalFee += $base + $weightSurcharge;
    }

    return $totalFee;
}

// ── AJAX Handler (Self-contained) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_calculate_shipping'])) {
    header('Content-Type: application/json');
    $buyerCity = trim($_POST['buyer_city'] ?? '');

    $items = [];
$ajaxArtworkId = (int)($_POST['artwork_id'] ?? 0);
if ($ajaxArtworkId > 0) {
    $items[] = ['type' => 'artwork', 'id' => $ajaxArtworkId];
}

    // Check if this is a commission checkout
$commissionOrderIdAjax = isset($_POST['commission_order_id']) ? (int)$_POST['commission_order_id'] : 0;
if ($commissionOrderIdAjax > 0) {
    $crRes = $conn->query("SELECT cr.artist_id FROM commission_requests cr WHERE cr.order_id = $commissionOrderIdAjax LIMIT 1");
    $artistIdAjax = $crRes ? ($crRes->fetch_assoc()['artist_id'] ?? null) : null;
    $artistCityAjax = '';
    if ($artistIdAjax) {
        $apRes = $conn->query("SELECT city FROM artist_profiles WHERE user_id = $artistIdAjax LIMIT 1");
        if ($apRes) $artistCityAjax = $apRes->fetch_assoc()['city'] ?? '';
    }
    $fee = (!empty($artistCityAjax) && strcasecmp(trim($buyerCity), trim($artistCityAjax)) === 0) ? 300 : 500;
} else {
    $fee = calculateShippingServerSide($conn, $buyerCity, $items);
}

    // Build breakdown for display
    $breakdown = [];
    if (!empty($items)) {
        $ids = array_map(fn($i) => (int)$i['id'], array_filter($items, fn($i) => $i['type'] === 'artwork'));
        if (!empty($ids)) {
            $idsStr = implode(',', $ids);
            $q = "SELECT a.weight_kg,
                         (SELECT ap.city FROM artist_profiles ap WHERE ap.user_id = a.artist_id LIMIT 1) AS artist_city
                  FROM artworks a WHERE a.id IN ($idsStr)";
            $bRes = $conn->query($q);
            while ($bRow = $bRes->fetch_assoc()) {
                $artistCity  = $bRow['artist_city'] ?? '';
                $weightKg    = (float)($bRow['weight_kg'] ?? 1.00);
                $base = (!empty($artistCity) && strcasecmp(trim($buyerCity), trim($artistCity)) === 0) ? 300 : 500;
                $surcharge   = (int)(max(0, ceil($weightKg - 1)) * 100);
                $breakdown[] = 'PKR ' . $base . ($surcharge > 0 ? ' + PKR ' . $surcharge . ' (weight)' : '') . ' = PKR ' . ($base + $surcharge);
            }
        }
    }

    echo json_encode(['shipping_fee' => $fee, 'breakdown' => $breakdown]);
    exit;
}

// ── Flow: Commission ──────────────────────────────────────
if ($isCommissionCheckout) {
    $stmt = $conn->prepare("
        SELECT o.*, c.name AS category_name, ua.name AS commission_artist_name
        FROM orders o
        LEFT JOIN categories c ON o.commission_category_id = c.id
        LEFT JOIN commission_requests cr ON cr.order_id = o.id
        LEFT JOIN users ua ON cr.artist_id = ua.id
        WHERE o.id = ? AND o.order_type = 'commission' AND o.order_status = 'assigned' AND o.price_status = 'accepted' AND o.payment_status = 'pending'
    ");
    $stmt->bind_param('i', $commissionOrderId);
    $stmt->execute();
    $existingOrder = $stmt->get_result()->fetch_assoc();

    if (!$existingOrder) {
        $_SESSION['error_msg'] = 'Order not ready for checkout.';
        header('Location: dashboard/buyer/account.php');
        exit;
    }
    
    if ($existingOrder['buyer_id'] !== null && $existingOrder['buyer_id'] != $buyerId) {
        header('Location: commission.php');
        exit;
    }

    $commissionCategoryName = $existingOrder['category_name'];
    $total = (float)($existingOrder['total'] ?? 0);
    $shippingFee = (float)($existingOrder['shipping_fee'] ?? 0); 
    $subtotal = (float)($existingOrder['subtotal'] ?? 0);

    if ($subtotal <= 0 && $total > 0) { $subtotal = $total; }

    $cartItems[] = [
        'type' => 'commission',
        'id' => $commissionOrderId,
        'title' => !empty($existingOrder['commission_description']) ? substr($existingOrder['commission_description'], 0, 80) . '...' : 'Custom Commission Request',
        'full_description' => $existingOrder['commission_description'] ?? '',
        'price' => $subtotal,
        'quantity' => 1,
        'artist_id' => $existingOrder['commission_artist_id'] ?? null,
        'artist_name' => $existingOrder['commission_artist_name'] ?? 'To be assigned',
        'cover_image' => $existingOrder['commission_reference_image'] ?? null,
        'deadline' => $existingOrder['commission_deadline'] ?? null,
        'budget_min' => $existingOrder['budget_min'] ?? null,
        'budget_max' => $existingOrder['budget_max'] ?? null,
        'category' => $commissionCategoryName
    ];

    if (isset($_SESSION['commission_checkout_error'])) {
        $orderError = $_SESSION['commission_checkout_error'];
        unset($_SESSION['commission_checkout_error']);
    }

} else {
    // ── Flow: Direct Artwork Purchase ─────────────────────────
    $artworkId = (int)($_GET['artwork_id'] ?? 0);
    if (!$artworkId) {
        header('Location: artworks.php');
        exit;
    }

    $awQuery = $conn->prepare("
        SELECT a.id, a.title, a.price, a.status, a.artist_id,
               (SELECT ai.image_path FROM artwork_images ai WHERE ai.artwork_id = a.id AND ai.is_cover = 1 LIMIT 1) AS cover_image,
               ua.name AS artist_name,
               c.slug AS category_slug
        FROM artworks a
        JOIN users ua ON a.artist_id = ua.id
        LEFT JOIN categories c ON a.category_id = c.id
        WHERE a.id = ?
    ");
    $awQuery->bind_param('i', $artworkId);
    $awQuery->execute();
    $artworkRow = $awQuery->get_result()->fetch_assoc();

    if (!$artworkRow) {
        header('Location: artworks.php');
        exit;
    }

    if ($artworkRow['status'] === 'sold') {
        $_SESSION['error_msg'] = 'Sorry, this artwork was just sold.';
        header('Location: artwork-detail.php?id=' . $artworkId);
        exit;
    }

    $price = $artworkRow['price'];
    $isDigitalItem = (($artworkRow['category_slug'] ?? '') === 'digital-art');
    $cartItems[] = [
        'type' => 'artwork',
        'id' => $artworkRow['id'],
        'title' => $artworkRow['title'],
        'price' => $price,
        'quantity' => 1,
        'artist_id' => $artworkRow['artist_id'],
        'artist_name' => $artworkRow['artist_name'],
        'cover_image' => $artworkRow['cover_image'],
        'status' => $artworkRow['status'],
        'is_digital' => $isDigitalItem,
    ];
    $subtotal = $price;
    $shippingFee = 0;
    $total = $subtotal;
}

// ── Fetch saved addresses ───────────────────────────────────
 $addresses = [];
 $addrQuery = $conn->prepare("SELECT * FROM buyer_addresses WHERE buyer_id = ? ORDER BY is_default DESC, created_at DESC");
 $addrQuery->bind_param('i', $buyerId);
 $addrQuery->execute();
 $addresses = $addrQuery->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Handle form submission ───────────────────────────────────
 $orderSuccess = false;
 $orderError = '';
 $orderNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? 'jazzcash';
    $saveAddress = isset($_POST['save_address']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');

    // COD is never allowed for commissions or digital artworks, regardless of what was posted
    $allowedMethods = ($isCommissionCheckout || $isDigitalItem)
        ? ['jazzcash', 'easypaisa', 'nayapay']
        : ['jazzcash', 'easypaisa', 'nayapay', 'cod'];
    $isCod = ($paymentMethod === 'cod');

    // Validation: City is required for calculation
    if (empty($city)) {
        $orderError = 'Please enter your City to calculate shipping.';
    } else {
        
        // File Upload — only required for wallet methods, never for COD
        $screenshotPath = null;
        if (!$isCod) {
            if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['payment_screenshot']['tmp_name'];
                $fileName = time() . '_' . basename($_FILES['payment_screenshot']['name']);
                $fileSize = $_FILES['payment_screenshot']['size'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($fileExt, $allowedExts) && $fileSize <= 2 * 1024 * 1024) {
                    if (move_uploaded_file($fileTmp, $uploadDir . $fileName)) {
                        $screenshotPath = 'uploads/payment_screenshots/' . $fileName;
                    } else {
                        $orderError = 'Failed to upload screenshot. Please try again.';
                    }
                } else {
                    $orderError = 'Invalid file. Only JPG, PNG, WEBP allowed (Max 2MB).';
                }
            } else {
                $orderError = 'Payment screenshot is required.';
            }
        }
        
        if (!$fullName || !$address || !$phone) {
            $orderError = 'Please fill in all required fields.';
        } elseif (!in_array($paymentMethod, $allowedMethods)) {
            if ($isCommissionCheckout) {
                $orderError = 'Cash on Delivery is not available for commissions. Please choose JazzCash, Easypaisa, or Nayapay.';
            } elseif ($isDigitalItem) {
                $orderError = 'Cash on Delivery is not available for digital artworks. Please choose JazzCash, Easypaisa, or Nayapay.';
            } else {
                $orderError = 'Invalid payment method.';
            }
        } elseif (!$isCod && !$screenshotPath) {
             $orderError = 'Payment screenshot is required.';
        } else {
            $conn->begin_transaction();
            try {
                // Recalculate Shipping Server-Side based on submitted City
                if ($isCommissionCheckout && $existingOrder) {
    $artistId = $existingOrder['commission_artist_id'] ?? null;
    $artistCity = '';
    if ($artistId) {
        $res = $conn->query("SELECT city FROM artist_profiles WHERE user_id = $artistId LIMIT 1");
        if ($res) $artistCity = $res->fetch_assoc()['city'] ?? '';
    }
    $finalShippingFee = (!empty($artistCity) && strcasecmp(trim($city), trim($artistCity)) === 0) ? 300 : 500;
} else {
    $finalShippingFee = calculateShippingServerSide($conn, $city, $cartItems);
}
$finalTotal = $subtotal + $finalShippingFee;

// Re-validate COD eligibility now that shipping is known — a borderline
// cart could cross the 10k line once shipping is added.
if ($isCod && $finalTotal > 10000) {
    throw new Exception('COD_LIMIT_EXCEEDED');
}

                if ($isCommissionCheckout && $existingOrder) {
                    $orderId = $existingOrder['id'];
                    $stmt = $conn->prepare("
    UPDATE orders SET 
        payment_method = ?, payment_screenshot = ?, shipping_address = ?, 
        shipping_city = ?, shipping_phone = ?, buyer_notes = ?,
        shipping_fee = ?, total = ?,
        updated_at = NOW() 
    WHERE id = ?
");
$stmt->bind_param('ssssssddi', $paymentMethod, $screenshotPath, $address, $city, $phone, $notes, $finalShippingFee, $finalTotal, $orderId);
                    $stmt->execute();
                    
                    $stmtStatusUpdate = $conn->prepare("UPDATE orders SET order_status = 'payment_review' WHERE id = ?");
                    $stmtStatusUpdate->bind_param('i', $orderId);
                    $stmtStatusUpdate->execute();

                    $historyNote = 'Buyer submitted payment details (' . strtoupper($paymentMethod) . '). Awaiting admin verification.';
                    $stmtHistory = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, 'assigned', 'payment_review', 'buyer', ?, ?)");
                    $stmtHistory->bind_param('iis', $orderId, $buyerId, $historyNote);
                    $stmtHistory->execute();

                } else {
                    // CART FLOW
                    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $orderType = 'artwork'; 
                    
                    $initialPaymentStatus = $isCod ? 'cod_pending' : 'pending';

                    $stmt = $conn->prepare("
                        INSERT INTO orders (buyer_id, order_number, order_type, order_status, subtotal, shipping_fee, discount, total, payment_method, payment_status, payment_screenshot, shipping_address, shipping_city, shipping_phone, buyer_notes, created_at)
                        VALUES (?, ?, ?, 'pending', ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->bind_param('issddssssssss', $buyerId, $orderNumber, $orderType, $subtotal, $finalShippingFee, $finalTotal, $paymentMethod, $initialPaymentStatus, $screenshotPath, $address, $city, $phone, $notes);
                    $stmt->execute();
                    $orderId = $conn->insert_id;
                    
                    foreach ($cartItems as $item) {
                        $itemStatus = 'pending';
                        $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, item_type, item_id, quantity, price, item_status) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmtItem->bind_param('isiiis', $orderId, $item['type'], $item['id'], $item['quantity'], $item['price'], $itemStatus);
                        $stmtItem->execute();

                        // Mark artwork as sold and release reservation
                        if ($item['type'] === 'artwork') {
                            $soldStmt = $conn->prepare("UPDATE artworks SET status = 'sold' WHERE id = ?");
                            $soldStmt->bind_param('i', $item['id']);
                            $soldStmt->execute();
                        }
                    }
                    
                    $placedNote = $isCod
                        ? 'Order placed with Cash on Delivery.'
                        : 'Order placed. Payment verification pending.';
                    $stmtHistory = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes) VALUES (?, NULL, 'pending', 'buyer', ?, ?)");
                    $stmtHistory->bind_param('iis', $orderId, $buyerId, $placedNote);
                    $stmtHistory->execute();
                }
                
                if ($saveAddress) {
                    $checkAddr = $conn->prepare("SELECT id FROM buyer_addresses WHERE buyer_id = ? AND address_line1 = ? AND city = ?");
                    $checkAddr->bind_param('iss', $buyerId, $address, $city);
                    $checkAddr->execute();
                    if ($checkAddr->get_result()->num_rows === 0) {
                        $stmtAddr = $conn->prepare("INSERT INTO buyer_addresses (buyer_id, address_line1, city, phone, is_default) VALUES (?, ?, ?, ?, 0)");
                        $stmtAddr->bind_param('isss', $buyerId, $address, $city, $phone);
                        $stmtAddr->execute();
                    }
                }
                
                $conn->commit();
                $orderSuccess = true;
                header("Location: order-confirmation.php?order_id=" . $orderId);
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                if ($e->getMessage() === 'COD_LIMIT_EXCEEDED') {
                    $orderError = 'Cash on Delivery is only available for orders under PKR 10,000 (including shipping). Please choose another payment method or reduce your order.';
                } else {
                    $orderError = 'Failed to place order. Please try again.';
                    error_log('Order placement error: ' . $e->getMessage());
                }
            }
        }
    }
}

function getImageUrl($path) {
    if (!$path) return null;
    $path = ltrim($path, './');
    if (strpos($path, 'uploads/') !== false) return $path;
    return 'uploads/commissions/' . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#F6EDDE;
  --card:#F6EDDE;
  --sand:#DDCDAE;
  --border:#0C3F30;
  --ink:#0C3F30;
  --body:#0C3F30;
  --muted:#0C3F30;
  --light:#0C3F30;
  --w:1280px; --r:10px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* NAV */
.nav{background:var(--ink);border-bottom:1px solid var(--ink);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:4px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--bg);padding:6px 10px;border-radius:6px;transition:background .12s;}
.nlinks a:hover,.nlinks a.active{background:var(--sand);color:var(--ink);}
.nsearch{display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--sand);border-radius:6px;padding:6px 12px;width:210px;flex-shrink:0;}
.nsearch input{border:none;background:transparent;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;width:100%;}
.nsearch input::placeholder{color:var(--ink);opacity:0.6;}
.nsearch svg{color:var(--ink);opacity:0.6;flex-shrink:0;}
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand);color:var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── MOBILE HAMBURGER & DRAWER GLOBAL STYLES ─── */
#nav-drawer{display:none;}
#nav-overlay{display:none;}
.ham-btn{display:none;}

/* CHECKOUT MAIN */
.main{max-width:var(--w);margin:0 auto;padding:28px;}
.checkout-title{font-family:'Playfair Display',serif;font-size:28px;font-weight:400;margin-bottom:24px;}
.checkout-layout{display:grid;grid-template-columns:1fr 400px;gap:32px;}

/* FORM SECTION */
.form-section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:24px;}
.form-section h3{font-size:16px;font-weight:600;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid var(--border);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:11px;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.form-group label span{color:var(--ink);}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;background:var(--bg);outline:none;transition:border-color .12s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--ink);}
.form-group textarea{min-height:80px;resize:vertical;}
.checkbox-group{display:flex;align-items:center;gap:8px;margin-top:8px;}
.checkbox-group input{width:16px;height:16px;margin:0;}
.checkbox-group label{text-transform:none;letter-spacing:0;font-size:12px;margin:0;color:var(--body);}

/* SAVED ADDRESSES */
.saved-addresses{margin-bottom:16px;}
.address-option{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;cursor:pointer;transition:all .12s;}
.address-option:hover{background:var(--sand);}
.address-option.selected{background:var(--sand);border-color:var(--ink);}
.address-option input{margin-top:2px;}
.address-details{flex:1;}
.address-name{font-weight:600;font-size:13px;margin-bottom:4px;}
.address-text{font-size:12px;color:var(--muted);}

/* COMMISSION BRIEF PANEL */
.commission-brief{background:var(--sand);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:24px;}
.commission-brief h3{font-size:16px;font-weight:600;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;}
.brief-row{display:flex;justify-content:space-between;margin-bottom:10px;font-size:13px;}
.brief-label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;}
.brief-value{font-weight:500;color:var(--ink);text-align:right;max-width:60%;}
.brief-desc{font-size:12.5px;color:var(--body);line-height:1.6;margin:12px 0;padding:12px;background:#fff;border-radius:8px;border:1px solid var(--border);}
.brief-ref-img{max-height:120px;border-radius:8px;margin-top:8px;border:1px solid var(--border);}

/* ORDER SUMMARY */
.summary-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;position:sticky;top:80px;}
.summary-title{font-size:16px;font-weight:600;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.order-items{margin-bottom:16px;max-height:320px;overflow-y:auto;}
.order-item{display:flex;gap:12px;align-items:center;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--border);}
.order-item:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.order-item-img{width:56px;height:56px;border-radius:8px;object-fit:cover;background:var(--sand);flex-shrink:0;}
.order-item-img-ph{width:56px;height:56px;border-radius:8px;background:var(--sand);flex-shrink:0;display:flex;align-items:center;justify-content:center;}
.order-item-details{flex:1;}
.order-item-title{font-size:13px;font-weight:500;color:var(--ink);margin-bottom:2px;}
.order-item-meta{font-size:11px;color:var(--muted);margin-bottom:4px;}
.order-item-price{font-size:13px;font-weight:600;color:var(--ink);}
.summary-row{display:flex;justify-content:space-between;margin-bottom:12px;font-size:13px;}
.summary-row.total{margin-top:12px;padding-top:12px;border-top:1px solid var(--border);font-weight:600;font-size:16px;}

/* PAYMENT METHODS */
.payment-methods{margin:16px 0;}
.payment-option{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;cursor:pointer;}
.payment-option:hover{background:var(--sand);}
.payment-option.selected{background:var(--sand);border-color:var(--ink);}
.payment-details{display:none;margin-top:8px;padding:10px;background:#fff;border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--body);}
.payment-details.active{display:block;}
.payment-details strong{display:block;margin-bottom:4px;color:var(--ink);font-size:13px;}

/* UPLOAD SECTION */
.upload-section { margin-top:12px; padding:12px; background:var(--bg); border:1px dashed var(--border); border-radius:8px; text-align:center; }
.upload-section label { font-size:12px; color:var(--ink); cursor:pointer; display:inline-flex; flex-direction:column; align-items:center; gap:8px; }
.upload-section input[type="file"] { display:none; }
.upload-icon { font-size:24px; color:var(--muted); }
#preview-container { margin-top:10px; display:none; }
#preview-image { max-width:100%; max-height:200px; border-radius:8px; border:1px solid var(--border); object-fit:contain; background:#fff; }

.place-order-btn{width:100%;background:var(--ink);color:var(--bg);border:none;padding:14px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;margin-top:16px;transition:background .15s;}
.place-order-btn:hover{background:var(--body);}
.place-order-btn:disabled{background:var(--muted);cursor:not-allowed;}
.error-msg{background:#FCEEE9;color:#7D2A14;border:1px solid #EEC5B8;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;}

/* FOOTER */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;line-height:1.65;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet (max-width: 1080px) */
@media(max-width:1080px){
  .checkout-layout{grid-template-columns:1fr;}
  .form-row{grid-template-columns:1fr;}
  .fg-foot{grid-template-columns:1fr 1fr;}
}

/* Mobile (max-width: 768px) */
@media(max-width:768px){
  /* Nav */
  .nlinks,.nsearch{display:none;}
  .nend .btn-ghost,.nend .btn-dark,.nend span{display:none;}
  .ham-btn{display:flex;flex-direction:column;justify-content:center;gap:5px;background:transparent;border:none;cursor:pointer;padding:6px;margin-left:auto;}
  .ham-btn span{display:block;width:22px;height:2px;background:var(--bg);border-radius:2px;}

  #nav-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:298;}
  #nav-overlay.open{display:block;}
  #nav-drawer{display:flex;flex-direction:column;position:fixed;top:0;right:0;width:75vw;max-width:300px;height:100vh;background:var(--ink);z-index:299;transform:translateX(100%);transition:transform 0.3s ease;padding:0;overflow-y:auto;}
  #nav-drawer.open{transform:translateX(0);}
  .drawer-top{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid rgba(246,237,222,0.1);}
  .drawer-logo b{font-family:'Playfair Display',serif;font-size:16px;color:var(--bg);display:block;}
  .drawer-logo small{font-size:7px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);}
  .drawer-close{background:transparent;border:none;color:var(--bg);font-size:18px;cursor:pointer;padding:4px;}
  .drawer-links{display:flex;flex-direction:column;padding:12px 0;}
  .drawer-links a{color:var(--bg);font-size:14px;padding:13px 20px;border-bottom:1px solid rgba(246,237,222,0.07);transition:background 0.12s;}
  .drawer-links a:hover{background:rgba(246,237,222,0.06);}
  .drawer-actions{margin-top:auto;padding:20px;display:flex;flex-direction:column;gap:10px;border-top:1px solid rgba(246,237,222,0.1);}
  .drawer-btn-ghost{font-size:13px;color:var(--bg);padding:9px 14px;border-radius:6px;border:1px solid rgba(246,237,222,0.4);text-align:center;}
  .drawer-btn-ghost:hover{border-color:var(--sand);background:rgba(246,237,222,0.08);}
  .drawer-btn-dark{font-size:13px;color:var(--ink);padding:9px 14px;border-radius:6px;background:var(--sand);text-align:center;font-weight:500;}
  .drawer-btn-dark:hover{background:#c4b69e;}

  /* Footer */
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10px;padding-top:14px;}
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nw">
    <a href="index.php" class="nlogo"><img src="logo.png" alt="Art Bazaar" style="height:36px;width:auto;display:block;"></a>
    <div class="nlinks">
      <a href="artworks.php">Explore Art</a>
      <a href="artists.php">Artists</a>
      <a href="commission.php">Commission Art</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search...">
    </div>
    <div class="nend">
      <span style="font-size:12.5px;color:var(--bg);">Welcome, <?= htmlspecialchars($buyerName) ?></span>
      <a href="logout.php" class="btn-ghost">Logout</a>

      <button class="ham-btn" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<div class="main">
  <h1 class="checkout-title"><?= $isCommissionCheckout ? 'Complete Commission Payment' : 'Checkout' ?></h1>
  
  <?php if (!empty($orderError)): ?>
    <div class="error-msg"><?= htmlspecialchars($orderError) ?></div>
  <?php endif; ?>
  
  <form method="POST" id="checkoutForm" enctype="multipart/form-data">
    <div class="checkout-layout">
      
      <!-- LEFT: COMMISSION BRIEF (if commission) + SHIPPING & BILLING -->
      <div>
        <?php if ($isCommissionCheckout && !empty($cartItems)): 
          $commissionItem = $cartItems[0];
        ?>
        <!-- Commission Brief Summary Panel -->
        <div class="commission-brief">
          <h3>
            <svg width="18" height="18" fill="none" stroke="var(--ink)" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Your Commission Brief
          </h3>
          <?php if (!empty($commissionItem['full_description'])): ?>
          <div class="brief-desc"><?= nl2br(htmlspecialchars($commissionItem['full_description'])) ?></div>
          <?php endif; ?>
          <?php if (!empty($commissionItem['cover_image'])): 
            $refImgUrl = 'uploads/commissions/' . ltrim($commissionItem['cover_image'], '/');
          ?>
          <div style="margin-bottom:12px;">
            <div class="brief-label" style="margin-bottom:4px;">Reference Image</div>
            <img src="<?= htmlspecialchars($refImgUrl) ?>" alt="Reference" class="brief-ref-img">
          </div>
          <?php endif; ?>
          <div class="brief-row">
            <span class="brief-label">Category</span>
            <span class="brief-value"><?= htmlspecialchars($commissionItem['category'] ?? 'Custom Orders') ?></span>
          </div>
          <div class="brief-row">
            <span class="brief-label">Deadline</span>
            <span class="brief-value"><?= $commissionItem['deadline'] ? date('M j, Y', strtotime($commissionItem['deadline'])) : 'Flexible' ?></span>
          </div>
          <div class="brief-row">
            <span class="brief-label">Assigned Artist</span>
            <span class="brief-value"><?= htmlspecialchars($commissionItem['artist_name']) ?></span>
          </div>
        </div>
        <?php endif; ?>
      
        <!-- Shipping Information -->
        <div class="form-section">
          <h3>Shipping Information</h3>
          
          <?php if (!empty($addresses)): ?>
          <div class="saved-addresses">
            <label style="display:block;font-size:11px;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);margin-bottom:8px;">Select saved address</label>
            <?php foreach ($addresses as $addr): ?>
            <div class="address-option" onclick="selectAddress(this, '<?= htmlspecialchars($addr['address_line1']) ?>', '<?= htmlspecialchars($addr['city']) ?>', '<?= htmlspecialchars($addr['phone']) ?>')">
              <input type="radio" name="saved_address_radio" <?= $addr['is_default'] ? 'checked' : '' ?>>
              <div class="address-details">
                <div class="address-name"><?= htmlspecialchars($buyerName) ?></div>
                <div class="address-text"><?= htmlspecialchars($addr['address_line1']) ?>, <?= htmlspecialchars($addr['city']) ?></div>
                <div class="address-text">Phone: <?= htmlspecialchars($addr['phone']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          
          <div class="form-row">
            <div class="form-group">
              <label>Full Name <span>*</span></label>
              <input type="text" name="full_name" id="fullName" value="<?= htmlspecialchars($buyerName) ?>" required>
            </div>
            <div class="form-group">
              <label>Phone Number <span>*</span></label>
              <input type="tel" name="phone" id="phone" placeholder="03XX-XXXXXXX" required>
            </div>
          </div>
          
          <div class="form-group">
            <label>Street Address <span>*</span></label>
            <input type="text" name="address" id="address" placeholder="House / Building / Street" required>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label>City <span>*</span></label>
              <input type="text" name="city" id="city" placeholder="Enter city to calculate shipping" required>
            </div>
            <div class="form-group">
              <label>Postal Code (Optional)</label>
              <input type="text" name="postal_code" placeholder="e.g. 54000">
            </div>
          </div>
          
          <div class="checkbox-group">
            <input type="checkbox" name="save_address" id="saveAddress" value="1">
            <label for="saveAddress">Save this address to my account</label>
          </div>
        </div>
        
        <!-- Order Notes -->
        <div class="form-section">
          <h3>Order Notes (Optional)</h3>
          <div class="form-group">
            <textarea name="notes" placeholder="Special instructions for delivery or notes for the artist..."></textarea>
          </div>
        </div>
      </div>
      
      <!-- RIGHT: ORDER SUMMARY -->
      <div>
        <div class="summary-card">
          <div class="summary-title">Order Summary</div>
          
          <div class="order-items">
            <?php foreach ($cartItems as $item): 
              $imgUrl = getImageUrl($item['cover_image'] ?? null);
            ?>
            <div class="order-item">
              <?php if ($imgUrl): ?>
                <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="order-item-img">
              <?php else: ?>
                <div class="order-item-img-ph">
                  <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </div>
              <?php endif; ?>
              
              <div class="order-item-details">
                <div class="order-item-title"><?= htmlspecialchars($item['title']) ?></div>
                <div class="order-item-meta">by <?= htmlspecialchars($item['artist_name'] ?? 'Art Bazaar') ?> • Qty: <?= $item['quantity'] ?></div>
                <div class="order-item-price">PKR <?= number_format($item['price'] * $item['quantity']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          
          <div class="summary-row">
            <span>Subtotal</span>
            <span>PKR <?= number_format($subtotal) ?></span>
          </div>
          
          <div class="summary-row">
            <span>Shipping</span>
            <span id="shipping-amount">PKR <?= number_format($shippingFee) ?></span>
          </div>
          
          <div class="summary-row total">
            <span>Total</span>
            <span id="total-amount">PKR <?= number_format($total) ?></span>
          </div>
          
          <div class="payment-methods">
            <div class="summary-title" style="margin-bottom:12px;padding-bottom:0;border-bottom:none;">Payment Method</div>
            
            <!-- JAZZCASH -->
            <div class="payment-option" onclick="selectPayment('jazzcash')">
              <input type="radio" name="payment_method" value="jazzcash" checked>
              <span>📱 JazzCash</span>
            </div>
            <div id="details-jazzcash" class="payment-details active">
              <strong>JazzCash Account Details:</strong>
              Account Title: ABDUR RAFEH<br>
              Account Number: 03163670633
            </div>
            
            <!-- EASYPAISA -->
            <div class="payment-option" onclick="selectPayment('easypaisa')">
              <input type="radio" name="payment_method" value="easypaisa">
              <span>📱 Easypaisa</span>
            </div>
            <div id="details-easypaisa" class="payment-details">
              <strong>Easypaisa Account Details:</strong>
              Account Title: Fatima Shahbaz<br>
              Account Number: 03210903337
            </div>
            
            <!-- NAYAPAY -->
            <div class="payment-option" onclick="selectPayment('nayapay')">
              <input type="radio" name="payment_method" value="nayapay">
              <span>💳 Nayapay</span>
            </div>
            <div id="details-nayapay" class="payment-details">
              <strong>Nayapay Account Details:</strong>
              Account Title: Fazal karim Ahsan<br>
              Account Number: 03304780888
            </div>

            <?php if (!$isCommissionCheckout && !$isDigitalItem && $subtotal <= 10000): ?>
<!-- COD -->
<div class="payment-option" onclick="selectPayment('cod')" id="cod-option">
  <input type="radio" name="payment_method" value="cod">
  <span>💵 Cash on Delivery</span>
</div>
<div id="details-cod" class="payment-details">
  <strong>Cash on Delivery</strong>
  Pay in cash when your order arrives. Only available for orders under PKR 10,000 (including shipping).
</div>
<?php endif; ?>
            
            <!-- Payment Screenshot Upload (hidden for COD) -->
            <div class="upload-section" id="upload-section">
              <label>
                <span class="upload-icon">📷</span>
                <span>Upload Payment Screenshot <span style="color:var(--ink)">*</span></span>
                <input type="file" name="payment_screenshot" id="payment_screenshot" accept="image/*" required onchange="previewImage(event)">
                <span style="font-size:10px;color:var(--muted);margin-top:4px;">JPG, PNG, WEBP (Max 2MB)</span>
              </label>
              <div id="preview-container">
                <img id="preview-image" src="" alt="Payment Preview">
              </div>
            </div>

            <?php if (!$isCommissionCheckout && !$isDigitalItem && $subtotal <= 10000): ?>
<p id="cod-warning" style="display:none;font-size:11px;color:#7D2A14;margin-top:8px;">Cash on Delivery is only available for orders under PKR 10,000 (including shipping). Please choose another payment method.</p>
<?php endif; ?>
          </div>
          
          <button type="submit" name="place_order" class="place-order-btn" id="placeOrderBtn" <?= ($shippingFee === 0 && !$isCommissionCheckout) ? 'disabled' : '' ?>><?= $isCommissionCheckout ? 'Confirm & Pay' : 'Place Order' ?></button>
          <?php if ($shippingFee === 0 && !$isCommissionCheckout): ?>
          <p style="font-size:10px;color:var(--ink);text-align:center;margin-top:8px;">Please enter your city to calculate shipping before placing the order.</p>
          <?php endif; ?>
          
          <p style="font-size:10px;color:var(--muted);text-align:center;margin-top:12px;">By placing this order, you agree to our Terms & Conditions</p>
        </div>
      </div>
      
    </div>
  </form>
</div>

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="artworks.php?featured=1">Featured</a></div>
      <div class="fc"><h4>For Artists</h4><a href="sell.php">How to Sell</a><a href="register.php">Join as Artist</a><a href="login.php">Artist Login</a></div>
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Commissions</a></div>
    </div>
    <div class="fbot"><span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span><span>Made with care in Pakistan 🇵🇰</span></div>
  </div>
</footer>

<!-- DRAWER & OVERLAY -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="drawer-top">
    <div class="drawer-logo"><img src="logo.png" alt="Art Bazaar" style="height:36px;width:auto;display:block;"></div>
    <button class="drawer-close" aria-label="Close menu">✕</button>
  </div>
  <div class="drawer-links">
    <a href="artworks.php">Explore Art</a>
    <a href="artists.php">Artists</a>
    <a href="commission.php">Commission Art</a>
    <a href="sell.php">Sell Your Art</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a>
    <a href="logout.php" class="drawer-btn-dark">Logout</a>
  </div>
</div>

<script>
// Hamburger drawer
const hamBtn = document.querySelector('.ham-btn');
const navDrawer = document.getElementById('nav-drawer');
const navOverlay = document.getElementById('nav-overlay');
function openDrawer(){ navDrawer.classList.add('open'); navOverlay.classList.add('open'); document.body.style.overflow='hidden'; }
function closeDrawer(){ navDrawer.classList.remove('open'); navOverlay.classList.remove('open'); document.body.style.overflow=''; }
if(hamBtn) hamBtn.addEventListener('click', openDrawer);
if(navOverlay) navOverlay.addEventListener('click', closeDrawer);
document.querySelector('.drawer-close')?.addEventListener('click', closeDrawer);

// Existing Page Logic
document.addEventListener('DOMContentLoaded', () => {
    const cityInput = document.getElementById('city');
    const shippingAmountEl = document.getElementById('shipping-amount');
    const totalAmountEl = document.getElementById('total-amount');
    const placeOrderBtn = document.getElementById('placeOrderBtn');
    const warningMsg = placeOrderBtn.nextElementSibling; // The <p> tag
    
    const subtotal = <?php echo $subtotal; ?>;
    const isCommission = <?php echo $isCommissionCheckout ? 'true' : 'false'; ?>;
    const isDigital = <?php echo $isDigitalItem ? 'true' : 'false'; ?>;
    const COD_LIMIT = 10000;
    
    let timeoutId;

    if (cityInput) {
        cityInput.addEventListener('input', function() {
            const city = this.value.trim();
            
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                if(city.length > 2) {
                    fetchShipping(city);
                } else {
                    // Reset if city cleared
                    shippingAmountEl.textContent = 'PKR 0';
                    totalAmountEl.textContent = 'PKR ' + subtotal.toLocaleString();
                    placeOrderBtn.disabled = true;
                    if(warningMsg) warningMsg.style.display = 'block';
                }
            }, 500);
        });
    }

    function fetchShipping(city) {
        const formData = new FormData();
        formData.append('ajax_calculate_shipping', '1');
        if (!isCommission) {
            formData.append('artwork_id', <?= (int)($cartItems[0]['id'] ?? 0) ?>);
        }
        formData.append('buyer_city', city);
        formData.append('commission_order_id', '<?php echo $commissionOrderId; ?>');

        fetch('checkout.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.shipping_fee !== undefined) {
                const newShipping = parseFloat(data.shipping_fee);
                const newTotal = subtotal + newShipping;

                shippingAmountEl.textContent = 'PKR ' + newShipping.toLocaleString();
                totalAmountEl.textContent = 'PKR ' + newTotal.toLocaleString();

                // Show per-item breakdown if available
                let breakdownEl = document.getElementById('shipping-breakdown');
                if (!breakdownEl) {
                    breakdownEl = document.createElement('p');
                    breakdownEl.id = 'shipping-breakdown';
                    breakdownEl.style.cssText = 'font-size:10px;color:var(--muted);margin-top:4px;line-height:1.6;';
                    shippingAmountEl.parentElement.parentElement.after(breakdownEl);
                }
                if (data.breakdown && data.breakdown.length > 0) {
                    breakdownEl.innerHTML = data.breakdown.map(b => '• ' + b).join('<br>');
                }

                placeOrderBtn.disabled = false;
                if (warningMsg) warningMsg.style.display = 'none';

                updateCodAvailability(newTotal);
            }
        })
        .catch(err => {
            console.error('Shipping calc error:', err);
        });
    }

    // Initialize Payment Method UI
    selectPayment('jazzcash');

    if (!isCommission) {
        updateCodAvailability(subtotal); // initial total before shipping is entered
    }

    // Initialize Address UI
    document.querySelectorAll('.address-option').forEach(opt => {
        const radio = opt.querySelector('input');
        if (radio && radio.checked) {
            opt.classList.add('selected');
            const textParts = opt.querySelector('.address-text')?.innerText.split(',') || [];
            const address = textParts[0] || '';
            const city = textParts[1]?.trim() || '';
            const phoneEl = opt.querySelector('.address-text:last-child')?.innerText.replace('Phone: ', '') || '';
            
            document.getElementById('address').value = address;
            if(city) {
                document.getElementById('city').value = city;
                if(!isCommission) fetchShipping(city);
            }
            if(phoneEl) document.getElementById('phone').value = phoneEl;
        }
    });
});

function selectPayment(method) {
  const radios = document.querySelectorAll('input[name="payment_method"]');
  const detailsDivs = document.querySelectorAll('.payment-details');
  
  radios.forEach(radio => {
    radio.checked = (radio.value === method);
    radio.closest('.payment-option')?.classList.toggle('selected', radio.checked);
  });

  detailsDivs.forEach(div => div.classList.remove('active'));
  
  const activeDiv = document.getElementById('details-' + method);
  if (activeDiv) {
    activeDiv.classList.add('active');
  }

  const uploadSection = document.getElementById('upload-section');
  const screenshotInput = document.getElementById('payment_screenshot');
  const isCod = (method === 'cod');

  if (uploadSection) uploadSection.style.display = isCod ? 'none' : 'block';
  if (screenshotInput) screenshotInput.required = !isCod;
}

function updateCodAvailability(currentTotal) {
  const codOption = document.getElementById('cod-option');
  const codWarning = document.getElementById('cod-warning');
  if (!codOption) return; // commission checkout has no COD option

  if (isDigital) {
    codOption.style.display = 'none';
    const codRadioDigital = codOption.querySelector('input[name="payment_method"]');
    if (codRadioDigital) codRadioDigital.disabled = true;
    if (codRadioDigital && codRadioDigital.checked) selectPayment('jazzcash');
    if (codWarning) codWarning.style.display = 'none';
    return;
  }

  const codRadio = codOption.querySelector('input[name="payment_method"]');
  const overLimit = currentTotal > COD_LIMIT;

  codOption.style.opacity = overLimit ? '0.5' : '1';
  codOption.style.pointerEvents = overLimit ? 'none' : 'auto';
  if (codRadio) codRadio.disabled = overLimit;

  if (overLimit && codRadio && codRadio.checked) {
    selectPayment('jazzcash'); // bump back to a valid method if total grew past the cap
  }

  if (codWarning) codWarning.style.display = overLimit ? 'block' : 'none';
}

function previewImage(event) {
  const container = document.getElementById('preview-container');
  const preview = document.getElementById('preview-image');
  const file = event.target.files[0];

  if (file) {
    const reader = new FileReader();
    reader.onload = function(e) {
      preview.src = e.target.result;
      container.style.display = 'block';
    }
    reader.readAsDataURL(file);
  } else {
    container.style.display = 'none';
  }
}

function selectAddress(element, address, city, phone) {
  document.querySelectorAll('.address-option').forEach(opt => opt.classList.remove('selected'));
  element.classList.add('selected');
  
  document.getElementById('address').value = address;
  document.getElementById('city').value = city;
  document.getElementById('phone').value = phone;
  
  // Trigger calculation update
  const event = new Event('input', { bubbles: true });
  document.getElementById('city').dispatchEvent(event);
}
</script>
</body>
</html>