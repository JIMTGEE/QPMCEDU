<?php
// /functions/gemini_api.php
// Wrapper for Google Gemini API calls in QPMS admin progress pages.
// Logs errors, handles retries lightly, and formats output for HTML display.

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/gemini.php';  // Pulls API config

function callGeminiAPI($prompt, $data = [], $conn = null, $user_id = null) {
    $config = include $_SERVER['DOCUMENT_ROOT'] . '/config/gemini.php';
    if (empty($config['api_key']) || $config['api_key'] === 'YOUR_ACTUAL_API_KEY_HERE') {
        error_log("Gemini API key not set—check /config/gemini.php");
        return ['success' => false, 'error' => 'API configuration missing.'];
    }

    $full_prompt = $prompt . "\n\nContext Data: " . json_encode($data, JSON_PRETTY_PRINT);

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $full_prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => $config['max_tokens'],
            'temperature' => $config['temperature']
        ]
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['base_url'] . '?key=' . $config['api_key'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $http_code !== 200) {
        $log_msg = "Gemini API error: HTTP $http_code, cURL: $error, Response: $response";
        error_log($log_msg);
        if ($conn && $user_id) {
            logActivity($conn, $user_id, 'Gemini API Error', $log_msg);  // Reuse our log func if available
        }
        return ['success' => false, 'error' => 'API request failed—check logs.'];
    }

    $decoded = json_decode($response, true);
    if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("Gemini invalid response: " . $response);
        return ['success' => false, 'error' => 'Invalid API response.'];
    }

    $report = $decoded['candidates'][0]['content']['parts'][0]['text'];
    return [
        'success' => true,
        'report' => $report,
        'usage' => $decoded['usageMetadata'] ?? null  // Tokens used, for monitoring
    ];
}
?>