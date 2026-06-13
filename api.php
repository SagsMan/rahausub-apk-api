<?php
/**
 * Rahausub APK REST API
 * Deploy to: api.rahausub.com.ng/api.php
 * Usage:     https://api.rahausub.com.ng/api.php?action=XXX
 * Payment:   PaymentPoint (api.paymentpoint.co) — Palmpay + Opay virtual accounts
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── DB connection ─────────────────────────────────────────────────────────────
function db_connect() {
    $conn = mysqli_connect('localhost', 'eduowrav_abz', 'uCq.4WRLNOsT', 'eduowrav_rahausub');
    if (!$conn) {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }
    return $conn;
}

// ── Response helpers ──────────────────────────────────────────────────────────
function api_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

function api_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// ── Token verification ────────────────────────────────────────────────────────
function verify_token($conn, $incoming_token) {
    if (empty($incoming_token)) return null;
    $ts = mysqli_real_escape_string($conn, $incoming_token);
    // Direct token lookup (current format — plain hex token stored as-is)
    $q = mysqli_query($conn,
        "SELECT id, email, sname, oname, phone, bvn, nin, token, admin_role,
                super_admin, referal_token, acc_no, bank_name, acc_name,
                acc_no2, bank_name2, acc_name2, pin, finger, password, status
           FROM users_tbl
          WHERE token = '$ts' AND status = 1 LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) return mysqli_fetch_assoc($q);
    // Legacy bcrypt fallback (old login.php stored bcrypt hash as token)
    $q2 = mysqli_query($conn,
        "SELECT id, email, sname, oname, phone, bvn, nin, token, admin_role,
                super_admin, referal_token, acc_no, bank_name, acc_name,
                acc_no2, bank_name2, acc_name2, pin, finger, password, status
           FROM users_tbl
          WHERE token LIKE '\$2y\$%' AND status = 1
          ORDER BY id DESC LIMIT 200");
    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            if (password_verify($incoming_token, $row['token'])) return $row;
        }
    }
    return null;
}

function get_token_from_request() {
    return $_SERVER['HTTP_X_API_TOKEN']
        ?? $_GET['token']
        ?? $_POST['token']
        ?? (json_decode(@file_get_contents('php://input'), true)['token'] ?? '');
}

function require_auth($conn) {
    $token = get_token_from_request();
    if (empty($token)) api_error('Unauthorized: token required', 401);
    $user = verify_token($conn, $token);
    if (!$user) api_error('Unauthorized: invalid or expired token', 401);
    return $user;
}

// ── PaymentPoint virtual account helpers ─────────────────────────────────────
function pp_create_account($conn, $email, $name, $phone) {
    $apiSecret  = 'f243601a0abd0415faac1ba6ac78e100d831e33b9ae37b1db6163aceb30dee221eb59362b4103594cf680e96b0e6135efeb7f3e2046c001cd38fb962';
    $apiKey     = '725058f9c9f42ab1aef6c962286bd449af78c43b';
    $businessId = 'a65e1352032347a56134852409d3996e4819f891';

    // Normalise phone to exactly 11 digits
    $phoneDigits = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($phoneDigits) < 11) {
        $phoneDigits .= substr((string)random_int(100000000, 999999999), 0, 11 - strlen($phoneDigits));
    } elseif (strlen($phoneDigits) > 11) {
        $phoneDigits = substr($phoneDigits, 0, 11);
    }

    $payload = json_encode([
        'email'      => $email,
        'name'       => $name,
        'phoneNumber'=> $phoneDigits,
        'bankCode'   => ['20946', '20897'], // Palmpay + Opay
        'businessId' => $businessId,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.paymentpoint.co/api/v1/createVirtualAccount',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiSecret,
            'Content-Type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['success' => false, 'message' => 'Connection error: ' . $curlError];

    $result = json_decode($response, true);
    if (!isset($result['status']) || $result['status'] !== 'success') {
        return ['success' => false, 'message' => 'PaymentPoint error: ' . ($result['message'] ?? $response)];
    }

    $bankAccounts = $result['bankAccounts'] ?? [];
    $account1     = $bankAccounts[0] ?? null;
    $account2     = $bankAccounts[1] ?? null;

    $em     = mysqli_real_escape_string($conn, $email);
    $updates = [];
    if ($account1) {
        $updates[] = "acc_no='"    . mysqli_real_escape_string($conn, $account1['accountNumber'] ?? '') . "'";
        $updates[] = "bank_name='" . mysqli_real_escape_string($conn, $account1['bankName']      ?? '') . "'";
        $updates[] = "acc_name='"  . mysqli_real_escape_string($conn, $account1['accountName']   ?? '') . "'";
    }
    if ($account2) {
        $updates[] = "acc_no2='"    . mysqli_real_escape_string($conn, $account2['accountNumber'] ?? '') . "'";
        $updates[] = "bank_name2='" . mysqli_real_escape_string($conn, $account2['bankName']      ?? '') . "'";
        $updates[] = "acc_name2='"  . mysqli_real_escape_string($conn, $account2['accountName']   ?? '') . "'";
    }
    if ($updates) {
        mysqli_query($conn, "UPDATE users_tbl SET " . implode(', ', $updates) . " WHERE email='$em'");
    }

    return [
        'success'   => true,
        'accounts'  => $bankAccounts,
        'account1'  => $account1,
        'account2'  => $account2,
        'message'   => 'Virtual account generated',
    ];
}

function pp_get_accounts($user) {
    $accounts = [];
    if (!empty($user['acc_no'])) {
        $accounts[] = [
            'provider'       => 'PaymentPoint',
            'bank_name'      => $user['bank_name']  ?? '',
            'account_number' => $user['acc_no']     ?? '',
            'account_name'   => $user['acc_name']   ?? '',
        ];
    }
    if (!empty($user['acc_no2'])) {
        $accounts[] = [
            'provider'       => 'PaymentPoint',
            'bank_name'      => $user['bank_name2'] ?? '',
            'account_number' => $user['acc_no2']    ?? '',
            'account_name'   => $user['acc_name2']  ?? '',
        ];
    }
    return $accounts;
}

// ── Ensure required tables exist (lightweight check) ─────────────────────────
function ensure_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications_tbl (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info','success','warning','danger') DEFAULT 'info',
        target ENUM('all','specific') DEFAULT 'all',
        target_email VARCHAR(255) NULL,
        created_by VARCHAR(255) NULL,
        is_read_by LONGTEXT NULL DEFAULT '[]',
        status TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS device_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        fcm_token TEXT NOT NULL,
        platform ENUM('android','ios') DEFAULT 'android',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS referal_tbl (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referal VARCHAR(255) NOT NULL,
        referee VARCHAR(255) NOT NULL,
        date_refer TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_referal (referal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS referal_earn_transaction_tbl (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referal_email VARCHAR(255) NOT NULL,
        buyer_email VARCHAR(255) NOT NULL,
        earn_amount DECIMAL(10,2) DEFAULT 0,
        date_trans TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status TINYINT(1) DEFAULT 0,
        INDEX idx_referal_email (referal_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ─────────────────────────────────────────────────────────────────────────────
$body   = json_decode(@file_get_contents('php://input'), true) ?? [];
$action = strtolower(trim(
    $_GET['action'] ?? $_POST['action'] ?? ($body['action'] ?? '')
));
$conn = db_connect();
ensure_tables($conn);

switch ($action) {

// ── HEALTH ────────────────────────────────────────────────────────────────────
case 'health':
case 'ping':
    api_response(['message' => 'Rahausub API is running', 'version' => '1.0', 'provider' => 'PaymentPoint', 'time' => date('Y-m-d H:i:s')]);
    break;

// ── LOGIN ─────────────────────────────────────────────────────────────────────
case 'login':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('POST required', 405);
    $body     = json_decode(@file_get_contents('php://input'), true) ?? [];
    $email    = trim($body['email']    ?? $_POST['email']    ?? '');
    $password = trim($body['password'] ?? $_POST['password'] ?? '');
    if (empty($email) || empty($password)) api_error('Email and password required');

    $em = mysqli_real_escape_string($conn, $email);
    $r  = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email = '$em' AND status = 1 LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) api_error('Invalid credentials', 401);
    $user = mysqli_fetch_assoc($r);
    if (!password_verify($password, $user['password'])) api_error('Invalid credentials', 401);

    $api_token = bin2hex(random_bytes(32));
    $ts        = mysqli_real_escape_string($conn, $api_token);
    mysqli_query($conn, "UPDATE users_tbl SET token = '$ts' WHERE id = " . intval($user['id']));

    $wq  = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal = ($wq && mysqli_num_rows($wq) > 0) ? floatval(mysqli_fetch_assoc($wq)['balance']) : 0;

    $accounts = pp_get_accounts($user);
    $primary  = $accounts[0] ?? null;

    api_response([
        'token'          => $api_token,
        'id'             => $user['id'],
        'email'          => $user['email'],
        'sname'          => $user['sname'],
        'oname'          => $user['oname'],
        'phone'          => $user['phone'],
        'admin_role'     => $user['admin_role'],
        'wallet_balance' => $bal,
        'haspin'         => !empty($user['pin']),
        'finger'         => (bool)(int)($user['finger'] ?? 0),
        'has_account'    => !empty($accounts),
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
    ]);
    break;

// ── REGISTER ──────────────────────────────────────────────────────────────────
case 'register':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('POST required', 405);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $body = array_merge($_POST, $body);

    if (!empty($body['fullName']) && (empty($body['sname']) || empty($body['oname']))) {
        $nameParts     = explode(' ', trim($body['fullName']), 2);
        $body['sname'] = $nameParts[0];
        $body['oname'] = $nameParts[1] ?? '';
    }

    foreach (['email', 'password', 'sname', 'phone'] as $f) {
        if (empty(trim($body[$f] ?? ''))) api_error("$f is required");
    }

    $em = mysqli_real_escape_string($conn, trim($body['email']));
    $ex = mysqli_query($conn, "SELECT id FROM users_tbl WHERE email = '$em' LIMIT 1");
    if ($ex && mysqli_num_rows($ex) > 0) api_error('Email already registered');

    $pass  = password_hash(trim($body['password']), PASSWORD_DEFAULT);
    $sname = mysqli_real_escape_string($conn, trim($body['sname']));
    $oname = mysqli_real_escape_string($conn, trim($body['oname'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($body['phone']));
    $pin   = md5(trim($body['pin'] ?? '0000'));
    $state = mysqli_real_escape_string($conn, trim($body['state'] ?? ''));
    $ref   = md5(trim($body['email']));
    $refBy = mysqli_real_escape_string($conn, trim($body['referal'] ?? $body['join_with_referal'] ?? ''));

    $ins = mysqli_query($conn,
        "INSERT INTO users_tbl(sname,oname,password,email,phone,referal_token,pin,state)
         VALUES('$sname','$oname','$pass','$em','$phone','$ref','$pin','$state')"
    );
    if (!$ins) api_error('Registration failed: ' . mysqli_error($conn));

    // Create wallet
    mysqli_query($conn, "INSERT INTO wallet_tbl(user_id, balance, status) VALUES('$em', 0, 1)");

    // Handle referral
    if (!empty($refBy)) {
        mysqli_query($conn, "INSERT INTO referal_tbl(referal, referee) VALUES('$refBy', '$ref')");
    }

    api_response(['message' => 'Registration successful. Please submit your BVN/NIN via the KYC section to activate your virtual account.']);
    break;

// ── PROFILE ───────────────────────────────────────────────────────────────────
case 'profile':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);

    // Refresh user data
    $uq = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    if ($uq) $user = mysqli_fetch_assoc($uq) ?: $user;

    $wq  = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal = ($wq && mysqli_num_rows($wq) > 0) ? floatval(mysqli_fetch_assoc($wq)['balance']) : 0;

    $accounts = pp_get_accounts($user);
    $primary  = $accounts[0] ?? null;

    api_response([
        'id'             => $user['id'],
        'email'          => $user['email'],
        'sname'          => $user['sname'],
        'oname'          => $user['oname'],
        'phone'          => $user['phone'],
        'state'          => $user['state'] ?? '',
        'admin_role'     => $user['admin_role'] ?? 0,
        'super_admin'    => $user['super_admin'] ?? 0,
        'referral_code'  => $user['referal_token'] ?? '',
        'referral_link'  => 'https://rahausub.com.ng/easyfinder/dashboard/register?join_with_referal=' . ($user['referal_token'] ?? ''),
        'wallet_balance' => $bal,
        'has_account'    => !empty($accounts),
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'accounts'       => $accounts,
        'bvn'            => !empty($user['bvn']) ? '****' . substr($user['bvn'], -4) : null,
        'has_bvn'        => !empty($user['bvn']),
        'has_nin'        => !empty($user['nin']),
        'kyc_complete'   => (!empty($user['bvn']) || !empty($user['nin'])),
    ]);
    break;

// ── WALLET BALANCE ────────────────────────────────────────────────────────────
case 'wallet':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $wq   = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal  = ($wq && mysqli_num_rows($wq) > 0) ? floatval(mysqli_fetch_assoc($wq)['balance']) : 0;
    api_response(['balance' => $bal, 'email' => $user['email']]);
    break;

// ── WALLET HISTORY ────────────────────────────────────────────────────────────
case 'wallet_history':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $q    = mysqli_query($conn, "SELECT * FROM wallet_history_tbl WHERE email = '$em' ORDER BY id DESC LIMIT 50");
    $rows = [];
    if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    api_response(['transactions' => $rows]);
    break;

// ── TRANSACTION HISTORY ───────────────────────────────────────────────────────
case 'transactions':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $q    = mysqli_query($conn, "SELECT * FROM transactions_tbl WHERE email = '$em' ORDER BY id DESC LIMIT 50");
    $rows = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rows[] = [
                'id'         => $row['id'],
                'title'      => $row['product_name'] ?? 'Transaction',
                'phone'      => $row['phone'] ?? '-',
                'date'       => $row['transaction_date'] ?? '-',
                'subtitle'   => ($row['status'] == 1) ? 'Successful' : 'Failed / Refunded',
                'amount'     => number_format($row['amount'], 0),
                'status'     => intval($row['status']),
                'negative'   => $row['status'] == 1,
                'request_id' => $row['request_id'] ?? '',
            ];
        }
    }
    api_response(['transactions' => $rows]);
    break;

// ── DASHBOARD STATS ───────────────────────────────────────────────────────────
case 'dashboard_stats':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);

    // Refresh user for account info
    $uq = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    if ($uq) $user = mysqli_fetch_assoc($uq) ?: $user;

    $wq  = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id = '$em' LIMIT 1");
    $bal = ($wq && mysqli_num_rows($wq) > 0) ? floatval(mysqli_fetch_assoc($wq)['balance']) : 0;

    $tq = mysqli_query($conn,
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status=0 THEN 1 ELSE 0 END) as failed
         FROM transactions_tbl WHERE email='$em'"
    );
    $ts = $tq ? mysqli_fetch_assoc($tq) : ['total' => 0, 'success' => 0, 'failed' => 0];

    $nq = mysqli_query($conn,
        "SELECT COUNT(*) as cnt FROM notifications_tbl WHERE status=1 AND (target='all' OR target_email='$em')"
    );
    $nc = $nq ? intval(mysqli_fetch_assoc($nq)['cnt']) : 0;

    $rq = mysqli_query($conn,
        "SELECT COUNT(*) as cnt FROM referal_tbl WHERE referal=(SELECT referal_token FROM users_tbl WHERE email='$em' LIMIT 1)"
    );
    $rc = $rq ? intval(mysqli_fetch_assoc($rq)['cnt']) : 0;

    $accounts = pp_get_accounts($user);
    $primary  = $accounts[0] ?? null;

    api_response([
        'wallet_balance'       => $bal,
        'total_transactions'   => intval($ts['total']),
        'success_transactions' => intval($ts['success']),
        'failed_transactions'  => intval($ts['failed']),
        'notifications_count'  => $nc,
        'referral_count'       => $rc,
        'has_account'          => !empty($accounts),
        'acc_no'               => $primary['account_number'] ?? '',
        'bank_name'            => $primary['bank_name'] ?? '',
        'acc_name'             => $primary['account_name'] ?? '',
        'accounts'             => $accounts,
    ]);
    break;

// ── FUNDING ACCOUNTS ──────────────────────────────────────────────────────────
case 'funding_accounts':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);

    // Refresh user
    $uq = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    if ($uq) $user = mysqli_fetch_assoc($uq) ?: $user;

    $accounts = pp_get_accounts($user);
    $primary  = $accounts[0] ?? null;
    $hasKyc   = !empty($user['bvn']) || !empty($user['nin']);
    $needsKyc = empty($accounts) && !$hasKyc;

    api_response([
        'accounts'       => $accounts,
        'has_accounts'   => count($accounts) > 0,
        'has_account'    => count($accounts) > 0,
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'account_number' => $primary['account_number'] ?? '',
        'account_name'   => $primary['account_name'] ?? '',
        'provider'       => 'PaymentPoint',
        'needs_bvn'      => $needsKyc,
        'setup_message'  => $needsKyc
            ? 'Please submit your BVN via the KYC section to activate your virtual account.'
            : (empty($accounts) ? 'Your account is being set up. Please check back.' : ''),
    ]);
    break;

// ── GENERATE VIRTUAL ACCOUNT (PaymentPoint) ───────────────────────────────────
// Action aliases: generate_account | generate_monnify (backward compat)
case 'generate_account':
case 'generate_monnify':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);

    // Refresh user
    $uq = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    if ($uq) $user = mysqli_fetch_assoc($uq) ?: $user;

    $existingAccounts = pp_get_accounts($user);
    if (count($existingAccounts) >= 2) {
        $primary = $existingAccounts[0];
        api_response([
            'message'        => 'Account already exists',
            'accounts'       => $existingAccounts,
            'acc_no'         => $primary['account_number'],
            'bank_name'      => $primary['bank_name'],
            'acc_name'       => $primary['account_name'],
            'account_number' => $primary['account_number'],
            'account_name'   => $primary['account_name'],
        ]);
    }

    if (empty($user['bvn']) && empty($user['nin'])) {
        api_error('Please submit your BVN or NIN via the KYC section first.', 422);
    }

    $fullName = trim(($user['sname'] ?? '') . ' ' . ($user['oname'] ?? ''));
    $result   = pp_create_account($conn, $user['email'], $fullName, $user['phone'] ?? '');

    if (!$result['success']) {
        api_error($result['message'] ?? 'Account generation failed. Please try again.', 422);
    }

    // Re-fetch fresh account data
    $uq2  = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    $fresh = $uq2 ? (mysqli_fetch_assoc($uq2) ?: $user) : $user;
    $newAccounts = pp_get_accounts($fresh);
    $primary = $newAccounts[0] ?? null;

    api_response([
        'message'        => 'Virtual account generated successfully',
        'accounts'       => $newAccounts,
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'account_number' => $primary['account_number'] ?? '',
        'account_name'   => $primary['account_name'] ?? '',
    ]);
    break;

// ── VERIFY ACCOUNT STATUS ─────────────────────────────────────────────────────
case 'verify_account':
case 'verify_monnify':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $uq   = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    if ($uq) $user = mysqli_fetch_assoc($uq) ?: $user;

    $accounts = pp_get_accounts($user);
    $primary  = $accounts[0] ?? null;
    api_response([
        'has_account'    => !empty($accounts),
        'accounts'       => $accounts,
        'acc_no'         => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'account_number' => $primary['account_number'] ?? '',
        'account_name'   => $primary['account_name'] ?? '',
    ]);
    break;

// ── SUBMIT KYC ────────────────────────────────────────────────────────────────
case 'submit_kyc':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $bvn  = preg_replace('/\D/', '', trim($body['bvn'] ?? $_POST['bvn'] ?? ''));
    $nin  = preg_replace('/\D/', '', trim($body['nin'] ?? $_POST['nin'] ?? ''));

    if (empty($bvn) && empty($nin)) api_error('BVN or NIN is required');

    $em   = mysqli_real_escape_string($conn, $user['email']);
    $sets = [];

    if (!empty($bvn)) {
        if (strlen($bvn) !== 11) api_error('BVN must be exactly 11 digits');
        $bvnSafe = mysqli_real_escape_string($conn, $bvn);
        $dup = mysqli_query($conn, "SELECT id FROM users_tbl WHERE bvn='$bvnSafe' AND email != '$em' LIMIT 1");
        if ($dup && mysqli_num_rows($dup) > 0) api_error('This BVN is already linked to another account', 409);
        $sets[] = "bvn='$bvnSafe'";
    }

    if (!empty($nin)) {
        if (strlen($nin) !== 11) api_error('NIN must be exactly 11 digits');
        $ninSafe = mysqli_real_escape_string($conn, $nin);
        $dup = mysqli_query($conn, "SELECT id FROM users_tbl WHERE nin='$ninSafe' AND email != '$em' LIMIT 1");
        if ($dup && mysqli_num_rows($dup) > 0) api_error('This NIN is already linked to another account', 409);
        $sets[] = "nin='$ninSafe'";
    }

    if (empty($sets)) api_error('BVN and NIN must be 11 digits');
    mysqli_query($conn, "UPDATE users_tbl SET " . implode(', ', $sets) . " WHERE email='$em'");

    // Attempt to auto-generate virtual account via PaymentPoint
    $ppResult = null;
    $uq = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    $freshUser = $uq ? (mysqli_fetch_assoc($uq) ?: $user) : $user;

    if (empty($freshUser['acc_no'])) {
        $fullName = trim(($freshUser['sname'] ?? '') . ' ' . ($freshUser['oname'] ?? ''));
        $ppResult = pp_create_account($conn, $freshUser['email'], $fullName, $freshUser['phone'] ?? '');
    }

    // Re-fetch after account generation
    $uq2  = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    $fresh = $uq2 ? (mysqli_fetch_assoc($uq2) ?: $freshUser) : $freshUser;
    $hasBvnNow = !empty($fresh['bvn']);
    $hasNinNow = !empty($fresh['nin']);
    $accounts  = pp_get_accounts($fresh);
    $primary   = $accounts[0] ?? null;

    $responseData = [
        'message'         => 'KYC submitted successfully',
        'needs_bvn'       => !$hasBvnNow && !$hasNinNow,
        'setup_message'   => !empty($accounts) ? '' : 'Generating your virtual account, please check back shortly.',
        'account_ready'   => !empty($accounts),
        'accounts'        => $accounts,
        'acc_no'          => $primary['account_number'] ?? '',
        'bank_name'       => $primary['bank_name'] ?? '',
        'acc_name'        => $primary['account_name'] ?? '',
    ];

    if ($ppResult && $ppResult['success']) {
        $responseData['account_generated'] = true;
        $responseData['account_number']    = $primary['account_number'] ?? '';
        $responseData['account_name']      = $primary['account_name'] ?? '';
    } elseif ($ppResult && !$ppResult['success']) {
        $responseData['account_error'] = $ppResult['message'] ?? 'Account generation failed';
    }

    api_response($responseData);
    break;

// ── GET KYC STATUS ────────────────────────────────────────────────────────────
case 'get_kyc_status':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $uq   = mysqli_query($conn, "SELECT * FROM users_tbl WHERE email='$em' LIMIT 1");
    if ($uq) $user = mysqli_fetch_assoc($uq) ?: $user;

    $hasBvn   = !empty($user['bvn']);
    $hasNin   = !empty($user['nin']);
    $accounts = pp_get_accounts($user);
    $primary  = $accounts[0] ?? null;

    api_response([
        'kyc_complete'   => ($hasBvn || $hasNin),
        'has_bvn'        => $hasBvn,
        'has_nin'        => $hasNin,
        'has_account'    => !empty($accounts),
        'needs_bvn'      => !$hasBvn && !$hasNin,
        'account_ready'  => !empty($accounts),
        'account_number' => $primary['account_number'] ?? '',
        'bank_name'      => $primary['bank_name'] ?? '',
        'account_name'   => $primary['account_name'] ?? '',
        'acc_no'         => $primary['account_number'] ?? '',
        'acc_name'       => $primary['account_name'] ?? '',
        'accounts'       => $accounts,
        'setup_message'  => (!$hasBvn && !$hasNin)
            ? 'Submit your BVN or NIN to activate your virtual account.'
            : (empty($accounts) ? 'Generating your virtual account, please check back shortly.' : ''),
    ]);
    break;

// ── BUY AIRTIME ───────────────────────────────────────────────────────────────
case 'buy_airtime':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $amount  = intval($body['amount']  ?? $_POST['amount']  ?? 0);
    $number  = trim($body['number']   ?? $_POST['number']   ?? '');
    $network = trim($body['network']  ?? $_POST['network']  ?? '');
    $pin     = trim($body['pin']      ?? $_POST['pin']      ?? '');

    if (!$amount || !$number || !$network || !$pin) api_error('amount, number, network and pin are required');
    if ($pin !== 'fingerprint' && md5($pin) !== $user['pin']) api_error('Invalid PIN');

    $em = mysqli_real_escape_string($conn, $user['email']);
    $wq = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
    if (!$wq || mysqli_num_rows($wq) === 0) api_error('Wallet not found');
    $wallet = mysqli_fetch_assoc($wq);
    if ($wallet['balance'] < $amount) api_error('Insufficient balance');

    $newBalance = $wallet['balance'] - $amount;
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$em'");

    $apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass' LIMIT 1");
    if (!$apiQ || mysqli_num_rows($apiQ) === 0) {
        mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");
        api_error('Service not configured');
    }
    $api = mysqli_fetch_assoc($apiQ);

    $networkMap = ['mtn' => 'mtn', 'airtel' => 'airtel', 'glo' => 'glo', '9mobile' => 'etisalat', 'etisalat' => 'etisalat'];
    $serviceID  = $networkMap[strtolower($network)] ?? strtolower($network);
    $requestId  = uniqid('AIR_');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => rtrim($api['api_url'], '/') . '/api/pay',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['request_id' => $requestId, 'serviceID' => $serviceID . '-airtime', 'amount' => $amount, 'phone' => $number]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'api-key: ' . $api['api_key'], 'secret-key: ' . $api['secret']],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $apiResponse = curl_exec($ch);
    $curlError   = curl_error($ch);
    curl_close($ch);

    $res    = json_decode($apiResponse, true);
    $status = !$curlError && $res && strtolower($res['code'] ?? '') === '000';

    if (!$status) mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");

    $nm   = mysqli_real_escape_string($conn, $number);
    $rid  = mysqli_real_escape_string($conn, $requestId);
    $resJ = mysqli_real_escape_string($conn, json_encode($res));
    mysqli_query($conn, "INSERT INTO transactions_tbl(unique_element,amount,real_amount,email,phone,request_id,product_name,response_description,status,transaction_date,is_bill,our_commission)
        VALUES('$nm','$amount','$amount','$em','$nm','$rid','Airtime Recharge','$resJ'," . ($status ? 1 : 0) . ",NOW(),1,0)");

    api_response(['success' => $status, 'message' => $status ? 'Airtime purchased successfully' : 'Transaction failed, wallet refunded', 'balance' => $status ? $newBalance : $wallet['balance']]);
    break;

// ── BUY DATA ──────────────────────────────────────────────────────────────────
case 'buy_data':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $amount    = intval($body['amount']    ?? $_POST['amount']    ?? 0);
    $number    = trim($body['number']     ?? $_POST['number']     ?? '');
    $serviceID = trim($body['serviceID']  ?? $_POST['serviceID']  ?? '');
    $variation = trim($body['variation']  ?? $_POST['variation']  ?? '');
    $pin       = trim($body['pin']        ?? $_POST['pin']        ?? '');

    if (!$amount || !$number || !$serviceID || !$variation || !$pin) api_error('amount, number, serviceID, variation and pin are required');
    if ($pin !== 'fingerprint' && md5($pin) !== $user['pin']) api_error('Invalid PIN');

    $em = mysqli_real_escape_string($conn, $user['email']);
    $wq = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
    if (!$wq || mysqli_num_rows($wq) === 0) api_error('Wallet not found');
    $wallet = mysqli_fetch_assoc($wq);
    if ($wallet['balance'] < $amount) api_error('Insufficient balance');

    $newBalance = $wallet['balance'] - $amount;
    mysqli_query($conn, "UPDATE wallet_tbl SET balance='$newBalance' WHERE user_id='$em'");

    $apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass' LIMIT 1");
    if (!$apiQ || mysqli_num_rows($apiQ) === 0) {
        mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");
        api_error('Service not configured');
    }
    $api = mysqli_fetch_assoc($apiQ);

    $requestId = uniqid('DATA_');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => rtrim($api['api_url'], '/') . '/api/pay',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['request_id' => $requestId, 'serviceID' => strtolower($serviceID), 'billersCode' => $number, 'variation_code' => $variation, 'amount' => $amount, 'phone' => $number]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'api-key: ' . $api['api_key'], 'secret-key: ' . $api['secret']],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $apiResponse = curl_exec($ch);
    $curlError   = curl_error($ch);
    curl_close($ch);

    $res    = json_decode($apiResponse, true);
    $status = !$curlError && $res && strtolower($res['code'] ?? '') === '000';

    if (!$status) mysqli_query($conn, "UPDATE wallet_tbl SET balance='{$wallet['balance']}' WHERE user_id='$em'");

    $nm   = mysqli_real_escape_string($conn, $number);
    $rid  = mysqli_real_escape_string($conn, $requestId);
    $pn   = mysqli_real_escape_string($conn, $res['content']['transactions']['product_name'] ?? 'Data Purchase');
    $resJ = mysqli_real_escape_string($conn, json_encode($res));
    mysqli_query($conn, "INSERT INTO transactions_tbl(unique_element,amount,real_amount,email,phone,request_id,product_name,response_description,status,transaction_date,is_bill,our_commission)
        VALUES('$nm','$amount','$amount','$em','$nm','$rid','$pn','$resJ'," . ($status ? 1 : 0) . ",NOW(),1,0)");

    api_response(['success' => $status, 'message' => $status ? 'Data purchase successful' : 'Transaction failed, wallet refunded', 'balance' => $status ? $newBalance : $wallet['balance']]);
    break;

// ── DATA PLANS ────────────────────────────────────────────────────────────────
case 'data_plans':
    $serviceID = trim($_GET['serviceID'] ?? $_POST['serviceID'] ?? ($body['serviceID'] ?? ''));
    if (empty($serviceID)) api_error('serviceID required');

    $apiQ = mysqli_query($conn, "SELECT * FROM api_settings WHERE api_name='vtpass' LIMIT 1");
    $api  = $apiQ && mysqli_num_rows($apiQ) > 0 ? mysqli_fetch_assoc($apiQ) : null;
    $url  = $api
        ? rtrim($api['api_url'], '/') . '/api/service-variations?serviceID=' . urlencode(strtolower($serviceID))
        : 'https://vtpass.com/api/service-variations?serviceID=' . urlencode(strtolower($serviceID));

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
    if ($api) curl_setopt($ch, CURLOPT_HTTPHEADER, ['api-key: ' . $api['api_key'], 'secret-key: ' . $api['secret']]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data  = json_decode($resp, true);
    $plans = [];
    foreach (($data['content']['variations'] ?? []) as $p) {
        $plans[] = ['plan_id' => $p['variation_code'], 'name' => $p['name'], 'amount' => $p['variation_amount']];
    }
    api_response(['plans' => $plans]);
    break;

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────
case 'notifications':
case 'get_notifications':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);

    $nots = [];
    $rn = mysqli_query($conn,
        "SELECT id, title, message, type, target, target_email, is_read_by, created_at
         FROM notifications_tbl
         WHERE status=1 AND (target='all' OR target_email='$em')
         ORDER BY id DESC LIMIT 50");
    if ($rn) {
        while ($nrow = mysqli_fetch_assoc($rn)) {
            $readers = json_decode($nrow['is_read_by'] ?: '[]', true);
            if (!is_array($readers)) $readers = [];
            $nrow['is_read'] = in_array($user['email'], $readers);
            $nrow['read']    = $nrow['is_read'];
            unset($nrow['is_read_by']);
            $nots[] = $nrow;
        }
    }
    $unread_cnt = count(array_filter($nots, fn($n) => !$n['is_read']));
    api_response(['notifications' => $nots, 'unread_count' => $unread_cnt]);
    break;

// ── GET UNREAD COUNT ──────────────────────────────────────────────────────────
case 'get_unread_count':
    $user = require_auth($conn);
    $es   = mysqli_real_escape_string($conn, $user['email']);
    $rn   = mysqli_query($conn,
        "SELECT id, is_read_by FROM notifications_tbl WHERE status=1 AND (target='all' OR target_email='$es')");
    $unread = 0;
    if ($rn) {
        while ($nr = mysqli_fetch_assoc($rn)) {
            $rd = json_decode($nr['is_read_by'] ?: '[]', true);
            if (!is_array($rd) || !in_array($user['email'], $rd)) $unread++;
        }
    }
    api_response(['unread_count' => $unread]);
    break;

// ── MARK NOTIFICATION READ ────────────────────────────────────────────────────
case 'mark_notification_read':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $id   = intval($body['notification_id'] ?? $body['id'] ?? $_POST['notification_id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) api_error('notification_id required');
    $q = mysqli_query($conn, "SELECT is_read_by FROM notifications_tbl WHERE id=$id AND status=1 LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) api_error('Notification not found', 404);
    $row     = mysqli_fetch_assoc($q);
    $readers = json_decode($row['is_read_by'] ?: '[]', true);
    if (!is_array($readers)) $readers = [];
    if (!in_array($user['email'], $readers)) {
        $readers[] = $user['email'];
        $rj = mysqli_real_escape_string($conn, json_encode($readers));
        mysqli_query($conn, "UPDATE notifications_tbl SET is_read_by='$rj' WHERE id=$id");
    }
    api_response(['message' => 'Marked as read']);
    break;

// ── MARK ALL NOTIFICATIONS READ ───────────────────────────────────────────────
case 'mark_all_notifications_read':
    $user = require_auth($conn);
    $es   = mysqli_real_escape_string($conn, $user['email']);
    $all  = mysqli_query($conn,
        "SELECT id, is_read_by FROM notifications_tbl WHERE status=1 AND (target='all' OR target_email='$es')");
    if ($all) {
        while ($arow = mysqli_fetch_assoc($all)) {
            $readers = json_decode($arow['is_read_by'] ?: '[]', true);
            if (!is_array($readers)) $readers = [];
            if (!in_array($user['email'], $readers)) {
                $readers[] = $user['email'];
                $rj = mysqli_real_escape_string($conn, json_encode($readers));
                mysqli_query($conn, "UPDATE notifications_tbl SET is_read_by='$rj' WHERE id=" . intval($arow['id']));
            }
        }
    }
    api_response(['message' => 'All notifications marked as read']);
    break;

// ── REFERRAL ──────────────────────────────────────────────────────────────────
case 'referral':
case 'get_referral_stats':
    $user = require_auth($conn);
    $em   = mysqli_real_escape_string($conn, $user['email']);
    $rq   = mysqli_query($conn,
        "SELECT u.sname, u.oname, u.email, u.date_join
         FROM referal_tbl rt
         JOIN users_tbl u ON u.referal_token = rt.referee
         WHERE rt.referal = (SELECT referal_token FROM users_tbl WHERE email='$em' LIMIT 1)
         ORDER BY rt.id DESC");
    $referred = [];
    if ($rq) while ($r = mysqli_fetch_assoc($rq)) $referred[] = $r;
    $tq    = mysqli_query($conn, "SELECT COALESCE(SUM(earn_amount),0) as total FROM referal_earn_transaction_tbl WHERE referal_email='$em'");
    $total = $tq ? floatval(mysqli_fetch_assoc($tq)['total'] ?? 0) : 0;
    $refCode = $user['referal_token'] ?? '';
    api_response([
        'referral_code'  => $refCode,
        'referral_link'  => 'https://rahausub.com.ng/easyfinder/dashboard/register?join_with_referal=' . $refCode,
        'total_referred' => count($referred),
        'total_earnings' => $total,
        'referred_users' => $referred,
        'share_message'  => 'Join Rahausub and earn on every data, airtime purchase! Use my referral code: ' . $refCode
                            . ' — Sign up at https://rahausub.com.ng/easyfinder/dashboard/register?join_with_referal=' . $refCode,
    ]);
    break;

// ── CHANGE PASSWORD ───────────────────────────────────────────────────────────
case 'change_password':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $old  = trim($body['old_password'] ?? $_POST['old_password'] ?? '');
    $new  = trim($body['new_password'] ?? $_POST['new_password'] ?? '');
    if (empty($old) || empty($new)) api_error('old_password and new_password required');
    if (!password_verify($old, $user['password'])) api_error('Current password is incorrect');
    $hash = mysqli_real_escape_string($conn, password_hash($new, PASSWORD_DEFAULT));
    $em   = mysqli_real_escape_string($conn, $user['email']);
    mysqli_query($conn, "UPDATE users_tbl SET password='$hash' WHERE email='$em'");
    api_response(['message' => 'Password changed successfully']);
    break;

// ── CHECK FINGERPRINT ─────────────────────────────────────────────────────────
case 'check_fingerprint':
    $body  = json_decode(@file_get_contents('php://input'), true) ?? [];
    $email = trim($body['email'] ?? $_GET['email'] ?? $_POST['email'] ?? '');
    if (empty($email)) api_error('email is required');
    $em = mysqli_real_escape_string($conn, $email);
    $q  = mysqli_query($conn, "SELECT finger FROM users_tbl WHERE email='$em' AND status=1 LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) api_error('User not found', 404);
    $row = mysqli_fetch_assoc($q);
    api_response(['finger' => (bool)(int)$row['finger'], 'email' => $email]);
    break;

// ── TOGGLE FINGERPRINT ────────────────────────────────────────────────────────
case 'toggle_fingerprint':
    $user   = require_auth($conn);
    $em     = mysqli_real_escape_string($conn, $user['email']);
    $cur    = mysqli_query($conn, "SELECT finger FROM users_tbl WHERE email='$em' LIMIT 1");
    if (!$cur || mysqli_num_rows($cur) === 0) api_error('User not found', 404);
    $row    = mysqli_fetch_assoc($cur);
    $newVal = intval($row['finger']) === 1 ? 0 : 1;
    mysqli_query($conn, "UPDATE users_tbl SET finger='$newVal' WHERE email='$em'");
    api_response(['finger' => (bool)$newVal, 'message' => $newVal ? 'Fingerprint enabled' : 'Fingerprint disabled']);
    break;

// ── VERIFY TOKEN ──────────────────────────────────────────────────────────────
case 'verify_token':
    $incomingToken = get_token_from_request();
    if (empty($incomingToken)) api_error('Token is required', 400);

    $ts = mysqli_real_escape_string($conn, $incomingToken);
    $q  = mysqli_query($conn,
        "SELECT id, sname, oname, email, phone, pin, finger,
                acc_no, bank_name, acc_name, acc_no2, bank_name2, acc_name2
           FROM users_tbl
          WHERE token = '$ts' AND status = 1 LIMIT 1");

    if ($q && mysqli_num_rows($q) > 0) {
        $row      = mysqli_fetch_assoc($q);
        $em       = mysqli_real_escape_string($conn, $row['email']);
        $wq       = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
        $bal      = ($wq && mysqli_num_rows($wq) > 0) ? floatval(mysqli_fetch_assoc($wq)['balance']) : 0;
        $accounts = pp_get_accounts($row);
        $primary  = $accounts[0] ?? null;
        api_response([
            'valid'          => true,
            'user_id'        => $row['id'],
            'email'          => $row['email'],
            'name'           => trim($row['sname'] . ' ' . $row['oname']),
            'phone'          => $row['phone'],
            'haspin'         => !empty($row['pin']),
            'finger'         => (bool)(int)$row['finger'],
            'wallet_balance' => $bal,
            'has_account'    => !empty($accounts),
            'acc_no'         => $primary['account_number'] ?? '',
            'bank_name'      => $primary['bank_name'] ?? '',
            'acc_name'       => $primary['account_name'] ?? '',
            'accounts'       => $accounts,
        ]);
    }

    // Legacy bcrypt fallback
    $q2 = mysqli_query($conn,
        "SELECT id, sname, oname, email, phone, pin, finger,
                acc_no, bank_name, acc_name, acc_no2, bank_name2, acc_name2
           FROM users_tbl
          WHERE token LIKE '\$2y\$%' AND status = 1
          ORDER BY id DESC LIMIT 200");
    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            if (password_verify($incomingToken, $row['token'])) {
                mysqli_query($conn, "UPDATE users_tbl SET token='$ts' WHERE id=" . intval($row['id']));
                $em       = mysqli_real_escape_string($conn, $row['email']);
                $wq       = mysqli_query($conn, "SELECT balance FROM wallet_tbl WHERE user_id='$em' LIMIT 1");
                $bal      = ($wq && mysqli_num_rows($wq) > 0) ? floatval(mysqli_fetch_assoc($wq)['balance']) : 0;
                $accounts = pp_get_accounts($row);
                $primary  = $accounts[0] ?? null;
                api_response([
                    'valid'          => true,
                    'user_id'        => $row['id'],
                    'email'          => $row['email'],
                    'name'           => trim($row['sname'] . ' ' . $row['oname']),
                    'phone'          => $row['phone'],
                    'haspin'         => !empty($row['pin']),
                    'finger'         => (bool)(int)$row['finger'],
                    'wallet_balance' => $bal,
                    'has_account'    => !empty($accounts),
                    'acc_no'         => $primary['account_number'] ?? '',
                    'bank_name'      => $primary['bank_name'] ?? '',
                    'acc_name'       => $primary['account_name'] ?? '',
                    'accounts'       => $accounts,
                ]);
            }
        }
    }
    api_error('Invalid or expired token', 401);
    break;

// ── SET PIN ───────────────────────────────────────────────────────────────────
case 'set_pin':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $pin  = trim($body['pin'] ?? $_POST['pin'] ?? '');
    if (empty($pin)) api_error('pin is required');
    if (!preg_match('/^\d{4,6}$/', $pin)) api_error('PIN must be 4–6 digits');
    $hashedPin = md5($pin);
    $em        = mysqli_real_escape_string($conn, $user['email']);
    mysqli_query($conn, "UPDATE users_tbl SET pin='$hashedPin' WHERE email='$em'");
    api_response(['message' => 'PIN set successfully']);
    break;

// ── CHANGE PIN ────────────────────────────────────────────────────────────────
case 'change_pin':
    $user = require_auth($conn);
    $body = json_decode(@file_get_contents('php://input'), true) ?? [];
    $old  = trim($body['old_pin'] ?? $_POST['old_pin'] ?? '');
    $new  = trim($body['new_pin'] ?? $_POST['new_pin'] ?? '');
    if (empty($old) || empty($new)) api_error('old_pin and new_pin required');
    if (md5($old) !== $user['pin']) api_error('Current PIN is incorrect');
    $newPin = mysqli_real_escape_string($conn, md5($new));
    $em     = mysqli_real_escape_string($conn, $user['email']);
    mysqli_query($conn, "UPDATE users_tbl SET pin='$newPin' WHERE email='$em'");
    api_response(['message' => 'PIN changed successfully']);
    break;

// ── DEFAULT ───────────────────────────────────────────────────────────────────
default:
    api_error("Unknown action: '$action'. Available actions: health, login, register, verify_token, check_fingerprint, toggle_fingerprint, set_pin, change_pin, change_password, profile, wallet, wallet_history, transactions, dashboard_stats, funding_accounts, generate_account, verify_account, submit_kyc, get_kyc_status, buy_airtime, buy_data, data_plans, notifications, get_notifications, get_unread_count, mark_notification_read, mark_all_notifications_read, referral, get_referral_stats", 404);
}

mysqli_close($conn);
