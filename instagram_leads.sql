-- ============================================================================
-- Instagram Leads + Notificações + Configurações Meta
-- CRM RD Pets — Integração Instagram/Facebook
-- ============================================================================

-- ── Tabela: instagram_leads ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS instagram_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fonte ENUM('lead_ad','direct_message') NOT NULL,
  form_id VARCHAR(100) DEFAULT NULL,
  ad_id VARCHAR(100) DEFAULT NULL,
  ad_name VARCHAR(255) DEFAULT NULL,
  page_id VARCHAR(100) DEFAULT NULL,
  nome VARCHAR(200) DEFAULT NULL,
  email VARCHAR(200) DEFAULT NULL,
  telefone VARCHAR(50) DEFAULT NULL,
  cidade VARCHAR(150) DEFAULT NULL,
  estado VARCHAR(5) DEFAULT NULL,
  empresa VARCHAR(200) DEFAULT NULL,
  mensagem TEXT DEFAULT NULL,
  ig_user_id VARCHAR(100) DEFAULT NULL,
  ig_username VARCHAR(100) DEFAULT NULL,
  dados_extra JSON DEFAULT NULL,
  status ENUM('novo','contatado','convertido','descartado') DEFAULT 'novo',
  cliente_id INT DEFAULT NULL,
  convertido_em TIMESTAMP NULL,
  convertido_por VARCHAR(120),
  contatado_em TIMESTAMP NULL,
  contatado_por VARCHAR(120),
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  meta_leadgen_id VARCHAR(100) DEFAULT NULL,
  UNIQUE KEY uniq_meta_leadgen (meta_leadgen_id),
  INDEX idx_status (status),
  INDEX idx_fonte (fonte),
  INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tabela: notificacoes ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notificacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(50) NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  mensagem TEXT DEFAULT NULL,
  link VARCHAR(500) DEFAULT NULL,
  ref_id INT DEFAULT NULL,
  lida TINYINT(1) DEFAULT 0,
  usuario_id INT DEFAULT NULL,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lida (lida),
  INDEX idx_usuario (usuario_id),
  INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Tabela: configuracoes_meta ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS configuracoes_meta (
  chave VARCHAR(100) PRIMARY KEY,
  valor TEXT,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores padrão
INSERT IGNORE INTO configuracoes_meta (chave, valor) VALUES
  ('meta_app_id', ''),
  ('meta_app_secret', ''),
  ('meta_verify_token', ''),
  ('meta_page_token', ''),
  ('meta_page_id', '');
