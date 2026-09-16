<?php
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
require "db.php";
require_once "role_helpers.php";
$user_id = (int)$_SESSION["user_id"];
$message = "";
$stmt = $conn->prepare("SELECT id, username, first_name, last_name, email, department, role, active, password FROM users WHERE id = ? AND active = 1 LIMIT 1");
$stmt->bind_param("i", $user_id); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$user) { header("Location: login.php"); exit; }
$role = $user["role"];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $current_password = $_POST["current_password"] ?? "";
    $email_is_changing = ($email !== $user["email"]);

    if ($first_name === "" || $last_name === "" || $email === "") {
        $message = "First name, last name, and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Enter a valid email address.";
    } elseif ($email_is_changing && $current_password === "") {
        $message = "Enter your current password to change your email address.";
    } elseif ($email_is_changing && !password_verify($current_password, $user["password"])) {
        $message = "Current password is incorrect.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $stmt->bind_param("si", $email, $user_id); $stmt->execute(); $duplicate = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($duplicate) {
            $message = "That email address is already in use.";
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");
            $stmt->bind_param("sssi", $first_name, $last_name, $email, $user_id);
            $stmt->execute(); $stmt->close();
            $_SESSION["first_name"] = $first_name;
            $_SESSION["last_name"] = $last_name;
            header("Location: account.php"); exit;
        }
    }
    $user["first_name"] = $first_name;
    $user["last_name"] = $last_name;
    $user["email"] = $email;
}
function e($v){ return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Edit Account</title>

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
<header class="header"><div><h1>Edit Account</h1><div>Update your personal information</div></div><strong><?php echo e($role); ?></strong></header>
<div class="content">
<?php if ($message !== ""): ?><div class="message error"><?php echo e($message); ?></div><?php endif; ?>
<div class="card"><div class="card-header"><h2>Personal Information</h2></div><div class="card-body">
<form method="POST">
<div class="form-row"><label>Username</label><input class="readonly" type="text" value="<?php echo e($user["username"]); ?>" readonly><div class="help">Username changes are handled through User Management.</div></div>
<div class="grid">
<div class="form-row"><label for="first_name">First Name</label><input id="first_name" name="first_name" type="text" maxlength="100" required value="<?php echo e($user["first_name"]); ?>"></div>
<div class="form-row"><label for="last_name">Last Name</label><input id="last_name" name="last_name" type="text" maxlength="100" required value="<?php echo e($user["last_name"]); ?>"></div>
</div>
<div class="form-row"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="255" required value="<?php echo e($user["email"]); ?>"></div>
<div class="form-row"><label for="current_password">Current Password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password"><div class="help">Only required if you're changing your email address.</div></div>
<div class="grid">
<div class="form-row"><label>Department</label><input class="readonly" type="text" value="<?php echo e($user["department"]); ?>" readonly></div>
<div class="form-row"><label>Role</label><input class="readonly" type="text" value="<?php echo e($user["role"]); ?>" readonly></div>
</div>
<div class="help" style="margin-bottom:14px;">Department and role are controlled through User Management.</div>
<div class="actions"><button class="button" type="submit">SAVE CHANGES</button><a class="button" href="account.php">CANCEL</a></div>
</form></div></div></div></main></div></body></html>