<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

$current_user_stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
$current_user_stmt->bind_param("i", $current_user_id);
$current_user_stmt->execute();
$current_user = $current_user_stmt->get_result()->fetch_assoc();
$current_user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

if (!isAnyAdministrator($current_user["role"])) {
    die("Access denied.");
}

$current_role = $current_user["role"];
$user_department = $current_user["department"] ?? "";

$users = [];

if ($current_role === "Administrator") {

    $result = $conn->query("
        SELECT
            id,
            username,
            first_name,
            last_name,
            email,
            department,
            role,
            active,
            created_at
        FROM users
        ORDER BY last_name ASC, first_name ASC
    ");

    while ($user = $result->fetch_assoc()) {
        $users[] = $user;
    }

} else {

    // IT Administrator: only users in their own department.
    $stmt = $conn->prepare("
        SELECT
            id,
            username,
            first_name,
            last_name,
            email,
            department,
            role,
            active,
            created_at
        FROM users
        WHERE department = ?
        ORDER BY last_name ASC, first_name ASC
    ");
    $stmt->bind_param("s", $user_department);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($user = $result->fetch_assoc()) {
        $users[] = $user;
    }

    $stmt->close();
}

?>

<!DOCTYPE html>
<html>

<head>

    <meta charset="UTF-8">

    <title>User Management</title>

    <link rel="stylesheet" href="app.css?v=19">

</head>

<body>

<div class="page">

<div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item">
            <a href="dashboard.php">Dashboard</a>
        </div>
        <div class="sidebar-item">
            <a href="tasks.php">Tasks</a>
        </div>
        <div class="sidebar-item">
            <a href="projects.php">Projects</a>
        </div>
        <div class="sidebar-item">
            <a href="calendar.php">Calendar</a>
        </div>
        <div class="sidebar-item">
            <a href="events.php">Meetings / Events</a>
        </div>
        <div class="sidebar-item">
            <a href="teams.php">Teams</a>
        </div>
        <div class="sidebar-item">
            <a href="org_chart.php">Organization Chart</a>
        </div>
        <div class="sidebar-item">
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
            <a href="analytics.php">Analytics</a>
        </div>
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item">
            <a href="time_off.php">Time Off</a>
        </div>
        <div class="sidebar-item">
            <a href="timesheets.php">Timesheets</a>
        </div>
        <div class="sidebar-item">
            <a href="notifications.php">Notifications</a>
        </div>

        <?php if (canPostAnnouncements($current_role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($current_role)): ?>
        <div class="sidebar-item active">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($current_role === "Administrator"): ?>
        <div class="sidebar-item">
            <a href="roles.php">Roles &amp; Permissions</a>
        </div>
        <?php endif; ?>

        <div class="sidebar-bottom">
            <div class="sidebar-item"><a href="account.php">Account</a></div>
            <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
</div>

<main class="main">

    <div class="header">

        <h1>User Management</h1>

        <div>
            <a class="button primary" href="create_user.php">
                Create User
            </a>
        </div>

    </div>

    <div class="content">

    <table>

        <tr>
            <th>Name</th>
            <th>Username</th>
            <th>Email</th>
            <th>Department</th>
            <th>Role</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>

        <?php foreach ($users as $user): ?>

            <tr>

                <td>
                    <?= htmlspecialchars(
                        $user["first_name"] . " " . $user["last_name"]
                    ) ?>
                </td>

                <td>
                    <?= htmlspecialchars($user["username"]) ?>
                </td>

                <td>
                    <?= htmlspecialchars($user["email"]) ?>
                </td>

                <td>
                    <?= htmlspecialchars($user["department"]) ?>
                </td>

                <td>
                    <?= htmlspecialchars($user["role"]) ?>
                </td>

                <td class="<?= $user["active"] ? "active" : "inactive" ?>">
                    <?= $user["active"] ? "Active" : "Inactive" ?>
                </td>

		<td>
    		    <a href="edit_user.php?id=<?= $user["id"] ?>">Edit</a>
		</td>

            </tr>

        <?php endforeach; ?>

    </table>

    </div>

</main>

</div>

</body>

</html>
