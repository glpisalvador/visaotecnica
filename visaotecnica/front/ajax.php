<?php

/**
 * Plugin Visão Técnica - endpoint AJAX (sempre JSON).
 * GET: opcoes, listar. POST: salvar_visao, excluir_visao, preferencias, acompanhar, assumir.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginVisaotecnicaConfig::class;
$Q = PluginVisaotecnicaConsulta::class;
$V = PluginVisaotecnicaVisao::class;
$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$responder = function (array $dados) use ($C, $post): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = $post ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem, array $extra = []) => $responder(['success' => false, 'mensagem' => $mensagem] + $extra);

$usuario = (int) Session::getLoginUserID();
if ($usuario <= 0) {
    $falhar('Sessão expirada. Recarregue a página.', ['sessao' => false]);
}
if (!$C::podeUsar()) {
    $falhar('Seu perfil não usa a Visão técnica.');
}
$acao = (string) ($_REQUEST['action'] ?? '');
$exigirPost = function () use ($post, $falhar): void {
    if (!$post) {
        $falhar('Requisição inválida.');
    }
};
/** Item ITIL do POST, já verificado (tipo permitido e visível) */
$item = function () use ($C, $falhar): CommonITILObject {
    $tipo = (string) ($_POST['itemtype'] ?? '');
    if (!in_array($tipo, $C::tiposPermitidos(), true)) {
        $falhar('Tipo inválido.');
    }
    $i = new $tipo();
    if (!$i->getFromDB((int) ($_POST['items_id'] ?? 0)) || !$i->canViewItem()) {
        $falhar('Item não encontrado.');
    }
    return $i;
};

try {
    switch ($acao) {
        case 'opcoes':
            $escopos = [];
            foreach ($C::escoposPermitidos() as $e) {
                $escopos[] = ['chave' => $e, 'rotulo' => $C::ESCOPOS[$e][0], 'icone' => $C::ESCOPOS[$e][1]];
            }
            $colunas = [];
            foreach ($C::COLUNAS as $k => [$rotulo, $ordenavel]) {
                $colunas[] = ['chave' => $k, 'rotulo' => $rotulo, 'ordenavel' => $ordenavel];
            }
            $responder(['success' => true, 'escopos' => $escopos, 'colunas' => $colunas, 'opcoes' => $Q::opcoes(), 'preferencias' => $V::preferencias($usuario), 'visoes' => $V::listar($usuario), 'parado_dias' => (int) $C::getConfig('parado_dias')]);

        case 'listar':
            $f = json_decode((string) ($_GET['f'] ?? '{}'), true);
            $f = $V::limparFiltros(is_array($f) ? $f : []);
            $f['pagina'] = (int) ($_GET['pagina'] ?? 1);
            $f['por_pagina'] = (int) ($_GET['por_pagina'] ?? 25);
            $exportar = !empty($_GET['exportar']);
            $r = $Q::listar($usuario, $f, !$exportar);
            if (!$exportar) {
                $r['contagens'] = $Q::contagens($usuario, $f);
            }
            $responder(['success' => true, 'gerado_em' => date('H:i:s')] + $r);

        case 'salvar_visao':
            $exigirPost();
            $filtros = json_decode((string) ($_POST['filtros'] ?? '{}'), true);
            $id = $V::salvar($usuario, (string) ($_POST['nome'] ?? ''), is_array($filtros) ? $filtros : [], !empty($_POST['padrao']));
            if ($id <= 0) {
                $falhar('Dê um nome para a visão.');
            }
            $responder(['success' => true, 'id' => $id, 'visoes' => $V::listar($usuario), 'mensagem' => 'Visão salva.']);

        case 'excluir_visao':
            $exigirPost();
            $V::excluir($usuario, (int) ($_POST['id'] ?? 0));
            $responder(['success' => true, 'visoes' => $V::listar($usuario), 'mensagem' => 'Visão excluída.']);

        case 'preferencias':
            $exigirPost();
            $V::salvarPreferencias($usuario, [
                'colunas'    => array_map('strval', (array) ($_POST['colunas'] ?? [])),
                'intervalo'  => (int) ($_POST['intervalo'] ?? 60),
                'por_pagina' => (int) ($_POST['por_pagina'] ?? 25),
            ]);
            $responder(['success' => true, 'preferencias' => $V::preferencias($usuario)]);

        case 'acompanhar':
            $exigirPost();
            $i = $item();
            if (!$i->canAddFollowups()) {
                $falhar('Você não pode adicionar acompanhamentos neste item.');
            }
            $conteudo = (string) ($_POST['conteudo'] ?? '');
            if (trim(strip_tags($conteudo, '<img>')) === '') {
                $falhar('Escreva o acompanhamento.');
            }
            $f = new ITILFollowup();
            $id = (int) $f->add([
                'itemtype'   => get_class($i),
                'items_id'   => (int) $i->getID(),
                'content'    => $conteudo,
                'is_private' => !empty($_POST['privado']) ? 1 : 0,
            ]);
            if ($id <= 0) {
                $falhar('O GLPI não aceitou o acompanhamento.');
            }
            $responder(['success' => true, 'mensagem' => 'Acompanhamento adicionado ao ' . mb_strtolower($C::TIPOS[get_class($i)][0]) . ' #' . $i->getID() . '.']);

        case 'assumir':
            $exigirPost();
            $i = $item();
            if (!$i->canAssignToMe()) {
                $falhar('Você não pode se atribuir a este item.');
            }
            $classe = $i->userlinkclass;
            $chave = getForeignKeyFieldForItemType(get_class($i));
            $ja = countElementsInTable(getTableForItemType($classe), [$chave => (int) $i->getID(), 'users_id' => $usuario, 'type' => CommonITILActor::ASSIGN]);
            if ($ja === 0) {
                $vinculo = new $classe();
                if (!$vinculo->add([$chave => (int) $i->getID(), 'users_id' => $usuario, 'type' => CommonITILActor::ASSIGN])) {
                    $falhar('O GLPI não aceitou a atribuição.');
                }
            }
            $responder(['success' => true, 'mensagem' => 'Você foi atribuído ao ' . mb_strtolower($C::TIPOS[get_class($i)][0]) . ' #' . $i->getID() . '.']);
    }
    $falhar('Ação desconhecida.');
} catch (\Throwable $e) {
    error_log('Plugin visaotecnica: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    $falhar('Não foi possível concluir a operação.');
}
