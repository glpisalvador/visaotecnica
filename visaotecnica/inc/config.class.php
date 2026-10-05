<?php

/**
 * Plugin Visão Técnica - configurações, catálogos (visões, colunas, tipos) e utilitários comuns
 */
class PluginVisaotecnicaConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_visaotecnica_configs';

    /** itemtype => [rótulo, rótulo plural, ícone, tabela de usuários, tabela de grupos, chave] */
    public const TIPOS = [
        'Ticket'  => ['Chamado', 'Chamados', 'ti ti-ticket', 'glpi_tickets_users', 'glpi_groups_tickets', 'tickets_id'],
        'Problem' => ['Problema', 'Problemas', 'ti ti-alert-triangle', 'glpi_problems_users', 'glpi_groups_problems', 'problems_id'],
        'Change'  => ['Mudança', 'Mudanças', 'ti ti-exchange', 'glpi_changes_users', 'glpi_changes_groups', 'changes_id'],
    ];

    /** Visões (escopos): chave => [rótulo, ícone, só para quem vê tudo] */
    public const ESCOPOS = [
        'meus'      => ['Atribuídos a mim', 'ti ti-user-check', false],
        'grupo'     => ['Do meu grupo', 'ti ti-users', false],
        'fila'      => ['Fila do grupo', 'ti ti-inbox', false],
        'acompanho' => ['Que acompanho', 'ti ti-eye', false],
        'abri'      => ['Que abri', 'ti ti-user', false],
        'sem'       => ['Sem atribuição', 'ti ti-user-question', true],
        'todos'     => ['Todos', 'ti ti-list', true],
    ];

    /** Colunas: chave => [rótulo, ordenável] */
    public const COLUNAS = [
        'id'          => ['ID', true],
        'tipo'        => ['Tipo', true],
        'titulo'      => ['Título', true],
        'status'      => ['Status', true],
        'prioridade'  => ['Prioridade', true],
        'entidade'    => ['Entidade', true],
        'requerente'  => ['Requerente', true],
        'tecnico'     => ['Técnico', true],
        'grupo'       => ['Grupo', true],
        'categoria'   => ['Categoria', true],
        'abertura'    => ['Abertura', true],
        'atualizacao' => ['Última atualização', true],
        'ultimo'      => ['Último acompanhamento', true],
        'prazo'       => ['Prazo', true],
        'papel'       => ['Meu papel', true],
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Visão Técnica';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'perfis'      => [],
            'perfis_todos' => [],
            'tipos'       => array_keys(self::TIPOS),
            'parado_dias' => '3',
            'intervalo'   => '60',
            'colunas'     => ['id', 'tipo', 'titulo', 'status', 'prioridade', 'entidade', 'requerente', 'tecnico', 'atualizacao', 'ultimo', 'prazo'],
            'limite'      => '1000',
            'por_pagina'  => '25',
        ];
    }

    private static ?array $visaotecnicaConfigs = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$visaotecnicaConfigs === null) {
            self::$visaotecnicaConfigs = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$visaotecnicaConfigs[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$visaotecnicaConfigs)) {
            return self::$visaotecnicaConfigs[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$visaotecnicaConfigs = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE));
    }

    public static function ids(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    // =====================================================================
    // Acesso
    // =====================================================================

    /** Perfil ativo pode usar a Visão técnica (lista vazia = perfis da interface padrão) */
    public static function podeUsar(): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }
        $perfis = self::ids('perfis');
        $ok = $perfis
            ? in_array((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0), $perfis, true)
            : ($_SESSION['glpiactiveprofile']['interface'] ?? '') !== 'helpdesk';
        return $ok && self::tiposPermitidos() !== [];
    }

    /** Pode ver as visões "Sem atribuição" e "Todos" (perfis liberados ou direito de ver todos os chamados) */
    public static function veTudo(): bool
    {
        $perfis = self::ids('perfis_todos');
        if ($perfis) {
            return in_array((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0), $perfis, true);
        }
        return (bool) Session::haveRight('ticket', Ticket::READALL);
    }

    /** Tipos ligados na configuração e que a pessoa pode ler */
    public static function tiposPermitidos(): array
    {
        $ligados = array_intersect(array_keys(self::TIPOS), array_map('strval', self::getArrayConfig('tipos')));
        return array_values(array_filter($ligados, fn($t) => $t::canView()));
    }

    public static function escoposPermitidos(): array
    {
        $ve = self::veTudo();
        return array_values(array_filter(array_keys(self::ESCOPOS), fn($e) => $ve || !self::ESCOPOS[$e][2]));
    }

    // =====================================================================
    // Listas e utilitários
    // =====================================================================

    /** glpi_profiles não tem is_deleted */
    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = ['rotulo' => (string) $r['name'], 'detalhe' => $r['interface'] === 'helpdesk' ? 'simplificada' : 'padrão'];
        }
        return $lista;
    }

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/visaotecnica/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ (servido em /plugins/<nome>/ no GLPI 11/12), com versão e data do arquivo */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $arquivo = dirname(__DIR__) . '/public/' . $caminho;
        return $CFG_GLPI['root_doc'] . '/plugins/visaotecnica/' . $caminho . '?v=' . PLUGIN_VISAOTECNICA_VERSION . '-' . (is_file($arquivo) ? filemtime($arquivo) : 0);
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /**
     * Multiselect com pesquisa, marcar todos e selecionados primeiro (página de configuração).
     * $opcoes: [valor => rótulo] ou [valor => ['rotulo' => ..., 'detalhe' => ...]]
     */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...'): string
    {
        $selecionados = array_map('strval', $selecionados);
        $itens = [];
        foreach ($opcoes as $valor => $o) {
            $o = is_array($o) ? $o : ['rotulo' => $o];
            $itens[] = ['valor' => (string) $valor, 'marcado' => in_array((string) $valor, $selecionados, true)] + $o + ['detalhe' => ''];
        }
        usort($itens, fn($a, $b) => [$b['marcado'], mb_strtolower($a['rotulo'])] <=> [$a['marcado'], mb_strtolower($b['rotulo'])]);
        $h = '<div class="visaotecnica-ms" data-visaotecnica-ms data-placeholder="' . self::e($placeholder) . '">';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="-1">';
        $h .= '<button type="button" class="visaotecnica-ms-cabecalho form-select form-select-sm" data-visaotecnica-ms-abrir><span class="visaotecnica-ms-texto"></span></button>';
        $h .= '<div class="visaotecnica-ms-dropdown" hidden>';
        $h .= '<div class="visaotecnica-ms-topo"><input type="text" class="form-control form-control-sm visaotecnica-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="visaotecnica-ms-todos"><input type="checkbox" class="visaotecnica-check" data-visaotecnica-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="visaotecnica-ms-opcoes">';
        foreach ($itens as $i) {
            $h .= '<label class="visaotecnica-ms-opcao' . ($i['marcado'] ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower($i['rotulo'] . ' ' . $i['detalhe'])) . '">'
                . '<input type="checkbox" class="visaotecnica-check" name="' . self::e($name) . '[]" value="' . self::e($i['valor']) . '"' . ($i['marcado'] ? ' checked' : '') . '>'
                . '<span class="visaotecnica-ms-rotulo">' . self::e($i['rotulo']) . ($i['detalhe'] !== '' ? ' <small>' . self::e($i['detalhe']) . '</small>' : '') . '</span>'
                . '</label>';
        }
        $h .= '</div></div><div class="visaotecnica-ms-contador"></div></div>';
        return $h;
    }

    public static function idsPost(string $campo): array
    {
        $ids = array_map('intval', (array) ($_POST[$campo] ?? []));
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }
}
