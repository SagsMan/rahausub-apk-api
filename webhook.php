<?php

// 🔐 Your PaymentPoint secret key
$secretKey = "f243601a0abd0415faac1ba6ac78e100d831e33b9ae37b1db6163aceb30dee221eb59362b4103594cf680e96b0e6135efeb7f3e2046c001cd38fb962"; // CHANGE THIS

// 1. Get raw payload
$payload = file_get_contents("php://input");

// 2. Get signature from header
$signature = $_SERVER['HTTP_PAYMENTPOINT_SIGNATURE'] ?? '';

// 3. Generate hash
$calculatedSignature = hash_hmac('sha256', $payload, $secretKey);

// 4. Verify signature
if (!hash_equals($calculatedSignature, $signature)) {
    http_response_code(400);
    echo "Invalid signature";
    exit;
}

// 5. Decode JSON
$data = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    echo "Invalid JSON";
    exit;
}

// ✅ 6. Process only successful payments
if (
    $data['notification_status'] === 'payment_successful' &&
    $data['transaction_status'] === 'success'
) {

    $txRef = $data['transaction_id'];
    $amount = $data['amount_paid'];
    $fee = $data['settlement_fee'];

    // safer: use settlement amount directly
    $netAmount = $data['settlement_amount'];

    $customerEmail = $data['customer']['email'];

    // update balance
    updateCustomerBalance($customerEmail, $amount, $fee, $txRef, $netAmount);
}

// always return 200 so PaymentPoint doesn't retry
http_response_code(200);
echo "Webhook received";




// ================= FUNCTION =================

function updateCustomerBalance($email, $amount, $fee, $txRef, $netAmount) {
    $conn = mysqli_connect("localhost", "eduowrav_abz", "uCq.4WRLNOsT", "eduowrav_rahausub");


    if (!$conn) {
        die("DB Error");
    }

    // 🔍 get user
    $get_user = mysqli_query($conn, "SELECT * FROM wallet_tbl WHERE user_id = '$email'");
    $row = mysqli_fetch_array($get_user);

    if (!$row) return;

    $my_balance = $row['balance'];

    // ✅ use settlement amount (already deducted fee)
    $balance = $my_balance + $netAmount;

    // update balance
    mysqli_query($conn, "UPDATE wallet_tbl SET balance = '$balance' WHERE user_id = '$email'");

    // prevent duplicate transaction (VERY IMPORTANT)
    // $check = mysqli_query($conn, "SELECT id FROM history WHERE id = 'transfer/$txRef'");
    // if (mysqli_num_rows($check) > 0) {
    //     return;
    // }

    // save history
    // $id = 'transfer/' . $txRef;

    // $network_id = 'Fund';
    // $phon = 'Account Credited';
    // $type = 'Fund';
    // $statu = 'successful';
    // $size = $netAmount;
    // $response = "Payment received via PaymentPoint. Account credited successfully.";
    // $customer = $email;
    // $date = date("jS m Y h:ia");

    // mysqli_query($conn, "
    //     INSERT INTO history 
    //     (id, network_id, phone, type, status, size, response, customer, date)
    //     VALUES 
    //     ('$id', '$network_id', '$phon', '$type', '$statu', '$size', '$response', '$customer', '$date')
    // ");
}

?>