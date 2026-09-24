<?php
require_once __DIR__ . '/config/db.php';


 $isLoggedIn = isset($_SESSION['user_id']);
 $inquirySuccess = false;
 $inquiryError = false;

// Pre-fill form for logged-in users
 $prefillName = '';
 $prefillEmail = '';
 $prefillPhone = '';
if ($isLoggedIn) {
    $uid = (int)$_SESSION['user_id'];
    $userRes = $conn->query("SELECT name, email, phone FROM users WHERE id = $uid");
    if ($userRow = $userRes->fetch_assoc()) {
        $prefillName = htmlspecialchars($userRow['name'] ?? '');
        $prefillEmail = htmlspecialchars($userRow['email'] ?? '');
        $prefillPhone = htmlspecialchars($userRow['phone'] ?? '');
    }
}

// Pre-selected Artist from URL (e.g., index.php?artist=5)
 $preSelectedArtistId = isset($_GET['artist']) ? (int)$_GET['artist'] : null;
 $preSelectedArtistName = null;

if ($preSelectedArtistId) {
    $stmt = $conn->prepare("SELECT u.name FROM users u LEFT JOIN artist_profiles ap ON u.id = ap.user_id WHERE u.id = ? AND u.role = 'artist' AND u.status = 'active'");
    $stmt->bind_param('i', $preSelectedArtistId);
    $stmt->execute();
    $artist = $stmt->get_result()->fetch_assoc();
    if ($artist) {
        $preSelectedArtistName = $artist['name'];
    }
}

// Handle inquiry submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact_buy') {
    $artworkId = (int)($_POST['artwork_id'] ?? 0);
    $buyerName = trim($_POST['buyer_name'] ?? ''); $buyerEmail = trim($_POST['buyer_email'] ?? '');
    $buyerPhone = trim($_POST['buyer_phone'] ?? ''); $message = trim($_POST['message'] ?? '');
    if (!$buyerName || !$buyerEmail) { $inquiryError = "Name and email are required."; } else {
        $artwork = $conn->query("SELECT price FROM artworks WHERE id = $artworkId")->fetch_assoc();
        $price = $artwork['price'] ?? 0; $orderNumber = 'INQ-' . time() . '-' . rand(1000, 9999);
        $stmt = $conn->prepare("INSERT INTO orders (buyer_id, guest_name, guest_email, guest_phone, order_number, order_type, order_status, subtotal, shipping_fee, discount, total, payment_method, payment_status, shipping_address, shipping_city, shipping_phone, buyer_notes, created_at, updated_at) VALUES (NULL, ?, ?, ?, ?, 'artwork', 'pending', ?, 0, 0, ?, 'cod', 'pending', 'TBD', 'TBD', ?, ?, NOW(), NOW())");
        $stmt->bind_param("ssssddss", $buyerName, $buyerEmail, $buyerPhone, $orderNumber, $price, $price, $buyerPhone, $message);
        $inquirySuccess = $stmt->execute();
        if ($inquirySuccess) {
            $orderId = $conn->insert_id;
            $ins = $conn->prepare("INSERT INTO order_items (order_id, item_type, item_id, quantity, price, item_status) VALUES (?, 'artwork', ?, 1, ?, 'pending')");
            $ins->bind_param("iid", $orderId, $artworkId, $price); $ins->execute();
        } else { $inquiryError = "Failed to submit. Please try again."; }
    }
}

// ============================================================
// HANDLE COMMISSION FORM SUBMISSION  
// ============================================================
 $commissionError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commission_request') {
    if (!$isLoggedIn) {
        $commissionError = "Please login to request a custom artwork commission.";
        goto commission_end; // skip processing entirely
    }
    $buyerName  = trim($_POST['buyer_name'] ?? '');
    $buyerEmail = trim($_POST['buyer_email'] ?? '');
    $buyerPhone = trim($_POST['buyer_phone'] ?? '');
    $requestedArtistId = !empty($_POST['requested_artist_id']) ? (int)$_POST['requested_artist_id'] : null;
    $artworkType = trim($_POST['artwork_type'] ?? '');
    $budgetMin  = !empty($_POST['budget_min']) ? (float)$_POST['budget_min'] : null;
    $budgetMax  = !empty($_POST['budget_max']) ? (float)$_POST['budget_max'] : null;
    $deadline   = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    $description = trim($_POST['description'] ?? '');
    $referenceImage = null;

    // ── NEW FIELDS ───────────────────────────────────────
    $commissionSize        = trim($_POST['commission_size'] ?? '');
    $commissionFramed      = trim($_POST['commission_framed'] ?? '');
    $commissionQuantity    = !empty($_POST['commission_quantity']) ? (int)$_POST['commission_quantity'] : 1;
    $commissionDeliveryCity = trim($_POST['commission_delivery_city'] ?? '');

    // Handle reference image upload (Increased size to 10MB)
    if (isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['reference_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $maxSize = 10 * 1024 * 1024; // 10MB
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if ($file['size'] <= $maxSize && in_array($ext, $allowedExt)) {
            $dir = __DIR__ . '/uploads/commissions/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'ref_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                $referenceImage = $filename;
            }
        }
    }

    if (!$buyerName || !$buyerEmail || !$description) {
        $commissionError = "Name, email, and description are required.";
    } else {
        // artwork_type now submits the category id directly
        $commissionCategoryId = !empty($_POST['artwork_type']) ? (int)$_POST['artwork_type'] : null;

        $buyerId = (isset($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : null;
        $orderNumber = 'COM-' . time() . '-' . rand(1000, 9999);
        $subtotal = $budgetMin ?? 0; $total = $subtotal;

        // Map artwork_type to DB ENUM
        $framedMap = ['unframed'=>'unframed','framed_basic'=>'framed','framed_premium'=>'framed','stretched_canvas'=>'unframed'];
        $commissionFramed = $framedMap[$commissionFramed] ?? 'not_specified';

        $stmt = $conn->prepare("
            INSERT INTO orders (
                buyer_id, guest_name, guest_email, guest_phone,
                order_number, order_type, order_status,
                subtotal, shipping_fee, discount, total,
                payment_method, payment_status,
                shipping_address, shipping_city, shipping_phone,
                commission_description, commission_reference_image,
                commission_deadline, commission_category_id,
                budget_min, budget_max,
                commission_size, commission_framed, commission_quantity, commission_delivery_city,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'commission', 'pending', ?, 0, 0, ?, 'cod', 'pending', '', '', '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->bind_param(
            "issssddsssiddssis",
            $buyerId, $buyerName, $buyerEmail, $buyerPhone,
            $orderNumber,
            $subtotal, $total,
            $description, $referenceImage,
            $deadline, $commissionCategoryId,
            $budgetMin, $budgetMax,
            $commissionSize, $commissionFramed, $commissionQuantity, $commissionDeliveryCity
        );

        $commissionSuccess = $stmt->execute();

        if ($commissionSuccess) {
            $newOrderId = $conn->insert_id;
            if ($requestedArtistId) {
                $cr = $conn->prepare("INSERT INTO commission_requests (order_id, artist_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                $cr->bind_param("ii", $newOrderId, $requestedArtistId);
            } else {
                $cr = $conn->prepare("INSERT INTO commission_requests (order_id, artist_id, created_at, updated_at) VALUES (?, NULL, NOW(), NOW())");
                $cr->bind_param("i", $newOrderId);
            }
            $cr->execute();

            $changedByRole = isset($_SESSION['user_id']) ? 'buyer' : 'system';
            $changedById   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'NULL';
            $conn->query("INSERT INTO order_status_history (order_id, status_from, status_to, notes, changed_by_role, changed_by_id, created_at) VALUES ($newOrderId, NULL, 'pending', 'Commission request submitted', '$changedByRole', $changedById, NOW())");

            header("Location: dashboard/buyer/account.php?commission_submitted=1");
            exit;
        } else {
            $commissionError = "Failed to submit. Please try again.";
        }
    }
    commission_end:;
}

function getImgUrl($p) {
    if (!$p) return null; $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/artworks/' . $p;
}

// ── Live search (used by the nav search box, physical + digital together) ──
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT a.id, a.title, a.price, a.status, a.delivery_type,
               u.name AS artist_name, c.name AS category_name,
               (SELECT image_path FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC, sort_order ASC LIMIT 1) AS cover_image
        FROM artworks a
        JOIN users u ON a.artist_id = u.id
        LEFT JOIN categories c ON a.category_id = c.id
        WHERE a.status = 'active' AND u.status = 'active'
          AND (a.title LIKE ? OR u.name LIKE ? OR c.name LIKE ?)
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['cover_image'] = getImgUrl($r['cover_image']);
        $r['price_formatted'] = 'Rs. ' . number_format($r['price']);
        $r['title'] = htmlspecialchars($r['title'], ENT_QUOTES);
        $r['artist_name'] = htmlspecialchars($r['artist_name'], ENT_QUOTES);
        $r['category_name'] = htmlspecialchars($r['category_name'] ?? '', ENT_QUOTES);
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

// ── Notifications: this buyer's answered, unseen Q&A replies ──
$myAnsweredQuestions = [];
if ($isLoggedIn) {
    $uidForQ = (int)$_SESSION['user_id'];
    $nStmt = $conn->prepare("
        SELECT aq.id, aq.artwork_id, aq.question, aq.answer, aq.answered_at, a.title AS artwork_title
        FROM artwork_questions aq
        JOIN artworks a ON a.id = aq.artwork_id
        WHERE aq.buyer_id = ? AND aq.answer IS NOT NULL AND aq.seen_by_buyer = 0
        ORDER BY aq.answered_at DESC
    ");
    $nStmt->bind_param('i', $uidForQ);
    $nStmt->execute();
    $myAnsweredQuestions = $nStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
function getProfileUrl($p) {
    if (!$p) return null; $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/profiles/' . $p;
}

 $availableArtists = $conn->query("SELECT u.id, u.name, ap.city, ap.art_style FROM users u JOIN artist_profiles ap ON u.id=ap.user_id WHERE u.role='artist' AND u.status='active' AND ap.accepts_commissions=1 AND ap.profile_complete=1 ORDER BY u.name ASC")->fetch_all(MYSQLI_ASSOC);
$featuredArtworks = $conn->query("SELECT a.id,a.title,a.price,a.city,a.status,a.reserved_by,a.delivery_type,u.name AS artist_name,u.id AS artist_id,c.name AS category_name,(SELECT image_path FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_image,(SELECT media_type FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_media_type FROM artworks a JOIN users u ON a.artist_id=u.id LEFT JOIN categories c ON a.category_id=c.id LEFT JOIN artist_profiles ap ON ap.user_id=u.id WHERE a.status = 'active' AND a.is_featured=1 AND u.status='active' ORDER BY a.updated_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$latestArtworks   = $conn->query("SELECT a.id,a.title,a.price,a.city,a.status,a.reserved_by,a.delivery_type,u.name AS artist_name,u.id AS artist_id,c.name AS category_name,(SELECT image_path FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_image,(SELECT media_type FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_media_type FROM artworks a JOIN users u ON a.artist_id=u.id JOIN categories c ON a.category_id=c.id JOIN artist_profiles ap ON ap.user_id=u.id WHERE a.status = 'active' AND u.status='active' AND ap.profile_complete=1 ORDER BY a.created_at DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);
$digitalArtworks  = $conn->query("SELECT a.id,a.title,a.price,a.status,a.delivery_type,u.name AS artist_name,u.id AS artist_id,c.name AS category_name,(SELECT image_path FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_image,(SELECT media_type FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_media_type FROM artworks a JOIN users u ON a.artist_id=u.id LEFT JOIN categories c ON a.category_id=c.id WHERE a.status = 'active' AND u.status='active' AND a.delivery_type='digital' ORDER BY a.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
 $categories       = $conn->query("SELECT id,name FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
 $heroArt = $featuredArtworks[0] ?? $latestArtworks[0] ?? null;
 $latestBlogPosts = $conn->query("SELECT bp.id, bp.title, bp.slug, bp.content, bp.featured_image, bp.published_at, bp.created_at, u.name AS author_name FROM blog_posts bp JOIN users u ON u.id = bp.author_id WHERE bp.status = 'published' ORDER BY bp.published_at DESC LIMIT 4")->fetch_all(MYSQLI_ASSOC);

 $svgIco = fn(string $inner): string => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">' . $inner . '</svg>';
 $catIcons = [
    'painting'      => $svgIco('<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.12a1.64 1.64 0 011.67-1.67h2c3.05 0 5.55-2.5 5.55-5.55C21.97 6.01 17.46 2 12 2z"/><circle cx="6.5" cy="12.5" r=".6"/><circle cx="8.5" cy="7.5" r=".6"/><circle cx="13.5" cy="6.5" r=".6"/><circle cx="17.5" cy="10.5" r=".6"/>'),
    'sketch'        => $svgIco('<path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>'),
    'digitalart'    => $svgIco('<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>'),
    'calligraphy'   => $svgIco('<path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.6 7.6"/><circle cx="11" cy="11" r="2"/>'),
    'photography'   => $svgIco('<path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/>'),
    'illustration'  => $svgIco('<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>'),
    'mixedmedia'    => $svgIco('<circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>'),
    'portrait'      => $svgIco('<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>'),
    'clothingart'   => $svgIco('<path d="M20.38 3.46L16 2a4 4 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10a2 2 0 002 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/>'),
    'crocheting'    => $svgIco('<circle cx="12" cy="12" r="9"/><path d="M4 9c5 2.2 11 2.2 16 0M3.5 14.5c5 2.5 12 2.5 17 0M9 3.4c-1.2 5.2-.2 11 3 17.2M15 3.4c1 5 .6 11-3 17"/>'),
    'glasswork'     => $svgIco('<path d="M7 2h10v5a5 5 0 01-10 0V2z"/><path d="M12 12v10M8 22h8"/>'),
    'handmadejewelry' => $svgIco('<path d="M6 3h12l4 6-10 12L2 9l4-6z"/><path d="M2 9h20M9 3l3 6 3-6M12 21L8 9M12 21l4-12"/>'),
    'customorders'  => $svgIco('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'),
 ];
 function getCatIcon(string $name): string {
    global $catIcons;
    $key = preg_replace('/[^a-z]/', '', strtolower($name));
    return $catIcons[$key] ?? $catIcons['painting'];
 }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Art Bazaar — Original Art from Pakistani Artists</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --ink:#0C3F30;
  --ink-deep:#082A1C;
  --muted:#4F695F;
  --faint:#547364;
  --line:#E4D7BB;
  --sand:#DDCDAE;
  --sand-deep:#C7B187;
  --bg:#F6EDDE;
  --card:#FBF6EA;
  --w:1280px;
}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{display:block;max-width:100%;}
:focus-visible{outline:2px solid var(--ink);outline-offset:2px;}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;transition-duration:.01ms !important;scroll-behavior:auto !important;}}

/* TOAST NOTIFICATION */
.toast{position:fixed;bottom:24px;right:24px;background:var(--ink-deep);color:#fff;padding:12px 20px;border-radius:8px;font-size:13px;z-index:600;display:flex;align-items:center;gap:10px;transform:translateX(400px);transition:transform .3s ease;box-shadow:0 4px 12px rgba(0,0,0,.15);}
.toast.show{transform:translateX(0);}
.toast svg{width:16px;height:16px;flex-shrink:0;}
.toast a{color:var(--sand);text-decoration:underline;margin-left:8px;}

/* ─── NAV ─── */
.nav{background:var(--ink-deep);border-bottom:1px solid var(--ink-deep);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:60px;display:flex;align-items:center;gap:18px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:6px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:2px;flex:1;}
.nlinks a{font-size:12.5px;color:rgba(246,237,222,.82);padding:7px 11px;border-radius:6px;transition:background .12s,color .12s;}
.nlinks a:hover{background:rgba(246,237,222,.08);color:var(--bg);}
.nsearch{position:relative;display:flex;align-items:center;gap:7px;background:rgba(246,237,222,.07);border:1px solid rgba(246,237,222,.16);border-radius:20px;padding:7px 14px;width:200px;flex-shrink:0;transition:border-color .15s,background .15s;}
.nsearch:focus-within{border-color:var(--sand);background:rgba(246,237,222,.1);}
.nsearch input{border:none;background:transparent;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--bg);outline:none;width:100%;}
.nsearch input::placeholder{color:var(--bg);opacity:.5;}
.nsearch svg{color:var(--bg);opacity:.55;flex-shrink:0;}

/* ─── LIVE SEARCH RESULTS ─── */
.search-dropdown{display:none;position:absolute;top:calc(100% + 12px);left:0;width:340px;max-height:420px;overflow-y:auto;background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 36px rgba(8,42,28,.22);z-index:400;}
.search-dropdown.open{display:block;}
.sr-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);text-decoration:none;transition:background .12s;}
.sr-item:last-child{border-bottom:none;}
.sr-item:hover{background:var(--bg);}
.sr-thumb{width:44px;height:44px;border-radius:6px;object-fit:cover;background:var(--sand);flex-shrink:0;}
.sr-thumb-ph{width:44px;height:44px;border-radius:6px;background:var(--sand);flex-shrink:0;}
.sr-info{min-width:0;flex:1;}
.sr-title{font-size:12.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sr-by{font-size:10.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sr-meta{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:6px;margin-top:2px;}
.sr-tag{font-size:8.5px;text-transform:uppercase;letter-spacing:.5px;background:var(--sand);padding:1px 6px;border-radius:20px;font-weight:600;color:var(--ink);}
.sr-empty,.sr-loading{padding:18px 16px;font-size:12.5px;color:var(--muted);font-style:italic;}

/* ─── MOBILE SEARCH ─── */
.msearch-btn{display:none;}
#mobile-search-overlay{display:none;}
.nend{display:flex;align-items:center;gap:9px;flex-shrink:0;position:relative;margin-left:auto;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:8px 15px;border-radius:20px;border:1px solid rgba(246,237,222,.3);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:rgba(246,237,222,.08);}
.btn-dark{font-size:12.5px;color:var(--ink-deep);padding:8px 17px;border-radius:20px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:600;transition:background .12s;}
.btn-dark:hover{background:#fff;}

/* ─── HERO ───
   The gallery moment: real artwork data (title / artist / price) framed
   like a piece on a wall, with the copy given plenty of room to breathe
   beside it rather than competing for the same box. */
.hero{max-width:var(--w);margin:0 auto;padding:64px 28px 80px;display:grid;grid-template-columns:1fr .92fr;gap:64px;align-items:center;position:relative;}
h1.htitle{font-family:'Playfair Display',serif;font-size:clamp(34px,4.1vw,54px);font-weight:400;line-height:1.14;color:var(--ink);letter-spacing:-.01em;margin-bottom:20px;max-width:19ch;}
.hdesc{font-size:14.5px;color:var(--muted);line-height:1.7;max-width:400px;margin-bottom:30px;}
.hbtns{display:flex;flex-wrap:wrap;align-items:center;gap:18px;margin-bottom:0;}
.btn-fill{display:inline-flex;align-items:center;gap:8px;background:var(--ink);color:var(--bg);padding:13px 26px;border-radius:30px;font-size:13.5px;font-weight:500;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s,transform .15s;}
.btn-fill:hover{background:var(--ink-deep);transform:translateY(-1px);}
.btn-text{font-size:13.5px;color:var(--ink);border-bottom:1px solid var(--ink);padding-bottom:2px;transition:color .12s,border-color .12s;}
.btn-text:hover{color:var(--muted);border-color:var(--muted);}

.hframe{position:relative;}
.hframe-plate{background:var(--card);border:1px solid var(--line);border-radius:4px;padding:16px;box-shadow:0 30px 60px -20px rgba(8,42,28,.3);}
.hframe-img{display:block;position:relative;aspect-ratio:4/5;overflow:hidden;background:var(--sand);}
.hframe-img img{width:100%;height:100%;object-fit:cover;}
.hframe-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.hframe-ph svg{opacity:.18;color:var(--ink);}
.hcaption{display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:14px 4px 2px;border-top:1px solid var(--line);margin-top:14px;}
.hcap-l{min-width:0;}
.hcap-title{font-family:'Playfair Display',serif;font-size:15px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hcap-by{font-size:11.5px;color:var(--muted);margin-top:2px;}
.hcap-price{font-size:13px;font-weight:600;color:var(--ink);flex-shrink:0;}
.hframe-tag{position:absolute;top:-11px;left:24px;background:var(--ink);color:var(--bg);font-size:10px;letter-spacing:.4px;padding:5px 12px;border-radius:20px;}

/* ─── LAYOUT / BANDS ───
   Sections group into alternating bands instead of a hairline rule after
   every block, so related content reads as one zone instead of a stack
   of equally-weighted, disconnected chunks. */
.wrap{max-width:var(--w);margin:0 auto;padding:0 28px;}
.band{padding:70px 0;}
.band-tint{background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line);}
.sec+.sec{margin-top:64px;}
.mid>.sec+.sec{margin-top:0;}
.sec-hd{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:28px;}
.sec-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;color:var(--ink);letter-spacing:-.01em;}
.sec-lnk{font-size:12.5px;color:var(--ink);border-bottom:1px solid var(--line);padding-bottom:2px;transition:color .12s,border-color .12s;white-space:nowrap;flex-shrink:0;}
.sec-lnk:hover{color:var(--muted);border-color:var(--muted);}

/* ─── CATEGORIES ───
   Wayfinding tiles, not another bordered card: a quiet fill on hover is
   the only chrome, so nine of these don't read as nine product cards. */
.cat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:4px;}
.cat-card{display:flex;flex-direction:column;align-items:center;gap:10px;padding:20px 8px;text-align:center;border-radius:14px;transition:background .15s;min-width:0;}
.cat-card:hover{background:var(--card);}
.cat-card svg{color:var(--ink);}
.cat-card span{font-size:11.5px;color:var(--ink);font-weight:500;line-height:1.3;}
.cat-more-btn{display:none;width:100%;padding:12px;background:transparent;border:1px solid var(--line);color:var(--ink);border-radius:24px;font-family:'DM Sans',sans-serif;font-weight:500;font-size:12.5px;cursor:pointer;margin-top:14px;transition:all .15s;}
.cat-more-btn:hover{background:var(--card);border-color:var(--ink);}

.qa-bell-wrap{position:relative;}
.qa-bell-btn{position:relative;background:transparent;border:1px solid rgba(246,237,222,.25);border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--bg);transition:background .12s,border-color .12s;}
.qa-bell-btn:hover{background:rgba(246,237,222,.1);border-color:var(--sand);}
.qa-bell-count{position:absolute;top:-5px;right:-5px;background:#B5502E;color:#fff;font-size:9.5px;font-weight:600;line-height:1;min-width:16px;height:16px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 3px;}
.qa-bell-dropdown{display:none;position:absolute;top:calc(100% + 12px);right:0;width:300px;max-height:380px;overflow-y:auto;background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 36px rgba(8,42,28,.22);z-index:300;}
.qa-bell-dropdown.open{display:block;}
.qa-bell-hd{font-size:11.5px;font-weight:600;color:var(--ink);padding:13px 16px 11px;border-bottom:1px solid var(--line);}
.qa-bell-empty{font-size:12.5px;color:var(--muted);font-style:italic;padding:18px 16px;}
.qa-bell-item{display:block;padding:12px 16px;border-bottom:1px solid var(--line);transition:background .12s;}
.qa-bell-item:last-child{border-bottom:none;}
.qa-bell-item:hover{background:var(--bg);}
.qa-bell-item-title{font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:3px;}
.qa-bell-item-q{font-size:11.5px;color:var(--muted);margin-bottom:5px;line-height:1.4;}
.qa-bell-item-tag{font-size:10px;font-weight:600;color:var(--ink);background:var(--bg);display:inline-block;padding:2px 8px;border-radius:20px;}
@media(max-width:768px){
  .qa-bell-dropdown{position:fixed;top:60px;right:8px;left:8px;width:auto;}
}

/* ─── ARTWORK CARD ───
   The image carries the weight; the label sits below it plainly, the way
   a print's caption card does — no border box, no shared drop-shadow. */
.aw-card{display:block;min-width:0;}
.aw-img{display:block;aspect-ratio:1;overflow:hidden;position:relative;background:var(--sand);border-radius:5px;cursor:pointer;}
.aw-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease;}
.aw-card:hover .aw-img img{transform:scale(1.045);}
.aw-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.aw-ph svg{opacity:.16;color:var(--ink);}
.aw-sold-tag{position:absolute;top:9px;right:9px;background:var(--ink-deep);color:var(--bg);font-size:8.5px;letter-spacing:.6px;text-transform:uppercase;padding:4px 8px;border-radius:20px;}
.aw-digital-tag{position:absolute;top:9px;left:9px;background:var(--card);color:var(--ink);font-size:8.5px;letter-spacing:.6px;text-transform:uppercase;padding:4px 8px;border-radius:20px;font-weight:600;}
.aw-body{padding:12px 1px 0;}
.aw-title{font-size:13px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;cursor:pointer;display:block;}
.aw-by{font-size:11.5px;color:var(--muted);margin-bottom:9px;cursor:pointer;}
.aw-by span{color:var(--ink);}
.aw-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:11px;}
.aw-price{font-size:13.5px;font-weight:600;color:var(--ink);}
.aw-price small{font-size:10px;font-weight:400;color:var(--muted);margin-right:1px;}
.aw-cat{font-size:10px;color:var(--muted);}
.aw-buy-btn,.aw-add-cart{display:block;width:100%;background:transparent;color:var(--ink);border:1px solid var(--line);border-radius:20px;padding:8px;font-size:11.5px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .12s;text-align:center;}
.aw-buy-btn:hover,.aw-add-cart:hover{background:var(--ink);border-color:var(--ink);color:var(--bg);}

/* ─── TWO COL MID ─── */
.mid{display:grid;grid-template-columns:1.35fr 1fr;gap:56px;}
.feat-aw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:22px 16px;}

/* ─── ARTIST CARDS ───
   No card box at all — the circular portrait already reads as distinct
   from a square artwork thumbnail, so it doesn't need one. */
.ar-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:26px 16px;}
.ar-card{text-align:center;min-width:0;}
.ar-av{width:72px;height:72px;border-radius:50%;margin:0 auto 12px;overflow:hidden;background:var(--sand);border:1px solid var(--line);}
.ar-av img{width:100%;height:100%;object-fit:cover;}
.ar-av-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:'Playfair Display',serif;font-size:22px;color:var(--ink);}
.ar-name{font-family:'Playfair Display',serif;font-size:15px;font-weight:400;color:var(--ink);margin-bottom:2px;}
.ar-style{font-size:11.5px;color:var(--muted);margin-bottom:1px;}
.ar-city{font-size:10.5px;color:var(--faint);margin-bottom:13px;}
.ar-btns{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
.ar-btn{font-size:10.5px;padding:6px 13px;border-radius:20px;border:1px solid var(--line);background:transparent;color:var(--ink);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.ar-btn:hover{border-color:var(--ink);}
.ar-btn.p{background:var(--ink);color:var(--bg);border-color:var(--ink);}
.ar-btn.p:hover{background:var(--ink-deep);}

/* ─── HOW IT WORKS ───
   This one genuinely is a sequence, so the numbering stays — as large,
   quiet serif numerals rather than a small solid badge, so it reads as
   typography rather than a generic step-tracker widget. */
.how-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:36px 28px;}
.how-card{position:relative;padding-top:6px;min-width:0;}
.how-n{font-family:'Playfair Display',serif;font-size:34px;font-weight:400;color:var(--line);line-height:1;margin-bottom:14px;}
.how-icon{color:var(--ink);margin-bottom:12px;}
.how-t{font-size:14px;font-weight:600;color:var(--ink);margin-bottom:5px;}
.how-d{font-size:12.5px;color:var(--muted);line-height:1.6;}

/* ─── COMMISSION BAND ───
   A full-bleed dark band, not a rounded card floating inside a wrap —
   this is the page's second anchor moment, so it gets room to be one. */
.comm-band{background:var(--ink-deep);color:var(--bg);}
.comm-wrap{max-width:var(--w);margin:0 auto;padding:64px 28px;display:grid;grid-template-columns:1fr auto;align-items:center;gap:36px;}
.cs-title{font-family:'Playfair Display',serif;font-size:clamp(24px,2.6vw,34px);font-weight:400;color:var(--bg);line-height:1.22;margin-bottom:12px;letter-spacing:-.01em;}
.cs-desc{font-size:13px;color:rgba(246,237,222,.62);max-width:460px;line-height:1.65;}
.btn-gold{display:inline-flex;align-items:center;gap:8px;background:var(--sand);color:var(--ink-deep);padding:14px 28px;border-radius:30px;font-size:13.5px;font-weight:600;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s,transform .15s;white-space:nowrap;}
.btn-gold:hover{background:#fff;transform:translateY(-1px);}

/* ─── LATEST ARTWORKS ─── */
.latest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:26px 18px;}
@media(max-width:768px){
  #latest-aw-grid .aw-card:nth-child(n+9){display:none;}
}
.blog-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:26px;}
.post-card{display:flex;flex-direction:column;cursor:pointer;min-width:0;}
.pc-img{aspect-ratio:4/3;overflow:hidden;position:relative;background:var(--sand);border-radius:5px;}
.pc-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease;}
.post-card:hover .pc-img img{transform:scale(1.045);}
.pc-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.pc-ph svg{opacity:.16;color:var(--ink);}
.pc-body{padding:14px 1px 0;flex:1;display:flex;flex-direction:column;}
.pc-title{font-family:'Playfair Display',serif;font-size:16.5px;font-weight:400;color:var(--ink);line-height:1.32;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.pc-excerpt{font-size:12.5px;color:var(--muted);line-height:1.58;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex:1;}
.pc-meta{display:flex;align-items:center;justify-content:space-between;font-size:11px;color:var(--faint);padding-top:12px;border-top:1px solid var(--line);}
.pc-meta span{display:flex;align-items:center;gap:5px;}
.pc-meta svg{width:12px;height:12px;flex-shrink:0;}
@media(max-width:1080px){.blog-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){.blog-grid{grid-template-columns:repeat(2,1fr);}}

/* ─── MODALS ─── */
.mbg{display:none;position:fixed;inset:0;background:rgba(8,42,28,.6);backdrop-filter:blur(3px);z-index:500;align-items:center;justify-content:center;padding:16px;}
.mbg.open{display:flex;}
.modal{background:var(--card);border-radius:16px;width:100%;max-width:490px;max-height:92vh;overflow-y:auto;}
.mhd{padding:24px 26px 0;display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:6px;}
.mhd h3{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;color:var(--ink);}
.mcls{background:var(--bg);border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--ink);flex-shrink:0;transition:background .12s;}
.mcls:hover{background:var(--sand);}
.mbd{padding:16px 26px 26px;}
.fg{margin-bottom:14px;}
.fg label{display:block;font-size:12px;color:var(--ink);font-weight:500;margin-bottom:6px;}
.fg label span{color:var(--muted);}
.fi,.fs,.ft{width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--bg);outline:none;transition:border-color .12s;}
.fi:focus,.fs:focus,.ft:focus{border-color:var(--ink);}
.ft{min-height:86px;resize:vertical;line-height:1.55;}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fr3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.msub{width:100%;background:var(--ink);color:var(--bg);border:none;padding:13px;border-radius:9px;font-size:13.5px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;margin-top:4px;transition:background .12s;}
.msub:hover{background:var(--ink-deep);}
.mmsg{padding:10px 13px;border-radius:8px;font-size:12px;margin-bottom:13px;}
.mmsg.ok{background:var(--bg);color:var(--ink);border:1px solid var(--line);}
.mmsg.er{background:#FBEBE5;color:#8A3B22;border:1px solid #EAC5B5;}

/* ─── FOOTER ─── */
.footer{background:var(--ink-deep);color:var(--bg);}
.fw{max-width:var(--w);margin:0 auto;padding:56px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:40px;}
.fb b{font-family:'Playfair Display',serif;font-size:19px;color:var(--bg);display:block;margin-bottom:9px;}
.fb p{font-size:12.5px;line-height:1.7;max-width:230px;color:rgba(246,237,222,.55);}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:13px;font-weight:600;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.55);margin-bottom:9px;transition:color .12s;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.1);padding-top:20px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;color:rgba(246,237,222,.55);}

/* ─── MOBILE HAMBURGER & DRAWER GLOBAL STYLES ─── */
.ham-btn { display:none; }
#nav-drawer { display:none; }
#nav-overlay { display:none; }

/* ─── RESPONSIVE ─── */
@media(max-width:1080px){
  .hero{gap:40px;}
  .mid{grid-template-columns:1fr;gap:48px;}
  .ar-grid{grid-template-columns:repeat(4,1fr);}
  .how-grid{grid-template-columns:repeat(2,1fr);}
  .fg-foot{grid-template-columns:1fr 1fr;}
}
@media(max-width:768px){
  .nlinks,.nsearch{display:none;}
  .band{padding:48px 0;}
  .hero{grid-template-columns:1fr;padding:36px 16px 56px;gap:36px;}
  h1.htitle{max-width:none;}
  .hbtns{flex-direction:column;align-items:flex-start;gap:14px;}
  .hframe{order:-1;max-width:320px;margin:0 auto;}
  .wrap{padding:0 16px;}
  .cat-row{grid-template-columns:repeat(3,1fr);gap:2px;}
  .cat-row.hidden-cats .cat-item:nth-child(n+7){display:none;}
  .cat-more-btn{display:block;}
  .feat-aw-grid{grid-template-columns:repeat(2,1fr);}
  .ar-grid{grid-template-columns:1fr 1fr;}
  .latest-grid{grid-template-columns:repeat(2,1fr);}
  .how-grid{grid-template-columns:repeat(2,1fr);gap:30px 20px;}
  .comm-wrap{grid-template-columns:1fr;padding:48px 16px;gap:22px;text-align:left;}
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:0 16px;}
  .fc{display:none;}
  .fb{margin-bottom:14px;}
  .fb b{font-size:17px;}
  .fb p{font-size:11px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10.5px;padding-top:16px;}
  .nend > .btn-ghost, .nend > .btn-dark { display:none; }
  .ham-btn { display:flex; flex-direction:column; justify-content:center; gap:5px; background:transparent; border:none; cursor:pointer; padding:6px; margin-left:auto;}
  .ham-btn span { display:block; width:22px; height:2px; background:var(--bg); border-radius:2px; }
  .msearch-btn{display:flex;align-items:center;justify-content:center;background:transparent;border:1px solid rgba(246,237,222,.3);border-radius:50%;width:34px;height:34px;color:var(--bg);cursor:pointer;margin-left:6px;}
  .msearch-btn:hover{background:rgba(246,237,222,.1);border-color:var(--sand);}
  #mobile-search-overlay{display:none;position:fixed;inset:0;background:var(--bg);z-index:500;flex-direction:column;}
  #mobile-search-overlay.open{display:flex;}
  .msearch-top{display:flex;align-items:center;gap:10px;padding:16px;border-bottom:1px solid var(--line);flex-shrink:0;}
  .msearch-top input{flex:1;border:1px solid var(--line);border-radius:20px;padding:11px 16px;font-size:14px;font-family:'DM Sans',sans-serif;outline:none;background:var(--card);color:var(--ink);}
  .msearch-close{background:transparent;border:none;font-size:20px;line-height:1;cursor:pointer;color:var(--ink);padding:4px;flex-shrink:0;}
  .msearch-results{flex:1;overflow-y:auto;}
  .msearch-results .sr-item{padding:14px 16px;}
  #nav-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:298; }
  #nav-overlay.open { display:block; }
  #nav-drawer { display:flex; flex-direction:column; position:fixed; top:0; right:0; width:75vw; max-width:300px; height:100vh; background:var(--ink-deep); z-index:299; transform:translateX(100%); transition:transform 0.3s ease; padding:0; overflow-y:auto; }
  #nav-drawer.open { transform:translateX(0); }
  .drawer-top { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid rgba(246,237,222,0.1); }
  .drawer-logo b { font-family:'Playfair Display',serif; font-size:16px; color:var(--bg); display:block; }
  .drawer-logo small { font-size:7px; letter-spacing:2px; text-transform:uppercase; color:var(--sand); }
  .drawer-close { background:transparent; border:none; color:var(--bg); font-size:18px; cursor:pointer; padding:4px; }
  .drawer-links { display:flex; flex-direction:column; padding:12px 0; }
  .drawer-links a { color:var(--bg); font-size:14px; padding:13px 20px; border-bottom:1px solid rgba(246,237,222,0.07); transition:background 0.12s; }
  .drawer-links a:hover { background:rgba(246,237,222,0.06); }
  .drawer-actions { margin-top:auto; padding:20px; display:flex; flex-direction:column; gap:10px; border-top:1px solid rgba(246,237,222,0.1); }
  .drawer-btn-ghost { font-size:13px; color:var(--bg); padding:9px 14px; border-radius:20px; border:1px solid rgba(246,237,222,0.4); text-align:center; transition:all 0.12s; }
  .drawer-btn-ghost:hover { border-color:var(--sand); background:rgba(246,237,222,0.08); }
  .drawer-btn-dark { font-size:13px; color:var(--ink-deep); padding:9px 14px; border-radius:20px; background:var(--sand); text-align:center; font-weight:600; transition:background 0.12s; }
  .drawer-btn-dark:hover { background:#fff; }
}
</style>
</head>
<?php
// Shared artwork-card renderer — used by Featured, Latest and Digital
// grids below so all three stay visually identical by construction
// instead of three hand-synced copies of the same markup.
function renderArtworkCard(array $art): string {
    $img = getImgUrl($art['cover_image']);
    $isSold = $art['status'] === 'sold';
    $isDigital = ($art['delivery_type'] ?? '') === 'digital';
    ob_start(); ?>
    <div class="aw-card">
      <a href="artwork-detail.php?id=<?= $art['id'] ?>" class="aw-img">
        <?php if ($img && $art['cover_media_type'] === 'video'): ?>
          <video src="<?= htmlspecialchars($img) ?>" muted playsinline></video>
        <?php elseif ($img && $art['cover_media_type'] === 'audio'): ?>
          <div class="aw-ph"><svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>
        <?php elseif ($img): ?>
          <img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy">
        <?php else: ?>
          <div class="aw-ph"><svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
        <?php endif; ?>
        <?php if ($isSold): ?><span class="aw-sold-tag">Sold</span><?php endif; ?>
        <?php if ($isDigital): ?><span class="aw-digital-tag">Digital</span><?php endif; ?>
      </a>
      <div class="aw-body">
        <a href="artwork-detail.php?id=<?= $art['id'] ?>" class="aw-title"><?= htmlspecialchars($art['title']) ?></a>
        <div class="aw-by" onclick="location.href='artist-profile.php?id=<?= $art['artist_id'] ?>'">by <span><?= htmlspecialchars($art['artist_name']) ?></span></div>
        <div class="aw-foot"><div class="aw-price"><small>Rs. </small><?= number_format($art['price']) ?></div><span class="aw-cat"><?= htmlspecialchars($art['category_name'] ?? 'Digital Art') ?></span></div>
        <?php if ($isSold): ?>
          <button class="aw-buy-btn" disabled style="opacity:.5;cursor:not-allowed;">Sold out</button>
        <?php else: ?>
          <a href="checkout.php?artwork_id=<?= $art['id'] ?>" class="aw-add-cart">Buy now</a>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nw">
    <a href="index.php" class="nlogo"><img src="logo.png" alt="Art Bazaar" style="height:34px;width:auto;display:block;"></a>
    <div class="nlinks">
      <a href="artworks.php">Physical Art</a>
      <a href="digital-art.php">Digital Art</a>
      <a href="artists.php">Artists</a>
      <a href="blog.php">Blog</a>
      <a href="commission.php">Custom Artwork</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" id="desktopSearchInput" placeholder="Search artworks, artists..." autocomplete="off" oninput="handleSearchInput(this, 'desktopSearchDropdown')" onkeydown="if(event.key==='Escape'){this.value='';closeSearchDropdown('desktopSearchDropdown');}">
      <div class="search-dropdown" id="desktopSearchDropdown"></div>
    </div>
    <div class="nend">

      <?php if ($isLoggedIn): ?>
        <div class="qa-bell-wrap">
          <button class="qa-bell-btn" id="qaBellBtn" aria-label="Question replies" onclick="toggleQABell()">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            <?php if (!empty($myAnsweredQuestions)): ?>
              <span class="qa-bell-count"><?= count($myAnsweredQuestions) ?></span>
            <?php endif; ?>
          </button>
          <div class="qa-bell-dropdown" id="qaBellDropdown">
            <div class="qa-bell-hd">Question Replies</div>
            <?php if (empty($myAnsweredQuestions)): ?>
              <div class="qa-bell-empty">No new replies right now.</div>
            <?php else: ?>
              <?php foreach ($myAnsweredQuestions as $mq): ?>
                <a class="qa-bell-item" href="artwork-detail.php?id=<?= (int)$mq['artwork_id'] ?>#qa-section">
                  <div class="qa-bell-item-title"><?= htmlspecialchars($mq['artwork_title']) ?></div>
                  <div class="qa-bell-item-q">You asked: "<?= htmlspecialchars(mb_strimwidth($mq['question'], 0, 60, '…')) ?>"</div>
                  <div class="qa-bell-item-tag">✓ Artist replied</div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <a href="dashboard/buyer/account.php" class="btn-ghost">My Account</a>
        <a href="logout.php" class="btn-dark">Logout</a>
      <?php else: ?>
        <a href="login.php" class="btn-ghost">Login</a>
      <?php endif; ?>

      <button class="msearch-btn" aria-label="Search" onclick="openMobileSearch()">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      </button>
      <button class="ham-btn" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div>
    <h1 class="htitle">Original art, straight from Pakistan's artists</h1>
    <p class="hdesc">Paintings, sketches, calligraphy and digital work, sold directly by the people who make it. Commission something one-of-a-kind, or take home a piece that already exists.</p>
    <div class="hbtns">
      <a href="artworks.php" class="btn-fill">Browse artworks</a>
      <a href="digital-art.php" class="btn-text">Digital art</a>
      <a href="commission.php" class="btn-text">Custom commissions</a>
    </div>
  </div>
  <div class="hframe">
    <?php if ($heroArt): $himg = getImgUrl($heroArt['cover_image']); ?>
    <span class="hframe-tag"><?= htmlspecialchars($heroArt['category_name'] ?? 'Featured') ?></span>
    <div class="hframe-plate">
      <a href="artwork-detail.php?id=<?= $heroArt['id'] ?>" class="hframe-img">
        <?php if ($himg && $heroArt['cover_media_type'] === 'video'): ?>
          <video src="<?= htmlspecialchars($himg) ?>" muted playsinline autoplay loop></video>
        <?php elseif ($himg): ?>
          <img src="<?= htmlspecialchars($himg) ?>" alt="<?= htmlspecialchars($heroArt['title']) ?>">
        <?php else: ?>
          <div class="hframe-ph"><svg width="52" height="52" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
        <?php endif; ?>
      </a>
      <div class="hcaption">
        <div class="hcap-l">
          <div class="hcap-title"><?= htmlspecialchars($heroArt['title']) ?></div>
          <div class="hcap-by">by <?= htmlspecialchars($heroArt['artist_name']) ?></div>
        </div>
        <div class="hcap-price">Rs. <?= number_format($heroArt['price']) ?></div>
      </div>
    </div>
    <?php else: ?>
    <div class="hframe-plate">
      <div class="hframe-img"><div class="hframe-ph"><svg width="52" height="52" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- CATEGORIES -->
<div class="band">
  <div class="wrap sec">
    <div class="sec-hd">
      <h2 class="sec-title">Explore by category</h2>
      <a href="artworks.php" class="sec-lnk">View all</a>
    </div>
    <div class="cat-row<?= count($categories) > 6 ? ' hidden-cats' : '' ?>" id="cat-row">
      <?php foreach ($categories as $cat): ?>
      <a href="artworks.php?category=<?= $cat['id'] ?>" class="cat-card cat-item">
        <?= getCatIcon($cat['name']) ?>
        <span><?= htmlspecialchars($cat['name']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php if (count($categories) > 6): ?>
    <button class="cat-more-btn" onclick="toggleCategories()">+ More categories</button>
    <?php endif; ?>
  </div>
</div>

<!-- FEATURED ARTWORKS -->
<div class="band band-tint">
  <div class="wrap">
    <div class="sec">
      <div class="sec-hd">
        <h2 class="sec-title">Featured artworks</h2>
        <a href="artworks.php?featured=1" class="sec-lnk">View all</a>
      </div>
      <?php if (empty($featuredArtworks)): ?>
        <p style="color:var(--muted);font-size:13px;padding:16px 0;">No featured artworks yet.</p>
      <?php else: ?>
      <div class="feat-aw-grid">
        <?php foreach ($featuredArtworks as $art) { echo renderArtworkCard($art); } ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- LATEST ARTWORKS -->
<div class="band">
  <div class="wrap sec">
    <div class="sec-hd">
      <h2 class="sec-title">Latest artworks</h2>
      <a href="artworks.php" class="sec-lnk">View all</a>
    </div>
    <div class="latest-grid" id="latest-aw-grid">
      <?php foreach ($latestArtworks as $art) { echo renderArtworkCard($art); } ?>
    </div>
  </div>

  <?php if (!empty($digitalArtworks)): ?>
  <div class="wrap sec">
    <div class="sec-hd">
      <h2 class="sec-title">Digital artworks</h2>
      <a href="digital-art.php" class="sec-lnk">View all</a>
    </div>
    <div class="latest-grid">
      <?php foreach ($digitalArtworks as $art) { echo renderArtworkCard($art); } ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- COMMISSION BAND -->
<section class="comm-band">
  <div class="comm-wrap">
    <div>
      <h2 class="cs-title">Want something made just for you?</h2>
      <p class="cs-desc">Request a custom piece from a Pakistani artist — portraits, calligraphy, illustration, or anything you can describe. Tell us what you have in mind and we'll match you with the right person for it.</p>
    </div>
    <button class="btn-gold" onclick="openCM()">Request custom artwork</button>
  </div>
</section>

<!-- LATEST INSIGHTS -->
<div class="band">
  <div class="wrap sec">
    <div class="sec-hd">
      <h2 class="sec-title">Latest insights</h2>
      <a href="blog.php" class="sec-lnk">View all posts</a>
    </div>
    <?php if (empty($latestBlogPosts)): ?>
      <p style="color:var(--muted);font-size:13px;padding:16px 0;">No blog posts yet.</p>
    <?php else: ?>
    <div class="blog-grid">
      <?php foreach ($latestBlogPosts as $p): ?>
      <a href="blog-post.php?slug=<?= htmlspecialchars($p['slug']) ?>" class="post-card">
        <div class="pc-img">
          <?php if ($p['featured_image']): ?>
            <img src="<?= htmlspecialchars($p['featured_image']) ?>" alt="" loading="lazy">
          <?php else: ?>
          <div class="pc-ph">
            <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/><path d="M17 16l2 2"/></svg>
          </div>
          <?php endif; ?>
        </div>
        <div class="pc-body">
          <div class="pc-title"><?= htmlspecialchars($p['title']) ?></div>
          <div class="pc-excerpt"><?= htmlspecialchars(mb_substr(strip_tags($p['content'] ?? ''), 0, 120)) ?>...</div>
          <div class="pc-meta">
            <span>
              <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <?= htmlspecialchars($p['author_name']) ?>
            </span>
            <span>
              <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <?= date('M j, Y', strtotime($p['published_at'] ?? $p['created_at'])) ?>
            </span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- HOW IT WORKS -->
  <div class="wrap sec">
    <div class="sec-hd">
      <h2 class="sec-title">How it works</h2>
    </div>
    <div class="how-grid">
      <div class="how-card">
        <div class="how-n">01</div>
        <div class="how-icon"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg></div>
        <div class="how-t">Explore</div>
        <div class="how-d">Browse original artworks from verified Pakistani artists.</div>
      </div>
      <div class="how-card">
        <div class="how-n">02</div>
        <div class="how-icon"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
        <div class="how-t">Buy instantly</div>
        <div class="how-d">Tap Buy Now and go straight to checkout — no cart needed.</div>
      </div>
      <div class="how-card">
        <div class="how-n">03</div>
        <div class="how-icon"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div>
        <div class="how-t">Place your order</div>
        <div class="how-d">Confirm delivery details and complete your purchase.</div>
      </div>
      <div class="how-card">
        <div class="how-n">04</div>
        <div class="how-icon"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></div>
        <div class="how-t">Receive</div>
        <div class="how-d">Your artwork arrives safely, anywhere in Pakistan.</div>
      </div>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's marketplace for original art — connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="artworks.php?featured=1">Featured</a></div>
      <div class="fc"><h4>For Artists</h4><a href="sell.php">How to Sell</a><a href="register.php">Join as Artist</a><a href="login.php">Artist Login</a></div>
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Custom Artwork</a><a href="terms.php">Terms &amp; Conditions</a><a href="privacy-policy.php">Privacy &amp; Policies</a></div>
    </div>
    <div class="fbot"><span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span><span>Made with care in Pakistan 🇵🇰</span></div>
  </div>
</footer>

<!-- COMMISSION MODAL -->
<div class="mbg" id="cm">
  <div class="modal">
    <div class="mhd">
      <h3>Request a custom artwork</h3>
      <button class="mcls" onclick="document.getElementById('cm').classList.remove('open')">✕</button>
    </div>
    <div class="mbd">
      <p style="font-size:12px;color:var(--muted);margin-bottom:10px;">Fill out the form and we'll connect you with the right artist. Your details are safe with us.</p>
      <p style="font-size:11px;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:9px;padding:11px 14px;margin-bottom:14px;line-height:1.6;">Submit your request and we'll review the details, then confirm pricing, timeline and shipping before payment. <strong>Official payment instructions are only ever shared by Art Bazaar Pakistan.</strong></p>

      <?php if ($commissionError): ?>
        <div class="mmsg er"><?= htmlspecialchars($commissionError) ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" id="commission-form">
        <input type="hidden" name="action" value="commission_request">

        <div class="frow">
          <div class="fg">
            <label>Your Name <span>*</span></label>
            <input type="text" name="buyer_name" class="fi" placeholder="Full name" value="<?= $prefillName ?>" required>
          </div>
          <div class="fg">
            <label>Email <span>*</span></label>
            <input type="email" name="buyer_email" class="fi" placeholder="you@example.com" value="<?= $prefillEmail ?>" required>
          </div>
        </div>

        <div class="fg">
          <label>Phone / WhatsApp</label>
          <input type="tel" name="buyer_phone" class="fi" placeholder="+92 300 0000000" value="<?= $prefillPhone ?>">
          <p style="font-size:10px;color:var(--muted);margin-top:5px;">Used only by Art Bazaar Pakistan for order updates.</p>
        </div>

        <div class="fg">
          <label>Preferred Artist <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
          <select name="requested_artist_id" class="fs" id="cm-artist-select">
            <option value="">Any artist — we'll find the best match</option>
            <?php foreach ($availableArtists as $a): ?>
              <option value="<?= $a['id'] ?>" <?= ($preSelectedArtistId == $a['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($a['name']) ?>
                <?php if ($a['city']): ?> (<?= htmlspecialchars($a['city']) ?>)<?php endif; ?>
                <?php if ($a['art_style']): ?> — <?= htmlspecialchars($a['art_style']) ?><?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fr3">
          <div class="fg">
            <label>Artwork Type</label>
            <select name="artwork_type" class="fs">
              <option value="">Select type...</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Budget Min (PKR)</label>
            <input type="number" name="budget_min" class="fi" placeholder="5000">
          </div>
          <div class="fg">
            <label>Budget Max (PKR)</label>
            <input type="number" name="budget_max" class="fi" placeholder="15000">
          </div>
        </div>
        <p style="font-size:10px;color:var(--muted);margin-top:-9px;margin-bottom:13px;">This is an estimated budget — final pricing is confirmed after review.</p>
        <div class="fg">
          <label>Preferred Deadline</label>
          <input type="date" name="deadline" class="fi">
        </div>

        <div class="frow">
            <div class="fg">
                <label>Artwork Size</label>
                <input type="text" name="commission_size" class="fi" placeholder="e.g. 18 x 24 inches">
            </div>
            <div class="fg">
                <label>Framed / Unframed</label>
                <select name="commission_framed" class="fs">
                    <option value="unframed">Unframed (Canvas/Paper)</option>
                    <option value="framed_basic">Framed (Basic)</option>
                    <option value="framed_premium">Framed (Premium)</option>
                    <option value="stretched_canvas">Stretched Canvas (No Frame)</option>
                </select>
            </div>
        </div>

        <div class="frow">
            <div class="fg">
                <label>Quantity</label>
                <input type="number" name="commission_quantity" class="fi" value="1" min="1">
            </div>
            <div class="fg">
                <label>Delivery City</label>
                <input type="text" name="commission_delivery_city" class="fi" placeholder="e.g. Lahore">
            </div>
        </div>

        <div class="fg">
          <label>Describe Your Request <span>*</span></label>
          <textarea name="description" class="ft" placeholder="Tell us what you want — subject, colors, size, style preferences, any specific details..." required></textarea>
        </div>

        <div class="fg">
          <label>Reference Image <span style="color:var(--muted);font-weight:400;">(optional, max 10MB)</span></label>
          <input type="file" name="reference_image" class="fi" accept="image/jpeg,image/png,image/webp,image/gif">
          <p style="font-size:10px;color:var(--muted);margin-top:5px;">Upload a reference image to help the artist understand your idea.</p>
        </div>

        <button type="submit" class="msub">Submit commission request</button>
      </form>
    </div>
  </div>
</div>

<!-- MOBILE SEARCH OVERLAY -->
<div id="mobile-search-overlay">
  <div class="msearch-top">
    <input type="text" id="mobileSearchInput" placeholder="Search artworks, artists..." autocomplete="off" oninput="handleSearchInput(this, 'mobileSearchDropdown')">
    <button class="msearch-close" aria-label="Close search" onclick="closeMobileSearch()">✕</button>
  </div>
  <div class="msearch-results" id="mobileSearchDropdown"></div>
</div>

<!-- DRAWER & OVERLAY -->
<div id="nav-overlay"></div>
<div id="nav-drawer">
  <div class="drawer-top">
    <div class="drawer-logo"><img src="logo.png" alt="Art Bazaar" style="height:36px;width:auto;display:block;"></div>
    <button class="drawer-close" aria-label="Close menu">✕</button>
  </div>
  <div class="drawer-links">
    <a href="artworks.php">Physical Art</a>
    <a href="digital-art.php">Digital Art</a>
    <a href="artists.php">Artists</a>
    <a href="blog.php">Blog</a>
    <a href="commission.php">Custom Artwork</a>
    <a href="sell.php">Sell Your Art</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    <?php if ($isLoggedIn): ?>
      <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a>
      <a href="logout.php" class="drawer-btn-dark">Logout</a>
    <?php else: ?>
      <a href="login.php" class="drawer-btn-ghost">Login</a>
    <?php endif; ?>
  </div>
</div>
<script>
const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false'; ?>;
// Hamburger drawer
const hamBtn = document.querySelector('.ham-btn');
const navDrawer = document.getElementById('nav-drawer');
const navOverlay = document.getElementById('nav-overlay');
function openDrawer(){ navDrawer.classList.add('open'); navOverlay.classList.add('open'); document.body.style.overflow='hidden'; }
function closeDrawer(){ navDrawer.classList.remove('open'); navOverlay.classList.remove('open'); document.body.style.overflow=''; }
if(hamBtn) hamBtn.addEventListener('click', openDrawer);
if(navOverlay) navOverlay.addEventListener('click', closeDrawer);
document.querySelector('.drawer-close')?.addEventListener('click', closeDrawer);
function toggleQABell() {
  document.getElementById('qaBellDropdown')?.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const wrap = document.querySelector('.qa-bell-wrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('qaBellDropdown')?.classList.remove('open');
  }
});

// ── Live search (physical + digital artworks together, no page redirect) ──
let searchDebounce = null;

function renderSearchResults(dropdownId, results, query) {
  const el = document.getElementById(dropdownId);
  if (!el) return;
  if (!results || results.length === 0) {
    const safeQ = query.replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; });
    el.innerHTML = '<div class="sr-empty">No artworks found for "' + safeQ + '"</div>';
    return;
  }
  el.innerHTML = results.map(function (r) {
    const thumb = r.cover_image
      ? '<img class="sr-thumb" src="' + r.cover_image + '" alt="" loading="lazy">'
      : '<div class="sr-thumb-ph"></div>';
    const tag = r.delivery_type === 'digital' ? '<span class="sr-tag">Digital</span>' : '';
    const sold = r.status === 'sold' ? '<span class="sr-tag">Sold</span>' : '';
    return '<a class="sr-item" href="artwork-detail.php?id=' + r.id + '">' + thumb +
      '<div class="sr-info">' +
        '<div class="sr-title">' + r.title + '</div>' +
        '<div class="sr-by">by ' + r.artist_name + '</div>' +
        '<div class="sr-meta">' + r.price_formatted + tag + sold + '</div>' +
      '</div></a>';
  }).join('');
}

function closeSearchDropdown(dropdownId) {
  document.getElementById(dropdownId)?.classList.remove('open');
}

function handleSearchInput(inputEl, dropdownId) {
  const dropdownEl = document.getElementById(dropdownId);
  const query = inputEl.value.trim();
  clearTimeout(searchDebounce);

  if (query.length < 2) {
    if (dropdownEl) { dropdownEl.classList.remove('open'); dropdownEl.innerHTML = ''; }
    return;
  }

  if (dropdownEl) {
    dropdownEl.classList.add('open');
    dropdownEl.innerHTML = '<div class="sr-loading">Searching…</div>';
  }

  searchDebounce = setTimeout(function () {
    fetch('index.php?ajax_search=1&q=' + encodeURIComponent(query))
      .then(function (res) { return res.json(); })
      .then(function (data) { renderSearchResults(dropdownId, data, query); })
      .catch(function () {
        if (dropdownEl) dropdownEl.innerHTML = '<div class="sr-empty">Something went wrong. Try again.</div>';
      });
  }, 300);
}

// Close the desktop dropdown when clicking outside the search box
document.addEventListener('click', function (e) {
  const wrap = document.querySelector('.nsearch');
  if (wrap && !wrap.contains(e.target)) {
    closeSearchDropdown('desktopSearchDropdown');
  }
});

// Mobile full-screen search overlay
function openMobileSearch() {
  document.getElementById('mobile-search-overlay')?.classList.add('open');
  document.body.style.overflow = 'hidden';
  setTimeout(function () { document.getElementById('mobileSearchInput')?.focus(); }, 50);
}
function closeMobileSearch() {
  document.getElementById('mobile-search-overlay')?.classList.remove('open');
  document.body.style.overflow = '';
  const input = document.getElementById('mobileSearchInput');
  const dd = document.getElementById('mobileSearchDropdown');
  if (input) input.value = '';
  if (dd) dd.innerHTML = '';
}

// Commission Modal Helper
function openCM(id, name) {
    if (!isLoggedIn) {
        window.location.href = 'login.php?redirect=' + encodeURIComponent(window.location.href);
        return;
    }
    const modal = document.getElementById('cm');
    const select = document.getElementById('cm-artist-select');
    modal.classList.add('open');
    if (id && select) {
        select.value = id;
    }
}

<?php if ($commissionError): ?>
document.getElementById('cm')?.classList.add('open');
<?php endif; ?>

// Categories Toggle
function toggleCategories() {
  const row = document.getElementById('cat-row');
  const btn = document.querySelector('.cat-more-btn');
  row.classList.toggle('hidden-cats');
  btn.textContent = row.classList.contains('hidden-cats') ? '+ More categories' : '− Show less';
}
</script>
</body>
</html>