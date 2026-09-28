#!/bin/sh
# ==================================================================
# build.sh — finally-main C++ 组件化构建脚本 (MinGW 13.1.0, Windows/Git-Bash)
#   用法:  sh build.sh            # 增量构建全部组件
#          sh build.sh apihub     # 只构建 apihub.exe
#   铁律: 每个 .cpp 单独编译成 .o, 零改动组件不重编; -Wall -Wextra 必须零告警
# ==================================================================
set -e
cd "$(dirname "$0")"

GXX="E:/Qt/Tools/Tools/mingw1310_64/bin/g++.exe"
AR="E:/Qt/Tools/Tools/mingw1310_64/bin/ar.exe"
INC="-Ivendor/mysql-connector-c-6.1.11-winx64/include"
LIB="-Lvendor/mysql-connector-c-6.1.11-winx64/lib"
FLAGS="-std=gnu++17 -O2 -mwindows -static -Wall -Wextra"
LDFLAGS="-static -Wl,-s $LIB -l:libmysql.dll -lwinhttp -lws2_32 -lbcrypt"

mkdir -p bin/obj

cc() {  # cc <源文件> <obj名>
  echo "[cc] $1"
  $GXX $FLAGS -c "$1" -o "bin/obj/$2" $INC
}

TARGET="${1:-all}"

if [ "$TARGET" = "all" ] || [ "$TARGET" = "common" ]; then
  cc common/hub_db.cpp    hub_db.o
  cc common/hub_okx.cpp   hub_okx.o
  cc common/hub_util.cpp  hub_util.o
  echo "[ar] libhub.a"
  $AR rcs bin/obj/libhub.a bin/obj/hub_db.o bin/obj/hub_okx.o bin/obj/hub_util.o
  cc sigcore.cpp sigcore.o
fi

if [ "$TARGET" = "all" ] || [ "$TARGET" = "apihub" ]; then
  cc api/api_eps.cpp     api_eps.o
  cc api/api_pages.cpp   api_pages.o
  cc api/apihub_main.cpp apihub_main.o
  echo "[link] bin/${OUT:-apihub.exe}"
  $GXX $FLAGS -o "bin/${OUT:-apihub.exe}" bin/obj/apihub_main.o bin/obj/api_eps.o \
      bin/obj/api_pages.o bin/obj/sigcore.o -Lbin/obj -lhub $LDFLAGS
fi

if [ "$TARGET" = "all" ] || [ "$TARGET" = "tphub" ]; then
  cc api/tphub_main.cpp tphub_main.o
  echo "[link] bin/${OUT:-tphub.exe}"
  $GXX $FLAGS -o "bin/${OUT:-tphub.exe}" bin/obj/tphub_main.o bin/obj/sigcore.o -Lbin/obj -lhub $LDFLAGS
fi

if [ "$TARGET" = "all" ] || [ "$TARGET" = "datahub" ]; then
  cc hub/datahub_fetch.cpp datahub_fetch.o
  cc hub/datahub_main.cpp  datahub_main.o
  echo "[link] bin/${OUT:-datahub.exe}"
  $GXX $FLAGS -o "bin/${OUT:-datahub.exe}" bin/obj/datahub_main.o bin/obj/datahub_fetch.o -Lbin/obj -lhub $LDFLAGS
fi

if [ "$TARGET" = "all" ] || [ "$TARGET" = "tradehub" ]; then
  cc trade/trade_okx.cpp      trade_okx.o
  cc trade/trade_data.cpp     trade_data.o
  cc trade/trade_engine.cpp   trade_engine.o
  cc trade/trade_backfill.cpp trade_backfill.o
  cc trade/tradehub_main.cpp  tradehub_main.o
  echo "[link] bin/${OUT:-tradehub.exe}"
  $GXX $FLAGS -o "bin/${OUT:-tradehub.exe}" bin/obj/tradehub_main.o bin/obj/trade_okx.o \
      bin/obj/trade_data.o bin/obj/trade_engine.o bin/obj/trade_backfill.o \
      bin/obj/sigcore.o -Lbin/obj -lhub $LDFLAGS
fi

if [ "$TARGET" = "all" ] || [ "$TARGET" = "bttimer" ]; then
  cc bt/bt_timer.cpp bt_timer.o
  echo "[link] bin/${OUT:-bt_timer.exe}"
  $GXX $FLAGS -o "bin/${OUT:-bt_timer.exe}" bin/obj/bt_timer.o -Lbin/obj -lhub $LDFLAGS
fi

if [ "$TARGET" = "all" ] || [ "$TARGET" = "dll" ]; then
  echo "[link] bin/${OUT:-sigcore.dll}"
  $GXX -std=gnu++17 -O2 -shared -static -Wall -Wextra -o "bin/${OUT:-sigcore.dll}" sigcore.cpp
fi

if [ "$TARGET" = "all" ] || [ "$TARGET" = "guard" ]; then
  cc hub/guard_core.cpp guard_core.o
  cc hub/guard_main.cpp guard_main.o
  echo "[link] bin/guard.exe"
  $GXX $FLAGS -o "bin/${OUT:-guard.exe}" bin/obj/guard_main.o bin/obj/guard_core.o -Lbin/obj -lhub \
      -lole32 -loleaut32 -ltaskschd $LDFLAGS
fi

echo "=== 产物 ==="
ls -la bin/*.exe bin/*.dll 2>/dev/null | awk '{printf "%-28s %10s\n", $9, $5}'
