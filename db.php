<?php

/*
 * Real credentials live in db_config.php, which is NOT committed to
 * Git (it's listed in .gitignore). This file (db.php) is safe to
 * commit - it contains no secrets, just the connection logic.
 *
 * First time setup on a new machine:
 *   1. Copy db_config.example.php to db_config.php
 *   2. Fill in your real database username/password in db_config.php
 */

if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . "db_config.php")) {
    die(
        "db_config.php is missing. Copy db_config.example.php to "
        . "db_config.php and fill in your database credentials."
    );
}

require __DIR__ . DIRECTORY_SEPARATOR . "db_config.php";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

?>
