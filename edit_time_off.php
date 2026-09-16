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

$role = $current_user["role"];
$user_department = $current_user["department"];

$time_off_id = (int)($_GET["id"] ?? $_POST["time_off_id"] ?? 0);

if ($time_off_id <= 0) {
    header("Location: time_off.php");
    exit;
}

$time_off_stmt = $conn->prepare("
    SELECT
        t.id,
        t.user_id,
        t.time_off_type,
        t.start_date,
        t.end_date,
        t.total_days,
        t.reason_notes,
        t.status,
        t.submitted_at,
        t.attachment,
        u.first_name,
        u.last_name,
        u.department
    FROM time_off t
    INNER JOIN users u ON u.id = t.user_id
    WHERE t.id = ?
");
$time_off_stmt->bind_param("i", $time_off_id);
$time_off_stmt->execute();
$time_off = $time_off_stmt->get_result()->fetch_assoc();
$time_off_stmt->close();

if (!$time_off) {
    header("Location: time_off.php");
    exit;
}

/*
 * Permissions:
 * - Administrators can edit any request.
 * - IT Administrators can edit any request in their own department.
 * - Employees can edit only their own Draft or Submitted requests.
 * - Managers do not edit employee requests here; they review them on view_time_off.php.
 */
$can_edit = false;

if ($role === "Administrator") {
    $can_edit = true;
} elseif (
    $role === "IT Administrator"
    && $time_off["department"] === $user_department
) {
    $can_edit = true;
} elseif (
    (int)$time_off["user_id"] === $user_id
    && in_array($time_off["status"], ["Draft", "Submitted"], true)
) {
    $can_edit = true;
}

if (!$can_edit) {
    header("Location: view_time_off.php?id=" . $time_off_id);
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

$time_off_type = $time_off["time_off_type"];
$start_date = $time_off["start_date"];
$end_date = $time_off["end_date"];
$total_days = $time_off["total_days"];
$reason_notes = $time_off["reason_notes"];
$status = $time_off["status"];
$attachment = $time_off["attachment"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $time_off_type = trim($_POST["time_off_type"] ?? "");
    $start_date = trim($_POST["start_date"] ?? "");
    $end_date = trim($_POST["end_date"] ?? "");
    $total_days = trim($_POST["total_days"] ?? "");
    $reason_notes = trim($_POST["reason_notes"] ?? "");
    $action = trim($_POST["action"] ?? "save");

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

    if (!in_array($action, ["save", "submit", "cancel_request"], true)) {
        $errors[] = "Invalid request action.";
    }

    /*
     * Cancellation is allowed for the owner while Draft/Submitted,
     * and Administrators can cancel any request.
     */
    if ($action === "cancel_request") {

        $is_department_admin =
            $role === "IT Administrator"
            && $time_off["department"] === $user_department;

        if (
            $role !== "Administrator"
            && !$is_department_admin
            && (
                (int)$time_off["user_id"] !== $user_id
                || !in_array($time_off["status"], ["Draft", "Submitted"], true)
            )
        ) {
            $errors[] = "You cannot cancel this request.";
        }

        if (!$errors) {

            $cancel_stmt = $conn->prepare("
                UPDATE time_off
                SET
                    status = 'Cancelled',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $cancel_stmt->bind_param("i", $time_off_id);
            $cancel_stmt->execute();
            $cancel_stmt->close();

            header("Location: view_time_off.php?id=" . $time_off_id);
            exit;
        }
    }

    if (!$errors && $action !== "cancel_request") {

        $new_attachment = $attachment;

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
                            . $time_off["user_id"]
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
                            $new_attachment = $safe_name;
                        }
                    }
                }
            }
        }

        if (!$errors) {

            if ($action === "submit") {
                $new_status = "Submitted";
                $submitted_at = date("Y-m-d H:i:s");
            } else {
                $new_status = $time_off["status"];
                $submitted_at = $time_off["submitted_at"];

                $is_department_admin =
                    $role === "IT Administrator"
                    && $time_off["department"] === $user_department;

                if ($role !== "Administrator" && !$is_department_admin) {
                    $new_status = $time_off["status"] === "Draft"
                        ? "Draft"
                        : "Submitted";
                }
            }

            $update_stmt = $conn->prepare("
                UPDATE time_off
                SET
                    time_off_type = ?,
                    start_date = ?,
                    end_date = ?,
                    total_days = ?,
                    reason_notes = ?,
                    status = ?,
                    submitted_at = ?,
                    attachment = ?
                WHERE id = ?
            ");

            $total_days_value = (float)$total_days;

            $update_stmt->bind_param(
                "sssdssssi",
                $time_off_type,
                $start_date,
                $end_date,
                $total_days_value,
                $reason_notes,
                $new_status,
                $submitted_at,
                $new_attachment,
                $time_off_id
            );

            if ($update_stmt->execute()) {

                $update_stmt->close();

                /*
                 * Remove the old physical attachment only after a successful update
                 * and only when a new file replaced it.
                 */
                if (
                    $new_attachment !== $attachment
                    && !empty($attachment)
                ) {
                    $old_file =
                        TIME_OFF_ATTACHMENT_DIR
                        . DIRECTORY_SEPARATOR
                        . basename($attachment);

                    if (file_exists($old_file)) {
                        @unlink($old_file);
                    }
                }

                header("Location: view_time_off.php?id=" . $time_off_id);
                exit;

            } else {

                $update_stmt->close();

                if (
                    $new_attachment !== $attachment
                    && !empty($new_attachment)
                ) {
                    $new_file =
                        TIME_OFF_ATTACHMENT_DIR
                        . DIRECTORY_SEPARATOR
                        . basename($new_attachment);

                    if (file_exists($new_file)) {
                        @unlink($new_file);
                    }
                }

                $errors[] = "Unable to update the Time Off request.";
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

<title>Edit Time Off Request</title>

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
            <h1>EDIT TIME OFF REQUEST</h1>

            <div class="sub">
                Request #<?php echo (int)$time_off["id"]; ?>
            </div>
        </div>

        <a
            class="button"
            href="view_time_off.php?id=<?php echo (int)$time_off["id"]; ?>"
        >
            BACK TO REQUEST
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

        <input
            type="hidden"
            name="time_off_id"
            value="<?php echo (int)$time_off["id"]; ?>"
        >

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
                            $time_off["first_name"]
                            . " "
                            . $time_off["last_name"]
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
                        value="<?php echo htmlspecialchars($time_off["department"]); ?>"
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
                    ><?php echo htmlspecialchars($reason_notes); ?></textarea>
                </div>

                <div class="field full">
                    <label>Current Attachment</label>

                    <div class="current-file">

                        <?php if (!empty($attachment)): ?>

                            <a
                                href="download_time_off_attachment.php?id=<?php echo (int)$time_off["id"]; ?>"
                                target="_blank"
                            >
                                VIEW CURRENT ATTACHMENT
                            </a>

                        <?php else: ?>

                            No attachment

                        <?php endif; ?>

                    </div>
                </div>

                <div class="field full">
                    <label for="attachment">
                        Replace Attachment
                    </label>

                    <input
                        type="file"
                        id="attachment"
                        name="attachment"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                    >

                    <div class="note">
                        Leave blank to keep the current attachment.
                    </div>
                </div>

            </div>

        </div>

        <div class="actions">

            <button
                class="button danger"
                type="submit"
                name="action"
                value="cancel_request"
            >
                CANCEL REQUEST
            </button>

            <div class="action-right">

                <a
                    class="button"
                    href="view_time_off.php?id=<?php echo (int)$time_off["id"]; ?>"
                >
                    DISCARD CHANGES
                </a>

                <button
                    class="button"
                    type="submit"
                    name="action"
                    value="save"
                >
                    SAVE CHANGES
                </button>

                <?php if ($time_off["status"] === "Draft"): ?>

                    <button
                        class="button primary"
                        type="submit"
                        name="action"
                        value="submit"
                    >
                        SUBMIT REQUEST
                    </button>

                <?php endif; ?>

            </div>

        </div>

    </form>

</div>

</div>

</div>

</body>
</html>
