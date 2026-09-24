<?php
/*
    csrf_helpers.php

    Minimal CSRF protection shared by every POST-only handler and every
    page that submits a POST form. session_start() must already have
    been called before any of these are used.

    Usage:
      - In a page that renders a <form method="POST">, print csrfField()
        somewhere inside the form.
      - At the top of the POST handling for that form (whether it's a
        dedicated *_action.php file or a block inside the same page),
        call requireValidCsrfToken() before doing anything else. It
        exits with 403 if the token is missing or doesn't match.
*/

function csrfToken(): string {
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8")
        . '">';
}

function requireValidCsrfToken(): void {
    $submitted = $_POST["csrf_token"] ?? "";
    $expected = $_SESSION["csrf_token"] ?? "";

    if ($expected === "" || $submitted === "" || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit("This form could not be verified (your session may have expired). Please go back, refresh the page, and try again.");
    }
}
