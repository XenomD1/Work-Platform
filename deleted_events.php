<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";

$user_id = (int)$_SESSION["user_id"];

/* ---------------------------------------------------------
   CURRENT USER
--------------------------------------------------------- */
$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ?
");

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$user_result = $user_stmt->get_result();
$current_user = $user_result->fetch_assoc();

$user_stmt->close();

if (!$current_user) {
    die("User not found.");
}

if (!isAnyAdministrator($current_user["role"])) {
    die("You do not have permission to access this page.");
}

$current_department = $current_user["department"] ?? "";

/* ---------------------------------------------------------
   RESTORE EVENT
--------------------------------------------------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["restore_id"])) {

    $restore_id = (int)$_POST["restore_id"];

    if ($restore_id > 0) {

        $target_stmt = $conn->prepare("
            SELECT department
            FROM events
            WHERE id = ?
              AND deleted = 1
        ");
        $target_stmt->bind_param("i", $restore_id);
        $target_stmt->execute();
        $target_event = $target_stmt->get_result()->fetch_assoc();
        $target_stmt->close();

        if (
            $target_event
            && canAdministerDepartment(
                $current_user["role"],
                $current_department,
                $target_event["department"]
            )
        ) {
            $restore_stmt = $conn->prepare("
                UPDATE events
                SET deleted = 0,
                    deleted_at = NULL,
                    deleted_by = NULL
                WHERE id = ?
                  AND deleted = 1
            ");

            $restore_stmt->bind_param("i", $restore_id);
            $restore_stmt->execute();
            $restore_stmt->close();
        }
    }

    header("Location: deleted_events.php");
    exit;
}

/* ---------------------------------------------------------
   LOAD DELETED EVENTS
--------------------------------------------------------- */
$events = [];

$sql = "
    SELECT
        e.id,
        e.name,
        e.type,
        e.department,
        e.start_date,
        e.end_date,
        e.start_time,
        e.end_time,
        e.status,
        CONCAT(u.first_name, ' ', u.last_name) AS organizer_name
    FROM events e
    INNER JOIN users u ON e.organizer = u.id
    WHERE e.deleted = 1
";

if ($current_user["role"] === "IT Administrator") {
    $sql .= " AND e.department = ? ";
}

$sql .= " ORDER BY e.deleted_at DESC, e.id DESC ";

$stmt = $conn->prepare($sql);

if ($current_user["role"] === "IT Administrator") {
    $stmt->bind_param("s", $current_department);
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $events[] = $row;
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Deleted Events</title>

    <link rel="stylesheet" href="app.css?v=9">

</head>

<body>
 
<div class="page">
 
 
    <!-- =================================
         SIDEBAR
    ================================= -->
 
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
 
 
    <!-- =================================
         MAIN
    ================================= -->
 
    <main class="main">
 
 
        <!-- HEADER -->
 
        <div class="header">
 
            <div class="header-title">
                Deleted Events
            </div>
 
            <div class="header-subtitle">
                Administrator-only area for viewing and restoring deleted events.
            </div>
 
        </div>
 
 
        <!-- CONTENT -->
 
        <div class="content">
 
            <?php if (empty($events)): ?>
 
                <div class="empty">
                    No deleted events found.
                </div>
 
            <?php else: ?>
 
                <div class="event-grid">
 
                    <?php foreach ($events as $event): ?>
 
                        <div class="event-card">
 
                            <div class="event-name">
                                <?php echo htmlspecialchars($event["name"]); ?>
                            </div>
 
                            <div class="detail">
                                <span class="detail-label">Type:</span>
                                <?php echo htmlspecialchars($event["type"]); ?>
                            </div>
 
                            <div class="detail">
                                <span class="detail-label">Organizer:</span>
                                <?php echo htmlspecialchars($event["organizer_name"]); ?>
                            </div>
 
                            <div class="detail">
                                <span class="detail-label">Department:</span>
                                <?php echo htmlspecialchars($event["department"]); ?>
                            </div>
 
                            <div class="detail">
                                <span class="detail-label">Date:</span>
                                <?php
                                if ($event["start_date"] === $event["end_date"]) {
                                    echo htmlspecialchars($event["start_date"]);
                                } else {
                                    echo htmlspecialchars($event["start_date"])
                                        . " - "
                                        . htmlspecialchars($event["end_date"]);
                                }
                                ?>
                            </div>
 
                            <div class="detail">
                                <span class="detail-label">Time:</span>
                                <?php echo htmlspecialchars(date("g:i A", strtotime($event["start_time"]))); ?>
                                -
                                <?php echo htmlspecialchars(date("g:i A", strtotime($event["end_time"]))); ?>
                            </div>
 
                            <div class="detail">
                                <span class="detail-label">Status:</span>
                                <?php echo htmlspecialchars($event["status"]); ?>
                            </div>
 
                            <div class="deleted-note">
                                This event is deleted and hidden from normal users.
                            </div>
 
                            <div class="actions">
 
                                <a href="view_event.php?id=<?php echo (int)$event["id"]; ?>">
                                    VIEW
                                </a>
 
                                <form method="post" action="deleted_events.php">
                                    <input
                                        type="hidden"
                                        name="restore_id"
                                        value="<?php echo (int)$event["id"]; ?>"
                                    >
 
                                    <button type="submit">
                                        RESTORE EVENT
                                    </button>
                                </form>
 
                            </div>
 
                        </div>
 
                    <?php endforeach; ?>
 
                </div>
 
            <?php endif; ?>
 
        </div>
 
 
    </main>
 
</div>
 
</body>
</html>