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

$role = $current_user["role"];
$user_department = $current_user["department"];
$self_service_roles = ["Employee"];

/*
 * Filters.
 */
$status_filter = trim($_GET["status"] ?? "");
$type_filter = trim($_GET["type"] ?? "");
$search = trim($_GET["search"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$per_page = 10;

$saved_views = getSavedViews($conn, $user_id, "TimeOff");

/*
 * Time Off Types.
 */
$time_off_types = [
    "Vacation",
    "Sick Leave",
    "Personal Leave",
    "Bereavement",
    "Jury Duty",
    "Medical Leave",
    "Other"
];

$statuses = [
    "Draft",
    "Submitted",
    "Approved",
    "Rejected",
    "Cancelled"
];

/*
 * Build visibility rules.
 *
 * Employees:
 *   - See only their own requests.
 *
 * Managers and IT Administrators:
 *   - See requests for employees in their department.
 *
 * Administrators:
 *   - See all requests.
 */
$where = [];
$params = [];
$types = "";

if (in_array($role, $self_service_roles, true)) {
    $where[] = "t.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
} elseif ($role === "Manager" || $role === "IT Administrator") {
    $where[] = "u.department = ?";
    $params[] = $user_department;
    $types .= "s";
}

/*
 * Optional filters.
 */
if ($status_filter !== "" && in_array($status_filter, $statuses, true)) {
    $where[] = "t.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($type_filter !== "" && in_array($type_filter, $time_off_types, true)) {
    $where[] = "t.time_off_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if ($search !== "") {
    $where[] = "(
        CONCAT(u.first_name, ' ', u.last_name) LIKE ?
        OR t.time_off_type LIKE ?
        OR t.reason_notes LIKE ?
    )";

    $like = "%" . $search . "%";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

/*
 * Count visible Time Off records.
 */
$count_sql = "
    SELECT COUNT(*) AS total
    FROM time_off t
    INNER JOIN users u ON u.id = t.user_id
    $where_sql
";

$count_stmt = $conn->prepare($count_sql);

if ($types !== "") {
    $count_stmt->bind_param($types, ...$params);
}

$count_stmt->execute();
$count_result = $count_stmt->get_result()->fetch_assoc();
$total_records = (int)($count_result["total"] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int)ceil($total_records / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/*
 * Load visible Time Off records.
 */
$list_sql = "
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
        u.first_name,
        u.last_name,
        u.department,
        CONCAT(a.first_name, ' ', a.last_name) AS approver_name
    FROM time_off t
    INNER JOIN users u ON u.id = t.user_id
    LEFT JOIN users a ON a.id = t.approver_id
    $where_sql
    ORDER BY
        CASE
            WHEN t.status = 'Submitted' THEN 1
            WHEN t.status = 'Approved' THEN 2
            WHEN t.status = 'Draft' THEN 3
            WHEN t.status = 'Rejected' THEN 4
            WHEN t.status = 'Cancelled' THEN 5
            ELSE 6
        END,
        t.start_date ASC,
        t.id DESC
    LIMIT ? OFFSET ?
";

$list_stmt = $conn->prepare($list_sql);

$list_params = $params;
$list_types = $types . "ii";
$list_params[] = $per_page;
$list_params[] = $offset;

$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$list_result = $list_stmt->get_result();

$time_off_records = [];

while ($row = $list_result->fetch_assoc()) {
    $time_off_records[] = $row;
}

$list_stmt->close();

$showing_start = $total_records === 0 ? 0 : $offset + 1;
$showing_end = min($offset + $per_page, $total_records);

/*
 * Keep filters when changing pages.
 */
function buildPageUrl($page_number, $status_filter, $type_filter, $search) {
    $query = ["page" => $page_number];

    if ($status_filter !== "") {
        $query["status"] = $status_filter;
    }

    if ($type_filter !== "") {
        $query["type"] = $type_filter;
    }

    if ($search !== "") {
        $query["search"] = $search;
    }

    return "time_off.php?" . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Time Off</title>

<link rel="stylesheet" href="app.css?v=22">

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
        <div class="sidebar-item active">
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

<div class="main">

    <div class="header">

        <div>
            <h1>TIME OFF</h1>

            <div class="sub">
                <?php if (in_array($role, $self_service_roles, true)): ?>
                    View and manage your Time Off requests
                <?php elseif ($role === "Manager" || $role === "IT Administrator"): ?>
                    View Time Off requests for your department
                <?php else: ?>
                    View and manage all Time Off requests
                <?php endif; ?>
            </div>
        </div>

        <a class="button primary" href="new_time_off.php">
            + NEW REQUEST
        </a>

    </div>

    <div class="content">

    <form class="filters" method="GET">

        <div class="filter-grid">

            <div class="field">
                <label for="search">Search</label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Employee, type, or notes"
                >
            </div>

            <div class="field">
                <label for="status">Status</label>

                <select id="status" name="status">
                    <option value="">All Statuses</option>

                    <?php foreach ($statuses as $item): ?>
                        <option
                            value="<?php echo htmlspecialchars($item); ?>"
                            <?php echo $status_filter === $item ? "selected" : ""; ?>
                        >
                            <?php echo htmlspecialchars($item); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="type">Type</label>

                <select id="type" name="type">
                    <option value="">All Types</option>

                    <?php foreach ($time_off_types as $item): ?>
                        <option
                            value="<?php echo htmlspecialchars($item); ?>"
                            <?php echo $type_filter === $item ? "selected" : ""; ?>
                        >
                            <?php echo htmlspecialchars($item); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button class="button primary" type="submit">
                    APPLY
                </button>

                <a class="button" href="time_off.php">
                    CLEAR
                </a>
            </div>

        </div>

    </form>

    <div class="card" style="margin-bottom:18px;">

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <strong>Saved Views:</strong>
                <?php if (!$saved_views): ?>
                    <span style="color:var(--slate);">None yet</span>
                <?php else: ?>
                    <?php foreach ($saved_views as $view): ?>
                        <a class="button" href="time_off.php?<?php echo htmlspecialchars($view["query_string"]); ?>"><?php echo htmlspecialchars($view["name"]); ?></a>
                        <form method="POST" action="saved_view_action.php" style="display:inline;" onsubmit="return confirm('Delete this saved view?');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="module" value="TimeOff">
                            <input type="hidden" name="view_id" value="<?php echo (int)$view["id"]; ?>">
                            <button class="button" type="submit" title="Delete" style="padding:4px 8px;">&times;</button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form method="POST" action="saved_view_action.php" id="save_view_form" style="display:flex;gap:8px;align-items:center;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="module" value="TimeOff">
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

    <?php if (!$time_off_records): ?>

        <div class="empty">
            No Time Off requests were found.
        </div>

    <?php else: ?>

        <div class="timeoff-grid">

            <?php foreach ($time_off_records as $item): ?>

                <div class="timeoff-card">

                    <div class="card-top">

                        <div>

                            <div class="timeoff-type">
                                <?php echo htmlspecialchars($item["time_off_type"]); ?>
                            </div>

                            <?php if (!in_array($role, $self_service_roles, true)): ?>

                                <div class="employee-name">
                                    <?php
                                    echo htmlspecialchars(
                                        $item["first_name"]
                                        . " "
                                        . $item["last_name"]
                                    );
                                    ?>
                                    —
                                    <?php echo htmlspecialchars($item["department"]); ?>
                                </div>

                            <?php endif; ?>

                        </div>

                        <span class="status">
                            <?php echo htmlspecialchars($item["status"]); ?>
                        </span>

                    </div>

                    <div class="detail">
                        <span class="detail-label">Start Date:</span>
                        <?php echo htmlspecialchars($item["start_date"]); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">End Date:</span>
                        <?php echo htmlspecialchars($item["end_date"]); ?>
                    </div>

                    <div class="detail">
                        <span class="detail-label">Total Days:</span>
                        <?php echo htmlspecialchars($item["total_days"]); ?>
                    </div>

                    <?php if (!empty($item["submitted_at"])): ?>

                        <div class="detail">
                            <span class="detail-label">Submitted:</span>

                            <?php
                            echo htmlspecialchars(
                                date(
                                    "M j, Y g:i A",
                                    strtotime($item["submitted_at"])
                                )
                            );
                            ?>
                        </div>

                    <?php endif; ?>

                    <?php if (!empty($item["reason_notes"])): ?>

                        <div class="reason">
                            <?php
                            $reason = trim($item["reason_notes"]);

                            if (strlen($reason) > 160) {
                                $reason = substr($reason, 0, 157) . "...";
                            }

                            echo htmlspecialchars($reason);
                            ?>
                        </div>

                    <?php endif; ?>

                    <?php if (
                        !empty($item["approver_name"])
                        || !empty($item["approval_date"])
                        || !empty($item["rejection_reason"])
                    ): ?>

                        <div class="review-box">

                            <?php if (!empty($item["approver_name"])): ?>

                                <div>
                                    <strong>Reviewed By:</strong>
                                    <?php echo htmlspecialchars($item["approver_name"]); ?>
                                </div>

                            <?php endif; ?>

                            <?php if (!empty($item["approval_date"])): ?>

                                <div>
                                    <strong>Reviewed Date:</strong>

                                    <?php
                                    echo htmlspecialchars(
                                        date(
                                            "M j, Y",
                                            strtotime($item["approval_date"])
                                        )
                                    );
                                    ?>
                                </div>

                            <?php endif; ?>

                            <?php if (!empty($item["rejection_reason"])): ?>

                                <div>
                                    <strong>Rejection Reason:</strong>
                                    <?php echo htmlspecialchars($item["rejection_reason"]); ?>
                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                    <div class="card-actions">

                        <a
                            class="button"
                            href="view_time_off.php?id=<?php echo (int)$item["id"]; ?>"
                        >
                            VIEW REQUEST
                        </a>

                        <?php
                        $can_edit = false;

                        if ($role === "Administrator") {
                            $can_edit = true;
                        } elseif (
                            $role === "IT Administrator"
                            && $item["department"] === $user_department
                        ) {
                            $can_edit = true;
                        } elseif (
                            in_array($role, $self_service_roles, true)
                            && (int)$item["user_id"] === $user_id
                            && in_array($item["status"], ["Draft", "Submitted"], true)
                        ) {
                            $can_edit = true;
                        }
                        ?>

                        <?php if ($can_edit): ?>

                            <a
                                class="button"
                                href="edit_time_off.php?id=<?php echo (int)$item["id"]; ?>"
                            >
                                EDIT
                            </a>

                        <?php endif; ?>

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
            <?php echo $total_records; ?>
            requests
        </div>

        <?php if ($total_pages > 1): ?>

            <div class="pagination">

                <?php if ($page > 1): ?>

                    <a href="<?php
                        echo htmlspecialchars(
                            buildPageUrl(
                                $page - 1,
                                $status_filter,
                                $type_filter,
                                $search
                            )
                        );
                    ?>">
                        &lt;
                    </a>

                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>

                    <?php if ($i === $page): ?>

                        <span class="active">
                            <?php echo $i; ?>
                        </span>

                    <?php else: ?>

                        <a href="<?php
                            echo htmlspecialchars(
                                buildPageUrl(
                                    $i,
                                    $status_filter,
                                    $type_filter,
                                    $search
                                )
                            );
                        ?>">
                            <?php echo $i; ?>
                        </a>

                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>

                    <a href="<?php
                        echo htmlspecialchars(
                            buildPageUrl(
                                $page + 1,
                                $status_filter,
                                $type_filter,
                                $search
                            )
                        );
                    ?>">
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
