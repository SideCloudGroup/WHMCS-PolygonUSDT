# Polygon USDT 支付网关 for WHMCS

一个专为WHMCS设计的Polygon网络USDT支付网关，支持自动确认收款、汇率偏移设置和多地址并发支付。

## ✨ 主要特性

- 🔗 **链上直读** - 主检测走 Polygon JSON-RPC `eth_getLogs`，不依赖 Polygonscan/Etherscan 的 tokentx 索引
- 💰 **自动收款确认** - 实时监控官方 USDT 合约转入，自动确认订单支付
- 📊 **智能汇率处理** - 支持多种货币和自定义汇率偏移（优惠/手续费）
- 🎯 **高精度计算** - 使用bcmath进行高精度数学运算，避免浮点数误差
- 🏦 **多地址管理** - 支持配置多个收款地址，实现并发支付
- ⏰ **支付超时控制** - 可配置支付超时时间，自动释放过期地址
- 🔄 **平滑升级** - 数据库迁移支持平滑升级，兼容旧版本数据

## 🛠 系统要求

- **WHMCS** 8.1+
- **PHP** 8.0+
- **PHP扩展**: bcmath, json, curl
- **Polygon JSON-RPC URL**（推荐）- 自有节点，或 Alchemy / Infura / QuickNode 等
- **Etherscan V2 API Key**（补充检测，可选）- [免费获取](https://etherscan.io/apis)

## 📥 安装步骤

### 1. 下载并解压文件

```bash
# 下载最新版本
wget https://github.com/your-repo/WHMCS-PolygonUSDT/archive/main.zip
unzip main.zip

# 复制文件到WHMCS目录
cp -r WHMCS-PolygonUSDT/* /path/to/whmcs/
```

### 2. 配置网关

1. 登录WHMCS管理后台
2. 导航到 `设置` > `支付` > `支付网关`
3. 找到并启用 `Polygon USDT支付`
4. 配置以下参数：

| 参数                      | 说明 | 示例 |
|-------------------------|------|------|
| **USDT收款地址**            | 每行一个Polygon钱包地址 | `0x1234...abcd`<br>`0x5678...efgh` |
| **支付超时时间**              | 支付超时时间（分钟） | `30` |
| **Polygon JSON-RPC URL** | 推荐。用于 `eth_getLogs` 直读链上 USDT Transfer | `https://polygon-mainnet.g.alchemy.com/v2/你的密钥` |
| **Etherscan V2 API密钥**  | 补充检测。tokentx 索引延迟时不能作为唯一依据 | 从 etherscan.io 获取 |
| **汇率偏移值**               | 小于1给优惠，大于1收手续费 | `0.96`（4%优惠）<br>`1.02`（2%手续费） |

### 3. 验证安装

- 创建测试订单，选择USDT支付方式
- 检查是否正确显示收款地址和金额
- 查看模块日志确认API连接正常

## 💡 工作原理

### 支付流程

1. **生成订单** → 系统分配一个空闲收款地址
2. **计算金额** → 根据汇率和偏移值计算USDT支付金额
3. **用户支付** → 用户向指定地址转账USDT
4. **自动确认** → Cron / 发票页 AJAX 先查 Polygon RPC `eth_getLogs`（官方 USDT 合约 + 收款地址 + `start_block`），再以 Etherscan `tokentx` 作补充；`status=0 / No transactions found` 视为空列表而非 API 失败
5. **订单完成** → 释放地址，标记订单为已支付

同一笔链上交易只会入账一次：会同时检查 WHMCS `tblaccounts` 和插件表 `transaction_id`（唯一索引，NULL 未支付行可并存）。

### 金额计算公式

```
发票货币为USD：USDT金额 = 发票金额 × 汇率偏移值
其他货币：USDT金额 = 发票金额 × USD汇率 × 汇率偏移值
```

## ⚙️ 高级配置

### 汇率偏移设置

- **优惠模式**: 设置 < 1.0（如0.96 = 4%优惠）
- **手续费模式**: 设置 > 1.0（如1.02 = 2%手续费）
- **无偏移**: 设置 = 1.0

### Polygon JSON-RPC URL

主检测依赖该 URL，请在网关设置中填写 Polygon 主网（chainId 137）的 HTTP JSON-RPC 地址：

1. **推荐**：自有节点，或 Alchemy / Infura / QuickNode 等带配额的节点（URL 中的密钥仅保存在 WHMCS 后台，不要写入仓库）。
2. **未配置**：已有安装可继续工作，系统会回退到 Etherscan `tokentx`。此时若 Polygonscan 索引损坏或返回空结果，将**不会**再把空结果记为硬错误，但也可能因此漏单，所以生产环境仍建议配置 RPC。
3. **公共节点**：如 `https://polygon-rpc.com` 可临时使用，可能限流或不稳定，不要当作唯一依赖。

查询会按官方 USDT 合约 `0xc2132D05D31c914a87C6611C10748AEb04B58e8F`、当前发票收款地址和 `start_block` 过滤，并在节点限制区块跨度时自动分片，不会全量扫描合约历史。

### 多地址配置

```
0x1234567890abcdef1234567890abcdef12345678
0x2345678901bcdef12345678901bcdef123456789
0x3456789012cdef123456789012cdef1234567890
```

### 调试日志

启用WHMCS模块日志：

1. `设置` > `常规设置` > `其他`
2. 启用 `模块调试日志`
3. 查看 `系统` > `模块日志`

## 🏗 架构设计

### 目录结构

```
modules/gateways/
├── polygonforwhmcs.php              # 主网关文件
└── polygonforwhmcs/
    ├── autoload.php                 # 自动加载器
    ├── src/
    │   ├── App.php                  # 核心应用类
    │   ├── Exceptions/
    │   ├── Models/                  # 数据模型
    │   │   ├── Invoice.php
    │   │   ├── PolygonForWHMCSInvoice.php
    │   │   └── Transaction.php
    │   ├── Support/                 # 链上检测
    │   │   └── UsdtTransferFinder.php
    │   └── Migrations/              # 数据库迁移
    │       └── CreatePolygonForWHMCSInvoicesTable.php
    └── templates/                   # 前端模板
        ├── pay_with_usdt.tpl
        └── payment.tpl
```

### 数据库表结构

| 字段                | 类型            | 说明           |
|-------------------|---------------|--------------|
| `invoice_id`      | int           | WHMCS发票ID    |
| `to_address`      | string        | 收款地址         |
| `usdt_amount`     | decimal(10,2) | USDT金额（2位小数） |
| `original_amount` | decimal(10,2) | 原始发票金额       |
| `currency`        | string(10)    | 发票货币类型       |
| `exchange_rate`   | decimal(10,6) | 当时汇率         |
| `rate_deviation`  | decimal(8,6)  | 汇率偏移值        |
| `expires_on`      | timestamp     | 过期时间         |
| `is_paid`         | boolean       | 是否已支付        |

## 🤝 技术支持

- **问题反馈**: [GitHub Issues](https://github.com/your-repo/issues)
- **文档Wiki**: [项目Wiki](https://github.com/your-repo/wiki)
- **更新日志**: 查看 [CHANGELOG.md](CHANGELOG.md)

## 📄 开源协议

本项目采用 [MIT License](LICENSE) 开源协议。

## 🔄 更新日志

### v2.1.0 (2026-11-16)

- ✨ 主检测改为 Polygon JSON-RPC `eth_getLogs`，按官方 USDT 合约、收款地址和 `start_block` 过滤
- ✨ Etherscan `tokentx` 降为补充路径，并分页查询，避免 `offset=5` 漏单
- 🐛 `status=0 / No transactions found` 视为空列表，不再当作 API 失败而跳过匹配
- ✨ 新增可选「Polygon JSON-RPC URL」网关设置；未配置时保持安全回退
- ✨ `transaction_id` 唯一索引，防止同一链上交易重复入账

### v2.0.0 (2025-01-XX)

- ✨ 重构为Etherscan V2 API
- ✨ 新增bcmath高精度计算
- ✨ 优化数据库存储结构
- ✨ 改进支付验证逻辑
- ✨ 全面中文化注释和文档

**⚡ Polygon USDT支付网关 - 让WHMCS支付更简单、更可靠！**