<?php

namespace Plugin\Btcpay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = trim(request()->getContent());
        // getallheaders() does not exist under Octane/Swoole; read via the request
        $signraturHeader = (string) request()->header('Btcpay-Sig', '');
        $json_param = json_decode($payload, true);

        $computedSignature = "sha256=" . \hash_hmac('sha256', $payload, $this->getConfig('btcpay_webhook_key'));

        if (!$this->hashEqual($signraturHeader, $computedSignature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => "Authorization:" . "token " . $this->getConfig('btcpay_api_key') . "\r\n"
            )
        ));

        $invoiceDetail = file_get_contents($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices/' . $json_param['invoiceId'], false, $context);
        $invoiceDetail = json_decode($invoiceDetail, true);

        // the webhook fires for created/expired/unpaid invoices too (same valid
        // signature), so trust only the invoice status fetched with the API key
        if (!isset($invoiceDetail['status']) || !in_array($invoiceDetail['status'], ['Settled', 'Complete'], true)) {
            throw new ApiException('Invoice is not settled: ' . ($invoiceDetail['status'] ?? 'unknown'), 400);
        }

        $out_trade_no = $invoiceDetail['metadata']["orderId"];
        $pay_trade_no = $json_param['invoiceId'];

        return [
            'trade_no' => $out_trade_no,
            'callback_no' => $pay_trade_no,
            // amount in cents for the panel-side order amount check
            'amount' => (int) round((float) ($invoiceDetail['price'] ?? 0) * 100),
        ];
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
} 