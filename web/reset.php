<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$match = $token !== '' ? validateResetToken($pdo, $token) : null;

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $match) {
    $password        = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters.';
    if ($password !== $passwordConfirm) $errors['password_confirm'] = 'Passwords do not match.';

    if (!$errors) {
        if (consumeResetToken($pdo, $token)) {
            setAccountPassword($pdo, $match['role'], $match['id'], $password);
            $done = true;
        } else {
            $match = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset password — UVMS</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="auth-shell">
  <div class="auth-card">

    <div class="auth-panel-dark">
      <div>
        <div class="seal">UVMS</div>
        <span class="dept-tag">Office of Student Affairs</span>
        <h1>UVMS</h1>
        <p>Violation Management System</p>
      </div>
    </div>

    <div class="auth-panel-light">

      <?php if (!$match && !$done): ?>
        <h2>Link expired or invalid</h2>
        <p class="auth-sub">This password reset link is no longer valid — it may have already been used, or more than 30 minutes have passed since it was requested.</p>
        <a href="index.php" class="btn-primary" style="display:block; text-align:center; text-decoration:none; box-sizing:border-box;">Back to log in</a>

      <?php elseif ($done): ?>
        <h2>Password updated successfully</h2>
        <p class="auth-sub">Your password has been changed. You can log in with it now.</p>
        <a href="index.php" class="btn-primary" style="display:block; text-align:center; text-decoration:none; box-sizing:border-box;">Back to log in</a>

      <?php else: ?>
        <h2>Create a new password</h2>
        <p class="auth-sub">Choose a new password for your account.</p>

        <form method="post">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="field">
            <label for="pwInput">New password</label>
            <input id="pwInput" name="password" type="password" placeholder="At least 6 characters"
                   style="border-color:<?= isset($errors['password']) ? 'var(--danger)' : 'var(--border)' ?>">
            <?php if (isset($errors['password'])): ?><small style="color:var(--danger);"><?= e($errors['password']) ?></small><?php endif; ?>
          </div>
          <div class="field">
            <label for="pwConfirmInput">Confirm new password</label>
            <input id="pwConfirmInput" name="password_confirm" type="password" placeholder="Re-enter your password"
                   style="border-color:<?= isset($errors['password_confirm']) ? 'var(--danger)' : 'var(--border)' ?>">
            <?php if (isset($errors['password_confirm'])): ?><small style="color:var(--danger);"><?= e($errors['password_confirm']) ?></small><?php endif; ?>
          </div>
          <button type="submit" class="btn-primary">Update password</button>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

</body>
</html>
