<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/mailer.php';
require __DIR__ . '/includes/google_sheets.php';

$session = requireRole('guard');
$guard = getGuard($pdo, $session['id']);
if (!$guard) { clearSession(); header('Location: index.php'); exit; }

$errors = [];

// ---------- Step 1: create the case ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $studentNumber = trim($_POST['student_number'] ?? '');
    $fullName      = trim($_POST['full_name'] ?? '');
    $program       = trim($_POST['program'] ?? '');
    $yearLevel     = trim($_POST['year_level'] ?? '');
    $typeId        = trim($_POST['type_id'] ?? '');

    if ($studentNumber === '') $errors['student_number'] = true;
    if ($fullName === '') $errors['full_name'] = true;
    if ($program === '') $errors['program'] = true;
    if ($yearLevel === '') $errors['year_level'] = true;
    if ($typeId === '' || !getViolationType($pdo, $typeId)) $errors['type_id'] = true;

    if (!$errors) {
        ensureStudent($pdo, $studentNumber, $fullName, $program, $yearLevel);
        $draft = createDraftViolation($pdo, $studentNumber, $guard['guard_id'], $typeId);
        $_SESSION['draft_case'] = $draft['case_id'];
        $_SESSION['draft_name'] = $fullName;
        header('Location: guard.php?step=details');
        exit;
    }
}

// ---------- Step 2: submit the violation details ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    $caseId  = $_SESSION['draft_case'] ?? '';
    $details = trim($_POST['details'] ?? '');

    if ($caseId && $details !== '') {
        $case = submitDraftViolation($pdo, $caseId, $details);
        unset($_SESSION['draft_case'], $_SESSION['draft_name']);
        autoSyncStudentsToSheet($pdo);

        // Notify the student their violation is on file, and notify
        // OSA staff there's a new case waiting for review. Email
        // failures are logged (see includes/mailer.php) and never
        // block the guard's workflow.
        if ($case) {
            $type = getViolationType($pdo, $case['type_id']);
            $violationName = $type['name'] ?? 'Violation';

            $student = getStudent($pdo, $case['student_number']);
            if ($student && !empty($student['email'])) {
                sendViolationLoggedEmail(
                    $student['email'],
                    $student['first_name'] . ' ' . $student['last_name'],
                    $case['case_id'],
                    $violationName,
                    fmtDate($case['date_recorded'])
                );
            }

            $studentName = $student ? $student['first_name'] . ' ' . $student['last_name'] : $case['student_number'];
            foreach (getAllOsaStaff($pdo) as $osaStaff) {
                sendNewCaseEmailToOsa(
                    $osaStaff['email'],
                    $osaStaff['first_name'] . ' ' . $osaStaff['last_name'],
                    $case['case_id'],
                    $studentName,
                    $violationName
                );
            }
        }

        $_SESSION['flash'] = "Case $caseId has been sent to OSA for review.";
        header('Location: guard.php');
        exit;
    }
    $errors['details'] = true;
}

// ---------- Discard the in-progress draft ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'discard') {
    $caseId = $_SESSION['draft_case'] ?? '';
    if ($caseId) discardDraftViolation($pdo, $caseId);
    unset($_SESSION['draft_case'], $_SESSION['draft_name']);
    header('Location: guard.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$view = $_GET['view'] ?? 'record';
$showDetailsStep = ($_GET['step'] ?? '') === 'details' && !empty($_SESSION['draft_case']);

$students = getAllStudents($pdo);
$types = getViolationTypes($pdo);
$log = violationsByGuard($pdo, $guard['guard_id']);

$typesById = [];
foreach ($types as $t) $typesById[$t['type_id']] = $t;

$draftCase = null;
if ($showDetailsStep) $draftCase = getCase($pdo, $_SESSION['draft_case']);

$fullName = trim($guard['first_name'] . ' ' . $guard['last_name']);
$initials = strtoupper(mb_substr($guard['first_name'], 0, 1) . mb_substr($guard['last_name'], 0, 1));

function pillClassGv(string $status): string {
    return $status === 'cleared' ? 'gv-pill gv-pill-cleared' : 'gv-pill gv-pill-pending';
}
function pillLabelGv(string $status): string {
    return $status === 'cleared' ? 'CLEARED' : strtoupper($status);
}
function displayCaseIdGv(string $caseId): string {
    return str_replace('-', ' - ', $caseId);
}

// Renders one violation card — same visual language as the student portal's
// case cards (watermark background, CASE ID / VIOLATION rows, status pill).
function renderGuardCard(array $v, array $typesById, PDO $pdo): void {
    $type = $typesById[$v['type_id']] ?? null;
    $s = getStudent($pdo, $v['student_number']);
    $studentName = $s ? $s['first_name'] . ' ' . $s['last_name'] : $v['student_number'];
    ?>
    <div class="gv-card">
      <div class="gv-card-watermark" aria-hidden="true"></div>
      <div class="gv-card-body">
        <div class="gv-card-row">
          <span class="gv-card-label">CASE ID</span>
          <span class="gv-card-value gv-card-caseid"><?= e(displayCaseIdGv($v['case_id'])) ?></span>
        </div>
        <div class="gv-card-row">
          <span class="gv-card-label">VIOLATION</span>
          <span class="gv-card-value"><?= e($type['name'] ?? '—') ?></span>
        </div>
        <div class="gv-card-date">
          <?= e(fmtDate($v['date_recorded'])) ?> - <?= e($studentName) ?>
        </div>
      </div>
      <span class="<?= pillClassGv($v['status']) ?>"><?= e(pillLabelGv($v['status'])) ?></span>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Guard Dashboard — Conduct System</title>
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
    <a href="guard.php?view=record" class="gv-nav-link<?= $view === 'record' ? ' active' : '' ?>">REPORTS</a>
    <a href="guard.php?view=log" class="gv-nav-link<?= $view === 'log' ? ' active' : '' ?>">HISTORY</a>
  </nav>

  <section class="gv-banner">
    <img src="assets/img/guard-banner.png" alt="" class="gv-banner-bg">
    <div class="gv-banner-content">
      <span class="gv-avatar-circle gv-avatar-circle-lg"><?= e($initials) ?></span>
      <div class="gv-banner-text">
        <div class="gv-banner-name"><?= e($fullName) ?></div>
        <div class="gv-banner-badge">Guard</div>
      </div>
    </div>
  </section>

  <main class="gv-main">

    <div id="view-record" class="gv-view<?= $view === 'record' ? ' active' : '' ?>">
      <h1 class="gv-heading">RECORD VIOLATION</h1>
      <p class="gv-subheading">Enter the student's information to create a case, then add the violation details.</p>

      <div class="gv-card-panel" id="cardStudentInfo" style="display:<?= $showDetailsStep ? 'none' : 'block' ?>;">
        <form method="post" id="createForm">
          <input type="hidden" name="action" value="create">
          <div class="gv-field">
            <label for="studentInput">Student Number</label>
            <input id="studentInput" name="student_number" type="text" placeholder="e.g. 21-00123" autocomplete="off"
                   list="studentList" class="gv-input" style="border-color:<?= isset($errors['student_number']) ? 'var(--danger)' : 'transparent' ?>">
            <datalist id="studentList">
              <?php foreach ($students as $s): ?>
                <option value="<?= e($s['student_number']) ?>"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="gv-row-3">
            <div class="gv-field">
              <label for="fullNameField">Full Name</label>
              <input id="fullNameField" name="full_name" type="text" placeholder="e.g. Juan Dela Cruz" class="gv-input"
                     style="border-color:<?= isset($errors['full_name']) ? 'var(--danger)' : 'transparent' ?>">
            </div>
            <div class="gv-field">
              <label for="programField">Program</label>
              <input id="programField" name="program" type="text" placeholder="e.g. BS Information Technology" class="gv-input"
                     style="border-color:<?= isset($errors['program']) ? 'var(--danger)' : 'transparent' ?>">
            </div>
            <div class="gv-field" style="position:relative;">
              <label for="yearLevelInput">Year</label>
              <input id="yearLevelInput" type="text" class="gv-input gv-select" placeholder="Select" readonly autocomplete="off"
                     style="cursor:pointer; border-color:<?= isset($errors['year_level']) ? 'var(--danger)' : 'transparent' ?>">
              <input type="hidden" id="yearLevelSelect" name="year_level" value="">
              <div id="yearLevelResults" class="search-results" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:20; max-height:260px; overflow-y:auto; background:var(--white);"></div>
            </div>
          </div>
          <div id="studentNotFound" style="display:none; font-size:12.5px; color:var(--text-muted); margin:-10px 0 16px;">Not on file yet — enter the student's name and program manually.</div>
          <div class="gv-field" style="position:relative;">
            <label for="typeSearchInput">Violation Type</label>
            <input id="typeSearchInput" name="type_display" type="text" placeholder="Type to search, e.g. curfew, uniform, cheating..." autocomplete="off"
                   class="gv-input gv-select"
                   value="<?= $types && !isset($errors['type_id']) ? e($types[0]['name'] . ' (' . $types[0]['severity'] . ')') : '' ?>"
                   style="border-color:<?= isset($errors['type_id']) ? 'var(--danger)' : 'transparent' ?>">
            <input type="hidden" id="typeIdField" name="type_id" value="<?= $types && !isset($errors['type_id']) ? e($types[0]['type_id']) : '' ?>">
            <div id="typeSearchResults" class="search-results" style="position:absolute; top:100%; left:0; right:0; z-index:20; max-height:260px; overflow-y:auto; background:var(--white);"></div>
          </div>
          <button type="submit" class="gv-btn-dark">Create Violation Record</button>
        </form>
      </div>

      <div class="gv-card-panel gv-card-panel-narrow" id="cardViolationDetails" style="display:<?= $showDetailsStep ? 'block' : 'none' ?>;">
        <h3 class="gv-panel-title">Violation Details</h3>
        <?php if ($draftCase): $type = $typesById[$draftCase['type_id']] ?? null; ?>
        <p class="gv-panel-sub">Case <?= e($draftCase['case_id']) ?> — <?= e($_SESSION['draft_name'] ?? '') ?> · <?= e($type['name'] ?? '—') ?></p>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="submit">
          <div class="gv-field">
            <textarea id="detailsInput" name="details" rows="6" placeholder="Describe what was observed..." class="gv-textarea"
              style="border-color:<?= isset($errors['details']) ? 'var(--danger)' : 'transparent' ?>"></textarea>
          </div>
          <div style="display:flex; gap:12px;">
            <button type="submit" class="gv-btn-dark">Submit violation to OSA</button>
            <button type="submit" formnovalidate name="action" value="discard" class="gv-btn-outline">Discard</button>
          </div>
        </form>
      </div>
    </div>

    <div id="view-log" class="gv-view<?= $view === 'log' ? ' active' : '' ?>">
      <h1 class="gv-heading">MY SUBMITTED REPORTS</h1>
      <p class="gv-subheading">Violations you've recorded, and their current status with OSA.</p>

      <div class="gv-field" style="margin-bottom:20px; max-width:420px;">
        <input id="logSearchInput" type="text" placeholder="Search by case ID, student, or violation type" autocomplete="off" class="gv-input">
      </div>

      <div class="gv-cards" id="logCards">
        <?php if ($log): foreach ($log as $v): ?>
          <div class="gv-card-search-wrap" data-search="<?= e(strtolower($v['case_id'] . ' ' . ($typesById[$v['type_id']]['name'] ?? '') . ' ' . $v['student_number'])) ?>">
            <?php renderGuardCard($v, $typesById, $pdo); ?>
          </div>
        <?php endforeach; else: ?>
          <div class="gv-empty" id="logEmptyState">No reports submitted yet.</div>
        <?php endif; ?>
      </div>
      <div class="gv-empty" id="logNoMatch" style="display:none;">No reports match your search.</div>
    </div>

  </main>
</div>

<div class="modal-backdrop<?= $flash ? ' show' : '' ?>" id="submitModal">
  <div class="modal">
    <h3>Report submitted</h3>
    <p id="submitModalText"><?= e($flash ?? 'The violation record has been sent to OSA for review.') ?></p>
    <div class="modal-actions">
      <button class="btn btn-dark" id="closeSubmitModal">Done</button>
    </div>
  </div>
</div>

<script>
document.getElementById('closeSubmitModal').addEventListener('click', () => {
  document.getElementById('submitModal').classList.remove('show');
});

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

// Student lookup preview — data is server-rendered, used only for the autofill UX.
const studentsData = <?php echo json_encode($students, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const studentsByNumber = {};
studentsData.forEach(s => studentsByNumber[s.student_number] = s);

// Violation type search — a plain text input plus a styled results list
// rendered underneath it, instead of the browser's native datalist dropdown.
const typesData = <?php echo json_encode($types, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

const typeSearchInput = document.getElementById('typeSearchInput');
const typeSearchResults = document.getElementById('typeSearchResults');
const typeIdField = document.getElementById('typeIdField');

function renderTypeMatches(rawQuery) {
  const query = rawQuery.trim().toLowerCase();
  const matches = query
    ? typesData.filter(t => (t.name + ' ' + t.severity).toLowerCase().includes(query))
    : typesData;

  if (!matches.length) {
    typeSearchResults.innerHTML = `<div class="search-empty">No violation types match "${rawQuery.trim()}".</div>`;
    return;
  }
  typeSearchResults.innerHTML = matches.map((t, i) => `
    <div class="search-result-item" data-index="${i}">
      ${t.name} <span class="severity-${t.severity}">(${t.severity})</span>
    </div>
  `).join('');
  typeSearchResults.querySelectorAll('.search-result-item').forEach((item, i) => {
    item.addEventListener('click', () => {
      const t = matches[i];
      typeIdField.value = t.type_id;
      typeSearchInput.value = t.name + ' (' + t.severity + ')';
      typeSearchResults.innerHTML = '';
    });
  });
}

if (typeSearchInput) {
  typeSearchInput.addEventListener('input', () => {
    typeIdField.value = '';
    renderTypeMatches(typeSearchInput.value);
  });
  typeSearchInput.addEventListener('focus', () => {
    renderTypeMatches(typeSearchInput.value);
  });
  document.addEventListener('click', (e) => {
    if (!typeSearchInput.contains(e.target) && !typeSearchResults.contains(e.target)) {
      typeSearchResults.innerHTML = '';
    }
  });
}

// Year dropdown — styled like the violation type results list, but a fixed
// set of options (no typing/filtering) and positioned to float over the
// fields below it instead of pushing them down when it opens.
const yearOptions = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];
const yearLevelInput = document.getElementById('yearLevelInput');
const yearLevelResults = document.getElementById('yearLevelResults');
const yearLevelField = document.getElementById('yearLevelSelect');

function setYearLevel(value) {
  yearLevelField.value = value;
  yearLevelInput.value = value;
}

function renderYearOptions() {
  yearLevelResults.innerHTML = yearOptions.map((y, i) => `
    <div class="search-result-item" data-index="${i}">${y}</div>
  `).join('');
  yearLevelResults.querySelectorAll('.search-result-item').forEach((item, i) => {
    item.addEventListener('click', () => {
      setYearLevel(yearOptions[i]);
      yearLevelResults.style.display = 'none';
    });
  });
}

if (yearLevelInput) {
  renderYearOptions();
  yearLevelInput.addEventListener('click', () => {
    yearLevelResults.style.display = yearLevelResults.style.display === 'none' ? 'block' : 'none';
  });
  document.addEventListener('click', (e) => {
    if (!yearLevelInput.contains(e.target) && !yearLevelResults.contains(e.target)) {
      yearLevelResults.style.display = 'none';
    }
  });
}

const studentInput = document.getElementById('studentInput');
if (studentInput) {
  studentInput.addEventListener('input', () => {
    const raw = studentInput.value.trim();
    const notFound = document.getElementById('studentNotFound');
    if (!raw) { notFound.style.display = 'none'; return; }
    const s = studentsByNumber[raw];
    if (!s) { notFound.style.display = 'block'; return; }
    notFound.style.display = 'none';
    document.getElementById('fullNameField').value = s.first_name + ' ' + s.last_name;
    document.getElementById('programField').value = s.program;
    setYearLevel(s.year_level || '');
  });
}

// Search-as-you-type over the guard's own submitted reports
const logSearchInput = document.getElementById('logSearchInput');
if (logSearchInput) {
  const logRows = Array.from(document.querySelectorAll('#logCards .gv-card-search-wrap'));
  const hasRows = logRows.length > 0;
  logSearchInput.addEventListener('input', () => {
    const query = logSearchInput.value.trim().toLowerCase();
    let visibleCount = 0;
    logRows.forEach(row => {
      const matches = row.dataset.search.includes(query);
      row.style.display = matches ? '' : 'none';
      if (matches) visibleCount++;
    });
    document.getElementById('logNoMatch').style.display = (hasRows && query && visibleCount === 0) ? 'block' : 'none';
  });
}
</script>
</body>
</html>
