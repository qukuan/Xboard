<?php

namespace Plugin\EpayPro;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use Illuminate\Support\Facades\Log;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    /**
     * 插件启动逻辑
     */
    public function boot(): void
    {
        // 注册到支付方式列表钩子，并加入启用状态检测
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['EpayPro'] = [
                    'name' => $this->getConfig('display_name', '易支付 Pro'),
                    'icon' => $this->getConfig('icon', '💳'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    /**
     * 声明配置表单
     */
    public function form(): array
    {
        return [
            'api_url' => [
                'label' => '支付网关地址',
                'type' => 'string',
                'required' => true,
                'description' => '例如：https://epay.com (末尾不要带 /)'
            ],
            'pid' => [
                'label' => '商户 ID (pid)',
                'type' => 'string',
                'required' => true
            ],
            'key' => [
                'label' => '通信密钥 (key)',
                'type' => 'string',
                'required' => true
            ],
            'payment_type' => [
                'label' => '指定支付方式',
                'type' => 'string',
                'required' => true,
                'description' => '常用值：alipay, wxpay'
            ],
            'display_name' => [
                'label' => '显示名称',
                'type' => 'string',
                'required' => true
            ],
            'icon' => [
                'label' => '支付图标',
                'type' => 'string',
                'description' => '例如：💳'
            ]
        ];
    }

    /**
     * 向支付网关发起请求，创建支付订单
     */
    public function pay($order): array
    {
        $apiUrl = rtrim($this->getConfig('api_url'), '/');
        $pid = $this->getConfig('pid');
        $key = $this->getConfig('key');
        $paymentType = $this->getConfig('payment_type', 'alipay');

        if (empty($apiUrl) || empty($pid) || empty($key)) {
            throw new ApiException('支付插件配置不完整，请在后台检查插件设置');
        }

        $params = [
            'pid'          => (int)$pid,
            'type'         => $paymentType,
            'out_trade_no' => (string)$order['trade_no'],
            'notify_url'   => $order['notify_url'],
            'return_url'   => $order['return_url'],
            'name'         => 'Order-' . $order['trade_no'],
            'money'        => (string)number_format($order['total_amount'] / 100, 2, '.', ''),
        ];

        // 发起支付时需要签名
        $params['sign'] = $this->makeSign($params, $key);
        $params['sign_type'] = 'MD5';

        // 生成支付跳转链接
        $payUrl = $apiUrl . '/submit.php?' . http_build_query($params);

        return [
            'type' => 1, // 重定向模式
            'data' => $payUrl
        ];
    }

    /**
     * 接收并处理来自支付网关的回调通知数据
     */
    public function notify($params): array|bool
    {
        // 1. 验证是否存在签名
        if (!isset($params['sign'])) {
            Log::warning('[Plugin\EpayPro] 回调缺失签名', ['params' => $params]);
            return false;
        }

        $sign = $params['sign'];
        
        // 2. 按照 Xboard 系统规范处理签名逻辑
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        
        // 生成待校验字符串
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('key');

        // 3. 验证签名
        if ($sign !== md5($str)) {
            Log::warning('[Plugin\EpayPro] 签名验证失败', [
                'received' => $sign,
                'computed' => md5($str)
            ]);
            return false;
        }

        // 4. 验证订单状态
        if (!isset($params['trade_status']) || $params['trade_status'] !== 'TRADE_SUCCESS') {
            return false;
        }

        return [
            'trade_no'      => $params['out_trade_no'],
            'callback_no'   => $params['trade_no'],
            'custom_result' => 'success'
        ];
    }

    /**
     * 发起支付时使用的签名辅助方法
     */
    private function makeSign(array $params, string $key): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        reset($params);

        $signStr = "";
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) continue;
            $signStr .= ($signStr === "" ? "" : "&") . $k . "=" . $v;
        }

        return strtolower(md5($signStr . $key));
    }
}