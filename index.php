<?php
session_start();
require_once __DIR__ . '/config/db.php';

function containsContactInfo(string $text): bool {
    $patterns = [
        '/\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i',
        '/(\+92|0)?[-\s]?[0-9]{3}[-\s]?[0-9]{7,8}/',
        '/\b(instagram|insta|ig|whatsapp|wa|facebook|fb|twitter|tiktok|snapchat)\s*[:\-@]?\s*\w+/i',
        '/@[a-zA-Z0-9._]{2,30}/',
        '/\b(iban|account\s*no|bank|easypaisa|jazzcash|sadapay|nayapay)\b/i',
        '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
    ];
    foreach ($patterns as $p) { if (preg_match($p, $text)) return true; }
    return false;
}

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
        $stmt->bind_param("ssssdddss", $buyerName, $buyerEmail, $buyerPhone, $orderNumber, $price, $price, $buyerPhone, $message);
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
}

function getImgUrl($p) {
    if (!$p) return null; $p = ltrim($p, './');
    if (strpos($p, 'uploads/') !== false) return $p;
    return 'uploads/artworks/' . $p;
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
$featuredArtworks = $conn->query("SELECT a.id,a.title,a.price,a.city,a.status,a.reserved_by,u.name AS artist_name,u.id AS artist_id,c.name AS category_name,(SELECT image_path FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_image FROM artworks a JOIN users u ON a.artist_id=u.id JOIN categories c ON a.category_id=c.id JOIN artist_profiles ap ON ap.user_id=u.id WHERE a.status = 'active' AND a.is_featured=1 AND u.status='active' AND ap.profile_complete=1 ORDER BY a.updated_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$latestArtworks   = $conn->query("SELECT a.id,a.title,a.price,a.city,a.status,a.reserved_by,u.name AS artist_name,u.id AS artist_id,c.name AS category_name,(SELECT image_path FROM artwork_images WHERE artwork_id=a.id ORDER BY is_cover DESC,sort_order ASC LIMIT 1) AS cover_image FROM artworks a JOIN users u ON a.artist_id=u.id JOIN categories c ON a.category_id=c.id JOIN artist_profiles ap ON ap.user_id=u.id WHERE a.status = 'active' AND u.status='active' AND ap.profile_complete=1 ORDER BY a.created_at DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);
$featuredArtists  = $conn->query("SELECT u.id,u.name,u.profile_picture,ap.city,ap.art_style,ap.accepts_commissions FROM users u JOIN artist_profiles ap ON u.id=ap.user_id WHERE u.role='artist' AND u.status='active' AND ap.is_featured=1 AND ap.profile_complete=1 ORDER BY u.created_at DESC LIMIT 4")->fetch_all(MYSQLI_ASSOC);
 $categories       = $conn->query("SELECT id,name FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
 $heroArt = $featuredArtworks[0] ?? $latestArtworks[0] ?? null;
 $latestBlogPosts = $conn->query("SELECT bp.id, bp.title, bp.slug, bp.content, bp.featured_image, bp.published_at, bp.created_at, u.name AS author_name FROM blog_posts bp JOIN users u ON u.id = bp.author_id WHERE bp.status = 'published' ORDER BY bp.published_at DESC LIMIT 4")->fetch_all(MYSQLI_ASSOC);

 $catIcons = [
    'Painting'     => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>',
    'Sketch'       => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
    'Digital Art'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
    'Calligraphy'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
    'Photography'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>',
    'Illustration' => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
    'Mixed Media'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>',
    'Portrait'     => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'Custom Orders'=> '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Art Bazaar — Original Art from Pakistani Artists</title>
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
  --terr:#0C3F30;
  --w:1280px; --r:10px;
}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{display:block;max-width:100%;}

/* TOAST NOTIFICATION */
.toast{position:fixed;bottom:24px;right:24px;background:var(--ink);color:#fff;padding:12px 20px;border-radius:8px;font-size:13px;z-index:600;display:flex;align-items:center;gap:10px;transform:translateX(400px);transition:transform .3s ease;box-shadow:0 4px 12px rgba(0,0,0,.15);}
.toast.show{transform:translateX(0);}
.toast svg{width:16px;height:16px;flex-shrink:0;}
.toast a{color:var(--sand);text-decoration:underline;margin-left:8px;}

/* ─── NAV ─── */
.nav{background:var(--ink);border-bottom:1px solid var(--ink);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:4px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--bg);padding:6px 10px;border-radius:6px;transition:background .12s;}
.nlinks a:hover{background:var(--sand); color: var(--ink);}
.nlinks a.dd::after{content:' ▾';font-size:9px;opacity:.4;}
.nsearch{display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--sand);border-radius:6px;padding:6px 12px;width:210px;flex-shrink:0;transition:border-color .15s;}
.nsearch:focus-within{border-color:var(--ink);}
.nsearch input{border:none;background:transparent;font-size:12.5px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;width:100%;}
.nsearch input::placeholder{color:var(--ink); opacity: 0.6;}
.nsearch svg{color:var(--ink); opacity: 0.6; flex-shrink:0;}
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand); color: var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── HERO ─── */
.hero{max-width:var(--w);margin:0 auto;padding:44px 28px 36px;display:grid;grid-template-columns:1.05fr 1fr;gap:48px;align-items:center;}
.htag{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;letter-spacing:1.5px;text-transform:uppercase;color:var(--ink);margin-bottom:14px;}
.htag-dot{width:5px;height:5px;border-radius:50%;background:var(--sand);flex-shrink:0;}
h1.htitle{font-family:'Playfair Display',serif;font-size:clamp(32px,3.4vw,48px);font-weight:400;line-height:1.1;color:var(--ink);margin-bottom:12px;}
h1.htitle em{font-style:italic;color:var(--ink);}
.hdesc{font-size:13.5px;color:var(--ink);line-height:1.65;max-width:420px;margin-bottom:22px;}
.hbtns{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:20px;}
.btn-fill{display:inline-flex;align-items:center;gap:6px;background:var(--sand);color:var(--ink);padding:10px 20px;border-radius:7px;font-size:13px;font-weight:500;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s;}
.btn-fill:hover{background:#c4b69e;}
.btn-line{display:inline-flex;align-items:center;gap:6px;background:transparent;color:var(--ink);padding:9px 16px;border-radius:7px;font-size:13px;border:1px solid var(--ink);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;}
.btn-line:hover{border-color:var(--sand);background:var(--sand); color: var(--ink);}
.htrust{display:flex;align-items:center;gap:18px;}
.trust-i{display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--ink);}
.trust-i svg{color:var(--ink);}
.himg{position:relative;border-radius:0;overflow:hidden;background:#F6EDDE;}
.himg img{width:100%;height:auto;object-fit:contain;object-position:center;}
.himg-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.himg-ph svg{opacity:.18;color:var(--ink);}
.hbadge{position:absolute;bottom:14px;left:14px;background:rgba(12,63,48,.84);backdrop-filter:blur(6px);color:var(--bg);border-radius:8px;padding:9px 13px;}
.hbadge-l{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;opacity:.5;margin-bottom:2px;}
.hbadge-v{font-family:'Playfair Display',serif;font-size:14px;}

/* ─── LAYOUT ─── */
.wrap{max-width:var(--w);margin:0 auto;padding:0 28px;}
.sec{padding:32px 0;}
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
.sec-title{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--ink);}
.sec-lnk{font-size:12px;color:var(--ink);border-bottom:1px solid var(--border);padding-bottom:1px;transition:color .12s,border-color .12s;}
.sec-lnk:hover{color:var(--ink);border-color:var(--sand);}
.divhr{border:none;border-top:1px solid var(--border);}

/* ─── CATEGORIES ─── */
.cat-row{display:grid;grid-template-columns:repeat(9,1fr);gap:8px;}
.cat-card{background:var(--card);border:1px solid var(--border);border-radius:9px;padding:14px 8px 11px;text-align:center;cursor:pointer;transition:all .15s;}
.cat-card:hover{border-color:var(--sand);background:var(--sand);transform:translateY(-1px);}
.cat-card svg{color:var(--ink);margin:0 auto 7px;display:block;}
.cat-card:hover svg{color:var(--ink);}
.cat-card span{font-size:11px;color:var(--body);font-weight:500;display:block;line-height:1.3;}
.cat-more-btn{display:none;width:100%;padding:10px;background:transparent;border:1px solid var(--sand);color:var(--ink);border-radius:9px;font-family:'DM Sans',sans-serif;font-weight:500;cursor:pointer;margin-top:12px;transition:all .15s;}
.cat-more-btn:hover{background:var(--sand);border-color:var(--ink);}
.cat-row.hidden-cats .cat-item:nth-child(n+7){display:none;}

.qa-bell-wrap{position:relative;}
.qa-bell-btn{position:relative;background:transparent;border:1px solid rgba(246,237,222,.3);border-radius:7px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--bg);transition:background .12s,border-color .12s;}
.qa-bell-btn:hover{background:rgba(246,237,222,.1);border-color:var(--sand);}
.qa-bell-count{position:absolute;top:-5px;right:-5px;background:#c0392b;color:#fff;font-size:9.5px;font-weight:600;line-height:1;min-width:16px;height:16px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 3px;}
.qa-bell-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;width:300px;max-height:380px;overflow-y:auto;background:var(--card);border:1px solid var(--border);border-radius:10px;box-shadow:0 12px 30px rgba(12,63,48,.18);z-index:300;}
.qa-bell-dropdown.open{display:block;}
.qa-bell-hd{font-size:11px;letter-spacing:1px;text-transform:uppercase;font-weight:600;color:var(--ink);padding:12px 16px 10px;border-bottom:1px solid var(--sand);}
.qa-bell-empty{font-size:12.5px;opacity:.55;font-style:italic;padding:18px 16px;}
.qa-bell-item{display:block;padding:12px 16px;border-bottom:1px solid var(--sand);transition:background .12s;}
.qa-bell-item:last-child{border-bottom:none;}
.qa-bell-item:hover{background:var(--sand);}
.qa-bell-item-title{font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:3px;}
.qa-bell-item-q{font-size:11.5px;color:var(--ink);opacity:.75;margin-bottom:5px;line-height:1.4;}
.qa-bell-item-tag{font-size:10px;font-weight:600;color:#0C3F30;background:#e6f4ef;display:inline-block;padding:2px 8px;border-radius:20px;}
@media(max-width:768px){
  .qa-bell-dropdown{position:fixed;top:58px;right:8px;left:8px;width:auto;}
}
/* ─── ARTWORK CARD ─── */
.aw-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;transition:transform .15s,box-shadow .15s;}
.aw-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.09);}
.aw-img{aspect-ratio:1;overflow:hidden;position:relative;background:var(--sand);cursor:pointer;}
.aw-img img{width:100%;height:100%;object-fit:cover;transition:transform .3s;}
.aw-card:hover .aw-img img{transform:scale(1.04);}
.aw-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.aw-ph svg{opacity:.16;color:var(--ink);}
.aw-sold-tag{position:absolute;top:7px;right:7px;background:rgba(12,63,48,.78);color:var(--bg);font-size:8.5px;letter-spacing:.8px;text-transform:uppercase;padding:3px 7px;border-radius:4px;}
.aw-body{padding:10px 11px 0;}
.aw-title{font-size:12.5px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:1px;cursor:pointer;}
.aw-by{font-size:11px;color:var(--ink);margin-bottom:8px;cursor:pointer;}
.aw-by span{color:var(--ink);}
.aw-foot{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.aw-price{font-size:13px;font-weight:600;color:var(--ink);}
.aw-price small{font-size:9.5px;font-weight:400;color:var(--ink);margin-right:1px;}
.aw-cat{font-size:10px;color:var(--ink);background:var(--sand);padding:2px 7px;border-radius:20px;}
.aw-buy-btn{display:block;width:calc(100% - 22px);margin:0 11px 11px;background:var(--sand);color:var(--ink);border:none;border-radius:6px;padding:7px;font-size:11.5px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .12s;text-align:center;}
.aw-buy-btn:hover{background:#c4b69e;}
.aw-add-cart{display:block;width:calc(100% - 22px);margin:0 11px 11px;background:var(--sand);color:var(--ink);border:none;border-radius:6px;padding:7px;font-size:11.5px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .12s;text-align:center;}
.aw-add-cart:hover{background:#c4b69e;}

/* ─── TWO COL MID ─── */
.mid{display:grid;grid-template-columns:1fr 1fr;gap:28px;padding:32px 0;}
.feat-aw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;}

/* ─── ARTIST CARDS ─── */
.ar-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:11px;}
.ar-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:18px 15px;text-align:center;transition:box-shadow .15s;}
.ar-card:hover{box-shadow:0 6px 20px rgba(12,63,48,.07);}
.ar-av{width:60px;height:60px;border-radius:50%;margin:0 auto 10px;overflow:hidden;background:var(--sand);border:2px solid var(--border);}
.ar-av img{width:100%;height:100%;object-fit:cover;}
.ar-av-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:'Playfair Display',serif;font-size:20px;color:var(--ink);}
.ar-name{font-family:'Playfair Display',serif;font-size:14.5px;font-weight:400;color:var(--ink);margin-bottom:1px;}
.ar-style{font-size:11px;color:var(--ink);margin-bottom:1px;}
.ar-city{font-size:10.5px;color:var(--ink);margin-bottom:11px;}
.ar-btns{display:flex;gap:6px;justify-content:center;}
.ar-btn{font-size:10.5px;padding:5px 11px;border-radius:5px;border:1px solid var(--border);background:transparent;color:var(--ink);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.ar-btn:hover{background:var(--sand);}
.ar-btn.p{background:var(--ink);color:var(--bg);border-color:var(--ink);}
.ar-btn.p:hover{background:var(--sand); color: var(--ink);}

/* ─── HOW IT WORKS ─── */
.how-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.how-card{padding:18px 14px;}
.how-n{width:32px;height:32px;border-radius:50%;background:var(--ink);color:var(--bg);font-family:'Playfair Display',serif;font-size:15px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;}
.how-icon{color:var(--ink);margin-bottom:8px;}
.how-t{font-size:13px;font-weight:600;color:var(--ink);margin-bottom:3px;}
.how-d{font-size:11.5px;color:var(--ink);line-height:1.55;}

/* ─── COMMISSION STRIP ─── */
.comm-strip{background:var(--ink);border-radius:12px;display:grid;grid-template-columns:1fr auto;align-items:center;gap:28px;padding:34px 40px;position:relative;overflow:hidden;}
.comm-strip::before{content:'';position:absolute;right:-40px;top:-40px;width:180px;height:180px;border:1px solid rgba(246,237,222,.05);border-radius:50%;}
.cs-l{position:relative;z-index:1;}
.cs-tag{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:8px;}
.cs-title{font-family:'Playfair Display',serif;font-size:clamp(20px,2.2vw,28px);font-weight:400;color:var(--bg);line-height:1.25;margin-bottom:7px;}
.cs-desc{font-size:12.5px;color:rgba(246,237,222,.48);max-width:440px;line-height:1.6;}
.cs-r{position:relative;z-index:1;flex-shrink:0;}
.btn-gold{display:inline-flex;align-items:center;gap:7px;background:var(--sand);color:var(--ink);padding:11px 22px;border-radius:7px;font-size:13px;font-weight:600;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s;white-space:nowrap;}
.btn-gold:hover{background:#c4b69e;}

/* ─── LATEST ARTWORKS ─── */
.latest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:14px;}
.blog-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
.post-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;transition:transform .15s,box-shadow .15s;cursor:pointer;display:flex;flex-direction:column;}
.post-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.09);}
.pc-img{aspect-ratio:4/3;overflow:hidden;position:relative;background:var(--sand);}
.pc-img img{width:100%;height:100%;object-fit:contain;transition:transform .3s;}
.post-card:hover .pc-img img{transform:scale(1.04);}
.pc-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
.pc-ph svg{opacity:.16;color:var(--ink);}
.pc-body{padding:16px 18px 18px;flex:1;display:flex;flex-direction:column;}
.pc-title{font-family:'Playfair Display',serif;font-size:17px;font-weight:400;color:var(--ink);line-height:1.3;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.pc-excerpt{font-size:12.5px;color:var(--ink);opacity:.65;line-height:1.55;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex:1;}
.pc-meta{display:flex;align-items:center;justify-content:space-between;font-size:11px;color:var(--ink);opacity:.5;}
.pc-meta span{display:flex;align-items:center;gap:4px;}
.pc-meta svg{width:12px;height:12px;}
@media(max-width:1080px){.blog-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){.blog-grid{grid-template-columns:repeat(2,1fr);}}

/* ─── MODALS ─── */
.mbg{display:none;position:fixed;inset:0;background:rgba(12,63,48,.58);backdrop-filter:blur(3px);z-index:500;align-items:center;justify-content:center;padding:16px;}
.mbg.open{display:flex;}
.modal{background:var(--card);border-radius:14px;width:100%;max-width:490px;max-height:92vh;overflow-y:auto;}
.mhd{padding:22px 24px 0;display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;}
.mhd h3{font-family:'Playfair Display',serif;font-size:21px;font-weight:400;color:var(--ink);}
.mcls{background:var(--sand);border:none;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--ink);flex-shrink:0;}
.mcls:hover{background:var(--border);}
.mbd{padding:14px 24px 24px;}
.fg{margin-bottom:12px;}
.fg label{display:block;font-size:10.5px;letter-spacing:.7px;text-transform:uppercase;color:var(--body);font-weight:500;margin-bottom:5px;}
.fg label span{color:var(--ink);}
.fi,.fs,.ft{width:100%;padding:9px 12px;border:1.5px solid var(--sand);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--bg);outline:none;transition:border-color .12s;}
.fi:focus,.fs:focus,.ft:focus{border-color:var(--ink);}
.ft{min-height:86px;resize:vertical;line-height:1.55;}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.fr3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.msub{width:100%;background:var(--sand);color:var(--ink);border:none;padding:11px;border-radius:8px;font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;margin-top:2px;transition:background .12s;}
.msub:hover{background:#c4b69e;}
.mmsg{padding:9px 12px;border-radius:7px;font-size:12px;margin-bottom:12px;}
.mmsg.ok{background:var(--sand);color:var(--ink);border:1px solid var(--border);}
.mmsg.er{background:var(--sand);color:var(--ink);border:1px solid var(--border);}

/* ─── FOOTER ─── */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;line-height:1.65;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;transition:color .12s;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── MOBILE HAMBURGER & DRAWER GLOBAL STYLES ─── */
#nav-drawer { display:none; }
#nav-overlay { display:none; }

/* ─── RESPONSIVE ─── */
@media(max-width:1080px){
  .cat-row{grid-template-columns:repeat(5,1fr);}
  .mid{grid-template-columns:1fr;}
  .ar-grid{grid-template-columns:repeat(4,1fr);}
  .how-grid{grid-template-columns:repeat(2,1fr);}
  .fg-foot{grid-template-columns:1fr 1fr;}
}
@media(max-width:768px){
  .nlinks,.nsearch{display:none;}
  .hero{grid-template-columns:1fr;padding:28px 16px;gap:28px;}
  .himg{display:block;}
  .wrap{padding:0 16px;}
  .cat-row{grid-template-columns:repeat(3,1fr);}
  .cat-row.hidden-cats .cat-item:nth-child(n+7){display:none;}
  .cat-more-btn{display:block;}
  .feat-aw-grid{grid-template-columns:repeat(2,1fr);}
  .ar-grid{grid-template-columns:1fr 1fr;}
  .latest-grid{grid-template-columns:repeat(2,1fr);}
  .how-grid{grid-template-columns:repeat(2,1fr);}
  .comm-strip{grid-template-columns:1fr;padding:26px 22px;gap:18px;}
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;}
  .fbot{flex-direction:column;gap:12px;text-align:center;font-size:10px;padding-top:14px;}
  .nend .btn-ghost, .nend .btn-dark, .nend span { display:none; }
  .ham-btn { display:flex; flex-direction:column; justify-content:center; gap:5px; background:transparent; border:none; cursor:pointer; padding:6px; margin-left:auto;}
  .ham-btn span { display:block; width:22px; height:2px; background:var(--bg); border-radius:2px; }
  #nav-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:298; }
  #nav-overlay.open { display:block; }
  #nav-drawer { display:flex; flex-direction:column; position:fixed; top:0; right:0; width:75vw; max-width:300px; height:100vh; background:var(--ink); z-index:299; transform:translateX(100%); transition:transform 0.3s ease; padding:0; overflow-y:auto; }
  #nav-drawer.open { transform:translateX(0); }
  .drawer-top { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid rgba(246,237,222,0.1); }
  .drawer-logo b { font-family:'Playfair Display',serif; font-size:16px; color:var(--bg); display:block; }
  .drawer-logo small { font-size:7px; letter-spacing:2px; text-transform:uppercase; color:var(--sand); }
  .drawer-close { background:transparent; border:none; color:var(--bg); font-size:18px; cursor:pointer; padding:4px; }
  .drawer-links { display:flex; flex-direction:column; padding:12px 0; }
  .drawer-links a { color:var(--bg); font-size:14px; padding:13px 20px; border-bottom:1px solid rgba(246,237,222,0.07); transition:background 0.12s; }
  .drawer-links a:hover { background:rgba(246,237,222,0.06); }
  .drawer-actions { margin-top:auto; padding:20px; display:flex; flex-direction:column; gap:10px; border-top:1px solid rgba(246,237,222,0.1); }
  .drawer-btn-ghost { font-size:13px; color:var(--bg); padding:9px 14px; border-radius:6px; border:1px solid rgba(246,237,222,0.4); text-align:center; transition:all 0.12s; }
  .drawer-btn-ghost:hover { border-color:var(--sand); background:rgba(246,237,222,0.08); }
  .drawer-btn-dark { font-size:13px; color:var(--ink); padding:9px 14px; border-radius:6px; background:var(--sand); text-align:center; font-weight:500; transition:background 0.12s; }
  .drawer-btn-dark:hover { background:#c4b69e; }
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nw">
    <a href="index.php" class="nlogo"><img src="logo.png" alt="Art Bazaar" style="height:36px;width:auto;display:block;"></a>
    <div class="nlinks">
      <a href="artworks.php" class="dd">Explore Art</a>
      <a href="artists.php">Artists</a>
      <a href="blog.php">Blog</a> 
      <a href="commission.php">Custom Artwork</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search artworks, artists..." onkeydown="if(event.key==='Enter'){window.location='artworks.php?q='+encodeURIComponent(this.value);}">
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
        <span style="font-size:12.5px;color:var(--bg);">Hi, <?= htmlspecialchars($_SESSION['name'] ?? 'Buyer') ?></span>
        <a href="dashboard/buyer/account.php" class="btn-ghost">My Account</a>
        <a href="logout.php" class="btn-dark">Logout</a>
      <?php else: ?>
        <a href="login.php" class="btn-ghost">Login</a>
        <a href="register.php" class="btn-dark">Join as Artist</a>
      <?php endif; ?>

      <button class="ham-btn" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div>
    <div class="htag"><span class="htag-dot"></span>Pakistan's Art Marketplace</div>
    <h1 class="htitle">Discover and buy<br>original artwork from<br><em>Pakistani artists.</em></h1>
    <p class="hdesc">A platform dedicated to supporting Pakistani talent. Explore paintings, sketches, calligraphy, digital art and request custom commissions.</p>
    <div class="hbtns">
      <a href="artworks.php" class="btn-fill">Explore Artworks <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
      <a href="sell.php" class="btn-line">Sell Your Art <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
    </div>
    <div class="htrust">
      <div class="trust-i"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>Original Artworks</div>
      <div class="trust-i"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Verified Artists</div>
      <div class="trust-i"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10 10z"/></svg>Secure & Trusted</div>
    </div>
  </div>
  <div class="himg">
    <img src="indexhero.jpeg" alt="Art Bazaar Hero">
</div>
</section>
<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'artist'): ?>
<div style="background:var(--ink);border-bottom:1px solid rgba(246,237,222,0.15);">
    <div style="max-width:var(--w);margin:0 auto;padding:10px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <span style="font-size:12.5px;color:var(--sand);">You're logged in as an artist — manage your artworks and orders from your dashboard.</span>
        <a href="dashboard/artist/index.php" style="display:inline-flex;align-items:center;gap:6px;background:var(--sand);color:var(--ink);padding:8px 16px;border-radius:6px;font-size:12.5px;font-weight:500;white-space:nowrap;text-decoration:none;transition:background .12s;" onmouseover="this.style.background='#c4b69e'" onmouseout="this.style.background='var(--sand)'">
            Go to Artist Dashboard →
        </a>
    </div>
</div>
<?php elseif (!isset($_SESSION['user_id'])): ?>
<div style="background:var(--ink);border-bottom:1px solid rgba(246,237,222,0.15);">
    <div style="max-width:var(--w);margin:0 auto;padding:10px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <span style="font-size:12.5px;color:var(--sand);">Are you an artist? Join Art Bazaar and start selling your work today.</span>
        <a href="register.php" style="display:inline-flex;align-items:center;gap:6px;background:var(--sand);color:var(--ink);padding:8px 16px;border-radius:6px;font-size:12.5px;font-weight:500;white-space:nowrap;text-decoration:none;transition:background .12s;" onmouseover="this.style.background='#c4b69e'" onmouseout="this.style.background='var(--sand)'">
            Start as an Artist →
        </a>
    </div>
</div>
<?php endif; ?>

<div class="wrap"><hr class="divhr"></div>

<!-- CATEGORIES -->
<div class="wrap"><hr class="divhr"></div>

<!-- CATEGORIES -->
<div class="wrap"><div class="sec">
  <div class="sec-hd"><h2 class="sec-title">Explore by Category</h2><a href="artworks.php" class="sec-lnk">View all categories</a></div>
  <div class="cat-row hidden-cats" id="cat-row">
    <?php foreach ($categories as $cat): ?>
    <a href="artworks.php?category=<?= $cat['id'] ?>" class="cat-card cat-item">
      <?= $catIcons[$cat['name']] ?? $catIcons['Custom Orders'] ?>
      <span><?= htmlspecialchars($cat['name']) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <button class="cat-more-btn" onclick="toggleCategories()">+ More Categories</button>
</div></div>

<div class="wrap"><hr class="divhr"></div>

<!-- FEATURED ARTWORKS + FEATURED ARTISTS -->
<div class="wrap"><div class="mid">
  <div>
    <div class="sec-hd"><h2 class="sec-title">Featured Artworks</h2><a href="artworks.php?featured=1" class="sec-lnk">View all artworks</a></div>
    <?php if (empty($featuredArtworks)): ?>
      <p style="color:var(--ink);font-size:13px;padding:16px 0;">No featured artworks yet.</p>
    <?php else: ?>
    <div class="feat-aw-grid">
      <?php foreach ($featuredArtworks as $art): $img = getImgUrl($art['cover_image']); ?>
      <div class="aw-card">
        <a href="artwork-detail.php?id=<?= $art['id'] ?>" class="aw-img" style="display:block;">
          <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><div class="aw-ph"><svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div><?php endif; ?>
          <?php if ($art['status']==='sold'): ?><span class="aw-sold-tag">Sold</span><?php endif; ?>
        </a>
        <div class="aw-body">
          <a href="artwork-detail.php?id=<?= $art['id'] ?>" class="aw-title" style="display:block;text-decoration:none;"><?= htmlspecialchars($art['title']) ?></a>
          <div class="aw-by" onclick="location.href='artist-profile.php?id=<?= $art['artist_id'] ?>'">by <span><?= htmlspecialchars($art['artist_name']) ?></span></div>
          <div class="aw-foot"><div class="aw-price"><small>Rs. </small><?= number_format($art['price']) ?></div><span class="aw-cat"><?= htmlspecialchars($art['category_name']) ?></span></div>
          <?php if ($art['status'] === 'sold'): ?>
  <button class="aw-buy-btn" disabled style="opacity:0.5;cursor:not-allowed;background:#ccc;">🚫 Sold Out</button>
<?php elseif ($isLoggedIn): ?>
  <a href="checkout.php?artwork_id=<?= $art['id'] ?>" class="aw-add-cart" style="text-decoration:none;">🛒 Buy Now</a>
<?php else: ?>
  <a href="login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="aw-add-cart" style="text-decoration:none;">Login to Buy</a>
<?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="sec-hd"><h2 class="sec-title">Featured Artists</h2><a href="artists.php" class="sec-lnk">View all artists</a></div>
    <?php if (empty($featuredArtists)): ?>
      <p style="color:var(--ink);font-size:13px;padding:16px 0;">No featured artists yet.</p>
    <?php else: ?>
    <div class="ar-grid">
      <?php foreach ($featuredArtists as $a): $pp = getProfileUrl($a['profile_picture']); ?>
      <div class="ar-card">
        <div class="ar-av">
          <?php if ($pp): ?><img src="<?= htmlspecialchars($pp) ?>" alt=""><?php else: ?><div class="ar-av-ph"><?= strtoupper(substr($a['name'],0,1)) ?></div><?php endif; ?>
        </div>
        <div class="ar-name"><?= htmlspecialchars($a['name']) ?></div>
        <div class="ar-style"><?= htmlspecialchars($a['art_style'] ?? 'Artist') ?></div>
        <div class="ar-city"><?= htmlspecialchars($a['city'] ?? '') ?></div>
        <div class="ar-btns">
          <a href="artist-profile.php?id=<?= $a['id'] ?>" class="ar-btn">View Profile</a>
          <?php if ($a['accepts_commissions']): ?><button class="ar-btn p" onclick="openCM(<?= $a['id'] ?>,'<?= addslashes($a['name']) ?>')">Request Custom Art</button><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div></div>

<div class="wrap"><hr class="divhr"></div>

<!-- HOW IT WORKS -->
<div class="wrap"><div class="sec">
  <div class="sec-hd"><h2 class="sec-title">How it works</h2></div>
  <div class="how-grid">
    <div class="how-card">
      <div class="how-n">1</div>
      <div class="how-icon"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg></div>
      <div class="how-t">Explore</div>
      <div class="how-d">Browse thousands of original artworks from verified Pakistani artists.</div>
    </div>
    <div class="how-card">
      <div class="how-n">2</div>
      <div class="how-icon"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
      <div class="how-t">Buy Instantly</div>
      <div class="how-d">Click Buy Now and go straight to a secure checkout — no cart needed.</div>
    </div>
    <div class="how-card">
      <div class="how-n">3</div>
      <div class="how-icon"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div>
      <div class="how-t">Confirm Order</div>
      <div class="how-d">Review your cart and complete the purchase with delivery details.</div>
    </div>
    <div class="how-card">
      <div class="how-n">4</div>
      <div class="how-icon"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></div>
      <div class="how-t">Receive</div>
      <div class="how-d">Get your artwork safely delivered to your door anywhere in Pakistan.</div>
    </div>
  </div>
</div></div>

<div class="wrap" style="padding:28px 28px;"><div class="comm-strip">
  <div class="cs-l">
    <div class="cs-tag">Custom Commissions</div>
    <h2 class="cs-title">Want something<br>made just for you?</h2>
    <p class="cs-desc">Request a custom artwork from our talented Pakistani artists. Portraits, calligraphy, illustrations — anything you can imagine.</p>
  </div>
  <div class="cs-r">
    <button class="btn-gold" onclick="document.getElementById('cm').classList.add('open')">Request Custom Artwork <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></button>
  </div>
</div></div>

<div class="wrap"><hr class="divhr"></div>

<!-- LATEST ARTWORKS -->
<div class="wrap"><div class="sec">
  <div class="sec-hd"><h2 class="sec-title">Latest Artworks</h2><a href="artworks.php" class="sec-lnk">View all</a></div>
  <div class="latest-grid">
    <?php foreach ($latestArtworks as $art): $img = getImgUrl($art['cover_image']); ?>
    <div class="aw-card">
      <a href="artwork-detail.php?id=<?= $art['id'] ?>" class="aw-img" style="display:block;">
        <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><div class="aw-ph"><svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div><?php endif; ?>
        <?php if ($art['status']==='sold'): ?><span class="aw-sold-tag">Sold</span><?php endif; ?>
      </a>
      <div class="aw-body">
        <a href="artwork-detail.php?id=<?= $art['id'] ?>" class="aw-title" style="display:block;text-decoration:none;"><?= htmlspecialchars($art['title']) ?></a>
        <div class="aw-by" onclick="location.href='artist-profile.php?id=<?= $art['artist_id'] ?>'">by <span><?= htmlspecialchars($art['artist_name']) ?></span></div>
        <div class="aw-foot"><div class="aw-price"><small>Rs. </small><?= number_format($art['price']) ?></div><span class="aw-cat"><?= htmlspecialchars($art['category_name']) ?></span></div>
        <?php if ($art['status'] === 'sold'): ?>
  <button class="aw-buy-btn" disabled style="opacity:0.5;cursor:not-allowed;background:#ccc;">🚫 Sold Out</button>
<?php elseif ($isLoggedIn): ?>
  <a href="checkout.php?artwork_id=<?= $art['id'] ?>" class="aw-add-cart" style="text-decoration:none;">🛒 Buy Now</a>
<?php else: ?>
  <a href="login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="aw-add-cart" style="text-decoration:none;">Login to Buy</a>
<?php endif; ?>
      </div> 
    </div>
    <?php endforeach; ?>
  </div>
</div></div>

<div class="wrap"><hr class="divhr"></div>

<!-- LATEST INSIGHTS -->
<div class="wrap"><div class="sec">
  <div class="sec-hd"><h2 class="sec-title">Latest Insights</h2><a href="blog.php" class="sec-lnk">View all posts</a></div>
  <?php if (empty($latestBlogPosts)): ?>
    <p style="color:var(--ink);font-size:13px;padding:16px 0;">No blog posts yet.</p>
  <?php else: ?>
  <div class="blog-grid">
    <?php foreach ($latestBlogPosts as $p): ?>
    <a href="blog-post.php?slug=<?= htmlspecialchars($p['slug']) ?>" class="post-card">
      <div class="pc-img">
        <?php if ($p['featured_image']): ?>
          <img src="<?= htmlspecialchars($p['featured_image']) ?>" alt="" loading="lazy">
        <?php else: ?>
        <div class="pc-ph">
          <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><path d="M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M7 8h10M7 12h6"/><path d="M17 16l2 2"/></svg>
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
</div></div>

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="artworks.php?featured=1">Featured</a></div>
      <div class="fc"><h4>For Artists</h4><a href="sell.php">How to Sell</a><a href="register.php">Join as Artist</a><a href="login.php">Artist Login</a></div>
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Custom Artwork</a><a href="terms.php">Terms & Conditions</a><a href="privacy-policy.php">Privacy & Policies</a></div>
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
      <p style="font-size:12px;color:var(--muted);margin-bottom:8px;">Fill out the form and we'll connect you with the perfect artist. Your details are safe with us.</p>
<p style="font-size:11px;color:var(--ink);background:var(--sand);border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:12px;line-height:1.6;">Submit your custom artwork request. The artist/platform will review the details, confirm pricing, timeline, and shipping before payment. <strong>Official payment instructions will only be shared by Art Bazaar Pakistan.</strong></p>
      
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
          <p style="font-size:10px;color:var(--muted);margin-top:4px;">Used only by Art Bazaar Pakistan for order updates.</p>
        </div>
        
        <div class="fg">
          <label>Preferred Artist <span style="font-size:10px;color:var(--muted);font-weight:400;">(optional)</span></label>
          <select name="requested_artist_id" class="fs" id="cm-artist-select">
            <option value="">— Any artist (we'll find the best match) —</option>
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
        <p style="font-size:10px;color:var(--muted);margin-top:-8px;margin-bottom:12px;">This is an estimated budget. Final pricing will be confirmed after review.</p>
        <div class="fg">
          <label>Preferred Deadline</label>
          <input type="date" name="deadline" class="fi">
        </div>
        
        <!-- ADDED NEW FIELDS -->
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
          <label>Reference Image <span style="font-size:10px;color:var(--muted);font-weight:400;">(optional, max 10MB)</span></label>
          <input type="file" name="reference_image" class="fi" accept="image/jpeg,image/png,image/webp,image/gif">
          <p style="font-size:10px;color:var(--muted);margin-top:4px;">Upload a reference image to help the artist understand your idea better.</p>
        </div>
        
        <button type="submit" class="msub">Submit Commission Request</button>
      </form>
    </div>
  </div>
</div>

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
    <a href="blog.php">Blog</a>
    <a href="commission.php" class="active">Custom Artwork</a>
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
      <a href="register.php" class="drawer-btn-dark">Join as Artist</a>
    <?php endif; ?>
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
function toggleQABell() {
  document.getElementById('qaBellDropdown')?.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const wrap = document.querySelector('.qa-bell-wrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('qaBellDropdown')?.classList.remove('open');
  }
});

// Commission Modal Helper
function openCM(id, name) {
    const modal = document.getElementById('cm');
    const select = document.getElementById('cm-artist-select');
    modal.classList.add('open');
    if (id && select) {
        select.value = id;
    }
}

// Categories Toggle
function toggleCategories() {
  const row = document.getElementById('cat-row');
  const btn = document.querySelector('.cat-more-btn');
  row.classList.toggle('hidden-cats');
  btn.textContent = row.classList.contains('hidden-cats') ? '+ More Categories' : '- Show Less';
}
</script>
</body>
</html>