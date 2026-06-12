<?php
/**
 * Rahausub APK API — Send push notification to a specific user.
 * Admin-only endpoint.
 *
 * POST body (JSON):
 * {
 *   "admin_key": "RahSubAdmin2026!",
 *   "email":     "user@example.com",
 *   "user_id":   123,
 *   "title":     "Notification title",
 *   "body":      "Notification message",
 *   "data":      { "screen": "Wallet" }
 * }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

header("Content-Type: application/json");
include_once 'conn.php';
include_once 'fcm_helper.php';

define('ADMIN_SECRET_KEY', 'RahSubAdmin2026!');

$data     = json_decode(file_get_contents("php://input"), true);
$response = ["success" => false, "message" => ""];

if (($data['admin_key'] ?? '') !== ADMIN_SECRET_KEY) {
    http_response_code(403);
    $response['message'] = "Forbidden";
    echo json_encode($response); exit;
}

$title = trim($data['title'] ?? '');
$body  = trim($data['body']  ?? '');
$extra = $data['data'] ?? [];

if (empty($title) || empty($body)) {
    $response['message'] = "title and body are required";
    echo json_encode($response); exit;
}

if (!empty($data['email'])) {
    $emailSafe = mysqli_real_escape_string($conn, $data['email']);
    $where = "email='$emailSafe'";
} elseif (!empty($data['user_id'])) {
    $uid   = intval($data['user_id']);
    $where = "user_id=$uid";
} else {
    $response['message'] = "email or user_id required";
    echo json_encode($response); exit;
}

$tokensQ = mysqli_query($conn, "SELECT fcm_token FROM device_tokens WHERE $where");
if (!$tokensQ || mysqli_num_rows($tokensQ) === 0) {
    $response['message'] = "No device tokens found for this user";
    echo json_encode($response); exit;
}

$tokens = [];
while ($row = mysqli_fetch_assoc($tokensQ)) {
    $tokens[] = $row['fcm_token'];
}

$result = fcm_send_to_tokens($tokens, $title, $body, $extra);

$response['success'] = $result['sent'] > 0;
$response['message'] = "Sent: {$result['sent']}, Failed: {$result['failed']}";
$response['detail']  = $result;
echo json_encode($response);
