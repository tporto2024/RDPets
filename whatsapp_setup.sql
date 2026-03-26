-- WhatsApp Chrome Extension — CRM Tables
-- Execute: mysql -u crmuser -p'CrmRD@2026' crm < whatsapp_setup.sql

CREATE TABLE IF NOT EXISTS whatsapp_contatos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    telefone        VARCHAR(30) NOT NULL,
    nome            VARCHAR(200) NULL,
    push_name       VARCHAR(200) NULL,
    foto_url        VARCHAR(500) NULL,
    cliente_id      INT NULL,
    status          ENUM('novo','contatado','convertido','descartado') NOT NULL DEFAULT 'novo',
    ultima_mensagem TEXT NULL,
    ultima_mensagem_em TIMESTAMP NULL,
    total_mensagens INT NOT NULL DEFAULT 0,
    convertido_em   TIMESTAMP NULL,
    convertido_por  VARCHAR(120) NULL,
    contatado_em    TIMESTAMP NULL,
    contatado_por   VARCHAR(120) NULL,
    observacoes     TEXT NULL,
    criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_telefone (telefone),
    INDEX idx_status (status),
    INDEX idx_ultima (ultima_mensagem_em),
    INDEX idx_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_mensagens (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    contato_id      INT NOT NULL,
    direcao         ENUM('recebida','enviada') NOT NULL,
    texto           TEXT NULL,
    tipo            VARCHAR(30) NOT NULL DEFAULT 'text',
    timestamp_wa    DATETIME NOT NULL,
    hash_msg        VARCHAR(64) NOT NULL,
    criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hash (hash_msg),
    INDEX idx_contato (contato_id),
    INDEX idx_timestamp (timestamp_wa),
    CONSTRAINT fk_msg_contato FOREIGN KEY (contato_id) REFERENCES whatsapp_contatos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    telefone        VARCHAR(30) NOT NULL,
    texto           TEXT NOT NULL,
    status          ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em      TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
