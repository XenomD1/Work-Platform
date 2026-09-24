<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php");
    exit;
}

require "db.php";
require_once "saved_view_helpers.php";
require_once "csrf_helpers.php";

requireValidCsrfToken();

$user_id = (int)$_SESSION["user_id"];

$module_pages = [
    "Tasks" => "tasks.php",
    "Projects" => "projects.php",
    "TimeOff" => "time_off.php",
    "Training" => "training.php",
    "Reports" => "reports.php",
];

$action = $_POST["action"] ?? "";
$module = $_POST["module"] ?? "";

if (!array_key_exists($module, $module_pages)) {
    header("Location: dashboard.php");
    exit;
}

$redirect_page = $module_pages[$module];

if ($action === "create") {

    $name = trim($_POST["name"] ?? "");
    $query_string = trim($_POST["query_string"] ?? "");

    // Strip a leading "?" if the page happened to send window.location.search verbatim.
    $query_string = ltrim($query_string, "?");

    if ($name === "") {
        header("Location: $redirect_page");
        exit;
    }

    createSavedView($conn, $user_id, $module, $name, $query_string);

    $redirect = $query_string !== ""
        ? $redirect_page . "?" . $query_string
        : $redirect_page;

    header("Location: $redirect");
    exit;
}

if ($action === "delete") {

    $view_id = (int)($_POST["view_id"] ?? 0);

    if ($view_id > 0) {
        deleteSavedView($conn, $user_id, $view_id);
    }

    header("Location: $redirect_page");
    exit;
}

header("Location: $redirect_page");
exit;
