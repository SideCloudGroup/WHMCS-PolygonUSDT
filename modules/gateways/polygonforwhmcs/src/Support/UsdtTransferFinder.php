<?php

namespace PolygonForWHMCS\Support;

use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use PolygonForWHMCS\Exceptions\RpcBlockRangeTooLarge;
use Throwable;

/**
 * 查找打入指定地址的 Polygon USDT Transfer。
 *
 * 主路径：配置了 JSON-RPC 时走 eth_getLogs（按合约、收款地址、start_block 过滤）。
 * 补充路径：Etherscan V2 account/tokentx。空结果不当作硬失败。
 */
class UsdtTransferFinder
{
    public const USDT_CONTRACT = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';

    public const USDT_CONTRACT_CHECKSUM = '0xc2132D05D31c914a87C6611C10748AEb04B58e8F';

    public const TRANSFER_TOPIC0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public const CHAIN_ID = 137;

    /**
     * 缺少 start_block 时的回看区块数（约 24 小时，Polygon ~2s/块）。
     */
    private const RPC_FALLBACK_LOOKBACK_BLOCKS = 43200;

    /**
     * 初始 eth_getLogs 分片大小；节点限制更严时会自动减半。
     */
    private const RPC_LOG_CHUNK_BLOCKS = 5000;

    private const ETHERSCAN_PAGE_SIZE = 100;

    private const ETHERSCAN_MAX_PAGES = 10;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $rpcUrl;

    /**
     * @var int
     */
    private $rpcRequestId = 1;

    public function __construct(string $apiKey, string $rpcUrl)
    {
        $this->apiKey = trim($apiKey);
        $this->rpcUrl = $this->normalizeRpcUrl($rpcUrl);
    }

    /**
     * 合并 RPC 与 tokentx 结果，按交易哈希去重（RPC 优先）。
     */
    public function find(string $address, int $startBlock): Collection
    {
        $merged = [];

        foreach ($this->findViaRpc($address, $startBlock) as $tx) {
            $merged[$tx['hash']] = $tx;
        }

        foreach ($this->findViaEtherscan($address, $startBlock) as $tx) {
            if (! isset($merged[$tx['hash']])) {
                $merged[$tx['hash']] = $tx;
            }
        }

        return new Collection(array_values($merged));
    }

    /**
     * 当前 Polygon 最新区块。优先 RPC，其次 Etherscan proxy。
     */
    public function latestBlock(): int
    {
        $block = $this->rpcBlockNumber();
        if ($block > 0) {
            return $block;
        }

        return $this->etherscanBlockNumber();
    }

    /**
     * @return array<int, array{hash: string, from: string, to: string, contractAddress: string, value: string}>
     */
    private function findViaRpc(string $address, int $startBlock): array
    {
        if ($this->rpcUrl === '') {
            return [];
        }

        $latest = $this->rpcBlockNumber();
        if ($latest <= 0) {
            $this->log('PolygonForWHMCS RPC Error', [
                'method' => 'eth_blockNumber',
                'rpc_host' => $this->rpcHost(),
            ], 'Latest block unavailable');

            return [];
        }

        $fromBlock = $startBlock > 0 ? $startBlock : max(0, $latest - self::RPC_FALLBACK_LOOKBACK_BLOCKS);
        if ($fromBlock > $latest) {
            $fromBlock = $latest;
        }

        try {
            $logs = $this->fetchLogsChunked($fromBlock, $latest, $this->padAddressTopic($address));
        } catch (Throwable $e) {
            $this->log('PolygonForWHMCS RPC Error', [
                'method' => 'eth_getLogs',
                'rpc_host' => $this->rpcHost(),
                'error' => $e->getMessage(),
            ], 'RPC Error');

            return [];
        }

        $transfers = [];
        foreach ($logs as $log) {
            $tx = $this->normalizeRpcLog($log, $address);
            if ($tx !== null) {
                $transfers[] = $tx;
            }
        }

        return $transfers;
    }

    /**
     * @return array<int, array{hash: string, from: string, to: string, contractAddress: string, value: string}>
     */
    private function findViaEtherscan(string $address, int $startBlock): array
    {
        if ($this->apiKey === '') {
            return [];
        }

        $transfers = [];
        [$startblock, $sort] = $this->etherscanRange($startBlock);

        for ($page = 1; $page <= self::ETHERSCAN_MAX_PAGES; $page++) {
            try {
                $data = $this->fetchTokentxPage($address, $startblock, $page, $sort);
            } catch (Throwable $e) {
                $this->log('PolygonForWHMCS API Error', [
                    'endpoint' => 'tokentx',
                    'address' => $address,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ], 'API Error');
                break;
            }

            if ($this->isEmptyTokentxResult($data)) {
                break;
            }

            $result = $data['result'] ?? null;
            if (! is_array($result)) {
                $this->log('PolygonForWHMCS API Error', [
                    'endpoint' => 'tokentx',
                    'address' => $address,
                    'page' => $page,
                    'status' => $data['status'] ?? null,
                    'message' => $data['message'] ?? null,
                    'result' => is_string($result) ? $result : null,
                ], 'API Error');
                break;
            }

            foreach ($result as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $tx = $this->normalizeEtherscanRow($row, $address);
                if ($tx !== null) {
                    $transfers[] = $tx;
                }
            }

            if (count($result) < self::ETHERSCAN_PAGE_SIZE) {
                break;
            }
        }

        return $transfers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLogsChunked(int $fromBlock, int $toBlock, string $paddedTo): array
    {
        $logs = [];
        $chunk = self::RPC_LOG_CHUNK_BLOCKS;
        $cursor = $fromBlock;

        while ($cursor <= $toBlock) {
            $end = min($cursor + $chunk - 1, $toBlock);
            try {
                $logs = array_merge($logs, $this->ethGetLogs($cursor, $end, $paddedTo));
                $cursor = $end + 1;
            } catch (RpcBlockRangeTooLarge $e) {
                if ($chunk <= 1) {
                    throw $e;
                }
                $chunk = max(1, intdiv($chunk, 2));
            }
        }

        return $logs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ethGetLogs(int $fromBlock, int $toBlock, string $paddedTo): array
    {
        $result = $this->rpcCall('eth_getLogs', [[
            'fromBlock' => $this->toHexBlock($fromBlock),
            'toBlock' => $this->toHexBlock($toBlock),
            'address' => self::USDT_CONTRACT_CHECKSUM,
            'topics' => [
                self::TRANSFER_TOPIC0,
                null,
                $paddedTo,
            ],
        ]]);

        if (! is_array($result)) {
            throw new \RuntimeException('unexpected eth_getLogs result');
        }

        return $result;
    }

    private function rpcBlockNumber(): int
    {
        if ($this->rpcUrl === '') {
            return 0;
        }

        try {
            $result = $this->rpcCall('eth_blockNumber', []);
            if (is_string($result) && preg_match('/^0x[0-9a-f]+$/i', $result)) {
                return hexdec($result);
            }
        } catch (Throwable $e) {
            $this->log('PolygonForWHMCS RPC Error', [
                'method' => 'eth_blockNumber',
                'rpc_host' => $this->rpcHost(),
                'error' => $e->getMessage(),
            ], 'RPC Error');
        }

        return 0;
    }

    /**
     * @param array<int, mixed> $params
     * @return mixed
     */
    private function rpcCall(string $method, array $params)
    {
        $http = new Client([
            'timeout' => 30,
            'http_errors' => false,
        ]);

        $response = $http->post($this->rpcUrl, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => $this->rpcRequestId++,
                'method' => $method,
                'params' => $params,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            throw new \RuntimeException('invalid JSON-RPC response HTTP ' . $statusCode);
        }

        if (isset($body['error'])) {
            $code = 0;
            $message = 'JSON-RPC error';
            if (is_array($body['error'])) {
                $code = (int) ($body['error']['code'] ?? 0);
                $message = (string) ($body['error']['message'] ?? $message);
            } elseif (is_string($body['error'])) {
                $message = $body['error'];
            }

            if ($this->isRangeLimitError($code, $message)) {
                throw new RpcBlockRangeTooLarge($message, $code);
            }

            throw new \RuntimeException($message, $code);
        }

        return $body['result'] ?? null;
    }

    private function etherscanBlockNumber(): int
    {
        if ($this->apiKey === '') {
            return 0;
        }

        try {
            $http = new Client([
                'base_uri' => 'https://api.etherscan.io',
                'timeout' => 30,
                'http_errors' => false,
            ]);

            $response = $http->get('/v2/api', [
                'query' => [
                    'chainid' => self::CHAIN_ID,
                    'module' => 'proxy',
                    'action' => 'eth_blockNumber',
                    'apikey' => $this->apiKey,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (isset($data['result']) && is_string($data['result']) && preg_match('/^0x[0-9a-f]+$/i', $data['result'])) {
                return hexdec($data['result']);
            }
        } catch (Throwable $e) {
            $this->log('PolygonForWHMCS API Error', [
                'endpoint' => 'eth_blockNumber',
                'error' => $e->getMessage(),
            ], 'API Error');
        }

        return 0;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function etherscanRange(int $startBlock): array
    {
        if ($startBlock > 0) {
            return [$startBlock, 'asc'];
        }

        $latest = $this->etherscanBlockNumber();
        if ($latest > 0) {
            return [max(0, $latest - self::RPC_FALLBACK_LOOKBACK_BLOCKS), 'asc'];
        }

        // 没有可靠起点时改为最新优先，避免从创世块升序翻页漏掉刚到账的支付
        return [0, 'desc'];
    }

    private function fetchTokentxPage(string $address, int $startblock, int $page, string $sort): array
    {
        $http = new Client([
            'base_uri' => 'https://api.etherscan.io',
            'timeout' => 30,
            'http_errors' => false,
        ]);

        $response = $http->get('/v2/api', [
            'query' => [
                'chainid' => self::CHAIN_ID,
                'module' => 'account',
                'action' => 'tokentx',
                'address' => $address,
                'page' => $page,
                'offset' => self::ETHERSCAN_PAGE_SIZE,
                'startblock' => $startblock,
                'sort' => $sort,
                'apikey' => $this->apiKey,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Etherscan 在无交易时返回 status=0 / No transactions found，这是空列表而不是故障。
     *
     * @param array<string, mixed> $data
     */
    private function isEmptyTokentxResult(array $data): bool
    {
        $message = strtolower(trim((string) ($data['message'] ?? '')));
        $result = $data['result'] ?? null;
        $status = (string) ($data['status'] ?? '');

        if ($message === 'no transactions found') {
            return true;
        }

        if (is_string($result) && strtolower(trim($result)) === 'no transactions found') {
            return true;
        }

        if ($status === '0' && is_array($result) && count($result) === 0) {
            return true;
        }

        if ($status === '1' && is_array($result) && count($result) === 0) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $log
     * @return array{hash: string, from: string, to: string, contractAddress: string, value: string}|null
     */
    private function normalizeRpcLog($log, string $expectedTo): ?array
    {
        if (! is_array($log)) {
            return null;
        }

        if (! empty($log['removed'])) {
            return null;
        }

        $topics = $log['topics'] ?? [];
        if (! is_array($topics) || count($topics) < 3) {
            return null;
        }

        $contract = strtolower((string) ($log['address'] ?? ''));
        if ($contract !== self::USDT_CONTRACT) {
            return null;
        }

        $topic0 = strtolower((string) $topics[0]);
        if ($topic0 !== self::TRANSFER_TOPIC0) {
            return null;
        }

        $hash = strtolower((string) ($log['transactionHash'] ?? ''));
        if ($hash === '' || ! preg_match('/^0x[0-9a-f]{64}$/', $hash)) {
            return null;
        }

        $from = $this->addressFromTopic((string) $topics[1]);
        $to = $this->addressFromTopic((string) $topics[2]);
        if ($to === '' || strtolower($to) !== strtolower($expectedTo)) {
            return null;
        }

        return [
            'hash' => $hash,
            'from' => $from,
            'to' => $to,
            'contractAddress' => $contract,
            'value' => $this->hexToDec((string) ($log['data'] ?? '0x0')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{hash: string, from: string, to: string, contractAddress: string, value: string}|null
     */
    private function normalizeEtherscanRow(array $row, string $expectedTo): ?array
    {
        $contract = strtolower((string) ($row['contractAddress'] ?? ''));
        if ($contract !== self::USDT_CONTRACT) {
            return null;
        }

        $to = strtolower((string) ($row['to'] ?? ''));
        if ($to !== strtolower($expectedTo)) {
            return null;
        }

        $hash = strtolower((string) ($row['hash'] ?? ''));
        if ($hash === '' || ! preg_match('/^0x[0-9a-f]{64}$/', $hash)) {
            return null;
        }

        $value = $row['value'] ?? '0';
        if (! is_numeric($value)) {
            return null;
        }

        return [
            'hash' => $hash,
            'from' => strtolower((string) ($row['from'] ?? '')),
            'to' => $to,
            'contractAddress' => $contract,
            'value' => (string) $value,
        ];
    }

    private function addressFromTopic(string $topic): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', $topic) ?? '');
        if (strlen($hex) < 40) {
            return '';
        }

        return '0x' . substr($hex, -40);
    }

    private function padAddressTopic(string $address): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', $address) ?? '');

        return '0x' . str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    private function hexToDec(string $hex): string
    {
        $hex = strtolower(preg_replace('/^0x/i', '', $hex) ?? '');
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }

        if (function_exists('gmp_init')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }

        $dec = '0';
        $length = strlen($hex);
        for ($i = 0; $i < $length; $i++) {
            $dec = bcmul($dec, '16', 0);
            $dec = bcadd($dec, (string) hexdec($hex[$i]), 0);
        }

        return $dec;
    }

    private function toHexBlock(int $block): string
    {
        return '0x' . dechex($block);
    }

    private function isRangeLimitError(int $code, string $message): bool
    {
        if ($code === -32005) {
            return true;
        }

        return (bool) preg_match(
            '/block range|too large|too many logs|query returned more|response size|limit exceeded|range limit|max.*range|query exceeds|timeout/i',
            $message
        );
    }

    private function normalizeRpcUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    private function rpcHost(): string
    {
        $host = parse_url($this->rpcUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unknown-rpc-host';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function log(string $gateway, array $data, string $result): void
    {
        if (function_exists('logTransaction')) {
            logTransaction($gateway, $data, $result);
        }
    }
}
