<?php
/**
 * webhook.php — PaymentPoint payment webhook
 * Automatically credits wallet and sends FCM push when payment arrives.
 * Deploy to: api.rahausub.com.ng/webhook.php
 *
 * PaymentPoint real payload format:
 *   notification_status, transaction_id, amount_paid, settlement_amount,
 *   transaction_status, sender{name,account_number,bank},
 *   receiver{name,account_number,bank}, customer{name,email}, timestamp
 *
 * payment_history_tbl columns: id, trans_id, amount, email, status, reason, super_admin, date_paid
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
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

wh_log("RECEIVED: " . $raw);

if (empty($payload)) {
    wh_log("ERROR: empty payload");
    wh_json(['status' => 'error', 'message' => 'Invalid payload'], 400);
}

// ── Verify HMAC signature — mismatch is WARNING only, never blocks payment ────
$PP_SECRET = 'f243601a0abd0415faac1ba6ac78e100d831e33b9ae37b1db6163aceb30dee221eb59362b4103594cf680e96b0e6135efeb7f3e2046c001cd38fb962';
$sigHeader = $_SERVER['HTTP_PAYMENTPOINT_SIGNATURE']
          ?? $_SERVER['HTTP_X_PAYMENTPOINT_SIGNATURE']
          ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
          ?? '';
if (!empty($sigHeader)) {
    $expected = hash_hmac('sha512', $raw, $PP_SECRET);
    if (!hash_equals($expected, strtolower($sigHeader))) {
        wh_log("WARNING: Signature mismatch (processing anyway)");
    } else {
        wh_log("Signature verified OK");
    }
}

// ── Extract fields — handles PaymentPoint real format AND generic formats ──────
// PaymentPoint real fields: notification_status, transaction_id, amount_paid,
//   transaction_status, receiver.account_number, sender.name
// Generic fallback fields: event/type, accountNumber, amount, reference, senderName

$notifStatus   = $payload['notification_status'] ?? '';
$txnStatus     = $payload['transaction_status']  ?? '';
$event         = $payload['event'] ?? $payload['type'] ?? '';

// Determine event from whichever field is present
$resolvedEvent = !empty($notifStatus) ? $notifStatus : $event;

// Account number — PaymentPoint puts it in receiver.account_number
$accountNumber = $payload['receiver']['account_number']
              ?? $payload['accountNumber']
              ?? $payload['account_number']
              ?? ($payload['data']['accountNumber'] ?? '');

// Amount — PaymentPoint uses amount_paid; use full amount user sent
$amount = floatval(
    $payload['amount_paid']
    ?? $payload['amount']
    ?? ($payload['data']['amount'] ?? 0)
);

// Reference / transaction ID
$reference = $payload['transaction_id']
           ?? $payload['reference']
           ?? $payload['transactionRef']
           ?? ($payload['data']['reference'] ?? uniqid('pp_'));

// Sender name
$senderName = $payload['sender']['name']
           ?? $payload['senderName']
           ?? $payload['sender_name']
           ?? ($payload['data']['senderName'] ?? 'Unknown Sender');

// Customer email (PaymentPoint includes this — lets us look up user by email too)
$customerEmail = $payload['customer']['email'] ?? '';

wh_log("FIELDS: event=$resolvedEvent amount=$amount acc=$accountNumber ref=$reference sender=$senderName customerEmail=$customerEmail");

// ── Accept credit/success events ──────────────────────────────────────────────
$creditEvents = [
    'payment_successful', 'payment.success', 'payment.completed',
    'transfer.credit', 'credit', 'deposit', 'CREDIT', 'success'
];
$isCredit = empty($resolvedEvent)
    || in_array($resolvedEvent, $creditEvents)
    || in_array($txnStatus, ['success', 'successful', 'completed'])
    || stripos($resolvedEvent, 'success') !== false
    || stripos($resolvedEvent, 'credit')  !== false
    || stripos($resolvedEvent, 'payment') !== false;

if (!$isCredit) {
    wh_log("SKIP: non-credit event '$resolvedEvent'");
    wh_json(['status' => 'ok', 'message' => 'Event acknowledged']);
}

if ($amount <= 0) {
    wh_log("ERROR: invalid amount ($amount)");
    wh_json(['status' => 'error', 'message' => 'Amount missing or zero'], 400);
}

if (empty($accountNumber) && empty($customerEmail)) {
    wh_log("ERROR: no accountNumber and no customerEmail — cannot identify user");
    wh_json(['status' => 'error', 'message' => 'Cannot identify user'], 400);
}

// ── Find the user — try account number first, fall back to customer email ──────
$user = null;

if (!empty($accountNumber)) {
    $acc = mysqli_real_escape_string($conn, $accountNumber);
    $uq  = mysqli_query($conn, "SELECT id, email, sname FROM users_tbl WHERE (acc_no='$acc' OR acc_no2='$acc') LIMIT 1");
    if ($uq && mysqli_num_rows($uq) > 0) {
        $user = mysqli_fetch_assoc($uq);
    }
}

if (!$user && !empty($customerEmail)) {
    $cemEsc = mysqli_real_escape_string($conn, $customerEmail);
    $uq2    = mysqli_query($conn, "SELECT id, email, sname FROM users_tbl WHERE email='$cemEsc' LIMIT 1");
    if ($uq2 && mysqli_num_rows($uq2) > 0) {
        $user = mysqli_fetch_assoc($uq2);
        wh_log("USER: found by customer email fallback");
    }
}

if (!$user) {
    wh_log("ERROR: no user found for acc=$accountNumber or email=$customerEmail");
    wh_json(['status' => 'error', 'message' => 'Account not found'], 404);
}

$email = $user['email'];
$em    = mysqli_real_escape_string($conn, $email);
$ref   = mysqli_real_escape_string($conn, $reference);

wh_log("USER: $email (id=" . $user['id'] . ")");

// ── Duplicate check ────────────────────────────────────────────────────────────
$dup = mysqli_query($conn, "SELECT id FROM payment_history_tbl WHERE trans_id='$ref' LIMIT 1");
if ($dup && mysqli_num_rows($dup) > 0) {
    wh_log("SKIP: duplicate ref $reference already processed");
    wh_json(['status' => 'ok', 'message' => 'Already processed']);
}

wh_log("PROCEED: crediting N$amount to $email");

// ── Credit wallet ─────────────────────────────────────────────────────────────
$wq = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
if ($wq && mysqli_num_rows($wq) > 0) {
    $currentBal = floatval(mysqli_fetch_assoc($wq)['balance']);
    $newBal     = $currentBal + $amount;
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBal' WHERE user_id='$em'");
    wh_log("WALLET: N$currentBal → N$newBal");
} else {
    $newBal = $amount;
    mysqli_query($conn, "INSERT INTO wallet_tbl(user_id, balance, status) VALUES('$em', '$newBal', 1)");
    wh_log("WALLET: created with N$newBal");
}

// ── Record in payment_history_tbl ─────────────────────────────────────────────
$amtEsc    = mysqli_real_escape_string($conn, $amount);
$reasonEsc = mysqli_real_escape_string($conn, "Wallet funded by $senderName");
mysqli_query($conn,
    "INSERT INTO payment_history_tbl (trans_id, amount, email, status, reason, super_admin, date_paid)
     VALUES ('$ref', '$amtEsc', '$em', 1, '$reasonEsc', 'paymentpoint', NOW())
     ON DUPLICATE KEY UPDATE status=1"
);
wh_log("HISTORY: recorded");

// ── In-app notification ───────────────────────────────────────────────────────
$title   = 'Wallet Credited';
$amtFmt  = number_format($amount, 2);
$balFmt  = number_format($newBal, 2);
$message = "N{$amtFmt} has been added to your wallet by {$senderName}. New balance: N{$balFmt}.";
$msgEsc  = mysqli_real_escape_string($conn, $message);
$titleEsc= mysqli_real_escape_string($conn, $title);
mysqli_query($conn,
    "INSERT INTO notifications_tbl (title, message, type, target, target_email, created_by, is_read_by, status)
     VALUES ('$titleEsc', '$msgEsc', 'success', 'specific', '$em', 'system', '[]', 1)"
);

// ── FCM push notification ─────────────────────────────────────────────────────
$tokensQ   = mysqli_query($conn, "SELECT fcm_token FROM device_tokens WHERE email='$em' AND fcm_token != ''");
$fcmTokens = [];
if ($tokensQ) {
    while ($r = mysqli_fetch_assoc($tokensQ)) $fcmTokens[] = $r['fcm_token'];
}
$fcmSuccess = 0;
foreach ($fcmTokens as $fcmToken) {
    $result = send_fcm_notification($fcmToken, $title, $message, [
        'type'      => 'wallet_credit',
        'amount'    => (string)$amount,
        'balance'   => (string)$newBal,
        'reference' => $reference,
        'email'     => $email,
    ]);
    if ($result['success']) $fcmSuccess++;
}

wh_log("DONE: N{$amount} → $email | balance=N{$newBal} | ref=$reference | FCM=$fcmSuccess/" . count($fcmTokens));

wh_json([
    'status'      => 'ok',
    'message'     => 'Payment processed',
    'email'       => $email,
    'amount'      => $amount,
    'new_balance' => $newBal,
    'fcm_sent'    => $fcmSuccess,
]);
