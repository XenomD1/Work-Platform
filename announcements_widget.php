<?php
/*
    announcements_widget.php

    Demonstrates how to pull the #27 Announcements widget into
    dashboard.php. dashboard.php's own source wasn't available to
    edit in place, so instead of guessing at its layout and risking
    breaking it, this shows the two lines that need to go there:

        require_once "announcement_helpers.php";
        echo renderAnnouncementsWidget($conn, $current_role, $current_department);

    ...called with the SAME re-fetched role/department variables
    dashboard.php already uses for its own permission checks (not
    $_SESSION["role"]/$_SESSION["department"] directly), placed
    wherever on the dashboard the announcements should appear.

    This file itself is a small standalone page that renders the
    widget on its own, in case you want to see it live before wiring
    it into dashboard.php, or want to link to it directly.
*/

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("SELECT role, department FROM users WHERE id = ? AND active = 1");
$me_stmt->bind_param("i", $user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
$me_stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

$role = $me["role"];
$department = $me["department"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements Widget Preview</title>
<link rel="stylesheet" href="app.css?v=22">
</head>
<body>

<div class="page">

<div class="main" style="margin:0;width:100%;">
    <div class="header">
        <div>
            <h1>ANNOUNCEMENTS WIDGET PREVIEW</h1>
            <div class="sub">This is what renderAnnouncementsWidget() outputs for your account right now</div>
        </div>
        <a class="button" href="announcements.php">MANAGE ANNOUNCEMENTS</a>
    </div>
    <div class="content">
        <?php echo renderAnnouncementsWidget($conn, $role, $department) ?: '<div class="empty">No announcements currently eligible to display.</div>'; ?>
    </div>
</div>

</div>

</body>
</html>
