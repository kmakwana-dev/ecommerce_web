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
 * gateway_parameter JSON (store in gateway_currencies.gateway_parameter):
 * {
 *   "client_id":        "SPR_NXT_prod_fb358171f6796c29",
 *   "client_secret":    "YOUR_SECRET",
 *   "api_id":           "20260",
 *   "api_id_status":    "20247",
 *   "bank_id":          "12",
 *   "payee_vpa":        "whimsicraftsolu.8080@jiomerchant",
 *   "expiry_time":      "10",
 *   "is_production":    true,
 *   "encryption_key":   "a0abf9087bd5b887a9a0f8d68c82eebc",
 *   "encryption_iv":    "c7a1a97450f3985c",
 *   "payin_init_url":   "https://api.sprintnxt.in/api/v2/UPIService/UPI",
 *   "payin_status_url": "https://api.sprintnxt.in/api/v2/UPIService/UPI",
 *   "proxy_url":        "https://checkout.novafin.tech"
 * }
 *
 * PROXY FLOW:
 *   Singapore App → checkout.novafin.tech (Indian VPS Nginx) → api.sprintnxt.in
 *   Header X-PG-Target-Host = "api.sprintnxt.in" tells Nginx which upstream to use.
 *
 * TOKEN (matches Node.js generateJioToken exactly):
 *   payload = JSON { client_secret, requestid (9-digit random), timestamp (epoch) }
 *   token   = AES-256-CBC(payload, key[0..31], iv[0..15]) → base64
 *   Client-id header = base64_encode(client_id)
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
    // =========================================================================
    public static function process($deposit): string
    {
        try {
            $raw   = self::extractRawJson($deposit->gatewayCurrency());
            $creds = self::resolveCredentials($raw);
        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . ' [ERR/INIT-CRED]', ['msg' => $e->getMessage()]);
            return self::errorJson($e->getMessage());
        }

        $txnRef   = self::makeTxnRef($deposit->trx);
        $endpoint = $creds['payin_init_url']; // already rewritten through proxy if set

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
            'env'         => $creds['is_production'] ? 'PRODUCTION' : 'UAT',
            'endpoint'    => $endpoint,
            'proxied'     => $creds['is_proxied'],
            'target_host' => $creds['original_api_host'],
            'trx'         => $deposit->trx,
            'txnRef'      => $txnRef,
            'deposit_id'  => $deposit->id,
            'amount'      => $deposit->final_amount,
            'payload'     => $payload,
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

            $details   = $result['details'] ?? [];
            $intentUrl = $details['intent_url'] ?? '';

            // Store txnReferance so webhook + status poll can look it up
            $deposit->btc_wallet = $txnRef;
            $deposit->save();

            Log::info(self::LOG_TAG . ' [3/INIT-SUCCESS]', [
                'trx'        => $deposit->trx,
                'txnRef'     => $txnRef,
                'UPIRefID'   => $details['UPIRefID'] ?? '',
                'intent_url' => substr($intentUrl, 0, 80) . '...',
            ]);

            if (empty($intentUrl)) {
                Log::error(self::LOG_TAG . ' [ERR/INIT-NO-INTENT-URL]', ['details' => $details]);
                return self::errorJson('No payment link returned by gateway.');
            }

            $send = [
                'intent_url'    => $intentUrl,
                'upi_ref_id'    => $details['UPIRefID']      ?? $txnRef,
                'txn_reference' => $details['txnReferance']  ?? $txnRef,
                'payee_vpa'     => $details['payeeVPA']       ?? $creds['payee_vpa'],
                'amount'        => $deposit->final_amount,
                'currency'      => $deposit->method_currency,
                'expiry_min'    => (int) $creds['expiry_time'],
                'status_url'    => route('ipn.jio.status'),
                'trx'           => $deposit->trx,
                'view'          => 'user.payment.jio',
                'method'        => 'GET',
                'url'           => route('ipn.jio'),
            ];

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
    //  POST https://yourdomain.com/ipn/jio
    //  Required response: {"responseMessage":"Successful","returnCode":"0"}
    // =========================================================================
    public function ipn(Request $request)
    {
        $rawBody = $request->getContent();
        $payload = $request->all();

        Log::info(self::LOG_TAG . ' [4/WEBHOOK-RECEIVED]', [
            'ip'           => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'raw_body'     => $rawBody,
        ]);

        // Production sends {"encdata":"<AES-256-CBC base64>"}, UAT sends plain JSON
        if (isset($payload['encdata'])) {
            Log::info(self::LOG_TAG . ' [4a/WEBHOOK-ENCRYPTED]');
            $decrypted = $this->decryptWebhook($payload['encdata']);
            if ($decrypted === null) {
                Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-DECRYPT-FAIL]');
                return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
            }
            $payload = $decrypted;
            Log::info(self::LOG_TAG . ' [4b/WEBHOOK-DECRYPTED]', ['payload' => $payload]);
        }

        $response = $payload['response'] ?? $payload;
        $wStatus  = (int) ($response['status'] ?? 0);

        Log::info(self::LOG_TAG . ' [5/WEBHOOK-PARSED]', [
            'status'   => $wStatus,
            'refid'    => $response['refid']    ?? '',
            'ref_id'   => $response['ref_id']   ?? '',
            'upiRefId' => $response['upiRefId'] ?? '',
            'amount'   => $response['amount']   ?? '',
            'utr'      => $response['utr']       ?? '',
        ]);

        // Matches Node: refid → ref_id → upiRefId
        $txnRef = $response['refid'] ?? $response['ref_id'] ?? $response['upiRefId'] ?? null;

        if (!$txnRef) {
            Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-NO-TXNREF]', ['response' => $response]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        $deposit = Deposit::where('btc_wallet', $txnRef)->orderBy('id', 'DESC')->first();

        if (!$deposit) {
            Log::warning(self::LOG_TAG . ' [WARN/WEBHOOK-DEPOSIT-NOT-FOUND]', ['txnRef' => $txnRef]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        // Already SUCCESS — skip
        if ($deposit->status == Status::PAYMENT_SUCCESS) {
            Log::info(self::LOG_TAG . ' [WEBHOOK-ALREADY-SUCCESS]', ['deposit_id' => $deposit->id]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        if ($wStatus === 1) {
            // Node uses payer_amount first — response.amount may be post-fee settlement
            $paidAmount     = (float) ($response['payer_amount'] ?? $response['amount'] ?? 0);
            $expectedAmount = (float) $deposit->final_amount;
            $amountMatched  = self::matchAmount($expectedAmount, $paidAmount);

            Log::info(self::LOG_TAG . ' [6/WEBHOOK-AMOUNT-CHECK]', [
                'expected'     => $expectedAmount,
                'received'     => $paidAmount,
                'matched'      => $amountMatched,
            ]);

            if ($amountMatched && $deposit->status == Status::PAYMENT_INITIATE) {
                $deposit->detail = $payload;
                $deposit->save();
                PaymentController::userDataUpdate($deposit);

                Log::info(self::LOG_TAG . ' [7/WEBHOOK-CONFIRMED]', [
                    'deposit_id' => $deposit->id,
                    'trx'        => $deposit->trx,
                    'utr'        => $response['utr']      ?? 'N/A',
                    'payer_vpa'  => $response['PayerVPA'] ?? 'N/A',
                ]);
            } elseif (!$amountMatched) {
                Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-AMOUNT-MISMATCH]', [
                    'deposit_id' => $deposit->id,
                    'expected'   => $expectedAmount,
                    'received'   => $paidAmount,
                ]);
            } else {
                Log::info(self::LOG_TAG . ' [WEBHOOK-ALREADY-PROCESSED]', ['deposit_id' => $deposit->id]);
            }

        } elseif ($wStatus === 4 || $wStatus === 5) {
            Log::info(self::LOG_TAG . ' [WEBHOOK-TERMINAL-FAIL]', [
                'deposit_id' => $deposit->id,
                'wStatus'    => $wStatus,
            ]);
        }
        // Status 2/3/6 — pending, no action

        return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
    }

    // =========================================================================
    //  STEP 3 — AJAX Status Polling  (GET /ipn/jio/status?trx=...)
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

        if ($deposit->status == Status::PAYMENT_SUCCESS) {
            return response()->json([
                'status'   => 'success',
                'redirect' => route('checkout.confirmation', $deposit->order->order_number),
            ]);
        }

        if ($deposit->status != Status::PAYMENT_INITIATE) {
            return response()->json(['status' => 'pending']);
        }

        try {
            $raw   = self::extractRawJson($deposit->gatewayCurrency());
            $creds = self::resolveCredentials($raw);
        } catch (\Exception $e) {
            Log::error(self::LOG_TAG . ' [ERR/POLL-CRED]', ['msg' => $e->getMessage()]);
            return response()->json(['status' => 'pending']);
        }

        $endpoint = $creds['payin_status_url'];

        $statusPayload = [
            'apiId'  => $creds['api_id_status'],
            'bankId' => $creds['bank_id'],
            'txnId'  => $deposit->btc_wallet,
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

            Log::info(self::LOG_TAG . ' [POLL/STATUS-RESPONSE]', ['trx' => $trx, 'result' => $result]);

            $gatewayOk = $result['status'] ?? false;
            if ($gatewayOk !== true && $gatewayOk !== 1) {
                Log::warning(self::LOG_TAG . ' [POLL/API-ERROR]', ['trx' => $trx, 'result' => $result]);
                return response()->json(['status' => 'pending']);
            }

            // Node priority selection when data is an array
            $dataRaw = $result['data'] ?? null;
            $data    = self::pickBestStatusRecord($dataRaw);

            if (!$data) {
                return response()->json(['status' => 'pending']);
            }

            $statusVal = strtolower((string) ($data['status'] ?? $data['statusvalue'] ?? ''));
            $isSuccess = ($statusVal === 'success' || $statusVal === '1');
            $isFailed  = in_array($statusVal, ['failed', 'qr_expired', '4', '5']);

            if ($isSuccess && $deposit->status == Status::PAYMENT_INITIATE) {
                // Node uses payer_amount (not amount which may be post-fee)
                $paidAmount     = (float) ($data['payer_amount'] ?? 0);
                $expectedAmount = (float) $deposit->final_amount;
                $amountMatched  = self::matchAmount($expectedAmount, $paidAmount);

                Log::info(self::LOG_TAG . ' [POLL/AMOUNT-CHECK]', [
                    'trx'          => $trx,
                    'expected'     => $expectedAmount,
                    'payer_amount' => $paidAmount,
                    'matched'      => $amountMatched,
                ]);

                if ($amountMatched) {
                    $deposit->detail = $result;
                    $deposit->save();
                    PaymentController::userDataUpdate($deposit);

                    Log::info(self::LOG_TAG . ' [POLL/CONFIRMED]', [
                        'trx'       => $trx,
                        'rrn'       => $data['rrn_number'] ?? 'N/A',
                        'payer_vpa' => $data['payer_vpa']  ?? 'N/A',
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

            if ($isFailed) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'Payment failed or QR expired. Please go back and try again.',
                ]);
            }

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
     * Safely extract the gateway_parameter JSON string from a gateway currency model.
     *
     * ViserMart uses two column name variants depending on the module:
     *   gateway_currencies.gateway_parameter   (e-commerce / ViserMart)
     *   gateway_currencies.gateway_parameters  (some forks)
     *
     * This method tries both and also handles the case where Laravel has
     * already decoded the JSON into an array/object (json cast on the model).
     */
    private static function extractRawJson($gatewayCurrency): string
    {
        // Try both column name variants
        $raw = $gatewayCurrency->gateway_parameter
            ?? $gatewayCurrency->gateway_parameters
            ?? null;

        if ($raw === null) {
            // Log all available attributes to help diagnose column name issues
            Log::error(self::LOG_TAG . ' [ERR/CRED-COLUMN-NOT-FOUND]', [
                'available_keys' => array_keys($gatewayCurrency->getAttributes()),
            ]);
            throw new \Exception('Could not find gateway credential column. Check laravel.log for available keys.');
        }

        // If Laravel cast it to array/object already, re-encode to string
        if (is_array($raw) || is_object($raw)) {
            return json_encode($raw);
        }

        return (string) $raw;
    }

    /**
     * Resolve and validate credentials from gateway_parameter JSON.
     * Mirrors Node resolveJioCredentialsSync() exactly.
     * Supports both snake_case (DB) and camelCase keys.
     *
     * @throws \Exception on missing required fields
     */
    private static function resolveCredentials(string $rawJson): array
    {
        $r = json_decode($rawJson, true);

        if (!is_array($r)) {
            Log::error(self::LOG_TAG . ' [ERR/CRED-JSON-DECODE-FAIL]', [
                'raw_preview' => substr($rawJson, 0, 200),
            ]);
            throw new \Exception('JIO gateway_parameter is not valid JSON.');
        }

        $clientId      = (string) ($r['client_id']      ?? $r['clientId']      ?? '');
        $clientSecret  = (string) ($r['client_secret']  ?? $r['clientSecret']  ?? '');
        $encryptionKey = (string) ($r['encryption_key'] ?? $r['encryptionKey'] ?? '');
        $encryptionIv  = (string) ($r['encryption_iv']  ?? $r['encryptionIv']  ?? '');

        // Log what we found (mask secrets) to aid debugging
        Log::info(self::LOG_TAG . ' [CRED-RESOLVED]', [
            'client_id'       => $clientId ? substr($clientId, 0, 8) . '...' : 'MISSING',
            'client_secret'   => $clientSecret  ? '***set***' : 'MISSING',
            'encryption_key'  => $encryptionKey ? '***set***' : 'MISSING',
            'encryption_iv'   => $encryptionIv  ? '***set***' : 'MISSING',
            'payin_init_url'  => $r['payin_init_url']   ?? $r['payinInitUrl']   ?? 'MISSING',
            'proxy_url'       => $r['proxy_url']         ?? $r['proxyUrl']       ?? 'none',
        ]);

        if (!$clientId || !$clientSecret || !$encryptionKey || !$encryptionIv) {
            throw new \Exception(
                'Missing JIO credentials: ' . implode(', ', array_filter([
                    !$clientId      ? 'client_id'      : null,
                    !$clientSecret  ? 'client_secret'  : null,
                    !$encryptionKey ? 'encryption_key' : null,
                    !$encryptionIv  ? 'encryption_iv'  : null,
                ])) . ' are required.'
            );
        }

        $rawPayinInitUrl   = (string) ($r['payin_init_url']   ?? $r['payinInitUrl']   ?? '');
        $rawPayinStatusUrl = (string) ($r['payin_status_url'] ?? $r['payinStatusUrl'] ?? '');
        $proxyUrl          = rtrim((string) ($r['proxy_url'] ?? $r['proxyUrl'] ?? ''), '/');
        $isProxied         = !empty($proxyUrl);

        // Real upstream host for X-PG-Target-Host header (Nginx needs this)
        $originalApiHost = '';
        if ($isProxied) {
            $originalApiHost = self::extractHost($rawPayinInitUrl)
                            ?: self::extractHost($rawPayinStatusUrl);
        }

        $isProduction = filter_var(
            $r['is_production'] ?? $r['isProduction'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        return [
            'client_id'         => $clientId,
            'client_secret'     => $clientSecret,
            'api_id'            => (string) ($r['api_id']        ?? $r['apiId']        ?? '20260'),
            'api_id_status'     => (string) ($r['api_id_status'] ?? $r['apiIdStatus']  ?? '20247'),
            'bank_id'           => (string) ($r['bank_id']       ?? $r['bankId']       ?? '12'),
            'payee_vpa'         => (string) ($r['payee_vpa']     ?? $r['payeeVpa']     ?? ''),
            'expiry_time'       => (string) ($r['expiry_time']   ?? $r['expiryTime']   ?? '10'),
            'is_production'     => $isProduction,
            'encryption_key'    => $encryptionKey,
            'encryption_iv'     => $encryptionIv,
            // URLs already rewritten through proxy (path preserved, host swapped)
            'payin_init_url'    => self::rewriteUrl($rawPayinInitUrl,   $proxyUrl),
            'payin_status_url'  => self::rewriteUrl($rawPayinStatusUrl, $proxyUrl),
            'proxy_url'         => $proxyUrl,
            'is_proxied'        => $isProxied,
            'original_api_host' => $originalApiHost,
        ];
    }

    /**
     * Rewrite a URL's origin through the proxy, keeping path+query intact.
     * Mirrors Node rewriteUrlThroughProxy().
     *
     * Example:
     *   rewriteUrl('https://api.sprintnxt.in/api/v2/UPIService/UPI',
     *              'https://checkout.novafin.tech')
     *   → 'https://checkout.novafin.tech/api/v2/UPIService/UPI'
     */
    private static function rewriteUrl(string $originalUrl, string $proxyBase): string
    {
        if (empty($proxyBase) || empty($originalUrl)) {
            return $originalUrl;
        }

        $orig  = parse_url($originalUrl);
        $proxy = parse_url($proxyBase);

        if (!$orig || !$proxy) {
            return $originalUrl;
        }

        $scheme = $proxy['scheme']   ?? $orig['scheme'] ?? 'https';
        $host   = $proxy['host']     ?? $orig['host']   ?? '';
        $port   = isset($proxy['port']) ? ':' . $proxy['port'] : '';
        $path   = $orig['path']      ?? '';
        $query  = isset($orig['query']) ? '?' . $orig['query'] : '';

        return rtrim($scheme . '://' . $host . $port . $path . $query, '/');
    }

    /**
     * Extract host (with port if non-standard) from a URL.
     * Mirrors Node extractHost().
     */
    private static function extractHost(string $url): string
    {
        if (empty($url)) return '';
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return '';
        return $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * Build auth headers — mirrors Node jioAuthHeaders() exactly.
     *
     * Client-id = base64_encode(clientId)          ← Node: Buffer.from(clientId).toString('base64')
     * Token      = generateToken() → AES encrypted  ← Node: generateJioToken()
     * X-PG-Target-Host added only when proxied      ← tells Nginx upstream host
     */
    private static function buildAuthHeaders(array $creds): array
    {
        $headers = [
            'Client-id'    => base64_encode($creds['client_id']),
            'Token'        => self::generateToken($creds),
            'accept'       => 'application/json',
            'content-type' => 'application/json',
        ];

        if ($creds['is_proxied'] && !empty($creds['original_api_host'])) {
            $headers['X-PG-Target-Host'] = $creds['original_api_host'];
        }

        return $headers;
    }

    /**
     * Generate dynamic Token per request.
     * Mirrors Node generateJioToken() exactly.
     *
     * PHP equivalent of:
     *   crypto.randomBytes(5).readUInt32BE(0).toString().padStart(9, '1')
     *
     * payload = JSON { client_secret, requestid, timestamp }
     * token   = AES-256-CBC encrypt(JSON, key, iv) → base64
     */
    private static function generateToken(array $creds): string
    {
        // Read first 4 bytes of 5 random bytes as big-endian uint32 → matches Node readUInt32BE(0)
        $bytes    = random_bytes(5);
        $uint32   = unpack('N', substr($bytes, 0, 4))[1];
        $requestId = str_pad((string) $uint32, 9, '1', STR_PAD_LEFT);

        $payload = json_encode([
            'client_secret' => $creds['client_secret'],
            'requestid'     => $requestId,
            'timestamp'     => (string) time(),
        ]);

        return self::encryptAes($payload, $creds['encryption_key'], $creds['encryption_iv']);
    }

    /**
     * AES-256-CBC encrypt → base64.
     * Mirrors Node encryptJioPayload():
     *   keyBuf = Buffer.alloc(32); Buffer.from(key,'utf8').copy(keyBuf)  → pad/truncate to 32
     *   ivBuf  = Buffer.alloc(16); Buffer.from(iv, 'utf8').copy(ivBuf)   → pad/truncate to 16
     */
    private static function encryptAes(string $plainText, string $key, string $iv): string
    {
        $keyBuf = str_pad(substr($key, 0, 32), 32, "\0"); // exactly 32 bytes
        $ivBuf  = str_pad(substr($iv,  0, 16), 16, "\0"); // exactly 16 bytes

        $encrypted = openssl_encrypt($plainText, 'AES-256-CBC', $keyBuf, OPENSSL_RAW_DATA, $ivBuf);

        return base64_encode($encrypted);
    }

    /**
     * Decrypt AES-256-CBC webhook encdata.
     * Mirrors Node decryptJioWebhook().
     * Falls back to raw base64 decode when no keys are configured.
     */
    private function decryptWebhook(string $encdata): ?array
    {
        try {
            $key = null;
            $iv  = null;

            // Try to pull keys from the Gateway model (alias = 'jio')
            $gateway = \App\Models\Gateway::where('alias', 'jio')->first();
            if ($gateway) {
                // Handle both column name variants
                $paramJson = $gateway->gateway_parameters
                          ?? $gateway->gateway_parameter
                          ?? null;

                $params = is_array($paramJson)
                    ? $paramJson
                    : json_decode((string) $paramJson, true);

                $key = $params['encryption_key'] ?? null;
                $iv  = $params['encryption_iv']  ?? null;
            }

            if (empty($key) || empty($iv)) {
                Log::warning(self::LOG_TAG . ' [WARN/DECRYPT-NO-KEYS] base64 fallback');
                $decoded = base64_decode($encdata, true);
                return $decoded ? json_decode($decoded, true) : null;
            }

            $encrypted = base64_decode($encdata, true);
            if ($encrypted === false) {
                Log::error(self::LOG_TAG . ' [ERR/DECRYPT-BASE64-FAIL]');
                return null;
            }

            $keyBuf    = str_pad(substr($key, 0, 32), 32, "\0");
            $ivBuf     = str_pad(substr($iv,  0, 16), 16, "\0");
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
     * Node priority selection when status API returns data as an array.
     *
     * Priority 1: any Success record
     * Priority 2: any Pending/Initiated record
     * Priority 3: last record (fallback)
     */
    private static function pickBestStatusRecord($dataRaw): ?array
    {
        if (!$dataRaw) return null;

        // Single object — return as-is
        if (is_array($dataRaw) && !isset($dataRaw[0])) {
            return $dataRaw;
        }

        if (!is_array($dataRaw) || count($dataRaw) === 0) {
            return null;
        }

        // Priority 1: Success
        foreach ($dataRaw as $item) {
            $s = strtolower((string) ($item['status'] ?? $item['statusvalue'] ?? ''));
            if ($s === 'success' || $s === '1') return $item;
        }

        // Priority 2: Pending/Initiated
        $pendingCodes = ['2', '3', '6', 'initiated', 'qr_generated', 'qrgenerated', 'pending'];
        foreach ($dataRaw as $item) {
            $s = strtolower((string) ($item['status'] ?? $item['statusvalue'] ?? ''));
            if (in_array($s, $pendingCodes)) return $item;
        }

        // Priority 3: Last item
        return $dataRaw[count($dataRaw) - 1];
    }

    /**
     * txnReferance: alphanumeric, 10–30 chars. Mirrors Node generateJioTxnRef().
     */
    private static function makeTxnRef(string $trx): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $trx);
        $clean = substr($clean, 0, 30);
        return strlen($clean) < 10 ? str_pad($clean, 10, '0') : $clean;
    }

    /**
     * Safe Indian mobile number. Mirrors Node safeMobile().
     */
    private static function safeMobile(?string $mobile): string
    {
        if ($mobile && preg_match('/^[6-9]\d{9}$/', $mobile)) {
            return $mobile;
        }
        $prefix = ['6', '7', '8', '9'][array_rand(['6', '7', '8', '9'])];
        return $prefix . str_pad((string) mt_rand(0, 999999999), 9, '1', STR_PAD_LEFT);
    }

    /**
     * Amount match with ₹1 tolerance.
     * Mirrors Node CommonHelper.matchAmount() typical behaviour.
     */
    private static function matchAmount(float $expected, float $received): bool
    {
        if ($received <= 0) return false;
        return abs($expected - $received) <= 1.0;
    }

    private static function errorJson(string $message): string
    {
        return json_encode(['error' => true, 'message' => $message]);
    }
}