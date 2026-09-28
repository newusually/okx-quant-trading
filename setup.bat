@echo off
REM ============================================================================
REM  setup.bat — OKX 量化交易系统 一键环境安装 + 编译脚本
REM ============================================================================
REM  功能: 自动检测并安装编译/运行本系统所需的全部环境:
REM    1. XAMPP (Apache 2.4 + PHP 8.2 + MariaDB 10.4)      — 网页与数据库
REM    2. MinGW-w64 g++ 13.1.0                              — C++ 编译器
REM    3. MySQL Connector C 6.1.11                          — C++ 数据库开发头文件/库
REM    4. 配置 php.ini (启用 FFI 扩展) 与 Apache 反向代理模块
REM    5. 创建数据库 finally 与全部核心表
REM    6. 编译全部 C++ 组件 (libhub.a / sigcore.dll / apihub / tphub / datahub / guard / tradehub)
REM  用法: 双击运行, 或在命令行执行 setup.bat [install|build|all]
REM    install = 只安装环境    build = 只编译    all = 安装+编译 (默认)
REM  注意: 需要联网下载安装包; 安装目录固定为 E:\xampp 与 E:\Qt\Tools\Tools\mingw1310_64
REM ============================================================================

setlocal enabledelayedexpansion
set "MODE=%1"
if "%MODE%"=="" set "MODE=all"

REM ---- 固定路径(与本系统线上部署一致, 如需改动请同步修改 cpp 源码内路径) ----
set "XAMPP=E:\xampp"
set "MINGW=E:\Qt\Tools\Tools\mingw1310_64\bin"
set "GXX=%MINGW%\g++.exe"
set "VENDOR=%~dp0cpp\vendor\mysql-connector-c-6.1.11-winx64"
set "SRC=%~dp0cpp"
set "BIN=%~dp0cpp\bin"
set "OBJ=%BIN%\obj"
set "DLDIR=%TEMP%\okx_setup_dl"

echo.
echo ============================================================
echo   OKX 量化交易系统 环境安装器  (模式: %MODE%)
echo ============================================================
echo.

if /i "%MODE%"=="build" goto :BUILD

REM ============================================================================
REM 第 1 步: 安装 XAMPP (Apache + PHP + MariaDB)
REM ============================================================================
echo [1/6] 检查 XAMPP ...
if exist "%XAMPP%\apache\bin\httpd.exe" (
    echo     已安装: %XAMPP%  跳过下载
) else (
    echo     未安装, 下载 XAMPP 8.2.12 (约 150MB) ...
    if not exist "%DLDIR%" mkdir "%DLDIR%"
    powershell -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://sourceforge.net/projects/xampp/files/XAMPP%%20Windows/8.2.12/xampp-windows-x64-8.2.12-0-VS16-installer.exe' -OutFile '%DLDIR%\xampp_setup.exe'"
    if errorlevel 1 (
        echo     [错误] XAMPP 下载失败, 请手动从 https://www.apachefriends.org 下载安装到 %XAMPP%
        pause & exit /b 1
    )
    echo     静默安装到 %XAMPP% ...
    "%DLDIR%\xampp_setup.exe" --mode unattended --unattendmodefile "%~dp0docs\xampp_unattend.xml" --prefix "%XAMPP%" 2>nul || "%DLDIR%\xampp_setup.exe" --mode unattended --prefix "%XAMPP%"
    if not exist "%XAMPP%\apache\bin\httpd.exe" (
        echo     [错误] 安装未完成, 请手动安装 XAMPP 到 %XAMPP% 后重新运行
        pause & exit /b 1
    )
)

REM ============================================================================
REM 第 2 步: 安装 MinGW-w64 g++ 编译器
REM ============================================================================
echo [2/6] 检查 MinGW-w64 g++ ...
if exist "%GXX%" (
    echo     已安装: %GXX%
) else (
    echo     未安装, 下载 MinGW-w64 13.1.0 (约 120MB) ...
    if not exist "%DLDIR%" mkdir "%DLDIR%"
    powershell -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://github.com/niXman/mingw-builds-binaries/releases/download/13.1.0-rt_v11-rev1/x86_64-13.1.0-release-posix-seh-ucrt-rt_v11-rev1.7z' -OutFile '%DLDIR%\mingw.7z'"
    if errorlevel 1 (
        echo     [错误] MinGW 下载失败, 请手动下载解压到 E:\Qt\Tools\Tools\mingw1310_64
        echo     地址: https://github.com/niXman/mingw-builds-binaries/releases/tag/13.1.0-rt_v11-rev1
        pause & exit /b 1
    )
    echo     解压到 E:\Qt\Tools\Tools\ ... (需要 7z, 若失败请手动解压)
    where 7z >nul 2>nul && (7z x -y -oE:\Qt\Tools\Tools "%DLDIR%\mingw.7z" >nul) || (tar -xf "%DLDIR%\mingw.7z" -C E:\Qt\Tools\Tools 2>nul)
    if not exist "%GXX%" (
        echo     [提示] 自动解压失败, 请手动将压缩包解压为 E:\Qt\Tools\Tools\mingw1310_64 后重新运行
        pause & exit /b 1
    )
)

REM ============================================================================
REM 第 3 步: 安装 MySQL Connector C 6.1.11 (C++ 头文件与链接库)
REM ============================================================================
echo [3/6] 检查 MySQL Connector C ...
if exist "%VENDOR%\include\mysql.h" (
    echo     已存在: %VENDOR%
) else (
    echo     下载 MySQL Connector C 6.1.11 (约 25MB) ...
    if not exist "%DLDIR%" mkdir "%DLDIR%"
    powershell -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://downloads.mysql.com/archives/get/p/19/file/mysql-connector-c-6.1.11-winx64.zip' -OutFile '%DLDIR%\mysqlc.zip'"
    if errorlevel 1 (
        echo     [错误] 下载失败, 请手动从 https://downloads.mysql.com/archives/c-c/ 下载
        echo     mysql-connector-c-6.1.11-winx64.zip 并解压到 %VENDOR%
        pause & exit /b 1
    )
    echo     解压到 %VENDOR% ...
    powershell -Command "Expand-Archive -Force '%DLDIR%\mysqlc.zip' '%TEMP%\okx_mysqlc'"
    robocopy "%TEMP%\okx_mysqlc\mysql-connector-c-6.1.11-winx64" "%VENDOR%" /E /NFL /NDL /NJH /NJS >nul
    rd /s /q "%TEMP%\okx_mysqlc" 2>nul
)

REM ============================================================================
REM 第 4 步: 配置 PHP (启用 FFI 扩展, sigcore.dll 需要) 与 Apache (反向代理)
REM ============================================================================
echo [4/6] 配置 PHP FFI 与 Apache 代理 ...
if exist "%XAMPP%\php\php.ini" (
    findstr /c:"extension=php_ffi" "%XAMPP%\php\php.ini" >nul 2>nul
    if errorlevel 1 (
        echo extension=php_ffi>>"%XAMPP%\php\php.ini"
        echo ffi.enable=true>>"%XAMPP%\php\php.ini"
        echo     已追加 php_ffi 配置到 php.ini
    ) else (
        echo     php.ini 已含 FFI 配置
    )
)
if exist "%XAMPP%\apache\conf\extra\httpd-proxy.conf" (
    echo     httpd-proxy.conf 已存在
) else (
    REM 启用代理模块: 80 端口非 PHP 请求转发到 C++ apihub (:8090)
    echo LoadModule proxy_module modules/mod_proxy.so>>"%XAMPP%\apache\conf\httpd.conf"
    echo LoadModule proxy_http_module modules/mod_proxy_http.so>>"%XAMPP%\apache\conf\httpd.conf"
    echo ProxyPassMatch "^/(?!.*\.php)(?!.*\.html)(?!.*_archived)(.*)$" "http://127.0.0.1:8090/$1">"%XAMPP%\apache\conf\extra\httpd-proxy.conf"
    echo Include conf/extra/httpd-proxy.conf>>"%XAMPP%\apache\conf\httpd.conf"
    echo     已写入 Apache 反向代理配置
)

REM ============================================================================
REM 第 5 步: 初始化数据库 finally + 核心表
REM ============================================================================
echo [5/6] 初始化数据库 ...
set "MYSQL=%XAMPP%\mysql\bin\mysql.exe"
if not exist "%MYSQL%" (
    echo     [警告] 未找到 mysql.exe, 请先启动 XAMPP 的 MySQL 服务后手动导入 docs\schema.sql
) else (
    "%MYSQL%" -u root < "%~dp0docs\schema.sql" 2>nul && (
        echo     数据库 finally 与核心表已创建
    ) || (
        echo     [提示] 数据库可能已存在或 MySQL 未启动; 也可手动导入 docs\schema.sql
    )
    echo.
    echo     [数据初始化说明]
    echo     - OKX 凭证: 复制 configs\okx_cred.example.sql 填入真实 Key 后导入
    echo     - 策略参数: 编译期硬锁在 cpp\trade\tradehub.h 的 LOCK_* 常量
    echo       (1U/20X/止盈+2%%/持仓12/时买3/冷却60分), 修改后重新编译即可
    echo     - K线历史回补: 启动 datahub.exe 后自动全市场分级回补,
    echo       tradehub.exe 启动时自动回填成交流水与K线缺口, 无需手工操作
)

REM ============================================================================
REM 第 6 步: 编译全部 C++ 组件
REM ============================================================================
:BUILD
echo [6/6] 编译 C++ 组件 ...
if not exist "%GXX%" (
    echo     [错误] 未找到 g++, 请先运行: setup.bat install
    pause & exit /b 1
)
if not exist "%OBJ%" mkdir "%OBJ%"

set "INC=-I%VENDOR%\include"
set "LIBS=-L%VENDOR%\lib -l:libmysql.dll -lwinhttp -lws2_32 -lbcrypt"
set "CFLAGS=-std=gnu++17 -O2 -Wall -Wextra -static"

echo     --- 编译公共组件库 libhub.a ---
"%GXX%" %CFLAGS% -mwindows -c %SRC%\common\hub_db.cpp   -o %OBJ%\hub_db.o   %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\common\hub_okx.cpp  -o %OBJ%\hub_okx.o  %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\common\hub_util.cpp -o %OBJ%\hub_util.o %INC% || goto :FAIL
if exist %BIN%\libhub.a del %BIN%\libhub.a
ar rcs %BIN%\libhub.a %OBJ%\hub_db.o %OBJ%\hub_okx.o %OBJ%\hub_util.o || goto :FAIL

echo     --- 编译 sigcore.dll (信号算法库, 供 PHP FFI 调用) ---
"%GXX%" %CFLAGS% -shared -c %SRC%\sigcore.cpp -o %OBJ%\sigcore.o %INC% || goto :FAIL
"%GXX%" -shared -static -o %BIN%\sigcore.dll %OBJ%\sigcore.o || goto :FAIL

echo     --- 编译 apihub.exe (API 服务:8090, sigcore 同源编入) ---
"%GXX%" %CFLAGS% -mwindows -c %SRC%\api\api_eps.cpp     -o %OBJ%\api_eps.o     %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\api\api_pages.cpp   -o %OBJ%\api_pages.o   %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\api\apihub_main.cpp -o %OBJ%\apihub_main.o %INC% || goto :FAIL
"%GXX%" -o %BIN%\apihub.exe %OBJ%\api_eps.o %OBJ%\api_pages.o %OBJ%\apihub_main.o %OBJ%\sigcore.o %BIN%\libhub.a %LIBS% || goto :FAIL

echo     --- 编译 tphub.exe (止盈引擎, sigcore 同源编入) ---
"%GXX%" %CFLAGS% -mwindows -c %SRC%\api\tphub_main.cpp -o %OBJ%\tphub_main.o %INC% || goto :FAIL
"%GXX%" -o %BIN%\tphub.exe %OBJ%\tphub_main.o %OBJ%\sigcore.o %BIN%\libhub.a %LIBS% || goto :FAIL

echo     --- 编译 datahub.exe (数据中枢, sigcore 同源编入) ---
"%GXX%" %CFLAGS% -mwindows -c %SRC%\hub\datahub_fetch.cpp -o %OBJ%\datahub_fetch.o %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\hub\datahub_main.cpp  -o %OBJ%\datahub_main.o  %INC% || goto :FAIL
"%GXX%" -o %BIN%\datahub.exe %OBJ%\datahub_fetch.o %OBJ%\datahub_main.o %OBJ%\sigcore.o %BIN%\libhub.a %LIBS% || goto :FAIL

echo     --- 编译 guard.exe (守护服务, 需 taskschd) ---
"%GXX%" %CFLAGS% -mwindows -c %SRC%\hub\guard_core.cpp -o %OBJ%\guard_core.o %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\hub\guard_main.cpp -o %OBJ%\guard_main.o %INC% || goto :FAIL
"%GXX%" -o %BIN%\guard.exe %OBJ%\guard_core.o %OBJ%\guard_main.o %BIN%\libhub.a %LIBS% -lole32 -loleaut32 -ltaskschd || goto :FAIL

echo     --- 编译 tradehub.exe (交易引擎, sigcore 同源编入) ---
"%GXX%" %CFLAGS% -mwindows -c %SRC%\trade\trade_backfill.cpp -o %OBJ%\trade_backfill.o %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\trade\trade_data.cpp     -o %OBJ%\trade_data.o     %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\trade\trade_engine.cpp   -o %OBJ%\trade_engine.o   %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\trade\trade_okx.cpp      -o %OBJ%\trade_okx.o      %INC% || goto :FAIL
"%GXX%" %CFLAGS% -mwindows -c %SRC%\trade\tradehub_main.cpp  -o %OBJ%\tradehub_main.o  %INC% || goto :FAIL
"%GXX%" -o %BIN%\tradehub.exe %OBJ%\trade_backfill.o %OBJ%\trade_data.o %OBJ%\trade_engine.o %OBJ%\trade_okx.o %OBJ%\tradehub_main.o %OBJ%\sigcore.o %BIN%\libhub.a %LIBS% || goto :FAIL

echo     --- 编译 cmd_pitbt.exe (黄金坑回测工具, sigcore 同源编入) ---
"%GXX%" %CFLAGS% -mwindows -c %SRC%\cmd_pitbt.cpp -o %OBJ%\cmd_pitbt.o %INC% || goto :FAIL
"%GXX%" -o %BIN%\cmd_pitbt.exe %OBJ%\cmd_pitbt.o %OBJ%\sigcore.o %BIN%\libhub.a %LIBS% || goto :FAIL

echo.
echo ============================================================
echo   全部完成! 二进制输出在 %BIN%
echo   后续步骤:
echo     1. 向数据库 okx_cred 表 INSERT 你的 OKX API 凭证
echo     2. 启动 MySQL / Apache / apihub.exe / tradehub.exe / tphub.exe / datahub.exe
echo     3. guard.exe install 注册为 Windows 服务 (开机自启+守护)
echo ============================================================
pause
exit /b 0

:FAIL
echo.
echo     [编译失败] 请检查上方错误信息
pause
exit /b 1
