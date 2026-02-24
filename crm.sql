-- ============================================================
-- CRM — Schema completo
-- Versão: 2.0  |  Gerado em: 2026-02-20
-- MariaDB 10.4+
--
-- Ordem de criação (respeita FKs):
--   1. usuarios
--   2. clientes
--   3. neg_etapas
--   4. neg_tipos
--   5. negociacoes
--   6. negociacoes_log
--   7. tarefas
--   8. tarefas_log
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET FOREIGN_KEY_CHECKS=0 */;

-- ─── 1. USUÁRIOS ─────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id`          int(11)                      NOT NULL AUTO_INCREMENT,
  `nome`        varchar(120)                 NOT NULL,
  `email`       varchar(160)                 NOT NULL,
  `perfil`      enum('master','user')        NOT NULL DEFAULT 'user',
  `google_id`   varchar(120)                 DEFAULT NULL,
  `avatar_url`  varchar(500)                 DEFAULT NULL,
  `telefone`    varchar(30)                  DEFAULT NULL,
  `senha_hash`  varchar(255)                 DEFAULT NULL,
  `criado_em`   timestamp                    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_email`     (`email`),
  UNIQUE KEY `ux_google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `usuarios` (`id`, `nome`, `email`, `perfil`, `telefone`, `senha_hash`, `criado_em`) VALUES
(1, 'Thiago Porto', 'tporto.thiago@gmail.com', 'master', '77991526666', '$2y$10$bAsGqmwM.T2LoPd6atcqs.Ntls4kjaWq9xPlNVhK8iGi7h1WjyQsi', '2025-09-01 19:39:24'),
(2, 'Patrick',      'patrickpssp@gmail.com',   'master', '',            '$2y$10$VvBVrYACV.RuyHM3kER0aeV4QG5rsahMKNwVIEn34kTZiZzOw0GES', '2025-09-01 20:20:18');

ALTER TABLE `usuarios` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

-- ─── 2. CLIENTES ─────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `clientes`;
CREATE TABLE `clientes` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `nome`         varchar(100) NOT NULL,
  `telefone`     varchar(20)  DEFAULT NULL,
  `email`        varchar(100) DEFAULT NULL,
  `empresa`      varchar(100) DEFAULT NULL,
  `tipo_negocio` varchar(100) DEFAULT NULL,
  `origem`       enum('Inbound','Outbound') DEFAULT NULL,
  `cnpj`         varchar(18)  DEFAULT NULL,
  `observacoes`  text         DEFAULT NULL,
  `criado_em`    timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clientes_cnpj` (`cnpj`),
  KEY `idx_nome`  (`nome`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `clientes` (`id`, `nome`, `telefone`, `email`, `empresa`, `cnpj`, `criado_em`) VALUES
(1,  'Thiago',         '6630260785',      'tporto.thiago@gmail.com', 'Sismedic',    NULL,                   '2025-09-01 18:12:52'),
(2,  'Thiago',         '77991526666',     'tporto.thiago@gmail.com', 'sismedic2',   '22.009.729/0001-61',   '2025-09-01 18:18:35'),
(3,  'Patrick',        '77991291292',     'tporto.thiago@gmail.com', 'Charlenes',   NULL,                   '2025-09-01 18:22:07'),
(4,  'Marcelas',       '67373773',        'tootpt@gmail.com',        'BArraquinha', NULL,                   '2025-09-01 18:29:06'),
(5,  'Luiz',           '44443343',        'thiago@gmail.com',        'Joao de Deus','57.969.285/0001-90',   '2025-09-01 18:37:02'),
(6,  'Luana',          '7799123343',      'luana@Gmaiil.com',        'Luana Pets',  '79.739.313/0001-16',   '2025-09-01 19:52:39'),
(7,  'Paulo Lamonier', '7788234567',      'tporto.thiago@gmail.com', 'Luma PEt',   '75.520.661/0001-47',   '2025-09-01 19:53:06'),
(19, 'Monique',        '',                '',                        'Petzila',     '88.120.113/0001-71',   '2025-09-01 20:30:33'),
(20, 'João Nogueira',  '44444',           'thiagohumberto600@gmail.com','Neves PEt','47.905.662/0001-74',  '2025-09-02 16:52:34'),
(21, 'Testando lead',  '161515156165156', 'cslcs@gkmail.com',        'Teste',       '12.321.321/3123-21',   '2025-09-02 19:40:01');

ALTER TABLE `clientes` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

-- ─── 3. ETAPAS DO KANBAN (dinâmicas e editáveis) ─────────────────────────────

DROP TABLE IF EXISTS `neg_etapas`;
CREATE TABLE `neg_etapas` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `nome`         varchar(100) NOT NULL,
  `cor`          varchar(30)  NOT NULL DEFAULT 'cinza',
  `ordem`        int(11)      NOT NULL DEFAULT 0,
  `is_encerrada` tinyint(1)   NOT NULL DEFAULT 0  COMMENT 'Encerra a negociação ao entrar nesta etapa',
  `is_ganho`     tinyint(1)   NOT NULL DEFAULT 0  COMMENT 'Conta como negociação ganha (vendida)',
  `criado_em`    timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cores disponíveis: cinza | vermelho | azul | amarelo | laranja | verde | roxo | rosa | indigo | ciano
INSERT INTO `neg_etapas` (`nome`, `cor`, `ordem`, `is_encerrada`, `is_ganho`) VALUES
('Importado',   'cinza',    1, 0, 0),
('Sem Retorno', 'vermelho', 2, 0, 0),
('Em contato',  'azul',     3, 0, 0),
('Testando',    'amarelo',  4, 0, 0),
('Adiado',      'laranja',  5, 1, 0),
('Vendido',     'verde',    6, 1, 1);

ALTER TABLE `neg_etapas` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

-- ─── 4. TIPOS DE NEGOCIAÇÃO ───────────────────────────────────────────────────

DROP TABLE IF EXISTS `neg_tipos`;
CREATE TABLE `neg_tipos` (
  `id`        int(11)      NOT NULL AUTO_INCREMENT,
  `nome`      varchar(100) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `criado_em` timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (Nenhum tipo pré-cadastrado — adicione em Configurações > Tipos de Negociação)

ALTER TABLE `neg_tipos` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

-- ─── 5. NEGOCIAÇÕES ──────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `negociacoes`;
CREATE TABLE `negociacoes` (
  `id`                   int(11)      NOT NULL AUTO_INCREMENT,
  `cliente_id`           int(11)      DEFAULT NULL,
  `etapa`                varchar(100) NOT NULL DEFAULT 'Importado',
  `tipo_id`              int(11)      DEFAULT NULL,
  `qualificacao`         varchar(50)  DEFAULT NULL,
  `valor`                decimal(10,2)DEFAULT 0.00,
  `previsao_fechamento`  datetime     DEFAULT NULL,
  `indicacao`            varchar(255) DEFAULT NULL,
  `notas`                text         DEFAULT NULL,
  `responsavel_id`       int(11)      DEFAULT NULL,
  `criado_em`            timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cliente`     (`cliente_id`),
  KEY `idx_responsavel` (`responsavel_id`),
  KEY `idx_tipo`        (`tipo_id`),
  CONSTRAINT `fk_neg_cliente`   FOREIGN KEY (`cliente_id`)    REFERENCES `clientes`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_neg_tipo`      FOREIGN KEY (`tipo_id`)       REFERENCES `neg_tipos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_neg_responsavel` FOREIGN KEY (`responsavel_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `negociacoes` (`id`, `cliente_id`, `etapa`, `qualificacao`, `valor`, `responsavel_id`, `criado_em`) VALUES
(2,  2,  'Em contato',  'Sem interesse',    0.00,   2,    '2025-09-01 18:18:35'),
(4,  4,  'Adiado',      'Muito Interessado', 200.00, NULL, '2025-09-01 18:29:06'),
(5,  5,  'Testando',    'Sem interesse',    360.00,  2,   '2025-09-01 18:37:02'),
(6,  6,  'Em contato',  'Quente',           300.00,  2,   '2025-09-01 19:52:39'),
(7,  7,  'Em contato',  'Muito Interessado', 100.00, 2,   '2025-09-01 19:53:06'),
(8,  19, 'Sem Retorno', 'Morno',            500.00,  2,   '2025-09-01 20:30:33'),
(9,  20, 'Importado',   'Morno',            200.00,  1,   '2025-09-02 16:52:34');

ALTER TABLE `negociacoes` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

-- ─── 6. LOG DE ETAPAS DAS NEGOCIAÇÕES ────────────────────────────────────────

DROP TABLE IF EXISTS `negociacoes_log`;
CREATE TABLE `negociacoes_log` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `negociacao_id`  int(11)      NOT NULL,
  `de_etapa`       varchar(100) DEFAULT NULL,
  `para_etapa`     varchar(100) NOT NULL,
  `changed_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `changed_by`     varchar(120) DEFAULT 'sistema',
  `changed_ip`     varchar(45)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_negociacao` (`negociacao_id`),
  KEY `idx_changed_at` (`changed_at`),
  CONSTRAINT `fk_log_negociacao` FOREIGN KEY (`negociacao_id`) REFERENCES `negociacoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `negociacoes_log` (`id`, `negociacao_id`, `de_etapa`, `para_etapa`, `changed_at`, `changed_by`, `changed_ip`) VALUES
(2,  5, 'Importado',   'Sem Retorno', '2025-09-01 19:08:39', 'sistema',      '::1'),
(5,  4, 'Importado',   'Sem Retorno', '2025-09-01 19:08:47', 'sistema',      '::1'),
(7,  5, 'Sem Retorno', 'Em contato',  '2025-09-01 19:10:38', 'sistema',      '::1'),
(8,  5, 'Em contato',  'Sem Retorno', '2025-09-01 19:10:39', 'sistema',      '::1'),
(9,  5, 'Sem Retorno', 'Importado',   '2025-09-01 19:10:40', 'sistema',      '::1'),
(15, 4, 'Testando',    'Adiado',      '2025-09-01 19:58:28', 'Thiago Porto', '::1'),
(16, 6, 'Importado',   'Sem Retorno', '2025-09-01 19:58:29', 'Thiago Porto', '::1'),
(17, 2, 'Importado',   'Sem Retorno', '2025-09-01 19:58:32', 'Thiago Porto', '::1'),
(18, 7, 'Importado',   'Sem Retorno', '2025-09-01 20:20:33', 'Patrick',      '::1'),
(19, 7, 'Sem Retorno', 'Importado',   '2025-09-01 20:20:35', 'Patrick',      '::1'),
(20, 7, 'Importado',   'Sem Retorno', '2025-09-01 20:23:03', 'Patrick',      '::1'),
(21, 5, 'Importado',   'Sem Retorno', '2025-09-01 20:23:06', 'Patrick',      '::1'),
(22, 5, 'Sem Retorno', 'Testando',    '2025-09-01 20:23:12', 'Patrick',      '::1'),
(23, 7, 'Sem Retorno', 'Em contato',  '2025-09-01 20:23:18', 'Patrick',      '::1'),
(25, 6, 'Sem Retorno', 'Importado',   '2025-09-01 20:23:24', 'Patrick',      '::1'),
(27, 2, 'Sem Retorno', 'Em contato',  '2025-09-01 20:23:28', 'Patrick',      '::1'),
(28, 6, 'Importado',   'Sem Retorno', '2025-09-01 20:23:31', 'Patrick',      '::1'),
(29, 6, 'Sem Retorno', 'Em contato',  '2025-09-01 20:23:37', 'Patrick',      '::1'),
(30, 9, 'Importado',   'Sem Retorno', '2025-09-02 16:52:38', 'Thiago Porto', '10.242.45.43'),
(31, 8, 'Importado',   'Sem Retorno', '2025-09-02 19:24:36', 'Patrick',      '::1'),
(32, 8, 'Sem Retorno', 'Importado',   '2025-09-02 19:24:38', 'Patrick',      '::1'),
(37, 4, 'Adiado',      'Vendido',     '2025-09-02 19:40:29', 'Patrick',      '10.242.166.247'),
(38, 4, 'Vendido',     'Adiado',      '2025-09-02 19:40:35', 'Patrick',      '10.242.166.247');

ALTER TABLE `negociacoes_log` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

-- ─── 7. TAREFAS ──────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `tarefas`;
CREATE TABLE `tarefas` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `negociacao_id`  int(11)      NOT NULL,
  `responsavel_id` int(11)      DEFAULT NULL,
  `tipo`           enum('Ligar','Email','Reunião','Tarefa','Almoço','Visita','WhatsApp') NOT NULL DEFAULT 'Tarefa',
  `assunto`        varchar(160) NOT NULL,
  `descricao`      text         DEFAULT NULL,
  `quando`         datetime     NOT NULL,
  `status`         enum('aberta','concluida') NOT NULL DEFAULT 'aberta',
  `prioridade`     enum('baixa','media','alta') NOT NULL DEFAULT 'media',
  `criado_em`      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_negociacao`  (`negociacao_id`),
  KEY `idx_responsavel` (`responsavel_id`),
  KEY `idx_quando`      (`quando`),
  KEY `idx_status`      (`status`),
  CONSTRAINT `fk_tarefa_negociacao`  FOREIGN KEY (`negociacao_id`)  REFERENCES `negociacoes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tarefa_responsavel` FOREIGN KEY (`responsavel_id`) REFERENCES `usuarios`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tarefas` (`id`, `negociacao_id`, `responsavel_id`, `tipo`, `assunto`, `descricao`, `quando`, `status`, `criado_em`) VALUES
(1, 6, 1, 'Email',   'll',           'ooo',      '2025-09-03 22:00:00', 'aberta', '2025-09-01 21:26:50'),
(2, 6, 1, 'Reunião', 'llfechamento', 'ooo',      '2025-09-04 22:00:00', 'aberta', '2025-09-01 21:27:15'),
(3, 7, 2, 'Ligar',   'ligar',        '...',      '2025-09-02 19:00:00', 'aberta', '2025-09-02 18:19:06'),
(4, 7, 2, 'Email',   'mandar email', 'detalhes', '2025-09-04 15:19:00', 'aberta', '2025-09-02 18:19:43');

ALTER TABLE `tarefas` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

-- ─── 8. LOG DE TAREFAS ───────────────────────────────────────────────────────

DROP TABLE IF EXISTS `tarefas_log`;
CREATE TABLE `tarefas_log` (
  `id`          int(11)     NOT NULL AUTO_INCREMENT,
  `tarefa_id`   int(11)     NOT NULL,
  `acao`        enum('criada','atualizada','concluida','reaberta','excluida') NOT NULL,
  `de_status`   enum('aberta','concluida') DEFAULT NULL,
  `para_status` enum('aberta','concluida') DEFAULT NULL,
  `changed_by`  varchar(120) DEFAULT NULL,
  `changed_ip`  varchar(45)  DEFAULT NULL,
  `payload`     text         DEFAULT NULL,
  `changed_at`  timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tarefa`     (`tarefa_id`),
  KEY `idx_changed_at` (`changed_at`),
  CONSTRAINT `fk_tarefas_log_tarefa` FOREIGN KEY (`tarefa_id`) REFERENCES `tarefas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tarefas_log` (`id`, `tarefa_id`, `acao`, `para_status`, `changed_by`, `changed_ip`, `payload`, `changed_at`) VALUES
(1, 1, 'criada', 'aberta', 'Thiago Porto', '::1', '{"assunto":"ll","tipo":"Email","quando":"2025-09-03 22:00:00"}',          '2025-09-01 21:26:50'),
(2, 2, 'criada', 'aberta', 'Thiago Porto', '::1', '{"assunto":"llfechamento","tipo":"Reunião","quando":"2025-09-04 22:00:00"}','2025-09-01 21:27:15'),
(3, 3, 'criada', 'aberta', 'Patrick',      '::1', '{"assunto":"ligar","tipo":"Ligar","quando":"2025-09-02 19:00:00"}',         '2025-09-02 18:19:06'),
(4, 4, 'criada', 'aberta', 'Patrick',      '::1', '{"assunto":"mandar email","tipo":"Email","quando":"2025-09-04 15:19:00"}',  '2025-09-02 18:19:43');

ALTER TABLE `tarefas_log` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

/*!40014 SET FOREIGN_KEY_CHECKS=1 */;

-- ============================================================
-- FIM DO SCHEMA
-- ============================================================
