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
<title>Legal & Privacy Hub — Art Bazaar Pakistan</title>
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

/* ─── PAGE HEADER ─── */
.page-hd{padding:44px 0 24px;text-align:center;}
.page-hd h1{font-family:'Playfair Display',serif;font-size:clamp(28px,3.4vw,42px);font-weight:400;color:var(--ink);margin-bottom:8px;}
.page-hd p{font-size:13.5px;color:var(--ink);opacity:.65;max-width:560px;margin:0 auto;line-height:1.6;}

/* ─── HUB CONTAINER ─── */
.hub-container{max-width:860px;margin:0 auto;padding:0 28px 60px;}

/* ─── OVERVIEW ─── */
.overview-box{background:rgba(12,63,48,0.04);border:1px solid var(--sand);border-radius:12px;padding:28px 32px;margin-bottom:48px;}
.overview-box h2{font-family:'Playfair Display',serif;font-size:20px;font-weight:400;color:var(--ink);margin-bottom:14px;}
.overview-box p{font-size:13.5px;line-height:1.7;color:var(--ink);margin-bottom:14px;}
.overview-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--ink);border-bottom:1px solid var(--ink);padding-bottom:1px;transition:color .12s,border-color .12s;}
.overview-link:hover{color:var(--sand);border-color:var(--sand);}

/* ─── POLICY CARDS GRID ─── */
.policy-cards-hd{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;color:var(--ink);margin-bottom:4px;}
.policy-cards-sub{font-size:13px;color:var(--ink);opacity:.6;margin-bottom:20px;}
.policy-cards-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:56px;}
.policy-card{background:var(--bg);border:1px solid var(--ink);border-radius:16px;padding:24px 22px;transition:transform .15s,box-shadow .15s;}
.policy-card:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(12,63,48,.08);}
.pc-icon{width:42px;height:42px;border-radius:50%;background:var(--sand);display:flex;align-items:center;justify-content:center;margin-bottom:14px;color:var(--ink);}
.pc-icon svg{width:20px;height:20px;}
.pc-title{font-family:'Playfair Display',serif;font-size:17px;font-weight:500;color:var(--ink);margin-bottom:6px;}
.pc-desc{font-size:13px;color:var(--ink);opacity:.6;line-height:1.5;margin-bottom:18px;}
.pc-btn{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--ink);padding:7px 16px;border-radius:6px;background:var(--sand);border:none;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .12s;}
.pc-btn:hover{background:#c4b69e;}
.pc-btn svg{width:12px;height:12px;}

/* ─── FULL PRIVACY POLICY ─── */
.full-privacy-hd{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;color:var(--ink);margin-bottom:6px;padding-top:12px;}
.full-privacy-date{font-size:11px;color:var(--ink);opacity:.5;margin-bottom:24px;}

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
  .hub-container{padding:0 16px 40px;}
  .overview-box{padding:20px 18px;}
  .policy-cards-grid{grid-template-columns:1fr;}
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

<!-- PAGE HEADER -->
<div class="wrap">
  <div class="page-hd">
    <h1>Legal & Privacy Hub</h1>
    <p>Everything you need to know about how Art Bazaar Pakistan works, protects your data, and handles orders.</p>
  </div>
  <hr class="divhr">
</div>

<div class="hub-container">

  <!-- ============================================ -->
  <!-- PRIVACY OVERVIEW -->
  <!-- ============================================ -->
  <div class="overview-box">
    <h2>Privacy at a Glance</h2>
    <p>We collect name, contact details, order information, artwork details, and payment status solely to run the platform, manage orders, coordinate shipping, and support users. We only collect what is needed to operate effectively and safely.</p>
    <p>We do not sell your data. Payment details should never be shared publicly or in chat — official instructions come only from Art Bazaar Pakistan. Admin controls access by role so that private information is never exposed publicly.</p>
    <a href="#privacy-details" class="overview-link">Read full Privacy Policy ↓</a>
  </div>

  <!-- ============================================ -->
  <!-- POLICY CARDS HUB -->
  <!-- ============================================ -->
  <h2 class="policy-cards-hd">Our Policies</h2>
  <p class="policy-cards-sub">Select a policy to read the full details.</p>
  
  <div class="policy-cards-grid">

    <!-- Card 1: Buyer Terms -->
    <div class="policy-card">
      <div class="pc-icon">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
      </div>
      <div class="pc-title">Buyer Terms</div>
      <div class="pc-desc">How buyers can browse, order, and request custom artwork.</div>
      <a href="buyer-terms.php" class="pc-btn">View Policy <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
    </div>

    <!-- Card 2: Artist Terms -->
    <div class="policy-card">
      <div class="pc-icon">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
      </div>
      <div class="pc-title">Artist Terms</div>
      <div class="pc-desc">Rules and guidelines for artists selling on the platform.</div>
      <a href="artist-terms.php" class="pc-btn">View Policy <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
    </div>

    <!-- Card 3: Shipping Policy -->
    <div class="policy-card">
      <div class="pc-icon">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      </div>
      <div class="pc-title">Shipping Policy</div>
      <div class="pc-desc">How shipping, packaging, and delivery works.</div>
      <a href="shipping-policy.php" class="pc-btn">View Policy <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
    </div>

    <!-- Card 4: Refund & Cancellation -->
    <div class="policy-card">
      <div class="pc-icon">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
      </div>
      <div class="pc-title">Refund & Cancellation</div>
      <div class="pc-desc">When and how refunds or cancellations are handled.</div>
      <a href="refund-policy.php" class="pc-btn">View Policy <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
    </div>

    <!-- Card 5: Payment & Fee Policy -->
    <div class="policy-card">
      <div class="pc-icon">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      </div>
      <div class="pc-title">Payment & Fee Policy</div>
      <div class="pc-desc">Payment instructions, platform fees, and off-platform rules.</div>
      <a href="payment-policy.php" class="pc-btn">View Policy <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
    </div>

    <!-- Card 6: Contact & Support -->
    <div class="policy-card">
      <div class="pc-icon">
        <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
      </div>
      <div class="pc-title">Contact & Support</div>
      <div class="pc-desc">Reach out for order help, reports, or any questions.</div>
      <a href="contact.php" class="pc-btn">Get Help <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
    </div>

  </div>

  <hr class="divhr" style="margin-bottom:40px;">

  <!-- ============================================ -->
  <!-- FULL PRIVACY POLICY -->
  <!-- ============================================ -->
  <div id="privacy-details">
    <h2 class="full-privacy-hd">Privacy Policy — Full Details</h2>
    <p class="full-privacy-date">Art Bazaar Pakistan — Last updated: [Add Date]</p>
    
    <div class="terms-highlight-box">
      <p>Art Bazaar Pakistan respects your privacy. This Privacy Policy explains what information we collect, why we collect it, how we use it, and how we protect it. By using Art Bazaar Pakistan, creating an account, uploading artwork, submitting a request, or placing an order, you agree to this Privacy Policy.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">1. Information We Collect</h3>
      <p class="terms-text">We may collect information from buyers, artists, and visitors. This may include:</p>
      <ul class="terms-list">
        <li>Name</li><li>Email address</li><li>Phone/WhatsApp number</li><li>City</li><li>Delivery address</li><li>Account login details</li><li>Artist bio/profile details</li><li>Artist profile picture</li><li>Artwork images and artwork details</li><li>Order details</li><li>Custom artwork request details</li><li>Budget, size, deadline, and reference images for custom requests</li><li>Payment status and order status</li><li>Messages or communication sent through the platform</li><li>Basic website usage information, such as pages visited or device/browser type</li>
      </ul>
      <p class="terms-text">We only collect information that is needed to run the platform, manage orders, support users, and improve the website.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">2. How We Use Your Information</h3>
      <p class="terms-text">We may use your information to:</p>
      <ul class="terms-list">
        <li>Create and manage your account</li><li>Show artist profiles and approved artworks</li><li>Process artwork orders</li><li>Manage custom artwork requests</li><li>Contact buyers about order updates</li><li>Contact artists about profile, artwork, or order updates</li><li>Confirm pricing, shipping, and delivery details</li><li>Send official payment instructions</li><li>Review and approve artist profiles</li><li>Review and approve artworks</li><li>Prevent fraud, fake orders, or platform misuse</li><li>Handle complaints, reports, refunds, cancellations, and disputes</li><li>Improve website performance and user experience</li><li>Follow legal, tax, or business requirements where needed</li>
      </ul>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">3. Buyer Information</h3>
      <p class="terms-text">When a buyer places an order or sends a custom artwork request, we may collect:</p>
      <ul class="terms-list">
        <li>Buyer name</li><li>Email</li><li>Phone/WhatsApp number</li><li>Delivery city/address</li><li>Order details</li><li>Custom artwork details</li><li>Budget and deadline preferences</li><li>Reference images</li><li>Payment/order status</li>
      </ul>
      <p class="terms-text">Buyer contact details are used for order updates, delivery coordination, and support. Buyer phone, email, and address should not be shown publicly.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">4. Artist Information</h3>
      <p class="terms-text">When an artist creates an account or profile, we may collect:</p>
      <ul class="terms-list">
        <li>Artist name</li><li>Email</li><li>Phone/WhatsApp number</li><li>City</li><li>Bio/about section</li><li>Art style or medium</li><li>Profile picture</li><li>Artwork images</li><li>Artwork descriptions, prices, and details</li><li>Social links, if provided</li>
      </ul>
      <p class="terms-text">Some artist profile information may be shown publicly after admin approval, such as: Artist name, City, Bio, Art style, Profile picture, Approved artworks.</p>
      <p class="terms-text">Private artist contact details, such as phone number, email, WhatsApp, address, or payment details, should not be shown publicly unless Art Bazaar Pakistan allows it in the future.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">5. Custom Artwork Requests</h3>
      <p class="terms-text">For custom artwork requests, we may collect:</p>
      <ul class="terms-list">
        <li>Buyer name and contact details</li><li>Preferred artist</li><li>Artwork type</li><li>Description</li><li>Estimated budget</li><li>Preferred deadline</li><li>Reference image</li><li>Delivery city/address</li><li>Chat or request messages</li><li>Final quote and order status</li>
      </ul>
      <p class="terms-text">Custom artwork pricing, shipping fee, and payment instructions are handled through Art Bazaar Pakistan. Buyer and artist private contact/payment details should not be shared publicly or in chat.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">6. Who Can See Your Information</h3>
      <p class="terms-text">Different users may see different information depending on their role.</p>
      <p class="terms-text"><strong>Admin may see:</strong> Buyer contact details, Artist contact details, Orders, Custom requests, Artwork submissions, Payment/order status, Messages needed for support or dispute handling.</p>
      <p class="terms-text"><strong>Artists may see:</strong> Their own profile and artwork information, Request/order details needed to complete work, Buyer request details needed for custom artwork. Artists should not automatically see buyer private contact details unless Art Bazaar Pakistan allows it for a specific reason.</p>
      <p class="terms-text"><strong>Buyers may see:</strong> Public artist profiles, Approved artworks, Their own orders and request details, Official updates from Art Bazaar Pakistan. Buyers should not see artist private contact details or payment details unless Art Bazaar Pakistan allows it for a specific reason.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">7. Payment Information</h3>
      <p class="terms-text">Art Bazaar Pakistan may record payment status, such as: Payment pending, Payment instructions sent, Payment received, Payment failed, Refunded or cancelled.</p>
      <div class="terms-highlight-box">
        <p>Users should not share bank account details, JazzCash, Easypaisa, card details, or other payment information in public pages, artist bios, artwork descriptions, or chat. Official payment instructions should only be shared by Art Bazaar Pakistan. We do not ask users to publicly post payment details.</p>
      </div>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">8. Shipping and Delivery Information</h3>
      <p class="terms-text">For delivery, we may collect: Buyer name, Phone number, Delivery address, City, Order details, Courier/tracking details. This information may be used to arrange delivery and provide order updates. Where necessary, limited delivery information may be shared with courier or delivery partners.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">9. Sharing Information With Third Parties</h3>
      <p class="terms-text">We do not sell user personal information. We may share limited information only when needed, such as with: Courier or delivery services, Payment or banking services (if used), Website hosting or technical service providers, Legal or official authorities (if required), Support teams or admins helping with orders/disputes. We only share information that is necessary for the specific purpose.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">10. Cookies and Website Data</h3>
      <p class="terms-text">Art Bazaar Pakistan may use cookies or similar technologies to: Keep users logged in, Improve website performance, Understand website traffic, Remember basic user preferences, Improve security. If analytics tools are used in the future, they may collect basic usage information such as page visits, browser type, device type, and general location. Users may disable cookies through their browser settings, but some website features may not work properly.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">11. Data Security</h3>
      <p class="terms-text">We try to protect user information through reasonable security measures. This may include: Account login protection, Admin-only access to private data, Limited access based on user role, Secure hosting practices, Avoiding public display of private contact/payment details. However, no website can guarantee 100% security. Users should also keep their passwords safe and avoid sharing private information publicly.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">12. Data Retention</h3>
      <p class="terms-text">We may keep user information as long as needed for: Account management, Orders and custom requests, Payment and delivery records, Dispute handling, Legal, tax, or business records, Platform safety and fraud prevention. If a user asks to delete their account, we may delete or hide their public profile information where possible. Some order, payment, or dispute records may need to be kept for business or legal reasons.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">13. User Rights and Requests</h3>
      <p class="terms-text">Users may contact Art Bazaar Pakistan to request: Access to their information, Correction of incorrect information, Deletion of account/profile information, Removal of public profile details, Help with privacy concerns. We may need to verify the user before making changes. Some information may not be deleted immediately if it is needed for order records, disputes, payment records, or legal/business requirements.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">14. Children and Young Users</h3>
      <p class="terms-text">Art Bazaar Pakistan is intended for users who can responsibly use an online marketplace. If a user is under 18, they should use the platform with permission and guidance from a parent or guardian, especially for buying, selling, payments, or delivery details. We do not knowingly encourage children to share private personal information without proper permission.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">15. Public Content</h3>
      <p class="terms-text">Some information may become public after approval, such as: Artist name, Artist city, Artist bio, Artist profile picture, Approved artworks, Artwork title, image, description, price, category, and city. Artists should not upload private information in artwork descriptions, bios, or images. Art Bazaar Pakistan may remove public content that includes private contact details, payment details, misleading claims, or unsafe information.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">16. Chat and Communication Privacy</h3>
      <p class="terms-text">Messages sent through Art Bazaar Pakistan may be reviewed by admin when needed for: Order support, Dispute handling, Safety, Fraud prevention, Rule enforcement.</p>
      <p class="terms-text">Users should not share private contact details or payment details in chat. This includes: Phone numbers, WhatsApp numbers, Instagram handles, Email addresses, Bank details, JazzCash/Easypaisa details, Home addresses, Direct payment instructions. Official payment instructions should only come from Art Bazaar Pakistan.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">17. Data Accuracy</h3>
      <p class="terms-text">Users are responsible for providing correct information. Art Bazaar Pakistan is not responsible for delays, failed delivery, wrong orders, or communication issues caused by incorrect information provided by the user. Buyers should make sure their delivery address and phone number are correct. Artists should make sure their artwork details, prices, and profile information are accurate.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">18. Changes to This Privacy Policy</h3>
      <p class="terms-text">Art Bazaar Pakistan may update this Privacy Policy from time to time. When changes are made, the updated version will be posted on the website with a new "Last updated" date. Continued use of the website means you accept the updated Privacy Policy.</p>
    </div>

    <div class="terms-section">
      <h3 class="terms-section-title">19. Contact</h3>
      <p class="terms-text">For privacy questions, correction requests, account deletion requests, or complaints, contact Art Bazaar Pakistan through the official Contact page or official support channel. Please include your name, email, and a clear explanation of your request.</p>
    </div>
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