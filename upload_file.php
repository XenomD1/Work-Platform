<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "notification_helpers.php";

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

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/*
 * Everyone can upload files.
 */
$location_types = [
    "Organization",
    "Department",
    "Project",
    "Task",
    "Team",
    "Event",
    "Training"
];

$errors = [];

$name = "";
$location_type = "Organization";
$location_id = "";
$folder_id = 0;

/*
 * Departments.
 */
$departments = [];

$department_result = $conn->query("
    SELECT DISTINCT department
    FROM users
    WHERE active = 1
      AND department IS NOT NULL
      AND department <> ''
    ORDER BY department
");

if ($department_result) {
    while ($row = $department_result->fetch_assoc()) {
        $departments[] = $row["department"];
    }
}

/*
 * Projects the user can access.
 */
$projects = [];

$project_result = $conn->query("
    SELECT id, name, department, manager_id
    FROM projects
    ORDER BY name
");

if ($project_result) {
    while ($project = $project_result->fetch_assoc()) {

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
        } else {

            $member_stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM teams t
                INNER JOIN team_members tm
                    ON tm.team_id = t.id
                WHERE t.project_id = ?
                  AND tm.user_id = ?
            ");

            $project_id_check = (int)$project["id"];

            $member_stmt->bind_param(
                "ii",
                $project_id_check,
                $user_id
            );

            $member_stmt->execute();

            $allowed =
                (int)$member_stmt
                    ->get_result()
                    ->fetch_assoc()["total"] > 0;

            $member_stmt->close();
        }

        if ($allowed) {
            $projects[] = $project;
        }
    }
}

/*
 * Tasks the user can access.
 */
$tasks = [];

$task_result = $conn->query("
    SELECT
        t.id,
        t.name,
        t.project_id,
        p.name AS project_name,
        p.department,
        p.manager_id
    FROM tasks t
    INNER JOIN projects p
        ON p.id = t.project_id
    ORDER BY p.name, t.name
");

if ($task_result) {
    while ($task = $task_result->fetch_assoc()) {

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
        } else {

            $assign_stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM task_assignments
                WHERE task_id = ?
                  AND user_id = ?
            ");

            $task_id_check = (int)$task["id"];

            $assign_stmt->bind_param(
                "ii",
                $task_id_check,
                $user_id
            );

            $assign_stmt->execute();

            $allowed =
                (int)$assign_stmt
                    ->get_result()
                    ->fetch_assoc()["total"] > 0;

            $assign_stmt->close();
        }

        if ($allowed) {
            $tasks[] = $task;
        }
    }
}

/*
 * Teams the user can access.
 */
$teams = [];

$team_result = $conn->query("
    SELECT
        t.id,
        t.name,
        t.team_lead_id,
        p.name AS project_name,
        p.department,
        p.manager_id
    FROM teams t
    INNER JOIN projects p
        ON p.id = t.project_id
    ORDER BY p.name, t.name
");

if ($team_result) {
    while ($team = $team_result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif ((int)$team["team_lead_id"] === $user_id) {
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
        } else {

            $member_stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM team_members
                WHERE team_id = ?
                  AND user_id = ?
            ");

            $team_id_check = (int)$team["id"];

            $member_stmt->bind_param(
                "ii",
                $team_id_check,
                $user_id
            );

            $member_stmt->execute();

            $allowed =
                (int)$member_stmt
                    ->get_result()
                    ->fetch_assoc()["total"] > 0;

            $member_stmt->close();
        }

        if ($allowed) {
            $teams[] = $team;
        }
    }
}

/*
 * Events the user can access.
 */
$events = [];

$event_result = $conn->query("
    SELECT
        id,
        name,
        type,
        department,
        organizer
    FROM events
    WHERE deleted = 0
    ORDER BY start_date DESC, name
");

if ($event_result) {
    while ($event = $event_result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif ($event["type"] === "Organization-Wide Event") {
            $allowed = true;
        } elseif ((int)$event["organizer"] === $user_id) {
            $allowed = true;
        } elseif ($event["department"] === $user_department) {
            $allowed = true;
        } else {

            $attendee_stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM event_attendees
                WHERE event_id = ?
                  AND user_id = ?
            ");

            $event_id_check = (int)$event["id"];

            $attendee_stmt->bind_param(
                "ii",
                $event_id_check,
                $user_id
            );

            $attendee_stmt->execute();

            $allowed =
                (int)$attendee_stmt
                    ->get_result()
                    ->fetch_assoc()["total"] > 0;

            $attendee_stmt->close();
        }

        if ($allowed) {
            $events[] = $event;
        }
    }
}

/*
 * Training the user can access.
 */
$training_items = [];

$training_result = $conn->query("
    SELECT
        id,
        name,
        department,
        organizer
    FROM training
    WHERE deleted = 0
    ORDER BY start_date DESC, name
");

if ($training_result) {
    while ($training = $training_result->fetch_assoc()) {

        $allowed = false;

        if ($role === "Administrator") {
            $allowed = true;
        } elseif ((int)$training["organizer"] === $user_id) {
            $allowed = true;
        } elseif ($training["department"] === $user_department) {
            $allowed = true;
        } else {

            $attendee_stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM training_attendees
                WHERE training_id = ?
                  AND (
                        user_id = ?
                        OR department = ?
                  )
            ");

            $training_id_check = (int)$training["id"];

            $attendee_stmt->bind_param(
                "iis",
                $training_id_check,
                $user_id,
                $user_department
            );

            $attendee_stmt->execute();

            $allowed =
                (int)$attendee_stmt
                    ->get_result()
                    ->fetch_assoc()["total"] > 0;

            $attendee_stmt->close();
        }

        if ($allowed) {
            $training_items[] = $training;
        }
    }
}

/*
 * Accessible folders.
 */
$folders = [];

$folder_result = $conn->query("
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

if ($folder_result) {
    while ($folder = $folder_result->fetch_assoc()) {
        $folders[] = $folder;
    }
}

/*
 * Determine whether a selected location is valid and accessible.
 */
function locationIsAllowed(
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
) {
    if ($location_type === "Organization") {
        return true;
    }

    if ($location_type === "Department") {
        if ($role === "Administrator") {
            return in_array($location_id, $departments, true);
        }

        return $location_id === $user_department;
    }

    $collections = [
        "Project" => $projects,
        "Task" => $tasks,
        "Team" => $teams,
        "Event" => $events,
        "Training" => $training_items
    ];

    if (!isset($collections[$location_type])) {
        return false;
    }

    foreach ($collections[$location_type] as $item) {
        if ((string)$item["id"] === (string)$location_id) {
            return true;
        }
    }

    return false;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $location_type = trim($_POST["location_type"] ?? "");
    $folder_id = (int)($_POST["folder_id"] ?? 0);

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
        $errors[] = "File Name is required.";
    }

    if (!in_array($location_type, $location_types, true)) {
        $errors[] = "Please select a valid category.";
    }

    if (
        $location_type !== "Organization"
        && ($location_id === "" || $location_id === null)
    ) {
        $errors[] = "Please select where this file belongs.";
    }

    if (
        in_array($location_type, $location_types, true)
        && !locationIsAllowed(
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
        $errors[] = "You do not have access to the selected location.";
    }

    /*
     * Validate folder.
     * A folder must use the same category/location as the file.
     */
    if ($folder_id > 0) {

        $selected_folder = null;

        foreach ($folders as $folder) {
            if ((int)$folder["id"] === $folder_id) {
                $selected_folder = $folder;
                break;
            }
        }

        if (!$selected_folder) {
            $errors[] = "Please select a valid folder.";
        } else {

            $folder_location_id = $selected_folder["location_id"];

            if (
                $selected_folder["location_type"] !== $location_type
                || (string)$folder_location_id !== (string)$location_id
            ) {
                $errors[] =
                    "The selected folder does not belong to the selected category/location.";
            }
        }
    }

    if (!isset($_FILES["upload_file"])) {
        $errors[] = "Select a file to upload.";
    } elseif ($_FILES["upload_file"]["error"] !== UPLOAD_ERR_OK) {
        $errors[] = "There was a problem uploading the file.";
    }

    if (!$errors) {

        $original_filename = basename($_FILES["upload_file"]["name"]);
        $extension = strtolower(
            pathinfo($original_filename, PATHINFO_EXTENSION)
        );

        /*
         * Allowlist, not a denylist: only file types actually needed
         * for business documents are accepted. Anything not on this
         * list is rejected, including any kind of executable script.
         */
        $allowed_mime_by_extension = [
            "pdf"  => ["application/pdf"],
            "doc"  => ["application/msword"],
            "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip"],
            "xls"  => ["application/vnd.ms-excel"],
            "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip"],
            "ppt"  => ["application/vnd.ms-powerpoint"],
            "pptx" => ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "application/zip"],
            "txt"  => ["text/plain"],
            "csv"  => ["text/plain", "text/csv"],
            "rtf"  => ["text/rtf", "application/rtf"],
            "jpg"  => ["image/jpeg"],
            "jpeg" => ["image/jpeg"],
            "png"  => ["image/png"],
            "gif"  => ["image/gif"],
            "zip"  => ["application/zip"]
        ];

        if (!array_key_exists($extension, $allowed_mime_by_extension)) {
            $errors[] = "That file type is not allowed.";
        } else {
            $detected_mime = false;

            if (function_exists("finfo_open")) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detected_mime = finfo_file($finfo, $_FILES["upload_file"]["tmp_name"]);
                    finfo_close($finfo);
                }
            }

            if (
                $detected_mime !== false
                && !in_array($detected_mime, $allowed_mime_by_extension[$extension], true)
            ) {
                $errors[] = "The file's contents don't match its extension.";
            }
        }
    }

    if (!$errors) {

        $upload_dir =
            __DIR__
            . DIRECTORY_SEPARATOR
            . "uploads"
            . DIRECTORY_SEPARATOR
            . "files";

        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0775, true)) {
                $errors[] = "Unable to create the file upload folder.";
            }
        }
    }

    if (!$errors) {

        $stored_filename =
            "file_"
            . $user_id
            . "_"
            . time()
            . "_"
            . bin2hex(random_bytes(8))
            . ($extension !== "" ? "." . $extension : "");

        $target_path =
            $upload_dir
            . DIRECTORY_SEPARATOR
            . $stored_filename;

        if (!move_uploaded_file(
            $_FILES["upload_file"]["tmp_name"],
            $target_path
        )) {
            $errors[] = "Unable to save the uploaded file.";
        }
    }

    if (!$errors) {

        $relative_path = "uploads/files/" . $stored_filename;
        $file_size = (int)filesize($target_path);

        $file_type = $_FILES["upload_file"]["type"] ?? null;

        $folder_value = $folder_id > 0 ? $folder_id : null;

        $conn->begin_transaction();

        try {

            $file_stmt = $conn->prepare("
                INSERT INTO files
                (
                    name,
                    folder_id,
                    location_type,
                    location_id,
                    current_version,
                    uploaded_by
                )
                VALUES (?, ?, ?, ?, 1, ?)
            ");

            $file_stmt->bind_param(
                "sissi",
                $name,
                $folder_value,
                $location_type,
                $location_id,
                $user_id
            );

            if (!$file_stmt->execute()) {
                throw new Exception("Unable to create the file record.");
            }

            $file_id = $file_stmt->insert_id;
            $file_stmt->close();

            $version_stmt = $conn->prepare("
                INSERT INTO file_versions
                (
                    file_id,
                    version_number,
                    original_filename,
                    stored_filename,
                    file_path,
                    file_size,
                    file_type,
                    uploaded_by
                )
                VALUES (?, 1, ?, ?, ?, ?, ?, ?)
            ");

            $version_stmt->bind_param(
                "isssisi",
                $file_id,
                $original_filename,
                $stored_filename,
                $relative_path,
                $file_size,
                $file_type,
                $user_id
            );

            if (!$version_stmt->execute()) {
                throw new Exception("Unable to create the file version.");
            }

            $version_stmt->close();

            $conn->commit();

            foreach (
                fileNotificationRecipientIds(
                    $conn,
                    $location_type,
                    $location_id,
                    $user_id
                ) as $recipient_id
            ) {
                createNotification(
                    $conn,
                    $recipient_id,
                    "File",
                    "New File Uploaded",
                    "A new file \"" . $name . "\" was uploaded.",
                    "Files",
                    $file_id,
                    "view_file.php?id=" . $file_id
                );
            }

            header("Location: view_file.php?id=" . $file_id);
            exit;

        } catch (Throwable $e) {

            $conn->rollback();

            if (file_exists($target_path)) {
                @unlink($target_path);
            }

            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Upload File</title>

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
        <div class="sidebar-item active">
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
            <h1>UPLOAD FILE</h1>
            <div class="sub">
                Add a new file to the work management library
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

    <form method="POST" enctype="multipart/form-data">

        <div class="card">

            <h2>File Information</h2>

            <div class="grid">

                <div class="field full">
                    <label for="name">
                        File Name <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?php echo e($name); ?>"
                        placeholder="Name shown in the file library"
                        required
                    >
                </div>

                <div class="field full">
                    <label for="upload_file">
                        Select File <span class="required">*</span>
                    </label>

                    <input
                        type="file"
                        id="upload_file"
                        name="upload_file"
                        required
                    >

                    <div class="note">
                        Allowed types: PDF, Word, Excel, PowerPoint, TXT, CSV, RTF, JPG, PNG, GIF, ZIP.
                    </div>
                </div>

            </div>

        </div>

        <div class="card">

            <h2>File Location</h2>

            <div class="grid location-grid">

                <div class="field full">
                    <label for="location_type">
                        Category <span class="required">*</span>
                    </label>

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
                        Because this platform does not use JavaScript, select the
                        matching destination below for the category you chose.
                        The other destination fields are ignored.
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
                                && $department !== $user_department
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
                    <label for="folder_id">
                        Folder
                    </label>

                    <select
                        id="folder_id"
                        name="folder_id"
                    >
                        <option value="0">No Folder</option>

                        <?php foreach ($folders as $folder): ?>

                            <option
                                value="<?php echo (int)$folder["id"]; ?>"
                                <?php echo $folder_id === (int)$folder["id"] ? "selected" : ""; ?>
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
                        If a folder is selected, it must belong to the same
                        category and destination as the file.
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
                UPLOAD FILE
            </button>

        </div>

    </form>

</div>

</div>

</div>

</body>
</html>