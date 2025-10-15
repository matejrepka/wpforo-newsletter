<?php
// Prevent direct access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Set headers for CORS and JSON response
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Rate limiting - simple implementation
session_start();
$max_attempts = 5;
$time_window = 300; // 5 minutes

if (!isset($_SESSION['email_attempts'])) {
    $_SESSION['email_attempts'] = [];
}

// Clean old attempts
$_SESSION['email_attempts'] = array_filter($_SESSION['email_attempts'], function($timestamp) use ($time_window) {
    return (time() - $timestamp) < $time_window;
});

if (count($_SESSION['email_attempts']) >= $max_attempts) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}

// Include database configuration
require_once 'config.php';

try {
    // Create connection with error handling
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
    
    // Set charset to prevent SQL injection
    $conn->set_charset("utf8mb4");
    
    // Get and validate email
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    // Sanitize email
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }
    
    // Additional email validation
    if (strlen($email) > 255) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email address is too long.']);
        exit;
    }
    
    // Check if email already exists
    $check_stmt = $conn->prepare("SELECT id FROM waitlist WHERE email = ? LIMIT 1");
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows > 0) {
        $check_stmt->close();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
        exit;
    }
    $check_stmt->close();
    
    // Insert email into database
    $stmt = $conn->prepare("INSERT INTO waitlist (email, created_at, ip_address) VALUES (?, NOW(), ?)");
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt->bind_param("ss", $email, $ip_address);
    
    if ($stmt->execute()) {
        // Add to rate limiting
        $_SESSION['email_attempts'][] = time();
        
        http_response_code(200);
        echo json_encode([
            'success' => true, 
            'message' => 'Thank you for joining our newsletter! Check your inbox for confirmation.'
        ]);
    } else {
        throw new Exception("Failed to register email");
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    // Log error (in production, log to file instead of displaying)
    error_log("Email registration error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred. Please try again later.'
    ]);
}
?>