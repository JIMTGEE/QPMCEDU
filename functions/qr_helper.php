<?php
// functions/qr_helper.php

/**
 * Generate a QR code as a base64 image string
 * Uses a simple QR code generation approach
 */
function generateQRCode($data, $size = 3) {
    // Create URL-encoded data for the QR code
    $encodedData = urlencode($data);
    
    // Use Google's Chart API as a fallback (works offline alternative below)
    // For production, consider using a PHP QR library like phpqrcode
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?data={$encodedData}&size=200x200&margin=10";
    
    // Try to get the QR image
    $imageData = @file_get_contents($qrUrl);
    
    if ($imageData !== false) {
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
    
    // Fallback: Generate a simple QR-like placeholder if API is unavailable
    return generateFallbackQR($data);
}

/**
 * Generate a fallback placeholder when QR API is unavailable
 */
function generateFallbackQR($data) {
    // Simple SVG-based QR placeholder
    $svg = '<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg">
        <rect width="200" height="200" fill="white"/>
        <rect x="10" y="10" width="180" height="180" fill="none" stroke="black" stroke-width="2"/>
        <text x="100" y="90" text-anchor="middle" font-family="Arial" font-size="12" fill="black">QR Code</text>
        <text x="100" y="110" text-anchor="middle" font-family="Arial" font-size="10" fill="gray">Unavailable</text>
        <text x="100" y="130" text-anchor="middle" font-family="Arial" font-size="8" fill="gray">Use manual login</text>
    </svg>';
    
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Create login data array for QR code
 */
function createLoginQRData($email, $password) {
    // Create a JSON object with login credentials
    $loginData = [
        'v' => '1', // version
        'e' => $email,
        'p' => base64_encode($password), // Basic encoding (consider encryption in production)
        't' => time()
    ];
    
    return json_encode($loginData);
}

/**
 * Generate a styled QR code image with logo overlay
 */
function generateStyledQRCode($email, $password, $userName = '') {
    $qrData = createLoginQRData($email, $password);
    return generateQRCode($qrData);
}
?>