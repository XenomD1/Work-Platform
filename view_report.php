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

$report_id = (int)($_GET["id"] ?? 0);

if ($report_id <= 0) {
    header("Location: reports.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Load report, creator, and project information.
 */
$stmt = $conn->prepare("
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

    WHERE r.id = ?
");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    header("Location: reports.php");
    exit;
}

/*
 * Visibility rules:
 *
 * Employee:
 * - Own reports only.
 *
 * Team Lead:
 * - Own reports.
 * - Reports from members of teams they lead on the report project.
 * - Cannot see Manager or Administrator reports.
 *
 * Manager:
 * - Own reports.
 * - Employee and Team Lead reports in work areas they manage.
 * - Cannot see Administrator reports.
 *
 * Administrator:
 * - Everyone's reports.
 */
$can_view = false;

if ($role === "Administrator") {
    $can_view = true;
}

if ((int)$report["created_by"] === $user_id) {
    $can_view = true;
}

/*
 * IT Administrator visibility.
 * Same as Administrator (no creator-level restriction), but only
 * for reports whose project belongs to their own department.
 */
if (
    !$can_view
    && $role === "IT Administrator"
    && $report["project_department"] === $user_department
) {
    $can_view = true;
}

/*
 * Manager visibility.
 */
if (
    !$can_view
    && $role === "Manager"
    && in_array(
        $report["creator_level"],
        ["Employee", "Team Lead"],
        true
    )
) {
    if (
        $report["project_department"] === $user_department
        || (
            $report["project_manager_id"] !== null
            && (int)$report["project_manager_id"] === $user_id
        )
    ) {
        $can_view = true;
    }
}

/*
 * Team Lead visibility.
 *
 * This applies to a user who actually leads a team on this project,
 * even if their base users.role value is not literally "Team Lead".
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

    $creator_id = (int)$report["created_by"];
    $project_id = (int)$report["project_id"];

    $stmt->bind_param(
        "iii",
        $project_id,
        $user_id,
        $creator_id
    );
    $stmt->execute();

    $total =
        (int)$stmt->get_result()->fetch_assoc()["total"];

    $stmt->close();

    if ($total > 0) {
        $can_view = true;
    }
}

if (!$can_view) {
    http_response_code(403);
    exit("You do not have permission to view this report.");
}

/*
 * Only the report creator can edit their report.
 * Administrators can view everyone's reports, but do not automatically
 * become the owner of someone else's report.
 */
$can_edit = ((int)$report["created_by"] === $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>View Report</title>

<link rel="stylesheet" href="app.css?v=22">

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
        <div class="sidebar-item active">
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
            <h1><?php echo e($report["title"]); ?></h1>

            <div class="sub">
                <?php echo e($report["project_name"]); ?>
            </div>
        </div>

        <div class="header-actions">

            <a class="button" href="comments.php?module=Report&id=<?php echo (int)$report["id"]; ?>">COMMENTS</a>

            <a class="button" href="print_report.php?id=<?php echo (int)$report["id"]; ?>" target="_blank">PRINT / EXPORT PDF</a>

            <a
                class="button"
                href="reports.php"
            >
                BACK TO REPORTS
            </a>

            <?php if ($can_edit): ?>

                <a
                    class="button primary"
                    href="edit_report.php?id=<?php echo (int)$report["id"]; ?>"
                >
                    EDIT REPORT
                </a>

            <?php endif; ?>

        </div>

    </div>

    <div class="content">

    <div class="card">

        <h2>Report Information</h2>

        <div class="info-grid">

            <div class="detail">
                <span class="label">Report Title</span>

                <span class="value">
                    <?php echo e($report["title"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Project</span>

                <span class="value">
                    <?php echo e($report["project_name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Report Type</span>

                <span class="value">
                    <?php echo e($report["report_type"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Report Level</span>

                <span class="value">
                    <span class="level">
                        <?php echo e($report["creator_level"]); ?>
                    </span>
                </span>
            </div>

            <div class="detail">
                <span class="label">Created By</span>

                <span class="value">
                    <?php
                    echo e(
                        $report["first_name"]
                        . " "
                        . $report["last_name"]
                    );
                    ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Reporting Period</span>

                <span class="value">
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
                </span>
            </div>

            <div class="detail">
                <span class="label">Created Date</span>

                <span class="value">
                    <?php
                    echo e(
                        date(
                            "M j, Y g:i A",
                            strtotime($report["created_at"])
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
                            strtotime($report["updated_at"])
                        )
                    );
                    ?>
                </span>
            </div>

        </div>

    </div>

    <div class="card">

        <h2>Report Content</h2>

        <div class="report-section">

            <h3>Summary</h3>

            <div class="report-text"><?php echo e($report["summary"]); ?></div>

        </div>

        <div class="report-section">

            <h3>Accomplishments</h3>

            <div class="report-text"><?php echo e($report["accomplishments"]); ?></div>

        </div>

        <div class="report-section">

            <h3>Issues / Risks</h3>

            <div class="report-text"><?php echo e($report["issues_risks"]); ?></div>

        </div>

        <div class="report-section">

            <h3>Next Steps</h3>

            <div class="report-text"><?php echo e($report["next_steps"]); ?></div>

        </div>

    </div>

</div>

</div>

</div>

</body>
</html>
