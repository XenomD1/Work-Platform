<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

$user_stmt = $conn->prepare("
    SELECT id, role
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

$action_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requireValidCsrfToken();

    $notification_id = (int)($_POST["notification_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");

    if ($action === "mark_all_read") {
        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1,
                read_at = COALESCE(read_at, NOW())
            WHERE user_id = ?
              AND is_read = 0
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        $action_message = $changed > 0
            ? "All notifications marked as read."
            : "You have no unread notifications.";
    } elseif (
        $notification_id > 0
        && in_array($action, ["mark_read", "mark_unread"], true)
    ) {
        if ($action === "mark_read") {
            $stmt = $conn->prepare("
                UPDATE notifications
                SET is_read = 1,
                    read_at = COALESCE(read_at, NOW())
                WHERE id = ?
                  AND user_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                UPDATE notifications
                SET is_read = 0,
                    read_at = NULL
                WHERE id = ?
                  AND user_id = ?
            ");
        }

        $stmt->bind_param("ii", $notification_id, $user_id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed > 0) {
            $action_message = $action === "mark_read"
                ? "Notification marked as read."
                : "Notification marked as unread.";
        }
    }
}

$status_filter = $_GET["status"] ?? "all";
$type_filter = trim($_GET["type"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$per_page = 15;

if (!in_array($status_filter, ["all", "unread", "read"], true)) {
    $status_filter = "all";
}

$types = [];
$type_stmt = $conn->prepare("
    SELECT DISTINCT type
    FROM notifications
    WHERE user_id = ?
    ORDER BY type ASC
");
$type_stmt->bind_param("i", $user_id);
$type_stmt->execute();
$type_result = $type_stmt->get_result();

while ($row = $type_result->fetch_assoc()) {
    $types[] = $row["type"];
}

$type_stmt->close();

$where = ["user_id = ?"];
$params = [$user_id];
$types_bind = "i";

if ($status_filter === "unread") {
    $where[] = "is_read = 0";
} elseif ($status_filter === "read") {
    $where[] = "is_read = 1";
}

if ($type_filter !== "") {
    $where[] = "type = ?";
    $params[] = $type_filter;
    $types_bind .= "s";
}

$where_sql = implode(" AND ", $where);

$count_sql = "
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE $where_sql
";

$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types_bind, ...$params);
$count_stmt->execute();
$total = (int)$count_stmt->get_result()->fetch_assoc()["total"];
$count_stmt->close();

$total_pages = max(1, (int)ceil($total / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$sql = "
    SELECT
        id,
        type,
        title,
        message,
        related_module,
        related_id,
        related_url,
        is_read,
        read_at,
        created_at
    FROM notifications
    WHERE $where_sql
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
";

$list_params = $params;
$list_params[] = $per_page;
$list_params[] = $offset;
$list_types = $types_bind . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

$stmt->close();

$unread_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = ?
      AND is_read = 0
");
$unread_stmt->bind_param("i", $user_id);
$unread_stmt->execute();
$unread_count = (int)$unread_stmt->get_result()->fetch_assoc()["total"];
$unread_stmt->close();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function buildPageUrl($page_number, $status_filter, $type_filter) {
    $params = [
        "status" => $status_filter,
        "page" => $page_number
    ];

    if ($type_filter !== "") {
        $params["type"] = $type_filter;
    }

    return "notifications.php?" . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications</title>
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
        <div class="sidebar-item">
            <a href="training.php">Training</a>
        </div>
        <div class="sidebar-item">
            <a href="time_off.php">Time Off</a>
        </div>
        <div class="sidebar-item">
            <a href="timesheets.php">Timesheets</a>
        </div>
        <div class="sidebar-item active">
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
            <div>
                <h1>NOTIFICATIONS</h1>
                <div class="sub">Updates and activity related to your work</div>
            </div>
            <div>
                <div class="unread-count">
                    Unread: <?php echo $unread_count; ?>
                </div>

                <?php if ($unread_count > 0): ?>
                    <form method="POST" style="margin-top:10px;">
                        <?= csrfField() ?>
                        <button
                            type="submit"
                            name="action"
                            value="mark_all_read"
                        >
                            MARK ALL READ
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="content">

        <?php if ($action_message !== ""): ?>
            <div class="empty">
                <?php echo e($action_message); ?>
            </div>
        <?php endif; ?>

        <form class="filters" method="get" action="notifications.php">
            <div class="filter-grid">
                <div>
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="all" <?php echo $status_filter === "all" ? "selected" : ""; ?>>All</option>
                        <option value="unread" <?php echo $status_filter === "unread" ? "selected" : ""; ?>>Unread</option>
                        <option value="read" <?php echo $status_filter === "read" ? "selected" : ""; ?>>Read</option>
                    </select>
                </div>

                <div>
                    <label for="type">Type</label>
                    <select id="type" name="type">
                        <option value="">All Types</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo e($type); ?>" <?php echo $type_filter === $type ? "selected" : ""; ?>>
                                <?php echo e($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit">APPLY FILTERS</button>
                    <a class="button" href="notifications.php">CLEAR</a>
                </div>
            </div>
        </form>

        <?php if (empty($notifications)): ?>
            <div class="empty">No notifications found.</div>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification <?php echo !$notification["is_read"] ? "unread" : ""; ?>">
                        <div class="notification-top">
                            <div>
                                <div class="notification-type"><?php echo e($notification["type"]); ?></div>
                                <div class="notification-title"><?php echo e($notification["title"]); ?></div>
                            </div>
                            <div>
                                <?php echo !$notification["is_read"] ? "<strong>UNREAD</strong>" : "Read"; ?>
                            </div>
                        </div>

                        <div class="notification-message"><?php echo e($notification["message"]); ?></div>

                        <div class="notification-meta">
                            <span>Created: <?php echo e(date("M j, Y g:i A", strtotime($notification["created_at"]))); ?></span>

                            <?php if (!empty($notification["related_module"])): ?>
                                <span>Module: <?php echo e($notification["related_module"]); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="notification-link">
                            <?php if (!empty($notification["related_url"])): ?>
                                <a
                                    class="button"
                                    href="open_notification.php?id=<?php echo (int)$notification["id"]; ?>"
                                >OPEN RELATED ITEM</a>
                            <?php endif; ?>

                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?php echo (int)$notification["id"]; ?>"
                                >
                                <button
                                    class="button"
                                    type="submit"
                                    name="action"
                                    value="<?php echo (int)$notification["is_read"] === 1 ? "mark_unread" : "mark_read"; ?>"
                                >
                                    <?php echo (int)$notification["is_read"] === 1 ? "MARK UNREAD" : "MARK READ"; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a
                            class="<?php echo $i === $page ? "active" : ""; ?>"
                            href="<?php echo e(buildPageUrl($i, $status_filter, $type_filter)); ?>"
                        >
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        </div>
    </main>
</div>
</body>
</html>
