<?php

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

class CreatePolygonForWHMCSInvoicesTable
{
    /**
     * 执行数据库迁移
     *
     * @return void
     */
    public function execute()
    {
        /** @var  Illuminate\Support\Facades\Schema $schema */
        $schema = Capsule::schema();

        // 如果表不存在，则创建新表
        if (! $schema->hasTable('mod_polygonforwhmcs_pay_invoices')) {
            $schema->create('mod_polygonforwhmcs_pay_invoices', function (Blueprint $table) {
                $table->id();
                $table->integer('invoice_id');
                $table->string('to_address');
                $table->string('from_address')->nullable();
                $table->string('transaction_id')->nullable();
                $table->timestamp('expires_on')->nullable();
                $table->boolean('is_released')->default(false);
                $table->integer("start_block")->nullable();
                $table->decimal('usdt_amount', 10, 2)->default(0)->comment('计算后的USDT金额(2位小数)');
                $table->decimal('original_amount', 10, 2)->default(0)->comment('原始发票金额');
                $table->string('currency', 10)->default('USD')->comment('发票货币');
                $table->decimal('exchange_rate', 10, 6)->default(1)->comment('汇率');
                $table->decimal('rate_deviation', 8, 6)->default(1)->comment('汇率偏移值');
                $table->boolean('is_paid')->default(false)->comment('是否已支付');
                $table->timestamps();

                // 添加数据库索引优化查询性能
                $table->index('invoice_id');
                $table->index('to_address');
                $table->index(['is_released', 'expires_on']);
            });
        } else {
            // 表已存在时，检查并添加缺失的字段（支持平滑升级）
            $this->addMissingColumns($schema);
        }
    }

    /**
     * 添加缺失的字段到现有表
     * 支持从旧版本平滑升级到新版本
     *
     * @param $schema 数据库架构构建器
     * @return void
     */
    private function addMissingColumns($schema)
    {
        if ($schema->hasTable('mod_polygonforwhmcs_pay_invoices')) {
            $columnsToAdd = [];

            if (! $schema->hasColumn('mod_polygonforwhmcs_pay_invoices', 'usdt_amount')) {
                $columnsToAdd[] = 'usdt_amount';
            }
            if (! $schema->hasColumn('mod_polygonforwhmcs_pay_invoices', 'original_amount')) {
                $columnsToAdd[] = 'original_amount';
            }
            if (! $schema->hasColumn('mod_polygonforwhmcs_pay_invoices', 'currency')) {
                $columnsToAdd[] = 'currency';
            }
            if (! $schema->hasColumn('mod_polygonforwhmcs_pay_invoices', 'exchange_rate')) {
                $columnsToAdd[] = 'exchange_rate';
            }
            if (! $schema->hasColumn('mod_polygonforwhmcs_pay_invoices', 'rate_deviation')) {
                $columnsToAdd[] = 'rate_deviation';
            }
            if (! $schema->hasColumn('mod_polygonforwhmcs_pay_invoices', 'is_paid')) {
                $columnsToAdd[] = 'is_paid';
            }

            // 如果有缺失的字段，则批量添加
            if (! empty($columnsToAdd)) {
                $schema->table('mod_polygonforwhmcs_pay_invoices', function (Blueprint $table) use ($columnsToAdd) {
                    foreach ($columnsToAdd as $column) {
                        switch ($column) {
                            case 'usdt_amount':
                                $table->decimal('usdt_amount', 10, 2)->default(0)->comment('计算后的USDT金额(2位小数)');
                                break;
                            case 'original_amount':
                                $table->decimal('original_amount', 10, 2)->default(0)->comment('原始发票金额');
                                break;
                            case 'currency':
                                $table->string('currency', 10)->default('USD')->comment('发票货币');
                                break;
                            case 'exchange_rate':
                                $table->decimal('exchange_rate', 10, 6)->default(1)->comment('汇率');
                                break;
                            case 'rate_deviation':
                                $table->decimal('rate_deviation', 8, 6)->default(1)->comment('汇率偏移值');
                                break;
                            case 'is_paid':
                                $table->boolean('is_paid')->default(false)->comment('是否已支付');
                                break;
                        }
                    }
                });
            }
        }
    }
}