<?php

/**
 * FILE LOCATION:
 *   core/app/Http/Controllers/Gateway/Jio/ProcessController.php
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PRODUCTION IMPLEMENTATION — ported from working Node.js reference code.
 * Supports proxy routing via X-PG-Target-Host (Indian VPS Nginx).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * GATEWAY DB REQUIREMENTS:
 *   gateways.code = 60  (must be < 1000 so automatic flow fires)
 *   gateway_currencies.gateway_parameter = JSON (see schema below)
 *
 * gateway_parameter JSON schema (store all of these in MySQL):
 * {
 *   "client_id":        "U1BSX05YVF9sb2...",          ← base64-encoded before sending as header
 *   "client_secret":    "your_client_secret",          ← used inside encrypted Token payload
 *   "api_id":           "20260",                       ← QR/intent init
 *   "api_id_status":    "20247",                       ← status check
 *   "bank_id":          "12",
 *   "payee_vpa":        "sprintnxt.8080@jiomerchant",
 *   "expiry_time":      "10",
 *   "is_production":    true,
 *   "encryption_key":   "56c271c135e53d1a0281ea8b0b6c8e02",   ← 32 bytes for AES-256
 *   "encryption_iv":    "5158c4f228be322e",                   ← 16 bytes for AES-CBC
 *   "payin_init_url":   "https://nxt.sprintnxt.in/PayInExposeNxt/api/v2/UPIService/UPI",
 *   "payin_status_url": "https://nxt.sprintnxt.in/NextgenAPIExpose/api/v2/UPIService/UPI",
 *   "proxy_url":        "https://checkout.novafin.tech"        ← leave empty/null for direct
 * }
 *
 * HOW PROXY WORKS:
 *   When proxy_url is set, all HTTP calls go to checkout.novafin.tech (Indian VPS).
 *   The header X-PG-Target-Host tells the Nginx there which upstream to forward to.
 *   Flow: Singapore App → checkout.novafin.tech (Nginx) → real JIO/SprintNXT API
 *
 * TOKEN GENERATION (matches Node.js exactly):
 *   payload = JSON { client_secret, requestid (9-digit random), timestamp (unix epoch) }
 *   token   = AES-256-CBC encrypt(payload, encryption_key, encryption_iv) → base64
 *   header  = "Token: <base64_ciphertext>"
 *   Client-id header = base64_encode(client_id)   ← Node does this too
 */

namespace App\Http\Controllers\Gateway\Jio;

use App\Constants\Status;
use App\Models\Deposit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Gateway\PaymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessController extends Controller
{
    private const LOG_TAG = '[JIO-LIVE]';

    // =========================================================================
    //  STEP 1 — QR / Intent Initiation
    //  Called by PaymentController::depositConfirm() via static process()
    // =========================================================================
    public static function process($deposit): string
    {
        try {
            $creds = self::resolveCredentials($deposit->gatewayCurrency()->gateway_parameter);
        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . ' [ERR/INIT-CRED-MISSING]', ['msg' => $e->getMessage()]);
            return self::errorJson($e->getMessage());
        }

        $txnRef   = self::makeTxnRef($deposit->trx);
        $endpoint = self::rewriteUrl($creds['payin_init_url'], $creds['proxy_url']);

        $payload = [
            'apiId'        => $creds['api_id'],
            'bankId'       => $creds['bank_id'],
            'amount'       => (string) round($deposit->final_amount, 2),
            'payeeVPA'     => $creds['payee_vpa'],
            'mobile'       => self::safeMobile(null),
            'ExpiryTime'   => (string) $creds['expiry_time'],
            'txnNote'      => 'Payment of ' . round($deposit->final_amount, 2),
            'txnReferance' => $txnRef,
        ];

        $headers = self::buildAuthHeaders($creds);

        Log::info(self::LOG_TAG . ' [1/INIT-REQUEST]', [
            'environment'   => $creds['is_production'] ? 'PRODUCTION' : 'UAT',
            'endpoint'      => $endpoint,
            'proxied'       => $creds['is_proxied'],
            'target_host'   => $creds['original_api_host'],
            'trx'           => $deposit->trx,
            'txnRef'        => $txnRef,
            'deposit_id'    => $deposit->id,
            'amount'        => $deposit->final_amount,
            'payload'       => $payload,
        ]);

        try {
            $response   = Http::timeout(30)->withHeaders($headers)->post($endpoint, $payload);
            $httpStatus = $response->status();
            $result     = $response->json();

            Log::info(self::LOG_TAG . ' [2/INIT-RESPONSE]', [
                'trx'         => $deposit->trx,
                'txnRef'      => $txnRef,
                'http_status' => $httpStatus,
                'response'    => $result,
            ]);

            if ($response->failed()) {
                Log::error(self::LOG_TAG . ' [ERR/INIT-HTTP-FAIL]', [
                    'trx'    => $deposit->trx,
                    'status' => $httpStatus,
                    'body'   => $response->body(),
                ]);
                return self::errorJson('Gateway returned HTTP ' . $httpStatus . '. Please try again.');
            }

            // Node checks: body?.status === true || body?.status === 1
            $gatewayStatus = $result['status'] ?? false;
            if ($gatewayStatus !== true && $gatewayStatus !== 1) {
                Log::error(self::LOG_TAG . ' [ERR/INIT-GATEWAY-FAIL]', [
                    'trx'     => $deposit->trx,
                    'message' => $result['message'] ?? 'Unknown',
                    'result'  => $result,
                ]);
                return self::errorJson($result['message'] ?? 'Gateway error. Please try again.');
            }

            $details = $result['details'] ?? [];

            // Store txnReferance → used to match webhook and status check
            $deposit->btc_wallet = $txnRef;
            $deposit->save();

            $intentUrl = $details['intent_url'] ?? '';

            Log::info(self::LOG_TAG . ' [3/INIT-SUCCESS-QR-GENERATED]', [
                'trx'        => $deposit->trx,
                'txnRef'     => $txnRef,
                'UPIRefID'   => $details['UPIRefID'] ?? '',
                'intent_url' => substr($intentUrl, 0, 80) . '...',
            ]);

            if (empty($intentUrl)) {
                Log::error(self::LOG_TAG . ' [ERR/INIT-NO-INTENT-URL]', ['details' => $details]);
                return self::errorJson('No payment link returned by gateway.');
            }

            $send['intent_url']    = $intentUrl;
            $send['upi_ref_id']    = $details['UPIRefID']     ?? $txnRef;
            $send['txn_reference'] = $details['txnReferance'] ?? $txnRef;
            $send['payee_vpa']     = $details['payeeVPA']      ?? $creds['payee_vpa'];
            $send['amount']        = $deposit->final_amount;
            $send['currency']      = $deposit->method_currency;
            $send['expiry_min']    = (int) $creds['expiry_time'];
            $send['status_url']    = route('ipn.jio.status');
            $send['trx']           = $deposit->trx;
            $send['view']          = 'user.payment.jio';
            $send['method']        = 'GET';
            $send['url']           = route('ipn.jio');

            return json_encode($send);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . ' [ERR/INIT-EXCEPTION]', [
                'trx'       => $deposit->trx,
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return self::errorJson('Gateway connection failed. Please try again.');
        }
    }

    // =========================================================================
    //  STEP 2 — Webhook / IPN Handler
    //  SprintNXT POSTs to https://yourdomain.com/ipn/jio after each payment.
    //
    //  UAT:  plain JSON posted directly
    //  LIVE: {"encdata":"<AES-256-CBC base64>"} — decrypted with same key/iv
    //
    //  Required response format: {"responseMessage":"Successful","returnCode":"0"}
    // =========================================================================
    public function ipn(Request $request)
    {
        $rawBody = $request->getContent();
        $payload = $request->all();

        Log::info(self::LOG_TAG . ' [4/WEBHOOK-RECEIVED]', [
            'ip'           => $request->ip(),
            'method'       => $request->method(),
            'content_type' => $request->header('Content-Type'),
            'raw_body'     => $rawBody,
            'parsed'       => $payload,
        ]);

        // Decrypt if production encdata (matches Node decryptJioWebhook logic)
        if (isset($payload['encdata'])) {
            Log::info(self::LOG_TAG . ' [4a/WEBHOOK-ENCRYPTED] Attempting decryption');

            $decrypted = $this->decryptWebhook($payload['encdata']);
            if ($decrypted === null) {
                Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-DECRYPT-FAIL]', [
                    'encdata_length' => strlen($payload['encdata']),
                ]);
                // Return 200 — we don't want SprintNXT to keep retrying
                return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
            }

            $payload = $decrypted;
            Log::info(self::LOG_TAG . ' [4b/WEBHOOK-DECRYPTED]', ['payload' => $payload]);
        }

        /*
         * Webhook payload structure (plain or after decryption):
         * {
         *   "event":    "upi",
         *   "bank":     "JIO",
         *   "response": {
         *     "status":              1,        ← 1=Success, 4=Expired, 5=Failed, 6=Pending
         *     "amount":              "799",    ← may be settlement amount (after fees)
         *     "utr":                 "...",
         *     "refid":               "TXNREF",
         *     "ref_id":              "TXNREF",  ← alternate key (Node checks this too)
         *     "upiRefId":            "TXNREF",
         *     "txnid":               "...",
         *     "receiver_vpa":        "...",
         *     "remarks":             "SUCCESS",
         *     "PayerVPA":            "user@upi",
         *     "PayerName":           "...",
         *     "TransactionDateTime": "..."
         *   }
         * }
         */
        $response = $payload['response'] ?? $payload;
        $wStatus  = (int) ($response['status'] ?? 0);
        $event    = $payload['event'] ?? 'unknown';
        $bank     = $payload['bank']  ?? 'unknown';

        Log::info(self::LOG_TAG . ' [5/WEBHOOK-PARSED]', [
            'event'    => $event,
            'bank'     => $bank,
            'status'   => $wStatus,
            'refid'    => $response['refid']    ?? '',
            'ref_id'   => $response['ref_id']   ?? '',
            'upiRefId' => $response['upiRefId'] ?? '',
            'amount'   => $response['amount']   ?? '',
            'utr'      => $response['utr']       ?? '',
        ]);

        // Prioritise refid → ref_id → upiRefId (matches Node callback logic exactly)
        $txnRef = $response['refid'] ?? $response['ref_id'] ?? $response['upiRefId'] ?? null;

        if (!$txnRef) {
            Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-NO-TXNREF]', ['response' => $response]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        $deposit = Deposit::where('btc_wallet', $txnRef)->orderBy('id', 'DESC')->first();

        if (!$deposit) {
            Log::warning(self::LOG_TAG . ' [WARN/WEBHOOK-DEPOSIT-NOT-FOUND]', [
                'txnRef'      => $txnRef,
                'searched_in' => 'deposits.btc_wallet',
            ]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        Log::info(self::LOG_TAG . ' [6/WEBHOOK-DEPOSIT-FOUND]', [
            'deposit_id'     => $deposit->id,
            'deposit_status' => $deposit->status,
            'txnRef'         => $txnRef,
        ]);

        // Already processed — skip (matches Node "payin.status === SUCCESS" guard)
        if ($deposit->status == Status::PAYMENT_SUCCESS) {
            Log::info(self::LOG_TAG . ' [7a/WEBHOOK-ALREADY-SUCCESS]', ['deposit_id' => $deposit->id]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        if ($wStatus === 1) {
            // Success callback — verify amount then confirm
            // Node prioritises payer_amount; response.amount may be post-fee settlement amount
            $paidAmount    = (float) ($response['payer_amount'] ?? $response['amount'] ?? 0);
            $expectedAmount = (float) $deposit->final_amount;
            $amountMatched = self::matchAmount($expectedAmount, $paidAmount);

            Log::info(self::LOG_TAG . ' [6a/WEBHOOK-AMOUNT-CHECK]', [
                'expected'      => $expectedAmount,
                'received'      => $paidAmount,
                'matched'       => $amountMatched,
                'payer_amount'  => $response['payer_amount'] ?? 'N/A',
                'amount_field'  => $response['amount']       ?? 'N/A',
            ]);

            if ($amountMatched && $deposit->status == Status::PAYMENT_INITIATE) {
                $deposit->detail = $payload;
                $deposit->save();
                PaymentController::userDataUpdate($deposit);

                Log::info(self::LOG_TAG . ' [7/WEBHOOK-PAYMENT-CONFIRMED]', [
                    'deposit_id' => $deposit->id,
                    'trx'        => $deposit->trx,
                    'amount'     => $deposit->final_amount,
                    'utr'        => $response['utr']      ?? 'N/A',
                    'payer_vpa'  => $response['PayerVPA'] ?? 'N/A',
                ]);
            } else if (!$amountMatched) {
                Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-AMOUNT-MISMATCH]', [
                    'deposit_id' => $deposit->id,
                    'expected'   => $expectedAmount,
                    'received'   => $paidAmount,
                ]);
                // Do NOT confirm — amount mismatch. Log it, alert manually if needed.
            } else {
                Log::info(self::LOG_TAG . ' [7b/WEBHOOK-ALREADY-PROCESSED]', [
                    'deposit_id'    => $deposit->id,
                    'current_status'=> $deposit->status,
                ]);
            }

        } elseif ($wStatus === 4 || $wStatus === 5) {
            // Terminal failure — log it (PHP framework may not have explicit FAILED status update)
            Log::info(self::LOG_TAG . ' [7c/WEBHOOK-TERMINAL-FAIL]', [
                'deposit_id' => $deposit->id,
                'wStatus'    => $wStatus,
                'meaning'    => self::webhookStatusMeaning($wStatus),
            ]);
        } else {
            // Status 2/3/6 — pending/initiated, no update needed
            Log::info(self::LOG_TAG . ' [7d/WEBHOOK-PENDING-STATUS]', [
                'deposit_id' => $deposit->id,
                'wStatus'    => $wStatus,
                'meaning'    => self::webhookStatusMeaning($wStatus),
            ]);
        }

        // SprintNXT REQUIRES exactly this response — any other format causes retries
        return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
    }

    // =========================================================================
    //  STEP 3 — AJAX Status Polling
    //  Payment page calls this every 5 seconds via GET /ipn/jio/status?trx=...
    // =========================================================================
    public function checkStatus(Request $request)
    {
        $trx = $request->input('trx');
        if (!$trx) {
            return response()->json(['status' => 'error', 'message' => 'Missing trx']);
        }

        $deposit = Deposit::where('trx', $trx)->orderBy('id', 'DESC')->first();
        if (!$deposit) {
            return response()->json(['status' => 'error', 'message' => 'Not found']);
        }

        // Already confirmed by webhook
        if ($deposit->status == Status::PAYMENT_SUCCESS) {
            Log::info(self::LOG_TAG . ' [POLL/ALREADY-SUCCESS]', ['trx' => $trx]);
            return response()->json([
                'status'   => 'success',
                'redirect' => route('checkout.confirmation', $deposit->order->order_number),
            ]);
        }

        if ($deposit->status != Status::PAYMENT_INITIATE) {
            return response()->json(['status' => 'pending']);
        }

        try {
            $creds = self::resolveCredentials($deposit->gatewayCurrency()->gateway_parameter);
        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . ' [ERR/POLL-CRED-MISSING]', ['msg' => $e->getMessage()]);
            return response()->json(['status' => 'pending']);
        }

        $endpoint = self::rewriteUrl($creds['payin_status_url'], $creds['proxy_url']);

        $statusPayload = [
            'apiId'  => $creds['api_id_status'],      // Always api_id_status (e.g. 20247) for status check
            'bankId' => $creds['bank_id'],
            'txnId'  => $deposit->btc_wallet,          // txnReferance sent at init
        ];

        $headers = self::buildAuthHeaders($creds);

        Log::info(self::LOG_TAG . ' [POLL/STATUS-REQUEST]', [
            'trx'         => $trx,
            'endpoint'    => $endpoint,
            'proxied'     => $creds['is_proxied'],
            'target_host' => $creds['original_api_host'],
            'payload'     => $statusPayload,
        ]);

        try {
            $response = Http::timeout(15)->withHeaders($headers)->post($endpoint, $statusPayload);
            $result   = $response->json();

            Log::info(self::LOG_TAG . ' [POLL/STATUS-RESPONSE]', [
                'trx'    => $trx,
                'result' => $result,
            ]);

            // API-level failure — keep polling, don't fail the user
            $gatewayOk = $result['status'] ?? false;
            if ($gatewayOk !== true && $gatewayOk !== 1) {
                Log::warning(self::LOG_TAG . ' [POLL/API-ERROR]', ['trx' => $trx, 'result' => $result]);
                return response()->json(['status' => 'pending']);
            }

            /*
             * Node logic: body.data can be an ARRAY — find the best record.
             * Priority 1: any Success record
             * Priority 2: any Pending/Initiated record
             * Priority 3: last record (fallback)
             *
             * statusvalue: 1=Success 2=Initiated 3=QRGenerated 4=Expired 5=Failed 6=Pending
             */
            $dataRaw = $result['data'] ?? null;
            $data    = null;

            if (is_array($dataRaw) && isset($dataRaw[0])) {
                // It's a list — apply Node priority selection
                foreach ($dataRaw as $item) {
                    $s = strtolower((string) ($item['status'] ?? $item['statusvalue'] ?? ''));
                    if ($s === 'success' || $s === '1') {
                        $data = $item;
                        break;
                    }
                }
                if (!$data) {
                    $pendingCodes = ['2', '3', '6', 'initiated', 'qr_generated', 'qrgenerated', 'pending'];
                    foreach ($dataRaw as $item) {
                        $s = strtolower((string) ($item['status'] ?? $item['statusvalue'] ?? ''));
                        if (in_array($s, $pendingCodes)) {
                            $data = $item;
                            break;
                        }
                    }
                }
                if (!$data && count($dataRaw) > 0) {
                    $data = $dataRaw[count($dataRaw) - 1];
                }
            } else {
                // Single object
                $data = $dataRaw;
            }

            if (!$data) {
                return response()->json(['status' => 'pending']);
            }

            $statusVal  = strtolower((string) ($data['status'] ?? $data['statusvalue'] ?? ''));
            $isSuccess  = ($statusVal === 'success' || $statusVal === '1');
            $isFailed   = in_array($statusVal, ['failed', 'qr_expired', '4', '5']);

            if ($isSuccess && $deposit->status == Status::PAYMENT_INITIATE) {
                // Node uses payer_amount to correctly match original amount (not settlement amount)
                $paidAmount     = (float) ($data['payer_amount'] ?? 0);
                $expectedAmount = (float) $deposit->final_amount;
                $amountMatched  = self::matchAmount($expectedAmount, $paidAmount);

                Log::info(self::LOG_TAG . ' [POLL/AMOUNT-CHECK]', [
                    'trx'          => $trx,
                    'expected'     => $expectedAmount,
                    'received'     => $paidAmount,
                    'payer_amount' => $data['payer_amount'] ?? 'N/A',
                    'amount_field' => $data['amount']       ?? 'N/A',
                    'matched'      => $amountMatched,
                ]);

                if ($amountMatched) {
                    $deposit->detail = $result;
                    $deposit->save();
                    PaymentController::userDataUpdate($deposit);

                    Log::info(self::LOG_TAG . ' [POLL/PAYMENT-CONFIRMED]', [
                        'trx'        => $trx,
                        'deposit_id' => $deposit->id,
                        'rrn'        => $data['rrn_number'] ?? 'N/A',
                        'payer_vpa'  => $data['payer_vpa']  ?? 'N/A',
                    ]);

                    return response()->json([
                        'status'   => 'success',
                        'redirect' => route('checkout.confirmation', $deposit->order->order_number),
                    ]);
                } else {
                    Log::error(self::LOG_TAG . ' [ERR/POLL-AMOUNT-MISMATCH]', [
                        'trx'      => $trx,
                        'expected' => $expectedAmount,
                        'received' => $paidAmount,
                    ]);
                    return response()->json([
                        'status'  => 'failed',
                        'message' => 'Amount mismatch. Please contact support.',
                    ]);
                }
            }

            // Terminal failure — stop polling
            if ($isFailed) {
                Log::info(self::LOG_TAG . ' [POLL/TERMINAL-FAIL]', [
                    'trx'        => $trx,
                    'statusvalue'=> $statusVal,
                ]);
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'Payment failed or QR expired. Please go back and try again.',
                ]);
            }

            // 2=Initiated, 3=QRGenerated, 6=Pending — keep polling
            return response()->json(['status' => 'pending']);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . ' [ERR/POLL-EXCEPTION]', [
                'trx'       => $trx,
                'exception' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'pending']);
        }
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Resolve and validate credentials from gateway_parameter JSON string.
     * Mirrors Node resolveJioCredentialsSync() exactly.
     *
     * @throws \Exception if required fields are missing
     */
    private static function resolveCredentials(string $rawJson): array
    {
        $r = json_decode($rawJson, true) ?? [];

        $clientId      = (string) ($r['client_id']      ?? $r['clientId']      ?? '');
        $clientSecret  = (string) ($r['client_secret']  ?? $r['clientSecret']  ?? '');
        $encryptionKey = (string) ($r['encryption_key'] ?? $r['encryptionKey'] ?? '');
        $encryptionIv  = (string) ($r['encryption_iv']  ?? $r['encryptionIv']  ?? '');

        if (!$clientId || !$clientSecret || !$encryptionKey || !$encryptionIv) {
            throw new \Exception('Missing JIO credentials: clientId, clientSecret, encryptionKey, encryptionIv are all required.');
        }

        $rawPayinInitUrl   = (string) ($r['payin_init_url']   ?? $r['payinInitUrl']   ?? '');
        $rawPayinStatusUrl = (string) ($r['payin_status_url'] ?? $r['payinStatusUrl'] ?? '');

        $proxyUrl = rtrim((string) ($r['proxy_url'] ?? $r['proxyUrl'] ?? ''), '/');
        $isProxied = !empty($proxyUrl);

        // Determine the real upstream host for the X-PG-Target-Host header
        $originalApiHost = '';
        if ($isProxied) {
            $originalApiHost = self::extractHost($rawPayinInitUrl)
                            ?: self::extractHost($rawPayinStatusUrl);
        }

        $isProduction = filter_var($r['is_production'] ?? $r['isProduction'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'client_id'          => $clientId,
            'client_secret'      => $clientSecret,
            'api_id'             => (string) ($r['api_id']        ?? $r['apiId']        ?? '20260'),
            'api_id_status'      => (string) ($r['api_id_status'] ?? $r['apiIdStatus']  ?? '20247'),
            'bank_id'            => (string) ($r['bank_id']       ?? $r['bankId']       ?? '12'),
            'payee_vpa'          => (string) ($r['payee_vpa']     ?? $r['payeeVpa']     ?? ''),
            'expiry_time'        => (string) ($r['expiry_time']   ?? $r['expiryTime']   ?? '10'),
            'is_production'      => $isProduction,
            'encryption_key'     => $encryptionKey,
            'encryption_iv'      => $encryptionIv,
            // URLs are rewritten to go through proxy if proxy_url is configured
            'payin_init_url'     => self::rewriteUrl($rawPayinInitUrl,   $proxyUrl),
            'payin_status_url'   => self::rewriteUrl($rawPayinStatusUrl, $proxyUrl),
            'proxy_url'          => $proxyUrl,
            'is_proxied'         => $isProxied,
            'original_api_host'  => $originalApiHost,
        ];
    }

    /**
     * Rewrites a URL's origin (scheme + host) to route through the proxy.
     * Path and query string are preserved exactly.
     * Mirrors Node rewriteUrlThroughProxy().
     *
     * Example:
     *   rewriteUrl('https://nxt.sprintnxt.in/PayInExposeNxt/api/v2/UPIService/UPI',
     *              'https://checkout.novafin.tech')
     *   → 'https://checkout.novafin.tech/PayInExposeNxt/api/v2/UPIService/UPI'
     */
    private static function rewriteUrl(string $originalUrl, string $proxyBase): string
    {
        if (empty($proxyBase) || empty($originalUrl)) {
            return $originalUrl;
        }

        $parsedOriginal = parse_url($originalUrl);
        $parsedProxy    = parse_url($proxyBase);

        if (!$parsedOriginal || !$parsedProxy) {
            return $originalUrl; // Parse failed — return as-is
        }

        $scheme = $parsedProxy['scheme'] ?? $parsedOriginal['scheme'] ?? 'https';
        $host   = $parsedProxy['host']   ?? $parsedOriginal['host']   ?? '';
        $port   = isset($parsedProxy['port']) ? ':' . $parsedProxy['port'] : '';
        $path   = $parsedOriginal['path']  ?? '';
        $query  = isset($parsedOriginal['query']) ? '?' . $parsedOriginal['query'] : '';

        return rtrim($scheme . '://' . $host . $port . $path . $query, '/');
    }

    /**
     * Extracts the host from a URL.
     * Mirrors Node extractHost().
     */
    private static function extractHost(string $url): string
    {
        if (empty($url)) return '';
        $parsed = parse_url($url);
        if (!$parsed) return '';
        $host = $parsed['host'] ?? '';
        if (isset($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }
        return $host;
    }

    /**
     * Build auth headers — matches Node jioAuthHeaders() exactly.
     *
     * Client-id = base64_encode(clientId)
     * Token      = AES-256-CBC encrypt(JSON payload, encryptionKey, encryptionIv) → base64
     *
     * When proxied, adds X-PG-Target-Host so Nginx knows which upstream to forward to.
     */
    private static function buildAuthHeaders(array $creds): array
    {
        $clientIdEncoded = base64_encode($creds['client_id']);
        $token           = self::generateToken($creds);

        $headers = [
            'Client-id'    => $clientIdEncoded,
            'Token'        => $token,
            'accept'       => 'application/json',
            'content-type' => 'application/json',
        ];

        // When proxy is active, tell Nginx which upstream host to forward to
        if ($creds['is_proxied'] && !empty($creds['original_api_host'])) {
            $headers['X-PG-Target-Host'] = $creds['original_api_host'];
        }

        return $headers;
    }

    /**
     * Generate dynamic Token — mirrors Node generateJioToken() exactly.
     *
     * payload = JSON {
     *   client_secret: "...",
     *   requestid:     "9-digit padded random",   ← crypto.randomBytes(5).readUInt32BE(0)
     *   timestamp:     "unix_epoch_seconds"
     * }
     * token = AES-256-CBC encrypt(JSON(payload), key, iv) → base64
     */
    private static function generateToken(array $creds): string
    {
        // PHP equivalent of crypto.randomBytes(5).readUInt32BE(0).toString().padStart(9,'1')
        $randomBytes = random_bytes(5);
        $uint32      = unpack('N', substr($randomBytes, 0, 4))[1]; // big-endian uint32
        $requestId   = str_pad((string) $uint32, 9, '1', STR_PAD_LEFT);

        $payload = json_encode([
            'client_secret' => $creds['client_secret'],
            'requestid'     => $requestId,
            'timestamp'     => (string) time(),
        ]);

        return self::encryptAes($payload, $creds['encryption_key'], $creds['encryption_iv']);
    }

    /**
     * AES-256-CBC encryption — mirrors Node encryptJioPayload().
     *
     * Key: padded/truncated to 32 bytes (Node: Buffer.alloc(32) then copy)
     * IV:  padded/truncated to 16 bytes (Node: Buffer.alloc(16) then copy)
     * Output: base64
     */
    private static function encryptAes(string $plainText, string $key, string $iv): string
    {
        // Pad or truncate to exact sizes — mirrors Node Buffer.alloc() + copy()
        $keyBuf = str_pad(substr($key, 0, 32), 32, "\0");
        $ivBuf  = str_pad(substr($iv,  0, 16), 16, "\0");

        $encrypted = openssl_encrypt($plainText, 'AES-256-CBC', $keyBuf, OPENSSL_RAW_DATA, $ivBuf);

        return base64_encode($encrypted);
    }

    /**
     * Decrypt AES-256-CBC webhook payload — mirrors Node decryptJioWebhook().
     * Falls back to plain base64 decode when key/iv not available.
     *
     * @return array|null  Decoded payload, or null on failure
     */
    private function decryptWebhook(string $encdata): ?array
    {
        try {
            // Try to get creds from the JIO gateway record in DB
            $gateway = \App\Models\Gateway::where('alias', 'jio')->first();

            $key = null;
            $iv  = null;

            if ($gateway) {
                $params = json_decode($gateway->gateway_parameters, true);
                $key    = $params['encryption_key'] ?? null;
                $iv     = $params['encryption_iv']  ?? null;
            }

            if (empty($key) || empty($iv)) {
                // No keys — attempt plain base64 decode fallback (Node does this too)
                Log::warning(self::LOG_TAG . ' [WARN/DECRYPT-NO-KEYS] Attempting base64 fallback');
                $decoded = base64_decode($encdata, true);
                return $decoded ? json_decode($decoded, true) : null;
            }

            $encrypted = base64_decode($encdata, true);
            if ($encrypted === false) {
                Log::error(self::LOG_TAG . ' [ERR/DECRYPT-BASE64-FAIL]');
                return null;
            }

            $keyBuf = str_pad(substr($key, 0, 32), 32, "\0");
            $ivBuf  = str_pad(substr($iv,  0, 16), 16, "\0");

            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $keyBuf, OPENSSL_RAW_DATA, $ivBuf);
            if ($decrypted === false) {
                Log::error(self::LOG_TAG . ' [ERR/DECRYPT-OPENSSL-FAIL]');
                return null;
            }

            return json_decode($decrypted, true);

        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . ' [ERR/DECRYPT-EXCEPTION]', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Validate txnReferance: alphanumeric, 10–30 characters.
     * Mirrors Node generateJioTxnRef().
     */
    private static function makeTxnRef(string $trx): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $trx);
        $clean = substr($clean, 0, 30);
        if (strlen($clean) < 10) {
            $clean = str_pad($clean, 10, '0');
        }
        return $clean;
    }

    /**
     * Generate a valid Indian mobile number for the 'mobile' field.
     * Mirrors Node safeMobile() — if input is invalid/missing, returns a random valid number.
     */
    private static function safeMobile(?string $mobile): string
    {
        if ($mobile && preg_match('/^[6-9]\d{9}$/', $mobile)) {
            return $mobile;
        }
        $prefixes = ['6', '7', '8', '9'];
        $prefix   = $prefixes[array_rand($prefixes)];
        return $prefix . str_pad((string) mt_rand(0, 999999999), 9, '1', STR_PAD_LEFT);
    }

    /**
     * Amount matching with 1-rupee tolerance.
     * Mirrors Node CommonHelper.matchAmount() behaviour.
     * Adjust tolerance as needed for your platform.
     */
    private static function matchAmount(float $expected, float $received): bool
    {
        if ($received <= 0) return false;
        return abs($expected - $received) <= 1.0;
    }

    private static function webhookStatusMeaning(int $status): string
    {
        return match($status) {
            1  => 'Success',
            2  => 'Initiated',
            3  => 'QR Generated',
            4  => 'QR Expired',
            5  => 'Failed',
            6  => 'Pending/Timeout',
            default => 'Unknown (' . $status . ')',
        };
    }

    private static function errorJson(string $message): string
    {
        return json_encode(['error' => true, 'message' => $message]);
    }
}