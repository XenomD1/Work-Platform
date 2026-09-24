<?php
/*
    totp_helpers.php

    Self-contained TOTP (RFC 6238) implementation for #26 Two-Factor
    Authentication - no external library/composer dependency, in
    keeping with the rest of the platform's dependency-free approach
    (e.g. analytics_helpers.php's inline-CSS bar charts instead of a
    JS charting library). Compatible with any standard authenticator
    app (Google Authenticator, Authy, 1Password, etc.), which all
    implement the same RFC.
*/

/**
 * RFC 4648 base32 encoding (no padding stripped, but padding is
 * tolerated on decode). Used both for the shared secret shown to the
 * user during enrollment and internally.
 */
function base32Encode(string $data): string {
    $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    if ($data === "") {
        return "";
    }

    $bits = "";
    for ($i = 0; $i < strlen($data); $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, "0", STR_PAD_LEFT);
    }

    $output = "";
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, "0", STR_PAD_RIGHT);
        }
        $output .= $alphabet[bindec($chunk)];
    }

    return $output;
}

function base32Decode(string $data): string {
    $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $data = strtoupper(preg_replace('/[^A-Z2-7]/i', "", $data));

    $bits = "";
    for ($i = 0; $i < strlen($data); $i++) {
        $pos = strpos($alphabet, $data[$i]);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, "0", STR_PAD_LEFT);
    }

    $bytes = "";
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) {
            continue;
        }
        $bytes .= chr(bindec($byte));
    }

    return $bytes;
}

/**
 * A fresh random base32 secret for a new enrollment (160 bits / 20
 * bytes, the RFC 6238 recommended size).
 */
function generateTotpSecret(): string {
    return base32Encode(random_bytes(20));
}

/**
 * The 6-digit TOTP code for $base32Secret at a given Unix timestamp.
 */
function totpCodeAt(string $base32Secret, int $timestamp, int $period = 30, int $digits = 6): string {
    $key = base32Decode($base32Secret);
    $counter = (int)floor($timestamp / $period);

    $binCounter = pack("N*", 0) . pack("N*", $counter); // 8-byte big-endian counter
    $hash = hash_hmac("sha1", $binCounter, $key, true);

    $offset = ord($hash[19]) & 0x0F;
    $truncated = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    );

    $code = $truncated % (10 ** $digits);
    return str_pad((string)$code, $digits, "0", STR_PAD_LEFT);
}

/**
 * Verifies a user-entered code against $base32Secret, allowing for a
 * small amount of clock drift (default: one period before/after -
 * i.e. codes are accepted for up to ~90 seconds around "now", which
 * is the normal tolerance most TOTP implementations use).
 */
function verifyTotpCode(string $base32Secret, string $code, int $window = 1, int $period = 30): bool {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $candidate = totpCodeAt($base32Secret, $now + ($i * $period), $period);
        if (hash_equals($candidate, $code)) {
            return true;
        }
    }

    return false;
}

/**
 * The otpauth:// provisioning URI, shown as text (and as the manual
 * entry key) during enrollment rather than rendered as a QR code -
 * generating a QR code would normally mean either a new dependency
 * or sending the secret to a third-party QR-image API, and this
 * platform doesn't do either. Any authenticator app accepts manual
 * key entry as a standard fallback.
 */
function totpProvisioningUri(string $base32Secret, string $accountLabel, string $issuer = "WorkPlatform"): string {
    $label = rawurlencode($issuer . ":" . $accountLabel);
    $params = http_build_query([
        "secret" => $base32Secret,
        "issuer" => $issuer,
        "algorithm" => "SHA1",
        "digits" => 6,
        "period" => 30,
    ]);
    return "otpauth://totp/" . $label . "?" . $params;
}

/**
 * Generates $count fresh recovery codes. Returns them as plaintext
 * (shown to the user exactly once) - callers are responsible for
 * hashing each one with hashRecoveryCode() before storing it.
 */
function generateRecoveryCodes(int $count = 10): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        // 10 hex chars, grouped for readability: XXXXX-XXXXX
        $raw = strtoupper(bin2hex(random_bytes(5)));
        $codes[] = substr($raw, 0, 5) . "-" . substr($raw, 5, 5);
    }
    return $codes;
}

function hashRecoveryCode(string $code): string {
    return hash("sha256", strtoupper(trim($code)));
}
