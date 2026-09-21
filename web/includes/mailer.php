<?php
/* ============================================
   UVMS — outgoing email
   Thin wrapper around PHPMailer configured for Gmail SMTP.
   Every notification in the app (welcome, password reset,
   violation logged, new case for OSA) goes through sendAppEmail().

   Failures here are LOGGED, never fatal — a bad Gmail password
   or a dropped connection should never break the page the user
   is on (submitting a violation, signing up, etc).
   ============================================ */

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/../mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// True once the user has actually edited mail_config.php.
function mailIsConfigured(): bool {
    return GMAIL_USER !== 'your-address@gmail.com'
        && trim(str_replace(' ', '', GMAIL_APP_PASSWORD)) !== ''
        && trim(str_replace(' ', '', GMAIL_APP_PASSWORD)) !== 'xxxxxxxxxxxxxxxx';
}

// Sends one HTML email via Gmail SMTP. Returns true/false; never throws.
function sendAppEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    if (!mailIsConfigured()) {
        error_log("UVMS mail: skipped '$subject' to $toEmail — mail_config.php not filled in yet.");
        return false;
    }
    if (trim($toEmail) === '') {
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = GMAIL_USER;
        $mail->Password   = str_replace(' ', '', GMAIL_APP_PASSWORD); // app passwords work with/without spaces
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(GMAIL_USER, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('UVMS mail: send failed — ' . $mail->ErrorInfo);
        return false;
    }
}

/* ---------- Shared email layout ---------- */

function emailLayout(string $title, string $bodyHtml): string {
    return '
    <div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px 28px;border:1px solid #e2e2e2;border-radius:8px;">
      <div style="font-size:13px;letter-spacing:1px;color:#8a1c1c;font-weight:700;text-transform:uppercase;">UVMS</div>
      <h2 style="margin:8px 0 20px;color:#1a1a1a;">' . htmlspecialchars($title) . '</h2>
      <div style="font-size:14.5px;line-height:1.6;color:#333;">' . $bodyHtml . '</div>
      <hr style="margin:28px 0 16px;border:none;border-top:1px solid #eee;">
      <p style="font-size:12px;color:#999;">Automated message from UVMS — Office of Student Affairs. Please do not reply to this email.</p>
    </div>';
}

/* ---------- Notification templates ---------- */

function sendWelcomeEmail(string $email, string $fullName, string $studentNumber): bool {
    $body = "<p>Hi " . htmlspecialchars($fullName) . ",</p>
        <p>Your UVMS account has been created successfully.</p>
        <p><strong>Student number:</strong> " . htmlspecialchars($studentNumber) . "<br>
        <strong>Login email:</strong> " . htmlspecialchars($email) . "</p>
        <p>You can now log in to check your conduct record and any violation cases on file.</p>";
    return sendAppEmail($email, $fullName, 'Welcome to UVMS — account created', emailLayout('Account created successfully', $body));
}

function sendPasswordResetEmail(string $email, string $fullName, string $resetLink): bool {
    $body = "<p>Hi " . htmlspecialchars($fullName) . ",</p>
        <p>We received a request to reset your UVMS password. Click the button below to choose a new one. This link expires in 30 minutes.</p>
        <p style=\"margin:24px 0;\"><a href=\"" . htmlspecialchars($resetLink) . "\" style=\"background:#8a1c1c;color:#fff;padding:12px 22px;border-radius:4px;text-decoration:none;font-weight:600;\">Reset your password</a></p>
        <p>If you didn't request this, you can safely ignore this email — your password won't be changed.</p>";
    return sendAppEmail($email, $fullName, 'Reset your UVMS password', emailLayout('Reset your password', $body));
}

function sendViolationLoggedEmail(string $email, string $fullName, string $caseId, string $violationName, string $dateRecorded): bool {
    $body = "<p>Hi " . htmlspecialchars($fullName) . ",</p>
        <p>A violation has been recorded under your name and is now on file with the Office of Student Affairs.</p>
        <table style=\"width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;\">
          <tr><td style=\"padding:6px 0;color:#777;width:140px;\">Case ID</td><td style=\"padding:6px 0;\"><strong>" . htmlspecialchars($caseId) . "</strong></td></tr>
          <tr><td style=\"padding:6px 0;color:#777;\">Violation</td><td style=\"padding:6px 0;\">" . htmlspecialchars($violationName) . "</td></tr>
          <tr><td style=\"padding:6px 0;color:#777;\">Date recorded</td><td style=\"padding:6px 0;\">" . htmlspecialchars($dateRecorded) . "</td></tr>
        </table>
        <p>This case is now with OSA for review. Log in to UVMS to view full details and its current status.</p>";
    return sendAppEmail($email, $fullName, "Violation recorded — Case $caseId", emailLayout('Violation recorded on your account', $body));
}

function sendDeadlineSetEmail(string $email, string $fullName, string $caseId, string $violationName, string $dueDate): bool {
    $body = "<p>Hi " . htmlspecialchars($fullName) . ",</p>
        <p>The Office of Student Affairs has set a deadline for your UVMS case.</p>
        <table style=\"width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;\">
          <tr><td style=\"padding:6px 0;color:#777;width:140px;\">Case ID</td><td style=\"padding:6px 0;\"><strong>" . htmlspecialchars($caseId) . "</strong></td></tr>
          <tr><td style=\"padding:6px 0;color:#777;\">Violation</td><td style=\"padding:6px 0;\">" . htmlspecialchars($violationName) . "</td></tr>
          <tr><td style=\"padding:6px 0;color:#777;\">Deadline</td><td style=\"padding:6px 0;\"><strong>" . htmlspecialchars($dueDate) . "</strong></td></tr>
        </table>
        <p>Please log in to UVMS and complete the required action by the deadline.</p>";
    return sendAppEmail($email, $fullName, "Deadline set — Case $caseId", emailLayout('A deadline was set for your case', $body));
}

function sendNewCaseEmailToOsa(string $email, string $osaName, string $caseId, string $studentName, string $violationName): bool {
    $body = "<p>Hi " . htmlspecialchars($osaName) . ",</p>
        <p>A new violation case has been submitted by a guard and is awaiting OSA review.</p>
        <table style=\"width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;\">
          <tr><td style=\"padding:6px 0;color:#777;width:140px;\">Case ID</td><td style=\"padding:6px 0;\"><strong>" . htmlspecialchars($caseId) . "</strong></td></tr>
          <tr><td style=\"padding:6px 0;color:#777;\">Student</td><td style=\"padding:6px 0;\">" . htmlspecialchars($studentName) . "</td></tr>
          <tr><td style=\"padding:6px 0;color:#777;\">Violation</td><td style=\"padding:6px 0;\">" . htmlspecialchars($violationName) . "</td></tr>
        </table>
        <p>Log in to UVMS to open and review this case.</p>";
    return sendAppEmail($email, $osaName, "New case for review — $caseId", emailLayout('New case submitted for review', $body));
}
