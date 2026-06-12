<?php
/**
 * Firebase Cloud Messaging (FCM) HTTP v1 API Helper — Rahausub
 * Uses Service Account credentials — no Composer required.
 *
 * Setup:
 * 1. Upload the Firebase Service Account JSON to the server at:
 *    /home/eduowrav/firebase_service_account.json
 * 2. Both adildata and rahausub share the same Firebase project: vtu-apps-5c6af
 */

define('FIREBASE_SERVICE_ACCOUNT_PATH', '/home/eduowrav/firebase_service_account.json');
define('FIREBASE_PROJECT_ID', 'vtu-apps-5c6af');

function fcm_get_access_token() {
    $sa = json_decode(file_get_contents(FIREBASE_SERVICE_ACCOUNT_PATH), true);
    if (!$sa) return null;

    $now = time();
    $exp = $now + 3600;

    $header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $exp,
    ]));

    $toSign = "$header.$payload";
    $key    = openssl_pkey_get_private($sa['private_key']);
    openssl_sign($toSign, $signature, $key, OPENSSL_ALGO_SHA256);
    $jwt = "$toSign." . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $resp['access_token'] ?? null;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function fcm_send_to_token($fcm_token, $title, $body, $data = []) {
    $access_token = fcm_get_access_token();
    if (!$access_token) {
        return ['success' => false, 'error' => 'Could not get Firebase access token'];
    }

    $url     = 'https://fcm.googleapis.com/v1/projects/' . FIREBASE_PROJECT_ID . '/messages:send';
    $message = [
        'message' => [
            'token'        => $fcm_token,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'android' => [
                'notification' => [
                    'sound'        => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ],
            'apns' => [
                'payload' => [
                    'aps' => ['sound' => 'default'],
                ],
            ],
        ],
    ];

    if (!empty($data)) {
        $message['message']['data'] = array_map('strval', $data);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($message),
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($resp, true);
    return [
        'success'   => $httpCode === 200,
        'http_code' => $httpCode,
        'response'  => $decoded,
    ];
}

function fcm_send_to_tokens($tokens, $title, $body, $data = []) {
    $results = ['sent' => 0, 'failed' => 0, 'results' => []];
    foreach ($tokens as $token) {
        $r = fcm_send_to_token($token, $title, $body, $data);
        if ($r['success']) {
            $results['sent']++;
        } else {
            $results['failed']++;
        }
        $results['results'][] = ['token' => substr($token, 0, 20) . '...', 'result' => $r];
    }
    return $results;
}

function sendUserPushNotification($conn, $email, $title, $body, $data = []) {
    $emailSafe = mysqli_real_escape_string($conn, $email);
    $q = mysqli_query($conn, "SELECT fcm_token FROM device_tokens WHERE email='$emailSafe'");
    if (!$q || mysqli_num_rows($q) === 0) return;
    $tokens = [];
    while ($row = mysqli_fetch_assoc($q)) { $tokens[] = $row['fcm_token']; }
    fcm_send_to_tokens($tokens, $title, $body, $data);
}
