<?php
ob_start();
session_start();

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Check database connection - FIXED: changed 'conn' to '$conn'
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in.";
    header("Location: /login.php");
    exit();
}
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// CSRF validation function
function validate_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Ensure Uploads directory exists
$target_dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/';
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0755, true);
}

// Check if new columns exist, if not, use only existing columns
$columns = [];
$col_check = $conn->query("SHOW COLUMNS FROM books");
if ($col_check) {
    while ($col = $col_check->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
}

// ============ READ BOOK (inline display) ============
if (isset($_GET['read']) && isset($_GET['id'])) {
    $book_id = (int)$_GET['id'];
    
    // Use appropriate columns based on what exists
    if (in_array('external_download_url', $columns)) {
        $stmt = $conn->prepare('SELECT id, title, file_url, source, external_download_url FROM books WHERE id = ?');
    } else {
        $stmt = $conn->prepare('SELECT id, title, file_url, source FROM books WHERE id = ?');
    }
    $stmt->bind_param('i', $book_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $file_url = $row['file_url'];
        $title = $row['title'];
        
        // For external sources, redirect to the source URL or use Google Docs viewer
        if (filter_var($file_url, FILTER_VALIDATE_URL)) {
            // Use Google Docs viewer for PDFs, otherwise redirect to source
            if (strpos($file_url, '.pdf') !== false) {
                $pdf_url = 'https://docs.google.com/viewer?url=' . urlencode($file_url) . '&embedded=true';
                header('Location: ' . $pdf_url);
            } else {
                // For EPUB or other formats, redirect to the source
                header('Location: ' . $file_url);
            }
        } else {
            // Local file
            $file_path = $_SERVER['DOCUMENT_ROOT'] . $file_url;
            if (file_exists($file_path) && strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'pdf') {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . urlencode($title) . '.pdf"');
                readfile($file_path);
            } else {
                echo "Book file not available for reading.";
            }
        }
    } else {
        echo "Book not found.";
    }
    exit;
}

// ============ DOWNLOAD BOOK ============
if (isset($_GET['download']) && isset($_GET['id'])) {
    $book_id = (int)$_GET['id'];
    
    // Use appropriate columns based on what exists
    if (in_array('external_download_url', $columns)) {
        $stmt = $conn->prepare('SELECT id, title, file_url, external_download_url, source FROM books WHERE id = ?');
    } else {
        $stmt = $conn->prepare('SELECT id, title, file_url, source FROM books WHERE id = ?');
    }
    $stmt->bind_param('i', $book_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $file_url = $row['file_url'];
        $title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $row['title']);
        
        // Use external download URL if available
        $download_url = (isset($row['external_download_url']) && !empty($row['external_download_url'])) ? $row['external_download_url'] : $file_url;
        
        if (filter_var($download_url, FILTER_VALIDATE_URL)) {
            // For external files, redirect to the download URL
            header('Location: ' . $download_url);
        } else {
            // Local file
            $file_path = $_SERVER['DOCUMENT_ROOT'] . $download_url;
            if (file_exists($file_path)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $title . '.pdf"');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
            } else {
                echo "File not found.";
            }
        }
    } else {
        echo "Book not found.";
    }
    exit;
}

// ============ AJAX Library Actions ============
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || !validate_csrf($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    
    $book_id = (int)($_POST['book_id'] ?? 0);
    $action = $_POST['library_action'] ?? '';
    
    if ($book_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
        exit;
    }
    
    if ($action === 'add') {
        $check = $conn->prepare("SELECT id FROM user_library WHERE user_id = ? AND book_id = ?");
        $check->bind_param("ii", $user_id, $book_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO user_library (user_id, book_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $book_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Added to library!', 'action' => 'add']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Already in library']);
        }
        $check->close();
    } elseif ($action === 'remove') {
        $stmt = $conn->prepare("DELETE FROM user_library WHERE user_id = ? AND book_id = ?");
        $stmt->bind_param("ii", $user_id, $book_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Removed from library!', 'action' => 'remove']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

// ============ AJAX Check Book Info ============
if (isset($_GET['check_book']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $book_id = (int)$_GET['id'];
    $stmt = $conn->prepare('SELECT file_url FROM books WHERE id = ?');
    $stmt->bind_param('i', $book_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['file_url' => $row['file_url']]);
    } else {
        echo json_encode(['file_url' => '']);
    }
    exit;
}

// ============ Handle Bulk African Literature Import ============
$bulk_import_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_import']) && $role === 'admin') {
    if (!isset($_POST['csrf_token']) || !validate_csrf($_POST['csrf_token'])) {
        $bulk_import_message = 'Invalid security token.';
    } else {
        $category = $_POST['category'] ?? '';
        $source = $_POST['source'] ?? '';
        $max_results = min(50, (int)($_POST['max_results'] ?? 20));
        
        if (empty($category) || empty($source)) {
            $bulk_import_message = 'Please select a category and source.';
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'QPMS-EBook-Portal/1.0 (contact@qpmsedu.com)');
            curl_setopt($ch, CURLOPT_TIMEOUT, 45);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $books_fetched = [];
            
            // African literature categories with specific search terms
            $african_categories = [
                'classic_african_novels' => ['Chinua Achebe', 'Ngũgĩ wa Thiong\'o', 'Wole Soyinka', 'Things Fall Apart', 'Weep Not Child', 'Arrow of God', 'No Longer at Ease', 'A Man of the People', 'Anthills of the Savannah', 'Petals of Blood'],
                'african_women_writers' => ['Chimamanda Ngozi Adichie', 'Buchi Emecheta', 'Mariama Bâ', 'Tsitsi Dangarembga', 'Nadine Gordimer', 'Ama Ata Aidoo', 'Grace Ogot', 'Bessie Head', 'Yvonne Vera', 'Leila Aboulela'],
                'african_history' => ['African history', 'precolonial Africa', 'African kingdoms', 'Mali Empire', 'Great Zimbabwe', 'Kingdom of Kush', 'African diaspora', 'African independence', 'Pan-Africanism', 'African resistance'],
                'african_poetry' => ['African poetry', 'Okot p\'Bitek', 'Christopher Okigbo', 'Léopold Sédar Senghor', 'Mazisi Kunene', 'Kofi Awoonor', 'Dennis Brutus', 'Jack Mapanje', 'Tchicaya U Tam\'si'],
                'south_african_literature' => ['Alan Paton', 'Nadine Gordimer', 'J.M. Coetzee', 'Cry the Beloved Country', 'July\'s People', 'Disgrace', 'Waiting for the Barbarians', 'The Conservationist'],
                'west_african_literature' => ['Ayi Kwei Armah', 'Ferdinand Oyono', 'Mongo Beti', 'Sembène Ousmane', 'The Beautyful Ones Are Not Yet Born', 'Houseboy', 'God\'s Bits of Wood'],
                'east_african_literature' => ['Abdulrazak Gurnah', 'M.G. Vassanji', 'Peter K. Palangyo', 'Bahadur Tejani', 'Paradise', 'By the Sea', 'The Gunny Sack'],
                'african_drama' => ['Wole Soyinka', 'Ama Ata Aidoo', 'Athol Fugard', 'Efua Sutherland', 'The Lion and the Jewel', 'Death and the King\'s Horseman', 'The Dilemma of a Ghost']
            ];
            
            // Get search terms based on selected category
            $search_terms = $african_categories[$category] ?? $african_categories['classic_african_novels'];
            
            switch($source) {
                case 'openlibrary':
                    foreach ($search_terms as $term) {
                        if (count($books_fetched) >= $max_results) break;
                        curl_setopt($ch, CURLOPT_URL, 'https://openlibrary.org/search.json?q=' . urlencode($term) . '&limit=10');
                        $response = curl_exec($ch);
                        if ($response) {
                            $data = json_decode($response, true);
                            if (isset($data['docs'])) {
                                foreach ($data['docs'] as $doc) {
                                    if (count($books_fetched) >= $max_results) break;
                                    if (!empty($doc['title']) && !empty($doc['cover_i'])) {
                                        $books_fetched[] = [
                                            'title' => substr($doc['title'], 0, 255),
                                            'author' => isset($doc['author_name'][0]) ? substr($doc['author_name'][0], 0, 255) : 'Unknown',
                                            'genre' => 'African Literature',
                                            'file_url' => 'https://archive.org/download/' . ($doc['identifier'][0] ?? '') . '/' . ($doc['identifier'][0] ?? '') . '.pdf',
                                            'cover_url' => 'https://covers.openlibrary.org/b/id/' . $doc['cover_i'] . '-L.jpg',
                                            'source' => 'openlibrary',
                                            'description' => 'African literary work fetched from Open Library.'
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    break;
                    
                case 'gutenberg':
                    foreach ($search_terms as $term) {
                        if (count($books_fetched) >= $max_results) break;
                        curl_setopt($ch, CURLOPT_URL, 'https://gutendex.com/books?search=' . urlencode($term) . '&languages=en');
                        $response = curl_exec($ch);
                        if ($response) {
                            $data = json_decode($response, true);
                            if (isset($data['results'])) {
                                foreach ($data['results'] as $book) {
                                    if (count($books_fetched) >= $max_results) break;
                                    $file_url = '';
                                    foreach ($book['formats'] as $format => $url) {
                                        if (strpos($format, 'pdf') !== false || strpos($format, 'epub') !== false) {
                                            $file_url = $url;
                                            break;
                                        }
                                    }
                                    if (!empty($file_url)) {
                                        $books_fetched[] = [
                                            'title' => substr($book['title'], 0, 255),
                                            'author' => isset($book['authors'][0]['name']) ? substr($book['authors'][0]['name'], 0, 255) : 'Unknown',
                                            'genre' => 'Classic African Literature',
                                            'file_url' => $file_url,
                                            'cover_url' => $book['formats']['image/jpeg'] ?? '',
                                            'source' => 'gutenberg',
                                            'description' => 'Classic African literary work from Project Gutenberg.'
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    break;
                    
                case 'africanstorybook':
                    curl_setopt($ch, CURLOPT_URL, 'https://api.africanstorybook.org/api/stories/?limit=' . $max_results);
                    $response = curl_exec($ch);
                    if ($response) {
                        $data = json_decode($response, true);
                        if (isset($data['results'])) {
                            foreach ($data['results'] as $story) {
                                if (count($books_fetched) >= $max_results) break;
                                $title = substr($story['title'] ?? 'Unknown', 0, 255);
                                $author = substr($story['author'] ?? 'African Storybook', 0, 255);
                                $file_url = $story['pdf_url'] ?? $story['download_url'] ?? '';
                                if (!empty($file_url)) {
                                    $books_fetched[] = [
                                        'title' => $title,
                                        'author' => $author,
                                        'genre' => 'African Children\'s Literature',
                                        'file_url' => $file_url,
                                        'cover_url' => $story['image_url'] ?? '',
                                        'source' => 'africanstorybook',
                                        'description' => 'African children\'s story from African Storybook Project.'
                                    ];
                                }
                            }
                        }
                    }
                    break;
                    
                case 'worlddigital':
                    foreach ($search_terms as $term) {
                        if (count($books_fetched) >= $max_results) break;
                        curl_setopt($ch, CURLOPT_URL, 'https://www.wdl.org/en/search/?q=' . urlencode($term) . '&format=json');
                        $response = curl_exec($ch);
                        if ($response) {
                            $data = json_decode($response, true);
                            if (isset($data['items'])) {
                                foreach ($data['items'] as $item) {
                                    if (count($books_fetched) >= $max_results) break;
                                    $file_url = $item['pdf_url'] ?? $item['download_url'] ?? '';
                                    if (!empty($file_url)) {
                                        $books_fetched[] = [
                                            'title' => substr($item['title'] ?? 'African Heritage', 0, 255),
                                            'author' => substr($item['creator'] ?? 'World Digital Library', 0, 255),
                                            'genre' => 'African Heritage',
                                            'file_url' => $file_url,
                                            'cover_url' => $item['thumbnail_url'] ?? '',
                                            'source' => 'worlddigital',
                                            'description' => 'African heritage document from World Digital Library.'
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    break;
            }
            
            curl_close($ch);
            
            // Insert fetched books into database
            $count = 0;
            foreach ($books_fetched as $book) {
                $check = $conn->prepare("SELECT id FROM books WHERE title = ? AND author = ?");
                $check->bind_param("ss", $book['title'], $book['author']);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    // Use appropriate insert based on available columns
                    if (in_array('external_download_url', $columns)) {
                        $stmt = $conn->prepare("INSERT INTO books (title, author, genre, description, file_url, cover_image_url, source, external_download_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssssss", $book['title'], $book['author'], $book['genre'], $book['description'], $book['file_url'], $book['cover_url'], $book['source'], $book['file_url']);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO books (title, author, genre, description, file_url, cover_image_url, source) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssssss", $book['title'], $book['author'], $book['genre'], $book['description'], $book['file_url'], $book['cover_url'], $book['source']);
                    }
                    if ($stmt->execute()) {
                        $count++;
                    }
                    $stmt->close();
                }
                $check->close();
            }
            
            $bulk_import_message = $count . ' African literature books imported successfully!';
            if ($count === 0) {
                $bulk_import_message = 'No new books found. Try a different category or source!';
            }
        }
    }
}

// ============ Handle Educational Program Import ============
$edu_import_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_educational']) && $role === 'admin') {
    if (!isset($_POST['csrf_token']) || !validate_csrf($_POST['csrf_token'])) {
        $edu_import_message = 'Invalid security token.';
    } else {
        $program_level = $_POST['program_level'] ?? '';
        $subject = $_POST['subject'] ?? '';
        
        if (empty($program_level) || empty($subject)) {
            $edu_import_message = 'Please select a program level and subject.';
        } else {
            // Educational program book mappings
            $educational_books = [
                'primary' => [
                    'mathematics' => [
                        ['title' => 'Primary Mathematics Grade 1', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/primary-mathematics-grade-1'],
                        ['title' => 'Primary Mathematics Grade 2', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/primary-mathematics-grade-2'],
                        ['title' => 'Primary Mathematics Grade 3', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/primary-mathematics-grade-3'],
                        ['title' => 'Primary Mathematics Grade 4', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/primary-mathematics-grade-4'],
                        ['title' => 'Primary Mathematics Grade 5', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/primary-mathematics-grade-5']
                    ],
                    'english' => [
                        ['title' => 'Primary English Grade 1', 'author' => 'Ministry of Education', 'genre' => 'English', 'file_url' => 'https://www.oercommons.org/courses/primary-english-grade-1'],
                        ['title' => 'Primary English Grade 2', 'author' => 'Ministry of Education', 'genre' => 'English', 'file_url' => 'https://www.oercommons.org/courses/primary-english-grade-2'],
                        ['title' => 'Primary English Grade 3', 'author' => 'Ministry of Education', 'genre' => 'English', 'file_url' => 'https://www.oercommons.org/courses/primary-english-grade-3']
                    ],
                    'science' => [
                        ['title' => 'Primary Science Grade 1', 'author' => 'Ministry of Education', 'genre' => 'Science', 'file_url' => 'https://www.oercommons.org/courses/primary-science-grade-1'],
                        ['title' => 'Primary Science Grade 2', 'author' => 'Ministry of Education', 'genre' => 'Science', 'file_url' => 'https://www.oercommons.org/courses/primary-science-grade-2'],
                        ['title' => 'Primary Science Grade 3', 'author' => 'Ministry of Education', 'genre' => 'Science', 'file_url' => 'https://www.oercommons.org/courses/primary-science-grade-3']
                    ]
                ],
                'secondary' => [
                    'mathematics' => [
                        ['title' => 'Secondary Mathematics Form 1', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/secondary-mathematics-form-1'],
                        ['title' => 'Secondary Mathematics Form 2', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/secondary-mathematics-form-2'],
                        ['title' => 'Secondary Mathematics Form 3', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/secondary-mathematics-form-3'],
                        ['title' => 'Secondary Mathematics Form 4', 'author' => 'Ministry of Education', 'genre' => 'Mathematics', 'file_url' => 'https://www.oercommons.org/courses/secondary-mathematics-form-4']
                    ],
                    'english' => [
                        ['title' => 'Secondary English Form 1', 'author' => 'Ministry of Education', 'genre' => 'English', 'file_url' => 'https://www.oercommons.org/courses/secondary-english-form-1'],
                        ['title' => 'Secondary English Form 2', 'author' => 'Ministry of Education', 'genre' => 'English', 'file_url' => 'https://www.oercommons.org/courses/secondary-english-form-2'],
                        ['title' => 'Secondary English Form 3', 'author' => 'Ministry of Education', 'genre' => 'English', 'file_url' => 'https://www.oercommons.org/courses/secondary-english-form-3']
                    ],
                    'biology' => [
                        ['title' => 'Biology Form 1', 'author' => 'Ministry of Education', 'genre' => 'Biology', 'file_url' => 'https://www.oercommons.org/courses/biology-form-1'],
                        ['title' => 'Biology Form 2', 'author' => 'Ministry of Education', 'genre' => 'Biology', 'file_url' => 'https://www.oercommons.org/courses/biology-form-2'],
                        ['title' => 'Biology Form 3', 'author' => 'Ministry of Education', 'genre' => 'Biology', 'file_url' => 'https://www.oercommons.org/courses/biology-form-3'],
                        ['title' => 'Biology Form 4', 'author' => 'Ministry of Education', 'genre' => 'Biology', 'file_url' => 'https://www.oercommons.org/courses/biology-form-4']
                    ],
                    'chemistry' => [
                        ['title' => 'Chemistry Form 1', 'author' => 'Ministry of Education', 'genre' => 'Chemistry', 'file_url' => 'https://www.oercommons.org/courses/chemistry-form-1'],
                        ['title' => 'Chemistry Form 2', 'author' => 'Ministry of Education', 'genre' => 'Chemistry', 'file_url' => 'https://www.oercommons.org/courses/chemistry-form-2'],
                        ['title' => 'Chemistry Form 3', 'author' => 'Ministry of Education', 'genre' => 'Chemistry', 'file_url' => 'https://www.oercommons.org/courses/chemistry-form-3'],
                        ['title' => 'Chemistry Form 4', 'author' => 'Ministry of Education', 'genre' => 'Chemistry', 'file_url' => 'https://www.oercommons.org/courses/chemistry-form-4']
                    ],
                    'physics' => [
                        ['title' => 'Physics Form 1', 'author' => 'Ministry of Education', 'genre' => 'Physics', 'file_url' => 'https://www.oercommons.org/courses/physics-form-1'],
                        ['title' => 'Physics Form 2', 'author' => 'Ministry of Education', 'genre' => 'Physics', 'file_url' => 'https://www.oercommons.org/courses/physics-form-2'],
                        ['title' => 'Physics Form 3', 'author' => 'Ministry of Education', 'genre' => 'Physics', 'file_url' => 'https://www.oercommons.org/courses/physics-form-3'],
                        ['title' => 'Physics Form 4', 'author' => 'Ministry of Education', 'genre' => 'Physics', 'file_url' => 'https://www.oercommons.org/courses/physics-form-4']
                    ],
                    'history' => [
                        ['title' => 'African History Form 1', 'author' => 'Ministry of Education', 'genre' => 'History', 'file_url' => 'https://www.oercommons.org/courses/african-history-form-1'],
                        ['title' => 'African History Form 2', 'author' => 'Ministry of Education', 'genre' => 'History', 'file_url' => 'https://www.oercommons.org/courses/african-history-form-2'],
                        ['title' => 'African History Form 3', 'author' => 'Ministry of Education', 'genre' => 'History', 'file_url' => 'https://www.oercommons.org/courses/african-history-form-3'],
                        ['title' => 'African History Form 4', 'author' => 'Ministry of Education', 'genre' => 'History', 'file_url' => 'https://www.oercommons.org/courses/african-history-form-4']
                    ]
                ],
                'tertiary' => [
                    'mathematics' => [
                        ['title' => 'Calculus I', 'author' => 'OpenStax', 'genre' => 'Mathematics', 'file_url' => 'https://openstax.org/details/books/calculus-volume-1'],
                        ['title' => 'Calculus II', 'author' => 'OpenStax', 'genre' => 'Mathematics', 'file_url' => 'https://openstax.org/details/books/calculus-volume-2'],
                        ['title' => 'Linear Algebra', 'author' => 'OpenStax', 'genre' => 'Mathematics', 'file_url' => 'https://openstax.org/details/books/linear-algebra'],
                        ['title' => 'Statistics', 'author' => 'OpenStax', 'genre' => 'Mathematics', 'file_url' => 'https://openstax.org/details/books/statistics']
                    ],
                    'english' => [
                        ['title' => 'College Writing', 'author' => 'OpenStax', 'genre' => 'English', 'file_url' => 'https://openstax.org/details/books/college-writing'],
                        ['title' => 'Rhetoric and Composition', 'author' => 'OpenStax', 'genre' => 'English', 'file_url' => 'https://openstax.org/details/books/rhetoric-and-composition']
                    ],
                    'biology' => [
                        ['title' => 'Biology 2e', 'author' => 'OpenStax', 'genre' => 'Biology', 'file_url' => 'https://openstax.org/details/books/biology-2e'],
                        ['title' => 'Concepts of Biology', 'author' => 'OpenStax', 'genre' => 'Biology', 'file_url' => 'https://openstax.org/details/books/concepts-biology']
                    ],
                    'chemistry' => [
                        ['title' => 'Chemistry 2e', 'author' => 'OpenStax', 'genre' => 'Chemistry', 'file_url' => 'https://openstax.org/details/books/chemistry-2e'],
                        ['title' => 'Organic Chemistry', 'author' => 'OpenStax', 'genre' => 'Chemistry', 'file_url' => 'https://openstax.org/details/books/organic-chemistry']
                    ],
                    'physics' => [
                        ['title' => 'University Physics Volume 1', 'author' => 'OpenStax', 'genre' => 'Physics', 'file_url' => 'https://openstax.org/details/books/university-physics-volume-1'],
                        ['title' => 'University Physics Volume 2', 'author' => 'OpenStax', 'genre' => 'Physics', 'file_url' => 'https://openstax.org/details/books/university-physics-volume-2'],
                        ['title' => 'University Physics Volume 3', 'author' => 'OpenStax', 'genre' => 'Physics', 'file_url' => 'https://openstax.org/details/books/university-physics-volume-3']
                    ],
                    'history' => [
                        ['title' => 'World History Volume 1', 'author' => 'OpenStax', 'genre' => 'History', 'file_url' => 'https://openstax.org/details/books/world-history-volume-1'],
                        ['title' => 'World History Volume 2', 'author' => 'OpenStax', 'genre' => 'History', 'file_url' => 'https://openstax.org/details/books/world-history-volume-2'],
                        ['title' => 'African History', 'author' => 'OpenStax', 'genre' => 'History', 'file_url' => 'https://openstax.org/details/books/african-history']
                    ]
                ]
            ];
            
            $books_to_import = $educational_books[$program_level][$subject] ?? [];
            $count = 0;
            
            foreach ($books_to_import as $book) {
                $check = $conn->prepare("SELECT id FROM books WHERE title = ? AND author = ?");
                $check->bind_param("ss", $book['title'], $book['author']);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    $description = "Educational resource for " . ucfirst($program_level) . " level " . ucfirst($subject) . " curriculum.";
                    
                    // Use appropriate insert based on available columns
                    if (in_array('educational_level', $columns) && in_array('subject', $columns)) {
                        if (in_array('external_download_url', $columns)) {
                            $stmt = $conn->prepare("INSERT INTO books (title, author, genre, description, file_url, source, external_download_url, educational_level, subject) VALUES (?, ?, ?, ?, ?, 'educational', ?, ?, ?)");
                            $stmt->bind_param("ssssssss", $book['title'], $book['author'], $book['genre'], $description, $book['file_url'], $book['file_url'], $program_level, $subject);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO books (title, author, genre, description, file_url, source, educational_level, subject) VALUES (?, ?, ?, ?, ?, 'educational', ?, ?)");
                            $stmt->bind_param("sssssss", $book['title'], $book['author'], $book['genre'], $description, $book['file_url'], $program_level, $subject);
                        }
                    } else {
                        $stmt = $conn->prepare("INSERT INTO books (title, author, genre, description, file_url, source) VALUES (?, ?, ?, ?, ?, 'educational')");
                        $stmt->bind_param("sssss", $book['title'], $book['author'], $book['genre'], $description, $book['file_url']);
                    }
                    
                    if ($stmt->execute()) {
                        $count++;
                    }
                    $stmt->close();
                }
                $check->close();
            }
            
            $edu_import_message = $count . ' educational books imported successfully for ' . ucfirst($program_level) . ' ' . ucfirst($subject) . '!';
            if ($count === 0) {
                $edu_import_message = 'No new educational books found for this selection.';
            }
        }
    }
}

// ============ Handle Book Upload (Admin only) ============
$upload_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_book']) && $role === 'admin') {
    if (!isset($_POST['csrf_token']) || !validate_csrf($_POST['csrf_token'])) {
        $upload_message = 'Invalid security token.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $genre = trim($_POST['genre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $educational_level = trim($_POST['educational_level'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        
        if (empty($title) || empty($author)) {
            $upload_message = 'Title and author are required.';
        } elseif (isset($_FILES['book_file']) && $_FILES['book_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'epub'];
            $ext = strtolower(pathinfo($_FILES['book_file']['name'], PATHINFO_EXTENSION));
            $file_size = $_FILES['book_file']['size'];
            
            if (!in_array($ext, $allowed)) {
                $upload_message = 'Only PDF and EPUB files are allowed.';
            } elseif ($file_size > 50000000) {
                $upload_message = 'File size must be less than 50MB.';
            } else {
                $safe_title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
                $file_name = time() . '_' . $safe_title . '.' . $ext;
                $target_file = $target_dir . $file_name;
                
                if (move_uploaded_file($_FILES['book_file']['tmp_name'], $target_file)) {
                    $file_url = '/Uploads/' . $file_name;
                    
                    // Handle cover image
                    $cover_url = '';
                    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                        $cover_ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                        if (in_array($cover_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                            $cover_name = time() . '_cover.' . $cover_ext;
                            $cover_target = $target_dir . $cover_name;
                            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $cover_target)) {
                                $cover_url = '/Uploads/' . $cover_name;
                            }
                        }
                    }
                    
                    // Use appropriate insert based on available columns
                    if (in_array('educational_level', $columns) && in_array('subject', $columns)) {
                        $stmt = $conn->prepare("INSERT INTO books (title, author, genre, description, file_url, cover_image_url, source, uploaded_by, educational_level, subject) VALUES (?, ?, ?, ?, ?, ?, 'local', ?, ?, ?)");
                        $stmt->bind_param("sssssssis", $title, $author, $genre, $description, $file_url, $cover_url, $user_id, $educational_level, $subject);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO books (title, author, genre, description, file_url, cover_image_url, source, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, 'local', ?)");
                        $stmt->bind_param("ssssssi", $title, $author, $genre, $description, $file_url, $cover_url, $user_id);
                    }
                    
                    if ($stmt->execute()) {
                        $upload_message = 'Book uploaded successfully!';
                    } else {
                        $upload_message = 'Database error: ' . $conn->error;
                        unlink($target_file);
                    }
                    $stmt->close();
                } else {
                    $upload_message = 'Failed to upload file.';
                }
            }
        } else {
            $upload_message = 'Please select a file to upload.';
        }
    }
}

// ============ Get Books with Pagination and Filters ============
$search = trim($_GET['search'] ?? '');
$educational_filter = trim($_GET['educational_level'] ?? '');
$genre_filter = trim($_GET['genre'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

$query_params = [];
$types = "";
$params = [];

$base_query = "SELECT * FROM books WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM books WHERE 1=1";

if (!empty($search)) {
    $base_query .= " AND (title LIKE ? OR author LIKE ? OR genre LIKE ? OR description LIKE ?)";
    $count_query .= " AND (title LIKE ? OR author LIKE ? OR genre LIKE ? OR description LIKE ?)";
    $search_param = '%' . $search . '%';
    $types .= "ssss";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if (!empty($educational_filter) && in_array('educational_level', $columns)) {
    $base_query .= " AND educational_level = ?";
    $count_query .= " AND educational_level = ?";
    $types .= "s";
    $params[] = $educational_filter;
}

if (!empty($genre_filter)) {
    $base_query .= " AND genre = ?";
    $count_query .= " AND genre = ?";
    $types .= "s";
    $params[] = $genre_filter;
}

$base_query .= " ORDER BY uploaded_at DESC LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $per_page;
$params[] = $offset;

$stmt = $conn->prepare($base_query);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total count
$count_stmt = $conn->prepare($count_query);
$count_types = substr($types, 0, -4); // Remove the LIMIT and OFFSET types
if (!empty($count_types)) {
    $count_params = array_slice($params, 0, -2);
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$pages = ceil($total / $per_page);

// Get unique genres for filter
$genre_result = $conn->query("SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");
$genres = $genre_result ? $genre_result->fetch_all(MYSQLI_ASSOC) : [];

// Get user's library
$library_stmt = $conn->prepare("SELECT book_id FROM user_library WHERE user_id = ?");
$library_stmt->bind_param("i", $user_id);
$library_stmt->execute();
$library_result = $library_stmt->get_result();
$user_library = [];
while ($row = $library_result->fetch_assoc()) {
    $user_library[] = $row['book_id'];
}
$library_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eBook Portal - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .book-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .book-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
        }
        .btn-hover {
            transition: all 0.2s ease;
        }
        .btn-hover:hover {
            transform: scale(1.05);
        }
        .btn-hover:active {
            transform: scale(0.95);
        }
        .modal-transition {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .toast {
            animation: slideIn 0.3s ease, fadeOut 0.3s ease 2.7s forwards;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut {
            to { opacity: 0; visibility: hidden; }
        }
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .line-clamp-1 {
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .tab-active {
            border-bottom: 2px solid #f97316;
            color: #f97316;
        }
    </style>
</head>
<body class="bg-gray-900">
    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 space-y-2"></div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-70 z-50 hidden items-center justify-center">
        <div class="bg-gray-800 rounded-lg p-6 flex items-center space-x-3">
            <i class="fas fa-spinner fa-spin text-orange-500 text-2xl"></i>
            <span class="text-white">Loading...</span>
        </div>
    </div>

    <!-- Header -->
    <nav class="bg-gradient-to-r from-orange-600 to-red-600 shadow-lg sticky top-0 z-30">
        <div class="container mx-auto px-4 py-4">
            <div class="flex flex-wrap justify-between items-center gap-4">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-book-open text-white text-2xl"></i>
                    <h1 class="text-white text-xl md:text-2xl font-bold">eBook Portal</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-white text-sm">
                        <i class="fas fa-user mr-2"></i><?= htmlspecialchars($_SESSION['user_role'] ?? 'User') ?>
                    </span>
                    <a href="/dashboard.php" class="text-white hover:text-gray-200 transition">
                        <i class="fas fa-arrow-left mr-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Messages -->
        <?php if ($upload_message): ?>
            <div class="bg-green-500 text-white px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-fade-in">
                <span><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($upload_message) ?></span>
                <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($bulk_import_message): ?>
            <div class="bg-green-500 text-white px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-fade-in">
                <span><i class="fas fa-download mr-2"></i><?= htmlspecialchars($bulk_import_message) ?></span>
                <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($edu_import_message): ?>
            <div class="bg-blue-500 text-white px-4 py-3 rounded-lg mb-6 flex items-center justify-between animate-fade-in">
                <span><i class="fas fa-graduation-cap mr-2"></i><?= htmlspecialchars($edu_import_message) ?></span>
                <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <div class="bg-gray-800 rounded-lg shadow-xl p-6 mb-8">
            <form method="GET" class="space-y-4">
                <div class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Search by title, author, genre, or description..." 
                                   class="w-full pl-10 pr-4 py-3 bg-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <?php if (in_array('educational_level', $columns)): ?>
                    <select name="educational_level" class="px-4 py-3 bg-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <option value="">All Educational Levels</option>
                        <option value="primary" <?= $educational_filter === 'primary' ? 'selected' : '' ?>>Primary School</option>
                        <option value="secondary" <?= $educational_filter === 'secondary' ? 'selected' : '' ?>>Secondary School</option>
                        <option value="tertiary" <?= $educational_filter === 'tertiary' ? 'selected' : '' ?>>Tertiary/University</option>
                    </select>
                    <?php endif; ?>
                    <select name="genre" class="px-4 py-3 bg-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <option value="">All Genres</option>
                        <?php foreach ($genres as $g): ?>
                            <option value="<?= htmlspecialchars($g['genre']) ?>" <?= $genre_filter === $g['genre'] ? 'selected' : '' ?>><?= htmlspecialchars($g['genre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-3 rounded-lg transition btn-hover">
                        <i class="fas fa-search mr-2"></i>Search
                    </button>
                </div>
            </form>
        </div>

        <!-- Admin Tools Tabs -->
        <?php if ($role === 'admin'): ?>
        <div class="bg-gray-800 rounded-lg shadow-xl mb-8">
            <div class="border-b border-gray-700">
                <div class="flex flex-wrap">
                    <button class="admin-tab-btn px-6 py-3 text-gray-400 hover:text-white transition" data-tab="upload">
                        <i class="fas fa-upload mr-2"></i>Upload Book
                    </button>
                    <button class="admin-tab-btn px-6 py-3 text-gray-400 hover:text-white transition" data-tab="african">
                        <i class="fas fa-africa mr-2"></i>African Literature Bulk Import
                    </button>
                    <button class="admin-tab-btn px-6 py-3 text-gray-400 hover:text-white transition" data-tab="educational">
                        <i class="fas fa-graduation-cap mr-2"></i>Educational Program Import
                    </button>
                </div>
            </div>
            
            <!-- Upload Book Tab -->
            <div id="tab-upload" class="admin-tab-content p-6 hidden">
                <h3 class="text-lg font-semibold text-white mb-4">Upload New Book</h3>
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="grid md:grid-cols-2 gap-4">
                        <input type="text" name="title" placeholder="Title" class="px-4 py-2 bg-gray-700 text-white rounded-lg" required>
                        <input type="text" name="author" placeholder="Author" class="px-4 py-2 bg-gray-700 text-white rounded-lg" required>
                        <input type="text" name="genre" placeholder="Genre" class="px-4 py-2 bg-gray-700 text-white rounded-lg">
                        <?php if (in_array('educational_level', $columns)): ?>
                        <select name="educational_level" class="px-4 py-2 bg-gray-700 text-white rounded-lg">
                            <option value="">Educational Level (Optional)</option>
                            <option value="primary">Primary School</option>
                            <option value="secondary">Secondary School</option>
                            <option value="tertiary">Tertiary/University</option>
                        </select>
                        <?php endif; ?>
                        <?php if (in_array('subject', $columns)): ?>
                        <input type="text" name="subject" placeholder="Subject (Optional)" class="px-4 py-2 bg-gray-700 text-white rounded-lg">
                        <?php endif; ?>
                    </div>
                    <textarea name="description" placeholder="Description" rows="3" class="w-full px-4 py-2 bg-gray-700 text-white rounded-lg"></textarea>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="text-gray-400 text-sm block mb-1">Book File (PDF/EPUB)</label>
                            <input type="file" name="book_file" accept=".pdf,.epub" class="w-full text-white" required>
                        </div>
                        <div>
                            <label class="text-gray-400 text-sm block mb-1">Cover Image (Optional)</label>
                            <input type="file" name="cover_image" accept="image/*" class="w-full text-white">
                        </div>
                    </div>
                    <button type="submit" name="upload_book" class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition btn-hover">
                        <i class="fas fa-upload mr-2"></i>Upload Book
                    </button>
                </form>
            </div>
            
            <!-- African Literature Bulk Import Tab -->
            <div id="tab-african" class="admin-tab-content p-6 hidden">
                <h3 class="text-lg font-semibold text-white mb-4">Bulk Import African Literature</h3>
                <p class="text-gray-400 text-sm mb-4">Import multiple African literature books at once from various sources.</p>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="grid md:grid-cols-3 gap-4">
                        <select name="category" class="px-4 py-2 bg-gray-700 text-white rounded-lg" required>
                            <option value="">Select Category</option>
                            <option value="classic_african_novels">Classic African Novels</option>
                            <option value="african_women_writers">African Women Writers</option>
                            <option value="african_history">African History</option>
                            <option value="african_poetry">African Poetry</option>
                            <option value="south_african_literature">South African Literature</option>
                            <option value="west_african_literature">West African Literature</option>
                            <option value="east_african_literature">East African Literature</option>
                            <option value="african_drama">African Drama</option>
                        </select>
                        <select name="source" class="px-4 py-2 bg-gray-700 text-white rounded-lg" required>
                            <option value="">Select Source</option>
                            <option value="openlibrary">Open Library</option>
                            <option value="gutenberg">Project Gutenberg</option>
                            <option value="africanstorybook">African Storybook Project</option>
                            <option value="worlddigital">World Digital Library</option>
                        </select>
                        <select name="max_results" class="px-4 py-2 bg-gray-700 text-white rounded-lg">
                            <option value="10">Max Results: 10</option>
                            <option value="20" selected>Max Results: 20</option>
                            <option value="30">Max Results: 30</option>
                            <option value="50">Max Results: 50</option>
                        </select>
                    </div>
                    <button type="submit" name="bulk_import" class="w-full bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition btn-hover">
                        <i class="fas fa-download mr-2"></i>Import African Literature
                    </button>
                </form>
            </div>
            
            <!-- Educational Program Import Tab -->
            <div id="tab-educational" class="admin-tab-content p-6 hidden">
                <h3 class="text-lg font-semibold text-white mb-4">Import Educational Program Books</h3>
                <p class="text-gray-400 text-sm mb-4">Import curriculum-aligned educational books for different levels and subjects.</p>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="grid md:grid-cols-2 gap-4">
                        <select name="program_level" class="px-4 py-2 bg-gray-700 text-white rounded-lg" required>
                            <option value="">Select Educational Level</option>
                            <option value="primary">Primary School</option>
                            <option value="secondary">Secondary School</option>
                            <option value="tertiary">Tertiary/University</option>
                        </select>
                        <select name="subject" class="px-4 py-2 bg-gray-700 text-white rounded-lg" required>
                            <option value="">Select Subject</option>
                            <option value="mathematics">Mathematics</option>
                            <option value="english">English</option>
                            <option value="biology">Biology</option>
                            <option value="chemistry">Chemistry</option>
                            <option value="physics">Physics</option>
                            <option value="history">History</option>
                        </select>
                    </div>
                    <button type="submit" name="import_educational" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition btn-hover">
                        <i class="fas fa-book mr-2"></i>Import Educational Books
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Books Grid -->
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-white mb-6">
                <i class="fas fa-book mr-2"></i>Available Books 
                <span class="text-sm text-gray-400">(<?= $total ?> books)</span>
            </h2>
            
            <?php if (empty($books)): ?>
                <div class="bg-gray-800 rounded-lg p-12 text-center">
                    <i class="fas fa-book-open text-gray-600 text-5xl mb-4"></i>
                    <p class="text-gray-400 text-lg">No books found. Try a different search or check back later!</p>
                    <?php if ($role === 'admin'): ?>
                        <p class="text-gray-500 mt-2">Admin: Use the tools above to import books from various sources!</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($books as $book): ?>
                    <div class="book-card bg-gray-800 rounded-lg overflow-hidden shadow-lg" data-book-id="<?= $book['id'] ?>">
                        <!-- Cover Image -->
                        <div class="h-64 overflow-hidden bg-gray-700 relative">
                            <?php if (!empty($book['cover_image_url'])): ?>
                                <img src="<?= htmlspecialchars($book['cover_image_url']) ?>" 
                                     alt="<?= htmlspecialchars($book['title']) ?>"
                                     class="w-full h-full object-cover"
                                     loading="lazy"
                                     onerror="this.src='https://placehold.co/400x500/374151/9CA3AF?text=No+Cover'">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-700 to-gray-800">
                                    <i class="fas fa-book text-gray-500 text-6xl"></i>
                                </div>
                            <?php endif; ?>
                            <!-- Badges -->
                            <div class="absolute top-2 left-2 flex gap-1 flex-wrap">
                                <span class="bg-black bg-opacity-70 text-white text-xs px-2 py-1 rounded-full">
                                    <?= ucfirst(htmlspecialchars($book['source'] ?? 'local')) ?>
                                </span>
                                <?php if (isset($book['educational_level']) && !empty($book['educational_level'])): ?>
                                    <span class="bg-blue-600 bg-opacity-90 text-white text-xs px-2 py-1 rounded-full">
                                        <i class="fas fa-graduation-cap mr-1"></i><?= ucfirst(htmlspecialchars($book['educational_level'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Book Info -->
                        <div class="p-4">
                            <h3 class="text-white font-semibold text-lg mb-1 line-clamp-1" title="<?= htmlspecialchars($book['title']) ?>"><?= htmlspecialchars($book['title']) ?></h3>
                            <p class="text-gray-400 text-sm mb-2">
                                <i class="fas fa-user mr-1"></i><?= htmlspecialchars($book['author']) ?>
                            </p>
                            <?php if (!empty($book['genre'])): ?>
                                <p class="text-gray-500 text-xs mb-3">
                                    <i class="fas fa-tag mr-1"></i><?= htmlspecialchars($book['genre']) ?>
                                </p>
                            <?php endif; ?>
                            <p class="text-gray-400 text-sm mb-4 line-clamp-2"><?= htmlspecialchars(substr($book['description'] ?? '', 0, 100)) ?></p>
                            
                            <!-- Action Buttons -->
                            <div class="flex gap-2">
                                <a href="?download=1&id=<?= $book['id'] ?>" 
                                   target="_blank"
                                   class="flex-1 bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-sm text-center transition btn-hover">
                                    <i class="fas fa-download mr-1"></i>Download
                                </a>
                                <button onclick="readBook(<?= $book['id'] ?>)" 
                                        class="flex-1 bg-purple-500 hover:bg-purple-600 text-white px-3 py-2 rounded-lg text-sm transition btn-hover">
                                    <i class="fas fa-eye mr-1"></i>Read
                                </button>
                                <button onclick="toggleLibrary(<?= $book['id'] ?>, this)" 
                                        data-in-library="<?= in_array($book['id'], $user_library) ? 'true' : 'false' ?>"
                                        class="library-btn w-10 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition btn-hover">
                                    <i class="fas <?= in_array($book['id'], $user_library) ? 'fa-check' : 'fa-bookmark' ?>"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Pagination -->
            <?php if ($pages > 1): ?>
            <div class="flex justify-center mt-8 space-x-2 flex-wrap gap-2">
                <?php if ($page > 1): ?>
                    <a href="?search=<?= urlencode($search) ?>&educational_level=<?= urlencode($educational_filter) ?>&genre=<?= urlencode($genre_filter) ?>&page=<?= $page-1 ?>" 
                       class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="?search=<?= urlencode($search) ?>&educational_level=<?= urlencode($educational_filter) ?>&genre=<?= urlencode($genre_filter) ?>&page=<?= $i ?>" 
                       class="px-4 py-2 <?= $i == $page ? 'bg-orange-500' : 'bg-gray-700 hover:bg-gray-600' ?> text-white rounded-lg transition">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $pages): ?>
                    <a href="?search=<?= urlencode($search) ?>&educational_level=<?= urlencode($educational_filter) ?>&genre=<?= urlencode($genre_filter) ?>&page=<?= $page+1 ?>" 
                       class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- PDF Modal -->
    <div id="pdf-modal" class="fixed inset-0 bg-black bg-opacity-95 z-50 hidden items-center justify-center modal-transition">
        <div class="relative w-full h-full bg-gray-900">
            <div class="absolute top-4 right-4 z-20 flex space-x-2">
                <button id="download-book" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-download mr-2"></i>Download
                </button>
                <button id="close-modal" class="bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <iframe id="pdf-iframe" class="w-full h-full" src="" frameborder="0"></iframe>
        </div>
    </div>

    <script>
        let currentBookId = null;
        
        // Utility functions
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500';
            toast.className = `${bgColor} text-white px-4 py-3 rounded-lg shadow-lg toast flex items-center space-x-2 min-w-[200px]`;
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span>${message}</span>
            `;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        function showLoading(show) {
            const overlay = document.getElementById('loading-overlay');
            if (show) {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            } else {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }
        }

        // Read book function
        function readBook(bookId) {
            const readUrl = `?read=1&id=${bookId}`;
            showLoading(true);
            
            fetch(`?check_book=1&id=${bookId}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                if (data.file_url) {
                    if (data.file_url.startsWith('/Uploads/') || data.file_url.includes(location.hostname)) {
                        const modal = document.getElementById('pdf-modal');
                        const iframe = document.getElementById('pdf-iframe');
                        const googleViewer = `https://docs.google.com/viewer?url=${encodeURIComponent(location.origin + readUrl)}&embedded=true`;
                        iframe.src = googleViewer;
                        currentBookId = bookId;
                        modal.classList.remove('hidden');
                        modal.classList.add('flex');
                    } else {
                        window.open(readUrl, '_blank');
                    }
                } else {
                    window.open(readUrl, '_blank');
                }
            })
            .catch(() => {
                showLoading(false);
                window.open(readUrl, '_blank');
            });
        }

        // Toggle library with AJAX
        async function toggleLibrary(bookId, button) {
            const isInLibrary = button.getAttribute('data-in-library') === 'true';
            const action = isInLibrary ? 'remove' : 'add';
            
            showLoading(true);
            
            try {
                const formData = new URLSearchParams();
                formData.append('csrf_token', '<?= $csrf_token ?>');
                formData.append('book_id', bookId);
                formData.append('library_action', action);
                
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (action === 'add') {
                        button.setAttribute('data-in-library', 'true');
                        button.innerHTML = '<i class="fas fa-check"></i>';
                        showToast('Added to your library!', 'success');
                    } else {
                        button.setAttribute('data-in-library', 'false');
                        button.innerHTML = '<i class="fas fa-bookmark"></i>';
                        showToast('Removed from your library!', 'success');
                    }
                } else {
                    showToast(data.message || 'Operation failed', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Network error. Please try again.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // Admin Tabs
        function initAdminTabs() {
            const tabBtns = document.querySelectorAll('.admin-tab-btn');
            const tabContents = document.querySelectorAll('.admin-tab-content');
            
            if (tabBtns.length > 0 && tabContents.length > 0) {
                tabBtns[0].classList.add('tab-active');
                tabContents[0].classList.remove('hidden');
            }
            
            tabBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const tabId = btn.getAttribute('data-tab');
                    
                    tabBtns.forEach(b => b.classList.remove('tab-active'));
                    tabContents.forEach(content => content.classList.add('hidden'));
                    
                    btn.classList.add('tab-active');
                    const targetContent = document.getElementById(`tab-${tabId}`);
                    if (targetContent) {
                        targetContent.classList.remove('hidden');
                    }
                });
            });
        }

        // Modal handling
        function initModal() {
            const closeModalBtn = document.getElementById('close-modal');
            const modal = document.getElementById('pdf-modal');
            const iframe = document.getElementById('pdf-iframe');
            
            if (closeModalBtn) {
                closeModalBtn.addEventListener('click', () => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    iframe.src = '';
                    currentBookId = null;
                });
            }
            
            if (modal) {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal && closeModalBtn) {
                        closeModalBtn.click();
                    }
                });
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal && !modal.classList.contains('hidden') && closeModalBtn) {
                    closeModalBtn.click();
                }
            });
        }

        // Download button handler
        document.getElementById('download-book')?.addEventListener('click', () => {
            if (currentBookId) {
                window.open(`?download=1&id=${currentBookId}`, '_blank');
                showToast('Download started!', 'success');
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            initAdminTabs();
            initModal();
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>