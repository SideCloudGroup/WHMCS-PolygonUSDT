<?php

namespace PolygonForWHMCS;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use PolygonForWHMCS\Exceptions\NoAddressAvailable;
use PolygonForWHMCS\Models\Invoice;
use PolygonForWHMCS\Models\PolygonForWHMCSInvoice;
use PolygonForWHMCS\Models\Transaction;
use RuntimeException;
use Smarty;
use Throwable;
use WHMCS\Database\Capsule;

class App
{
    /**
     * USDT收款地址列表
     *
     * @var string[]
     */
    protected $addresses = [];

    /**
     * 支付网关配置字段
     *
     * @var string[]
     */
    protected $config = [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Polygon USDT支付',
        ],
        'addresses' => [
            'FriendlyName' => 'USDT收款地址',
            'Type' => 'textarea',
            'Rows' => '20',
            'Cols' => '30',
            'Description' => '每行一个Polygon钱包地址，建议配置多个地址支持并发支付',
        ],
        'timeout' => [
            'FriendlyName' => '支付超时时间',
            'Type' => 'text',
            'Value' => 30,
            'Description' => '支付超时时间，单位：分钟'
        ],
        'apikey' => [
            'FriendlyName' => 'Etherscan V2 API密钥',
            'Type' => 'text',
            'Description' => '从 https://etherscan.io/apis 获取的API密钥，支持Polygon等多链查询',
        ],
        'exchangeRateDeviation' => [
            'FriendlyName' => '汇率偏移值',
            'Type' => 'text',
            'Value' => 1.00,
            'Description' => '汇率偏移值，设置小于1给用户优惠，大于1收取手续费'
        ],
    ];

    /**
     * Smarty模板引擎
     *
     * @var Smarty
     */
    protected $smarty;

    /**
     * 支付超时时间（分钟）
     *
     * @var int
     */
    protected $timeout;

    /**
     * Etherscan V2 API密钥
     *
     * @var string
     */
    protected $apikey;

    /**
     * 汇率偏移值
     *
     * @var float
     */
    protected $exchangeRateDeviation;

    /**
     * 创建应用实例
     *
     * @param array $params 网关参数
     * @param bool $configMode 是否为配置模式
     *
     * @return void
     */
    public function __construct(array $params = [], bool $configMode = false)
    {
        if (! function_exists('getGatewayVariables')) {
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'init.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/gatewayfunctions.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/invoicefunctions.php';
        } else {
            if (empty($params) && ! $configMode) {
                try {
                    $params = getGatewayVariables('polygonforwhmcs');
                } catch (Throwable $e) {
                }
            }
        }

        $this->timeout = $params['timeout'] ?? 30;
        $this->addresses = array_filter(preg_split("/\r\n|\n|\r/", $params['addresses'] ?? ''));
        $this->apikey = $params['apikey'];
        $this->exchangeRateDeviation = $this->normalizeDeviation($params['exchangeRateDeviation'] ?? null);

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir(Polygon_PAY_ROOT . DIRECTORY_SEPARATOR . 'templates');
        $this->smarty->setCompileDir(WHMCS_ROOT . DIRECTORY_SEPARATOR . 'templates_c');
    }

    /**
     * 安装Polygon USDT支付网关
     * 运行数据库迁移并返回配置字段
     *
     * @return string[] 配置字段数组
     */
    public function install()
    {
        $this->runMigrations();

        return $this->config;
    }

    /**
     * 运行数据库迁移
     *
     * @return void
     */
    protected function runMigrations()
    {
        $migrationPath = __DIR__ . DIRECTORY_SEPARATOR . 'Migrations';
        $migrations = array_diff(scandir($migrationPath), ['.', '..']);

        foreach ($migrations as $migration) {
            require_once $migrationPath . DIRECTORY_SEPARATOR . $migration;

            $migrationName = str_replace('.php', '', $migration);

            (new $migrationName)->execute();
        }
    }

    /**
     * 渲染支付页面或处理AJAX请求
     *
     * @param array $params 支付参数
     *
     * @return string|void HTML内容或JSON响应
     */
    public function render(array $params)
    {
        switch ($_GET['act'] ?? '') {
            case 'invoice_status':
                $this->renderInvoiceStatusJson($params);
                return;
            case 'create':
                $this->createPolygonForWHMCSInvoice($params);
                return;
            default:
                return $this->renderPaymentHTML($params);
        }
    }

    /**
     * 获取发票状态JSON响应
     *
     * @param array $params 请求参数
     *
     * @return void
     */
    protected function renderInvoiceStatusJson(array $params)
    {
        $polygonInvoice = (new PolygonForWHMCSInvoice())->firstValidByInvoiceId($params['invoiceid']);
        if ($polygonInvoice) {
            $invoice = (new Invoice())->with('transactions')->find($params['invoiceid']);
            $this->checkTransaction($polygonInvoice);
            $polygonInvoice = $polygonInvoice->refresh();

            if (mb_strtolower($invoice['status']) === 'unpaid') {
                if ($polygonInvoice['expires_on']->subMinutes(3)->lt(Carbon::now())) {
                    $polygonInvoice->renew($this->timeout);
                }

                $polygonInvoice = $polygonInvoice->refresh();
            }

            $json = [
                'status' => $invoice['status'],
                'amountin' => $invoice['transactions']->sum('amountin'),
                'valid_till' => $polygonInvoice['expires_on']->toDateTimeString(),
                'usdt_amount' => number_format($polygonInvoice['usdt_amount'] ?? 0, 2, '.', ''),
            ];

            $this->json($json);
        }

        $this->json([
            'status' => false,
            'error' => 'invoice does not exists',
        ]);
    }

    /**
     * 检查USDT交易状态
     *
     * @param PolygonForWHMCSInvoice $invoice Polygon发票记录
     *
     * @return void
     */
    protected function checkTransaction(PolygonForWHMCSInvoice $invoice)
    {
        $this->getTransactions($invoice['to_address'], $invoice['start_block'])
            ->each(function ($transaction) use ($invoice) {
                $whmcsTransaction = (new Transaction())->firstByTransId($transaction['hash']);
                $whmcsInvoice = Invoice::find($invoice['invoice_id']);
                // If current invoice has been paid ignore it.
                if ($whmcsTransaction) {
                    return;
                }

                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    return;
                }

                // 验证接收地址（忽略大小写差异）
                if (mb_strtolower($transaction['to']) !== mb_strtolower($invoice['to_address'])) {
                    return;
                }

                // 验证USDT合约地址（Polygon主网合约）
                $usdtContract = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';
                if (mb_strtolower($transaction['contractAddress']) !== mb_strtolower($usdtContract)) {
                    return;
                }

                // 跳过tokenSymbol检查，API可能返回USDT、USDT0等不同变体

                // 使用bcmath高精度转换收到的USDT金额
                $receivedUSDTRaw = bcdiv((string) $transaction['value'], '1000000', 6);
                $receivedUSDT = bcadd($receivedUSDTRaw, '0', 2); // 格式化为2位小数

                // 对比数据库存储的USDT金额（允许±0.01误差）
                $expectedUSDT = bcadd((string) $invoice['usdt_amount'], '0', 2);
                $tolerance = '0.01'; // 容差设置为±0.01 USDT

                // bcmath高精度比较差异值
                $difference = bcadd(bcsub($receivedUSDT, $expectedUSDT, 2), '0', 2);
                if ($difference[0] === '-') {
                    $difference = bcmul($difference, '-1', 2); // 转换为绝对值
                }

                if (bccomp($difference, $tolerance, 2) >= 1) {
                    logTransaction('PolygonForWHMCS Amount Mismatch', [
                        'received_usdt' => $receivedUSDT,
                        'expected_usdt' => $expectedUSDT,
                        'difference' => $difference,
                        'tolerance' => $tolerance,
                        'transaction_hash' => $transaction['hash'],
                        'invoice_id' => $invoice['invoice_id']
                    ], 'Amount Validation Failed');
                    return;
                }

                // 使用数据库存储的原始发票金额进行支付记录
                $actualAmount = (float) $invoice['original_amount'];

                AddInvoicePayment(
                    $invoice['invoice_id'], // 发票ID
                    $transaction['hash'], // 交易哈希
                    $actualAmount, // 支付金额
                    0, // 交易手续费
                    'polygonforwhmcs' // 网关标识

                );

                logTransaction('PolygonForWHMCS', $transaction, 'Successfully Paid');

                $whmcsInvoice = $whmcsInvoice->refresh();
                // 如果发票已完全支付则释放地址，否则续期
                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    $invoice->markAsPaid($transaction['from'], $transaction['hash']);
                } else {
                    $invoice->renew($this->timeout);
                }
            });
    }

    /**
     * 使用Etherscan V2 API获取Polygon USDT代币交易
     *
     * @param string $address 钱包地址
     * @param int $startblock 起始区块号
     *
     * @return Collection 交易记录集合
     */
    protected function getTransactions(string $address, int $startblock): Collection
    {
        $http = new Client([
            'base_uri' => 'https://api.etherscan.io',
            'timeout' => 30,
        ]);

        $response = $http->get("/v2/api", [
            'query' => [
                'chainid' => 137, // Polygon chain ID
                'module' => "account",
                'action' => "tokentx",
                'address' => $address,
                'page' => 1,
                'offset' => 5,
                'startblock' => $startblock,
                'sort' => "desc",
                'apikey' => $this->apikey,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        // 检查API响应
        if (! isset($data['result']) || $data['status'] !== '1') {
            logTransaction('PolygonForWHMCS API Error', [
                'endpoint' => 'tokentx',
                'address' => $address,
                'response' => $data
            ], 'API Error');

            return new Collection([]);
        }

        // 过滤USDT交易（Polygon主网USDT合约地址）
        $usdtContract = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';
        $usdtTransactions = array_filter($data['result'], function ($transaction) use ($usdtContract) {
            return mb_strtolower($transaction['contractAddress']) === mb_strtolower($usdtContract);
        });

        return new Collection($usdtTransactions);
    }

    /**
     * 输出JSON响应
     *
     * @param array $json 响应数据
     *
     * @return void
     */
    protected function json(array $json)
    {
        $json = json_encode($json);
        header('Content-Type: application/json');
        echo $json;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            exit();
        }
    }

    /**
     * 创建Polygon支付发票
     *
     * @param array $params 请求参数
     *
     * @return void
     */
    protected function createPolygonForWHMCSInvoice(array $params)
    {
        try {
            $invoice = (new Invoice())->find($params['invoiceid']);

            if (mb_strtolower($invoice['status']) === 'paid') {
                $this->json([
                    'status' => false,
                    'error' => 'The invoice has been paid in full.'
                ]);
            } else {
                $payment = $this->calculateUsdtAmount($params);

                $address = $this->getAvailableAddress(
                    $params['invoiceid'],
                    $payment['usdt_amount'],
                    $payment['original_amount'],
                    $payment['currency'],
                    $payment['exchange_rate'],
                    $payment['rate_deviation']
                );

                $usdtAmount = $payment['usdt_amount'];

                $this->json([
                    'status' => true,
                    'address' => $address,
                    'usdt_amount' => number_format($usdtAmount, 2, '.', ''),
                ]);
            }
        } catch (Throwable $e) {
            $this->json([
                'status' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取可用的USDT收款地址
     *
     * @param int $invoiceId 发票ID
     * @param float $usdtAmount USDT金额
     * @param float $originalAmount 原始发票金额
     * @param string $currency 发票货币
     * @param float $exchangeRate 汇率
     * @param float $rateDeviation 汇率偏移值
     *
     * @return string 收款地址
     *
     * @throws NoAddressAvailable 无可用地址时抛出
     * @throws RuntimeException 发票已关联地址时抛出
     */
    protected function getAvailableAddress(
        int    $invoiceId,
        float  $usdtAmount = 0,
        float  $originalAmount = 0,
        string $currency = 'USD',
        float  $exchangeRate = 1,
        float  $rateDeviation = 1
    ): string
    {
        $polygonInvoice = new PolygonForWHMCSInvoice();

        if ($polygonInvoice->firstValidByInvoiceId($invoiceId)) {
            throw new RuntimeException("The invoice has been associated with a USDT address please refresh the invoice page.");
        }

        $inUseAddresses = $polygonInvoice->inUse()->get(['to_address']);

        $availableAddresses = array_values(array_diff($this->addresses, $inUseAddresses->pluck('to_address')->toArray()));

        if (count($availableAddresses) <= 0) {
            throw new NoAddressAvailable('no available address please try again later.');
        }

        $address = $availableAddresses[array_rand($availableAddresses)];

        // 关联地址并存储预计算的USDT金额和汇率信息
        $polygonInvoice->associate(
            $address,
            $invoiceId,
            $this->timeout,
            (string) $this->getNowPolygonBlock(),
            $usdtAmount,
            $originalAmount,
            $currency,
            $exchangeRate,
            $rateDeviation
        );

        return $address;
    }

    /**
     * 获取当前Polygon区块号
     *
     * @return int 区块号
     */
    protected function getNowPolygonBlock(): int
    {
        $http = new Client([
            'base_uri' => 'https://api.etherscan.io',
            'timeout' => 30,
        ]);

        $response = $http->get("/v2/api", [
            'query' => [
                'chainid' => 137, // Polygon chain ID
                'module' => "proxy",
                'action' => "eth_blockNumber",
                'apikey' => $this->apikey,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (isset($data['result'])) {
            return hexdec($data['result']); // 转换十六进制到十进制
        }

        // API调用失败时返回0，避免阻塞流程
        return 0;
    }

    /**
     * 规范化汇率偏移值，避免空配置被转换成 0
     */
    protected function normalizeDeviation($value): float
    {
        if (is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }

        return 1.00;
    }

    /**
     * 从网关参数或发票记录解析应付金额
     */
    protected function resolveInvoiceAmount(array $params): float
    {
        foreach (['amount', 'basecurrencyamount'] as $key) {
            $amount = $this->normalizeAmount($params[$key] ?? null);
            if ($amount > 0) {
                return $amount;
            }
        }

        $invoice = (new Invoice())->with('transactions')->find($params['invoiceid'] ?? 0);
        if (! $invoice) {
            return 0.0;
        }

        $paid = (float) $invoice->transactions->sum('amountin');
        $balance = round((float) $invoice->total - $paid, 2);

        return $balance > 0 ? $balance : $this->normalizeAmount($invoice->total);
    }

    /**
     * 解析发票货币代码
     */
    protected function resolveInvoiceCurrency(array $params): string
    {
        foreach ($this->extractCurrencyCandidates($params) as $code) {
            if ($this->isKnownCurrencyCode($code)) {
                return $code;
            }
        }

        $defaultCode = Capsule::table('tblcurrencies')->where('default', 1)->value('code');

        return $defaultCode ? strtoupper((string) $defaultCode) : 'USD';
    }

    /**
     * 按优先级收集可能的发票货币
     */
    protected function extractCurrencyCandidates(array $params): array
    {
        $candidates = [];

        $direct = $this->extractCurrencyCode($params['currency'] ?? null);
        if ($direct !== '') {
            $candidates[] = $direct;
        }

        if (isset($params['currencyId'])) {
            $code = $this->currencyCodeById($params['currencyId']);
            if ($code !== '') {
                $candidates[] = $code;
            }
        }

        $clientCurrency = $this->extractCurrencyCode($params['clientdetails']['currency'] ?? null);
        if ($clientCurrency !== '') {
            $candidates[] = $clientCurrency;
        }

        $userId = $params['clientdetails']['userid'] ?? $params['clientdetails']['id'] ?? null;
        if (! $userId && ! empty($params['invoiceid'])) {
            $userId = Capsule::table('tblinvoices')->where('id', $params['invoiceid'])->value('userid');
        }

        if ($userId && function_exists('getCurrency')) {
            $currency = getCurrency($userId);
            if (! empty($currency['code'])) {
                $candidates[] = strtoupper((string) $currency['code']);
            }
        } elseif ($userId) {
            $currencyId = Capsule::table('tblclients')->where('id', $userId)->value('currency');
            $code = $this->currencyCodeById($currencyId);
            if ($code !== '') {
                $candidates[] = $code;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * 从网关参数中提取货币代码，兼容代码、ID 和数组
     */
    protected function extractCurrencyCode($value): string
    {
        if (is_array($value)) {
            if (! empty($value['code'])) {
                return strtoupper(trim((string) $value['code']));
            }
            if (! empty($value['id'])) {
                return $this->currencyCodeById($value['id']);
            }

            return '';
        }

        if (is_numeric($value)) {
            return $this->currencyCodeById($value);
        }

        if (! is_string($value)) {
            return '';
        }

        $code = strtoupper(trim($value));
        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        return '';
    }

    protected function currencyCodeById($currencyId): string
    {
        if (! is_numeric($currencyId) || (int) $currencyId <= 0) {
            return '';
        }

        $code = Capsule::table('tblcurrencies')->where('id', (int) $currencyId)->value('code');

        return $code ? strtoupper((string) $code) : '';
    }

    protected function isKnownCurrencyCode(string $code): bool
    {
        if ($code === '') {
            return false;
        }

        return Capsule::table('tblcurrencies')->where('code', $code)->exists();
    }

    /**
     * 避免把已经是USD的金额再按其他货币换算
     */
    protected function normalizePayableCurrency(array $params, float $amount, string $currency): string
    {
        if (in_array($currency, ['USD', 'USDT', 'USDC'], true)) {
            return 'USD';
        }

        $default = Capsule::table('tblcurrencies')->where('default', 1)->first();
        if (! $default || strtoupper((string) $default->code) !== 'USD') {
            return $currency;
        }

        $baseAmount = $this->normalizeAmount($params['basecurrencyamount'] ?? null);
        if ($baseAmount > 0 && abs($amount - $baseAmount) < 0.009) {
            return 'USD';
        }

        return $currency;
    }

    /**
     * 清洗金额字符串
     */
    protected function normalizeAmount($value): float
    {
        if (is_numeric($value)) {
            return round((float) $value, 6);
        }

        if (! is_string($value) || trim($value) === '') {
            return 0.0;
        }

        $filtered = preg_replace('/[^\d,.\-]/', '', $value);
        if ($filtered === null || $filtered === '' || $filtered === '-') {
            return 0.0;
        }

        if (strpos($filtered, ',') !== false && strpos($filtered, '.') !== false) {
            if (strrpos($filtered, ',') > strrpos($filtered, '.')) {
                $filtered = str_replace('.', '', $filtered);
                $filtered = str_replace(',', '.', $filtered);
            } else {
                $filtered = str_replace(',', '', $filtered);
            }
        } elseif (preg_match('/,\d{1,2}$/', $filtered)) {
            $filtered = str_replace(',', '.', $filtered);
        } else {
            $filtered = str_replace(',', '', $filtered);
        }

        return is_numeric($filtered) ? round((float) $filtered, 6) : 0.0;
    }

    /**
     * 将发票金额转换为USD
     * 优先使用WHMCS货币汇率；未启用USD时回退到在线汇率
     *
     * @return array{0: float, 1: float} [usdAmount, exchangeRate]
     */
    protected function convertAmountToUsd(float $amount, string $currency): array
    {
        $currency = strtoupper($currency);
        if (in_array($currency, ['USD', 'USDT', 'USDC'], true)) {
            return [$amount, 1.0];
        }

        $from = Capsule::table('tblcurrencies')->where('code', $currency)->first();
        $usd = Capsule::table('tblcurrencies')->where('code', 'USD')->first();

        if ($usd && (float) $usd->rate > 0) {
            $fromId = $from->id ?? Capsule::table('tblcurrencies')->where('default', 1)->value('id');

            if ($fromId && function_exists('convertCurrency')) {
                $converted = $this->normalizeAmount(convertCurrency($amount, $fromId, $usd->id));
                $fromRate = ($from && (float) $from->rate > 0) ? (float) $from->rate : 1.0;
                if ($converted > 0) {
                    return [$converted, (float) $usd->rate / $fromRate];
                }
            }

            if ($from && (float) $from->rate > 0) {
                $converted = (float) bcmul(
                    bcdiv((string) $amount, (string) $from->rate, 8),
                    (string) $usd->rate,
                    6
                );
                if ($converted > 0) {
                    return [$converted, (float) $usd->rate / (float) $from->rate];
                }
            }
        }

        $rate = $this->fetchOnlineUsdRate($currency);
        $converted = (float) bcmul((string) $amount, (string) $rate, 6);
        if ($converted <= 0) {
            throw new RuntimeException('无法将 ' . $currency . ' 转换为USDT：WHMCS未启用USD，在线汇率换算结果无效。');
        }

        return [$converted, $rate];
    }

    /**
     * 在WHMCS没有USD货币时，从公开汇率接口获取 1 单位原币兑USD
     */
    protected function fetchOnlineUsdRate(string $currency): float
    {
        $currency = strtoupper($currency);
        $http = new Client(['timeout' => 10]);

        $endpoints = [
            [
                'url' => 'https://api.frankfurter.app/latest',
                'query' => ['from' => $currency, 'to' => 'USD'],
                'path' => ['rates', 'USD'],
            ],
            [
                'url' => 'https://open.er-api.com/v6/latest/' . rawurlencode($currency),
                'query' => [],
                'path' => ['rates', 'USD'],
            ],
        ];

        foreach ($endpoints as $endpoint) {
            try {
                $options = [];
                if (! empty($endpoint['query'])) {
                    $options['query'] = $endpoint['query'];
                }

                $response = $http->get($endpoint['url'], $options);
                $data = json_decode((string) $response->getBody(), true);
                $rate = $data;
                foreach ($endpoint['path'] as $key) {
                    $rate = is_array($rate) ? ($rate[$key] ?? null) : null;
                }

                if (is_numeric($rate) && (float) $rate > 0) {
                    return (float) $rate;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        throw new RuntimeException('无法获取 ' . $currency . ' 对USD的汇率。WHMCS未启用USD时需要访问在线汇率接口，请检查服务器出网，或重新启用USD货币。');
    }

    /**
     * 计算应付USDT金额及汇率快照
     */
    protected function calculateUsdtAmount(array $params): array
    {
        $originalAmount = $this->resolveInvoiceAmount($params);
        if ($originalAmount <= 0) {
            throw new RuntimeException('发票金额无效，无法创建USDT支付订单。');
        }

        $currency = $this->normalizePayableCurrency(
            $params,
            $originalAmount,
            $this->resolveInvoiceCurrency($params)
        );
        $deviation = $this->exchangeRateDeviation > 0 ? $this->exchangeRateDeviation : 1.00;
        [$usdAmount, $exchangeRate] = $this->convertAmountToUsd($originalAmount, $currency);

        $usdtAmount = (float) bcmul((string) $usdAmount, (string) $deviation, 2);
        if ($usdtAmount <= 0) {
            throw new RuntimeException('USDT金额计算结果为0，请检查WHMCS货币汇率与汇率偏移值配置。');
        }

        return [
            'usdt_amount' => $usdtAmount,
            'original_amount' => $originalAmount,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'rate_deviation' => $deviation,
        ];
    }

    /**
     * 渲染USDT支付页面HTML
     *
     * @param array $params 支付参数
     *
     * @return string HTML内容
     */
    protected function renderPaymentHTML(array $params): string
    {
        $polygonInvoice = new PolygonForWHMCSInvoice();

        if ($validAddress = $polygonInvoice->validInvoice($params['invoiceid'])) {
            $validAddress->renew($this->timeout);
            $validTill = Carbon::now()->addMinutes($this->timeout)->toDateTimeString();

            $amount = (float) ($validAddress['usdt_amount'] ?? 0);
            if ($amount <= 0) {
                try {
                    $payment = $this->calculateUsdtAmount($params);
                    $validAddress->forceFill([
                        'usdt_amount' => $payment['usdt_amount'],
                        'original_amount' => $payment['original_amount'],
                        'currency' => $payment['currency'],
                        'exchange_rate' => $payment['exchange_rate'],
                        'rate_deviation' => $payment['rate_deviation'],
                    ])->save();
                    $amount = $payment['usdt_amount'];
                } catch (Throwable $e) {
                    return '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
                }
            }

            return $this->view('payment.tpl', [
                'address' => $validAddress['to_address'],
                'amount' => number_format($amount, 2, '.', ''),
                'validTill' => $validTill,
            ]);
        } else {
            return $this->view('pay_with_usdt.tpl');
        }
    }

    /**
     * 获取Smarty渲染的模板
     *
     * @param string $viewName 模板文件名
     * @param array $arguments 模板变量
     *
     * @return string 渲染结果
     */
    protected function view(string $viewName, array $arguments = [])
    {
        foreach ($arguments as $name => $variable) {
            $this->smarty->assign($name, $variable);
        }

        return $this->smarty->fetch($viewName);
    }

    /**
     * 定时任务处理
     * 检查支付状态并清理过期发票
     *
     * @return void
     */
    public function cron()
    {
        $this->checkPaidInvoice();

        (new PolygonForWHMCSInvoice())->markExpiredInvoiceAsReleased();
    }

    /**
     * 检查待支付的发票
     *
     * @return void
     */
    protected function checkPaidInvoice()
    {
        $invoices = (new PolygonForWHMCSInvoice())->getValidInvoices();

        $invoices->each(function ($invoice) {
            $this->checkTransaction($invoice);
        });
    }
}