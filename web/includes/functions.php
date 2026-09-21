<?php
/* ============================================
   UVMS — shared data-access layer
   PHP + PDO/MySQL equivalent of the old data.js.
   Every function here talks to the real database.
   ============================================ */

/* ---------- Auth ---------- */

// Looks up an ID across all three account tables and returns
// ['role' => ..., 'account' => [...]] for whichever one matches —
// the account itself determines the role, same as the old JS version.
function authenticate(PDO $pdo, string $id, string $password): ?array {
    $stmt = $pdo->prepare('SELECT * FROM student WHERE student_number = ?');
    $stmt->execute([$id]);
    if ($row = $stmt->fetch()) {
        return password_verify($password, $row['password_hash'] ?? '') ? ['role' => 'student', 'account' => $row] : null;
    }

    $stmt = $pdo->prepare('SELECT * FROM guard WHERE guard_id = ?');
    $stmt->execute([$id]);
    if ($row = $stmt->fetch()) {
        return password_verify($password, $row['password_hash'] ?? '') ? ['role' => 'guard', 'account' => $row] : null;
    }

    $stmt = $pdo->prepare('SELECT * FROM osa WHERE osa_id = ?');
    $stmt->execute([$id]);
    if ($row = $stmt->fetch()) {
        return password_verify($password, $row['password_hash'] ?? '') ? ['role' => 'osa', 'account' => $row] : null;
    }

    return null;
}

// Used by "Forgot password." Only students and OSA staff have an
// email on file — guard records don't carry one, same as before.
function findAccountByEmail(PDO $pdo, string $email): ?array {
    $email = trim(strtolower($email));
    if ($email === '') return null;

    $stmt = $pdo->prepare('SELECT * FROM student WHERE LOWER(email) = ?');
    $stmt->execute([$email]);
    if ($row = $stmt->fetch()) return ['role' => 'student', 'account' => $row];

    $stmt = $pdo->prepare('SELECT * FROM osa WHERE LOWER(email) = ?');
    $stmt->execute([$email]);
    if ($row = $stmt->fetch()) return ['role' => 'osa', 'account' => $row];

    return null;
}

/* ---------- Password reset tokens ---------- */

// Creates a reset token for the given account, invalidating any
// previous unused tokens for that same account first. Returns the
// RAW token (goes in the emailed link) — only its hash is stored.
function createPasswordResetToken(PDO $pdo, string $role, string $id): string {
    $pdo->prepare('DELETE FROM password_reset WHERE account_role = ? AND account_id = ? AND used = 0')
        ->execute([$role, $id]);

    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

    $pdo->prepare('INSERT INTO password_reset (token_hash, account_role, account_id, expires_at, used) VALUES (?, ?, ?, ?, 0)')
        ->execute([$tokenHash, $role, $id, $expiresAt]);

    return $token;
}

// Looks up a raw token from the URL. Returns ['role'=>, 'id'=>] if it's
// valid, unused, and unexpired — null otherwise.
function validateResetToken(PDO $pdo, string $token): ?array {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT * FROM password_reset WHERE token_hash = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['role' => $row['account_role'], 'id' => $row['account_id']];
}

function consumeResetToken(PDO $pdo, string $token): bool {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'UPDATE password_reset SET used = 1
         WHERE token_hash = ? AND used = 0 AND expires_at > NOW()'
    );
    $stmt->execute([$tokenHash]);
    return $stmt->rowCount() === 1;
}

// Sets a new password for any of the three account types.
function setAccountPassword(PDO $pdo, string $role, string $id, string $plainPassword): void {
    $hash  = password_hash($plainPassword, PASSWORD_BCRYPT);
    $table = ['student' => 'student', 'guard' => 'guard', 'osa' => 'osa'][$role] ?? null;
    $col   = ['student' => 'student_number', 'guard' => 'guard_id', 'osa' => 'osa_id'][$role] ?? null;
    if (!$table) return;
    $pdo->prepare("UPDATE $table SET password_hash = ? WHERE $col = ?")->execute([$hash, $id]);
}

/* ---------- OSA distribution list ---------- */

function getAllOsaStaff(PDO $pdo): array {
    return $pdo->query("SELECT * FROM osa WHERE email IS NOT NULL AND email != ''")->fetchAll();
}

/* ---------- Self-service sign up ---------- */

// True if the email is already claimed by a student or OSA account.
function emailTaken(PDO $pdo, string $email): bool {
    return findAccountByEmail($pdo, $email) !== null;
}

// Creates (or claims) a student account for self sign-up.
//   - If the student number already has a password set, sign-up is
//     rejected (account already claimed)
//     this via emailTaken()/studentNumberClaimed() first.
//   - If the student number exists without a password (e.g. a guard
//     logged a violation before the student ever signed up), this
//     claims that existing record by attaching email + password.
//   - Otherwise a brand new student record is created.
function studentNumberClaimed(PDO $pdo, string $studentNumber): bool {
    $s = getStudent($pdo, $studentNumber);
    return $s !== null && !empty($s['password_hash']);
}

function signUpStudent(PDO $pdo, string $studentNumber, string $firstName, string $lastName, string $email, string $program, string $yearLevel, string $plainPassword): array {
    $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
    $existing = getStudent($pdo, $studentNumber);

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE student SET first_name = ?, last_name = ?, email = ?, program = ?, year_level = ?, password_hash = ? WHERE student_number = ?');
        $stmt->execute([$firstName, $lastName, $email, $program, $yearLevel, $hash, $studentNumber]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO student (student_number, first_name, last_name, email, program, year_level, status, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$studentNumber, $firstName, $lastName, $email, '', '', 'active', $hash]);
    }

    return getStudent($pdo, $studentNumber);
}

function setSession(string $role, string $id): void {
    session_regenerate_id(true);
    $_SESSION['role'] = $role;
    $_SESSION['id']   = $id;
}

function getSession(): ?array {
    if (!isset($_SESSION['role'], $_SESSION['id'])) return null;
    return ['role' => $_SESSION['role'], 'id' => $_SESSION['id']];
}

function clearSession(): void {
    $_SESSION = [];
    session_destroy();
}

function requireRole(string $role): array {
    $session = getSession();
    if (!$session || $session['role'] !== $role) {
        header('Location: index.php');
        exit;
    }
    return $session;
}

/* ---------- Lookups ---------- */

function getStudent(PDO $pdo, string $studentNumber): ?array {
    $stmt = $pdo->prepare('SELECT * FROM student WHERE student_number = ?');
    $stmt->execute([$studentNumber]);
    return $stmt->fetch() ?: null;
}

function getGuard(PDO $pdo, string $guardId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM guard WHERE guard_id = ?');
    $stmt->execute([$guardId]);
    return $stmt->fetch() ?: null;
}

function getOsa(PDO $pdo, string $osaId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM osa WHERE osa_id = ?');
    $stmt->execute([$osaId]);
    return $stmt->fetch() ?: null;
}

function getViolationType(PDO $pdo, string $typeId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM violation_type WHERE type_id = ?');
    $stmt->execute([$typeId]);
    return $stmt->fetch() ?: null;
}

function getViolationTypes(PDO $pdo): array {
    return $pdo->query('SELECT * FROM violation_type ORDER BY type_id')->fetchAll();
}

function getAllStudents(PDO $pdo): array {
    return $pdo->query('SELECT * FROM student ORDER BY last_name, first_name')->fetchAll();
}

function getAllGuards(PDO $pdo): array {
    return $pdo->query('SELECT * FROM guard ORDER BY last_name, first_name')->fetchAll();
}

function getAllOsaAccounts(PDO $pdo): array {
    return $pdo->query('SELECT * FROM osa ORDER BY last_name, first_name')->fetchAll();
}

function accountIdTaken(PDO $pdo, string $role, string $id): bool {
    $columns = [
        'student' => ['student', 'student_number'],
        'guard' => ['guard', 'guard_id'],
        'osa' => ['osa', 'osa_id'],
    ];
    if (!isset($columns[$role])) return true;

    foreach ($columns as [$table, $column]) {
        $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE $column = ? LIMIT 1");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

function createAccountByOsa(PDO $pdo, string $role, string $id, string $firstName, string $lastName, array $details, string $plainPassword, string $performedByOsa): void {
    if (!in_array($role, ['student', 'osa'], true)) {
        throw new InvalidArgumentException('Only student and OSA accounts can be created here.');
    }

    $hash = password_hash($plainPassword, PASSWORD_BCRYPT);

    $pdo->beginTransaction();
    try {
        if ($role === 'student') {
            $stmt = $pdo->prepare(
                'INSERT INTO student (student_number, first_name, last_name, email, program, year_level, status, password_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $firstName, $lastName, $details['email'], $details['program'], $details['year_level'], 'active', $hash]);
            $pdo->prepare('INSERT INTO student_account_log (student_number, action, performed_by_osa) VALUES (?, ?, ?)')
                ->execute([$id, 'created', $performedByOsa]);
        } elseif ($role === 'osa') {
            $pdo->prepare('INSERT INTO osa (osa_id, first_name, last_name, email, position, password_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $firstName, $lastName, $details['email'], $details['position'], $hash]);
        } else {
            throw new InvalidArgumentException('Unsupported account role.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function removeAccountByOsa(PDO $pdo, string $role, string $id, string $performedByOsa): void {
    if (!in_array($role, ['student', 'osa'], true)) {
        throw new InvalidArgumentException('Only student and OSA accounts can be removed here.');
    }
    if ($role === 'osa' && $id === $performedByOsa) {
        throw new RuntimeException('You cannot remove the account you are currently using.');
    }

    $pdo->beginTransaction();
    try {
        if ($role === 'student') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM violation WHERE student_number = ?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('This student cannot be removed because violation records are linked to the account.');
            }
            $pdo->prepare('DELETE FROM student WHERE student_number = ?')->execute([$id]);
            $pdo->prepare('INSERT INTO student_account_log (student_number, action, performed_by_osa) VALUES (?, ?, ?)')
                ->execute([$id, 'deleted', $performedByOsa]);
        } else {
            $pdo->prepare('DELETE FROM osa WHERE osa_id = ?')->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function getCase(PDO $pdo, string $caseId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM violation WHERE case_id = ?');
    $stmt->execute([$caseId]);
    return $stmt->fetch() ?: null;
}

// Registers a student record from a guard's manual entry if that
// student number isn't already on file. Existing records are left
// untouched so a typo'd re-entry can't clobber real student data.
function ensureStudent(PDO $pdo, string $studentNumber, string $fullName, string $program, string $yearLevel): array {
    if ($existing = getStudent($pdo, $studentNumber)) return $existing;

    $parts = preg_split('/\s+/', trim($fullName));
    $lastName = count($parts) > 1 ? array_pop($parts) : '';
    $firstName = $parts ? implode(' ', $parts) : trim($fullName);

    $stmt = $pdo->prepare(
        'INSERT INTO student (student_number, first_name, last_name, email, program, year_level, status, password_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$studentNumber, $firstName, $lastName, '', $program, $yearLevel, 'active', null]);

    return getStudent($pdo, $studentNumber);
}

function violationsForStudent(PDO $pdo, string $studentNumber): array {
    $stmt = $pdo->prepare(
        "SELECT * FROM violation WHERE student_number = ? AND status != 'draft' ORDER BY date_recorded DESC"
    );
    $stmt->execute([$studentNumber]);
    return $stmt->fetchAll();
}

function violationsByGuard(PDO $pdo, string $guardId): array {
    $stmt = $pdo->prepare('SELECT * FROM violation WHERE guard_id = ? ORDER BY date_recorded DESC');
    $stmt->execute([$guardId]);
    return $stmt->fetchAll();
}

function openCases(PDO $pdo): array {
    return $pdo->query(
        "SELECT * FROM violation WHERE status IN ('submitted','open') ORDER BY date_recorded DESC"
    )->fetchAll();
}

function nextCaseId(PDO $pdo): string {
    $max = (int) $pdo->query(
        "SELECT MAX(CAST(SUBSTRING(case_id, 6) AS UNSIGNED)) FROM violation"
    )->fetchColumn();
    $next = ($max ?: 1000) + 1;
    return 'CASE-' . $next;
}

function createDraftViolation(PDO $pdo, string $studentNumber, string $guardId, string $typeId): array {
    $caseId = nextCaseId($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO violation (case_id, student_number, guard_id, osa_id, type_id, details, status, date_recorded, date_submitted)
         VALUES (?, ?, ?, NULL, ?, ?, ?, NOW(), NULL)'
    );
    $stmt->execute([$caseId, $studentNumber, $guardId, $typeId, '', 'draft']);
    return getCase($pdo, $caseId);
}

function submitDraftViolation(PDO $pdo, string $caseId, string $details): ?array {
    $stmt = $pdo->prepare(
        "UPDATE violation SET details = ?, status = 'submitted', date_submitted = NOW() WHERE case_id = ?"
    );
    $stmt->execute([$details, $caseId]);
    return getCase($pdo, $caseId);
}

function discardDraftViolation(PDO $pdo, string $caseId): bool {
    $stmt = $pdo->prepare("DELETE FROM violation WHERE case_id = ? AND status = 'draft'");
    $stmt->execute([$caseId]);
    return $stmt->rowCount() > 0;
}

function openCaseForReview(PDO $pdo, string $caseId, string $osaId): ?array {
    $stmt = $pdo->prepare("UPDATE violation SET status = 'open', osa_id = ? WHERE case_id = ?");
    $stmt->execute([$osaId, $caseId]);
    return getCase($pdo, $caseId);
}

function clearCase(PDO $pdo, string $caseId): ?array {
    $stmt = $pdo->prepare("UPDATE violation SET status = 'cleared' WHERE case_id = ?");
    $stmt->execute([$caseId]);
    return getCase($pdo, $caseId);
}

/* ---------- Compliance deadlines (Google Calendar) ---------- */

// Stores/updates the "fix or clear by" date on a case, plus the linked
// Google Calendar event so a later edit updates it instead of duplicating.
function setCaseDeadline(PDO $pdo, string $caseId, string $dueDate, ?string $eventId, ?string $eventLink): ?array {
    $stmt = $pdo->prepare(
        'UPDATE violation SET due_date = ?, calendar_event_id = ?, calendar_event_link = ? WHERE case_id = ?'
    );
    $stmt->execute([$dueDate, $eventId, $eventLink, $caseId]);
    return getCase($pdo, $caseId);
}

// Removes the deadline from a case (the caller is responsible for also
// deleting the Calendar event first via deleteCaseDeadlineEvent()).
function clearCaseDeadline(PDO $pdo, string $caseId): ?array {
    $stmt = $pdo->prepare(
        'UPDATE violation SET due_date = NULL, calendar_event_id = NULL, calendar_event_link = NULL WHERE case_id = ?'
    );
    $stmt->execute([$caseId]);
    return getCase($pdo, $caseId);
}

function searchStudents(PDO $pdo, string $query): array {
    $needle = '%' . $query . '%';
    $stmt = $pdo->prepare(
        "SELECT * FROM student WHERE student_number LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?
         ORDER BY last_name, first_name"
    );
    $stmt->execute([$needle, $needle]);
    return $stmt->fetchAll();
}

/* ---------- Presentation helpers ---------- */

function fmtDate(?string $ts): string {
    if (!$ts) return '—';
    $d = new DateTime($ts);
    return $d->format('M j, Y') . ' · ' . $d->format('g:i A');
}

function fmtDateOnly(?string $ts): string {
    if (!$ts) return '—';
    $d = new DateTime($ts);
    return $d->format('M j, Y');
}

function pillClass(string $status): string {
    return match ($status) {
        'draft'     => 'pill pill-draft',
        'submitted' => 'pill pill-submitted',
        'open'      => 'pill pill-open',
        default     => 'pill pill-cleared',
    };
}

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}