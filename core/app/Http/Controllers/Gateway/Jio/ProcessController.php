<?php

/**
 * FILE LOCATION:
 *   core/app/Http/Controllers/Gateway/Jio/ProcessController.php
 *
 * GATEWAY DB REQUIREMENTS:
 *   gateway_parameters JSON:
 * {
 *   "client_id":        "U1BSX0...",
 *   "client_secret":    "secret...",
 *   "api_id":           "20260",
 *   "api_id_status":    "20247",
 *   "bank_id":          "12",
 *   "payee_vpa":        "sprintnxt.8080@jiomerchant",
 *   "expiry_time":      "10",
 *   "encryption_key":   "56c271c135e53d1a0281ea8b0b6c8e02",
 *   "encryption_iv":    "5158c4f228be322e",
 *   "payin_init_url":   "https://nxt.sprintnxt.in/PayInExposeNxt/api/v2/UPIService/UPI",
 *   "payin_status_url": "https://nxt.sprintnxt.in/NextgenAPIExpose/api/v2/UPIService/UPI",
 *   "proxy_url":        "https://checkout.novafin.tech" // optional
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
    private const LOG_TAG = '[JIO-API]';

    // =========================================================================
    //  STEP 1 — QR / Intent Initiation
    // =========================================================================
    public static function process($deposit): string
    {
        // ViserMart stores params in both gateways.gateway_parameters and gateway_currencies.gateway_parameter
        // We merge them so if you updated the main table, it still works.
        $currencyAcc = json_decode($deposit->gatewayCurrency()->gateway_parameter ?? '{}', true) ?? [];
        $gatewayAcc  = [];
        if ($deposit->gatewayCurrency()->method) {
            $gatewayAcc = json_decode($deposit->gatewayCurrency()->method->gateway_parameters ?? '{}', true) ?? [];
        } else {
            $gw = \App\Models\Gateway::where('code', $deposit->method_code)->first();
            if ($gw) {
                $gatewayAcc = json_decode($gw->gateway_parameters ?? '{}', true) ?? [];
            }
        }
        $acc = (object) array_merge($gatewayAcc, $currencyAcc);

        $apiId     = $acc->api_id ?? '20260';
        $bankId    = $acc->bank_id ?? '12';
        $payeeVPA  = $acc->payee_vpa ?? '';
        $expiryMin = $acc->expiry_time ?? '10';

        $txnRef   = self::makeTxnRef($deposit->trx);
        $endpoint = self::resolveUrl($acc->payin_init_url ?? '', $acc->proxy_url ?? '');

        $payload = [
            'apiId'        => (string)$apiId,
            'bankId'       => (string)$bankId,
            'amount'       => (string) round($deposit->final_amount, 2),
            'payeeVPA'     => (string)$payeeVPA,
            'mobile'       => self::fakeMobileNumber(),
            'ExpiryTime'   => (string)$expiryMin,
            'txnNote'      => 'Order Payment',
            'txnReferance' => $txnRef,
        ];

        Log::info(self::LOG_TAG . ' [1/INIT-REQUEST]', [
            'endpoint'    => $endpoint,
            'trx'         => $deposit->trx,
            'txnRef'      => $txnRef,
            'amount'      => $deposit->final_amount,
            'payload'     => $payload,
        ]);

        try {
            $headers = self::headers($acc, $acc->payin_init_url ?? '');
            
            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->post($endpoint, $payload);

            $httpStatus = $response->status();
            $result     = $response->json();

            Log::info(self::LOG_TAG . ' [2/INIT-RESPONSE]', [
                'trx'         => $deposit->trx,
                'txnRef'      => $txnRef,
                'http_status' => $httpStatus,
                'response'    => $result,
            ]);

            if ($response->failed()) {
                return self::errorJson('Gateway returned HTTP ' . $httpStatus . '. Please try again.');
            }

            if (!isset($result['status']) || ($result['status'] !== true && $result['status'] !== 1)) {
                return self::errorJson($result['message'] ?? 'Gateway error. Please try again.');
            }

            $details = $result['details'] ?? [];

            // Store txnReferance in btc_wallet — used to match webhook and status check
            $deposit->btc_wallet = $txnRef;
            $deposit->save();

            $send['intent_url']    = $details['intent_url'] ?? '';
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
                'exception' => $e->getMessage()
            ]);
            return self::errorJson('Gateway connection failed. Please try again.');
        }
    }

    // =========================================================================
    //  STEP 2 — Webhook / IPN handler
    // =========================================================================
    public function ipn(Request $request)
    {
        $payload = $request->all();

        Log::info(self::LOG_TAG . ' [4/WEBHOOK-RECEIVED]', [
            'ip'     => $request->ip(),
            'parsed' => $payload,
        ]);

        if (isset($payload['encdata'])) {
            $gateway = \App\Models\Gateway::where('alias', 'jio')->first();
            $params = $gateway ? json_decode($gateway->gateway_parameters) : null;
            $decrypted = $this->decryptWebhook($payload['encdata'], $params);
            if ($decrypted) {
                $payload = $decrypted;
                Log::info(self::LOG_TAG . ' [4b/WEBHOOK-DECRYPTED]', ['payload' => $payload]);
            }
        }

        $response   = $payload['response'] ?? $payload;
        $wStatus    = (int) ($response['status'] ?? 0);
        
        $txnRef = $response['refid'] ?? $response['ref_id'] ?? $response['upiRefId'] ?? $response['txnid'] ?? null;

        if (!$txnRef) {
            Log::error(self::LOG_TAG . ' [ERR/WEBHOOK-NO-TXNREF]', ['response' => $response]);
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        $deposit = Deposit::where('btc_wallet', $txnRef)->orderBy('id', 'DESC')->first();
        if (!$deposit) {
            return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
        }

        if ($wStatus === 1) {
            if ($deposit->status == Status::PAYMENT_INITIATE) {
                $paidAmount = (float)($response['payer_amount'] ?? $response['amount'] ?? 0);
                if (round($deposit->final_amount, 2) <= $paidAmount) {
                    $deposit->detail = $payload;
                    $deposit->save();
                    PaymentController::userDataUpdate($deposit);
                }
            }
        } else if ($wStatus === 4 || $wStatus === 5) {
            // Failed
            if ($deposit->status == Status::PAYMENT_INITIATE) {
                $deposit->status = Status::PAYMENT_REJECT;
                $deposit->save();
            }
        }

        return response()->json(['responseMessage' => 'Successful', 'returnCode' => '0']);
    }

    // =========================================================================
    //  STEP 3 — AJAX Status Polling
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
        
        if ($deposit->status == Status::PAYMENT_REJECT) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'Payment Failed. Please try again.',
            ]);
        }

        if ($deposit->status != Status::PAYMENT_INITIATE) {
            return response()->json(['status' => 'pending']);
        }

        $currencyAcc = json_decode($deposit->gatewayCurrency()->gateway_parameter ?? '{}', true) ?? [];
        $gatewayAcc  = [];
        if ($deposit->gatewayCurrency()->method) {
            $gatewayAcc = json_decode($deposit->gatewayCurrency()->method->gateway_parameters ?? '{}', true) ?? [];
        } else {
            $gw = \App\Models\Gateway::where('code', $deposit->method_code)->first();
            if ($gw) {
                $gatewayAcc = json_decode($gw->gateway_parameters ?? '{}', true) ?? [];
            }
        }
        $acc = (object) array_merge($gatewayAcc, $currencyAcc);

        $endpoint = self::resolveUrl($acc->payin_status_url ?? '', $acc->proxy_url ?? '');

        $statusPayload = [
            'apiId'  => (string)($acc->api_id_status ?? '20247'),
            'bankId' => (string)($acc->bank_id ?? '12'),
            'txnId'  => $deposit->btc_wallet,
        ];

        try {
            $headers = self::headers($acc, $acc->payin_status_url ?? '');
            
            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->post($endpoint, $statusPayload);

            $result = $response->json();

            if (!isset($result['status']) || $result['status'] !== true) {
                return response()->json(['status' => 'pending']);
            }

            $data = null;
            if (isset($result['data'])) {
                if (is_array($result['data']) && isset($result['data'][0])) {
                    // It's an array
                    foreach ($result['data'] as $d) {
                        $s = strtolower((string)($d['status'] ?? $d['statusvalue'] ?? ''));
                        if ($s === 'success' || $s === '1') {
                            $data = $d;
                            break;
                        }
                    }
                    if (!$data) {
                        foreach ($result['data'] as $d) {
                            $s = strtolower((string)($d['status'] ?? $d['statusvalue'] ?? ''));
                            if (in_array($s, ['2', '3', '6', 'initiated', 'qr_generated', 'qrgenerated', 'pending'])) {
                                $data = $d;
                                break;
                            }
                        }
                    }
                    if (!$data && count($result['data']) > 0) {
                        $data = end($result['data']);
                    }
                } else {
                    $data = $result['data'];
                }
            }

            if ($data) {
                $statusVal = strtolower((string)($data['status'] ?? $data['statusvalue'] ?? ''));
                $isSuccess = $statusVal === 'success' || $statusVal === '1';
                $isFailed  = in_array($statusVal, ['failed', 'qr_expired', '4', '5']);

                if ($isSuccess) {
                    $paidAmount = (float)($data['payer_amount'] ?? $data['amount'] ?? 0);
                    if (round($deposit->final_amount, 2) <= $paidAmount) {
                        $deposit->detail = $result;
                        $deposit->save();
                        PaymentController::userDataUpdate($deposit);
                        
                        return response()->json([
                            'status'   => 'success',
                            'redirect' => route('checkout.confirmation', $deposit->order->order_number),
                        ]);
                    } else {
                        $deposit->status = Status::PAYMENT_REJECT;
                        $deposit->save();
                        return response()->json([
                            'status'  => 'failed',
                            'message' => 'Amount mismatch. Payment Failed.',
                        ]);
                    }
                } else if ($isFailed) {
                    $deposit->status = Status::PAYMENT_REJECT;
                    $deposit->save();
                    return response()->json([
                        'status'  => 'failed',
                        'message' => 'Payment Failed or Expired. Please try again.',
                    ]);
                }
            }

            return response()->json(['status' => 'pending']);

        } catch (\Exception $e) {
            return response()->json(['status' => 'pending']);
        }
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private static function resolveUrl(string $originalUrl, string $proxyUrl): string
    {
        if (empty($proxyUrl) || empty($originalUrl)) {
            return $originalUrl;
        }
        $parsed = parse_url($originalUrl);
        $proxy = parse_url($proxyUrl);
        
        $scheme = $proxy['scheme'] ?? $parsed['scheme'];
        $host = $proxy['host'] ?? $parsed['host'];
        $port = isset($proxy['port']) ? ':' . $proxy['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        
        return rtrim($scheme . '://' . $host . $port . $path . $query, '/');
    }

    private static function generateJioToken(object $acc): string
    {
        $payload = json_encode([
            'client_secret' => $acc->client_secret ?? '',
            'requestid'     => str_pad((string) mt_rand(1, 999999999), 9, '1', STR_PAD_LEFT),
            'timestamp'     => (string) time(),
        ]);

        $key = str_pad(substr($acc->encryption_key ?? '', 0, 32), 32, "\0");
        $iv  = str_pad(substr($acc->encryption_iv ?? '', 0, 16), 16, "\0");

        $encrypted = openssl_encrypt($payload, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($encrypted);
    }

    private static function headers(object $acc, string $originalUrl): array
    {
        $clientIdEncoded = base64_encode($acc->client_id ?? '');
        $token = self::generateJioToken($acc);

        $headers = [
            'Client-id'    => $clientIdEncoded,
            'Token'        => $token,
            'accept'       => 'application/json',
            'content-type' => 'application/json',
        ];

        $proxyUrl = rtrim($acc->proxy_url ?? '', '/');
        if (!empty($proxyUrl) && !empty($originalUrl)) {
            $host = parse_url($originalUrl, PHP_URL_HOST);
            if ($host) {
                $headers['X-PG-Target-Host'] = $host;
            }
        }

        return $headers;
    }

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

    private function decryptWebhook(string $encdata, ?object $params): ?array
    {
        try {
            if (!$params) {
                return json_decode(base64_decode($encdata, true), true);
            }

            $keyStr = $params->encryption_key ?? '';
            $ivStr  = $params->encryption_iv ?? '';

            if (empty($keyStr) || empty($ivStr)) {
                return json_decode(base64_decode($encdata, true), true);
            }

            $key = str_pad(substr($keyStr, 0, 32), 32, "\0");
            $iv  = str_pad(substr($ivStr, 0, 16), 16, "\0");

            $encrypted = base64_decode($encdata, true);
            if ($encrypted === false) {
                return null;
            }

            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($decrypted === false) {
                return null;
            }

            return json_decode($decrypted, true);

        } catch (\Exception $e) {
            return null;
        }
    }

    private static function fakeMobileNumber(): string
    {
        $prefixes = ['6', '7', '8', '9'];
        $prefix   = $prefixes[array_rand($prefixes)];
        $number   = $prefix . str_pad((string)mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        return $number;
    }

    private static function errorJson(string $message): string
    {
        return json_encode(['error' => true, 'message' => $message]);
    }
}