<?php
/* ============================================================
   FAMILIA MANAGER — API DE FINANÇAS
   ============================================================ */

// Inclui o arquivo de configuração global (geralmente contém a conexão com o banco 'getDB()' e funções úteis como 'sanitize()')
require_once __DIR__ . '/../includes/config.php';

// Bloqueia o acesso caso o usuário não esteja logado, garantindo a segurança da API
requireLogin();

// Informa ao navegador/aplicativo que a resposta desta página será no formato JSON (padrão para APIs)
header('Content-Type: application/json; charset=utf-8');

// Captura a ação desejada (seja via GET na URL ou POST no corpo da requisição)
$action     = $_GET['action'] ?? $_POST['action'] ?? '';

// Pega os IDs da família e do usuário diretamente da sessão (seguro, pois o usuário não pode forjar esses dados na URL)
$familia_id = $_SESSION['familia_id'];
$user_id    = $_SESSION['user_id'];

// Roteador: Decide qual bloco de código executar com base na variável $action
switch ($action) {

    // ── Resumo do mês ─────────────────────────────────────────
    // Usado para alimentar os "Cards" do topo do painel (Saldo, Total de Receitas, etc)
    case 'summary':
        // Pega o mês e ano da URL. Se não vier nada (??), usa a data atual do servidor (date('n') e date('Y'))
        $mes = intval($_GET['mes'] ?? date('n'));
        $ano = intval($_GET['ano'] ?? date('Y'));

        // Prepara a query SQL. O 'SUM(CASE...)' permite calcular totais de receitas e despesas em uma única consulta ao banco
        $stmt = getDB()->prepare('SELECT
            SUM(CASE WHEN tipo = "receita" THEN valor ELSE 0 END) AS receitas,
            SUM(CASE WHEN tipo = "despesa" THEN valor ELSE 0 END) AS despesas,
            COUNT(*) AS total
          FROM transacoes
          WHERE familia_id = ? AND MONTH(data) = ? AND YEAR(data) = ?');
          
        // Executa a query trocando as interrogações (?) pelos valores reais de forma segura (previne SQL Injection)
        $stmt->execute([$familia_id, $mes, $ano]);
        
        // Pega o resultado (geralmente uma única linha com as somas)
        $summary = $stmt->fetch();
        
        // Calcula o saldo final matematicamente via PHP
        $summary['saldo'] = ($summary['receitas'] ?? 0) - ($summary['despesas'] ?? 0);
        
        // Retorna a resposta pro Front-end no formato JSON
        jsonResponse(['success' => true, 'data' => $summary]);
        break;

    // ── Listar transações ─────────────────────────────────────
    // Usado para montar a tabela/lista central de transações
    case 'list':
        $mes  = intval($_GET['mes']  ?? date('n'));
        $ano  = intval($_GET['ano']  ?? date('Y'));
        $tipo = sanitize($_GET['tipo'] ?? '');

        // Busca todas as transações e faz um JOIN com a tabela 'usuarios' para saber quem cadastrou o gasto/receita
        $sql = 'SELECT t.*, u.nome AS usuario_nome FROM transacoes t
                JOIN usuarios u ON u.id = t.usuario_id
                WHERE t.familia_id = ? AND MONTH(t.data) = ? AND YEAR(t.data) = ?';
        
        $params = [$familia_id, $mes, $ano];
        
        // Se o front-end pediu um filtro específico (ex: só despesas), adiciona isso à query dinamicamente
        if ($tipo) { 
            $sql .= ' AND t.tipo = ?'; 
            $params[] = $tipo; 
        }
        
        // Ordena da data mais recente para a mais antiga
        $sql .= ' ORDER BY t.data DESC';

        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        
        // Retorna todos os resultados encontrados (fetchAll) em formato JSON
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ── Criar transação ───────────────────────────────────────
    // Chamado quando o usuário clica em "Salvar" no Modal de nova transação
    case 'create':
        // Recebe e limpa (sanitize) os dados enviados via formulário POST
        $tipo      = sanitize($_POST['tipo'] ?? '');
        $descricao = sanitize($_POST['descricao'] ?? '');
        $valor     = floatval($_POST['valor'] ?? 0); // Garante que o valor será um número decimal
        $categoria = sanitize($_POST['categoria'] ?? '');
        $data      = sanitize($_POST['data'] ?? date('Y-m-d'));

        // Validação básica: Garante que o tipo é válido, tem descrição e o valor não é zero
        if (!in_array($tipo, ['receita','despesa']) || !$descricao || !$valor) {
            jsonResponse(['success' => false, 'message' => 'Dados inválidos.'], 400); // 400 = Bad Request (Erro do cliente)
        }

        // Insere os dados na tabela transacoes
        $stmt = getDB()->prepare('INSERT INTO transacoes (familia_id, usuario_id, tipo, descricao, valor, categoria, data) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$familia_id, $user_id, $tipo, $descricao, $valor, $categoria, $data]);
        
        // Retorna sucesso e devolve o ID da transação que acabou de ser criada no banco
        jsonResponse(['success' => true, 'id' => getDB()->lastInsertId()]);
        break;

    // ── Excluir transação ─────────────────────────────────────
    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        
        // IMPORTANTE: Exclui pelo ID da transação E pela familia_id.
        // Isso impede que um usuário mal-intencionado exclua transações de outra família passando um ID aleatório.
        $stmt = getDB()->prepare('DELETE FROM transacoes WHERE id = ? AND familia_id = ?');
        $stmt->execute([$id, $familia_id]);
        
        jsonResponse(['success' => true]);
        break;

    // ── Metas ─────────────────────────────────────────────────
    // Retorna a lista de metas financeiras para desenhar as barras de progresso
    case 'metas':
        $stmt = getDB()->prepare('SELECT * FROM metas_financeiras WHERE familia_id = ? ORDER BY criado_em DESC');
        $stmt->execute([$familia_id]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ── Criar Meta ────────────────────────────────────────────
    case 'create_meta':
        $titulo     = sanitize($_POST['titulo'] ?? '');
        $valor_meta = floatval($_POST['valor_meta'] ?? 0);
        $valor_atual= floatval($_POST['valor_atual'] ?? 0);
        $prazo      = sanitize($_POST['prazo'] ?? '') ?: null; // Se vazio, salva como NULL no banco
        $icone      = sanitize($_POST['icone'] ?? '🎯');

        // Impede a criação de metas sem título ou sem valor objetivo
        if (!$titulo || !$valor_meta) {
            jsonResponse(['success' => false, 'message' => 'Dados inválidos.'], 400);
        }

        $stmt = getDB()->prepare('INSERT INTO metas_financeiras (familia_id, titulo, valor_meta, valor_atual, prazo, icone) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$familia_id, $titulo, $valor_meta, $valor_atual, $prazo, $icone]);
        
        jsonResponse(['success' => true, 'id' => getDB()->lastInsertId()]);
        break;

    // ── Ação Padrão (Fallback) ────────────────────────────────
    // Se o Front-end enviar uma 'action' que não existe no switch, retorna erro
    default:
        jsonResponse(['error' => 'Ação inválida.'], 400);
}