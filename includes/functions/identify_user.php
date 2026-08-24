<?php
// includes/functions/identify_user.php
// Looks a user up by either email or PH mobile number typed into a single
// "Email or Phone Number" login field. Phone numbers are stored in the DB
// normalized as 63 + 9 digits (no leading 0, no +) — same convention already
// used by auth/manage_phone.php — so a 09XXXXXXXXX input is converted before
// the lookup.

function findUserByIdentifier($conn, string $identifier): ?array {
    $identifier = trim($identifier);
    $digits = preg_replace('/[^0-9]/', '', $identifier);

    if (preg_match('/^09[0-9]{9}$/', $digits)) {
        $phone = '63' . substr($digits, 1);
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$identifier]);
    }

    $user = $stmt->fetch();
    return $user ?: null;
}
