-- Migration 001: schema inicial pra clonais_saas (MariaDB/MySQL).
-- Compatível com SQLite pra testes (com mínimas adaptações automáticas no runner).
--
-- Convenções:
-- - utf8mb4_unicode_ci (caracteres BR sem perda)
-- - InnoDB (transactions, row-level locking, FKs)
-- - Índices nos lookups frequentes
-- - JSON column pra payload extensível (campos opcionais)

-- ────────────────────────────────────────────────────────────
-- Tabela de controle de migrations (idempotência do runner)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(20) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- TRENDS — fonte de verdade do pipeline
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trends (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site VARCHAR(50) NOT NULL,
    termo VARCHAR(500) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'novo',
    score_discover DECIMAL(6,2) NOT NULL DEFAULT 0,
    data_detectada DATETIME NOT NULL,
    ultimo_update DATETIME NOT NULL,
    publicado_em DATETIME NULL,
    post_id INT UNSIGNED NULL,
    url_post VARCHAR(500) NULL,
    titulo VARCHAR(500) NULL,
    cluster_key VARCHAR(50) NULL,
    origem VARCHAR(80) NULL,
    categoria VARCHAR(100) NULL,
    volume_busca INT UNSIGNED NULL,
    volume_label VARCHAR(50) NULL,
    growth_pct DECIMAL(7,2) NULL,
    intencao VARCHAR(50) NULL,
    angulo VARCHAR(255) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    noticias_qtd INT UNSIGNED NULL,
    pingo_link VARCHAR(500) NULL,
    -- Campos restantes (categoria_ids, relacionados, pain, cluster_detect completo,
    -- score_detalhado, predictor_*, lint_*, etc) ficam aqui sem migration nova:
    payload JSON NULL,
    PRIMARY KEY (id),
    -- Unique por (site, termo lowercase) — mesma lógica do upsert JSON
    UNIQUE KEY uk_site_termo (site, termo),
    KEY idx_site_status (site, status),
    KEY idx_status_score (status, score_discover DESC),
    KEY idx_data_detectada (data_detectada),
    KEY idx_publicado_em (publicado_em),
    KEY idx_post_id (post_id),
    KEY idx_cluster (site, cluster_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- POST_PERFORMANCE — snapshots GSC por (post × surface × dia)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS post_performance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ts DATE NOT NULL,
    post_id INT UNSIGNED NOT NULL,
    trend_id INT UNSIGNED NULL,
    site VARCHAR(50) NOT NULL,
    url VARCHAR(500) NOT NULL,
    published_at DATE NULL,
    day_offset INT NULL,
    surface ENUM('web','discover','googleNews') NOT NULL,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    impressions INT UNSIGNED NOT NULL DEFAULT 0,
    ctr DECIMAL(7,5) NOT NULL DEFAULT 0,
    position DECIMAL(6,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ts_post_surface (ts, post_id, surface),
    KEY idx_site_ts (site, ts),
    KEY idx_post_surface (post_id, surface),
    KEY idx_trend_id (trend_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- CLICK_LOG_SUMMARY — agregação diária pré-computada por (post × dia BRT)
-- (tabela secundária; raw fica em wp_X.wp_cc_click_events do WP)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS click_log_summary (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dia_brt DATE NOT NULL,
    site VARCHAR(50) NOT NULL,
    post_id INT UNSIGNED NOT NULL,
    slug VARCHAR(190) NULL,
    clicks_unicos INT UNSIGNED NOT NULL DEFAULT 0,    -- dedupe (ip × dia × post)
    clicks_brutos INT UNSIGNED NOT NULL DEFAULT 0,
    last_synced_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_dia_site_post (dia_brt, site, post_id),
    KEY idx_post (post_id),
    KEY idx_site_dia (site, dia_brt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- CLICK_SYNC_STATE — controle de incremental por site
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS click_sync_state (
    site VARCHAR(50) NOT NULL,
    last_synced_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_synced_at DATETIME NULL,
    PRIMARY KEY (site)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- Marca migration aplicada
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO schema_migrations (version) VALUES ('001');
