<?php
// includes/functions/paymongo_client.php
// Shared PayMongo Payment Links helper — replaces the curl boilerplate that
// used to be duplicated across process_payment_xendit.php,
// process_topup_xendit.php, and generate_receipt.php.
//
// API reference verified against the official paymongo/paymongo-php SDK
// source (docs.paymongo.com's pages were broken/restructured at the time
// this was written): Basic auth with the secret key, JSON:API request
// wrapping ({"data":{"attributes":{...}}}), amounts in integer centavos.

/**
 * Create a PayMongo Payment Link.
 *
 * @param  float  $amountPesos     Amount in PESOS (converted to centavos here —
 *                                  do NOT pre-convert at the call site).
 * @param  string $description
 * @param  string $referenceNumber Human-readable correlation id (e.g. 'INV-...').
 * @param  array  $metadata        Structured correlation data (booking_id, type, ...).
 *                                  This is the PRIMARY correlation key read back
 *                                  from webhook payloads — reference_number is a
 *                                  secondary, human-readable label only.
 * @return array  ['success'=>bool, 'id'=>string|null, 'checkout_url'=>string|null, 'message'=>string]
 */
function paymongoCreateLink($amountPesos, $description, $referenceNumber, $metadata = []) {
    $amountPesos = floatval($amountPesos);
    if ($amountPesos <= 0) {
        return ['success' => false, 'id' => null, 'checkout_url' => null, 'message' => 'Invalid amount'];
    }

    $secret_key = getenv('PAYMONGO_SECRET_KEY');
    if (!$secret_key) {
        error_log('paymongoCreateLink: PAYMONGO_SECRET_KEY is not set');
        return ['success' => false, 'id' => null, 'checkout_url' => null, 'message' => 'Payment gateway not configured'];
    }

    // PayMongo amounts are INTEGER CENTAVOS, not decimal pesos.
    $amountCentavos = (int) round($amountPesos * 100);

    $payload = [
        'data' => [
            'attributes' => [
                'amount'           => $amountCentavos,
                'currency'         => 'PHP',
                'description'      => $description,
                'reference_number' => $referenceNumber,
                'metadata'         => $metadata,
            ],
        ],
    ];

    $ch = curl_init('https://api.paymongo.com/v1/links');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secret_key . ':'),
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        error_log("paymongoCreateLink: cURL error: $curl_err");
        return ['success' => false, 'id' => null, 'checkout_url' => null, 'message' => 'Connection error to payment gateway'];
    }

    $result = json_decode($response, true);

    if ($http_code === 200 && isset($result['data']['id'], $result['data']['attributes']['checkout_url'])) {
        return [
            'success'      => true,
            'id'           => $result['data']['id'],
            'checkout_url' => $result['data']['attributes']['checkout_url'],
            'message'      => 'OK',
        ];
    }

    $err_msg = $result['errors'][0]['detail'] ?? "HTTP $http_code";
    error_log("paymongoCreateLink: API error ($http_code): " . $response);
    return ['success' => false, 'id' => null, 'checkout_url' => null, 'message' => $err_msg];
}

/**
 * Retrieve a Payment Link and its payment status. Used by the browser-
 * redirect landing pages to verify a payment server-side before crediting
 * anything — the landing page URL itself proves nothing (anyone can visit
 * it without paying), so this call is what actually confirms payment.
 *
 * @param  string $linkId
 * @return array  ['success'=>bool, 'paid'=>bool, 'status'=>string|null, 'message'=>string]
 */
function paymongoRetrieveLink($linkId) {
    $secret_key = getenv('PAYMONGO_SECRET_KEY');
    if (!$secret_key || !$linkId) {
        return ['success' => false, 'paid' => false, 'status' => null, 'message' => 'Missing gateway id or key'];
    }

    $ch = curl_init('https://api.paymongo.com/v1/links/' . urlencode($linkId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($secret_key . ':'),
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1');

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("paymongoRetrieveLink: HTTP $http_code for link $linkId: $response");
        return ['success' => false, 'paid' => false, 'status' => null, 'message' => "HTTP $http_code"];
    }

    $result = json_decode($response, true);
    $status = $result['data']['attributes']['status'] ?? null;

    // A Link's own status becomes 'paid' once fully paid; belt-and-suspenders,
    // also treat a non-empty payments[] with any 'paid' entry as paid.
    $paid = ($status === 'paid');
    if (!$paid && !empty($result['data']['attributes']['payments'])) {
        foreach ($result['data']['attributes']['payments'] as $p) {
            if (($p['attributes']['status'] ?? '') === 'paid') { $paid = true; break; }
        }
    }

    return ['success' => true, 'paid' => $paid, 'status' => $status, 'message' => 'OK'];
}

/**
 * Verify the Paymongo-Signature webhook header and return the decoded event
 * on success. Algorithm pulled verbatim from the official SDK's
 * WebhookService::constructEvent(): header is "t=<ts>,te=<test_sig>,li=<live_sig>";
 * the signed string is "{timestamp}.{raw_payload}"; compare against te (test
 * mode) or li (live mode), whichever is present.
 *
 * @param  string $rawPayload   The raw (unparsed) request body — must be the
 *                                exact bytes PayMongo signed, so read this
 *                                BEFORE any JSON decoding.
 * @param  string $signatureHeader  The Paymongo-Signature header value.
 * @param  string $webhookSecret
 * @return array|null  Decoded payload as an array on success, null on failure.
 */
function paymongoVerifyWebhookSignature($rawPayload, $signatureHeader, $webhookSecret) {
    if (!is_string($signatureHeader) || $signatureHeader === '') {
        error_log('paymongoVerifyWebhookSignature: missing signature header');
        return null;
    }

    $parts = explode(',', $signatureHeader);
    if (count($parts) < 3) {
        error_log('paymongoVerifyWebhookSignature: malformed signature header');
        return null;
    }

    $timestamp          = explode('=', $parts[0])[1] ?? '';
    $testModeSignature   = explode('=', $parts[1])[1] ?? '';
    $liveModeSignature   = explode('=', $parts[2])[1] ?? '';

    $comparisonSignature = !empty($liveModeSignature) ? $liveModeSignature : $testModeSignature;

    if ($timestamp === '' || $comparisonSignature === '') {
        error_log('paymongoVerifyWebhookSignature: incomplete signature header');
        return null;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $webhookSecret);

    if (!hash_equals($expected, $comparisonSignature)) {
        error_log('paymongoVerifyWebhookSignature: signature mismatch');
        return null;
    }

    return json_decode($rawPayload, true);
}
