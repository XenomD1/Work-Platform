<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";
require_once "notification_helpers.php";
require_once "csrf_helpers.php";

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

/*
 * Load the current user.
 */
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

/*
 * Administrators can delete any training. IT Administrators can
 * delete training in their own department only.
 */
if (!isAnyAdministrator($current_user["role"])) {
    header("Location: training.php");
    exit;
}

$training_id = (int)($_GET["id"] ?? $_POST["training_id"] ?? 0);

if ($training_id <= 0) {
    header("Location: training.php");
    exit;
}

/*
 * Load the active training record.
 */
$training_stmt = $conn->prepare("
    SELECT
        t.id,
        t.name,
        t.description,
        t.type,
        t.department,
        t.location,
        t.start_date,
        t.end_date,
        t.start_time,
        t.end_time,
        t.status,
        t.organizer,
        CONCAT(u.first_name, ' ', u.last_name) AS organizer_name
    FROM training t
    LEFT JOIN users u ON u.id = t.organizer
    WHERE t.id = ?
      AND t.deleted = 0
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
    !canAdministerDepartment(
        $current_user["role"],
        $current_user["department"] ?? "",
        $training["department"]
    )
) {
    http_response_code(403);
    exit("You do not have permission to delete this training.");
}

$error = "";

/*
 * Soft delete after confirmation.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $confirm = $_POST["confirm_delete"] ?? "";

    if ($confirm !== "yes") {
        $error = "Please confirm that you want to delete this training.";
    } else {

        $delete_stmt = $conn->prepare("
            UPDATE training
            SET
                deleted = 1,
                deleted_at = NOW(),
                deleted_by = ?
            WHERE id = ?
              AND deleted = 0
        ");

        $delete_stmt->bind_param("ii", $user_id, $training_id);

        if ($delete_stmt->execute()) {
            $deleted = $delete_stmt->affected_rows > 0;
            $delete_stmt->close();

            if ($deleted) {
                $recipient_ids = trainingNotificationRecipientIds(
                    $conn,
                    $training_id,
                    (int)$training["organizer"]
                );

                foreach ($recipient_ids as $recipient_id) {
                    if ($recipient_id === $user_id) {
                        continue;
                    }

                    createNotification(
                        $conn,
                        $recipient_id,
                        "Training",
                        "Training Deleted",
                        "The training \"" . $training["name"] . "\" was deleted.",
                        "Training",
                        $training_id,
                        "training.php"
                    );
                }
            }

            header("Location: training.php");
            exit;
        }

        $delete_stmt->close();
        $error = "Unable to delete training.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Delete Training</title>

<link rel="stylesheet" href="app.css?v=20">

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
            <h1>DELETE TRAINING</h1>
            <div class="sub">Confirm training deletion</div>
        </div>

        <a
            class="button"
            href="view_training.php?id=<?php echo $training_id; ?>"
        >
            BACK TO TRAINING
        </a>
    </div>

    <div class="content">

    <?php if ($error !== ""): ?>
        <div class="error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="warning">
        <strong>Warning:</strong>
        This training will be removed from normal Training and Calendar views.
        It will not be permanently erased and can be restored later by an Administrator.
    </div>

    <div class="card">

        <div class="details">

            <div class="detail full">
                <span class="label">Training Name</span>
                <span class="value">
                    <?php echo htmlspecialchars($training["name"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Type</span>
                <span class="value">
                    <?php echo htmlspecialchars($training["type"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Status</span>
                <span class="value">
                    <?php echo htmlspecialchars($training["status"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Organizer</span>
                <span class="value">
                    <?php
                    echo htmlspecialchars(
                        $training["organizer_name"] ?: "Not Available"
                    );
                    ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Department</span>
                <span class="value">
                    <?php echo htmlspecialchars($training["department"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Location</span>
                <span class="value">
                    <?php echo htmlspecialchars($training["location"]); ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Dates</span>
                <span class="value">
                    <?php
                    if ($training["start_date"] === $training["end_date"]) {
                        echo htmlspecialchars($training["start_date"]);
                    } else {
                        echo htmlspecialchars($training["start_date"])
                            . " - "
                            . htmlspecialchars($training["end_date"]);
                    }
                    ?>
                </span>
            </div>

            <div class="detail">
                <span class="label">Time</span>
                <span class="value">
                    <?php
                    echo htmlspecialchars(
                        date("g:i A", strtotime($training["start_time"]))
                    );
                    ?>
                    -
                    <?php
                    echo htmlspecialchars(
                        date("g:i A", strtotime($training["end_time"]))
                    );
                    ?>
                </span>
            </div>

            <div class="detail full">
                <span class="label">Description</span>
                <span class="value">
                    <?php echo nl2br(htmlspecialchars($training["description"])); ?>
                </span>
            </div>

        </div>

        <form method="POST">
            <?= csrfField() ?>

            <input
                type="hidden"
                name="training_id"
                value="<?php echo $training_id; ?>"
            >

            <div class="confirm-box">
                <label>
                    <input
                        type="checkbox"
                        name="confirm_delete"
                        value="yes"
                        required
                    >

                    I understand this training will be hidden from normal users
                    and moved to the deleted training area.
                </label>
            </div>

            <div class="actions">

                <a
                    class="button"
                    href="view_training.php?id=<?php echo $training_id; ?>"
                >
                    CANCEL
                </a>

                <button
                    class="button button-danger"
                    type="submit"
                >
                    DELETE TRAINING
                </button>

            </div>

        </form>

    </div>

</div>

</div>

</div>

</body>
</html>