<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];
$user_department = $current_user["department"];
$current_full_name = trim(
    $current_user["first_name"] . " " . $current_user["last_name"]
);

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Filters.
 */
$search = trim($_GET["search"] ?? "");
$project_filter = (int)($_GET["project"] ?? 0);
$type_filter = trim($_GET["report_type"] ?? "");
$creator_filter = (int)($_GET["creator"] ?? 0);
$date_from = trim($_GET["date_from"] ?? "");
$date_to = trim($_GET["date_to"] ?? "");

$report_types = [
    "Project Status Report",
    "Task Status / Completion Report",
    "Team Membership Report",
    "Event Report",
    "Training Report",
    "Time Off Report",
    "File Activity Report",
    "Department Summary Report"
];

/*
 * Projects for filter dropdown.
 */
$projects = [];

$result = $conn->query("
    SELECT id, name
    FROM projects
    ORDER BY name ASC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
}

/*
 * Creators for filter dropdown.
 * The final report list is still visibility-filtered below.
 */
$creators = [];

$result = $conn->query("
    SELECT id, first_name, last_name
    FROM users
    WHERE active = 1
    ORDER BY last_name, first_name
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $creators[] = $row;
    }
}

/*
 * Helper: determine whether current user leads a team on a project.
 */
function userLeadsTeamOnProject($conn, $user_id, $project_id) {
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

/*
 * Helper: determine whether report creator belongs to a team
 * led by the current user on this report's project.
 */
function creatorIsOnLedTeam(
    $conn,
    $viewer_id,
    $creator_id,
    $project_id
) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams t
        INNER JOIN team_members tm
            ON tm.team_id = t.id
        WHERE t.project_id = ?
          AND t.team_lead_id = ?
          AND tm.user_id = ?
    ");
    $stmt->bind_param(
        "iii",
        $project_id,
        $viewer_id,
        $creator_id
    );
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    return $total > 0;
}

/*
 * Helper: determine whether current Manager manages this project.
 * This uses either project department or the project's manager_id.
 */
function managerCanViewProject(
    $project_department,
    $project_manager_id,
    $user_department,
    $user_id
) {
    return (
        $project_department === $user_department
        || (
            $project_manager_id !== null
            && (int)$project_manager_id === $user_id
        )
    );
}

/*
 * Load report records and related project/creator data.
 *
 * creator_level is stored on the report so visibility is based on
 * the level used when that report was created:
 * Employee / Team Lead / Manager / Administrator.
 */
$sql = "
    SELECT
        r.id,
        r.title,
        r.project_id,
        r.report_type,
        r.period_start,
        r.period_end,
        r.summary,
        r.accomplishments,
        r.issues_risks,
        r.next_steps,
        r.created_by,
        r.creator_level,
        r.created_at,
        r.updated_at,

        p.name AS project_name,
        p.department AS project_department,
        p.manager_id AS project_manager_id,

        u.first_name,
        u.last_name

    FROM reports r

    INNER JOIN projects p
        ON p.id = r.project_id

    INNER JOIN users u
        ON u.id = r.created_by

    ORDER BY r.updated_at DESC, r.created_at DESC
";

$result = $conn->query($sql);
$reports = [];

if ($result) {
    while ($report = $result->fetch_assoc()) {

        $can_view = false;

        /*
         * Administrator:
         * can view everyone's reports.
         */
        if ($role === "Administrator") {
            $can_view = true;
        }

        /*
         * Everyone can always view their own report.
         */
        if ((int)$report["created_by"] === $user_id) {
            $can_view = true;
        }

        /*
         * IT Administrator:
         * same as Administrator (no creator-level restriction),
         * but only for reports whose project is in their own department.
         */
        if (
            !$can_view
            && $role === "IT Administrator"
            && $report["project_department"] === $user_department
        ) {
            $can_view = true;
        }

        /*
         * Manager:
         * can view Employee and Team Lead reports in work areas they manage.
         * Cannot view another Manager's report or an Administrator report.
         */
        if (
            !$can_view
            && $role === "Manager"
            && in_array(
                $report["creator_level"],
                ["Employee", "Team Lead"],
                true
            )
            && managerCanViewProject(
                $report["project_department"],
                $report["project_manager_id"],
                $user_department,
                $user_id
            )
        ) {
            $can_view = true;
        }

        /*
         * Team Lead:
         * can view reports created by members of teams they lead
         * on this project.
         * Team Lead cannot view Manager or Administrator reports.
         */
        if (
            !$can_view
            && $role !== "Manager"
            && $role !== "Administrator"
            && in_array(
                $report["creator_level"],
                ["Employee", "Team Lead"],
                true
            )
            && userLeadsTeamOnProject(
                $conn,
                $user_id,
                (int)$report["project_id"]
            )
            && creatorIsOnLedTeam(
                $conn,
                $user_id,
                (int)$report["created_by"],
                (int)$report["project_id"]
            )
        ) {
            $can_view = true;
        }

        /*
         * Regular users who are not a qualifying Team Lead only see their own
         * reports, which has already been handled above.
         */
        if (!$can_view) {
            continue;
        }

        /*
         * Apply filters after visibility.
         */
        if (
            $project_filter > 0
            && (int)$report["project_id"] !== $project_filter
        ) {
            continue;
        }

        if (
            $type_filter !== ""
            && $report["report_type"] !== $type_filter
        ) {
            continue;
        }

        if (
            $creator_filter > 0
            && (int)$report["created_by"] !== $creator_filter
        ) {
            continue;
        }

        if (
            $date_from !== ""
            && substr($report["created_at"], 0, 10) < $date_from
        ) {
            continue;
        }

        if (
            $date_to !== ""
            && substr($report["created_at"], 0, 10) > $date_to
        ) {
            continue;
        }

        if ($search !== "") {

            $haystack = strtolower(
                $report["title"]
                . " "
                . $report["project_name"]
                . " "
                . $report["report_type"]
                . " "
                . $report["summary"]
                . " "
                . $report["accomplishments"]
                . " "
                . $report["issues_risks"]
                . " "
                . $report["next_steps"]
                . " "
                . $report["first_name"]
                . " "
                . $report["last_name"]
            );

            if (
                strpos(
                    $haystack,
                    strtolower($search)
                ) === false
            ) {
                continue;
            }
        }

        $reports[] = $report;
    }
}

/*
 * Anyone can create a report if new_report.php finds at least one project
 * they are allowed to report on.
 */
$can_create_report = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reports</title>

<link rel="stylesheet" href="app.css?v=13">

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
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item active">
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
            <h1>REPORTS</h1>

            <div class="sub">
                Create and view reports based on your role and project access
            </div>
        </div>

        <?php if ($can_create_report): ?>

            <a
                class="button primary"
                href="new_report.php"
            >
                + CREATE REPORT
            </a>

        <?php endif; ?>

    </div>

    <div class="content">

    <form
        class="filters"
        method="GET"
    >

        <div class="filter-grid">

            <div class="field">
                <label for="search">Search</label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?php echo e($search); ?>"
                    placeholder="Report, project, summary, creator"
                >
            </div>

            <div class="field">
                <label for="project">Project</label>

                <select
                    id="project"
                    name="project"
                >
                    <option value="0">
                        All Projects
                    </option>

                    <?php foreach ($projects as $project): ?>

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
                <label for="report_type">
                    Report Type
                </label>

                <select
                    id="report_type"
                    name="report_type"
                >
                    <option value="">
                        All Types
                    </option>

                    <?php foreach ($report_types as $type): ?>

                        <option
                            value="<?php echo e($type); ?>"
                            <?php echo $type_filter === $type ? "selected" : ""; ?>
                        >
                            <?php echo e($type); ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="field">
                <label for="creator">
                    Creator
                </label>

                <select
                    id="creator"
                    name="creator"
                >
                    <option value="0">
                        All Visible Creators
                    </option>

                    <?php foreach ($creators as $creator): ?>

                        <option
                            value="<?php echo (int)$creator["id"]; ?>"
                            <?php echo $creator_filter === (int)$creator["id"] ? "selected" : ""; ?>
                        >
                            <?php
                            echo e(
                                $creator["first_name"]
                                . " "
                                . $creator["last_name"]
                            );
                            ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

        </div>

        <div class="filter-grid second">

            <div class="field">
                <label for="date_from">
                    Created From
                </label>

                <input
                    type="date"
                    id="date_from"
                    name="date_from"
                    value="<?php echo e($date_from); ?>"
                >
            </div>

            <div class="field">
                <label for="date_to">
                    Created To
                </label>

                <input
                    type="date"
                    id="date_to"
                    name="date_to"
                    value="<?php echo e($date_to); ?>"
                >
            </div>

            <div class="filter-actions">

                <button
                    class="button primary"
                    type="submit"
                >
                    APPLY
                </button>

                <a
                    class="button"
                    href="reports.php"
                >
                    CLEAR
                </a>

            </div>

        </div>

    </form>

    <?php if (!$reports): ?>

        <div class="empty">
            No reports are available for your account or current filters.
        </div>

    <?php else: ?>

        <div class="report-grid">

            <?php foreach ($reports as $report): ?>

                <div class="report-card">

                    <div class="card-top">

                        <div>

                            <div class="report-title">
                                <?php echo e($report["title"]); ?>
                            </div>

                            <div class="project">
                                <?php echo e($report["project_name"]); ?>
                            </div>

                        </div>

                        <span class="level">
                            <?php echo e($report["creator_level"]); ?>
                        </span>

                    </div>

                    <div class="detail">
                        <strong>Type:</strong>
                        <?php echo e($report["report_type"]); ?>
                    </div>

                    <div class="detail">
                        <strong>Created By:</strong>
                        <?php
                        echo e(
                            $report["first_name"]
                            . " "
                            . $report["last_name"]
                        );
                        ?>
                    </div>

                    <div class="detail">
                        <strong>Reporting Period:</strong>

                        <?php
                        echo e(
                            date(
                                "M j, Y",
                                strtotime($report["period_start"])
                            )
                        );
                        ?>

                        —

                        <?php
                        echo e(
                            date(
                                "M j, Y",
                                strtotime($report["period_end"])
                            )
                        );
                        ?>
                    </div>

                    <div class="detail">
                        <strong>Created:</strong>
                        <?php
                        echo e(
                            date(
                                "M j, Y g:i A",
                                strtotime($report["created_at"])
                            )
                        );
                        ?>
                    </div>

                    <div class="summary">

                        <?php
                        $summary = trim($report["summary"]);

                        if (strlen($summary) > 220) {
                            $summary =
                                substr($summary, 0, 217)
                                . "...";
                        }

                        echo e($summary);
                        ?>

                    </div>

                    <div class="actions">

                        <a
                            class="button"
                            href="view_report.php?id=<?php echo (int)$report["id"]; ?>"
                        >
                            VIEW REPORT
                        </a>

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
