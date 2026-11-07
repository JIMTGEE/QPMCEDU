<?php
header('Content-Type: application/json');
session_start();

// Include database connection
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Verify CSRF token
$csrf_token = $_POST['csrf_token'] ?? '';
$stmt = $conn->prepare('SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()');
$stmt->bind_param('is', $_SESSION['user_id'], $csrf_token);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}
$stmt->close();

$search = filter_input(INPUT_POST, 'search', FILTER_SANITIZE_STRING) ?? '';
$search_param = '%' . $search . '%';
$response = ['suggestions' => []];

// Database search
$stmt = $conn->prepare('SELECT title, author FROM books WHERE title LIKE ? OR author LIKE ? OR genre LIKE ? LIMIT 5');
$stmt->bind_param('sss', $search_param, $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $response['suggestions'][] = ['title' => $row['title'], 'author' => $row['author']];
}
$stmt->close();

// Admin API search
if ($_SESSION['user_role'] === 'admin') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'QPMS-EBook-Portal/1.0 (contact@qpmsedu.com)');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Open Library
    curl_setopt($ch, CURLOPT_URL, 'https://openlibrary.org/search.json?q=' . urlencode($search) . '&limit=3&fields=title,author_name');
    $ol_response = curl_exec($ch);
    if ($ol_response !== false) {
        $data = json_decode($ol_response, true);
        if ($data && isset($data['docs'])) {
            foreach ($data['docs'] as $doc) {
                $response['suggestions'][] = [
                    'title' => $doc['title'] ?? 'Unknown',
                    'author' => implode(', ', $doc['author_name'] ?? ['Unknown'])
                ];
            }
        }
    }

    // Internet Archive
    curl_setopt($ch, CURLOPT_URL, 'https://archive.org/advancedsearch.php?q=' . urlencode($search) . ' AND mediatype:texts&fl[]=title,creator&format=json&rows=3');
    $ia_response = curl_exec($ch);
    if ($ia_response !== false) {
        $data = json_decode($ia_response, true);
        if ($data && isset($data['response']['docs'])) {
            foreach ($data['response']['docs'] as $doc) {
                $response['suggestions'][] = [
                    'title' => $doc['title'] ?? 'Unknown',
                    'author' => $doc['creator'] ?? 'Unknown'
                ];
            }
        }
    }
    curl_close($ch);
}

echo json_encode($response);
mysqli_close($conn);
?>