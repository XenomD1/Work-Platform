# WorkPlatform

A PHP/MySQL work management platform covering projects, tasks, meetings/events,
teams, file storage, reports, training, and time off — built to run on XAMPP.

## Features

- **Projects & Tasks** — project tracking with milestones, task assignment,
  and completion tracking.
- **Calendar** — month/week/day views aggregating meetings, training, task
  due dates, and approved time off, with recurring event support.
- **Meetings / Events** — single and recurring events, RSVPs, reminders sent
  by email, automatic status completion.
- **Teams** — team membership, team leads, project-scoped teams.
- **Organization Chart** — Administrator-only department-by-department
  breakdown (headcount, management contacts), with drill-down into each
  department's roster.
- **Files** — upload, versioning, folders, and Trash (soft delete with
  restore).
- **Reports** — tiered visibility by creator level (Employee / Team Lead /
  Manager / Administrator).
- **Analytics** — role-scoped metrics and reporting, reachable from the
  sidebar.
- **Training** — scheduling, attendee management, department or
  individual-based invitations.
- **Time Off** — request/approval workflow with optional attachments.
- **Timesheets** — reachable from the sidebar.
- **Notifications** — in-app notifications for assignments, approvals, and
  status changes across every module above.
- **Announcements** — organization-wide or department-targeted posts
  (Organization/Department Announcement, Scheduled Maintenance, Office
  Closure, Policy Update), publish-now or scheduled, optional expiration,
  with lifecycle states (Active/Scheduled/Expired/Inactive). Posting is
  gated by `canPostAnnouncements()`; non-admins can only target their own
  department.
- **User roles & permissions** — see [Roles & Permissions](#roles--permissions)
  below.

## Requirements

- [XAMPP](https://www.apachefriends.org/) (Apache + PHP + MySQL/MariaDB)
- PHP 8.x
- A modern browser

## Setup

1. **Clone this repository** into your XAMPP `htdocs` folder, e.g.
   `C:\xampp\htdocs\workplatform`.

2. **Create the database.** In phpMyAdmin, create a database (default name
   used throughout this project: `work_management`) and import your schema.

3. **Set up your database credentials.** This repo does not include real
   credentials — `db_config.php` is git-ignored on purpose.

   - Copy `db_config.example.php` to `db_config.php`.
   - Edit `db_config.php` and fill in your actual database host, username,
     password, and database name.
   - `db.php` reads from `db_config.php` automatically — you shouldn't need
     to touch `db.php` itself.

4. **Set up private file storage.** Time-off attachments are stored outside
   the web-servable folder so they can never be reached by a direct URL.

   - Open `storage_config.php` and confirm `TIME_OFF_ATTACHMENT_DIR`
     resolves to a folder outside your Apache document root. The default
     assumes the standard XAMPP layout (`C:\xampp\htdocs\...`) and points two
     folders up, landing outside `htdocs` entirely.
   - Create that folder if it doesn't already exist, or let the app create
     it automatically on first upload.

5. **Visit the site** at `http://localhost/workplatform/login.php` and log
   in with a seeded account (or insert one directly via phpMyAdmin — see
   `create_user.php` for the fields required, passwords are hashed with
   PHP's `password_hash()`).

## Scheduled background tasks

Two scripts need to run periodically outside the browser — they check
`PHP_SAPI !== "cli"` and refuse to run over HTTP:

| Script | Purpose | Suggested frequency |
|---|---|---|
| `process_event_statuses.php` | Auto-completes events/occurrences once their end time passes; auto-cancels a recurring series if every occurrence was individually cancelled. | Every 5–15 minutes |
| `process_event_reminders.php` | Sends due event reminders by email and logs delivery attempts. | More often than your shortest reminder option (e.g. every 5 minutes if a 15-minute reminder exists) |

Three `.bat` files are included for Windows Task Scheduler:

- `run_event_statuses.bat` — runs the status processor alone.
- `run_event_reminders.bat` — runs the reminder processor alone.
- `run_event_maintenance.bat` — runs **both** in sequence.

Don't schedule all three separately — `run_event_maintenance.bat` already
covers the other two, and running them all independently just means
`process_event_statuses.php` runs redundantly.

**Note:** `process_event_reminders.php` uses PHP's built-in `mail()`. A
default XAMPP install typically does not send real email without
additional configuration (e.g. Mercury Mail or an external SMTP relay) —
confirm mail delivery is actually working before relying on it.

## Roles & Permissions

| Role | Access |
|---|---|
| **Employee** | Own tasks, assigned projects/teams, own time-off requests, own reports. |
| **Manager** | Everything an Employee has, plus department-wide visibility and management for their own department (projects, tasks, events, training, time-off approvals, reports). |
| **IT Administrator** | The same *kind* of access as Administrator (unrestricted by creator level, can manage users, etc.) but scoped to their own department only. Cannot create Administrator accounts or manage another department's data. |
| **Administrator** | Full access to everything, every department, no restrictions. |

Two shared helper files implement this consistently across the app:

- `role_helpers.php` — `isAnyAdministrator($role)` and
  `canAdministerDepartment($role, $userDepartment, $targetDepartment)`.
- `notification_helpers.php` — `createNotification()` and related functions
  used by every module to notify affected users (excluding the person who
  triggered the action, and skipping inactive/deactivated accounts).

### Roles & Permissions page (`roles.php`)

Administrator-only (strict — `$role === "Administrator"`, not just
`isAnyAdministrator()`). Backed by three real tables:

- `custom_roles` (`id`, `name`, `description`, `is_system`, `created_at`) —
  the four roles above are seeded as `is_system = 1`.
- `permissions` (`id`, `code`, `module`, `label`, `description`,
  `is_protected`) — the full catalog of assignable permissions, grouped by
  module (Projects, Tasks, Teams, Reports, Time Off, Training/Events,
  Files, Analytics, Admin, …).
- `role_permissions` (`role_name`, `permission_code`) — a many-to-many
  junction table; a row's presence *is* the "allowed" flag, there's no
  boolean column.

`roles.php` renders every role × permission as a checkbox matrix, grouped
by module, and on save replaces each role's full set of rows in
`role_permissions` inside a transaction. Submitted role names and
permission codes are always validated against the real tables before any
write.

**`is_protected` guard:** a permission marked `is_protected = 1` cannot be
unchecked for the **Administrator** role specifically — the checkbox
renders disabled and force-checked, and the server re-adds any protected
code for Administrator even if a stale/edited request tries to drop it.
This exists so an Administrator can never accidentally strip every admin
of the ability to manage roles again. Protected permissions can still be
freely toggled for every other role.

**Important — this is a reference/tracking layer, not yet live
enforcement.** Checking or unchecking a box in `roles.php` only changes
what's stored in `role_permissions`. It does **not** change what any role
can actually do elsewhere in the app today — real access control is still
the hardcoded checks described above (`isAnyAdministrator()`,
`canAdministerDepartment()`, and the individual `$role === "Manager"` /
`$role === "IT Administrator"` checks scattered through pages like
`edit_project.php`, `edit_team.php`, `edit_event.php`,
`edit_training.php`, `file_trash.php`, and `announcement_helpers.php`).
Wiring real, page-by-page enforcement to the `role_permissions` table is a
separate, larger project — not yet done.

### Sidebar convention

Every page with a sidebar follows one canonical nav order: Dashboard,
Tasks, Projects, Calendar, Meetings / Events, Teams, Organization Chart,
Files, Reports, Analytics, Training, Time Off, Timesheets, Notifications,
Announcements *(if `canPostAnnouncements($role)`)*, User Management *(if
`isAnyAdministrator($role)`)*, Roles & Permissions *(if `$role ===
"Administrator"`, strict)*, then a visually separated Account / Settings
group at the bottom.

The variable holding the current user's role is **not** consistent by
name across files — it's `$role`, `$current_role`, `$user["role"]`, or
(in a couple of older files) `$current_user["role"]` directly, with no
separate variable at all. Always check what a given file already uses
before adding or editing a sidebar conditional; copying a variable name
from another file without checking has been the single most common bug
source when this nav was standardized (a sidebar conditional silently
falling back to `$_SESSION["role"] ?? ""` instead of the page's own
re-fetched, active-status-checked role value).

Project, task, and team "manager" assignment is done by selecting a real
user account (stored as `manager_id`, a foreign key to `users.id`) — not by
typing a name — to avoid ambiguity between people who share a name.

## Project structure notes

- Each module generally follows a `new_*.php` / `edit_*.php` / `view_*.php`
  / `delete_*.php` pattern (e.g. `new_task.php`, `edit_task.php`,
  `view_task.php`).
- File uploads are validated against an explicit allowlist of extensions
  (not a denylist), cross-checked against actual file content via `finfo`.
- Soft-deleted items (files, folders, events, training) go through a Trash
  flow with the ability to restore before permanent deletion.

## Security notes for anyone extending this project

- Never commit `db_config.php`. If it's ever accidentally committed, adding
  it to `.gitignore` afterward does **not** remove it from Git history —
  that requires rewriting history (e.g. `git filter-repo`).
- Time-off attachments live outside the document root by design — don't
  move that storage location back inside a web-accessible folder.
- File upload validation uses an allowlist; if you add new accepted file
  types, add them to the `$allowed_mime_by_extension` list in both
  `upload_file.php` and `new_file_version.php` (or better, factor it into a
  shared helper).
