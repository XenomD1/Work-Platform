<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

$me_stmt = $conn->prepare("
    SELECT role, department
    FROM users
    WHERE id = ? AND active = 1
");
$me_stmt->bind_param("i", $current_user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();
$me_stmt->close();

if (!$me) {
    header("Location: login.php");
    exit;
}

$current_role = $me["role"];
$current_department = $me["department"] ?? "";

if (!isAnyAdministrator($current_role)) {
    http_response_code(403);
    exit("Access denied.");
}

$project_id = (int)($_GET["project"] ?? $_GET["id"] ?? $_POST["project_id"] ?? 0);

if ($project_id <= 0) {
    header("Location: projects.php");
    exit;
}

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/* -------------------------------------------------
   ACTIVE USERS (for the Project Manager selector)
------------------------------------------------- */

$manager_options = [];

$manager_options_result = $conn->query("
    SELECT id, first_name, last_name
    FROM users
    WHERE active = 1
    ORDER BY last_name ASC, first_name ASC
");

if ($manager_options_result) {
    while ($row = $manager_options_result->fetch_assoc()) {
        $manager_options[] = $row;
    }
}

/* -------------------------------------------------
   LOAD THE PROJECT BEING EDITED
------------------------------------------------- */

$project_stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
$project_stmt->bind_param("i", $project_id);
$project_stmt->execute();
$project = $project_stmt->get_result()->fetch_assoc();
$project_stmt->close();

if (!$project) {
    header("Location: projects.php");
    exit;
}

if (
    !canAdministerDepartment(
        $current_role,
        $current_department,
        $project["department"]
    )
) {
    http_response_code(403);
    exit("Access denied.");
}

$project["objectives"] = [];
$stmt = $conn->prepare("SELECT objective FROM objectives WHERE project_id = ? ORDER BY id ASC");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res = $stmt->get_result();
while ($item = $res->fetch_assoc()) { $project["objectives"][] = $item["objective"]; }
$stmt->close();

$project["deliverables"] = [];
$stmt = $conn->prepare("SELECT deliverable FROM deliverables WHERE project_id = ? ORDER BY id ASC");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res = $stmt->get_result();
while ($item = $res->fetch_assoc()) { $project["deliverables"][] = $item["deliverable"]; }
$stmt->close();

$project["milestones"] = [];
$stmt = $conn->prepare("SELECT milestone, due_date, status, completion FROM milestones WHERE project_id = ? ORDER BY id ASC");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res = $stmt->get_result();
while ($item = $res->fetch_assoc()) { $project["milestones"][] = $item; }
$stmt->close();

if (!empty($project["milestones"])) {
    $total_completion = 0;
    foreach ($project["milestones"] as $milestone) {
        $total_completion += (float)$milestone["completion"];
    }
    $project["completion"] = $total_completion / count($project["milestones"]);
}

$project["priorities"] = [];
$stmt = $conn->prepare("SELECT priority FROM priorities WHERE project_id = ? ORDER BY id ASC");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res = $stmt->get_result();
while ($item = $res->fetch_assoc()) { $project["priorities"][] = $item["priority"]; }
$stmt->close();

$project["risks"] = [];
$stmt = $conn->prepare("SELECT risk FROM risks WHERE project_id = ? ORDER BY id ASC");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res = $stmt->get_result();
while ($item = $res->fetch_assoc()) { $project["risks"][] = $item["risk"]; }
$stmt->close();

$error = "";

/* -------------------------------------------------
   DELETE PROJECT
------------------------------------------------- */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_project"])
) {
    requireValidCsrfToken();
    $confirmed = isset($_POST["confirm_delete"]);

    if (!$confirmed) {
        $error = "Please confirm project deletion before continuing.";
    } else {
        $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $stmt->close();

        header("Location: projects.php");
        exit;
    }
}

/* -------------------------------------------------
   SAVE PROJECT
------------------------------------------------- */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && !isset($_POST["delete_project"])
) {

    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $description = trim($_POST["description"] ?? "");

    if ($current_role === "IT Administrator") {
        // Cannot move a project out of the department they administer.
        $department = $current_department;
    }

    $start_date = $_POST["start_date"] ?? "";
    $start_date = ($start_date !== "") ? $start_date : null;

    $end_date = $_POST["end_date"] ?? "";
    $end_date = ($end_date !== "") ? $end_date : null;

    $manager_id = (int)($_POST["manager_id"] ?? 0);
    $manager = "";

    if ($manager_id > 0) {
        foreach ($manager_options as $person) {
            if ((int)$person["id"] === $manager_id) {
                $manager = trim($person["first_name"] . " " . $person["last_name"]);
                break;
            }
        }

        if ($manager === "") {
            // Submitted an id that isn't a real active user.
            $manager_id = 0;
        }
    }

    $status = trim($_POST["status"] ?? "");
    $phase = trim($_POST["phase"] ?? "");
    $complexity = trim($_POST["complexity"] ?? "");
    $cost = $_POST["cost"] ?? null;
    $roi = trim($_POST["roi"] ?? "");

    $overview = trim($_POST["overview"] ?? "");
    $additional = trim($_POST["additional"] ?? "");

    if ($name === "") {
        $error = "Project name is required.";
    } else {

        /* ---------------------------------------------
           COMPLETION: if this project has milestones,
           completion is always the average of their
           completion percentages, not a manually typed
           value - otherwise a manual edit here would just
           get silently overwritten the next time the page
           loads. Only use the typed value when there are
           no milestones.
        --------------------------------------------- */

        $milestone_names_input = $_POST["milestone_name"] ?? [];
        $milestone_status_input = $_POST["milestone_status"] ?? [];
        $milestone_completion_input = $_POST["milestone_completion"] ?? [];

        $milestone_total = 0;
        $milestone_count = 0;

        foreach ($milestone_names_input as $i => $milestone_name_input) {

            if (trim($milestone_name_input) === "") {
                continue;
            }

            $milestone_status_value = trim($milestone_status_input[$i] ?? "");
            $milestone_completion_value = (float)($milestone_completion_input[$i] ?? 0);

            if ($milestone_status_value === "Complete") {
                $milestone_completion_value = 100;
            } elseif ($milestone_status_value === "Not Started") {
                $milestone_completion_value = 0;
            }

            $milestone_total += $milestone_completion_value;
            $milestone_count++;
        }

        if ($milestone_count > 0) {

            $completion = $milestone_total / $milestone_count;

        } else {

            $completion = (float)($_POST["completion"] ?? 0);

            if ($completion < 0) {
                $completion = 0;
            }

            if ($completion > 100) {
                $completion = 100;
            }
        }

        /* ---------------------------------------------
           UPDATE MAIN PROJECT INFORMATION
        --------------------------------------------- */

        $stmt = $conn->prepare("
            UPDATE projects
            SET
                name = ?,
                type = ?,
                department = ?,
                description = ?,
                start_date = ?,
                end_date = ?,
                manager = ?,
                manager_id = ?,
                status = ?,
                phase = ?,
                complexity = ?,
                cost = ?,
                roi = ?,
                completion = ?,
                overview = ?,
                additional = ?
            WHERE id = ?
        ");

        $manager_id_value = $manager_id > 0 ? $manager_id : null;

        $stmt->bind_param(
            "sssssssisssdsdssi",
            $name,
            $type,
            $department,
            $description,
            $start_date,
            $end_date,
            $manager,
            $manager_id_value,
            $status,
            $phase,
            $complexity,
            $cost,
            $roi,
            $completion,
            $overview,
            $additional,
            $project_id
        );

        $stmt->execute();
        $stmt->close();

        /* ---------------------------------------------
           SAVE OBJECTIVES
        --------------------------------------------- */

        $conn->query("DELETE FROM objectives WHERE project_id = $project_id");

        $objectives = array_filter(array_map(
            "trim",
            preg_split("/\r\n|\r|\n/", $_POST["objectives"] ?? "")
        ));

        $stmt = $conn->prepare("INSERT INTO objectives (project_id, objective) VALUES (?, ?)");
        foreach ($objectives as $objective) {
            $stmt->bind_param("is", $project_id, $objective);
            $stmt->execute();
        }
        $stmt->close();

        /* ---------------------------------------------
           SAVE DELIVERABLES
        --------------------------------------------- */

        $conn->query("DELETE FROM deliverables WHERE project_id = $project_id");

        $deliverables = array_filter(array_map(
            "trim",
            preg_split("/\r\n|\r|\n/", $_POST["deliverables"] ?? "")
        ));

        $stmt = $conn->prepare("INSERT INTO deliverables (project_id, deliverable) VALUES (?, ?)");
        foreach ($deliverables as $deliverable) {
            $stmt->bind_param("is", $project_id, $deliverable);
            $stmt->execute();
        }
        $stmt->close();

        /* ---------------------------------------------
           SAVE MILESTONES
        --------------------------------------------- */

        $conn->query("DELETE FROM milestones WHERE project_id = $project_id");

        $milestone_names = $_POST["milestone_name"] ?? [];
        $milestone_due = $_POST["milestone_due"] ?? [];
        $milestone_status = $_POST["milestone_status"] ?? [];
        $milestone_completion = $_POST["milestone_completion"] ?? [];

        $stmt = $conn->prepare("
            INSERT INTO milestones (project_id, milestone, due_date, status, completion)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($milestone_names as $i => $milestone_name_value) {

            $milestone_name_value = trim($milestone_name_value);

            if ($milestone_name_value === "") {
                continue;
            }

            $due_date = $milestone_due[$i] ?? null;
            $m_status = trim($milestone_status[$i] ?? "");
            $m_completion = $milestone_completion[$i] ?? 0;

            if ($m_status === "Complete") {
                $m_completion = 100;
            } elseif ($m_status === "Not Started") {
                $m_completion = 0;
            }

            $stmt->bind_param(
                "isssd",
                $project_id,
                $milestone_name_value,
                $due_date,
                $m_status,
                $m_completion
            );
            $stmt->execute();
        }
        $stmt->close();

        /* ---------------------------------------------
           SAVE PRIORITIES
        --------------------------------------------- */

        $conn->query("DELETE FROM priorities WHERE project_id = $project_id");

        $priorities = array_filter(array_map(
            "trim",
            preg_split("/\r\n|\r|\n/", $_POST["priorities"] ?? "")
        ));

        $stmt = $conn->prepare("INSERT INTO priorities (project_id, priority) VALUES (?, ?)");
        foreach ($priorities as $priority) {
            $stmt->bind_param("is", $project_id, $priority);
            $stmt->execute();
        }
        $stmt->close();

        /* ---------------------------------------------
           SAVE RISKS
        --------------------------------------------- */

        $conn->query("DELETE FROM risks WHERE project_id = $project_id");

        $risks = array_filter(array_map(
            "trim",
            preg_split("/\r\n|\r|\n/", $_POST["risks"] ?? "")
        ));

        $stmt = $conn->prepare("INSERT INTO risks (project_id, risk) VALUES (?, ?)");
        foreach ($risks as $risk) {
            $stmt->bind_param("is", $project_id, $risk);
            $stmt->execute();
        }
        $stmt->close();

        /* ---------------------------------------------
           NOTIFICATIONS
        --------------------------------------------- */

        $previous_manager_id = (int)($project["manager_id"] ?? 0);

        if ($manager_id > 0 && $manager_id !== $previous_manager_id && $manager_id !== $current_user_id) {
            createNotification(
                $conn, $manager_id, "Project", "Project Manager Assignment",
                "You were assigned as the Project Manager for \"" . $name . "\".",
                "Projects", $project_id, "projects.php?project=" . $project_id
            );
        }

        if ($previous_manager_id > 0 && $previous_manager_id !== $manager_id && $previous_manager_id !== $current_user_id) {
            createNotification(
                $conn, $previous_manager_id, "Project", "Project Manager Assignment Removed",
                "You are no longer the Project Manager for \"" . $name . "\".",
                "Projects", $project_id, "projects.php?project=" . $project_id
            );
        }

        if ($manager_id > 0 && $manager_id === $previous_manager_id && $manager_id !== $current_user_id) {
            createNotification(
                $conn, $manager_id, "Project", "Project Updated",
                "The project \"" . $name . "\" was updated.",
                "Projects", $project_id, "projects.php?project=" . $project_id
            );
        }

        header("Location: projects.php?project=" . $project_id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Project</title>
<link rel="stylesheet" href="app.css?v=22">
</head>
<body>

<div class="page">

    <div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item"><a href="dashboard.php">Dashboard</a></div>
        <div class="sidebar-item"><a href="tasks.php">Tasks</a></div>
        <div class="sidebar-item active"><a href="projects.php">Projects</a></div>
        <div class="sidebar-item"><a href="calendar.php">Calendar</a></div>
        <div class="sidebar-item"><a href="events.php">Meetings / Events</a></div>
        <div class="sidebar-item"><a href="teams.php">Teams</a></div>
        <div class="sidebar-item"><a href="org_chart.php">Organization Chart</a></div>
        <div class="sidebar-item"><a href="files.php">Files</a></div>
        <div class="sidebar-item"><a href="reports.php">Reports</a></div>
        <div class="sidebar-item"><a href="analytics.php">Analytics</a></div>
        <div class="sidebar-item"><a href="training.php">Training</a></div>
        <div class="sidebar-item"><a href="time_off.php">Time Off</a></div>
        <div class="sidebar-item"><a href="timesheets.php">Timesheets</a></div>
        <div class="sidebar-item"><a href="notifications.php">Notifications</a></div>

        <?php if (canPostAnnouncements($current_role)): ?>
        <div class="sidebar-item"><a href="announcements.php">Announcements</a></div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($current_role)): ?>
        <div class="sidebar-item"><a href="users.php">User Management</a></div>
        <?php endif; ?>

        <?php if ($current_role === "Administrator"): ?>
        <div class="sidebar-item"><a href="roles.php">Roles &amp; Permissions</a></div>
        <?php endif; ?>

        <div class="sidebar-bottom">
            <div class="sidebar-item"><a href="account.php">Account</a></div>
            <div class="sidebar-item"><a href="settings.php">Settings</a></div>
        </div>
    </div>

    <main class="main">

        <div class="header">
            <div>
                <div class="header-title">Edit Project</div>
            </div>
            <div class="header-department"><?php echo e($project["name"]); ?></div>
        </div>

        <div class="content">

            <?php if ($error !== ""): ?>
                <div class="error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST">

                <?= csrfField() ?>

                <input type="hidden" name="project_id" value="<?php echo (int)$project_id; ?>">

                <div class="card">

                    <div class="edit-grid">

                        <div class="form-row">
                            <label>Project Name</label>
                            <input type="text" name="name" value="<?php echo e($project["name"]); ?>" required>
                        </div>

                        <div class="form-row">
                            <label>Project Type</label>
                            <input type="text" name="type" value="<?php echo e($project["type"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Department</label>
                            <input
                                type="text"
                                name="department"
                                value="<?php echo e($project["department"]); ?>"
                                <?php echo $current_role === "IT Administrator" ? "readonly" : ""; ?>
                            >
                        </div>

                        <div class="form-row">
                            <label>Project Manager</label>
                            <select name="manager_id">
                                <option value="">-- No Manager --</option>
                                <?php $current_manager_id = (int)($project["manager_id"] ?? 0); ?>
                                <?php foreach ($manager_options as $person): ?>
                                    <option
                                        value="<?php echo (int)$person["id"]; ?>"
                                        <?php echo $current_manager_id === (int)$person["id"] ? "selected" : ""; ?>
                                    >
                                        <?php echo e($person["first_name"] . " " . $person["last_name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row full">
                            <label>Description / Purpose</label>
                            <input type="text" name="description" value="<?php echo e($project["description"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo e($project["start_date"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?php echo e($project["end_date"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Status</label>
                            <input type="text" name="status" value="<?php echo e($project["status"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Phase</label>
                            <input type="text" name="phase" value="<?php echo e($project["phase"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Complexity</label>
                            <input type="text" name="complexity" value="<?php echo e($project["complexity"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Cost</label>
                            <input type="text" name="cost" value="<?php echo e($project["cost"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Projected ROI</label>
                            <input type="text" name="roi" value="<?php echo e($project["roi"]); ?>">
                        </div>

                        <div class="form-row">
                            <label>Completion</label>
                            <?php if (!empty($project["milestones"])): ?>
                                <input type="text" value="<?php echo e(round($project["completion"], 2)); ?>%" disabled>
                                <input type="hidden" name="completion" value="<?php echo e($project["completion"]); ?>">
                                <div class="help">Calculated automatically from milestone completion.</div>
                            <?php else: ?>
                                <input type="text" name="completion" value="<?php echo e($project["completion"]); ?>">
                            <?php endif; ?>
                        </div>

                        <div class="form-row full">
                            <label>Overview</label>
                            <textarea name="overview" rows="3"><?php echo e($project["overview"]); ?></textarea>
                        </div>

                        <div class="form-row">
                            <label>Objectives</label>
                            <textarea name="objectives" rows="4" placeholder="Enter one objective per line"><?php echo e(implode("\n", $project["objectives"])); ?></textarea>
                        </div>

                        <div class="form-row">
                            <label>Key Deliverables</label>
                            <textarea name="deliverables" rows="4" placeholder="Enter one deliverable per line"><?php echo e(implode("\n", $project["deliverables"])); ?></textarea>
                        </div>

                        <div class="form-row full">
                            <label>Milestones</label>
                            <div class="milestone-edit-list" id="milestone-edit-list">
                                <?php foreach ($project["milestones"] as $milestone): ?>
                                    <div class="milestone-edit-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                                        <input type="text" name="milestone_name[]" value="<?php echo e($milestone["milestone"]); ?>" placeholder="Milestone">
                                        <input type="date" name="milestone_due[]" value="<?php echo e($milestone["due_date"]); ?>">
                                        <input type="text" name="milestone_status[]" value="<?php echo e($milestone["status"]); ?>" placeholder="Status">
                                        <input type="number" name="milestone_completion[]" value="<?php echo e($milestone["completion"]); ?>" min="0" max="100" step="0.01" placeholder="Completion %">
                                        <button type="button" class="button" onclick="this.closest('.milestone-edit-row').remove()">REMOVE</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" onclick="addMilestoneRow()">+ ADD MILESTONE</button>
                            <div class="help">A blank milestone name is dropped when you save. Removing a row here and saving removes it permanently.</div>
                        </div>

                        <div class="form-row">
                            <label>Priorities</label>
                            <textarea name="priorities" rows="4" placeholder="Enter one priority per line"><?php echo e(implode("\n", $project["priorities"])); ?></textarea>
                        </div>

                        <div class="form-row">
                            <label>Risks / Issues</label>
                            <textarea name="risks" rows="4" placeholder="Enter one risk or issue per line"><?php echo e(implode("\n", $project["risks"])); ?></textarea>
                        </div>

                        <div class="form-row full">
                            <label>Additional Information</label>
                            <textarea name="additional" rows="3"><?php echo e($project["additional"]); ?></textarea>
                        </div>

                    </div>

                    <div class="toolbar" style="margin-top:20px;">
                        <a class="button" href="projects.php?project=<?php echo (int)$project_id; ?>">Cancel</a>
                        <button class="btn-primary" type="submit">Save Project</button>
                    </div>

                    <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--line);">
                        <label style="display:inline-flex; align-items:center; gap:7px; margin-right:10px; font-weight:normal;">
                            <input type="checkbox" name="confirm_delete" value="1" style="width:auto;">
                            Confirm project deletion
                        </label>

                        <button class="btn-danger" type="submit" name="delete_project" value="1">
                            Delete Project
                        </button>
                    </div>

                </div>

            </form>

        </div>

    </main>

</div>

<script>
function addMilestoneRow() {
    var list = document.getElementById("milestone-edit-list");
    var row = document.createElement("div");
    row.className = "milestone-edit-row";
    row.style.display = "flex";
    row.style.gap = "8px";
    row.style.alignItems = "center";
    row.style.marginBottom = "8px";
    row.innerHTML =
        '<input type="text" name="milestone_name[]" placeholder="Milestone">' +
        '<input type="date" name="milestone_due[]">' +
        '<input type="text" name="milestone_status[]" placeholder="Status">' +
        '<input type="number" name="milestone_completion[]" min="0" max="100" step="0.01" placeholder="Completion %">' +
        '<button type="button" class="button" onclick="this.closest(\'.milestone-edit-row\').remove()">REMOVE</button>';
    list.appendChild(row);
}
</script>

</body>
</html>
