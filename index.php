<?php
/**
 * index.php - Painel Principal de Gestão e Controle de Agenda Clínica
 * Modalidades: Pilates Studio, Hidroginástica e Fisioterapia
 * Acesso Exclusivo do Estabelecimento
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Garante acesso exclusivo da administração/recepção
verificarAcessoAdmin();

$pdo = getPDOConnection();
$mensagemSucesso = $_GET['msg'] ?? '';
$mensagemErro = '';

// Identifica o usuário atualmente autenticado na sessão
$usuarioIdSessao = (int)($_SESSION['usuario_id'] ?? 0);
$usuarioLogado = null;
if ($usuarioIdSessao > 0) {
    try {
        $stmtUser = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmtUser->execute([$usuarioIdSessao]);
        $usuarioLogado = $stmtUser->fetch();
    } catch (Exception $eU) {}
}
if (!$usuarioLogado) {
    $usuarioLogado = [
        'id' => $_SESSION['usuario_id'] ?? 1,
        'nome' => $_SESSION['usuario_nome'] ?? 'Administrador do Sistema',
        'email' => $_SESSION['usuario_email'] ?? 'admin@clinica.com',
        'papel' => $_SESSION['usuario_papel'] ?? 'admin',
        'telefone' => '(11) 98888-0001',
        'ativo' => 1
    ];
}

$papelAtual = $usuarioLogado['papel'] ?? ($_SESSION['usuario_papel'] ?? 'admin');
$nomeUsuarioAtual = $usuarioLogado['nome'] ?? ($_SESSION['usuario_nome'] ?? 'Usuário');
$emailUsuarioAtual = $usuarioLogado['email'] ?? ($_SESSION['usuario_email'] ?? '');
$inicialUsuario = strtoupper(substr(trim($nomeUsuarioAtual), 0, 1) ?: 'U');
$pacienteIdVinculado = (int)($usuarioLogado['paciente_id'] ?? ($_SESSION['paciente_id'] ?? 0));
$profissionalIdVinculado = (int)($usuarioLogado['profissional_id'] ?? ($_SESSION['profissional_id'] ?? 0));

// Se for perfil de aluno e ainda não possuir paciente_id vinculado, busca por e-mail na tabela pacientes
if ($papelAtual === 'aluno' && $pacienteIdVinculado === 0) {
    try {
        $stmtFindPac = $pdo->prepare("SELECT id FROM pacientes WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
        $stmtFindPac->execute([$emailUsuarioAtual]);
        $foundPacId = $stmtFindPac->fetchColumn();
        if ($foundPacId) {
            $pacienteIdVinculado = (int)$foundPacId;
            $pdo->prepare("UPDATE usuarios SET paciente_id = ? WHERE id = ?")->execute([$pacienteIdVinculado, $usuarioLogado['id']]);
        }
    } catch (Exception $e) {}
}

// Matriz de Controle de Acesso Baseado em Papéis (RBAC)
$ehAdmin = ($papelAtual === 'admin');
$ehSecretaria = ($papelAtual === 'secretaria');
$ehProfessor = ($papelAtual === 'professor');
$ehAluno = ($papelAtual === 'aluno');

$podeGerenciarTudo = $ehAdmin;
$podeGerenciarUsuarios = $ehAdmin;
$podeGerenciarEquipe = ($ehAdmin || $ehSecretaria);
$podeGerenciarGrade = ($ehAdmin || $ehSecretaria);
$podeGerenciarPacientes = ($ehAdmin || $ehSecretaria);
$podeBloquearHorarios = ($ehAdmin || $ehSecretaria);
$podeVisualizarProntuario = ($ehAdmin || $ehSecretaria || $ehProfessor);

// Parâmetros de navegação e filtros
$dataSelecionada = $_GET['data'] ?? date('Y-m-d');
$diaSemanaNum = (int)date('w', strtotime($dataSelecionada));

// Define aba inicial padrão conforme o papel do usuário
$abaPadrao = 'diaria';
if ($ehAluno) {
    $abaPadrao = 'meu_painel';
} elseif ($ehProfessor) {
    $abaPadrao = 'chamada';
}

$abaAtiva = $_GET['aba'] ?? $abaPadrao;
$filtroModalidade = $_GET['modalidade'] ?? 'todas';

// -------------------------------------------------------------------
// FLUXO DE SEGURANÇA E BLOQUEIO DE ABAS RESTRITAS
// -------------------------------------------------------------------
if ($ehAluno) {
    // Alunos e Pacientes têm acesso estritamente restrito: apenas 'meu_painel'
    $abasPermitidasAluno = ['meu_painel'];
    if (!in_array($abaAtiva, $abasPermitidasAluno, true)) {
        header("Location: index.php?aba=meu_painel");
        exit;
    }
} elseif ($ehProfessor) {
    // Professores acessam 'chamada', 'diaria', 'semanal', 'pacientes'
    $abasPermitidasProf = ['chamada', 'diaria', 'semanal', 'pacientes'];
    if (!in_array($abaAtiva, $abasPermitidasProf, true)) {
        $abaAtiva = 'chamada';
        $mensagemErro = 'Acesso restrito: Professores não têm permissão para acessar configurações administrativas.';
    }
} elseif ($ehSecretaria) {
    // Secretária tem acesso operacional, mas não acessa 'usuarios'
    if ($abaAtiva === 'usuarios') {
        $abaAtiva = 'diaria';
        $mensagemErro = 'Acesso restrito: Apenas Administradores Gerais podem gerenciar contas e credenciais de usuários.';
    }
}

// -------------------------------------------------------------------
// PROCESSAMENTO DE FORMULÁRIOS E AÇÕES (POST)
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        // 1. NOVO AGENDAMENTO COM VALIDAÇÃO DE CAPACIDADE
        if ($acao === 'novo_agendamento') {
            $pacienteId = (int)($_POST['paciente_id'] ?? 0);
            $modalidadeId = $_POST['modalidade_id'] ?? '';
            $data = trim($_POST['data'] ?? '');
            if (strpos($data, '/') !== false) {
                $pts = explode('/', $data);
                if (count($pts) === 3 && strlen($pts[2]) === 4) {
                    $data = sprintf('%04d-%02d-%02d', (int)$pts[2], (int)$pts[1], (int)$pts[0]);
                }
            }
            $horaInicio = $_POST['hora_inicio'] ?? '';
            $horaFim = $_POST['hora_fim'] ?? '';
            $profissionalId = (int)($_POST['profissional_id'] ?? 0);
            $gradeId = !empty($_POST['grade_horario_id']) ? (int)$_POST['grade_horario_id'] : null;
            $tipoAtendimento = trim($_POST['tipo_atendimento'] ?? '');
            $observacao = trim($_POST['observacao'] ?? '');

            // Se for aluno, garante que o agendamento seja vinculado estritamente ao seu próprio cadastro
            if ($ehAluno) {
                if ($pacienteIdVinculado > 0) {
                    $pacienteId = $pacienteIdVinculado;
                } else {
                    throw new Exception('Seu cadastro de aluno ainda não possui vínculo de paciente na clínica.');
                }
            }

            if (!$pacienteId || !$modalidadeId || !$data || !$horaInicio || !$profissionalId) {
                throw new Exception('Preencha todos os campos obrigatórios do agendamento.');
            }

            // 1. Validação de data: Não permitir agendamento em datas passadas (apenas hoje ou futuro)
            $hoje = date('Y-m-d');
            if (empty($data) || $data < $hoje || strtotime($data) < strtotime($hoje)) {
                throw new Exception('A data do agendamento não pode ser anterior ao dia de hoje.');
            }

            // 2. Validação de duplicidade: Paciente já possui agendamento ativo nesta data e horário
            $stmtExistePac = $pdo->prepare("
                SELECT COUNT(*) FROM agendamentos 
                WHERE data = ? AND hora_inicio = ? AND paciente_id = ? AND status != 'cancelado'
            ");
            $stmtExistePac->execute([$data, $horaInicio, $pacienteId]);
            if ((int)$stmtExistePac->fetchColumn() > 0) {
                throw new Exception('Já existe agendamento naquele horário.');
            }

            // Checagem de capacidade máxima
            $capacidadeMaxima = 1;
            if ($gradeId) {
                $stmtC = $pdo->prepare("SELECT capacidade_maxima FROM grade_horarios WHERE id = ?");
                $stmtC->execute([$gradeId]);
                $capacidadeMaxima = (int)($stmtC->fetchColumn() ?: 1);
            } else {
                $stmtC = $pdo->prepare("SELECT capacidade_padrao FROM modalidades WHERE id = ?");
                $stmtC->execute([$modalidadeId]);
                $capacidadeMaxima = (int)($stmtC->fetchColumn() ?: 1);
            }

            // Conta agendamentos ativos no mesmo horário e modalidade
            $stmtOcup = $pdo->prepare("
                SELECT COUNT(*) FROM agendamentos 
                WHERE data = ? AND hora_inicio = ? AND modalidade_id = ? AND status != 'cancelado'
            ");
            $stmtOcup->execute([$data, $horaInicio, $modalidadeId]);
            $ocupados = (int)$stmtOcup->fetchColumn();

            if ($ocupados >= $capacidadeMaxima) {
                throw new Exception('Já existe agendamento naquele horário.');
            }

            $stmtIns = $pdo->prepare("
                INSERT INTO agendamentos (data, hora_inicio, hora_fim, modalidade_id, grade_horario_id, profissional_id, paciente_id, status, tipo_atendimento, observacao)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmado', ?, ?)
            ");
            $stmtIns->execute([$data, $horaInicio, $horaFim, $modalidadeId, $gradeId, $profissionalId, $pacienteId, $tipoAtendimento, $observacao]);

            $retAba = $ehAluno ? 'meu_painel' : 'diaria';
            header("Location: index.php?data={$data}&aba={$retAba}&msg=" . urlencode('Agendamento realizado com sucesso!'));
            exit;
        }

        // 2. ATUALIZAR STATUS DE PRESENÇA / FALTA / CONFIRMADO
        if ($acao === 'atualizar_status') {
            if ($ehAluno) {
                throw new Exception('Alunos não possuem autorização para alterar o status de presença das aulas.');
            }
            $agendamentoId = (int)($_POST['agendamento_id'] ?? 0);
            $novoStatus = $_POST['status'] ?? '';
            $statusPermitidos = ['agendado', 'confirmado', 'realizado', 'falta', 'cancelado'];

            if (in_array($novoStatus, $statusPermitidos, true)) {
                $stmtSt = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
                $stmtSt->execute([$novoStatus, $agendamentoId]);
            }
            header("Location: index.php?data={$dataSelecionada}&aba=diaria&msg=" . urlencode('Status de presença atualizado!'));
            exit;
        }

        // 3. EXCLUIR OU CANCELAR AGENDAMENTO
        if ($acao === 'excluir_agendamento' || $acao === 'cancelar_meu_agendamento') {
            $agendamentoId = (int)($_POST['agendamento_id'] ?? 0);
            if ($ehAluno) {
                // Aluno só pode cancelar seu próprio agendamento
                $stmtCheck = $pdo->prepare("SELECT id FROM agendamentos WHERE id = ? AND paciente_id = ?");
                $stmtCheck->execute([$agendamentoId, $pacienteIdVinculado]);
                if (!$stmtCheck->fetch()) {
                    throw new Exception('Você só tem autorização para cancelar seus próprios agendamentos.');
                }
                $stmtDel = $pdo->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ?");
                $stmtDel->execute([$agendamentoId]);
                header("Location: index.php?aba=meu_painel&msg=" . urlencode('Agendamento cancelado com sucesso.'));
                exit;
            } else {
                $stmtDel = $pdo->prepare("DELETE FROM agendamentos WHERE id = ?");
                $stmtDel->execute([$agendamentoId]);
                header("Location: index.php?data={$dataSelecionada}&aba={$abaAtiva}&msg=" . urlencode('Agendamento cancelado/excluído.'));
                exit;
            }
        }

        // BLOQUEIO DE HORÁRIOS / DIAS NA AGENDA
        if ($acao === 'novo_bloqueio') {
            if (!$podeBloquearHorarios) {
                throw new Exception('Acesso restrito: seu perfil não possui autorização para bloquear horários.');
            }
            $dataBloq = trim($_POST['data'] ?? '');
            if (strpos($dataBloq, '/') !== false) {
                $pts = explode('/', $dataBloq);
                if (count($pts) === 3 && strlen($pts[2]) === 4) {
                    $dataBloq = sprintf('%04d-%02d-%02d', (int)$pts[2], (int)$pts[1], (int)$pts[0]);
                }
            }
            $modalidadeId = trim($_POST['modalidade_id'] ?? 'todas');
            if ($modalidadeId === 'todas' || empty($modalidadeId)) {
                $modalidadeId = null;
            }
            $diaInteiro = isset($_POST['dia_inteiro']) && $_POST['dia_inteiro'] == '1';
            $horaInicio = (!$diaInteiro && !empty($_POST['hora_inicio'])) ? $_POST['hora_inicio'] : null;
            $horaFim = (!$diaInteiro && !empty($_POST['hora_fim'])) ? $_POST['hora_fim'] : null;
            $motivo = trim($_POST['motivo'] ?? '');

            if (!$dataBloq || !$motivo) {
                throw new Exception('Data e motivo do bloqueio são campos obrigatórios.');
            }

            $stmtInsBloq = $pdo->prepare("
                INSERT INTO bloqueios_agenda (data, hora_inicio, hora_fim, modalidade_id, motivo)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtInsBloq->execute([$dataBloq, $horaInicio, $horaFim, $modalidadeId, $motivo]);

            header("Location: index.php?data={$dataBloq}&aba={$abaAtiva}&msg=" . urlencode('Horário/Data bloqueado com sucesso na clínica!'));
            exit;
        }

        if ($acao === 'excluir_bloqueio') {
            if (!$podeBloquearHorarios) {
                throw new Exception('Acesso restrito: seu perfil não possui autorização para remover bloqueios.');
            }
            $bloqueioId = (int)($_POST['bloqueio_id'] ?? 0);
            $stmtDelBloq = $pdo->prepare("DELETE FROM bloqueios_agenda WHERE id = ?");
            $stmtDelBloq->execute([$bloqueioId]);
            header("Location: index.php?data={$dataSelecionada}&aba={$abaAtiva}&msg=" . urlencode('Bloqueio de horário removido com sucesso.'));
            exit;
        }

        // 4. CADASTRAR NOVO PACIENTE / ALUNO
        if ($acao === 'novo_paciente') {
            if (!$podeGerenciarPacientes) {
                throw new Exception('Acesso restrito: seu perfil não tem permissão para cadastrar pacientes.');
            }
            $nome = trim($_POST['nome'] ?? '');
            $telefone = trim($_POST['telefone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $dataNascimento = !empty($_POST['data_nascimento']) ? $_POST['data_nascimento'] : null;
            $modalidadeInteresse = trim($_POST['modalidade_interesse'] ?? 'pilates');
            $obs = trim($_POST['observacoes_medicas'] ?? '');

            if (!$nome || !$telefone) {
                throw new Exception('Nome completo e Telefone/WhatsApp são obrigatórios.');
            }

            // Garante que as colunas novas existam caso a tabela tenha sido criada em versão anterior
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM `pacientes`")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('modalidade_interesse', $cols, true)) {
                    $pdo->exec("ALTER TABLE `pacientes` ADD COLUMN `modalidade_interesse` VARCHAR(50) DEFAULT 'pilates'");
                }
                if (!in_array('data_nascimento', $cols, true)) {
                    $pdo->exec("ALTER TABLE `pacientes` ADD COLUMN `data_nascimento` DATE NULL");
                }
            } catch (Exception $eCol) {
                // Continua com fallback
            }

            // Tenta inserir com as novas colunas; se falhar por coluna ausente, usa fallback seguro
            try {
                $stmtPac = $pdo->prepare("
                    INSERT INTO pacientes (nome, telefone, email, data_nascimento, modalidade_interesse, observacoes_medicas) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtPac->execute([$nome, $telefone, $email, $dataNascimento, $modalidadeInteresse, $obs]);
            } catch (PDOException $eIns) {
                if (strpos($eIns->getMessage(), 'modalidade_interesse') !== false || strpos($eIns->getMessage(), 'data_nascimento') !== false) {
                    // Fallback para schema legado
                    $stmtFallback = $pdo->prepare("
                        INSERT INTO pacientes (nome, telefone, email, observacoes_medicas) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmtFallback->execute([$nome, $telefone, $email, $obs]);
                } else {
                    throw $eIns;
                }
            }

            // Se requisitado de um modal em outra aba, volta para ela
            $retornoAba = $_POST['retorno_aba'] ?? 'pacientes';
            header("Location: index.php?aba={$retornoAba}&data={$dataSelecionada}&msg=" . urlencode("Paciente {$nome} cadastrado com sucesso!"));
            exit;
        }

        // 5. EDITAR PACIENTE EXISTENTE
        if ($acao === 'editar_paciente') {
            if (!$podeGerenciarPacientes) {
                throw new Exception('Acesso restrito: seu perfil não tem permissão para editar dados de pacientes.');
            }
            $pacienteId = (int)($_POST['paciente_id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $telefone = trim($_POST['telefone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $dataNascimento = !empty($_POST['data_nascimento']) ? $_POST['data_nascimento'] : null;
            $modalidadeInteresse = trim($_POST['modalidade_interesse'] ?? 'pilates');
            $obs = trim($_POST['observacoes_medicas'] ?? '');

            if (!$pacienteId || !$nome || !$telefone) {
                throw new Exception('ID, Nome e Telefone são obrigatórios para editar.');
            }

            try {
                $stmtEditPac = $pdo->prepare("
                    UPDATE pacientes 
                    SET nome = ?, telefone = ?, email = ?, data_nascimento = ?, modalidade_interesse = ?, observacoes_medicas = ?
                    WHERE id = ?
                ");
                $stmtEditPac->execute([$nome, $telefone, $email, $dataNascimento, $modalidadeInteresse, $obs, $pacienteId]);
            } catch (PDOException $eUp) {
                if (strpos($eUp->getMessage(), 'modalidade_interesse') !== false || strpos($eUp->getMessage(), 'data_nascimento') !== false) {
                    $stmtEditFallback = $pdo->prepare("
                        UPDATE pacientes 
                        SET nome = ?, telefone = ?, email = ?, observacoes_medicas = ?
                        WHERE id = ?
                    ");
                    $stmtEditFallback->execute([$nome, $telefone, $email, $obs, $pacienteId]);
                } else {
                    throw $eUp;
                }
            }

            header("Location: index.php?aba=pacientes&msg=" . urlencode("Dados do paciente atualizados com sucesso!"));
            exit;
        }

        // 6. EXCLUIR PACIENTE
        if ($acao === 'excluir_paciente') {
            if (!$podeGerenciarPacientes) {
                throw new Exception('Acesso restrito: seu perfil não tem permissão para excluir pacientes.');
            }
            $pacienteId = (int)($_POST['paciente_id'] ?? 0);
            $stmtDelPac = $pdo->prepare("DELETE FROM pacientes WHERE id = ?");
            $stmtDelPac->execute([$pacienteId]);
            header("Location: index.php?aba=pacientes&msg=" . urlencode('Paciente removido com sucesso.'));
            exit;
        }

        // 7. ADICIONAR HORÁRIO NA GRADE SEMANAL (ADMIN)
        if ($acao === 'adicionar_slot_grade') {
            if (!$podeGerenciarGrade) {
                throw new Exception('Acesso restrito: apenas administradores e secretária podem alterar a grade de horários.');
            }
            $diaSemana = (int)($_POST['dia_semana'] ?? 1);
            $horaInicio = $_POST['hora_inicio'] ?? '';
            $horaFim = $_POST['hora_fim'] ?? '';
            $modalidadeId = $_POST['modalidade_id'] ?? 'pilates';
            $profissionalId = (int)($_POST['profissional_id'] ?? 1);
            $capacidade = (int)($_POST['capacidade_maxima'] ?? 4);
            $sala = trim($_POST['sala_local'] ?? 'Studio Sala 1');

            $stmtG = $pdo->prepare("
                INSERT INTO grade_horarios (dia_semana, hora_inicio, hora_fim, modalidade_id, profissional_id, capacidade_maxima, sala_local, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtG->execute([$diaSemana, $horaInicio, $horaFim, $modalidadeId, $profissionalId, $capacidade, $sala]);

            header("Location: index.php?aba=grade&dia={$diaSemana}&msg=" . urlencode('Novo horário cadastrado na grade semanal!'));
            exit;
        }

        // 8. EXCLUIR HORÁRIO DA GRADE
        if ($acao === 'excluir_slot_grade') {
            if (!$podeGerenciarGrade) {
                throw new Exception('Acesso restrito: apenas administradores e secretária podem remover horários da grade.');
            }
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $diaSemana = (int)($_POST['dia_semana'] ?? 1);
            $stmtDelG = $pdo->prepare("DELETE FROM grade_horarios WHERE id = ?");
            $stmtDelG->execute([$slotId]);

            header("Location: index.php?aba=grade&dia={$diaSemana}&msg=" . urlencode('Horário removido da grade semanal.'));
            exit;
        }

        // 9. RESTAURAR GRADE PADRÃO
        if ($acao === 'restaurar_grade_padrao') {
            if (!$podeGerenciarGrade) {
                throw new Exception('Acesso restrito: apenas administradores podem restaurar a grade padrão.');
            }
            garantirTabelasEIniciais($pdo);
            header("Location: index.php?aba=diaria&data={$dataSelecionada}&msg=" . urlencode('Grade de horários padrão carregada com sucesso!'));
            exit;
        }

        // 10. CADASTRAR NOVO PROFISSIONAL
        if ($acao === 'novo_profissional') {
            if (!$podeGerenciarEquipe) {
                throw new Exception('Acesso restrito: apenas a administração pode cadastrar novos profissionais.');
            }
            $nome = trim($_POST['nome'] ?? '');
            $registro = trim($_POST['registro_profissional'] ?? '');
            $especialidade = trim($_POST['especialidade'] ?? 'Profissional da Saúde');
            $modalidadesArray = $_POST['modalidades'] ?? ['pilates'];
            $modalidadesStr = is_array($modalidadesArray) ? implode(',', $modalidadesArray) : (string)$modalidadesArray;
            $telefone = trim($_POST['telefone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            if (!$nome || !$registro) {
                throw new Exception('Nome completo e Registro Profissional (CREFITO / CREF) são obrigatórios.');
            }

            $stmtProf = $pdo->prepare("
                INSERT INTO profissionais (nome, registro_profissional, especialidade, modalidades, telefone, email, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtProf->execute([$nome, $registro, $especialidade, $modalidadesStr, $telefone, $email, $ativo]);

            header("Location: index.php?aba=profissionais&msg=" . urlencode("Profissional {$nome} cadastrado com sucesso!"));
            exit;
        }

        // 11. EDITAR PROFISSIONAL EXISTENTE
        if ($acao === 'editar_profissional') {
            if (!$podeGerenciarEquipe) {
                throw new Exception('Acesso restrito: apenas a administração pode editar profissionais.');
            }
            $profId = (int)($_POST['profissional_id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $registro = trim($_POST['registro_profissional'] ?? '');
            $especialidade = trim($_POST['especialidade'] ?? 'Profissional da Saúde');
            $modalidadesArray = $_POST['modalidades'] ?? ['pilates'];
            $modalidadesStr = is_array($modalidadesArray) ? implode(',', $modalidadesArray) : (string)$modalidadesArray;
            $telefone = trim($_POST['telefone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            if (!$profId || !$nome || !$registro) {
                throw new Exception('ID, Nome e Registro Profissional são obrigatórios para editar.');
            }

            $stmtUpProf = $pdo->prepare("
                UPDATE profissionais
                SET nome = ?, registro_profissional = ?, especialidade = ?, modalidades = ?, telefone = ?, email = ?, ativo = ?
                WHERE id = ?
            ");
            $stmtUpProf->execute([$nome, $registro, $especialidade, $modalidadesStr, $telefone, $email, $ativo, $profId]);

            header("Location: index.php?aba=profissionais&msg=" . urlencode("Dados do profissional atualizados com sucesso!"));
            exit;
        }

        // 12. ATIVAR / DESATIVAR PROFISSIONAL
        if ($acao === 'toggle_ativo_profissional') {
            if (!$podeGerenciarEquipe) {
                throw new Exception('Acesso restrito: apenas a administração pode alterar status de profissionais.');
            }
            $profId = (int)($_POST['profissional_id'] ?? 0);
            $stmtTog = $pdo->prepare("UPDATE profissionais SET ativo = NOT ativo WHERE id = ?");
            $stmtTog->execute([$profId]);

            header("Location: index.php?aba=profissionais&msg=" . urlencode("Status do profissional alterado com sucesso!"));
            exit;
        }

        // 13. EXCLUIR PROFISSIONAL
        if ($acao === 'excluir_profissional') {
            if (!$podeGerenciarEquipe) {
                throw new Exception('Acesso restrito: apenas a administração pode excluir profissionais.');
            }
            $profId = (int)($_POST['profissional_id'] ?? 0);

            // Verifica se possui agendamentos vinculados
            $stmtCheckAg = $pdo->prepare("SELECT COUNT(*) FROM agendamentos WHERE profissional_id = ?");
            $stmtCheckAg->execute([$profId]);
            $temAg = (int)$stmtCheckAg->fetchColumn();

            if ($temAg > 0) {
                // Em vez de excluir e quebrar integridade, desativa o profissional
                $stmtDesat = $pdo->prepare("UPDATE profissionais SET ativo = 0 WHERE id = ?");
                $stmtDesat->execute([$profId]);
                header("Location: index.php?aba=profissionais&msg=" . urlencode("O profissional possui {$temAg} agendamento(s) no histórico. Ele foi desativado em vez de excluído para preservar o histórico."));
                exit;
            }

            $stmtDelProf = $pdo->prepare("DELETE FROM profissionais WHERE id = ?");
            $stmtDelProf->execute([$profId]);

            header("Location: index.php?aba=profissionais&msg=" . urlencode("Profissional removido com sucesso!"));
            exit;
        }

        // 14. CADASTRAR NOVO USUÁRIO
        if ($acao === 'novo_usuario') {
            if (!$podeGerenciarUsuarios) {
                throw new Exception('Acesso restrito: apenas o Administrador Geral pode criar contas de usuários.');
            }
            $nome = trim($_POST['nome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $telefone = trim($_POST['telefone'] ?? '');
            $senha = trim($_POST['senha'] ?? '');
            $papel = trim($_POST['papel'] ?? 'aluno');
            $profissionalId = !empty($_POST['profissional_id']) ? (int)$_POST['profissional_id'] : null;
            $pacienteId = !empty($_POST['paciente_id']) ? (int)$_POST['paciente_id'] : null;
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            if (!$nome || !$email || !$senha) {
                throw new Exception('Nome, E-mail e Senha são campos obrigatórios.');
            }
            if (strlen($senha) < 3) {
                throw new Exception('A senha deve ter no mínimo 3 caracteres.');
            }

            $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(?)");
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetch()) {
                throw new Exception("O e-mail \"{$email}\" já está cadastrado no sistema.");
            }

            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
            $stmtInsUser = $pdo->prepare("
                INSERT INTO usuarios (nome, email, senha_hash, papel, telefone, ativo, profissional_id, paciente_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsUser->execute([$nome, $email, $senhaHash, $papel, $telefone, $ativo, $profissionalId, $pacienteId]);

            header("Location: index.php?aba=usuarios&msg=" . urlencode("Usuário {$nome} cadastrado com sucesso com perfil de " . ucfirst($papel) . "!"));
            exit;
        }

        // 15. EDITAR DADOS DO USUÁRIO
        if ($acao === 'editar_usuario') {
            if (!$podeGerenciarUsuarios) {
                throw new Exception('Acesso restrito: apenas o Administrador Geral pode alterar dados de usuários.');
            }
            $id = (int)($_POST['usuario_id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $telefone = trim($_POST['telefone'] ?? '');
            $papel = trim($_POST['papel'] ?? 'aluno');
            $profissionalId = !empty($_POST['profissional_id']) ? (int)$_POST['profissional_id'] : null;
            $pacienteId = !empty($_POST['paciente_id']) ? (int)$_POST['paciente_id'] : null;
            $ativo = isset($_POST['ativo']) ? 1 : 0;
            $novaSenha = trim($_POST['nova_senha'] ?? '');

            if (!$id || !$nome || !$email) {
                throw new Exception('Preencha os campos obrigatórios do usuário.');
            }

            $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(TRIM(email)) = LOWER(?) AND id != ?");
            $stmtCheck->execute([$email, $id]);
            if ($stmtCheck->fetch()) {
                throw new Exception("O e-mail \"{$email}\" já está em uso por outro usuário.");
            }

            if (!empty($novaSenha)) {
                if (strlen($novaSenha) < 3) {
                    throw new Exception('A nova senha deve ter no mínimo 3 caracteres.');
                }
                $senhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);
                $stmtUp = $pdo->prepare("
                    UPDATE usuarios 
                    SET nome = ?, email = ?, senha_hash = ?, papel = ?, telefone = ?, ativo = ?, profissional_id = ?, paciente_id = ?
                    WHERE id = ?
                ");
                $stmtUp->execute([$nome, $email, $senhaHash, $papel, $telefone, $ativo, $profissionalId, $pacienteId, $id]);
            } else {
                $stmtUp = $pdo->prepare("
                    UPDATE usuarios 
                    SET nome = ?, email = ?, papel = ?, telefone = ?, ativo = ?, profissional_id = ?, paciente_id = ?
                    WHERE id = ?
                ");
                $stmtUp->execute([$nome, $email, $papel, $telefone, $ativo, $profissionalId, $pacienteId, $id]);
            }

            // Se for o próprio usuário logado, sincroniza sessão
            if ($id === (int)($_SESSION['usuario_id'] ?? 0)) {
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['usuario_papel'] = $papel;
                $_SESSION['usuario_email'] = $email;
            }

            header("Location: index.php?aba=usuarios&msg=" . urlencode("Dados do usuário {$nome} atualizados com sucesso!"));
            exit;
        }

        // 16. ALTERAR SENHA DO USUÁRIO
        if ($acao === 'alterar_senha_usuario') {
            if (!$podeGerenciarUsuarios) {
                throw new Exception('Acesso restrito: apenas o Administrador Geral pode redefinir senhas de outros usuários.');
            }
            $id = (int)($_POST['usuario_id'] ?? 0);
            $novaSenha = trim($_POST['nova_senha'] ?? '');

            if (!$id || empty($novaSenha)) {
                throw new Exception('Por favor, informe a nova senha.');
            }
            if (strlen($novaSenha) < 3) {
                throw new Exception('A nova senha deve conter no mínimo 3 caracteres.');
            }

            $senhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);
            $stmtUp = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
            $stmtUp->execute([$senhaHash, $id]);

            header("Location: index.php?aba=usuarios&msg=" . urlencode("Senha redefinida com sucesso no banco de dados!"));
            exit;
        }

        // 17. ATIVAR / DESATIVAR USUÁRIO
        if ($acao === 'toggle_usuario_ativo') {
            if (!$podeGerenciarUsuarios) {
                throw new Exception('Acesso restrito: apenas o Administrador Geral pode ativar ou desativar contas.');
            }
            $id = (int)($_POST['usuario_id'] ?? 0);
            if ($id === (int)($_SESSION['usuario_id'] ?? 0)) {
                throw new Exception('Você não pode desativar sua própria conta logada.');
            }
            $stmtTog = $pdo->prepare("UPDATE usuarios SET ativo = NOT ativo WHERE id = ?");
            $stmtTog->execute([$id]);

            header("Location: index.php?aba=usuarios&msg=" . urlencode("Status de acesso do usuário alterado!"));
            exit;
        }

        // 18. EXCLUIR USUÁRIO
        if ($acao === 'excluir_usuario') {
            if (!$podeGerenciarUsuarios) {
                throw new Exception('Acesso restrito: apenas o Administrador Geral pode excluir contas de usuários.');
            }
            $id = (int)($_POST['usuario_id'] ?? 0);
            if ($id === (int)($_SESSION['usuario_id'] ?? 0)) {
                throw new Exception('Você não pode excluir sua própria conta de administrador.');
            }
            $stmtDel = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmtDel->execute([$id]);

            header("Location: index.php?aba=usuarios&msg=" . urlencode("Usuário excluído com sucesso do sistema!"));
            exit;
        }
    } catch (Exception $e) {
        $mensagemErro = $e->getMessage();
    }
}

// -------------------------------------------------------------------
// CONSULTAS PARA ALIMENTAR A INTERFACE
// -------------------------------------------------------------------
$modalidades = $pdo->query("SELECT * FROM modalidades ORDER BY nome ASC")->fetchAll();
$profissionais = $pdo->query("SELECT * FROM profissionais WHERE ativo = 1 ORDER BY nome ASC")->fetchAll();
$todosProfissionais = $pdo->query("SELECT * FROM profissionais ORDER BY ativo DESC, nome ASC")->fetchAll();
$pacientes = $pdo->query("SELECT * FROM pacientes ORDER BY nome ASC")->fetchAll();

// Informações específicas do Aluno / Paciente conectado
$dadosMeuPaciente = null;
$meusAgendamentos = [];
$minhasObservacoesClinicas = [];
$minhasEstatisticas = [
    'total' => 0,
    'realizados' => 0,
    'faltas' => 0,
    'futuros' => 0,
    'taxa' => 100
];
$proximaAulaAluno = null;

if ($ehAluno) {
    if ($pacienteIdVinculado > 0) {
        $stmtMeuPac = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
        $stmtMeuPac->execute([$pacienteIdVinculado]);
        $dadosMeuPaciente = $stmtMeuPac->fetch();
    }
    if (!$dadosMeuPaciente && !empty($emailUsuarioAtual)) {
        $stmtMeuPac = $pdo->prepare("SELECT * FROM pacientes WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
        $stmtMeuPac->execute([$emailUsuarioAtual]);
        $dadosMeuPaciente = $stmtMeuPac->fetch();
        if ($dadosMeuPaciente) {
            $pacienteIdVinculado = (int)$dadosMeuPaciente['id'];
        }
    }

    if ($pacienteIdVinculado > 0) {
        $stmtMeusAg = $pdo->prepare("
            SELECT a.*, prof.nome as profissional_nome, prof.registro_profissional, prof.especialidade, m.nome as modalidade_nome
            FROM agendamentos a
            JOIN profissionais prof ON a.profissional_id = prof.id
            JOIN modalidades m ON a.modalidade_id = m.id
            WHERE a.paciente_id = ?
            ORDER BY a.data DESC, a.hora_inicio DESC
        ");
        $stmtMeusAg->execute([$pacienteIdVinculado]);
        $meusAgendamentos = $stmtMeusAg->fetchAll();

        $totalAg = count($meusAgendamentos);
        $realizados = 0;
        $faltas = 0;
        $futuros = 0;
        $hoje = date('Y-m-d');
        foreach ($meusAgendamentos as $ma) {
            if ($ma['status'] === 'realizado') $realizados++;
            elseif ($ma['status'] === 'falta') $faltas++;
            elseif ($ma['data'] >= $hoje && in_array($ma['status'], ['agendado', 'confirmado'])) {
                $futuros++;
                if (!$proximaAulaAluno) {
                    $proximaAulaAluno = $ma;
                }
            }
        }
        $taxa = ($realizados + $faltas > 0) ? round(($realizados / ($realizados + $faltas)) * 100) : 100;
        $minhasEstatisticas = [
            'total' => $totalAg,
            'realizados' => $realizados,
            'faltas' => $faltas,
            'futuros' => $futuros,
            'taxa' => $taxa
        ];

        try {
            $stmtMinhasObs = $pdo->prepare("SELECT * FROM observacoes_clinicas WHERE paciente_id = ? ORDER BY data DESC, criado_em DESC");
            $stmtMinhasObs->execute([$pacienteIdVinculado]);
            $minhasObservacoesClinicas = $stmtMinhasObs->fetchAll();
        } catch (Exception $eObs) {}
    }
}

// Consulta de todos os usuários para a Gestão de Usuários (restrita a Administradores)
$todosUsuarios = [];
if ($podeGerenciarUsuarios) {
    $todosUsuarios = $pdo->query("
        SELECT u.*, 
               prof.nome as profissional_nome, 
               pac.nome as paciente_nome 
        FROM usuarios u 
        LEFT JOIN profissionais prof ON u.profissional_id = prof.id 
        LEFT JOIN pacientes pac ON u.paciente_id = pac.id 
        ORDER BY u.id ASC
    ")->fetchAll();
}

// Métricas agregadas de histórico por paciente
$statsPacientesRaw = $pdo->query("
    SELECT paciente_id,
           COUNT(*) as total,
           SUM(CASE WHEN status = 'realizado' THEN 1 ELSE 0 END) as realizados,
           SUM(CASE WHEN status = 'falta' THEN 1 ELSE 0 END) as faltas,
           SUM(CASE WHEN status IN ('agendado', 'confirmado') THEN 1 ELSE 0 END) as pendentes,
           SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados
    FROM agendamentos
    GROUP BY paciente_id
")->fetchAll();
$statsPacientesMap = [];
foreach ($statsPacientesRaw as $st) {
    $statsPacientesMap[(int)$st['paciente_id']] = $st;
}

// Todos os agendamentos com detalhes para o histórico dos pacientes
$todosAgendamentosPacientes = $pdo->query("
    SELECT a.*, prof.nome as profissional_nome, prof.registro_profissional, m.nome as modalidade_nome
    FROM agendamentos a
    JOIN profissionais prof ON a.profissional_id = prof.id
    JOIN modalidades m ON a.modalidade_id = m.id
    ORDER BY a.data DESC, a.hora_inicio DESC
")->fetchAll();
$agendamentosPorPacienteJson = [];
foreach ($todosAgendamentosPacientes as $tag) {
    $agendamentosPorPacienteJson[(int)$tag['paciente_id']][] = $tag;
}

// Todas as observações clínicas para o Histórico Completo dos pacientes
$todasObservacoesPacientes = [];
try {
    $todasObservacoesPacientes = $pdo->query("
        SELECT o.*, prof.nome as prof_nome_rel, prof.registro_profissional as prof_reg_rel
        FROM observacoes_clinicas o
        LEFT JOIN profissionais prof ON o.profissional_id = prof.id
        ORDER BY o.data DESC, o.criado_em DESC
    ")->fetchAll();
} catch (Exception $e) {
    $todasObservacoesPacientes = [];
}
$observacoesPorPacienteJson = [];
foreach ($todasObservacoesPacientes as $obs) {
    $observacoesPorPacienteJson[(int)$obs['paciente_id']][] = $obs;
}

// Slots da grade semanal fixa para o dia da semana atual
$stmtGradeDia = $pdo->prepare("
    SELECT g.*, m.nome as modalidade_nome, p.nome as profissional_nome, p.especialidade
    FROM grade_horarios g
    JOIN modalidades m ON g.modalidade_id = m.id
    JOIN profissionais p ON g.profissional_id = p.id
    WHERE g.dia_semana = ? AND g.ativo = 1
    ORDER BY g.hora_inicio ASC
");
$stmtGradeDia->execute([$diaSemanaNum]);
$gradeSlotsDia = $stmtGradeDia->fetchAll();

// Agendamentos cadastrados para esta data específica
$sqlAg = "
    SELECT a.*, p.nome as paciente_nome, p.telefone as paciente_telefone, p.observacoes_medicas,
           prof.nome as profissional_nome, m.nome as modalidade_nome
    FROM agendamentos a
    JOIN pacientes p ON a.paciente_id = p.id
    JOIN profissionais prof ON a.profissional_id = prof.id
    JOIN modalidades m ON a.modalidade_id = m.id
    WHERE a.data = ?
";
if ($filtroModalidade !== 'todas') {
    $sqlAg .= " AND a.modalidade_id = " . $pdo->quote($filtroModalidade);
}
$sqlAg .= " ORDER BY a.hora_inicio ASC, a.id ASC";

$stmtAg = $pdo->prepare($sqlAg);
$stmtAg->execute([$dataSelecionada]);
$agendamentosDia = $stmtAg->fetchAll();

// Bloqueios cadastrados para esta data específica (feriados, manutenções, etc.)
$stmtBlk = $pdo->prepare("SELECT * FROM bloqueios_agenda WHERE data = ? ORDER BY hora_inicio ASC");
$stmtBlk->execute([$dataSelecionada]);
$bloqueiosDia = $stmtBlk->fetchAll();

if (!function_exists('verificarSlotBloqueado')) {
    function verificarSlotBloqueado($slot, $bloqueiosDia) {
        foreach ($bloqueiosDia as $b) {
            $modIgual = empty($b['modalidade_id']) || $b['modalidade_id'] === $slot['modalidade_id'];
            if (!$modIgual) continue;
            
            if (empty($b['hora_inicio']) || empty($b['hora_fim'])) {
                return $b['motivo'];
            }
            
            if ($slot['hora_inicio'] < $b['hora_fim'] && $slot['hora_fim'] > $b['hora_inicio']) {
                return $b['motivo'];
            }
        }
        return null;
    }
}

// Estatísticas do dia
$totalAgendadosHoje = count($agendamentosDia);
$totalRealizadosHoje = 0;
foreach ($agendamentosDia as $ag) {
    if ($ag['status'] === 'realizado') $totalRealizadosHoje++;
}

// Agrupamento de agendamentos por slot (hora + modalidade)
$agendamentosPorSlot = [];
$agendamentosAvulsos = [];
$slotsEncontrados = [];

foreach ($gradeSlotsDia as $slot) {
    $chave = substr($slot['hora_inicio'], 0, 5) . '_' . $slot['modalidade_id'];
    $slotsEncontrados[$chave] = true;
}

foreach ($agendamentosDia as $ag) {
    $chave = substr($ag['hora_inicio'], 0, 5) . '_' . $ag['modalidade_id'];
    if (isset($slotsEncontrados[$chave])) {
        $agendamentosPorSlot[$chave][] = $ag;
    } else {
        $agendamentosAvulsos[] = $ag;
    }
}

// -------------------------------------------------------------------
// DADOS PARA A VISÃO SEMANAL (SEGUNDA A SÁBADO)
// -------------------------------------------------------------------
$tsRef = strtotime($dataSelecionada);
$wDayRef = (int)date('w', $tsRef); // 0 = Dom, 1 = Seg, ..., 6 = Sáb
// Calcula a segunda-feira da semana de $dataSelecionada
$offsetParaSegunda = ($wDayRef === 0) ? -6 : 1 - $wDayRef;
$segundaTimestamp = strtotime("{$offsetParaSegunda} days", $tsRef);
$segundaDataStr = date('Y-m-d', $segundaTimestamp);
$sabadoTimestamp = strtotime("+5 days", $segundaTimestamp);
$sabadoDataStr = date('Y-m-d', $sabadoTimestamp);

// Busca agendamentos da semana inteira
$stmtSemanaAgs = $pdo->prepare("
    SELECT a.*, p.nome as paciente_nome, p.telefone as paciente_telefone,
           m.nome as modalidade_nome, prof.nome as profissional_nome
    FROM agendamentos a
    JOIN pacientes p ON a.paciente_id = p.id
    JOIN modalidades m ON a.modalidade_id = m.id
    JOIN profissionais prof ON a.profissional_id = prof.id
    WHERE a.data BETWEEN ? AND ? AND a.status != 'cancelado'
    ORDER BY a.data ASC, a.hora_inicio ASC
");
$stmtSemanaAgs->execute([$segundaDataStr, $sabadoDataStr]);
$agendamentosSemana = $stmtSemanaAgs->fetchAll();

$agendamentosPorData = [];
foreach ($agendamentosSemana as $agS) {
    $agendamentosPorData[$agS['data']][] = $agS;
}

// Busca todos os slots de grade semanal ativa para computar capacidade por dia
$gradeTodaSemana = $pdo->query("
    SELECT g.*, m.nome as modalidade_nome, p.nome as profissional_nome
    FROM grade_horarios g
    JOIN modalidades m ON g.modalidade_id = m.id
    JOIN profissionais p ON g.profissional_id = p.id
    WHERE g.ativo = 1
    ORDER BY g.dia_semana ASC, g.hora_inicio ASC
")->fetchAll();

$gradePorDiaSemana = [];
foreach ($gradeTodaSemana as $gItem) {
    $gradePorDiaSemana[(int)$gItem['dia_semana']][] = $gItem;
}

// Constrói os 6 cards de dias da semana (Segunda a Sábado)
$diasSemanaCards = [];
$nomesCurtosDias = ['', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$nomesLongosDias = ['', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
$mesesPt = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun', '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];

for ($i = 0; $i < 6; $i++) {
    $diaTs = strtotime("+{$i} days", $segundaTimestamp);
    $dStr = date('Y-m-d', $diaTs);
    $dNum = $i + 1; // 1 = Seg, 2 = Ter, ..., 6 = Sáb
    $mesNum = date('m', $diaTs);

    $agsDesteDia = $agendamentosPorData[$dStr] ?? [];
    $slotsDesteDia = $gradePorDiaSemana[$dNum] ?? [];

    $countPilates = 0;
    $countHidro = 0;
    $countFisio = 0;
    foreach ($agsDesteDia as $ag) {
        if ($ag['modalidade_id'] === 'pilates') $countPilates++;
        elseif ($ag['modalidade_id'] === 'hidroginastica') $countHidro++;
        elseif ($ag['modalidade_id'] === 'fisioterapia') $countFisio++;
    }

    $capacidadeDia = 0;
    foreach ($slotsDesteDia as $slot) {
        $capacidadeDia += (int)$slot['capacidade_maxima'];
    }

    $totalAgendados = count($agsDesteDia);
    $taxaOcupacao = $capacidadeDia > 0 ? min(100, (int)round(($totalAgendados / $capacidadeDia) * 100)) : ($totalAgendados > 0 ? 100 : 0);

    $diasSemanaCards[] = [
        'data' => $dStr,
        'dia_semana_num' => $dNum,
        'dia_curto' => $nomesCurtosDias[$dNum],
        'dia_longo' => $nomesLongosDias[$dNum],
        'dia_numero' => date('d', $diaTs),
        'mes_abreviado' => $mesesPt[$mesNum] ?? date('M', $diaTs),
        'data_formatada' => date('d/m/Y', $diaTs),
        'is_hoje' => ($dStr === date('Y-m-d')),
        'is_selecionado' => ($dStr === $dataSelecionada),
        'total_agendados' => $totalAgendados,
        'capacidade_total' => $capacidadeDia,
        'taxa_ocupacao' => $taxaOcupacao,
        'count_pilates' => $countPilates,
        'count_hidro' => $countHidro,
        'count_fisio' => $countFisio,
        'slots' => $slotsDesteDia,
        'agendamentos' => $agsDesteDia
    ];
}

// Formatador de datas em Português
$diasSemanaNomes = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
$diaSemanaTexto = $diasSemanaNomes[$diaSemanaNum];
$dataExibicao = date('d/m/Y', strtotime($dataSelecionada));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controle de Agenda Clínica - Pilates, Hidro e Fisioterapia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .badge-pilates { background-color: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .badge-hidro { background-color: #ecfeff; color: #0e7490; border-color: #a5f3fc; }
        .badge-fisio { background-color: #eef2ff; color: #4338ca; border-color: #c7d2fe; }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen flex flex-col font-sans antialiased">

    <!-- Topo da Aplicação -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Barra Superior com Identidade à Esquerda e Caixa do Usuário Logado Fixa no Canto Superior Direito -->
            <div class="flex items-center justify-between py-2.5 sm:py-3.5 border-b border-slate-100 gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-700 via-purple-700 to-cyan-600 flex items-center justify-center text-white shadow-sm font-bold text-base shrink-0">
                        CA
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight truncate">Controle de Agenda Clínica</h1>
                            <?php if ($papelAtual === 'admin'): ?>
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-md bg-indigo-100 text-indigo-800 border border-indigo-200 shrink-0">
                                    <svg class="w-3 h-3 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    Administrador
                                </span>
                            <?php elseif ($papelAtual === 'secretaria'): ?>
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-md bg-teal-100 text-teal-800 border border-teal-200 shrink-0">
                                    <svg class="w-3 h-3 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    Secretária / Recepção
                                </span>
                            <?php elseif ($papelAtual === 'professor'): ?>
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-md bg-sky-100 text-sky-800 border border-sky-200 shrink-0">
                                    <svg class="w-3 h-3 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                    Professor / Fisio
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-md bg-amber-100 text-amber-800 border border-amber-200 shrink-0">
                                    <svg class="w-3 h-3 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
                                    Aluno / Paciente
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[11px] sm:text-xs text-slate-500 hidden sm:block truncate">
                            Gestão de horários: <strong class="text-emerald-700">Pilates</strong>, <strong class="text-cyan-700">Hidroginástica</strong> e <strong class="text-indigo-700">Fisioterapia</strong>
                        </p>
                    </div>
                </div>

                <!-- Caixa do Usuário Logado Fixa no Canto Superior Direito -->
                <div class="relative shrink-0">
                    <button type="button" id="btnPerfilUsuario" onclick="toggleMenuUsuario(event)" class="flex items-center gap-2 sm:gap-2.5 px-2.5 sm:px-3 py-1.5 bg-slate-50 hover:bg-slate-100/90 border border-slate-200 hover:border-slate-300 rounded-xl text-xs font-semibold text-slate-800 transition shadow-2xs cursor-pointer group">
                        <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-gradient-to-tr from-indigo-600 to-purple-600 text-white font-bold text-xs flex items-center justify-center shadow-xs ring-2 ring-indigo-200/80 group-hover:ring-indigo-300 transition shrink-0">
                            <?= $inicialUsuario ?>
                        </div>
                        <div class="text-left hidden xs:block sm:block">
                            <div class="text-xs font-bold text-slate-900 leading-tight truncate max-w-[120px]">
                                <?= htmlspecialchars(explode(' ', trim($nomeUsuarioAtual))[0]) ?>
                            </div>
                            <div class="text-[10px] text-slate-500 font-normal leading-none capitalize">
                                <?= $papelAtual ?>
                            </div>
                        </div>
                        <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-slate-600 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <!-- Menu Dropdown do Usuário -->
                    <div id="dropdownMenuUsuario" class="hidden absolute right-0 mt-2 w-72 bg-white rounded-2xl shadow-2xl border border-slate-200 py-2 z-50 text-xs">
                        <div class="px-3.5 py-2.5 border-b border-slate-100 bg-slate-50/60 rounded-t-xl">
                            <div class="font-bold text-slate-900 text-sm truncate"><?= htmlspecialchars($nomeUsuarioAtual) ?></div>
                            <div class="text-slate-500 text-[11px] truncate"><?= htmlspecialchars($emailUsuarioAtual) ?></div>
                            <div class="mt-1.5 flex items-center gap-1.5">
                                <?php if ($papelAtual === 'admin'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-100 text-indigo-800">Administrador Geral</span>
                                <?php elseif ($papelAtual === 'secretaria'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-teal-100 text-teal-800">Secretária / Recepção</span>
                                <?php elseif ($papelAtual === 'professor'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-sky-100 text-sky-800">Professor / Fisio</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">Aluno / Paciente</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($podeGerenciarUsuarios): ?>
                        <a href="index.php?aba=usuarios" class="w-full text-left px-3.5 py-2.5 text-slate-700 hover:bg-indigo-50/70 flex items-center gap-2 font-medium transition cursor-pointer">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            <span>Gestão de Usuários & Acessos</span>
                        </a>
                        <?php endif; ?>

                        <a href="login.php?trocar_conta=1" class="w-full text-left px-3.5 py-2 text-slate-600 hover:bg-slate-50 flex items-center gap-2 transition cursor-pointer">
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            <span>Trocar de Conta / Novo Login</span>
                        </a>

                        <a href="logout.php" class="w-full text-left px-3.5 py-2 text-rose-600 hover:bg-rose-50 flex items-center gap-2 border-t border-slate-100 mt-1 font-semibold transition cursor-pointer">
                            <svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            <span>Sair (Logout)</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Linha Inferior: Abas de Navegação à Esquerda e Opções / Botões de Ação à Direita (embaixo sem ficar feio) -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between py-2.5 gap-3">
                <nav class="flex items-center gap-1.5 flex-wrap overflow-x-auto scrollbar-none">
                    <?php if ($ehAluno): ?>
                    <a href="index.php?aba=meu_painel" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 <?= $abaAtiva === 'meu_painel' ? 'bg-amber-100 text-amber-900 border border-amber-300' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
                        Meus Agendamentos & Aulas
                    </a>
                    <?php endif; ?>

                    <?php if (!$ehAluno): ?>
                    <a href="index.php?data=<?= $dataSelecionada ?>&aba=diaria" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 <?= $abaAtiva === 'diaria' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Agenda Diária
                    </a>

                    <a href="index.php?data=<?= $dataSelecionada ?>&aba=semanal" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 <?= $abaAtiva === 'semanal' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                        Visão Semanal
                    </a>
                    <?php endif; ?>

                    <?php if ($podeGerenciarGrade): ?>
                    <a href="index.php?aba=grade" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 <?= $abaAtiva === 'grade' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Grade & Vagas
                    </a>
                    <?php endif; ?>

                    <?php if ($podeGerenciarPacientes || $ehProfessor): ?>
                    <a href="index.php?aba=pacientes" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 <?= $abaAtiva === 'pacientes' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        Pacientes / Alunos
                        <span class="ml-1 px-1.5 py-0.2 bg-slate-200 text-slate-800 text-[10px] rounded-full font-bold">
                            <?= count($pacientes) ?>
                        </span>
                    </a>
                    <?php endif; ?>

                    <?php if ($podeGerenciarEquipe): ?>
                    <a href="index.php?aba=profissionais" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 <?= $abaAtiva === 'profissionais' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Profissionais
                        <span class="ml-1 px-1.5 py-0.2 bg-indigo-100 text-indigo-800 text-[10px] rounded-full font-bold">
                            <?= count($todosProfissionais) ?>
                        </span>
                    </a>
                    <?php endif; ?>

                    <?php if ($podeGerenciarUsuarios): ?>
                    <a href="index.php?aba=usuarios" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 <?= $abaAtiva === 'usuarios' ? 'bg-purple-100 text-purple-900 border border-purple-300' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <svg class="w-3.5 h-3.5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        Usuários
                        <span class="ml-1 px-1.5 py-0.2 bg-purple-200 text-purple-900 text-[10px] rounded-full font-bold">
                            <?= count($todosUsuarios) ?>
                        </span>
                    </a>
                    <?php endif; ?>
                </nav>

                <!-- Opções de Ação e Filtro de Data (Embaixo no subheader, alinhadas e elegantes) -->
                <div class="flex items-center gap-2 flex-wrap sm:justify-end">
                    <?php if ($podeGerenciarPacientes): ?>
                    <button onclick="abrirModalPaciente()" class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold shadow-2xs transition-colors cursor-pointer" title="Cadastrar novo paciente ou aluno">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                        <span>+ Paciente</span>
                    </button>
                    <?php endif; ?>

                    <?php if ($podeGerenciarEquipe): ?>
                    <button onclick="abrirModalProfissional()" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-indigo-50 text-slate-700 hover:text-indigo-800 border border-slate-200 hover:border-indigo-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer" title="Cadastrar novo profissional na equipe">
                        <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        <span>+ Profissional</span>
                    </button>
                    <?php endif; ?>

                    <?php if ($podeBloquearHorarios): ?>
                    <button onclick="abrirModalBloqueio()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 hover:bg-amber-50 text-slate-700 hover:text-amber-900 border border-slate-200 hover:border-amber-200 rounded-lg text-xs font-semibold transition-colors cursor-pointer" title="Bloquear data ou horário para manutenção/feriado">
                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span>Bloquear Horário</span>
                    </button>
                    <?php endif; ?>

                    <button onclick="abrirModalAgendamento()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white rounded-lg text-xs font-bold shadow-2xs transition-colors cursor-pointer">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span><?= $ehAluno ? '+ Agendar Minha Aula' : '+ Novo Agendamento' ?></span>
                    </button>

                    <?php if ($abaAtiva === 'diaria'): ?>
                    <!-- Controle de Navegação de Dias -->
                    <div class="flex items-center gap-1 pl-1.5 sm:border-l sm:border-slate-200">
                        <?php
                            $diaAnterior = date('Y-m-d', strtotime($dataSelecionada . ' -1 day'));
                            $diaSeguinte = date('Y-m-d', strtotime($dataSelecionada . ' +1 day'));
                            $hoje = date('Y-m-d');
                        ?>
                        <a href="index.php?data=<?= $diaAnterior ?>&aba=diaria&modalidade=<?= $filtroModalidade ?>" class="p-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-bold text-xs" title="Dia anterior">&larr;</a>
                        <a href="index.php?data=<?= $hoje ?>&aba=diaria&modalidade=<?= $filtroModalidade ?>" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 text-xs font-semibold">Hoje</a>
                        <a href="index.php?data=<?= $diaSeguinte ?>&aba=diaria&modalidade=<?= $filtroModalidade ?>" class="p-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-bold text-xs" title="Próximo dia">&rarr;</a>
                        <input type="date" value="<?= $dataSelecionada ?>" onchange="window.location.href='index.php?aba=diaria&modalidade=<?= $filtroModalidade ?>&data=' + this.value" class="text-xs p-1 bg-white border border-slate-300 rounded-lg text-slate-800 font-medium cursor-pointer shadow-2xs">
                        <span class="text-xs font-bold text-slate-700 ml-1 hidden xl:inline-block">
                            <?= $diaSemanaTexto ?>, <?= $dataExibicao ?>
                        </span>
                    </div>
                    <?php elseif ($abaAtiva === 'semanal'): ?>
                    <!-- Controle de Navegação de Semanas -->
                    <div class="flex items-center gap-1 pl-1.5 sm:border-l sm:border-slate-200">
                        <?php
                            $semanaAnterior = date('Y-m-d', strtotime($dataSelecionada . ' -7 days'));
                            $semanaSeguinte = date('Y-m-d', strtotime($dataSelecionada . ' +7 days'));
                            $hoje = date('Y-m-d');
                        ?>
                        <a href="index.php?data=<?= $semanaAnterior ?>&aba=semanal" class="p-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-bold text-xs" title="Semana anterior">&larr; Semana Anterior</a>
                        <a href="index.php?data=<?= $hoje ?>&aba=semanal" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 text-xs font-semibold">Semana Atual</a>
                        <a href="index.php?data=<?= $semanaSeguinte ?>&aba=semanal" class="p-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-bold text-xs" title="Próxima semana">Próxima Semana &rarr;</a>
                        <span class="text-xs font-bold text-indigo-900 bg-indigo-50 border border-indigo-200 px-2 py-1 rounded-lg ml-1 hidden sm:inline-block">
                            <?= date('d/m', $segundaTimestamp) ?> a <?= date('d/m/Y', $sabadoTimestamp) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Conteúdo Principal -->
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-1">

        <!-- Mensagens de Sucesso ou Erro (Auto-dismiss em até 10s) -->
        <?php if (!empty($mensagemSucesso) || !empty($_GET['msg'])): ?>
            <div class="notificacao-temporaria mb-5 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-semibold flex items-center justify-between shadow-xs transition-all duration-300" role="alert">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= htmlspecialchars($mensagemSucesso ?: $_GET['msg']) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] text-emerald-600 font-mono tempo-restante">(10s)</span>
                    <button type="button" onclick="fecharAlerta(this.closest('.notificacao-temporaria'))" class="text-emerald-700 hover:text-emerald-900 font-bold p-1 cursor-pointer" title="Fechar">✕</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($mensagemErro)): ?>
            <div class="notificacao-temporaria mb-5 p-3.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-semibold flex items-center justify-between shadow-xs transition-all duration-300" role="alert">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    <span><?= htmlspecialchars($mensagemErro) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] text-rose-600 font-mono tempo-restante">(10s)</span>
                    <button type="button" onclick="fecharAlerta(this.closest('.notificacao-temporaria'))" class="text-rose-700 hover:text-rose-900 font-bold p-1 cursor-pointer" title="Fechar">✕</button>
                </div>
            </div>
        <?php endif; ?>

        <!-- ======================================================== -->
        <!-- ABA: PAINEL EXCLUSIVO DO ALUNO / PACIENTE                -->
        <!-- ======================================================== -->
        <?php if ($abaAtiva === 'meu_painel'): ?>
            <!-- Card de Boas-Vindas do Aluno -->
            <div class="bg-gradient-to-r from-amber-50 via-orange-50 to-amber-100/60 border border-amber-200/80 rounded-2xl p-6 mb-6 shadow-xs">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="flex items-start gap-3.5">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500 text-white flex items-center justify-center font-bold text-lg shadow-sm shrink-0">
                            <?= $inicialUsuario ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h2 class="text-lg font-bold text-slate-900">Olá, <?= htmlspecialchars($nomeUsuarioAtual) ?>!</h2>
                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-200 text-amber-900 border border-amber-300">
                                    Portal do Aluno / Paciente
                                </span>
                            </div>
                            <p class="text-xs text-slate-600 mt-1 max-w-2xl">
                                Bem-vindo(a) ao seu painel exclusivo da clínica. Aqui você acompanha sua frequência, próximas aulas de Pilates, Hidroginástica ou Fisioterapia e pode solicitar novos agendamentos com rapidez.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button onclick="abrirModalAgendamento()" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-2 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            + Agendar Minha Aula
                        </button>
                    </div>
                </div>
            </div>

            <!-- Métricas e Próxima Aula -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <!-- Próxima Sessão -->
                <div class="md:col-span-2 bg-white rounded-2xl border border-slate-200 p-5 shadow-xs flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Próxima Aula Agendada
                        </span>
                        <?php if ($proximaAulaAluno): ?>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                Confirmada
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($proximaAulaAluno): ?>
                        <div class="p-3.5 bg-indigo-50/70 border border-indigo-100 rounded-xl">
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-bold text-indigo-950 text-sm">
                                    <?= htmlspecialchars($proximaAulaAluno['modalidade_nome']) ?>
                                </span>
                                <span class="text-xs font-semibold text-indigo-800">
                                    <?= date('d/m/Y', strtotime($proximaAulaAluno['data'])) ?> às <?= substr($proximaAulaAluno['hora_inicio'], 0, 5) ?>
                                </span>
                            </div>
                            <div class="text-xs text-slate-600 flex items-center gap-1 mt-1">
                                <span>Profissional:</span>
                                <strong class="text-slate-800"><?= htmlspecialchars($proximaAulaAluno['profissional_nome']) ?></strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-4 bg-slate-50 border border-slate-200/70 rounded-xl text-center">
                            <p class="text-xs text-slate-500 font-medium">Nenhuma aula futura agendada no momento.</p>
                            <button onclick="abrirModalAgendamento()" class="mt-2 text-xs font-bold text-indigo-600 hover:text-indigo-800 hover:underline cursor-pointer">
                                + Clique aqui para reservar seu horário
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Presenças -->
                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Presenças Confirmadas</div>
                    <div class="text-3xl font-extrabold text-emerald-600 leading-none">
                        <?= $minhasEstatisticas['realizados'] ?>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-2">Aulas e atendimentos concluídos</p>
                </div>

                <!-- Frequência / Faltas -->
                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Taxa de Frequência</div>
                    <div class="text-3xl font-extrabold text-indigo-600 leading-none">
                        <?= $minhasEstatisticas['taxa'] ?>%
                    </div>
                    <p class="text-[11px] text-slate-500 mt-2">
                        <?= $minhasEstatisticas['faltas'] ?> falta(s) registrada(s)
                    </p>
                </div>
            </div>

            <!-- Tabela dos Meus Agendamentos -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <h3 class="font-bold text-slate-900 text-sm">Histórico e Próximos Agendamentos</h3>
                        <p class="text-[11px] text-slate-500">Seus horários reservados na clínica</p>
                    </div>
                    <span class="px-2.5 py-1 bg-slate-100 text-slate-700 text-xs font-bold rounded-lg">
                        Total: <?= count($meusAgendamentos) ?> aula(s)
                    </span>
                </div>

                <?php if (empty($meusAgendamentos)): ?>
                    <div class="p-10 text-center">
                        <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-3">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800">Nenhum agendamento encontrado</h4>
                        <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
                            Você ainda não possui horários agendados. Reserve sua aula de Pilates, Hidroginástica ou Fisioterapia agora mesmo.
                        </p>
                        <button onclick="abrirModalAgendamento()" class="mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition cursor-pointer">
                            + Agendar Minha Primeira Aula
                        </button>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50/80 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider text-[10px]">
                                <tr>
                                    <th class="px-4 py-3">Data</th>
                                    <th class="px-4 py-3">Horário</th>
                                    <th class="px-4 py-3">Modalidade</th>
                                    <th class="px-4 py-3">Profissional</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Ação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($meusAgendamentos as $ag): 
                                    $dataAg = $ag['data'];
                                    $ehFuturo = ($dataAg >= date('Y-m-d') && in_array($ag['status'], ['agendado', 'confirmado']));
                                ?>
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="px-4 py-3 font-semibold text-slate-900 whitespace-nowrap">
                                            <?= date('d/m/Y', strtotime($dataAg)) ?>
                                        </td>
                                        <td class="px-4 py-3 font-mono font-medium text-slate-700 whitespace-nowrap">
                                            <?= substr($ag['hora_inicio'], 0, 5) ?> - <?= substr($ag['hora_fim'], 0, 5) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($ag['modalidade_id'] === 'pilates'): ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-100 text-emerald-800">Pilates Studio</span>
                                            <?php elseif ($ag['modalidade_id'] === 'hidroginastica'): ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-cyan-100 text-cyan-800">Hidroginástica</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-100 text-indigo-800">Fisioterapia</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 font-medium text-slate-800">
                                            <?= htmlspecialchars($ag['profissional_nome']) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($ag['status'] === 'realizado'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Presença Concluída</span>
                                            <?php elseif ($ag['status'] === 'confirmado'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800">Confirmado</span>
                                            <?php elseif ($ag['status'] === 'agendado'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">Agendado</span>
                                            <?php elseif ($ag['status'] === 'falta'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800">Falta</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600">Cancelado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <?php if ($ehFuturo): ?>
                                                <form method="POST" onsubmit="return confirm('Tem certeza que deseja cancelar este agendamento?');" class="inline">
                                                    <input type="hidden" name="acao" value="cancelar_meu_agendamento">
                                                    <input type="hidden" name="agendamento_id" value="<?= $ag['id'] ?>">
                                                    <button type="submit" class="px-2.5 py-1 text-[11px] font-semibold text-rose-600 hover:text-white hover:bg-rose-600 border border-rose-300 hover:border-rose-600 rounded-lg transition cursor-pointer">
                                                        Cancelar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-slate-400 text-xs">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Prontuário e Orientações Médicas (se houver para este aluno) -->
            <?php if (!empty($minhasObservacoesClinicas)): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-6 mb-8">
                    <h3 class="font-bold text-slate-900 text-sm mb-1 flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Orientações e Prontuário do Fisioterapeuta / Professor
                    </h3>
                    <p class="text-[11px] text-slate-500 mb-4">Evolução e registros de acompanhamento dos seus atendimentos</p>
                    <div class="space-y-3">
                        <?php foreach ($minhasObservacoesClinicas as $obs): ?>
                            <div class="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl">
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <strong class="text-slate-900"><?= htmlspecialchars($obs['titulo']) ?></strong>
                                    <span class="text-slate-500"><?= date('d/m/Y', strtotime($obs['data'])) ?></span>
                                </div>
                                <p class="text-xs text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($obs['texto'])) ?></p>
                                <div class="mt-2 text-[10px] text-slate-500 flex items-center gap-1">
                                    <span>Profissional: <?= htmlspecialchars($obs['profissional_nome']) ?></span>
                                    <?php if (!empty($obs['dor_eva'])): ?>
                                        <span class="ml-2 font-bold text-amber-700">Dor EVA: <?= (int)$obs['dor_eva'] ?>/10</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <!-- ======================================================== -->
        <!-- ABA 1: AGENDA DIÁRIA                                     -->
        <!-- ======================================================== -->
        <?php elseif ($abaAtiva === 'diaria'): ?>
            <!-- Painel de Filtros por Modalidade e Resumo Diário -->
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-xs mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider mr-1">Filtrar:</span>
                    <a href="index.php?data=<?= $dataSelecionada ?>&aba=diaria&modalidade=todas" class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?= $filtroModalidade === 'todas' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                        Todas as Modalidades
                    </a>
                    <a href="index.php?data=<?= $dataSelecionada ?>&aba=diaria&modalidade=pilates" class="px-3 py-1.5 rounded-lg text-xs font-bold transition border <?= $filtroModalidade === 'pilates' ? 'bg-emerald-600 text-white border-emerald-600' : 'badge-pilates hover:bg-emerald-100' ?>">
                        Pilates Studio (até 4 vagas)
                    </a>
                    <a href="index.php?data=<?= $dataSelecionada ?>&aba=diaria&modalidade=hidroginastica" class="px-3 py-1.5 rounded-lg text-xs font-bold transition border <?= $filtroModalidade === 'hidroginastica' ? 'bg-cyan-600 text-white border-cyan-600' : 'badge-hidro hover:bg-cyan-100' ?>">
                        Hidroginástica (até 12 vagas)
                    </a>
                    <a href="index.php?data=<?= $dataSelecionada ?>&aba=diaria&modalidade=fisioterapia" class="px-3 py-1.5 rounded-lg text-xs font-bold transition border <?= $filtroModalidade === 'fisioterapia' ? 'bg-indigo-600 text-white border-indigo-600' : 'badge-fisio hover:bg-indigo-100' ?>">
                        Fisioterapia (1:1 individual)
                    </a>
                </div>

                <div class="flex items-center gap-3 text-xs font-semibold flex-wrap">
                    <div class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-900 border border-indigo-100 flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                        Agendados Hoje: <strong><?= $totalAgendadosHoje ?></strong>
                    </div>
                    <div class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-900 border border-emerald-100 flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                        Presenças Confirmadas: <strong><?= $totalRealizadosHoje ?></strong>
                    </div>
                </div>
            </div>

            <!-- Bloqueios Ativos para esta Data -->
            <?php if (!empty($bloqueiosDia)): ?>
                <div class="mb-4 space-y-2">
                    <?php foreach ($bloqueiosDia as $bDia): ?>
                        <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl flex items-center justify-between text-xs text-amber-900 shadow-2xs">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <span>
                                    <strong>Bloqueio Ativo:</strong> <?= htmlspecialchars($bDia['motivo']) ?>
                                    <?php if (!empty($bDia['hora_inicio'])): ?>
                                        <span class="font-mono font-semibold">(<?= substr($bDia['hora_inicio'], 0, 5) ?> às <?= substr($bDia['hora_fim'], 0, 5) ?>)</span>
                                    <?php else: ?>
                                        <span class="font-semibold">(Dia inteiro)</span>
                                    <?php endif; ?>
                                    <?php if (!empty($bDia['modalidade_id'])): ?>
                                        <span class="text-amber-700 font-medium">- Somente <?= ucfirst($bDia['modalidade_id']) ?></span>
                                    <?php else: ?>
                                        <span class="text-amber-700 font-medium">- Todas as modalidades</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($podeBloquearHorarios): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Deseja realmente remover este bloqueio?')">
                                    <input type="hidden" name="acao" value="excluir_bloqueio">
                                    <input type="hidden" name="bloqueio_id" value="<?= $bDia['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 text-[11px] font-bold text-rose-700 hover:bg-rose-100 rounded-lg transition cursor-pointer">
                                        Remover Bloqueio
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Grade de Horários e Atendimentos do Dia -->
            <div class="space-y-4">
                <?php
                // Filtra os slots da grade semanal conforme a modalidade escolhida
                $slotsFiltrados = array_filter($gradeSlotsDia, function($s) use ($filtroModalidade) {
                    return $filtroModalidade === 'todas' || $s['modalidade_id'] === $filtroModalidade;
                });

                // Se não houver slots fixos nem agendamentos cadastrados para a data
                if (empty($slotsFiltrados) && empty($agendamentosDia)): ?>
                    <div class="bg-white rounded-2xl border border-slate-200 p-8 text-center shadow-xs">
                        <div class="w-14 h-14 bg-indigo-50 rounded-2xl text-indigo-600 flex items-center justify-center mx-auto mb-3 shadow-2xs">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <h3 class="text-base font-bold text-slate-800">
                            Nenhum horário fixo cadastrado para <?= $diaSemanaTexto ?>
                        </h3>
                        <p class="text-xs text-slate-500 max-w-md mx-auto mt-1 mb-5">
                            Você pode configurar a grade semanal de <?= $diaSemanaTexto ?> ou agendar um atendimento individual agora mesmo.
                        </p>
                        <div class="flex flex-wrap items-center justify-center gap-3">
                            <a href="index.php?aba=grade&dia=<?= $diaSemanaNum ?>" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl shadow-xs transition">
                                + Configurar Grade de <?= $diaSemanaTexto ?>
                            </a>
                            <button onclick="abrirModalAgendamento()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl transition cursor-pointer">
                                + Novo Agendamento Avulso
                            </button>
                            <form method="POST" class="inline">
                                <input type="hidden" name="acao" value="restaurar_grade_padrao">
                                <button type="submit" class="px-4 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-bold rounded-xl transition cursor-pointer">
                                    ⚡ Carregar Grade Padrão
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>

                    <!-- Loop dos Horários da Grade Fixa -->
                    <?php foreach ($slotsFiltrados as $slot): 
                        $chave = substr($slot['hora_inicio'], 0, 5) . '_' . $slot['modalidade_id'];
                        $pacientesAgendados = $agendamentosPorSlot[$chave] ?? [];
                        $vagasOcupadas = count($pacientesAgendados);
                        $capacidade = (int)$slot['capacidade_maxima'];
                        $vagasRestantes = max(0, $capacidade - $vagasOcupadas);
                        $porcentagemOcupacao = $capacidade > 0 ? min(100, round(($vagasOcupadas / $capacidade) * 100)) : 100;
                        $badgeClass = 'badge-' . ($slot['modalidade_id'] === 'pilates' ? 'pilates' : ($slot['modalidade_id'] === 'hidroginastica' ? 'hidro' : 'fisio'));
                        $motivoBloqueio = verificarSlotBloqueado($slot, $bloqueiosDia);
                        $slotBloqueado = !empty($motivoBloqueio);
                    ?>
                    <div class="bg-white rounded-xl border <?= $slotBloqueado ? 'border-amber-300 bg-amber-50/20' : 'border-slate-200' ?> shadow-xs overflow-hidden">
                        <!-- Topo do Horário / Turma -->
                        <div class="px-5 py-3.5 bg-slate-50/90 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="px-2.5 py-1 rounded-md bg-slate-900 text-white font-mono text-xs font-bold shadow-2xs">
                                    <?= substr($slot['hora_inicio'], 0, 5) ?> - <?= substr($slot['hora_fim'], 0, 5) ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $badgeClass ?>">
                                    <?= htmlspecialchars($slot['modalidade_nome']) ?>
                                </span>
                                <span class="text-xs text-slate-600">
                                    Prof: <strong class="text-slate-800"><?= htmlspecialchars($slot['profissional_nome']) ?></strong>
                                    <span class="text-slate-300 mx-1">•</span>
                                    <span class="text-slate-500"><?= htmlspecialchars($slot['sala_local']) ?></span>
                                </span>
                            </div>

                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <div class="text-xs font-bold <?= $vagasRestantes === 0 ? 'text-rose-600' : 'text-slate-800' ?>">
                                        <?= $vagasOcupadas ?> / <?= $capacidade ?>
                                        <span class="text-[11px] font-normal text-slate-500 ml-1">
                                            <?= $slot['modalidade_id'] === 'fisioterapia' ? ($vagasOcupadas > 0 ? 'Ocupado' : 'Horário Livre') : ($vagasRestantes === 1 ? 'vaga livre' : 'vagas livres') ?>
                                        </span>
                                    </div>
                                    <!-- Mini barra de ocupação visual -->
                                    <div class="w-24 bg-slate-200 h-1.5 rounded-full overflow-hidden mt-1 ml-auto">
                                        <div class="h-full <?= $porcentagemOcupacao >= 100 ? 'bg-rose-500' : ($porcentagemOcupacao >= 75 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width: <?= $porcentagemOcupacao ?>%"></div>
                                    </div>
                                </div>

                                <?php if ($slotBloqueado): ?>
                                    <span class="px-2.5 py-1 bg-amber-100 text-amber-800 border border-amber-300 text-xs font-bold rounded-lg flex items-center gap-1" title="Horário com bloqueio na clínica">
                                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        Bloqueado: <?= htmlspecialchars($motivoBloqueio) ?>
                                    </span>
                                <?php elseif ($dataSelecionada < date('Y-m-d')): ?>
                                    <span class="px-2.5 py-1 bg-slate-100 text-slate-400 border border-slate-200 text-xs font-semibold rounded-lg" title="Data passada - Não é permitido agendar no passado">
                                        Data passada
                                    </span>
                                <?php elseif ($vagasRestantes > 0): ?>
                                    <button onclick="agendarNesteSlot(<?= htmlspecialchars(json_encode($slot)) ?>)" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 text-xs font-bold rounded-lg transition cursor-pointer flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                        + Agendar Paciente
                                    </button>
                                <?php else: ?>
                                    <span class="px-3 py-1.5 bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold rounded-lg">
                                        Horário Lotado
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Pacientes Agendados no Horário -->
                        <div class="p-4">
                            <?php if (empty($pacientesAgendados)): ?>
                                <p class="text-xs text-slate-400 italic py-1">
                                    Nenhum paciente agendado para este horário ainda.
                                </p>
                            <?php else: ?>
                                <div class="divide-y divide-slate-100">
                                    <?php foreach ($pacientesAgendados as $ag): 
                                        $telLimpo = preg_replace('/\D/', '', $ag['paciente_telefone']);
                                        $msgWhats = urlencode("Olá {$ag['paciente_nome']}, confirmamos seu atendimento de {$ag['modalidade_nome']} hoje às " . substr($ag['hora_inicio'], 0, 5) . ".");
                                    ?>
                                    <div class="py-2.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-bold text-xs">
                                                <?= strtoupper(substr($ag['paciente_nome'], 0, 2)) ?>
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs font-bold text-slate-900"><?= htmlspecialchars($ag['paciente_nome']) ?></span>
                                                    <?php if (!empty($ag['tipo_atendimento'])): ?>
                                                        <span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-semibold"><?= htmlspecialchars($ag['tipo_atendimento']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-[11px] text-slate-500 flex items-center gap-2 mt-0.5">
                                                    <span><?= htmlspecialchars($ag['paciente_telefone']) ?></span>
                                                    <?php if (!empty($ag['observacoes_medicas'])): ?>
                                                        <span class="text-amber-700 bg-amber-50 px-1.5 py-0.2 rounded border border-amber-200 text-[10px]" title="Restrição Médica">
                                                            <?= htmlspecialchars($ag['observacoes_medicas']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <!-- Seletor de Presença Interativo -->
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="acao" value="atualizar_status">
                                                <input type="hidden" name="agendamento_id" value="<?= $ag['id'] ?>">
                                                <select name="status" onchange="this.form.submit()" class="text-xs py-1 px-2 bg-slate-50 border border-slate-300 rounded-lg font-semibold text-slate-700 cursor-pointer shadow-2xs">
                                                    <option value="confirmado" <?= $ag['status'] === 'confirmado' ? 'selected' : '' ?>>Confirmado</option>
                                                    <option value="realizado" <?= $ag['status'] === 'realizado' ? 'selected' : '' ?>>Presente / Realizado</option>
                                                    <option value="falta" <?= $ag['status'] === 'falta' ? 'selected' : '' ?>>Falta</option>
                                                    <option value="cancelado" <?= $ag['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                                </select>
                                            </form>

                                            <?php if ($telLimpo): ?>
                                                <a href="https://wa.me/55<?= $telLimpo ?>?text=<?= $msgWhats ?>" target="_blank" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-semibold rounded-lg transition" title="Enviar lembrete pelo WhatsApp">
                                                    WhatsApp
                                                </a>
                                            <?php endif; ?>

                                            <form method="POST" class="inline" onsubmit="return confirm('Deseja desmarcar este agendamento de <?= htmlspecialchars($ag['paciente_nome']) ?>?')">
                                                <input type="hidden" name="acao" value="excluir_agendamento">
                                                <input type="hidden" name="agendamento_id" value="<?= $ag['id'] ?>">
                                                <button type="submit" class="text-rose-600 hover:text-rose-800 text-xs font-semibold px-2 py-1 transition cursor-pointer">
                                                    Excluir
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Agendamentos Avulsos / Fora da Grade Padrão -->
                    <?php if (!empty($agendamentosAvulsos)): ?>
                    <div class="mt-8">
                        <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">
                            Atendimentos e Agendamentos Avulsos do Dia
                        </h4>
                        <div class="bg-white rounded-xl border border-slate-200 shadow-xs divide-y divide-slate-100">
                            <?php foreach ($agendamentosAvulsos as $ag): 
                                $telLimpo = preg_replace('/\D/', '', $ag['paciente_telefone']);
                                $msgWhats = urlencode("Olá {$ag['paciente_nome']}, confirmamos seu atendimento de {$ag['modalidade_nome']} hoje às " . substr($ag['hora_inicio'], 0, 5) . ".");
                            ?>
                            <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 bg-slate-900 text-white font-mono text-xs font-bold rounded">
                                            <?= substr($ag['hora_inicio'], 0, 5) ?> - <?= substr($ag['hora_fim'], 0, 5) ?>
                                        </span>
                                        <span class="font-bold text-xs text-slate-900"><?= htmlspecialchars($ag['paciente_nome']) ?></span>
                                        <span class="text-xs text-slate-500">• <?= htmlspecialchars($ag['modalidade_nome']) ?></span>
                                        <span class="text-xs text-slate-500">• Prof: <?= htmlspecialchars($ag['profissional_nome']) ?></span>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-1">
                                        <?= htmlspecialchars($ag['paciente_telefone']) ?>
                                        <?php if (!empty($ag['tipo_atendimento'])): ?>
                                            • <?= htmlspecialchars($ag['tipo_atendimento']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="acao" value="atualizar_status">
                                        <input type="hidden" name="agendamento_id" value="<?= $ag['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="text-xs py-1 px-2 bg-slate-50 border border-slate-300 rounded font-semibold text-slate-700 cursor-pointer">
                                            <option value="confirmado" <?= $ag['status'] === 'confirmado' ? 'selected' : '' ?>>Confirmado</option>
                                            <option value="realizado" <?= $ag['status'] === 'realizado' ? 'selected' : '' ?>>Presente</option>
                                            <option value="falta" <?= $ag['status'] === 'falta' ? 'selected' : '' ?>>Falta</option>
                                            <option value="cancelado" <?= $ag['status'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                        </select>
                                    </form>
                                    <?php if ($telLimpo): ?>
                                        <a href="https://wa.me/55<?= $telLimpo ?>?text=<?= $msgWhats ?>" target="_blank" class="px-2 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs rounded">WhatsApp</a>
                                    <?php endif; ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Excluir este agendamento?')">
                                        <input type="hidden" name="acao" value="excluir_agendamento">
                                        <input type="hidden" name="agendamento_id" value="<?= $ag['id'] ?>">
                                        <button type="submit" class="text-rose-600 hover:text-rose-800 text-xs font-semibold px-2 py-1">Excluir</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>

        <!-- ======================================================== -->
        <!-- ABA: VISÃO SEMANAL (OCUPAÇÃO, VAGAS E DIAS DA SEMANA)     -->
        <!-- ======================================================== -->
        <?php elseif ($abaAtiva === 'semanal'): 
            // Totais da semana inteira
            $totalSemana = count($agendamentosSemana);
            $totPilatesSem = 0;
            $totHidroSem = 0;
            $totFisioSem = 0;
            foreach ($agendamentosSemana as $asItem) {
                if ($asItem['modalidade_id'] === 'pilates') $totPilatesSem++;
                elseif ($asItem['modalidade_id'] === 'hidroginastica') $totHidroSem++;
                elseif ($asItem['modalidade_id'] === 'fisioterapia') $totFisioSem++;
            }
        ?>
            <div class="space-y-6">
                <!-- Painel Superior da Visão Semanal -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="p-2 bg-indigo-50 text-indigo-700 rounded-lg">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                            </span>
                            <div>
                                <h2 class="text-base font-bold text-slate-900">Visão Geral da Semana</h2>
                                <p class="text-xs text-slate-500">
                                    Semana de <strong class="text-slate-800"><?= date('d/m', $segundaTimestamp) ?></strong> a <strong class="text-slate-800"><?= date('d/m/Y', $sabadoTimestamp) ?></strong> • Segunda a Sábado
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Métricas da Semana -->
                    <div class="flex items-center gap-2 flex-wrap text-xs">
                        <div class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl">
                            <span class="text-slate-500 font-medium">Total:</span>
                            <strong class="ml-1 text-slate-900 font-bold"><?= $totalSemana ?></strong>
                        </div>
                        <div class="px-3 py-1.5 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl font-semibold flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            <?= $totPilatesSem ?> Pilates
                        </div>
                        <div class="px-3 py-1.5 bg-cyan-50 border border-cyan-200 text-cyan-800 rounded-xl font-semibold flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-cyan-500"></span>
                            <?= $totHidroSem ?> Hidro
                        </div>
                        <div class="px-3 py-1.5 bg-indigo-50 border border-indigo-200 text-indigo-800 rounded-xl font-semibold flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            <?= $totFisioSem ?> Fisioterapia
                        </div>
                    </div>
                </div>

                <!-- Grade com os 6 Dias Úteis da Semana (Segunda a Sábado) -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                    <?php foreach ($diasSemanaCards as $card): ?>
                    <div class="bg-white rounded-2xl border <?= $card['is_hoje'] ? 'border-indigo-400 ring-2 ring-indigo-200' : 'border-slate-200' ?> shadow-xs p-4 flex flex-col justify-between transition hover:shadow-md">
                        <div>
                            <!-- Cabeçalho do Card do Dia -->
                            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div>
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        <?= $card['dia_curto'] ?>
                                    </span>
                                    <div class="flex items-baseline gap-1 mt-0.5">
                                        <span class="text-2xl font-bold font-mono text-slate-900">
                                            <?= $card['dia_numero'] ?>
                                        </span>
                                        <span class="text-xs font-semibold text-slate-500 capitalize">
                                            <?= $card['mes_abreviado'] ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($card['is_hoje']): ?>
                                    <span class="px-2 py-0.5 bg-indigo-600 text-white rounded-full text-[10px] font-bold shadow-xs">
                                        Hoje
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Distribuição por Modalidades no Dia -->
                            <div class="space-y-1.5 py-3 text-xs">
                                <div class="flex items-center justify-between p-1.5 bg-emerald-50/60 rounded-lg text-emerald-900 border border-emerald-100">
                                    <span class="font-medium text-[11px] flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        Pilates
                                    </span>
                                    <strong class="font-mono text-xs"><?= $card['count_pilates'] ?></strong>
                                </div>

                                <div class="flex items-center justify-between p-1.5 bg-cyan-50/60 rounded-lg text-cyan-900 border border-cyan-100">
                                    <span class="font-medium text-[11px] flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-cyan-500"></span>
                                        Hidro
                                    </span>
                                    <strong class="font-mono text-xs"><?= $card['count_hidro'] ?></strong>
                                </div>

                                <div class="flex items-center justify-between p-1.5 bg-indigo-50/60 rounded-lg text-indigo-900 border border-indigo-100">
                                    <span class="font-medium text-[11px] flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                        Fisio
                                    </span>
                                    <strong class="font-mono text-xs"><?= $card['count_fisio'] ?></strong>
                                </div>
                            </div>

                            <!-- Barra de Ocupação Diária -->
                            <div class="pt-1 pb-3">
                                <div class="flex items-center justify-between text-[11px] mb-1">
                                    <span class="text-slate-500 font-medium">Ocupação:</span>
                                    <span class="font-bold font-mono text-slate-800"><?= $card['taxa_ocupacao'] ?>%</span>
                                </div>
                                <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                    <div class="h-1.5 rounded-full transition-all <?= $card['taxa_ocupacao'] >= 80 ? 'bg-amber-500' : 'bg-indigo-600' ?>" style="width: <?= $card['taxa_ocupacao'] ?>%"></div>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-1">
                                    <?= $card['total_agendados'] ?> alunos <?= $card['capacidade_total'] > 0 ? "de {$card['capacidade_total']} vagas" : '' ?>
                                </div>
                            </div>
                        </div>

                        <!-- Ações do Dia -->
                        <div class="pt-3 border-t border-slate-100 space-y-1.5">
                            <a href="index.php?data=<?= $card['data'] ?>&aba=diaria" class="w-full py-1.5 px-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold rounded-lg text-center block text-xs transition">
                                Abrir Dia &rarr;
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Quadro Detalhado da Semana -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">Quadro Completo de Atendimentos da Semana</h3>
                            <p class="text-xs text-slate-500">Todos os agendamentos confirmados e agendados entre Segunda e Sábado</p>
                        </div>
                        <span class="text-xs font-semibold px-2.5 py-1 bg-white border border-slate-200 rounded-lg text-slate-700">
                            <?= $totalSemana ?> atendimentos listados
                        </span>
                    </div>

                    <?php if (empty($agendamentosSemana)): ?>
                        <div class="p-10 text-center text-slate-500 text-xs">
                            Nenhum agendamento encontrado para esta semana. Use os botões acima para cadastrar novos agendamentos.
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 uppercase font-semibold">
                                    <tr>
                                        <th class="py-3 px-4">Data / Dia</th>
                                        <th class="py-3 px-4">Horário</th>
                                        <th class="py-3 px-4">Modalidade</th>
                                        <th class="py-3 px-4">Paciente / Aluno</th>
                                        <th class="py-3 px-4">Profissional</th>
                                        <th class="py-3 px-4">Status</th>
                                        <th class="py-3 px-4 text-right">Ação</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($agendamentosSemana as $agS): 
                                        $dObj = strtotime($agS['data']);
                                        $diaSemanaNumS = (int)date('w', $dObj);
                                        $diaNomeS = $nomesCurtosDias[$diaSemanaNumS] ?? '';
                                        $badgeMod = 'badge-' . ($agS['modalidade_id'] === 'fisioterapia' ? 'fisio' : ($agS['modalidade_id'] === 'hidroginastica' ? 'hidro' : 'pilates'));
                                    ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="py-3 px-4 font-semibold text-slate-800">
                                            <span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded font-bold mr-1"><?= $diaNomeS ?></span>
                                            <?= date('d/m', $dObj) ?>
                                        </td>
                                        <td class="py-3 px-4 font-mono font-bold text-slate-900">
                                            <?= substr($agS['hora_inicio'], 0, 5) ?> - <?= substr($agS['hora_fim'], 0, 5) ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold border <?= $badgeMod ?>">
                                                <?= htmlspecialchars($agS['modalidade_nome']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($agS['paciente_nome']) ?></div>
                                            <div class="text-[11px] text-slate-500"><?= htmlspecialchars($agS['paciente_telefone']) ?></div>
                                        </td>
                                        <td class="py-3 px-4 text-slate-700 font-medium">
                                            <?= htmlspecialchars($agS['profissional_nome']) ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="px-2 py-0.5 rounded text-[11px] font-semibold <?= $agS['status'] === 'realizado' ? 'bg-emerald-100 text-emerald-800' : ($agS['status'] === 'falta' ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700') ?>">
                                                <?= ucfirst($agS['status']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-right">
                                            <a href="index.php?data=<?= $agS['data'] ?>&aba=diaria" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold text-[11px] transition">
                                                Ver no Dia
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ======================================================== -->
        <!-- ABA 2: GESTÃO DE HORÁRIOS (GRADE & VAGAS)                 -->
        <!-- ======================================================== -->
        <?php elseif ($abaAtiva === 'grade'): 
            $diaGradeSel = (int)($_GET['dia'] ?? 1);
            $stmtG = $pdo->prepare("
                SELECT g.*, m.nome as modalidade_nome, p.nome as profissional_nome 
                FROM grade_horarios g 
                JOIN modalidades m ON g.modalidade_id = m.id 
                JOIN profissionais p ON g.profissional_id = p.id 
                WHERE g.dia_semana = ? 
                ORDER BY g.hora_inicio ASC
            ");
            $stmtG->execute([$diaGradeSel]);
            $slotsGrade = $stmtG->fetchAll();
            $dias = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 0 => 'Domingo'];
        ?>
            <div class="space-y-6">
                <!-- Seletor de Dia da Semana da Grade -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1">
                    <?php foreach ($dias as $num => $nome): ?>
                        <a href="index.php?aba=grade&dia=<?= $num ?>" class="py-2 px-4 rounded-xl text-xs font-bold transition whitespace-nowrap <?= $diaGradeSel === $num ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50' ?>">
                            <?= $nome ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Formulário para Adicionar Horário na Grade -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
                    <h3 class="text-sm font-bold text-slate-900 mb-1">
                        Adicionar Novo Horário na Grade Fixa de <?= $dias[$diaGradeSel] ?>
                    </h3>
                    <p class="text-xs text-slate-500 mb-4">
                        Defina a modalidade, capacidade máxima de alunos e profissional responsável.
                    </p>

                    <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 text-xs">
                        <input type="hidden" name="acao" value="adicionar_slot_grade">
                        <input type="hidden" name="dia_semana" value="<?= $diaGradeSel ?>">

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Modalidade *</label>
                            <select name="modalidade_id" id="gradeModalidade" onchange="ajustarCapacidadeModalidade(this.value)" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                                <option value="pilates">Pilates Studio (até 4 alunos)</option>
                                <option value="hidroginastica">Hidroginástica (até 12 alunos)</option>
                                <option value="fisioterapia">Fisioterapia (1:1 individual)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Profissional Responsável *</label>
                            <select name="profissional_id" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                                <?php foreach ($profissionais as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Início *</label>
                                <input type="time" name="hora_inicio" value="08:00" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg font-mono">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Fim *</label>
                                <input type="time" name="hora_fim" value="08:50" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg font-mono">
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Capacidade de Vagas *</label>
                            <input type="number" name="capacidade_maxima" id="gradeCapacidade" value="4" min="1" max="30" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                        </div>

                        <div class="sm:col-span-3">
                            <label class="block font-bold text-slate-700 mb-1">Sala / Local</label>
                            <input type="text" name="sala_local" value="Studio de Pilates - Sala 1" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-xs transition cursor-pointer">
                                Salvar Horário na Grade
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tabela de Horários Cadastrados para o Dia -->
                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-xs">
                    <div class="px-5 py-3.5 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                        <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider">
                            Horários Configurados para <?= $dias[$diaGradeSel] ?>
                        </h4>
                        <span class="text-xs text-slate-500 font-semibold">
                            <?= count($slotsGrade) ?> horário(s) ativo(s)
                        </span>
                    </div>

                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50/50 border-b border-slate-200 font-bold text-slate-600">
                            <tr>
                                <th class="py-3 px-4">Horário</th>
                                <th class="py-3 px-4">Modalidade</th>
                                <th class="py-3 px-4">Profissional</th>
                                <th class="py-3 px-4">Capacidade</th>
                                <th class="py-3 px-4">Sala / Local</th>
                                <th class="py-3 px-4 text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($slotsGrade)): ?>
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-slate-400 italic">
                                        Nenhum horário fixo cadastrado para <?= $dias[$diaGradeSel] ?> ainda. Preencha o formulário acima para adicionar.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($slotsGrade as $slot): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="py-3 px-4 font-mono font-bold text-slate-900">
                                        <?= substr($slot['hora_inicio'], 0, 5) ?> - <?= substr($slot['hora_fim'], 0, 5) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-semibold capitalize"><?= htmlspecialchars($slot['modalidade_nome']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-slate-700"><?= htmlspecialchars($slot['profissional_nome']) ?></td>
                                    <td class="py-3 px-4 font-bold text-indigo-700">
                                        <?= $slot['capacidade_maxima'] ?> <?= $slot['capacidade_maxima'] == 1 ? 'vaga' : 'vagas' ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-500"><?= htmlspecialchars($slot['sala_local']) ?></td>
                                    <td class="py-3 px-4 text-right">
                                        <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja remover este horário da grade?')">
                                            <input type="hidden" name="acao" value="excluir_slot_grade">
                                            <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
                                            <input type="hidden" name="dia_semana" value="<?= $diaGradeSel ?>">
                                            <button type="submit" class="text-rose-600 hover:text-rose-800 font-bold cursor-pointer">
                                                Excluir
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ======================================================== -->
        <!-- ABA 3: PACIENTES / ALUNOS (CADASTRO E GESTÃO COMPLETA)   -->
        <!-- ======================================================== -->
        <?php elseif ($abaAtiva === 'pacientes'): ?>
            <div class="space-y-6">
                <!-- Cabeçalho com Botão de Novo Paciente e Busca -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Cadastro de Pacientes e Alunos</h2>
                        <p class="text-xs text-slate-500">
                            Gerencie dados de contato, WhatsApp e histórico de restrições médicas para Pilates, Hidro e Fisioterapia.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button onclick="abrirModalPaciente()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                            + Cadastrar Novo Paciente / Aluno
                        </button>
                    </div>
                </div>

                <!-- Campo de Busca em Tempo Real -->
                <div class="flex items-center justify-between gap-4">
                    <div class="relative w-full max-w-sm">
                        <input type="text" id="filtroPacientes" onkeyup="filtrarTabelaPacientes()" placeholder="Buscar paciente por nome ou telefone..." class="w-full pl-9 pr-4 py-2 bg-white border border-slate-300 rounded-xl text-xs text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-emerald-500 shadow-2xs">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <span class="text-xs font-semibold text-slate-500">
                        Total: <strong><?= count($pacientes) ?></strong> cadastrado(s)
                    </span>
                </div>

                <!-- Tabela de Pacientes Cadastrados -->
                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-xs">
                    <table class="w-full text-left text-xs" id="tabelaPacientes">
                        <thead class="bg-slate-50 border-b border-slate-200 font-bold text-slate-600">
                            <tr>
                                <th class="py-3 px-4">Paciente / Aluno</th>
                                <th class="py-3 px-4">Histórico & Sessões</th>
                                <th class="py-3 px-4">Telefone / WhatsApp</th>
                                <th class="py-3 px-4">E-mail</th>
                                <th class="py-3 px-4">Modalidade Principal</th>
                                <th class="py-3 px-4">Observações Médicas / Restrições</th>
                                <th class="py-3 px-4 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($pacientes)): ?>
                                <tr>
                                    <td colspan="7" class="py-12 text-center text-slate-400">
                                        <p class="font-bold text-sm text-slate-700">Nenhum paciente cadastrado</p>
                                        <p class="text-xs mt-1 mb-4">Cadastre o primeiro paciente ou aluno para começar a agendar horários.</p>
                                        <button onclick="abrirModalPaciente()" class="px-4 py-2 bg-emerald-600 text-white rounded-xl font-bold cursor-pointer">
                                            + Cadastrar Primeiro Paciente
                                        </button>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pacientes as $pac): 
                                    $telLimpo = preg_replace('/\D/', '', $pac['telefone']);
                                    $stPac = $statsPacientesMap[(int)$pac['id']] ?? ['total' => 0, 'realizados' => 0, 'faltas' => 0, 'pendentes' => 0];
                                ?>
                                <tr class="hover:bg-slate-50 transition item-paciente">
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-slate-900 nome-paciente"><?= htmlspecialchars($pac['nome']) ?></div>
                                        <div class="text-[10px] text-slate-400">Cadastrado em <?= date('d/m/Y', strtotime($pac['criado_em'] ?? 'now')) ?></div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200" title="Atendimentos realizados com presença">
                                                ✓ <?= (int)$stPac['realizados'] ?> presenças
                                            </span>
                                            <?php if ((int)$stPac['faltas'] > 0): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-800 border border-rose-200" title="Faltas registradas">
                                                    ✗ <?= (int)$stPac['faltas'] ?> faltas
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((int)$stPac['pendentes'] > 0): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-800 border border-indigo-200" title="Atendimentos pendentes / futuros">
                                                    ⏳ <?= (int)$stPac['pendentes'] ?> pendentes
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 font-mono tel-paciente">
                                        <div class="flex items-center gap-1.5">
                                            <span><?= htmlspecialchars($pac['telefone']) ?></span>
                                            <?php if ($telLimpo): ?>
                                                <a href="https://wa.me/55<?= $telLimpo ?>" target="_blank" class="text-emerald-600 hover:text-emerald-700" title="Abrir conversa no WhatsApp">
                                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.299.144.35.49 1.199.533 1.286.044.087.073.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.861.174.086.275.072.376-.044.102-.115.434-.506.549-.68.116-.173.232-.144.39-.087.159.058 1.011.477 1.184.564.173.087.289.13.332.202.043.073.043.419-.101.824z"/></svg>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-slate-500"><?= htmlspecialchars($pac['email'] ?: '-') ?></td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold capitalize <?= ($pac['modalidade_interesse'] ?? '') === 'hidroginastica' ? 'badge-hidro' : (($pac['modalidade_interesse'] ?? '') === 'fisioterapia' ? 'badge-fisio' : 'badge-pilates') ?>">
                                            <?= htmlspecialchars($pac['modalidade_interesse'] ?? 'Pilates') ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 max-w-xs">
                                        <?php if (!empty($pac['observacoes_medicas'])): ?>
                                            <span class="text-amber-800 bg-amber-50 px-2 py-1 rounded border border-amber-200 block text-[11px] leading-tight">
                                                <?= htmlspecialchars($pac['observacoes_medicas']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-400 italic">Nenhuma restrição registrada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2">
                                            <!-- Ver Histórico Completo Individual -->
                                            <button onclick="abrirHistoricoPaciente(<?= (int)$pac['id'] ?>, <?= htmlspecialchars(json_encode($pac)) ?>)" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded-lg font-bold text-[11px] transition cursor-pointer flex items-center gap-1" title="Ver histórico completo de aulas realizadas, faltas e observações em ordem cronológica">
                                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                                                Histórico Completo
                                            </button>

                                            <!-- Agendar Direto para este paciente -->
                                            <button onclick="agendarParaPaciente(<?= htmlspecialchars(json_encode($pac)) ?>)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold text-[11px] transition cursor-pointer" title="Criar agendamento para este paciente">
                                                + Agendar
                                            </button>

                                            <!-- Editar Paciente -->
                                            <button onclick="editarPaciente(<?= htmlspecialchars(json_encode($pac)) ?>)" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold text-[11px] transition cursor-pointer">
                                                Editar
                                            </button>

                                            <!-- Excluir Paciente -->
                                            <form method="POST" class="inline" onsubmit="return confirm('Deseja realmente excluir o cadastro de <?= htmlspecialchars($pac['nome']) ?>?')">
                                                <input type="hidden" name="acao" value="excluir_paciente">
                                                <input type="hidden" name="paciente_id" value="<?= $pac['id'] ?>">
                                                <button type="submit" class="text-rose-600 hover:text-rose-800 font-semibold text-[11px] px-1 py-1 cursor-pointer">
                                                    Excluir
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ======================================================== -->
        <!-- ABA 4: PROFISSIONAIS & EQUIPE TÉCNICA                    -->
        <!-- ======================================================== -->
        <?php elseif ($abaAtiva === 'profissionais'): 
            $totalProfs = count($todosProfissionais);
            $totalAtivos = 0;
            foreach ($todosProfissionais as $tp) {
                if ((int)$tp['ativo'] === 1) $totalAtivos++;
            }
            $totalInativos = $totalProfs - $totalAtivos;
        ?>
            <div class="space-y-6">
                <!-- Cabeçalho com Métricas e Botão de Novo Profissional -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="p-2 bg-indigo-50 text-indigo-700 rounded-lg">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            </span>
                            <div>
                                <h2 class="text-base font-bold text-slate-900">Equipe Técnica e Profissionais</h2>
                                <p class="text-xs text-slate-500">
                                    Fisioterapeutas, instrutores de Pilates Studio e professores de Hidroginástica credenciados.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex items-center gap-2 text-xs">
                            <span class="px-2.5 py-1 bg-slate-50 border border-slate-200 rounded-lg font-semibold text-slate-600">
                                Total: <strong class="text-slate-900"><?= $totalProfs ?></strong>
                            </span>
                            <span class="px-2.5 py-1 bg-emerald-50 border border-emerald-200 rounded-lg font-semibold text-emerald-800">
                                Ativos: <strong><?= $totalAtivos ?></strong>
                            </span>
                            <?php if ($totalInativos > 0): ?>
                            <span class="px-2.5 py-1 bg-slate-100 border border-slate-200 rounded-lg font-semibold text-slate-500">
                                Inativos: <strong><?= $totalInativos ?></strong>
                            </span>
                            <?php endif; ?>
                        </div>

                        <button onclick="abrirModalProfissional()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            + Cadastrar Novo Profissional
                        </button>
                    </div>
                </div>

                <!-- Barra de Pesquisa Rápida e Filtros de Status Ativo/Inativo -->
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                    <div class="relative w-full max-w-sm">
                        <input type="text" id="filtroProfissionais" onkeyup="filtrarTabelaProfissionais()" placeholder="Buscar por nome, especialidade ou registro..." class="w-full pl-9 pr-4 py-2 bg-white border border-slate-300 rounded-xl text-xs text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500 shadow-2xs">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <!-- Botões de Filtro: Ativos (padrão oculta inativos) / Inativos / Todos -->
                    <div class="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200 self-start sm:self-auto">
                        <button type="button" id="btnProfAtivos" onclick="setFiltroProfissionalStatus('ativos')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer bg-white text-indigo-700 shadow-xs border border-indigo-100">
                            Ativos (<?= $totalAtivos ?>)
                        </button>
                        <button type="button" id="btnProfInativos" onclick="setFiltroProfissionalStatus('inativos')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer text-slate-600 hover:text-slate-900">
                            Inativos (<?= $totalInativos ?>)
                        </button>
                        <button type="button" id="btnProfTodos" onclick="setFiltroProfissionalStatus('todos')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer text-slate-600 hover:text-slate-900">
                            Todos (<?= $totalProfs ?>)
                        </button>
                    </div>
                </div>

                <!-- Alerta Contextual de Ocultação de Inativos -->
                <div id="bannerProfInativosOcultos" class="<?= $totalInativos > 0 ? '' : 'hidden' ?> px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between gap-3 text-xs text-slate-600">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                        <span>Exibindo apenas <strong><?= $totalAtivos ?></strong> profissional(is) ativo(s). Há <strong><?= $totalInativos ?></strong> profissional(is) inativo(s) oculto(s).</span>
                    </div>
                    <button type="button" onclick="setFiltroProfissionalStatus('inativos')" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 underline cursor-pointer">
                        Ver profissionais inativos
                    </button>
                </div>

                <div id="bannerProfApenasInativos" class="hidden px-4 py-2.5 bg-amber-50/80 border border-amber-200 rounded-xl flex items-center justify-between gap-3 text-xs text-amber-900">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span><strong>Filtro de Inativos:</strong> Exibindo profissionais desativados. Eles não aparecem na criação de novos agendamentos na grade.</span>
                    </div>
                    <button type="button" onclick="setFiltroProfissionalStatus('ativos')" class="text-xs font-bold text-amber-900 hover:text-amber-950 underline cursor-pointer">
                        Voltar para Ativos
                    </button>
                </div>

                <!-- Grid de Profissionais -->
                <?php if (empty($todosProfissionais)): ?>
                    <div class="bg-white p-12 rounded-2xl border border-slate-200 shadow-xs text-center">
                        <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <h3 class="text-sm font-bold text-slate-800 mb-1">Nenhum profissional cadastrado</h3>
                        <p class="text-xs text-slate-500 mb-4">Cadastre os profissionais da clínica para associar às modalidades e horários da grade.</p>
                        <button onclick="abrirModalProfissional()" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold shadow-xs">
                            Cadastrar Primeiro Profissional
                        </button>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5" id="gridProfissionais">
                        <?php foreach ($todosProfissionais as $prof): 
                            $telLimpo = preg_replace('/\D/', '', $prof['telefone'] ?? '');
                            $mods = array_map('trim', explode(',', $prof['modalidades'] ?? ''));
                            // Iniciais do nome
                            $partesNome = explode(' ', trim($prof['nome']));
                            $iniciais = strtoupper(substr($partesNome[0], 0, 1) . (isset($partesNome[1]) ? substr($partesNome[1], 0, 1) : ''));
                        ?>
                        <div class="item-profissional bg-white rounded-2xl border <?= $prof['ativo'] ? 'border-slate-200' : 'border-slate-200 opacity-75 bg-slate-50/70' ?> shadow-xs p-5 flex flex-col justify-between transition hover:shadow-md" data-ativo="<?= (int)$prof['ativo'] ?>">
                            <div>
                                <!-- Topo do Card com Avatar e Status -->
                                <div class="flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
                                    <div class="flex items-center gap-3">
                                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-white font-bold text-sm flex items-center justify-center shadow-xs">
                                            <?= $iniciais ?: 'PR' ?>
                                        </div>
                                        <div>
                                            <h3 class="nome-profissional text-sm font-bold text-slate-900 leading-tight">
                                                <?= htmlspecialchars($prof['nome']) ?>
                                            </h3>
                                            <div class="registro-profissional inline-block mt-1 font-mono text-[11px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200">
                                                <?= htmlspecialchars($prof['registro_profissional']) ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <?php if ($prof['ativo']): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                Ativo
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-600">
                                                Inativo
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Especialidade e Modalidades -->
                                <div class="py-3 space-y-2 text-xs">
                                    <div>
                                        <span class="text-[11px] font-semibold text-slate-400 block">Especialidade Clínica</span>
                                        <span class="especialidade-profissional font-medium text-slate-800">
                                            <?= htmlspecialchars($prof['especialidade'] ?: 'Profissional da Saúde') ?>
                                        </span>
                                    </div>

                                    <div>
                                        <span class="text-[11px] font-semibold text-slate-400 block mb-1">Modalidades que Atende</span>
                                        <div class="flex flex-wrap gap-1.5">
                                            <?php foreach ($mods as $m): 
                                                if (!$m) continue;
                                                $badgeClass = ($m === 'fisioterapia') ? 'badge-fisio' : (($m === 'hidroginastica') ? 'badge-hidro' : 'badge-pilates');
                                                $label = ($m === 'fisioterapia') ? 'Fisioterapia' : (($m === 'hidroginastica') ? 'Hidroginástica' : 'Pilates Studio');
                                            ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $badgeClass ?>">
                                                    <?= $label ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Contato -->
                                    <div class="pt-2 border-t border-slate-100 space-y-1 text-slate-600 text-[11px]">
                                        <?php if (!empty($prof['telefone'])): ?>
                                            <div class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                                <span><?= htmlspecialchars($prof['telefone']) ?></span>
                                                <?php if ($telLimpo): ?>
                                                    <a href="https://wa.me/55<?= $telLimpo ?>" target="_blank" class="text-emerald-700 hover:text-emerald-900 font-bold ml-1">WhatsApp &nearr;</a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($prof['email'])): ?>
                                            <div class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                                <a href="mailto:<?= htmlspecialchars($prof['email']) ?>" class="hover:underline text-slate-600 truncate">
                                                    <?= htmlspecialchars($prof['email']) ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Botões de Ação do Card -->
                            <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-2">
                                    <button onclick="editarProfissional(<?= htmlspecialchars(json_encode($prof)) ?>)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold transition cursor-pointer">
                                        Editar
                                    </button>

                                    <!-- Alternar Status Ativo/Inativo -->
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="acao" value="toggle_ativo_profissional">
                                        <input type="hidden" name="profissional_id" value="<?= $prof['id'] ?>">
                                        <button type="submit" class="px-2 py-1.5 text-[11px] <?= $prof['ativo'] ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50' ?> rounded-lg font-semibold transition cursor-pointer" title="<?= $prof['ativo'] ? 'Desativar profissional' : 'Ativar profissional' ?>">
                                            <?= $prof['ativo'] ? 'Desativar' : 'Ativar' ?>
                                        </button>
                                    </form>
                                </div>

                                <form method="POST" class="inline" onsubmit="return confirm('Deseja realmente excluir o cadastro de <?= htmlspecialchars($prof['nome']) ?>? Caso possua agendamentos, o sistema irá apenas desativá-lo para manter o histórico.')">
                                    <input type="hidden" name="acao" value="excluir_profissional">
                                    <input type="hidden" name="profissional_id" value="<?= $prof['id'] ?>">
                                    <button type="submit" class="text-rose-600 hover:text-rose-800 text-xs font-semibold px-2 py-1.5 cursor-pointer">
                                        Excluir
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($abaAtiva === 'usuarios'): 
            $totalUsers = count($todosUsuarios);
            $totalAdmins = 0;
            $totalSecretarias = 0;
            $totalProfessores = 0;
            $totalAlunos = 0;
            $totalUsersAtivos = 0;
            foreach ($todosUsuarios as $u) {
                if ($u['papel'] === 'admin') $totalAdmins++;
                elseif ($u['papel'] === 'secretaria') $totalSecretarias++;
                elseif ($u['papel'] === 'professor') $totalProfessores++;
                elseif ($u['papel'] === 'aluno') $totalAlunos++;
                if ((int)$u['ativo'] === 1) $totalUsersAtivos++;
            }
            $totalUsersInativos = $totalUsers - $totalUsersAtivos;
        ?>
            <div class="space-y-6">
                <!-- Cabeçalho com Métricas e Botão de Novo Usuário -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="p-2 bg-purple-50 text-purple-700 rounded-lg">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            </span>
                            <div>
                                <h2 class="text-base font-bold text-slate-900">Gestão de Usuários e Acessos</h2>
                                <p class="text-xs text-slate-500">
                                    Controle central de credenciais, alteração de senhas, permissões e perfis de acesso.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 flex-wrap">
                        <button onclick="abrirModalUsuario()" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                            + Cadastrar Novo Usuário
                        </button>
                    </div>
                </div>

                <!-- Métricas Rápidas de Perfis -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <div class="bg-white p-3.5 rounded-xl border border-slate-200 shadow-2xs">
                        <span class="text-[11px] font-semibold text-slate-500 block">Total Geral</span>
                        <span class="text-lg font-extrabold text-slate-900"><?= $totalUsers ?></span>
                    </div>
                    <div class="bg-indigo-50/60 p-3.5 rounded-xl border border-indigo-100">
                        <span class="text-[11px] font-semibold text-indigo-700 block">Administradores</span>
                        <span class="text-lg font-extrabold text-indigo-900"><?= $totalAdmins ?></span>
                    </div>
                    <div class="bg-teal-50/60 p-3.5 rounded-xl border border-teal-100">
                        <span class="text-[11px] font-semibold text-teal-700 block">Secretárias</span>
                        <span class="text-lg font-extrabold text-teal-900"><?= $totalSecretarias ?></span>
                    </div>
                    <div class="bg-sky-50/60 p-3.5 rounded-xl border border-sky-100">
                        <span class="text-[11px] font-semibold text-sky-700 block">Professores</span>
                        <span class="text-lg font-extrabold text-sky-900"><?= $totalProfessores ?></span>
                    </div>
                    <div class="bg-amber-50/60 p-3.5 rounded-xl border border-amber-100">
                        <span class="text-[11px] font-semibold text-amber-700 block">Alunos / Clientes</span>
                        <span class="text-lg font-extrabold text-amber-900"><?= $totalAlunos ?></span>
                    </div>
                    <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                        <span class="text-[11px] font-semibold text-slate-500 block">Ativos / Inativos</span>
                        <span class="text-lg font-extrabold text-slate-900"><?= $totalUsersAtivos ?> <span class="text-xs font-normal text-slate-400">/ <?= $totalUsersInativos ?></span></span>
                    </div>
                </div>

                <!-- Barra de Pesquisa e Filtros de Papel e Status -->
                <div class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
                    <div class="relative w-full max-w-sm">
                        <input type="text" id="filtroUsuarios" onkeyup="filtrarTabelaUsuarios()" placeholder="Buscar por nome, e-mail ou telefone..." class="w-full pl-9 pr-4 py-2 bg-white border border-slate-300 rounded-xl text-xs text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-purple-500 shadow-2xs">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <!-- Filtros por Papel -->
                        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200 text-xs">
                            <button type="button" onclick="setFiltroUsuarioPapel('todos')" id="btnUserPapelTodos" class="px-2.5 py-1 rounded-lg font-bold transition bg-white text-purple-700 shadow-xs cursor-pointer">Todos</button>
                            <button type="button" onclick="setFiltroUsuarioPapel('admin')" id="btnUserPapelAdmin" class="px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer">Admin</button>
                            <button type="button" onclick="setFiltroUsuarioPapel('secretaria')" id="btnUserPapelSec" class="px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer">Secretária</button>
                            <button type="button" onclick="setFiltroUsuarioPapel('professor')" id="btnUserPapelProf" class="px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer">Professor</button>
                            <button type="button" onclick="setFiltroUsuarioPapel('aluno')" id="btnUserPapelAluno" class="px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer">Aluno</button>
                        </div>

                        <!-- Filtros por Status -->
                        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200 text-xs">
                            <button type="button" onclick="setFiltroUsuarioStatus('todos')" id="btnUserStatusTodos" class="px-2.5 py-1 rounded-lg font-bold transition bg-white text-purple-700 shadow-xs cursor-pointer">Todos</button>
                            <button type="button" onclick="setFiltroUsuarioStatus('ativos')" id="btnUserStatusAtivos" class="px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer">Ativos</button>
                            <button type="button" onclick="setFiltroUsuarioStatus('inativos')" id="btnUserStatusInativos" class="px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer">Inativos</button>
                        </div>
                    </div>
                </div>

                <!-- Grid de Usuários -->
                <?php if (empty($todosUsuarios)): ?>
                    <div class="bg-white p-12 rounded-2xl border border-slate-200 shadow-xs text-center">
                        <h3 class="text-sm font-bold text-slate-800 mb-1">Nenhum usuário cadastrado</h3>
                        <p class="text-xs text-slate-500 mb-4">Cadastre usuários com diferentes perfis para acessar o sistema.</p>
                        <button onclick="abrirModalUsuario()" class="px-4 py-2 bg-purple-600 text-white rounded-xl text-xs font-bold shadow-xs">
                            Cadastrar Primeiro Usuário
                        </button>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5" id="gridUsuarios">
                        <?php foreach ($todosUsuarios as $u): 
                            $telLimpo = preg_replace('/\D/', '', $u['telefone'] ?? '');
                            $partesNome = explode(' ', trim($u['nome']));
                            $iniciais = strtoupper(substr($partesNome[0], 0, 1) . (isset($partesNome[1]) ? substr($partesNome[1], 0, 1) : ''));
                            $isEu = ((int)$u['id'] === (int)($usuarioLogado['id'] ?? 0));
                        ?>
                        <div class="item-usuario bg-white rounded-2xl border <?= $u['ativo'] ? 'border-slate-200' : 'border-slate-200 opacity-70 bg-slate-50/70' ?> shadow-xs p-5 flex flex-col justify-between transition hover:shadow-md" data-papel="<?= $u['papel'] ?>" data-ativo="<?= (int)$u['ativo'] ?>">
                            <div>
                                <div class="flex items-start justify-between gap-3 pb-3 border-b border-slate-100">
                                    <div class="flex items-center gap-3">
                                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?= $u['papel'] === 'admin' ? 'from-purple-600 to-indigo-700' : ($u['papel'] === 'secretaria' ? 'from-teal-500 to-teal-700' : ($u['papel'] === 'professor' ? 'from-sky-500 to-sky-700' : 'from-amber-500 to-amber-700')) ?> text-white font-bold text-sm flex items-center justify-center shadow-xs">
                                            <?= $iniciais ?: 'U' ?>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <h3 class="nome-usuario text-sm font-bold text-slate-900 leading-tight">
                                                    <?= htmlspecialchars($u['nome']) ?>
                                                </h3>
                                                <?php if ($isEu): ?>
                                                    <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-indigo-100 text-indigo-800">Você</span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="email-usuario text-xs text-slate-500 block truncate mt-0.5">
                                                <?= htmlspecialchars($u['email']) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <?php if ($u['ativo']): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">Ativo</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-600">Inativo</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="py-3 space-y-2 text-xs">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] font-semibold text-slate-400">Perfil de Acesso:</span>
                                        <?php if ($u['papel'] === 'admin'): ?>
                                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">Administrador</span>
                                        <?php elseif ($u['papel'] === 'secretaria'): ?>
                                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-teal-50 text-teal-700 border border-teal-200">Secretária / Recepção</span>
                                        <?php elseif ($u['papel'] === 'professor'): ?>
                                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-sky-50 text-sky-700 border border-sky-200">Professor / Instrutor</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Aluno / Cliente</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($u['telefone'])): ?>
                                        <div class="flex items-center justify-between text-[11px] text-slate-600">
                                            <span class="text-slate-400 font-semibold">Telefone:</span>
                                            <span class="tel-usuario font-medium">
                                                <?= htmlspecialchars($u['telefone']) ?>
                                                <?php if ($telLimpo): ?>
                                                    <a href="https://wa.me/55<?= $telLimpo ?>" target="_blank" class="text-emerald-700 hover:text-emerald-900 font-bold ml-1">&nearr;</a>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($u['profissional_nome'])): ?>
                                        <div class="text-[11px] bg-sky-50/70 p-2 rounded-lg border border-sky-100 text-sky-900">
                                            Vinculado ao Profissional: <strong><?= htmlspecialchars($u['profissional_nome']) ?></strong>
                                        </div>
                                    <?php elseif (!empty($u['paciente_nome'])): ?>
                                        <div class="text-[11px] bg-amber-50/70 p-2 rounded-lg border border-amber-100 text-amber-900">
                                            Vinculado ao Aluno: <strong><?= htmlspecialchars($u['paciente_nome']) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Botões de Ação do Usuário -->
                            <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <button onclick="abrirModalAlterarSenha(<?= htmlspecialchars(json_encode($u)) ?>)" class="px-2.5 py-1.5 bg-purple-50 hover:bg-purple-100 text-purple-800 border border-purple-200 rounded-lg font-bold transition cursor-pointer flex items-center gap-1" title="Definir nova senha">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                        Senha
                                    </button>

                                    <button onclick="editarUsuario(<?= htmlspecialchars(json_encode($u)) ?>)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold transition cursor-pointer">
                                        Editar
                                    </button>

                                    <?php if (!$isEu): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="acao" value="toggle_usuario_ativo">
                                            <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="px-2 py-1.5 text-[11px] <?= $u['ativo'] ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50' ?> rounded-lg font-semibold transition cursor-pointer" title="<?= $u['ativo'] ? 'Desativar usuário' : 'Ativar usuário' ?>">
                                                <?= $u['ativo'] ? 'Desativar' : 'Ativar' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <?php if (!$isEu): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Deseja realmente excluir o acesso de <?= htmlspecialchars($u['nome']) ?>?')">
                                        <input type="hidden" name="acao" value="excluir_usuario">
                                        <input type="hidden" name="usuario_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="text-rose-600 hover:text-rose-800 text-xs font-semibold px-2 py-1.5 cursor-pointer">
                                            Excluir
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- ======================================================== -->
    <!-- MODAL 1: NOVO / EDITAR PACIENTE (ALUNO)                  -->
    <!-- ======================================================== -->
    <div id="modalPaciente" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50">
                <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2" id="modalPacienteTitulo">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    Cadastrar Novo Paciente / Aluno
                </h3>
                <button type="button" onclick="fecharModalPaciente()" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" class="p-6 space-y-4 text-xs" id="formPaciente">
                <input type="hidden" name="acao" id="formPacienteAcao" value="novo_paciente">
                <input type="hidden" name="paciente_id" id="formPacienteId" value="">
                <input type="hidden" name="retorno_aba" value="<?= $abaAtiva ?>">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nome Completo *</label>
                    <input type="text" name="nome" id="pacNome" required placeholder="Ex: Patrícia de Souza" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Telefone / WhatsApp *</label>
                        <input type="text" name="telefone" id="pacTelefone" required placeholder="(11) 99999-9999" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">E-mail</label>
                        <input type="email" name="email" id="pacEmail" placeholder="paciente@email.com" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Data de Nascimento</label>
                        <input type="date" name="data_nascimento" id="pacNascimento" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Modalidade Principal</label>
                        <select name="modalidade_interesse" id="pacModalidade" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                            <option value="pilates">Pilates Studio</option>
                            <option value="hidroginastica">Hidroginástica</option>
                            <option value="fisioterapia">Fisioterapia</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Observações Médicas / Restrições Clínicas</label>
                    <textarea name="observacoes_medicas" id="pacObs" rows="3" placeholder="Ex: Hérnia de disco L4-L5, pós-operatório de joelho, labirintite, gestante sem restrições..." class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs"></textarea>
                    <p class="text-[10px] text-slate-400 mt-1">Essas notas serão visualizadas pelo profissional nos horários da agenda diária.</p>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="fecharModalPaciente()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold shadow-xs">
                        Salvar Paciente
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL 2: NOVO AGENDAMENTO                                -->
    <!-- ======================================================== -->
    <div id="modalAgendamento" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50">
                <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <?= $ehAluno ? 'Agendar Minha Aula / Sessão' : 'Novo Agendamento na Clínica' ?>
                </h3>
                <button type="button" onclick="fecharModalAgendamento()" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" id="formNovoAgendamento" onsubmit="return validarFormAgendamento(this)" class="p-6 space-y-4 text-xs">
                <input type="hidden" name="acao" value="novo_agendamento">
                <input type="hidden" name="grade_horario_id" id="modalGradeId" value="">

                <!-- Paciente / Aluno -->
                <?php if ($ehAluno): ?>
                    <input type="hidden" name="paciente_id" value="<?= $pacienteIdVinculado ?>">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Aluno / Paciente</label>
                        <div class="p-2.5 bg-slate-100 border border-slate-200 rounded-lg text-slate-800 font-semibold flex items-center justify-between">
                            <span class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-amber-500 text-white font-bold text-xs flex items-center justify-center">
                                    <?= $inicialUsuario ?>
                                </span>
                                <span><?= htmlspecialchars($nomeUsuarioAtual) ?></span>
                            </span>
                            <span class="text-[10px] px-2 py-0.5 bg-amber-100 text-amber-800 rounded font-bold border border-amber-200">Você (Logado)</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="font-bold text-slate-700">Paciente / Aluno *</label>
                            <?php if ($podeGerenciarPacientes): ?>
                            <button type="button" onclick="fecharModalAgendamento(); abrirModalPaciente();" class="text-[11px] text-emerald-700 font-bold hover:underline cursor-pointer">
                                + Cadastrar Novo Paciente Agora
                            </button>
                            <?php endif; ?>
                        </div>
                        <select name="paciente_id" id="modalPacienteId" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                            <option value="">Selecione o paciente cadastrado...</option>
                            <?php foreach ($pacientes as $pac): ?>
                                <option value="<?= $pac['id'] ?>"><?= htmlspecialchars($pac['nome']) ?> (<?= htmlspecialchars($pac['telefone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Modalidade -->
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Modalidade do Serviço *</label>
                    <select name="modalidade_id" id="modalModalidadeId" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                        <option value="pilates">Pilates Studio (até 4 alunos)</option>
                        <option value="hidroginastica">Hidroginástica (até 12 alunos)</option>
                        <option value="fisioterapia">Fisioterapia (1:1 individual)</option>
                    </select>
                </div>

                <!-- Data e Horário -->
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Data *</label>
                        <input type="date" name="data" id="modalData" min="<?= date('Y-m-d') ?>" value="<?= ($dataSelecionada < date('Y-m-d')) ? date('Y-m-d') : $dataSelecionada ?>" onchange="const h=getHojeFormatadoISO(); if(this.value && this.value < h){ alert('A data do agendamento não pode ser anterior ao dia de hoje.'); this.value=h; }" onblur="const h=getHojeFormatadoISO(); if(!this.value || this.value < h){ this.value=h; }" required class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Início *</label>
                        <input type="time" name="hora_inicio" id="modalHoraInicio" value="08:00" required class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Fim *</label>
                        <input type="time" name="hora_fim" id="modalHoraFim" value="08:50" required class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg font-mono">
                    </div>
                </div>

                <!-- Profissional -->
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Profissional Responsável *</label>
                    <select name="profissional_id" id="modalProfissionalId" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                        <?php foreach ($profissionais as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?> (<?= htmlspecialchars($p['especialidade']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Tipo de Atendimento e Observações -->
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Tipo de Atendimento</label>
                    <input type="text" name="tipo_atendimento" placeholder="Ex: Aula Avulsa, Mensalista, Avaliação Inicial" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Observações Internas</label>
                    <textarea name="observacao" rows="2" placeholder="Anotações para a recepção ou equipe clínica..." class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg"></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="fecharModalAgendamento()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold shadow-xs">
                        Salvar Agendamento
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL 3: NOVO / EDITAR PROFISSIONAL                      -->
    <!-- ======================================================== -->
    <div id="modalProfissional" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50">
                <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2" id="modalProfissionalTitulo">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Cadastrar Novo Profissional
                </h3>
                <button type="button" onclick="fecharModalProfissional()" class="text-slate-400 hover:text-slate-600 font-bold">✕</button>
            </div>

            <form method="POST" class="p-6 space-y-4 text-xs" id="formProfissional">
                <input type="hidden" name="acao" id="formProfissionalAcao" value="novo_profissional">
                <input type="hidden" name="profissional_id" id="formProfissionalId" value="">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nome Completo *</label>
                    <input type="text" name="nome" id="profNome" required placeholder="Ex: Dra. Camila Rocha" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Registro Profissional *</label>
                        <input type="text" name="registro_profissional" id="profRegistro" required placeholder="Ex: CREFITO 12345-F ou CREF 9876-G" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Especialidade Clínica *</label>
                        <input type="text" name="especialidade" id="profEspecialidade" required placeholder="Ex: Fisioterapia Ortopédica e Coluna" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Telefone / WhatsApp *</label>
                        <input type="text" name="telefone" id="profTelefone" required placeholder="(11) 98888-7777" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">E-mail Profissional</label>
                        <input type="email" name="email" id="profEmail" placeholder="profissional@clinica.com" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-2">Modalidades que Atende *</label>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="flex items-center gap-2 p-2.5 bg-emerald-50/70 border border-emerald-200 rounded-lg cursor-pointer">
                            <input type="checkbox" name="modalidades[]" value="pilates" id="checkModPilates" class="rounded text-emerald-600 focus:ring-emerald-500">
                            <span class="font-bold text-emerald-900 text-[11px]">Pilates Studio</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 bg-cyan-50/70 border border-cyan-200 rounded-lg cursor-pointer">
                            <input type="checkbox" name="modalidades[]" value="hidroginastica" id="checkModHidro" class="rounded text-cyan-600 focus:ring-cyan-500">
                            <span class="font-bold text-cyan-900 text-[11px]">Hidroginástica</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 bg-indigo-50/70 border border-indigo-200 rounded-lg cursor-pointer">
                            <input type="checkbox" name="modalidades[]" value="fisioterapia" id="checkModFisio" class="rounded text-indigo-600 focus:ring-indigo-500">
                            <span class="font-bold text-indigo-900 text-[11px]">Fisioterapia</span>
                        </label>
                    </div>
                </div>

                <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg flex items-center justify-between">
                    <div>
                        <span class="font-bold text-slate-800 block text-xs">Profissional Ativo na Clínica</span>
                        <span class="text-[11px] text-slate-500">Profissionais ativos aparecem na grade de horários e nos novos agendamentos.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="ativo" id="profAtivo" value="1" checked class="sr-only peer">
                        <div class="w-9 h-5 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="fecharModalProfissional()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold shadow-xs">
                        Salvar Profissional
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL 4: HISTÓRICO DE AGENDAMENTOS DO PACIENTE           -->
    <!-- ======================================================== -->
    <div id="modalHistoricoPaciente" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-3xl w-full max-h-[90vh] flex flex-col overflow-hidden">
            <!-- Topo do Modal de Histórico -->
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-white font-bold text-sm flex items-center justify-center shadow-xs shrink-0" id="histAvatar">
                        PA
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-slate-900" id="histNomePaciente">Nome do Paciente</h3>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-indigo-100 text-indigo-800" id="histTotalSessoes">
                                0 sessões
                            </span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-slate-500 mt-1 flex-wrap">
                            <span class="font-mono text-[11px]" id="histTelefone">-</span>
                            <span id="histWhatsAppLink"></span>
                            <span id="histEmail" class="text-slate-400"></span>
                        </div>
                    </div>
                </div>
                <button type="button" onclick="fecharHistoricoPaciente()" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg font-bold text-sm">✕</button>
            </div>

            <!-- Corpo Rolável -->
            <div class="p-6 overflow-y-auto space-y-4">
                <!-- Banner de Restrições Médicas -->
                <div id="histRestricoesBanner" class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 hidden">
                    <strong class="block font-bold mb-0.5">⚠️ Restrições e Recomendações Médicas:</strong>
                    <p id="histRestricoesTexto" class="text-amber-800"></p>
                </div>

                <!-- 4 Indicadores Rápidos -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                    <div class="bg-emerald-50/70 border border-emerald-200 rounded-xl p-3">
                        <span class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider block">Aulas Realizadas</span>
                        <span class="text-2xl font-bold font-mono text-emerald-800 mt-0.5 block" id="histStatRealizados">0</span>
                        <span class="text-[10px] text-emerald-600 font-bold" id="histStatAssiduidade">100% presenças</span>
                    </div>
                    <div class="bg-rose-50/70 border border-rose-200 rounded-xl p-3">
                        <span class="text-[10px] font-bold text-rose-700 uppercase tracking-wider block">Faltas Registradas</span>
                        <span class="text-2xl font-bold font-mono text-rose-800 mt-0.5 block" id="histStatFaltas">0</span>
                        <span class="text-[10px] text-rose-600 font-medium">ausências</span>
                    </div>
                    <div class="bg-cyan-50/70 border border-cyan-200 rounded-xl p-3">
                        <span class="text-[10px] font-bold text-cyan-700 uppercase tracking-wider block">Observações Clínicas</span>
                        <span class="text-2xl font-bold font-mono text-cyan-800 mt-0.5 block" id="histStatObservacoes">0</span>
                        <span class="text-[10px] text-cyan-600 font-medium">evoluções / notas</span>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total no Histórico</span>
                        <span class="text-2xl font-bold font-mono text-slate-800 mt-0.5 block" id="histStatTotal">0</span>
                        <span class="text-[10px] text-slate-500 font-medium">registros</span>
                    </div>
                </div>

                <!-- Botões de Filtro -->
                <div class="flex items-center justify-between border-b border-slate-100 pb-2 flex-wrap gap-2">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <button onclick="filtrarHistoricoStatus('todos')" id="btnFiltroHistTodos" class="px-3 py-1 rounded-lg text-xs font-bold bg-indigo-600 text-white cursor-pointer">
                            Todos
                        </button>
                        <button onclick="filtrarHistoricoStatus('realizado')" id="btnFiltroHistRealizado" class="px-3 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 cursor-pointer">
                            Aulas Realizadas
                        </button>
                        <button onclick="filtrarHistoricoStatus('falta')" id="btnFiltroHistFalta" class="px-3 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 cursor-pointer">
                            Faltas
                        </button>
                        <button onclick="filtrarHistoricoStatus('observacao')" id="btnFiltroHistObs" class="px-3 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 cursor-pointer">
                            Observações Clínicas
                        </button>
                        <button onclick="filtrarHistoricoStatus('pendente')" id="btnFiltroHistPendente" class="px-3 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 cursor-pointer">
                            Pendentes
                        </button>
                    </div>
                    <span class="text-xs text-slate-400" id="histCountFiltrados">0 registro(s)</span>
                </div>

                <!-- Lista de Agendamentos do Paciente -->
                <div id="histListaAgendamentos" class="space-y-2.5">
                    <!-- Gerado dinamicamente via JS -->
                </div>
            </div>

            <!-- Rodapé do Modal -->
            <div class="px-6 py-3.5 border-t border-slate-100 bg-slate-50 flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">Histórico completo gerado pelo sistema clínico.</span>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="fecharHistoricoPaciente()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-xl text-xs font-semibold">
                        Fechar
                    </button>
                    <button type="button" id="btnHistNovoAgendamento" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs">
                        + Novo Agendamento
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL 5: CADASTRAR / EDITAR USUÁRIO DO SISTEMA          -->
    <!-- ======================================================== -->
    <div id="modalUsuario" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full max-h-[92vh] flex flex-col overflow-hidden text-xs">
            <!-- Topo -->
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2" id="modalUsuarioTitulo">
                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    Cadastrar Novo Usuário
                </h3>
                <button type="button" onclick="fecharModalUsuario()" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Formulário -->
            <form method="POST" id="formUsuario" class="p-6 space-y-4 overflow-y-auto flex-1">
                <input type="hidden" name="acao" id="formUsuarioAcao" value="novo_usuario">
                <input type="hidden" name="usuario_id" id="formUsuarioId" value="">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nome Completo *</label>
                    <input type="text" name="nome" id="userNome" required placeholder="Ex: Dra. Camila Rocha ou Mariana Silva" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-purple-500">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">E-mail de Acesso *</label>
                        <input type="email" name="email" id="userEmail" required placeholder="usuario@clinica.com" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Telefone / WhatsApp</label>
                        <input type="text" name="telefone" id="userTelefone" placeholder="(11) 98765-4321" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Perfil de Acesso (Papel) *</label>
                    <select name="papel" id="userPapel" onchange="onUserPapelChange()" required class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-purple-500">
                        <option value="admin">Administrador Geral (Acesso total)</option>
                        <option value="secretaria">Secretária / Recepção (Agenda, grade, pacientes)</option>
                        <option value="professor">Professor / Fisioterapeuta (Aulas e chamada)</option>
                        <option value="aluno" selected>Aluno / Cliente (Apenas seus próprios agendamentos)</option>
                    </select>
                </div>

                <!-- Campo dinâmico: vínculo com Profissional (se professor) -->
                <div id="campoVinculoProfissional" class="hidden">
                    <label class="block font-bold text-sky-800 mb-1">Vincular ao Cadastro de Profissional</label>
                    <select name="profissional_id" id="userProfissionalId" class="w-full p-2.5 bg-sky-50/50 border border-sky-300 rounded-lg text-xs">
                        <option value="">-- Nenhum vínculo ou selecione o profissional --</option>
                        <?php foreach ($todosProfissionais as $pItem): ?>
                            <option value="<?= $pItem['id'] ?>"><?= htmlspecialchars($pItem['nome']) ?> (<?= htmlspecialchars($pItem['especialidade']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-[10px] text-slate-400 mt-1 block">Permite ao professor visualizar sua grade de aulas e lista de presença.</span>
                </div>

                <!-- Campo dinâmico: vínculo com Paciente/Aluno (se aluno) -->
                <div id="campoVinculoPaciente">
                    <label class="block font-bold text-amber-800 mb-1">Vincular ao Cadastro de Paciente / Aluno</label>
                    <select name="paciente_id" id="userPacienteId" class="w-full p-2.5 bg-amber-50/50 border border-amber-300 rounded-lg text-xs">
                        <option value="">-- Nenhum vínculo ou selecione o aluno --</option>
                        <?php foreach ($pacientes as $pacItem): ?>
                            <option value="<?= $pacItem['id'] ?>"><?= htmlspecialchars($pacItem['nome']) ?> (<?= htmlspecialchars($pacItem['telefone']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-[10px] text-slate-400 mt-1 block">Permite ao aluno visualizar apenas seu próprio prontuário e aulas agendadas.</span>
                </div>

                <!-- Senha -->
                <div>
                    <label class="block font-bold text-slate-700 mb-1" id="userSenhaLabel">Senha de Acesso *</label>
                    <div class="relative">
                        <input type="password" name="senha" id="userSenha" placeholder="Digite uma senha inicial (mínimo 3 caracteres)" class="w-full pl-3 pr-10 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-purple-500">
                        <button type="button" onclick="toggleMostrarSenhaModal('userSenha', 'eyeIconUserModal')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer">
                            <svg id="eyeIconUserModal" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <span class="text-[11px] text-slate-500 mt-1 block" id="userSenhaDica">
                        A senha será criptografada automaticamente (bcrypt seguro) antes de ser salva no MySQL.
                    </span>
                </div>

                <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg flex items-center justify-between">
                    <div>
                        <span class="font-bold text-slate-800 block text-xs">Usuário Ativo</span>
                        <span class="text-[11px] text-slate-500">Usuários inativos não conseguem autenticar nem acessar o painel.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="ativo" id="userAtivo" value="1" checked class="sr-only peer">
                        <div class="w-9 h-5 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600"></div>
                    </label>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="fecharModalUsuario()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-bold shadow-xs cursor-pointer">
                        Salvar Usuário
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL 6: ALTERAR SENHA DO USUÁRIO                        -->
    <!-- ======================================================== -->
    <div id="modalAlterarSenhaUsuario" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-sm w-full p-6 text-xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    Redefinir Senha
                </h3>
                <button type="button" onclick="fecharModalAlterarSenha()" class="text-slate-400 hover:text-slate-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="acao" value="alterar_senha_usuario">
                <input type="hidden" name="usuario_id" id="altSenhaUsuarioId" value="">

                <div>
                    <span class="text-slate-400 font-semibold block text-[11px]">Usuário Selecionado:</span>
                    <strong class="text-slate-900 text-sm block" id="altSenhaUsuarioNome">--</strong>
                    <span class="text-slate-500 text-[11px] block" id="altSenhaUsuarioEmail">--</span>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nova Senha *</label>
                    <div class="relative">
                        <input type="password" name="nova_senha" id="altNovaSenhaInput" required placeholder="Digite a nova senha (mínimo 3 caracteres)" class="w-full pl-3 pr-10 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-purple-500">
                        <button type="button" onclick="toggleMostrarSenhaModal('altNovaSenhaInput', 'eyeIconAltSenha')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer">
                            <svg id="eyeIconAltSenha" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <span class="text-[11px] text-slate-400 mt-1 block">A nova senha será salva no MySQL com hash bcrypt seguro.</span>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="fecharModalAlterarSenha()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-bold shadow-xs cursor-pointer">
                        Atualizar Senha
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL 7: BLOQUEAR HORÁRIO / AGENDA DA CLÍNICA             -->
    <!-- ======================================================== -->
    <div id="modalBloqueio" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-md w-full overflow-hidden text-xs">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-amber-50">
                <h3 class="text-sm font-bold text-amber-900 flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    Bloquear Agenda da Clínica
                </h3>
                <button type="button" onclick="fecharModalBloqueio()" class="text-slate-400 hover:text-slate-600 font-bold p-1">✕</button>
            </div>

            <form method="POST" class="p-6 space-y-4" id="formBloqueio">
                <input type="hidden" name="acao" value="novo_bloqueio">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Data do Bloqueio *</label>
                    <input type="date" name="data" id="bloqueioData" required min="<?= date('Y-m-d') ?>" value="<?= $dataSelecionada ?>" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-amber-500">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Modalidade Afetada *</label>
                    <select name="modalidade_id" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-amber-500">
                        <option value="todas">Todas as Modalidades (Feriado Geral da Clínica)</option>
                        <option value="pilates">Somente Pilates Studio</option>
                        <option value="hidroginastica">Somente Hidroginástica (Ex: Manutenção da Piscina)</option>
                        <option value="fisioterapia">Somente Fisioterapia</option>
                    </select>
                </div>

                <div>
                    <label class="flex items-center gap-2 font-bold text-slate-700 mb-2 cursor-pointer">
                        <input type="checkbox" name="dia_inteiro" id="checkBloqueioDiaInteiro" value="1" checked onchange="toggleCamposHorarioBloqueio(this.checked)" class="w-4 h-4 text-amber-600 rounded">
                        Bloquear o dia inteiro
                    </label>

                    <div id="camposHorarioBloqueio" class="grid grid-cols-2 gap-2 mt-2 hidden">
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1">Hora Início</label>
                            <input type="time" name="hora_inicio" id="bloqueioHoraInicio" value="08:00" class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1">Hora Término</label>
                            <input type="time" name="hora_fim" id="bloqueioHoraFim" value="12:00" class="w-full p-2 bg-slate-50 border border-slate-300 rounded-lg font-mono">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Motivo do Bloqueio *</label>
                    <input type="text" name="motivo" required placeholder="Ex: Feriado de Páscoa / Manutenção da Caldeira da Piscina" class="w-full p-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-amber-500">
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="fecharModalBloqueio()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-lg shadow-xs">
                        Confirmar Bloqueio
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts da Aplicação -->
    <script>
        // Modal de Bloqueio de Horário
        function abrirModalBloqueio() {
            const d = document.getElementById('bloqueioData');
            if (d) {
                const h = getHojeFormatadoISO();
                d.min = h;
                if (!d.value || d.value < h) d.value = h;
            }
            document.getElementById('modalBloqueio').classList.remove('hidden');
        }
        function fecharModalBloqueio() {
            document.getElementById('modalBloqueio').classList.add('hidden');
        }
        function toggleCamposHorarioBloqueio(diaInteiro) {
            const box = document.getElementById('camposHorarioBloqueio');
            if (box) {
                if (diaInteiro) {
                    box.classList.add('hidden');
                } else {
                    box.classList.remove('hidden');
                }
            }
        }
        // Menu Dropdown do Usuário Logado
        function toggleMenuUsuario(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('dropdownMenuUsuario');
            if (menu) {
                menu.classList.toggle('hidden');
            }
        }

        // Fecha menu ao clicar fora
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('dropdownMenuUsuario');
            const btn = document.getElementById('btnPerfilUsuario');
            if (menu && !menu.classList.contains('hidden')) {
                if (!menu.contains(e.target) && (!btn || !btn.contains(e.target))) {
                    menu.classList.add('hidden');
                }
            }
        });

        // Alternar visualização de senha em formulários modais
        function toggleMostrarSenhaModal(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) {
                    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />';
                }
            } else {
                input.type = 'password';
                if (icon) {
                    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />';
                }
            }
        }

        // Modal de Usuário (Cadastrar e Editar)
        function abrirModalUsuario() {
            document.getElementById('modalUsuarioTitulo').innerHTML = `
                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Cadastrar Novo Usuário
            `;
            document.getElementById('formUsuarioAcao').value = 'novo_usuario';
            document.getElementById('formUsuarioId').value = '';
            document.getElementById('formUsuario').reset();
            document.getElementById('userPapel').value = 'aluno';
            document.getElementById('userSenha').required = true;
            document.getElementById('userSenhaLabel').textContent = 'Senha de Acesso *';
            document.getElementById('userSenhaDica').textContent = 'A senha será criptografada automaticamente com bcrypt antes de ser salva no MySQL.';
            document.getElementById('userAtivo').checked = true;
            onUserPapelChange();
            document.getElementById('modalUsuario').classList.remove('hidden');
        }

        function fecharModalUsuario() {
            document.getElementById('modalUsuario').classList.add('hidden');
        }

        function editarUsuario(user) {
            document.getElementById('modalUsuarioTitulo').innerHTML = `
                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Editar Usuário: ` + user.nome;
            document.getElementById('formUsuarioAcao').value = 'editar_usuario';
            document.getElementById('formUsuarioId').value = user.id;
            document.getElementById('userNome').value = user.nome || '';
            document.getElementById('userEmail').value = user.email || '';
            document.getElementById('userTelefone').value = user.telefone || '';
            document.getElementById('userPapel').value = user.papel || 'aluno';
            document.getElementById('userProfissionalId').value = user.profissional_id || '';
            document.getElementById('userPacienteId').value = user.paciente_id || '';
            document.getElementById('userSenha').value = '';
            document.getElementById('userSenha').required = false;
            document.getElementById('userSenhaLabel').textContent = 'Nova Senha (Opcional)';
            document.getElementById('userSenhaDica').textContent = 'Deixe em branco para manter a senha atual, ou digite uma nova para alterá-la.';
            document.getElementById('userAtivo').checked = (parseInt(user.ativo) === 1);
            onUserPapelChange();
            document.getElementById('modalUsuario').classList.remove('hidden');
        }

        function onUserPapelChange() {
            const papel = document.getElementById('userPapel').value;
            const campoProf = document.getElementById('campoVinculoProfissional');
            const campoPac = document.getElementById('campoVinculoPaciente');
            if (papel === 'professor') {
                campoProf.classList.remove('hidden');
                campoPac.classList.add('hidden');
            } else if (papel === 'aluno') {
                campoProf.classList.add('hidden');
                campoPac.classList.remove('hidden');
            } else {
                campoProf.classList.add('hidden');
                campoPac.classList.add('hidden');
            }
        }

        // Modal Alterar Senha Rápida
        function abrirModalAlterarSenha(user) {
            document.getElementById('altSenhaUsuarioId').value = user.id;
            document.getElementById('altSenhaUsuarioNome').textContent = user.nome;
            document.getElementById('altSenhaUsuarioEmail').textContent = user.email;
            document.getElementById('altNovaSenhaInput').value = '';
            document.getElementById('modalAlterarSenhaUsuario').classList.remove('hidden');
        }

        function fecharModalAlterarSenha() {
            document.getElementById('modalAlterarSenhaUsuario').classList.add('hidden');
        }

        // Filtro da Grade de Usuários
        let filtroUserPapelAtual = 'todos';
        let filtroUserStatusAtual = 'todos';

        function filtrarTabelaUsuarios() {
            const termo = (document.getElementById('filtroUsuarios')?.value || '').toLowerCase().trim();
            const cards = document.querySelectorAll('.item-usuario');

            cards.forEach(card => {
                const papel = card.getAttribute('data-papel');
                const ativo = parseInt(card.getAttribute('data-ativo'));
                const nome = card.querySelector('.nome-usuario')?.textContent.toLowerCase() || '';
                const email = card.querySelector('.email-usuario')?.textContent.toLowerCase() || '';
                const tel = card.querySelector('.tel-usuario')?.textContent.toLowerCase() || '';

                const matchTexto = !termo || nome.includes(termo) || email.includes(termo) || tel.includes(termo);
                const matchPapel = (filtroUserPapelAtual === 'todos') || (papel === filtroUserPapelAtual);
                const matchStatus = (filtroUserStatusAtual === 'todos') ||
                                    (filtroUserStatusAtual === 'ativos' && ativo === 1) ||
                                    (filtroUserStatusAtual === 'inativos' && ativo === 0);

                if (matchTexto && matchPapel && matchStatus) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function setFiltroUsuarioPapel(papel) {
            filtroUserPapelAtual = papel;
            const papeis = ['todos', 'admin', 'secretaria', 'professor', 'aluno'];
            papeis.forEach(p => {
                const btnId = p === 'todos' ? 'btnUserPapelTodos' : (p === 'admin' ? 'btnUserPapelAdmin' : (p === 'secretaria' ? 'btnUserPapelSec' : (p === 'professor' ? 'btnUserPapelProf' : 'btnUserPapelAluno')));
                const btn = document.getElementById(btnId);
                if (btn) {
                    if (p === papel) {
                        btn.className = 'px-2.5 py-1 rounded-lg font-bold transition bg-white text-purple-700 shadow-xs cursor-pointer';
                    } else {
                        btn.className = 'px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer';
                    }
                }
            });
            filtrarTabelaUsuarios();
        }

        function setFiltroUsuarioStatus(status) {
            filtroUserStatusAtual = status;
            const statuses = ['todos', 'ativos', 'inativos'];
            statuses.forEach(s => {
                const btnId = s === 'todos' ? 'btnUserStatusTodos' : (s === 'ativos' ? 'btnUserStatusAtivos' : 'btnUserStatusInativos');
                const btn = document.getElementById(btnId);
                if (btn) {
                    if (s === status) {
                        btn.className = 'px-2.5 py-1 rounded-lg font-bold transition bg-white text-purple-700 shadow-xs cursor-pointer';
                    } else {
                        btn.className = 'px-2.5 py-1 rounded-lg font-semibold transition text-slate-600 hover:text-slate-900 cursor-pointer';
                    }
                }
            });
            filtrarTabelaUsuarios();
        }

        function getHojeFormatadoISO() {
            const d = new Date();
            const ano = d.getFullYear();
            const mes = String(d.getMonth() + 1).padStart(2, '0');
            const dia = String(d.getDate()).padStart(2, '0');
            return `${ano}-${mes}-${dia}`;
        }

        // Modal de Agendamento
        function abrirModalAgendamento() {
            const inputData = document.getElementById('modalData');
            const hojeStr = getHojeFormatadoISO();
            if (inputData) {
                inputData.min = hojeStr;
                if (!inputData.value || inputData.value < hojeStr) {
                    inputData.value = hojeStr;
                }
            }
            document.getElementById('modalAgendamento').classList.remove('hidden');
        }
        function fecharModalAgendamento() {
            document.getElementById('modalAgendamento').classList.add('hidden');
        }
        function agendarNesteSlot(slot) {
            const hojeStr = getHojeFormatadoISO();
            if (slot.data && slot.data < hojeStr) {
                alert('A data do agendamento não pode ser anterior ao dia de hoje.');
                return;
            }
            document.getElementById('modalGradeId').value = slot.id;
            document.getElementById('modalModalidadeId').value = slot.modalidade_id;
            document.getElementById('modalHoraInicio').value = slot.hora_inicio.substring(0, 5);
            document.getElementById('modalHoraFim').value = slot.hora_fim.substring(0, 5);
            document.getElementById('modalProfissionalId').value = slot.profissional_id;
            const inputData = document.getElementById('modalData');
            if (inputData) {
                inputData.min = hojeStr;
                inputData.value = (slot.data && slot.data >= hojeStr) ? slot.data : hojeStr;
            }
            abrirModalAgendamento();
        }
        function validarFormAgendamento(form) {
            const inputData = form.querySelector('[name="data"]');
            if (inputData) {
                const hojeStr = getHojeFormatadoISO();
                if (!inputData.value || inputData.value < hojeStr) {
                    alert('A data do agendamento não pode ser anterior ao dia de hoje.');
                    inputData.value = hojeStr;
                    inputData.focus();
                    return false;
                }
            }
            return true;
        }

        // Modal de Profissional
        function abrirModalProfissional() {
            document.getElementById('modalProfissionalTitulo').innerHTML = `
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                Cadastrar Novo Profissional
            `;
            document.getElementById('formProfissionalAcao').value = 'novo_profissional';
            document.getElementById('formProfissionalId').value = '';
            document.getElementById('formProfissional').reset();
            document.getElementById('checkModPilates').checked = true;
            document.getElementById('profAtivo').checked = true;
            document.getElementById('modalProfissional').classList.remove('hidden');
        }
        function fecharModalProfissional() {
            document.getElementById('modalProfissional').classList.add('hidden');
        }
        function editarProfissional(prof) {
            document.getElementById('modalProfissionalTitulo').innerHTML = `
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Editar Profissional: ` + prof.nome;
            document.getElementById('formProfissionalAcao').value = 'editar_profissional';
            document.getElementById('formProfissionalId').value = prof.id;
            document.getElementById('profNome').value = prof.nome || '';
            document.getElementById('profRegistro').value = prof.registro_profissional || '';
            document.getElementById('profEspecialidade').value = prof.especialidade || '';
            document.getElementById('profTelefone').value = prof.telefone || '';
            document.getElementById('profEmail').value = prof.email || '';

            const mods = (prof.modalidades || '').split(',').map(m => m.trim());
            document.getElementById('checkModPilates').checked = mods.includes('pilates');
            document.getElementById('checkModHidro').checked = mods.includes('hidroginastica');
            document.getElementById('checkModFisio').checked = mods.includes('fisioterapia');

            document.getElementById('profAtivo').checked = (parseInt(prof.ativo) === 1);
            document.getElementById('modalProfissional').classList.remove('hidden');
        }
        let filtroProfStatusAtual = 'ativos'; // Padrão: oculta inativos a não ser que filtre por eles

        function setFiltroProfissionalStatus(status) {
            filtroProfStatusAtual = status;

            const btnAtivos = document.getElementById('btnProfAtivos');
            const btnInativos = document.getElementById('btnProfInativos');
            const btnTodos = document.getElementById('btnProfTodos');
            const bannerInativosOcultos = document.getElementById('bannerProfInativosOcultos');
            const bannerApenasInativos = document.getElementById('bannerProfApenasInativos');

            const btnAtivoClass = 'px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer bg-white text-indigo-700 shadow-xs border border-indigo-100';
            const btnInativoClass = 'px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer text-slate-600 hover:text-slate-900';

            if (btnAtivos) btnAtivos.className = (status === 'ativos') ? btnAtivoClass : btnInativoClass;
            if (btnInativos) btnInativos.className = (status === 'inativos') ? btnAtivoClass : btnInativoClass;
            if (btnTodos) btnTodos.className = (status === 'todos') ? btnAtivoClass : btnInativoClass;

            if (bannerInativosOcultos) {
                if (status === 'ativos') {
                    bannerInativosOcultos.classList.remove('hidden');
                } else {
                    bannerInativosOcultos.classList.add('hidden');
                }
            }

            if (bannerApenasInativos) {
                if (status === 'inativos') {
                    bannerApenasInativos.classList.remove('hidden');
                } else {
                    bannerApenasInativos.classList.add('hidden');
                }
            }

            filtrarTabelaProfissionais();
        }

        function filtrarTabelaProfissionais() {
            const inputEl = document.getElementById('filtroProfissionais');
            const termo = inputEl ? inputEl.value.toLowerCase().trim() : '';
            const cards = document.querySelectorAll('.item-profissional');

            cards.forEach(card => {
                const isAtivo = parseInt(card.getAttribute('data-ativo') || '1') === 1;
                const nome = card.querySelector('.nome-profissional')?.textContent.toLowerCase() || '';
                const reg = card.querySelector('.registro-profissional')?.textContent.toLowerCase() || '';
                const esp = card.querySelector('.especialidade-profissional')?.textContent.toLowerCase() || '';

                // Verifica filtro de status
                let matchStatus = true;
                if (filtroProfStatusAtual === 'ativos') {
                    matchStatus = isAtivo;
                } else if (filtroProfStatusAtual === 'inativos') {
                    matchStatus = !isAtivo;
                }

                // Verifica filtro de busca textual
                const matchTexto = !termo || nome.includes(termo) || reg.includes(termo) || esp.includes(termo);

                if (matchStatus && matchTexto) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Executa o filtro de profissionais inicialmente para já ocultar inativos por padrão
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('gridProfissionais')) {
                filtrarTabelaProfissionais();
            }
        });

        // Modal de Paciente
        function abrirModalPaciente() {
            document.getElementById('modalPacienteTitulo').innerHTML = `
                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                Cadastrar Novo Paciente / Aluno
            `;
            document.getElementById('formPacienteAcao').value = 'novo_paciente';
            document.getElementById('formPacienteId').value = '';
            document.getElementById('formPaciente').reset();
            document.getElementById('modalPaciente').classList.remove('hidden');
        }
        function fecharModalPaciente() {
            document.getElementById('modalPaciente').classList.add('hidden');
        }
        function editarPaciente(pac) {
            document.getElementById('modalPacienteTitulo').innerHTML = `
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Editar Paciente: ` + pac.nome;
            document.getElementById('formPacienteAcao').value = 'editar_paciente';
            document.getElementById('formPacienteId').value = pac.id;
            document.getElementById('pacNome').value = pac.nome || '';
            document.getElementById('pacTelefone').value = pac.telefone || '';
            document.getElementById('pacEmail').value = pac.email || '';
            document.getElementById('pacNascimento').value = pac.data_nascimento || '';
            document.getElementById('pacModalidade').value = pac.modalidade_interesse || 'pilates';
            document.getElementById('pacObs').value = pac.observacoes_medicas || '';
            document.getElementById('modalPaciente').classList.remove('hidden');
        }
        function agendarParaPaciente(pac) {
            document.getElementById('modalPacienteId').value = pac.id;
            if (pac.modalidade_interesse) {
                document.getElementById('modalModalidadeId').value = pac.modalidade_interesse;
            }
            abrirModalAgendamento();
        }

        // Filtro em tempo real na tabela de pacientes
        function filtrarTabelaPacientes() {
            const termo = document.getElementById('filtroPacientes').value.toLowerCase();
            const linhas = document.querySelectorAll('.item-paciente');
            linhas.forEach(linha => {
                const nome = linha.querySelector('.nome-paciente').textContent.toLowerCase();
                const tel = linha.querySelector('.tel-paciente').textContent.toLowerCase();
                if (nome.includes(termo) || tel.includes(termo)) {
                    linha.style.display = '';
                } else {
                    linha.style.display = 'none';
                }
            });
        }

        // Ajusta capacidade sugerida ao mudar a modalidade na grade semanal
        function ajustarCapacidadeModalidade(mod) {
            const capInput = document.getElementById('gradeCapacidade');
            if (mod === 'pilates') capInput.value = 4;
            else if (mod === 'hidroginastica') capInput.value = 12;
            else if (mod === 'fisioterapia') capInput.value = 1;
        }

        // ========================================================
        // HISTÓRICO COMPLETO DO PACIENTE (AULAS, FALTAS E OBSERVAÇÕES)
        // ========================================================
        window.todosAgendamentosPorPaciente = <?= json_encode($agendamentosPorPacienteJson) ?>;
        window.todasObservacoesPorPaciente = <?= json_encode($observacoesPorPacienteJson) ?>;
        window.statsPacientesMap = <?= json_encode($statsPacientesMap) ?>;
        let pacienteAtualHistorico = null;
        let filtroStatusAtual = 'todos';

        function abrirHistoricoPaciente(pacId, pacData) {
            pacienteAtualHistorico = pacData;
            filtroStatusAtual = 'todos';

            // Iniciais
            const partes = (pacData.nome || 'P').trim().split(' ');
            const iniciais = (partes[0][0] + (partes.length > 1 ? partes[1][0] : '')).toUpperCase();
            document.getElementById('histAvatar').textContent = iniciais;

            // Nome e telefone
            document.getElementById('histNomePaciente').textContent = pacData.nome;
            document.getElementById('histTelefone').textContent = pacData.telefone || 'Sem telefone';
            
            // Link WhatsApp
            const telLimpo = (pacData.telefone || '').replace(/\D/g, '');
            const waContainer = document.getElementById('histWhatsAppLink');
            if (telLimpo) {
                waContainer.innerHTML = `<a href="https://wa.me/55${telLimpo}" target="_blank" class="text-emerald-600 hover:text-emerald-700 font-bold flex items-center gap-1">WhatsApp</a>`;
            } else {
                waContainer.innerHTML = '';
            }

            // Email
            document.getElementById('histEmail').textContent = pacData.email ? `• ${pacData.email}` : '';

            // Restrições médicas
            const restricoesBanner = document.getElementById('histRestricoesBanner');
            if (pacData.observacoes_medicas && pacData.observacoes_medicas.trim().length > 0) {
                document.getElementById('histRestricoesTexto').textContent = pacData.observacoes_medicas;
                restricoesBanner.classList.remove('hidden');
            } else {
                restricoesBanner.classList.add('hidden');
            }

            // Estatísticas
            const ags = window.todosAgendamentosPorPaciente[pacId] || [];
            const obss = window.todasObservacoesPorPaciente[pacId] || [];

            const realizados = ags.filter(a => a.status === 'realizado').length;
            const faltas = ags.filter(a => a.status === 'falta').length;
            const pendentes = ags.filter(a => a.status === 'agendado' || a.status === 'confirmado').length;
            const totalObs = obss.length;
            const totalGeral = realizados + faltas + totalObs;

            const basePresenca = realizados + faltas;
            const taxa = basePresenca > 0 ? Math.round((realizados / basePresenca) * 100) : 100;

            document.getElementById('histTotalSessoes').textContent = `${totalGeral} ${totalGeral === 1 ? 'registro' : 'registros'}`;
            document.getElementById('histStatTotal').textContent = totalGeral;
            document.getElementById('histStatRealizados').textContent = realizados;
            document.getElementById('histStatAssiduidade').textContent = `${taxa}% presenças`;
            document.getElementById('histStatFaltas').textContent = faltas;
            document.getElementById('histStatObservacoes').textContent = totalObs;

            // Botão Novo Agendamento
            document.getElementById('btnHistNovoAgendamento').onclick = function() {
                fecharHistoricoPaciente();
                agendarParaPaciente(pacData);
            };

            filtrarHistoricoStatus('todos');
            document.getElementById('modalHistoricoPaciente').classList.remove('hidden');
        }

        function fecharHistoricoPaciente() {
            document.getElementById('modalHistoricoPaciente').classList.add('hidden');
            pacienteAtualHistorico = null;
        }

        function filtrarHistoricoStatus(status) {
            filtroStatusAtual = status;

            // Atualiza botões
            const botoes = {
                todos: document.getElementById('btnFiltroHistTodos'),
                realizado: document.getElementById('btnFiltroHistRealizado'),
                falta: document.getElementById('btnFiltroHistFalta'),
                observacao: document.getElementById('btnFiltroHistObs'),
                pendente: document.getElementById('btnFiltroHistPendente')
            };

            for (const [key, btn] of Object.entries(botoes)) {
                if (!btn) continue;
                if (key === status) {
                    btn.className = 'px-3 py-1 rounded-lg text-xs font-bold bg-indigo-600 text-white cursor-pointer';
                } else {
                    btn.className = 'px-3 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 cursor-pointer';
                }
            }

            renderizarListaHistorico();
        }

        function renderizarListaHistorico() {
            if (!pacienteAtualHistorico) return;
            const pacId = parseInt(pacienteAtualHistorico.id);
            const ags = window.todosAgendamentosPorPaciente[pacId] || [];
            const obss = window.todasObservacoesPorPaciente[pacId] || [];

            // Monta lista unificada de eventos cronológicos (aulas realizadas, faltas e observações)
            const eventos = [];

            // Aulas Realizadas, Faltas e Pendentes
            ags.forEach(ag => {
                let tipo = 'agendamento';
                if (ag.status === 'realizado') tipo = 'realizado';
                else if (ag.status === 'falta') tipo = 'falta';
                else if (ag.status === 'agendado' || ag.status === 'confirmado') tipo = 'pendente';
                else if (ag.status === 'cancelado') tipo = 'cancelado';

                eventos.push({
                    tipo: tipo,
                    categoria: 'agendamento',
                    data: ag.data,
                    hora: ag.hora_inicio || '00:00',
                    horaFim: ag.hora_fim || '',
                    datetimeKey: `${ag.data}T${(ag.hora_inicio || '00:00').substring(0, 5)}`,
                    modalidade: ag.modalidade_nome || ag.modalidade_id,
                    modalidadeId: ag.modalidade_id,
                    titulo: ag.tipo_atendimento || `Aula de ${ag.modalidade_nome || ag.modalidade_id}`,
                    profissional: ag.profissional_nome || 'Profissional da Clínica',
                    registro: ag.registro_profissional || '',
                    observacao: ag.observacao || '',
                    status: ag.status,
                    id: ag.id
                });
            });

            // Observações Clínicas
            obss.forEach(obs => {
                eventos.push({
                    tipo: 'observacao',
                    categoria: 'observacao',
                    data: obs.data,
                    hora: obs.hora || '00:00',
                    datetimeKey: `${obs.data}T${(obs.hora || '00:00').substring(0, 5)}`,
                    modalidade: obs.modalidade || '',
                    modalidadeId: obs.modalidade || '',
                    titulo: obs.titulo || `Observação Clínica (${obs.tipo || 'Geral'})`,
                    profissional: obs.prof_nome_rel || obs.profissional_nome || 'Profissional da Clínica',
                    registro: obs.prof_reg_rel || obs.profissional_registro || '',
                    observacao: obs.texto || '',
                    dorEva: obs.dor_eva,
                    tipoObs: obs.tipo,
                    id: obs.id
                });
            });

            // Ordena em ordem cronológica (mais recentes primeiro)
            eventos.sort((a, b) => b.datetimeKey.localeCompare(a.datetimeKey));

            // Filtra de acordo com o filtro selecionado
            let filtrados = eventos;
            if (filtroStatusAtual === 'realizado') {
                filtrados = eventos.filter(e => e.tipo === 'realizado');
            } else if (filtroStatusAtual === 'falta') {
                filtrados = eventos.filter(e => e.tipo === 'falta');
            } else if (filtroStatusAtual === 'observacao') {
                filtrados = eventos.filter(e => e.tipo === 'observacao');
            } else if (filtroStatusAtual === 'pendente') {
                filtrados = eventos.filter(e => e.tipo === 'pendente');
            }

            document.getElementById('histCountFiltrados').textContent = `${filtrados.length} registro(s)`;
            const container = document.getElementById('histListaAgendamentos');

            if (filtrados.length === 0) {
                container.innerHTML = `
                    <div class="py-8 text-center text-slate-400 bg-slate-50 rounded-xl border border-slate-100">
                        <p class="font-bold text-xs text-slate-600">Nenhum registro encontrado</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Não há aulas realizadas, faltas ou observações com o filtro selecionado.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            filtrados.forEach(item => {
                const dataPartes = (item.data || '').split('-');
                const dataFormatada = dataPartes.length === 3 ? `${dataPartes[2]}/${dataPartes[1]}/${dataPartes[0]}` : item.data;
                const horaIni = (item.hora || '').substring(0, 5);
                const horaFim = (item.horaFim || '').substring(0, 5);

                if (item.tipo === 'observacao') {
                    // Card de Observação Clínica
                    html += `
                        <div class="p-3.5 rounded-xl border border-l-4 border-l-cyan-500 bg-cyan-50/20 border-cyan-200">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-cyan-100 text-cyan-800 border border-cyan-300">
                                    🩺 Observação Clínica
                                </span>
                                <span class="font-bold text-slate-900 text-xs font-mono">${dataFormatada}</span>
                                ${horaIni && horaIni !== '00:00' ? `<span class="font-mono text-xs font-semibold text-slate-700 bg-slate-100 px-2 py-0.5 rounded">${horaIni}</span>` : ''}
                                ${item.modalidade ? `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold border capitalize badge-pilates">${item.modalidade}</span>` : ''}
                                ${item.dorEva ? `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">Dor EVA: ${item.dorEva}/10</span>` : ''}
                            </div>
                            <div class="font-bold text-slate-900 text-xs mt-1.5">${item.titulo}</div>
                            <div class="text-xs text-slate-600 mt-0.5">
                                Profissional: <strong>${item.profissional}</strong>
                                ${item.registro ? `<span class="text-slate-400 font-mono text-[10px] ml-1">(${item.registro})</span>` : ''}
                            </div>
                            <div class="text-[11px] text-slate-700 bg-white p-2.5 rounded-lg mt-2 border border-cyan-100 leading-relaxed whitespace-pre-wrap">
                                <span class="font-bold text-cyan-800 text-[10px] uppercase block mb-0.5">Evolução / Anotação Clínica:</span>
                                ${item.observacao}
                            </div>
                        </div>
                    `;
                } else {
                    // Card de Agendamento (Aula realizada, falta ou pendente)
                    let badgeStatusClass = 'bg-slate-100 text-slate-700 border-slate-200';
                    let statusLabel = 'Agendado';
                    let itemBgClass = 'bg-white border-slate-200';
                    let corBorda = 'border-l-indigo-500';

                    if (item.status === 'realizado') {
                        badgeStatusClass = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                        statusLabel = '✓ Aula Realizada (Presença)';
                        itemBgClass = 'bg-emerald-50/20 border-emerald-100';
                        corBorda = 'border-l-emerald-500';
                    } else if (item.status === 'falta') {
                        badgeStatusClass = 'bg-rose-100 text-rose-800 border-rose-300';
                        statusLabel = '✗ Falta Registrada';
                        itemBgClass = 'bg-rose-50/20 border-rose-200';
                        corBorda = 'border-l-rose-500';
                    } else if (item.status === 'confirmado') {
                        badgeStatusClass = 'bg-cyan-100 text-cyan-800 border-cyan-300';
                        statusLabel = 'Confirmado';
                        itemBgClass = 'bg-cyan-50/20 border-cyan-100';
                    } else if (item.status === 'cancelado') {
                        badgeStatusClass = 'bg-slate-200 text-slate-600 border-slate-300 line-through';
                        statusLabel = 'Cancelado';
                        itemBgClass = 'bg-slate-50 border-slate-200';
                    } else {
                        badgeStatusClass = 'bg-indigo-100 text-indigo-800 border-indigo-200';
                        statusLabel = '⏳ Pendente';
                    }

                    const modBadgeClass = item.modalidadeId === 'pilates' ? 'badge-pilates' : (item.modalidadeId === 'hidroginastica' ? 'badge-hidro' : 'badge-fisio');

                    html += `
                        <div class="p-3.5 rounded-xl border border-l-4 ${corBorda} flex flex-col sm:flex-row sm:items-center justify-between gap-3 ${itemBgClass}">
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-bold text-slate-900 text-xs font-mono">${dataFormatada}</span>
                                    <span class="font-mono text-xs font-semibold text-slate-700 bg-slate-100 px-2 py-0.5 rounded">${horaIni}${horaFim ? ' - ' + horaFim : ''}</span>
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold border ${badgeStatusClass}">${statusLabel}</span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border capitalize ${modBadgeClass}">${item.modalidade}</span>
                                </div>
                                <div class="font-bold text-slate-900 text-xs mt-1">${item.titulo}</div>
                                <div class="text-xs text-slate-600 mt-0.5">
                                    Profissional: <strong>${item.profissional}</strong>
                                    ${item.registro ? `<span class="text-slate-400 font-mono text-[10px] ml-1">(${item.registro})</span>` : ''}
                                </div>
                                ${item.observacao ? `<div class="text-[11px] text-slate-600 bg-white p-2 rounded mt-1.5 border border-slate-200">${item.observacao}</div>` : ''}
                            </div>

                            <!-- Ações rápidas de status -->
                            <div class="flex items-center gap-1.5 shrink-0 self-end sm:self-center">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="acao" value="atualizar_status">
                                    <input type="hidden" name="agendamento_id" value="${item.id}">
                                    <input type="hidden" name="novo_status" value="realizado">
                                    <button type="submit" class="px-2 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded text-[11px] font-bold transition" title="Marcar presença realizada">
                                        Presença
                                    </button>
                                </form>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="acao" value="atualizar_status">
                                    <input type="hidden" name="agendamento_id" value="${item.id}">
                                    <input type="hidden" name="novo_status" value="falta">
                                    <button type="submit" class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-800 border border-rose-200 rounded text-[11px] font-bold transition" title="Registrar falta">
                                        Falta
                                    </button>
                                </form>
                            </div>
                        </div>
                    `;
                }
            });

            container.innerHTML = html;
        }

        // ==========================================================
        // AUTO-DISMISS E CONTROLE DE NOTIFICAÇÕES (MÁXIMO 10 SEGUNDOS)
        // ==========================================================
        function fecharAlerta(el) {
            if (!el) return;
            el.style.transition = 'opacity 0.4s ease, transform 0.4s ease, max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-6px)';
            setTimeout(() => {
                if (el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            }, 400);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Timer para auto-dismiss das notificações em até 10 segundos
            const alertas = document.querySelectorAll('.notificacao-temporaria');
            alertas.forEach(function(alerta) {
                let segundos = 10;
                const contador = alerta.querySelector('.tempo-restante');
                const timer = setInterval(function() {
                    segundos--;
                    if (contador) {
                        contador.textContent = '(' + segundos + 's)';
                    }
                    if (segundos <= 0) {
                        clearInterval(timer);
                        fecharAlerta(alerta);
                    }
                }, 1000);
            });

            // Validação em tempo real caso o usuário altere a data manualmente no input
            const inputModalData = document.getElementById('modalData');
            if (inputModalData) {
                inputModalData.addEventListener('change', function() {
                    const hojeStr = new Date().toLocaleDateString('en-CA');
                    if (this.value && this.value < hojeStr) {
                        alert('A data do agendamento não pode ser anterior ao dia de hoje.');
                        this.value = hojeStr;
                    }
                });
            }
        });
    </script>
</body>
</html>
