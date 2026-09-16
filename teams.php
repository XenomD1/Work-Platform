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

$status_filter = trim($_GET["status"] ?? "");
$project_filter = (int)($_GET["project"] ?? 0);
$search = trim($_GET["search"] ?? "");

$statuses = ["Active", "Inactive"];

/*
 * Build visibility rules.
 *
 * Administrator:
 *   sees all teams.
 *
 * Manager:
 *   sees teams tied to projects in their department.
 *
 * Project Manager:
 *   a user whose full name matches projects.manager can see those project teams.
 *
 * Employee:
 *   sees teams they belong to.
 *
 * A Manager who is also the named Project Manager still gets visibility
 * through either rule.
 */
$where_parts = [];
$params = [];
$param_types = "";

if ($role === "Administrator") {

    // No visibility restriction.

} elseif ($role === "Manager" || $role === "IT Administrator") {

    $where_parts[] = "(
        p.department = ?
        OR p.manager_id = ?
        OR EXISTS (
            SELECT 1
            FROM team_members tm_visibility
            WHERE tm_visibility.team_id = t.id
              AND tm_visibility.user_id = ?
        )
    )";

    $params[] = $user_department;
    $params[] = $user_id;
    $params[] = $user_id;
    $param_types .= "sii";

} else {

    $where_parts[] = "(
        p.manager_id = ?
        OR EXISTS (
            SELECT 1
            FROM team_members tm_visibility
            WHERE tm_visibility.team_id = t.id
              AND tm_visibility.user_id = ?
        )
    )";

    $params[] = $user_id;
    $params[] = $user_id;
    $param_types .= "ii";
}

/*
 * Optional filters.
 */
if ($status_filter !== "" && in_array($status_filter, $statuses, true)) {
    $where_parts[] = "t.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if ($project_filter > 0) {
    $where_parts[] = "t.project_id = ?";
    $params[] = $project_filter;
    $param_types .= "i";
}

if ($search !== "") {
    $where_parts[] = "(
        t.name LIKE ?
        OR t.description LIKE ?
        OR t.responsibilities LIKE ?
        OR p.name LIKE ?
    )";

    $like = "%" . $search . "%";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $param_types .= "ssss";
}

$where_sql = $where_parts
    ? "WHERE " . implode(" AND ", $where_parts)
    : "";

/*
 * Project filter list.
 */
$project_options = [];

$project_result = $conn->query("
    SELECT id, name, department, manager_id
    FROM projects
    ORDER BY name ASC
");

if ($project_result) {
    while ($project = $project_result->fetch_assoc()) {

        $can_see_project = false;

        if ($role === "Administrator") {
            $can_see_project = true;
        } elseif (
            ($role === "Manager" || $role === "IT Administrator")
            && $project["department"] === $user_department
        ) {
            $can_see_project = true;
        } elseif (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            $can_see_project = true;
        }

        if ($can_see_project) {
            $project_options[] = $project;
        }
    }
}

/*
 * Determine whether user can create teams.
 * Administrator, Manager, and IT Administrator can create.
 * A Project Manager can also create for their own project,
 * even if their user role is Employee.
 */
$can_create_team = false;

if ($role === "Administrator" || $role === "Manager" || $role === "IT Administrator") {
    $can_create_team = true;
} else {
    foreach ($project_options as $project) {
        if (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            $can_create_team = true;
            break;
        }
    }
}

/*
 * Load teams with lead, project, member count,
 * and represented departments.
 */
$sql = "
    SELECT
        t.id,
        t.project_id,
        t.name,
        t.description,
        t.responsibilities,
        t.team_lead_id,
        t.status,
        t.created_at,

        p.name AS project_name,
        p.department AS project_department,
        p.manager_id AS project_manager_id,

        CONCAT(lead_user.first_name, ' ', lead_user.last_name) AS team_lead_name,

        COUNT(DISTINCT tm.user_id) AS member_count,

        GROUP_CONCAT(
            DISTINCT member_user.department
            ORDER BY member_user.department
            SEPARATOR ', '
        ) AS departments_represented

    FROM teams t

    INNER JOIN projects p
        ON p.id = t.project_id

    INNER JOIN users lead_user
        ON lead_user.id = t.team_lead_id

    LEFT JOIN team_members tm
        ON tm.team_id = t.id

    LEFT JOIN users member_user
        ON member_user.id = tm.user_id

    $where_sql

    GROUP BY
        t.id,
        t.project_id,
        t.name,
        t.description,
        t.responsibilities,
        t.team_lead_id,
        t.status,
        t.created_at,
        p.name,
        p.department,
        p.manager_id,
        lead_user.first_name,
        lead_user.last_name

    ORDER BY
        CASE WHEN t.status = 'Active' THEN 1 ELSE 2 END,
        p.name ASC,
        t.name ASC
";

$stmt = $conn->prepare($sql);

if ($param_types !== "") {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$teams = [];

while ($row = $result->fetch_assoc()) {
    $teams[] = $row;
}

$stmt->close();

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function canEditTeam($team, $role, $user_department, $user_id) {

    if ($role === "Administrator") {
        return true;
    }

    if (
        ($role === "Manager" || $role === "IT Administrator")
        && $team["project_department"] === $user_department
    ) {
        return true;
    }

    if (
        $team["project_manager_id"] !== null
        && (int)$team["project_manager_id"] === $user_id
    ) {
        return true;
    }

    return false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Teams</title>

<link rel="stylesheet" href="app.css?v=20">

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
            <h1>TEAMS</h1>
            <div class="sub">
                Project-based teams and team membership
            </div>
        </div>

        <?php if ($can_create_team): ?>
            <a class="button primary" href="new_team.php">
                + NEW TEAM
            </a>
        <?php endif; ?>

    </div>

    <div class="content">

    <form class="filters" method="GET">

        <div class="filter-grid">

            <div class="field">
                <label for="search">Search</label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?php echo e($search); ?>"
                    placeholder="Team, project, description, responsibilities"
                >
            </div>

            <div class="field">
                <label for="project">Project</label>

                <select id="project" name="project">
                    <option value="0">All Projects</option>

                    <?php foreach ($project_options as $project): ?>
                        <option
                            value="<?php echo (int)$project["id"]; ?>"
                            <?php echo $project_filter === (int)$project["id"] ? "selected" : ""; ?>
                        >
                            <?php echo e($project["name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="status">Status</label>

                <select id="status" name="status">
                    <option value="">All Statuses</option>

                    <?php foreach ($statuses as $item): ?>
                        <option
                            value="<?php echo e($item); ?>"
                            <?php echo $status_filter === $item ? "selected" : ""; ?>
                        >
                            <?php echo e($item); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button class="button primary" type="submit">
                    APPLY
                </button>

                <a class="button" href="teams.php">
                    CLEAR
                </a>
            </div>

        </div>

    </form>

    <?php if (!$teams): ?>

        <div class="empty">
            No teams were found.
        </div>

    <?php else: ?>

        <div class="team-grid">

            <?php foreach ($teams as $team): ?>

                <div class="team-card">

                    <div class="card-top">

                        <div>
                            <div class="team-name">
                                <?php echo e($team["name"]); ?>
                            </div>

                            <div class="project-name">
                                Project:
                                <?php echo e($team["project_name"]); ?>
                            </div>
                        </div>

                        <span class="status">
                            <?php echo e($team["status"]); ?>
                        </span>

                    </div>

                    <div class="detail">
                        <span class="detail-label">Team Lead:</span>
                        <?php echo e($team["team_lead_name"]); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Members:</span>
                        <?php echo (int)$team["member_count"]; ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Departments Represented:</span>
                        <?php echo e($team["departments_represented"] ?: "None"); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Created:</span>
                        <?php
                        echo e(
                            date(
                                "M j, Y",
                                strtotime($team["created_at"])
                            )
                        );
                        ?>
                    </div>

                    <div class="description">
                        <?php
                        $description = trim($team["description"]);

                        if (strlen($description) > 170) {
                            $description = substr($description, 0, 167) . "...";
                        }

                        echo e($description);
                        ?>
                    </div>

                    <div class="card-actions">

                        <a
                            class="button"
                            href="view_team.php?id=<?php echo (int)$team["id"]; ?>"
                        >
                            VIEW TEAM
                        </a>

                        <?php if (
                            canEditTeam(
                                $team,
                                $role,
                                $user_department,
                                $user_id
                            )
                        ): ?>

                            <a
                                class="button"
                                href="edit_team.php?id=<?php echo (int)$team["id"]; ?>"
                            >
                                EDIT
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    </div>

</div>

</div>

</body>
</html>
