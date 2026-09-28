/* ==================================================================
 * hub_db.cpp — MySQL 公共组件 (与原 apihub.cpp/datahub.cpp 同构, 逐字迁移)
 *
 * 文件职责：
 *   提供全系统唯一的 MySQL 数据访问通道。所有线程共用一条全局连接 g_my，
 *   由静态互斥锁 g_dbMtx 串行化保护（同一时刻只允许一个线程执行 SQL），
 *   因此组件内部无需为每线程建连，也天然规避了 MySQL C API 的线程安全问题。
 *
 * 包含函数及作用：
 *   - db_connect_locked  建立连接（要求调用方已持锁），开启断线自动重连，失败记日志
 *   - db_q               执行 SELECT，把结果集取回进程内存（RowSet），逐字段转 std::string
 *   - db_scalar          基于 db_q 取第一行第一列的单值（COUNT/MAX 等聚合查询便捷入口）
 *   - db_ex              执行写语句（INSERT/UPDATE/CREATE/事务控制），只关心成败
 *
 * 数据流向：
 *   各业务组件(ep_* 系列/store/read_recent) → db_q/db_ex → 本文件加锁 → MySQL(trading 库)
 *   → 结果以字符串形式回传给调用方，调用方自行做类型转换(atof_s/atoll_s)。
 *
 * 被谁调用：hub_util.cpp(K线读写)、api_eps.cpp(各接口)、hub_okx.cpp(okx_cred 读凭证)。
 * 全系统依赖本文件的连接与锁，编译进 libhub.a 后随各组件 exe 链接。
 * ================================================================== */
#include "hub.h"                              // 公共声明头(RowSet/函数签名/logline 等)

static std::mutex g_dbMtx;                    // 数据库全局互斥锁: 串行化所有 SQL 执行(单连接模型)
static const char* DB_HOST = "127.0.0.1";     // MySQL 主机: 本机(交易系统与 DB 同机部署, 免网络延迟)
static const char* DB_USER = "root";          // MySQL 用户名
static const char* DB_PASS = "";              // MySQL 密码(本地空密码)
static const char* DB_NAME = "trading";       // 默认业务库名: K线/信号/订单等全部存这里

MYSQL* g_my = nullptr;                        // 全局唯一 MySQL 连接句柄, nullptr 表示尚未连接

bool db_connect_locked() {
    if (g_my) mysql_close(g_my);              // 若已有旧连接先关闭(重连场景: 释放旧句柄避免泄漏)
    g_my = mysql_init(nullptr);               // 初始化 MySQL 连接对象
    if (!g_my) return false;                  // 初始化失败(通常是内存不足)
    my_bool reconnect = 1;                    // 开启客户端断线自动重连标志
    mysql_options(g_my, MYSQL_OPT_RECONNECT, &reconnect); // MySQL 8.x 默认关闭重连, 显式打开防长时间运行掉线
    if (!mysql_real_connect(g_my, DB_HOST, DB_USER, DB_PASS, DB_NAME, 3306, nullptr, 0)) {
        logline(std::string("DB connect FAIL: ") + mysql_error(g_my)); // 连接失败必须落日志, 便于排查宕机原因
        mysql_close(g_my); g_my = nullptr;    // 清空句柄, 下次调用 db_q/db_ex 会再次尝试连接
        return false;
    }
    mysql_set_character_set(g_my, "utf8mb4"); // 字符集 utf8mb4: 兼容策略备注里的中文与 emoji
    return true;                              // 连接成功, 调用方必须已持有 g_dbMtx(见函数名 _locked)
}

RowSet db_q(const std::string& sql) {
    std::lock_guard<std::mutex> lk(g_dbMtx);  // 持锁进入临界区: 保证整个查询过程独占连接
    RowSet rs;                                // 返回值: 默认 ok=false, 失败时直接返回空集
    if (!g_my && !db_connect_locked()) return rs;      // 首次调用: 连接不存在则先建连, 失败即放弃
    if (mysql_ping(g_my) != 0 && !db_connect_locked()) return rs; // 检测连接活性, 掉线则重连一次, 仍失败则放弃
    if (mysql_query(g_my, sql.c_str()) != 0) {
        logline(std::string("db_q FAIL: ") + mysql_error(g_my) + " | " + sql.substr(0, 140)); // 记录错误与 SQL 前 140 字符(定位问题语句)
        return rs;                            // 查询失败: ok 保持 false
    }
    MYSQL_RES* res = mysql_store_result(g_my);// 把服务端结果集整体拉到客户端内存(结果集都不大, 安全)
    if (res) {
        while (MYSQL_ROW r = mysql_fetch_row(res)) {   // 逐行遍历结果集
            std::vector<std::string> row;              // 一行的字符串容器
            for (unsigned i = 0; i < mysql_num_fields(res); i++)
                row.push_back(r[i] ? r[i] : "");       // NULL 列统一转空串, 上层不用再判空
            rs.rows.push_back(std::move(row));         // 移动加入结果集, 免拷贝
        }
        mysql_free_result(res);               // 释放结果集内存(必须, 否则泄漏)
    }
    rs.ok = true;                             // 执行成功标志置位(即使 0 行, SELECT 本身是成功的)
    return rs;
}

std::string db_scalar(const std::string& sql, bool& ok) {
    RowSet rs = db_q(sql);                    // 复用 db_q 执行(自带锁/重连/日志)
    ok = rs.ok && !rs.rows.empty() && !rs.rows[0].empty(); // 成功且至少有一行一列才算取到值
    return ok ? rs.rows[0][0] : "";           // 只返回第一行第一列(聚合查询的典型用法)
}

bool db_ex(const std::string& sql) {
    std::lock_guard<std::mutex> lk(g_dbMtx);  // 写语句同样要持锁(与读共用一条连接)
    if (!g_my && !db_connect_locked()) return false;  // 连接不存在则先建连
    if (mysql_ping(g_my) != 0 && !db_connect_locked()) return false; // 掉线重连一次
    if (mysql_query(g_my, sql.c_str()) != 0) {
        logline(std::string("db_ex FAIL: ") + mysql_error(g_my) + " | " + sql.substr(0, 140)); // 写失败落日志(影响入库完整性, 必须留痕)
        return false;
    }
    return true;                              // 写入成功(不含 affected rows 语义, 调用方不关心)
}
