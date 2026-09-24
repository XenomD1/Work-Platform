<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require "db.php";
require_once "role_helpers.php";
require_once "announcement_helpers.php";

function e($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

/* =========================================================
   CALENDAR SETTINGS
========================================================= */

$allowed_categories = [
    "Projects",
    "Tasks",
    "Milestones",
    "Meetings",
    "Deadlines",
    "Training",
    "Time Off",
    "Holidays"
];

$selected_categories = $_GET["category"] ?? $allowed_categories;

if (!is_array($selected_categories)) {
    $selected_categories = $allowed_categories;
}

$selected_categories = array_values(array_intersect(
    $allowed_categories,
    $selected_categories
));

$default_calendar_view = "month";

if (!isset($_GET["view"])) {
    $preference_user_id = (int)$_SESSION["user_id"];

    $preference_stmt = $conn->prepare("
        SELECT default_calendar_view
        FROM user_preferences
        WHERE user_id = ?
        LIMIT 1
    ");
    $preference_stmt->bind_param("i", $preference_user_id);
    $preference_stmt->execute();
    $preference = $preference_stmt->get_result()->fetch_assoc();
    $preference_stmt->close();

    if (
        $preference
        && in_array(
            $preference["default_calendar_view"],
            ["month", "week", "day"],
            true
        )
    ) {
        $default_calendar_view = $preference["default_calendar_view"];
    }
}

$view = $_GET["view"] ?? $default_calendar_view;
if (!in_array($view, ["month", "week", "day"], true)) {
    $view = $default_calendar_view;
}

$year = isset($_GET["year"]) ? (int)$_GET["year"] : (int)date("Y");
$month = isset($_GET["month"]) ? (int)$_GET["month"] : (int)date("n");
$day = isset($_GET["day"]) ? (int)$_GET["day"] : (int)date("j");

if ($month < 1 || $month > 12) {
    $month = (int)date("n");
}

if ($year < 2000 || $year > 2100) {
    $year = (int)date("Y");
}

$selected_date = new DateTime();
$selected_date->setDate($year, $month, 1);

if ($view === "day") {
    $max_day = (int)$selected_date->format("t");
    $day = max(1, min($day, $max_day));
    $selected_date->setDate($year, $month, $day);
}

/* =========================================================
   NAVIGATION
========================================================= */

if (isset($_GET["nav"])) {
    $nav = $_GET["nav"];

    if ($nav === "prev") {
        if ($view === "month") {
            $selected_date->modify("-1 month");
        } elseif ($view === "week") {
            $selected_date->modify("-7 days");
        } else {
            $selected_date->modify("-1 day");
        }
    }

    if ($nav === "next") {
        if ($view === "month") {
            $selected_date->modify("+1 month");
        } elseif ($view === "week") {
            $selected_date->modify("+7 days");
        } else {
            $selected_date->modify("+1 day");
        }
    }

    if ($nav === "today") {
        $selected_date = new DateTime();
    }

    $year = (int)$selected_date->format("Y");
    $month = (int)$selected_date->format("n");
    $day = (int)$selected_date->format("j");
}

/* =========================================================
   DEPARTMENTS
========================================================= */

$departments = [];

$department_result = $conn->query("SELECT DISTINCT department FROM users WHERE active = 1 AND department IS NOT NULL AND department <> '' ORDER BY department ASC");

if ($department_result) {
    while ($row = $department_result->fetch_assoc()) {
        $departments[] = $row["department"];
    }
}

$user_department = "";
$user_id = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("SELECT role, department FROM users WHERE id = ? AND active = 1 LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_row = $user_result->fetch_assoc();
$stmt->close();

if (!$user_row) {
    header("Location: login.php");
    exit;
}

$user_role = $user_row["role"] ?? "Employee";
$user_department = $user_row["department"] ?? "";

$department_filter = $_GET["department"] ?? "";

if ($user_role !== "Administrator" && $user_role !== "Manager" && $user_role !== "IT Administrator") {
    $department_filter = $user_department;
}

if ($department_filter !== "" && !in_array($department_filter, $departments, true) && $department_filter !== "all") {
    $department_filter = $user_department;
}

/* =========================================================
   DATE RANGE
========================================================= */

if ($view === "month") {
    $range_start = new DateTime($selected_date->format("Y-m-01"));
    $range_start->modify("last sunday");

    $range_end = new DateTime($selected_date->format("Y-m-t"));
    $range_end->modify("next saturday");
} elseif ($view === "week") {
    $range_start = clone $selected_date;
    $range_start->modify("sunday this week");

    $range_end = clone $range_start;
    $range_end->modify("+6 days");
} else {
    $range_start = clone $selected_date;
    $range_end = clone $selected_date;
}

$range_start_sql = $range_start->format("Y-m-d");
$range_end_sql = $range_end->format("Y-m-d");

/* =========================================================
   CALENDAR ITEMS
========================================================= */

$events = [];

function add_calendar_event(&$events, $date, $category, $name, $url, $start_date = null, $end_date = null, $time = null) {
    if (!$date) {
        return;
    }

    $events[] = [
        "date" => $date,
        "category" => $category,
        "name" => $name,
        "url" => $url,
        "start_date" => $start_date ?: $date,
        "end_date" => $end_date ?: $date,
        "time" => $time
    ];
}

/* ---------------------------------------------------------
   PROJECTS
--------------------------------------------------------- */
if (in_array("Projects", $selected_categories, true)) {
    $sql = "SELECT id, name, department, start_date, end_date FROM projects WHERE start_date IS NOT NULL AND end_date IS NOT NULL";

    if ($department_filter !== "" && $department_filter !== "all") {
        $sql .= " AND department = ?";
    }

    $sql .= " AND start_date <= ? AND end_date >= ? ORDER BY start_date ASC";

    $stmt = $conn->prepare($sql);

    if ($department_filter !== "" && $department_filter !== "all") {
        $stmt->bind_param("sss", $department_filter, $range_end_sql, $range_start_sql);
    } else {
        $stmt->bind_param("ss", $range_end_sql, $range_start_sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $start = new DateTime($row["start_date"]);
        $end = new DateTime($row["end_date"]);
        $cursor = clone $start;

        while ($cursor <= $end) {
            $date = $cursor->format("Y-m-d");

            if ($date >= $range_start_sql && $date <= $range_end_sql) {
                add_calendar_event(
                    $events,
                    $date,
                    "Projects",
                    $row["name"],
                    "projects.php?project=" . (int)$row["id"],
                    $row["start_date"],
                    $row["end_date"]
                );
            }

            $cursor->modify("+1 day");
        }
    }

    $stmt->close();
}

/* ---------------------------------------------------------
   TASKS
--------------------------------------------------------- */
if (in_array("Tasks", $selected_categories, true)) {
    $sql = "SELECT t.id, t.name, t.start_date, t.due_date, p.department
            FROM tasks t
            INNER JOIN projects p ON t.project_id = p.id
            WHERE t.start_date IS NOT NULL
              AND t.due_date IS NOT NULL";

    if ($department_filter !== "" && $department_filter !== "all") {
        $sql .= " AND p.department = ?";
    }

    $sql .= " AND t.start_date <= ? AND t.due_date >= ? ORDER BY t.start_date ASC";

    $stmt = $conn->prepare($sql);

    if ($department_filter !== "" && $department_filter !== "all") {
        $stmt->bind_param("sss", $department_filter, $range_end_sql, $range_start_sql);
    } else {
        $stmt->bind_param("ss", $range_end_sql, $range_start_sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $start = new DateTime($row["start_date"]);
        $end = new DateTime($row["due_date"]);
        $cursor = clone $start;

        while ($cursor <= $end) {
            $date = $cursor->format("Y-m-d");

            if ($date >= $range_start_sql && $date <= $range_end_sql) {
                add_calendar_event(
                    $events,
                    $date,
                    "Tasks",
                    $row["name"],
                    "view_task.php?id=" . (int)$row["id"],
                    $row["start_date"],
                    $row["due_date"]
                );
            }

            $cursor->modify("+1 day");
        }
    }

    $stmt->close();
}

/* ---------------------------------------------------------
   MILESTONES
--------------------------------------------------------- */
if (in_array("Milestones", $selected_categories, true)) {
    $sql = "SELECT m.id, m.milestone, m.due_date, p.id AS project_id, p.department
            FROM milestones m
            INNER JOIN projects p ON m.project_id = p.id
            WHERE m.due_date IS NOT NULL
              AND m.due_date BETWEEN ? AND ?";

    if ($department_filter !== "" && $department_filter !== "all") {
        $sql .= " AND p.department = ?";
    }

    $sql .= " ORDER BY m.due_date ASC";

    $stmt = $conn->prepare($sql);

    if ($department_filter !== "" && $department_filter !== "all") {
        $stmt->bind_param("sss", $range_start_sql, $range_end_sql, $department_filter);
    } else {
        $stmt->bind_param("ss", $range_start_sql, $range_end_sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        add_calendar_event(
            $events,
            $row["due_date"],
            "Milestones",
            $row["milestone"],
            "projects.php?project=" . (int)$row["project_id"]
        );
    }

    $stmt->close();
}

/* ---------------------------------------------------------
   DEADLINES
   Uses task due dates and milestone due dates.
--------------------------------------------------------- */
if (in_array("Deadlines", $selected_categories, true)) {
    $sql = "SELECT t.id, t.name, t.due_date, p.department
            FROM tasks t
            INNER JOIN projects p ON t.project_id = p.id
            WHERE t.due_date BETWEEN ? AND ?";

    if ($department_filter !== "" && $department_filter !== "all") {
        $sql .= " AND p.department = ?";
    }

    $sql .= " ORDER BY t.due_date ASC";

    $stmt = $conn->prepare($sql);

    if ($department_filter !== "" && $department_filter !== "all") {
        $stmt->bind_param("sss", $range_start_sql, $range_end_sql, $department_filter);
    } else {
        $stmt->bind_param("ss", $range_start_sql, $range_end_sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        add_calendar_event(
            $events,
            $row["due_date"],
            "Deadlines",
            $row["name"],
            "view_task.php?id=" . (int)$row["id"]
        );
    }

    $stmt->close();

    $sql = "SELECT m.id, m.milestone, m.due_date, p.id AS project_id, p.department
            FROM milestones m
            INNER JOIN projects p ON m.project_id = p.id
            WHERE m.due_date BETWEEN ? AND ?";

    if ($department_filter !== "" && $department_filter !== "all") {
        $sql .= " AND p.department = ?";
    }

    $sql .= " ORDER BY m.due_date ASC";

    $stmt = $conn->prepare($sql);

    if ($department_filter !== "" && $department_filter !== "all") {
        $stmt->bind_param("sss", $range_start_sql, $range_end_sql, $department_filter);
    } else {
        $stmt->bind_param("ss", $range_start_sql, $range_end_sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        add_calendar_event(
            $events,
            $row["due_date"],
            "Deadlines",
            $row["milestone"],
            "projects.php?project=" . (int)$row["project_id"]
        );
    }

    $stmt->close();
}

/* =========================================================
   HOLIDAYS
========================================================= */

$holidays = [
    "01-01" => "New Year's Day",
    "06-19" => "Juneteenth",
    "07-04" => "Independence Day",
    "11-11" => "Veterans Day",
    "12-25" => "Christmas Day"
];

/* Thanksgiving: fourth Thursday in November */
$november = new DateTime($year . "-11-01");
$thursday_count = 0;
for ($i = 0; $i < 30; $i++) {
    if ($november->format("N") == 4) {
        $thursday_count++;
        if ($thursday_count === 4) {
            $holidays["11-" . $november->format("d")] = "Thanksgiving Day";
            break;
        }
    }
    $november->modify("+1 day");
}

/* Memorial Day: last Monday in May */
$may = new DateTime($year . "-05-31");
while ($may->format("N") != 1) {
    $may->modify("-1 day");
}
$holidays["05-" . $may->format("d")] = "Memorial Day";

/* Labor Day: first Monday in September */
$september = new DateTime($year . "-09-01");
while ($september->format("N") != 1) {
    $september->modify("+1 day");
}
$holidays["09-" . $september->format("d")] = "Labor Day";

/* Martin Luther King Jr. Day: third Monday in January */
$january = new DateTime($year . "-01-01");
$monday_count = 0;
for ($i = 0; $i < 31; $i++) {
    if ($january->format("N") == 1) {
        $monday_count++;
        if ($monday_count === 3) {
            $holidays["01-" . $january->format("d")] = "Martin Luther King Jr. Day";
            break;
        }
    }
    $january->modify("+1 day");
}

/* Presidents' Day: third Monday in February */
$february = new DateTime($year . "-02-01");
$monday_count = 0;
for ($i = 0; $i < 28; $i++) {
    if ($february->format("N") == 1) {
        $monday_count++;
        if ($monday_count === 3) {
            $holidays["02-" . $february->format("d")] = "Presidents' Day";
            break;
        }
    }
    $february->modify("+1 day");
}

if (in_array("Holidays", $selected_categories, true)) {
    foreach ($holidays as $month_day => $holiday_name) {
        $holiday_date = $year . "-" . $month_day;

        if ($holiday_date >= $range_start_sql && $holiday_date <= $range_end_sql) {
            add_calendar_event(
                $events,
                $holiday_date,
                "Holidays",
                $holiday_name,
                "calendar.php?year=" . $year . "&month=" . (int)substr($month_day, 0, 2) . "&day=" . (int)substr($month_day, 3)
            );
        }
    }
}

/* =========================================================
   OPTIONAL FUTURE MODULES
   These queries only run if the tables exist.
========================================================= */

function table_exists($conn, $table) {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

/*
 * Tracks any category whose table couldn't be found, so a genuine
 * misconfiguration (typo'd table name, missing migration) surfaces
 * as a visible warning instead of silently showing an empty calendar.
 */
$missing_calendar_tables = [];

/* Meetings */
if (in_array("Meetings", $selected_categories, true) && !table_exists($conn, "events")) {
    $missing_calendar_tables[] = "events";
}

if (
    in_array("Meetings", $selected_categories, true)
    && table_exists($conn, "events")
) {

    $sql = "
        SELECT
            e.id,
            e.name,
            e.department,
            e.type,
            e.organizer,
            e.start_date,
            e.end_date,
            e.start_time,
            e.end_time,
            e.status,
            e.recurrence_type,
            eo.occurrence_date,
            eo.start_time AS occurrence_start_time,
            eo.end_time AS occurrence_end_time,
            eo.status AS occurrence_status
        FROM events e
        LEFT JOIN event_occurrences eo
            ON eo.event_id = e.id
        WHERE e.deleted = 0
          AND (
                (
                    e.recurrence_type = 'none'
                    AND e.start_date <= ?
                    AND e.end_date >= ?
                )
                OR
                (
                    e.recurrence_type <> 'none'
                    AND eo.occurrence_date BETWEEN ? AND ?
                )
          )
    ";

    $meeting_params = [
        $range_end_sql,
        $range_start_sql,
        $range_start_sql,
        $range_end_sql
    ];
    $meeting_types = "ssss";

    if ($user_role !== "Administrator") {
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

        $meeting_params[] = $user_department;
        $meeting_params[] = $user_id;
        $meeting_params[] = $user_id;
        $meeting_types .= "sii";
    }

    if ($department_filter !== "" && $department_filter !== "all") {
        $sql .= "
            AND (
                e.department = ?
                OR e.department IS NULL
                OR e.department = ''
                OR e.type = 'Organization-Wide Event'
            )
        ";
        $meeting_params[] = $department_filter;
        $meeting_types .= "s";
    }

    $sql .= " ORDER BY e.id ASC, eo.occurrence_date ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($meeting_types, ...$meeting_params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        if ($row["recurrence_type"] === "none") {

            $start = new DateTime($row["start_date"]);
            $end = new DateTime($row["end_date"]);
            $cursor = clone $start;

            while ($cursor <= $end) {
                $date = $cursor->format("Y-m-d");

                if ($date >= $range_start_sql && $date <= $range_end_sql) {
                    $name = $row["name"];

                    if ($row["status"] === "Cancelled") {
                        $name = "CANCELLED — " . $name;
                    }

                    add_calendar_event(
                        $events,
                        $date,
                        "Meetings",
                        $name,
                        "view_event.php?id=" . (int)$row["id"],
                        $row["start_date"],
                        $row["end_date"],
                        $row["start_time"]
                    );
                }

                $cursor->modify("+1 day");
            }

        } else {

            if (empty($row["occurrence_date"])) {
                continue;
            }

            $occurrence_start = $row["occurrence_date"];

            $base_start = new DateTime($row["start_date"]);
            $base_end = new DateTime($row["end_date"]);
            $duration_days =
                (int)$base_start->diff($base_end)->format("%a");

            $occurrence_end_obj = new DateTime($occurrence_start);

            if ($duration_days > 0) {
                $occurrence_end_obj->modify(
                    "+" . $duration_days . " days"
                );
            }

            $occurrence_end =
                $occurrence_end_obj->format("Y-m-d");

            $name = $row["name"];

            if ($row["occurrence_status"] === "Cancelled") {
                $name = "CANCELLED — " . $name;
            }

            $cursor = new DateTime($occurrence_start);
            $end_cursor = new DateTime($occurrence_end);

            while ($cursor <= $end_cursor) {
                $date = $cursor->format("Y-m-d");

                if ($date >= $range_start_sql && $date <= $range_end_sql) {
                    add_calendar_event(
                        $events,
                        $date,
                        "Meetings",
                        $name,
                        "view_event.php?id="
                            . (int)$row["id"]
                            . "&occurrence="
                            . urlencode($occurrence_start),
                        $occurrence_start,
                        $occurrence_end,
                        $row["occurrence_start_time"]
                    );
                }

                $cursor->modify("+1 day");
            }
        }
    }

    $stmt->close();
}

/* Training */
if (in_array("Training", $selected_categories, true) && !table_exists($conn, "training")) {
    $missing_calendar_tables[] = "training";
}

if (in_array("Training", $selected_categories, true) && table_exists($conn, "training")) {
    $sql = "SELECT id, name, department, start_date, end_date, start_time
            FROM training
            WHERE start_date <= ? AND end_date >= ?";

    if ($department_filter !== "" && $department_filter !== "all") {
        $sql .= " AND department = ?";
    }

    $stmt = $conn->prepare($sql);

    if ($department_filter !== "" && $department_filter !== "all") {
        $stmt->bind_param("sss", $range_end_sql, $range_start_sql, $department_filter);
    } else {
        $stmt->bind_param("ss", $range_end_sql, $range_start_sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $start = new DateTime($row["start_date"]);
        $end = new DateTime($row["end_date"]);
        $cursor = clone $start;

        while ($cursor <= $end) {
            $date = $cursor->format("Y-m-d");
            if ($date >= $range_start_sql && $date <= $range_end_sql) {
                add_calendar_event(
                    $events,
                    $date,
                    "Training",
                    $row["name"],
                    "view_training.php?id=" . (int)$row["id"],
                    $row["start_date"],
                    $row["end_date"],
                    $row["start_time"]
                );
            }
            $cursor->modify("+1 day");
        }
    }

    $stmt->close();
}

/* Time Off */
if (in_array("Time Off", $selected_categories, true) && !table_exists($conn, "time_off")) {
    $missing_calendar_tables[] = "time_off";
}

if (in_array("Time Off", $selected_categories, true) && table_exists($conn, "time_off")) {
    $sql = "SELECT t.id, t.user_id, t.start_date, t.end_date
            FROM time_off t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.status = 'Approved'
              AND t.start_date <= ?
              AND t.end_date >= ?";

    $time_off_params = [$range_end_sql, $range_start_sql];
    $time_off_types = "ss";

    if ($user_role === "Administrator") {
        // No further restriction - sees everyone's approved time off.
    } elseif ($user_role === "Manager" || $user_role === "IT Administrator") {
        $sql .= " AND u.department = ?";
        $time_off_params[] = $user_department;
        $time_off_types .= "s";
    } else {
        $sql .= " AND t.user_id = ?";
        $time_off_params[] = $user_id;
        $time_off_types .= "i";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($time_off_types, ...$time_off_params);

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $name = "Time Off";

        $user_stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $user_stmt->bind_param("i", $row["user_id"]);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $name = $user_row["first_name"] . " " . $user_row["last_name"];
        }
        $user_stmt->close();

        $start = new DateTime($row["start_date"]);
        $end = new DateTime($row["end_date"]);
        $cursor = clone $start;

        while ($cursor <= $end) {
            $date = $cursor->format("Y-m-d");
            if ($date >= $range_start_sql && $date <= $range_end_sql) {
                add_calendar_event(
                    $events,
                    $date,
                    "Time Off",
                    $name,
                    "view_time_off.php?id=" . (int)$row["id"],
                    $row["start_date"],
                    $row["end_date"]
                );
            }
            $cursor->modify("+1 day");
        }
    }

    $stmt->close();
}

/* =========================================================
   SORT EVENTS
========================================================= */

usort($events, function ($a, $b) {
    if ($a["date"] === $b["date"]) {
        $category_compare = strcmp($a["category"], $b["category"]);
        if ($category_compare !== 0) {
            return $category_compare;
        }
        return strcmp($a["name"], $b["name"]);
    }
    return strcmp($a["date"], $b["date"]);
});

$events_by_date = [];
foreach ($events as $event) {
    $events_by_date[$event["date"]][] = $event;
}

/*
 * In Week and Day views, single-day Meetings and Training items with a
 * start time belong in the hourly schedule. Projects, Tasks, Milestones,
 * Deadlines, Holidays, Time Off, and multi-day items remain all-day.
 */
function is_timed_calendar_event($event) {
    if (
        !in_array($event["category"], ["Meetings", "Training"], true)
        || empty($event["time"])
        || $event["start_date"] !== $event["end_date"]
    ) {
        return false;
    }

    return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $event["time"]) === 1;
}

function calendar_event_hour($event) {
    if (!is_timed_calendar_event($event)) {
        return null;
    }

    return (int)substr($event["time"], 0, 2);
}

function calendar_event_time_label($event) {
    if (!is_timed_calendar_event($event)) {
        return "";
    }

    $timestamp = strtotime($event["time"]);

    return $timestamp !== false
        ? date("g:i A", $timestamp)
        : "";
}

function split_day_calendar_events($events_for_day) {
    $all_day = [];
    $timed = [];

    foreach ($events_for_day as $event) {
        if (is_timed_calendar_event($event)) {
            $timed[] = $event;
        } else {
            $all_day[] = $event;
        }
    }

    usort($timed, function ($a, $b) {
        return strcmp($a["time"], $b["time"]);
    });

    return [
        "all_day" => $all_day,
        "timed" => $timed
    ];
}

/* =========================================================
   MULTI-DAY EVENT BARS

   Multi-day items (projects, meetings, training that run
   across more than one day) are shown as a single continuous
   bar spanning their date range, instead of a repeated box on
   every day they touch. Single-day items keep using the
   existing per-day list.
========================================================= */

$multi_day_events = [];
$seen_multi_day = [];
$single_day_events_by_date = [];

foreach ($events as $event) {

    if ($event["start_date"] !== $event["end_date"]) {

        $key = $event["category"] . "|" . $event["name"] . "|" . $event["url"] .
               "|" . $event["start_date"] . "|" . $event["end_date"];

        if (!isset($seen_multi_day[$key])) {
            $seen_multi_day[$key] = true;
            $multi_day_events[] = $event;
        }

    } else {

        $single_day_events_by_date[$event["date"]][] = $event;
    }
}

/*
   Given a week's date range, returns the multi-day events that
   overlap it, each assigned to a "lane" so overlapping bars
   stack instead of colliding, plus how many lanes were used so
   the day cells can reserve enough space above their own
   single-day items.
*/
function build_week_bars($multi_day_events, $week_start, $week_end) {

    $bars = [];
    $lanes = [];

    $sorted = $multi_day_events;

    usort($sorted, function ($a, $b) {
        $start_compare = strcmp($a["start_date"], $b["start_date"]);
        if ($start_compare !== 0) {
            return $start_compare;
        }
        return strcmp($b["end_date"], $a["end_date"]);
    });

    foreach ($sorted as $event) {

        if ($event["end_date"] < $week_start || $event["start_date"] > $week_end) {
            continue;
        }

        $overlap_start = max($event["start_date"], $week_start);
        $overlap_end = min($event["end_date"], $week_end);

        $start_col = (int)round(
            (strtotime($overlap_start) - strtotime($week_start)) / 86400
        );

        $end_col = (int)round(
            (strtotime($overlap_end) - strtotime($week_start)) / 86400
        );

        $lane_index = null;

        foreach ($lanes as $index => $ranges) {

            $conflict = false;

            foreach ($ranges as $range) {
                if ($start_col <= $range[1] && $end_col >= $range[0]) {
                    $conflict = true;
                    break;
                }
            }

            if (!$conflict) {
                $lane_index = $index;
                break;
            }
        }

        if ($lane_index === null) {
            $lane_index = count($lanes);
            $lanes[$lane_index] = [];
        }

        $lanes[$lane_index][] = [$start_col, $end_col];

        $bars[] = [
            "event" => $event,
            "start_col" => $start_col,
            "end_col" => $end_col,
            "lane" => $lane_index,
            "continues_before" => $event["start_date"] < $week_start,
            "continues_after" => $event["end_date"] > $week_end
        ];
    }

    return [
        "bars" => $bars,
        "lane_count" => count($lanes)
    ];
}

/* =========================================================
   URL BUILDER
========================================================= */

function calendar_url($params = []) {
    global $selected_categories, $department_filter, $view;

    $base = [
        "view" => $view,
        "department" => $department_filter
    ];

    foreach ($selected_categories as $category) {
        $base["category"][] = $category;
    }

    $base = array_merge($base, $params);
    return "calendar.php?" . http_build_query($base);
}

/* =========================================================
   VIEW DATES
========================================================= */

$month_title = $selected_date->format("F Y");
$today = date("Y-m-d");

$week_days = [];
$week_cursor = clone $range_start;
for ($i = 0; $i < 7; $i++) {
    $week_days[] = clone $week_cursor;
    $week_cursor->modify("+1 day");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar</title>
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
        <div class="sidebar-item active">
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
        <div class="sidebar-item">
            <a href="notifications.php">Notifications</a>
        </div>

        <?php if (canPostAnnouncements($user_role)): ?>
        <div class="sidebar-item">
            <a href="announcements.php">Announcements</a>
        </div>
        <?php endif; ?>

        <?php if (isAnyAdministrator($user_role)): ?>
        <div class="sidebar-item">
            <a href="users.php">User Management</a>
        </div>
        <?php endif; ?>

        <?php if ($user_role === "Administrator"): ?>
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

        <?php if (!empty($missing_calendar_tables) && isAnyAdministrator($user_role)): ?>
            <div style="background:#fef2f2;border:2px solid #fecaca;color:#991b1b;padding:12px 16px;margin:14px 20px 0;border-radius:6px;font-size:13px;">
                <strong>Configuration warning:</strong>
                The following table<?= count($missing_calendar_tables) > 1 ? "s were" : " was" ?> not found
                and <?= count($missing_calendar_tables) > 1 ? "their" : "its" ?> category is being skipped on this calendar:
                <?= e(implode(", ", $missing_calendar_tables)) ?>.
                Only Administrators see this message.
            </div>
        <?php endif; ?>

        <div class="header">
            <div>
                <h1>CALENDAR</h1>
                <div class="sub"><?= e($month_title) ?></div>
            </div>
        </div>

        <div class="content">

        <div class="toolbar">
            <a class="button" href="<?= e(calendar_url(["nav" => "prev"])) ?>">&lt; PREVIOUS</a>

            <form method="GET" style="display:flex; gap:7px;">
                <input type="hidden" name="view" value="<?= e($view) ?>">
                <input type="hidden" name="department" value="<?= e($department_filter) ?>">

                <?php foreach ($selected_categories as $category): ?>
                    <input type="hidden" name="category[]" value="<?= e($category) ?>">
                <?php endforeach; ?>

                <select name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === (int)$selected_date->format("n") ? "selected" : "" ?>>
                            <?= e(date("F", mktime(0, 0, 0, $m, 1))) ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <select name="year">
                    <?php for ($y = ((int)$selected_date->format("Y") - 5); $y <= ((int)$selected_date->format("Y") + 5); $y++): ?>
                        <option value="<?= $y ?>" <?= $y === (int)$selected_date->format("Y") ? "selected" : "" ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>

                <button class="button" type="submit">
                    GO
                </button>
            </form>

            <a class="button" href="<?= e(calendar_url(["nav" => "next"])) ?>">NEXT &gt;</a>
            <a class="button" href="<?= e(calendar_url(["nav" => "today"])) ?>">TODAY</a>

            <div class="toolbar-spacer"></div>

            <a class="button" href="<?= e(calendar_url(["view" => "month"])) ?>">MONTH</a>
            <a class="button" href="<?= e(calendar_url(["view" => "week"])) ?>">WEEK</a>
            <a class="button" href="<?= e(calendar_url(["view" => "day"])) ?>">DAY</a>
        </div>

        <form method="GET" class="filters">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="hidden" name="year" value="<?= (int)$selected_date->format("Y") ?>">
            <input type="hidden" name="month" value="<?= (int)$selected_date->format("n") ?>">
            <input type="hidden" name="day" value="<?= (int)$selected_date->format("j") ?>">

            <?php foreach ($allowed_categories as $category): ?>
                <label class="filter-label">
                    <input
                        type="checkbox"
                        name="category[]"
                        value="<?= e($category) ?>"
                        <?= in_array($category, $selected_categories, true) ? "checked" : "" ?>
                    >
                    <?= e($category) ?>
                </label>
            <?php endforeach; ?>

            <?php if ($user_role === "Administrator" || $user_role === "Manager" || $user_role === "IT Administrator"): ?>
                <select name="department">
                    <option value="all" <?= $department_filter === "all" ? "selected" : "" ?>>All Departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e($department) ?>" <?= $department_filter === $department ? "selected" : "" ?>>
                            <?= e($department) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="submit" class="filter-button">APPLY FILTERS</button>
        </form>

        <div class="calendar-wrap">

            <?php if ($view === "month"): ?>

                <div class="month-grid-wrap">

                    <div class="month-headings">
                        <?php foreach (["SUN", "MON", "TUE", "WED", "THU", "FRI", "SAT"] as $weekday): ?>
                            <div class="day-heading"><?= $weekday ?></div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $month_cursor = clone $range_start;

                    for ($week_index = 0; $week_index < 6; $week_index++):

                        $week_start_date = $month_cursor->format("Y-m-d");
                        $week_end_cursor = clone $month_cursor;
                        $week_end_cursor->modify("+6 days");
                        $week_end_date = $week_end_cursor->format("Y-m-d");

                        $week_bar_data = build_week_bars(
                            $multi_day_events,
                            $week_start_date,
                            $week_end_date
                        );

                        $lane_count = $week_bar_data["lane_count"];
                        $bars_height = $lane_count > 0 ? ($lane_count * 20 + 4) : 0;
                    ?>

                        <div class="week-row">

                            <div class="week-days">

                                <?php
                                $day_cursor = clone $month_cursor;

                                for ($d = 0; $d < 7; $d++):
                                    $cell_date = $day_cursor->format("Y-m-d");
                                    $is_current_month = $day_cursor->format("n") === $selected_date->format("n");
                                    $is_today = $cell_date === $today;
                                    $day_events = $single_day_events_by_date[$cell_date] ?? [];
                                ?>

                                    <div class="day-cell <?= $is_current_month ? "" : "other-month" ?>">
                                        <div class="day-number <?= $is_today ? "today" : "" ?>">
                                            <?= $day_cursor->format("j") ?>
                                        </div>

                                        <div style="height: <?= $bars_height ?>px;"></div>

                                        <?php if (!empty($day_events)): ?>

                                            <?php $visible_events = array_slice($day_events, 0, 3); ?>

                                            <?php foreach ($visible_events as $event): ?>
                                                <a class="calendar-event" href="<?= e($event["url"]) ?>" title="<?= e($event["category"] . " — " . $event["name"]) ?>">
                                                    <span class="event-category"><?= e(strtoupper($event["category"])) ?></span>
                                                    <?= e($event["name"]) ?>
                                                </a>
                                            <?php endforeach; ?>

                                            <?php if (count($day_events) > 3): ?>
                                                <a class="more-link" href="<?= e(calendar_url(["year" => (int)$day_cursor->format("Y"), "month" => (int)$day_cursor->format("n"), "day" => (int)$day_cursor->format("j"), "view" => "day"])) ?>">
                                                    + <?= count($day_events) - 3 ?> more
                                                </a>
                                            <?php endif; ?>

                                        <?php endif; ?>
                                    </div>

                                <?php
                                    $day_cursor->modify("+1 day");
                                endfor;
                                ?>

                            </div>

                            <?php if (!empty($week_bar_data["bars"])): ?>

                                <div class="week-bars">

                                    <?php foreach ($week_bar_data["bars"] as $bar): ?>

                                        <?php
                                        $left_pct = ($bar["start_col"] / 7) * 100;
                                        $width_pct = (($bar["end_col"] - $bar["start_col"] + 1) / 7) * 100;
                                        $top_px = $bar["lane"] * 20;
                                        ?>

                                        <a
                                            class="month-bar"
                                            href="<?= e($bar["event"]["url"]) ?>"
                                            title="<?= e($bar["event"]["category"] . " — " . $bar["event"]["name"]) ?>"
                                            style="left: <?= $left_pct ?>%; width: <?= $width_pct ?>%; top: <?= $top_px ?>px;"
                                        >
                                            <?= $bar["continues_before"] ? "&laquo; " : "" ?><?= e($bar["event"]["name"]) ?><?= $bar["continues_after"] ? " &raquo;" : "" ?>
                                        </a>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php
                        $month_cursor->modify("+7 days");
                    endfor;
                    ?>

                </div>

            <?php elseif ($view === "week"): ?>

                <div class="week-grid">

                    <div class="week-headers">
                        <?php foreach ($week_days as $week_day): ?>
                            <div class="week-header">
                                <?= strtoupper($week_day->format("D")) ?>
                                <?= $week_day->format("M j") ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $week_start_date = $week_days[0]->format("Y-m-d");
                    $week_end_date = $week_days[6]->format("Y-m-d");

                    $week_bar_data = build_week_bars(
                        $multi_day_events,
                        $week_start_date,
                        $week_end_date
                    );

                    $lane_count = $week_bar_data["lane_count"];
                    $bars_height = $lane_count > 0
                        ? ($lane_count * 20 + 4)
                        : 0;

                    $week_split = [];
                    $schedule_start_hour = 8;
                    $schedule_end_hour = 17;

                    foreach ($week_days as $week_day) {
                        $day_key = $week_day->format("Y-m-d");

                        $split = split_day_calendar_events(
                            $single_day_events_by_date[$day_key] ?? []
                        );

                        $week_split[$day_key] = $split;

                        foreach ($split["timed"] as $timed_event) {
                            $event_hour = calendar_event_hour($timed_event);

                            if ($event_hour !== null) {
                                $schedule_start_hour = min(
                                    $schedule_start_hour,
                                    $event_hour
                                );

                                $schedule_end_hour = max(
                                    $schedule_end_hour,
                                    $event_hour
                                );
                            }
                        }
                    }
                    ?>

                    <div class="week-all-day-row">

                        <div class="week-all-day-cols">

                            <?php foreach ($week_days as $week_day): ?>

                                <?php
                                $day_key = $week_day->format("Y-m-d");
                                $day_events =
                                    $week_split[$day_key]["all_day"] ?? [];
                                ?>

                                <div class="all-day-col">

                                    <strong>ALL DAY</strong>

                                    <div style="height: <?= $bars_height ?>px;"></div>

                                    <?php if (empty($day_events)): ?>

                                        <div class="empty">No items</div>

                                    <?php else: ?>

                                        <?php foreach (array_slice($day_events, 0, 8) as $event): ?>

                                            <a
                                                class="calendar-event"
                                                href="<?= e($event["url"]) ?>"
                                            >
                                                <span class="event-category">
                                                    <?= e(strtoupper($event["category"])) ?>
                                                </span>

                                                <?= e($event["name"]) ?>
                                            </a>

                                        <?php endforeach; ?>

                                        <?php if (count($day_events) > 8): ?>
                                            <a class="more-link" href="<?= e(calendar_url(["year" => (int)$week_day->format("Y"), "month" => (int)$week_day->format("n"), "day" => (int)$week_day->format("j"), "view" => "day"])) ?>">
                                                + <?= count($day_events) - 8 ?> more
                                            </a>
                                        <?php endif; ?>

                                    <?php endif; ?>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <?php if (!empty($week_bar_data["bars"])): ?>

                            <div class="week-bars">

                                <?php foreach ($week_bar_data["bars"] as $bar): ?>

                                    <?php
                                    $left_pct =
                                        ($bar["start_col"] / 7) * 100;

                                    $width_pct =
                                        (
                                            (
                                                $bar["end_col"]
                                                - $bar["start_col"]
                                                + 1
                                            )
                                            / 7
                                        ) * 100;

                                    $top_px = $bar["lane"] * 20;
                                    ?>

                                    <a
                                        class="month-bar"
                                        href="<?= e($bar["event"]["url"]) ?>"
                                        title="<?= e(
                                            $bar["event"]["category"]
                                            . " — "
                                            . $bar["event"]["name"]
                                        ) ?>"
                                        style="left: <?= $left_pct ?>%; width: <?= $width_pct ?>%; top: <?= $top_px ?>px;"
                                    >
                                        <?= $bar["continues_before"] ? "&laquo; " : "" ?>
                                        <?= e($bar["event"]["name"]) ?>
                                        <?= $bar["continues_after"] ? " &raquo;" : "" ?>
                                    </a>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="week-hours">

                        <?php foreach ($week_days as $week_day): ?>

                            <?php
                            $day_key = $week_day->format("Y-m-d");
                            $timed_events =
                                $week_split[$day_key]["timed"] ?? [];
                            ?>

                            <div class="week-column">

                                <?php for (
                                    $hour = $schedule_start_hour;
                                    $hour <= $schedule_end_hour;
                                    $hour++
                                ): ?>

                                    <div class="time-row">

                                        <strong>
                                            <?= date("g A", mktime($hour, 0, 0)) ?>
                                        </strong>

                                        <?php foreach ($timed_events as $event): ?>

                                            <?php if (
                                                calendar_event_hour($event)
                                                === $hour
                                            ): ?>

                                                <a
                                                    class="timed-event"
                                                    href="<?= e($event["url"]) ?>"
                                                >
                                                    <span class="timed-event-time">
                                                        <?= e(
                                                            calendar_event_time_label(
                                                                $event
                                                            )
                                                        ) ?>
                                                        ·
                                                        <?= e(
                                                            strtoupper(
                                                                $event["category"]
                                                            )
                                                        ) ?>
                                                    </span>

                                                    <span class="timed-event-name">
                                                        <?= e($event["name"]) ?>
                                                    </span>
                                                </a>

                                            <?php endif; ?>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endfor; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php else: ?>

                <?php
                $day_key = $selected_date->format("Y-m-d");

                $day_split = split_day_calendar_events(
                    $single_day_events_by_date[$day_key] ?? []
                );

                $day_all_day_events = $day_split["all_day"];
                $day_timed_events = $day_split["timed"];

                $day_schedule_start_hour = 8;
                $day_schedule_end_hour = 17;

                foreach ($day_timed_events as $timed_event) {
                    $event_hour = calendar_event_hour($timed_event);

                    if ($event_hour !== null) {
                        $day_schedule_start_hour = min(
                            $day_schedule_start_hour,
                            $event_hour
                        );

                        $day_schedule_end_hour = max(
                            $day_schedule_end_hour,
                            $event_hour
                        );
                    }
                }

                $day_multi_day_items = [];

                foreach ($multi_day_events as $multi_event) {
                    if (
                        $multi_event["start_date"] <= $day_key
                        && $multi_event["end_date"] >= $day_key
                    ) {
                        $day_multi_day_items[] = $multi_event;
                    }
                }
                ?>

                <div class="day-grid">

                    <div class="day-view-header">
                        <?= e($selected_date->format("l, F j, Y")) ?>
                    </div>

                    <div class="day-all-day">

                        <strong>ALL DAY</strong>

                        <?php if (
                            empty($day_all_day_events)
                            && empty($day_multi_day_items)
                        ): ?>

                            <div class="empty">
                                No all-day items for this day.
                            </div>

                        <?php else: ?>

                            <?php foreach ($day_multi_day_items as $event): ?>

                                <a
                                    class="calendar-event"
                                    href="<?= e($event["url"]) ?>"
                                >
                                    <span class="event-category">
                                        <?= e(strtoupper($event["category"])) ?>
                                    </span>

                                    <?= $event["start_date"] < $day_key
                                        ? "&laquo; "
                                        : "" ?>

                                    <?= e($event["name"]) ?>

                                    <?= $event["end_date"] > $day_key
                                        ? " &raquo;"
                                        : "" ?>
                                </a>

                            <?php endforeach; ?>

                            <?php foreach ($day_all_day_events as $event): ?>

                                <a
                                    class="calendar-event"
                                    href="<?= e($event["url"]) ?>"
                                >
                                    <span class="event-category">
                                        <?= e(strtoupper($event["category"])) ?>
                                    </span>

                                    <?= e($event["name"]) ?>
                                </a>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                    <?php for (
                        $hour = $day_schedule_start_hour;
                        $hour <= $day_schedule_end_hour;
                        $hour++
                    ): ?>

                        <div class="time-row">

                            <strong>
                                <?= date("g A", mktime($hour, 0, 0)) ?>
                            </strong>

                            <?php foreach ($day_timed_events as $event): ?>

                                <?php if (
                                    calendar_event_hour($event) === $hour
                                ): ?>

                                    <a
                                        class="timed-event"
                                        href="<?= e($event["url"]) ?>"
                                    >
                                        <span class="timed-event-time">
                                            <?= e(
                                                calendar_event_time_label(
                                                    $event
                                                )
                                            ) ?>
                                            ·
                                            <?= e(
                                                strtoupper(
                                                    $event["category"]
                                                )
                                            ) ?>
                                        </span>

                                        <span class="timed-event-name">
                                            <?= e($event["name"]) ?>
                                        </span>
                                    </a>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </div>

                    <?php endfor; ?>

                </div>

            <?php endif; ?>

        </div>

        </div>
    </main>
</div>

</body>
</html>
