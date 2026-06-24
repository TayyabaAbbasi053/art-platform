<?php
session_start();
require_once __DIR__ . '/config/db.php';

// ── Auth guard ───────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    $_SESSION['redirect_after_login'] = 'process-payment.php';
    header('Location: login.php');
    exit;
}

 $buyerId = (int) $_SESSION['user_id'];

// ── Check if order ID is provided ────────────────────────
 $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0);

if (!$orderId) {
    header('Location: orders.php');
    exit;
}

// ── Fetch order details and verify ownership ─────────────
// Changed to LEFT JOIN users to support guest orders (buyer_id can be NULL)
 $stmt = $conn->prepare("
    SELECT o.*, u.name AS buyer_name, u.email AS buyer_email
    FROM orders o
    LEFT JOIN users u ON o.buyer_id = u.id
    WHERE o.id = ? AND (o.buyer_id = ? OR o.buyer_id IS NULL) AND o.payment_status = 'pending'
");
 $stmt->bind_param('ii', $orderId, $buyerId);
 $stmt->execute();
 $order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// ── Handle payment method selection and redirect ─────────
 $paymentMethod = $_POST['payment_method'] ?? ($order['payment_method'] ?? '');
 $paymentError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $selectedMethod = $_POST['payment_method'] ?? '';
    
    if (!in_array($selectedMethod, ['bank_transfer', 'easypaisa', 'jazzcash'])) {
        $paymentError = 'Please select a valid payment method.';
    } else {
        // Update payment method if changed
        if ($selectedMethod !== $order['payment_method']) {
            $stmt = $conn->prepare("UPDATE orders SET payment_method = ? WHERE id = ?");
            $stmt->bind_param('si', $selectedMethod, $orderId);
            $stmt->execute();
            $order['payment_method'] = $selectedMethod;
        }
        
        // Redirect based on payment method
        switch ($selectedMethod) {
            case 'bank_transfer':
                header("Location: payment-instructions.php?order_id=$orderId&method=bank");
                break;
            case 'easypaisa':
                header("Location: payment-instructions.php?order_id=$orderId&method=easypaisa");
                break;
            case 'jazzcash':
                header("Location: payment-instructions.php?order_id=$orderId&method=jazzcash");
                break;
            default:
                header("Location: payment-instructions.php?order_id=$orderId&method=cod");
        }
        exit;
    }
}

// ── Handle COD confirmation (no payment processing needed) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cod'])) {
    // For COD, order is confirmed immediately
    $conn->begin_transaction();
    
    try {
        // Update order status
        $stmt = $conn->prepare("UPDATE orders SET order_status = 'confirmed', payment_status = 'pending' WHERE id = ?");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        
        // Add status history
        $stmt = $conn->prepare("
            INSERT INTO order_status_history (order_id, status_from, status_to, changed_by_role, changed_by_id, notes)
            VALUES (?, 'pending', 'confirmed', 'buyer', ?, 'Order confirmed with Cash on Delivery')
        ");
        $stmt->bind_param('ii', $orderId, $buyerId);
        $stmt->execute();
        
        $conn->commit();
        
        header("Location: order-confirmation.php?order_id=$orderId");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $paymentError = 'Failed to process order. Please try again.';
    }
}

// ── Get payment method details ───────────────────────────
function getPaymentMethodDetails($method) {
    $details = [
        'bank_transfer' => [
            'name' => 'Bank Transfer',
            'icon' => '🏦',
            'account_title' => 'Art Bazaar (Pvt) Ltd',
            'account_number' => '1234-567890-01',
            'bank_name' => 'HBL (Habib Bank Limited)',
            'branch' => 'Lahore Main Branch',
            'iban' => 'PK36HABB0012345678901234',
            'instructions' => 'Please use your Order Number as the reference/description when transferring funds.'
        ],
        'easypaisa' => [
            'name' => 'Easypaisa',
            'icon' => '📱',
            'account_title' => 'Art Bazaar',
            'easypaisa_number' => '03XX 1234567',
            'instructions' => 'Open Easypaisa app → Send Money → Enter Mobile Number → Enter amount → Add Order Number in notes.'
        ],
        'jazzcash' => [
            'name' => 'JazzCash',
            'icon' => '📱',
            'account_title' => 'Art Bazaar',
            'jazzcash_number' => '03XX 7654321',
            'instructions' => 'Open JazzCash app → Send Money → Enter Mobile Number → Enter amount → Add Order Number in notes.'
        ],
        'cod' => [
            'name' => 'Cash on Delivery',
            'icon' => '💰',
            'instructions' => 'Pay cash to the delivery person when your order arrives. No advance payment needed.'
        ]
    ];
    return $details[$method] ?? $details['cod'];
}

 $orderTotal = $order['total'];
 $orderNumber = $order['order_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Process Payment — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#F7F1E8; --card:#FFFDF8; --sand:#EFE3D2; --border:#E6DDD0;
  --ink:#1E1B18; --body:#3D332A; --muted:#8A7D72; --light:#A79B8E;
  --terr:#C96B4B; --terr2:#9F482F; --sage:#2F6F5E; --gold:#C9A96E;
  --w:1280px; --r:10px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.55;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}

/* NAV */
.nav{background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:200;}
.nw{max-width:var(--w);margin:0 auto;padding:0 28px;height:58px;display:flex;align-items:center;gap:16px;}
.nlogo{flex-shrink:0;display:flex;flex-direction:column;line-height:1;}
.nlogo b{font-family:'Playfair Display',serif;font-size:18px;font-weight:500;color:var(--ink);}
.nlogo small{font-size:7.5px;letter-spacing:2.5px;text-transform:uppercase;color:var(--light);}
.nlinks{display:flex;align-items:center;gap:1px;flex:1;}
.nlinks a{font-size:12.5px;color:var(--body);padding:6px 10px;border-radius:6px;}
.nlinks a:hover,.nlinks a.active{background:var(--sand);}
.nsearch{display:flex;align-items:center;gap:6px;background:var(--sand);border:1px solid var(--border);border-radius:6px;padding:6px 12px;width:210px;}
.nsearch input{border:none;background:transparent;font-size:12.5px;outline:none;width:100%;}
.nend{display:flex;align-items:center;gap:8px;}
.btn-ghost{font-size:12.5px;color:var(--body);padding:7px 14px;border-radius:6px;border:1px solid var(--border);background:transparent;cursor:pointer;}
.btn-ghost:hover{border-color:var(--muted);background:var(--sand);}
.btn-dark{font-size:12.5px;color:#fff;padding:7px 16px;border-radius:6px;background:var(--ink);cursor:pointer;font-weight:500;}
.btn-dark:hover{background:var(--body);}

/* BREADCRUMB */
.breadcrumb{max-width:var(--w);margin:0 auto;padding:20px 28px 0;font-size:12px;color:var(--muted);}
.breadcrumb a{color:var(--body);}
.breadcrumb a:hover{color:var(--terr);}

/* MAIN */
.main{max-width:var(--w);margin:0 auto;padding:28px;}
.page-title{font-family:'Playfair Display',serif;font-size:28px;font-weight:400;margin-bottom:8px;}
.page-sub{color:var(--muted);margin-bottom:28px;}

/* TWO COLUMN */
.payment-layout{display:grid;grid-template-columns:1fr 360px;gap:32px;}

/* PAYMENT METHODS */
.methods-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:24px;}
.methods-title{font-size:16px;font-weight:600;margin-bottom:16px;}
.method-option{display:flex;align-items:center;gap:14px;padding:14px;border:1.5px solid var(--border);border-radius:12px;margin-bottom:12px;cursor:pointer;transition:all .15s;}
.method-option:hover{background:var(--sand);}
.method-option.selected{border-color:var(--terr);background:var(--sand);}
.method-radio{width:18px;height:18px;border-radius:50%;border:2px solid var(--muted);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.method-option.selected .method-radio{border-color:var(--terr);}
.method-radio.selected-dot{width:10px;height:10px;border-radius:50%;background:var(--terr);}
.method-icon{font-size:28px;}
.method-info{flex:1;}
.method-name{font-weight:600;margin-bottom:2px;}
.method-desc{font-size:11px;color:var(--muted);}

/* ORDER SUMMARY */
.summary-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;position:sticky;top:80px;}
.summary-title{font-size:16px;font-weight:600;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.summary-row{display:flex;justify-content:space-between;margin-bottom:12px;font-size:13px;}
.summary-row.total{margin-top:12px;padding-top:12px;border-top:1px solid var(--border);font-weight:600;font-size:16px;}
.order-number{background:var(--sand);padding:10px;border-radius:8px;text-align:center;margin-bottom:16px;font-size:12px;}
.order-number strong{color:var(--ink);}
.payment-buttons{display:flex;flex-direction:column;gap:10px;margin-top:20px;}
.btn-pay{background:var(--ink);color:#fff;border:none;padding:14px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;transition:background .15s;}
.btn-pay:hover{background:var(--body);}
.btn-cod{background:var(--terr);color:#fff;border:none;padding:14px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;transition:background .15s;}
.btn-cod:hover{background:var(--terr2);}
.btn-back{background:transparent;border:1px solid var(--border);padding:12px;border-radius:8px;font-size:13px;cursor:pointer;transition:all .15s;}
.btn-back:hover{border-color:var(--terr);color:var(--terr);}

/* ERROR MESSAGE */
.error-msg{background:#FCEEE9;color:#7D2A14;border:1px solid #EEC5B8;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;}

/* FOOTER */
.footer{background:var(--ink);color:rgba(255,255,255,.48);margin-top:56px;}
.fw{max-width:var(--w);margin:0 auto;padding:40px 28px 26px;}
.fg-foot{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:32px;}
.fb b{font-family:'Playfair Display',serif;font-size:17px;color:#fff;display:block;margin-bottom:7px;}
.fb p{font-size:12.5px;max-width:230px;}
.fc h4{font-size:9.5px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.32);margin-bottom:11px;}
.fc a{display:block;font-size:12.5px;color:rgba(255,255,255,.42);margin-bottom:8px;}
.fc a:hover{color:#fff;}
.fbot{border-top:1px solid rgba(255,255,255,.07);padding-top:18px;display:flex;justify-content:space-between;font-size:11.5px;}

@media(max-width:900px){.payment-layout{grid-template-columns:1fr;}}
@media(max-width:768px){.nlinks,.nsearch{display:none;}.fg-foot{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- NAV -->
<nav class="nav">
  <div class="nw">
    <a href="index.php" class="nlogo"><b>Art Bazaar</b><small>Marketplace</small></a>
    <div class="nlinks">
      <a href="artworks.php">Explore Art</a>
      <a href="artists.php">Artists</a>
      <a href="commission.php">Custom Artwork</a>
      <a href="sell.php">Sell Your Art</a>
      <a href="about.php">About Us</a>
      <a href="contact.php">Contact</a>
    </div>
    <div class="nsearch">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input type="text" placeholder="Search...">
    </div>
    <div class="nend">
      <span style="font-size:13px;color:var(--body);">Welcome, <?= htmlspecialchars($_SESSION['name'] ?? 'Buyer') ?></span>
      <a href="logout.php" class="btn-ghost">Logout</a>
    </div>
  </div>
</nav>

<!-- BREADCRUMB -->
<div class="breadcrumb">
  <a href="index.php">Home</a> <span>/</span>
  <a href="cart.php">Cart</a> <span>/</span>
  <a href="checkout.php">Checkout</a> <span>/</span>
  <span>Payment</span>
</div>

<!-- MAIN CONTENT -->
<div class="main">
  <h1 class="page-title">Complete Payment</h1>
  <p class="page-sub">Choose your preferred payment method for Order #<?= htmlspecialchars($orderNumber) ?></p>
  
  <?php if ($paymentError): ?>
    <div class="error-msg"><?= htmlspecialchars($paymentError) ?></div>
  <?php endif; ?>
  
  <div class="payment-layout">
    
    <!-- LEFT: PAYMENT METHODS -->
    <div>
      <div class="methods-card">
        <div class="methods-title">Select Payment Method</div>
        
        <form method="POST" id="paymentForm">
          <input type="hidden" name="order_id" value="<?= $orderId ?>">
          
          <!-- Bank Transfer -->
          <div class="method-option <?= $paymentMethod === 'bank_transfer' ? 'selected' : '' ?>" onclick="selectMethod('bank_transfer')">
            <div class="method-radio"><div class="selected-dot" style="display:<?= $paymentMethod === 'bank_transfer' ? 'block' : 'none' ?>"></div></div>
            <div class="method-icon">🏦</div>
            <div class="method-info">
              <div class="method-name">Bank Transfer</div>
              <div class="method-desc">Transfer directly to our bank account</div>
            </div>
            <input type="radio" name="payment_method" value="bank_transfer" style="display:none;" <?= $paymentMethod === 'bank_transfer' ? 'checked' : '' ?>>
          </div>
          
          <!-- Easypaisa -->
          <div class="method-option <?= $paymentMethod === 'easypaisa' ? 'selected' : '' ?>" onclick="selectMethod('easypaisa')">
            <div class="method-radio"><div class="selected-dot" style="display:<?= $paymentMethod === 'easypaisa' ? 'block' : 'none' ?>"></div></div>
            <div class="method-icon">📱</div>
            <div class="method-info">
              <div class="method-name">Easypaisa</div>
              <div class="method-desc">Pay using Easypaisa mobile account</div>
            </div>
            <input type="radio" name="payment_method" value="easypaisa" style="display:none;" <?= $paymentMethod === 'easypaisa' ? 'checked' : '' ?>>
          </div>
          
          <!-- JazzCash -->
          <div class="method-option <?= $paymentMethod === 'jazzcash' ? 'selected' : '' ?>" onclick="selectMethod('jazzcash')">
            <div class="method-radio"><div class="selected-dot" style="display:<?= $paymentMethod === 'jazzcash' ? 'block' : 'none' ?>"></div></div>
            <div class="method-icon">📱</div>
            <div class="method-info">
              <div class="method-name">JazzCash</div>
              <div class="method-desc">Pay using JazzCash mobile account</div>
            </div>
            <input type="radio" name="payment_method" value="jazzcash" style="display:none;" <?= $paymentMethod === 'jazzcash' ? 'checked' : '' ?>>
          </div>
        </form>
      </div>
      
      <div class="methods-card">
        <div class="methods-title">Payment Information</div>
        <div style="font-size:13px;color:var(--body);line-height:1.65;">
          <p>✅ All transactions are secure and encrypted.</p>
          <p>✅ Your order will be processed once payment is confirmed.</p>
          <p>✅ For online payments, you'll receive confirmation within 24 hours.</p>
        </div>
      </div>
    </div>
    
    <!-- RIGHT: ORDER SUMMARY -->
    <div>
      <div class="summary-card">
        <div class="summary-title">Order Summary</div>
        
        <div class="order-number">
          <strong>Order #<?= htmlspecialchars($orderNumber) ?></strong>
        </div>
        
        <div class="summary-row">
          <span>Subtotal</span>
          <span>PKR <?= number_format($order['subtotal']) ?></span>
        </div>
        <div class="summary-row">
          <span>Shipping</span>
          <span>PKR <?= number_format($order['shipping_fee']) ?></span>
        </div>
        <div class="summary-row total">
          <span>Total Amount</span>
          <span>PKR <?= number_format($orderTotal) ?></span>
        </div>
        
        <div class="payment-buttons">
          <?php if ($order['payment_method'] === 'cod'): ?>
            <form method="POST">
              <input type="hidden" name="order_id" value="<?= $orderId ?>">
              <button type="submit" name="confirm_cod" class="btn-cod">Confirm COD Order</button>
            </form>
          <?php else: ?>
            <button type="button" class="btn-pay" onclick="submitPayment()">Proceed to Payment</button>
          <?php endif; ?>
          <a href="checkout.php?order_id=<?= $orderId ?>" class="btn-back">← Back to Checkout</a>
        </div>
      </div>
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
      <div class="fc"><h4>Company</h4><a href="about.php">About Us</a><a href="contact.php">Contact</a><a href="commission.php">Custom Artwork</a></div>
    </div>
    <div class="fbot"><span>© <?= date('Y') ?> Art Bazaar. Supporting Pakistani artists.</span><span>Made with care in Pakistan 🇵🇰</span></div>
  </div>
</footer>

<script>
let selectedMethod = '<?= $paymentMethod ?>';

function selectMethod(method) {
  selectedMethod = method;
  
  // Update UI
  document.querySelectorAll('.method-option').forEach(opt => {
    opt.classList.remove('selected');
    const dot = opt.querySelector('.selected-dot');
    if (dot) dot.style.display = 'none';
  });
  
  const selectedOption = document.querySelector(`.method-option[onclick="selectMethod('${method}')"]`);
  if (selectedOption) {
    selectedOption.classList.add('selected');
    const dot = selectedOption.querySelector('.selected-dot');
    if (dot) dot.style.display = 'block';
  }
  
  // Update hidden radio
  const radio = document.querySelector(`input[name="payment_method"][value="${method}"]`);
  if (radio) radio.checked = true;
}

function submitPayment() {
  const form = document.getElementById('paymentForm');
  const confirmBtn = document.createElement('input');
  confirmBtn.type = 'hidden';
  confirmBtn.name = 'confirm_payment';
  confirmBtn.value = '1';
  form.appendChild(confirmBtn);
  form.submit();
}
</script>

</body>
</html>