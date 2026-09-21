<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/mailer.php';
require __DIR__ . '/includes/google_client.php';
require __DIR__ . '/includes/google_sheets.php';
require __DIR__ . '/includes/google_calendar.php';
require_once __DIR__ . '/includes/google_oauth.php';

$session = requireRole('osa');
$osa = getOsa($pdo, $session['id']);
if (!$osa) { clearSession(); header('Location: index.php'); exit; }

$flash = null;
$accountErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'connect_google_calendar') {
  $url = googleOAuthAuthorizationUrl($session['id']);
  if ($url) {
    header('Location: ' . $url);
    exit;
  }
  $_SESSION['flash'] = 'Google Calendar OAuth is not configured. Copy google_oauth_config.sample.php to google_oauth_config.php and fill in the OAuth client values.';
  header('Location: osa.php?view=review');
  exit;
}

// ---------- Open a case for review (submitted -> open) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    $caseId = $_POST['case_id'] ?? '';
    $v = getCase($pdo, $caseId);
    if ($v && $v['status'] === 'submitted') {
        openCaseForReview($pdo, $caseId, $osa['osa_id']);
        autoSyncStudentsToSheet($pdo);
    }
    header('Location: osa.php?view=review&open=' . urlencode($caseId));
    exit;
}

// ---------- Clear a case ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $caseId = $_POST['case_id'] ?? '';
    $before = getCase($pdo, $caseId);
    // A cleared case doesn't need its "fix/clear by" reminder anymore —
    // tidy up the calendar event so the shared OSA calendar stays accurate.
    if ($before && !empty($before['calendar_event_id'] ?? null)) {
        deleteCaseDeadlineEvent($before['calendar_event_id']);
        clearCaseDeadline($pdo, $caseId);
    }
    clearCase($pdo, $caseId);
    autoSyncStudentsToSheet($pdo);
    $view = $_POST['return_view'] ?? 'review';
    $student = $_POST['return_student'] ?? '';
    $_SESSION['flash'] = "Case $caseId marked as cleared.";
    header('Location: osa.php?view=' . urlencode($view) . ($student ? '&student=' . urlencode($student) : ''));
    exit;
}

// ---------- Set / update a case's compliance deadline (Google Calendar) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_deadline') {
    $caseId  = $_POST['case_id'] ?? '';
    $dueDate = $_POST['due_date'] ?? '';
    $view    = $_POST['return_view'] ?? 'review';
    $student = $_POST['return_student'] ?? '';

  if (!googleOAuthConnected($session['id'])) {
    $url = googleOAuthAuthorizationUrl($session['id']);
    if ($url) {
      $_SESSION['flash'] = 'Authorize your Google Calendar, then submit the deadline again.';
      header('Location: ' . $url);
      exit;
    }
    $_SESSION['flash'] = 'Google Calendar is not configured. Copy google_oauth_config.sample.php to google_oauth_config.php and add your Google OAuth client values.';
    header('Location: osa.php?view=' . urlencode($view) . ($student ? '&student=' . urlencode($student) : '') . '&open=' . urlencode($caseId));
    exit;
  }

    $v = getCase($pdo, $caseId);
    if ($v && $dueDate !== '') {
        $s = getStudent($pdo, $v['student_number']);
        $type = getViolationType($pdo, $v['type_id']);
        $studentName = $s ? trim($s['first_name'] . ' ' . $s['last_name']) : $v['student_number'];

        $result = upsertCaseDeadlineEvent($caseId, $studentName, $type['name'] ?? 'Violation', $dueDate, $v['calendar_event_id'] ?? null);
        if ($result['ok']) {
            setCaseDeadline($pdo, $caseId, $dueDate, $result['event_id'], $result['event_link']);
            autoSyncStudentsToSheet($pdo);
          if ($s && !empty($s['email'])) {
            sendDeadlineSetEmail(
              $s['email'],
              $studentName,
              $caseId,
              $type['name'] ?? 'Violation',
              fmtDateOnly($dueDate)
            );
          }
            $_SESSION['flash'] = "Deadline for $caseId set to " . fmtDateOnly($dueDate) . '.';
        } else {
            $_SESSION['flash'] = $result['message'];
        }
    }
    header('Location: osa.php?view=' . urlencode($view) . ($student ? '&student=' . urlencode($student) : '') . '&open=' . urlencode($caseId));
    exit;
}

// ---------- Remove a case's compliance deadline ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_deadline') {
    $caseId  = $_POST['case_id'] ?? '';
    $view    = $_POST['return_view'] ?? 'review';
    $student = $_POST['return_student'] ?? '';

    $v = getCase($pdo, $caseId);
    if ($v && !empty($v['calendar_event_id'] ?? null)) {
        deleteCaseDeadlineEvent($v['calendar_event_id']);
    }
    if ($v) {
        clearCaseDeadline($pdo, $caseId);
        autoSyncStudentsToSheet($pdo);
        $_SESSION['flash'] = "Deadline removed for $caseId.";
    }
    header('Location: osa.php?view=' . urlencode($view) . ($student ? '&student=' . urlencode($student) : '') . '&open=' . urlencode($caseId));
    exit;
}

// ---------- Sync the student directory to Google Sheets ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_sheet') {
    $result = syncStudentsToSheet($pdo, $session['id']);
    $_SESSION['flash'] = $result['message'];
    $student = $_POST['return_student'] ?? '';
    header('Location: osa.php?view=records' . ($student ? '&student=' . urlencode($student) : ''));
    exit;
}

  // ---------- Create an account ----------
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_account') {
    $role = $_POST['account_role'] ?? '';
    $accountId = trim($_POST['account_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (!in_array($role, ['student', 'osa'], true)) $accountErrors['role'] = 'Choose either a student or OSA account.';
    if ($accountId === '') $accountErrors['id'] = 'Account ID is required.';
    if ($firstName === '') $accountErrors['first_name'] = 'First name is required.';
    if ($lastName === '') $accountErrors['last_name'] = 'Last name is required.';
    if (in_array($role, ['student', 'osa'], true) && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) $accountErrors['email'] = 'Enter a valid email address.';
    if (strlen($password) < 6) $accountErrors['password'] = 'Password must be at least 6 characters.';
    if ($password !== $passwordConfirm) $accountErrors['password_confirm'] = 'Passwords do not match.';
    if ($role === 'student' && trim($_POST['program'] ?? '') === '') $accountErrors['program'] = 'Program is required.';
    if ($role === 'student' && trim($_POST['year_level'] ?? '') === '') $accountErrors['year_level'] = 'Year level is required.';
    if ($role === 'osa' && trim($_POST['position'] ?? '') === '') $accountErrors['position'] = 'Position is required.';
    if (!$accountErrors && accountIdTaken($pdo, $role, $accountId)) $accountErrors['id'] = 'That account ID is already in use.';
    if (!$accountErrors && $email !== '' && emailTaken($pdo, $email)) $accountErrors['email'] = 'That email is already linked to an account.';

    if (!$accountErrors) {
      try {
        createAccountByOsa($pdo, $role, $accountId, $firstName, $lastName, [
          'email' => $email,
          'program' => trim($_POST['program'] ?? ''),
          'year_level' => trim($_POST['year_level'] ?? ''),
          'position' => trim($_POST['position'] ?? ''),
        ], $password, $session['id']);
        $_SESSION['flash'] = ucfirst($role) . ' account created successfully.';
        header('Location: osa.php?view=accounts');
        exit;
      } catch (PDOException $e) {
        $accountErrors['form'] = 'The account could not be created. Check that the account ID is unique.';
      }
    }
  }

  // ---------- Remove a student or OSA account ----------
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_account') {
    $role = $_POST['account_role'] ?? '';
    $accountId = trim($_POST['account_id'] ?? '');

    try {
      removeAccountByOsa($pdo, $role, $accountId, $session['id']);
      $_SESSION['flash'] = ucfirst($role) . ' account removed successfully.';
    } catch (Throwable $e) {
      $_SESSION['flash'] = $e->getMessage();
    }
    header('Location: osa.php?view=accounts');
    exit;
  }

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$view = $_GET['view'] ?? (($_POST['action'] ?? '') === 'create_account' ? 'accounts' : 'review');
$openCaseId = $_GET['open'] ?? null;

$rows = openCases($pdo);
$typesById = [];
foreach (getViolationTypes($pdo) as $t) $typesById[$t['type_id']] = $t;

$searchedStudent = null;
$searchHistory = [];
if (($_GET['student'] ?? '') !== '') {
    $searchedStudent = getStudent($pdo, $_GET['student']);
    if ($searchedStudent) $searchHistory = violationsForStudent($pdo, $searchedStudent['student_number']);
}

$allStudents = getAllStudents($pdo);
$allOsaAccounts = getAllOsaAccounts($pdo);

// Build a lookup of full case detail (for both the review list and search history) for the modal
$detailCaseIds = array_unique(array_merge(
    array_column($rows, 'case_id'),
    array_column($searchHistory, 'case_id')
));
$caseDetails = [];
foreach ($detailCaseIds as $cid) {
    $v = getCase($pdo, $cid);
    if (!$v) continue;
    $s = getStudent($pdo, $v['student_number']);
    $g = getGuard($pdo, $v['guard_id']);
    $type = $typesById[$v['type_id']] ?? null;
    $caseDetails[$cid] = [
        'case_id'    => $v['case_id'],
        'student'    => $s ? $s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['student_number'] . ')' : '—',
        'type'       => $type['name'] ?? '—',
        'severity'   => $type['severity'] ?? '',
        'details'    => $v['details'],
        'guard'      => $g ? $g['first_name'] . ' ' . $g['last_name'] : '—',
        'date'       => fmtDate($v['date_recorded']),
        'status'     => $v['status'],
        'pill_label' => $v['status'] === 'cleared' ? 'CLEARED' : 'PENDING',
        'pill_class' => $v['status'] === 'cleared' ? 'gv-pill gv-pill-cleared' : 'gv-pill gv-pill-pending',
        'can_clear'  => $v['status'] !== 'cleared',
        'due_date_iso'     => !empty($v['due_date']) ? (new DateTime($v['due_date']))->format('Y-m-d') : '',
        'due_date_display' => !empty($v['due_date']) ? fmtDateOnly($v['due_date']) : null,
        'calendar_link'    => $v['calendar_event_link'] ?? null,
    ];
}

$fullName = trim($osa['first_name'] . ' ' . $osa['last_name']);
$initials = strtoupper(mb_substr($osa['first_name'], 0, 1) . mb_substr($osa['last_name'], 0, 1));

function displayCaseIdOsa(string $caseId): string {
    return str_replace('-', ' - ', $caseId);
}
function pillClassOsa(string $status): string {
    return $status === 'cleared' ? 'gv-pill gv-pill-cleared' : 'gv-pill gv-pill-pending';
}
function pillLabelOsa(string $status): string {
    return $status === 'cleared' ? 'CLEARED' : 'PENDING';
}

// Renders one case card — same visual language as the guard/student portals
// (watermark background, CASE ID / VIOLATION rows, status pill) — but shows
// who reported it, since that's what OSA needs to see.
function renderOsaCard(array $v, array $typesById, PDO $pdo, string $returnView): void {
    $type = $typesById[$v['type_id']] ?? null;
    $g = getGuard($pdo, $v['guard_id']);
    $guardName = $g ? $g['first_name'] . ' ' . $g['last_name'] : '—';
    ?>
    <div class="gv-card gv-card-clickable" onclick="openCase('<?= e($v['case_id']) ?>', '<?= e($returnView) ?>')">
      <div class="gv-card-watermark" aria-hidden="true"></div>
      <div class="gv-card-body">
        <div class="gv-card-row">
          <span class="gv-card-label">CASE ID</span>
          <span class="gv-card-value gv-card-caseid"><?= e(displayCaseIdOsa($v['case_id'])) ?></span>
        </div>
        <div class="gv-card-row">
          <span class="gv-card-label">VIOLATION</span>
          <span class="gv-card-value"><?= e($type['name'] ?? '—') ?></span>
        </div>
        <div class="gv-card-date">
          <?= e(fmtDate($v['date_recorded'])) ?> - Reported By <?= e($guardName) ?>
        </div>
        <?php if (!empty($v['due_date'] ?? null)): ?>
          <div class="gv-card-date" style="color: var(--warn);">Fix/clear by <?= e(fmtDateOnly($v['due_date'])) ?></div>
        <?php endif; ?>
      </div>
      <span class="<?= pillClassOsa($v['status']) ?>"><?= e(pillLabelOsa($v['status'])) ?></span>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OSA Dashboard — Conduct System</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="guard-page">

<div class="gv-shell">

  <header class="gv-topbar">
    <div class="gv-topbar-left">
      <button type="button" class="gv-menu-btn" id="menuBtn" aria-label="Toggle menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <span class="gv-topbar-title">DASHBOARD</span>
    </div>
    <div class="gv-account">
      <button type="button" class="gv-avatar-btn" id="acctBtn" aria-haspopup="true" aria-expanded="false">
        <span class="gv-avatar-circle gv-avatar-circle-sm"><?= e($initials) ?></span>
      </button>
      <div class="gv-account-menu" id="acctMenu">
        <form method="post" action="logout.php" style="margin:0;">
          <button type="submit" class="gv-logout-btn">Log Out</button>
        </form>
      </div>
    </div>
  </header>

  <nav class="gv-nav" id="gvNav">
    <a href="osa.php?view=review" class="gv-nav-link<?= $view === 'review' ? ' active' : '' ?>">CASE REVIEW</a>
    <a href="osa.php?view=records" class="gv-nav-link<?= $view === 'records' ? ' active' : '' ?>">STUDENT RECORDS</a>
    <a href="osa.php?view=accounts" class="gv-nav-link<?= $view === 'accounts' ? ' active' : '' ?>">ACCOUNTS</a>
  </nav>

  <section class="gv-banner">
    <img src="assets/img/osa-banner.png" alt="" class="gv-banner-bg">
    <div class="gv-banner-content">
      <span class="gv-avatar-circle gv-avatar-circle-lg"><?= e($initials) ?></span>
      <div class="gv-banner-text">
        <div class="gv-banner-name"><?= e($fullName) ?></div>
        <div class="gv-banner-badge">OSA</div>
      </div>
      <?php if (googleOAuthConnected($session['id'])): ?>
        <span class="gv-banner-badge">GOOGLE SERVICES CONNECTED</span>
        <form method="post" style="margin-left:8px;">
          <input type="hidden" name="action" value="connect_google_calendar">
          <button type="submit" class="gv-btn-dark">RECONNECT GOOGLE SERVICES</button>
        </form>
      <?php else: ?>
        <form method="post" style="margin-left:auto;">
          <input type="hidden" name="action" value="connect_google_calendar">
          <button type="submit" class="gv-btn-dark">CONNECT GOOGLE CALENDAR</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <main class="gv-main">

    <div id="view-review" class="gv-view<?= $view === 'review' ? ' active' : '' ?>">
      <h1 class="gv-heading">CASE REVIEW</h1>
      <p class="gv-subheading">Violations submitted by guards, awaiting or under OSA review.</p>

      <div class="gv-cards">
        <?php if ($rows): foreach ($rows as $v): ?>
          <?php renderOsaCard($v, $typesById, $pdo, 'review'); ?>
        <?php endforeach; else: ?>
          <div class="gv-empty">No open cases. All caught up.</div>
        <?php endif; ?>
      </div>
    </div>

    <div id="view-records" class="gv-view<?= $view === 'records' ? ' active' : '' ?>">
      <h1 class="gv-heading">STUDENT RECORDS</h1>
      <p class="gv-subheading">Search a student to view their full violation history.</p>

      <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:20px;">
        <form method="post" style="margin:0;">
          <input type="hidden" name="action" value="sync_sheet">
          <?php if ($searchedStudent): ?><input type="hidden" name="return_student" value="<?= e($searchedStudent['student_number']) ?>"><?php endif; ?>
          <button type="submit" class="gv-btn-dark">SYNC TO GOOGLE SHEET</button>
        </form>
        <?php if (googleIsConfigured()): ?>
          <a href="<?= e(googleSheetUrl()) ?>" target="_blank" rel="noopener" style="font-size:13px;">Open the Google Sheet →</a>
        <?php else: ?>
          <span style="font-size:13px; color:var(--text-muted);">Google isn't set up yet — see SETUP_GOOGLE.md.</span>
        <?php endif; ?>
      </div>

      <div class="gv-search-panel">
        <div class="gv-field">
          <label for="searchInput">Search by student number or name</label>
          <input id="searchInput" type="text" placeholder="e.g. 2411685 or Christian Reyes" autocomplete="off" class="gv-input"
                 value="<?= $searchedStudent ? e($searchedStudent['first_name'] . ' ' . $searchedStudent['last_name'] . ' (' . $searchedStudent['student_number'] . ')') : '' ?>">
        </div>
        <div id="searchResults" class="search-results" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:20; max-height:280px; overflow-y:auto; background:var(--white); margin-top:0;"></div>
      </div>

      <?php if ($searchedStudent): ?>
        <p class="gv-student-summary">
          <strong><?= e($searchedStudent['first_name'] . ' ' . $searchedStudent['last_name']) ?></strong>
          &nbsp;·&nbsp;<?= e($searchedStudent['student_number']) ?>
          &nbsp;·&nbsp;<?= e($searchedStudent['program'] . ' - ' . $searchedStudent['year_level']) ?>
        </p>
        <div class="gv-cards">
          <?php if ($searchHistory): foreach ($searchHistory as $v): ?>
            <?php renderOsaCard($v, $typesById, $pdo, 'records'); ?>
          <?php endforeach; else: ?>
            <div class="gv-empty">No violations on record for this student.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div id="view-accounts" class="gv-view<?= $view === 'accounts' ? ' active' : '' ?>">
      <h1 class="gv-heading">ACCOUNT MANAGEMENT</h1>
      <p class="gv-subheading">Create login accounts for students and OSA staff.</p>

      <div class="gv-card-panel gv-card-panel-narrow">
        <?php if (!empty($accountErrors['form'])): ?><div class="auth-error show" style="margin-bottom:16px;"><?= e($accountErrors['form']) ?></div><?php endif; ?>
        <?php if ($accountErrors && empty($accountErrors['form'])): ?><div class="auth-error show" style="margin-bottom:16px;">Please check the account details and try again.</div><?php endif; ?>
        <form method="post" id="accountForm">
          <input type="hidden" name="action" value="create_account">
          <div class="gv-row-3">
            <div class="gv-field">
              <label for="accountRole">Account type</label>
              <select id="accountRole" name="account_role" class="gv-input" required>
                <?php $selectedRole = $_POST['account_role'] ?? 'student'; ?>
                <option value="student"<?= $selectedRole === 'student' ? ' selected' : '' ?>>Student</option>
                <option value="osa"<?= $selectedRole === 'osa' ? ' selected' : '' ?>>OSA staff</option>
              </select>
            </div>
            <div class="gv-field">
              <label for="accountId">Account ID / number</label>
              <input id="accountId" name="account_id" type="text" class="gv-input" value="<?= e($_POST['account_id'] ?? '') ?>" required>
            </div>
          </div>
          <div class="gv-row-3">
            <div class="gv-field"><label for="accountFirstName">First name</label><input id="accountFirstName" name="first_name" type="text" class="gv-input" value="<?= e($_POST['first_name'] ?? '') ?>" required></div>
            <div class="gv-field"><label for="accountLastName">Last name</label><input id="accountLastName" name="last_name" type="text" class="gv-input" value="<?= e($_POST['last_name'] ?? '') ?>" required></div>
            <div class="gv-field"><label for="accountEmail">Email</label><input id="accountEmail" name="email" type="email" class="gv-input" value="<?= e($_POST['email'] ?? '') ?>"><small class="field-note">Required for students and OSA staff.</small></div>
          </div>
          <div id="studentAccountFields" class="gv-row-3">
            <div class="gv-field"><label for="accountProgram">Program</label><input id="accountProgram" name="program" type="text" class="gv-input" value="<?= e($_POST['program'] ?? '') ?>"></div>
            <div class="gv-field"><label for="accountYear">Year level</label><input id="accountYear" name="year_level" type="text" class="gv-input" value="<?= e($_POST['year_level'] ?? '') ?>" placeholder="e.g. 3rd Year"></div>
          </div>
          <div id="osaAccountFields" class="gv-field" style="display:none; max-width:33%;"><label for="accountPosition">Position</label><input id="accountPosition" name="position" type="text" class="gv-input" value="<?= e($_POST['position'] ?? '') ?>" placeholder="e.g. OSA Officer"></div>
          <div class="gv-row-3">
            <div class="gv-field"><label for="accountPassword">Temporary password</label><input id="accountPassword" name="password" type="password" class="gv-input" required></div>
            <div class="gv-field"><label for="accountPasswordConfirm">Confirm password</label><input id="accountPasswordConfirm" name="password_confirm" type="password" class="gv-input" required></div>
          </div>
          <button type="submit" class="gv-btn-dark">CREATE ACCOUNT</button>
        </form>
      </div>

      <div class="gv-card-panel gv-card-panel-narrow" style="margin-top:20px;">
        <h3 class="gv-panel-title">EXISTING ACCOUNTS</h3>
        <div class="account-list">
          <?php foreach ($allStudents as $account): ?>
            <div class="account-list-row">
              <strong>Student</strong>
              <span><?= e($account['student_number']) ?> - <?= e($account['first_name'] . ' ' . $account['last_name']) ?></span>
              <small><?= e($account['program'] ?: 'No program') ?></small>
              <form method="post" class="account-remove-form" onsubmit="return confirm('Remove this student account? Accounts with violation records cannot be removed.');">
                <input type="hidden" name="action" value="remove_account">
                <input type="hidden" name="account_role" value="student">
                <input type="hidden" name="account_id" value="<?= e($account['student_number']) ?>">
                <button type="submit" class="account-remove-btn">REMOVE</button>
              </form>
            </div>
          <?php endforeach; ?>
          <?php foreach ($allOsaAccounts as $account): ?>
            <div class="account-list-row">
              <strong>OSA</strong>
              <span><?= e($account['osa_id']) ?> - <?= e($account['first_name'] . ' ' . $account['last_name']) ?></span>
              <small><?= e($account['position'] ?: 'No position') ?></small>
              <form method="post" class="account-remove-form" onsubmit="return confirm('Remove this OSA account?');">
                <input type="hidden" name="action" value="remove_account">
                <input type="hidden" name="account_role" value="osa">
                <input type="hidden" name="account_id" value="<?= e($account['osa_id']) ?>">
                <button type="submit" class="account-remove-btn">REMOVE</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </main>
</div>

<div class="gv-case-modal-backdrop" id="caseDetailModal">
  <div class="gv-case-modal">
    <h3 class="gv-case-modal-title">CASE DETAILS</h3>
    <dl class="gv-case-modal-grid" id="caseDetailGrid"></dl>

    <div class="gv-case-modal-deadline" style="margin: 16px 0;">
      <label for="deadlineInput" style="display:block; font-size:13px; color:var(--text-muted); margin-bottom:6px;">Compliance deadline — fix or clear by</label>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <input type="date" id="deadlineInput" class="gv-input" style="flex:1; min-width:150px; margin-bottom:0;">
        <button type="button" class="gv-btn-dark" id="setDeadlineBtn" style="white-space:nowrap;">SET DEADLINE</button>
      </div>
      <div id="deadlineStatus" style="font-size:13px; margin-top:8px; color:var(--text-muted);"></div>
    </div>

    <div class="gv-case-modal-actions">
      <button type="button" class="gv-btn-dark" id="closeDetail">CLOSE</button>
      <form method="post" id="clearForm" style="margin:0;">
        <input type="hidden" name="action" value="clear">
        <input type="hidden" name="case_id" id="clearCaseId">
        <input type="hidden" name="return_view" id="clearReturnView">
        <input type="hidden" name="return_student" id="clearReturnStudent">
        <button type="submit" class="gv-btn-accent" id="clearCaseBtn">CLEAR CASE</button>
      </form>
    </div>
  </div>
</div>

<div class="toast<?= $flash ? ' show' : '' ?>" id="toast"><?= e($flash ?? '') ?></div>

<script>
const caseDetails = <?php echo json_encode($caseDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const allStudents = <?php echo json_encode($allStudents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function openCase(caseId, returnView) {
  // A submitted case gets moved to "open" the first time OSA looks at it —
  // that state change is a real write, so it's sent to the server; the
  // reload brings the page back with ?open=<id> and reopens this modal.
  const v = caseDetails[caseId];
  if (v && v.status === 'submitted') {
    postAction('open', { case_id: caseId }, returnView);
    return;
  }
  showDetail(caseId, returnView);
}

let currentCaseId = null;
let currentReturnView = 'review';

function postAction(action, fields, returnView) {
  const form = document.createElement('form');
  form.method = 'post';
  form.action = 'osa.php' + (returnView === 'records' ? ('?view=records') : '');
  const params = new URLSearchParams(window.location.search);
  const all = { action, return_view: returnView, return_student: params.get('student') || '', ...fields };
  Object.entries(all).forEach(([k, val]) => {
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = k; input.value = val;
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
}

function showDetail(caseId, returnView) {
  const v = caseDetails[caseId];
  if (!v) return;
  currentCaseId = caseId;
  currentReturnView = returnView;
  document.getElementById('caseDetailGrid').innerHTML = `
    <dt>Case ID</dt><dd>${v.case_id}</dd>
    <dt>Student</dt><dd>${v.student}</dd>
    <dt>Violation</dt><dd>${v.type} <span class="severity-${v.severity}">(${v.severity})</span></dd>
    <dt>Details</dt><dd>${v.details}</dd>
    <dt>Recorded By</dt><dd>${v.guard}</dd>
    <dt>Date Recorded</dt><dd>${v.date}</dd>
    <dt>Status</dt><dd><span class="${v.pill_class}">${v.pill_label}</span></dd>
  `;
  document.getElementById('clearCaseId').value = caseId;
  document.getElementById('clearReturnView').value = returnView;
  const params = new URLSearchParams(window.location.search);
  document.getElementById('clearReturnStudent').value = params.get('student') || '';
  document.getElementById('clearCaseBtn').style.display = v.can_clear ? 'inline-block' : 'none';

  document.getElementById('deadlineInput').value = v.due_date_iso || '';
  const statusEl = document.getElementById('deadlineStatus');
  if (v.due_date_display) {
    const link = v.calendar_link ? ` · <a href="${v.calendar_link}" target="_blank" rel="noopener">View in Calendar</a>` : '';
    statusEl.innerHTML = `Due ${v.due_date_display}${link} · <a href="#" id="removeDeadlineLink">Remove</a>`;
    const rm = document.getElementById('removeDeadlineLink');
    if (rm) rm.addEventListener('click', (e) => {
      e.preventDefault();
      postAction('clear_deadline', { case_id: currentCaseId }, currentReturnView);
    });
  } else {
    statusEl.textContent = 'No deadline set yet.';
  }

  document.getElementById('caseDetailModal').classList.add('show');
}

document.getElementById('setDeadlineBtn').addEventListener('click', () => {
  const val = document.getElementById('deadlineInput').value;
  if (!val || !currentCaseId) return;
  postAction('set_deadline', { case_id: currentCaseId, due_date: val }, currentReturnView);
});

document.getElementById('closeDetail').addEventListener('click', () => {
  document.getElementById('caseDetailModal').classList.remove('show');
});

<?php if ($openCaseId && isset($caseDetails[$openCaseId])): ?>
showDetail(<?= json_encode($openCaseId) ?>, <?= json_encode($view) ?>);
<?php endif; ?>

// Hamburger menu toggle
const menuBtn = document.getElementById('menuBtn');
const gvNav = document.getElementById('gvNav');
menuBtn.addEventListener('click', () => {
  const open = gvNav.classList.toggle('show');
  menuBtn.classList.toggle('open', open);
  menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
});

// Account dropdown (avatar → Log Out)
const acctBtn = document.getElementById('acctBtn');
const acctMenu = document.getElementById('acctMenu');
acctBtn.addEventListener('click', (e) => {
  e.stopPropagation();
  const open = acctMenu.classList.toggle('show');
  acctBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
});
document.addEventListener('click', (e) => {
  if (!acctMenu.contains(e.target) && e.target !== acctBtn) {
    acctMenu.classList.remove('show');
    acctBtn.setAttribute('aria-expanded', 'false');
  }
});

const accountRole = document.getElementById('accountRole');
if (accountRole) {
  const studentFields = document.getElementById('studentAccountFields');
  const osaFields = document.getElementById('osaAccountFields');
  const emailInput = document.getElementById('accountEmail');
  function updateAccountFields() {
    const role = accountRole.value;
    studentFields.style.display = role === 'student' ? 'grid' : 'none';
    osaFields.style.display = role === 'osa' ? 'block' : 'none';
    emailInput.required = role === 'student' || role === 'osa';
  }
  accountRole.addEventListener('change', updateAccountFields);
  updateAccountFields();
}

// Live search-as-you-type over the already-loaded student list — styled like
// the other dropdown lists in the app, and floated so opening it doesn't
// push the summary/cards below it down the page.
const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');
if (searchInput) {
  searchInput.addEventListener('input', () => {
    const query = searchInput.value.trim().toLowerCase();
    if (!query) { searchResults.style.display = 'none'; searchResults.innerHTML = ''; return; }
    const matches = allStudents.filter(s => {
      const fullName = (s.first_name + ' ' + s.last_name).toLowerCase();
      return s.student_number.toLowerCase().includes(query) || fullName.includes(query);
    });
    if (!matches.length) {
      searchResults.innerHTML = `<div class="search-empty">No students match "${query}".</div>`;
      searchResults.style.display = 'block';
      return;
    }
    searchResults.innerHTML = matches.map(s => `
      <div class="search-result-item" data-student="${s.student_number}">
        <span class="num">${s.student_number}</span>${s.first_name} ${s.last_name}
        <span style="color:var(--text-muted);"> · ${s.program}</span>
      </div>
    `).join('');
    searchResults.style.display = 'block';
    searchResults.querySelectorAll('.search-result-item').forEach(item => {
      item.addEventListener('click', () => {
        window.location.href = 'osa.php?view=records&student=' + encodeURIComponent(item.dataset.student);
      });
    });
  });
  document.addEventListener('click', (e) => {
    if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
      searchResults.style.display = 'none';
    }
  });
}
</script>
</body>
</html>
