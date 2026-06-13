<?php
/**
 * webhook.php — PaymentPoint payment webhook
 * Automatically credits wallet and sends FCM push when payment arrives.
 * Deploy to: api.rahausub.com.ng/webhook.php
 */

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/fcm_helper.php';

header('Content-Type: application/json');

// ── Helpers ────────────────────────────────────────────────────────────────────
function wh_log($msg) {
    $ts = date('Y-m-d H:i:s');
    @file_put_contents(__DIR__ . '/webhook_log.txt', "[$ts] $msg\n", FILE_APPEND);
}

function wh_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Read payload ───────────────────────────────────────────────────────────────
$raw    = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (empty($payload)) {
    wh_log("Empty payload received");
    wh_json(['status' => 'error', 'message' => 'Invalid payload'], 400);
}

// ── Verify HMAC signature (PaymentPoint sends HMAC-SHA512) ────────────────────
$PP_SECRET  = 'f243601a0abd0415faac1ba6ac78e100d831e33b9ae37b1db6163aceb30dee221eb59362b4103594cf680e96b0e6135efeb7f3e2046c001cd38fb962';
$sigHeader  = $_SERVER['HTTP_PAYMENTPOINT_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMENTPOINT_SIGNATURE'] ?? '';
if (!empty($sigHeader)) {
    $expected = hash_hmac('sha512', $raw, $PP_SECRET);
    if (!hash_equals($expected, strtolower($sigHeader))) {
        wh_log("Signature mismatch. Got: $sigHeader");
        wh_json(['status' => 'error', 'message' => 'Invalid signature'], 401);
    }
}

wh_log("Received: " . $raw);

// ── Extract key fields (PaymentPoint event structure) ─────────────────────────
$event          = $payload['event']          ?? $payload['type']               ?? '';
$accountNumber  = $payload['accountNumber']  ?? $payload['account_number']     ?? ($payload['data']['accountNumber'] ?? '');
$amount         = floatval($payload['amount'] ?? $payload['data']['amount']    ?? 0);
$reference      = $payload['reference']      ?? $payload['transactionRef']     ?? ($payload['data']['reference'] ?? uniqid('pp_'));
$senderName     = $payload['senderName']     ?? $payload['sender_name']        ?? ($payload['data']['senderName'] ?? 'Unknown Sender');
$currency       = $payload['currency']       ?? 'NGN';

// Accept any credit/deposit event
$creditEvents = ['payment.success', 'payment.completed', 'transfer.credit', 'credit', 'deposit', 'CREDIT'];
$isCredit = empty($event) || in_array($event, $creditEvents) || stripos($event, 'credit') !== false || stripos($event, 'success') !== false;

if (!$isCredit) {
    wh_log("Non-credit event '$event' — skipped");
    wh_json(['status' => 'ok', 'message' => 'Event acknowledged (no action needed)']);
}

if ($amount <= 0 || empty($accountNumber)) {
    wh_log("Invalid amount ($amount) or accountNumber ($accountNumber)");
    wh_json(['status' => 'error', 'message' => 'Amount or account number missing'], 400);
}

// ── Find the user by virtual account number ───────────────────────────────────
$acc = mysqli_real_escape_string($conn, $accountNumber);
$uq  = mysqli_query($conn, "SELECT id, email, sname FROM users_tbl WHERE (acc_no='$acc' OR acc_no2='$acc') AND status=1 LIMIT 1");

if (!$uq || mysqli_num_rows($uq) === 0) {
    wh_log("No user found for account: $accountNumber");
    wh_json(['status' => 'error', 'message' => 'Account not found'], 404);
}
$user  = mysqli_fetch_assoc($uq);
$email = $user['email'];
$name  = trim($user['sname']);
$uid   = intval($user['id']);
$em    = mysqli_real_escape_string($conn, $email);
$ref   = mysqli_real_escape_string($conn, $reference);

// ── Duplicate check — idempotent ──────────────────────────────────────────────
$dup = mysqli_query($conn, "SELECT id FROM payment_history_tbl WHERE reference='$ref' LIMIT 1");
if ($dup && mysqli_num_rows($dup) > 0) {
    wh_log("Duplicate webhook for ref $reference — skipped");
    wh_json(['status' => 'ok', 'message' => 'Already processed']);
}

// ── Credit wallet ─────────────────────────────────────────────────────────────
$wq = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
if ($wq && mysqli_num_rows($wq) > 0) {
    $currentBal = floatval(mysqli_fetch_assoc($wq)['balance']);
    $newBal     = $currentBal + $amount;
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBal' WHERE user_id='$em'");
} else {
    $newBal = $amount;
    mysqli_query($conn, "INSERT INTO wallet_tbl(user_id, balance, status) VALUES('$em', '$newBal', 1)");
}

// ── Record transaction ─────────────────────────────────────────────────────────
$amtEsc = mysqli_real_escape_string($conn, $amount);
$senderEsc = mysqli_real_escape_string($conn, $senderName);
$acctEsc   = mysqli_real_escape_string($conn, $accountNumber);
$newBalEsc = mysqli_real_escape_string($conn, $newBal);
mysqli_query($conn,
    "INSERT INTO payment_history_tbl (user_id, reference, amount, sender_name, account_number, balance_after, status, created_at)
     VALUES ('$em', '$ref', '$amtEsc', '$senderEsc', '$acctEsc', '$newBalEsc', 'success', NOW())
     ON DUPLICATE KEY UPDATE status='success'"
);

// ── In-app notification ───────────────────────────────────────────────────────
$title   = 'Wallet Credited ✅';
$amtFmt  = number_format($amount, 2);
$balFmt  = number_format($newBal, 2);
$message = "₦{$amtFmt} has been credited to your wallet by {$senderName}. New balance: ₦{$balFmt}.";
$msgEsc  = mysqli_real_escape_string($conn, $message);
$titleEsc= mysqli_real_escape_string($conn, $title);
mysqli_query($conn,
    "INSERT INTO notifications_tbl (title, message, type, target, target_email, created_by, is_read_by, status)
     VALUES ('$titleEsc', '$msgEsc', 'success', 'specific', '$em', 'system', '[]', 1)"
);

// ── FCM push notification ─────────────────────────────────────────────────────
$tokensQ = mysqli_query($conn, "SELECT fcm_token FROM device_tokens WHERE email='$em' AND fcm_token != ''");
$fcmTokens = [];
if ($tokensQ) {
    while ($r = mysqli_fetch_assoc($tokensQ)) $fcmTokens[] = $r['fcm_token'];
}

$fcmSuccess = 0;
foreach ($fcmTokens as $fcmToken) {
    $result = send_fcm_notification(
        $fcmToken,
        $title,
        $message,
        [
            'type'       => 'wallet_credit',
            'amount'     => (string)$amount,
            'balance'    => (string)$newBal,
            'reference'  => $reference,
            'email'      => $email,
        ]
    );
    if ($result['success']) $fcmSuccess++;
}

wh_log("Credited ₦{$amount} to $email (ref: $reference) — FCM sent to $fcmSuccess/{$fcmTokens} device(s)");

wh_json([
    'status'       => 'ok',
    'message'      => 'Payment processed',
    'email'        => $email,
    'amount'       => $amount,
    'new_balance'  => $newBal,
    'fcm_sent'     => $fcmSuccess,
]);
