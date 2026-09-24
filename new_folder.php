<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "csrf_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: login.php");
    exit;
}

$role = $user["role"];
$user_department = $user["department"];
$current_full_name = trim($user["first_name"] . " " . $user["last_name"]);

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Folder creation is limited to:
 * Administrators, Managers, Project Managers, and Team Leads.
 */
$can_create_folder = in_array($role, ["Administrator", "Manager", "IT Administrator"], true);

if (!$can_create_folder) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM projects
        WHERE manager_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $can_create_folder =
        (int)$stmt->get_result()->fetch_assoc()["total"] > 0;
    $stmt->close();
}

if (!$can_create_folder) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM teams
        WHERE team_lead_id = ?
          AND status = 'Active'
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $can_create_folder =
        (int)$stmt->get_result()->fetch_assoc()["total"] > 0;
    $stmt->close();
}

if (!$can_create_folder) {
    http_response_code(403);
    exit("You do not have permission to create folders.");
}

$location_types = [
    "Organization",
    "Department",
    "Project",
    "Task",
    "Team",
    "Event",
    "Training"
];

$name = "";
$location_type = "Organization";
$location_id = "";
$parent_folder_id = 0;
$errors = [];

/*
 * Departments.
 */
$departments = [];

$result = $conn->query("
    SELECT DISTINCT department
    FROM users
    WHERE active = 1
      AND department IS NOT NULL
      AND department <> ''
    ORDER BY department
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row["department"];
    }
}

/*
 * Projects this user is allowed to manage/create folders for.
 */
$projects = [];

$result = $conn->query("
    SELECT id, name, department, manager_id
    FROM projects
    ORDER BY name
");

if ($result) {
    while ($project = $result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif (
            ($role === "Manager" || $role === "IT Administrator")
            && $project["department"] === $user_department
        ) {
            $allowed = true;
        } elseif (
            $project["manager_id"] !== null
            && (int)$project["manager_id"] === $user_id
        ) {
            $allowed = true;
        }

        if ($allowed) {
            $projects[] = $project;
        }
    }
}

/*
 * Tasks belonging to projects the user manages.
 */
$tasks = [];

$result = $conn->query("
    SELECT
        t.id,
        t.name,
        t.project_id,
        p.name AS project_name,
        p.department,
        p.manager_id
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    ORDER BY p.name, t.name
");

if ($result) {
    while ($task = $result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif (
            ($role === "Manager" || $role === "IT Administrator")
            && $task["department"] === $user_department
        ) {
            $allowed = true;
        } elseif (
            $task["manager_id"] !== null
            && (int)$task["manager_id"] === $user_id
        ) {
            $allowed = true;
        }

        if ($allowed) {
            $tasks[] = $task;
        }
    }
}

/*
 * Teams.
 * Team Leads can create folders for teams they lead.
 * Project Managers can create folders for teams in projects they manage.
 */
$teams = [];

$result = $conn->query("
    SELECT
        t.id,
        t.name,
        t.team_lead_id,
        t.project_id,
        p.name AS project_name,
        p.department,
        p.manager_id
    FROM teams t
    INNER JOIN projects p ON p.id = t.project_id
    WHERE t.status = 'Active'
    ORDER BY p.name, t.name
");

if ($result) {
    while ($team = $result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif (
            ($role === "Manager" || $role === "IT Administrator")
            && $team["department"] === $user_department
        ) {
            $allowed = true;
        } elseif (
            $team["manager_id"] !== null
            && (int)$team["manager_id"] === $user_id
        ) {
            $allowed = true;
        } elseif (
            (int)$team["team_lead_id"] === $user_id
        ) {
            $allowed = true;
        }

        if ($allowed) {
            $teams[] = $team;
        }
    }
}

/*
 * Events.
 * Administrators can create event folders.
 * Managers can create them for their department.
 * Organizers can create them for events they organize.
 */
$events = [];

$result = $conn->query("
    SELECT id, name, type, department, organizer
    FROM events
    WHERE deleted = 0
    ORDER BY name
");

if ($result) {
    while ($event = $result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif ((int)$event["organizer"] === $user_id) {
            $allowed = true;
        } elseif (
            ($role === "Manager" || $role === "IT Administrator")
            && $event["department"] === $user_department
        ) {
            $allowed = true;
        }

        if ($allowed) {
            $events[] = $event;
        }
    }
}

/*
 * Training.
 */
$training_items = [];

$result = $conn->query("
    SELECT id, name, department, organizer
    FROM training
    WHERE deleted = 0
    ORDER BY name
");

if ($result) {
    while ($training = $result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif ((int)$training["organizer"] === $user_id) {
            $allowed = true;
        } elseif (
            ($role === "Manager" || $role === "IT Administrator")
            && $training["department"] === $user_department
        ) {
            $allowed = true;
        }

        if ($allowed) {
            $training_items[] = $training;
        }
    }
}

/*
 * Existing folders for optional parent folder.
 */
$folders = [];

$result = $conn->query("
    SELECT
        id,
        name,
        parent_folder_id,
        location_type,
        location_id
    FROM file_folders
    WHERE is_trashed = 0
    ORDER BY name
");

if ($result) {
    while ($folder = $result->fetch_assoc()) {
        $folders[] = $folder;
    }
}

function locationAllowed(
    $type,
    $id,
    $role,
    $department,
    $departments,
    $projects,
    $tasks,
    $teams,
    $events,
    $training_items
) {
    if ($type === "Organization") {
        return $role === "Administrator";
    }

    if ($type === "Department") {
        if ($role === "Administrator") {
            return in_array($id, $departments, true);
        }

        return ($role === "Manager" || $role === "IT Administrator") && $id === $department;
    }

    $collections = [
        "Project" => $projects,
        "Task" => $tasks,
        "Team" => $teams,
        "Event" => $events,
        "Training" => $training_items
    ];

    if (!isset($collections[$type])) {
        return false;
    }

    foreach ($collections[$type] as $item) {
        if ((string)$item["id"] === (string)$id) {
            return true;
        }
    }

    return false;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $location_type = trim($_POST["location_type"] ?? "");
    $parent_folder_id = (int)($_POST["parent_folder_id"] ?? 0);

    if ($location_type === "Organization") {
        $location_id = null;
    } elseif ($location_type === "Department") {
        $location_id = trim($_POST["department_location"] ?? "");
    } elseif ($location_type === "Project") {
        $location_id = trim($_POST["project_location"] ?? "");
    } elseif ($location_type === "Task") {
        $location_id = trim($_POST["task_location"] ?? "");
    } elseif ($location_type === "Team") {
        $location_id = trim($_POST["team_location"] ?? "");
    } elseif ($location_type === "Event") {
        $location_id = trim($_POST["event_location"] ?? "");
    } elseif ($location_type === "Training") {
        $location_id = trim($_POST["training_location"] ?? "");
    } else {
        $location_id = "";
    }

    if ($name === "") {
        $errors[] = "Folder Name is required.";
    }

    if (strlen($name) > 150) {
        $errors[] = "Folder Name cannot be longer than 150 characters.";
    }

    if (!in_array($location_type, $location_types, true)) {
        $errors[] = "Please select a valid category.";
    }

    if (
        $location_type !== "Organization"
        && ($location_id === "" || $location_id === null)
    ) {
        $errors[] = "Please select where this folder belongs.";
    }

    if (
        in_array($location_type, $location_types, true)
        && !locationAllowed(
            $location_type,
            $location_id,
            $role,
            $user_department,
            $departments,
            $projects,
            $tasks,
            $teams,
            $events,
            $training_items
        )
    ) {
        $errors[] = "You do not have permission to create a folder in that location.";
    }

    /*
     * Parent folder must belong to exactly the same category/location.
     */
    if ($parent_folder_id > 0) {

        $parent = null;

        foreach ($folders as $folder) {
            if ((int)$folder["id"] === $parent_folder_id) {
                $parent = $folder;
                break;
            }
        }

        if (!$parent) {
            $errors[] = "Please select a valid parent folder.";
        } elseif (
            $parent["location_type"] !== $location_type
            || (string)$parent["location_id"] !== (string)$location_id
        ) {
            $errors[] =
                "The parent folder must belong to the same category and location.";
        }
    }

    /*
     * Prevent duplicate folder names in the same parent/location.
     */
    if (!$errors) {

        if ($parent_folder_id > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM file_folders
                WHERE name = ?
                  AND parent_folder_id = ?
                  AND location_type = ?
                  AND (
                        location_id = ?
                        OR (location_id IS NULL AND ? IS NULL)
                  )
                  AND is_trashed = 0
            ");

            $stmt->bind_param(
                "sisss",
                $name,
                $parent_folder_id,
                $location_type,
                $location_id,
                $location_id
            );
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM file_folders
                WHERE name = ?
                  AND parent_folder_id IS NULL
                  AND location_type = ?
                  AND (
                        location_id = ?
                        OR (location_id IS NULL AND ? IS NULL)
                  )
                  AND is_trashed = 0
            ");

            $stmt->bind_param(
                "ssss",
                $name,
                $location_type,
                $location_id,
                $location_id
            );
        }

        $stmt->execute();
        $duplicate =
            (int)$stmt->get_result()->fetch_assoc()["total"] > 0;
        $stmt->close();

        if ($duplicate) {
            $errors[] = "A folder with that name already exists in this location.";
        }
    }

    if (!$errors) {

        $parent_value =
            $parent_folder_id > 0
                ? $parent_folder_id
                : null;

        $stmt = $conn->prepare("
            INSERT INTO file_folders
            (
                name,
                parent_folder_id,
                location_type,
                location_id,
                created_by
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sissi",
            $name,
            $parent_value,
            $location_type,
            $location_id,
            $user_id
        );

        if ($stmt->execute()) {
            $new_folder_id = $stmt->insert_id;
            $stmt->close();

            header(
                "Location: files.php?folder="
                . $new_folder_id
            );
            exit;
        }

        $errors[] = "Unable to create the folder.";
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Folder</title>

<link rel="stylesheet" href="app.css?v=11">

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
        <div class="sidebar-item active">
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
            <h1>NEW FOLDER</h1>
            <div class="sub">
                Create a folder for organization, department, project,
                task, team, event, or training files
            </div>
        </div>

        <a class="button" href="files.php">
            BACK TO FILES
        </a>
    </div>

    <div class="content">

    <?php if ($errors): ?>
        <div class="error">
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
            <h2>Folder Information</h2>

            <div class="grid location-grid">

                <div class="field full">
                    <label for="name">Folder Name</label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        maxlength="150"
                        value="<?php echo e($name); ?>"
                        required
                    >
                </div>

                <div class="field full">
                    <label for="location_type">Category</label>

                    <select
                        id="location_type"
                        name="location_type"
                        required
                    >
                        <?php foreach ($location_types as $type): ?>
                            <option
                                value="<?php echo e($type); ?>"
                                <?php echo $location_type === $type ? "selected" : ""; ?>
                            >
                                <?php echo e($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="note">
                        Select the matching destination below. Only the
                        destination matching the selected category is used.
                    </div>
                </div>

                <div class="field location-box">
                    <h3>Department</h3>

                    <select name="department_location">
                        <option value="">Select Department</option>

                        <?php foreach ($departments as $department): ?>

                            <?php
                            if (
                                $role !== "Administrator"
                                && !(
                                    ($role === "Manager" || $role === "IT Administrator")
                                    && $department === $user_department
                                )
                            ) {
                                continue;
                            }
                            ?>

                            <option
                                value="<?php echo e($department); ?>"
                                <?php echo $location_type === "Department" && $location_id === $department ? "selected" : ""; ?>
                            >
                                <?php echo e($department); ?>
                            </option>

                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field location-box">
                    <h3>Project</h3>

                    <select name="project_location">
                        <option value="">Select Project</option>

                        <?php foreach ($projects as $project): ?>
                            <option
                                value="<?php echo (int)$project["id"]; ?>"
                                <?php echo $location_type === "Project" && (string)$location_id === (string)$project["id"] ? "selected" : ""; ?>
                            >
                                <?php echo e($project["name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field location-box">
                    <h3>Task</h3>

                    <select name="task_location">
                        <option value="">Select Task</option>

                        <?php foreach ($tasks as $task): ?>
                            <option
                                value="<?php echo (int)$task["id"]; ?>"
                                <?php echo $location_type === "Task" && (string)$location_id === (string)$task["id"] ? "selected" : ""; ?>
                            >
                                <?php echo e($task["project_name"] . " — " . $task["name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field location-box">
                    <h3>Team</h3>

                    <select name="team_location">
                        <option value="">Select Team</option>

                        <?php foreach ($teams as $team): ?>
                            <option
                                value="<?php echo (int)$team["id"]; ?>"
                                <?php echo $location_type === "Team" && (string)$location_id === (string)$team["id"] ? "selected" : ""; ?>
                            >
                                <?php echo e($team["project_name"] . " — " . $team["name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field location-box">
                    <h3>Event</h3>

                    <select name="event_location">
                        <option value="">Select Event</option>

                        <?php foreach ($events as $event): ?>
                            <option
                                value="<?php echo (int)$event["id"]; ?>"
                                <?php echo $location_type === "Event" && (string)$location_id === (string)$event["id"] ? "selected" : ""; ?>
                            >
                                <?php echo e($event["name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field location-box">
                    <h3>Training</h3>

                    <select name="training_location">
                        <option value="">Select Training</option>

                        <?php foreach ($training_items as $training): ?>
                            <option
                                value="<?php echo (int)$training["id"]; ?>"
                                <?php echo $location_type === "Training" && (string)$location_id === (string)$training["id"] ? "selected" : ""; ?>
                            >
                                <?php echo e($training["name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field full">
                    <label for="parent_folder_id">
                        Parent Folder
                    </label>

                    <select
                        id="parent_folder_id"
                        name="parent_folder_id"
                    >
                        <option value="0">
                            No Parent Folder
                        </option>

                        <?php foreach ($folders as $folder): ?>
                            <option
                                value="<?php echo (int)$folder["id"]; ?>"
                                <?php echo $parent_folder_id === (int)$folder["id"] ? "selected" : ""; ?>
                            >
                                <?php
                                echo e(
                                    $folder["name"]
                                    . " — "
                                    . $folder["location_type"]
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="note">
                        A parent folder must belong to the same category
                        and location as the new folder.
                    </div>
                </div>

            </div>
        </div>

        <div class="actions">
            <a class="button" href="files.php">
                CANCEL
            </a>

            <button
                class="button primary"
                type="submit"
            >
                CREATE FOLDER
            </button>
        </div>

    </form>

</div>

</div>

</div>

</body>
</html>
