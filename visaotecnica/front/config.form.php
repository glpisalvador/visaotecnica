<?php

/**
 * Plugin Visão Técnica - configuração (marketplace e menu). Faz POST para esta mesma página.
 */

Session::checkLoginUser();

$C = PluginVisaotecnicaConfig::class;
$V = PluginVisaotecnicaVisao::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['save_action'] ?? '') === 'salvar_geral') {
    $C::setArrayConfig('perfis', $C::idsPost('perfis'));
    $C::setArrayConfig('perfis_todos', $C::idsPost('perfis_todos'));
    $C::setArrayConfig('tipos', array_values(array_intersect(array_keys($C::TIPOS), array_map('strval', (array) ($_POST['tipos'] ?? [])))));
    $colunas = array_values(array_intersect(array_keys($C::COLUNAS), array_map('strval', (array) ($_POST['colunas'] ?? []))));
    $C::setArrayConfig('colunas', $colunas ?: $C::padroes()['colunas']);
    $C::setConfig('parado_dias', (string) max(1, min(90, (int) ($_POST['parado_dias'] ?? 3))));
    $intervalo = (int) ($_POST['intervalo'] ?? 60);
    $C::setConfig('intervalo', (string) (in_array($intervalo, $V::INTERVALOS, true) ? $intervalo : 60));
    $C::setConfig('limite', (string) max(50, min(5000, (int) ($_POST['limite'] ?? 1000))));
    $C::setConfig('por_pagina', (string) max(10, min(200, (int) ($_POST['por_pagina'] ?? 25))));
    Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
}

Html::header('Visão Técnica', $_SERVER['PHP_SELF'] ?? '', 'helpdesk', 'PluginVisaotecnicaMenu');
echo '<link rel="stylesheet" href="' . $e($C::urlAsset('css/visaotecnica.css')) . '">';

$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card visaotecnica-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body visaotecnica-corpo">' . $corpo . '</div></div>';
$campo = fn(string $rotulo, string $controle, string $dica = '') => '<div class="visaotecnica-campo"><label>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';
$numero = fn(string $nome, int $valor, int $min, int $max) => '<input type="number" class="form-control form-control-sm visaotecnica-numero" name="' . $nome . '" min="' . $min . '" max="' . $max . '" value="' . $valor . '">';
$explicacao = fn(string $texto) => '<p class="visaotecnica-explicacao"><i class="ti ti-info-circle"></i><span>' . $texto . '</span></p>';

$tipos = '';
foreach ($C::TIPOS as $t => [, $plural, $icone]) {
    $tipos .= '<label class="visaotecnica-opcao"><input type="checkbox" class="visaotecnica-check" name="tipos[]" value="' . $e($t) . '"' . (in_array($t, $C::getArrayConfig('tipos'), true) ? ' checked' : '') . '><i class="' . $e($icone) . '"></i> ' . $e($plural) . '</label>';
}
$colunas = '';
foreach ($C::COLUNAS as $k => [$rotulo]) {
    $colunas .= '<label class="visaotecnica-opcao"><input type="checkbox" class="visaotecnica-check" name="colunas[]" value="' . $e($k) . '"' . (in_array($k, $C::getArrayConfig('colunas'), true) ? ' checked' : '') . '> ' . $e($rotulo) . '</label>';
}
$intervalos = '';
foreach ([0 => 'Manual', 30 => '30 segundos', 60 => '1 minuto', 120 => '2 minutos', 300 => '5 minutos'] as $v => $rotulo) {
    $intervalos .= '<option value="' . $v . '"' . ((int) $C::getConfig('intervalo') === $v ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
}

echo '<div class="visaotecnica-pagina">';
echo '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="visaotecnica-form"><input type="hidden" name="save_action" value="salvar_geral">';
echo '<div class="visaotecnica-grade-config">';
echo $card('ti ti-shield-lock', 'Acesso', $explicacao('A Visão técnica fica em Assistência e mostra só itens das entidades ativas da pessoa, dos tipos que ela pode ler.')
    . $campo('Perfis com acesso', $C::multiselect('perfis', $C::listarPerfis(), $C::ids('perfis'), 'Todos da interface padrão'), 'Vazio: todos os perfis da interface padrão.')
    . $campo('Perfis que veem "Sem atribuição" e "Todos"', $C::multiselect('perfis_todos', $C::listarPerfis(), $C::ids('perfis_todos'), 'Quem tem "ver todos os chamados"'), 'Vazio: quem tem o direito nativo de ver todos os chamados.')
    . $campo('Tipos', '<div class="visaotecnica-opcoes">' . $tipos . '<input type="hidden" name="tipos[]" value=""></div>'));
echo $card('ti ti-columns', 'Colunas padrão', $explicacao('Valem para quem ainda não escolheu as próprias colunas em "Colunas".') . '<div class="visaotecnica-opcoes visaotecnica-opcoes-coluna">' . $colunas . '<input type="hidden" name="colunas[]" value=""></div>');
echo $card('ti ti-adjustments', 'Comportamento', $campo('Atualização automática padrão', '<select class="form-select form-select-sm visaotecnica-numero" name="intervalo">' . $intervalos . '</select>', 'Cada pessoa pode mudar na própria tela.')
    . $campo('Itens por página padrão', $numero('por_pagina', (int) $C::getConfig('por_pagina'), 10, 200))
    . $campo('"Parado" a partir de (dias sem atualização)', $numero('parado_dias', (int) $C::getConfig('parado_dias'), 1, 90), 'A coluna "Última atualização" fica destacada a partir daqui.')
    . $campo('Limite de itens por tipo', $numero('limite', (int) $C::getConfig('limite'), 50, 5000), 'Protege o servidor em visões muito grandes; acima disso a tela avisa para usar filtros.'));
echo '</div>';
echo '<div class="visaotecnica-rodape-form"><button type="submit" class="btn btn-sm visaotecnica-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
echo Html::closeForm(false);
echo '</div>';
echo '<script src="' . $e($C::urlAsset('js/visaotecnica.js')) . '"></script>';
Html::footer();
