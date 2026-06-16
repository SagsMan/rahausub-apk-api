<?php

function generateBankAccount($email, $name, $phone){
    include_once 'conn.php';
    global $conn;

    $apiSecret = "f243601a0abd0415faac1ba6ac78e100d831e33b9ae37b1db6163aceb30dee221eb59362b4103594cf680e96b0e6135efeb7f3e2046c001cd38fb962";
    $apiKey = "725058f9c9f42ab1aef6c962286bd449af78c43b";
    $businessId = "a65e1352032347a56134852409d3996e4819f891";

    $url = "https://api.paymentpoint.co/api/v1/createVirtualAccount";

    // Error log file
    $logFile = __DIR__ . '/bank_account_error.log';

    function logBankError($message, $data = null){
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

    if (!$conn) {
        logBankError("DB Connection failed");
        return ["success" => false, "message" => "DB Connection failed"];
    }

    $emailSafe = mysqli_real_escape_string($conn, $email);

    $check = mysqli_query($conn, "SELECT acc_no, bank_name, acc_name, acc_no2, bank_name2, acc_name2 FROM users_tbl WHERE email = '$emailSafe' LIMIT 1");

    if (!$check) {
        logBankError("User lookup query failed", mysqli_error($conn));
        return ["success" => false, "message" => "DB Query failed"];
    }

    if (mysqli_num_rows($check) < 1) {
        logBankError("User not found", $email);
        return ["success" => false, "message" => "User not found"];
    }

    $current = mysqli_fetch_array($check);

    $hasAcc1 = !empty($current['acc_no']);
    $hasAcc2 = !empty($current['acc_no2']);

    if ($hasAcc1 && $hasAcc2) {
        return ["success" => true, "message" => "already_has_two"];
    }

    // Normalize phone to 11 digits
    $phoneDigits = preg_replace('/\D+/', '', (string)$phone);

    if (strlen($phoneDigits) < 11) {
        $padLength = 11 - strlen($phoneDigits);
        $suffix = '';

        for ($i = 0; $i < $padLength; $i++) {
            $suffix .= (string)random_int(0, 9);
        }

        $phoneDigits .= $suffix;

    } elseif (strlen($phoneDigits) > 11) {
        $phoneDigits = substr($phoneDigits, 0, 11);
    }

    $data = [
        "email" => $email,
        "name" => $name,
        "phoneNumber" => $phoneDigits,
        "bankCode" => ["20946", "20897"], // Palmpay + Opay
        "businessId" => $businessId
    ];

    $headers = [
        "Authorization: Bearer $apiSecret",
        "Content-Type: application/json",
        "api-key: $apiKey"
    ];

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if (curl_errno($curl)) {

        $curlError = curl_error($curl);

        logBankError("Curl Error", [
            "error" => $curlError,
            "payload" => $data
        ]);

        curl_close($curl);

        return [
            "success" => false,
            "message" => "Curl Error: " . $curlError
        ];
    }

    curl_close($curl);

    $result = json_decode($response, true);

    // Log full API response if failed
    if (!isset($result['status']) || $result['status'] !== 'success') {

        logBankError("API Error", [
            "http_code" => $httpCode,
            "request_payload" => $data,
            "response" => $response
        ]);

        return [
            "success" => false,
            "message" => "API Error: " . $response
        ];
    }

    $bankAccounts = $result['bankAccounts'] ?? [];

    $account1 = $bankAccounts[0] ?? null;
    $account2 = $bankAccounts[1] ?? null;

    $updates = [];

    if (!$hasAcc1 && $account1) {

        $acc_no = mysqli_real_escape_string($conn, $account1['accountNumber']);
        $bank_name = mysqli_real_escape_string($conn, $account1['bankName']);
        $acc_name = mysqli_real_escape_string($conn, $account1['accountName']);

        $updates[] = "acc_no = '$acc_no'";
        $updates[] = "bank_name = '$bank_name'";
        $updates[] = "acc_name = '$acc_name'";
    }

    if (!$hasAcc2 && $account2) {

        $acc_no2 = mysqli_real_escape_string($conn, $account2['accountNumber']);
        $bank_name2 = mysqli_real_escape_string($conn, $account2['bankName']);
        $acc_name2 = mysqli_real_escape_string($conn, $account2['accountName']);

        $updates[] = "acc_no2 = '$acc_no2'";
        $updates[] = "bank_name2 = '$bank_name2'";
        $updates[] = "acc_name2 = '$acc_name2'";
    }

    if (empty($updates)) {

        logBankError("No update needed", [
            "email" => $email,
            "response" => $result
        ]);

        return [
            "success" => true,
            "message" => "no_update_needed"
        ];
    }

    $updateSql = "UPDATE users_tbl SET " . implode(", ", $updates) . " WHERE email = '$emailSafe'";

    $update = mysqli_query($conn, $updateSql);

    if ($update) {

        logBankError("Bank account created successfully", [
            "email" => $email,
            "updated_fields" => $updates
        ]);

        return [
            "success" => true,
            "message" => "updated"
        ];
    }

    logBankError("DB Update Error", [
        "mysql_error" => mysqli_error($conn),
        "query" => $updateSql
    ]);

    return [
        "success" => false,
        "message" => "DB Error: " . mysqli_error($conn)
    ];
}

?>
