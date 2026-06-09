<?php
// ============================================================
// ART BAZAAR - BUYER FACING HOMEPAGE WITH ORDER SYSTEM TESTING
// ============================================================

session_start();
require_once __DIR__ . '/config/db.php';

// --- Determine if user is logged in as buyer ---
 $isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer';
 $currentUser = $isLoggedIn ? $_SESSION['name'] : null;

// --- Handle Contact to Buy Form Submission ---
 $inquirySuccess = false;
 $inquiryError = false;
 $inquiryId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'contact_buy') {
    $artworkId = (int) ($_POST['artwork_id'] ?? 0);
    $buyerName = trim($_POST['buyer_name'] ?? '');
    $buyerEmail = trim($_POST['buyer_email'] ?? '');
    $buyerPhone = trim($_POST['buyer_phone'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($buyerName) || empty($buyerEmail)) {
        $inquiryError = "Name and email are required.";
    } else {
        $stmt = $conn->prepare("INSERT INTO buyer_inquiries (artwork_id, buyer_name, buyer_email, buyer_phone, message, status) VALUES (?, ?, ?, ?, ?, 'new')");
        $stmt->bind_param("issss", $artworkId, $buyerName, $buyerEmail, $buyerPhone, $message);
        if ($stmt->execute()) {
            $inquirySuccess = true;
            $inquiryId = $stmt->insert_id;
        } else {
            $inquiryError = "Failed to submit inquiry. Please try again.";
        }
        $stmt->close();
    }
}

// --- Handle Commission Request Form Submission ---
 $commissionSuccess = false;
 $commissionError = false;
 $commissionId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commission_request') {
    $buyerName = trim($_POST['commission_name'] ?? '');
    $buyerEmail = trim($_POST['commission_email'] ?? '');
    $buyerPhone = trim($_POST['commission_phone'] ?? '');
    $requestedArtistId = !empty($_POST['requested_artist_id']) ? (int) $_POST['requested_artist_id'] : null;
    $artworkType = trim($_POST['artwork_type'] ?? '');
    $budgetMin = !empty($_POST['budget_min']) ? (int) $_POST['budget_min'] : null;
    $budgetMax = !empty($_POST['budget_max']) ? (int) $_POST['budget_max'] : null;
    $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $description = trim($_POST['description'] ?? '');
    
    // Handle reference image upload
    $referenceImage = null;
    if (isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['reference_image'];
        $maxSize = 2 * 1024 * 1024;
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file['size'] <= $maxSize && in_array($ext, $allowedExt)) {
            $uploadDir = __DIR__ . '/uploads/commissions/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = 'ref_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                $referenceImage = $filename;
            } else {
                $commissionError = "Failed to upload reference image.";
            }
        } else {
            $commissionError = "Invalid image file. Max size 2MB, allowed: JPG, PNG, WEBP, GIF";
        }
    }
    
    if (empty($buyerName) || empty($buyerEmail) || empty($description)) {
        $commissionError = "Name, email, and description are required.";
    }
    
    if (!$commissionError) {
        $stmt = $conn->prepare("INSERT INTO commission_requests (buyer_name, buyer_email, buyer_phone, requested_artist_id, artwork_type, budget_min, budget_max, deadline, description, reference_image, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())");
        $stmt->bind_param("sssissssss", $buyerName, $buyerEmail, $buyerPhone, $requestedArtistId, $artworkType, $budgetMin, $budgetMax, $deadline, $description, $referenceImage);
        if ($stmt->execute()) {
            $commissionSuccess = true;
            $commissionId = $stmt->insert_id;
            $_POST = array();
        } else {
            $commissionError = "Failed to submit commission request. Please try again.";
        }
        $stmt->close();
    }
}

// --- NEW: Handle Full Checkout Submission for Artworks ---
 $orderSuccess = false;
 $orderError = false;
 $orderNumber = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout_artwork') {
    $artworkId = (int) ($_POST['artwork_id'] ?? 0);
    $buyerName = trim($_POST['order_name'] ?? '');
    $buyerEmail = trim($_POST['order_email'] ?? '');
    $buyerPhone = trim($_POST['order_phone'] ?? '');
    $shippingAddress = trim($_POST['shipping_address'] ?? '');
    $shippingCity = trim($_POST['shipping_city'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? 'cod');
    $buyerNotes = trim($_POST['buyer_notes'] ?? '');
    
    if (empty($buyerName) || empty($buyerEmail) || empty($buyerPhone) || empty($shippingAddress) || empty($shippingCity)) {
        $orderError = "All shipping and payment fields are required.";
    } else {
        // Fetch artwork price and artist
        $artStmt = $conn->prepare("SELECT price, artist_id FROM artworks WHERE id = ?");
        $artStmt->bind_param("i", $artworkId);
        $artStmt->execute();
        $artRes = $artStmt->get_result()->fetch_assoc();
        $artStmt->close();
        
        if ($artRes) {
            $price = $artRes['price'];
            $artistId = $artRes['artist_id'];
            $shippingFee = 250; // Flat rate test fee
            $total = $price + $shippingFee;
            $orderNum = 'ORD-' . strtoupper(uniqid());
            
            // Create test buyer or fetch existing
            $buyerId = null;
            $bStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $bStmt->bind_param("s", $buyerEmail);
            $bStmt->execute();
            $bRes = $bStmt->get_result();
            if ($bRes->num_rows > 0) {
                $buyerId = $bRes->fetch_assoc()['id'];
            } else {
                // Auto-register test buyer
                $dummyHash = password_hash('Test@123', PASSWORD_DEFAULT);
                $insBuyer = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, 'buyer', 'active')");
                $insBuyer->bind_param("ssss", $buyerName, $buyerEmail, $buyerPhone, $dummyHash);
                $insBuyer->execute();
                $buyerId = $insBuyer->insert_id;
                $insBuyer->close();
            }
            $bStmt->close();
            
            $conn->begin_transaction();
            try {
                // 1. Insert Order
                $oStmt = $conn->prepare("INSERT INTO orders (buyer_id, order_number, order_status, subtotal, shipping_fee, total, payment_method, payment_status, shipping_address, shipping_city, shipping_phone, buyer_notes) VALUES (?, ?, 'pending', ?, ?, ?, ?, 'pending', ?, ?, ?, ?)");
                $oStmt->bind_param("isdddssssss", $buyerId, $orderNum, $price, $shippingFee, $total, $paymentMethod, $shippingAddress, $shippingCity, $buyerPhone, $buyerNotes);
                $oStmt->execute();
                $orderId = $oStmt->insert_id;
                $oStmt->close();
                
                // 2. Insert Order Item
                $iStmt = $conn->prepare("INSERT INTO order_items (order_id, item_type, item_id, quantity, price, item_status) VALUES (?, 'artwork', ?, 1, ?, 'pending')");
                $iStmt->bind_param("iid", $orderId, $artworkId, $price);
                $iStmt->execute();
                $iStmt->close();
                
                // 3. Insert Status History
                $hStmt = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, notes, changed_by_role, changed_by_id) VALUES (?, NULL, 'pending', 'Order placed', 'buyer', ?)");
                $hStmt->bind_param("ii", $orderId, $buyerId);
                $hStmt->execute();
                $hStmt->close();
                
                // 4. Update Artwork Status
                $uStmt = $conn->prepare("UPDATE artworks SET status = 'sold' WHERE id = ?");
                $uStmt->bind_param("i", $artworkId);
                $uStmt->execute();
                $uStmt->close();
                
                $conn->commit();
                $orderSuccess = true;
                $orderNumber = $orderNum;
                
            } catch (Exception $e) {
                $conn->rollback();
                $orderError = "Order failed: " . $e->getMessage();
            }
        } else {
            $orderError = "Artwork not found.";
        }
    }
}

// --- NEW: Handle Full Checkout Submission for Commission Orders ---
 $commOrderSuccess = false;
 $commOrderError = false;
 $commOrderNumber = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout_commission') {
    $commissionId = (int) ($_POST['commission_id'] ?? 0);
    $buyerName = trim($_POST['comm_order_name'] ?? '');
    $buyerEmail = trim($_POST['comm_order_email'] ?? '');
    $buyerPhone = trim($_POST['comm_order_phone'] ?? '');
    $shippingAddress = trim($_POST['comm_shipping_address'] ?? '');
    $shippingCity = trim($_POST['comm_shipping_city'] ?? '');
    $paymentMethod = trim($_POST['comm_payment_method'] ?? 'cod');
    $buyerNotes = trim($_POST['comm_buyer_notes'] ?? '');
    $agreedPrice = !empty($_POST['agreed_price']) ? (float) $_POST['agreed_price'] : 0;
    
    if (empty($buyerName) || empty($buyerEmail) || empty($buyerPhone) || empty($shippingAddress) || empty($shippingCity) || $agreedPrice <= 0) {
        $commOrderError = "All fields including a valid Agreed Price are required.";
    } else {
        // Fetch commission
        $cStmt = $conn->prepare("SELECT requested_artist_id FROM commission_requests WHERE id = ?");
        $cStmt->bind_param("i", $commissionId);
        $cStmt->execute();
        $cRes = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();
        
        if ($cRes) {
            $artistId = $cRes['requested_artist_id'];
            $shippingFee = 0; // Usually included or free for custom
            $total = $agreedPrice + $shippingFee;
            $orderNum = 'COMM-' . strtoupper(uniqid());
            
            $buyerId = null;
            $bStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $bStmt->bind_param("s", $buyerEmail);
            $bStmt->execute();
            $bRes = $bStmt->get_result();
            if ($bRes->num_rows > 0) {
                $buyerId = $bRes->fetch_assoc()['id'];
            } else {
                $dummyHash = password_hash('Test@123', PASSWORD_DEFAULT);
                $insBuyer = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, 'buyer', 'active')");
                $insBuyer->bind_param("ssss", $buyerName, $buyerEmail, $buyerPhone, $dummyHash);
                $insBuyer->execute();
                $buyerId = $insBuyer->insert_id;
                $insBuyer->close();
            }
            $bStmt->close();
            
            $conn->begin_transaction();
            try {
                // 1. Insert Order
                $oStmt = $conn->prepare("INSERT INTO orders (buyer_id, order_number, order_status, subtotal, shipping_fee, total, payment_method, payment_status, shipping_address, shipping_city, shipping_phone, buyer_notes) VALUES (?, ?, 'pending', ?, ?, ?, ?, 'pending', ?, ?, ?, ?)");
                $oStmt->bind_param("isdddssssss", $buyerId, $orderNum, $agreedPrice, $shippingFee, $total, $paymentMethod, $shippingAddress, $shippingCity, $buyerPhone, $buyerNotes);
                $oStmt->execute();
                $orderId = $oStmt->insert_id;
                $oStmt->close();
                
                // 2. Insert Order Item
                $iStmt = $conn->prepare("INSERT INTO order_items (order_id, item_type, item_id, quantity, price, item_status) VALUES (?, 'commission', ?, 1, ?, 'pending')");
                $iStmt->bind_param("iid", $orderId, $commissionId, $agreedPrice);
                $iStmt->execute();
                $iStmt->close();
                
                // 3. Insert Status History
                $hStmt = $conn->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, notes, changed_by_role, changed_by_id) VALUES (?, NULL, 'pending', 'Commission order placed', 'buyer', ?)");
                $hStmt->bind_param("ii", $orderId, $buyerId);
                $hStmt->execute();
                $hStmt->close();
                
                // 4. Update Commission Request with Order ID, Agreed Price, and Delivery Status
                $uStmt = $conn->prepare("UPDATE commission_requests SET order_id = ?, agreed_price = ?, delivery_status = 'deposit_paid' WHERE id = ?");
                $uStmt->bind_param("idi", $orderId, $agreedPrice, $commissionId);
                $uStmt->execute();
                $uStmt->close();
                
                $conn->commit();
                $commOrderSuccess = true;
                $commOrderNumber = $orderNum;
                
            } catch (Exception $e) {
                $conn->rollback();
                $commOrderError = "Order failed: " . $e->getMessage();
            }
        } else {
            $commOrderError = "Commission request not found.";
        }
    }
}

// --- Fetch active artists who accept commissions (for the dropdown) ---
 $availableArtists = [];
 $artistResult = $conn->query("
    SELECT u.id, u.name, ap.city, ap.art_style
    FROM users u
    JOIN artist_profiles ap ON u.id = ap.user_id
    WHERE u.role = 'artist' AND u.status = 'active' AND ap.accepts_commissions = 1
    ORDER BY u.name ASC
");
while ($row = $artistResult->fetch_assoc()) {
    $availableArtists[] = $row;
}

// Helper function to get correct image path for artwork images
function getArtworkImageUrl($imagePath) {
    if (empty($imagePath)) return null;
    $imagePath = ltrim($imagePath, './');
    if (strpos($imagePath, 'uploads/') === 0) return $imagePath;
    if (strpos($imagePath, 'uploads/') !== false) return $imagePath;
    return 'uploads/artworks/' . $imagePath;
}

// Helper function to get correct profile image path for artists
function getArtistProfileImageUrl($imagePath) {
    if (empty($imagePath)) return null;
    $imagePath = ltrim($imagePath, './');
    if (strpos($imagePath, 'uploads/') === 0) return $imagePath;
    if (strpos($imagePath, 'uploads/') !== false) return $imagePath;
    return 'uploads/profiles/' . $imagePath;
}

// --- Fetch Approved Artworks for Display ---
 $artworks = [];
 $result = $conn->query("
    SELECT a.*, 
           u.name AS artist_name, u.id AS artist_id,
           c.name AS category_name,
           (SELECT image_path FROM artwork_images WHERE artwork_id = a.id ORDER BY is_cover DESC, sort_order ASC LIMIT 1) AS cover_image
    FROM artworks a
    JOIN users u ON a.artist_id = u.id
    JOIN categories c ON a.category_id = c.id
    WHERE a.status = 'approved'
    ORDER BY a.created_at DESC
    LIMIT 12
");

while ($row = $result->fetch_assoc()) {
    $artworks[] = $row;
}

// --- Fetch Featured Artists ---
 $featuredArtists = [];
 $result = $conn->query("
    SELECT u.id, u.name, u.profile_picture, ap.city, ap.art_style, ap.is_featured,
           ap.accepts_commissions
    FROM users u
    JOIN artist_profiles ap ON u.id = ap.user_id
    WHERE u.role = 'artist' AND u.status = 'active' AND ap.is_featured = 1
    ORDER BY u.created_at DESC
    LIMIT 6
");

while ($row = $result->fetch_assoc()) {
    $featuredArtists[] = $row;
}

// --- Fetch Categories ---
 $categories = [];
 $result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

// --- Fetch recent test commissions for checkout ---
 $recentCommissions = [];
 $commRes = $conn->query("SELECT id, buyer_name, artwork_type, budget_max FROM commission_requests ORDER BY created_at DESC LIMIT 5");
while ($row = $commRes->fetch_assoc()) {
    $recentCommissions[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Art Bazaar — Buy Original Art in Pakistan</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --black: #0a0a0a; --grey1: #f7f7f7; --grey2: #efefef; --grey3: #d8d8d8;
            --grey4: #999; --grey5: #555; --white: #ffffff; --red: #d63031;
            --green: #00b894; --amber: #e17055; --blue: #0984e3;
            --shadow: 0 4px 20px rgba(0,0,0,0.05); --shadow-hover: 0 8px 30px rgba(0,0,0,0.1);
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--white); color: var(--black); line-height: 1.6; }
        h1, h2, h3, h4, .logo { font-family: 'Playfair Display', serif; }
        .container { max-width: 1280px; margin: 0 auto; padding: 0 24px; }

        .header { background: var(--white); border-bottom: 1px solid var(--grey2); position: sticky; top: 0; z-index: 100; }
        .nav { display: flex; align-items: center; justify-content: space-between; padding: 16px 0; flex-wrap: wrap; gap: 16px; }
        .logo h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.5px; }
        .logo span { font-size: 10px; letter-spacing: 2px; color: var(--grey4); display: block; }
        .nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        .nav-links a { text-decoration: none; color: var(--grey5); font-size: 14px; font-weight: 500; transition: color 0.2s; }
        .nav-links a:hover, .nav-links a.active { color: var(--black); }

        .btn-outline { padding: 8px 20px; border: 1.5px solid var(--grey3); border-radius: 30px; background: transparent; font-weight: 500; font-size: 13px; cursor: pointer; transition: all 0.2s; text-decoration: none; color: var(--black); display: inline-block; }
        .btn-outline:hover { border-color: var(--black); background: var(--grey1); }
        .btn-primary { background: var(--black); color: var(--white); padding: 10px 24px; border-radius: 30px; text-decoration: none; font-size: 14px; font-weight: 500; display: inline-block; transition: background 0.2s; border: none; cursor: pointer; }
        .btn-primary:hover { background: #222; }
        .btn-secondary { background: transparent; border: 1.5px solid var(--black); color: var(--black); padding: 10px 24px; border-radius: 30px; text-decoration: none; font-size: 14px; font-weight: 500; display: inline-block; transition: all 0.2s; cursor: pointer; }
        .btn-secondary:hover { background: var(--black); color: var(--white); }

        .hero { background: var(--grey1); padding: 60px 0; margin-bottom: 48px; }
        .hero-content { display: flex; align-items: center; justify-content: space-between; gap: 48px; flex-wrap: wrap; }
        .hero-text { flex: 1; }
        .hero-text h1 { font-size: 48px; font-weight: 500; line-height: 1.2; margin-bottom: 20px; }
        .hero-text p { font-size: 16px; color: var(--grey5); margin-bottom: 32px; max-width: 500px; }
        .hero-buttons { display: flex; gap: 16px; flex-wrap: wrap; }
        .hero-image { flex: 1; text-align: center; }
        .hero-image img { max-width: 100%; border-radius: 20px; box-shadow: var(--shadow); }

        .section-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
        .section-header h2 { font-size: 28px; font-weight: 500; }
        .section-header a { color: var(--grey5); text-decoration: none; font-size: 14px; border-bottom: 1px solid var(--grey3); }
        .section-header a:hover { color: var(--black); }

        .artwork-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 28px; margin-bottom: 60px; }
        .artwork-card { background: var(--white); border-radius: 16px; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; border: 1px solid var(--grey2); cursor: pointer; }
        .artwork-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
        .artwork-image { width: 100%; aspect-ratio: 1; object-fit: cover; background: var(--grey1); }
        .artwork-info { padding: 16px; }
        .artwork-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; color: var(--black); }
        .artwork-artist { font-size: 13px; color: var(--grey5); margin-bottom: 8px; }
        .artwork-price { font-size: 16px; font-weight: 600; color: var(--black); }
        .artwork-category { font-size: 11px; color: var(--grey4); text-transform: uppercase; letter-spacing: 1px; margin-top: 6px; }

        .artist-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 28px; margin-bottom: 60px; }
        .artist-card { text-align: center; background: var(--white); border-radius: 20px; padding: 24px 16px; border: 1px solid var(--grey2); transition: all 0.2s; text-decoration: none; color: inherit; display: block; }
        .artist-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
        .artist-avatar { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin: 0 auto 16px; background: var(--grey2); }
        .artist-avatar-placeholder { width: 100px; height: 100px; border-radius: 50%; background: var(--black); margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 36px; color: white; font-weight: 500; }
        .artist-name { font-size: 16px; font-weight: 600; margin-bottom: 4px; }
        .artist-city { font-size: 12px; color: var(--grey4); }
        .commission-badge { display: inline-block; margin-top: 8px; font-size: 10px; background: var(--green); color: white; padding: 3px 10px; border-radius: 20px; }
        .artist-buttons { display: flex; gap: 8px; justify-content: center; margin-top: 12px; }
        .artist-btn { padding: 6px 12px; font-size: 11px; border-radius: 20px; text-decoration: none; cursor: pointer; transition: all 0.2s; font-weight: 500; }
        .artist-btn-profile { background: transparent; border: 1px solid var(--grey3); color: var(--grey5); }
        .artist-btn-profile:hover { border-color: var(--black); color: var(--black); }
        .artist-btn-commission { background: var(--black); border: 1px solid var(--black); color: white; }
        .artist-btn-commission:hover { background: #333; }

        .categories-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 60px; }
        .category-tag { background: var(--grey1); padding: 10px 24px; border-radius: 40px; text-decoration: none; color: var(--grey5); font-size: 14px; transition: all 0.2s; border: 1px solid var(--grey2); }
        .category-tag:hover { background: var(--black); color: var(--white); border-color: var(--black); }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: all 0.2s; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal { background: var(--white); max-width: 90vw; width: 550px; max-height: 90vh; overflow-y: auto; border-radius: 20px; position: relative; transform: translateY(20px); transition: transform 0.2s; }
        .modal-overlay.active .modal { transform: translateY(0); }
        .modal-header { padding: 24px 28px 16px; border-bottom: 1px solid var(--grey2); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 500; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--grey4); }
        .modal-close:hover { color: var(--black); }
        .modal-body { padding: 24px 28px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--grey5); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--grey3); border-radius: 10px; font-size: 14px; font-family: inherit; }
        .form-group select { cursor: pointer; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--black); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .modal-footer { padding: 16px 28px 28px; display: flex; justify-content: flex-end; gap: 12px; }
        .success-message { background: #e6fff3; color: #00875a; padding: 12px 20px; border-radius: 10px; margin-bottom: 24px; }
        .error-message { background: #fff0f0; color: #c0392b; padding: 12px 20px; border-radius: 10px; margin-bottom: 24px; }

        .test-checkout-section { margin: 40px 0; padding: 24px; background: var(--grey1); border-radius: 20px; border: 1px solid var(--grey2); }
        .test-checkout-section h3 { font-family: 'Playfair Display', serif; font-size: 20px; margin-bottom: 8px; }
        .test-checkout-section .sub { font-size: 12px; color: var(--grey4); margin-bottom: 20px; }
        .checkout-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .checkout-card { background: white; border-radius: 16px; padding: 20px; border: 1px solid var(--grey2); }
        .checkout-card h4 { font-size: 16px; margin-bottom: 12px; font-family: 'Playfair Display', serif; }

        .footer { background: var(--black); color: var(--grey4); padding: 48px 0 24px; margin-top: 60px; }
        .footer-content { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 32px; margin-bottom: 40px; }
        .footer-section h4 { color: var(--white); margin-bottom: 16px; font-size: 16px; }
        .footer-section p, .footer-section a { color: var(--grey4); text-decoration: none; font-size: 13px; line-height: 1.8; }
        .footer-section a:hover { color: var(--white); }
        .footer-bottom { text-align: center; padding-top: 24px; border-top: 1px solid #222; font-size: 12px; }

        @media (max-width: 768px) {
            .hero-text h1 { font-size: 32px; }
            .nav-links { display: none; }
            .container { padding: 0 16px; }
            .form-row { grid-template-columns: 1fr; }
            .artist-buttons { flex-direction: column; align-items: center; }
            .checkout-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="header">
    <div class="container">
        <div class="nav">
            <div class="logo">
                <h1>ART BAZAAR</h1>
                <span>PAKISTAN'S ART MARKETPLACE</span>
            </div>
            <div class="nav-links">
                <a href="index.php" class="active">Home</a>
                <a href="artworks.php">Artworks</a>
                <a href="artists.php">Artists</a>
                <a href="commission.php">Commission Art</a>
                <a href="sell.php">Sell Your Art</a>
                <a href="about.php">About</a>
                <a href="contact.php">Contact</a>
                <?php if ($isLoggedIn): ?>
                    <a href="dashboard/buyer/">My Account</a>
                <?php else: ?>
                    <a href="login.php">Sign In</a>
                <?php endif; ?>
                <a href="register.php" class="btn-outline">Join as Artist</a>
            </div>
        </div>
    </div>
</header>

<section class="hero">
    <div class="container">
        <div class="hero-content">
            <div class="hero-text">
                <h1>Original Art from Pakistan's Finest Artists</h1>
                <p>Discover unique paintings, digital art, calligraphy, and more. Directly from artists to your home.</p>
                <div class="hero-buttons">
                    <a href="artworks.php" class="btn-primary">Explore Artworks</a>
                    <button onclick="openCommissionModal(null, null)" class="btn-secondary">Request Custom Art</button>
                </div>
            </div>
            <div class="hero-image">
                <img src="https://images.unsplash.com/photo-1579783902614-a3fb3927b6a5?w=600&h=400&fit=crop" alt="Art collection">
            </div>
        </div>
    </div>
</section>

<div class="container">
    <?php if ($inquirySuccess): ?>
        <div class="success-message"><i class="fas fa-check-circle"></i> Your inquiry #<?= $inquiryId ?> has been submitted! The admin will contact you shortly.</div>
    <?php endif; ?>
    <?php if ($inquiryError): ?>
        <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($inquiryError) ?></div>
    <?php endif; ?>
    
    <?php if ($commissionSuccess): ?>
        <div class="success-message"><i class="fas fa-check-circle"></i> Your commission request #<?= $commissionId ?> has been submitted! Proceed to checkout below or wait for admin review.</div>
    <?php endif; ?>
    <?php if ($commissionError): ?>
        <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($commissionError) ?></div>
    <?php endif; ?>

    <?php if ($orderSuccess): ?>
        <div class="success-message"><i class="fas fa-check-circle"></i> Artwork Order placed successfully! Order Number: <strong><?= htmlspecialchars($orderNumber) ?></strong>. You can test this order in the Admin/Artist panels now.</div>
    <?php endif; ?>
    <?php if ($orderError): ?>
        <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($orderError) ?></div>
    <?php endif; ?>

    <?php if ($commOrderSuccess): ?>
        <div class="success-message"><i class="fas fa-check-circle"></i> Commission Order placed successfully! Order Number: <strong><?= htmlspecialchars($commOrderNumber) ?></strong>. You can test this order in the Admin/Artist panels now.</div>
    <?php endif; ?>
    <?php if ($commOrderError): ?>
        <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($commOrderError) ?></div>
    <?php endif; ?>

    <div class="section-header">
        <h2>Browse by Category</h2>
        <a href="artworks.php">View All →</a>
    </div>
    <div class="categories-grid">
        <?php foreach (array_slice($categories, 0, 8) as $cat): ?>
            <a href="artworks.php?category=<?= $cat['id'] ?>" class="category-tag"><?= htmlspecialchars($cat['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="section-header">
        <h2>Latest Artworks</h2>
        <a href="artworks.php">Browse All →</a>
    </div>
    <div class="artwork-grid">
        <?php if (empty($artworks)): ?>
            <p>No artworks available yet. Check back soon!</p>
        <?php else: ?>
            <?php foreach ($artworks as $art): ?>
                <?php $imageUrl = getArtworkImageUrl($art['cover_image'] ?? ''); ?>
                <div class="artwork-card" onclick="openArtworkModal(
                    <?= $art['id'] ?>, 
                    '<?= htmlspecialchars(addslashes($art['title'])) ?>', 
                    '<?= htmlspecialchars(addslashes($art['artist_name'])) ?>', 
                    <?= $art['artist_id'] ?>,
                    <?= $art['price'] ?>, 
                    '<?= htmlspecialchars(addslashes($art['description'] ?? '')) ?>', 
                    '<?= addslashes($imageUrl) ?>', 
                    '<?= htmlspecialchars(addslashes($art['category_name'] ?? '')) ?>', 
                    '<?= htmlspecialchars(addslashes($art['city'] ?? '')) ?>', 
                    <?= $art['delivery_available'] ? 'true' : 'false' ?>, 
                    <?= $art['similar_work_available'] ? 'true' : 'false' ?>, 
                    '<?= htmlspecialchars(addslashes($art['delivery_status'] ?? 'not_applicable')) ?>'
                )">
                    <img class="artwork-image" src="<?= $imageUrl ?: 'https://placehold.co/400x400/efefef/999?text=No+Image' ?>" alt="<?= htmlspecialchars($art['title']) ?>">
                    <div class="artwork-info">
                        <div class="artwork-title"><?= htmlspecialchars($art['title']) ?></div>
                        <div class="artwork-artist">by <?= htmlspecialchars($art['artist_name']) ?></div>
                        <div class="artwork-price">PKR <?= number_format($art['price']) ?></div>
                        <div class="artwork-category"><?= htmlspecialchars($art['category_name'] ?? '') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="section-header">
        <h2>Featured Artists</h2>
        <a href="artists.php">View All →</a>
    </div>
    <div class="artist-grid">
        <?php if (empty($featuredArtists)): ?>
            <p>No featured artists yet.</p>
        <?php else: ?>
            <?php foreach ($featuredArtists as $artist): 
                $profileImageUrl = getArtistProfileImageUrl($artist['profile_picture'] ?? '');
            ?>
                <div class="artist-card">
                    <?php if ($profileImageUrl): ?>
                        <img class="artist-avatar" src="<?= htmlspecialchars($profileImageUrl) ?>" alt="<?= htmlspecialchars($artist['name']) ?>">
                    <?php else: ?>
                        <div class="artist-avatar-placeholder"><?= strtoupper(substr($artist['name'], 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="artist-name"><?= htmlspecialchars($artist['name']) ?></div>
                    <div class="artist-city"><?= htmlspecialchars($artist['city'] ?? 'Pakistan') ?></div>
                    <?php if ($artist['accepts_commissions'] ?? 0): ?>
                        <div class="commission-badge">✓ Accepts Commissions</div>
                    <?php endif; ?>
                    <div class="artist-buttons">
                        <a href="artist-profile.php?id=<?= $artist['id'] ?>" class="artist-btn artist-btn-profile">View Profile</a>
                        <button onclick="openCommissionModal(<?= $artist['id'] ?>, '<?= htmlspecialchars(addslashes($artist['name'])) ?>')" class="artist-btn artist-btn-commission">Commission Me</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TEST CHECKOUT SECTION -->
    <div class="test-checkout-section">
        <h3>🛒 Test Order Checkout</h3>
        <p class="sub">Use these forms to generate full orders directly into the database. This allows you to test order management, tracking, and chat in the Admin & Artist panels.</p>
        
        <div class="checkout-grid">
            <!-- Artwork Checkout -->
            <div class="checkout-card">
                <h4>🖼️ Place Artwork Order</h4>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="checkout_artwork">
                    <div class="form-group">
                        <label>Select Artwork *</label>
                        <select name="artwork_id" required>
                            <option value="">-- Choose an Artwork --</option>
                            <?php foreach ($artworks as $art): ?>
                                <option value="<?= $art['id'] ?>"><?= htmlspecialchars($art['title']) ?> (PKR <?= number_format($art['price']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Your Name *</label>
                            <input type="text" name="order_name" required placeholder="Full Name">
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="order_email" required placeholder="you@example.com">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="text" name="order_phone" required placeholder="03XX-XXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>Shipping Address *</label>
                        <textarea name="shipping_address" required placeholder="Street, House #, Area..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>City *</label>
                            <input type="text" name="shipping_city" required placeholder="Lahore">
                        </div>
                        <div class="form-group">
                            <label>Payment Method *</label>
                            <select name="payment_method" required>
                                <option value="cod">Cash on Delivery</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="easypaisa">Easypaisa</option>
                                <option value="jazzcash">JazzCash</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="buyer_notes" rows="2" placeholder="Any special instructions..."></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="width: 100%;">Place Artwork Order</button>
                </form>
            </div>

            <!-- Commission Checkout -->
            <div class="checkout-card">
                <h4>🎨 Place Commission Order</h4>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="checkout_commission">
                    <div class="form-group">
                        <label>Select Commission Request *</label>
                        <select name="commission_id" required>
                            <option value="">-- Choose a Commission --</option>
                            <?php foreach ($recentCommissions as $c): ?>
                                <option value="<?= $c['id'] ?>">#<?= $c['id'] ?> - <?= htmlspecialchars($c['buyer_name']) ?> (<?= htmlspecialchars($c['artwork_type'] ?? 'N/A') ?>) [Max: PKR <?= number_format($c['budget_max'] ?? 0) ?>]</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Agreed Price (PKR) *</label>
                        <input type="number" name="agreed_price" required placeholder="Final agreed price" min="1">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Your Name *</label>
                            <input type="text" name="comm_order_name" required placeholder="Full Name">
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="comm_order_email" required placeholder="you@example.com">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="text" name="comm_order_phone" required placeholder="03XX-XXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>Shipping Address *</label>
                        <textarea name="comm_shipping_address" required placeholder="Street, House #, Area..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>City *</label>
                            <input type="text" name="comm_shipping_city" required placeholder="Karachi">
                        </div>
                        <div class="form-group">
                            <label>Payment Method *</label>
                            <select name="comm_payment_method" required>
                                <option value="cod">Cash on Delivery</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="easypaisa">Easypaisa</option>
                                <option value="jazzcash">JazzCash</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="comm_buyer_notes" rows="2" placeholder="Any details for the artist..."></textarea>
                    </div>
                    <button type="submit" class="btn-primary" style="width: 100%;">Place Commission Order</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Artwork Detail Modal -->
<div class="modal-overlay" id="artworkModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Artwork Details</h3>
            <button class="modal-close" onclick="closeModal('artworkModal')">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<!-- Commission Request Modal -->
<div class="modal-overlay" id="commissionModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Request Custom Artwork</h3>
            <button class="modal-close" onclick="closeModal('commissionModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 20px; font-size: 13px; color: var(--grey5);">Tell us what you're looking for! Choose a specific artist or leave it open for us to find the best match.</p>
            <form method="POST" enctype="multipart/form-data" id="commissionForm">
                <input type="hidden" name="action" value="commission_request">
                <div class="form-group">
                    <label>Your Name *</label>
                    <input type="text" name="commission_name" required placeholder="Enter your full name">
                </div>
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="commission_email" required placeholder="you@example.com">
                </div>
                <div class="form-group">
                    <label>Phone / WhatsApp</label>
                    <input type="text" name="commission_phone" placeholder="03XX-XXXXXXX">
                </div>
                <div class="form-group">
                    <label>Choose an Artist (Optional)</label>
                    <select name="requested_artist_id" id="commissionArtistSelect">
                        <option value="">-- Any artist (we'll find the best match) --</option>
                        <?php foreach ($availableArtists as $artist): ?>
                            <option value="<?= $artist['id'] ?>">
                                <?= htmlspecialchars($artist['name']) ?> 
                                <?php if ($artist['city']): ?>(<?= htmlspecialchars($artist['city']) ?>)<?php endif; ?>
                                <?php if ($artist['art_style']): ?> - <?= htmlspecialchars($artist['art_style']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type of Artwork</label>
                    <select name="artwork_type">
                        <option value="">Select type...</option>
                        <option value="painting">Painting</option>
                        <option value="portrait">Portrait</option>
                        <option value="digital_art">Digital Art</option>
                        <option value="calligraphy">Calligraphy</option>
                        <option value="abstract">Abstract</option>
                        <option value="landscape">Landscape</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Budget (Min) PKR</label>
                        <input type="number" name="budget_min" placeholder="Min budget">
                    </div>
                    <div class="form-group">
                        <label>Budget (Max) PKR</label>
                        <input type="number" name="budget_max" placeholder="Max budget">
                    </div>
                </div>
                <div class="form-group">
                    <label>Desired Deadline</label>
                    <input type="date" name="deadline">
                </div>
                <div class="form-group">
                    <label>Describe Your Request *</label>
                    <textarea name="description" rows="4" required placeholder="Describe what you want: subject, colors, size, style preferences..."></textarea>
                </div>
                <div class="form-group">
                    <label>Reference Image (Optional)</label>
                    <input type="file" name="reference_image" accept="image/jpeg,image/png,image/webp,image/gif">
                    <small style="font-size: 10px; color: var(--grey4);">Upload a reference image (max 2MB). Allowed: JPG, PNG, WEBP, GIF</small>
                </div>
                <div class="modal-footer" style="padding: 0; margin-top: 20px;">
                    <button type="button" class="btn-outline" onclick="closeModal('commissionModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let currentArtworkId = null;
    let currentArtistId = null;

    function openArtworkModal(id, title, artist, artistId, price, description, coverImage, category, city, deliveryAvailable, similarAvailable, deliveryStatus) {
        currentArtworkId = id;
        currentArtistId = artistId;
        
        let deliveryStatusHtml = '';
        const statusLabel = deliveryStatus ? deliveryStatus.replace(/_/g, ' ') : 'Not applicable';
        if (deliveryStatus && deliveryStatus !== 'not_applicable') {
            deliveryStatusHtml = `<span style="font-size: 12px; background: #e8f4ff; padding: 4px 12px; border-radius: 20px;">🚚 Delivery: ${escapeHtml(statusLabel)}</span>`;
        }
        
        const modalBody = document.getElementById('modalBody');
        modalBody.innerHTML = `
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <img src="${coverImage || 'https://placehold.co/400x400/efefef/999?text=No+Image'}" style="width: 100%; border-radius: 12px; object-fit: cover; max-height: 300px;" alt="${escapeHtml(title)}">
                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h3 style="font-size: 22px; margin-bottom: 4px;">${escapeHtml(title)}</h3>
                        <p style="color: var(--grey5);">by ${escapeHtml(artist)}</p>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 20px; font-weight: 600;">PKR ${Number(price).toLocaleString()}</div>
                        <div style="font-size: 12px; color: var(--grey4);">${escapeHtml(category)} ${city ? '• ' + escapeHtml(city) : ''}</div>
                    </div>
                </div>
                ${description ? `<p style="color: var(--grey5); line-height: 1.6;">${escapeHtml(description)}</p>` : ''}
                <div style="display: flex; gap: 16px; padding: 12px 0; flex-wrap: wrap;">
                    ${deliveryAvailable ? '<span style="font-size: 12px; background: var(--grey1); padding: 4px 12px; border-radius: 20px;">📦 Delivery Available</span>' : ''}
                    ${similarAvailable ? '<span style="font-size: 12px; background: var(--grey1); padding: 4px 12px; border-radius: 20px;">🎨 Similar Work Available</span>' : ''}
                    ${deliveryStatusHtml}
                </div>
                <hr style="border-color: var(--grey2); margin: 8px 0;">
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button onclick="closeModal('artworkModal'); openCommissionModal(${artistId}, '${escapeHtml(artist)}')" class="btn-secondary" style="flex: 1; text-align: center;">
                        🎨 Request Custom Similar
                    </button>
                </div>
                <hr style="border-color: var(--grey2); margin: 8px 0;">
                <h4 style="margin: 8px 0 4px;">Interested in buying this artwork?</h4>
                <p style="font-size: 13px; color: var(--grey5); margin-bottom: 16px;">Fill out the form below and the admin will connect you with the artist.</p>
                <form method="POST" id="inquiryForm">
                    <input type="hidden" name="action" value="contact_buy">
                    <input type="hidden" name="artwork_id" value="${currentArtworkId}">
                    <div class="form-group"><label>Your Name *</label><input type="text" name="buyer_name" required placeholder="Enter your full name"></div>
                    <div class="form-group"><label>Email Address *</label><input type="email" name="buyer_email" required placeholder="you@example.com"></div>
                    <div class="form-group"><label>Phone / WhatsApp</label><input type="text" name="buyer_phone" placeholder="03XX-XXXXXXX"></div>
                    <div class="form-group"><label>Message (Optional)</label><textarea name="message" rows="3" placeholder="Any questions about this artwork?"></textarea></div>
                    <div class="modal-footer" style="padding: 0; margin-top: 8px;">
                        <button type="button" class="btn-outline" onclick="closeModal('artworkModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Send Inquiry</button>
                    </div>
                </form>
            </div>
        `;
        document.getElementById('artworkModal').classList.add('active');
    }

    function openCommissionModal(artistId = null, artistName = null) {
        const artistSelect = document.getElementById('commissionArtistSelect');
        if (artistId && artistName) {
            for (let i = 0; i < artistSelect.options.length; i++) {
                if (artistSelect.options[i].value == artistId) {
                    artistSelect.selectedIndex = i; break;
                }
            }
        } else {
            artistSelect.selectedIndex = 0;
        }
        document.getElementById('commissionModal').classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
        if (modalId === 'artworkModal') currentArtworkId = null;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    document.getElementById('artworkModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal('artworkModal');
    });
    document.getElementById('commissionModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal('commissionModal');
    });
</script>

<footer class="footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <h4>Art Bazaar</h4>
                <p>Connecting art lovers with Pakistan's finest artists. Original artworks, custom commissions, and a thriving creative community.</p>
            </div>
            <div class="footer-section">
                <h4>Explore</h4>
                <p><a href="artworks.php">Artworks</a></p>
                <p><a href="artists.php">Artists</a></p>
                <p><a href="commission.php">Commission Art</a></p>
                <p><a href="sell.php">Sell Your Art</a></p>
            </div>
            <div class="footer-section">
                <h4>Support</h4>
                <p><a href="about.php">About Us</a></p>
                <p><a href="contact.php">Contact</a></p>
                <p><a href="terms.php">Terms & Conditions</a></p>
                <p><a href="privacy.php">Privacy Policy</a></p>
            </div>
            <div class="footer-section">
                <h4>Connect</h4>
                <p><i class="fab fa-instagram"></i> <a href="#">Instagram</a></p>
                <p><i class="fab fa-facebook"></i> <a href="#">Facebook</a></p>
                <p><i class="fab fa-whatsapp"></i> <a href="#">WhatsApp</a></p>
                <p><i class="far fa-envelope"></i> <a href="mailto:info@artbazaar.pk">info@artbazaar.pk</a></p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> Art Bazaar. All rights reserved. Empowering Pakistani artists.</p>
        </div>
    </div>
</footer>

</body>
</html>