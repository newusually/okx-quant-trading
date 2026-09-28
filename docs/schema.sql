-- ============================================================================
-- schema.sql — OKX 量化交易系统 数据库初始化脚本
-- 库名: finally   字符集: utf8mb4
-- 用法: mysql -u root < schema.sql
-- 注意: 本脚本只建表头结构, 不含任何业务数据与密钥
-- ============================================================================

CREATE DATABASE IF NOT EXISTS finally DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE finally;

-- ----------------------------------------------------------------------------
-- okx_cred — OKX API 凭证表 (C++ 运行时读取; 部署时自行 INSERT, 不要提交到 git)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS okx_cred (
  id         INT PRIMARY KEY,              -- 固定为 1
  api_key    VARCHAR(64)  NOT NULL,        -- OKX API Key
  secret_key VARCHAR(128) NOT NULL,        -- OKX Secret Key
  passphrase VARCHAR(64)  NOT NULL         -- OKX Passphrase
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- app_settings — 策略参数表 (列名固定为 sval)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  skey  VARCHAR(64) PRIMARY KEY,           -- 参数名 (如 UnitDollar / Leverage)
  sval  VARCHAR(255) NOT NULL              -- 参数值
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- trade_flow — 成交流水台账 (展示必须 ORDER BY trade_time DESC, id DESC)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trade_flow (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  inst_id   VARCHAR(32)  NOT NULL,         -- 合约名 如 ETH-USDT-SWAP
  side      VARCHAR(8)   NOT NULL,         -- buy / sell
  sz        DECIMAL(20,8) NOT NULL,        -- 成交张数/数量
  px        DECIMAL(20,8) DEFAULT NULL,    -- 成交价
  fee       DECIMAL(20,8) DEFAULT 0,       -- 手续费
  remark    VARCHAR(255) DEFAULT '',       -- 信号来源备注 (如 5m+3m 金▲共振)
  trade_time DATETIME NOT NULL,            -- 成交时间
  KEY idx_time (trade_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- position_detail — 持仓台账 (每合约一行快照)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS position_detail (
  inst_id  VARCHAR(32) PRIMARY KEY,        -- 合约名
  avg_px   DECIMAL(20,8) DEFAULT 0,        -- 持仓均价
  last_px  DECIMAL(20,8) DEFAULT 0,        -- 最新价
  upl      DECIMAL(20,8) DEFAULT 0,        -- 未实现盈亏
  upl_ratio DECIMAL(10,4) DEFAULT 0,       -- 收益率
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- pnl_history — 分钟级账户盈利快照 (画盈利曲线用)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pnl_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  snap_time DATETIME NOT NULL,             -- 快照时间
  upl       DECIMAL(20,8) DEFAULT 0,       -- 未实现盈亏
  equity    DECIMAL(20,8) DEFAULT 0,       -- 账户权益
  KEY idx_snap (snap_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- kline_signals — K线信号标注表 (bit0=三均线多头 bit1=神奇九转, 位2~5=九转计数5~9)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kline_signals (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  inst_id     VARCHAR(32) NOT NULL,        -- 合约名
  bar         VARCHAR(8)  NOT NULL,        -- 周期 5m/15m/1H/4H
  candle_time DATETIME    NOT NULL,        -- K线时间
  sigmask     INT NOT NULL DEFAULT 0,      -- 信号位图
  UNIQUE KEY uk_sig (inst_id, bar, candle_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- kline_<inst>_<bar> — K线表 (每个合约每个周期一张表, 由 C++ datahub 自动建表)
-- 列名固定为 o/h/l/c/vol (非 open/high/low/close/volume, 血泪教训!)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kline_ETH-USDT-SWAP_5m (
  t  BIGINT PRIMARY KEY,                   -- K线开始时间 (秒级时间戳)
  o  DECIMAL(20,8) NOT NULL,               -- 开盘价
  h  DECIMAL(20,8) NOT NULL,               -- 最高价
  l  DECIMAL(20,8) NOT NULL,               -- 最低价
  c  DECIMAL(20,8) NOT NULL,               -- 收盘价
  vol DECIMAL(28,8) NOT NULL               -- 成交量
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- bt_reports — AI 模拟交易员回测报告表 (bt_timer.exe 每小时写一场, 保留7天)
-- 结构: 场次ID / 运行时刻 / 回测窗口 / 规模统计 / 一句话简介 / 全员汇总JSON / 前十详细JSON
CREATE TABLE IF NOT EXISTS bt_reports (
  id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT '报告场次ID',
  run_ts BIGINT NOT NULL COMMENT '运行时刻(毫秒)',
  period_start BIGINT NOT NULL DEFAULT 0 COMMENT '回测窗口起(毫秒)',
  period_end BIGINT NOT NULL DEFAULT 0 COMMENT '回测窗口止(毫秒)',
  n_traders INT NOT NULL DEFAULT 0 COMMENT '模拟交易员数',
  n_contracts INT NOT NULL DEFAULT 0 COMMENT '参与合约数',
  n_trades INT NOT NULL DEFAULT 0 COMMENT '总成交笔数',
  brief VARCHAR(255) NOT NULL DEFAULT '' COMMENT '一句话简介(首页列表用)',
  summary_json LONGTEXT COMMENT '全员100行汇总JSON',
  top10_json LONGTEXT COMMENT '前十详细JSON(含曲线/明细/评语/感言)',
  KEY idx_run (run_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI模拟交易员回测报告(每小时一场)';
