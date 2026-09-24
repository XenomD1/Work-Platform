<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";

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
 * Workload / capacity.
 *
 * Scoped to this team's project: for each member, how many tasks in
 * this project are assigned to them, how many are still active
 * (not Complete), and how many of those are overdue (due date has
 * passed and the task isn't Complete).
 */
$workload = [];

foreach ($members as $member) {
    $workload[(int)$member["id"]] = [
        "assigned" => 0,
        "active" => 0,
        "overdue" => 0,
        "overdue_tasks" => [],
    ];
}

if ($members) {
    $workload_stmt = $conn->prepare("
        SELECT
            tm.user_id,
            t.id AS task_id,
            t.name AS task_name,
            t.status,
            t.due_date
        FROM team_members tm
        INNER JOIN task_assignments ta
            ON ta.user_id = tm.user_id
        INNER JOIN tasks t
            ON t.id = ta.task_id
        WHERE tm.team_id = ?
          AND t.project_id = ?
    ");

    $workload_stmt->bind_param(
        "ii",
        $team_id,
        $team["project_id"]
    );
    $workload_stmt->execute();
    $workload_result = $workload_stmt->get_result();

    $today = date("Y-m-d");

    while ($row = $workload_result->fetch_assoc()) {
        $member_id = (int)$row["user_id"];

        if (!isset($workload[$member_id])) {
            continue;
        }

        $workload[$member_id]["assigned"]++;

        $is_complete = ($row["status"] === "Complete");

        if (!$is_complete) {
            $workload[$member_id]["active"]++;
        }

        $due_date = $row["due_date"];
        $is_overdue =
            !$is_complete
            && !empty($due_date)
            && substr($due_date, 0, 10) < $today;

        if ($is_overdue) {
            $workload[$member_id]["overdue"]++;
            $workload[$member_id]["overdue_tasks"][] = [
                "id" => (int)$row["task_id"],
                "name" => $row["task_name"],
                "due_date" => $due_date,
            ];
        }
    }

    $workload_stmt->close();

    foreach ($workload as $member_id => $data) {
        usort(
            $workload[$member_id]["overdue_tasks"],
            function ($a, $b) {
                return strcmp($a["due_date"], $b["due_date"]);
            }
        );
    }
}

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

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
        <div class="sidebar-item">
            <a href="roles.php">Roles &amp; Permissions</a>
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

            <a class="button" href="comments.php?module=Team&id=<?php echo (int)$team["id"]; ?>">COMMENTS</a>

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

        <h2>Team Workload</h2>

        <?php if (!$members): ?>

            <div class="empty">
                No members are assigned to this team.
            </div>

        <?php else: ?>

            <div class="grid info-grid">

                <?php foreach ($members as $member): ?>

                    <?php
                    $member_id = (int)$member["id"];
                    $data = $workload[$member_id];
                    ?>

                    <div class="detail full">

                        <span class="label">
                            <?php
                            echo e(
                                $member["first_name"]
                                . " "
                                . $member["last_name"]
                            );
                            ?>
                        </span>

                        <span class="value">

                            <?php echo (int)$data["assigned"]; ?> assigned
                            &nbsp;·&nbsp;
                            <?php echo (int)$data["active"]; ?> active
                            &nbsp;·&nbsp;
                            <span<?php echo $data["overdue"] > 0 ? ' style="color:#b3261e;font-weight:700;"' : ""; ?>>
                                <?php echo (int)$data["overdue"]; ?> overdue
                            </span>

                            <?php if ($data["overdue_tasks"]): ?>

                                <ul style="margin-top:6px;">

                                    <?php foreach ($data["overdue_tasks"] as $overdue_task): ?>

                                        <li>
                                            <a
                                                href="view_task.php?id=<?php echo (int)$overdue_task["id"]; ?>"
                                                style="color: black;"
                                            >
                                                <?php echo e($overdue_task["name"]); ?>
                                            </a>
                                            &nbsp;—&nbsp;
                                            due
                                            <?php
                                            echo e(
                                                date(
                                                    "M j, Y",
                                                    strtotime($overdue_task["due_date"])
                                                )
                                            );
                                            ?>
                                        </li>

                                    <?php endforeach; ?>

                                </ul>

                            <?php endif; ?>

                        </span>

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
