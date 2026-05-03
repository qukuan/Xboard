#!/bin/bash

# --- 1. 读取 .env 配置 ---
if [ -f .env ]; then
    # 使用 sed 清除可能存在的空格或双引号
    DB_HOST=$(grep DB_HOST .env | cut -d '=' -f2 | sed 's/[" ]//g')
    DB_PORT=$(grep DB_PORT .env | cut -d '=' -f2 | sed 's/[" ]//g')
    DB_NAME=$(grep DB_DATABASE .env | cut -d '=' -f2 | sed 's/[" ]//g')
    DB_USER=$(grep DB_USERNAME .env | cut -d '=' -f2 | sed 's/[" ]//g')
    DB_PASS=$(grep DB_PASSWORD .env | cut -d '=' -f2 | sed 's/[" ]//g')
    echo "✅ 已从 .env 加载数据库: $DB_NAME"
else
    echo "❌ 错误: 未能在当前目录找到 .env 文件"
    exit 1
fi

# --- 2. 定义 SQL 逻辑 ---
SQL_COMMANDS="
ALTER TABLE v2_stat 
    ALTER COLUMN order_total TYPE numeric(12,2) USING order_total::numeric,
    ALTER COLUMN commission_total TYPE numeric(12,2) USING commission_total::numeric,
    ALTER COLUMN paid_total TYPE numeric(12,2) USING paid_total::numeric;

ALTER TABLE v2_commission_log 
    ALTER COLUMN order_amount TYPE numeric(12,2) USING order_amount::numeric,
    ALTER COLUMN get_amount TYPE numeric(12,2) USING get_amount::numeric;

ALTER TABLE v2_order 
    ALTER COLUMN total_amount TYPE numeric(12,2) USING total_amount::numeric,
    ALTER COLUMN handling_amount TYPE numeric(12,2) USING handling_amount::numeric,
    ALTER COLUMN discount_amount TYPE numeric(12,2) USING discount_amount::numeric,
    ALTER COLUMN surplus_amount TYPE numeric(12,2) USING surplus_amount::numeric,
    ALTER COLUMN refund_amount TYPE numeric(12,2) USING refund_amount::numeric,
    ALTER COLUMN balance_amount TYPE numeric(12,2) USING balance_amount::numeric,
    ALTER COLUMN commission_balance TYPE numeric(12,2) USING commission_balance::numeric,
    ALTER COLUMN actual_commission_balance TYPE numeric(12,2) USING actual_commission_balance::numeric;
"

# --- 3. 自动识别环境并执行 ---
echo "正在检测运行环境..."

# 方案 A: 检测是否为 Docker 环境 (根据你的 DB_HOST 判断或搜索容器)
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -q "$DB_HOST"; then
    echo "🚀 检测到 Docker 容器: $DB_HOST，正在注入 SQL..."
    echo "$SQL_COMMANDS" | docker exec -i "$DB_HOST" psql -U "$DB_USER" -d "$DB_NAME"
    EXIT_CODE=$?

# 方案 B: 尝试本地 psql 客户端
elif command -v psql >/dev/null 2>&1; then
    echo "🏠 检测到本地 psql 客户端，正在连接 $DB_HOST..."
    export PGPASSWORD="$DB_PASS"
    echo "$SQL_COMMANDS" | psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME"
    EXIT_CODE=$?

else
    echo "❌ 错误: 环境中既没有找到 Docker 容器 '$DB_HOST'，也没有安装本地 'psql' 客户端。"
    exit 1
fi

# --- 4. 结果反馈 ---
if [ $EXIT_CODE -eq 0 ]; then
    echo "------------------------------------------"
    echo "✅ 执行成功！涉及表：v2_stat, v2_commission_log, v2_order"
    echo "金额字段已全部转换为 numeric(12,2)"
else
    echo "❌ 执行失败，请检查数据库权限或容器状态。"
fi