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

 $contactSuccess = false;
 $contactError = false;
 $errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (!$name || !$message) {
        $contactError = true;
        $errorMessage = 'Name and message are required.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contactError = true;
        $errorMessage = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, phone, message, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->bind_param("ssss", $name, $email, $phone, $message);
        
        if ($stmt->execute()) {
            $contactSuccess = true;
        } else {
            $contactError = true;
            $errorMessage = 'Failed to send message. Please try again.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact Us — Art Bazaar</title>
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

/* HERO */
.hero{background:var(--ink);padding:52px 28px;}
.hero-inner{max-width:var(--w);margin:0 auto;}
.hero-tag{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:8px;}
.hero h1{font-family:'Playfair Display',serif;font-size:clamp(32px,3.5vw,44px);font-weight:400;color:var(--bg);line-height:1.15;}
.hero h1 em{font-style:italic;color:var(--sand);}
.hero-desc{font-size:14px;color:rgba(246,237,222,.5);max-width:560px;margin-top:12px;}

/* MAIN CONTENT */
.main{max-width:var(--w);margin:0 auto;padding:48px 28px;display:grid;grid-template-columns:1fr 0.9fr;gap:48px;}

/* LEFT: CONTACT INFO */
.info-section{margin-bottom:32px;}
.info-title{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;margin-bottom:16px;}
.info-text{color:var(--body);line-height:1.7;margin-bottom:28px;}
.contact-details{margin-bottom:28px;}
.contact-item{display:flex;align-items:center;gap:12px;margin-bottom:16px;}
.contact-icon{width:40px;height:40px;background:var(--sand);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--ink);flex-shrink:0;}
.contact-detail strong{display:block;font-size:13px;margin-bottom:2px;}
.contact-detail span{font-size:12px;color:var(--muted);}
.social-links{display:flex;gap:12px;margin-top:24px;}
.social-link{width:38px;height:38px;background:var(--sand);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--ink);transition:all .12s;}
.social-link:hover{background:var(--border);color:var(--bg);}

/* RIGHT: FORM */
.form-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;}
.form-title{font-family:'Playfair Display',serif;font-size:22px;font-weight:400;margin-bottom:4px;}
.form-sub{font-size:12px;color:var(--muted);margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border);}
.fg{margin-bottom:16px;}
.fg label{display:block;font-size:10.5px;letter-spacing:.7px;text-transform:uppercase;color:var(--body);font-weight:500;margin-bottom:6px;}
.fg label span{color:var(--ink);}
.fi,.ft{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;background:var(--bg);outline:none;transition:border-color .12s;}
.fi:focus,.ft:focus{border-color:var(--ink);}
.ft{min-height:120px;resize:vertical;line-height:1.55;}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.msub{width:100%;background:var(--ink);color:var(--bg);border:none;padding:12px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;margin-top:8px;transition:background .15s;}
.msub:hover{background:var(--body);}
.mmsg{padding:12px 16px;border-radius:8px;font-size:12.5px;margin-bottom:16px;}
.mmsg.ok{background:#EBF5EE;color:#2A6040;border:1px solid #B8DFC8;}
.mmsg.er{background:#FCEEE9;color:#7D2A14;border:1px solid #EEC5B8;}

/* MAP PLACEHOLDER */
.map-placeholder{background:var(--sand);border-radius:12px;padding:24px;text-align:center;margin-top:24px;}
.map-placeholder svg{opacity:.3;margin-bottom:8px;}
.map-placeholder p{font-size:11px;color:var(--muted);}

/* FOOTER */
.footer{background:var(--ink);color:var(--bg);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:var(--bg);display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;line-height:1.65;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:var(--sand);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(246,237,222,.42);margin-bottom:8px;transition:color .12s;}
.fc a:hover{color:var(--bg);}
.fbot{border-top:1px solid rgba(246,237,222,.07);padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:11.5px;}

/* ─── RESPONSIVE ─── */

/* Tablet (max-width: 1080px) */
@media(max-width:1080px){
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

  /* Layout */
  .main{grid-template-columns:1fr;}
  .frow{grid-template-columns:1fr;}
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
      <a href="blog.php">Blog</a> 
      <a href="commission.php">Commission Art</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php" class="active">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search...">
    </div>
    <div class="nend">
      <a href="cart.php" class="cart-icon">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
        <?php $cartCount = getCartCount(); if ($cartCount > 0): ?>
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

<!-- HERO -->
<section class="hero" style="padding:0;position:relative;">
  <img src="contacthero.jpeg" alt="Contact Us" style="width:100%;height:auto;object-fit:contain;display:block;">
  <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(12,63,48,0.45);display:flex;align-items:flex-end;padding-bottom:40px;">
    <div class="hero-inner" style="padding:0 28px;">
      <div class="hero-tag">GET IN TOUCH</div>
      <h1>We'd love to <em>hear from you</em>.</h1>
      <p class="hero-desc">Have questions about buying, selling, or commissions? Our team is here to help.</p>
    </div>
  </div>
</section>

<!-- MAIN CONTENT -->
<div class="main">
  
  <!-- LEFT: CONTACT INFO -->
  <div>
    <div class="info-section">
      <h3 class="info-title">Reach out to us</h3>
      <p class="info-text">Whether you're an artist looking to join, a buyer with a question, or just want to say hello — we'd love to hear from you. Our team typically responds within 24-48 hours.</p>
      
      <div class="contact-details">
        <div class="contact-item">
          <div class="contact-icon"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
          <div class="contact-detail"><strong>Email</strong><span>info@artbazaar.pk</span></div>
        </div>
        <div class="contact-item">
          <div class="contact-icon"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.362 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg></div>
          <div class="contact-detail"><strong>WhatsApp</strong><span>+92 300 123 4567</span></div>
        </div>
        <div class="contact-item">
          <div class="contact-icon"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
          <div class="contact-detail"><strong>Office</strong><span>Lahore, Pakistan</span></div>
        </div>
      </div>
      
      <div class="social-links">
        <a href="#" class="social-link"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="5"/><line x1="17" y1="7" x2="17.01" y2="7"/></svg></a>
        <a href="#" class="social-link"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg></a>
        <a href="#" class="social-link"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z"/></svg></a>
      </div>
    </div>
    
    <div class="map-placeholder">
      <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><path d="M12 2a7 7 0 00-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 00-7-7z"/><circle cx="12" cy="9" r="2"/></svg>
      <p>Lahore, Pakistan — We're online, serving artists and collectors nationwide</p>
    </div>
  </div>
  
  <!-- RIGHT: FORM -->
  <div class="form-card">
    <h2 class="form-title">Send us a message</h2>
    <p class="form-sub">We'll get back to you as soon as possible.</p>
    
    <?php if ($contactSuccess): ?>
      <div class="mmsg ok">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:6px;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Message sent successfully! We'll respond within 24-48 hours.
      </div>
    <?php endif; ?>
    
    <?php if ($contactError): ?>
      <div class="mmsg er"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>
    
    <form method="POST" action="">
      <div class="frow">
        <div class="fg">
          <label>Your Name <span>*</span></label>
          <input type="text" name="name" class="fi" placeholder="Full name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>
        <div class="fg">
          <label>Email</label>
          <input type="email" name="email" class="fi" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
      </div>
      
      <div class="fg">
        <label>Phone / WhatsApp</label>
        <input type="tel" name="phone" class="fi" placeholder="+92 300 0000000" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>
      
      <div class="fg">
        <label>Message <span>*</span></label>
        <textarea name="message" class="ft" placeholder="Tell us how we can help..." required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
      </div>
      
      <button type="submit" class="msub">Send Message</button>
    </form>
  </div>
  
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
    <a href="blog.php">Blog</a> 
    <a href="commission.php">Commission Art</a>
    <a href="sell.php">Sell Your Art</a>
    <a href="about.php">About Us</a>
    <a href="contact.php">Contact</a>
  </div>
  <div class="drawer-actions">
    <a href="cart.php" class="drawer-cart">🛒 Cart</a>
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
</script>
</body>
</html>