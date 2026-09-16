<?php
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit; }
require "db.php";
require_once "role_helpers.php";
$user_id = (int)$_SESSION["user_id"];
$stmt = $conn->prepare("SELECT id, username, first_name, last_name, email, department, role, active, created_at FROM users WHERE id = ? AND active = 1 LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header("Location: login.php"); exit; }
$role = $user["role"];
function e($v){ return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Account</title>

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
            <div class="sidebar-item"><a href="logout.php">Logout</a></div>
        </div>
</aside><main class="main">
<header class="header"><div><h1>Account</h1><div>Your personal account information</div></div><strong><?php echo e($role); ?></strong></header>
<div class="content">
<div class="card"><div class="card-header"><h2>Profile</h2><div class="actions"><a class="button" href="edit_account.php">EDIT ACCOUNT</a><a class="button" href="change_password.php">CHANGE PASSWORD</a></div></div>
<div class="card-body"><div class="grid">
<div class="field"><div class="label">USERNAME</div><div class="value"><?php echo e($user["username"]); ?></div></div>
<div class="field"><div class="label">EMAIL</div><div class="value"><?php echo e($user["email"]); ?></div></div>
<div class="field"><div class="label">FIRST NAME</div><div class="value"><?php echo e($user["first_name"]); ?></div></div>
<div class="field"><div class="label">LAST NAME</div><div class="value"><?php echo e($user["last_name"]); ?></div></div>
<div class="field"><div class="label">DEPARTMENT</div><div class="value"><?php echo e($user["department"]); ?></div></div>
<div class="field"><div class="label">ROLE</div><div class="value"><?php echo e($user["role"]); ?></div></div>
<div class="field"><div class="label">ACCOUNT STATUS</div><div class="value"><?php echo (int)$user["active"] === 1 ? "Active" : "Inactive"; ?></div></div>
<div class="field"><div class="label">ACCOUNT CREATED</div><div class="value"><?php echo e($user["created_at"]); ?></div></div>
</div></div></div></div></main></div></body></html>