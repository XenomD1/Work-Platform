<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

/* ---------------------------------------------------------
   GET CURRENT USER
--------------------------------------------------------- */
$user_sql = "SELECT id, first_name, last_name, role, department FROM users WHERE id = ? AND active = 1";
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
$department = $current_user["department"];

/* ---------------------------------------------------------
   SEARCH / FILTERS
--------------------------------------------------------- */
$search = trim($_GET["search"] ?? "");
$type_filter = trim($_GET["type"] ?? "");
$status_filter = trim($_GET["status"] ?? "");

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

$statuses = [
    "Planned",
    "Confirmed",
    "Cancelled",
    "Completed"
];

/* ---------------------------------------------------------
   LOAD EVENTS
--------------------------------------------------------- */
$sql = "
    SELECT
        e.id,
        e.name,
        e.description,
        e.type,
        e.department,
        e.location,
        e.start_date,
        e.end_date,
        e.start_time,
        e.end_time,
        e.status,
        e.organizer,
        CONCAT(u.first_name, ' ', u.last_name) AS organizer_name
    FROM events e
    INNER JOIN users u ON e.organizer = u.id
    WHERE e.deleted = 0
";

$params = [];
$types = "";

/* ---------------------------------------------------------
   VISIBILITY
--------------------------------------------------------- */

/*
   Administrators see everything.

   Managers see:
   - events in their department
   - organization-wide events
   - events where they are an attendee
   - events they organized

   Employees see:
   - events in their department
   - organization-wide events
   - events where they are an attendee
*/
if ($role === "Administrator") {

    // No visibility restriction.

} elseif ($role === "Manager" || $role === "IT Administrator") {

    $sql .= "
        AND (
            e.department = ?
            OR e.type = 'Organization-Wide Event'
            OR e.organizer = ?
            OR EXISTS (
                SELECT 1
                FROM event_attendees ea
                WHERE ea.event_id = e.id
                  AND ea.user_id = ?
            )
        )
    ";

    $params[] = $department;
    $params[] = $user_id;
    $params[] = $user_id;
    $types .= "sii";

} else {

    $sql .= "
        AND (
            e.department = ?
            OR e.type = 'Organization-Wide Event'
            OR EXISTS (
                SELECT 1
                FROM event_attendees ea
                WHERE ea.event_id = e.id
                  AND ea.user_id = ?
            )
        )
    ";

    $params[] = $department;
    $params[] = $user_id;
    $types .= "si";
}

/* ---------------------------------------------------------
   SEARCH
--------------------------------------------------------- */
if ($search !== "") {
    $sql .= "
        AND (
            e.name LIKE ?
            OR e.description LIKE ?
        )
    ";

    $search_value = "%" . $search . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $types .= "ss";
}

/* ---------------------------------------------------------
   TYPE FILTER
--------------------------------------------------------- */
if ($type_filter !== "") {
    $sql .= " AND e.type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

/* ---------------------------------------------------------
   STATUS FILTER
--------------------------------------------------------- */
if ($status_filter !== "") {
    $sql .= " AND e.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

/* ---------------------------------------------------------
   SORTING
--------------------------------------------------------- */
$sql .= " ORDER BY e.start_date ASC, e.start_time ASC";

/* ---------------------------------------------------------
   RUN QUERY
--------------------------------------------------------- */
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$events = [];

while ($row = $result->fetch_assoc()) {
    $events[] = $row;
}

$stmt->close();

$total_events = count($events);

/* ---------------------------------------------------------
   PERMISSIONS
--------------------------------------------------------- */
$can_manage_events = (
    $role === "Administrator" ||
    $role === "Manager" ||
    $role === "IT Administrator"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meetings / Events</title>

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
 
 
    <!-- =================================
         MAIN
    ================================= -->
 
    <main class="main">
 
 
        <!-- HEADER -->
 
        <div class="header">
 
            <div class="header-title">
                Meetings / Events
            </div>
 
        </div>
 
 
 
 
        <!-- CONTENT -->
 
        <div class="content">
        <!-- NEW EVENT BUTTON -->
 
        <div class="toolbar">
 
            <?php if ($can_manage_events): ?>
 
                <a href="new_event.php" class="new-event-button">
                    + NEW EVENT
                </a>
 
            <?php endif; ?>
 
        </div>

 
 
            <div class="filter-box">
 
                <form method="get" class="filter-form">
 
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Event name or description"
                        >
                    </div>
 
                    <div class="filter-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="">All Types</option>
 
                            <?php foreach ($event_types as $type): ?>
                                <option
                                    value="<?php echo htmlspecialchars($type); ?>"
                                    <?php echo ($type_filter === $type) ? "selected" : ""; ?>
                                >
                                    <?php echo htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
 
                        </select>
                    </div>
 
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">All Statuses</option>
 
                            <?php foreach ($statuses as $status): ?>
                                <option
                                    value="<?php echo htmlspecialchars($status); ?>"
                                    <?php echo ($status_filter === $status) ? "selected" : ""; ?>
                                >
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
 
                        </select>
                    </div>
 
                    <button type="submit" class="filter-button">
                        SEARCH
                    </button>
 
                    <a href="events.php" class="clear-button">
                        CLEAR
                    </a>
 
                </form>
 
            </div>
 
 
            <?php if ($total_events === 0): ?>
 
                <div class="empty-state">
                    No meetings or events found.
                </div>
 
            <?php else: ?>
 
                <div class="event-grid">
 
                    <?php foreach ($events as $event): ?>
 
                        <div class="event-card">
 
                            <div class="event-name">
                                <?php echo htmlspecialchars($event["name"]); ?>
                            </div>
 
                            <div class="event-row">
                                <span class="event-label">Type:</span>
                                <?php echo htmlspecialchars($event["type"]); ?>
                            </div>
 
                            <div class="event-row">
                                <span class="event-label">Organizer:</span>
                                <?php echo htmlspecialchars($event["organizer_name"]); ?>
                            </div>
 
                            <div class="event-row">
                                <span class="event-label">Department:</span>
                                <?php echo htmlspecialchars($event["department"]); ?>
                            </div>
 
                            <div class="event-row">
                                <span class="event-label">Location:</span>
                                <?php echo htmlspecialchars($event["location"]); ?>
                            </div>
 
                            <div class="event-row">
                                <span class="event-label">Date:</span>
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
 
                            <div class="event-row">
                                <span class="event-label">Time:</span>
                                <?php
                                echo htmlspecialchars(
                                    date("g:i A", strtotime($event["start_time"]))
                                );
                                ?>
                                -
                                <?php
                                echo htmlspecialchars(
                                    date("g:i A", strtotime($event["end_time"]))
                                );
                                ?>
                            </div>
 
                            <div class="event-row">
                                <span class="event-label">Status:</span>
 
                                <span class="status">
                                    <?php echo htmlspecialchars($event["status"]); ?>
                                </span>
                            </div>
 
                            <div class="event-actions">
 
                                <a href="view_event.php?id=<?php echo (int)$event["id"]; ?>">
                                    View Event
                                </a>
 
                                <?php
                                $can_edit = false;
 
                                if ($role === "Administrator") {
                                    $can_edit = true;
                                } elseif (
                                    ($role === "Manager" || $role === "IT Administrator") &&
                                    ($event["department"] === $department || (int)$event["organizer"] === $user_id)
                                ) {
                                    $can_edit = true;
                                }
                                ?>
 
                                <?php if ($can_edit): ?>
                                    <a href="edit_event.php?id=<?php echo (int)$event["id"]; ?>">
                                        Edit Event
                                    </a>
                                <?php endif; ?>
 
                            </div>
 
                        </div>
 
                    <?php endforeach; ?>
 
                </div>
 
            <?php endif; ?>
 
 
        </div>
 
 
        <!-- FOOTER -->
 
        <div class="footer">
 
            Showing <?php echo $total_events; ?>
            <?php echo ($total_events === 1) ? "event" : "events"; ?>
 
        </div>
 
 
    </main>
 
</div>
 
</body>
</html>