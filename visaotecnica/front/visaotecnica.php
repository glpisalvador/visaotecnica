<?php

/**
 * Plugin Visão Técnica - página (Assistência > Visão técnica). A estrutura é montada aqui e a
 * lista é carregada e atualizada pelo JS (front/ajax.php).
 */

Session::checkLoginUser();

$C = PluginVisaotecnicaConfig::class;
$e = [$C, 'e'];

if (!$C::podeUsar()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header('Visão técnica', $_SERVER['PHP_SELF'] ?? '', 'helpdesk', 'PluginVisaotecnicaMenu');
echo '<link rel="stylesheet" href="' . $e($C::urlAsset('css/visaotecnica.css')) . '">';

echo '<div class="visaotecnica" data-visaotecnica data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '" data-usuario="' . (int) Session::getLoginUserID() . '">';

// Visões (abas)
echo '<ul class="nav nav-tabs visaotecnica-escopos" data-escopos></ul>';

echo '<div class="card visaotecnica-card">';
// Barra de ferramentas
echo '<div class="card-header visaotecnica-barra">'
    . '<div class="visaotecnica-busca"><i class="ti ti-search"></i><input type="search" class="form-control form-control-sm" placeholder="Pesquisar nº, título ou requerente..." data-busca></div>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-alternar-filtros><i class="ti ti-filter"></i><span>Filtros</span><span class="visaotecnica-contador" data-contador-filtros hidden></span></button>'
    . '<div class="visaotecnica-grupo-botoes">'
    . '<select class="form-select form-select-sm visaotecnica-visoes" data-visoes title="Visões salvas"><option value="">Visões salvas</option></select>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-salvar-visao title="Salvar filtros e colunas como visão"><i class="ti ti-device-floppy"></i></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-excluir-visao title="Excluir a visão escolhida" hidden><i class="ti ti-trash"></i></button>'
    . '</div>'
    . '<div class="visaotecnica-direita">'
    . '<div class="visaotecnica-colunas-menu" data-menu-colunas><button type="button" class="btn btn-sm btn-ghost-secondary" data-abrir-colunas><i class="ti ti-columns"></i><span>Colunas</span></button><div class="visaotecnica-colunas-lista" hidden data-lista-colunas></div></div>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-exportar title="Exportar a visão atual (CSV)"><i class="ti ti-file-spreadsheet"></i><span>CSV</span></button>'
    . '<select class="form-select form-select-sm visaotecnica-intervalo" data-intervalo title="Atualização automática">'
    . '<option value="0">Atualizar: manual</option><option value="30">a cada 30 s</option><option value="60">a cada 1 min</option><option value="120">a cada 2 min</option><option value="300">a cada 5 min</option></select>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-atualizar title="Atualizar agora"><i class="ti ti-refresh"></i></button>'
    . '</div></div>';

// Filtros (recolhíveis)
echo '<div class="visaotecnica-filtros" data-filtros hidden><div class="visaotecnica-filtros-grade">'
    . '<div class="visaotecnica-campo"><label>Tipo</label><div data-ms="tipos"></div></div>'
    . '<div class="visaotecnica-campo"><label>Status</label><div data-ms="status"></div><small>Vazio: só os em aberto.</small></div>'
    . '<div class="visaotecnica-campo"><label>Prioridade</label><div data-ms="prioridades"></div></div>'
    . '<div class="visaotecnica-campo"><label>Entidade</label><div data-ms="entidades"></div><small>Inclui as entidades filhas.</small></div>'
    . '<div class="visaotecnica-campo"><label>Grupo técnico</label><div data-ms="grupos"></div></div>'
    . '<div class="visaotecnica-campo"><label>Técnico</label><div data-ms="tecnicos"></div></div>'
    . '<div class="visaotecnica-campo"><label>Categoria</label><div data-ms="categorias"></div></div>'
    . '<div class="visaotecnica-campo"><label>Prazo de solução</label><select class="form-select form-select-sm" data-filtro="prazo"><option value="">Qualquer</option><option value="vencido">Vencido</option><option value="hoje">Vence hoje</option><option value="noprazo">No prazo</option><option value="sem">Sem prazo</option></select></div>'
    . '<div class="visaotecnica-campo"><label>Sem atualização há</label><select class="form-select form-select-sm" data-filtro="parados"><option value="">Qualquer</option><option value="1">1 dia ou mais</option><option value="3">3 dias ou mais</option><option value="7">7 dias ou mais</option><option value="15">15 dias ou mais</option><option value="30">30 dias ou mais</option></select></div>'
    . '<div class="visaotecnica-campo visaotecnica-data" data-filtro-data="abertura_de"><label>Aberto de</label>' . Html::showDateField('abertura_de', ['value' => '', 'maybeempty' => true, 'display' => false]) . '</div>'
    . '<div class="visaotecnica-campo visaotecnica-data" data-filtro-data="abertura_ate"><label>até</label>' . Html::showDateField('abertura_ate', ['value' => '', 'maybeempty' => true, 'display' => false]) . '</div>'
    . '</div><div class="visaotecnica-filtros-rodape"><button type="button" class="btn btn-sm btn-ghost-secondary" data-limpar-filtros><i class="ti ti-filter-off"></i><span>Limpar filtros</span></button></div></div>';

echo '<div class="visaotecnica-chips" data-chips hidden></div>';

// Tabela
echo '<div class="card-body p-0"><div class="table-responsive visaotecnica-tabela-caixa"><table class="table table-sm table-hover visaotecnica-tabela" data-tabela><thead><tr></tr></thead>'
    . '<tbody><tr><td class="visaotecnica-vazio"><i class="ti ti-loader-2"></i> Carregando...</td></tr></tbody></table></div></div>';

echo '<div class="card-footer visaotecnica-rodape"><span class="visaotecnica-pequeno" data-resumo></span>'
    . '<nav class="visaotecnica-paginacao" data-paginacao></nav>'
    . '<span class="visaotecnica-pequeno visaotecnica-hora" data-hora></span></div>';
echo '</div></div>';

echo '<script src="' . $e($C::urlAsset('js/visaotecnica.js')) . '"></script>';
Html::footer();
