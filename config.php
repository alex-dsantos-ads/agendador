<?php
/**
 * config.php - Conexão Segura com Banco de Dados MySQL via PDO
 * e Gerenciamento de Sessão de Acesso Exclusivo do Estabelecimento
 */

declare(strict_types=1);

// Configurações de Fuso Horário e Sessão
date_default_timezone_set('America/Sao_Paulo');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Credenciais do Banco MySQL (Altere conforme seu ambiente: XAMPP, Laragon, cPanel, etc.)
define('DB_HOST', 'localhost');
define('DB_NAME', 'clinica_agenda');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', 3306);

// PIN / Senha padrão de acesso exclusivo do estabelecimento
define('ADMIN_DEFAULT_PIN', '1234');

/**
 * Retorna instância singleton de conexão PDO com tratamento amigável de erro
 */
function getPDOConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // Tenta conectar primeiro diretamente à base de dados
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Se o erro for "banco de dados desconhecido", tenta criar o banco automaticamente
            if (strpos($e->getMessage(), 'Unknown database') !== false || $e->getCode() === 1049) {
                try {
                    $pdoRoot = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS, $options);
                    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                } catch (Exception $e2) {
                    exibirErroConexao($e->getMessage());
                }
            } else {
                exibirErroConexao($e->getMessage());
            }
        }

        // Garante que as tabelas e dados essenciais existam
        garantirTabelasEIniciais($pdo);
    }
    return $pdo;
}

/**
 * Exibe tela de orientação caso o MySQL esteja desligado ou credenciais erradas
 */
function exibirErroConexao(string $erroMsg): void {
    http_response_code(500);
    echo "<!DOCTYPE html>
    <html lang='pt-BR'>
    <head>
        <meta charset='UTF-8'>
        <title>Conexão com o Banco de Dados</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
            .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; max-width: 600px; width: 100%; padding: 32px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
            h2 { color: #f87171; margin-top: 0; font-size: 20px; }
            p { font-size: 14px; line-height: 1.6; color: #cbd5e1; }
            code { background: #0f172a; padding: 3px 6px; border-radius: 6px; color: #38bdf8; font-family: monospace; font-size: 13px; }
            .steps { background: #0f172a; border-left: 4px solid #6366f1; padding: 14px 18px; border-radius: 0 8px 8px 0; margin: 18px 0; }
            .steps ol { margin: 0; padding-left: 20px; font-size: 13px; color: #94a3b8; }
            .steps li { margin-bottom: 6px; }
            .btn { display: inline-block; background: #6366f1; color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; font-size: 13px; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class='card'>
            <h2>Erro ao Conectar ao MySQL</h2>
            <p>Não foi possível conectar ao banco de dados MySQL com as configurações atuais em <code>config.php</code>.</p>
            <div class='steps'>
                <p style='margin-top:0;font-weight:bold;color:#f8fafc;'>Como Resolver:</p>
                <ol>
                    <li>Certifique-se de que o serviço <strong>MySQL</strong> está iniciado no <strong>XAMPP</strong> ou <strong>Laragon</strong>.</li>
                    <li>Abra o <code>config.php</code> e verifique o usuário (padrão XAMPP: <code>root</code>) e senha (padrão: vazia <code>\"\"</code>).</li>
                    <li>Importe o arquivo <code>database.sql</code> no seu phpMyAdmin (<code>http://localhost/phpmyadmin</code>).</li>
                </ol>
            </div>
            <p><small style='color:#64748b;'>Detalhes técnicos: " . htmlspecialchars($erroMsg) . "</small></p>
            <a href='javascript:location.reload()' class='btn'>Tentar Novamente</a>
        </div>
    </body>
    </html>";
    exit;
}

/**
 * Garante que a estrutura das tabelas e os horários padrão estejam disponíveis
 */
function garantirTabelasEIniciais(PDO $pdo): void {
    try {
        // Cria tabela de modalidades se não existir
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `modalidades` (
              `id` VARCHAR(30) PRIMARY KEY,
              `nome` VARCHAR(100) NOT NULL,
              `descricao` TEXT,
              `duracao_minutos` INT NOT NULL DEFAULT 50,
              `capacidade_padrao` INT NOT NULL DEFAULT 1,
              `local_padrao` VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $modalidadesCount = (int)$pdo->query("SELECT COUNT(*) FROM `modalidades`")->fetchColumn();
        if ($modalidadesCount === 0) {
            $pdo->exec("
                INSERT INTO `modalidades` (`id`, `nome`, `descricao`, `duracao_minutos`, `capacidade_padrao`, `local_padrao`) VALUES
                ('pilates', 'Pilates Studio', 'Studio aparelhos (Reformer/Cadillac) e solo', 50, 4, 'Studio de Pilates - Sala 1'),
                ('hidroginastica', 'Hidroginástica', 'Aulas aquáticas na piscina aquecida', 45, 12, 'Piscina Térmica'),
                ('fisioterapia', 'Fisioterapia', 'Atendimento clínico individualizado 1:1', 50, 1, 'Consultório de Fisioterapia');
            ");
        }

        // Tabela de Profissionais
        $pdo->exec("
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
        ");

        $profissionaisCount = (int)$pdo->query("SELECT COUNT(*) FROM `profissionais`")->fetchColumn();
        if ($profissionaisCount === 0) {
            $pdo->exec("
                INSERT INTO `profissionais` (`id`, `nome`, `registro_profissional`, `especialidade`, `modalidades`, `telefone`) VALUES
                (1, 'Dra. Camila Rocha', 'CREFITO-3 189420-F', 'Fisioterapia Ortopédica e Coluna', 'fisioterapia', '(11) 98765-4321'),
                (2, 'Dr. Lucas Mendes', 'CREFITO-3 210455-F', 'Fisioterapia Desportiva e Pilates', 'fisioterapia,pilates', '(11) 97654-3210'),
                (3, 'Profa. Beatriz Costa', 'CREF 094820-G/SP', 'Instrutora de Pilates Studio', 'pilates', '(11) 99123-4567'),
                (4, 'Profa. Juliana Lima', 'CREF 112034-G/SP', 'Educadora Física e Hidroginástica', 'hidroginastica', '(11) 98234-5678');
            ");
        }

        // Tabela de Pacientes / Alunos
        $pdo->exec("
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
        ");

        // MIGRAÇÃO AUTOMÁTICA DE COLUNAS: Garante que bancos de dados existentes recebam as novas colunas
        try {
            $colunasPacientes = $pdo->query("SHOW COLUMNS FROM `pacientes`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('modalidade_interesse', $colunasPacientes, true)) {
                $pdo->exec("ALTER TABLE `pacientes` ADD COLUMN `modalidade_interesse` VARCHAR(50) DEFAULT 'pilates' AFTER `email`");
            }
            if (!in_array('data_nascimento', $colunasPacientes, true)) {
                $pdo->exec("ALTER TABLE `pacientes` ADD COLUMN `data_nascimento` DATE AFTER `email`");
            }
        } catch (Exception $eCol) {
            // Ignora se não for possível alterar ou já existir
        }

        // Pacientes / Alunos: nenhum paciente de teste pré-inserido nesta versão limpa.
        // Eles serão criados conforme o aplicativo for utilizado pelo painel ou tela de cadastro de clientes.

        // Tabela de Grade Semanal
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grade_horarios` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `dia_semana` TINYINT NOT NULL,
              `hora_inicio` TIME NOT NULL,
              `hora_fim` TIME NOT NULL,
              `modalidade_id` VARCHAR(30) NOT NULL,
              `profissional_id` INT NOT NULL,
              `capacidade_maxima` INT NOT NULL DEFAULT 1,
              `sala_local` VARCHAR(100) NOT NULL,
              `ativo` TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $gradeCount = (int)$pdo->query("SELECT COUNT(*) FROM `grade_horarios`")->fetchColumn();
        if ($gradeCount === 0) {
            // Insere grade semanal completa de Segunda (1) a Sábado (6)
            $pdo->exec("
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
                (6, '09:00:00', '09:45:00', 'hidroginastica', 4, 12, 'Piscina Aquecida', 1);
            ");
        }

        // Tabela de Agendamentos (Nenhum agendamento de teste pré-inserido nesta versão limpa)
        $pdo->exec("
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
              `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Tabela de Bloqueios Especiais de Agenda (Feriados / Manutenções)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `bloqueios_agenda` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `data` DATE NOT NULL,
              `hora_inicio` TIME NULL,
              `hora_fim` TIME NULL,
              `modalidade_id` VARCHAR(30) NULL,
              `motivo` VARCHAR(255) NOT NULL,
              `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Tabela de Usuários e Gestão de Acessos
        $pdo->exec("
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
              `ultimo_acesso` DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Insere unicamente o Administrador inicial do sistema (caso não exista nenhum usuário)
        $usuariosCount = (int)$pdo->query("SELECT COUNT(*) FROM `usuarios`")->fetchColumn();
        if ($usuariosCount === 0) {
            $senhaHashAdmin = '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm'; // hash bcrypt de 'admin'
            $stmtInitAdmin = $pdo->prepare("
                INSERT INTO `usuarios` (`nome`, `email`, `senha_hash`, `papel`, `telefone`, `ativo`, `profissional_id`, `paciente_id`) 
                VALUES ('Administrador do Sistema', 'admin@clinica.com', ?, 'admin', '(11) 98888-0001', 1, NULL, NULL)
            ");
            $stmtInitAdmin->execute([$senhaHashAdmin]);
        }

        // Tabela de Observações Clínicas e Prontuário do Paciente
        $pdo->exec("
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
              `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

    } catch (Exception $e) {
        // Ignora silenciosamente para não interromper a navegação caso o usuário use permissões estritas
    }
}

/**
 * Garante que somente o estabelecimento autenticado pode acessar a página
 */
function verificarAcessoAdmin(): void {
    if (empty($_SESSION['admin_autenticado']) || $_SESSION['admin_autenticado'] !== true) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'mensagem' => 'Acesso não autorizado ao estabelecimento.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: login.php');
        exit;
    }
}
