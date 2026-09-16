<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];
$user_department = $current_user["department"];
$current_full_name = trim(
    $current_user["first_name"] . " " . $current_user["last_name"]
);

$team_id = (int)($_GET["id"] ?? 0);

if ($team_id <= 0) {
    header("Location: teams.php");
    exit;
}

/*
 * Load team.
 */
$team_stmt = $conn->prepare("
    SELECT
        t.id,
        t.project_id,
        t.name,
        t.description,
        t.responsibilities,
        t.team_lead_id,
        t.status,
        t.created_by,
        t.created_at,
        t.updated_at,

        p.name AS project_name,
        p.department AS project_department,
        p.manager AS project_manager,
        p.manager_id AS project_manager_id,

        CONCAT(lead_user.first_name, ' ', lead_user.last_name) AS team_lead_name,

        CONCAT(created_user.first_name, ' ', created_user.last_name) AS created_by_name

    FROM teams t

    INNER JOIN projects p
        ON p.id = t.project_id

    INNER JOIN users lead_user
        ON lead_user.id = t.team_lead_id

    INNER JOIN users created_user
        ON created_user.id = t.created_by

    WHERE t.id = ?
");

$team_stmt->bind_param("i", $team_id);
$team_stmt->execute();
$team = $team_stmt->get_result()->fetch_assoc();
$team_stmt->close();

if (!$team) {
    header("Location: teams.php");
    exit;
}

/*
 * Load team members.
 */
$members = [];

$member_stmt = $conn->prepare("
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.department
    FROM team_members tm
    INNER JOIN users u
        ON u.id = tm.user_id
    WHERE tm.team_id = ?
    ORDER BY u.last_name, u.first_name
");

$member_stmt->bind_param("i", $team_id);
$member_stmt->execute();
$member_result = $member_stmt->get_result();

while ($row = $member_result->fetch_assoc()) {
    $members[] = $row;
}

$member_stmt->close();

/*
 * Departments represented.
 */
$departments = [];

foreach ($members as $member) {
    $department = trim($member["department"] ?? "");

    if ($department !== "" && !in_array($department, $departments, true)) {
        $departments[] = $department;
    }
}

sort($departments);

/*
 * Visibility:
 * Administrator -> any team
 * Manager -> project in own department
 * Project Manager -> teams for their own project
 * Employee -> teams they belong to
 */
$is_member = false;

foreach ($members as $member) {
    if ((int)$member["id"] === $user_id) {
        $is_member = true;
        break;
    }
}

$can_view = false;

if ($role === "Administrator") {
    $can_view = true;
} elseif (
    ($role === "Manager" || $role === "IT Administrator")
    && $team["project_department"] === $user_department
) {
    $can_view = true;
} elseif (
    $team["project_manager_id"] !== null
    && (int)$team["project_manager_id"] === $user_id
) {
    $can_view = true;
} elseif ($is_member) {
    $can_view = true;
}

if (!$can_view) {
    header("Location: teams.php");
    exit;
}

/*
 * Edit permission:
 * Administrator
 * Manager for project department
 * Project Manager for that project
 */
$can_edit = false;

if ($role === "Administrator") {
    $can_edit = true;
} elseif (
    ($role === "Manager" || $role === "IT Administrator")
    && $team["project_department"] === $user_department
) {
    $can_edit = true;
} elseif (
    $team["project_manager_id"] !== null
    && (int)$team["project_manager_id"] === $user_id
) {
    $can_edit = true;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>View Team</title>

<link rel="stylesheet" href="app.css?v=12">

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
        <div class="sidebar-item active">
            <a href="teams.php">Teams</a>
        </div>
        <div class="sidebar-item">
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item">
            <a href="time_off.php">Time Off</a>
        </div>
        <div class="sidebar-item">
            <a href="notifications.php">Notifications</a>
        </div>

        <?php if (isAnyAdministrator($_SESSION["role"] ?? "")): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <div class="sidebar-bottom">
        <div class="sidebar-item">
            <a href="account.php">Account</a>
        </div>
        <div class="sidebar-item">
            <a href="settings.php">Settings</a>
        </div>
        </div>
    </div>

<div class="main">

    <div class="header">

        <div>
            <h1><?php echo e($team["name"]); ?></h1>

            <div class="sub">
                Project:
                <?php echo e($team["project_name"]); ?>
                &nbsp;·&nbsp;
                <span class="status">
                    <?php echo e($team["status"]); ?>
                </span>
            </div>
        </div>

        <div class="header-actions">

            <a class="button" href="teams.php">
                BACK TO TEAMS
            </a>

            <a
                class="button"
                href="projects.php?project=<?php echo (int)$team["project_id"]; ?>"
            >
                VIEW PROJECT
            </a>

            <?php if ($can_edit): ?>

                <a
                    class="button primary"
                    href="edit_team.php?id=<?php echo (int)$team["id"]; ?>"
                >
                    EDIT TEAM
                </a>

            <?php endif; ?>

        </div>

    </div>

    <div class="content">

    <div class="card">

        <h2>Team Information</h2>

        <div class="grid info-grid">

            <div class="detail">
                <span class="label">Team Lead</span>
                <span class="value">
                    <?php echo e($team["team_lead_name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Project Department</span>
                <span class="value">
                    <?php echo e($team["project_department"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Project Manager</span>
                <span class="value">
                    <?php echo e($team["project_manager"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Members</span>
                <span class="value">
                    <?php echo count($members); ?>
                </span>
            </div>

            <div class="detail full">
                <span class="label">Departments Represented</span>
                <span class="value">
                    <?php echo e($departments ? implode(", ", $departments) : "None"); ?>
                </span>
            </div>

            <div class="detail full">
                <span class="label">Description / Purpose</span>
                <span class="value text-block"><?php
                    echo e($team["description"]);
                ?></span>
            </div>

            <div class="detail full">
                <span class="label">Responsibilities</span>
                <span class="value text-block"><?php
                    echo e($team["responsibilities"]);
                ?></span>
            </div>

        </div>

    </div>

    <div class="card">

        <h2>Team Members</h2>

        <?php if (!$members): ?>

            <div class="empty">
                No members are assigned to this team.
            </div>

        <?php else: ?>

            <div class="member-grid">

                <?php foreach ($members as $member): ?>

                    <div class="member-card">

                        <div class="member-name">

                            <?php
                            echo e(
                                $member["first_name"]
                                . " "
                                . $member["last_name"]
                            );
                            ?>

                            <?php if ((int)$member["id"] === (int)$team["team_lead_id"]): ?>
                                <span class="lead-tag">
                                    TEAM LEAD
                                </span>
                            <?php endif; ?>

                        </div>

                        <div class="member-department">
                            <?php echo e($member["department"]); ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

    <div class="card">

        <h2>Record Information</h2>

        <div class="grid info-grid">

            <div class="detail">
                <span class="label">Created By</span>
                <span class="value">
                    <?php echo e($team["created_by_name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Created Date</span>
                <span class="value">
                    <?php
                    echo e(
                        date(
                            "M j, Y g:i A",
                            strtotime($team["created_at"])
                        )
                    );
                    ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Last Updated</span>
                <span class="value">
                    <?php
                    echo e(
                        date(
                            "M j, Y g:i A",
                            strtotime($team["updated_at"])
                        )
                    );
                    ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Team ID</span>
                <span class="value">
                    <?php echo (int)$team["id"]; ?>
                </span>
            </div>

        </div>

    </div>

</div>

</div>

</div>

</body>
</html>
