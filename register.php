<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;overflow:hidden;}
body{font-family:'DM Sans',sans-serif;background:#f5f5f5;min-height:100vh;display:flex;align-items:center;justify-content:center;}
.wrap{display:flex;width:780px;height:540px;border-radius:24px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.12),0 2px 8px rgba(0,0,0,0.06);border:none;background:#fff;}
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
.right{flex:1;padding:32px 36px;display:flex;flex-direction:column;justify-content:center;}
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
@media(max-width:700px){
  html,body{overflow:auto;height:auto;}
  body{padding:0;background:#fff;}
  .wrap{flex-direction:column;width:100%;max-width:400px;height:auto;border-radius:0;box-shadow:none;}
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

    if (!$name || !$email || !$password || !$confirm || !$role) {
        $error = 'All fields are required.';
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
                    $profile = $conn->prepare("INSERT INTO artist_profiles (user_id) VALUES (?)");
                    $profile->bind_param('i', $userId);
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
        <a href="index.php"><img src="logo.png" alt="Art Bazaar" style="height:60px;width:auto;display:block;"></a>
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
            <div id="artist-note" style="display:none;font-size:10px;color:#0C3F30;background:#f0f8f5;border:1px solid #c8e6d8;border-radius:6px;padding:7px 10px;margin-bottom:10px;line-height:1.5;">🎨 Artist profiles are reviewed before appearing publicly.</div>
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
    document.getElementById('artist-note').style.display = role === 'artist' ? 'block' : 'none';
}
</script>
</body>
</html>