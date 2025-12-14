<?php
require "vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

/**
 * verifyToken($allowedRoles = [])
 * 
 * @param array $allowedRoles - roles allowed to access endpoint
 * @return object $decodedUser - decoded token data
 */
function verifyToken(array $allowedRoles = [])
{
    // Get request headers
    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode([
            "status" => "error",
            "message" => "Authorization header missing"
        ]);
        exit;
    }

    // Extract Bearer token
    $authHeader = trim($headers['Authorization']);
    $token = str_replace("Bearer ", "", $authHeader);

    // Get secret
    $secret = $_ENV['JWT_SECRET'] ?? "my_super_secret_key";

    try {
        // Decode JWT
        $decoded = JWT::decode($token, new Key($secret, "HS256"));
        $user = (array)$decoded->user;

        // ROLE CHECK
        if (!empty($allowedRoles)) {
            if (!isset($user['role']) || !in_array($user['role'], $allowedRoles)) {
                http_response_code(403);
                echo json_encode([
                    "status"  => "error",
                    "message" => "Access denied: Insufficient permissions"
                ]);
                exit;
            }
        }

        // Return decoded token
        return $decoded;

    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode([
            "status" => "error",
            "message" => "Invalid or expired token",
            "details" => $e->getMessage()
        ]);
        exit;
    }
}
?>
