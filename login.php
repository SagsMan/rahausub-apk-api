<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include_once 'conn.php';

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

$response = [
    "success" => false,
    "message" => ""
];

// Validate
if (empty($data['email']) || empty($data['password'])) {
    $response['message'] = "Email and password required";
    echo json_encode($response);
    exit;
}

$email = mysqli_real_escape_string($conn, $data['email']);
$password = $data['password'];

// Check user
$query = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$email' LIMIT 1");

if (mysqli_num_rows($query) === 0) {
    $response['message'] = "Invalid credentials";
    echo json_encode($response);
    exit;
}

$user = mysqli_fetch_assoc($query);

// Verify password
if (!password_verify($password, $user['password'])) {
    $response['message'] = "Invalid credentials";
    echo json_encode($response);
    exit;
}

// Store raw token in DB — enables instant O(1) lookup (WHERE token=raw)
// instead of scanning all users with password_verify() (~20s with many users).
// The raw token is already cryptographically secure (32 random bytes = 256-bit).
$rawToken = bin2hex(random_bytes(32));
mysqli_query($conn, "UPDATE users_tbl SET token='$rawToken' WHERE id=" . intval($user['id']));
if (empty($user['pin'])){
    $haspin = false;
}else{
    $haspin = true;
}
$response['success'] = true;
$response['message'] = "Login successful";
$response['token'] = $rawToken;
$response['finger'] = $user['finger'];
$response['user'] = [
    "id" => $user['id'],
    "email" => $user['email'],
    "haspin" => $haspin,
    "phone" => $user['phone'],
    "name" => $user['sname'] . " " . $user['oname']
];

echo json_encode($response);
?>