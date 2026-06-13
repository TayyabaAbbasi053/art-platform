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
<title>Payment & Fee Policy — Art Bazaar Pakistan</title>
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
    <h1>Payment & Fee Policy</h1>
    <p>Art Bazaar Pakistan — Last updated: [Add Date]</p>
  </div>
  <hr class="divhr">
</div>

<div class="terms-container">

  <div class="terms-highlight-box">
    <p>This Payment & Maintenance Fee Policy explains how payments, final totals, platform fees, and payment instructions work on Art Bazaar Pakistan. By placing an order, requesting custom artwork, or selling through Art Bazaar Pakistan, you agree to this policy along with our Terms & Conditions, Buyer Terms, Artist Terms, Shipping Policy, and Refund & Cancellation Policy.</p>
  </div>

  <!-- 1 -->
  <div class="terms-section">
    <h2 class="terms-section-title">1. Payment Overview</h2>
    <p class="terms-text">Art Bazaar Pakistan helps manage payment instructions for ready-made artwork orders and custom artwork requests. Payments should only be made according to the official instructions shared by Art Bazaar Pakistan. Buyers and artists should not arrange direct payments outside the platform unless Art Bazaar Pakistan officially allows it for a specific order.</p>
  </div>

  <!-- 2 -->
  <div class="terms-section">
    <h2 class="terms-section-title">2. Official Payment Instructions</h2>
    <p class="terms-text">Official payment instructions will only come from Art Bazaar Pakistan. Buyers should not trust payment details shared through:</p>
    <ul class="terms-list">
      <li>Artist bio</li>
      <li>Artwork description</li>
      <li>Public comments</li>
      <li>Unofficial chat</li>
      <li>Personal WhatsApp/Instagram messages</li>
      <li>Any source not confirmed by Art Bazaar Pakistan</li>
    </ul>
    <p class="terms-text">If a buyer is unsure about payment instructions, they should contact Art Bazaar Pakistan before paying.</p>
  </div>

  <!-- 3 -->
  <div class="terms-section">
    <h2 class="terms-section-title">3. No Payment Details in Chat</h2>
    <div class="terms-highlight-box">
      <p>Users should not share payment details in chat or public areas of the website. This includes: Bank account details, JazzCash details, Easypaisa details, Card details, Payment screenshots with private information, Direct payment instructions, Personal payment links. This rule helps protect buyers, artists, and the platform from scams and confusion.</p>
    </div>
  </div>

  <!-- 4 -->
  <div class="terms-section">
    <h2 class="terms-section-title">4. Final Total</h2>
    <p class="terms-text">The final amount payable by the buyer may include:</p>
    <ul class="terms-list">
      <li>Artwork price</li>
      <li>Shipping fee</li>
      <li>Maintenance/platform fee</li>
      <li>Packaging or handling cost, if applicable</li>
      <li>Any other agreed charges confirmed before payment</li>
    </ul>
    <p class="terms-text">The buyer should review the final total before making payment.</p>
  </div>

  <!-- 5 -->
  <div class="terms-section">
    <h2 class="terms-section-title">5. Ready-Made Artwork Payments</h2>
    <p class="terms-text">For ready-made artworks, payment will be confirmed after Art Bazaar Pakistan checks:</p>
    <ul class="terms-list">
      <li>Artwork availability</li>
      <li>Artwork price</li>
      <li>Buyer delivery details</li>
      <li>Shipping fee</li>
      <li>Final total</li>
      <li>Payment method</li>
    </ul>
    <p class="terms-text">The order is not final until Art Bazaar Pakistan confirms the payment process. Artists should not ship or hand over artwork before Art Bazaar Pakistan confirms the order/payment process.</p>
  </div>

  <!-- 6 -->
  <div class="terms-section">
    <h2 class="terms-section-title">6. Custom Artwork Payments</h2>
    <p class="terms-text">For custom artwork, the buyer’s budget is only an estimate. The final quote may be shared after the request details are reviewed. The final quote may include:</p>
    <ul class="terms-list">
      <li>Final artwork price</li>
      <li>Shipping fee</li>
      <li>Maintenance/platform fee</li>
      <li>Packaging or handling cost, if applicable</li>
      <li>Estimated timeline</li>
    </ul>
    <p class="terms-text">Custom artwork should not begin until the final quote and payment process are confirmed through Art Bazaar Pakistan. Depending on the order, Art Bazaar Pakistan may require:</p>
    <ul class="terms-list">
      <li>Full payment before work begins</li>
      <li>Partial advance payment before work begins</li>
      <li>Remaining payment before delivery</li>
      <li>Another payment schedule agreed and confirmed by Art Bazaar Pakistan</li>
    </ul>
    <p class="terms-text">The payment schedule should be clearly communicated before work starts.</p>
  </div>

  <!-- 7 -->
  <div class="terms-section">
    <h2 class="terms-section-title">7. Maintenance / Platform Fee</h2>
    <p class="terms-text">Art Bazaar Pakistan may charge a maintenance/platform fee for managing the platform and order process. This fee helps support:</p>
    <ul class="terms-list">
      <li>Website maintenance</li>
      <li>Admin review</li>
      <li>Artist profile and artwork approval</li>
      <li>Buyer and artist support</li>
      <li>Order management</li>
      <li>Custom request management</li>
      <li>Payment coordination</li>
      <li>Dispute handling</li>
      <li>Platform safety</li>
    </ul>
    <p class="terms-text">This fee should be called a maintenance fee or platform fee, not "commission." Where applicable, the maintenance/platform fee should be included clearly in the final total before payment.</p>
  </div>

  <!-- 8 -->
  <div class="terms-section">
    <h2 class="terms-section-title">8. Artist Payment</h2>
    <p class="terms-text">Artist payment may be processed after Art Bazaar Pakistan confirms the buyer’s payment and order status. Artist payout may depend on:</p>
    <ul class="terms-list">
      <li>Payment received from buyer</li>
      <li>Order confirmation</li>
      <li>Artwork completion</li>
      <li>Shipping/delivery status</li>
      <li>Refund or dispute status</li>
      <li>Platform maintenance fee, if applicable</li>
    </ul>
    <p class="terms-text">If there is a dispute, refund request, damaged delivery issue, or cancellation, artist payment may be delayed until the issue is reviewed.</p>
  </div>

  <!-- 9 -->
  <div class="terms-section">
    <h2 class="terms-section-title">9. Cash on Delivery</h2>
    <p class="terms-text">Cash on Delivery may only be available if Art Bazaar Pakistan clearly offers it for a specific order. Cash on Delivery should not be assumed for every order. COD availability may depend on:</p>
    <ul class="terms-list">
      <li>Courier support</li>
      <li>Buyer city</li>
      <li>Artwork price</li>
      <li>Artwork type</li>
      <li>Ready-made or custom order</li>
      <li>Platform decision</li>
    </ul>
    <p class="terms-text">For custom artwork, COD may not always be suitable because artists may need payment confirmation before starting work.</p>
  </div>

  <!-- 10 -->
  <div class="terms-section">
    <h2 class="terms-section-title">10. Payment Confirmation</h2>
    <p class="terms-text">A payment is considered confirmed only when Art Bazaar Pakistan verifies it. Buyers may be asked to provide proof of payment if needed. Payment proof should be shared only through official platform/support channels. Buyers should avoid sharing payment proof publicly or with private information visible.</p>
  </div>

  <!-- 11 -->
  <div class="terms-section">
    <h2 class="terms-section-title">11. Failed or Delayed Payments</h2>
    <p class="terms-text">If payment is delayed, failed, or not verified, the order may stay pending. Art Bazaar Pakistan may cancel or pause an order if:</p>
    <ul class="terms-list">
      <li>Payment is not received</li>
      <li>Payment proof is unclear</li>
      <li>Buyer does not respond</li>
      <li>Payment is made to the wrong account</li>
      <li>Payment is made outside the official process</li>
    </ul>
  </div>

  <!-- 12 -->
  <div class="terms-section">
    <h2 class="terms-section-title">12. Off-Platform Payments</h2>
    <p class="terms-text">Art Bazaar Pakistan is not responsible for losses, scams, failed orders, or disputes caused by off-platform payments. Off-platform payment means payment arranged directly between buyer and artist without official confirmation from Art Bazaar Pakistan.</p>
    <p class="terms-text">Users who repeatedly try to bypass the platform may face:</p>
    <ul class="terms-list">
      <li>Order cancellation</li>
      <li>Account warning</li>
      <li>Account suspension</li>
      <li>Removal from the platform</li>
    </ul>
  </div>

  <!-- 13 -->
  <div class="terms-section">
    <h2 class="terms-section-title">13. Refunds and Payment Reversals</h2>
    <p class="terms-text">Refunds are handled according to the Refund & Cancellation Policy. Refund eligibility may depend on:</p>
    <ul class="terms-list">
      <li>Order type</li>
      <li>Order stage</li>
      <li>Whether artist has started work</li>
      <li>Whether artwork has shipped</li>
      <li>Payment method</li>
      <li>Proof provided</li>
      <li>Dispute review</li>
      <li>Whether payment was made officially or off-platform</li>
    </ul>
    <p class="terms-text">Payments made outside Art Bazaar Pakistan may not be protected or refundable through the platform.</p>
  </div>

  <!-- 14 -->
  <div class="terms-section">
    <h2 class="terms-section-title">14. Price Changes</h2>
    <p class="terms-text">Prices may change before final confirmation due to:</p>
    <ul class="terms-list">
      <li>Shipping fee update</li>
      <li>Packaging cost</li>
      <li>Artwork size/details</li>
      <li>Custom request changes</li>
      <li>Deadline changes</li>
      <li>Material cost</li>
      <li>Framing request</li>
      <li>Courier charges</li>
    </ul>
    <p class="terms-text">Once the final quote is accepted and payment is confirmed, changes should only happen if both sides agree and Art Bazaar Pakistan confirms it.</p>
  </div>

  <!-- 15 -->
  <div class="terms-section">
    <h2 class="terms-section-title">15. Taxes or Extra Charges</h2>
    <p class="terms-text">If any tax, bank charge, transfer fee, courier charge, or government requirement applies, it may be added or adjusted where necessary. Art Bazaar Pakistan will try to communicate any such charges clearly before final payment where possible.</p>
  </div>

  <!-- 16 -->
  <div class="terms-section">
    <h2 class="terms-section-title">16. User Responsibility</h2>
    <p class="terms-text"><strong>Buyers are responsible for:</strong></p>
    <ul class="terms-list">
      <li>Reading the final total carefully</li>
      <li>Paying only through official instructions</li>
      <li>Confirming payment details before sending money</li>
      <li>Not sharing private payment details publicly</li>
      <li>Keeping payment proof safely</li>
    </ul>
    <p class="terms-text"><strong>Artists are responsible for:</strong></p>
    <ul class="terms-list">
      <li>Not asking buyers for direct payment</li>
      <li>Not sharing payment details publicly</li>
      <li>Waiting for Art Bazaar Pakistan confirmation before starting/shipping where required</li>
      <li>Following the agreed price and payment process</li>
    </ul>
  </div>

  <!-- 17 -->
  <div class="terms-section">
    <h2 class="terms-section-title">17. Contact for Payment Questions</h2>
    <p class="terms-text">For payment questions, payment confirmation, refund questions, or suspicious payment instructions, contact Art Bazaar Pakistan through the official Contact page or support channel. Please include:</p>
    <ul class="terms-list">
      <li>Name</li>
      <li>Order/request details</li>
      <li>Payment method, if relevant</li>
      <li>Clear explanation of the issue</li>
      <li>Payment proof, if requested</li>
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