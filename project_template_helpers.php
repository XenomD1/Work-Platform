<?php

/*
 * Project templates.
 *
 * Templates live in their own parallel set of tables (project_template_*)
 * so they can never leak into any existing project/task query - nothing
 * elsewhere in the app needs to know templates exist at all.
 *
 * Milestone and task dates are stored as day offsets from the source
 * project's start_date, and converted back to real dates using the new
 * project's start_date when a project is created from a template.
 * Assignees, task dependencies, recurrence, and checklists are
 * deliberately not captured - those are specific to one real project's
 * people and schedule, not reusable structure.
 */

/**
 * Templates a user is allowed to see: their own department's templates,
 * or every template for an Administrator.
 */
function getAvailableTemplates(mysqli $conn, string $role, string $department): array {
    if ($role === "Administrator") {
        $result = $conn->query("
            SELECT id, name, department, type, created_at
            FROM project_templates
            ORDER BY name ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT id, name, department, type, created_at
            FROM project_templates
            WHERE department = ?
            ORDER BY name ASC
        ");
        $stmt->bind_param("s", $department);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    $templates = [];

    while ($row = $result->fetch_assoc()) {
        $templates[] = $row;
    }

    return $templates;
}

/**
 * Day offset between a project's start_date and some other date
 * belonging to it (a milestone or task date). Null if either date is
 * missing, since there's nothing to anchor the offset to.
 */
function dateToOffset(?string $anchor_date, ?string $target_date): ?int {
    if (empty($anchor_date) || empty($target_date)) {
        return null;
    }

    $anchor = new DateTime($anchor_date);
    $target = new DateTime($target_date);

    return (int)$anchor->diff($target)->format("%r%a");
}

/**
 * The reverse of dateToOffset() - applies a day offset to a new
 * anchor date. Null if either input is missing.
 */
function offsetToDate(?string $anchor_date, ?int $offset_days): ?string {
    if (empty($anchor_date) || $offset_days === null) {
        return null;
    }

    $dt = new DateTime($anchor_date);
    $dt->modify(($offset_days >= 0 ? "+" : "") . $offset_days . " days");

    return $dt->format("Y-m-d");
}

/**
 * Whitelist of the table/column pairs saveProjectAsTemplate() and
 * createProjectFromTemplate() are allowed to build a query string
 * around. The table/column names that reach those queries are always
 * one of the hardcoded literals below, never user input - but the
 * queries are still built with string interpolation (mysqli can't bind
 * identifiers), so this whitelist is what stops that interpolation
 * from ever becoming a real injection point if the code is changed
 * later to loop over something else. Any pair not listed here throws
 * instead of silently building a query out of an unexpected identifier.
 */
const PROJECT_TEMPLATE_LIST_COLUMNS = [
    "objectives" => "objective",
    "deliverables" => "deliverable",
    "priorities" => "priority",
    "risks" => "risk",
];

/**
 * Validates a source/target table+column set against the whitelist
 * above before it is used to build SQL. Throws if the pair isn't
 * recognized.
 */
function assertValidTemplateListColumn(string $table, string $column): void {
    if ((PROJECT_TEMPLATE_LIST_COLUMNS[$table] ?? null) !== $column) {
        throw new InvalidArgumentException(
            "assertValidTemplateListColumn: unsupported table/column pair ($table.$column)."
        );
    }
}

/**
 * Copies a live project's structure into a new template. Returns the
 * new template's id, or null if the project doesn't exist.
 */
function saveProjectAsTemplate(
    mysqli $conn,
    int $project_id,
    string $template_name,
    int $created_by
): ?int {
    $project_stmt = $conn->prepare("
        SELECT name, department, type, complexity, cost, roi,
               overview, additional, start_date
        FROM projects
        WHERE id = ?
    ");
    $project_stmt->bind_param("i", $project_id);
    $project_stmt->execute();
    $project = $project_stmt->get_result()->fetch_assoc();
    $project_stmt->close();

    if (!$project) {
        return null;
    }

    $template_name = $template_name !== "" ? $template_name : $project["name"];

    $insert_stmt = $conn->prepare("
        INSERT INTO project_templates
            (name, department, type, complexity, cost, roi, overview, additional, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert_stmt->bind_param(
        "ssssdsssi",
        $template_name,
        $project["department"],
        $project["type"],
        $project["complexity"],
        $project["cost"],
        $project["roi"],
        $project["overview"],
        $project["additional"],
        $created_by
    );
    $insert_stmt->execute();
    $template_id = (int)$insert_stmt->insert_id;
    $insert_stmt->close();

    $start_date = $project["start_date"];

    foreach (
        [
            ["objectives", "objective", "project_template_objectives", "objective"],
            ["deliverables", "deliverable", "project_template_deliverables", "deliverable"],
            ["priorities", "priority", "project_template_priorities", "priority"],
            ["risks", "risk", "project_template_risks", "risk"]
        ] as [$source_table, $source_column, $target_table, $target_column]
    ) {
        assertValidTemplateListColumn($source_table, $source_column);

        $rows_stmt = $conn->prepare("SELECT `$source_column` FROM `$source_table` WHERE project_id = ?");
        $rows_stmt->bind_param("i", $project_id);
        $rows_stmt->execute();
        $rows_result = $rows_stmt->get_result();

        $copy_stmt = $conn->prepare("INSERT INTO `$target_table` (template_id, `$target_column`) VALUES (?, ?)");

        while ($row = $rows_result->fetch_assoc()) {
            $value = $row[$source_column];
            $copy_stmt->bind_param("is", $template_id, $value);
            $copy_stmt->execute();
        }

        $rows_stmt->close();
        $copy_stmt->close();
    }

    $milestones_stmt = $conn->prepare("
        SELECT milestone, due_date FROM milestones WHERE project_id = ?
    ");
    $milestones_stmt->bind_param("i", $project_id);
    $milestones_stmt->execute();
    $milestones_result = $milestones_stmt->get_result();

    $milestone_insert_stmt = $conn->prepare("
        INSERT INTO project_template_milestones (template_id, milestone, due_offset_days)
        VALUES (?, ?, ?)
    ");

    while ($milestone = $milestones_result->fetch_assoc()) {
        $offset = dateToOffset($start_date, $milestone["due_date"]);
        $milestone_text = $milestone["milestone"];
        $milestone_insert_stmt->bind_param("isi", $template_id, $milestone_text, $offset);
        $milestone_insert_stmt->execute();
    }

    $milestones_stmt->close();
    $milestone_insert_stmt->close();

    $tasks_stmt = $conn->prepare("
        SELECT name, description, priority, start_date, due_date
        FROM tasks
        WHERE project_id = ?
    ");
    $tasks_stmt->bind_param("i", $project_id);
    $tasks_stmt->execute();
    $tasks_result = $tasks_stmt->get_result();

    $task_insert_stmt = $conn->prepare("
        INSERT INTO project_template_tasks
            (template_id, name, description, priority, start_offset_days, due_offset_days)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    while ($task = $tasks_result->fetch_assoc()) {
        $start_offset = dateToOffset($start_date, $task["start_date"]);
        $due_offset = dateToOffset($start_date, $task["due_date"]);

        $task_insert_stmt->bind_param(
            "isssii",
            $template_id,
            $task["name"],
            $task["description"],
            $task["priority"],
            $start_offset,
            $due_offset
        );
        $task_insert_stmt->execute();
    }

    $tasks_stmt->close();
    $task_insert_stmt->close();

    $teams_stmt = $conn->prepare("
        SELECT name, description, responsibilities
        FROM teams
        WHERE project_id = ?
    ");
    $teams_stmt->bind_param("i", $project_id);
    $teams_stmt->execute();
    $teams_result = $teams_stmt->get_result();

    $team_insert_stmt = $conn->prepare("
        INSERT INTO project_template_teams (template_id, name, description, responsibilities)
        VALUES (?, ?, ?, ?)
    ");

    while ($team = $teams_result->fetch_assoc()) {
        $team_insert_stmt->bind_param(
            "isss",
            $template_id,
            $team["name"],
            $team["description"],
            $team["responsibilities"]
        );
        $team_insert_stmt->execute();
    }

    $teams_stmt->close();
    $team_insert_stmt->close();

    return $template_id;
}

/**
 * Creates a new real project from a template, converting relative
 * offsets back into real dates using $start_date as the anchor.
 * $manager_id/$manager_name are the creating user, who automatically
 * becomes the new project's manager. Returns the new project's id, or
 * null if the template doesn't exist.
 */
function createProjectFromTemplate(
    mysqli $conn,
    int $template_id,
    string $project_name,
    string $department,
    string $start_date,
    ?string $end_date,
    int $manager_id,
    string $manager_name
): ?int {
    $template_stmt = $conn->prepare("
        SELECT type, complexity, cost, roi, overview, additional
        FROM project_templates
        WHERE id = ?
    ");
    $template_stmt->bind_param("i", $template_id);
    $template_stmt->execute();
    $template = $template_stmt->get_result()->fetch_assoc();
    $template_stmt->close();

    if (!$template) {
        return null;
    }

    $project_insert_stmt = $conn->prepare("
        INSERT INTO projects
        (
            name, type, department, description, start_date, end_date,
            manager, manager_id, status, phase, complexity, cost, roi,
            completion, overview, additional
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Not Started', 'Planning', ?, ?, ?, 0, ?, ?)
    ");

    $description = "";

    $project_insert_stmt->bind_param(
        "sssssssisdsss",
        $project_name,
        $template["type"],
        $department,
        $description,
        $start_date,
        $end_date,
        $manager_name,
        $manager_id,
        $template["complexity"],
        $template["cost"],
        $template["roi"],
        $template["overview"],
        $template["additional"]
    );

    $project_insert_stmt->execute();
    $new_project_id = (int)$project_insert_stmt->insert_id;
    $project_insert_stmt->close();

    foreach (
        [
            ["project_template_objectives", "objective", "objectives", "objective"],
            ["project_template_deliverables", "deliverable", "deliverables", "deliverable"],
            ["project_template_priorities", "priority", "priorities", "priority"],
            ["project_template_risks", "risk", "risks", "risk"]
        ] as [$source_table, $source_column, $target_table, $target_column]
    ) {
        assertValidTemplateListColumn($target_table, $target_column);

        $rows_stmt = $conn->prepare("SELECT `$source_column` FROM `$source_table` WHERE template_id = ?");
        $rows_stmt->bind_param("i", $template_id);
        $rows_stmt->execute();
        $rows_result = $rows_stmt->get_result();

        $copy_stmt = $conn->prepare("INSERT INTO `$target_table` (project_id, `$target_column`) VALUES (?, ?)");

        while ($row = $rows_result->fetch_assoc()) {
            $value = $row[$source_column];
            $copy_stmt->bind_param("is", $new_project_id, $value);
            $copy_stmt->execute();
        }

        $rows_stmt->close();
        $copy_stmt->close();
    }

    $milestones_stmt = $conn->prepare("
        SELECT milestone, due_offset_days FROM project_template_milestones WHERE template_id = ?
    ");
    $milestones_stmt->bind_param("i", $template_id);
    $milestones_stmt->execute();
    $milestones_result = $milestones_stmt->get_result();

    $milestone_insert_stmt = $conn->prepare("
        INSERT INTO milestones (project_id, milestone, due_date, status, completion)
        VALUES (?, ?, ?, 'Not Started', 0)
    ");

    while ($milestone = $milestones_result->fetch_assoc()) {
        $due_date = offsetToDate($start_date, $milestone["due_offset_days"]);
        $milestone_text = $milestone["milestone"];
        $milestone_insert_stmt->bind_param("iss", $new_project_id, $milestone_text, $due_date);
        $milestone_insert_stmt->execute();
    }

    $milestones_stmt->close();
    $milestone_insert_stmt->close();

    $tasks_stmt = $conn->prepare("
        SELECT name, description, priority, start_offset_days, due_offset_days
        FROM project_template_tasks
        WHERE template_id = ?
    ");
    $tasks_stmt->bind_param("i", $template_id);
    $tasks_stmt->execute();
    $tasks_result = $tasks_stmt->get_result();

    $task_insert_stmt = $conn->prepare("
        INSERT INTO tasks (project_id, name, description, priority, status, start_date, due_date, completion)
        VALUES (?, ?, ?, ?, 'Not Started', ?, ?, 0)
    ");

    while ($task = $tasks_result->fetch_assoc()) {
        $task_start_date = offsetToDate($start_date, $task["start_offset_days"]);
        $task_due_date = offsetToDate($start_date, $task["due_offset_days"]);

        $task_insert_stmt->bind_param(
            "isssss",
            $new_project_id,
            $task["name"],
            $task["description"],
            $task["priority"],
            $task_start_date,
            $task_due_date
        );
        $task_insert_stmt->execute();
    }

    $tasks_stmt->close();
    $task_insert_stmt->close();

    $teams_stmt = $conn->prepare("
        SELECT name, description, responsibilities
        FROM project_template_teams
        WHERE template_id = ?
    ");
    $teams_stmt->bind_param("i", $template_id);
    $teams_stmt->execute();
    $teams_result = $teams_stmt->get_result();

    $team_insert_stmt = $conn->prepare("
        INSERT INTO teams (project_id, name, description, responsibilities, team_lead_id, status, created_by)
        VALUES (?, ?, ?, ?, ?, 'Active', ?)
    ");

    while ($team = $teams_result->fetch_assoc()) {
        $team_insert_stmt->bind_param(
            "isssii",
            $new_project_id,
            $team["name"],
            $team["description"],
            $team["responsibilities"],
            $manager_id,
            $manager_id
        );
        $team_insert_stmt->execute();
    }

    $teams_stmt->close();
    $team_insert_stmt->close();

    return $new_project_id;
}
