<?php
session_start();
require_once __DIR__ . '/config/db.php';

// ── Auth guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    $_SESSION['redirect_after_login'] = 'cart.php';
    header('Location: login.php');
    exit;
}

 $buyerId = (int) $_SESSION['user_id'];
 $message = '';
 $error = '';
 $isLoggedIn = true; // For drawer state

// ── Helper Functions ───────────────────────────────────
function getImageUrl($path) {
    if (!$path) return null;
    $path = ltrim($path, './');
    if (strpos($path, 'uploads/') !== false) return $path;
    return 'uploads/artworks/' . $path;
}

// ── Handle POST Actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemType = $_POST['item_type'] ?? '';
    $itemId = (int)($_POST['item_id'] ?? 0);
    
    // Remove item from cart
    if ($action === 'remove') {
        $stmt = $conn->prepare("DELETE FROM shopping_cart WHERE buyer_id = ? AND item_type = ? AND item_id = ?");
        $stmt->bind_param('isi', $buyerId, $itemType, $itemId);
        $stmt->execute();
        $message = 'Item removed from cart.';
    }
    
    // Update quantity (artwork items only — commissions are always qty 1)
    if ($action === 'update_qty' && $itemType === 'artwork') {
        $quantity = (int)($_POST['quantity'] ?? 1);
        if ($quantity <= 0) $quantity = 1;
        $stmt = $conn->prepare("UPDATE shopping_cart SET quantity = ? WHERE buyer_id = ? AND item_type = ? AND item_id = ?");
        $stmt->bind_param('iisi', $quantity, $buyerId, $itemType, $itemId);
        $stmt->execute();
        $message = 'Cart updated.';
    }
    
    // Clear entire cart
    if ($action === 'clear') {
        $conn->query("DELETE FROM shopping_cart WHERE buyer_id = $buyerId");
        $message = 'Cart cleared.';
    }
}

// ── Sync Session Cart to Database ────────────────────────
// Only sync ARTWORK items — commissions now skip the cart entirely
if (!isset($_SESSION['user_id']) && isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $sessionItem) {
        $type = $sessionItem['type'] ?? 'artwork';
        $id = (int)($sessionItem['artwork_id'] ?? $sessionItem['id'] ?? 0);
        $qty = (int)($sessionItem['quantity'] ?? 1);
        
        // Block commission items from being added via session sync
        if ($type === 'commission') {
            continue;
        }
        
        if ($id > 0) {
            $check = $conn->prepare("SELECT quantity FROM shopping_cart WHERE buyer_id = ? AND item_type = ? AND item_id = ?");
            $check->bind_param('isi', $buyerId, $type, $id);
            $check->execute();
            $res = $check->get_result();
            
            if ($res->num_rows > 0) {
                $existing = $res->fetch_assoc();
                $newQty = $existing['quantity'] + $qty;
                $upd = $conn->prepare("UPDATE shopping_cart SET quantity = ? WHERE buyer_id = ? AND item_type = ? AND item_id = ?");
                $upd->bind_param('iisi', $newQty, $buyerId, $type, $id);
                $upd->execute();
            } else {
                $ins = $conn->prepare("INSERT INTO shopping_cart (buyer_id, item_type, item_id, quantity) VALUES (?, ?, ?, ?)");
                $ins->bind_param('isii', $buyerId, $type, $id, $qty);
                $ins->execute();
            }
        }
    }
    unset($_SESSION['cart']);
}

// ── Fetch cart items with details ─────────────────────────
 $cartItems = [];
 $artworkSubtotal = 0;
 $hasCommissionItems = false;

 // Added co.price_status to the SELECT to check if price is accepted
 $cartQuery = $conn->prepare("
    SELECT sc.item_type, sc.item_id, sc.quantity,
           a.id AS artwork_id, a.title AS artwork_title, a.price AS artwork_price, 
           a.status AS artwork_status,
           (SELECT ai.image_path FROM artwork_images ai WHERE ai.artwork_id = a.id AND ai.is_cover = 1 LIMIT 1) AS cover_image,
           ua.id AS artwork_artist_id, ua.name AS artwork_artist_name,
           cr.id AS commission_id,
           co.id AS commission_order_id,
           co.order_number AS commission_order_number,
           co.commission_description, co.budget_min, co.budget_max, co.order_status AS commission_status,
           co.total AS agreed_price, co.commission_reference_image,
           co.price_status, -- ADDED: Fetch price_status
           uc.id AS commission_artist_id, uc.name AS commission_artist_name
    FROM shopping_cart sc
    LEFT JOIN artworks a ON sc.item_type = 'artwork' AND sc.item_id = a.id AND a.status IN ('approved', 'sold')
    LEFT JOIN users ua ON a.artist_id = ua.id
    LEFT JOIN commission_requests cr ON sc.item_type = 'commission' AND sc.item_id = cr.id
    LEFT JOIN orders co ON cr.order_id = co.id
    LEFT JOIN users uc ON cr.artist_id = uc.id
    WHERE sc.buyer_id = ?
");
 $cartQuery->bind_param('i', $buyerId);
 $cartQuery->execute();
 $cartResult = $cartQuery->get_result();

while ($row = $cartResult->fetch_assoc()) {
    if ($row['item_type'] === 'artwork' && $row['artwork_id']) {
        $item = [
            'type' => 'artwork',
            'id' => $row['artwork_id'],
            'title' => $row['artwork_title'],
            'price' => $row['artwork_price'],
            'quantity' => $row['quantity'],
            'artist_id' => $row['artwork_artist_id'],
            'artist_name' => $row['artwork_artist_name'],
            'cover_image' => $row['cover_image'],
            'status' => $row['artwork_status']
        ];
        $artworkSubtotal += $item['price'] * $item['quantity'];
        $cartItems[] = $item;
    } 
    elseif ($row['item_type'] === 'commission' && $row['commission_id']) {
        $hasCommissionItems = true;
        $price = $row['agreed_price'] ?? $row['budget_max'] ?? $row['budget_min'] ?? 0;
        $item = [
            'type' => 'commission',
            'id' => $row['commission_id'],
            'title' => !empty($row['commission_description']) 
                ? substr($row['commission_description'], 0, 60) . '…' 
                : 'Custom Commission Request',
            'description' => substr($row['commission_description'] ?? '', 0, 100),
            'budget_min' => $row['budget_min'],
            'budget_max' => $row['budget_max'],
            'agreed_price' => $row['agreed_price'],
            'quantity' => 1, // Commissions are always qty 1
            'artist_id' => $row['commission_artist_id'],
            'artist_name' => $row['commission_artist_name'],
            'status' => $row['commission_status'],
            'price' => $price,
            'commission_order_id' => $row['commission_order_id'],
            'commission_order_number' => $row['commission_order_number'],
            'price_status' => $row['price_status'] // ADDED: Store in item array
        ];
        $cartItems[] = $item;
    }
}

// Subtotal and totals are artwork-only (commissions checkout separately)
 $subtotal = $artworkSubtotal;
 $shippingFee = $subtotal > 0 ? 350 : 0; // Only charge shipping if there are artwork items
 $total = $subtotal + $shippingFee;

// Count artwork items for the checkout flow
 $artworkCount = 0;
foreach ($cartItems as $ci) {
    if ($ci['type'] === 'artwork') $artworkCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shopping Cart — Art Bazaar</title>
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
.cart-icon{position:relative;display:flex;align-items:center;padding:6px 10px;border-radius:6px;transition:background .12s;cursor:pointer;color:var(--bg);}
.cart-icon:hover{background:var(--sand);color:var(--ink);}
.cart-count{position:absolute;top:-5px;right:-5px;background:var(--sand);color:var(--ink);font-size:9px;font-weight:600;padding:2px 5px;border-radius:20px;min-width:16px;text-align:center;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand);color:var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── MOBILE HAMBURGER & DRAWER GLOBAL STYLES ─── */
#nav-drawer{display:none;}
#nav-overlay{display:none;}
.ham-btn{display:none;}

/* CART MAIN */
.main{max-width:var(--w);margin:0 auto;padding:28px;}
.cart-title{font-family:'Playfair Display',serif;font-size:28px;font-weight:400;margin-bottom:24px;}

.cart-layout{display:grid;grid-template-columns:1fr 320px;gap:32px;}

/* CART ITEMS */
.cart-items{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
.cart-header{display:grid;grid-template-columns:3fr 1fr 1fr 0.5fr;padding:14px 20px;background:var(--sand);border-bottom:1px solid var(--border);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);}
.cart-item{display:grid;grid-template-columns:3fr 1fr 1fr 0.5fr;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);}
.cart-item:last-child{border-bottom:none;}
.item-info{display:flex;gap:14px;align-items:center;}
.item-img{width:70px;height:70px;border-radius:8px;object-fit:cover;background:var(--sand);}
.item-details h4{font-size:15px;font-weight:500;margin-bottom:4px;}
.item-details .artist{font-size:11px;color:var(--muted);margin-bottom:4px;}
.item-details .type-badge{display:inline-block;font-size:9px;background:var(--sand);padding:2px 8px;border-radius:20px;color:var(--ink);}
.item-price{font-weight:600;color:var(--ink);}
.item-qty select{padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--card);font-size:13px;font-family:'DM Sans',sans-serif;cursor:pointer;}
.item-total{font-weight:600;color:var(--ink);}
.remove-btn{background:transparent;border:none;cursor:pointer;color:var(--muted);font-size:18px;transition:color .12s;font-family:'DM Sans',sans-serif;}
.remove-btn:hover{color:var(--ink);}

/* COMMISSION NOTICE */
.commission-notice{background:#FFF8F0;border:1px solid #F0D9B5;border-radius:8px;padding:10px 14px;margin-top:8px;font-size:12px;color:var(--body);line-height:1.5;}
.commission-notice strong{color:var(--ink);display:block;margin-bottom:3px;font-size:11px;letter-spacing:.5px;text-transform:uppercase;}
.commission-notice a{color:var(--ink);font-weight:600;text-decoration:underline;}
.commission-notice a:hover{color:var(--muted);}
.commission-qty{font-size:12px;color:var(--muted);font-style:italic;}

/* COMMISSION BANNER (top of cart) */
.commission-banner{background:#FFFCF5;border:1px solid var(--sand);border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;}
.commission-banner .cb-icon{flex-shrink:0;width:36px;height:36px;background:var(--sand);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--ink);}
.commission-banner .cb-text{flex:1;font-size:13px;color:var(--body);line-height:1.5;}
.commission-banner .cb-text strong{color:var(--ink);}
.commission-banner .cb-text a{color:var(--ink);font-weight:600;}
.commission-banner .cb-text a:hover{color:var(--muted);}

/* CART SIDEBAR */
.cart-sidebar{position:sticky;top:80px;}
.summary-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:20px;}
.summary-title{font-size:16px;font-weight:600;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.summary-row{display:flex;justify-content:space-between;margin-bottom:12px;font-size:13px;}
.summary-row.total{margin-top:12px;padding-top:12px;border-top:1px solid var(--border);font-weight:600;font-size:16px;}
.summary-note{font-size:11px;color:var(--muted);margin-top:8px;line-height:1.5;padding-top:8px;border-top:1px dashed var(--border);}
.checkout-btn{width:100%;background:var(--ink);color:var(--bg);border:none;padding:14px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;margin-top:8px;transition:background .15s;font-family:'DM Sans',sans-serif;display:inline-block;text-align:center;}
.checkout-btn:hover{background:var(--body);}
.clear-cart-btn{width:100%;background:transparent;border:1px solid var(--border);padding:12px;border-radius:8px;font-size:13px;cursor:pointer;margin-top:10px;transition:all .15s;font-family:'DM Sans',sans-serif;}
.clear-cart-btn:hover{border-color:var(--ink);color:var(--ink);}
.empty-cart{text-align:center;padding:60px 20px;}
.empty-cart svg{opacity:.2;margin-bottom:16px;}
.empty-cart h3{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;margin-bottom:8px;}
.empty-cart p{color:var(--muted);margin-bottom:24px;}

/* MESSAGE */
.msg{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:20px;}
.msg.success{background:#EBF5EE;color:#2A6040;border:1px solid #B8DFC8;}
.msg.error{background:#FCEEE9;color:#7D2A14;border:1px solid #EEC5B8;}

/* FOOTER */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet (max-width: 1080px) */
@media(max-width:1080px){
  .cart-layout{grid-template-columns:1fr;}
  .cart-header{display:none;}
  .cart-item{grid-template-columns:1fr;gap:12px;}
  .item-info{flex-direction:column;text-align:center;}
  .item-qty,.item-price,.item-total{text-align:center;}
  .commission-banner{flex-direction:column;}
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
  .drawer-cart{color:var(--bg);font-size:13.5px;padding:8px 0;}
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
      <span style="font-size:12.5px;color:var(--bg);">Welcome, <?= htmlspecialchars($_SESSION['name'] ?? 'Buyer') ?></span>
      <a href="logout.php" class="btn-ghost">Logout</a>

      <button class="ham-btn" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<div class="main">
  <h1 class="cart-title">Your Cart</h1>
  
  <?php if ($message): ?>
    <div class="msg success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  
  <?php if (empty($cartItems)): ?>
    <div class="empty-cart">
      <svg width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
      <h3>Your cart is empty</h3>
      <p>Looks like you haven't added any artworks or commissions yet.</p>
      <a href="artworks.php" class="btn-dark" style="display:inline-block;">Browse Artworks</a>
    </div>
  <?php else: ?>

    <?php if ($hasCommissionItems): ?>
    <!-- COMMISSION BANNER — shown when legacy commission items exist in cart -->
    <div class="commission-banner">
      <div class="cb-icon">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      </div>
      <div class="cb-text">
        <strong>Commission orders are managed separately.</strong>
        Commission items in your cart are from a previous session. They checkout independently from artwork purchases. Use the <strong>"Complete Commission →"</strong> link on each commission item below (only if price is accepted), or remove them if no longer needed.
      </div>
    </div>
    <?php endif; ?>

    <div class="cart-layout">
      <!-- LEFT: CART ITEMS -->
      <div class="cart-items">
        <div class="cart-header">
          <span>Product</span>
          <span>Price</span>
          <span>Quantity</span>
          <span>Total</span>
        </div>
        
        <?php foreach ($cartItems as $item): 
          $imgUrl = getImageUrl($item['cover_image'] ?? null);
          $itemTotal = $item['price'] * $item['quantity'];
          $isCommission = $item['type'] === 'commission';
          
          // Determine if checkout link should be shown
          $canCheckoutCommission = $isCommission && isset($item['price_status']) && $item['price_status'] === 'accepted';
        ?>
        <div class="cart-item" style="<?= $isCommission ? 'background:#FFFCF5;' : '' ?>">
          <div class="item-info">
            <?php if ($imgUrl && !$isCommission): ?>
              <img class="item-img" src="<?= htmlspecialchars($imgUrl) ?>" alt="">
            <?php else: ?>
              <div class="item-img" style="display:flex;align-items:center;justify-content:center;background:var(--sand);">
                <?php if ($isCommission): ?>
                <svg width="30" height="30" fill="none" stroke="var(--ink)" stroke-width="1.2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                <?php else: ?>
                <svg width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="14.5" r="1.5"/></svg>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="item-details">
              <h4><?= htmlspecialchars($item['title']) ?></h4>
              <div class="artist">by <?= htmlspecialchars($item['artist_name'] ?? 'Art Bazaar') ?></div>
              <span class="type-badge" style="<?= $isCommission ? 'background:#F0D9B5;color:#7D5A2E;' : '' ?>"><?= $isCommission ? '✦ Custom Commission' : 'Ready-made Artwork' ?></span>
              
              <?php if ($isCommission): ?>
              <!-- Commission-specific notice -->
              <div class="commission-notice">
                <strong>Commission — separate checkout</strong>
                <?php if ($canCheckoutCommission): ?>
                    Price accepted. Ready to pay.
                    <?php if (!empty($item['commission_order_id'])): ?>
                        <br><a href="checkout.php?order_id=<?= (int)$item['commission_order_id'] ?>&type=commission">Complete Commission →</a>
                    <?php else: ?>
                        <br><a href="checkout.php?type=commission&commission_id=<?= (int)$item['id'] ?>">Complete Commission →</a>
                    <?php endif; ?>
                <?php else: ?>
                    Status: <?= ucfirst($item['price_status'] ?? 'Pending') ?>. 
                    <?php if(isset($item['price_status']) && $item['price_status'] === 'proposed'): ?>
                        <br>Please review the price proposal in your account dashboard before checkout.
                    <?php else: ?>
                        <br>Awaiting artist price proposal.
                    <?php endif; ?>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="item-price">
            PKR <?= number_format($item['price']) ?>
            <?php if ($isCommission && !empty($item['budget_min'])): ?>
              <div style="font-size:10px;color:var(--muted);font-weight:400;">Budget: PKR <?= number_format($item['budget_min']) ?>–<?= number_format($item['budget_max'] ?? 0) ?></div>
            <?php endif; ?>
          </div>
          <div class="item-qty">
            <?php if ($isCommission): ?>
              <span class="commission-qty">1 (fixed)</span>
            <?php else: ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="update_qty">
              <input type="hidden" name="item_type" value="artwork">
              <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
              <select name="quantity" onchange="this.form.submit()">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <option value="<?= $i ?>" <?= $item['quantity'] == $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
              </select>
            </form>
            <?php endif; ?>
          </div>
          <div class="item-total">PKR <?= number_format($itemTotal) ?></div>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="item_type" value="<?= $item['type'] ?>">
            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
            <button type="submit" class="remove-btn" title="Remove">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      
      <!-- RIGHT: SUMMARY -->
      <div class="cart-sidebar">
        <div class="summary-card">
          <div class="summary-title">Order Summary</div>
          
          <?php if ($artworkCount > 0): ?>
          <div class="summary-row">
            <span>Artwork Subtotal (<?= $artworkCount ?>)</span>
            <span>PKR <?= number_format($subtotal) ?></span>
          </div>
          <div class="summary-row">
            <span>Shipping</span>
            <span>PKR <?= number_format($shippingFee) ?></span>
          </div>
          <div class="summary-row total">
            <span>Total</span>
            <span>PKR <?= number_format($total) ?></span>
          </div>
          <a href="checkout.php" class="checkout-btn">Proceed to Checkout →</a>
          <?php else: ?>
          <div class="summary-row">
            <span>No artwork items</span>
            <span>PKR 0</span>
          </div>
          <div class="summary-note" style="margin-top:12px;">
            Your cart only contains commission items, which checkout separately. Use the "Complete Commission →" link on each item (if price is accepted).
          </div>
          <?php endif; ?>
          
          <?php if ($hasCommissionItems && $artworkCount > 0): ?>
          <div class="summary-note">
            <strong>Note:</strong> Commission items in your cart are <em>not</em> included in this total — they are checked out separately. Use the "Complete Commission →" link on each commission item above.
          </div>
          <?php endif; ?>
          
          <form method="POST" onsubmit="return confirm('Clear all items from your cart?')">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="clear-cart-btn">Clear Cart</button>
          </form>
        </div>
        
        <div class="summary-card">
          <div class="summary-title">Shipping Info</div>
          <p style="font-size:12px;color:var(--muted);line-height:1.6;">
            Standard shipping across Pakistan: PKR 350.<br>
            Delivery takes 3–7 business days.<br>
            For custom commissions, timeline will be discussed with the artist.
          </p>
        </div>
      </div>
    </div>
  <?php endif; ?>
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
    <a href="cart.php" class="drawer-cart">🛒 Cart</a>
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
</script>
</body>
</html>