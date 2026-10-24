<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 *
 * Binance Pay Merchant API mock for local end-to-end tests and demos. Never point a live store at it.
 *
 * It checks request signatures the way Binance does, serves a stand-in checkout page and sends RSA-signed
 * webhooks, so the whole module runs without a Binance merchant account:
 *
 *   MOCK_API_KEY=mock-key MOCK_API_SECRET=mock-secret PHP_CLI_SERVER_WORKERS=4 \
 *       php -S 127.0.0.1:8701 Test/Mock/binance-pay-mock.php
 *
 * Then set the module's API URL to http://127.0.0.1:8701 and its API key and secret to the same values.
 *
 * Environment:
 *   MOCK_API_KEY, MOCK_API_SECRET  credentials the requests must be signed with (defaults: mock-key, mock-secret)
 *   MOCK_STATE_DIR                 where orders and the webhook signing key are kept (default: system temp dir)
 *   MOCK_WEBHOOK_DELAY             seconds before a webhook is sent (default 1), as Binance sends them later
 *   MOCK_WEBHOOK_INSECURE=1        do not check TLS certificates when calling the store (local certificates)
 *   PHP_CLI_SERVER_WORKERS         2 or more: the store calls back into the mock while it handles a webhook
 *
 * Test helpers:
 *   GET  /mock/state                     orders, refunds and webhooks as JSON
 *   POST /mock/reset                     forget all orders
 *   POST /mock/pay/{merchantTradeNo}     pay without a browser; form field "amount" pays a different amount
 *   POST /mock/expire/{merchantTradeNo}  expire an unpaid order and send PAY_CLOSED
 */
declare(strict_types=1);

const BPM_RATES = ['USD' => '1', 'CAD' => '0.72', 'EUR' => '1.08', 'GBP' => '1.27', 'AUD' => '0.66', 'VND' => '0.0000394'];
const BPM_CRYPTO = 'USDT';

if (PHP_SAPI === 'cli') {
    // Background worker: php binance-pay-mock.php send-webhook <id>
    if (($argv[1] ?? '') === 'send-webhook' && isset($argv[2])) {
        sleep(max(0, (int)(getenv('MOCK_WEBHOOK_DELAY') ?: 1)));
        bpm_send_webhook($argv[2]);
        exit(0);
    }
    fwrite(STDERR, "Run with: php -S 127.0.0.1:8701 " . basename(__FILE__) . "\n");
    exit(1);
}

bpm_route(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    (string)file_get_contents('php://input')
);

/**
 * Dispatch one HTTP request.
 */
function bpm_route(string $method, string $path, string $body): void
{
    $api = [
        '/binancepay/openapi/v3/order' => 'bpm_api_create_order',
        '/binancepay/openapi/v2/order/query' => 'bpm_api_query_order',
        '/binancepay/openapi/order/close' => 'bpm_api_close_order',
        '/binancepay/openapi/order/refund' => 'bpm_api_refund',
        '/binancepay/openapi/certificates' => 'bpm_api_certificates',
    ];
    if ($method === 'POST' && isset($api[$path])) {
        $error = bpm_check_signature($body);
        if ($error !== null) {
            bpm_api_fail($error[0], $error[1]);
            return;
        }
        $request = json_decode($body, true);
        if (!is_array($request)) {
            bpm_api_fail('400001', 'Parameter format is wrong or parameter transferring doesn\'t follow the rules.');
            return;
        }
        $api[$path]($request);
        return;
    }

    if (preg_match('#^/checkout/(\d+)$#', $path, $m) && $method === 'GET') {
        bpm_checkout_page($m[1]);
    } elseif (preg_match('#^/checkout/(\d+)/(pay|cancel)$#', $path, $m) && $method === 'POST') {
        $m[2] === 'pay' ? bpm_checkout_pay($m[1]) : bpm_checkout_cancel($m[1]);
    } elseif ($path === '/mock/state' && $method === 'GET') {
        bpm_json(200, bpm_with_state(fn(array &$state) => $state));
    } elseif ($path === '/mock/reset' && $method === 'POST') {
        bpm_with_state(function (array &$state) {
            $state = bpm_empty_state();
        });
        bpm_json(200, ['reset' => true]);
    } elseif (preg_match('#^/mock/pay/([A-Za-z0-9]+)$#', $path, $m) && $method === 'POST') {
        $order = bpm_pay($m[1], isset($_POST['amount']) ? (string)$_POST['amount'] : null);
        bpm_json($order ? 200 : 404, $order ?: ['error' => 'not payable']);
    } elseif (preg_match('#^/mock/expire/([A-Za-z0-9]+)$#', $path, $m) && $method === 'POST') {
        $order = bpm_close($m[1], 'EXPIRED');
        bpm_json($order ? 200 : 404, $order ?: ['error' => 'not open']);
    } else {
        bpm_json(404, ['error' => 'not found', 'path' => $path]);
    }
}

/* ---------------------------------------------------------------- API ---------------------------------------- */

/**
 * POST /binancepay/openapi/v3/order
 */
function bpm_api_create_order(array $request): void
{
    $tradeNo = (string)($request['merchantTradeNo'] ?? '');
    $fiatCurrency = strtoupper((string)($request['fiatCurrency'] ?? ''));
    $fiatAmount = (string)($request['fiatAmount'] ?? '');
    $problem = match (true) {
        !preg_match('/^[A-Za-z0-9]{1,32}$/', $tradeNo) => 'merchantTradeNo must be 1-32 letters or digits',
        ($request['env']['terminalType'] ?? '') === '' => 'env.terminalType is required',
        !isset(BPM_RATES[$fiatCurrency]) => 'fiatCurrency is not supported',
        !is_numeric($fiatAmount) || (float)$fiatAmount <= 0 => 'fiatAmount must be positive',
        empty($request['goodsDetails']) || !is_array($request['goodsDetails']) => 'goodsDetails is required',
        strlen((string)($request['description'] ?? '')) > 256 => 'description is longer than 256',
        bpm_query_count((string)($request['returnUrl'] ?? '')) > 1
            || bpm_query_count((string)($request['cancelUrl'] ?? '')) > 1 => 'URLs may carry one query parameter',
        default => null,
    };
    if ($problem !== null) {
        bpm_api_fail('400100', $problem);
        return;
    }

    $order = bpm_with_state(function (array &$state) use ($request, $tradeNo, $fiatCurrency, $fiatAmount) {
        if (isset($state['orders'][$tradeNo])) {
            return null;
        }
        $prepayId = (string)random_int(100000000000000000, 999999999999999999);
        $now = bpm_now();
        $state['orders'][$tradeNo] = [
            'merchantTradeNo' => $tradeNo,
            'prepayId' => $prepayId,
            'status' => 'INITIAL',
            'terminalType' => (string)$request['env']['terminalType'],
            'fiatCurrency' => $fiatCurrency,
            'fiatAmount' => $fiatAmount,
            'currency' => BPM_CRYPTO,
            'totalFee' => bcmul(number_format((float)$fiatAmount, 8, '.', ''), BPM_RATES[$fiatCurrency], 8),
            'orderAmount' => null,
            'refundedAmount' => '0.00000000',
            'createTime' => $now,
            'expireTime' => (int)($request['orderExpireTime'] ?? $now + 3600 * 1000),
            'description' => (string)($request['description'] ?? ''),
            'goodsDetails' => $request['goodsDetails'],
            'returnUrl' => (string)($request['returnUrl'] ?? ''),
            'cancelUrl' => (string)($request['cancelUrl'] ?? ''),
            'webhookUrl' => (string)($request['webhookUrl'] ?? ''),
            'passThroughInfo' => (string)($request['passThroughInfo'] ?? ''),
        ];
        $state['orders'][$tradeNo]['orderAmount'] = $state['orders'][$tradeNo]['totalFee'];
        return $state['orders'][$tradeNo];
    });
    if ($order === null) {
        bpm_api_fail('400201', 'merchantTradeNo is invalid or duplicated');
        return;
    }

    $checkoutUrl = bpm_public_url() . '/checkout/' . $order['prepayId'];
    bpm_api_success([
        'currency' => $order['currency'],
        'totalFee' => $order['totalFee'],
        'fiatCurrency' => $order['fiatCurrency'],
        'fiatAmount' => $order['fiatAmount'],
        'prepayId' => $order['prepayId'],
        'terminalType' => $order['terminalType'],
        'expireTime' => $order['expireTime'],
        'qrcodeLink' => $checkoutUrl . '.jpg',
        'qrContent' => $checkoutUrl,
        'checkoutUrl' => $checkoutUrl,
        'deeplink' => 'bnc://mock/payment/secpay?prepayId=' . $order['prepayId'],
        'universalUrl' => $checkoutUrl,
    ]);
}

/**
 * POST /binancepay/openapi/v2/order/query
 */
function bpm_api_query_order(array $request): void
{
    $tradeNo = (string)($request['merchantTradeNo'] ?? '');
    $order = bpm_with_state(function (array &$state) use ($tradeNo, $request) {
        $order = bpm_find_order($state, $tradeNo, (string)($request['prepayId'] ?? ''));
        if ($order === null) {
            return null;
        }
        if (in_array($order['status'], ['INITIAL', 'PENDING'], true) && $order['expireTime'] < bpm_now()) {
            $state['orders'][$order['merchantTradeNo']]['status'] = 'EXPIRED';
            $order['status'] = 'EXPIRED';
        }
        return $order;
    });
    if ($order === null) {
        bpm_api_fail('400202', 'Order not found.');
        return;
    }

    bpm_api_success(bpm_query_result($order));
}

/**
 * POST /binancepay/openapi/order/close
 */
function bpm_api_close_order(array $request): void
{
    $order = bpm_close((string)($request['merchantTradeNo'] ?? ''), 'CANCELED');
    if ($order === null) {
        bpm_api_fail('400202', 'Order not found or not open.');
        return;
    }

    bpm_api_success(true);
}

/**
 * POST /binancepay/openapi/order/refund
 */
function bpm_api_refund(array $request): void
{
    $requestId = (string)($request['refundRequestId'] ?? '');
    $amount = (string)($request['refundAmount'] ?? '');
    if ($requestId === '' || strlen($requestId) > 64 || !is_numeric($amount) || (float)$amount <= 0) {
        bpm_api_fail('400100', 'refundRequestId and a positive refundAmount are required');
        return;
    }
    $amount = number_format((float)$amount, 8, '.', '');

    $result = bpm_with_state(function (array &$state) use ($request, $requestId, $amount) {
        if (isset($state['refunds'][$requestId])) {
            return ['refund' => $state['refunds'][$requestId] + ['duplicateRequest' => 'Y']];
        }
        $order = bpm_find_order($state, '', (string)($request['prepayId'] ?? ''));
        if ($order === null || !in_array($order['status'], ['PAID', 'REFUNDING', 'REFUNDED'], true)) {
            return ['error' => ['400202', 'Order not found or not paid.']];
        }
        $refunded = bcadd($order['refundedAmount'], $amount, 8);
        if (bccomp($refunded, $order['orderAmount'], 8) > 0) {
            return ['error' => ['400102', 'Refund amount exceeds the refundable amount.']];
        }

        $tradeNo = $order['merchantTradeNo'];
        $state['orders'][$tradeNo]['refundedAmount'] = $refunded;
        $state['orders'][$tradeNo]['status'] = bccomp($refunded, $order['orderAmount'], 8) === 0
            ? 'FULL_REFUNDED'
            : 'REFUNDED';
        $refund = [
            'refundId' => (string)random_int(100000000000000000, 999999999999999999),
            'refundRequestId' => $requestId,
            'prepayId' => $order['prepayId'],
            'orderAmount' => $order['orderAmount'],
            'refundedAmount' => $refunded,
            'refundAmount' => $amount,
            'refundedCommission' => '0',
            'refundCommission' => '0',
            'remainingAttempts' => 9,
            'payerOpenId' => 'mockmerchantopenid',
            'refundStatus' => 'PENDING',
            'duplicateRequest' => 'N',
        ];
        $state['refunds'][$requestId] = $refund;

        $webhookUrl = (string)($request['webhookUrl'] ?? '') ?: $order['webhookUrl'];
        $data = [
            'merchantTradeNo' => $tradeNo,
            'totalFee' => (float)$order['orderAmount'],
            'transactTime' => bpm_now(),
            'refundInfo' => [
                'orderAmount' => $order['orderAmount'],
                'duplicateRequest' => 'N',
                'payerOpenId' => 'mockmerchantopenid',
                'prepayId' => $order['prepayId'],
                'refundRequestId' => $requestId,
                'refundedAmount' => $refunded,
                'remainingAttempts' => 9,
                'refundAmount' => $amount,
            ],
            'currency' => $order['currency'],
            'commission' => 0,
            'openUserId' => 'mockshopperopenid',
            'productType' => 'WEB',
            'productName' => 'WEB',
            'tradeType' => 'WEB',
        ];
        $webhookId = bpm_queue_webhook($state, $webhookUrl, 'PAY_REFUND', 'REFUND_SUCCESS', $data);
        return ['refund' => $refund, 'webhook' => $webhookId];
    });

    if (isset($result['error'])) {
        bpm_api_fail($result['error'][0], $result['error'][1]);
        return;
    }
    if (isset($result['webhook'])) {
        bpm_dispatch_webhook($result['webhook']);
    }
    bpm_api_success($result['refund']);
}

/**
 * POST /binancepay/openapi/certificates
 */
function bpm_api_certificates(array $request): void
{
    $key = bpm_webhook_key();
    bpm_api_success([['certSerial' => $key['serial'], 'certPublic' => $key['public']]]);
}

/* ------------------------------------------------------------ Checkout page ----------------------------------- */

/**
 * GET /checkout/{prepayId}: the page the shopper pays on.
 */
function bpm_checkout_page(string $prepayId): void
{
    $order = bpm_with_state(fn(array &$state) => bpm_find_order($state, '', $prepayId));
    if ($order === null) {
        bpm_html(404, '<p>Unknown payment.</p>');
        return;
    }

    $e = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES);
    $open = in_array($order['status'], ['INITIAL', 'PENDING'], true) && $order['expireTime'] > bpm_now();
    $amount = rtrim(rtrim($order['totalFee'], '0'), '.');
    $items = [];
    foreach ((array)$order['goodsDetails'] as $goods) {
        $items[] = $e($goods['goodsDetail'] ?? $goods['goodsName'] ?? '');
    }
    $actions = $open
        ? '<form method="post" action="/checkout/' . $e($prepayId) . '/pay">'
            . '<button class="pay" type="submit">Pay ' . $e($amount . ' ' . $order['currency']) . '</button></form>'
            . '<form method="post" action="/checkout/' . $e($prepayId) . '/cancel">'
            . '<button class="cancel" type="submit">Cancel payment</button></form>'
        : '<p class="closed">This payment is ' . $e(strtolower($order['status'])) . '.</p>';

    bpm_html(200, '
<div class="banner">TEST MODE &middot; local Binance Pay mock &middot; no real funds move</div>
<main>
  <p class="label">Pay merchant</p>
  <h1>' . $e($order['description']) . '</h1>
  <p class="items">' . implode('<br>', $items) . '</p>
  <div class="amount">' . $e($amount) . ' <span>' . $e($order['currency']) . '</span></div>
  <p class="fiat">&asymp; ' . $e($order['fiatAmount'] . ' ' . $order['fiatCurrency']) . '</p>
  <p class="expires" data-expire="' . (int)$order['expireTime'] . '">Expires in <b>--:--</b></p>
  ' . $actions . '
  <p class="meta">Order ' . $e($order['merchantTradeNo']) . ' &middot; Prepay ID ' . $e($prepayId) . '</p>
</main>
<script>
(function () {
  var el = document.querySelector(".expires");
  function tick() {
    var left = Math.max(0, Math.floor((+el.dataset.expire - Date.now()) / 1000));
    el.querySelector("b").textContent = Math.floor(left / 60) + ":" + String(left % 60).padStart(2, "0");
  }
  tick();
  setInterval(tick, 1000);
})();
</script>');
}

/**
 * POST /checkout/{prepayId}/pay: the shopper pays; Binance sends them back to returnUrl.
 */
function bpm_checkout_pay(string $prepayId): void
{
    $order = bpm_with_state(fn(array &$state) => bpm_find_order($state, '', $prepayId));
    if ($order === null) {
        bpm_html(404, '<p>Unknown payment.</p>');
        return;
    }
    $paid = bpm_pay($order['merchantTradeNo']);
    bpm_redirect($paid ? $order['returnUrl'] : '/checkout/' . $prepayId);
}

/**
 * POST /checkout/{prepayId}/cancel: the shopper gives up; the order stays open, as on Binance.
 */
function bpm_checkout_cancel(string $prepayId): void
{
    $order = bpm_with_state(fn(array &$state) => bpm_find_order($state, '', $prepayId));
    bpm_redirect($order ? $order['cancelUrl'] : '/');
}

/* ---------------------------------------------------------------- Orders -------------------------------------- */

/**
 * Mark an open order paid and queue the PAY_SUCCESS webhook.
 *
 * @param string|null $amount a different paid amount, to test mismatches
 */
function bpm_pay(string $tradeNo, ?string $amount = null): ?array
{
    $result = bpm_with_state(function (array &$state) use ($tradeNo, $amount) {
        $order = $state['orders'][$tradeNo] ?? null;
        if ($order === null || !in_array($order['status'], ['INITIAL', 'PENDING'], true)
            || $order['expireTime'] < bpm_now()
        ) {
            return null;
        }
        $order['status'] = 'PAID';
        $order['transactionId'] = 'M_P_' . random_int(10000000000000000, 99999999999999999);
        $order['transactTime'] = bpm_now();
        if ($amount !== null && is_numeric($amount)) {
            $order['orderAmount'] = number_format((float)$amount, 8, '.', '');
        }
        $state['orders'][$tradeNo] = $order;

        $data = [
            'merchantTradeNo' => $tradeNo,
            'productType' => 'WEB',
            'productName' => $order['description'],
            'transactTime' => $order['transactTime'],
            'tradeType' => 'WEB',
            'totalFee' => (float)$order['orderAmount'],
            'currency' => $order['currency'],
            'transactionId' => $order['transactionId'],
            'openUserId' => 'mockshopperopenid',
            'commission' => 0,
            'paymentInfo' => bpm_payment_info($order),
            'passThroughInfo' => $order['passThroughInfo'],
        ];
        return [
            'order' => $order,
            'webhook' => bpm_queue_webhook($state, $order['webhookUrl'], 'PAY', 'PAY_SUCCESS', $data),
        ];
    });
    if ($result === null) {
        return null;
    }

    bpm_dispatch_webhook($result['webhook']);
    return $result['order'];
}

/**
 * Close an open order (CANCELED or EXPIRED) and queue the PAY_CLOSED webhook.
 */
function bpm_close(string $tradeNo, string $status): ?array
{
    $result = bpm_with_state(function (array &$state) use ($tradeNo, $status) {
        $order = $state['orders'][$tradeNo] ?? null;
        if ($order === null || !in_array($order['status'], ['INITIAL', 'PENDING'], true)) {
            return null;
        }
        $order['status'] = $status;
        if ($status === 'EXPIRED') {
            $order['expireTime'] = bpm_now() - 1000;
        }
        $state['orders'][$tradeNo] = $order;

        $data = [
            'merchantTradeNo' => $tradeNo,
            'totalFee' => (float)$order['totalFee'],
            'transactTime' => bpm_now(),
            'currency' => $order['currency'],
            'openUserId' => '',
            'productType' => 'WEB',
            'productName' => $order['description'],
            'tradeType' => 'WEB',
            'passThroughInfo' => $order['passThroughInfo'],
        ];
        return [
            'order' => $order,
            'webhook' => bpm_queue_webhook($state, $order['webhookUrl'], 'PAY', 'PAY_CLOSED', $data),
        ];
    });
    if ($result === null) {
        return null;
    }

    bpm_dispatch_webhook($result['webhook']);
    return $result['order'];
}

/**
 * Query Order (v2) result for an order.
 */
function bpm_query_result(array $order): array
{
    $result = [
        'merchantId' => 98729382672,
        'prepayId' => $order['prepayId'],
        'merchantTradeNo' => $order['merchantTradeNo'],
        'status' => $order['status'],
        'currency' => $order['currency'],
        'orderAmount' => $order['orderAmount'],
        'openUserId' => '',
        'passThroughInfo' => $order['passThroughInfo'],
        'createTime' => $order['createTime'],
    ];
    if (isset($order['transactionId'])) {
        $result['transactionId'] = $order['transactionId'];
        $result['transactTime'] = $order['transactTime'];
        $result['openUserId'] = 'mockshopperopenid';
        $result['paymentInfo'] = bpm_payment_info($order);
    }

    return $result;
}

/**
 * Payment details of a paid order; the mock always pays in USDT.
 */
function bpm_payment_info(array $order): array
{
    return [
        'payerId' => '100103455154',
        'payMethod' => 'funding',
        'paymentInstructions' => [
            ['currency' => $order['currency'], 'amount' => $order['orderAmount'], 'price' => '1'],
        ],
        'channel' => 'DEFAULT',
    ];
}

/**
 * Find an order by merchantTradeNo or prepayId.
 */
function bpm_find_order(array $state, string $tradeNo, string $prepayId): ?array
{
    if ($tradeNo !== '') {
        return $state['orders'][$tradeNo] ?? null;
    }
    foreach ($state['orders'] as $order) {
        if ($order['prepayId'] === $prepayId) {
            return $order;
        }
    }

    return null;
}

/* --------------------------------------------------------------- Webhooks ------------------------------------- */

/**
 * Record a webhook to send; it is sent by bpm_dispatch_webhook() after the state is saved.
 */
function bpm_queue_webhook(array &$state, string $url, string $bizType, string $bizStatus, array $data): ?string
{
    if ($url === '') {
        return null;
    }
    // bizId is larger than a 64-bit integer, like Binance's, so the raw body cannot be built with json_encode.
    $bizId = '29' . random_int(100000000, 999999999) . random_int(100000000, 999999999);
    $body = sprintf(
        '{"bizType":%s,"data":%s,"bizIdStr":"%s","bizId":%s,"bizStatus":%s}',
        json_encode($bizType),
        json_encode((string)json_encode($data, JSON_UNESCAPED_SLASHES), JSON_UNESCAPED_SLASHES),
        $bizId,
        $bizId,
        json_encode($bizStatus)
    );
    $state['webhooks'][$bizId] = [
        'url' => $url,
        'bizType' => $bizType,
        'bizStatus' => $bizStatus,
        'merchantTradeNo' => $data['merchantTradeNo'] ?? '',
        'body' => $body,
        'status' => 'queued',
        'queuedAt' => bpm_now(),
    ];

    return $bizId;
}

/**
 * Send a queued webhook from a background process, a moment later, as Binance does.
 */
function bpm_dispatch_webhook(?string $webhookId): void
{
    if ($webhookId === null) {
        return;
    }
    exec(sprintf(
        '%s %s send-webhook %s > /dev/null 2>&1 &',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        escapeshellarg($webhookId)
    ));
}

/**
 * POST one queued webhook, signed with the mock's RSA key, and record the store's answer.
 */
function bpm_send_webhook(string $webhookId): void
{
    $webhook = bpm_with_state(fn(array &$state) => $state['webhooks'][$webhookId] ?? null);
    if ($webhook === null) {
        return;
    }

    $key = bpm_webhook_key();
    $timestamp = (string)bpm_now();
    $nonce = bin2hex(random_bytes(16));
    $signature = '';
    openssl_sign($timestamp . "\n" . $nonce . "\n" . $webhook['body'] . "\n", $signature, $key['private'], OPENSSL_ALGO_SHA256);

    $curl = curl_init($webhook['url']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $webhook['body'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $key['serial'],
            'BinancePay-Signature: ' . base64_encode($signature),
        ],
    ]);
    if (getenv('MOCK_WEBHOOK_INSECURE')) {
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    }
    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $answer = is_string($response) ? json_decode($response, true) : null;
    $ok = $httpCode === 200 && is_array($answer) && ($answer['returnCode'] ?? '') === 'SUCCESS';
    bpm_with_state(function (array &$state) use ($webhookId, $ok, $httpCode, $response, $error) {
        $state['webhooks'][$webhookId]['status'] = $ok ? 'delivered' : 'failed';
        $state['webhooks'][$webhookId]['httpCode'] = $httpCode;
        $state['webhooks'][$webhookId]['response'] = is_string($response) ? substr($response, 0, 500) : $error;
        $state['webhooks'][$webhookId]['sentAt'] = bpm_now();
    });
}

/**
 * The RSA key webhooks are signed with, created on first use.
 *
 * @return array{private: string, public: string, serial: string}
 */
function bpm_webhook_key(): array
{
    $file = bpm_state_dir() . '/webhook-key.pem';
    if (!is_file($file)) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pem = '';
        openssl_pkey_export($key, $pem);
        file_put_contents($file . '.tmp', $pem);
        rename($file . '.tmp', $file);
    }
    $private = (string)file_get_contents($file);
    $public = (string)openssl_pkey_get_details(openssl_pkey_get_private($private))['key'];

    return ['private' => $private, 'public' => $public, 'serial' => substr(sha1($public), 0, 32)];
}

/* ------------------------------------------------------------------ Plumbing ---------------------------------- */

/**
 * Check BinancePay-* headers the way Binance does.
 *
 * @return array{0: string, 1: string}|null error code and message
 */
function bpm_check_signature(string $body): ?array
{
    $timestamp = (string)($_SERVER['HTTP_BINANCEPAY_TIMESTAMP'] ?? '');
    $nonce = (string)($_SERVER['HTTP_BINANCEPAY_NONCE'] ?? '');
    $apiKey = (string)($_SERVER['HTTP_BINANCEPAY_CERTIFICATE_SN'] ?? '');
    $signature = (string)($_SERVER['HTTP_BINANCEPAY_SIGNATURE'] ?? '');

    if (!hash_equals(getenv('MOCK_API_KEY') ?: 'mock-key', $apiKey)) {
        return ['400004', 'API identity key not found or invalid.'];
    }
    if (!ctype_digit($timestamp) || abs(bpm_now() - (int)$timestamp) > 60000) {
        return ['400003', 'Timestamp for this request is outside of the time window.'];
    }
    if (!preg_match('/^[A-Za-z0-9]{32}$/', $nonce)) {
        return ['400001', 'BinancePay-Nonce must be 32 letters or digits.'];
    }
    $expected = strtoupper(hash_hmac(
        'sha512',
        $timestamp . "\n" . $nonce . "\n" . $body . "\n",
        getenv('MOCK_API_SECRET') ?: 'mock-secret'
    ));

    return hash_equals($expected, $signature) ? null : ['400002', 'Incorrect signature result'];
}

/**
 * Run $callback on the state under an exclusive file lock; it may change the state in place.
 */
function bpm_with_state(callable $callback): mixed
{
    $handle = fopen(bpm_state_dir() . '/state.json', 'c+');
    flock($handle, LOCK_EX);
    $state = json_decode((string)stream_get_contents($handle), true) ?: bpm_empty_state();
    $before = json_encode($state);

    $result = $callback($state);

    $after = (string)json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($after !== $before) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $after);
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

function bpm_empty_state(): array
{
    return ['orders' => [], 'refunds' => [], 'webhooks' => []];
}

function bpm_state_dir(): string
{
    $dir = getenv('MOCK_STATE_DIR') ?: sys_get_temp_dir() . '/binance-pay-mock';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    return $dir;
}

function bpm_public_url(): string
{
    return 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8701');
}

function bpm_now(): int
{
    return (int)round(microtime(true) * 1000);
}

function bpm_query_count(string $url): int
{
    $query = (string)parse_url($url, PHP_URL_QUERY);

    return $query === '' ? 0 : count(explode('&', $query));
}

function bpm_api_success(mixed $data): void
{
    bpm_json(200, ['status' => 'SUCCESS', 'code' => '000000', 'data' => $data, 'errorMessage' => '']);
}

function bpm_api_fail(string $code, string $message): void
{
    bpm_json(400, ['status' => 'FAIL', 'code' => $code, 'errorMessage' => $message]);
}

function bpm_json(int $httpCode, mixed $data): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function bpm_redirect(string $url): void
{
    http_response_code(302);
    header('Location: ' . $url);
}

function bpm_html(int $httpCode, string $content): void
{
    http_response_code($httpCode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Test checkout - Binance Pay mock</title><style>
body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#1e2329}
.banner{background:#fff4d6;color:#7a5a00;text-align:center;padding:10px 16px;font-size:14px;font-weight:600}
main{max-width:420px;margin:40px auto;background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.label{margin:0;color:#707a8a;font-size:14px}h1{margin:4px 0 8px;font-size:20px}
.items{color:#474d57;font-size:14px;margin:0 0 24px}
.amount{font-size:40px;font-weight:700;letter-spacing:-.5px}.amount span{font-size:20px;color:#707a8a}
.fiat{margin:0 0 8px;color:#707a8a}.expires{margin:0 0 24px;color:#707a8a;font-size:14px}
button{width:100%;padding:14px;border:0;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;margin-bottom:12px}
.pay{background:#1e2329;color:#fff}.cancel{background:#eaecef;color:#1e2329}
.closed{padding:14px;background:#eaecef;border-radius:10px;text-align:center}
.meta{margin:12px 0 0;color:#a0a6b0;font-size:12px;word-break:break-all}
</style></head><body>' . $content . '</body></html>';
}
