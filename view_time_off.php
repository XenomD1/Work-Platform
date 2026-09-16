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

$role = $current_user["role"];
$user_department = $current_user["department"];
$self_service_roles = ["Employee"];

$time_off_id = (int)($_GET["id"] ?? $_POST["time_off_id"] ?? 0);

if ($time_off_id <= 0) {
    header("Location: time_off.php");
    exit;
}

$message = "";
$error = "";

/*
 * Load request and employee information.
 */
function loadTimeOff($conn, $time_off_id) {
    $stmt = $conn->prepare("
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
            t.approver_id,
            t.approval_date,
            t.rejection_reason,
            t.attachment,
            t.created_at,
            t.updated_at,
            u.first_name,
            u.last_name,
            u.department,
            CONCAT(a.first_name, ' ', a.last_name) AS approver_name
        FROM time_off t
        INNER JOIN users u ON u.id = t.user_id
        LEFT JOIN users a ON a.id = t.approver_id
        WHERE t.id = ?
    ");
    $stmt->bind_param("i", $time_off_id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $record;
}

$time_off = loadTimeOff($conn, $time_off_id);

if (!$time_off) {
    header("Location: time_off.php");
    exit;
}

/*
 * Visibility:
 * Employee -> own request only
 * Manager / IT Administrator -> same department
 * Administrator -> any request
 */
$can_view = false;

if ($role === "Administrator") {
    $can_view = true;
} elseif (
    ($role === "Manager" || $role === "IT Administrator")
    && $time_off["department"] === $user_department
) {
    $can_view = true;
} elseif (
    in_array($role, $self_service_roles, true)
    && (int)$time_off["user_id"] === $user_id
) {
    $can_view = true;
}

if (!$can_view) {
    header("Location: time_off.php");
    exit;
}

/*
 * Managers, IT Administrators (in the employee's department), and
 * Administrators can approve/reject Submitted requests - never the
 * requester themselves.
 */
$can_review =
    $time_off["status"] === "Submitted"
    && (int)$time_off["user_id"] !== $user_id
    && (
        $role === "Administrator"
        || (
            ($role === "Manager" || $role === "IT Administrator")
            && $time_off["department"] === $user_department
        )
    );

/*
 * Owner can edit Draft/Submitted.
 * Administrator can edit any request.
 * IT Administrator can edit any request in their own department.
 */
$can_edit =
    $role === "Administrator"
    || (
        $role === "IT Administrator"
        && $time_off["department"] === $user_department
    )
    || (
        (int)$time_off["user_id"] === $user_id
        && in_array($time_off["status"], ["Draft", "Submitted"], true)
    );

/*
 * Handle approval/rejection.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = trim($_POST["action"] ?? "");

    if (!$can_review) {
        $error = "You do not have permission to review this request.";
    } elseif ($action === "approve") {

        $stmt = $conn->prepare("
            UPDATE time_off
            SET
                status = 'Approved',
                approver_id = ?,
                approval_date = NOW(),
                rejection_reason = NULL
            WHERE id = ?
              AND status = 'Submitted'
        ");
        $stmt->bind_param("ii", $user_id, $time_off_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $message = "Time Off request approved.";
        } else {
            $error = "The request could not be approved.";
        }

        $stmt->close();

    } elseif ($action === "reject") {

        $rejection_reason = trim($_POST["rejection_reason"] ?? "");

        if ($rejection_reason === "") {
            $error = "A rejection reason is required.";
        } else {

            $stmt = $conn->prepare("
                UPDATE time_off
                SET
                    status = 'Rejected',
                    approver_id = ?,
                    approval_date = NOW(),
                    rejection_reason = ?
                WHERE id = ?
                  AND status = 'Submitted'
            ");
            $stmt->bind_param(
                "isi",
                $user_id,
                $rejection_reason,
                $time_off_id
            );
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $message = "Time Off request rejected.";
            } else {
                $error = "The request could not be rejected.";
            }

            $stmt->close();
        }

    } else {
        $error = "Invalid review action.";
    }

    /*
     * Reload after any update.
     */
    $time_off = loadTimeOff($conn, $time_off_id);

    $can_review =
        $time_off["status"] === "Submitted"
        && (int)$time_off["user_id"] !== $user_id
        && (
            $role === "Administrator"
            || (
                ($role === "Manager" || $role === "IT Administrator")
                && $time_off["department"] === $user_department
            )
        );

    $can_edit =
        $role === "Administrator"
        || (
            $role === "IT Administrator"
            && $time_off["department"] === $user_department
        )
        || (
            (int)$time_off["user_id"] === $user_id
            && in_array($time_off["status"], ["Draft", "Submitted"], true)
        );
}

function formatDateValue($value) {
    if (!$value) {
        return "—";
    }

    return date("M j, Y", strtotime($value));
}

function formatDateTimeValue($value) {
    if (!$value) {
        return "—";
    }

    return date("M j, Y g:i A", strtotime($value));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>View Time Off</title>

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
            <h1>TIME OFF REQUEST</h1>

            <div class="sub">
                Request #<?php echo (int)$time_off["id"]; ?>
            </div>
        </div>

        <div class="header-actions">

            <a class="button" href="time_off.php">
                BACK TO TIME OFF
            </a>

            <?php if ($can_edit): ?>

                <a
                    class="button primary"
                    href="edit_time_off.php?id=<?php echo (int)$time_off["id"]; ?>"
                >
                    EDIT REQUEST
                </a>

            <?php endif; ?>

        </div>

    </div>

    <div class="content">

    <?php if ($message !== ""): ?>
        <div class="message">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="card">

        <h2>
            Request Information
            &nbsp;
            <span class="status">
                <?php echo htmlspecialchars($time_off["status"]); ?>
            </span>
        </h2>

        <div class="grid">

            <div class="detail">
                <span class="label">Employee</span>
                <span class="value">
                    <?php
                    echo htmlspecialchars(
                        $time_off["first_name"]
                        . " "
                        . $time_off["last_name"]
                    );
                    ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Department</span>
                <span class="value">
                    <?php echo htmlspecialchars($time_off["department"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Time Off Type</span>
                <span class="value">
                    <?php echo htmlspecialchars($time_off["time_off_type"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Total Days</span>
                <span class="value">
                    <?php echo htmlspecialchars($time_off["total_days"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Start Date</span>
                <span class="value">
                    <?php echo htmlspecialchars(formatDateValue($time_off["start_date"])); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">End Date</span>
                <span class="value">
                    <?php echo htmlspecialchars(formatDateValue($time_off["end_date"])); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Submitted Date</span>
                <span class="value">
                    <?php echo htmlspecialchars(formatDateTimeValue($time_off["submitted_at"])); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Created Date</span>
                <span class="value">
                    <?php echo htmlspecialchars(formatDateTimeValue($time_off["created_at"])); ?>
                </span>
            </div>

            <div class="detail full">
                <span class="label">Reason / Notes</span>
                <span class="value notes"><?php
                    echo $time_off["reason_notes"] !== ""
                        ? htmlspecialchars($time_off["reason_notes"])
                        : "—";
                ?></span>
            </div>

            <div class="detail full">
                <span class="label">Attachment</span>
                <span class="value">

                    <?php if (!empty($time_off["attachment"])): ?>

                        <a
                            class="attachment-link"
                            href="download_time_off_attachment.php?id=<?php echo (int)$time_off["id"]; ?>"
                            target="_blank"
                        >
                            VIEW ATTACHMENT
                        </a>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </span>
            </div>

        </div>

    </div>

    <?php if (
        !empty($time_off["approver_name"])
        || !empty($time_off["approval_date"])
        || !empty($time_off["rejection_reason"])
    ): ?>

        <div class="card">

            <h2>Review Information</h2>

            <div class="grid">

                <div class="detail">
                    <span class="label">Reviewed By</span>
                    <span class="value">
                        <?php echo htmlspecialchars($time_off["approver_name"] ?: "—"); ?>
                    </span>
                </div>

                <div class="detail">
                    <span class="label">Reviewed Date</span>
                    <span class="value">
                        <?php echo htmlspecialchars(formatDateTimeValue($time_off["approval_date"])); ?>
                    </span>
                </div>

                <?php if (!empty($time_off["rejection_reason"])): ?>

                    <div class="detail full">
                        <span class="label">Rejection Reason</span>
                        <span class="value notes"><?php
                            echo htmlspecialchars($time_off["rejection_reason"]);
                        ?></span>
                    </div>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

    <?php if ($can_review): ?>

        <div class="card">

            <h2>Review Request</h2>

            <div class="review-grid">

                <div class="review-form">

                    <h3>Approve Request</h3>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="time_off_id"
                            value="<?php echo (int)$time_off["id"]; ?>"
                        >

                        <button
                            class="button approve"
                            type="submit"
                            name="action"
                            value="approve"
                        >
                            APPROVE
                        </button>

                    </form>

                </div>

                <div class="review-form">

                    <h3>Reject Request</h3>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="time_off_id"
                            value="<?php echo (int)$time_off["id"]; ?>"
                        >

                        <textarea
                            name="rejection_reason"
                            placeholder="Enter the reason for rejecting this request"
                            required
                        ></textarea>

                        <button
                            class="button reject"
                            type="submit"
                            name="action"
                            value="reject"
                        >
                            REJECT
                        </button>

                    </form>

                </div>

            </div>

        </div>

    <?php endif; ?>

</div>

</div>

</div>

</body>
</html>
