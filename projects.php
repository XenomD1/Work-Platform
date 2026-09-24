<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";
require_once "saved_view_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

/*
    Re-fetch the current user from the database rather than trusting
    $_SESSION["role"]/$_SESSION["department"] for permission checks.
    Every permission decision on this page (Archive, Edit Project, New
    Task, Save As Template, the sidebar's User Management link) is
    driven by $me below instead of $_SESSION - so a user who is
    deactivated or has their role/department changed while they still
    have an open session loses those capabilities immediately, the
    same way every other module on this platform already behaves.
*/
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
   LOAD PROJECTS FROM DATABASE
------------------------------------------------- */

$projects = [];

$result = $conn->query("SELECT * FROM projects ORDER BY id ASC");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $project_id = $row["id"];

        $projects[$project_id] = $row;


        /* ---------------------------------------------
           LOAD OBJECTIVES
        --------------------------------------------- */

        $projects[$project_id]["objectives"] = [];

        $stmt = $conn->prepare("
            SELECT objective
            FROM objectives
            WHERE project_id = ?
            ORDER BY id ASC
        ");

        $stmt->bind_param("i", $project_id);
        $stmt->execute();

        $objective_result = $stmt->get_result();

        while ($item = $objective_result->fetch_assoc()) {
            $projects[$project_id]["objectives"][] =
                $item["objective"];
        }

        $stmt->close();


        /* ---------------------------------------------
           LOAD DELIVERABLES
        --------------------------------------------- */

        $projects[$project_id]["deliverables"] = [];

        $stmt = $conn->prepare("
            SELECT deliverable
            FROM deliverables
            WHERE project_id = ?
            ORDER BY id ASC
        ");

        $stmt->bind_param("i", $project_id);
        $stmt->execute();

        $deliverable_result = $stmt->get_result();

        while ($item = $deliverable_result->fetch_assoc()) {
            $projects[$project_id]["deliverables"][] =
                $item["deliverable"];
        }

        $stmt->close();


        /* ---------------------------------------------
           LOAD MILESTONES
        --------------------------------------------- */

        $projects[$project_id]["milestones"] = [];

        $stmt = $conn->prepare("
            SELECT milestone, due_date, status, completion
            FROM milestones
            WHERE project_id = ?
            ORDER BY id ASC
        ");

        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $milestone_result = $stmt->get_result();

        while ($item = $milestone_result->fetch_assoc()) {
            $projects[$project_id]["milestones"][] =$item;
        }

        $stmt->close();


/* ---------------------------------------------
   CALCULATE PROJECT COMPLETION FROM MILESTONES
--------------------------------------------- */

if (!empty($projects[$project_id]["milestones"])) {

    $total_completion = 0;
    $milestone_count = count($projects[$project_id]["milestones"]);

    foreach ($projects[$project_id]["milestones"] as $milestone) {
        $total_completion += (float) $milestone["completion"];
    }

    $projects[$project_id]["completion"] =
        $total_completion / $milestone_count;
}


        /* ---------------------------------------------
           LOAD PRIORITIES
        --------------------------------------------- */

        $projects[$project_id]["priorities"] = [];

        $stmt = $conn->prepare("
            SELECT priority
            FROM priorities
            WHERE project_id = ?
            ORDER BY id ASC
        ");

        $stmt->bind_param("i", $project_id);
        $stmt->execute();

        $priority_result = $stmt->get_result();

        while ($item = $priority_result->fetch_assoc()) {
            $projects[$project_id]["priorities"][] =
                $item["priority"];
        }

        $stmt->close();


        /* ---------------------------------------------
           LOAD RISKS
        --------------------------------------------- */

        $projects[$project_id]["risks"] = [];

        $stmt = $conn->prepare("
            SELECT risk
            FROM risks
            WHERE project_id = ?
            ORDER BY id ASC
        ");

        $stmt->bind_param("i", $project_id);
        $stmt->execute();

        $risk_result = $stmt->get_result();

        while ($item = $risk_result->fetch_assoc()) {
            $projects[$project_id]["risks"][] =
                $item["risk"];
        }

        $stmt->close();
    }
}

/* -------------------------------------------------
   SELECT PROJECT
------------------------------------------------- */

$selected = isset($_GET["project"])
    ? (int)$_GET["project"]
    : (!empty($projects) ? (int)array_key_first($projects) : 0);

if (!isset($projects[$selected])) {

    if (!empty($projects)) {
        $selected = array_key_first($projects);
    } else {
        $selected = null;
    }
}

$project = $selected !== null
    ? $projects[$selected]
    : null;

/* -------------------------------------------------
   LOAD TASKS FOR SELECTED PROJECT
------------------------------------------------- */

$project_tasks = [];

if ($selected !== null) {

    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.name,
            t.priority,
            t.status,
            t.completion,
            t.start_date,
            t.due_date,
            GROUP_CONCAT(
                CONCAT(u.first_name, ' ', u.last_name)
                ORDER BY u.last_name, u.first_name
                SEPARATOR ', '
            ) AS assigned_to
        FROM tasks t
        LEFT JOIN task_assignments ta
            ON t.id = ta.task_id
        LEFT JOIN users u
            ON ta.user_id = u.id
        WHERE t.project_id = ?
        GROUP BY
            t.id,
            t.name,
            t.priority,
            t.status,
            t.completion,
            t.start_date,
            t.due_date
        ORDER BY t.id ASC
    ");

    $stmt->bind_param("i", $selected);
    $stmt->execute();

    $task_result = $stmt->get_result();

    while ($task = $task_result->fetch_assoc()) {
        $project_tasks[] = $task;
    }

    $stmt->close();
}

/* -------------------------------------------------
   HTML ESCAPE FUNCTION
------------------------------------------------- */

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
    Filter option lists, built from the projects already loaded above
    rather than a fresh query - department/status are free text
    fields, so these are just the distinct values actually in use.
*/
$available_departments = [];
$available_statuses = [];

foreach ($projects as $p) {
    if (!empty($p["department"]) && !in_array($p["department"], $available_departments, true)) {
        $available_departments[] = $p["department"];
    }
    if (!empty($p["status"]) && !in_array($p["status"], $available_statuses, true)) {
        $available_statuses[] = $p["status"];
    }
}

sort($available_departments);
sort($available_statuses);

$department_filter = trim($_GET["department"] ?? "");
$status_filter = trim($_GET["status"] ?? "");

/*
    Archive view: "active" (default) hides archived projects so they
    stop cluttering the list, "archived" shows only archived ones,
    "all" shows both. Archived projects stay fully reachable directly
    (e.g. a saved view or a direct ?project= link still loads them) -
    this filter only affects what appears in the browsable list.
*/
$archive_view = $_GET["archived"] ?? "active";

if (!in_array($archive_view, ["active", "archived", "all"], true)) {
    $archive_view = "active";
}

$saved_views = getSavedViews($conn, $current_user_id, "Projects");

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Projects</title>


<link rel="stylesheet" href="app.css?v=22">

</head>


<body>


<!-- =====================================================
     PROJECT PAGE
====================================================== -->

<div class="page">


    <!-- =================================
         SIDEBAR
    ================================= -->

    <div class="sidebar">
        <div class="sidebar-spacer"></div>
        <div class="sidebar-item">
            <a href="dashboard.php">Dashboard</a>
        </div>
        <div class="sidebar-item">
            <a href="tasks.php">Tasks</a>
        </div>
        <div class="sidebar-item active">
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
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($current_role === "Administrator"): ?>
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


    <div class="split-main">

    <!-- ================================================
         PROJECT LIST
    ================================================= -->

    <div class="project-list">

        <div class="project-list-header">

            <form method="GET">

                <input
                    class="search-box"
                    type="text"
                    name="search"
                    placeholder="Search Projects"
                    value="<?php echo e($_GET["search"] ?? ""); ?>"
                >

                <select name="department" onchange="this.form.submit()" style="margin-top:8px;width:100%;">
                    <option value="">All Departments</option>
                    <?php foreach ($available_departments as $department_option): ?>
                        <option value="<?php echo e($department_option); ?>" <?php echo $department_filter === $department_option ? "selected" : ""; ?>>
                            <?php echo e($department_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" onchange="this.form.submit()" style="margin-top:8px;width:100%;">
                    <option value="">All Statuses</option>
                    <?php foreach ($available_statuses as $status_option): ?>
                        <option value="<?php echo e($status_option); ?>" <?php echo $status_filter === $status_option ? "selected" : ""; ?>>
                            <?php echo e($status_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="archived" onchange="this.form.submit()" style="margin-top:8px;width:100%;">
                    <option value="active" <?php echo $archive_view === "active" ? "selected" : ""; ?>>Active Projects</option>
                    <option value="archived" <?php echo $archive_view === "archived" ? "selected" : ""; ?>>Archived Projects</option>
                    <option value="all" <?php echo $archive_view === "all" ? "selected" : ""; ?>>All Projects</option>
                </select>

            </form>

    	    <?php if (isAnyAdministrator($current_role)): ?>
                <a href="new_project.php" class="new-project-button">+ New Project</a>
                <a href="templates.php" class="new-project-button">Templates</a>
            <?php endif; ?>

        </div>

        <div style="padding:0 14px 12px;">

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px;">
                <strong>Saved Views:</strong>
                <?php if (!$saved_views): ?>
                    <span style="color:var(--slate);">None yet</span>
                <?php else: ?>
                    <?php foreach ($saved_views as $view): ?>
                        <a class="button" href="projects.php?<?php echo e($view["query_string"]); ?>" style="padding:3px 8px;"><?php echo e($view["name"]); ?></a>
                        <form method="POST" action="saved_view_action.php" style="display:inline;" onsubmit="return confirm('Delete this saved view?');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="module" value="Projects">
                            <input type="hidden" name="view_id" value="<?php echo (int)$view["id"]; ?>">
                            <button class="button" type="submit" title="Delete" style="padding:3px 7px;">&times;</button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form method="POST" action="saved_view_action.php" id="save_view_form" style="display:flex;gap:6px;margin-top:8px;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="module" value="Projects">
                <input type="hidden" name="query_string" id="save_view_query_string" value="">
                <input type="text" name="name" placeholder="Name this view..." maxlength="100" style="flex:1;min-width:0;">
                <button class="button primary" type="submit" style="white-space:nowrap;">SAVE VIEW</button>
            </form>

        </div>

        <script>
        (function () {
            var form = document.getElementById("save_view_form");
            if (!form) return;
            form.addEventListener("submit", function () {
                document.getElementById("save_view_query_string").value =
                    window.location.search.replace(/^\?/, "");
            });
        })();
        </script>


        <?php

        $search = strtolower(trim($_GET["search"] ?? ""));

        foreach ($projects as $id => $p):

            if (
                $search !== "" &&
                strpos(strtolower($p["name"]), $search) === false
            ) {
                continue;
            }

            if (
                $department_filter !== ""
                && ($p["department"] ?? "") !== $department_filter
            ) {
                continue;
            }

            if (
                $status_filter !== ""
                && ($p["status"] ?? "") !== $status_filter
            ) {
                continue;
            }

            if ($archive_view === "active" && (int)($p["archived"] ?? 0) === 1) {
                continue;
            }

            if ($archive_view === "archived" && (int)($p["archived"] ?? 0) === 0) {
                continue;
            }

        ?>

            <a
		class="project-link <?php echo ($id == $selected) ? "selected" : ""; ?>"
		href="projects.php?project=<?php echo $id; ?>"
	    >
		<div class="project-link-name">
			<?php echo e($p["name"]); ?>
			<?php if ((int)($p["archived"] ?? 0) === 1): ?>
				<span style="font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:var(--slate);border:1px solid var(--slate);border-radius:3px;padding:1px 5px;margin-left:6px;">Archived</span>
			<?php endif; ?>
		</div>

		<div class="project-link-department">
			<?php echo e($p["department"]); ?>
		</div>

		<div class="project-link-info">
			<?php echo e($p["status"]); ?> · <?php echo e($p["completion"]); ?>%
		</div>
            </a>

        <?php endforeach; ?>

    </div>


    <!-- ================================================
         PROJECT DETAILS
    ================================================= -->

    <div class="project-main">


        <!-- PROJECT NAME / TYPE / DEPARTMENT -->

        <div class="project-header">

            <div class="project-header-top">
                <div class="project-title">
                    <?php echo e($project["name"]); ?>
                    <?php if ((int)($project["archived"] ?? 0) === 1): ?>
                        <span style="font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--slate);border:1px solid var(--slate);border-radius:3px;padding:2px 7px;margin-left:8px;vertical-align:middle;">Archived</span>
                    <?php endif; ?>
                </div>

                <a href="comments.php?module=Project&id=<?php echo (int)$selected; ?>" class="btn">Comments</a>

                <a href="print_project.php?id=<?php echo (int)$selected; ?>" class="btn" target="_blank">Print / Export PDF</a>

                <?php
                $can_save_as_template =
                    $current_role === "Administrator"
                    || (
                        ($current_role === "Manager" || $current_role === "IT Administrator")
                        && $project["department"] === $current_department
                    )
                    || (int)($project["manager_id"] ?? 0) === $current_user_id;
                ?>

                <?php if ($can_save_as_template): ?>
                    <a href="save_as_template.php?project=<?php echo (int)$selected; ?>" class="btn">
                        Save As Template
                    </a>
                <?php endif; ?>

                <?php
                $can_archive =
                    canAdministerDepartment($current_role, $current_department, $project["department"])
                    || (int)($project["manager_id"] ?? 0) === $current_user_id;
                ?>

                <?php if ($can_archive): ?>
                    <form method="POST" action="toggle_project_archive.php" style="display:inline;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="project_id" value="<?php echo (int)$selected; ?>">
                        <button type="submit" class="btn">
                            <?php echo (int)($project["archived"] ?? 0) === 1 ? "Unarchive" : "Archive"; ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (canAdministerDepartment($current_role, $current_department, $project["department"])): ?>
                    <a href="edit_project.php?project=<?php echo (int)$selected; ?>" class="btn btn-primary">
                        Edit Project
                    </a>
                <?php endif; ?>
            </div>

            <div class="project-type">
                Type: <?php echo e($project["type"]); ?>
            </div>

            <div class="project-department">
                Department: <?php echo e($project["department"]); ?>
            </div>

        </div>


        <!-- DESCRIPTION -->

        <div class="description">

            <?php echo e($project["description"]); ?>

        </div>


        <!-- START / END DATE -->

        <div class="dates">

            <div class="date-item">
                Start Date: <?php echo e($project["start_date"]); ?>
            </div>

            <div class="date-item">
                End Date: <?php echo e($project["end_date"]); ?>
            </div>

        </div>


        <!-- MANAGER / STATUS / PHASE -->

        <div class="info-row">

            <div class="info-cell">
                Project Manager: <?php echo e($project["manager"]); ?>
            </div>

            <div class="info-cell">
                Status: <?php echo e($project["status"]); ?>
            </div>

            <div class="info-cell">
                Phase: <?php echo e($project["phase"]); ?>
            </div>

        </div>


        <!-- COMPLEXITY / COST / ROI / COMPLETION -->

        <div class="metrics">

            <div class="metric">
                Complexity: <?php echo e($project["complexity"]); ?>
            </div>

            <div class="metric">
                Cost: <?php echo e($project["cost"]); ?>
            </div>

            <div class="metric">
                Projected ROI: <?php echo e($project["roi"]); ?>
            </div>

            <div class="metric">
                Completion: <?php echo e($project["completion"]); ?>
            </div>

        </div>


        <!-- OVERVIEW -->

        <div class="overview">

            Overview: <?php echo e($project["overview"]); ?>

        </div>


        <!-- PROJECT INFORMATION BOXES -->

        <div class="boxes">


            <!-- OBJECTIVES -->

            <div class="info-box">

                <div class="info-box-title">
                    Objectives
                </div>

                <ul>

                    <?php foreach ($project["objectives"] as $point): ?>

                        <li><?php echo e($point); ?></li>

                    <?php endforeach; ?>

                </ul>

            </div>


            <!-- KEY DELIVERABLES -->

            <div class="info-box">

                <div class="info-box-title">
                    Key Deliverables
                </div>

                <ul>

                    <?php foreach ($project["deliverables"] as $point): ?>

                        <li><?php echo e($point); ?></li>

                    <?php endforeach; ?>

                </ul>

            </div>


            <!-- MILESTONES -->

            <div class="info-box info-box-wide">

                <div class="info-box-title">
                    Milestones
                </div>

                <div class="milestone-list">

                    <?php foreach ($project["milestones"] as $milestone): ?>

                        <div class="milestone-row">

                            <div class="milestone-name">
                                <?php echo e($milestone["milestone"]); ?>
                            </div>

                            <div class="milestone-due">
                                Due: <?php echo e($milestone["due_date"]); ?>
                            </div>

                            <div class="milestone-status">
                                Status: <?php echo e($milestone["status"]); ?>
                            </div>

                            <div class="milestone-completion">
                                Completion: <?php echo e($milestone["completion"]); ?>%
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>


            <!-- PRIORITIES -->

            <div class="info-box">

                <div class="info-box-title">
                    Priorities
                </div>

                <ul>

                    <?php foreach ($project["priorities"] as $point): ?>

                        <li><?php echo e($point); ?></li>

                    <?php endforeach; ?>

                </ul>

            </div>


            <!-- RISKS / ISSUES -->

            <div class="info-box">

                <div class="info-box-title">
                    Risks/Issues
                </div>

                <ul>

                    <?php foreach ($project["risks"] as $point): ?>

                        <li><?php echo e($point); ?></li>

                    <?php endforeach; ?>

                </ul>

            </div>


        </div>

        <!-- TASKS -->
<div class="tasks-section">

    <div class="tasks-header">
        <div class="tasks-title">Tasks</div>

        <?php if (canAdministerDepartment($current_role, $current_department, $project["department"])): ?>
            <a href="new_task.php?project=<?php echo $selected; ?>" class="new-task-button">
                + New Task
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($project_tasks)): ?>

        <div class="task-grid">

            <?php foreach ($project_tasks as $task): ?>

                <div class="task-card">

                    <div class="task-name">
                        <?php echo e($task["name"]); ?>
                    </div>

                    <div class="task-row">
                        <div><strong>Assigned To:</strong> <?php echo e($task["assigned_to"] ?: "Unassigned"); ?></div>
                        <div><strong>Priority:</strong> <?php echo e($task["priority"]); ?></div>
                        <div><strong>Status:</strong> <?php echo e($task["status"]); ?></div>
                        <div><strong>Completion:</strong> <?php echo e($task["completion"]); ?>%</div>
                        <div><strong>Due Date:</strong> <?php echo e($task["due_date"]); ?></div>
                    </div>

                    <div class="task-actions">
                        <a href="view_task.php?id=<?php echo (int)$task["id"]; ?>" class="task-button">View Task</a>

                        <?php if (canAdministerDepartment($current_role, $current_department, $project["department"])): ?>
                            <a href="edit_task.php?id=<?php echo (int)$task["id"]; ?>" class="task-button">Edit Task</a>
                        <?php endif; ?>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>
        <div class="no-tasks">No tasks assigned to this project.</div>
    <?php endif; ?>

</div>

<!-- ADDITIONAL INFORMATION -->

        <div class="additional">

            Additional Information

            <?php if ($project["additional"] !== ""): ?>

                : <?php echo e($project["additional"]); ?>

            <?php endif; ?>

        </div>


    </div>

    </div>

</div>

</body>
</html>
