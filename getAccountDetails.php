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
require_once 'generateBankAccount.php';

// Log file
$logFile = __DIR__ . '/bank_account_error.log';

function apiLog($message, $data = null){
    global $logFile;

    $log = "[" . date("Y-m-d H:i:s") . "] " . $message;

    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " | " . json_encode($data, JSON_PRETTY_PRINT);
        } else {
            $log .= " | " . $data;
        }
    }

    $log .= PHP_EOL;

    file_put_contents($logFile, $log, FILE_APPEND);
}

$data = json_decode(file_get_contents("php://input"), true);

$response = ["success" => false];

apiLog("Incoming Request", $data);

// ❌ Token missing
if (empty($data['token'])) {

    apiLog("Token missing");

    $response['message'] = "Token required";

    echo json_encode($response);
    exit;
}

$incomingToken = $data['token'];

// 🔍 Verify token
$query = mysqli_query($conn, "SELECT email, sname, oname, phone, token FROM users_tbl WHERE token IS NOT NULL");

if (!$query) {

    apiLog("User token query failed", mysqli_error($conn));

    $response['message'] = "Database error";

    echo json_encode($response);
    exit;
}

$email = null;
$fullName = null;
$phone = null;

while ($row = mysqli_fetch_assoc($query)) {

    if (password_verify($incomingToken, $row['token'])) {

        $email = $row['email'];
        $fullName = trim($row['sname'] . " " . $row['oname']);
        $phone = $row['phone'];

        apiLog("Token verified", [
            "email" => $email,
            "phone" => $phone
        ]);

        break;
    }
}

// ❌ Invalid token
if (!$email) {

    apiLog("Invalid token used");

    $response['message'] = "Invalid token";

    echo json_encode($response);
    exit;
}

// 🔍 Check existing accounts (both slots)
$stmt = $conn->prepare("SELECT acc_no, bank_name, acc_name, acc_no2, bank_name2, acc_name2 FROM users_tbl WHERE email = ?");

if (!$stmt) {

    apiLog("Prepare statement failed", mysqli_error($conn));

    $response['message'] = "Database prepare error";

    echo json_encode($response);
    exit;
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$hasAcc1 = !empty($user['acc_no']);
$hasAcc2 = !empty($user['acc_no2']);

// ✅ At least one account already exists — return both
if ($hasAcc1) {

    apiLog("Account(s) already exist", ["email" => $email]);

    $response['success'] = true;

    // Primary account
    $response['account_number'] = $user['acc_no'];
    $response['bank_name']      = $user['bank_name'];
    $response['account_name']   = $user['acc_name'];

    // Secondary account (Opay / second bank)
    if ($hasAcc2) {
        $response['account_number2'] = $user['acc_no2'];
        $response['bank_name2']      = $user['bank_name2'];
        $response['account_name2']   = $user['acc_name2'];
    }

    echo json_encode($response);
    exit;
}

// 🚀 Create new virtual account(s)
apiLog("Creating virtual account", [
    "email" => $email,
    "name"  => $fullName,
    "phone" => $phone
]);

$create = generateBankAccount($email, $fullName, $phone);

// ❌ Creation failed
if (!$create['success']) {

    apiLog("Bank account creation failed", $create);

    $response['message'] = $create['message'];

    echo json_encode($response);
    exit;
}

// 🔁 Fetch again after creation
$stmt = $conn->prepare("SELECT acc_no, bank_name, acc_name, acc_no2, bank_name2, acc_name2 FROM users_tbl WHERE email = ?");

if (!$stmt) {

    apiLog("Second prepare failed", mysqli_error($conn));

    $response['message'] = "Database error";

    echo json_encode($response);
    exit;
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user || empty($user['acc_no'])) {

    apiLog("Account creation succeeded but no account found after fetch", ["email" => $email]);

    $response['message'] = "Account created but retrieval failed";

    echo json_encode($response);
    exit;
}

// ✅ Return newly created account(s)
apiLog("Account created successfully", [
    "email"          => $email,
    "account_number" => $user['acc_no'],
    "bank_name"      => $user['bank_name']
]);

$response['success']        = true;
$response['account_number'] = $user['acc_no'];
$response['bank_name']      = $user['bank_name'];
$response['account_name']   = $user['acc_name'];

if (!empty($user['acc_no2'])) {
    $response['account_number2'] = $user['acc_no2'];
    $response['bank_name2']      = $user['bank_name2'];
    $response['account_name2']   = $user['acc_name2'];
}

echo json_encode($response);
?>
