<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";

$current_user_id = (int)$_SESSION["user_id"];

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
    : 3;

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

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Projects</title>


<link rel="stylesheet" href="app.css?v=9">

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

            </form>

    	    <?php if (isAnyAdministrator($_SESSION["role"])): ?>
                <a href="new_project.php" class="new-project-button">+ New Project</a>
            <?php endif; ?>

        </div>


        <?php

        $search = strtolower(trim($_GET["search"] ?? ""));

        foreach ($projects as $id => $p):

            if (
                $search !== "" &&
                strpos(strtolower($p["name"]), $search) === false
            ) {
                continue;
            }

        ?>

            <a
		class="project-link <?php echo ($id == $selected) ? "selected" : ""; ?>"
		href="projects.php?project=<?php echo $id; ?>"
	    >
		<div class="project-link-name">
			<?php echo e($p["name"]); ?>
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
                </div>

                <?php if (canAdministerDepartment($_SESSION["role"], $_SESSION["department"] ?? "", $project["department"])): ?>
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

        <?php if (canAdministerDepartment($_SESSION["role"], $_SESSION["department"] ?? "", $project["department"])): ?>
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

                        <?php if (canAdministerDepartment($_SESSION["role"], $_SESSION["department"] ?? "", $project["department"])): ?>
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
