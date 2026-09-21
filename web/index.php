<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/mailer.php';
require __DIR__ . '/includes/google_sheets.php';

$destinations = ['student' => 'student.php', 'guard' => 'guard.php', 'osa' => 'osa.php'];

$loginError   = false;
$forgotError  = false;
$signupErrors = [];
$toast        = null;
$activeTab    = 'login';

// ---------- Handle login ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $id       = trim($_POST['id'] ?? '');
    $password = $_POST['password'] ?? '';
    $result   = authenticate($pdo, $id, $password);

    if (!$result) {
        $loginError = true;
    } else {
        $idField = $result['role'] === 'student' ? $result['account']['student_number']
            : ($result['role'] === 'guard' ? $result['account']['guard_id'] : $result['account']['osa_id']);
        setSession($result['role'], $idField);
        header('Location: ' . $destinations[$result['role']]);
        exit;
    }
}

// ---------- Handle "forgot password" ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot') {
    $email = trim($_POST['email'] ?? '');
    $match = findAccountByEmail($pdo, $email);
    if (!$match) {
        $forgotError = true;
    } else {
        $account   = $match['account'];
        $idField   = $match['role'] === 'student' ? $account['student_number'] : $account['osa_id'];
        $fullName  = $account['first_name'] . ' ' . $account['last_name'];
        $token     = createPasswordResetToken($pdo, $match['role'], $idField);
        $resetLink = rtrim(APP_BASE_URL, '/') . '/reset.php?token=' . urlencode($token);

        $sent = sendPasswordResetEmail($email, $fullName, $resetLink);
        $toast = $sent
            ? 'Reset link sent to ' . e($email) . '. Check the inbox (and spam folder).'
            : 'Found the account, but the email could not be sent. Ask an admin to check the Gmail setup in mail_config.php.';
    }
}

// ---------- Handle sign up ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'signup') {
    $activeTab      = 'signup';
    $studentNumber  = trim($_POST['student_number'] ?? '');
    $firstName      = trim($_POST['first_name'] ?? '');
    $lastName       = trim($_POST['last_name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $program        = trim($_POST['program'] ?? '');
    $yearLevel      = trim($_POST['year_level'] ?? '');
    $password       = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($studentNumber === '') $signupErrors['student_number'] = 'Student number is required.';
    if ($firstName === '') $signupErrors['first_name'] = 'First name is required.';
    if ($lastName === '') $signupErrors['last_name'] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $signupErrors['email'] = 'Enter a valid email address.';
    if ($program === '') $signupErrors['program'] = 'Program is required.';
    if ($yearLevel === '') $signupErrors['year_level'] = 'Year level is required.';
    if (strlen($password) < 6) $signupErrors['password'] = 'Password must be at least 6 characters.';
    if ($password !== $passwordConfirm) $signupErrors['password_confirm'] = 'Passwords do not match.';

    if (!$signupErrors && studentNumberClaimed($pdo, $studentNumber)) {
        $signupErrors['student_number'] = 'An account already exists for that student number. Log in instead.';
    }
    if (!$signupErrors && emailTaken($pdo, $email)) {
        $signupErrors['email'] = 'That email is already linked to an account.';
    }

    if (!$signupErrors) {
        $fullName = $firstName . ' ' . $lastName;
        $student = signUpStudent($pdo, $studentNumber, $firstName, $lastName, $email, $program, $yearLevel, $password);
        autoSyncStudentsToSheet($pdo);
        $sent = sendWelcomeEmail($email, $fullName, $studentNumber);
        $toast = $sent
            ? 'Account created successfully. Check your inbox for a welcome email — you can log in now.'
            : 'Account created successfully, but the welcome email could not be sent. Check mail_config.php. You can still log in now.';
        $activeTab = 'login';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in — Student Conduct System</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">

<div class="login-shell">

  <div class="login-visual">
    <img class="login-visual-bg" src="assets/img/login-bg-texture.png" alt="">
    <img class="login-visual-logo" src="assets/img/UVMS-logo-crop.png" alt="UVMS logo">
  </div>

  <div class="login-card-wrap">
    <div class="login-card">

      <div class="login-tabs">
        <button type="button" class="login-tab<?= $activeTab === 'login' ? ' active' : '' ?>" id="loginTab">Log In</button>
        <button type="button" class="login-tab<?= $activeTab === 'signup' ? ' active' : '' ?>" id="signupTab">Sign Up</button>
      </div>

      <div id="loginPane" style="display:<?= $activeTab === 'login' ? 'block' : 'none' ?>;">
        <h2>Welcome</h2>
        <p class="auth-sub">Log in with your account ID — your role is detected automatically.</p>

        <div class="auth-error<?= $loginError ? ' show' : '' ?>">Invalid ID or password. Please try again.</div>

        <form id="loginForm" method="post">
          <input type="hidden" name="action" value="login">
          <div class="field">
            <label for="idInput">Account Number</label>
            <input id="idInput" name="id" type="text" placeholder="e.g. 21-00123" autocomplete="off" value="<?= e($_POST['id'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label for="pwInput">Password</label>
            <input id="pwInput" name="password" type="password" placeholder="Enter your password" required>
          </div>
          <div class="field-foot">
            <button type="button" class="link-small" id="forgotPasswordBtn">Forgot Password</button>
          </div>
          <button type="submit" class="btn-primary">Log In</button>
        </form>

      </div>

      <div id="signupPane" style="display:<?= $activeTab === 'signup' ? 'block' : 'none' ?>;">
        <h2>Create a student account</h2>
        <p class="auth-sub">Sign up is for students only. Guards and OSA staff accounts are created by an administrator.</p>

        <form id="signupForm" method="post">
          <input type="hidden" name="action" value="signup">
          <div class="field">
            <label for="suStudentNumber">Student number</label>
            <input id="suStudentNumber" name="student_number" type="text" placeholder="e.g. 2411695" autocomplete="off"
                   value="<?= e(($_POST['action'] ?? '') === 'signup' ? ($_POST['student_number'] ?? '') : '') ?>"
                   style="border-color:<?= isset($signupErrors['student_number']) ? 'var(--danger)' : 'var(--border)' ?>">
            <?php if (isset($signupErrors['student_number'])): ?><small style="color:var(--danger);"><?= e($signupErrors['student_number']) ?></small><?php endif; ?>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="suFirstName">First name</label>
              <input id="suFirstName" name="first_name" type="text" placeholder="e.g. Juan" autocomplete="given-name"
                     value="<?= e(($_POST['action'] ?? '') === 'signup' ? ($_POST['first_name'] ?? '') : '') ?>"
                     style="border-color:<?= isset($signupErrors['first_name']) ? 'var(--danger)' : 'var(--border)' ?>" required>
              <?php if (isset($signupErrors['first_name'])): ?><small style="color:var(--danger);"> <?= e($signupErrors['first_name']) ?></small><?php endif; ?>
            </div>
            <div class="field">
              <label for="suLastName">Last name</label>
              <input id="suLastName" name="last_name" type="text" placeholder="e.g. Dela Cruz" autocomplete="family-name"
                     value="<?= e(($_POST['action'] ?? '') === 'signup' ? ($_POST['last_name'] ?? '') : '') ?>"
                     style="border-color:<?= isset($signupErrors['last_name']) ? 'var(--danger)' : 'var(--border)' ?>" required>
              <?php if (isset($signupErrors['last_name'])): ?><small style="color:var(--danger);"> <?= e($signupErrors['last_name']) ?></small><?php endif; ?>
            </div>
          </div>
          <div class="field">
            <label for="suEmail">School email</label>
            <input id="suEmail" name="email" type="email" placeholder="you@uvms.edu.ph" autocomplete="off"
                   value="<?= e(($_POST['action'] ?? '') === 'signup' ? ($_POST['email'] ?? '') : '') ?>"
                   style="border-color:<?= isset($signupErrors['email']) ? 'var(--danger)' : 'var(--border)' ?>">
            <?php if (isset($signupErrors['email'])): ?><small style="color:var(--danger);"><?= e($signupErrors['email']) ?></small><?php endif; ?>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="suProgram">Program</label>
              <input id="suProgram" name="program" type="text" placeholder="e.g. BS Information Technology" autocomplete="organization-title"
                     value="<?= e(($_POST['action'] ?? '') === 'signup' ? ($_POST['program'] ?? '') : '') ?>"
                     style="border-color:<?= isset($signupErrors['program']) ? 'var(--danger)' : 'var(--border)' ?>" required>
              <?php if (isset($signupErrors['program'])): ?><small style="color:var(--danger);"> <?= e($signupErrors['program']) ?></small><?php endif; ?>
            </div>
            <div class="field">
              <label for="suYearLevel">Year level</label>
              <?php $selectedYearLevel = ($_POST['action'] ?? '') === 'signup' ? ($_POST['year_level'] ?? '') : ''; ?>
              <select id="suYearLevel" name="year_level"
                      style="border-color:<?= isset($signupErrors['year_level']) ? 'var(--danger)' : 'var(--border)' ?>" required>
                <option value="">Select year level</option>
                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'] as $yearOption): ?>
                  <option value="<?= e($yearOption) ?>"<?= $selectedYearLevel === $yearOption ? ' selected' : '' ?>><?= e($yearOption) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($signupErrors['year_level'])): ?><small style="color:var(--danger);"> <?= e($signupErrors['year_level']) ?></small><?php endif; ?>
            </div>
          </div>
          <div class="field">
            <label for="suPassword">Password</label>
            <input id="suPassword" name="password" type="password" placeholder="At least 6 characters"
                   style="border-color:<?= isset($signupErrors['password']) ? 'var(--danger)' : 'var(--border)' ?>">
            <?php if (isset($signupErrors['password'])): ?><small style="color:var(--danger);"><?= e($signupErrors['password']) ?></small><?php endif; ?>
          </div>
          <div class="field">
            <label for="suPasswordConfirm">Confirm password</label>
            <input id="suPasswordConfirm" name="password_confirm" type="password" placeholder="Re-enter your password"
                   style="border-color:<?= isset($signupErrors['password_confirm']) ? 'var(--danger)' : 'var(--border)' ?>">
            <?php if (isset($signupErrors['password_confirm'])): ?><small style="color:var(--danger);"><?= e($signupErrors['password_confirm']) ?></small><?php endif; ?>
          </div>
          <button type="submit" class="btn-primary">Sign Up</button>
        </form>
      </div>

    </div>
  </div>

</div>

<div class="modal-backdrop<?= $forgotError ? ' show' : '' ?>" id="forgotModal">
  <div class="modal">
    <h3>Reset your password</h3>
    <p>Enter the school email linked to your account and we'll send a reset link.</p>
    <form method="post">
      <input type="hidden" name="action" value="forgot">
      <div class="field">
        <label for="forgotEmailInput">School email</label>
        <input id="forgotEmailInput" name="email" type="email" placeholder="you@uvms.edu.ph" autocomplete="off" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="auth-error<?= $forgotError ? ' show' : '' ?>">No account found with that email. Guard accounts aren't reachable by email.</div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" id="cancelForgot">Cancel</button>
        <button type="submit" class="btn btn-dark">Send reset link</button>
      </div>
    </form>
  </div>
</div>

<div class="toast<?= $toast ? ' show' : '' ?>" id="toast"><?= e($toast ?? '') ?></div>

<script>
function showPane(pane) {
  document.getElementById('loginPane').style.display = pane === 'login' ? 'block' : 'none';
  document.getElementById('signupPane').style.display = pane === 'signup' ? 'block' : 'none';
  document.getElementById('loginTab').classList.toggle('active', pane === 'login');
  document.getElementById('signupTab').classList.toggle('active', pane === 'signup');
}

document.getElementById('loginTab').addEventListener('click', () => showPane('login'));
document.getElementById('signupTab').addEventListener('click', () => showPane('signup'));

document.getElementById('forgotPasswordBtn').addEventListener('click', () => {
  document.getElementById('forgotModal').classList.add('show');
});
document.getElementById('cancelForgot').addEventListener('click', () => {
  document.getElementById('forgotModal').classList.remove('show');
});

<?php if ($toast): ?>
setTimeout(() => document.getElementById('toast').classList.remove('show'), 3400);
<?php endif; ?>
</script>
</body>
</html>
