<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Helper function to get cart count
function getCartCount() {
    global $conn;
    if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer') {
        $buyerId = (int)$_SESSION['user_id'];
        $res = $conn->query("SELECT SUM(quantity) as total FROM shopping_cart WHERE buyer_id = $buyerId");
        $row = $res->fetch_assoc();
        return (int)($row['total'] ?? 0);
    }
    $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        if (($item['type'] ?? 'artwork') === 'artwork') {
            $count += $item['quantity'];
        }
    }
    return $count;
}

 $isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer';
 $cartCount = getCartCount();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Refund & Cancellation Policy — Art Bazaar Pakistan</title>
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

/* ─── NAV ─── */
.nav{background:var(--ink);border-bottom:1px solid var(--ink);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;margin-right:4px;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--bg);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--sand);margin-top:1px;}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--bg);padding:6px 10px;border-radius:6px;transition:background .12s;}
.nlinks a:hover{background:var(--sand); color: var(--ink);}
.nend{display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;margin-left:auto;}
.cart-icon{position:relative;display:flex;align-items:center;padding:6px 10px;border-radius:6px;transition:background .12s;cursor:pointer; color: var(--bg);}
.cart-icon:hover{background:var(--sand); color: var(--ink);}
.cart-count{position:absolute;top:-5px;right:-5px;background:var(--ink);color:var(--bg);font-size:9px;font-weight:600;padding:2px 5px;border-radius:20px;min-width:16px;text-align:center;}
.btn-ghost{font-size:12.5px;color:var(--bg);padding:7px 14px;border-radius:6px;border:1px solid var(--bg);background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .12s;}
.btn-ghost:hover{border-color:var(--sand);background:var(--sand); color: var(--ink);}
.btn-dark{font-size:12.5px;color:var(--ink);padding:7px 16px;border-radius:6px;border:none;background:var(--sand);cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:background .12s;}
.btn-dark:hover{background:#c4b69e;}

/* ─── LAYOUT ─── */
.wrap{max-width:var(--w);margin:0 auto;padding:0 28px;}
.divhr{border:none;border-top:1px solid var(--border);}

/* ─── TERMS CONTENT SPECIFIC ─── */
.page-hd{padding:44px 0 24px;text-align:center;}
.page-hd h1{font-family:'Playfair Display',serif;font-size:clamp(26px,3.4vw,42px);font-weight:400;color:var(--ink);margin-bottom:6px;}
.page-hd p{font-size:12.5px;color:var(--ink);opacity:.7;}

.terms-container{max-width:860px;margin:0 auto;padding:0 28px 60px;}

.terms-section{margin-bottom:28px;}
.terms-section-title{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--ink);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--sand);}
.terms-text{font-size:13.5px;line-height:1.7;color:var(--ink);margin-bottom:12px;}

.terms-list{list-style:none;padding:0;margin:0 0 12px 0;}
.terms-list li{position:relative;padding-left:20px;margin-bottom:6px;font-size:13.5px;line-height:1.6;color:var(--ink);}
.terms-list li::before{content:'';position:absolute;left:0;top:9px;width:6px;height:6px;border-radius:50%;background:var(--sand);}

.terms-highlight-box{background:rgba(12,63,48,0.04);border:1px solid var(--sand);border-radius:8px;padding:16px 20px;margin-bottom:16px;}
.terms-highlight-box p{font-size:13px;line-height:1.6;margin:0;}

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

/* ─── MOBILE HAMBURGER & DRAWER ─── */
#nav-drawer { display:none; }
#nav-overlay { display:none; }
.ham-btn { display:none; }

@media(max-width:1080px){
  .fg-foot{grid-template-columns:1fr 1fr;}
}
@media(max-width:768px){
  .nlinks,.nsearch{display:none;}
  .wrap{padding:0 16px;}
  .terms-container{padding:0 16px 40px;}
  .fg-foot{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 16px;}
  .fc{display:none;}
  .fb{margin-bottom:12px;}
  .fb b{font-size:16px;}
  .fb p{font-size:10px;max-width:280px;}
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
      <a href="blog.php">Blog</a> 
      <a href="commission.php">Commission Art</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nend">
      <a href="cart.php" class="cart-icon">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
        <?php if ($cartCount > 0): ?>
        <span class="cart-count"><?= $cartCount ?></span>
        <?php endif; ?>
      </a>
      <?php if ($isLoggedIn): ?>
        <span style="font-size:12.5px;color:var(--bg);">Hi, <?= htmlspecialchars($_SESSION['name'] ?? 'Buyer') ?></span>
        <a href="dashboard/buyer/account.php" class="btn-ghost">My Account</a>
        <a href="logout.php" class="btn-dark">Logout</a>
      <?php else: ?>
        <a href="login.php" class="btn-ghost">Login</a>
        <a href="register.php" class="btn-dark">Join as Artist</a>
      <?php endif; ?>
      <button class="ham-btn" aria-label="Open menu"><span></span><span></span><span></span></button>
    </div>
  </div>
</nav>

<!-- Overlay & Drawer -->
<div id="nav-overlay" onclick="closeDrawer()"></div>
<div id="nav-drawer">
  <div class="drawer-top">
    <div class="drawer-logo"><b>Art Bazaar</b><small>Pakistan</small></div>
    <button class="drawer-close" onclick="closeDrawer()">✕</button>
  </div>
  <div class="drawer-links">
    <a href="artworks.php">Explore Art</a><a href="artists.php">Artists</a><a href="blog.php">Blog</a><a href="commission.php">Commission Art</a><a href="sell.php">Sell Your Art</a><a href="about.php">About Us</a><a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    <a href="cart.php" class="drawer-cart">🛒 Cart (<?= $cartCount ?>)</a>
    <?php if ($isLoggedIn): ?>
      <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a><a href="logout.php" class="drawer-btn-dark">Logout</a>
    <?php else: ?>
      <a href="login.php" class="drawer-btn-ghost">Login</a><a href="register.php" class="drawer-btn-dark">Join as Artist</a>
    <?php endif; ?>
  </div>
</div>

<div class="wrap">
  <div class="page-hd">
    <h1>Refund & Cancellation Policy</h1>
    <p>Art Bazaar Pakistan — Last updated: [Add Date]</p>
  </div>
  <hr class="divhr">
</div>

<div class="terms-container">

  <div class="terms-highlight-box">
    <p>This Refund & Cancellation Policy explains how cancellations, refunds, damaged orders, and disputes are handled on Art Bazaar Pakistan. By placing an order or requesting custom artwork, you agree to this policy along with our Terms & Conditions, Buyer Terms, Artist Terms, Shipping Policy, and Payment & Maintenance Fee Policy.</p>
  </div>

  <!-- 1 -->
  <div class="terms-section">
    <h2 class="terms-section-title">1. General Policy</h2>
    <p class="terms-text">Art Bazaar Pakistan handles refunds and cancellations based on the type of order and the stage of the order. There are two main order types:</p>
    <ul class="terms-list">
      <li>Ready-made artwork orders</li>
      <li>Custom artwork requests</li>
    </ul>
    <p class="terms-text">Refund and cancellation rules may be different for each type.</p>
  </div>

  <!-- 2 -->
  <div class="terms-section">
    <h2 class="terms-section-title">2. Ready-Made Artwork Cancellation</h2>
    <p class="terms-text">Ready-made artwork is artwork that already exists and is listed on the website. A buyer may request cancellation before the artwork is shipped.</p>
    <p class="terms-text"><strong>Cancellation may be accepted if:</strong></p>
    <ul class="terms-list">
      <li>Payment has not been completed</li>
      <li>The artwork has not been packed or shipped</li>
      <li>The artist has not started delivery preparation</li>
      <li>Art Bazaar Pakistan approves the cancellation</li>
    </ul>
    <p class="terms-text"><strong>Cancellation may not be accepted if:</strong></p>
    <ul class="terms-list">
      <li>The artwork has already been shipped</li>
      <li>The buyer gave incorrect details after confirmation</li>
      <li>The order is already near delivery</li>
      <li>The buyer is cancelling without a valid reason after confirmation</li>
    </ul>
  </div>

  <!-- 3 -->
  <div class="terms-section">
    <h2 class="terms-section-title">3. Custom Artwork Cancellation</h2>
    <p class="terms-text">Custom artwork is made specially according to the buyer’s request. Custom artwork cancellation is limited because the artist may spend time, effort, and materials after confirmation.</p>
    <p class="terms-text">A buyer may request cancellation before:</p>
    <ul class="terms-list">
      <li>Final quote is accepted</li>
      <li>Payment process is confirmed</li>
      <li>Artist starts work</li>
    </ul>
    <p class="terms-text">Once the artist has started work, full cancellation/refund may not be available.</p>
  </div>

  <!-- 4 -->
  <div class="terms-section">
    <h2 class="terms-section-title">4. Before Final Quote</h2>
    <p class="terms-text">If a custom artwork request is still at the discussion/review stage and the final quote has not been accepted, the buyer can cancel the request without major issue. At this stage, no artwork has officially started.</p>
  </div>

  <!-- 5 -->
  <div class="terms-section">
    <h2 class="terms-section-title">5. After Final Quote Is Accepted</h2>
    <p class="terms-text">Once the buyer accepts the final quote, the request becomes more serious. After quote acceptance, cancellation may depend on:</p>
    <ul class="terms-list">
      <li>Whether payment has been made</li>
      <li>Whether the artist has started work</li>
      <li>Whether materials have been purchased</li>
      <li>Whether the buyer is changing the request</li>
      <li>Whether the artist has already spent time on the artwork</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan will review the situation before deciding cancellation/refund.</p>
  </div>

  <!-- 6 -->
  <div class="terms-section">
    <h2 class="terms-section-title">6. After Artist Starts Work</h2>
    <p class="terms-text">If the artist has started custom artwork, full refund may not be available. A partial refund may be considered depending on:</p>
    <ul class="terms-list">
      <li>How much work has been completed</li>
      <li>Whether materials have been used</li>
      <li>Whether the buyer caused major changes</li>
      <li>Whether the artist followed the agreed details</li>
      <li>Whether cancellation is fair to both buyer and artist</li>
    </ul>
    <p class="terms-text">If a large part of the work is already completed, refund may be limited or not available.</p>
  </div>

  <!-- 7 -->
  <div class="terms-section">
    <h2 class="terms-section-title">7. Buyer Change of Mind</h2>
    <p class="terms-text">Refunds may not be available if the buyer simply changes their mind after:</p>
    <ul class="terms-list">
      <li>Confirming the order</li>
      <li>Accepting the final quote</li>
      <li>Payment process has started</li>
      <li>Artist has started custom work</li>
      <li>Artwork has been shipped</li>
    </ul>
    <p class="terms-text">Buyers should review all details carefully before confirming an order.</p>
  </div>

  <!-- 8 -->
  <div class="terms-section">
    <h2 class="terms-section-title">8. Artist Cannot Complete the Order</h2>
    <p class="terms-text">If the artist cannot complete a confirmed order, Art Bazaar Pakistan may offer one of the following:</p>
    <ul class="terms-list">
      <li>Cancel the order</li>
      <li>Offer a refund, if payment was made</li>
      <li>Assign another suitable artist, if possible</li>
      <li>Offer a revised timeline, if buyer agrees</li>
    </ul>
    <p class="terms-text">The final decision will depend on the order stage and situation.</p>
  </div>

  <!-- 9 -->
  <div class="terms-section">
    <h2 class="terms-section-title">9. Wrong Artwork Delivered</h2>
    <p class="terms-text">If the buyer receives the wrong artwork, the buyer should contact Art Bazaar Pakistan as soon as possible. The buyer should provide:</p>
    <ul class="terms-list">
      <li>Order details</li>
      <li>Photos of the received artwork</li>
      <li>Photos of packaging</li>
      <li>Any courier proof, if available</li>
    </ul>
    <p class="terms-text">After review, Art Bazaar Pakistan may arrange correction, replacement, return, refund, or another fair solution.</p>
  </div>

  <!-- 10 -->
  <div class="terms-section">
    <h2 class="terms-section-title">10. Damaged Artwork or Package</h2>
    <p class="terms-text">If artwork arrives damaged, the buyer should report it as soon as possible. The buyer should provide:</p>
    <ul class="terms-list">
      <li>Clear photos of the outer package</li>
      <li>Clear photos of the damaged artwork</li>
      <li>Photos of packaging material</li>
      <li>Order details</li>
      <li>Courier receipt/tracking details, if available</li>
    </ul>
    <p class="terms-text">Damage cases will be reviewed based on:</p>
    <ul class="terms-list">
      <li>Proof provided</li>
      <li>Packaging quality</li>
      <li>Courier handling</li>
      <li>Artwork fragility</li>
      <li>Whether the issue was reported quickly</li>
      <li>Order type</li>
    </ul>
    <p class="terms-text">Refund, replacement, repair, or compensation may depend on the situation.</p>
  </div>

  <!-- 11 -->
  <div class="terms-section">
    <h2 class="terms-section-title">11. Packaging-Related Damage</h2>
    <p class="terms-text">Artists are responsible for safely packing artwork before pickup or delivery. If damage appears to be caused by poor packaging, Art Bazaar Pakistan may review the artist’s responsibility.</p>
    <p class="terms-text">Poor packaging may affect:</p>
    <ul class="terms-list">
      <li>Refund decision</li>
      <li>Artist payment</li>
      <li>Artist visibility</li>
      <li>Future approval of artist listings</li>
      <li>Dispute outcome</li>
    </ul>
    <p class="terms-text">For fragile or framed artwork, artists must take extra care in packaging.</p>
  </div>

  <!-- 12 -->
  <div class="terms-section">
    <h2 class="terms-section-title">12. Courier Delays or Courier Issues</h2>
    <p class="terms-text">Courier delays may happen due to factors outside Art Bazaar Pakistan’s control. These may include:</p>
    <ul class="terms-list">
      <li>Weather</li>
      <li>Public holidays</li>
      <li>Courier workload</li>
      <li>Remote delivery area</li>
      <li>Incorrect address</li>
      <li>Buyer not responding</li>
      <li>Courier operational issues</li>
    </ul>
    <p class="terms-text">A delay alone does not always qualify for refund. Art Bazaar Pakistan will try to help track or resolve delayed orders where possible.</p>
  </div>

  <!-- 13 -->
  <div class="terms-section">
    <h2 class="terms-section-title">13. Failed Delivery</h2>
    <p class="terms-text">If delivery fails because of buyer error, refund may be limited. Buyer-related delivery issues may include:</p>
    <ul class="terms-list">
      <li>Incorrect address</li>
      <li>Wrong phone number</li>
      <li>Buyer not responding</li>
      <li>Buyer refusing delivery</li>
      <li>Buyer unavailable to receive order</li>
    </ul>
    <p class="terms-text">Extra shipping charges may apply for re-delivery or return.</p>
  </div>

  <!-- 14 -->
  <div class="terms-section">
    <h2 class="terms-section-title">14. Off-Platform Payments</h2>
    <div class="terms-highlight-box">
      <p>Art Bazaar Pakistan is not responsible for refunds or disputes caused by off-platform payments. Buyers should not pay artists directly unless Art Bazaar Pakistan officially allows it for that specific order. Artists should not ask buyers for direct payment. If a buyer or artist moves payment outside Art Bazaar Pakistan, the platform may not be able to protect either side.</p>
    </div>
  </div>

  <!-- 15 -->
  <div class="terms-section">
    <h2 class="terms-section-title">15. Refund Method and Timeline</h2>
    <p class="terms-text">If a refund is approved, the refund method and timeline will depend on:</p>
    <ul class="terms-list">
      <li>Payment method used</li>
      <li>Bank/payment service processing time</li>
      <li>Verification of payment</li>
      <li>Order status</li>
      <li>Dispute review</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan will share refund instructions or updates through official support channels.</p>
  </div>

  <!-- 16 -->
  <div class="terms-section">
    <h2 class="terms-section-title">16. Non-Refundable Cases</h2>
    <p class="terms-text">Refunds may not be available if:</p>
    <ul class="terms-list">
      <li>Buyer changes their mind after work starts</li>
      <li>Custom artwork was made according to agreed details</li>
      <li>Buyer gave unclear or incorrect instructions</li>
      <li>Buyer approved the quote and then changed the idea</li>
      <li>Buyer provided wrong delivery information</li>
      <li>Buyer made payment outside the official platform process</li>
      <li>Damage was reported too late without proof</li>
      <li>Artwork was successfully delivered as described</li>
    </ul>
  </div>

  <!-- 17 -->
  <div class="terms-section">
    <h2 class="terms-section-title">17. Partial Refunds</h2>
    <p class="terms-text">Partial refunds may be considered when:</p>
    <ul class="terms-list">
      <li>Custom artwork is partly completed</li>
      <li>Materials have already been used</li>
      <li>Artist has spent time on the work</li>
      <li>Buyer cancels after confirmation</li>
      <li>A minor issue exists but the artwork is still usable</li>
      <li>Both buyer and artist agree to a partial solution</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may decide a fair partial refund after reviewing the case.</p>
  </div>

  <!-- 18 -->
  <div class="terms-section">
    <h2 class="terms-section-title">18. Order Cancellation by Art Bazaar Pakistan</h2>
    <p class="terms-text">Art Bazaar Pakistan may cancel an order if:</p>
    <ul class="terms-list">
      <li>Artwork is unavailable</li>
      <li>Artist profile or artwork is not approved</li>
      <li>Payment is not completed</li>
      <li>Buyer or artist breaks platform rules</li>
      <li>Fraud or suspicious activity is suspected</li>
      <li>Delivery is not possible</li>
      <li>Final price/shipping cannot be confirmed</li>
      <li>The request is inappropriate or unsafe</li>
    </ul>
    <p class="terms-text">If payment was made, refund eligibility will depend on the situation.</p>
  </div>

  <!-- 19 -->
  <div class="terms-section">
    <h2 class="terms-section-title">19. Dispute Review</h2>
    <p class="terms-text">If there is a dispute, Art Bazaar Pakistan may review:</p>
    <ul class="terms-list">
      <li>Order details</li>
      <li>Chat history</li>
      <li>Payment status</li>
      <li>Artwork photos</li>
      <li>Reference images</li>
      <li>Packaging photos</li>
      <li>Courier proof</li>
      <li>Artist progress proof</li>
      <li>Buyer complaint details</li>
    </ul>
    <p class="terms-text">Users should provide clear proof to support their claim. Art Bazaar Pakistan will try to make a fair decision based on available information.</p>
  </div>

  <!-- 20 -->
  <div class="terms-section">
    <h2 class="terms-section-title">20. Final Decision</h2>
    <p class="terms-text">Refund and cancellation decisions are handled case by case. Art Bazaar Pakistan may approve, reject, or partially approve a refund/cancellation request depending on the order stage, proof, and fairness to both buyer and artist.</p>
  </div>

  <!-- 21 -->
  <div class="terms-section">
    <h2 class="terms-section-title">21. Contact for Refunds or Cancellations</h2>
    <p class="terms-text">To request a refund, cancellation, or dispute review, contact Art Bazaar Pakistan through the official Contact page or support channel. Please include:</p>
    <ul class="terms-list">
      <li>Name</li>
      <li>Order/request details</li>
      <li>Phone/email used for the order</li>
      <li>Reason for cancellation/refund</li>
      <li>Photos or proof, if needed</li>
    </ul>
  </div>

</div>

<!-- FOOTER -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb"><b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p></div>
      <div class="fc"><h4>Explore</h4><a href="artworks.php">All Artworks</a><a href="artists.php">All Artists</a><a href="artworks.php?featured=1">Featured</a></div>
      <div class="fc"><h4>For Artists</h4><a href="sell.php">How to Sell</a><a href="register.php">Join as Artist</a><a href="login.php">Artist Login</a></div>
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Commissions</a><a href="terms.php">Terms & Conditions</a><a href="privacy-policy.php">Privacy & Policies</a></div>
    </div>
    <div class="fbot">
      <span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span>
      <span>Made with care in Pakistan 🇵🇰</span>
    </div>
  </div>
</footer>

<script>
const hamBtn = document.querySelector('.ham-btn');
const navDrawer = document.getElementById('nav-drawer');
const navOverlay = document.getElementById('nav-overlay');

if(hamBtn) {
  hamBtn.addEventListener('click', () => {
    navDrawer.classList.add('open');
    navOverlay.classList.add('open');
  });
}

function closeDrawer() {
  navDrawer.classList.remove('open');
  navOverlay.classList.remove('open');
}
</script>

</body>
</html>