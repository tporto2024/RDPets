#!/usr/bin/env bash
# ============================================================
# testa_app.sh — Testes automatizados do CRM RD Pets
# Uso local:  bash testa_app.sh
# Uso no EC2: ssh -i ~/.ssh/RD.pem ec2-user@18.190.119.64 'bash -s' < testa_app.sh
# ============================================================

BASE_URL="http://localhost:8080"
DB_USER="crmuser"
DB_PASS="CrmRD@2026"
DB_NAME="crm"
CRM_DIR="/var/www/html/crm"
PHP_BIN="/usr/bin/php"

PASS=0
FAIL=0
WARN=0

verde="\033[0;32m"
vermelho="\033[0;31m"
amarelo="\033[0;33m"
reset="\033[0m"
negrito="\033[1m"

ok()   { echo -e "  ${verde}✔${reset} $1"; ((PASS++)); }
fail() { echo -e "  ${vermelho}✘${reset} $1"; ((FAIL++)); }
warn() { echo -e "  ${amarelo}⚠${reset} $1"; ((WARN++)); }
titulo() { echo -e "\n${negrito}$1${reset}"; }

echo -e "${negrito}============================================${reset}"
echo -e "${negrito}  Testes CRM RD Pets — $(date '+%d/%m/%Y %H:%M:%S')${reset}"
echo -e "${negrito}============================================${reset}"

# ── 1. Arquivos obrigatórios ──────────────────────────────────────────────────
titulo "📁 Arquivos obrigatórios"
ARQUIVOS=(
  config.php auth.php _nav.php _footer.php
  index.php login.php logout.php
  negociacoes.php negociacao_detalhe.php
  tarefas.php clientes.php usuarios.php
  configuracoes.php api.php
  whatsapp.php email_resumo.php
)
for f in "${ARQUIVOS[@]}"; do
  if [ -f "$CRM_DIR/$f" ]; then
    ok "$f existe"
  else
    fail "$f NÃO encontrado"
  fi
done

# ── 2. Permissões ────────────────────────────────────────────────────────────
titulo "🔐 Permissões de arquivo"
for f in "${ARQUIVOS[@]}"; do
  FILE="$CRM_DIR/$f"
  [ -f "$FILE" ] || continue
  OWNER=$(stat -c '%U' "$FILE" 2>/dev/null)
  if [ "$OWNER" = "apache" ]; then
    ok "$f → dono: apache"
  else
    warn "$f → dono: $OWNER (esperado: apache)"
  fi
done

# ── 3. Sintaxe PHP ────────────────────────────────────────────────────────────
titulo "🐘 Sintaxe PHP"
PHP_ARQUIVOS=(config.php auth.php api.php whatsapp.php email_resumo.php
              negociacoes.php negociacao_detalhe.php tarefas.php
              clientes.php usuarios.php configuracoes.php)
for f in "${PHP_ARQUIVOS[@]}"; do
  FILE="$CRM_DIR/$f"
  [ -f "$FILE" ] || continue
  RESULT=$(sudo $PHP_BIN -l "$FILE" 2>&1)
  if echo "$RESULT" | grep -q "No syntax errors"; then
    ok "$f — sintaxe OK"
  else
    fail "$f — ERRO DE SINTAXE: $RESULT"
  fi
done

# ── 4. Banco de dados ─────────────────────────────────────────────────────────
titulo "🗄️  Banco de dados"

# Conexão
CONN=$(mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1;" 2>&1)
if echo "$CONN" | grep -q "^1"; then
  ok "Conexão com MySQL OK (usuário: $DB_USER)"
else
  fail "Falha na conexão MySQL: $CONN"
fi

# Tabelas
TABELAS=(usuarios clientes negociacoes negociacoes_log tarefas tarefas_log neg_etapas neg_tipos)
for t in "${TABELAS[@]}"; do
  EXISTS=$(mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -se "SHOW TABLES LIKE '$t';" 2>/dev/null)
  if [ "$EXISTS" = "$t" ]; then
    COUNT=$(mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -se "SELECT COUNT(*) FROM $t;" 2>/dev/null)
    ok "Tabela $t existe ($COUNT registros)"
  else
    fail "Tabela $t NÃO encontrada"
  fi
done

# Coluna telefone em usuarios
COL=$(mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -se \
  "SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='usuarios' AND COLUMN_NAME='telefone';" 2>/dev/null)
if [ "$COL" = "1" ]; then
  ok "Coluna usuarios.telefone existe"
else
  fail "Coluna usuarios.telefone NÃO encontrada"
fi

# ── 5. HTTP — Páginas ─────────────────────────────────────────────────────────
titulo "🌐 HTTP — Páginas"
PAGINAS=(
  "login.php|200"
  "index.php|302"
  "negociacoes.php|302"
  "tarefas.php|302"
  "clientes.php|302"
  "usuarios.php|302"
  "api.php|401"
)
for entry in "${PAGINAS[@]}"; do
  PAGE="${entry%%|*}"
  ESPERADO="${entry##*|}"
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/$PAGE" 2>/dev/null)
  if [ "$STATUS" = "$ESPERADO" ]; then
    ok "$PAGE → HTTP $STATUS"
  else
    fail "$PAGE → HTTP $STATUS (esperado: $ESPERADO)"
  fi
done

# ── 6. API ────────────────────────────────────────────────────────────────────
titulo "⚙️  API"
API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST "$BASE_URL/api.php" \
  -H "Content-Type: application/json" \
  -d '{"action":"acao_invalida"}' 2>/dev/null)
if [ "$API_STATUS" = "401" ]; then
  ok "api.php bloqueia requisição não autenticada (401)"
else
  fail "api.php retornou $API_STATUS (esperado: 401)"
fi

# ── 7. Cron ───────────────────────────────────────────────────────────────────
titulo "⏰ Cron"
CRON=$(crontab -l 2>/dev/null | grep "email_resumo.php")
if [ -n "$CRON" ]; then
  ok "Cron configurado: $CRON"
else
  warn "Cron do email_resumo.php não encontrado"
fi

CROND=$(systemctl is-active crond 2>/dev/null)
if [ "$CROND" = "active" ]; then
  ok "Serviço crond está ativo"
else
  fail "Serviço crond não está ativo ($CROND)"
fi

# ── 8. PHP e extensões ────────────────────────────────────────────────────────
titulo "🐘 PHP e extensões"
PHP_VER=$(sudo $PHP_BIN -r "echo PHP_VERSION;" 2>/dev/null)
ok "PHP versão: $PHP_VER"

for ext in pdo pdo_mysql curl openssl json; do
  if sudo $PHP_BIN -m 2>/dev/null | grep -qi "^$ext$"; then
    ok "Extensão $ext carregada"
  else
    fail "Extensão $ext NÃO encontrada"
  fi
done

# ── 9. Servidor Web ───────────────────────────────────────────────────────────
titulo "🌍 Servidor Web"
NGINX=$(systemctl is-active nginx 2>/dev/null)
APACHE=$(systemctl is-active httpd 2>/dev/null)
if [ "$NGINX" = "active" ]; then
  ok "Nginx está ativo"
elif [ "$APACHE" = "active" ]; then
  ok "Apache (httpd) está ativo"
else
  fail "Nenhum servidor web ativo (nginx=$NGINX, httpd=$APACHE)"
fi

PHPFPM=$(systemctl is-active php-fpm 2>/dev/null)
if [ "$PHPFPM" = "active" ]; then
  ok "PHP-FPM está ativo"
else
  warn "PHP-FPM não está ativo ($PHPFPM)"
fi

# ── Resultado Final ───────────────────────────────────────────────────────────
TOTAL=$((PASS + FAIL + WARN))
echo ""
echo -e "${negrito}============================================${reset}"
echo -e "${negrito}  Resultado: $TOTAL testes${reset}"
echo -e "  ${verde}✔ Passou:   $PASS${reset}"
echo -e "  ${vermelho}✘ Falhou:   $FAIL${reset}"
echo -e "  ${amarelo}⚠ Aviso:    $WARN${reset}"
echo -e "${negrito}============================================${reset}"

[ "$FAIL" -eq 0 ] && exit 0 || exit 1
