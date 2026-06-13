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
<title>Terms & Conditions — Art Bazaar Pakistan</title>
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
.page-hd h1{font-family:'Playfair Display',serif;font-size:clamp(28px,3.4vw,42px);font-weight:400;color:var(--ink);margin-bottom:6px;}
.page-hd p{font-size:12.5px;color:var(--ink);opacity:.7;}

.terms-container{max-width:860px;margin:0 auto;padding:0 28px 60px;}

.terms-section{margin-bottom:28px;}
.terms-section-title{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--ink);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--sand);}
.terms-text{font-size:13.5px;line-height:1.7;color:var(--ink);margin-bottom:12px;}

.terms-list{list-style:none;padding:0;margin:0 0 12px 0;}
.terms-list li{position:relative;padding-left:20px;margin-bottom:6px;font-size:13.5px;line-height:1.6;color:var(--ink);}
.terms-list li::before{content:'';position:absolute;left:0;top:9px;width:6px;height:6px;border-radius:50%;background:var(--sand);}

.terms-numbered{padding-left:24px;margin:0 0 12px 0;}
.terms-numbered li{margin-bottom:8px;font-size:13.5px;line-height:1.6;color:var(--ink);}
.terms-numbered li::marker{color:var(--ink);font-weight:600;}

.terms-highlight-box{background:rgba(12,63,48,0.04);border:1px solid var(--sand);border-radius:8px;padding:16px 20px;margin-bottom:16px;}
.terms-highlight-box p{font-size:13px;line-height:1.6;margin:0;}

/* ─── FOOTER (Exact Index Match) ─── */
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

      <button class="ham-btn" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
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
    <a href="artworks.php">Explore Art</a>
    <a href="artists.php">Artists</a>
    <a href="blog.php">Blog</a>
    <a href="commission.php">Commission Art</a>
    <a href="sell.php">Sell Your Art</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    <a href="cart.php" class="drawer-cart">🛒 Cart (<?= $cartCount ?>)</a>
    <?php if ($isLoggedIn): ?>
      <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a>
      <a href="logout.php" class="drawer-btn-dark">Logout</a>
    <?php else: ?>
      <a href="login.php" class="drawer-btn-ghost">Login</a>
      <a href="register.php" class="drawer-btn-dark">Join as Artist</a>
    <?php endif; ?>
  </div>
</div>

<div class="wrap">
  <div class="page-hd">
    <h1>Terms & Conditions</h1>
    <p>Art Bazaar Pakistan — Last updated: [Add Date]</p>
  </div>
  <hr class="divhr">
</div>

<div class="terms-container">

  <div class="terms-highlight-box">
    <p>Welcome to Art Bazaar Pakistan. By using our website, creating an account, uploading artwork, placing an order, or sending a custom artwork request, you agree to these Terms & Conditions. Please read them carefully.</p>
  </div>

  <!-- 1 -->
  <div class="terms-section">
    <h2 class="terms-section-title">1. About Art Bazaar Pakistan</h2>
    <p class="terms-text">Art Bazaar Pakistan is an online platform that helps Pakistani artists showcase their work and helps buyers discover, request, and purchase artwork. The platform may include:</p>
    <ul class="terms-list">
      <li>Ready-made artwork listings</li>
      <li>Artist profiles</li>
      <li>Custom artwork requests</li>
      <li>Buyer inquiries</li>
      <li>Order management</li>
      <li>Platform-managed payment and delivery guidance</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan acts as a platform between buyers and artists. We help manage requests, communication, payment instructions, and order updates to make the process safer and clearer.</p>
  </div>

  <!-- 2 -->
  <div class="terms-section">
    <h2 class="terms-section-title">2. Who Can Use the Website</h2>
    <p class="terms-text">You may use Art Bazaar Pakistan as:</p>
    <ul class="terms-list">
      <li>A buyer</li>
      <li>An artist</li>
      <li>A visitor browsing the website</li>
    </ul>
    <p class="terms-text">By using the website, you agree that the information you provide is correct and that you will not misuse the platform.</p>
  </div>

  <!-- 3 -->
  <div class="terms-section">
    <h2 class="terms-section-title">3. Account Rules</h2>
    <p class="terms-text">Users must provide accurate information when creating an account or submitting a request. You are responsible for keeping your account login details safe. You must not:</p>
    <ul class="terms-list">
      <li>Create fake accounts</li>
      <li>Use another person’s identity</li>
      <li>Misuse buyer or artist information</li>
      <li>Share false order details</li>
      <li>Use the platform for scams or fraud</li>
      <li>Upload harmful, stolen, or misleading content</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may suspend, block, or remove accounts that break these rules.</p>
  </div>

  <!-- 4 -->
  <div class="terms-section">
    <h2 class="terms-section-title">4. Artist Profile Rules</h2>
    <p class="terms-text">Artists may create profiles to showcase their work. Artist profiles may require:</p>
    <ul class="terms-list">
      <li>Full name or artist name</li>
      <li>City</li>
      <li>Bio/about section</li>
      <li>Art style or medium</li>
      <li>Profile picture</li>
      <li>Contact details for admin use</li>
      <li>Sample artworks or portfolio</li>
    </ul>
    <p class="terms-text">Artist profiles may not appear publicly until they are complete and approved by Art Bazaar Pakistan. Art Bazaar Pakistan may approve, reject, hide, or remove artist profiles if the information is incomplete, misleading, inappropriate, or against platform rules.</p>
  </div>

  <!-- 5 -->
  <div class="terms-section">
    <h2 class="terms-section-title">5. Artwork Upload Rules</h2>
    <p class="terms-text">Artists must only upload artwork that they created themselves or have the full legal right to sell or display. Artists must not upload:</p>
    <ul class="terms-list">
      <li>Stolen artwork</li>
      <li>Copied artwork</li>
      <li>AI/generated or edited work falsely presented as handmade/original</li>
      <li>Copyrighted characters or designs unless they have permission</li>
      <li>Artwork copied from another artist, website, Pinterest, Instagram, or Google</li>
      <li>Offensive, misleading, or illegal content</li>
    </ul>
    <p class="terms-text">All uploaded artworks may be reviewed by Art Bazaar Pakistan before appearing publicly. Pending, rejected, hidden, or unapproved artworks will not be shown publicly. Art Bazaar Pakistan may remove any artwork that appears unoriginal, low quality, misleading, or against platform rules.</p>
  </div>

  <!-- 6 -->
  <div class="terms-section">
    <h2 class="terms-section-title">6. Buyer Rules</h2>
    <p class="terms-text">Buyers may browse artworks, place purchase requests, and request custom artwork. Buyers must provide correct details, including:</p>
    <ul class="terms-list">
      <li>Name</li>
      <li>Email</li>
      <li>Phone/WhatsApp number</li>
      <li>Delivery city/address when needed</li>
      <li>Correct custom artwork details</li>
      <li>Budget and deadline information, where applicable</li>
    </ul>
    <p class="terms-text">Buyers must not:</p>
    <ul class="terms-list">
      <li>Submit fake orders</li>
      <li>Harass artists or platform staff</li>
      <li>Ask artists to work outside the platform</li>
      <li>Share payment details in chat</li>
      <li>Make direct payments unless officially instructed by Art Bazaar Pakistan</li>
      <li>Use reference images they do not have the right to use</li>
    </ul>
  </div>

  <!-- 7 -->
  <div class="terms-section">
    <h2 class="terms-section-title">7. Ready-Made Artwork Orders</h2>
    <p class="terms-text">Ready-made artworks are artworks already created and listed on the website. When a buyer submits an order or purchase request, Art Bazaar Pakistan may review the order before confirming it. The final order may include:</p>
    <ul class="terms-list">
      <li>Artwork price</li>
      <li>Shipping fee</li>
      <li>Platform maintenance fee, if applicable</li>
      <li>Any agreed packaging or handling charges</li>
    </ul>
    <p class="terms-text">Shipping charges may vary depending on city, artwork size, weight, frame, fragility, and courier cost. An order is only confirmed after Art Bazaar Pakistan confirms the final amount and payment process.</p>
  </div>

  <!-- 8 -->
  <div class="terms-section">
    <h2 class="terms-section-title">8. Custom Artwork Requests</h2>
    <p class="terms-text">Custom artwork requests are made when a buyer wants artwork created based on their idea, reference, size, style, or requirements. The buyer may provide:</p>
    <ul class="terms-list">
      <li>Artwork type</li>
      <li>Description</li>
      <li>Reference image</li>
      <li>Estimated budget</li>
      <li>Preferred deadline</li>
      <li>Delivery city</li>
      <li>Size and framing preferences</li>
    </ul>
    <p class="terms-text">The budget shared by the buyer is only an estimate. Final pricing is decided after the request is reviewed. A custom artwork request may follow this process:</p>
    <ol class="terms-numbered">
      <li>Buyer submits a request.</li>
      <li>Art Bazaar Pakistan reviews the request.</li>
      <li>Artist may be assigned or selected.</li>
      <li>Price, timeline, and details are discussed.</li>
      <li>Art Bazaar Pakistan sends the final quote.</li>
      <li>Buyer confirms the quote.</li>
      <li>Official payment instructions are shared.</li>
      <li>Artist starts work after confirmation/payment process.</li>
      <li>Order status is updated until completion and delivery.</li>
    </ol>
    <p class="terms-text">Custom artwork pricing is not final until confirmed by Art Bazaar Pakistan.</p>
  </div>

  <!-- 9 -->
  <div class="terms-section">
    <h2 class="terms-section-title">9. Pricing</h2>
    <p class="terms-text">Artwork prices are set based on the artist’s work, size, style, medium, time, and other details. For custom artworks, the final price may be different from the buyer’s estimated budget. The final total may include:</p>
    <ul class="terms-list">
      <li>Artwork price</li>
      <li>Shipping fee</li>
      <li>Maintenance/platform fee</li>
      <li>Packaging or handling cost, if applicable</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan will try to keep pricing clear before the buyer confirms the order.</p>
  </div>

  <!-- 10 -->
  <div class="terms-section">
    <h2 class="terms-section-title">10. Maintenance / Platform Fee</h2>
    <p class="terms-text">Art Bazaar Pakistan may charge a maintenance or platform fee for managing the website, requests, orders, communication, and platform operations. This fee helps support:</p>
    <ul class="terms-list">
      <li>Website maintenance</li>
      <li>Order management</li>
      <li>Artist and buyer support</li>
      <li>Admin review</li>
      <li>Platform safety</li>
      <li>Request and payment coordination</li>
    </ul>
    <p class="terms-text">We avoid using the word “commission” for this model. Any platform fee should be clearly communicated before final payment where applicable.</p>
  </div>

  <!-- 11 -->
  <div class="terms-section">
    <h2 class="terms-section-title">11. Payments</h2>
    <p class="terms-text">Payment instructions will only be shared officially by Art Bazaar Pakistan. Users should not share payment details in public pages, artist bios, artwork descriptions, or chat.</p>
    <div class="terms-highlight-box">
      <p>Buyers should not pay artists directly unless Art Bazaar Pakistan officially allows it for a specific order. Artists should not ask buyers for direct payment, bank details, JazzCash, Easypaisa, WhatsApp payment, or any off-platform payment arrangement. Art Bazaar Pakistan is not responsible for losses caused by payments made outside the official platform process.</p>
    </div>
  </div>

  <!-- 12 -->
  <div class="terms-section">
    <h2 class="terms-section-title">12. Shipping and Delivery</h2>
    <p class="terms-text">Shipping charges may vary depending on:</p>
    <ul class="terms-list">
      <li>Buyer city</li>
      <li>Artist city</li>
      <li>Artwork size</li>
      <li>Weight</li>
      <li>Frame</li>
      <li>Fragility</li>
      <li>Packaging needs</li>
      <li>Courier availability</li>
      <li>Courier charges</li>
    </ul>
    <p class="terms-text">Delivery timelines are estimates and may be affected by courier delays, weather, public holidays, incorrect address, or other issues outside our control. Buyers must provide accurate delivery information. For fragile or framed artwork, extra packaging or shipping charges may apply.</p>
  </div>

  <!-- 13 -->
  <div class="terms-section">
    <h2 class="terms-section-title">13. Refunds and Cancellations</h2>
    <p class="terms-text">Refunds and cancellations depend on the order type and order stage. For ready-made artworks, cancellation may be possible before the artwork is shipped. For custom artworks, cancellation may be limited once the artist has started working.</p>
    <p class="terms-text"><strong>Refunds may not be available if:</strong></p>
    <ul class="terms-list">
      <li>The custom artwork has already been started</li>
      <li>The buyer changes their mind after confirmation</li>
      <li>The buyer gave unclear or incorrect details</li>
      <li>The buyer approved the final quote and work has started</li>
      <li>The issue was caused by off-platform payment or communication</li>
    </ul>
    <p class="terms-text"><strong>Refunds or partial refunds may be considered if:</strong></p>
    <ul class="terms-list">
      <li>The artist cannot complete the order</li>
      <li>The wrong artwork is sent</li>
      <li>The artwork is damaged during delivery, depending on proof and courier handling</li>
      <li>Art Bazaar Pakistan decides a refund is fair after review</li>
    </ul>
    <p class="terms-text">Final refund and cancellation decisions may be handled by Art Bazaar Pakistan based on the situation.</p>
  </div>

  <!-- 14 -->
  <div class="terms-section">
    <h2 class="terms-section-title">14. Communication Rules</h2>
    <p class="terms-text">Users must communicate respectfully. Users must not:</p>
    <ul class="terms-list">
      <li>Harass, threaten, insult, or pressure others</li>
      <li>Share private contact details publicly</li>
      <li>Share payment details in chat</li>
      <li>Ask to bypass Art Bazaar Pakistan</li>
      <li>Send spam or fake requests</li>
      <li>Use abusive or inappropriate language</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may monitor communication where needed for safety, support, dispute handling, or platform protection.</p>
  </div>

  <!-- 15 -->
  <div class="terms-section">
    <h2 class="terms-section-title">15. Direct Contact and Platform Bypass</h2>
    <p class="terms-text">Buyer and artist direct contact details should not be public initially. Users should not use Art Bazaar Pakistan to find each other and then move the order outside the platform to avoid rules, payment process, or platform fees. Bypassing the platform may lead to:</p>
    <ul class="terms-list">
      <li>Order cancellation</li>
      <li>Account warning</li>
      <li>Account suspension</li>
      <li>Removal from the platform</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan is not responsible for disputes, scams, losses, or failed orders that happen outside the official platform process.</p>
  </div>

  <!-- 16 -->
  <div class="terms-section">
    <h2 class="terms-section-title">16. Reviews, Reports, and Disputes</h2>
    <p class="terms-text">Users may report:</p>
    <ul class="terms-list">
      <li>Fake artwork</li>
      <li>Stolen artwork</li>
      <li>Suspicious artist profiles</li>
      <li>Misleading listings</li>
      <li>Payment issues</li>
      <li>Delivery issues</li>
      <li>Abusive behavior</li>
      <li>Order disputes</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may review the case and take action, including removing content, suspending accounts, cancelling orders, or helping resolve the issue. Users should provide proof where possible, such as screenshots, order details, payment proof, or delivery photos.</p>
  </div>

  <!-- 17 -->
  <div class="terms-section">
    <h2 class="terms-section-title">17. Platform Rights</h2>
    <p class="terms-text">Art Bazaar Pakistan has the right to:</p>
    <ul class="terms-list">
      <li>Approve or reject artist profiles</li>
      <li>Approve or reject artworks</li>
      <li>Hide or remove listings</li>
      <li>Edit or remove misleading content</li>
      <li>Suspend or block accounts</li>
      <li>Cancel suspicious orders</li>
      <li>Refuse service where needed</li>
      <li>Update platform rules and policies</li>
    </ul>
    <p class="terms-text">We may take action if a user breaks these Terms & Conditions or harms the safety, trust, or reputation of the platform.</p>
  </div>

  <!-- 18 -->
  <div class="terms-section">
    <h2 class="terms-section-title">18. Website Content and Availability</h2>
    <p class="terms-text">Art Bazaar Pakistan tries to keep the website accurate and available, but we do not guarantee that the website will always be error-free, uninterrupted, or fully updated. Artwork availability, prices, artist status, and shipping costs may change. We may update, remove, or change website content when needed.</p>
  </div>

  <!-- 19 -->
  <div class="terms-section">
    <h2 class="terms-section-title">19. Limitation of Responsibility</h2>
    <p class="terms-text">Art Bazaar Pakistan works to create a safer and more organized marketplace, but we cannot guarantee every outcome. We are not responsible for:</p>
    <ul class="terms-list">
      <li>Losses caused by off-platform payments</li>
      <li>Direct deals made outside Art Bazaar Pakistan</li>
      <li>Incorrect information provided by users</li>
      <li>Courier delays or courier damage beyond our control</li>
      <li>Buyer or artist misuse of the platform</li>
      <li>Copyright issues caused by artist-uploaded content</li>
      <li>Technical errors outside our reasonable control</li>
    </ul>
    <p class="terms-text">We will still try to support fair dispute handling where possible.</p>
  </div>

  <!-- 20 -->
  <div class="terms-section">
    <h2 class="terms-section-title">20. Changes to These Terms</h2>
    <p class="terms-text">Art Bazaar Pakistan may update these Terms & Conditions from time to time. When changes are made, the updated version will be posted on the website with a new “Last updated” date. Continued use of the website means you accept the updated Terms.</p>
  </div>

  <!-- 21 -->
  <div class="terms-section">
    <h2 class="terms-section-title">21. Contact</h2>
    <p class="terms-text">For questions, complaints, reports, or support, contact Art Bazaar Pakistan through the official Contact page or official platform support channel. Please include your name, order/request details, and a clear explanation of the issue when contacting us.</p>
  </div>

</div>

<!-- EXACT FOOTER FROM INDEX -->
<footer class="footer">
  <div class="fw">
    <div class="fg-foot">
      <div class="fb">
        <b>Art Bazaar</b><p>Pakistan's premier marketplace for original art. Connecting talented Pakistani artists with art lovers across the country.</p>
      </div>
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
// Mobile Drawer Logic
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