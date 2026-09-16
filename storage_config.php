<?php

/*
 * Where time-off attachments are stored on disk.
 *
 * This is intentionally OUTSIDE the folder your web server serves
 * (your "workplatform" app folder / document root). Since PHP's
 * filesystem functions aren't restricted to the document root the
 * way the web server's static file serving is, this means there is
 * no URL that can ever reach these files directly - the only way in
 * is through download_time_off_attachment.php, which checks
 * permission first.
 *
 * Default assumption: this file lives in the same folder as your
 * other PHP pages (e.g. C:\xampp\htdocs\workplatform\), and your
 * site's document root is the "htdocs" folder one level above that
 * (the XAMPP default). Going up two levels from here lands outside
 * "htdocs" entirely, in a folder no URL can reach.
 *
 * IMPORTANT: verify this actually resolves outside your document
 * root for your setup. If your site's document root is something
 * other than XAMPP's default "htdocs", adjust the path below.
 */

define(
    "TIME_OFF_ATTACHMENT_DIR",
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "private_uploads" . DIRECTORY_SEPARATOR . "time_off"
);
