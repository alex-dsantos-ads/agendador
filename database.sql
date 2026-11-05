-- =============================================================================
-- BANCO DE DADOS: clinica_agenda
-- Sistema de Gestão de Agenda para Estabelecimento Clínico
-- Modalidades: Pilates Studio, Hidroginástica e Fisioterapia
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `clinica_agenda` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `clinica_agenda`;

-- 1. Tabela de Configurações Administrativas
CREATE TABLE IF NOT EXISTS `configuracoes` (
  `chave` VARCHAR(50) PRIMARY KEY,
  `valor` VARCHAR(255) NOT NULL,
  `atualizado_em` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `configuracoes` (`chave`, `valor`) VALUES
('pin_acesso_admin', '1234'),
('nome_estabelecimento', 'Clínica Corpore - Pilates, Hidro & Fisioterapia'),
('limite_padrao_pilates', '4'),
('limite_padrao_hidro', '12'),
('limite_padrao_fisio', '1')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- 2. Tabela de Modalidades
CREATE TABLE IF NOT EXISTS `modalidades` (
  `id` VARCHAR(30) PRIMARY KEY,
  `nome` VARCHAR(100) NOT NULL,
  `descricao` TEXT,
  `duracao_minutos` INT NOT NULL DEFAULT 50,
  `capacidade_padrao` INT NOT NULL DEFAULT 1,
  `local_padrao` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `modalidades` (`id`, `nome`, `descricao`, `duracao_minutos`, `capacidade_padrao`, `local_padrao`) VALUES
('pilates', 'Pilates Studio', 'Studio aparelhos (Reformer, Cadillac, Chair) e solo', 50, 4, 'Studio de Pilates - Sala 1'),
('hidroginastica', 'Hidroginástica', 'Aulas aquáticas na piscina aquecida', 45, 12, 'Piscina Térmica'),
('fisioterapia', 'Fisioterapia', 'Atendimento clínico individualizado 1:1', 50, 1, 'Consultório de Fisioterapia')
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`);

-- 3. Tabela de Profissionais / Instrutores
CREATE TABLE IF NOT EXISTS `profissionais` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(100) NOT NULL,
  `registro_profissional` VARCHAR(50) NOT NULL,
  `especialidade` VARCHAR(150),
  `modalidades` VARCHAR(255) NOT NULL,
  `telefone` VARCHAR(20),
  `email` VARCHAR(100),
  `ativo` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `profissionais` (`id`, `nome`, `registro_profissional`, `especialidade`, `modalidades`, `telefone`) VALUES
(1, 'Dra. Camila Rocha', 'CREFITO-3 189420-F', 'Fisioterapia Ortopédica e Coluna', 'fisioterapia', '(11) 98765-4321'),
(2, 'Dr. Lucas Mendes', 'CREFITO-3 210455-F', 'Fisioterapia Desportiva e Pilates', 'fisioterapia,pilates', '(11) 97654-3210'),
(3, 'Profa. Beatriz Costa', 'CREF 094820-G/SP', 'Instrutora de Pilates Studio', 'pilates', '(11) 99123-4567'),
(4, 'Profa. Juliana Lima', 'CREF 112034-G/SP', 'Educadora Física e Hidroginástica', 'hidroginastica', '(11) 98234-5678')
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`);

-- 4. Tabela de Pacientes / Alunos
CREATE TABLE IF NOT EXISTS `pacientes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(100) NOT NULL,
  `telefone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(100),
  `data_nascimento` DATE,
  `modalidade_interesse` VARCHAR(50) DEFAULT 'pilates',
  `observacoes_medicas` TEXT,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pacientes e alunos serão cadastrados conforme o aplicativo for utilizado pelo painel ou tela de cadastro.
-- Nenhum paciente de teste pré-inserido nesta versão.


-- 5. Tabela da Grade Semanal Fixa (Gerenciada pelo Administrador)
CREATE TABLE IF NOT EXISTS `grade_horarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dia_semana` TINYINT NOT NULL, -- 0=Dom, 1=Seg, 2=Ter, 3=Qua, 4=Qui, 5=Sex, 6=Sáb
  `hora_inicio` TIME NOT NULL,
  `hora_fim` TIME NOT NULL,
  `modalidade_id` VARCHAR(30) NOT NULL,
  `profissional_id` INT NOT NULL,
  `capacidade_maxima` INT NOT NULL DEFAULT 1,
  `sala_local` VARCHAR(100) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (`modalidade_id`) REFERENCES `modalidades`(`id`),
  FOREIGN KEY (`profissional_id`) REFERENCES `profissionais`(`id`),
  INDEX idx_dia_hora (`dia_semana`, `hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserção da Grade Semanal Fixa
INSERT INTO `grade_horarios` (`dia_semana`, `hora_inicio`, `hora_fim`, `modalidade_id`, `profissional_id`, `capacidade_maxima`, `sala_local`, `ativo`) VALUES
-- SEGUNDA-FEIRA (1)
(1, '07:00:00', '07:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(1, '08:00:00', '08:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(1, '08:00:00', '08:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(1, '08:00:00', '08:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(1, '09:00:00', '09:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(1, '09:00:00', '09:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(1, '14:00:00', '14:50:00', 'pilates', 2, 4, 'Studio Pilates - Sala 1', 1),
(1, '15:00:00', '15:50:00', 'fisioterapia', 2, 1, 'Consultório 2', 1),
(1, '16:00:00', '16:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(1, '17:00:00', '17:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
-- TERÇA-FEIRA (2)
(2, '07:30:00', '08:20:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(2, '08:30:00', '09:20:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(2, '08:00:00', '08:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(2, '09:00:00', '09:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(2, '09:00:00', '09:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(2, '14:00:00', '14:50:00', 'fisioterapia', 2, 1, 'Consultório 2', 1),
(2, '15:00:00', '15:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(2, '17:00:00', '17:50:00', 'pilates', 2, 4, 'Studio Pilates - Sala 1', 1),
-- QUARTA-FEIRA (3)
(3, '07:00:00', '07:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(3, '08:00:00', '08:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(3, '08:00:00', '08:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(3, '08:00:00', '08:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(3, '09:00:00', '09:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(3, '14:00:00', '14:50:00', 'pilates', 2, 4, 'Studio Pilates - Sala 1', 1),
(3, '16:00:00', '16:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
-- QUINTA-FEIRA (4)
(4, '07:30:00', '08:20:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(4, '08:30:00', '09:20:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(4, '08:00:00', '08:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(4, '09:00:00', '09:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(4, '15:00:00', '15:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(4, '17:00:00', '17:50:00', 'pilates', 2, 4, 'Studio Pilates - Sala 1', 1),
-- SEXTA-FEIRA (5)
(5, '07:00:00', '07:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(5, '08:00:00', '08:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(5, '08:00:00', '08:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1),
(5, '08:00:00', '08:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(5, '09:00:00', '09:50:00', 'fisioterapia', 1, 1, 'Consultório 1', 1),
(5, '14:00:00', '14:50:00', 'pilates', 2, 4, 'Studio Pilates - Sala 1', 1),
-- SÁBADO (6)
(6, '08:00:00', '08:50:00', 'pilates', 3, 4, 'Studio Pilates - Sala 1', 1),
(6, '09:00:00', '09:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- 6. Tabela de Bloqueios Especiais de Agenda (Feriados / Manutenções)
CREATE TABLE IF NOT EXISTS `bloqueios_agenda` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `data` DATE NOT NULL,
  `hora_inicio` TIME NULL,
  `hora_fim` TIME NULL,
  `modalidade_id` VARCHAR(30) NULL,
  `motivo` VARCHAR(255) NOT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Tabela de Agendamentos
CREATE TABLE IF NOT EXISTS `agendamentos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `data` DATE NOT NULL,
  `hora_inicio` TIME NOT NULL,
  `hora_fim` TIME NOT NULL,
  `modalidade_id` VARCHAR(30) NOT NULL,
  `grade_horario_id` INT NULL,
  `profissional_id` INT NOT NULL,
  `paciente_id` INT NOT NULL,
  `status` ENUM('agendado', 'confirmado', 'realizado', 'falta', 'cancelado') NOT NULL DEFAULT 'confirmado',
  `tipo_atendimento` VARCHAR(150),
  `observacao` TEXT,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`modalidade_id`) REFERENCES `modalidades`(`id`),
  FOREIGN KEY (`profissional_id`) REFERENCES `profissionais`(`id`),
  FOREIGN KEY (`paciente_id`) REFERENCES `pacientes`(`id`),
  INDEX idx_data_hora (`data`, `hora_inicio`, `modalidade_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agendamentos serão registrados conforme o uso do aplicativo.
-- Nenhum agendamento de teste pré-inserido nesta versão.

-- 8. Tabela de Usuários e Gestão de Acessos
-- Apenas o Administrador inicial é criado.
-- Professores e Secretários são cadastrados pelo Administrador pelo painel de Gestão.
-- Clientes e Alunos se cadastram pela tela de cadastro do sistema.
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(100) NOT NULL,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `senha_hash` VARCHAR(255) NOT NULL,
  `papel` ENUM('admin', 'secretaria', 'professor', 'aluno') NOT NULL DEFAULT 'secretaria',
  `telefone` VARCHAR(25),
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `profissional_id` INT NULL,
  `paciente_id` INT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `ultimo_acesso` DATETIME NULL,
  FOREIGN KEY (`profissional_id`) REFERENCES `profissionais`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`paciente_id`) REFERENCES `pacientes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuário Inicial Único (Administrador do Sistema):
INSERT INTO `usuarios` (`nome`, `email`, `senha_hash`, `papel`, `telefone`, `ativo`, `profissional_id`, `paciente_id`) VALUES
('Administrador do Sistema', 'admin@clinica.com', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', 'admin', '(11) 98888-0001', 1, NULL, NULL)
ON DUPLICATE KEY UPDATE `email` = VALUES(`email`);

-- 9. Tabela de Observações Clínicas e Prontuário do Paciente
CREATE TABLE IF NOT EXISTS `observacoes_clinicas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `paciente_id` INT NOT NULL,
  `profissional_id` INT NULL,
  `profissional_nome` VARCHAR(100) NOT NULL,
  `profissional_registro` VARCHAR(50) NULL,
  `data` DATE NOT NULL,
  `hora` TIME NULL,
  `modalidade` VARCHAR(30) NOT NULL,
  `titulo` VARCHAR(150) NOT NULL,
  `texto` TEXT NOT NULL,
  `tipo` ENUM('evolucao', 'avaliacao', 'dor_intercorrencia', 'orientacao', 'geral') NOT NULL DEFAULT 'evolucao',
  `dor_eva` TINYINT NULL,
  `agendamento_id` INT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`paciente_id`) REFERENCES `pacientes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


