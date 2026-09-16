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

$role = $current_user["role"];
$user_department = $current_user["department"];

/* ---------------------------------------------------------
   FILTERS
--------------------------------------------------------- */
$search = trim($_GET["search"] ?? "");
$type_filter = trim($_GET["type"] ?? "");
$status_filter = trim($_GET["status"] ?? "");

/* ---------------------------------------------------------
   TRAINING TYPES
--------------------------------------------------------- */
$training_types = [
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

/* ---------------------------------------------------------
   BASE QUERY
--------------------------------------------------------- */
$sql = "
    SELECT
        t.id,
        t.name,
        t.description,
        t.type,
        t.organizer,
        t.department,
        t.location,
        t.start_date,
        t.end_date,
        t.start_time,
        t.end_time,
        t.status,
        CONCAT(u.first_name, ' ', u.last_name) AS organizer_name
    FROM training t
    INNER JOIN users u ON t.organizer = u.id
    WHERE t.deleted = 0
";

$params = [];
$types = "";

/* ---------------------------------------------------------
   VISIBILITY
--------------------------------------------------------- */
if ($role !== "Administrator") {
    $sql .= " AND t.department = ?";
    $types .= "s";
    $params[] = $user_department;
}

/* ---------------------------------------------------------
   SEARCH
--------------------------------------------------------- */
if ($search !== "") {
    $sql .= " AND (
        t.name LIKE ?
        OR t.description LIKE ?
        OR t.type LIKE ?
        OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
    )";

    $search_value = "%" . $search . "%";

    $types .= "ssss";
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
}

/* ---------------------------------------------------------
   TYPE FILTER
--------------------------------------------------------- */
if ($type_filter !== "" && in_array($type_filter, $training_types, true)) {
    $sql .= " AND t.type = ?";
    $types .= "s";
    $params[] = $type_filter;
}

/* ---------------------------------------------------------
   STATUS FILTER
--------------------------------------------------------- */
if ($status_filter !== "" && in_array($status_filter, $statuses, true)) {
    $sql .= " AND t.status = ?";
    $types .= "s";
    $params[] = $status_filter;
}

/* ---------------------------------------------------------
   SORTING
--------------------------------------------------------- */
$sql .= " ORDER BY t.start_date ASC, t.start_time ASC, t.id ASC";

/* ---------------------------------------------------------
   EXECUTE
--------------------------------------------------------- */
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

$training = [];

while ($row = $result->fetch_assoc()) {
    $training[] = $row;
}

$stmt->close();

/* ---------------------------------------------------------
   PAGINATION
--------------------------------------------------------- */
$per_page = 10;
$total_training = count($training);
$total_pages = max(1, (int)ceil($total_training / $per_page));

$page = (int)($_GET["page"] ?? 1);

if ($page < 1) {
    $page = 1;
}

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;
$visible_training = array_slice($training, $offset, $per_page);

/* ---------------------------------------------------------
   URL BUILDER
--------------------------------------------------------- */
function training_page_url($page, $search, $type_filter, $status_filter) {
    $query = [
        "page" => $page
    ];

    if ($search !== "") {
        $query["search"] = $search;
    }

    if ($type_filter !== "") {
        $query["type"] = $type_filter;
    }

    if ($status_filter !== "") {
        $query["status"] = $status_filter;
    }

    return "training.php?" . http_build_query($query);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Training</title>

    <link rel="stylesheet" href="app.css?v=15">

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
 
 
    <!-- =================================
         MAIN
    ================================= -->
 
    <main class="main">
 
 
        <!-- HEADER -->
 
        <div class="header">
 
            <div class="header-title">
                Training
            </div>
 
        </div>
 
 
 
 
        <!-- CONTENT -->
 
        <div class="content">
        <!-- NEW TRAINING BUTTON -->
 
        <div class="toolbar">
 
            <?php if ($role === "Administrator" || $role === "Manager" || $role === "IT Administrator"): ?>
 
                <a href="new_training.php" class="new-training-button">
                    + NEW TRAINING
                </a>
 
            <?php endif; ?>
 
        </div>

 
 
            <div class="filter-box">
 
                <form method="get" action="training.php" class="filter-row">
 
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search training..."
                        >
                    </div>
 
                    <div class="filter-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="">All Types</option>
 
                            <?php foreach ($training_types as $type): ?>
                                <option
                                    value="<?php echo htmlspecialchars($type); ?>"
                                    <?php echo $type_filter === $type ? "selected" : ""; ?>
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
                                    <?php echo $status_filter === $status ? "selected" : ""; ?>
                                >
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
 
                        </select>
                    </div>
 
                    <button type="submit" class="filter-button">
                        SEARCH
                    </button>
 
                </form>
 
            </div>
 
 
            <?php if (empty($visible_training)): ?>
 
                <div class="empty">
                    No training found.
                </div>
 
            <?php else: ?>
 
                <div class="training-grid">
 
                    <?php foreach ($visible_training as $item): ?>
 
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
                                <?php echo htmlspecialchars($item["organizer_name"]); ?>
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
                                <?php echo htmlspecialchars(date("g:i A", strtotime($item["start_time"]))); ?>
                                -
                                <?php echo htmlspecialchars(date("g:i A", strtotime($item["end_time"]))); ?>
                            </div>
 
                            <div class="training-detail">
                                <span class="detail-label">Status:</span>
 
                                <span class="status">
                                    <?php echo htmlspecialchars($item["status"]); ?>
                                </span>
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
 
                            <div class="card-actions">
 
                                <a href="view_training.php?id=<?php echo (int)$item["id"]; ?>">
                                    VIEW TRAINING
                                </a>
 
                                <?php
                                $can_edit = false;
 
                                if ($role === "Administrator") {
                                    $can_edit = true;
                                } elseif (
                                    ($role === "Manager" || $role === "IT Administrator")
                                    && $item["department"] === $user_department
                                ) {
                                    $can_edit = true;
                                }
                                ?>
 
                                <?php if ($can_edit): ?>
                                    <a href="edit_training.php?id=<?php echo (int)$item["id"]; ?>">
                                        EDIT
                                    </a>
                                <?php endif; ?>
 
                            </div>
 
                        </div>
 
                    <?php endforeach; ?>
 
                </div>
 
            <?php endif; ?>
 
 
 
 
        <!-- FOOTER -->
 
        <div class="footer">
 
            <div>
                Showing
                <?php echo $total_training === 0 ? 0 : $offset + 1; ?>
                -
                <?php echo min($offset + $per_page, $total_training); ?>
                of
                <?php echo $total_training; ?>
                training records
            </div>
 
            <?php if ($total_pages > 1): ?>
 
                <div class="pagination">
 
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
 
                        <a
                            href="<?php echo htmlspecialchars(training_page_url($p, $search, $type_filter, $status_filter)); ?>"
                            class="<?php echo $p === $page ? "active" : ""; ?>"
                        >
                            <?php echo $p; ?>
                        </a>
 
                    <?php endfor; ?>
 
                </div>
 
            <?php endif; ?>
 
        </div>
        </div>
 
 
    </main>
 
</div>
 
</body>
</html>