-- ============================================================================
-- okx_cred.example.sql — OKX API 凭证示例 (复制为 okx_cred.sql 填入真实值后导入)
-- 警告: 填入真实凭证后此文件绝不能提交到 git / 上传任何地方!
-- 导入: mysql -u root finally < okx_cred.sql
-- ============================================================================
USE finally;

-- 清掉旧凭证后插入 (id 固定为 1, C++ 只读 id=1 这一行)
DELETE FROM okx_cred WHERE id = 1;
INSERT INTO okx_cred (id, api_key, secret_key, passphrase) VALUES
(1,
 'YOUR_OKX_API_KEY_HERE',      -- 在 OKX 官网 "交易-API" 创建, 需开通交易权限
 'YOUR_OKX_SECRET_KEY_HERE',   -- 创建时显示的 Secret (只显示一次, 务必备份)
 'YOUR_OKX_PASSPHRASE_HERE'    -- 创建 API 时自己设置的口令短语
);
