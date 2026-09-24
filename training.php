<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "saved_view_helpers.php";
require_once "csrf_helpers.php";
require_once "announcement_helpers.php";

$user_id = (int)$_SESSION["user_id"];

/* ---------------------------------------------------------
   CURRENT USER
--------------------------------------------------------- */
$user_stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, department
    FROM users
    WHERE id = ?
      AND active = 1
");

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
$user_department = $current_user["department"];

/* ---------------------------------------------------------
   FILTERS
--------------------------------------------------------- */
$search = trim($_GET["search"] ?? "");
$type_filter = trim($_GET["type"] ?? "");
$status_filter = trim($_GET["status"] ?? "");
$upcoming_only = isset($_GET["upcoming"]) && $_GET["upcoming"] === "1";

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
   UPCOMING ONLY
--------------------------------------------------------- */
if ($upcoming_only) {
    $sql .= " AND t.start_date >= CURDATE()";
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
function training_page_url($page, $search, $type_filter, $status_filter, $upcoming_only = false) {
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

    if ($upcoming_only) {
        $query["upcoming"] = "1";
    }

    return "training.php?" . http_build_query($query);
}

$saved_views = getSavedViews($conn, $user_id, "Training");
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Training</title>

    <link rel="stylesheet" href="app.css?v=22">

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

                    <div class="filter-group" style="flex-direction:row;align-items:center;gap:6px;">
                        <input type="checkbox" id="upcoming" name="upcoming" value="1" style="width:auto;" <?php echo $upcoming_only ? "checked" : ""; ?>>
                        <label for="upcoming" style="margin:0;">Upcoming only</label>
                    </div>

                    <button type="submit" class="filter-button">
                        SEARCH
                    </button>

                </form>

            </div>

            <div class="card" style="margin-bottom:18px;">

                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">

                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <strong>Saved Views:</strong>
                        <?php if (!$saved_views): ?>
                            <span style="color:var(--slate);">None yet</span>
                        <?php else: ?>
                            <?php foreach ($saved_views as $view): ?>
                                <a class="button" href="training.php?<?php echo htmlspecialchars($view["query_string"]); ?>"><?php echo htmlspecialchars($view["name"]); ?></a>
                                <form method="POST" action="saved_view_action.php" style="display:inline;" onsubmit="return confirm('Delete this saved view?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="module" value="Training">
                                    <input type="hidden" name="view_id" value="<?php echo (int)$view["id"]; ?>">
                                    <button class="button" type="submit" title="Delete" style="padding:4px 8px;">&times;</button>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="saved_view_action.php" id="save_view_form" style="display:flex;gap:8px;align-items:center;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="module" value="Training">
                        <input type="hidden" name="query_string" id="save_view_query_string" value="">
                        <input type="text" name="name" placeholder="Name this view..." maxlength="100" style="width:180px;">
                        <button class="button primary" type="submit">SAVE CURRENT VIEW</button>
                    </form>

                </div>

            </div>

            <script>
            (function () {
                var form = document.getElementById("save_view_form");
                if (!form) return;
                form.addEventListener("submit", function () {
                    document.getElementById("save_view_query_string").value =
                        window.location.search.replace(/^\?/, "");
                });
            })();
            </script>


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
                            href="<?php echo htmlspecialchars(training_page_url($p, $search, $type_filter, $status_filter, $upcoming_only)); ?>"
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
