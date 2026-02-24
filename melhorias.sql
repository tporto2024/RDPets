-- ============================================================
-- melhorias.sql — Execute APÓS importar o crm.sql original
-- Adiciona melhorias sem quebrar dados existentes
-- ============================================================

-- Adiciona campo "observacoes" na tabela clientes
ALTER TABLE `clientes`
  ADD COLUMN IF NOT EXISTS `observacoes` TEXT DEFAULT NULL AFTER `cnpj`;

-- Adiciona campo "prioridade" nas tarefas
ALTER TABLE `tarefas`
  ADD COLUMN IF NOT EXISTS `prioridade` ENUM('baixa','media','alta') NOT NULL DEFAULT 'media' AFTER `status`;

-- Adiciona campo "notas" nas negociações
ALTER TABLE `negociacoes`
  ADD COLUMN IF NOT EXISTS `notas` TEXT DEFAULT NULL AFTER `indicacao`;

-- Adiciona campo "tipo_negocio" na tabela clientes
ALTER TABLE `clientes`
  ADD COLUMN IF NOT EXISTS `tipo_negocio` VARCHAR(100) DEFAULT NULL AFTER `empresa`;

-- Índice para buscas por nome de cliente (melhora performance)
ALTER TABLE `clientes`
  ADD INDEX IF NOT EXISTS `idx_nome` (`nome`);

-- Índice para buscas por e-mail de cliente
ALTER TABLE `clientes`
  ADD INDEX IF NOT EXISTS `idx_email` (`email`);

-- ============================================================
-- Pronto! O sistema já está compatível com o banco melhorado.
-- ============================================================
