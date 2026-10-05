<?php

/**
 * Plugin Visão Técnica - visões salvas (filtros + colunas com nome) e preferências de cada pessoa
 */
class PluginVisaotecnicaVisao
{
    public const VISOES = 'glpi_plugin_visaotecnica_visoes';
    public const PREFERENCIAS = 'glpi_plugin_visaotecnica_preferencias';
    public const INTERVALOS = [0, 30, 60, 120, 300];

    /** Mantém só chaves conhecidas dos filtros, com tipos seguros */
    public static function limparFiltros(array $f): array
    {
        $listas = ['tipos', 'status', 'prioridades', 'entidades', 'categorias', 'grupos', 'tecnicos', 'colunas'];
        $r = [];
        foreach ($listas as $k) {
            if (!empty($f[$k]) && is_array($f[$k])) {
                $r[$k] = array_values(array_slice(array_map(fn($v) => mb_substr((string) $v, 0, 60), $f[$k]), 0, 500));
            }
        }
        foreach (['escopo', 'abertura_de', 'abertura_ate', 'prazo', 'busca', 'ordem', 'direcao'] as $k) {
            if (isset($f[$k]) && is_scalar($f[$k]) && (string) $f[$k] !== '') {
                $r[$k] = mb_substr((string) $f[$k], 0, 100);
            }
        }
        if (!empty($f['parados'])) {
            $r['parados'] = max(1, min(365, (int) $f['parados']));
        }
        return $r;
    }

    public static function listar(int $usuario): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['FROM' => self::VISOES, 'WHERE' => ['users_id' => $usuario], 'ORDER' => 'nome ASC']) as $r) {
            $lista[] = [
                'id'      => (int) $r['id'],
                'nome'    => (string) $r['nome'],
                'filtros' => json_decode((string) $r['filtros'], true) ?: [],
                'padrao'  => (bool) $r['is_default'],
            ];
        }
        return $lista;
    }

    /** Salva (ou substitui a de mesmo nome); retorna o id */
    public static function salvar(int $usuario, string $nome, array $filtros, bool $padrao): int
    {
        global $DB;
        $nome = mb_substr(trim((string) preg_replace('/\s+/', ' ', $nome)), 0, 100);
        if ($nome === '') {
            return 0;
        }
        if ($padrao) {
            $DB->update(self::VISOES, ['is_default' => 0], ['users_id' => $usuario]);
        }
        $dados = ['filtros' => json_encode(self::limparFiltros($filtros), JSON_UNESCAPED_UNICODE), 'is_default' => $padrao ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s')];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::VISOES, 'WHERE' => ['users_id' => $usuario, 'nome' => $nome], 'LIMIT' => 1]) as $r) {
            $DB->update(self::VISOES, $dados, ['id' => (int) $r['id']]);
            return (int) $r['id'];
        }
        $DB->insert(self::VISOES, $dados + ['users_id' => $usuario, 'nome' => $nome, 'date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    public static function excluir(int $usuario, int $id): bool
    {
        global $DB;
        $DB->delete(self::VISOES, ['id' => $id, 'users_id' => $usuario]);
        return $DB->affectedRows() > 0;
    }

    public static function preferencias(int $usuario): array
    {
        global $DB;
        $C = PluginVisaotecnicaConfig::class;
        $p = [
            'colunas'    => array_values(array_intersect(array_keys($C::COLUNAS), $C::getArrayConfig('colunas'))),
            'intervalo'  => (int) $C::getConfig('intervalo'),
            'por_pagina' => (int) $C::getConfig('por_pagina'),
        ];
        foreach ($DB->request(['FROM' => self::PREFERENCIAS, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1]) as $r) {
            $colunas = json_decode((string) $r['colunas'], true);
            if (is_array($colunas) && $colunas) {
                $p['colunas'] = array_values(array_intersect(array_keys($C::COLUNAS), $colunas));
            }
            $p['intervalo'] = (int) $r['intervalo'];
            $p['por_pagina'] = (int) $r['por_pagina'];
        }
        if (!in_array($p['intervalo'], self::INTERVALOS, true)) {
            $p['intervalo'] = 60;
        }
        $p['por_pagina'] = max(10, min(200, $p['por_pagina'] ?: 25));
        if (!$p['colunas']) {
            $p['colunas'] = ['id', 'titulo', 'status'];
        }
        return $p;
    }

    public static function salvarPreferencias(int $usuario, array $p): bool
    {
        global $DB;
        $atual = self::preferencias($usuario);
        $dados = [
            'colunas'    => json_encode(array_values(array_intersect(array_keys(PluginVisaotecnicaConfig::COLUNAS), (array) ($p['colunas'] ?? $atual['colunas'])))),
            'intervalo'  => in_array((int) ($p['intervalo'] ?? $atual['intervalo']), self::INTERVALOS, true) ? (int) ($p['intervalo'] ?? $atual['intervalo']) : 60,
            'por_pagina' => max(10, min(200, (int) ($p['por_pagina'] ?? $atual['por_pagina']))),
        ];
        if (count($DB->request(['FROM' => self::PREFERENCIAS, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1])) > 0) {
            return (bool) $DB->update(self::PREFERENCIAS, $dados, ['users_id' => $usuario]);
        }
        return (bool) $DB->insert(self::PREFERENCIAS, $dados + ['users_id' => $usuario]);
    }
}
