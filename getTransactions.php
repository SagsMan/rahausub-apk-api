<?php
header("Content-Type: application/json");
include_once 'conn.php';
require_once 'transactionToken.php';

$data = json_decode(file_get_contents("php://input"), true);
$token = $data['token'] ?? '';

if (!$token) {
    echo json_encode(["success" => false, "message" => "Token required"]);
    exit;
}

// Verify user
$verify = verifyUserToken($conn, $token);
if (!$verify['success']) {
    echo json_encode($verify);
    exit;
}

$user      = $verify['user'];
$email     = $user['email'];
$emailSafe = mysqli_real_escape_string($conn, $email);

$transactions = [];

// ── Purchase transactions ─────────────────────────────────────────────────────
$q = mysqli_query($conn, "SELECT * FROM transactions_tbl WHERE email='$emailSafe' ORDER BY id DESC LIMIT 50");
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $response    = $row['response_description'];
        $fullReceipt = null;

        if ($response) {
            $decoded = json_decode($response, true);
            if ($decoded) $fullReceipt = $decoded;
        }

        $transactions[] = [
            "id"          => "txn_" . $row['id'],
            "title"       => $row['product_name'] ?? "Transaction",
            "phone"       => $row['phone'] ?? "-",
            "date"        => $row['transaction_date'] ?? "-",
            "subtitle"    => ($row['status'] == 1) ? "Purchase Successful" : "Failed / Refunded",
            "amount"      => (($row['status'] == 1 ? "- " : "+ ") . "N" . number_format($row['amount'], 0)),
            "negative"    => $row['status'] == 1,
            "fullReceipt" => $fullReceipt,
            "_sort_ts"    => strtotime($row['transaction_date'] ?? '1970-01-01'),
        ];
    }
}

// ── Wallet funding records (actual columns: trans_id, amount, email, status, reason, date_paid) ──
$wq = mysqli_query($conn, "SELECT * FROM payment_history_tbl WHERE email='$emailSafe' ORDER BY id DESC LIMIT 50");
if ($wq) {
    while ($row = mysqli_fetch_assoc($wq)) {
        $dateStr      = $row['date_paid'] ?? date('Y-m-d H:i:s');
        $amtFormatted = "N" . number_format($row['amount'], 0);

        $transactions[] = [
            "id"          => "pay_" . $row['id'],
            "title"       => "Wallet Funding",
            "phone"       => "-",
            "date"        => $dateStr,
            "subtitle"    => "Wallet Credited Successfully",
            "amount"      => "+ " . $amtFormatted,
            "negative"    => false,
            "fullReceipt" => [
                "reference" => $row['trans_id']  ?? "",
                "reason"    => $row['reason']    ?? "",
                "amount"    => $row['amount']    ?? 0,
            ],
            "_sort_ts"    => strtotime($dateStr),
        ];
    }
}

// ── Sort newest first ─────────────────────────────────────────────────────────
usort($transactions, function($a, $b) {
    return $b['_sort_ts'] - $a['_sort_ts'];
});

// Strip internal sort key and cap list
$transactions = array_slice(
    array_map(function($t) { unset($t['_sort_ts']); return $t; }, $transactions),
    0, 80
);

echo json_encode([
    "success"      => true,
    "transactions" => $transactions
]);
?>
