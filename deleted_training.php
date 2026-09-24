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

/*
 * Load current user.
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
 * Deleted Training is Administrator-only, or an IT Administrator
 * viewing/restoring records in their own department.
 */
if (!isAnyAdministrator($current_user["role"])) {
    header("Location: training.php");
    exit;
}

$current_department = $current_user["department"] ?? "";

$message = "";
$error = "";

/*
 * Restore a deleted training record.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requireValidCsrfToken();

    $restore_id = (int)($_POST["restore_id"] ?? 0);

    if ($restore_id <= 0) {
        $error = "Invalid training record.";
    } else {

        $target_stmt = $conn->prepare("
            SELECT department
            FROM training
            WHERE id = ?
              AND deleted = 1
        ");
        $target_stmt->bind_param("i", $restore_id);
        $target_stmt->execute();
        $target_training = $target_stmt->get_result()->fetch_assoc();
        $target_stmt->close();

        if (
            !$target_training
            || !canAdministerDepartment(
                $current_user["role"],
                $current_department,
                $target_training["department"]
            )
        ) {
            $error = "You do not have permission to restore this training record.";
        } else {

            $restore_stmt = $conn->prepare("
                UPDATE training
                SET
                    deleted = 0,
                    deleted_at = NULL,
                    deleted_by = NULL
                WHERE id = ?
                  AND deleted = 1
            ");

            $restore_stmt->bind_param("i", $restore_id);

            if ($restore_stmt->execute()) {

                if ($restore_stmt->affected_rows > 0) {
                    $message = "Training restored successfully.";
                } else {
                    $error = "Training could not be restored.";
                }

            } else {
                $error = "Unable to restore training.";
            }

            $restore_stmt->close();
        }
    }
}

/*
 * Pagination.
 */
$per_page = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

$count_sql = "SELECT COUNT(*) AS total FROM training WHERE deleted = 1";

if ($current_user["role"] === "IT Administrator") {
    $count_sql .= " AND department = ?";
}

$count_stmt = $conn->prepare($count_sql);

if ($current_user["role"] === "IT Administrator") {
    $count_stmt->bind_param("s", $current_department);
}

$count_stmt->execute();
$count_result = $count_stmt->get_result();

$total_deleted = 0;

if ($count_result) {
    $count_row = $count_result->fetch_assoc();
    $total_deleted = (int)$count_row["total"];
}

$count_stmt->close();

$total_pages = max(1, (int)ceil($total_deleted / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/*
 * Load deleted training records.
 */
$deleted_training = [];

$list_sql = "
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
        t.deleted_at,
        CONCAT(org.first_name, ' ', org.last_name) AS organizer_name,
        CONCAT(del.first_name, ' ', del.last_name) AS deleted_by_name
    FROM training t
    LEFT JOIN users org ON org.id = t.organizer
    LEFT JOIN users del ON del.id = t.deleted_by
    WHERE t.deleted = 1
";

if ($current_user["role"] === "IT Administrator") {
    $list_sql .= " AND t.department = ? ";
}

$list_sql .= " ORDER BY t.deleted_at DESC, t.id DESC LIMIT ? OFFSET ? ";

$list_stmt = $conn->prepare($list_sql);

if ($current_user["role"] === "IT Administrator") {
    $list_stmt->bind_param("sii", $current_department, $per_page, $offset);
} else {
    $list_stmt->bind_param("ii", $per_page, $offset);
}

$list_stmt->execute();
$list_result = $list_stmt->get_result();

while ($row = $list_result->fetch_assoc()) {
    $deleted_training[] = $row;
}

$list_stmt->close();

$showing_start = $total_deleted === 0 ? 0 : $offset + 1;
$showing_end = min($offset + $per_page, $total_deleted);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Deleted Training</title>

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
            <h1>DELETED TRAINING</h1>
            <div class="sub">
                Restore training sessions that were soft deleted
            </div>
        </div>

        <a class="button" href="training.php">
            BACK TO TRAINING
        </a>

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

    <?php if (!$deleted_training): ?>

        <div class="empty">
            No deleted training sessions were found.
        </div>

    <?php else: ?>

        <div class="training-grid">

            <?php foreach ($deleted_training as $item): ?>

                <div class="training-card">

                    <div class="training-name">
                        <?php echo htmlspecialchars($item["name"]); ?>
                    </div>

                    <div class="training-detail">
                        <span class="detail-label">Type:</span>
                        <?php echo htmlspecialchars($item["type"]); ?>
                    </div>

                    <div class="training-detail">
                        <span class="detail-label">Organizer:</span>
                        <?php
                        echo htmlspecialchars(
                            $item["organizer_name"] ?: "Not Available"
                        );
                        ?>
                    </div>

                    <div class="training-detail">
                        <span class="detail-label">Department:</span>
                        <?php echo htmlspecialchars($item["department"]); ?>
                    </div>

                    <div class="training-detail">
                        <span class="detail-label">Location:</span>
                        <?php echo htmlspecialchars($item["location"]); ?>
                    </div>

                    <div class="training-detail">
                        <span class="detail-label">Date:</span>

                        <?php
                        if ($item["start_date"] === $item["end_date"]) {
                            echo htmlspecialchars($item["start_date"]);
                        } else {
                            echo htmlspecialchars($item["start_date"])
                                . " - "
                                . htmlspecialchars($item["end_date"]);
                        }
                        ?>
                    </div>

                    <div class="training-detail">
                        <span class="detail-label">Time:</span>

                        <?php
                        echo htmlspecialchars(
                            date("g:i A", strtotime($item["start_time"]))
                        );
                        ?>

                        -

                        <?php
                        echo htmlspecialchars(
                            date("g:i A", strtotime($item["end_time"]))
                        );
                        ?>
                    </div>

                    <div class="training-detail">
                        <span class="detail-label">Status:</span>
                        <?php echo htmlspecialchars($item["status"]); ?>
                    </div>

                    <div class="description">
                        <?php
                        $description = trim($item["description"]);

                        if (strlen($description) > 160) {
                            $description = substr($description, 0, 157) . "...";
                        }

                        echo htmlspecialchars($description);
                        ?>
                    </div>

                    <div class="deleted-info">

                        <div>
                            <strong>Deleted:</strong>

                            <?php
                            echo $item["deleted_at"]
                                ? htmlspecialchars(
                                    date(
                                        "M j, Y g:i A",
                                        strtotime($item["deleted_at"])
                                    )
                                )
                                : "Unknown";
                            ?>
                        </div>

                        <div>
                            <strong>Deleted By:</strong>

                            <?php
                            echo htmlspecialchars(
                                $item["deleted_by_name"] ?: "Unknown"
                            );
                            ?>
                        </div>

                    </div>

                    <div class="card-actions">

                        <form method="POST">
                            <?= csrfField() ?>

                            <input
                                type="hidden"
                                name="restore_id"
                                value="<?php echo (int)$item["id"]; ?>"
                            >

                            <button
                                class="button button-primary"
                                type="submit"
                            >
                                RESTORE TRAINING
                            </button>

                        </form>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <div class="footer">

        <div>
            Showing
            <?php echo $showing_start; ?>
            -
            <?php echo $showing_end; ?>
            of
            <?php echo $total_deleted; ?>
            deleted training sessions
        </div>

        <?php if ($total_pages > 1): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>

                    <a href="deleted_training.php?page=<?php echo $page - 1; ?>">
                        &lt;
                    </a>

                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>

                    <?php if ($i === $page): ?>

                        <span class="active">
                            <?php echo $i; ?>
                        </span>

                    <?php else: ?>

                        <a href="deleted_training.php?page=<?php echo $i; ?>">
                            <?php echo $i; ?>
                        </a>

                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>

                    <a href="deleted_training.php?page=<?php echo $page + 1; ?>">
                        &gt;
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</div>

</div>

</div>

</body>
</html>
