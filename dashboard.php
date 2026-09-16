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
$current_full_name = trim($current_user["first_name"] . " " . $current_user["last_name"]);

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function dashboardCanAccessFileLocation(
    mysqli $conn,
    string $location_type,
    $location_id,
    int $user_id,
    string $role,
    string $user_department
): bool {
    if ($location_type === "Organization") {
        return true;
    }

    if ($role === "Administrator") {
        return true;
    }

    if ($location_type === "Department") {
        return (string)$location_id === (string)$user_department;
    }

    if ($location_type === "Project") {
        $stmt = $conn->prepare("
            SELECT department, manager_id
            FROM projects
            WHERE id = ?
        ");
        $id = (int)$location_id;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$project) {
            return false;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $project["department"] === $user_department
        ) {
            return true;
        }

        if (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM teams t
            INNER JOIN team_members tm ON tm.team_id = t.id
            WHERE t.project_id = ?
              AND tm.user_id = ?
        ");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Task") {
        $stmt = $conn->prepare("
            SELECT p.department, p.manager_id
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $id = (int)$location_id;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$task) {
            return false;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $task["department"] === $user_department
        ) {
            return true;
        }

        if (
            $task["manager_id"] !== null
            && (int)$task["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM task_assignments
            WHERE task_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Team") {
        $stmt = $conn->prepare("
            SELECT t.team_lead_id, p.department, p.manager_id
            FROM teams t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?
        ");
        $id = (int)$location_id;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $team = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$team) {
            return false;
        }

        if ((int)$team["team_lead_id"] === $user_id) {
            return true;
        }

        if (
            ($role === "Manager" || $role === "IT Administrator")
            && $team["department"] === $user_department
        ) {
            return true;
        }

        if (
            $team["manager_id"] !== null
            && (int)$team["manager_id"] === $user_id
        ) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM team_members
            WHERE team_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Event") {
        $stmt = $conn->prepare("
            SELECT department, type, organizer
            FROM events
            WHERE id = ?
              AND deleted = 0
        ");
        $id = (int)$location_id;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$event) {
            return false;
        }

        if ($event["type"] === "Organization-Wide Event") {
            return true;
        }

        if ((int)$event["organizer"] === $user_id) {
            return true;
        }

        if ($event["department"] === $user_department) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM event_attendees
            WHERE event_id = ?
              AND user_id = ?
        ");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    if ($location_type === "Training") {
        $stmt = $conn->prepare("
            SELECT department, organizer
            FROM training
            WHERE id = ?
              AND deleted = 0
        ");
        $id = (int)$location_id;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $training = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$training) {
            return false;
        }

        if ((int)$training["organizer"] === $user_id) {
            return true;
        }

        if ($training["department"] === $user_department) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM training_attendees
            WHERE training_id = ?
              AND (user_id = ? OR department = ?)
        ");
        $stmt->bind_param("iis", $id, $user_id, $user_department);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["total"];
        $stmt->close();

        return $total > 0;
    }

    return false;
}

function dashboardUserLeadsTeamOnProject(
    mysqli $conn,
    int $user_id,
    int $project_id
): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams
        WHERE project_id = ?
          AND team_lead_id = ?
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    return $total > 0;
}

function dashboardCreatorIsOnLedTeam(
    mysqli $conn,
    int $viewer_id,
    int $creator_id,
    int $project_id
): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams t
        INNER JOIN team_members tm ON tm.team_id = t.id
        WHERE t.project_id = ?
          AND t.team_lead_id = ?
          AND tm.user_id = ?
    ");
    $stmt->bind_param("iii", $project_id, $viewer_id, $creator_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    return $total > 0;
}

$projects = [];
$project_sql = "
    SELECT id, name, department, status, manager_id
    FROM projects
";
$project_params = [];
$project_types = "";

if ($role === "Administrator") {
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $project_sql .= " WHERE department = ? OR manager_id = ?";
    $project_params = [$user_department, $user_id];
    $project_types = "si";
} else {
    $project_sql .= "
        WHERE manager_id = ?
           OR EXISTS (
                SELECT 1
                FROM tasks t
                INNER JOIN task_assignments ta ON ta.task_id = t.id
                WHERE t.project_id = projects.id
                  AND ta.user_id = ?
           )
           OR EXISTS (
                SELECT 1
                FROM teams tm_project
                INNER JOIN team_members tm_member
                    ON tm_member.team_id = tm_project.id
                WHERE tm_project.project_id = projects.id
                  AND tm_member.user_id = ?
           )
    ";
    $project_params = [$user_id, $user_id, $user_id];
    $project_types = "iii";
}

$project_sql .= " ORDER BY name ASC LIMIT 6";
$project_stmt = $conn->prepare($project_sql);
if ($project_types !== "") {
    $project_stmt->bind_param($project_types, ...$project_params);
}
$project_stmt->execute();
$project_result = $project_stmt->get_result();
while ($row = $project_result->fetch_assoc()) {
    $projects[] = $row;
}
$project_stmt->close();

$tasks = [];
$task_sql = "
    SELECT
        t.id,
        t.name,
        t.status,
        t.priority,
        t.due_date,
        t.completion,
        p.name AS project_name,
        p.department
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
";
$task_params = [];
$task_types = "";

if ($role === "Administrator") {
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $task_sql .= "
        WHERE p.department = ?
           OR p.manager_id = ?
           OR EXISTS (
                SELECT 1
                FROM task_assignments ta
                WHERE ta.task_id = t.id
                  AND ta.user_id = ?
           )
    ";
    $task_params = [$user_department, $user_id, $user_id];
    $task_types = "sii";
} else {
    $task_sql .= "
        WHERE EXISTS (
            SELECT 1
            FROM task_assignments ta
            WHERE ta.task_id = t.id
              AND ta.user_id = ?
        )
    ";
    $task_params = [$user_id];
    $task_types = "i";
}

$task_sql .= "
    ORDER BY
        CASE WHEN t.status = 'Complete' THEN 2 ELSE 1 END,
        CASE WHEN t.due_date IS NULL THEN 2 ELSE 1 END,
        t.due_date ASC,
        t.name ASC
    LIMIT 8
";

$task_stmt = $conn->prepare($task_sql);
if ($task_types !== "") {
    $task_stmt->bind_param($task_types, ...$task_params);
}
$task_stmt->execute();
$task_result = $task_stmt->get_result();
while ($row = $task_result->fetch_assoc()) {
    $tasks[] = $row;
}
$task_stmt->close();

$teams = [];
$team_sql = "
    SELECT
        t.id,
        t.name,
        t.status,
        p.name AS project_name,
        p.department,
        p.manager_id
    FROM teams t
    INNER JOIN projects p ON p.id = t.project_id
";
$team_params = [];
$team_types = "";

if ($role === "Administrator") {
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $team_sql .= "
        WHERE p.department = ?
           OR p.manager_id = ?
           OR t.team_lead_id = ?
           OR EXISTS (
                SELECT 1
                FROM team_members tm
                WHERE tm.team_id = t.id
                  AND tm.user_id = ?
           )
    ";
    $team_params = [$user_department, $user_id, $user_id, $user_id];
    $team_types = "siii";
} else {
    $team_sql .= "
        WHERE p.manager_id = ?
           OR t.team_lead_id = ?
           OR EXISTS (
                SELECT 1
                FROM team_members tm
                WHERE tm.team_id = t.id
                  AND tm.user_id = ?
           )
    ";
    $team_params = [$user_id, $user_id, $user_id];
    $team_types = "iii";
}

$team_sql .= "
    ORDER BY
        CASE WHEN t.status = 'Active' THEN 1 ELSE 2 END,
        t.name ASC
    LIMIT 6
";
$team_stmt = $conn->prepare($team_sql);
if ($team_types !== "") {
    $team_stmt->bind_param($team_types, ...$team_params);
}
$team_stmt->execute();
$team_result = $team_stmt->get_result();
while ($row = $team_result->fetch_assoc()) {
    $teams[] = $row;
}
$team_stmt->close();

$events = [];
$event_sql = "
    SELECT
        e.id,
        e.name,
        e.type,
        e.department,
        e.start_date,
        e.start_time,
        e.status
    FROM events e
    WHERE e.deleted = 0
      AND e.start_date >= CURDATE()
      AND e.status NOT IN ('Cancelled', 'Completed')
";
$event_params = [];
$event_types = "";

if ($role === "Administrator") {
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $event_sql .= "
        AND (
            e.department = ?
            OR e.type = 'Organization-Wide Event'
            OR e.organizer = ?
            OR EXISTS (
                SELECT 1
                FROM event_attendees ea
                WHERE ea.event_id = e.id
                  AND ea.user_id = ?
            )
        )
    ";
    $event_params = [$user_department, $user_id, $user_id];
    $event_types = "sii";
} else {
    $event_sql .= "
        AND (
            e.department = ?
            OR e.type = 'Organization-Wide Event'
            OR EXISTS (
                SELECT 1
                FROM event_attendees ea
                WHERE ea.event_id = e.id
                  AND ea.user_id = ?
            )
        )
    ";
    $event_params = [$user_department, $user_id];
    $event_types = "si";
}

$event_sql .= " ORDER BY e.start_date ASC, e.start_time ASC LIMIT 6";
$event_stmt = $conn->prepare($event_sql);
if ($event_types !== "") {
    $event_stmt->bind_param($event_types, ...$event_params);
}
$event_stmt->execute();
$event_result = $event_stmt->get_result();
while ($row = $event_result->fetch_assoc()) {
    $events[] = $row;
}
$event_stmt->close();

$training = [];
$training_sql = "
    SELECT
        t.id,
        t.name,
        t.type,
        t.department,
        t.start_date,
        t.start_time,
        t.status
    FROM training t
    WHERE t.deleted = 0
      AND t.start_date >= CURDATE()
      AND t.status NOT IN ('Cancelled', 'Completed')
";
$training_params = [];
$training_types = "";

if ($role !== "Administrator") {
    $training_sql .= " AND t.department = ?";
    $training_params[] = $user_department;
    $training_types .= "s";
}

$training_sql .= " ORDER BY t.start_date ASC, t.start_time ASC LIMIT 6";
$training_stmt = $conn->prepare($training_sql);
if ($training_types !== "") {
    $training_stmt->bind_param($training_types, ...$training_params);
}
$training_stmt->execute();
$training_result = $training_stmt->get_result();
while ($row = $training_result->fetch_assoc()) {
    $training[] = $row;
}
$training_stmt->close();

$time_off = [];
$time_sql = "
    SELECT
        t.id,
        t.user_id,
        t.time_off_type,
        t.start_date,
        t.end_date,
        t.status,
        CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
        u.department
    FROM time_off t
    INNER JOIN users u ON u.id = t.user_id
";
$time_params = [];
$time_types = "";

if ($role === "Administrator") {
    $time_sql .= " WHERE t.status = 'Submitted'";
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $time_sql .= "
        WHERE u.department = ?
          AND t.status = 'Submitted'
    ";
    $time_params[] = $user_department;
    $time_types .= "s";
} else {
    $time_sql .= "
        WHERE t.user_id = ?
          AND t.status IN ('Submitted', 'Approved')
    ";
    $time_params[] = $user_id;
    $time_types .= "i";
}

$time_sql .= " ORDER BY t.start_date ASC, t.id DESC LIMIT 6";
$time_stmt = $conn->prepare($time_sql);
if ($time_types !== "") {
    $time_stmt->bind_param($time_types, ...$time_params);
}
$time_stmt->execute();
$time_result = $time_stmt->get_result();
while ($row = $time_result->fetch_assoc()) {
    $time_off[] = $row;
}
$time_stmt->close();

$files = [];
$file_result = $conn->query("
    SELECT
        f.id,
        f.name,
        f.location_type,
        f.location_id,
        f.updated_at
    FROM files f
    WHERE f.is_trashed = 0
    ORDER BY f.updated_at DESC, f.id DESC
    LIMIT 50
");
if ($file_result) {
    while ($row = $file_result->fetch_assoc()) {
        if (
            dashboardCanAccessFileLocation(
                $conn,
                $row["location_type"],
                $row["location_id"],
                $user_id,
                $role,
                $user_department
            )
        ) {
            $files[] = $row;
        }

        if (count($files) >= 6) {
            break;
        }
    }
}

$reports = [];
$report_result = $conn->query("
    SELECT
        r.id,
        r.title,
        r.project_id,
        r.created_by,
        r.creator_level,
        r.updated_at,
        p.name AS project_name,
        p.department AS project_department,
        p.manager_id AS project_manager_id
    FROM reports r
    INNER JOIN projects p ON p.id = r.project_id
    ORDER BY r.updated_at DESC, r.id DESC
    LIMIT 50
");
if ($report_result) {
    while ($report = $report_result->fetch_assoc()) {
        $can_view = false;

        if ($role === "Administrator") {
            $can_view = true;
        }

        if ((int)$report["created_by"] === $user_id) {
            $can_view = true;
        }

        if (
            !$can_view
            && $role === "IT Administrator"
            && $report["project_department"] === $user_department
        ) {
            $can_view = true;
        }

        if (
            !$can_view
            && $role === "Manager"
            && in_array($report["creator_level"], ["Employee", "Team Lead"], true)
            && (
                $report["project_department"] === $user_department
                || (
                    $report["project_manager_id"] !== null
                    && (int)$report["project_manager_id"] === $user_id
                )
            )
        ) {
            $can_view = true;
        }

        if (
            !$can_view
            && $role !== "Manager"
            && $role !== "Administrator"
            && in_array($report["creator_level"], ["Employee", "Team Lead"], true)
            && dashboardUserLeadsTeamOnProject(
                $conn,
                $user_id,
                (int)$report["project_id"]
            )
            && dashboardCreatorIsOnLedTeam(
                $conn,
                $user_id,
                (int)$report["created_by"],
                (int)$report["project_id"]
            )
        ) {
            $can_view = true;
        }

        if ($can_view) {
            $reports[] = $report;
        }

        if (count($reports) >= 6) {
            break;
        }
    }
}

$notifications = [];
$notification_stmt = $conn->prepare("
    SELECT id, title, type, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT 6
");
$notification_stmt->bind_param("i", $user_id);
$notification_stmt->execute();
$notification_result = $notification_stmt->get_result();
while ($row = $notification_result->fetch_assoc()) {
    $notifications[] = $row;
}
$notification_stmt->close();

$unread_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = ?
      AND is_read = 0
");
$unread_stmt->bind_param("i", $user_id);
$unread_stmt->execute();
$unread_notifications = (int)$unread_stmt->get_result()->fetch_assoc()["total"];
$unread_stmt->close();

$overdue_count = 0;
foreach ($tasks as $task) {
    if (
        !empty($task["due_date"])
        && $task["due_date"] < date("Y-m-d")
        && $task["status"] !== "Complete"
    ) {
        $overdue_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link rel="stylesheet" href="app.css?v=20">
</head>
<body>
<div class="page">
    <aside class="sidebar">
        <div class="sidebar-spacer"></div>

        <div class="sidebar-item active"><a href="dashboard.php">Dashboard</a></div>
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
            <div class="sidebar-item"><a href="account.php">Account</a></div>
            <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
    </aside>

    <main class="main">
        <header class="header">
            <div>
                <h1>Dashboard</h1>
                <div><?php echo e($current_user["first_name"] . " " . $current_user["last_name"]); ?></div>
            </div>
            <div class="header-meta">
                <strong><?php echo e($role); ?></strong><br>
                <?php echo e($user_department); ?>
            </div>
        </header>

        <div class="content">
            <section class="summary-grid">
                <div class="summary-card">
                    <a href="projects.php">
                        <div class="summary-number"><?php echo count($projects); ?></div>
                        <div>Visible Projects</div>
                    </a>
                </div>

                <div class="summary-card">
                    <a href="tasks.php">
                        <div class="summary-number"><?php echo count($tasks); ?></div>
                        <div>Visible Tasks</div>
                    </a>
                </div>

                <div class="summary-card">
                    <a href="tasks.php">
                        <div class="summary-number"><?php echo $overdue_count; ?></div>
                        <div>Overdue Tasks Shown</div>
                    </a>
                </div>

                <div class="summary-card">
                    <a href="notifications.php?status=unread">
                        <div class="summary-number"><?php echo $unread_notifications; ?></div>
                        <div>Unread Notifications</div>
                    </a>
                </div>
            </section>

            <section class="dashboard-grid">
                <div class="panel">
                    <div class="panel-header">
                        <h2>Projects</h2>
                        <a href="projects.php">View All</a>
                    </div>
                    <?php if ($projects): ?>
                        <?php foreach ($projects as $project): ?>
                            <div class="item">
                                <a href="projects.php?project=<?php echo (int)$project["id"]; ?>">
                                    <div class="item-title"><?php echo e($project["name"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($project["department"]); ?>
                                        <?php if (!empty($project["status"])): ?>
                                            · <?php echo e($project["status"]); ?>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No projects to display.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Tasks</h2>
                        <a href="tasks.php">View All</a>
                    </div>
                    <?php if ($tasks): ?>
                        <?php foreach ($tasks as $task): ?>
                            <div class="item">
                                <a href="view_task.php?id=<?php echo (int)$task["id"]; ?>">
                                    <div class="item-title"><?php echo e($task["name"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($task["project_name"]); ?> ·
                                        <?php echo e($task["status"]); ?>
                                        <?php if (!empty($task["due_date"])): ?>
                                            · Due <?php echo e($task["due_date"]); ?>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No tasks to display.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Meetings / Events</h2>
                        <a href="events.php">View All</a>
                    </div>
                    <?php if ($events): ?>
                        <?php foreach ($events as $event): ?>
                            <div class="item">
                                <a href="view_event.php?id=<?php echo (int)$event["id"]; ?>">
                                    <div class="item-title"><?php echo e($event["name"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($event["start_date"]); ?>
                                        <?php if (!empty($event["start_time"])): ?>
                                            · <?php echo e(substr($event["start_time"], 0, 5)); ?>
                                        <?php endif; ?>
                                        · <?php echo e($event["type"]); ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No upcoming meetings or events.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Training</h2>
                        <a href="training.php">View All</a>
                    </div>
                    <?php if ($training): ?>
                        <?php foreach ($training as $item): ?>
                            <div class="item">
                                <a href="view_training.php?id=<?php echo (int)$item["id"]; ?>">
                                    <div class="item-title"><?php echo e($item["name"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($item["start_date"]); ?>
                                        <?php if (!empty($item["start_time"])): ?>
                                            · <?php echo e(substr($item["start_time"], 0, 5)); ?>
                                        <?php endif; ?>
                                        · <?php echo e($item["type"]); ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No upcoming training.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Teams</h2>
                        <a href="teams.php">View All</a>
                    </div>
                    <?php if ($teams): ?>
                        <?php foreach ($teams as $team): ?>
                            <div class="item">
                                <a href="view_team.php?id=<?php echo (int)$team["id"]; ?>">
                                    <div class="item-title"><?php echo e($team["name"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($team["project_name"]); ?> ·
                                        <?php echo e($team["status"]); ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No teams to display.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Time Off</h2>
                        <a href="time_off.php">View All</a>
                    </div>
                    <?php if ($time_off): ?>
                        <?php foreach ($time_off as $item): ?>
                            <div class="item">
                                <a href="view_time_off.php?id=<?php echo (int)$item["id"]; ?>">
                                    <div class="item-title">
                                        <?php if ($role === "Employee"): ?>
                                            <?php echo e($item["time_off_type"]); ?>
                                        <?php else: ?>
                                            <?php echo e($item["employee_name"]); ?> — <?php echo e($item["time_off_type"]); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-meta">
                                        <?php echo e($item["start_date"]); ?> to <?php echo e($item["end_date"]); ?> ·
                                        <?php echo e($item["status"]); ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No Time Off items to display.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Recent Files</h2>
                        <a href="files.php">View All</a>
                    </div>
                    <?php if ($files): ?>
                        <?php foreach ($files as $file): ?>
                            <div class="item">
                                <a href="view_file.php?id=<?php echo (int)$file["id"]; ?>">
                                    <div class="item-title"><?php echo e($file["name"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($file["location_type"]); ?> ·
                                        Updated <?php echo e($file["updated_at"]); ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No accessible recent files.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Recent Reports</h2>
                        <a href="reports.php">View All</a>
                    </div>
                    <?php if ($reports): ?>
                        <?php foreach ($reports as $report): ?>
                            <div class="item">
                                <a href="view_report.php?id=<?php echo (int)$report["id"]; ?>">
                                    <div class="item-title"><?php echo e($report["title"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($report["project_name"]); ?> ·
                                        Updated <?php echo e($report["updated_at"]); ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No reports to display.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Notifications</h2>
                        <a href="notifications.php">View All</a>
                    </div>
                    <?php if ($notifications): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="item <?php echo (int)$notification["is_read"] === 0 ? "unread" : ""; ?>">
                                <a href="open_notification.php?id=<?php echo (int)$notification["id"]; ?>">
                                    <div class="item-title"><?php echo e($notification["title"]); ?></div>
                                    <div class="item-meta">
                                        <?php echo e($notification["type"]); ?> ·
                                        <?php echo e($notification["created_at"]); ?>
                                        <?php if ((int)$notification["is_read"] === 0): ?>
                                            · Unread
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">No notifications.</div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Calendar</h2>
                        <a href="calendar.php">Open Calendar</a>
                    </div>
                    <div class="item">
                        <a href="calendar.php">
                            <div class="item-title">Open your calendar</div>
                            <div class="item-meta">
                                View projects, tasks, milestones, meetings, training, Time Off and holidays by date.
                            </div>
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
