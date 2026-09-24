<?php
/*
    two_factor_enroll.php

    #26 Two-Factor Authentication - self-service enrollment for the
    current user, restricted to Administrator/IT Administrator (the
    "higher-privilege" accounts the spec targets). Flow:

      1. GET with no enrollment in progress: generate a secret, stash
         it in the session (NOT the database yet), show the manual
         entry key and ask for a code to confirm the authenticator is
         actually set up correctly.
      2. POST verify: check the submitted code against the pending
         secret. Only on success does the secret get written to
         users.two_factor_secret and two_factor_enabled flip to 1 -
         so a failed/abandoned enrollment never leaves a half-enabled
         account.
      3. On success, generate recovery codes, store their hashes, and
         show the plaintext codes exactly once.

    This page only ever touches the current user's own account -
    enrolling someone else is out of scope by design (2FA proves the
    account owner possesses their own authenticator).
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "totp_helpers.php";
require_once "activity_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("
    SELECT id, first_name, last_name, username, role, department,
           two_factor_enabled, two_factor_enrolled_at
    FROM users
    WHERE id = ? AND active = 1
");
$me_stmt->bind_param("i", $user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
$me_stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

$role = $me["role"];

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

if (!in_array($role, ["Administrator", "IT Administrator"], true)) {
    http_response_code(403);
    echo "Two-factor authentication is currently offered for Administrator and IT Administrator accounts.";
    exit;
}

$error = "";
$recovery_codes_to_show = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();

    $submitted_action = $_POST["action"] ?? "";

    if ($submitted_action === "verify" && isset($_SESSION["2fa_enroll_secret"])) {
        $pending_secret = $_SESSION["2fa_enroll_secret"];
        $code = $_POST["code"] ?? "";

        if (verifyTotpCode($pending_secret, $code)) {

            $update_stmt = $conn->prepare("
                UPDATE users
                SET two_factor_secret = ?, two_factor_enabled = 1, two_factor_enrolled_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->bind_param("si", $pending_secret, $user_id);
            $update_stmt->execute();
            $update_stmt->close();

            // Replace any old recovery codes from a previous enrollment.
            $delete_stmt = $conn->prepare("DELETE FROM two_factor_recovery_codes WHERE user_id = ?");
            $delete_stmt->bind_param("i", $user_id);
            $delete_stmt->execute();
            $delete_stmt->close();

            $plain_codes = generateRecoveryCodes(10);
            $insert_stmt = $conn->prepare("INSERT INTO two_factor_recovery_codes (user_id, code_hash) VALUES (?, ?)");
            foreach ($plain_codes as $plain_code) {
                $hash = hashRecoveryCode($plain_code);
                $insert_stmt->bind_param("is", $user_id, $hash);
                $insert_stmt->execute();
            }
            $insert_stmt->close();

            logActivity(
                $conn,
                $user_id,
                "2FA Enrolled",
                "Security",
                $user_id,
                "Enabled two-factor authentication.",
                "settings.php"
            );

            unset($_SESSION["2fa_enroll_secret"]);
            $recovery_codes_to_show = $plain_codes;
            $me["two_factor_enabled"] = 1;

        } else {
            $error = "That code didn't match. Make sure your authenticator app's clock is correct and try the current code again.";
        }

    } elseif ($submitted_action === "disable") {

        $update_stmt = $conn->prepare("
            UPDATE users
            SET two_factor_secret = NULL, two_factor_enabled = 0, two_factor_enrolled_at = NULL
            WHERE id = ?
        ");
        $update_stmt->bind_param("i", $user_id);
        $update_stmt->execute();
        $update_stmt->close();

        $delete_stmt = $conn->prepare("DELETE FROM two_factor_recovery_codes WHERE user_id = ?");
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        logActivity(
            $conn,
            $user_id,
            "2FA Disabled",
            "Security",
            $user_id,
            "Disabled two-factor authentication.",
            "settings.php"
        );

        unset($_SESSION["2fa_enroll_secret"]);
        header("Location: settings.php");
        exit;
    }
}

if (!$me["two_factor_enabled"] && !$recovery_codes_to_show && !isset($_SESSION["2fa_enroll_secret"])) {
    $_SESSION["2fa_enroll_secret"] = generateTotpSecret();
}

$pending_secret = $_SESSION["2fa_enroll_secret"] ?? null;
$provisioning_uri = $pending_secret
    ? totpProvisioningUri($pending_secret, $me["username"] ?: $me["first_name"] . " " . $me["last_name"])
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Two-Factor Authentication</title>

<link rel="stylesheet" href="app.css?v=22">

</head>
<body>

<div class="page">

<div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div>
        <div class="sidebar-item"><a href="tasks.php">Tasks</a></div>
        <div class="sidebar-item"><a href="projects.php">Projects</a></div>
        <div class="sidebar-item"><a href="calendar.php">Calendar</a></div>
        <div class="sidebar-item"><a href="events.php">Meetings / Events</a></div>
        <div class="sidebar-item"><a href="teams.php">Teams</a></div>
        <div class="sidebar-item"><a href="org_chart.php">Organization Chart</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="analytics.php">Analytics</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="timesheets.php">Timesheets</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item"><a href="announcements.php">Announcements</a></div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
        <div class="sidebar-item"><a href="roles.php">Roles &amp; Permissions</a></div>
        <?php endif; ?>

        <div class="sidebar-bottom">
        <div class="sidebar-item"><a href="account.php">Account</a></div>
        <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
</div>

<div class="main">

    <div class="header">
        <div>
            <h1>TWO-FACTOR AUTHENTICATION</h1>
            <div class="sub">Protects your account with a second step at login</div>
        </div>
        <a class="button" href="settings.php">BACK TO SETTINGS</a>
    </div>

    <div class="content">

        <?php if ($recovery_codes_to_show): ?>

            <div class="card">
                <h2>Two-Factor Authentication Enabled</h2>
                <div class="message">Your authenticator app is now connected. Save these recovery codes somewhere safe and separate from your password - each one works once, and lets you sign in if you ever lose access to your authenticator.</div>
                <div class="table-card">
                    <table>
                        <tbody>
                            <?php foreach ($recovery_codes_to_show as $rc): ?>
                                <tr><td><code><?php echo e($rc); ?></code></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="warning">These codes are shown only this once and cannot be displayed again - if you lose them, an Administrator can reset your 2FA so you can re-enroll.</div>
            </div>

        <?php elseif ($me["two_factor_enabled"]): ?>

            <div class="card">
                <h2>Status: Enabled</h2>
                <p>Enrolled <?php echo $me["two_factor_enrolled_at"] ? e(date("F j, Y", strtotime($me["two_factor_enrolled_at"]))) : ""; ?>. You'll be asked for a code from your authenticator app each time you log in.</p>
                <form method="POST" onsubmit="return confirm('Disable two-factor authentication for your account?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="disable">
                    <button class="button" type="submit">DISABLE TWO-FACTOR AUTHENTICATION</button>
                </form>
            </div>

        <?php else: ?>

            <div class="card">
                <h2>Set Up Your Authenticator</h2>
                <p>1. Open your authenticator app (Google Authenticator, Authy, 1Password, etc.) and add a new account manually.</p>
                <p>2. Enter this key exactly as shown:</p>
                <p><code style="font-size:1.1em; letter-spacing:1px;"><?php echo e(chunk_split($pending_secret, 4, " ")); ?></code></p>
                <p class="help">Account name: <?php echo e($me["username"] ?: $me["first_name"] . " " . $me["last_name"]); ?> &middot; Issuer: WorkPlatform &middot; Type: Time-based &middot; Digits: 6</p>

                <?php if ($error): ?>
                    <div class="error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <p>3. Enter the 6-digit code your app is now showing to confirm setup:</p>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="verify">
                    <div class="form-row">
                        <label for="code">Verification Code</label>
                        <input type="text" id="code" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
                    </div>
                    <div class="actions">
                        <button class="button primary" type="submit">VERIFY & ENABLE</button>
                    </div>
                </form>
            </div>

        <?php endif; ?>

    </div>

</div>

</div>

</body>
</html>
