<?php
session_start();

/**
 * Generate a CSRF token
 * @return string The generated CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token
 * @return bool True if valid, false otherwise
 */
function verifyCsrfToken() {
    $header_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $post_token = $_POST['csrf_token'] ?? '';
    $token = $header_token ?: $post_token;

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        error_log("CSRF token verification failed: provided=$token, expected=" . ($_SESSION['csrf_token'] ?? 'none'));
        return false;
    }

    // Check token expiration (e.g., 1 hour)
    $token_age = time() - ($_SESSION['csrf_token_time'] ?? 0);
    if ($token_age > 3600) {
        error_log("CSRF token expired: age=$token_age seconds");
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return false;
    }

    return true;
}

/**
 * Regenerate CSRF token
 */
function regenerateCsrfToken() {
    unset($_SESSION['csrf_token']);
    unset($_SESSION['csrf_token_time']);
    return generateCsrfToken();
}
?>