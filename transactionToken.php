<?php

function verifyUserToken($conn, $incomingToken) {

    if (empty($incomingToken)) {
        return ["success" => false, "message" => "Token required"];
    }

    // Get users with tokens
    $query = mysqli_query($conn, "SELECT id, sname, oname, email, phone, pin, token FROM users_tbl WHERE token IS NOT NULL");

    if (!$query) {
        return ["success" => false, "message" => "DB Error: " . mysqli_error($conn)];
    }

    while ($row = mysqli_fetch_assoc($query)) {

        // Verify hashed token
        if (password_verify($incomingToken, $row['token'])) {

            return [
                "success" => true,
                "user" => [
                    "id" => $row['id'],
                    "name" => $row['sname'] . " " . $row['oname'],
                    "email" => $row['email'],
                    "phone" => $row['phone'],
                    "pin" => $row['pin'] // hashed PIN
                ]
            ];
        }
    }

    return ["success" => false, "message" => "Invalid or expired token"];
}