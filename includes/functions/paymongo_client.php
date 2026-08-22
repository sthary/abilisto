<?php
// includes/functions/paymongo_client.php
// Shared PayMongo Checkout Sessions helper — replaces the curl boilerplate
// that used to be duplicated across process_payment_xendit.php,
// process_topup_xendit.php, and generate_receipt.php.
//
// Uses Checkout Sessions (not Payment Links) specifically because Links
// don't support a post-payment redirect — PayMongo silently drops any
// redirect-shaped field passed to POST /v1/links (confirmed empirically
// against the live API, since it's undocumented behavior either way).
// Checkout Sessions have first-class success_url/cancel_url support.
//
// Every endpoint here was verified empirically against the real PayMongo
// sandbox API with test keys, because docs.paymongo.com was largely
// 404/restructured at the time this was written and gave inconsistent
// answers. Ground truth confirmed live:
//   - Create:   POST https://api.paymongo.com/v2/checkout_sessions
//   - Retrieve: GET  https://api.paymongo.com/v1/checkout_sessions/{id}
//               (yes, different version than create — confirmed by the v2
//               path 404ing with "route does not exist" and v1 working)
// Basic auth with the secret key, JSON:API request wrapping
// ({"data":{"attributes":{...}}}), amounts in integer centavos.

/**
 * Create a PayMongo Checkout Session.
 *
 * @param  float  $amountPesos     Amount in PESOS (converted to centavos here —
 *                                  do NOT pre-convert at the call site).
 * @param  string $description
 * @param  string $referenceNumber Human-readable correlation id (e.g. 'INV-...').
 * @param  array  $metadata        Structured correlation data (booking_id, type, ...).
 *                                  This is the PRIMARY correlation key read back
 *                                  from webhook payloads — reference_number is a
 *                                  secondary, human-readable label only. Confirmed
 *                                  it round-trips correctly on retrieve.
 * @param  string $successUrl      Where the client lands after paying.
 * @param  string $cancelUrl       Where the client lands if they back out.
 * @return array  ['success'=>bool, 'id'=>string|null, 'checkout_url'=>string|null, 'message'=>string]
 */
function paymongoCreateCheckoutSession($amountPesos, $description, $referenceNumber, $metadata, $successUrl, $cancelUrl) {
    $amountPesos = floatval($amountPesos);
    if ($amountPesos <= 0) {
        return ['success' => false, 'id' => null, 'checkout_url' => null, 'message' => 'Invalid amount'];
    }

    $secret_key = getenv('PAYMONGO_SECRET_KEY');
    if (!$secret_key) {
        error_log('paymongoCreateCheckoutSession: PAYMONGO_SECRET_KEY is not set');
        return ['success' => false, 'id' => null, 'checkout_url' => null, 'message' => 'Payment gateway not configured'];
    }

    // PayMongo amounts are INTEGER CENTAVOS, not decimal pesos.
    $amountCentavos = (int) round($amountPesos * 100);

    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name'     => $description,
                    'amount'   => $amountCentavos,
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['gcash', 'card', 'paymaya'],
                'description'           => $description,
                'reference_number'      => $referenceNumber,
                'metadata'              => $metadata,
                'success_url'           => $successUrl,
                'cancel_url'            => $cancelUrl,
                'send_email_receipt'    => false,
            ],
        ],
    ];

    $ch = curl_init('https://api.paymongo.com/v2/checkout_sessions');
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
        error_log("paymongoCreateCheckoutSession: cURL error: $curl_err");
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
    error_log("paymongoCreateCheckoutSession: API error ($http_code): " . $response);
    return ['success' => false, 'id' => null, 'checkout_url' => null, 'message' => $err_msg];
}

/**
 * Retrieve a Checkout Session and its payment status. Used by the browser-
 * redirect landing pages to verify a payment server-side before crediting
 * anything — the landing page URL itself proves nothing (anyone can visit
 * it without paying, success_url or not), so this call is what actually
 * confirms payment.
 *
 * @param  string $sessionId
 * @return array  ['success'=>bool, 'paid'=>bool, 'status'=>string|null, 'message'=>string]
 */
function paymongoRetrieveCheckoutSession($sessionId) {
    $secret_key = getenv('PAYMONGO_SECRET_KEY');
    if (!$secret_key || !$sessionId) {
        return ['success' => false, 'paid' => false, 'status' => null, 'message' => 'Missing gateway id or key'];
    }

    // Note: retrieval is on the v1 path even though creation is v2 —
    // confirmed empirically (v2 GET 404s with "route does not exist").
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . urlencode($sessionId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($secret_key . ':'),
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1');

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("paymongoRetrieveCheckoutSession: HTTP $http_code for session $sessionId: $response");
        return ['success' => false, 'paid' => false, 'status' => null, 'message' => "HTTP $http_code"];
    }

    $result = json_decode($response, true);
    $attrs  = $result['data']['attributes'] ?? [];
    $status = $attrs['status'] ?? null;

    // Don't rely on a single terminal status string (unconfirmed exact
    // wording once paid) — check every independent signal the session
    // carries: a 'paid' entry in payments[], or a succeeded payment_intent.
    $paid = false;
    if (!empty($attrs['payments'])) {
        foreach ($attrs['payments'] as $p) {
            if (($p['attributes']['status'] ?? '') === 'paid') { $paid = true; break; }
        }
    }
    if (!$paid && !empty($attrs['payment_intent']['attributes']['status'])) {
        $paid = ($attrs['payment_intent']['attributes']['status'] === 'succeeded');
    }
    if (!$paid && $status === 'paid') {
        $paid = true;
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
