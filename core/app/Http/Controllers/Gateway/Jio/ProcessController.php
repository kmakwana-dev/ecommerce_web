<?php

/**
 * FILE LOCATION:
 *   core/app/Http/Controllers/Gateway/Jio/ProcessController.php
 *
 * ALSO REQUIRED — run this SQL once to move Jio out of manual range (≥1000)
 * so PaymentController::depositConfirm() routes it here instead of manualDepositConfirm():
 *
 *   UPDATE gateways          SET code = 60  WHERE alias = 'jio';
 *   UPDATE gateway_currencies SET method_code = 60 WHERE gateway_alias = 'jio';
 *
 * gateway_parameters JSON stored in DB (already correct, keep as-is):
 * {
 *   "client_id":   "U1BSX05YVF9sb2NhbF9mY2MxYzFlMDMwMTQ2YTk4",
 *   "user_agent":  "NXT198342",
 *   "token":       "<your_token>",
 *   "api_id":      "20260",
 *   "bank_id":     "12",
 *   "payee_vpa":   "sprintnxt.8080@jiomerchant",
 *   "expiry_time": "10",
 *   "is_production": false,          ← add this; set true for live
 *   "encryption_key": "<32-char-key-from-sprintnxt>",   ← for webhook decrypt
 *   "encryption_iv":  "<16-char-iv-from-sprintnxt>"     ← for webhook decrypt
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
    //  API base URLs
    // ─────────────────────────────────────────────────────────────────────────
    private const URL_UAT        = 'https://nxt-nonprod.sprintnxt.in/PayInExposeNxt/api/v2/UPIService/UPI';
    private const URL_PRODUCTION = 'https://nxt.sprintnxt.in/PayInExposeNxt/api/v2/UPIService/UPI';

    // Status check uses NonProdNextgenAPIExpose path (different from init which uses PayInExposeNxt)
    private const URL_STATUS_UAT        = 'https://nxt-nonprod.sprintnxt.in/NonProdNextgenAPIExpose/api/v2/UPIService/UPI';
    private const URL_STATUS_PRODUCTION = 'https://nxt.sprintnxt.in/NextgenAPIExpose/api/v2/UPIService/UPI';

    // ─────────────────────────────────────────────────────────────────────────
    //  STEP 1 — Called by PaymentController::depositConfirm()
    //  Initiates the UPI intent with SprintNXT and returns render data.
    // ─────────────────────────────────────────────────────────────────────────
    public static function process($deposit): string
    {
        $acc = json_decode($deposit->gatewayCurrency()->gateway_parameter);

        $clientId   = $acc->client_id;
        $userAgent  = $acc->user_agent;
        $token      = $acc->token;
        $apiId      = $acc->api_id;
        $bankId     = $acc->bank_id     ?? '12';
        $payeeVPA   = $acc->payee_vpa;
        $expiryMin  = $acc->expiry_time ?? '10';
        $isProd     = isset($acc->is_production) && $acc->is_production;

        // JIO txnReferance must be 10–30 chars — trx alone is fine if ≥10 chars
        // We trim/pad to stay in range safely
        $txnRef = self::makeTxnRef($deposit->trx);

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

        $endpoint = $isProd ? self::URL_PRODUCTION : self::URL_UAT;

        try {
            $response = Http::timeout(30)
                ->withHeaders(self::headers($clientId, $userAgent, $token))
                ->post($endpoint, $payload);

            if ($response->failed()) {
                Log::error('[Jio] HTTP Error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return self::errorJson('Gateway returned HTTP ' . $response->status() . '. Please try again.');
            }

            $result = $response->json();

            Log::info('[Jio] Init Response', ['trx' => $deposit->trx, 'result' => $result]);

            if (!($result['status'] ?? false)) {
                return self::errorJson($result['message'] ?? 'Gateway error. Please try again.');
            }

            $details = $result['details'];

            // Store the txnReferance we used — this is the key for status check & webhook matching
            $deposit->btc_wallet = $txnRef;
            $deposit->save();

            $send['intent_url']    = $details['intent_url'];
            $send['upi_ref_id']    = $details['UPIRefID']      ?? $txnRef;
            $send['txn_reference'] = $details['txnReferance']  ?? $txnRef;
            $send['payee_vpa']     = $details['payeeVPA']      ?? $payeeVPA;
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
            Log::error('[Jio] Exception during init: ' . $e->getMessage());
            return self::errorJson('Gateway connection failed. Please try again.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  STEP 2 — Webhook / IPN
    //  SprintNXT POST's to this URL after payment completes.
    //  In production the body is: {"encdata":"<AES-encrypted-string>"}
    //  In UAT the body may be plain JSON.
    // ─────────────────────────────────────────────────────────────────────────
    public function ipn(Request $request)
    {
        Log::info('[Jio] Webhook received', ['raw' => $request->getContent()]);

        $payload = $request->all();

        // ── Production: decrypt encdata ──────────────────────────────────────
        if (isset($payload['encdata'])) {
            $decrypted = $this->decryptWebhook($payload['encdata'], $request);
            if ($decrypted === null) {
                Log::warning('[Jio] Webhook decryption failed');
                return response()->json(['responseMessage' => 'Failed', 'returnCode' => '1'], 400);
            }
            $payload = $decrypted;
            Log::info('[Jio] Webhook decrypted', $payload);
        }

        /*
         * JIO Bank webhook structure (after decryption):
         * {
         *   "event": "upi",
         *   "bank":  "JIO",
         *   "response": {
         *     "status":     1,          ← 1=success, 4=expired, 5=failed, 6=pending
         *     "amount":     "10",
         *     "utr":        "...",
         *     "refid":      "...",
         *     "upiRefId":   "...",
         *     "txnid":      "...",
         *     "remarks":    "SUCCESS",
         *     "txnNote":    "..."
         *   }
         * }
         */
        $response = $payload['response'] ?? $payload;
        $status   = (int) ($response['status'] ?? 0);

        // Only process status=1 (Success)
        if ($status !== 1) {
            Log::info('[Jio] Webhook non-success status', ['status' => $status]);
            return response()->json(['responseMessage' => 'Received', 'returnCode' => '0']);
        }

        // Identify the deposit — SprintNXT echoes back txnNote or upiRefId
        // We store txnReferance in btc_wallet, and txnNote = 'Order Payment' isn't unique
        // upiRefId == our txnRef (we sent txnReferance = txnRef, and UPIRefID echoes it back)
        $txnRef = $response['upiRefId'] ?? $response['refid'] ?? $response['txnid'] ?? null;

        if (!$txnRef) {
            Log::warning('[Jio] Webhook missing txnRef', $payload);
            return response()->json(['responseMessage' => 'Missing ref', 'returnCode' => '1'], 400);
        }

        $deposit = Deposit::where('btc_wallet', $txnRef)
            ->orderBy('id', 'DESC')
            ->first();

        if (!$deposit) {
            Log::warning('[Jio] Webhook deposit not found', ['txnRef' => $txnRef]);
            // Return 200 so SprintNXT doesn't keep retrying for a non-existent deposit
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        if ($deposit->status == Status::PAYMENT_INITIATE) {
            $deposit->detail = $payload;
            $deposit->save();
            PaymentController::userDataUpdate($deposit);
            Log::info('[Jio] Webhook payment confirmed', ['deposit_id' => $deposit->id]);
        }

        // SprintNXT expects exactly this response format
        return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  STEP 3 — AJAX Status Polling
    //  Payment page polls this every 5 seconds.
    // ─────────────────────────────────────────────────────────────────────────
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

        // Already confirmed (e.g. webhook beat the poll)
        if ($deposit->status == Status::PAYMENT_SUCCESS) {
            return response()->json([
                'status'   => 'success',
                'redirect' => route('checkout.confirmation', $deposit->order->order_number),
            ]);
        }

        // Not yet initiated / already failed
        if ($deposit->status != Status::PAYMENT_INITIATE) {
            return response()->json(['status' => 'pending']);
        }

        // ── Call SprintNXT status check ──────────────────────────────────────
        $acc    = json_decode($deposit->gatewayCurrency()->gateway_parameter);
        $isProd = isset($acc->is_production) && $acc->is_production;

        $endpoint = $isProd ? self::URL_STATUS_PRODUCTION : self::URL_STATUS_UAT;

        try {
            $response = Http::timeout(15)
                ->withHeaders(self::headers($acc->client_id, $acc->user_agent, $acc->token))
                ->post($endpoint, [
                    'apiId'  => 20247,                   // Status check always uses apiId 20247 (different from init 20260)
                    'bankId' => $acc->bank_id ?? '12',
                    'txnId'  => $deposit->btc_wallet,    // The txnReferance we sent at init time
                ]);

            $result = $response->json();
            Log::info('[Jio] Status poll result', ['trx' => $trx, 'result' => $result]);

            /*
             * SprintNXT status check response structure:
             * {
             *   "status": true,
             *   "responsecode": 1,
             *   "data": {
             *     "statusvalue": 1,   ← THIS is the transaction status (not data.Status or responsecode)
             *     "status": "Completed",
             *     "rrn_number": "...",
             *     "payer_vpa": "...",
             *     ...
             *   }
             * }
             *
             * statusvalue codes:
             *   1 = Success / Completed   ← payment done
             *   2 = Initiated
             *   3 = QRGenerated           ← waiting for scan/payment
             *   4 = QRExpired
             *   5 = Failed
             *   6 = Pending / Timeout
             */
            $txnStatus = (int) ($result['data']['statusvalue'] ?? 0);

            // responsecode=7 or status=false means the txn lookup itself failed (not a terminal state)
            if (!($result['status'] ?? false) && ($result['responsecode'] ?? 0) !== 1) {
                Log::warning('[Jio] Status API error', ['trx' => $trx, 'result' => $result]);
                return response()->json(['status' => 'pending']);
            }

            if ($txnStatus === 1 && $deposit->status == Status::PAYMENT_INITIATE) {
                $deposit->detail = $result;
                $deposit->save();
                PaymentController::userDataUpdate($deposit);

                return response()->json([
                    'status'   => 'success',
                    'redirect' => route('checkout.confirmation', $deposit->order->order_number),
                ]);
            }

            if (in_array($txnStatus, [4, 5])) {
                return response()->json(['status' => 'failed', 'message' => 'Payment failed or QR expired. Please try again.']);
            }

            // statusvalue 2, 3, 6 = still waiting
            return response()->json(['status' => 'pending']);

        } catch (\Exception $e) {
            Log::error('[Jio] Status check exception: ' . $e->getMessage());
            return response()->json(['status' => 'pending']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build standard SprintNXT request headers.
     */
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
     * JIO txnReferance must be 10–30 characters.
     * ViserMart trx is typically 12 chars (e.g. "ABC123456789") — should pass,
     * but we ensure it here defensively.
     */
    private static function makeTxnRef(string $trx): string
    {
        // Remove any non-alphanumeric characters
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
     * Decrypt SprintNXT JIO Bank webhook encdata.
     *
     * SprintNXT uses AES-256-CBC. They provide:
     *   encryption_key (32 bytes) and encryption_iv (16 bytes)
     * stored in your gateway_parameters JSON.
     *
     * If you haven't received the key/IV from SprintNXT yet,
     * set "encryption_key" and "encryption_iv" in gateway_parameters
     * once they share it. Until then UAT sends plain JSON.
     */
    private function decryptWebhook(string $encdata, Request $request): ?array
    {
        try {
            // Find any deposit to read gateway params (we need key/iv before we know which deposit)
            // Use the most recent Jio deposit to get gateway params
            $deposit = Deposit::whereHas('gateway', function ($q) {
                $q->where('alias', 'jio');
            })->orderBy('id', 'DESC')->first();

            if (!$deposit) {
                Log::warning('[Jio] Cannot find gateway params for decryption');
                return null;
            }

            $acc = json_decode($deposit->gatewayCurrency()->gateway_parameter);

            if (empty($acc->encryption_key) || empty($acc->encryption_iv)) {
                // UAT mode — attempt raw JSON parse (SprintNXT sends plain in UAT)
                $decoded = base64_decode($encdata, true);
                if ($decoded === false) {
                    return null;
                }
                $parsed = json_decode($decoded, true);
                return $parsed ?: null;
            }

            $key       = $acc->encryption_key;
            $iv        = $acc->encryption_iv;
            $encrypted = base64_decode($encdata, true);

            if ($encrypted === false) {
                return null;
            }

            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

            if ($decrypted === false) {
                Log::error('[Jio] openssl_decrypt failed');
                return null;
            }

            return json_decode($decrypted, true);

        } catch (\Exception $e) {
            Log::error('[Jio] Decrypt exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Return a JSON-encoded error response for process().
     */
    private static function errorJson(string $message): string
    {
        return json_encode(['error' => true, 'message' => $message]);
    }
}