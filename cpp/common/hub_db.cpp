// ==================================================================
// hub_db.cpp — MySQL 公共组件 (与原 apihub.cpp/datahub.cpp 同构, 逐字迁移)
// 全局互斥线程安全; 自动重连; 失败写日志
// ==================================================================
#include "hub.h"

static std::mutex g_dbMtx;
static const char* DB_HOST = "127.0.0.1";
static const char* DB_USER = "root";
static const char* DB_PASS = "";
static const char* DB_NAME = "trading";

MYSQL* g_my = nullptr;

bool db_connect_locked() {
    if (g_my) mysql_close(g_my);
    g_my = mysql_init(nullptr);
    if (!g_my) return false;
    my_bool reconnect = 1;
    mysql_options(g_my, MYSQL_OPT_RECONNECT, &reconnect);
    if (!mysql_real_connect(g_my, DB_HOST, DB_USER, DB_PASS, DB_NAME, 3306, nullptr, 0)) {
        logline(std::string("DB connect FAIL: ") + mysql_error(g_my));
        mysql_close(g_my); g_my = nullptr;
        return false;
    }
    mysql_set_character_set(g_my, "utf8mb4");
    return true;
}

RowSet db_q(const std::string& sql) {
    std::lock_guard<std::mutex> lk(g_dbMtx);
    RowSet rs;
    if (!g_my && !db_connect_locked()) return rs;
    if (mysql_ping(g_my) != 0 && !db_connect_locked()) return rs;
    if (mysql_query(g_my, sql.c_str()) != 0) {
        logline(std::string("db_q FAIL: ") + mysql_error(g_my) + " | " + sql.substr(0, 140));
        return rs;
    }
    MYSQL_RES* res = mysql_store_result(g_my);
    if (res) {
        while (MYSQL_ROW r = mysql_fetch_row(res)) {
            std::vector<std::string> row;
            for (unsigned i = 0; i < mysql_num_fields(res); i++)
                row.push_back(r[i] ? r[i] : "");
            rs.rows.push_back(std::move(row));
        }
        mysql_free_result(res);
    }
    rs.ok = true;
    return rs;
}

std::string db_scalar(const std::string& sql, bool& ok) {
    RowSet rs = db_q(sql);
    ok = rs.ok && !rs.rows.empty() && !rs.rows[0].empty();
    return ok ? rs.rows[0][0] : "";
}

bool db_ex(const std::string& sql) {
    std::lock_guard<std::mutex> lk(g_dbMtx);
    if (!g_my && !db_connect_locked()) return false;
    if (mysql_ping(g_my) != 0 && !db_connect_locked()) return false;
    if (mysql_query(g_my, sql.c_str()) != 0) {
        logline(std::string("db_ex FAIL: ") + mysql_error(g_my) + " | " + sql.substr(0, 140));
        return false;
    }
    return true;
}
