<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
/* FIX 1: Allow scrolling so form doesn't hide top elements */
html,body{height:100%;overflow:auto;}
body{font-family:'DM Sans',sans-serif;background:#f5f5f5;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:20px 10px;}
/* FIX 2: Use min-height so container can grow */
.wrap{display:flex;width:780px;min-height:540px;border-radius:24px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.12),0 2px 8px rgba(0,0,0,0.06);border:none;background:#fff;margin:20px 0;}
.left{width:38%;background:#0C3F30;padding:36px 28px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;}
.left::after{content:'';position:absolute;top:-60px;left:-60px;width:220px;height:220px;border:1px solid #1e1e1e;border-radius:50%;}
.left-tag{font-size:11px;letter-spacing:3px;color:#555;text-transform:uppercase;font-weight:400;z-index:1;}
.left-content{z-index:1;}
.left-headline{font-family:'Playfair Display',serif;font-size:28px;color:#fff;line-height:1.25;font-weight:400;}
.left-sub{font-size:11px;color:#fff;line-height:1.7;margin-top:12px;}
.left-features{list-style:none;margin-top:16px;}
.left-features li{font-size:10px;color:#fff;padding:4px 0;border-bottom:1px solid #161616;letter-spacing:0.3px;}
.left-features li:last-child{border:none;}
.left-footer{font-size:10px;color:#fff;z-index:1;}
.right{flex:1;padding:32px 36px;display:flex;flex-direction:column;justify-content:flex-start;}
.right h2{font-family:'Playfair Display',serif;font-size:24px;font-weight:400;color:#0a0a0a;margin-bottom:3px;}
.right p.sub{font-size:11px;color:#999;margin-bottom:18px;}
label{display:block;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:#888;margin-bottom:4px;font-weight:500;}
input[type=text],input[type=email],input[type=password],input[type=tel]{width:100%;border:none;border-bottom:1.5px solid #e0e0e0;padding:6px 0;font-size:13px;font-family:'DM Sans',sans-serif;color:#0a0a0a;outline:none;background:transparent;transition:border-color .2s;}
input:focus{border-bottom-color:#0a0a0a;}
.field{margin-bottom:12px;}
.role-row{display:flex;gap:10px;margin-bottom:16px;}
.role-opt{flex:1;border:1.5px solid #e0e0e0;padding:10px 8px;cursor:pointer;text-align:center;transition:all .2s;position:relative;border-radius:10px;}
.role-opt input{display:none;}
.role-opt span{font-size:11px;color:#888;display:block;margin-top:2px;font-weight:400;}
.role-opt strong{font-size:12px;color:#0a0a0a;display:block;}
.role-opt.selected{border-color:#0a0a0a;background:#fafafa;}
.role-opt.selected strong{color:#0a0a0a;}
.role-opt.selected span{color:#666;}
button[type=submit]{width:100%;background:#0C3F30;color:#fff;border:none;padding:11px;font-size:12px;font-family:'DM Sans',sans-serif;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;margin-top:2px;transition:background .2s;border-radius:10px;}
button[type=submit]:hover{background:#0a5240;}
.bottom-link{text-align:center;margin-top:12px;font-size:11px;color:#999;}
.bottom-link a{color:#0a0a0a;font-weight:500;text-decoration:none;}
.msg{padding:8px 12px;font-size:11px;margin-bottom:14px;border-left:3px solid;border-radius:0 8px 8px 0;line-height:1.4;}
.msg.error{background:#fff5f5;border-color:#e24b4a;color:#8b1a1a;}
.msg.success{background:#f5fff8;border-color:#2a9d5c;color:#1a6e3c;}

/* new styles */
.artist-extra{display:none;margin-bottom:0;}
.artist-extra.show{display:block;}
.section-label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#0C3F30;font-weight:600;margin:10px 0 8px;padding-top:8px;border-top:1px solid #f0f0f0;}
.payment-toggle-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
.pay-toggle{display:flex;align-items:center;gap:5px;font-size:10px;color:#666;cursor:pointer;padding:5px 10px;border:1.5px solid #e0e0e0;border-radius:20px;transition:all .2s;user-select:none;}
.pay-toggle input{display:none;}
.pay-toggle.active{border-color:#0C3F30;background:#f0f8f5;color:#0C3F30;font-weight:500;}
.pay-fields{display:none;background:#fafafa;border-radius:8px;padding:10px 12px;margin-bottom:8px;}
.pay-fields.show{display:block;}
.pay-fields .field{margin-bottom:8px;}
.pay-fields .field:last-child{margin-bottom:0;}
.two-col{display:flex;gap:12px;}
.two-col .field{flex:1;}

.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:#fff;text-decoration:none;font-weight:500;letter-spacing:2px;display:inline-block;}
.logo-text span{color:#8fbc8f;}

@media(max-width:700px){
  body{padding:0;background:#fff;align-items:flex-start;}
  .wrap{flex-direction:column;width:100%;max-width:400px;min-height:auto;border-radius:0;box-shadow:none;margin:0;}
  .left{width:100%;padding:24px 22px;min-height:auto;}
  .left-headline{font-size:22px;margin-top:10px;}
  .left-sub{display:none;}
  .left-features{display:none;}
  .left-footer{font-size:9px;}
  .right{padding:24px 22px;}
  .right h2{font-size:20px;}
  .field{margin-bottom:14px;}
  .role-row{margin-bottom:16px;}
  input{padding:7px 0;font-size:13px;}
  .two-col{flex-direction:column;gap:0;}
  .payment-toggle-row{gap:6px;}
  .pay-toggle{font-size:9px;padding:4px 8px;}
}
</style>
</head>
<body>
<?php
require_once __DIR__ . '/config/db.php';

 $error = '';
 $success = '';
 $formData = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $role     = $_POST['role'] ?? '';

    // new fields
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $hasBankAccount = isset($_POST['has_bank_account']) ? 1 : 0;
$hasEasypaisa   = isset($_POST['has_easypaisa'])    ? 1 : 0;
$hasJazzcash    = isset($_POST['has_jazzcash'])      ? 1 : 0;
$hasNayapay     = isset($_POST['has_nayapay'])       ? 1 : 0;
$hasSadapay     = isset($_POST['has_sadapay'])       ? 1 : 0;

    if (!$name || !$email || !$password || !$confirm || !$role) {
        $error = 'All fields are required.';
    } elseif ($role === 'artist' && (!$city || !$address)) {
        $error = 'City and address are required for artists.';
    } elseif ($role === 'artist' && !$hasBankAccount && !$hasEasypaisa && !$hasJazzcash && !$hasNayapay && !$hasSadapay) {
        $error = 'Please add at least one payment method (bank account or mobile wallet).';
    } elseif (!in_array($role, ['artist', 'buyer'])) {
        $error = 'Please select a valid account type.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->bind_param('s', $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'An account with this email already exists.';
        } else {
            $hash   = password_hash($password, PASSWORD_BCRYPT);
            $status = $role === 'artist' ? 'pending' : 'active';
            $stmt   = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssss', $name, $email, $phone, $hash, $role, $status);

            if ($stmt->execute()) {
                if ($role === 'artist') {
                    $userId = $conn->insert_id;

                    $bankName    = $hasBankAccount ? trim($_POST['bank_name'] ?? '')           : null;
                    $bankTitle   = $hasBankAccount ? trim($_POST['bank_account_title'] ?? '')  : null;
                    $bankNumber  = $hasBankAccount ? trim($_POST['bank_account_number'] ?? '') : null;

                    $epName   = $hasEasypaisa ? trim($_POST['easypaisa_name'] ?? '')   : null;
                    $epNum    = $hasEasypaisa ? trim($_POST['easypaisa_number'] ?? '')  : null;
                    $jcName   = $hasJazzcash  ? trim($_POST['jazzcash_name'] ?? '')    : null;
                    $jcNum    = $hasJazzcash  ? trim($_POST['jazzcash_number'] ?? '')   : null;
                    $npName   = $hasNayapay   ? trim($_POST['nayapay_name'] ?? '')     : null;
                    $npNum    = $hasNayapay   ? trim($_POST['nayapay_number'] ?? '')    : null;
                    $spName   = $hasSadapay   ? trim($_POST['sadapay_name'] ?? '')     : null;
                    $spNum    = $hasSadapay   ? trim($_POST['sadapay_number'] ?? '')    : null;

                    // FIX 3: Corrected bind_param types and variables count (19 total)
                    $profile = $conn->prepare("INSERT INTO artist_profiles 
                        (user_id, city, address,
                         has_bank_account, bank_name, bank_account_title, bank_account_number,
                         has_easypaisa, easypaisa_name, easypaisa_number,
                         has_jazzcash, jazzcash_name, jazzcash_number,
                         has_nayapay, nayapay_name, nayapay_number,
                         has_sadapay, sadapay_name, sadapay_number)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    
                    $profile->bind_param('issississississssss',
                        $userId, $city, $address,
                        $hasBankAccount, $bankName, $bankTitle, $bankNumber,
                        $hasEasypaisa, $epName, $epNum,
                        $hasJazzcash,  $jcName, $jcNum,
                        $hasNayapay,   $npName, $npNum,
                        $hasSadapay,   $spName, $spNum
                    );
                    $profile->execute();
                    $success = 'Artist account created! Please wait for admin approval before logging in.';
                } else {
                    $success = 'Account created successfully! You can now log in.';
                }
                $formData = [];
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>
<div class="wrap">
    <div class="left">
        <a href="index.php" class="logo-text">Art <span>Bazaar</span></a>
        <div class="left-content">
            <p class="left-headline">Join the<br>community.</p>
            <p class="left-sub">Whether you create or collect — there's a place for you here.</p>
            <ul class="left-features">
                <li>→ Artists: showcase and sell your artwork</li>
                <li>→ Buyers: browse and request custom artwork</li>
                <li>→ Secure platform-managed requests</li>
                <li>→ Supporting Pakistani artists</li>
            </ul>
        </div>
        <p class="left-footer">Art Bazaar — Pakistan's art marketplace.</p>
    </div>
    <div class="right">
        <h2>Create Account</h2>
        <p class="sub">Choose your account type to get started.</p>

        <?php if ($error): ?>
            <div class="msg error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="msg success"><?= htmlspecialchars($success) ?></div>
            <p class="bottom-link"><a href="login.php">Go to Login →</a></p>
        <?php else: ?>

        <form method="POST" id="regForm">
            <div class="role-row">
                <label class="role-opt <?= ($formData['role'] ?? '') === 'artist' ? 'selected' : '' ?>" onclick="selectRole('artist',this)">
                    <input type="radio" name="role" value="artist" <?= ($formData['role'] ?? '') === 'artist' ? 'checked' : '' ?>>
                    <strong>Artist</strong>
                    <span>Sell your artwork</span>
                </label>
                <label class="role-opt <?= ($formData['role'] ?? '') === 'buyer' ? 'selected' : '' ?>" onclick="selectRole('buyer',this)">
                    <input type="radio" name="role" value="buyer" <?= ($formData['role'] ?? '') === 'buyer' ? 'checked' : '' ?>>
                    <strong>Buyer</strong>
                    <span>Request custom artwork</span>
                </label>
            </div>

            <div class="field">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="Your full name" value="<?= htmlspecialchars($formData['name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($formData['email'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Phone / WhatsApp <span style="color:#ccc;font-size:9px;letter-spacing:0">(optional)</span></label>
                <input type="tel" name="phone" placeholder="+92 300 0000000" value="<?= htmlspecialchars($formData['phone'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" placeholder="Min. 8 characters" required>
            </div>
            <div class="field">
                <label>Confirm Password</label>
                <input type="password" name="confirm" placeholder="Repeat password" required>
            </div>

            <div id="artist-extra" class="artist-extra">
                <div class="section-label">📍 Location</div>
                <div class="two-col">
                    <div class="field">
                        <label>City</label>
                        <input type="text" name="city" placeholder="e.g. Lahore" value="<?= htmlspecialchars($formData['city'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>Address</label>
                        <input type="text" name="address" placeholder="Street / Area" value="<?= htmlspecialchars($formData['address'] ?? '') ?>">
                    </div>
                </div>

                <div class="section-label">💳 Payment Methods <span style="font-size:9px;color:#999;text-transform:none;letter-spacing:0;font-weight:400">(at least one required)</span></div>
                <div class="payment-toggle-row">
                    <label class="pay-toggle <?= isset($formData['has_bank_account']) ? 'active' : '' ?>" onclick="togglePay('bank',this)">
                        <input type="checkbox" name="has_bank_account" <?= isset($formData['has_bank_account']) ? 'checked' : '' ?>> 🏦 Bank Account
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_easypaisa']) ? 'active' : '' ?>" onclick="togglePay('easypaisa',this)">
                        <input type="checkbox" name="has_easypaisa" <?= isset($formData['has_easypaisa']) ? 'checked' : '' ?>> Easypaisa
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_jazzcash']) ? 'active' : '' ?>" onclick="togglePay('jazzcash',this)">
                        <input type="checkbox" name="has_jazzcash" <?= isset($formData['has_jazzcash']) ? 'checked' : '' ?>> JazzCash
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_nayapay']) ? 'active' : '' ?>" onclick="togglePay('nayapay',this)">
                        <input type="checkbox" name="has_nayapay" <?= isset($formData['has_nayapay']) ? 'checked' : '' ?>> NayaPay
                    </label>
                    <label class="pay-toggle <?= isset($formData['has_sadapay']) ? 'active' : '' ?>" onclick="togglePay('sadapay',this)">
                        <input type="checkbox" name="has_sadapay" <?= isset($formData['has_sadapay']) ? 'checked' : '' ?>> SadaPay
                    </label>
                </div>

                <div id="pay-bank" class="pay-fields <?= isset($formData['has_bank_account']) ? 'show' : '' ?>">
                    <div class="field"><label>Bank Name</label><input type="text" name="bank_name" placeholder="e.g. HBL, Meezan" value="<?= htmlspecialchars($formData['bank_name'] ?? '') ?>"></div>
                    <div class="two-col">
                        <div class="field"><label>Account Title</label><input type="text" name="bank_account_title" placeholder="Name on account" value="<?= htmlspecialchars($formData['bank_account_title'] ?? '') ?>"></div>
                        <div class="field"><label>Account Number</label><input type="text" name="bank_account_number" placeholder="Account / IBAN" value="<?= htmlspecialchars($formData['bank_account_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-easypaisa" class="pay-fields <?= isset($formData['has_easypaisa']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="easypaisa_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['easypaisa_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="easypaisa_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['easypaisa_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-jazzcash" class="pay-fields <?= isset($formData['has_jazzcash']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="jazzcash_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['jazzcash_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="jazzcash_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['jazzcash_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-nayapay" class="pay-fields <?= isset($formData['has_nayapay']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="nayapay_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['nayapay_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="nayapay_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['nayapay_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="pay-sadapay" class="pay-fields <?= isset($formData['has_sadapay']) ? 'show' : '' ?>">
                    <div class="two-col">
                        <div class="field"><label>Account Name</label><input type="text" name="sadapay_name" placeholder="Registered name" value="<?= htmlspecialchars($formData['sadapay_name'] ?? '') ?>"></div>
                        <div class="field"><label>Mobile Number</label><input type="tel" name="sadapay_number" placeholder="03XX XXXXXXX" value="<?= htmlspecialchars($formData['sadapay_number'] ?? '') ?>"></div>
                    </div>
                </div>

                <div id="artist-note" style="font-size:10px;color:#0C3F30;background:#f0f8f5;border:1px solid #c8e6d8;border-radius:6px;padding:7px 10px;margin-bottom:10px;line-height:1.5;">🎨 Artist profiles are reviewed before appearing publicly.</div>
            </div>

            <div style="display:flex;align-items:flex-start;gap:8px;margin:10px 0 8px;">
              <input type="checkbox" name="terms" id="terms" required style="margin-top:2px;width:14px;height:14px;accent-color:#0C3F30;">
              <label for="terms" style="font-size:10px;color:#888;text-transform:none;letter-spacing:0;font-weight:400;line-height:1.5;">I agree to the <a href="terms.php" style="color:#0C3F30;">Terms & Conditions</a> and <a href="privacy.php" style="color:#0C3F30;">Privacy Policy</a></label>
            </div>
            <button type="submit">Create Account</button>
        </form>

        <p class="bottom-link">Already have an account? <a href="login.php">Sign in</a></p>
        <?php endif; ?>
    </div>
</div>
<script>
function selectRole(role, el) {
    document.querySelectorAll('.role-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    el.querySelector('input').checked = true;
    const extra = document.getElementById('artist-extra');
    if (role === 'artist') {
        extra.classList.add('show');
    } else {
        extra.classList.remove('show');
    }
}

function togglePay(key, el) {
    el.classList.toggle('active');
    const cb = el.querySelector('input[type=checkbox]');
    cb.checked = !cb.checked;
    document.getElementById('pay-' + key).classList.toggle('show', cb.checked);
}

// Restore artist section on page reload with errors
(function(){
    const role = document.querySelector('input[name=role]:checked');
    if (role && role.value === 'artist') {
        document.getElementById('artist-extra').classList.add('show');
    }
})();
</script>
</body>
</html>