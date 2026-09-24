<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "notification_helpers.php";
require_once "csrf_helpers.php";
function projectReportNotificationRecipientIds(
    mysqli $conn,
    int $project_id,
    int $exclude_user_id = 0
): array {
    $ids = [];

    $stmt = $conn->prepare("
        SELECT manager_id
        FROM projects
        WHERE id = ?
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($project && !empty($project["manager_id"])) {
        $ids[] = (int)$project["manager_id"];
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT team_lead_id
        FROM teams
        WHERE project_id = ?
          AND team_lead_id IS NOT NULL
    ");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row["team_lead_id"];
    }

    $stmt->close();

    $ids = array_values(array_unique(array_filter(array_map("intval", $ids))));

    if ($exclude_user_id > 0) {
        $ids = array_values(
            array_filter(
                $ids,
                static fn($id) => $id !== $exclude_user_id
            )
        );
    }

    return $ids;
}


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
$current_full_name = trim(
    $current_user["first_name"] . " " . $current_user["last_name"]
);

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

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
 * A user can create a report only for a project they have worked on.
 *
 * For this platform, "worked on" means at least one of:
 * - They are the Project Manager.
 * - They are a member of a team on that project.
 * - They are assigned to a task on that project.
 */
$projects = [];

$result = $conn->query("
    SELECT
        id,
        name,
        department,
        manager_id
    FROM projects
    ORDER BY name ASC
");

if ($result) {
    while ($project = $result->fetch_assoc()) {

        $project_id_check = (int)$project["id"];
        $worked_on_project = false;

        if (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            $worked_on_project = true;
        }

        if (!$worked_on_project) {

            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM teams t
                INNER JOIN team_members tm
                    ON tm.team_id = t.id
                WHERE t.project_id = ?
                  AND tm.user_id = ?
            ");
            $stmt->bind_param(
                "ii",
                $project_id_check,
                $user_id
            );
            $stmt->execute();

            $worked_on_project =
                (int)$stmt->get_result()->fetch_assoc()["total"] > 0;

            $stmt->close();
        }

        if (!$worked_on_project) {

            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM tasks t
                INNER JOIN task_assignments ta
                    ON ta.task_id = t.id
                WHERE t.project_id = ?
                  AND ta.user_id = ?
            ");
            $stmt->bind_param(
                "ii",
                $project_id_check,
                $user_id
            );
            $stmt->execute();

            $worked_on_project =
                (int)$stmt->get_result()->fetch_assoc()["total"] > 0;

            $stmt->close();
        }

        if ($worked_on_project) {
            $projects[] = $project;
        }
    }
}

/*
 * If the user has not worked on a project yet,
 * they cannot create a report.
 */
if (!$projects) {
    http_response_code(403);
    exit(
        "You cannot create a report because no projects are currently "
        . "associated with your work."
    );
}

$title = "";
$project_id = 0;
$report_type = "";
$period_start = "";
$period_end = "";
$summary = "";
$accomplishments = "";
$issues_risks = "";
$next_steps = "";
$errors = [];

/*
 * Determine creator visibility level for the selected project.
 *
 * Administrator role -> Administrator report
 * Manager role       -> Manager report
 * Otherwise, if the user leads a team on the project -> Team Lead report
 * Otherwise          -> Employee report
 */
function getCreatorLevel(
    $conn,
    $user_id,
    $role,
    $project_id
) {
    if ($role === "Administrator") {
        return "Administrator";
    }

    if ($role === "Manager" || $role === "IT Administrator") {
        return "Manager";
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams
        WHERE project_id = ?
          AND team_lead_id = ?
    ");
    $stmt->bind_param(
        "ii",
        $project_id,
        $user_id
    );
    $stmt->execute();

    $is_team_lead =
        (int)$stmt->get_result()->fetch_assoc()["total"] > 0;

    $stmt->close();

    return $is_team_lead
        ? "Team Lead"
        : "Employee";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $title = trim($_POST["title"] ?? "");
    $project_id = (int)($_POST["project_id"] ?? 0);
    $report_type = trim($_POST["report_type"] ?? "");
    $period_start = trim($_POST["period_start"] ?? "");
    $period_end = trim($_POST["period_end"] ?? "");
    $summary = trim($_POST["summary"] ?? "");
    $accomplishments = trim($_POST["accomplishments"] ?? "");
    $issues_risks = trim($_POST["issues_risks"] ?? "");
    $next_steps = trim($_POST["next_steps"] ?? "");

    if ($title === "") {
        $errors[] = "Report Title is required.";
    }

    if ($project_id <= 0) {
        $errors[] = "Project is required.";
    }

    if (!in_array($report_type, $report_types, true)) {
        $errors[] = "Please select a valid Report Type.";
    }

    if ($period_start === "") {
        $errors[] = "Reporting Period Start Date is required.";
    }

    if ($period_end === "") {
        $errors[] = "Reporting Period End Date is required.";
    }

    if (
        $period_start !== ""
        && $period_end !== ""
        && $period_end < $period_start
    ) {
        $errors[] =
            "Reporting Period End Date cannot be before the Start Date.";
    }

    if ($summary === "") {
        $errors[] = "Summary is required.";
    }

    if ($accomplishments === "") {
        $errors[] = "Accomplishments are required.";
    }

    if ($issues_risks === "") {
        $errors[] = "Issues / Risks is required.";
    }

    if ($next_steps === "") {
        $errors[] = "Next Steps is required.";
    }

    /*
     * Verify selected project is one the current user actually worked on.
     */
    $allowed_project_ids = array_map(
        fn($project) => (int)$project["id"],
        $projects
    );

    if (
        $project_id > 0
        && !in_array(
            $project_id,
            $allowed_project_ids,
            true
        )
    ) {
        $errors[] =
            "You can only create reports for projects you have worked on.";
    }

    if (!$errors) {

        $creator_level = getCreatorLevel(
            $conn,
            $user_id,
            $role,
            $project_id
        );

        $stmt = $conn->prepare("
            INSERT INTO reports
            (
                title,
                project_id,
                report_type,
                period_start,
                period_end,
                summary,
                accomplishments,
                issues_risks,
                next_steps,
                created_by,
                creator_level
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sisssssssis",
            $title,
            $project_id,
            $report_type,
            $period_start,
            $period_end,
            $summary,
            $accomplishments,
            $issues_risks,
            $next_steps,
            $user_id,
            $creator_level
        );

        if ($stmt->execute()) {

            $report_id = $stmt->insert_id;
            $stmt->close();

            $creator_name = trim(
                $current_user["first_name"] . " " . $current_user["last_name"]
            );

            foreach (
                projectReportNotificationRecipientIds(
                    $conn,
                    $project_id,
                    $user_id
                ) as $recipient_id
            ) {
                createNotification(
                    $conn,
                    $recipient_id,
                    "Report",
                    "New Project Report",
                    $creator_name . " created the report \"" . $title . "\".",
                    "Reports",
                    $report_id,
                    "view_report.php?id=" . $report_id
                );
            }

            header(
                "Location: view_report.php?id="
                . $report_id
            );
            exit;

        } else {

            $errors[] = "Unable to create the report.";
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Report</title>
    <link rel="stylesheet" href="app.css?v=20">
</head>
<body>
<div class="page">
    <div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div>
        <div class="sidebar-item"><a href="tasks.php">Tasks</a></div>
        <div class="sidebar-item"><a href="projects.php">Projects</a></div>
        <div class="sidebar-item"><a href="calendar.php">Calendar</a></div>
        <div class="sidebar-item"><a href="events.php">Meetings / Events</a></div>
        <div class="sidebar-item"><a href="teams.php">Teams</a></div>
        <div class="sidebar-item"><a href="org_chart.php">Organization Chart</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item active"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="analytics.php">Analytics</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="timesheets.php">Timesheets</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (canPostAnnouncements($role)): ?>
        <div class="sidebar-item"><a href="announcements.php">Announcements</a></div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($role)): ?>
        <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>

        <?php if ($role === "Administrator"): ?>
        <div class="sidebar-item"><a href="roles.php">Roles &amp; Permissions</a></div>
        <?php endif; ?>

<div class="sidebar-bottom">
            <div class="sidebar-item"><a href="account.php">Account</a></div>
            <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
    </div>

    <main class="main">
        <div class="header">
            <div class="header-title">Create Report</div>
            <a class="button" href="reports.php">Back to Reports</a>
        </div>

        <div class="content">
            <div class="sub">Create a report for a project you have worked on</div>

            <?php if ($errors): ?>
                <div class="message">
                    <strong>Please correct the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?= csrfField() ?>
                <div class="card">
                    <h2>Report Information</h2>
                    <div class="form-grid">
                        <div class="form-row full">
                            <label for="title">Report Title <span class="required">*</span></label>
                            <input type="text" id="title" name="title" value="<?php echo e($title); ?>" required>
                        </div>

                        <div class="form-row">
                            <label for="project_id">Project <span class="required">*</span></label>
                            <select id="project_id" name="project_id" required>
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo (int)$project["id"]; ?>" <?php echo $project_id === (int)$project["id"] ? "selected" : ""; ?>>
                                        <?php echo e($project["name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="report-note">Only projects associated with your work are listed.</div>
                        </div>

                        <div class="form-row">
                            <label for="report_type">Report Type <span class="required">*</span></label>
                            <select id="report_type" name="report_type" required>
                                <option value="">Select Report Type</option>
                                <?php foreach ($report_types as $type): ?>
                                    <option value="<?php echo e($type); ?>" <?php echo $report_type === $type ? "selected" : ""; ?>><?php echo e($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <label for="period_start">Reporting Period Start <span class="required">*</span></label>
                            <input type="date" id="period_start" name="period_start" value="<?php echo e($period_start); ?>" required>
                        </div>

                        <div class="form-row">
                            <label for="period_end">Reporting Period End <span class="required">*</span></label>
                            <input type="date" id="period_end" name="period_end" value="<?php echo e($period_end); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>Report Content</h2>
                    <div class="form-grid">
                        <div class="form-row full">
                            <label for="summary">Summary <span class="required">*</span></label>
                            <textarea id="summary" name="summary" required><?php echo e($summary); ?></textarea>
                        </div>
                        <div class="form-row full">
                            <label for="accomplishments">Accomplishments <span class="required">*</span></label>
                            <textarea id="accomplishments" name="accomplishments" required><?php echo e($accomplishments); ?></textarea>
                        </div>
                        <div class="form-row full">
                            <label for="issues_risks">Issues / Risks <span class="required">*</span></label>
                            <textarea id="issues_risks" name="issues_risks" required><?php echo e($issues_risks); ?></textarea>
                        </div>
                        <div class="form-row full">
                            <label for="next_steps">Next Steps <span class="required">*</span></label>
                            <textarea id="next_steps" name="next_steps" required><?php echo e($next_steps); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="report-actions">
                    <a class="button" href="reports.php">Cancel</a>
                    <button class="button primary" type="submit">Create Report</button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>
