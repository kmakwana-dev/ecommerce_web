<?php

/**
 * FILE LOCATION:
 *   core/app/Http/Controllers/Gateway/Jio/ProcessController.php
 *
 * GATEWAY DB REQUIREMENTS (already done):
 *   gateways.code = 60  (must be < 1000 so automatic flow fires)
 *   gateway_currencies.gateway_parameter = full JSON with all credentials
 *
 * gateway_parameters JSON:
 * {
 *   "client_id":      "U1BSX05YVF9sb2NhbF9mY2MxYzFlMDMwMTQ2YTk4",
 *   "user_agent":     "NXT198342",
 *   "token":          "<long_token>",
 *   "api_id":         "20260",       ← used for QR init only
 *   "api_id_status":  "20247",       ← used for status check (add this field)
 *   "bank_id":        "12",
 *   "payee_vpa":      "sprintnxt.8080@jiomerchant",
 *   "expiry_time":    "10",
 *   "is_production":  false,
 *   "encryption_key": "56c271c135e53d1a0281ea8b0b6c8e02",
 *   "encryption_iv":  "5158c4f228be322e"
 * }
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
    // ─────────────────────────────────────────────────────────────────────────
    //  API URLs
    //  IMPORTANT: Init and Status use DIFFERENT base paths on SprintNXT
    // ─────────────────────────────────────────────────────────────────────────

    // QR / Intent generation
    private const URL_INIT_UAT        = 'https://nxt-nonprod.sprintnxt.in/PayInExposeNxt/api/v2/UPIService/UPI';
    private const URL_INIT_PRODUCTION = 'https://nxt.sprintnxt.in/PayInExposeNxt/api/v2/UPIService/UPI';

    // Transaction status check (different path: NonProdNextgenAPIExpose)
    private const URL_STATUS_UAT        = 'https://nxt-nonprod.sprintnxt.in/NonProdNextgenAPIExpose/api/v2/UPIService/UPI';
    private const URL_STATUS_PRODUCTION = 'https://nxt.sprintnxt.in/NextgenAPIExpose/api/v2/UPIService/UPI';

    // Fixed apiId for status check (always 20247 per SprintNXT docs — different from init apiId)
    private const API_ID_STATUS = 20247;

    // ─────────────────────────────────────────────────────────────────────────
    //  UAT Signoff Log channel tag — every API interaction is logged with this
    //  prefix so you can grep/export them easily for SprintNXT UAT signoff
    // ─────────────────────────────────────────────────────────────────────────
    private const LOG_TAG = '[JIO-UAT]';

    // =========================================================================
    //  STEP 1 — QR / Intent Initiation
    //  Called by PaymentController::depositConfirm() via static process()
    // =========================================================================
    public static function process($deposit): string
    {
        $acc = json_decode($deposit->gatewayCurrency()->gateway_parameter);

        $clientId  = $acc->client_id;
        $userAgent = $acc->user_agent;
        $token     = $acc->token;
        $apiId     = $acc->api_id;
        $bankId    = $acc->bank_id    ?? '12';
        $payeeVPA  = $acc->payee_vpa;
        $expiryMin = $acc->expiry_time ?? '10';
        $isProd    = isset($acc->is_production) && $acc->is_production;

        $txnRef   = self::makeTxnRef($deposit->trx);
        $endpoint = $isProd ? self::URL_INIT_PRODUCTION : self::URL_INIT_UAT;

        $payload = [
            'apiId'        => $apiId,
            'bankId'       => $bankId,
            'amount'       => (string) round($deposit->final_amount, 2),
            'payeeVPA'     => $payeeVPA,
            'mobile'       => $deposit->customer->mobileNumber ?? '9999999999',
            'ExpiryTime'   => (string) $expiryMin,
            'txnNote'      => 'Order Payment',
            'txnReferance' => $txnRef,
        ];

        // ── UAT Signoff Log: Init Request ─────────────────────────────────────
        Log::info(self::LOG_TAG . ' [1/INIT-REQUEST]', [
            'environment' => $isProd ? 'PRODUCTION' : 'UAT',
            'endpoint'    => $endpoint,
            'trx'         => $deposit->trx,
            'txnRef'      => $txnRef,
            'deposit_id'  => $deposit->id,
            'amount'      => $deposit->final_amount,
            'currency'    => $deposit->method_currency,
            'payload'     => $payload,
        ]);

        try {
            $response = Http::timeout(30)
                ->withHeaders(self::headers($clientId, $userAgent, $token))
                ->post($endpoint, $payload);

            $httpStatus = $response->status();
            $result     = $response->json();

            // ── UAT Signoff Log: Init Response ──────────────────────────────
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

            if (!($result['status'] ?? false)) {
                Log::error(self::LOG_TAG . ' [ERR/INIT-GATEWAY-FAIL]', [
                    'trx'     => $deposit->trx,
                    'message' => $result['message'] ?? 'Unknown',
                    'result'  => $result,
                ]);
                return self::errorJson($result['message'] ?? 'Gateway error. Please try again.');
            }

            $details = $result['details'];

            // Store txnReferance in btc_wallet — used to match webhook and status check
            $deposit->btc_wallet = $txnRef;
            $deposit->save();

            Log::info(self::LOG_TAG . ' [3/INIT-SUCCESS-QR-GENERATED]', [
                'trx'          => $deposit->trx,
                'txnRef'       => $txnRef,
                'UPIRefID'     => $details['UPIRefID'] ?? '',
                'payeeVPA'     => $details['payeeVPA'] ?? '',
                'merchantId'   => $details['merchantId'] ?? '',
                'intent_url'   => substr($details['intent_url'] ?? '', 0, 80) . '...',
            ]);

            $send['intent_url']    = $details['intent_url'];
            $send['upi_ref_id']    = $details['UPIRefID']     ?? $txnRef;
            $send['txn_reference'] = $details['txnReferance'] ?? $txnRef;
            $send['payee_vpa']     = $details['payeeVPA']     ?? $payeeVPA;
            $send['amount']        = $deposit->final_amount;
            $send['currency']      = $deposit->method_currency;
            $send['expiry_min']    = (int) $expiryMin;
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
    //  STEP 2 — Webhook / IPN handler
    //  SprintNXT POSTs to https://yourdomain.com/ipn/jio after each payment.
    //
    //  UAT: SprintNXT sends plain JSON (no encryption)
    //  Production: SprintNXT sends {"encdata":"<AES-256-CBC encrypted>"}
    //
    //  To TEST manually in UAT, use the /jio/webhook-test admin route.
    //  Required response format: {"responseMessage":"Successful","returnCode":"0"}
    // =========================================================================
    public function ipn(Request $request)
    {
        $rawBody = $request->getContent();
        $payload = $request->all();

        // ── UAT Signoff Log: Webhook Received ────────────────────────────────
        Log::info(self::LOG_TAG . ' [4/WEBHOOK-RECEIVED]', [
            'ip'          => $request->ip(),
            'method'      => $request->method(),
            'content_type'=> $request->header('Content-Type'),
            'raw_body'    => $rawBody,
            'parsed'      => $payload,
        ]);

        // ── Decrypt if production encdata ────────────────────────────────────
        if (isset($payload['encdata'])) {
            Log::info(self::LOG_TAG . ' [4a/WEBHOOK-ENCRYPTED] Attempting decryption');

            $decrypted = $this->decryptWebhook($payload['encdata']);
            if ($decrypted === null) {
                Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-DECRYPT-FAIL]', [
                    'encdata_length' => strlen($payload['encdata']),
                ]);
                // Still return 200 — we don't want SprintNXT to keep retrying
                return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
            }

            $payload = $decrypted;
            Log::info(self::LOG_TAG . ' [4b/WEBHOOK-DECRYPTED]', ['payload' => $payload]);
        }

        /*
         * JIO Bank webhook payload structure (plain or after decryption):
         * {
         *   "event":    "upi",
         *   "bank":     "JIO",
         *   "response": {
         *     "status":              1,       ← 1=Success, 4=Expired, 5=Failed, 6=Pending
         *     "amount":              "799",
         *     "utr":                 "...",
         *     "refid":               "TXNREF",    ← our txnReferance
         *     "upiRefId":            "TXNREF",    ← same
         *     "txnid":               "...",
         *     "receiver_vpa":        "...",
         *     "remarks":             "SUCCESS",
         *     "PayerVPA":            "user@upi",
         *     "PayerName":           "...",
         *     "TransactionDateTime": "...",
         *     "txnNote":             "Order Payment"
         *   }
         * }
         */
        $response   = $payload['response'] ?? $payload;
        $wStatus    = (int) ($response['status'] ?? 0);
        $event      = $payload['event']  ?? 'unknown';
        $bank       = $payload['bank']   ?? 'unknown';

        Log::info(self::LOG_TAG . ' [5/WEBHOOK-PARSED]', [
            'event'   => $event,
            'bank'    => $bank,
            'status'  => $wStatus,
            'refid'   => $response['refid']    ?? '',
            'upiRefId'=> $response['upiRefId'] ?? '',
            'amount'  => $response['amount']   ?? '',
            'utr'     => $response['utr']      ?? '',
            'remarks' => $response['remarks']  ?? '',
        ]);

        // Non-success statuses — acknowledge and skip
        if ($wStatus !== 1) {
            Log::info(self::LOG_TAG . ' [5a/WEBHOOK-NON-SUCCESS]', [
                'status'  => $wStatus,
                'meaning' => self::webhookStatusMeaning($wStatus),
            ]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        // Find our deposit by txnReferance stored in btc_wallet
        // SprintNXT echoes it back in both refid and upiRefId
        $txnRef = $response['upiRefId'] ?? $response['refid'] ?? $response['txnid'] ?? null;

        if (!$txnRef) {
            Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-NO-TXNREF]', ['response' => $response]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        $deposit = Deposit::where('btc_wallet', $txnRef)
            ->orderBy('id', 'DESC')
            ->first();

        if (!$deposit) {
            Log::warning(self::LOG_TAG . ' [WARN/WEBHOOK-DEPOSIT-NOT-FOUND]', [
                'txnRef'     => $txnRef,
                'searched_in'=> 'deposits.btc_wallet',
            ]);
            // Return 200 — SprintNXT must not keep retrying
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        Log::info(self::LOG_TAG . ' [6/WEBHOOK-DEPOSIT-FOUND]', [
            'deposit_id'    => $deposit->id,
            'deposit_status'=> $deposit->status,
            'txnRef'        => $txnRef,
        ]);

        if ($deposit->status == Status::PAYMENT_INITIATE) {
            $deposit->detail = $payload;
            $deposit->save();
            PaymentController::userDataUpdate($deposit);

            Log::info(self::LOG_TAG . ' [7/WEBHOOK-PAYMENT-CONFIRMED]', [
                'deposit_id' => $deposit->id,
                'trx'        => $deposit->trx,
                'amount'     => $deposit->final_amount,
                'utr'        => $response['utr'] ?? 'N/A',
                'payer_vpa'  => $response['PayerVPA'] ?? 'N/A',
            ]);
        } else {
            Log::info(self::LOG_TAG . ' [7a/WEBHOOK-ALREADY-PROCESSED]', [
                'deposit_id'    => $deposit->id,
                'current_status'=> $deposit->status,
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

        // Already confirmed by webhook — just redirect
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

        $acc      = json_decode($deposit->gatewayCurrency()->gateway_parameter);
        $isProd   = isset($acc->is_production) && $acc->is_production;
        $endpoint = $isProd ? self::URL_STATUS_PRODUCTION : self::URL_STATUS_UAT;

        $statusPayload = [
            'apiId'  => self::API_ID_STATUS,     // Always 20247 for status check
            'bankId' => $acc->bank_id ?? '12',
            'txnId'  => $deposit->btc_wallet,     // txnReferance sent at init
        ];

        Log::info(self::LOG_TAG . ' [POLL/STATUS-REQUEST]', [
            'trx'      => $trx,
            'endpoint' => $endpoint,
            'payload'  => $statusPayload,
        ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders(self::headers($acc->client_id, $acc->user_agent, $acc->token))
                ->post($endpoint, $statusPayload);

            $result = $response->json();

            Log::info(self::LOG_TAG . ' [POLL/STATUS-RESPONSE]', [
                'trx'    => $trx,
                'result' => $result,
            ]);

            /*
             * Status check response:
             * {
             *   "status": true,
             *   "responsecode": 1,
             *   "message": "Transaction Found!",
             *   "data": {
             *     "statusvalue":   3,            ← THE transaction status
             *     "status":        "QRGenerated",
             *     "rrn_number":    "",           ← populated on success
             *     "payer_vpa":     "",
             *     "payer_amount":  "",
             *     "amount":        5097,
             *     "txn_id":        "...",
             *     ...
             *   }
             * }
             *
             * statusvalue: 1=Success, 2=Initiated, 3=QRGenerated, 4=Expired, 5=Failed, 6=Pending
             */
            $txnStatus = (int) ($result['data']['statusvalue'] ?? 0);

            // API-level failure (wrong endpoint, auth fail etc.) — keep polling, don't fail user
            if (!($result['status'] ?? false)) {
                Log::warning(self::LOG_TAG . ' [POLL/API-ERROR]', [
                    'trx'    => $trx,
                    'result' => $result,
                ]);
                return response()->json(['status' => 'pending']);
            }

            if ($txnStatus === 1 && $deposit->status == Status::PAYMENT_INITIATE) {
                $deposit->detail = $result;
                $deposit->save();
                PaymentController::userDataUpdate($deposit);

                Log::info(self::LOG_TAG . ' [POLL/PAYMENT-CONFIRMED]', [
                    'trx'        => $trx,
                    'deposit_id' => $deposit->id,
                    'rrn'        => $result['data']['rrn_number'] ?? 'N/A',
                    'payer_vpa'  => $result['data']['payer_vpa']  ?? 'N/A',
                ]);

                return response()->json([
                    'status'   => 'success',
                    'redirect' => route('checkout.confirmation', $deposit->order->order_number),
                ]);
            }

            // QR expired or payment failed — stop polling, show error
            if (in_array($txnStatus, [4, 5])) {
                Log::info(self::LOG_TAG . ' [POLL/TERMINAL-FAIL]', [
                    'trx'         => $trx,
                    'statusvalue' => $txnStatus,
                    'meaning'     => self::statusValueMeaning($txnStatus),
                ]);
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'Payment ' . self::statusValueMeaning($txnStatus) . '. Please go back and try again.',
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
    //  MANUAL WEBHOOK TEST (Admin only — for UAT testing)
    //  Route: GET /admin/jio/webhook-test
    //  Lets you simulate a SprintNXT webhook POST to your own /ipn/jio
    //  endpoint with a real txnRef from your DB, to verify the full flow.
    // =========================================================================
    public function webhookTestPage(Request $request)
    {
        // Get recent Jio deposits for the test form dropdown
        $deposits = Deposit::whereHas('gateway', function ($q) {
                $q->where('alias', 'jio');
            })
            ->whereIn('status', [Status::PAYMENT_INITIATE, Status::PAYMENT_PENDING])
            ->orderBy('id', 'DESC')
            ->take(10)
            ->get();

        $pageTitle = 'Jio Webhook Test (UAT)';
        return view('admin.gateways.jio_webhook_test', compact('pageTitle', 'deposits'));
    }

    public function webhookTestFire(Request $request)
    {
        $txnRef  = $request->input('txn_ref');
        $status  = (int) $request->input('status', 1);  // default success
        $amount  = $request->input('amount', '10');

        if (!$txnRef) {
            return response()->json(['error' => 'txn_ref is required']);
        }

        // Build the plain JSON payload exactly as SprintNXT sends it (UAT = no encryption)
        $webhookPayload = [
            'event' => 'upi',
            'bank'  => 'JIO',
            'response' => [
                'status'              => $status,
                'amount'              => $amount,
                'utr'                 => 'UTR' . strtoupper(substr(md5(microtime()), 0, 12)),
                'refid'               => $txnRef,
                'receiver_name'       => 'Test Merchant',
                'receiver_vpa'        => 'sprintnxt.8080@jiomerchant',
                'txnid'               => $txnRef . 'U419',
                'upiRefId'            => $txnRef,
                'remarks'             => $status === 1 ? 'SUCCESS' : 'FAILED',
                'PayerMobileNumber'   => '9999999999',
                'PayerVPA'            => 'test@upi',
                'PayerName'           => 'Test User',
                'TransactionDateTime' => now()->format('Y-m-d H:i:s'),
                'txnNote'             => 'Order Payment',
            ],
        ];

        // POST to our own IPN endpoint
        $ipnUrl = route('ipn.jio');

        Log::info(self::LOG_TAG . ' [TEST/WEBHOOK-FIRED]', [
            'target_url' => $ipnUrl,
            'payload'    => $webhookPayload,
        ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($ipnUrl, $webhookPayload);

            return response()->json([
                'fired_to'       => $ipnUrl,
                'payload_sent'   => $webhookPayload,
                'response_status'=> $response->status(),
                'response_body'  => $response->json(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private static function headers(string $clientId, string $userAgent, string $token): array
    {
        return [
            'Client-id'    => $clientId,
            'user-agent'   => $userAgent,
            'Token'        => $token,
            'accept'       => 'application/json',
            'content-type' => 'application/json',
        ];
    }

    /**
     * JIO txnReferance: alphanumeric, 10–30 characters.
     * ViserMart trx is 12 chars, normally fine. We sanitize defensively.
     */
    private static function makeTxnRef(string $trx): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $trx);
        if (strlen($clean) < 10) {
            $clean = str_pad($clean, 10, '0');
        }
        if (strlen($clean) > 30) {
            $clean = substr($clean, 0, 30);
        }
        return $clean;
    }

    /**
     * Decrypt AES-256-CBC encdata from JIO Bank webhook (production only).
     * UAT sends plain JSON — no encdata field at all.
     */
    private function decryptWebhook(string $encdata): ?array
    {
        try {
            $gateway = \App\Models\Gateway::where('alias', 'jio')->first();
            if (!$gateway) {
                Log::error(self::LOG_TAG . ' [ERR/DECRYPT-NO-GATEWAY]');
                return null;
            }

            $params = json_decode($gateway->gateway_parameters);
            $key    = $params->encryption_key ?? null;
            $iv     = $params->encryption_iv  ?? null;

            if (empty($key) || empty($iv)) {
                // No keys configured — try base64 decode as fallback (won't work for real production)
                Log::warning(self::LOG_TAG . ' [WARN/DECRYPT-NO-KEYS] Attempting base64 fallback');
                $decoded = base64_decode($encdata, true);
                return $decoded ? json_decode($decoded, true) : null;
            }

            $encrypted = base64_decode($encdata, true);
            if ($encrypted === false) {
                Log::error(self::LOG_TAG . ' [ERR/DECRYPT-BASE64-FAIL]');
                return null;
            }

            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
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

    private static function webhookStatusMeaning(int $status): string
    {
        return match($status) {
            1 => 'Success',
            4 => 'QR Expired',
            5 => 'Failed',
            6 => 'Pending/Timeout',
            default => 'Unknown (' . $status . ')',
        };
    }

    private static function statusValueMeaning(int $sv): string
    {
        return match($sv) {
            1 => 'Success',
            2 => 'Initiated',
            3 => 'QR Generated',
            4 => 'QR Expired',
            5 => 'Failed',
            6 => 'Pending',
            default => 'Unknown (' . $sv . ')',
        };
    }

    private static function errorJson(string $message): string
    {
        return json_encode(['error' => true, 'message' => $message]);
    }
}