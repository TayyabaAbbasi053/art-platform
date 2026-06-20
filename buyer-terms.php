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
<title>Buyer Terms — Art Bazaar Pakistan</title>
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
    <h1>Buyer Terms</h1>
    <p>Art Bazaar Pakistan — Last updated: [Add Date]</p>
  </div>
  <hr class="divhr">
</div>

<div class="terms-container">

  <div class="terms-highlight-box">
    <p>These Buyer Terms explain how buyers can use Art Bazaar Pakistan to browse artworks, place orders, and request custom artwork. By using Art Bazaar Pakistan as a buyer, you agree to these Buyer Terms, along with our Terms & Conditions and Privacy Policy.</p>
  </div>

  <!-- 1 -->
  <div class="terms-section">
    <h2 class="terms-section-title">1. Buying Through Art Bazaar Pakistan</h2>
    <p class="terms-text">Art Bazaar Pakistan helps buyers discover Pakistani artists, view artworks, and request ready-made or custom artwork. Buyers can:</p>
    <ul class="terms-list">
      <li>Browse available artworks</li>
      <li>View artist profiles</li>
      <li>Place purchase requests</li>
      <li>Request custom artwork</li>
      <li>Communicate through the platform where available</li>
      <li>Receive order and payment updates from Art Bazaar Pakistan</li>
    </ul>
  </div>

  <!-- 2 -->
  <div class="terms-section">
    <h2 class="terms-section-title">2. Buyer Account</h2>
    <p class="terms-text">Buyers may need an account to place orders, track requests, or manage communication. Buyers must provide correct information, including:</p>
    <ul class="terms-list">
      <li>Name</li>
      <li>Email</li>
      <li>Phone/WhatsApp number</li>
      <li>Delivery city/address when needed</li>
    </ul>
    <p class="terms-text">Incorrect information may cause delays, failed delivery, or cancelled orders.</p>
  </div>

  <!-- 3 -->
  <div class="terms-section">
    <h2 class="terms-section-title">3. Ready-Made Artwork Orders</h2>
    <p class="terms-text">Ready-made artworks are artworks already created and listed on the website. When a buyer places an order or submits a purchase request, the order is not final until Art Bazaar Pakistan confirms:</p>
    <ul class="terms-list">
      <li>Artwork availability</li>
      <li>Final price</li>
      <li>Shipping fee</li>
      <li>Delivery details</li>
      <li>Payment instructions</li>
    </ul>
    <p class="terms-text">If an artwork is already sold, unavailable, or removed, Art Bazaar Pakistan may cancel or reject the order request.</p>
  </div>

  <!-- 4 -->
  <div class="terms-section">
    <h2 class="terms-section-title">4. Custom Artwork Requests</h2>
    <p class="terms-text">Custom artwork means artwork made specially based on the buyer’s request. Buyers may be asked to provide:</p>
    <ul class="terms-list">
      <li>Artwork type</li>
      <li>Description</li>
      <li>Reference image</li>
      <li>Preferred artist</li>
      <li>Estimated budget</li>
      <li>Preferred deadline</li>
      <li>Size</li>
      <li>Framed/unframed preference</li>
      <li>Delivery city</li>
    </ul>
    <p class="terms-text">The budget provided by the buyer is only an estimate. Final pricing is confirmed after the request is reviewed. A custom request is not confirmed until the final quote is accepted and the payment process is confirmed by Art Bazaar Pakistan.</p>
  </div>

  <!-- 5 -->
  <div class="terms-section">
    <h2 class="terms-section-title">5. Final Price and Quote</h2>
    <p class="terms-text">For custom artwork, the final price may depend on:</p>
    <ul class="terms-list">
      <li>Artwork size</li>
      <li>Style and detail level</li>
      <li>Medium/materials</li>
      <li>Artist time and effort</li>
      <li>Deadline</li>
      <li>Framing</li>
      <li>Shipping and packaging</li>
      <li>Platform maintenance fee, if applicable</li>
    </ul>
    <p class="terms-text">Before payment, Art Bazaar Pakistan will try to clearly share the final amount. The final total may include:</p>
    <ul class="terms-list">
      <li>Artwork price</li>
      <li>Shipping fee</li>
      <li>Maintenance/platform fee</li>
      <li>Packaging or handling cost, if applicable</li>
    </ul>
  </div>

  <!-- 6 -->
  <div class="terms-section">
    <h2 class="terms-section-title">6. Shipping Fee</h2>
    <p class="terms-text">Shipping fee is not always fixed. Shipping may depend on:</p>
    <ul class="terms-list">
      <li>Buyer city</li>
      <li>Artist city</li>
      <li>Artwork size</li>
      <li>Weight</li>
      <li>Frame</li>
      <li>Fragility</li>
      <li>Courier cost</li>
      <li>Packaging needs</li>
    </ul>
    <p class="terms-text">The final shipping fee will be confirmed before the order is finalized.</p>
  </div>

  <!-- 7 -->
  <div class="terms-section">
    <h2 class="terms-section-title">7. Payment Instructions</h2>
    <div class="terms-highlight-box">
      <p>Payment instructions will only be shared officially by Art Bazaar Pakistan. Buyers should not: Pay artists directly unless officially allowed by Art Bazaar Pakistan, Share bank details in chat, Ask for artist payment details directly, Send JazzCash/Easypaisa/bank details in public messages, Make off-platform payments. Art Bazaar Pakistan is not responsible for losses caused by direct or off-platform payments.</p>
    </div>
  </div>

  <!-- 8 -->
  <div class="terms-section">
    <h2 class="terms-section-title">8. Buyer Communication Rules</h2>
    <p class="terms-text">Buyers must communicate respectfully with artists and platform staff. Buyers must not:</p>
    <ul class="terms-list">
      <li>Use abusive language</li>
      <li>Harass or pressure artists</li>
      <li>Submit fake requests</li>
      <li>Ask artists to work outside the platform</li>
      <li>Ask artists for direct payment/contact details</li>
      <li>Share inappropriate, illegal, or offensive references</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan may cancel requests or restrict accounts that break these rules.</p>
  </div>

  <!-- 9 -->
  <div class="terms-section">
    <h2 class="terms-section-title">9. Reference Images</h2>
    <p class="terms-text">Buyers may upload reference images for custom artwork. Reference images are used to help the artist understand the idea, style, pose, colors, or details. Buyers should not upload images they do not have the right to use. Art Bazaar Pakistan or the artist may reject a request if the reference image is inappropriate, copyrighted, unclear, or against platform rules.</p>
  </div>

  <!-- 10 -->
  <div class="terms-section">
    <h2 class="terms-section-title">10. Order Changes</h2>
    <p class="terms-text">For ready-made artworks, changes are usually not possible because the artwork already exists. For custom artwork, changes may be possible before the artist starts work. Once the artist has started working, major changes may:</p>
    <ul class="terms-list">
      <li>Cost extra</li>
      <li>Increase the timeline</li>
      <li>Be rejected if they are too different from the original request</li>
    </ul>
    <p class="terms-text">Buyers should give clear details before confirming the final quote.</p>
  </div>

  <!-- 11 -->
  <div class="terms-section">
    <h2 class="terms-section-title">11. Deadlines and Timelines</h2>
    <p class="terms-text">Buyers may provide a preferred deadline for custom artwork. A preferred deadline is not guaranteed until confirmed by Art Bazaar Pakistan or the artist. Delays may happen due to:</p>
    <ul class="terms-list">
      <li>Artist availability</li>
      <li>Artwork complexity</li>
      <li>Material issues</li>
      <li>Revisions</li>
      <li>Shipping delays</li>
      <li>Public holidays</li>
      <li>Courier problems</li>
    </ul>
    <p class="terms-text">Art Bazaar Pakistan will try to keep buyers updated where possible.</p>
  </div>

  <!-- 12 -->
  <div class="terms-section">
    <h2 class="terms-section-title">12. Cancellations</h2>
    <p class="terms-text">For ready-made artwork, cancellation may be possible before the artwork is shipped. For custom artwork, cancellation may be limited after the final quote is accepted or after the artist starts work. A buyer may not be eligible for a full refund if:</p>
    <ul class="terms-list">
      <li>The artist has already started the custom artwork</li>
      <li>Materials have already been purchased</li>
      <li>The buyer changes their mind after confirmation</li>
      <li>The buyer gave unclear or incorrect details</li>
      <li>The buyer approved the final quote and timeline</li>
    </ul>
    <p class="terms-text">Each cancellation will be reviewed based on the situation.</p>
  </div>

  <!-- 13 -->
  <div class="terms-section">
    <h2 class="terms-section-title">13. Refunds</h2>
    <p class="terms-text">Refunds may be considered if:</p>
    <ul class="terms-list">
      <li>The artist cannot complete the order</li>
      <li>The wrong artwork is delivered</li>
      <li>The order is cancelled before work/shipping starts</li>
      <li>The artwork is damaged during delivery and valid proof is provided</li>
      <li>Art Bazaar Pakistan decides a refund is fair after review</li>
    </ul>
    <p class="terms-text">Refunds may not be available if:</p>
    <ul class="terms-list">
      <li>The buyer changes their mind after work starts</li>
      <li>The custom artwork was made according to the approved request</li>
      <li>The buyer provided wrong delivery/contact details</li>
      <li>The buyer made payment outside the official platform process</li>
      <li>The issue happened due to off-platform communication</li>
    </ul>
  </div>

  <!-- 14 -->
  <div class="terms-section">
    <h2 class="terms-section-title">14. Delivery Issues</h2>
    <p class="terms-text">Buyers must provide correct delivery details. Art Bazaar Pakistan is not responsible for delivery problems caused by:</p>
    <ul class="terms-list">
      <li>Wrong address</li>
      <li>Wrong phone number</li>
      <li>Buyer not responding to courier</li>
      <li>Buyer not available to receive order</li>
      <li>Courier delays outside platform control</li>
    </ul>
    <p class="terms-text">If an artwork arrives damaged, the buyer should contact Art Bazaar Pakistan as soon as possible with:</p>
    <ul class="terms-list">
      <li>Order details</li>
      <li>Clear photos of the package</li>
      <li>Clear photos of the damaged artwork</li>
      <li>Any courier proof, if available</li>
    </ul>
  </div>

  <!-- 15 -->
  <div class="terms-section">
    <h2 class="terms-section-title">15. Buyer Responsibility</h2>
    <p class="terms-text">Buyers are responsible for:</p>
    <ul class="terms-list">
      <li>Reading artwork details before ordering</li>
      <li>Providing correct order details</li>
      <li>Confirming final quote before payment</li>
      <li>Following payment instructions carefully</li>
      <li>Checking delivery details</li>
      <li>Communicating respectfully</li>
      <li>Not bypassing the platform</li>
    </ul>
  </div>

  <!-- 16 -->
  <div class="terms-section">
    <h2 class="terms-section-title">16. Platform Support</h2>
    <p class="terms-text">Art Bazaar Pakistan may help buyers with:</p>
    <ul class="terms-list">
      <li>Order questions</li>
      <li>Custom request updates</li>
      <li>Payment instruction clarification</li>
      <li>Delivery issues</li>
      <li>Artist communication</li>
      <li>Refund or cancellation review</li>
      <li>Dispute handling</li>
    </ul>
    <p class="terms-text">Buyers should contact Art Bazaar Pakistan through the official Contact page or support channel.</p>
  </div>

  <!-- 17 -->
  <div class="terms-section">
    <h2 class="terms-section-title">17. Agreement</h2>
    <p class="terms-text">By placing an order or sending a custom artwork request, the buyer agrees that:</p>
    <ul class="terms-list">
      <li>Final pricing may be confirmed after review</li>
      <li>Shipping fee may vary</li>
      <li>Payment instructions will come from Art Bazaar Pakistan</li>
      <li>Custom artwork may have limited cancellation/refund options</li>
      <li>Direct payment/contact outside the platform is not protected by Art Bazaar Pakistan</li>
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