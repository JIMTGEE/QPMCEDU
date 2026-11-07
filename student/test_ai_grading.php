<?php
/**
 * Test AI Grading Endpoint
 * Access this to verify the Gemini API is working
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

// Test 1: Check if API key is set
$apiKey = $apiKey = getenv('GEMINI_API_KEY');
echo "<h2>AI Grading Test</h2>";

echo "<h3>Test 1: API Key Check</h3>";
if ($apiKey) {
    echo "✅ API Key is set<br>";
} else {
    echo "❌ API Key is missing<br>";
}

// Test 2: Try the Gemini API
echo "<h3>Test 2: Gemini API Connection</h3>";

$testPrompt = [
    "contents" => [
        [
            "parts" => [
                ["text" => "Say 'Hello, this is a test' and nothing else."]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature" => 0.1,
        "maxOutputTokens" => 50
    ]
];

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-goog-api-key: ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($testPrompt),
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "❌ CURL Error: " . htmlspecialchars($curlError) . "<br>";
    echo "<p style='color:red'>This means the server cannot connect to Google's API. Check your firewall/network.</p>";
} elseif ($httpCode !== 200) {
    echo "❌ HTTP Error: $httpCode<br>";
    echo "Response: " . htmlspecialchars($response) . "<br>";
    echo "<p style='color:red'>Check if the API key is valid and has billing enabled.</p>";
} else {
    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? 'No response';
    echo "✅ API Response: " . htmlspecialchars($text) . "<br>";
    echo "<p style='color:green'>Gemini API is working correctly!</p>";
}

// Test 3: Full grading test
echo "<h3>Test 3: Full Grading Simulation</h3>";

$testAssignment = [
    'title' => 'Test Assignment',
    'description' => 'Explain photosynthesis in plants.',
    'type' => 'homework',
    'max_points' => 10
];

$testAnswer = "Photosynthesis is the process where plants use sunlight, water, and carbon dioxide to create glucose and oxygen. The chlorophyll in leaves captures sunlight energy to convert these materials into food for the plant.";

$gradingPrompt = "You are an AI grading assistant. Grade this submission.
Assignment: {$testAssignment['title']}
Max Points: {$testAssignment['max_points']}
Student Answer: {$testAnswer}

Return ONLY valid JSON:
{
    \"score\": number,
    \"feedback\": \"detailed feedback\",
    \"strengths\": \"what was good\",
    \"areas_for_improvement\": \"what to improve\"
}";

$gradingData = [
    "contents" => [
        [
            "parts" => [
                ["text" => $gradingPrompt]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature" => 0.3,
        "maxOutputTokens" => 500
    ]
];

$ch2 = curl_init($url);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-goog-api-key: ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($gradingData),
    CURLOPT_TIMEOUT => 15,
]);

$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

if ($httpCode2 === 200) {
    $decoded2 = json_decode($response2, true);
    $aiText = $decoded2['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    // Try to parse JSON
    $aiText = trim($aiText);
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $aiText, $matches)) {
        $aiText = $matches[1];
    }
    
    $gradeResult = json_decode($aiText, true);
    
    if ($gradeResult && isset($gradeResult['score'])) {
        echo "✅ Full grading works!<br>";
        echo "Score: {$gradeResult['score']}/10<br>";
        echo "Feedback: " . htmlspecialchars($gradeResult['feedback'] ?? 'N/A') . "<br>";
        echo "Strengths: " . htmlspecialchars($gradeResult['strengths'] ?? 'N/A') . "<br>";
        echo "Areas to improve: " . htmlspecialchars($gradeResult['areas_for_improvement'] ?? 'N/A') . "<br>";
    } else {
        echo "⚠️ Got response but couldn't parse JSON. Raw response:<br>";
        echo "<pre>" . htmlspecialchars($aiText) . "</pre>";
    }
} else {
    echo "❌ Grading test failed: HTTP $httpCode2<br>";
}

// Test 4: Check database for ungraded submissions
echo "<h3>Test 4: Ungraded Submissions in Database</h3>";

if (isset($conn) && $conn) {
    $sql = "SELECT COUNT(*) as count FROM assignment_submissions WHERE is_graded = 0 AND status = 'submitted'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    echo "Ungraded submissions ready for AI: {$row['count']}<br>";
    
    if ($row['count'] > 0) {
        $sql2 = "SELECT asub.id, u.name, a.title, asub.submission_date 
                 FROM assignment_submissions asub 
                 JOIN users u ON asub.user_id = u.id 
                 JOIN assignments a ON asub.assignment_id = a.id 
                 WHERE asub.is_graded = 0 AND asub.status = 'submitted' 
                 LIMIT 5";
        $result2 = $conn->query($sql2);
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse; margin-top:10px;'>";
        echo "<tr><th>Submission ID</th><th>Student</th><th>Assignment</th><th>Date</th></tr>";
        while ($row2 = $result2->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row2['id']}</td>";
            echo "<td>" . htmlspecialchars($row2['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row2['title']) . "</td>";
            echo "<td>" . htmlspecialchars($row2['submission_date']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} else {
    echo "❌ Database connection not available<br>";
}

echo "<hr>";
echo "<p><a href='/student/manage_student_assignments.php' style='color:blue'>← Go to Assignments Page</a></p>";