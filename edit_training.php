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

function trainingNotificationRecipientIds(mysqli $conn, int $training_id, int $organizer_id): array {
    $ids = [$organizer_id];

    $stmt = $conn->prepare("
        SELECT user_id, department
        FROM training_attendees
        WHERE training_id = ?
    ");
    $stmt->bind_param("i", $training_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $departments = [];

    while ($row = $result->fetch_assoc()) {
        if (!empty($row["user_id"])) {
            $ids[] = (int)$row["user_id"];
        } elseif (!empty($row["department"])) {
            $departments[] = $row["department"];
        }
    }

    $stmt->close();

    foreach (array_unique($departments) as $department) {
        $dept_stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE active = 1
              AND department = ?
        ");
        $dept_stmt->bind_param("s", $department);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->get_result();

        while ($user = $dept_result->fetch_assoc()) {
            $ids[] = (int)$user["id"];
        }

        $dept_stmt->close();
    }

    return array_values(array_unique(array_filter(array_map("intval", $ids))));
}

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

if (
    $current_user["role"] !== "Administrator"
    && $current_user["role"] !== "Manager"
    && $current_user["role"] !== "IT Administrator"
) {
    header("Location: training.php");
    exit;
}

$training_id = (int)($_GET["id"] ?? $_POST["training_id"] ?? 0);

if ($training_id <= 0) {
    header("Location: training.php");
    exit;
}

$training_stmt = $conn->prepare("
    SELECT id, name, description, type, organizer, department, location,
           start_date, end_date, start_time, end_time, status, reminder
    FROM training
    WHERE id = ? AND deleted = 0
");
$training_stmt->bind_param("i", $training_id);
$training_stmt->execute();
$training = $training_stmt->get_result()->fetch_assoc();
$training_stmt->close();

if (!$training) {
    header("Location: training.php");
    exit;
}

if (
    ($current_user["role"] === "Manager" || $current_user["role"] === "IT Administrator")
    && $training["department"] !== $current_user["department"]
) {
    header("Location: training.php");
    exit;
}

$types = [
    "New Employee Orientation",
    "Professional Development",
    "Technical Training",
    "Compliance Training",
    "Safety Training",
    "Leadership Training",
    "Skills Training",
    "Workshop",
    "Other"
];

$statuses = ["Planned", "Confirmed", "Cancelled", "Completed"];

$reminders = [
    "none" => "None",
    "15" => "15 minutes before",
    "30" => "30 minutes before",
    "60" => "1 hour before",
    "1440" => "1 day before"
];

$users = [];
$user_list = $conn->query("
    SELECT id, first_name, last_name, department
    FROM users
    WHERE active = 1
    ORDER BY last_name, first_name
");
if ($user_list) {
    while ($row = $user_list->fetch_assoc()) {
        $users[] = $row;
    }
}

$departments = [];
$dept_result = $conn->query("
    SELECT DISTINCT department
    FROM users
    WHERE active = 1
      AND department IS NOT NULL
      AND department <> ''
    ORDER BY department
");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row["department"];
    }
}

$existing_attendees = [];
$attendee_stmt = $conn->prepare("
    SELECT id, user_id, department, attendance_status
    FROM training_attendees
    WHERE training_id = ?
    ORDER BY id
");
$attendee_stmt->bind_param("i", $training_id);
$attendee_stmt->execute();
$attendee_result = $attendee_stmt->get_result();
while ($row = $attendee_result->fetch_assoc()) {
    $existing_attendees[] = $row;
}
$attendee_stmt->close();

$attendee_mode = "employees";
$attendee_users = [];
$attendee_department = "";

foreach ($existing_attendees as $attendee) {
    if (!empty($attendee["user_id"])) {
        $attendee_users[] = (int)$attendee["user_id"];
    } elseif (!empty($attendee["department"])) {
        $attendee_mode = "department";
        $attendee_department = $attendee["department"];
    }
}

$previous_recipient_ids = trainingNotificationRecipientIds(
    $conn,
    $training_id,
    (int)$training["organizer"]
);
$previous_organizer_id = (int)$training["organizer"];

$errors = [];

$name = $training["name"];
$description = $training["description"];
$type = $training["type"];
$organizer = (int)$training["organizer"];
$department = $training["department"];
$location = $training["location"];
$start_date = $training["start_date"];
$end_date = $training["end_date"];
$start_time = $training["start_time"];
$end_time = $training["end_time"];
$status = $training["status"];
$reminder = $training["reminder"];

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
    $reminder = trim($_POST["reminder"] ?? "none");
    $attendee_mode = trim($_POST["attendee_mode"] ?? "employees");
    $attendee_users = isset($_POST["attendees"]) && is_array($_POST["attendees"])
        ? array_values(array_unique(array_map("intval", $_POST["attendees"])))
        : [];
    $attendee_department = trim($_POST["attendee_department"] ?? "");

    if ($name === "") $errors[] = "Training Name is required.";
    if ($description === "") $errors[] = "Description is required.";
    if (!in_array($type, $types, true)) $errors[] = "Please select a valid training type.";
    if ($organizer <= 0) $errors[] = "Organizer is required.";
    if ($department === "") $errors[] = "Department is required.";
    if ($location === "") $errors[] = "Location is required.";
    if ($start_date === "") $errors[] = "Start Date is required.";
    if ($end_date === "") $errors[] = "End Date is required.";
    if ($start_time === "") $errors[] = "Start Time is required.";
    if ($end_time === "") $errors[] = "End Time is required.";
    if (!in_array($status, $statuses, true)) $errors[] = "Please select a valid status.";
    if (!array_key_exists($reminder, $reminders)) $errors[] = "Please select a valid reminder.";

    if ($start_date !== "" && $end_date !== "" && $end_date < $start_date) {
        $errors[] = "End Date cannot be before Start Date.";
    }

    if ($start_date !== "" && $end_date !== "" &&
        $start_date === $end_date &&
        $start_time !== "" && $end_time !== "" &&
        $end_time < $start_time) {
        $errors[] = "End Time cannot be before Start Time.";
    }

    if (!in_array($attendee_mode, ["employees", "department"], true)) {
        $errors[] = "Please select a valid attendee option.";
    }

    if ($attendee_mode === "employees" && count($attendee_users) === 0) {
        $errors[] = "Select at least one attendee.";
    }

    if ($attendee_mode === "department" && $attendee_department === "") {
        $errors[] = "Select an attendee department.";
    }

    if (
        ($current_user["role"] === "Manager" || $current_user["role"] === "IT Administrator")
        && $department !== $current_user["department"]
    ) {
        $errors[] = "You can only edit training in your own department.";
    }

    if (!$errors) {
        $conn->begin_transaction();

        try {
            $old_values = [
                "Training Name" => $training["name"],
                "Description" => $training["description"],
                "Type" => $training["type"],
                "Organizer" => (string)$training["organizer"],
                "Department" => $training["department"],
                "Location" => $training["location"],
                "Start Date" => $training["start_date"],
                "End Date" => $training["end_date"],
                "Start Time" => $training["start_time"],
                "End Time" => $training["end_time"],
                "Status" => $training["status"],
                "Reminder" => $training["reminder"]
            ];

            $new_values = [
                "Training Name" => $name,
                "Description" => $description,
                "Type" => $type,
                "Organizer" => (string)$organizer,
                "Department" => $department,
                "Location" => $location,
                "Start Date" => $start_date,
                "End Date" => $end_date,
                "Start Time" => $start_time,
                "End Time" => $end_time,
                "Status" => $status,
                "Reminder" => $reminder
            ];

            $update_stmt = $conn->prepare("
                UPDATE training
                SET name = ?, description = ?, type = ?, organizer = ?, department = ?,
                    location = ?, start_date = ?, end_date = ?, start_time = ?,
                    end_time = ?, status = ?, reminder = ?
                WHERE id = ? AND deleted = 0
            ");
            $update_stmt->bind_param(
                "sssissssssssi",
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
                $reminder,
                $training_id
            );
            $update_stmt->execute();
            $update_stmt->close();

            $history_stmt = $conn->prepare("
                INSERT INTO training_history
                (training_id, field_name, old_value, new_value, changed_by)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($old_values as $field_name => $old_value) {
                $new_value = $new_values[$field_name];
                if ((string)$old_value !== (string)$new_value) {
                    $history_stmt->bind_param(
                        "isssi",
                        $training_id,
                        $field_name,
                        $old_value,
                        $new_value,
                        $user_id
                    );
                    $history_stmt->execute();
                }
            }
            $history_stmt->close();

            /*
             * Compare the actual attendee data (mode, department, and
             * the set of user ids) rather than rendered name strings -
             * two different people can render to the same name, which
             * would otherwise hide a real change, and reordering the
             * same set of people would otherwise look like a change
             * when nothing actually changed.
             */
            $old_mode = "employees";
            $old_department = "";
            $old_employee_ids = [];

            foreach ($existing_attendees as $attendee) {
                if (!empty($attendee["user_id"])) {
                    $old_employee_ids[] = (int)$attendee["user_id"];
                } elseif (!empty($attendee["department"])) {
                    $old_mode = "department";
                    $old_department = $attendee["department"];
                }
            }

            sort($old_employee_ids);

            $new_employee_ids = $attendee_users;
            sort($new_employee_ids);

            $attendees_changed =
                $old_mode !== $attendee_mode
                || ($old_mode === "department" && $old_department !== $attendee_department)
                || ($old_mode === "employees" && $old_employee_ids !== $new_employee_ids);

            if ($attendees_changed) {

                if ($old_mode === "department") {
                    $old_attendee_description = "Entire Department: " . $old_department;
                } else {
                    $old_names = [];
                    $name_stmt = $conn->prepare("
                        SELECT first_name, last_name
                        FROM users
                        WHERE id = ?
                    ");
                    foreach ($old_employee_ids as $old_user_id) {
                        $name_stmt->bind_param("i", $old_user_id);
                        $name_stmt->execute();
                        $person = $name_stmt->get_result()->fetch_assoc();
                        if ($person) {
                            $old_names[] = $person["first_name"] . " " . $person["last_name"];
                        }
                    }
                    $name_stmt->close();
                    $old_attendee_description = "Specific Employees: " . implode(", ", $old_names);
                }

                if ($attendee_mode === "department") {
                    $new_attendee_description = "Entire Department: " . $attendee_department;
                } else {
                    $new_names = [];
                    $name_stmt = $conn->prepare("
                        SELECT first_name, last_name
                        FROM users
                        WHERE id = ?
                    ");
                    foreach ($attendee_users as $selected_user_id) {
                        $name_stmt->bind_param("i", $selected_user_id);
                        $name_stmt->execute();
                        $person = $name_stmt->get_result()->fetch_assoc();
                        if ($person) {
                            $new_names[] = $person["first_name"] . " " . $person["last_name"];
                        }
                    }
                    $name_stmt->close();
                    $new_attendee_description = "Specific Employees: " . implode(", ", $new_names);
                }

                $delete_attendees = $conn->prepare("
                    DELETE FROM training_attendees
                    WHERE training_id = ?
                ");
                $delete_attendees->bind_param("i", $training_id);
                $delete_attendees->execute();
                $delete_attendees->close();

                if ($attendee_mode === "employees") {
                    $insert_attendee = $conn->prepare("
                        INSERT INTO training_attendees
                        (training_id, user_id, department, attendance_status)
                        VALUES (?, ?, NULL, 'No Response')
                    ");
                    foreach ($attendee_users as $selected_user_id) {
                        $insert_attendee->bind_param("ii", $training_id, $selected_user_id);
                        $insert_attendee->execute();
                    }
                    $insert_attendee->close();
                } else {
                    $insert_attendee = $conn->prepare("
                        INSERT INTO training_attendees
                        (training_id, user_id, department, attendance_status)
                        VALUES (?, NULL, ?, 'No Response')
                    ");
                    $insert_attendee->bind_param("is", $training_id, $attendee_department);
                    $insert_attendee->execute();
                    $insert_attendee->close();
                }

                $attendee_history_stmt = $conn->prepare("
                    INSERT INTO training_history
                    (training_id, field_name, old_value, new_value, changed_by)
                    VALUES (?, 'Attendees', ?, ?, ?)
                ");
                $attendee_history_stmt->bind_param(
                    "issi",
                    $training_id,
                    $old_attendee_description,
                    $new_attendee_description,
                    $user_id
                );
                $attendee_history_stmt->execute();
                $attendee_history_stmt->close();
            }

            $conn->commit();

            $current_recipient_ids = trainingNotificationRecipientIds(
                $conn,
                $training_id,
                $organizer
            );

            $added_recipient_ids = array_values(
                array_diff($current_recipient_ids, $previous_recipient_ids)
            );
            $removed_recipient_ids = array_values(
                array_diff($previous_recipient_ids, $current_recipient_ids)
            );
            $remaining_recipient_ids = array_values(
                array_intersect($current_recipient_ids, $previous_recipient_ids)
            );

            foreach ($added_recipient_ids as $recipient_id) {
                $recipient_id = (int)$recipient_id;

                if ($recipient_id === $user_id) {
                    continue;
                }

                if ($recipient_id === $organizer) {
                    createNotification(
                        $conn,
                        $recipient_id,
                        "Training",
                        "Training Organizer Assignment",
                        "You were assigned as the organizer for \"" . $name . "\".",
                        "Training",
                        $training_id,
                        "view_training.php?id=" . $training_id
                    );
                } else {
                    createNotification(
                        $conn,
                        $recipient_id,
                        "Training",
                        "Training Assignment",
                        "You were added to the training \"" . $name . "\".",
                        "Training",
                        $training_id,
                        "view_training.php?id=" . $training_id
                    );
                }
            }

            foreach ($removed_recipient_ids as $recipient_id) {
                $recipient_id = (int)$recipient_id;

                if ($recipient_id === $user_id) {
                    continue;
                }

                if ($recipient_id === $previous_organizer_id) {
                    createNotification(
                        $conn,
                        $recipient_id,
                        "Training",
                        "Training Organizer Assignment Removed",
                        "You are no longer the organizer for \"" . $name . "\".",
                        "Training",
                        $training_id,
                        "training.php"
                    );
                } else {
                    createNotification(
                        $conn,
                        $recipient_id,
                        "Training",
                        "Removed from Training",
                        "You were removed from the training \"" . $name . "\".",
                        "Training",
                        $training_id,
                        "training.php"
                    );
                }
            }

            foreach ($remaining_recipient_ids as $recipient_id) {
                $recipient_id = (int)$recipient_id;

                if ($recipient_id === $user_id) {
                    continue;
                }

                createNotification(
                    $conn,
                    $recipient_id,
                    "Training",
                    $status === "Cancelled" ? "Training Cancelled" : "Training Updated",
                    $status === "Cancelled"
                        ? "The training \"" . $name . "\" was cancelled."
                        : "The training \"" . $name . "\" was updated.",
                    "Training",
                    $training_id,
                    "view_training.php?id=" . $training_id
                );
            }

            header("Location: view_training.php?id=" . $training_id);
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
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
<title>Edit Training</title>

<link rel="stylesheet" href="app.css?v=15">

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
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item">
            <a href="analytics.php">Analytics</a>
        </div>
        <div class="sidebar-item active">
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

        <?php if (canPostAnnouncements($current_user["role"])): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($current_user["role"])): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($current_user["role"] === "Administrator"): ?>
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
            <h1>EDIT TRAINING</h1>
            <div class="sub">Update training session information</div>
        </div>

        <a class="button" href="view_training.php?id=<?php echo $training_id; ?>">
            BACK TO TRAINING
        </a>
    </div>

    <div class="content">

    <?php if ($errors): ?>
        <div class="error">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="training_id" value="<?php echo $training_id; ?>">

        <div class="card">
            <h2>Training Information</h2>

            <div class="grid">

                <div class="field full">
                    <label for="name">Training Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name"
                           value="<?php echo htmlspecialchars($name); ?>" required>
                </div>

                <div class="field full">
                    <label for="description">Description <span class="required">*</span></label>
                    <textarea id="description" name="description" required><?php echo htmlspecialchars($description); ?></textarea>
                </div>

                <div class="field">
                    <label for="type">Training Type <span class="required">*</span></label>
                    <select id="type" name="type" required>
                        <option value="">Select Type</option>
                        <?php foreach ($types as $item): ?>
                            <option value="<?php echo htmlspecialchars($item); ?>"
                                <?php echo $type === $item ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($item); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="organizer">Organizer <span class="required">*</span></label>
                    <select id="organizer" name="organizer" required>
                        <option value="">Select Organizer</option>
                        <?php foreach ($users as $person): ?>
                            <option value="<?php echo (int)$person["id"]; ?>"
                                <?php echo $organizer === (int)$person["id"] ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($person["first_name"] . " " . $person["last_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="department">Department <span class="required">*</span></label>

                    <?php if ($current_user["role"] === "Manager" || $current_user["role"] === "IT Administrator"): ?>
                        <input type="text"
                               class="readonly"
                               value="<?php echo htmlspecialchars($department); ?>"
                               readonly>

                        <input type="hidden"
                               name="department"
                               value="<?php echo htmlspecialchars($department); ?>">

                        <div class="note">
                            You can only edit training in your own department.
                        </div>
                    <?php else: ?>
                        <select id="department" name="department" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"
                                    <?php echo $department === $dept ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="location">Location <span class="required">*</span></label>
                    <input type="text" id="location" name="location"
                           value="<?php echo htmlspecialchars($location); ?>"
                           placeholder="Room, building, or online location" required>
                </div>

                <div class="field">
                    <label for="start_date">Start Date <span class="required">*</span></label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>

                <div class="field">
                    <label for="end_date">End Date <span class="required">*</span></label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>

                <div class="field">
                    <label for="start_time">Start Time <span class="required">*</span></label>
                    <input type="time" id="start_time" name="start_time"
                           value="<?php echo htmlspecialchars($start_time); ?>" required>
                </div>

                <div class="field">
                    <label for="end_time">End Time <span class="required">*</span></label>
                    <input type="time" id="end_time" name="end_time"
                           value="<?php echo htmlspecialchars($end_time); ?>" required>
                </div>

            </div>
        </div>

        <div class="card">
            <h2>Attendees</h2>

            <div class="field">
                <label>Attendee Type <span class="required">*</span></label>

                <div class="radio-row">
                    <label>
                        <input type="radio" name="attendee_mode" value="employees"
                            <?php echo $attendee_mode === "employees" ? "checked" : ""; ?>>
                        Specific Employees
                    </label>

                    <label>
                        <input type="radio" name="attendee_mode" value="department"
                            <?php echo $attendee_mode === "department" ? "checked" : ""; ?>>
                        Entire Department
                    </label>
                </div>
            </div>

            <div class="field" style="margin-top:18px;">
                <label>Specific Employees</label>

                <div class="checkbox-grid">
                    <?php foreach ($users as $person): ?>
                        <?php $person_id = (int)$person["id"]; ?>

                        <div class="checkbox-item">
                            <label>
                                <input type="checkbox"
                                       name="attendees[]"
                                       value="<?php echo $person_id; ?>"
                                    <?php echo in_array($person_id, $attendee_users, true) ? "checked" : ""; ?>>

                                <?php echo htmlspecialchars($person["first_name"] . " " . $person["last_name"]); ?>

                                <small style="color:#6b7280;">
                                    — <?php echo htmlspecialchars($person["department"]); ?>
                                </small>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="field" style="margin-top:18px;">
                <label for="attendee_department">Entire Department</label>

                <select id="attendee_department" name="attendee_department">
                    <option value="">Select Department</option>

                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"
                            <?php echo $attendee_department === $dept ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="card">
            <h2>Status and Reminder</h2>

            <div class="grid">

                <div class="field">
                    <label for="status">Status</label>

                    <select id="status" name="status">
                        <?php foreach ($statuses as $item): ?>
                            <option value="<?php echo htmlspecialchars($item); ?>"
                                <?php echo $status === $item ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($item); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="reminder">Reminder</label>

                    <select id="reminder" name="reminder">
                        <?php foreach ($reminders as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value); ?>"
                                <?php echo $reminder === $value ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field full">
                    <label>Reminder Delivery</label>
                    <input type="text" value="Email" readonly>
                </div>

            </div>
        </div>

        <div class="actions">
            <a class="button" href="view_training.php?id=<?php echo $training_id; ?>">
                CANCEL
            </a>

            <button class="button primary" type="submit">
                SAVE CHANGES
            </button>
        </div>

    </form>

</div>

</div>

</div>

</body>
</html>