-- ══════════════════════════════════════════════════════════════
-- log_atividades.sql — Tabela de log de atividades dos usuários
-- Execute: mysql -u crmuser -p crm < log_atividades.sql
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS usuarios_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id   INT,
    usuario_nome VARCHAR(200) NOT NULL,
    pagina       VARCHAR(100) NOT NULL,
    acao         VARCHAR(200) NOT NULL,
    detalhes     TEXT,
    ip           VARCHAR(45),
    criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_usuario_id (usuario_id),
    KEY idx_criado_em  (criado_em),
    KEY idx_pagina     (pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
