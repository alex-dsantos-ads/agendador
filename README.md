# Sistema de Gestão de Agenda Clínica (PHP + MySQL)

Sistema completo para controle de horários e agendamentos de **Pilates Studio**, **Hidroginástica** e **Fisioterapia**, com acesso exclusivo do estabelecimento (PIN/Senha).

---

## Estrutura dos Arquivos

- **`index.php`**: Painel principal de controle diário da agenda, visualização de vagas e presença, agendamento e gestão da grade semanal.
- **`login.php`**: Tela completa de autenticação com abas para Login (E-mail ou Telefone com Senha) e Auto-Cadastro de Clientes/Alunos com validação de duplicidade e gravação direta no banco MySQL.
- **`logout.php`**: Encerra a sessão administrativa.
- **`config.php`**: Configurações de fuso horário, sessão e conexão PDO com MySQL.
- **`api.php`**: Endpoints REST JSON com validação de limite de vagas e capacidade de atendimento.
- **`database.sql`**: Script MySQL para criar o banco `clinica_agenda` com todas as tabelas e dados iniciais de demonstração.

---

## Como Instalar e Rodar (Passo a Passo)

### 1. Servidor Local (XAMPP, WampServer ou Laragon)
1. Inicie os serviços do **Apache** e **MySQL** no painel de controle do XAMPP/Laragon.
2. Copie esta pasta com todos os arquivos para o diretório web:
   - No **XAMPP**: `C:\xampp\htdocs\clinica\`
   - No **Laragon**: `C:\laragon\www\clinica\`
3. Abra o navegador e acesse o phpMyAdmin: `http://localhost/phpmyadmin`
4. Clique na aba **Importar** (ou crie a base `clinica_agenda`) e selecione o arquivo **`database.sql`**.
5. Abra o arquivo `config.php` e ajuste as credenciais se necessário (padrão do XAMPP: usuário `root`, senha vazia `""`).
6. Acesse no navegador: `http://localhost/clinica/`

### 2. Hospedagem Web (cPanel, Hostinger, Locaweb, etc.)
1. Crie um banco de dados MySQL no painel da hospedagem.
2. Importe o arquivo `database.sql` pelo phpMyAdmin da hospedagem.
3. Envie todos os arquivos (`.php`) via FTP ou Gerenciador de Arquivos para a pasta `public_html`.
4. Edite o `config.php` com o nome do banco, usuário e senha fornecidos pela hospedagem.

---

## Acesso Inicial do Administrador
- O acesso administrativo inicial é configurado na tabela `usuarios` do banco de dados (ou via PIN de emergência configurável no `config.php`).
- Clientes / Alunos realizam cadastro comum pela tela de login, e a equipe é gerenciada na aba Usuários.

---

## Gestão de Usuários e Codificação de Senhas
- **Perfis de Acesso**:
  - **Administrador**: Gestão total da clínica, agendamentos, profissionais e usuários.
  - **Secretária / Recepção**: Gestão de agenda diária/semanal, pacientes e horários.
  - **Professor / Fisio**: Visualização de aulas, grade e chamada de alunos.
  - **Aluno / Paciente**: Visualização exclusiva de seus agendamentos e solicitações.
- **Segurança e Criptografia das Senhas**:
  - Todas as senhas criadas pelo painel ou tela de cadastro utilizam criptografia **Bcrypt (`password_hash`)**.
  - **Compatibilidade com phpMyAdmin / Banco de Dados**: Caso você insira manualmente um usuário pelo phpMyAdmin com senha em texto puro ou hash MD5/SHA1, o sistema reconhece a senha no primeiro login e atualiza automaticamente para Bcrypt no banco.
  - **Troca de Conta**: Acesse o ícone do usuário no canto superior direito para alternar de perfil ou clique em "Sair (Logout)".


