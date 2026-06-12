<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight (VERY IMPORTANT)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");
include_once 'conn.php';

// Get raw JSON input
$data = json_decode(file_get_contents("php://input"), true);

// Response array
$response = [
    "success" => false,
    "message" => "",
    "errors" => []
];

// Validate inputs
if (
    empty($data['fullName']) ||
    empty($data['email']) ||
    empty($data['phone']) ||
    empty($data['password']) ||
    empty($data['state'])
) {
    $response['message'] = "All fields are required";
    echo json_encode($response);
    exit;
}

// Split full name → sname + oname
$names = explode(" ", $data['fullName']);
$sname = $names[0];
$oname = isset($names[1]) ? $names[1] : "";

// Sanitize
$email = mysqli_real_escape_string($conn, $data['email']);
$phone = mysqli_real_escape_string($conn, $data['phone']);
$state = mysqli_real_escape_string($conn, $data['state']);

// Hash password (IMPORTANT 🔐)
$password = password_hash($data['password'], PASSWORD_DEFAULT);

// Default values
$pin = rand(1000, 9999);

// Check if email exists
$check = mysqli_query($conn, "SELECT id FROM users_tbl WHERE email='$email' OR phone='$phone'");
if (mysqli_num_rows($check) > 0) {
    $response['message'] = "User already registered";
    echo json_encode($response);
    exit;
}

// Insert user
$query = "INSERT INTO users_tbl 
(sname, oname, password, email, phone, state) 
VALUES 
('$sname', '$oname', '$password', '$email', '$phone', '$state')";

if (mysqli_query($conn, $query)) {
    $response['success'] = true;
    $response['message'] = "Registration successful";
} else {
    $response['message'] = "Database error: " . mysqli_error($conn);
}

echo json_encode($response);
?>