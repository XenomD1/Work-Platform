<?php
/*
    two_factor_verify.php

    #26 Two-Factor Authentication - the second login step. This page
    expects login.php to have already checked the username/password
    and, for an account with two_factor_enabled = 1, set
    $_SESSION["2fa_pending_user_id"] (and NOT $_SESSION["user_id"] -
    the whole point is that password-only is not "logged in" for a
    protected account) before redirecting here instead of to
    dashboard.php.

    I don't have login.php's source, so I haven't modified it
    directly - seeing it would let this be wired in exactly rather
    than guessed. The integration point is small: right after
    login.php confirms the password is correct, look up the user's
    two_factor_enabled flag and branch:

        if ($user["two_factor_enabled"]) {
            $_SESSION["2fa_pending_user_id"] = $user["id"];
            header("Location: two_factor_verify.php");
            exit;
        } else {
            $_SESSION["user_id"] = $user["id"];
            header("Location: dashboard.php");
            exit;
        }

    Everything past that point - verifying the code, establishing the
    real session, handling recovery codes - is handled entirely by
    this file.
*/

session_start();

if (!isset($_SESSION["2fa_pending_user_id"])) {
    // Nothing pending - either already logged in, or arrived here
    // directly without a password check. Either way this isn't a
    // valid state to show a code-entry form for.
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "csrf_helpers.php";
require_once "totp_helpers.php";
require_once "activity_helpers.php";

$pending_user_id = (int)$_SESSION["2fa_pending_user_id"];

$user_stmt = $conn->prepare("
    SELECT id, first_name, two_factor_secret, two_factor_enabled
    FROM users
    WHERE id = ? AND active = 1
");
$user_stmt->bind_param("i", $pending_user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$user || !$user["two_factor_enabled"] || !$user["two_factor_secret"]) {
    // Account state changed since the password step (deactivated,
    // 2FA turned off by an admin reset, etc.) - don't let a stale
    // pending flag grant access either way.
    unset($_SESSION["2fa_pending_user_id"]);
    header("Location: login.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$error = "";

// Very small rate limit against brute-forcing 6-digit codes: after 5
// failed attempts in this pending session, force back to the login
// form (which re-establishes the password step).
$_SESSION["2fa_attempts"] = $_SESSION["2fa_attempts"] ?? 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();

    if ($_SESSION["2fa_attempts"] >= 5) {
        unset($_SESSION["2fa_pending_user_id"], $_SESSION["2fa_attempts"]);
        header("Location: login.php?2fa_locked=1");
        exit;
    }

    $mode = $_POST["mode"] ?? "code";

    $verified = false;

    if ($mode === "recovery") {
        $recovery_code = trim($_POST["recovery_code"] ?? "");
        if ($recovery_code !== "") {
            $hash = hashRecoveryCode($recovery_code);
            $rc_stmt = $conn->prepare("
                SELECT id
                FROM two_factor_recovery_codes
                WHERE user_id = ? AND code_hash = ? AND used_at IS NULL
                LIMIT 1
            ");
            $rc_stmt->bind_param("is", $pending_user_id, $hash);
            $rc_stmt->execute();
            $rc_row = $rc_stmt->get_result()->fetch_assoc();
            $rc_stmt->close();

            if ($rc_row) {
                $mark_used_stmt = $conn->prepare("UPDATE two_factor_recovery_codes SET used_at = NOW() WHERE id = ?");
                $mark_used_stmt->bind_param("i", $rc_row["id"]);
                $mark_used_stmt->execute();
                $mark_used_stmt->close();
                $verified = true;

                logActivity(
                    $conn,
                    $pending_user_id,
                    "2FA Recovery Code Used",
                    "Security",
                    $pending_user_id,
                    "Logged in using a two-factor recovery code.",
                    "settings.php"
                );
            }
        }
    } else {
        $code = $_POST["code"] ?? "";
        $verified = verifyTotpCode($user["two_factor_secret"], $code);
    }

    if ($verified) {
        unset($_SESSION["2fa_pending_user_id"], $_SESSION["2fa_attempts"]);
        $_SESSION["user_id"] = $pending_user_id;
        $_SESSION["2fa_verified"] = true;
        header("Location: dashboard.php");
        exit;
    }

    $_SESSION["2fa_attempts"]++;
    $error = $mode === "recovery"
        ? "That recovery code wasn't recognized or has already been used."
        : "That code didn't match. Codes refresh every 30 seconds - try the current one.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Your Identity</title>
<link rel="stylesheet" href="app.css?v=22">
</head>
<body>

<div class="page" style="display:flex; align-items:center; justify-content:center; min-height:100vh;">

<div class="card" style="max-width:420px; width:100%;">
    <h1>TWO-FACTOR VERIFICATION</h1>
    <p>Hi <?php echo e($user["first_name"]); ?>, enter the current code from your authenticator app to finish signing in.</p>

    <?php if ($error): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="mode" value="code">
        <div class="form-row">
            <label for="code">Verification Code</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
        </div>
        <div class="actions">
            <button class="button primary" type="submit">VERIFY</button>
        </div>
    </form>

    <details style="margin-top:16px;">
        <summary>Use a recovery code instead</summary>
        <form method="POST" style="margin-top:10px;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="mode" value="recovery">
            <div class="form-row">
                <label for="recovery_code">Recovery Code</label>
                <input type="text" id="recovery_code" name="recovery_code" placeholder="XXXXX-XXXXX" required>
            </div>
            <div class="actions">
                <button class="button" type="submit">USE RECOVERY CODE</button>
            </div>
        </form>
    </details>

    <p class="help" style="margin-top:16px;">Lost access to both your authenticator and your recovery codes? Contact an Administrator to reset two-factor authentication on your account.</p>
</div>

</div>

</body>
</html>
