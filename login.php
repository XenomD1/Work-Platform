<?php
session_start();
require_once "db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {

        $message = "Please enter your username and password.";

    } else {

        $stmt = $conn->prepare("
            SELECT
                id,
                username,
                password,
                first_name,
                last_name,
                department,
                role,
                active
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();

            if (!password_verify($password, $user["password"])) {

                $message = "Invalid username or password.";

            } elseif (!$user["active"]) {

                $message = "This account is inactive.";

            } else {

                // Regenerate the session ID now that the user's identity
                // has changed, so a session ID issued before login can't
                // be reused to hijack the now-authenticated session.
                session_regenerate_id(true);

                // Store the user's information in the session
                $_SESSION["user_id"] = $user["id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["first_name"] = $user["first_name"];
                $_SESSION["last_name"] = $user["last_name"];
                $_SESSION["department"] = $user["department"];
                $_SESSION["role"] = $user["role"];

                $allowed_landing_pages = [
                    "dashboard.php",
                    "projects.php",
                    "tasks.php",
                    "events.php",
                    "teams.php",
                    "files.php",
                    "reports.php",
                    "training.php",
                    "time_off.php",
                    "calendar.php",
                    "notifications.php"
                ];

                $landing_page = "dashboard.php";

                $preference_stmt = $conn->prepare("
                    SELECT default_landing_page
                    FROM user_preferences
                    WHERE user_id = ?
                    LIMIT 1
                ");
                $preference_stmt->bind_param("i", $user["id"]);
                $preference_stmt->execute();
                $preference = $preference_stmt->get_result()->fetch_assoc();
                $preference_stmt->close();

                if (
                    $preference
                    && in_array(
                        $preference["default_landing_page"],
                        $allowed_landing_pages,
                        true
                    )
                ) {
                    $landing_page = $preference["default_landing_page"];
                }

                header("Location: " . $landing_page);
                exit;

            }

        } else {

            $message = "Invalid username or password.";
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head>

    <meta charset="UTF-8">
    <title>Login</title>

    <link rel="stylesheet" href="app.css?v=19">
</head>

<body class="login-page">

<div class="login-container">

    <h1>Work Management Platform</h1>

    <?php if ($message !== ""): ?>

        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <div class="form-row">

            <label>Username</label>

            <input
                type="text"
                name="username"
                required
            >

        </div>

        <div class="form-row">

            <label>Password</label>

            <input
                type="password"
                name="password"
                required
            >

        </div>

        <button type="submit" class="btn-primary" style="width:100%; justify-content:center; margin-top:6px;">
            Login
        </button>

    </form>

</div>

</body>
</html>