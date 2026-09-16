<?php
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
require "db.php";
require_once "role_helpers.php";
$user_id = (int)$_SESSION["user_id"];
$message = "";
$stmt = $conn->prepare("SELECT id, password, role FROM users WHERE id = ? AND active = 1 LIMIT 1");
$stmt->bind_param("i", $user_id); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$user) { header("Location: login.php"); exit; }
$role = $user["role"];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $current_password = $_POST["current_password"] ?? "";
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";
    if ($current_password === "" || $new_password === "" || $confirm_password === "") {
        $message = "All password fields are required.";
    } elseif (!password_verify($current_password, $user["password"])) {
        $message = "Current password is incorrect.";
    } elseif (strlen($new_password) < 8) {
        $message = "New password must be at least 8 characters.";
    } elseif ($new_password !== $confirm_password) {
        $message = "New password and confirmation do not match.";
    } elseif (password_verify($new_password, $user["password"])) {
        $message = "New password must be different from your current password.";
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $user_id); $stmt->execute(); $stmt->close();
        session_regenerate_id(true);
        header("Location: account.php"); exit;
    }
}
function e($v){ return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Change Password</title>

<link rel="stylesheet" href="app.css?v=20">

</head><body><div class="page"><aside class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div>
        <div class="sidebar-item"><a href="tasks.php">Tasks</a></div>
        <div class="sidebar-item"><a href="projects.php">Projects</a></div>
        <div class="sidebar-item"><a href="calendar.php">Calendar</a></div>
        <div class="sidebar-item"><a href="events.php">Meetings / Events</a></div>
        <div class="sidebar-item"><a href="teams.php">Teams</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>
        <?php if (isAnyAdministrator($role)): ?>
            <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>
        <div class="sidebar-bottom">
            <div class="sidebar-item active"><a href="account.php">Account</a></div>
            <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
</aside><main class="main">
<header class="header"><div><h1>Change Password</h1><div>Update your account password</div></div><strong><?php echo e($role); ?></strong></header>
<div class="content">
<?php if ($message !== ""): ?><div class="message error"><?php echo e($message); ?></div><?php endif; ?>
<div class="card"><div class="card-header"><h2>Password</h2></div><div class="card-body">
<form method="POST">
<div class="form-row"><label for="current_password">Current Password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
<div class="form-row"><label for="new_password">New Password</label><input id="new_password" name="new_password" type="password" minlength="8" required autocomplete="new-password"><div class="help">Minimum 8 characters.</div></div>
<div class="form-row"><label for="confirm_password">Confirm New Password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
<div class="actions"><button class="button" type="submit">CHANGE PASSWORD</button><a class="button" href="account.php">CANCEL</a></div>
</form></div></div></div></main></div></body></html>