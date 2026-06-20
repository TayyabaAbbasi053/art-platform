<?php
session_start();
require_once __DIR__ . '/config/db.php';

 $isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shipping Policy — Art Bazaar Pakistan</title>
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
    <?php if ($isLoggedIn): ?>
      <a href="dashboard/buyer/account.php" class="drawer-btn-ghost">My Account</a><a href="logout.php" class="drawer-btn-dark">Logout</a>
    <?php else: ?>
      <a href="login.php" class="drawer-btn-ghost">Login</a><a href="register.php" class="drawer-btn-dark">Join as Artist</a>
    <?php endif; ?>
  </div>
</div>

<div class="wrap">
  <div class="page-hd">
    <h1>Shipping Policy</h1>
    <p>Art Bazaar Pakistan — Last updated: [Add Date]</p>
  </div>
  <hr class="divhr">
</div>

<div class="terms-container">

  <div class="terms-highlight-box">
    <p>This Shipping Policy explains how shipping and delivery works on Art Bazaar Pakistan. By placing an order or requesting custom artwork, you agree to this Shipping Policy along with our Terms & Conditions, Buyer Terms, and Artist Terms.</p>
  </div>

  <!-- 1 -->
  <div class="terms-section">
    <h2 class="terms-section-title">1. Shipping Overview</h2>
    <p class="terms-text">Art Bazaar Pakistan helps coordinate delivery for ready-made and custom artwork orders. Shipping may be handled through courier services, delivery partners, or another delivery method approved by Art Bazaar Pakistan. Shipping details and charges may be confirmed before the order is finalized.</p>
  </div>

  <!-- 2 -->
  <div class="terms-section">
    <h2 class="terms-section-title">2. Shipping Fee Is Not Always Fixed</h2>
    <p class="terms-text">Shipping fee may vary from order to order. The final shipping fee may depend on:</p>
    <ul class="terms-list">
      <li>Buyer city</li>
      <li>Artist city</li>
      <li>Artwork size</li>
      <li>Artwork weight</li>
      <li>Framed or unframed artwork</li>
      <li>Fragility of the artwork</li>
      <li>Packaging requirements</li>
      <li>Courier charges</li>
      <li>Courier availability</li>
      <li>Delivery urgency</li>
    </ul>
    <p class="terms-text">For this reason, any shipping fee shown early on the website may be estimated and not final. The final shipping fee will be confirmed by Art Bazaar Pakistan before final payment or order confirmation.</p>
  </div>

  <!-- 3 -->
  <div class="terms-section">
    <h2 class="terms-section-title">3. Ready-Made Artwork Shipping</h2>
    <p class="terms-text">For ready-made artwork, shipping will be confirmed after Art Bazaar Pakistan checks:</p>
    <ul class="terms-list">
      <li>Artwork availability</li>
      <li>Artist location</li>
      <li>Buyer delivery city/address</li>
      <li>Artwork size and weight</li>
      <li>Packaging requirements</li>
      <li>Courier cost</li>
    </ul>
    <p class="terms-text">The order will only move forward after the final price, shipping fee, and payment process are confirmed by Art Bazaar Pakistan.</p>
  </div>

  <!-- 4 -->
  <div class="terms-section">
    <h2 class="terms-section-title">4. Custom Artwork Shipping</h2>
    <p class="terms-text">For custom artwork, shipping may be confirmed after the artwork details are finalized. Shipping may depend on:</p>
    <ul class="terms-list">
      <li>Final artwork size</li>
      <li>Whether it is framed or unframed</li>
      <li>Materials used</li>
      <li>Fragility</li>
      <li>Delivery city</li>
      <li>Packaging needs</li>
    </ul>
    <p class="terms-text">Custom artwork shipping fee may not be final at the request stage. The final quote may include:</p>
    <ul class="terms-list">
      <li>Artwork price</li>
      <li>Shipping fee</li>
      <li>Maintenance/platform fee</li>
      <li>Packaging or handling cost, if applicable</li>
    </ul>
  </div>

  <!-- 5 -->
  <div class="terms-section">
    <h2 class="terms-section-title">5. Delivery City and Address</h2>
    <p class="terms-text">Buyers must provide correct delivery details, including:</p>
    <ul class="terms-list">
      <li>Full name</li>
      <li>Phone/WhatsApp number</li>
      <li>City</li>
      <li>Complete delivery address</li>
      <li>Any delivery instructions, if needed</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan is not responsible for delays, failed delivery, or extra charges caused by incorrect or incomplete buyer information.</p>
  </div>

  <!-- 6 -->
  <div class="terms-section">
    <h2 class="terms-section-title">6. Packaging</h2>
    <p class="terms-text">Artists are responsible for properly packing the artwork before pickup or delivery. Artwork must be packed safely according to its type, size, frame, material, and fragility. For fragile, framed, glass-covered, canvas, paper-based, large, or high-value artworks, the artist must use suitable protective packaging to reduce the risk of damage during delivery.</p>
    <p class="terms-text">Packaging may depend on:</p>
    <ul class="terms-list">
      <li>Artwork size</li>
      <li>Frame</li>
      <li>Glass</li>
      <li>Canvas</li>
      <li>Paper quality</li>
      <li>Fragility</li>
      <li>Courier handling</li>
      <li>Delivery distance</li>
    </ul>
    <p class="terms-text">Extra packaging charges may apply for fragile, framed, large, or high-value artwork. If an artwork is fragile or needs special handling, the artist must inform Art Bazaar Pakistan before shipping is confirmed. Art Bazaar Pakistan may guide the artist on packaging requirements, but the artist is responsible for packing the artwork carefully before handing it over for delivery.</p>
  </div>

  <!-- 7 -->
  <div class="terms-section">
    <h2 class="terms-section-title">7. Framed and Fragile Artwork</h2>
    <p class="terms-text">Framed artwork may cost more to ship because it can be heavier and more fragile. If the artwork includes glass, delicate framing, or fragile material, extra packaging may be required. Art Bazaar Pakistan may recommend special packaging or courier handling for framed or fragile artwork. The buyer will be informed if extra packaging or handling charges are added to the final total.</p>
  </div>

  <!-- 8 -->
  <div class="terms-section">
    <h2 class="terms-section-title">8. Delivery Time</h2>
    <p class="terms-text">Delivery time is an estimate, not a guarantee. Delivery may be affected by:</p>
    <ul class="terms-list">
      <li>Courier delays</li>
      <li>Public holidays</li>
      <li>Weather conditions</li>
      <li>Incorrect address</li>
      <li>Buyer not responding to courier</li>
      <li>Artist preparation time</li>
      <li>Packaging time</li>
      <li>City-to-city courier timelines</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan will try to keep buyers updated where possible.</p>
  </div>

  <!-- 9 -->
  <div class="terms-section">
    <h2 class="terms-section-title">9. Shipping Status</h2>
    <p class="terms-text">Order status may include:</p>
    <ul class="terms-list">
      <li>Pending</li>
      <li>Confirmed</li>
      <li>Processing</li>
      <li>Packed</li>
      <li>Shipped</li>
      <li>Delivered</li>
      <li>Cancelled</li>
    </ul>
    <p class="terms-text">Where available, courier name and tracking number may be shared with the buyer. Tracking availability depends on the courier service used.</p>
  </div>

  <!-- 10 -->
  <div class="terms-section">
    <h2 class="terms-section-title">10. Delivery Coverage</h2>
    <p class="terms-text">Art Bazaar Pakistan may support delivery across Pakistan where courier service is available. Some areas may have limited courier coverage. If delivery is not available to the buyer’s location, Art Bazaar Pakistan may cancel the order or suggest another possible delivery method.</p>
  </div>

  <!-- 11 -->
  <div class="terms-section">
    <h2 class="terms-section-title">11. Buyer Responsibility During Delivery</h2>
    <p class="terms-text">The buyer is responsible for:</p>
    <ul class="terms-list">
      <li>Providing correct address and phone number</li>
      <li>Being available to receive the order</li>
      <li>Responding to courier calls/messages</li>
      <li>Checking the package carefully at delivery</li>
      <li>Reporting delivery issues quickly</li>
    </ul>
    <p class="terms-text">If the buyer is unavailable or gives incorrect details, delivery may be delayed, returned, or cancelled. Extra delivery charges may apply in some cases.</p>
  </div>

  <!-- 12 -->
  <div class="terms-section">
    <h2 class="terms-section-title">12. Artist Responsibility During Shipping</h2>
    <p class="terms-text">Artists must prepare artwork carefully for pickup or delivery after the order is confirmed. Artists should not ship artwork before Art Bazaar Pakistan confirms the order and payment process. Artists must inform Art Bazaar Pakistan if:</p>
    <ul class="terms-list">
      <li>Artwork is fragile</li>
      <li>Artwork is framed</li>
      <li>Artwork needs special packaging</li>
      <li>Artwork size or weight is different from the listing</li>
      <li>Artwork is no longer available</li>
      <li>Artwork needs careful courier handling</li>
    </ul>
    <p class="terms-text">Artists are responsible for packing the artwork safely before giving it to the courier or delivery partner. Poor packaging may affect refund, damage, or dispute decisions.</p>
  </div>

  <!-- 13 -->
  <div class="terms-section">
    <h2 class="terms-section-title">13. Damaged Package or Artwork</h2>
    <p class="terms-text">If the package or artwork arrives damaged, the buyer should contact Art Bazaar Pakistan as soon as possible. The buyer should provide:</p>
    <ul class="terms-list">
      <li>Order details</li>
      <li>Clear photos of the outer package</li>
      <li>Clear photos of the damaged artwork</li>
      <li>Photos of packaging material</li>
      <li>Courier receipt or tracking details, if available</li>
    </ul>
    <p class="terms-text">Damage claims may be reviewed by Art Bazaar Pakistan. Refund, replacement, or compensation decisions may depend on:</p>
    <ul class="terms-list">
      <li>Proof provided by the buyer</li>
      <li>Packaging quality</li>
      <li>Courier handling</li>
      <li>Artwork type</li>
      <li>Order type</li>
      <li>Whether the issue was reported quickly</li>
    </ul>
  </div>

  <!-- 14 -->
  <div class="terms-section">
    <h2 class="terms-section-title">14. Lost or Delayed Orders</h2>
    <p class="terms-text">If an order is delayed or appears lost, Art Bazaar Pakistan may contact the courier or delivery partner to check the status. Resolution may depend on courier policies and available proof. Art Bazaar Pakistan will try to support the buyer and artist, but courier delays or courier loss may sometimes be outside the platform’s control.</p>
  </div>

  <!-- 15 -->
  <div class="terms-section">
    <h2 class="terms-section-title">15. Shipping Changes</h2>
    <p class="terms-text">A buyer should request address changes as early as possible. Address changes may not be possible after the artwork has been shipped. If a changed address increases the shipping cost, the buyer may need to pay the difference before delivery continues.</p>
  </div>

  <!-- 16 -->
  <div class="terms-section">
    <h2 class="terms-section-title">16. Failed Delivery</h2>
    <p class="terms-text">Delivery may fail if:</p>
    <ul class="terms-list">
      <li>Address is incorrect</li>
      <li>Phone number is incorrect</li>
      <li>Buyer does not respond</li>
      <li>Buyer refuses delivery</li>
      <li>Courier cannot access the area</li>
      <li>Payment or delivery confirmation is incomplete</li>
    </ul>
    <p class="terms-text">If delivery fails, the order may be returned, delayed, or cancelled. Extra shipping charges may apply for re-delivery.</p>
  </div>

  <!-- 17 -->
  <div class="terms-section">
    <h2 class="terms-section-title">17. Shipping Fee in Final Total</h2>
    <p class="terms-text">The final total shared with the buyer may include:</p>
    <ul class="terms-list">
      <li>Artwork price</li>
      <li>Shipping fee</li>
      <li>Maintenance/platform fee</li>
      <li>Packaging or handling cost, if applicable</li>
    </ul>
    <p class="terms-text">The buyer should review the final total before confirming payment.</p>
  </div>

  <!-- 18 -->
  <div class="terms-section">
    <h2 class="terms-section-title">18. Contact for Shipping Issues</h2>
    <p class="terms-text">For shipping questions, delivery delays, damaged packages, or address corrections, contact Art Bazaar Pakistan through the official Contact page or support channel. Please include:</p>
    <ul class="terms-list">
      <li>Name</li>
      <li>Order/request details</li>
      <li>Phone/email used for the order</li>
      <li>Clear explanation of the issue</li>
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