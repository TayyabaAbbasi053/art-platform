<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Setup — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;}
body{font-family:'DM Sans',sans-serif;background:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.wrap{display:flex;width:780px;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.12),0 2px 8px rgba(0,0,0,0.06);border:none;}
.left{width:40%;background:#0a0a0a;padding:40px 32px;display:flex;flex-direction:column;justify-content:space-between;}
.left-tag{font-size:11px;letter-spacing:3px;color:#555;text-transform:uppercase;font-weight:400;}
.left-headline{font-family:'Playfair Display',serif;font-size:32px;color:#fff;line-height:1.25;font-weight:400;margin-top:auto;}
.left-sub{font-size:12px;color:#555;line-height:1.7;margin-top:14px;}
.left-warning{font-size:10px;color:#888;border-top:1px solid #222;padding-top:16px;margin-top:auto;letter-spacing:0.3px;line-height:1.6;}
.right{flex:1;padding:36px 40px;display:flex;flex-direction:column;justify-content:center;}
.right h2{font-family:'Playfair Display',serif;font-size:26px;font-weight:400;color:#0a0a0a;margin-bottom:4px;}
.right p{font-size:12px;color:#999;margin-bottom:24px;}
label{display:block;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#888;margin-bottom:6px;font-weight:500;}
input{width:100%;border:none;border-bottom:1.5px solid #e0e0e0;padding:8px 0;font-size:14px;font-family:'DM Sans',sans-serif;color:#0a0a0a;outline:none;background:transparent;transition:border-color .2s;}
input:focus{border-bottom-color:#0a0a0a;}
.field{margin-bottom:20px;}
button{width:100%;background:#0a0a0a;color:#fff;border:none;padding:13px;font-size:13px;font-family:'DM Sans',sans-serif;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;margin-top:4px;transition:background .2s;border-radius:8px;}
button:hover{background:#333;}
.msg{padding:10px 14px;font-size:12px;margin-bottom:18px;border-left:3px solid;border-radius:0 8px 8px 0;line-height:1.5;}
.msg.success{background:#f5fff8;border-color:#2a9d5c;color:#1a6e3c;}
.msg.error{background:#fff5f5;border-color:#e24b4a;color:#8b1a1a;}
.right a{color:#0a0a0a;font-weight:500;text-decoration:none;font-size:13px;}
@media(max-width:700px){
  body{padding:16px;}
  .wrap{flex-direction:column;width:100%;max-width:400px;}
  .left{width:100%;padding:24px 22px;min-height:auto;}
  .left-headline{font-size:22px;margin-top:10px;}
  .left-sub{display:none;}
  .left-warning{font-size:9px;padding-top:12px;margin-top:12px;}
  .right{padding:24px 22px;}
  .right h2{font-size:22px;}
  .field{margin-bottom:16px;}
  input{padding:7px 0;font-size:13px;}
}
</style>
</head>
<body>
<?php
require_once __DIR__ . '/config/db.php';

 $message = '';
 $messageType = '';

 $check = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
 $adminExists = $check && $check->num_rows > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminExists) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$name || !$email || !$password || !$confirm) {
        $message = 'All fields are required.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'error';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
        $messageType = 'error';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
        $messageType = 'error';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'admin', 'active')");
        $stmt->bind_param('sss', $name, $email, $hash);
        if ($stmt->execute()) {
            $message = 'Admin account created successfully. Delete this file now!';
            $messageType = 'success';
            $adminExists = true;
        } else {
            $message = 'Error: ' . $conn->error;
            $messageType = 'error';
        }
    }
}
?>
<div class="wrap">
    <div class="left">
        <span class="left-tag">Art Bazaar</span>
        <div>
            <p class="left-headline">One-time<br>admin<br>setup.</p>
            <p class="left-sub">Create the master admin account. This page should be deleted immediately after use.</p>
        </div>
        <p class="left-warning">⚠ Delete admin-setup.php after creating the account. Never leave this file on a live server.</p>
    </div>
    <div class="right">
        <h2>Create Admin</h2>
        <p>Fill in the details below to set up the admin account.</p>

        <?php if ($message): ?>
            <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($adminExists && $messageType !== 'success'): ?>
            <div class="msg error">An admin account already exists. This setup page is no longer needed — delete it.</div>
        <?php elseif (!$adminExists || $messageType === 'error'): ?>
        <form method="POST">
            <div class="field">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="Admin Name" required>
            </div>
            <div class="field">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="admin@artbazaar.com" required>
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" placeholder="Min. 8 characters" required>
            </div>
            <div class="field">
                <label>Confirm Password</label>
                <input type="password" name="confirm" placeholder="Repeat password" required>
            </div>
            <button type="submit">Create Admin Account</button>
        </form>
        <?php endif; ?>

        <?php if ($messageType === 'success'): ?>
            <p style="margin-top:18px;font-size:13px;color:#999;">
                <a href="login.php">Go to Login →</a>
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>