<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';

$session = requireRole('student');
$student = getStudent($pdo, $session['id']);
if (!$student) { clearSession(); header('Location: index.php'); exit; }

$history = violationsForStudent($pdo, $student['student_number']);

// Pre-fetch related rows so the detail modal can render without another query per row
$typesById = [];
foreach (getViolationTypes($pdo) as $t) $typesById[$t['type_id']] = $t;
$guardsById = [];
$osaById = [];
foreach ($history as $v) {
    if (!isset($guardsById[$v['guard_id']])) $guardsById[$v['guard_id']] = getGuard($pdo, $v['guard_id']);
    if ($v['osa_id'] && !isset($osaById[$v['osa_id']])) $osaById[$v['osa_id']] = getOsa($pdo, $v['osa_id']);
}

// Figma splits the history into a CURRENT tab (still pending review)
// and a PAST tab (already resolved) — draft rows never reach here.
$currentCases = array_values(array_filter($history, fn($v) => $v['status'] !== 'cleared'));
$pastCases    = array_values(array_filter($history, fn($v) => $v['status'] === 'cleared'));

$fullName = trim($student['first_name'] . ' ' . $student['last_name']);
$initials = strtoupper(mb_substr($student['first_name'], 0, 1) . mb_substr($student['last_name'], 0, 1));

function statusLabel(string $status): string {
    return $status === 'cleared' ? 'CLEARED' : 'PENDING';
}
function statusPillClass(string $status): string {
    return $status === 'cleared' ? 'sv-pill sv-pill-cleared' : 'sv-pill sv-pill-pending';
}
function displayCaseId(string $caseId): string {
    return str_replace('-', ' - ', $caseId);
}

// Renders one violation card. Kept as a function since the exact same
// markup is used for both the CURRENT and PAST lists.
function renderCard(array $v, array $typesById, array $guardsById): void {
    $type  = $typesById[$v['type_id']] ?? null;
    $guard = $guardsById[$v['guard_id']] ?? null;
    ?>
    <div class="sv-card" onclick="openDetail('<?= e($v['case_id']) ?>')">
      <div class="sv-card-watermark" aria-hidden="true"></div>
      <div class="sv-card-body">
        <div class="sv-card-row">
          <span class="sv-card-label">CASE ID</span>
          <span class="sv-card-value sv-card-caseid"><?= e(displayCaseId($v['case_id'])) ?></span>
        </div>
        <div class="sv-card-row">
          <span class="sv-card-label">VIOLATION</span>
          <span class="sv-card-value"><?= e($type['name'] ?? '—') ?></span>
        </div>
        <div class="sv-card-date">
          <?= e(fmtDate($v['date_recorded'])) ?> - Reported By <?= e($guard ? $guard['first_name'] . ' ' . $guard['last_name'] : '—') ?>
        </div>
      </div>
      <span class="<?= statusPillClass($v['status']) ?>"><?= statusLabel($v['status']) ?></span>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard — Conduct System</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="student-page">

<div class="sv-shell">

  <header class="sv-topbar">
    <div class="sv-topbar-title">VIOLATIONS</div>
    <div class="sv-account">
      <button type="button" class="sv-avatar-btn" id="acctBtn" aria-haspopup="true" aria-expanded="false">
        <span class="sv-avatar-circle sv-avatar-circle-sm"><?= e($initials) ?></span>
      </button>
      <div class="sv-account-menu" id="acctMenu">
        <form method="post" action="logout.php" style="margin:0;">
          <button type="submit" class="sv-logout-btn">Log Out</button>
        </form>
      </div>
    </div>
  </header>

  <section class="sv-banner">
    <img src="assets/img/student-banner.png" alt="" class="sv-banner-bg">
    <div class="sv-banner-content">
      <span class="sv-avatar-circle sv-avatar-circle-lg"><?= e($initials) ?></span>
      <div class="sv-banner-text">
        <div class="sv-banner-name"><?= e($fullName) ?></div>
        <div class="sv-banner-badge"><?= e(($student['program'] ?: '—') . ' - ' . $student['student_number']) ?></div>
      </div>
    </div>
  </section>

  <main class="sv-main">
    <h1 class="sv-heading">VIOLATION HISTORY</h1>
    <p class="sv-subheading">Records filed against your account by campus guards.</p>

    <div class="sv-tabs">
      <button type="button" class="sv-tab active" id="tabCurrent">CURRENT</button>
      <button type="button" class="sv-tab" id="tabPast">PAST</button>
    </div>

    <div class="sv-cards" id="cardsCurrent">
      <?php if ($currentCases): foreach ($currentCases as $v): renderCard($v, $typesById, $guardsById); endforeach; else: ?>
        <div class="sv-empty">No current violations on record. Keep it that way.</div>
      <?php endif; ?>
    </div>

    <div class="sv-cards" id="cardsPast" style="display:none;">
      <?php if ($pastCases): foreach ($pastCases as $v): renderCard($v, $typesById, $guardsById); endforeach; else: ?>
        <div class="sv-empty">No past violations on record.</div>
      <?php endif; ?>
    </div>
  </main>
</div>

<div class="sv-modal-backdrop" id="detailModal">
  <div class="sv-modal">
    <button type="button" class="sv-modal-close" id="closeDetail" aria-label="Close"><span></span><span></span></button>
    <h2 class="sv-modal-title">CASE DETAILS</h2>
    <dl class="sv-detail-grid" id="detailGrid"></dl>
    <button type="button" class="sv-modal-close-btn" id="closeDetailBtn">CLOSE</button>
  </div>
</div>

<script>
// Case detail data rendered server-side once, used only to populate the modal instantly.
const cases = <?php
  $out = [];
  foreach ($history as $v) {
      $type = $typesById[$v['type_id']] ?? null;
      $guard = $guardsById[$v['guard_id']] ?? null;
      $out[$v['case_id']] = [
          'case_id' => displayCaseId($v['case_id']),
          'student' => $fullName,
          'type' => $type['name'] ?? '—',
          'details' => $v['details'],
          'guard' => $guard ? $guard['first_name'] . ' ' . $guard['last_name'] : '—',
          'date' => fmtDate($v['date_recorded']),
          'status' => statusLabel($v['status']),
          'pillClass' => statusPillClass($v['status']),
      ];
  }
  echo json_encode($out, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;

function openDetail(caseId) {
  const v = cases[caseId];
  if (!v) return;
  document.getElementById('detailGrid').innerHTML = `
    <dt>Case ID</dt><dd>${v.case_id}</dd>
    <dt>Student</dt><dd>${v.student}</dd>
    <dt>Violation</dt><dd>${v.type}</dd>
    <dt>Details</dt><dd>${v.details || '—'}</dd>
    <dt>Recorded By</dt><dd>${v.guard}</dd>
    <dt>Date Recorded</dt><dd>${v.date}</dd>
    <dt>Status</dt><dd><span class="${v.pillClass}">${v.status}</span></dd>
  `;
  document.getElementById('detailModal').classList.add('show');
}
function closeDetail() {
  document.getElementById('detailModal').classList.remove('show');
}
document.getElementById('closeDetail').addEventListener('click', closeDetail);
document.getElementById('closeDetailBtn').addEventListener('click', closeDetail);
document.getElementById('detailModal').addEventListener('click', (e) => {
  if (e.target.id === 'detailModal') closeDetail();
});

// CURRENT / PAST tabs
const tabCurrent = document.getElementById('tabCurrent');
const tabPast = document.getElementById('tabPast');
const cardsCurrent = document.getElementById('cardsCurrent');
const cardsPast = document.getElementById('cardsPast');
tabCurrent.addEventListener('click', () => {
  tabCurrent.classList.add('active');
  tabPast.classList.remove('active');
  cardsCurrent.style.display = '';
  cardsPast.style.display = 'none';
});
tabPast.addEventListener('click', () => {
  tabPast.classList.add('active');
  tabCurrent.classList.remove('active');
  cardsPast.style.display = '';
  cardsCurrent.style.display = 'none';
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
</script>
</body>
</html>
