#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────
# check_pre_deploy.sh — Roda os 6 smokes encadeados ANTES de deploy.
#
# Falha (exit 1) se qualquer smoke falhar.
#
# Uso:
#   ./scripts/check_pre_deploy.sh
#   ./scripts/check_pre_deploy.sh --verbose    # mostra output completo dos smokes
#
# Cenários onde rodar:
#   - Antes de qualquer deploy/upload pra produção
#   - Hook pre-push em git config core.hooksPath
#   - CI (GitHub Actions, GitLab CI, etc)
# ──────────────────────────────────────────────────────────────────────────

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP_BIN:-php}"
VERBOSE=0
FAIL=0

for arg in "$@"; do
    case "$arg" in
        --verbose|-v) VERBOSE=1 ;;
    esac
done

run_smoke() {
    local label="$1"
    local script="$2"
    printf "[%s] %-22s " "$label" "$script"
    if [ "$VERBOSE" -eq 1 ]; then
        echo
        if "$PHP" "$ROOT/scripts/$script.php"; then
            echo "  [OK] $script"
        else
            echo "  [FAIL] $script"
            FAIL=$((FAIL + 1))
        fi
    else
        if "$PHP" "$ROOT/scripts/$script.php" >/dev/null 2>&1; then
            echo "[OK]"
        else
            echo "[FAIL]"
            FAIL=$((FAIL + 1))
        fi
    fi
}

echo
echo "============================================================"
echo " PRE-DEPLOY CHECK · 10 smokes em sequência"
echo "============================================================"
echo

run_smoke "1/10 geral"          "_smoke_test"
run_smoke "2/10 caminho c"      "_smoke_caminho_c"
run_smoke "3/10 resiliencia 1"  "_smoke_resiliencia"
run_smoke "4/10 resiliencia 2"  "_smoke_resiliencia_2"
run_smoke "5/10 performance"    "_smoke_performance"
run_smoke "6/10 clicks"         "_smoke_clicks"
run_smoke "7/10 preditor"       "_smoke_preditor"
run_smoke "8/10 cluster killer" "_smoke_cluster_killer"
run_smoke "9/10 p0 revisao"     "_smoke_p0_revisao"
run_smoke "10/10 db mysql"      "_smoke_db_mysql"

echo
echo "============================================================"
if [ "$FAIL" -eq 0 ]; then
    echo " TODOS OS 10 SMOKES PASSARAM · pronto pra deploy"
    echo "============================================================"
    exit 0
else
    echo " $FAIL DE 10 SMOKES FALHARAM · NÃO FAZER DEPLOY"
    echo " Rode com --verbose pra ver o erro completo"
    echo "============================================================"
    exit 1
fi
