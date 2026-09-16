<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

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

$errors = [];

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

$statuses = [
    "Planned",
    "Confirmed",
    "Cancelled",
    "Completed"
];

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

$name = "";
$description = "";
$type = "";
$organizer = $user_id;
$department = $current_user["department"];
$location = "";
$start_date = "";
$end_date = "";
$start_time = "";
$end_time = "";
$status = "Planned";
$reminder = "none";
$attendee_mode = "employees";
$attendee_users = [];
$attendee_department = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $organizer = (int)($_POST["organizer"] ?? 0);
    $department = trim($_POST["department"] ?? "");

    if ($current_user["role"] === "IT Administrator" || $current_user["role"] === "Manager") {
        $department = $current_user["department"];
    }
    $location = trim($_POST["location"] ?? "");
    $start_date = trim($_POST["start_date"] ?? "");
    $end_date = trim($_POST["end_date"] ?? "");
    $start_time = trim($_POST["start_time"] ?? "");
    $end_time = trim($_POST["end_time"] ?? "");
    $status = trim($_POST["status"] ?? "Planned");
    $reminder = trim($_POST["reminder"] ?? "none");
    $attendee_mode = trim($_POST["attendee_mode"] ?? "employees");
    $attendee_users = isset($_POST["attendees"]) && is_array($_POST["attendees"])
        ? array_map("intval", $_POST["attendees"])
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

    if ($attendee_mode === "employees" && count($attendee_users) === 0) {
        $errors[] = "Select at least one attendee.";
    }

    if ($attendee_mode === "department" && $attendee_department === "") {
        $errors[] = "Select an attendee department.";
    }

    if (!$errors) {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO training
                (name, description, type, organizer, department, location,
                 start_date, end_date, start_time, end_time, status,
                 reminder, reminder_delivery)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'email')
            ");

            $stmt->bind_param(
                "sssissssssss",
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
                $reminder
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to create training.");
            }

            $training_id = $stmt->insert_id;
            $stmt->close();

            if ($attendee_mode === "employees") {
                $attendee_stmt = $conn->prepare("
                    INSERT INTO training_attendees
                    (training_id, user_id, department, attendance_status)
                    VALUES (?, ?, NULL, 'No Response')
                ");

                foreach ($attendee_users as $attendee_id) {
                    if ($attendee_id > 0) {
                        $attendee_stmt->bind_param("ii", $training_id, $attendee_id);
                        $attendee_stmt->execute();
                    }
                }

                $attendee_stmt->close();
            } else {
                $attendee_stmt = $conn->prepare("
                    INSERT INTO training_attendees
                    (training_id, user_id, department, attendance_status)
                    VALUES (?, NULL, ?, 'No Response')
                ");
                $attendee_stmt->bind_param("is", $training_id, $attendee_department);
                $attendee_stmt->execute();
                $attendee_stmt->close();
            }

            $history_stmt = $conn->prepare("
                INSERT INTO training_history
                (training_id, field_name, old_value, new_value, changed_by)
                VALUES (?, 'Created', NULL, ?, ?)
            ");

            $history_stmt->bind_param("isi", $training_id, $name, $user_id);
            $history_stmt->execute();
            $history_stmt->close();

            $conn->commit();

            header("Location: training.php");
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
<title>New Training</title>

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
            <a href="files.php">Files</a>
        </div>
        <div class="sidebar-item">
            <a href="reports.php">Reports</a>
        </div>
        <div class="sidebar-item active">
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
            <h1>NEW TRAINING</h1>
            <div class="sub">Create a new training session</div>
        </div>

        <div class="top-actions">
            <a class="button" href="training.php">BACK TO TRAINING</a>
        </div>
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
                    <textarea id="description" name="description" required><?php
                        echo htmlspecialchars($description);
                    ?></textarea>
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
                    <select id="department" name="department" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <?php if (($current_user["role"] === "IT Administrator" || $current_user["role"] === "Manager") && $dept !== $current_user["department"]) continue; ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"
                                <?php echo $department === $dept ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="location">Location <span class="required">*</span></label>
                    <input type="text" id="location" name="location"
                           value="<?php echo htmlspecialchars($location); ?>"
                           placeholder="Room, building, or online location" required>
                </div>

            </div>
        </div>

        <div class="card">
            <h2>Date and Time</h2>

            <div class="grid">

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

                <div>
                    <label style="display:inline-block;margin-right:25px;font-weight:normal;">
                        <input type="radio" name="attendee_mode" value="employees"
                            <?php echo $attendee_mode === "employees" ? "checked" : ""; ?>>
                        Specific Employees
                    </label>

                    <label style="display:inline-block;font-weight:normal;">
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
                                <?php echo htmlspecialchars(
                                    $person["first_name"] . " " . $person["last_name"]
                                ); ?>
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
            <a class="button" href="training.php">CANCEL</a>
            <button class="button primary" type="submit">CREATE TRAINING</button>
        </div>

    </form>

</div>

</div>

</div>

</body>
</html>
