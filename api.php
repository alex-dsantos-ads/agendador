<?php
/**
 * api.php - API REST JSON em PHP
 * Controle de Agendamentos, Grade de Horários e Vagas
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Garante que somente o estabelecimento autenticado pode acessar a API
verificarAcessoAdmin();

$pdo = getPDOConnection();
$acao = $_GET['acao'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($acao) {
        // ----------------------------------------------------
        // 1. OBTER AGENDA DE UM DIA COM VAGAS CALCULADAS
        // ----------------------------------------------------
        case 'obter_dia':
            $data = $_GET['data'] ?? date('Y-m-d');
            $diaSemana = (int)date('w', strtotime($data));

            // Slots cadastrados na grade semanal para este dia da semana
            $stmtGrade = $pdo->prepare("
                SELECT g.*, m.nome as modalidade_nome, p.nome as profissional_nome, p.especialidade
                FROM grade_horarios g
                JOIN modalidades m ON g.modalidade_id = m.id
                JOIN profissionais p ON g.profissional_id = p.id
                WHERE g.dia_semana = ? AND g.ativo = 1
                ORDER BY g.hora_inicio ASC
            ");
            $stmtGrade->execute([$diaSemana]);
            $grade = $stmtGrade->fetchAll();

            // Agendamentos já marcados para o dia
            $stmtAg = $pdo->prepare("
                SELECT a.*, p.nome as paciente_nome, p.telefone as paciente_telefone,
                       prof.nome as profissional_nome, m.nome as modalidade_nome
                FROM agendamentos a
                JOIN pacientes p ON a.paciente_id = p.id
                JOIN profissionais prof ON a.profissional_id = prof.id
                JOIN modalidades m ON a.modalidade_id = m.id
                WHERE a.data = ? AND a.status != 'cancelado'
                ORDER BY a.hora_inicio ASC
            ");
            $stmtAg->execute([$data]);
            $agendamentos = $stmtAg->fetchAll();

            // Bloqueios cadastrados para esta data
            $stmtBl = $pdo->prepare("SELECT * FROM bloqueios_agenda WHERE data = ?");
            $stmtBl->execute([$data]);
            $bloqueios = $stmtBl->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data' => $data,
                'dia_semana' => $diaSemana,
                'grade' => $grade,
                'agendamentos' => $agendamentos,
                'bloqueios' => $bloqueios
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 2. NOVO AGENDAMENTO COM VALIDAÇÃO DE CAPACIDADE
        // ----------------------------------------------------
        case 'criar_agendamento':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $data = trim($dados['data'] ?? '');
            if (strpos($data, '/') !== false) {
                $pts = explode('/', $data);
                if (count($pts) === 3 && strlen($pts[2]) === 4) {
                    $data = sprintf('%04d-%02d-%02d', (int)$pts[2], (int)$pts[1], (int)$pts[0]);
                }
            }
            $horaInicio = $dados['hora_inicio'] ?? '';
            $horaFim = $dados['hora_fim'] ?? '';
            $modalidadeId = $dados['modalidade_id'] ?? '';
            $profissionalId = (int)($dados['profissional_id'] ?? 0);
            $pacienteId = (int)($dados['paciente_id'] ?? 0);
            $tipoAtendimento = $dados['tipo_atendimento'] ?? '';
            $observacao = $dados['observacao'] ?? '';
            $gradeId = !empty($dados['grade_horario_id']) ? (int)$dados['grade_horario_id'] : null;

            if (!$data || !$horaInicio || !$modalidadeId || !$profissionalId || !$pacienteId) {
                throw new Exception('Campos obrigatórios incompletos.');
            }

            // 1. Validação de data: Não permitir data no passado (hoje ou maior)
            $hoje = date('Y-m-d');
            if (empty($data) || $data < $hoje || strtotime($data) < strtotime($hoje)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'mensagem' => 'A data do agendamento não pode ser anterior ao dia de hoje.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 2. Validação de duplicidade: Paciente já tem agendamento ativo nesta data e horário
            $stmtExistePac = $pdo->prepare("
                SELECT COUNT(*) FROM agendamentos 
                WHERE data = ? AND hora_inicio = ? AND paciente_id = ? AND status != 'cancelado'
            ");
            $stmtExistePac->execute([$data, $horaInicio, $pacienteId]);
            if ((int)$stmtExistePac->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'mensagem' => 'Já existe agendamento naquele horário.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Descobre a capacidade máxima (da grade ou da modalidade)
            $capacidadeMaxima = 1;
            if ($gradeId) {
                $stmtCap = $pdo->prepare("SELECT capacidade_maxima FROM grade_horarios WHERE id = ?");
                $stmtCap->execute([$gradeId]);
                $capGrade = $stmtCap->fetchColumn();
                if ($capGrade) $capacidadeMaxima = (int)$capGrade;
            } else {
                $stmtCap = $pdo->prepare("SELECT capacidade_padrao FROM modalidades WHERE id = ?");
                $stmtCap->execute([$modalidadeId]);
                $capMod = $stmtCap->fetchColumn();
                if ($capMod) $capacidadeMaxima = (int)$capMod;
            }

            // Conta agendamentos ativos no mesmo horário
            $stmtOcupados = $pdo->prepare("
                SELECT COUNT(*) FROM agendamentos 
                WHERE data = ? AND hora_inicio = ? AND modalidade_id = ? AND status != 'cancelado'
            ");
            $stmtOcupados->execute([$data, $horaInicio, $modalidadeId]);
            $ocupados = (int)$stmtOcupados->fetchColumn();

            if ($ocupados >= $capacidadeMaxima) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'mensagem' => 'Já existe agendamento naquele horário.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmtInsert = $pdo->prepare("
                INSERT INTO agendamentos (data, hora_inicio, hora_fim, modalidade_id, grade_horario_id, profissional_id, paciente_id, status, tipo_atendimento, observacao)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmado', ?, ?)
            ");
            $stmtInsert->execute([$data, $horaInicio, $horaFim, $modalidadeId, $gradeId, $profissionalId, $pacienteId, $tipoAtendimento, $observacao]);

            echo json_encode([
                'status' => 'success',
                'mensagem' => 'Agendamento cadastrado com sucesso!',
                'id' => $pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 3. ATUALIZAR STATUS (PRESENÇA, FALTA, CONFIRMADO, CANCELADO)
        // ----------------------------------------------------
        case 'atualizar_status':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($dados['id'] ?? 0);
            $novoStatus = $dados['status'] ?? '';

            $statusPermitidos = ['agendado', 'confirmado', 'realizado', 'falta', 'cancelado'];
            if (!in_array($novoStatus, $statusPermitidos, true)) {
                throw new Exception('Status inválido informado.');
            }

            $stmtUpdate = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
            $stmtUpdate->execute([$novoStatus, $id]);

            echo json_encode(['status' => 'success', 'mensagem' => 'Status atualizado com sucesso!']);
            break;

        // ----------------------------------------------------
        // 4. EXCLUIR AGENDAMENTO
        // ----------------------------------------------------
        case 'excluir_agendamento':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($dados['id'] ?? 0);

            $stmtDel = $pdo->prepare("DELETE FROM agendamentos WHERE id = ?");
            $stmtDel->execute([$id]);

            echo json_encode(['status' => 'success', 'mensagem' => 'Agendamento excluído!']);
            break;

        // ----------------------------------------------------
        // 5. CADASTRAR NOVO HORÁRIO NA GRADE SEMANAL (ADMIN)
        // ----------------------------------------------------
        case 'adicionar_grade':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;

            $diaSemana = (int)($dados['dia_semana'] ?? 1);
            $horaInicio = $dados['hora_inicio'] ?? '';
            $horaFim = $dados['hora_fim'] ?? '';
            $modalidadeId = $dados['modalidade_id'] ?? 'pilates';
            $profissionalId = (int)($dados['profissional_id'] ?? 1);
            $capacidadeMaxima = (int)($dados['capacidade_maxima'] ?? 4);
            $salaLocal = $dados['sala_local'] ?? 'Studio';

            $stmtInsGrade = $pdo->prepare("
                INSERT INTO grade_horarios (dia_semana, hora_inicio, hora_fim, modalidade_id, profissional_id, capacidade_maxima, sala_local, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtInsGrade->execute([$diaSemana, $horaInicio, $horaFim, $modalidadeId, $profissionalId, $capacidadeMaxima, $salaLocal]);

            echo json_encode([
                'status' => 'success',
                'mensagem' => 'Horário adicionado à grade semanal!',
                'id' => $pdo->lastInsertId()
            ]);
            break;

        // ----------------------------------------------------
        // 6. EXCLUIR HORÁRIO DA GRADE SEMANAL
        // ----------------------------------------------------
        case 'excluir_grade':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($dados['id'] ?? 0);

            $stmtDelG = $pdo->prepare("DELETE FROM grade_horarios WHERE id = ?");
            $stmtDelG->execute([$id]);

            echo json_encode(['status' => 'success', 'mensagem' => 'Horário removido da grade!']);
            break;

        // ----------------------------------------------------
        // 7. CADASTRAR NOVO PACIENTE
        // ----------------------------------------------------
        case 'criar_paciente':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;

            $nome = trim($dados['nome'] ?? '');
            $telefone = trim($dados['telefone'] ?? '');
            $email = trim($dados['email'] ?? '');
            $dataNascimento = !empty($dados['data_nascimento']) ? $dados['data_nascimento'] : null;
            $modalidadeInteresse = trim($dados['modalidade_interesse'] ?? 'pilates');
            $observacoes = trim($dados['observacoes_medicas'] ?? '');

            if (!$nome || !$telefone) {
                throw new Exception('Nome e Telefone são obrigatórios.');
            }

            try {
                $stmtP = $pdo->prepare("INSERT INTO pacientes (nome, telefone, email, data_nascimento, modalidade_interesse, observacoes_medicas) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtP->execute([$nome, $telefone, $email, $dataNascimento, $modalidadeInteresse, $observacoes]);
            } catch (PDOException $eP) {
                $stmtP = $pdo->prepare("INSERT INTO pacientes (nome, telefone, email, observacoes_medicas) VALUES (?, ?, ?, ?)");
                $stmtP->execute([$nome, $telefone, $email, $observacoes]);
            }

            echo json_encode([
                'status' => 'success',
                'mensagem' => 'Paciente cadastrado com sucesso!',
                'id' => $pdo->lastInsertId()
            ]);
            break;

        // ----------------------------------------------------
        // 8. CADASTRAR NOVO PROFISSIONAL
        // ----------------------------------------------------
        case 'criar_profissional':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;

            $nome = trim($dados['nome'] ?? '');
            $registro = trim($dados['registro_profissional'] ?? '');
            $especialidade = trim($dados['especialidade'] ?? '');
            $modalidades = is_array($dados['modalidades'] ?? null) ? implode(',', $dados['modalidades']) : trim($dados['modalidades'] ?? 'pilates');
            $telefone = trim($dados['telefone'] ?? '');
            $email = trim($dados['email'] ?? '');

            if (!$nome || !$registro) {
                throw new Exception('Nome e Registro Profissional são obrigatórios.');
            }

            $stmtProf = $pdo->prepare("
                INSERT INTO profissionais (nome, registro_profissional, especialidade, modalidades, telefone, email, ativo)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtProf->execute([$nome, $registro, $especialidade, $modalidades, $telefone, $email]);

            echo json_encode([
                'status' => 'success',
                'mensagem' => 'Profissional cadastrado com sucesso!',
                'id' => $pdo->lastInsertId()
            ]);
            break;

        // ----------------------------------------------------
        // 9. ATIVAR / DESATIVAR PROFISSIONAL
        // ----------------------------------------------------
        case 'toggle_profissional':
            if ($metodo !== 'POST') throw new Exception('Método inválido.');
            $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($dados['id'] ?? 0);

            $stmtTog = $pdo->prepare("UPDATE profissionais SET ativo = NOT ativo WHERE id = ?");
            $stmtTog->execute([$id]);

            echo json_encode(['status' => 'success', 'mensagem' => 'Status do profissional atualizado!']);
            break;

        default:
            http_response_code(404);
            echo json_encode(['status' => 'error', 'mensagem' => 'Ação não encontrada.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
