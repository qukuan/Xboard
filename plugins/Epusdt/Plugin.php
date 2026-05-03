<?php

namespace Plugin\Epusdt;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            $methods['Epusdt'] = [
                'name' => $this->getConfig('display_name', 'Epusdt 加密支付'),
                'icon' => '₮',
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin'
            ];
            return $methods;
        });
    }


    /**
     * 支付方式配置表单
     */
    public function form(): array
    {
        return [
            'api_url' => [
                'label' => 'Epusdt 网关地址',
                'type' => 'string',
                'required' => true,
                'description' => '例如：https://pay.example.com (末尾不要带 /)'
            ],
            'api_token' => [
                'label' => 'API Token',
                'type' => 'string',
                'required' => true,
                'description' => '请填写 Epusdt 的 api_auth_token'
            ],
            'display_name' => [
                'label' => '显示名称',
                'type' => 'string',
                'required' => true,
                'description' => '后台显示的支付名称'
            ]
        ];
    }


    /**
     * 发起支付
     */
    public function pay($order): array
    {
        $apiUrl = rtrim($this->getConfig('api_url'), '/');
        $apiToken = $this->getConfig('api_token');

        // 构造请求参数
        $params = [
            'amount'       => (float)number_format($order['total_amount'] / 100, 2, '.', ''),
            'order_id'     => (string)$order['trade_no'],
            'notify_url'   => $order['notify_url'],
            'redirect_url' => $order['return_url'],
        ];
        $params['signature'] = $this->makeSign($params, $apiToken);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($apiUrl . '/api/v1/order/create-transaction', $params);

            if ($response->failed()) {
                throw new \Exception('无法连接到 Epusdt 网关，请检查 API 地址');
            }

            $result = $response->json();

            if (!isset($result['status_code']) || $result['status_code'] !== 200) {
                throw new \Exception('Epusdt 响应错误: ' . ($result['message'] ?? '未知错误'));
            }

            return [
                'type' => 1, // 重定向
                'data' => $result['data']['payment_url']
            ];
        } catch (\Exception $e) {
            Log::error('[Plugin\Epusdt] 发起支付异常: ' . $e->getMessage());
            // Epusdt网关响应错误时使用 ApiException，以便被Xboard项目系统全局 Handler 捕获并把错误信息透传给前端显示
            throw new ApiException($e->getMessage());
        }
    }


    /**
     * 异步回调处理
     */
    public function notify($params): array|bool
    {
        // 打印原始回调数据日志
        $rawContent = request()->getContent();
        /*Log::info('[Plugin\Epusdt] 收到异步回调原始数据', [
            'method' => request()->method(),
            'headers' => request()->headers->all(),
            'raw_body' => $rawContent,
            'input_params' => $params
        ]);*/

        // 处理可能未被解析的 JSON Body 
        if (empty($params) && !empty($rawContent)) {
            $params = json_decode($rawContent, true) ?? [];
        }

        $apiToken = $this->getConfig('api_token');

        if (!isset($params['signature'])) {
            Log::warning('[Plugin\Epusdt] 回调参数缺失签名字段');
            return false;
        }

        $receivedSign = $params['signature'];
        
        // 严格提取签名所需字段，避免路由注入参数干扰
        // 必须参与签名的字段为：trade_id, order_id, amount, actual_amount, token, block_transaction_id, status
        $expectedKeys = [
            'trade_id', 'order_id', 'amount', 'actual_amount', 
            'token', 'block_transaction_id', 'status'
        ];
        
        $signData = [];
        foreach ($expectedKeys as $key) {
            if (isset($params[$key])) {
                // 转为字符串处理，防止数字类型在拼接时产生差异
                $signData[$key] = (string)$params[$key];
            }
        }

        // 校验签名
        $computedSign = $this->makeSign($signData, $apiToken);
        
        if ($receivedSign !== $computedSign) {
            Log::warning('[Plugin\Epusdt] 回调签名验证失败', [
                'received' => $receivedSign,
                'computed' => $computedSign,
                'sign_payload' => $signData
            ]);
            return false;
        }

        // 检查订单状态 (只有为2时支付成功) 
        if ((int)$params['status'] !== 2) {
            Log::info('[Plugin\Epusdt] 订单状态非支付成功: ' . $params['status']);
            return false;
        }

        // 返回给 PaymentController 进行后续 OrderService->paid() 处理
        return [
            'trade_no'      => $params['order_id'], // Xboard 的订单号
            'callback_no'   => $params['trade_id'], // Epusdt 的流水号
            'custom_result' => 'ok'                 // 告诉 Controller 返回 'ok' 给 Epusdt
        ];
    }


    /**
     * 严格复刻 Epusdt 官方 MD5 签名算法
     */
    private function makeSign(array $params, string $token): string
    {
        // 1. 移除签名本身
        unset($params['signature']);
        
        // 2. 按 ASCII 码从小到大排序 (字典序)
        ksort($params);
        reset($params);

        // 3. 拼接字符串
        $signStr = "";
        foreach ($params as $key => $val) {
            // 如果参数值为空不参与签名 
            if ($val === '' || $val === null) {
                continue;
            }
            
            if ($signStr !== "") {
                $signStr .= "&";
            }
            $signStr .= $key . "=" . $val;
        }

        // 4. 拼接 Token 并 MD5 转小写
        // MD5(待加密参数 + api接口认证token)
        return strtolower(md5($signStr . $token));
    }
}