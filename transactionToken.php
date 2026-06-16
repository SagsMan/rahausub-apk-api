<?php

function verifyUserToken($conn, $incomingToken) {

    if (empty($incomingToken)) {
        return ["success" => false, "message" => "Token required"];
    }

    $safe = mysqli_real_escape_string($conn, $incomingToken);

    // Fast path: raw token stored directly (new login.php stores plaintext).
    // Single indexed lookup — O(1), no bcrypt overhead.
    $q = mysqli_query($conn, "SELECT id, sname, oname, email, phone, pin, token FROM users_tbl WHERE token='$safe' AND status=1 LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) {
        $row = mysqli_fetch_assoc($q);
        return [
            "success" => true,
            "user" => [
                "id"    => $row['id'],
                "name"  => $row['sname'] . " " . $row['oname'],
                "email" => $row['email'],
                "phone" => $row['phone'],
                "pin"   => $row['pin'],
            ]
        ];
    }

    // Legacy fallback: old login.php stored bcrypt hash as token.
    // Scan only rows that look like bcrypt hashes to avoid checking every user.
    $q2 = mysqli_query($conn, "SELECT id, sname, oname, email, phone, pin, token FROM users_tbl WHERE token LIKE '\$2y\$%' AND status=1 ORDER BY id DESC LIMIT 200");
    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            if (password_verify($incomingToken, $row['token'])) {
                // Upgrade stored token to raw for future fast lookups
                $raw = bin2hex(random_bytes(32));
                $rawSafe = mysqli_real_escape_string($conn, $raw);
                mysqli_query($conn, "UPDATE users_tbl SET token='$rawSafe' WHERE id=" . intval($row['id']));
                return [
                    "success" => true,
                    "user" => [
                        "id"    => $row['id'],
                        "name"  => $row['sname'] . " " . $row['oname'],
                        "email" => $row['email'],
                        "phone" => $row['phone'],
                        "pin"   => $row['pin'],
                    ]
                ];
            }
        }
    }

    return ["success" => false, "message" => "Invalid or expired token"];
}
