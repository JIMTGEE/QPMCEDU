<?php
// /attendance/includes/auth_check.php
// Role-based access control for the attendance module

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once __DIR__ . '/../../config/db.php'; // Adjust path as needed

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Get current user's role
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

// Get current user's ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Check if user has one of the allowed roles
function hasRole($allowedRoles = []) {
    if (!isLoggedIn()) return false;
    $role = getCurrentUserRole();
    return in_array($role, $allowedRoles);
}

// Require authentication - redirects to login if not logged in
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: /login.php'); // Adjust to your login page
        exit();
    }
}

// Require specific role(s) - redirects if not authorized
function requireRole($allowedRoles = []) {
    requireAuth();
    if (!hasRole($allowedRoles)) {
        header('HTTP/1.1 403 Forbidden');
        die('Access Denied. You do not have permission to view this page.');
    }
}

// For API endpoints - returns JSON error if not authorized
function requireRoleAPI($allowedRoles = []) {
    requireAuth();
    if (!hasRole($allowedRoles)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Access Denied', 'success' => false]);
        exit();
    }
}

// Check if user is staff (can use the scanner)
function isStaff() {
    return hasRole(['admin', 'teacher', 'security', 'head_academics']);
}

// Check if user can override denials
function canOverride() {
    return hasRole(['admin', 'teacher', 'security', 'head_academics']);
}

// Get user details from database
function getUserDetails($userId) {
    global $conn;
    $stmt = $conn->prepare("SELECT id, name, email, role, profile_picture, status FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Check if user is active
function isUserActive($userId) {
    $user = getUserDetails($userId);
    return $user && $user['status'] === 'active';
}