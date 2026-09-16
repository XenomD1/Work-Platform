<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require "storage_config.php";
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

$time_off_types = [
    "Vacation",
    "Sick Leave",
    "Personal Leave",
    "Bereavement",
    "Jury Duty",
    "Medical Leave",
    "Other"
];

$errors = [];

$time_off_type = "";
$start_date = "";
$end_date = "";
$total_days = "";
$reason_notes = "";
$status = "Draft";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $time_off_type = trim($_POST["time_off_type"] ?? "");
    $start_date = trim($_POST["start_date"] ?? "");
    $end_date = trim($_POST["end_date"] ?? "");
    $total_days = trim($_POST["total_days"] ?? "");
    $reason_notes = trim($_POST["reason_notes"] ?? "");
    $action = trim($_POST["action"] ?? "draft");

    if (!in_array($time_off_type, $time_off_types, true)) {
        $errors[] = "Please select a valid Time Off Type.";
    }

    if ($start_date === "") {
        $errors[] = "Start Date is required.";
    }

    if ($end_date === "") {
        $errors[] = "End Date is required.";
    }

    if ($start_date !== "" && $end_date !== "" && $end_date < $start_date) {
        $errors[] = "End Date cannot be before Start Date.";
    }

    if ($total_days === "" || !is_numeric($total_days) || (float)$total_days <= 0) {
        $errors[] = "Total Days must be greater than 0.";
    }

    if ($action !== "draft" && $action !== "submit") {
        $errors[] = "Invalid request action.";
    }

    if (!$errors) {

        if ($action === "submit") {
            $status = "Submitted";
            $submitted_at = date("Y-m-d H:i:s");
        } else {
            $status = "Draft";
            $submitted_at = null;
        }

        $attachment_name = null;

        if (isset($_FILES["attachment"]) && $_FILES["attachment"]["error"] !== UPLOAD_ERR_NO_FILE) {

            if ($_FILES["attachment"]["error"] !== UPLOAD_ERR_OK) {
                $errors[] = "There was a problem uploading the attachment.";
            } else {

                $allowed_extensions = [
                    "pdf",
                    "doc",
                    "docx",
                    "jpg",
                    "jpeg",
                    "png"
                ];

                $original_name = basename($_FILES["attachment"]["name"]);
                $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                if (!in_array($extension, $allowed_extensions, true)) {
                    $errors[] = "Attachment must be PDF, DOC, DOCX, JPG, JPEG, or PNG.";
                } else {

                    $upload_dir = TIME_OFF_ATTACHMENT_DIR;

                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0775, true)) {
                            $errors[] = "Unable to create the attachment folder.";
                        }
                    }

                    if (!$errors) {

                        $safe_name =
                            "timeoff_"
                            . $user_id
                            . "_"
                            . time()
                            . "_"
                            . bin2hex(random_bytes(4))
                            . "."
                            . $extension;

                        $target_path =
                            $upload_dir
                            . DIRECTORY_SEPARATOR
                            . $safe_name;

                        if (!move_uploaded_file(
                            $_FILES["attachment"]["tmp_name"],
                            $target_path
                        )) {
                            $errors[] = "Unable to save the attachment.";
                        } else {
                            $attachment_name = $safe_name;
                        }
                    }
                }
            }
        }

        if (!$errors) {

            $stmt = $conn->prepare("
                INSERT INTO time_off
                (
                    user_id,
                    time_off_type,
                    start_date,
                    end_date,
                    total_days,
                    reason_notes,
                    status,
                    submitted_at,
                    attachment
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $total_days_value = (float)$total_days;

            $stmt->bind_param(
                "isssdssss",
                $user_id,
                $time_off_type,
                $start_date,
                $end_date,
                $total_days_value,
                $reason_notes,
                $status,
                $submitted_at,
                $attachment_name
            );

            if ($stmt->execute()) {

                $new_id = $stmt->insert_id;
                $stmt->close();

                header("Location: view_time_off.php?id=" . $new_id);
                exit;

            } else {

                $stmt->close();

                if ($attachment_name !== null) {
                    $uploaded_file =
                        TIME_OFF_ATTACHMENT_DIR
                        . DIRECTORY_SEPARATOR
                        . $attachment_name;

                    if (file_exists($uploaded_file)) {
                        unlink($uploaded_file);
                    }
                }

                $errors[] = "Unable to create the Time Off request.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>New Time Off Request</title>

<link rel="stylesheet" href="app.css?v=18">

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
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item active">
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
            <h1>NEW TIME OFF REQUEST</h1>
            <div class="sub">Create a new Time Off request</div>
        </div>

        <a class="button" href="time_off.php">
            BACK TO TIME OFF
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

    <form method="POST" enctype="multipart/form-data">

        <div class="card">
            <h2>Employee Information</h2>

            <div class="grid">

                <div class="field">
                    <label>Employee</label>

                    <input
                        class="readonly"
                        type="text"
                        value="<?php
                        echo htmlspecialchars(
                            $current_user["first_name"]
                            . " "
                            . $current_user["last_name"]
                        );
                        ?>"
                        readonly
                    >
                </div>

                <div class="field">
                    <label>Department</label>

                    <input
                        class="readonly"
                        type="text"
                        value="<?php echo htmlspecialchars($current_user["department"]); ?>"
                        readonly
                    >
                </div>

            </div>
        </div>

        <div class="card">
            <h2>Request Information</h2>

            <div class="grid">

                <div class="field">
                    <label for="time_off_type">
                        Time Off Type <span class="required">*</span>
                    </label>

                    <select
                        id="time_off_type"
                        name="time_off_type"
                        required
                    >
                        <option value="">Select Type</option>

                        <?php foreach ($time_off_types as $item): ?>
                            <option
                                value="<?php echo htmlspecialchars($item); ?>"
                                <?php echo $time_off_type === $item ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars($item); ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field">
                    <label for="total_days">
                        Total Days <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        id="total_days"
                        name="total_days"
                        min="0.5"
                        step="0.5"
                        value="<?php echo htmlspecialchars($total_days); ?>"
                        required
                    >

                    <div class="note">
                        Enter the total requested days, such as 1, 2, or 2.5.
                    </div>
                </div>

                <div class="field">
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

                <div class="field">
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

                <div class="field full">
                    <label for="reason_notes">
                        Reason / Notes
                    </label>

                    <textarea
                        id="reason_notes"
                        name="reason_notes"
                        placeholder="Optional notes about this request"
                    ><?php echo htmlspecialchars($reason_notes); ?></textarea>
                </div>

                <div class="field full">
                    <label for="attachment">
                        Attachment
                    </label>

                    <input
                        type="file"
                        id="attachment"
                        name="attachment"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                    >

                    <div class="note">
                        Optional. Accepted: PDF, DOC, DOCX, JPG, JPEG, PNG.
                    </div>
                </div>

            </div>

        </div>

        <div class="actions">

            <a
                class="button"
                href="time_off.php"
            >
                CANCEL
            </a>

            <button
                class="button secondary"
                type="submit"
                name="action"
                value="draft"
            >
                SAVE DRAFT
            </button>

            <button
                class="button primary"
                type="submit"
                name="action"
                value="submit"
            >
                SUBMIT REQUEST
            </button>

        </div>

    </form>

</div>

</div>

</div>

</body>
</html>
