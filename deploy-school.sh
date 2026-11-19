#!/usr/bin/env bash
set -Eeuo pipefail

trap 'echo "❌ Erro no deploy na linha $LINENO (comando: $BASH_COMMAND)" >&2; exit 1' ERR

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

ENVIRONMENT=""
SKIP_BACKUP=false
SKIP_GIT=false

usage() {
    cat <<'EOF'
Uso: ./deploy-school.sh [ambiente] [opções]

Ambientes:
  prod | main        Deploy de Produção (branch main, docker-compose.yml)
  homolog | dev      Deploy de Homologação (branch dev, docker-compose.homolog.yml)
  (se omitido, detecta "homolog" pelo caminho do diretório; caso contrário usa "prod")

Opções:
  --env <ambiente>   Define explicitamente o ambiente (prod ou homolog)
  --skip-backup      Pula a etapa de backup MySQL antes das migrations
  --skip-git         Pula git fetch / checkout / pull
  -h, --help         Exibe esta ajuda
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        prod|production|main)
            ENVIRONMENT="prod"
            shift
            ;;
        homolog|dev|development|staging)
            ENVIRONMENT="homolog"
            shift
            ;;
        --env)
            [[ $# -lt 2 ]] && { echo "❌ Falta valor para --env." >&2; exit 1; }
            case "$2" in
                prod|production|main) ENVIRONMENT="prod" ;;
                homolog|dev|development|staging) ENVIRONMENT="homolog" ;;
                *) echo "❌ Ambiente inválido: $2 (use prod ou homolog)." >&2; exit 1 ;;
            esac
            shift 2
            ;;
        --skip-backup)
            SKIP_BACKUP=true
            shift
            ;;
        --skip-git)
            SKIP_GIT=true
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "❌ Parâmetro desconhecido: $1" >&2
            usage
            exit 1
            ;;
    esac
done

if [[ -z "$ENVIRONMENT" ]]; then
    if [[ "$PWD" == *"homolog"* ]]; then
        ENVIRONMENT="homolog"
    else
        ENVIRONMENT="prod"
    fi
fi

if [[ "$ENVIRONMENT" == "homolog" ]]; then
    TARGET_BRANCH="dev"
    COMPOSE_FILE="docker-compose.homolog.yml"
    ENV_FILE="./backend-laravel/.env.homolog"
    APP_LABEL="Gestão Escolar (Homologação)"
    APP_SERVICE="gestao-escolar-app-homolog"
    SCHEDULER_SERVICE="gestao-escolar-scheduler-homolog"
    WEB_SERVICE="gestao-escolar-web-homolog"
    ADMIN_URL="https://gedu.vilavelha.es.gov.br/dev/admin/"
    API_URL="https://gedu.vilavelha.es.gov.br/GE/dev/v1"
    SWAGGER_URL="https://gedu.vilavelha.es.gov.br/dev/admin/api/documentation"
else
    TARGET_BRANCH="main"
    COMPOSE_FILE="docker-compose.yml"
    ENV_FILE="./backend-laravel/.env.production"
    APP_LABEL="Gestão Escolar (Produção)"
    APP_SERVICE="gestao-escolar-app"
    SCHEDULER_SERVICE="gestao-escolar-scheduler"
    WEB_SERVICE="gestao-escolar-web"
    ADMIN_URL="https://gedu.vilavelha.es.gov.br/admin/"
    API_URL="https://gedu.vilavelha.es.gov.br/GE/v1"
    SWAGGER_URL="https://gedu.vilavelha.es.gov.br/admin/api/documentation"
fi

BACKUP_DIR="/var/backups/gestao-escolar"
TIMESTAMP="$(date +%F_%H-%M)"

read_env_var() {
    local key="$1"
    local file="$2"
    local line val
    line="$(grep -E "^[[:space:]]*${key}=" "$file" | tail -n 1 || true)"
    val="${line#*=}"
    val="${val//$'\r'/}"
    # Remove aspas externas simples ou duplas
    if [[ "$val" =~ ^\"(.*)\"$ ]]; then
        val="${BASH_REMATCH[1]}"
    elif [[ "$val" =~ ^\'(.*)\'$ ]]; then
        val="${BASH_REMATCH[1]}"
    fi
    printf '%s' "$val"
}

echo "============================================================"
echo "🚀 Iniciando deploy: $APP_LABEL"
echo "📁 Diretório: $SCRIPT_DIR"
echo "🐳 Compose: $COMPOSE_FILE | Branch: $TARGET_BRANCH"
echo "============================================================"

# ==============================
# 1. PRÉ-REQUISITOS
# ==============================
command -v docker >/dev/null 2>&1 || { echo "❌ Docker não encontrado no servidor." >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "❌ Plugin 'docker compose' não disponível." >&2; exit 1; }

[[ -f "$COMPOSE_FILE" ]] || { echo "❌ Arquivo $COMPOSE_FILE não encontrado." >&2; exit 1; }
[[ -f "$ENV_FILE" ]] || { echo "❌ Arquivo de ambiente $ENV_FILE não encontrado." >&2; exit 1; }

if ! docker network inspect gestao-net >/dev/null 2>&1; then
    echo "🌐 Criando rede externa gestao-net..."
    docker network create gestao-net
fi

# ==============================
# 2. BACKUP DO BANCO DE DADOS
# ==============================
if [[ "$SKIP_BACKUP" == false ]]; then
    DB_CONN="$(read_env_var DB_CONNECTION "$ENV_FILE")"
    DB_HOST="$(read_env_var DB_HOST "$ENV_FILE")"
    DB_PORT="$(read_env_var DB_PORT "$ENV_FILE")"
    DB_NAME="$(read_env_var DB_DATABASE "$ENV_FILE")"
    DB_USER="$(read_env_var DB_USERNAME "$ENV_FILE")"
    DB_PASS="$(read_env_var DB_PASSWORD "$ENV_FILE")"
    DB_PORT="${DB_PORT:-3306}"

    if [[ "${DB_CONN:-mysql}" == "mysql" || "${DB_CONN:-mysql}" == "mariadb" ]]; then
        : "${DB_HOST:?DB_HOST não definido em $ENV_FILE}"
        : "${DB_NAME:?DB_DATABASE não definido em $ENV_FILE}"
        : "${DB_USER:?DB_USERNAME não definido em $ENV_FILE}"
        : "${DB_PASS:?DB_PASSWORD não definido em $ENV_FILE}"

        mkdir -p "$BACKUP_DIR"
        BACKUP_FILE="$BACKUP_DIR/backup_${ENVIRONMENT}_${DB_NAME}_${TIMESTAMP}.sql.gz"
        echo "💾 Gerando backup do banco ($DB_NAME em ${DB_HOST}:${DB_PORT})..."

        docker run --rm --network host \
            -e MYSQL_PWD="$DB_PASS" \
            mysql:8 \
            mysqldump \
                -h "$DB_HOST" \
                -P "$DB_PORT" \
                -u "$DB_USER" \
                --single-transaction \
                --quick \
                --lock-tables=false \
                "$DB_NAME" \
            | gzip > "$BACKUP_FILE"

        if [[ ! -s "$BACKUP_FILE" ]]; then
            echo "❌ Backup falhou ou gerou arquivo vazio ($BACKUP_FILE). Abortando deploy!" >&2
            rm -f "$BACKUP_FILE"
            exit 1
        fi

        echo "✅ Backup concluído: $BACKUP_FILE"
        echo "🧹 Removendo backups com mais de 15 dias em $BACKUP_DIR..."
        find "$BACKUP_DIR" -type f -name "*.sql.gz" -mtime +15 -delete || true
    else
        echo "ℹ️ DB_CONNECTION=${DB_CONN:-não definido} — pulando mysqldump."
    fi
else
    echo "⚠️ Backup ignorado (--skip-backup)."
fi

# ==============================
# 3. ATUALIZAÇÃO GIT
# ==============================
if [[ "$SKIP_GIT" == false ]]; then
    if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "🔄 Atualizando código fonte na branch $TARGET_BRANCH..."
        git fetch origin "$TARGET_BRANCH"
        git checkout "$TARGET_BRANCH"
        git pull --ff-only origin "$TARGET_BRANCH"
    else
        echo "⚠️ Repositório Git não detectado ou git indisponível. Pulando atualização Git."
    fi
else
    echo "⚠️ Atualização Git ignorada (--skip-git)."
fi

# ==============================
# 4. BUILD E CONTAINERS DOCKER
# ==============================
echo "🐳 Construindo imagens e subindo containers ($COMPOSE_FILE)..."
docker compose -f "$COMPOSE_FILE" up -d --build --remove-orphans

# ==============================
# 5. BACKEND LARAVEL & SWAGGER
# ==============================
echo "📦 Instalando dependências Composer no container ($APP_SERVICE)..."
docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" \
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "🔐 Preparando diretórios e permissões de storage/cache..."
docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" sh -lc '
    mkdir -p storage/api-docs storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
'

echo "🗄️ Executando migrations..."
docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" php artisan migrate --force

echo "🔗 Garantindo link simbólico de storage..."
docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" php artisan storage:link || true

echo "📚 Gerando especificação Swagger (OpenAPI 3.0)..."
docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" php artisan l5-swagger:generate

echo "⚡ Limpando e recriando caches de otimização do Laravel..."
docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" php artisan optimize:clear
docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" php artisan optimize

echo "⏱️ Reiniciando scheduler ($SCHEDULER_SERVICE)..."
docker compose -f "$COMPOSE_FILE" restart "$SCHEDULER_SERVICE"

# ==============================
# 6. PROXY MASTER NGINX
# ==============================
if docker ps --format '{{.Names}}' | grep -qx 'gestao-proxy'; then
    echo "🔄 Validando e recarregando Nginx Master Proxy (gestao-proxy)..."
    if docker exec gestao-proxy nginx -t; then
        docker exec gestao-proxy nginx -s reload
    else
        echo "⚠️ Falha no nginx -t do gestao-proxy; reload não executado." >&2
    fi
else
    echo "ℹ️ Container gestao-proxy não está em execução neste host; reload ignorado."
fi

# ==============================
# 7. VERIFICAÇÃO DE SAÚDE (HEALTH)
# ==============================
echo "🩺 Validando endpoint /api/v1/health via $WEB_SERVICE..."
HEALTH_OK=false
for attempt in 1 2 3 4 5; do
    if docker compose -f "$COMPOSE_FILE" exec -T "$APP_SERVICE" \
        curl -fsS --max-time 10 "http://${WEB_SERVICE}/api/v1/health" >/dev/null 2>&1; then
        HEALTH_OK=true
        break
    fi
    sleep 2
done

if [[ "$HEALTH_OK" == true ]]; then
    echo "✅ Healthcheck OK!"
else
    echo "⚠️ Healthcheck interno falhou após 5 tentativas; verifique logs com:" >&2
    echo "   docker compose -f $COMPOSE_FILE logs --tail=50 $APP_SERVICE $WEB_SERVICE" >&2
fi

echo "============================================================"
echo "🎉 Deploy concluído: $APP_LABEL"
echo "   • Painel Admin: $ADMIN_URL"
echo "   • API Base:     $API_URL"
echo "   • Swagger UI:   $SWAGGER_URL"
echo "============================================================"
