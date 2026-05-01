@echo off
REM check_pre_deploy.bat - Roda 9 smokes encadeados ANTES de deploy.
REM Falha (exit 1) se qualquer smoke falhar.

setlocal enabledelayedexpansion
set "PHP=C:\xampp\php\php.exe"
set "ROOT=%~dp0.."
set "FAIL_COUNT=0"

echo.
echo ============================================================
echo  PRE-DEPLOY CHECK - 23 smokes em sequencia
echo ============================================================
echo.

set "SMOKES=_smoke_test _smoke_caminho_c _smoke_resiliencia _smoke_resiliencia_2 _smoke_performance _smoke_clicks _smoke_preditor _smoke_cluster_killer _smoke_p0_revisao _smoke_db_mysql _smoke_pacote_8h _smoke_social_quality _smoke_failsafe _smoke_roi _smoke_e2e _smoke_hardening _smoke_roi_extra _smoke_serp_intel _smoke_title_ab _smoke_pacote_pre_deploy _smoke_pacote_ctr_serp _smoke_pacote_defesa _smoke_pacote_perf_edge"
set "IDX=0"
set "TOTAL=23"

for %%S in (%SMOKES%) do (
    set /a IDX+=1
    echo [!IDX!/%TOTAL%] %%S
    "%PHP%" "%ROOT%\scripts\%%S.php" >nul 2>&1
    if errorlevel 1 (
        echo   FAIL %%S
        set /a FAIL_COUNT+=1
    ) else (
        echo   OK
    )
)

echo.
echo ============================================================
if !FAIL_COUNT! EQU 0 (
    echo  TODOS OS 23 SMOKES PASSARAM - pronto pra deploy
    echo ============================================================
    exit /b 0
) else (
    echo  !FAIL_COUNT! SMOKES FALHARAM - NAO FAZER DEPLOY
    echo  Rode o smoke individualmente pra ver o erro detalhado
    echo ============================================================
    exit /b 1
)
