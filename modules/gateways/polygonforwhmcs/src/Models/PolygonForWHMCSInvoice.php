<?php

namespace PolygonForWHMCS\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PolygonForWHMCSInvoice extends Model
{
    /**
     * 模型关联的数据表
     *
     * @var string
     */
    protected $table = 'mod_polygonforwhmcs_pay_invoices';

    /**
     * 可批量赋值的字段
     *
     * @var string[]
     */
    protected $fillable = [
        'invoice_id',
        'to_address',
        'from_address',
        'transaction_id',
        'expires_on',
        'is_released',
        'start_block',
        'usdt_amount',
        'original_amount',
        'currency',
        'exchange_rate',
        'rate_deviation',
        'is_paid',
    ];

    /**
     * 字段类型转换配置
     *
     * @var string[]
     */
    protected $casts = [
        'expires_on' => 'datetime',
        'usdt_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'rate_deviation' => 'decimal:6',
        'is_paid' => 'boolean',
        'is_released' => 'boolean',
    ];

    /**
     * 查询正在使用的地址
     *
     * @param Builder $builder
     *
     * @return Builder
     */
    public function scopeInUse(Builder $builder): Builder
    {
        return $builder->where('is_released', false);
    }

    /**
     * Assoicated with an invoice.
     *
     * @param string $address
     * @param int $invoiceId
     * @param int $timeout
     *
     * @return  void
     */
    /**
     * Associate address with invoice and store payment information
     *
     * @param string $address
     * @param int $invoiceId
     * @param int $timeout
     * @param string $start_block
     * @param float $usdtAmount
     * @param float $originalAmount
     * @param string $currency
     * @param float $exchangeRate
     * @param float $rateDeviation
     * @return self
     */
    public function associate(
        string $address,
        int    $invoiceId,
        int    $timeout = 30,
        string $start_block = '0',
        float  $usdtAmount = 0,
        float  $originalAmount = 0,
        string $currency = 'USD',
        float  $exchangeRate = 1,
        float  $rateDeviation = 1
    ): self
    {
        return $this->newQuery()->create([
            'to_address' => $address,
            'invoice_id' => $invoiceId,
            'expires_on' => Carbon::now()->addMinutes($timeout),
            'start_block' => $start_block,
            'usdt_amount' => $usdtAmount,
            'original_amount' => $originalAmount,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'rate_deviation' => $rateDeviation,
            'is_paid' => false,
            'is_released' => false,
        ]);
    }

    /**
     * Determine if there is an invoice within the validity period.
     *
     * @param int $invoiceId
     *
     * @return  Collection
     */
    public function validInvoice(int $invoiceId)
    {
        return $this->newQuery()
            ->where('invoice_id', $invoiceId)
            ->where('expires_on', '>', Carbon::now())
            ->where('is_released', false)
            ->first();
    }

    /**
     * Update the expires date.
     *
     * @param int $timeout
     *
     * @return  bool
     */
    public function renew(int $timeout = 30): bool
    {
        return $this->forceFill(['expires_on' => Carbon::now()->addMinutes($timeout)])->save();
    }

    /**
     * Mark all expired invoices as released.
     *
     * @return  void
     */
    public function markExpiredInvoiceAsReleased()
    {
        $this->newQuery()
            ->where('expires_on', '<=', Carbon::now())
            ->where('is_released', false)
            ->update([
                'is_released' => true,
            ]);
    }

    /**
     * Get all valid invoices.
     *
     * @return  Collection
     */
    public function getValidInvoices()
    {
        return $this->newQuery()
            ->where('expires_on', '>', Carbon::now())
            ->where('is_released', 0)
            ->get();
    }

    /**
     * Mark inovice as paid.
     *
     * @param string $fromAddress
     * @param string $transactionId
     *
     * @return  void
     */
    public function markAsPaid(string $fromAddress, string $transactionId)
    {
        $this->forceFill([
            'from_address' => $fromAddress,
            'transaction_id' => $transactionId,
            'is_paid' => true,
            'is_released' => true,
        ])
            ->save();
    }

    /**
     * Get polygonforwhmcs pay valid invoice by invoice id.
     *
     * @param int $invoiceId
     *
     * @return  Model|null
     */
    public function firstValidByInvoiceId(int $invoiceId)
    {
        return $this->newQuery()
            ->where('invoice_id', $invoiceId)
            ->where('expires_on', '>', Carbon::now())
            ->where('is_released', 0)
            ->latest('id')
            ->first();
    }
}