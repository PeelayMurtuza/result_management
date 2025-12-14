<?php
header('Content-Type: application/json');

// Include DB + Composer autoload
include 'db.php';
require 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Load ENV
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$secret_key = $_ENV['JWT_SECRET'] ?? 'my_super_secret_key';

// Validate method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

// Read JSON
$data = json_decode(file_get_contents('php://input'), true);

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
    exit;
}

// Fetch user
$stmt = $conn->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// Check password
if (!password_verify($password, $user['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Incorrect password']);
    exit;
}

// Create JWT payload
$payload = [
    'iss' => 'localhost',
    'iat' => time(),
    'exp' => time() + (60 * 60 * 24), // 24 hours expiry
    'user' => [
        'id'   => $user['id'],
        'name' => $user['name'],
        'role' => $user['role']
    ]
];

// Generate token
$jwt = JWT::encode($payload, $secret_key, 'HS256');

// Success response
echo json_encode([
    'status' => 'success',
    'message' => 'Login successful',
    'token' => $jwt,
    'user' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'role' => $user['role']
    ]
]);
?>
