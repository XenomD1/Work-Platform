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

/* ---------------------------------------------------------
   CURRENT USER
--------------------------------------------------------- */
$user_sql = "
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ? AND active = 1
";

$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$user_result = $user_stmt->get_result();
$current_user = $user_result->fetch_assoc();

$user_stmt->close();

if (!$current_user) {
    header("Location: login.php");
    exit;
}

$role = $current_user["role"];
$user_department = $current_user["department"];

/* ---------------------------------------------------------
   PERMISSION
--------------------------------------------------------- */
if ($role !== "Administrator" && $role !== "Manager" && $role !== "IT Administrator") {
    header("Location: events.php");
    exit;
}

/* ---------------------------------------------------------
   EVENT TYPES
--------------------------------------------------------- */
$event_types = [
    "Department Meeting",
    "Organization-Wide Event",
    "Team Meeting",
    "Training",
    "Workshop",
    "Presentation",
    "Conference",
    "Planning Session",
    "Staff Development",
    "Orientation",
    "Other"
];

/* ---------------------------------------------------------
   STATUSES
--------------------------------------------------------- */
$statuses = [
    "Planned",
    "Confirmed",
    "Cancelled",
    "Completed"
];

/* ---------------------------------------------------------
   RECURRENCE OPTIONS
--------------------------------------------------------- */
$recurrence_options = [
    "none" => "Does not repeat",
    "daily" => "Every day",
    "weekly" => "Every week",
    "monthly" => "Every month"
];

/* ---------------------------------------------------------
   REMINDER OPTIONS
--------------------------------------------------------- */
$reminder_options = [
    "none" => "None",
    "15 minutes" => "15 minutes before",
    "30 minutes" => "30 minutes before",
    "1 hour" => "1 hour before",
    "1 day" => "1 day before"
];

/* ---------------------------------------------------------
   FORM VALUES
--------------------------------------------------------- */
$name = "";
$description = "";
$type = "";
$organizer = $user_id;
$department = $user_department;
$location = "";
$start_date = "";
$end_date = "";
$start_time = "";
$end_time = "";
$status = "Planned";
$recurrence_type = "none";
$repeat_until = "";
$reminder = "none";

$selected_attendees = [];

$errors = [];

/* ---------------------------------------------------------
   LOAD ACTIVE USERS
--------------------------------------------------------- */
$users = [];

$users_sql = "
    SELECT id, first_name, last_name, department
    FROM users
    WHERE active = 1
    ORDER BY first_name ASC, last_name ASC
";

$users_result = $conn->query($users_sql);

while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}

/* ---------------------------------------------------------
   LOAD DEPARTMENTS
--------------------------------------------------------- */
$departments = [];

$department_result = $conn->query("
    SELECT DISTINCT department
    FROM users
    WHERE active = 1
      AND department IS NOT NULL
      AND department <> ''
    ORDER BY department ASC
");

while ($row = $department_result->fetch_assoc()) {
    $departments[] = $row["department"];
}

/* ---------------------------------------------------------
   FORM SUBMISSION
--------------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $organizer = (int)($_POST["organizer"] ?? 0);
    $department = trim($_POST["department"] ?? "");
    $location = trim($_POST["location"] ?? "");
    $start_date = trim($_POST["start_date"] ?? "");
    $end_date = trim($_POST["end_date"] ?? "");
    $start_time = trim($_POST["start_time"] ?? "");
    $end_time = trim($_POST["end_time"] ?? "");
    $status = trim($_POST["status"] ?? "Planned");
    $recurrence_type = trim($_POST["recurrence_type"] ?? "none");
    $repeat_until = trim($_POST["repeat_until"] ?? "");
    $reminder = trim($_POST["reminder"] ?? "none");

    $selected_attendees = $_POST["attendees"] ?? [];

    if (!is_array($selected_attendees)) {
        $selected_attendees = [];
    }

    $selected_attendees = array_map("intval", $selected_attendees);

    /* -----------------------------------------------------
       VALIDATION
    ----------------------------------------------------- */

    if ($name === "") {
        $errors[] = "Event name is required.";
    }

    if ($description === "") {
        $errors[] = "Description is required.";
    }

    if (!in_array($type, $event_types, true)) {
        $errors[] = "Please select a valid event type.";
    }

    if ($organizer <= 0) {
        $errors[] = "Organizer is required.";
    }

    if ($department === "") {
        $errors[] = "Department is required.";
    }

    if ($location === "") {
        $errors[] = "Location is required.";
    }

    if ($start_date === "") {
        $errors[] = "Start date is required.";
    }

    if ($end_date === "") {
        $errors[] = "End date is required.";
    }

    if ($start_time === "") {
        $errors[] = "Start time is required.";
    }

    if ($end_time === "") {
        $errors[] = "End time is required.";
    }

    if (!in_array($status, $statuses, true)) {
        $errors[] = "Please select a valid status.";
    }

    if (!array_key_exists($recurrence_type, $recurrence_options)) {
        $errors[] = "Please select a valid recurrence option.";
    }

    if (!array_key_exists($reminder, $reminder_options)) {
        $errors[] = "Please select a valid reminder.";
    }

    if (empty($selected_attendees)) {
        $errors[] = "At least one attendee is required.";
    }

    /* -----------------------------------------------------
       DATE / TIME VALIDATION
    ----------------------------------------------------- */

    if ($start_date !== "" && $end_date !== "") {

        $start_date_obj = DateTime::createFromFormat("Y-m-d", $start_date);
        $end_date_obj = DateTime::createFromFormat("Y-m-d", $end_date);

        if (!$start_date_obj || !$end_date_obj) {
            $errors[] = "Please enter valid dates.";
        } elseif ($end_date_obj < $start_date_obj) {
            $errors[] = "End date cannot be before start date.";
        }
    }

    if ($start_time !== "" && $end_time !== "") {

        if ($start_date === $end_date && $end_time < $start_time) {
            $errors[] = "End time cannot be before start time.";
        }
    }

    /* -----------------------------------------------------
       RECURRENCE VALIDATION
    ----------------------------------------------------- */

    if ($recurrence_type !== "none") {

        if ($repeat_until === "") {
            $errors[] = "Repeat Until is required for recurring events.";
        } elseif ($start_date !== "") {

            $repeat_until_obj = DateTime::createFromFormat(
                "Y-m-d",
                $repeat_until
            );

            $start_date_obj = DateTime::createFromFormat(
                "Y-m-d",
                $start_date
            );

            if (!$repeat_until_obj || !$start_date_obj) {
                $errors[] = "Repeat Until must be a valid date.";
            } elseif ($repeat_until_obj < $start_date_obj) {
                $errors[] = "Repeat Until cannot be before the start date.";
            } else {

                /*
                    Count how many occurrences this recurrence would
                    generate, so a far-future Repeat Until combined with
                    a daily/weekly repeat can't silently create an
                    enormous number of rows in event_occurrences.
                */

                $max_occurrences = 366;
                $occurrence_count = 0;
                $count_cursor = clone $start_date_obj;

                while (
                    $count_cursor <= $repeat_until_obj &&
                    $occurrence_count <= $max_occurrences
                ) {

                    $occurrence_count++;

                    if ($recurrence_type === "daily") {
                        $count_cursor->modify("+1 day");
                    } elseif ($recurrence_type === "weekly") {
                        $count_cursor->modify("+1 week");
                    } elseif ($recurrence_type === "monthly") {
                        $count_cursor->modify("+1 month");
                    } else {
                        break;
                    }
                }

                if ($occurrence_count > $max_occurrences) {
                    $errors[] =
                        "This recurrence would create more than " .
                        $max_occurrences .
                        " occurrences. Please choose a shorter repeat range.";
                }
            }
        }
    } else {
        $repeat_until = null;
    }

    /* -----------------------------------------------------
       MANAGER DEPARTMENT RULE
    ----------------------------------------------------- */

    if ($role === "Manager" || $role === "IT Administrator") {

        if ($department !== $user_department) {
            $errors[] = "You can only create events for your own department.";
        }
    }

    /* -----------------------------------------------------
       VALIDATE ORGANIZER
    ----------------------------------------------------- */

    if ($organizer > 0) {

        $organizer_stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND active = 1
        ");

        $organizer_stmt->bind_param("i", $organizer);
        $organizer_stmt->execute();

        $organizer_result = $organizer_stmt->get_result();

        if ($organizer_result->num_rows === 0) {
            $errors[] = "The selected organizer is not valid.";
        }

        $organizer_stmt->close();
    }

    /* -----------------------------------------------------
       VALIDATE ATTENDEES
    ----------------------------------------------------- */

    if (!empty($selected_attendees)) {

        $valid_attendees = [];

        $attendee_stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND active = 1
        ");

        foreach ($selected_attendees as $attendee_id) {

            if ($attendee_id <= 0) {
                continue;
            }

            $attendee_stmt->bind_param("i", $attendee_id);
            $attendee_stmt->execute();

            $attendee_result = $attendee_stmt->get_result();

            if ($attendee_result->num_rows > 0) {
                $valid_attendees[] = $attendee_id;
            }
        }

        $attendee_stmt->close();

        $selected_attendees = array_values(
            array_unique($valid_attendees)
        );

        if (empty($selected_attendees)) {
            $errors[] = "At least one valid attendee is required.";
        }
    }

    /* -----------------------------------------------------
       CREATE EVENT
    ----------------------------------------------------- */

    if (empty($errors)) {

        try {

            $conn->begin_transaction();

            $insert_sql = "
                INSERT INTO events (
                    name,
                    description,
                    type,
                    organizer,
                    department,
                    location,
                    start_date,
                    end_date,
                    start_time,
                    end_time,
                    status,
                    recurrence_type,
                    repeat_until,
                    reminder,
                    reminder_delivery
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'email')
            ";

            $insert_stmt = $conn->prepare($insert_sql);

            $insert_stmt->bind_param(
                "sssissssssssss",
                $name,
                $description,
                $type,
                $organizer,
                $department,
                $location,
                $start_date,
                $end_date,
                $start_time,
                $end_time,
                $status,
                $recurrence_type,
                $repeat_until,
                $reminder
            );

            $insert_stmt->execute();

            $event_id = $conn->insert_id;

            $insert_stmt->close();

            /* -------------------------------------------------
               ADD ATTENDEES
            ------------------------------------------------- */

            $attendee_insert = $conn->prepare("
                INSERT INTO event_attendees (
                    event_id,
                    user_id,
                    attendance_status
                )
                VALUES (?, ?, 'No Response')
            ");

            foreach ($selected_attendees as $attendee_id) {

                $attendee_insert->bind_param(
                    "ii",
                    $event_id,
                    $attendee_id
                );

                $attendee_insert->execute();
            }

            $attendee_insert->close();

            /* -------------------------------------------------
               CREATE EVENT HISTORY
            ------------------------------------------------- */

            $history_stmt = $conn->prepare("
                INSERT INTO event_history (
                    event_id,
                    field_name,
                    old_value,
                    new_value,
                    changed_by
                )
                VALUES (?, ?, NULL, ?, ?)
            ");

            $history_fields = [
                "name" => $name,
                "description" => $description,
                "type" => $type,
                "organizer" => (string)$organizer,
                "department" => $department,
                "location" => $location,
                "start_date" => $start_date,
                "end_date" => $end_date,
                "start_time" => $start_time,
                "end_time" => $end_time,
                "status" => $status,
                "recurrence_type" => $recurrence_type,
                "repeat_until" => $repeat_until,
                "reminder" => $reminder,
                "reminder_delivery" => "email"
            ];

            foreach ($history_fields as $field_name => $new_value) {

                $new_value_string = ($new_value === null)
                    ? null
                    : (string)$new_value;

                $history_stmt->bind_param(
                    "issi",
                    $event_id,
                    $field_name,
                    $new_value_string,
                    $user_id
                );

                $history_stmt->execute();
            }

            $history_stmt->close();

            /* -------------------------------------------------
               CREATE RECURRING OCCURRENCES
            ------------------------------------------------- */

            if ($recurrence_type !== "none" && $repeat_until !== null) {

                $occurrence_stmt = $conn->prepare("
                    INSERT INTO event_occurrences (
                        event_id,
                        occurrence_date,
                        start_time,
                        end_time,
                        status
                    )
                    VALUES (?, ?, ?, ?, 'Scheduled')
                ");

                $current_date = new DateTime($start_date);
                $last_date = new DateTime($repeat_until);

                while (true) {

                    $occurrence_date = $current_date->format("Y-m-d");

                    $occurrence_stmt->bind_param(
                        "isss",
                        $event_id,
                        $occurrence_date,
                        $start_time,
                        $end_time
                    );

                    $occurrence_stmt->execute();

                    if ($recurrence_type === "daily") {

                        $current_date->modify("+1 day");

                    } elseif ($recurrence_type === "weekly") {

                        $current_date->modify("+1 week");

                    } elseif ($recurrence_type === "monthly") {

                        $current_date->modify("+1 month");
                    }

                    if ($current_date > $last_date) {
                        break;
                    }
                }

                $occurrence_stmt->close();
            }

            $conn->commit();

            header("Location: view_event.php?id=" . $event_id);
            exit;

        } catch (Exception $e) {

            $conn->rollback();

            $errors[] = "The event could not be created. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>New Event</title>

    <link rel="stylesheet" href="app.css?v=9">

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
        <div class="sidebar-item active">
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

    <main class="main">

        <div class="header">
            <div class="header-title">New Event</div>
        </div>

        <div class="content">

        <?php if (!empty($errors)): ?>

            <div class="error-box">

                <strong>Please correct the following:</strong>

                <ul>

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?php echo htmlspecialchars($error); ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <div class="form-box">

            <form method="post">

                <?= csrfField() ?>

                <!-- -----------------------------------------
                     BASIC INFORMATION
                ------------------------------------------ -->

                <div class="form-section">

                    <h2>Basic Information</h2>

                    <div class="form-grid">

                        <div class="form-group full">

                            <label for="name">
                                Event Name <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?php echo htmlspecialchars($name); ?>"
                                required
                            >

                        </div>

                        <div class="form-group full">

                            <label for="description">
                                Description <span class="required">*</span>
                            </label>

                            <textarea
                                id="description"
                                name="description"
                                required
                            ><?php echo htmlspecialchars($description); ?></textarea>

                        </div>

                        <div class="form-group">

                            <label for="type">
                                Type <span class="required">*</span>
                            </label>

                            <select id="type" name="type" required>

                                <option value="">
                                    Select Type
                                </option>

                                <?php foreach ($event_types as $event_type): ?>

                                    <option
                                        value="<?php echo htmlspecialchars($event_type); ?>"
                                        <?php echo ($type === $event_type) ? "selected" : ""; ?>
                                    >
                                        <?php echo htmlspecialchars($event_type); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label for="organizer">
                                Organizer <span class="required">*</span>
                            </label>

                            <select id="organizer" name="organizer" required>

                                <option value="">
                                    Select Organizer
                                </option>

                                <?php foreach ($users as $user): ?>

                                    <option
                                        value="<?php echo (int)$user["id"]; ?>"
                                        <?php echo ($organizer === (int)$user["id"]) ? "selected" : ""; ?>
                                    >
                                        <?php
                                        echo htmlspecialchars(
                                            $user["first_name"] . " " . $user["last_name"]
                                        );
                                        ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label for="department">
                                Department <span class="required">*</span>
                            </label>

                            <select
                                id="department"
                                name="department"
                                required
                                <?php echo ($role === "Manager" || $role === "IT Administrator") ? "disabled" : ""; ?>
                            >

                                <option value="">
                                    Select Department
                                </option>

                                <?php foreach ($departments as $dept): ?>

                                    <option
                                        value="<?php echo htmlspecialchars($dept); ?>"
                                        <?php echo ($department === $dept) ? "selected" : ""; ?>
                                    >
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <?php if ($role === "Manager" || $role === "IT Administrator"): ?>

                                <input
                                    type="hidden"
                                    name="department"
                                    value="<?php echo htmlspecialchars($department); ?>"
                                >

                                <div class="help-text">
                                    You can only create events for your own department.
                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="form-group">

                            <label for="location">
                                Location <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                id="location"
                                name="location"
                                value="<?php echo htmlspecialchars($location); ?>"
                                placeholder="Building, room, or online location"
                                required
                            >

                        </div>

                    </div>

                </div>

                <!-- -----------------------------------------
                     DATE / TIME
                ------------------------------------------ -->

                <div class="form-section">

                    <h2>Date &amp; Time</h2>

                    <div class="form-grid">

                        <div class="form-group">

                            <label for="start_date">
                                Start Date <span class="required">*</span>
                            </label>

                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                value="<?php echo htmlspecialchars($start_date); ?>"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label for="end_date">
                                End Date <span class="required">*</span>
                            </label>

                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                value="<?php echo htmlspecialchars($end_date); ?>"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label for="start_time">
                                Start Time <span class="required">*</span>
                            </label>

                            <input
                                type="time"
                                id="start_time"
                                name="start_time"
                                value="<?php echo htmlspecialchars($start_time); ?>"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label for="end_time">
                                End Time <span class="required">*</span>
                            </label>

                            <input
                                type="time"
                                id="end_time"
                                name="end_time"
                                value="<?php echo htmlspecialchars($end_time); ?>"
                                required
                            >

                        </div>

                    </div>

                </div>

                <!-- -----------------------------------------
                     ATTENDEES
                ------------------------------------------ -->

                <div class="form-section">

                    <h2>Attendees</h2>

                    <div class="form-group">

                        <label>
                            Select Attendees <span class="required">*</span>
                        </label>

                        <div class="attendee-box">

                            <?php foreach ($users as $user): ?>

                                <?php
                                $attendee_id = (int)$user["id"];
                                $checked = in_array(
                                    $attendee_id,
                                    $selected_attendees,
                                    true
                                );
                                ?>

                                <label class="attendee-row">

                                    <input
                                        type="checkbox"
                                        name="attendees[]"
                                        value="<?php echo $attendee_id; ?>"
                                        <?php echo $checked ? "checked" : ""; ?>
                                    >

                                    <span class="attendee-name">

                                        <?php
                                        echo htmlspecialchars(
                                            $user["first_name"] .
                                            " " .
                                            $user["last_name"]
                                        );
                                        ?>

                                        <span class="attendee-department">
                                            <?php
                                            echo htmlspecialchars(
                                                $user["department"] ?? ""
                                            );
                                            ?>
                                        </span>

                                    </span>

                                </label>

                            <?php endforeach; ?>

                        </div>

                        <div class="help-text">
                            Select one or more employees.
                        </div>

                    </div>

                </div>

                <!-- -----------------------------------------
                     STATUS
                ------------------------------------------ -->

                <div class="form-section">

                    <h2>Status</h2>

                    <div class="form-grid">

                        <div class="form-group">

                            <label for="status">
                                Status <span class="required">*</span>
                            </label>

                            <select id="status" name="status" required>

                                <?php foreach ($statuses as $event_status): ?>

                                    <option
                                        value="<?php echo htmlspecialchars($event_status); ?>"
                                        <?php echo ($status === $event_status) ? "selected" : ""; ?>
                                    >
                                        <?php echo htmlspecialchars($event_status); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                </div>

                <!-- -----------------------------------------
                     RECURRENCE
                ------------------------------------------ -->

                <div class="form-section">

                    <h2>Recurrence</h2>

                    <div class="form-grid">

                        <div class="form-group">

                            <label for="recurrence_type">
                                Repeat
                            </label>

                            <select
                                id="recurrence_type"
                                name="recurrence_type"
                            >

                                <?php foreach ($recurrence_options as $value => $label): ?>

                                    <option
                                        value="<?php echo htmlspecialchars($value); ?>"
                                        <?php echo ($recurrence_type === $value) ? "selected" : ""; ?>
                                    >
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label for="repeat_until">
                                Repeat Until
                            </label>

                            <input
                                type="date"
                                id="repeat_until"
                                name="repeat_until"
                                value="<?php echo htmlspecialchars($repeat_until ?? ""); ?>"
                            >

                            <div class="help-text">
                                Required when the event repeats. Limited to 366 occurrences.
                            </div>

                        </div>

                    </div>

                </div>

                <!-- -----------------------------------------
                     REMINDER
                ------------------------------------------ -->

                <div class="form-section">

                    <h2>Reminder</h2>

                    <div class="form-grid">

                        <div class="form-group">

                            <label for="reminder">
                                Reminder
                            </label>

                            <select
                                id="reminder"
                                name="reminder"
                            >

                                <?php foreach ($reminder_options as $value => $label): ?>

                                    <option
                                        value="<?php echo htmlspecialchars($value); ?>"
                                        <?php echo ($reminder === $value) ? "selected" : ""; ?>
                                    >
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                    <div class="help-text">
                        Reminders are delivered by email to the organizer and attendees.
                    </div>

                </div>

                <!-- -----------------------------------------
                     ACTIONS
                ------------------------------------------ -->

                <div class="form-actions">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        CREATE EVENT
                    </button>

                    <a
                        href="events.php"
                        class="button button-secondary"
                    >
                        CANCEL
                    </a>

                </div>

            </form>

        </div>

        </div>

    </main>

</div>

</body>

</html>