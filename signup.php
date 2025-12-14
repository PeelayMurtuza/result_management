<?php
header('Content-Type: application/json');
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$name       = trim($data['name'] ?? '');
$email      = trim($data['email'] ?? '');
$password   = $data['password'] ?? '';
$role       = $data['role'] ?? '';
$username   = trim($data['username'] ?? '');
$creator_id = $data['creator_id'] ?? null;

// Validate required fields
if (!$name || !$email || !$password || !$role || !$username) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required']);
    exit;
}

/* ----------------------------------------
   COUNT USERS (FIRST USER = ADMIN ONLY)
   ---------------------------------------- */
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
$stmt->execute();
$userCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$creator = null;

if ($userCount == 0) {
    // First user MUST be admin
    if ($role !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'First user must be admin']);
        exit;
    }
    $creator_id = null;

} else {
    // Other users require creator_id
    if (!$creator_id) {
        echo json_encode(['status' => 'error', 'message' => 'creator_id required']);
        exit;
    }

    // Fetch creator
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$creator) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid creator']);
        exit;
    }

    // Role permissions
    if ($creator['role'] === 'admin') {
        // OK

    } elseif ($creator['role'] === 'teacher') {
        if ($role !== 'student') {
            echo json_encode(['status' => 'error', 'message' => 'Teacher can only create students']);
            exit;
        }

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
}

/* ----------------------------------------
   CHECK UNIQUE EMAIL + USERNAME
   ---------------------------------------- */
$stmt = $conn->prepare("SELECT * FROM users WHERE username = :u");
$stmt->execute(['u' => $username]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM users WHERE email = :e");
$stmt->execute(['e' => $email]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
    exit;
}

/* ----------------------------------------
   INSERT USER
   ---------------------------------------- */
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $conn->prepare("
    INSERT INTO users (name, email, username, password, role, creator_id)
    VALUES (:name, :email, :username, :password, :role, :creator_id)
");

$stmt->execute([
    'name'       => $name,
    'email'      => $email,
    'username'   => $username,
    'password'   => $passwordHash,
    'role'       => $role,
    'creator_id' => $creator_id
]);

$user_id = $conn->lastInsertId();

/* ----------------------------------------
   AUTO-CREATE PARENT IF TEACHER CREATES STUDENT
   ---------------------------------------- */
if ($creator && $creator['role'] === 'teacher' && $role === 'student') {

    $parentUsername = "P" . $username;
    $parentEmail    = "parent_" . $email;

    // Avoid duplicate parent username
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = :u");
    $stmt->execute(['u' => $parentUsername]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Parent username exists']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO users (name, email, username, password, role, parent_of)
        VALUES (:name, :email, :username, :password, 'parent', :parent_of)
    ");

    $stmt->execute([
        'name'      => $name . "'s Parent",
        'email'     => $parentEmail,
        'username'  => $parentUsername,
        'password'  => $passwordHash,   // same password
        'parent_of' => $user_id
    ]);

}

/* ----------------------------------------
   SUCCESS RESPONSE
   ---------------------------------------- */
echo json_encode([
    'status' => 'success',
    'message' => 'User created successfully',
    'user_id' => $user_id,
    'role'    => $role
]);
?>
