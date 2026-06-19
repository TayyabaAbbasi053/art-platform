<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['user_id'])) {
    $redirect = $_GET['redirect'] ?? '';
    if (!empty($redirect) && strpos($redirect, '/') === 0 &&
        strpos($redirect, 'http://') === false &&
        strpos($redirect, 'https://') === false) {
        header('Location: ' . $redirect);
    } else {
        $role = $_SESSION['role'];
        if ($role === 'admin') header('Location: dashboard/admin/index.php');
        elseif ($role === 'artist') header('Location: dashboard/artist/index.php');
        else header('Location: index.php');
    }
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/autoload.php';

function sendOtpEmail(string $toEmail, string $toName, string $otp): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'teamartbazaar.pk@gmail.com';
        // ╔═══════════════════════════════════════════════╗
        // ║  PASTE YOUR GMAIL APP PASSWORD HERE          ║
        // ║  Go to Google → Security → App Passwords     ║
        // ╚═══════════════════════════════════════════════╝
        $mail->Password   = 'REMOVED';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addReplyTo('teamartbazaar.pk@gmail.com', 'Art Bazaar');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Art Bazaar Verification Code';
        $mail->AltBody = "Your Art Bazaar verification code is: $otp. This code expires in 10 minutes.";
        $mail->Body    = "
        <div style='font-family:sans-serif;max-width:420px;margin:auto;padding:32px;background:#fff;border-radius:12px'>
            <p style='font-size:13px;color:#555;margin:0 0 8px'>Art Bazaar</p>
            <h2 style='font-size:28px;font-weight:400;color:#0a0a0a;margin:0 0 20px;font-family:Georgia,serif'>Password Reset</h2>
            <p style='font-size:14px;color:#444;line-height:1.6;margin:0 0 24px'>Hello {$toName}, we received a request to reset your Art Bazaar password. Use the verification code below. It expires in <strong>10 minutes</strong>.</p>
            <div style='background:#0a0a0a;color:#fff;font-size:32px;letter-spacing:10px;text-align:center;padding:20px;border-radius:10px;font-weight:500'>{$otp}</div>
            <p style='font-size:12px;color:#aaa;margin:24px 0 0'>If you didn't request this, ignore this email.</p>
        </div>";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Capture redirect parameter from GET or POST
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';

 $step  = 'login';
 $error = '';
 $info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, password_hash, role, status, status_reason FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) {
            $error = 'No account found with that email.';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Wrong password. Try again.';
        } elseif ($user['status'] === 'blocked') {
            $error = 'Your account has been suspended.' . (!empty($user['status_reason']) ? ' Reason: ' . $user['status_reason'] : ' Please contact support.');
        } elseif ($user['status'] === 'pending') {
            $error = 'Your account is pending approval.';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['role']    = $user['role'];
            
            // Check for valid redirect URL
            if (!empty($redirect)) {
                // Security: Only allow internal redirects (must start with /)
                if (strpos($redirect, '/') === 0 && 
                    strpos($redirect, 'http://') === false && 
                    strpos($redirect, 'https://') === false) {
                    header('Location: ' . $redirect);
                    exit;
                }
            }
            
            // Fallback to role-based redirect
            if ($user['role'] === 'admin') header('Location: dashboard/admin/index.php');
            elseif ($user['role'] === 'artist') header('Location: dashboard/artist/index.php');
            else header('Location: index.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot_email') {
    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
        $step  = 'forgot_email';
    } else {
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) {
            $error = 'No account found with that email.';
            $step  = 'forgot_email';
        } else {
            $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', time() + 600);
            $_SESSION['fp_email']   = $email;
            $_SESSION['fp_name']    = $user['name'];
            $_SESSION['fp_user_id'] = $user['id'];
            $_SESSION['fp_otp']     = $otp;
            $_SESSION['fp_expires'] = $expires;
            $_SESSION['fp_step']    = 'forgot_otp';
            $step = 'forgot_otp';
            if (!sendOtpEmail($email, $user['name'], $otp)) {
                $error = 'Could not send email. Check mail config.';
                $step  = 'forgot_email';
                unset($_SESSION['fp_step']);
            } else {
                $info = 'A 6-digit code was sent to ' . $email . '. Check your spam/junk folder if you don\'t see it in inbox.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot_otp') {
    $entered = trim(implode('', $_POST['otp'] ?? []));
    $step    = 'forgot_otp';
    if (strlen($entered) !== 6) {
        $error = 'Enter the complete 6-digit code.';
    } elseif (!isset($_SESSION['fp_otp'])) {
        $error = 'Session expired. Start again.';
        $step  = 'forgot_email';
    } elseif (strtotime($_SESSION['fp_expires']) < time()) {
        $error = 'Code expired. Request a new one.';
        $step  = 'forgot_email';
        unset($_SESSION['fp_otp'], $_SESSION['fp_expires']);
    } elseif ($entered !== $_SESSION['fp_otp']) {
        $error = 'Incorrect code. Try again.';
    } else {
        $_SESSION['fp_step'] = 'forgot_reset';
        $step = 'forgot_reset';
        unset($_SESSION['fp_otp']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot_reset') {
    $pw1  = $_POST['password'] ?? '';
    $pw2  = $_POST['confirm'] ?? '';
    $step = 'forgot_reset';
    if (strlen($pw1) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match.';
    } elseif (!isset($_SESSION['fp_user_id'])) {
        $error = 'Session expired. Start again.';
        $step  = 'login';
    } else {
        $hash = password_hash($pw1, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $hash, $_SESSION['fp_user_id']);
        $stmt->execute();
        foreach (array_keys($_SESSION) as $k) {
            if (str_starts_with($k, 'fp_')) unset($_SESSION[$k]);
        }
        $step = 'login';
        $info = 'Password updated! You can now sign in.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['back'])) {
    foreach (array_keys($_SESSION) as $k) {
        if (str_starts_with($k, 'fp_')) unset($_SESSION[$k]);
    }
    $step = 'login';
}

if ($step === 'login' && isset($_SESSION['fp_step'])) {
    $step = $_SESSION['fp_step'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Art Bazaar</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
html,body{height:100%;overflow:hidden;}
body{font-family:'DM Sans',sans-serif;background:#f5f5f5;min-height:100vh;display:flex;align-items:center;justify-content:center;}
.wrap{display:flex;width:760px;height:480px;border-radius:24px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.12),0 2px 8px rgba(0,0,0,0.06);background:#fff;}
.left{width:40%;background:#0C3F30;padding:36px 28px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;}
.left::after{content:'';position:absolute;bottom:-60px;right:-60px;width:220px;height:220px;border:1px solid #1e1e1e;border-radius:50%;}
.left::before{content:'';position:absolute;bottom:-30px;right:-30px;width:140px;height:140px;border:1px solid #1e1e1e;border-radius:50%;}
.left-tag{font-size:11px;letter-spacing:3px;color:#555;text-transform:uppercase;font-weight:400;z-index:1;}
.left-content{z-index:1;}
.left-headline{font-family:'Playfair Display',serif;font-size:32px;color:#fff;line-height:1.2;font-weight:400;}
.left-sub{font-size:12px;color:#fff;line-height:1.7;margin-top:14px;}
.left-footer{font-size:11px;color:#fff;z-index:1;}
.right{flex:1;padding:36px 40px;display:flex;flex-direction:column;justify-content:center;overflow:hidden;}
.right h2{font-family:'Playfair Display',serif;font-size:26px;font-weight:400;color:#0a0a0a;margin-bottom:4px;}
.right p.sub{font-size:12px;color:#999;margin-bottom:24px;}
label{display:block;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#888;margin-bottom:6px;font-weight:500;}
.input-wrap{position:relative;}
input[type=email],input[type=password],input[type=text]{width:100%;border:none;border-bottom:1.5px solid #e0e0e0;padding:8px 0;font-size:14px;font-family:'DM Sans',sans-serif;color:#0a0a0a;outline:none;background:transparent;transition:border-color .2s;}
input:focus{border-bottom-color:#0C3F30;}
.toggle-pw{position:absolute;right:0;top:8px;cursor:pointer;font-size:11px;color:#aaa;letter-spacing:0.5px;text-transform:uppercase;background:none;border:none;font-family:'DM Sans',sans-serif;}
.field{margin-bottom:20px;}
button[type=submit]{width:100%;background:#0C3F30;color:#fff;border:none;padding:13px;font-size:13px;font-family:'DM Sans',sans-serif;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;margin-top:4px;transition:background .2s;border-radius:10px;}
button[type=submit]:hover{background:#0a5240;}
.bottom-link{text-align:center;margin-top:18px;font-size:12px;color:#999;}
.bottom-link a{color:#0a0a0a;font-weight:500;text-decoration:none;}
.msg{padding:10px 14px;font-size:12px;margin-bottom:16px;border-left:3px solid;border-radius:0 8px 8px 0;line-height:1.4;}
.msg.error{background:#fff5f5;border-color:#e24b4a;color:#8b1a1a;}
.msg.info{background:#f5f9ff;border-color:#378ADD;color:#0c447c;}
.forgot-link{font-size:11px;color:#aaa;cursor:pointer;text-align:right;margin-top:-12px;margin-bottom:20px;display:block;background:none;border:none;font-family:'DM Sans',sans-serif;width:100%;text-decoration:none;}
.forgot-link:hover{color:#0a0a0a;}
.otp-row{display:flex;gap:10px;justify-content:center;margin-bottom:22px;}
.otp-row input{width:44px;height:50px;border:1.5px solid #e0e0e0;border-radius:10px;text-align:center;font-size:22px;font-family:'DM Sans',sans-serif;color:#0a0a0a;outline:none;transition:border-color .2s;background:#fff;padding:0;-moz-appearance:textfield;}
.otp-row input::-webkit-outer-spin-button,.otp-row input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.otp-row input:focus{border-color:#0a0a0a;}
.back-btn{background:none;border:none;font-size:11px;color:#aaa;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:0.5px;padding:0;margin-bottom:16px;display:flex;align-items:center;gap:4px;text-decoration:none;}
.back-btn:hover{color:#0a0a0a;}
.resend-link{font-size:11px;color:#aaa;text-align:center;display:block;margin-top:12px;cursor:pointer;background:none;border:none;font-family:'DM Sans',sans-serif;}
.resend-link:hover{color:#0a0a0a;}
@media(max-width:700px){
  html,body{overflow:auto;height:auto;}
  body{align-items:flex-start;background:#fff;padding:0;}
  .wrap{flex-direction:column;width:100%;height:auto;min-height:100vh;border-radius:0;box-shadow:none;}
  .left{width:100%;padding:28px 22px;min-height:auto;}
  .left-headline{font-size:24px;}
  .right{padding:28px 22px;}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="left">
    <a href="index.php"><img src="logo.png" alt="Art Bazaar" style="height:60px;width:auto;display:block;"></a>
    <div class="left-content">
      <?php if ($step === 'login'): ?>
        <p class="left-headline">Welcome<br>back.</p>
        <p class="left-sub">Pakistan's home for original art — connecting artists and collectors.</p>
      <?php elseif ($step === 'forgot_email'): ?>
        <p class="left-headline">Forgot<br>password?</p>
        <p class="left-sub">Enter your email and we'll send you a reset code.</p>
      <?php elseif ($step === 'forgot_otp'): ?>
        <p class="left-headline">Check<br>your email.</p>
        <p class="left-sub">We sent a 6-digit code to your inbox. It expires in 10 minutes.</p>
      <?php else: ?>
        <p class="left-headline">New<br>password.</p>
        <p class="left-sub">Choose a strong password to secure your account.</p>
      <?php endif; ?>
    </div>
    <p class="left-footer">Art marketplace for Pakistani artists.</p>
  </div>

  <div class="right">

    <?php if ($step !== 'login'): ?>
      <a href="?back=1" class="back-btn">← Back to sign in</a>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="msg error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
      <div class="msg info"><?= htmlspecialchars($info) ?></div>
    <?php endif; ?>

    <?php if ($step === 'login'): ?>
    <h2>Sign In</h2>
    <p class="sub">Enter your details to continue.</p>
    <form method="POST">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label>Password</label>
        <div class="input-wrap">
          <input type="password" name="password" id="pw" placeholder="Your password" required>
          <button type="button" class="toggle-pw" onclick="let p=document.getElementById('pw');p.type=p.type==='password'?'text':'password';this.textContent=p.type==='password'?'Show':'Hide'">Show</button>
        </div>
      </div>
      <button type="button" class="forgot-link" onclick="goForgot()">Forgot password?</button>
      <button type="submit">Sign In</button>
    </form>
    <p class="bottom-link">Don't have an account? <a href="register.php">Register</a></p>

    <?php elseif ($step === 'forgot_email'): ?>
    <h2>Reset Password</h2>
    <p class="sub">We'll send a 6-digit code to your email.</p>
    <form method="POST">
      <input type="hidden" name="action" value="forgot_email">
      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@example.com" required autofocus>
      </div>
      <button type="submit">Send Code</button>
    </form>

    <?php elseif ($step === 'forgot_otp'): ?>
    <h2>Enter Code</h2>
    <p class="sub">6-digit code sent to <?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?> — check your spam/junk if not in inbox.</p>
    <form method="POST" id="otpForm">
      <input type="hidden" name="action" value="forgot_otp">
      <div class="otp-row">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <input type="text" name="otp[]" maxlength="1" pattern="[0-9]" id="otp<?= $i ?>" autocomplete="off">
        <?php endfor; ?>
      </div>
      <button type="submit">Verify Code</button>
    </form>
    <form method="POST" style="text-align:center">
      <input type="hidden" name="action" value="forgot_email">
      <input type="hidden" name="email" value="<?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?>">
      <button type="submit" class="resend-link">Didn't receive it? Resend code</button>
    </form>

    <?php elseif ($step === 'forgot_reset'): ?>
    <h2>New Password</h2>
    <p class="sub">Choose a strong password for your account.</p>
    <form method="POST">
      <input type="hidden" name="action" value="forgot_reset">
      <div class="field">
        <label>New Password</label>
        <div class="input-wrap">
          <input type="password" name="password" id="pw1" placeholder="Min. 8 characters" required autofocus>
          <button type="button" class="toggle-pw" onclick="let p=document.getElementById('pw1');p.type=p.type==='password'?'text':'password';this.textContent=p.type==='password'?'Show':'Hide'">Show</button>
        </div>
      </div>
      <div class="field">
        <label>Confirm Password</label>
        <input type="password" name="confirm" placeholder="Repeat password" required>
      </div>
      <button type="submit">Update Password</button>
    </form>
    <?php endif; ?>

  </div>
</div>

<script>
function goForgot() {
    var f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = '<input type="hidden" name="action" value="forgot_email">';
    document.body.appendChild(f);
    f.submit();
}

document.querySelectorAll('.otp-row input').forEach((inp, i, all) => {
    inp.addEventListener('input', e => {
        inp.value = inp.value.replace(/[^0-9]/g, '').slice(-1);
        if (inp.value && i < all.length - 1) all[i + 1].focus();
    });
    inp.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !inp.value && i > 0) all[i - 1].focus();
    });
    inp.addEventListener('paste', e => {
        e.preventDefault();
        const digits = (e.clipboardData.getData('text').replace(/\D/g, '')).slice(0, 6);
        digits.split('').forEach((d, j) => { if (all[i + j]) all[i + j].value = d; });
        const next = Math.min(i + digits.length, all.length - 1);
        all[next].focus();
    });
});
</script>
</body>
</html>