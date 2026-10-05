<?php

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;

/**
 * Plugin Visão Técnica - consulta dos itens (chamados, problemas e mudanças).
 * Uma consulta por tipo com subconsultas para a visão e os filtros, sempre dentro das entidades
 * da pessoa. Nomes de atores, grupos e o último acompanhamento são buscados em lote.
 */
class PluginVisaotecnicaConsulta
{
    private static array $visaotecnicaCache = [];

    // =====================================================================
    // Apoio
    // =====================================================================

    /** Grupos da pessoa (glpi_groups não tem is_deleted) */
    public static function gruposDoUsuario(int $usuario): array
    {
        global $DB;
        if (!isset(self::$visaotecnicaCache['grupos'][$usuario])) {
            $ids = [];
            foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $usuario]]) as $r) {
                $ids[] = (int) $r['groups_id'];
            }
            self::$visaotecnicaCache['grupos'][$usuario] = $ids;
        }
        return self::$visaotecnicaCache['grupos'][$usuario];
    }

    /** Status em aberto do tipo (tudo menos solucionado e fechado) */
    public static function statusAbertos(string $tipo): array
    {
        $todos = array_keys($tipo::getAllStatusArray());
        return array_values(array_diff($todos, $tipo::getSolvedStatusArray(), $tipo::getClosedStatusArray()));
    }

    private static function sub(string $tabela, string $chave, array $where): QuerySubQuery
    {
        return new QuerySubQuery(['SELECT' => $chave, 'FROM' => $tabela, 'WHERE' => $where]);
    }

    /** Condição da visão (escopo) para o tipo */
    private static function criterioEscopo(string $tipo, string $escopo, int $usuario): array
    {
        [, , , $tUsuarios, $tGrupos, $chave] = PluginVisaotecnicaConfig::TIPOS[$tipo];
        $grupos = self::gruposDoUsuario($usuario) ?: [-1];
        $tecnico = CommonITILActor::ASSIGN;
        $observador = CommonITILActor::OBSERVER;
        switch ($escopo) {
            case 'meus':
                return [['t.id' => self::sub($tUsuarios, $chave, ['users_id' => $usuario, 'type' => $tecnico])]];
            case 'grupo':
                return [['t.id' => self::sub($tGrupos, $chave, ['groups_id' => $grupos, 'type' => $tecnico])]];
            case 'fila':
                return [
                    ['t.id' => self::sub($tGrupos, $chave, ['groups_id' => $grupos, 'type' => $tecnico])],
                    ['NOT' => ['t.id' => self::sub($tUsuarios, $chave, ['type' => $tecnico])]],
                ];
            case 'acompanho':
                return [['OR' => [
                    ['t.id' => self::sub($tUsuarios, $chave, ['users_id' => $usuario, 'type' => $observador])],
                    ['t.id' => self::sub($tGrupos, $chave, ['groups_id' => $grupos, 'type' => $observador])],
                ]]];
            case 'abri':
                return [['OR' => [
                    ['t.id' => self::sub($tUsuarios, $chave, ['users_id' => $usuario, 'type' => CommonITILActor::REQUESTER])],
                    ['t.users_id_recipient' => $usuario],
                ]]];
            case 'sem':
                return [
                    ['NOT' => ['t.id' => self::sub($tUsuarios, $chave, ['type' => $tecnico])]],
                    ['NOT' => ['t.id' => self::sub($tGrupos, $chave, ['type' => $tecnico])]],
                ];
        }
        return [];
    }

    /** Condições dos filtros para o tipo; null quando o tipo fica de fora */
    private static function criterioFiltros(string $tipo, array $f): ?array
    {
        [, , , $tUsuarios, $tGrupos, $chave] = PluginVisaotecnicaConfig::TIPOS[$tipo];
        $w = [];
        if (!empty($f['tipos']) && !in_array($tipo, $f['tipos'], true)) {
            return null;
        }
        // Status: "Ticket:2"; sem status escolhido = em aberto
        $status = [];
        $algumStatus = false;
        foreach ((array) ($f['status'] ?? []) as $s) {
            [$st, $valor] = array_pad(explode(':', (string) $s, 2), 2, '');
            $algumStatus = true;
            if ($st === $tipo) {
                $status[] = (int) $valor;
            }
        }
        if ($algumStatus && !$status) {
            return null;
        }
        $w[] = ['t.status' => $status ?: (self::statusAbertos($tipo) ?: [-1])];
        if (!empty($f['prioridades'])) {
            $w[] = ['t.priority' => array_map('intval', $f['prioridades'])];
        }
        if (!empty($f['entidades'])) {
            $entidades = [];
            foreach (array_map('intval', $f['entidades']) as $e) {
                $entidades = array_merge($entidades, array_map('intval', getSonsOf('glpi_entities', $e)));
            }
            $w[] = ['t.entities_id' => array_values(array_unique($entidades)) ?: [-1]];
        }
        if (!empty($f['categorias'])) {
            $w[] = ['t.itilcategories_id' => array_map('intval', $f['categorias'])];
        }
        if (!empty($f['grupos'])) {
            $w[] = ['t.id' => self::sub($tGrupos, $chave, ['groups_id' => array_map('intval', $f['grupos']), 'type' => CommonITILActor::ASSIGN])];
        }
        if (!empty($f['tecnicos'])) {
            $tecnicos = array_map('intval', $f['tecnicos']);
            $semTecnico = in_array(0, $tecnicos, true);
            $comTecnico = array_values(array_filter($tecnicos, fn($v) => $v > 0));
            $ou = [];
            if ($comTecnico) {
                $ou[] = ['t.id' => self::sub($tUsuarios, $chave, ['users_id' => $comTecnico, 'type' => CommonITILActor::ASSIGN])];
            }
            if ($semTecnico) {
                $ou[] = ['NOT' => ['t.id' => self::sub($tUsuarios, $chave, ['type' => CommonITILActor::ASSIGN])]];
            }
            $w[] = ['OR' => $ou];
        }
        if (!empty($f['abertura_de']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $f['abertura_de'])) {
            $w[] = ['t.date' => ['>=', substr((string) $f['abertura_de'], 0, 10) . ' 00:00:00']];
        }
        if (!empty($f['abertura_ate']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $f['abertura_ate'])) {
            $w[] = ['t.date' => ['<=', substr((string) $f['abertura_ate'], 0, 10) . ' 23:59:59']];
        }
        if (!empty($f['parados'])) {
            $w[] = ['t.date_mod' => ['<', date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $f['parados']) . ' days'))]];
        }
        $agora = date('Y-m-d H:i:s');
        switch ((string) ($f['prazo'] ?? '')) {
            case 'vencido':
                $w[] = ['t.time_to_resolve' => ['<', $agora]];
                break;
            case 'hoje':
                $w[] = ['t.time_to_resolve' => ['>=', $agora]];
                $w[] = ['t.time_to_resolve' => ['<=', date('Y-m-d 23:59:59')]];
                break;
            case 'noprazo':
                $w[] = ['t.time_to_resolve' => ['>=', $agora]];
                break;
            case 'sem':
                $w[] = ['t.time_to_resolve' => null];
                break;
        }
        $busca = trim((string) ($f['busca'] ?? ''));
        if ($busca !== '') {
            $numero = ltrim($busca, '#');
            $termo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
            $ou = [
                ['t.name' => ['LIKE', $termo]],
                ['t.id' => new QuerySubQuery([
                    'SELECT'     => 'ut.' . $chave,
                    'FROM'       => $tUsuarios . ' AS ut',
                    'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['ut' => 'users_id', 'u' => 'id']]],
                    'WHERE'      => ['ut.type' => CommonITILActor::REQUESTER, 'OR' => [['u.name' => ['LIKE', $termo]], ['u.realname' => ['LIKE', $termo]], ['u.firstname' => ['LIKE', $termo]]]],
                ])],
            ];
            if (ctype_digit($numero)) {
                $ou[] = ['t.id' => (int) $numero];
            }
            $w[] = ['OR' => $ou];
        }
        return $w;
    }

    private static function criterioBase(string $tipo, string $escopo, int $usuario, array $f): ?array
    {
        $filtros = self::criterioFiltros($tipo, $f);
        if ($filtros === null) {
            return null;
        }
        return array_merge(
            ['t.is_deleted' => 0],
            getEntitiesRestrictCriteria('t', 'entities_id', '', false),
            self::criterioEscopo($tipo, $escopo, $usuario),
            $filtros
        );
    }

    // =====================================================================
    // Listagem
    // =====================================================================

    /** Visão válida para a pessoa */
    public static function escopo(string $escopo): string
    {
        $permitidos = PluginVisaotecnicaConfig::escoposPermitidos();
        return in_array($escopo, $permitidos, true) ? $escopo : $permitidos[0];
    }

    /** Quantos itens em cada visão, com os filtros atuais */
    public static function contagens(int $usuario, array $f): array
    {
        global $DB;
        $r = [];
        foreach (PluginVisaotecnicaConfig::escoposPermitidos() as $escopo) {
            $n = 0;
            foreach (PluginVisaotecnicaConfig::tiposPermitidos() as $tipo) {
                $where = self::criterioBase($tipo, $escopo, $usuario, $f);
                if ($where === null) {
                    continue;
                }
                foreach ($DB->request(['SELECT' => [new QueryExpression('COUNT(*) AS n')], 'FROM' => getTableForItemType($tipo) . ' AS t', 'WHERE' => $where]) as $l) {
                    $n += (int) $l['n'];
                }
            }
            $r[$escopo] = $n;
        }
        return $r;
    }

    /**
     * Itens da visão com os filtros, ordenados e paginados.
     * $f: escopo, tipos, status, prioridades, entidades, categorias, grupos, tecnicos, abertura_de,
     *     abertura_ate, parados, prazo, busca, ordem, direcao, pagina, por_pagina
     */
    public static function listar(int $usuario, array $f, bool $paginar = true): array
    {
        global $DB;
        $escopo = self::escopo((string) ($f['escopo'] ?? 'meus'));
        $limite = max(50, min(5000, (int) PluginVisaotecnicaConfig::getConfig('limite')));
        $linhas = [];
        $cortado = false;
        foreach (PluginVisaotecnicaConfig::tiposPermitidos() as $tipo) {
            $where = self::criterioBase($tipo, $escopo, $usuario, $f);
            if ($where === null) {
                continue;
            }
            $brutas = [];
            foreach ($DB->request([
                'SELECT' => ['t.id', 't.name', 't.status', 't.priority', 't.entities_id', 't.itilcategories_id', 't.date', 't.date_mod', 't.time_to_resolve', 't.users_id_recipient'],
                'FROM'   => getTableForItemType($tipo) . ' AS t',
                'WHERE'  => $where,
                'ORDER'  => 't.date_mod DESC',
                'LIMIT'  => $limite + 1,
            ]) as $r) {
                $brutas[(int) $r['id']] = $r;
            }
            if (count($brutas) > $limite) {
                $cortado = true;
                array_pop($brutas);
            }
            foreach (self::enriquecer($tipo, $brutas, $usuario) as $l) {
                $linhas[] = $l;
            }
        }

        // Ordenação
        $ordem = array_key_exists((string) ($f['ordem'] ?? ''), PluginVisaotecnicaConfig::COLUNAS) ? (string) $f['ordem'] : 'atualizacao';
        $direcao = strtolower((string) ($f['direcao'] ?? '')) === 'asc' ? 1 : -1;
        $valor = function (array $l) use ($ordem) {
            return match ($ordem) {
                'id'          => $l['id'],
                'tipo'        => $l['tipo_rotulo'],
                'titulo'      => mb_strtolower($l['titulo']),
                'status'      => $l['status'],
                'prioridade'  => $l['prioridade'],
                'entidade'    => mb_strtolower($l['entidade']),
                'requerente'  => mb_strtolower(implode(', ', $l['requerentes'])),
                'tecnico'     => mb_strtolower(implode(', ', $l['tecnicos'])),
                'grupo'       => mb_strtolower(implode(', ', $l['grupos'])),
                'categoria'   => mb_strtolower($l['categoria']),
                'abertura'    => $l['abertura'],
                'ultimo'      => $l['ultimo']['data'] ?? '',
                'prazo'       => $l['prazo']['data'] ?? '9999',
                'papel'       => implode(',', $l['papel']),
                default       => $l['atualizacao'],
            };
        };
        usort($linhas, fn($a, $b) => ($valor($a) <=> $valor($b)) * $direcao ?: ($b['atualizacao'] <=> $a['atualizacao']));

        $total = count($linhas);
        $porPagina = max(10, min(200, (int) ($f['por_pagina'] ?? PluginVisaotecnicaConfig::getConfig('por_pagina'))));
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        if ($paginar) {
            $paginas = max(1, (int) ceil($total / $porPagina));
            $pagina = min($pagina, $paginas);
            $linhas = array_slice($linhas, ($pagina - 1) * $porPagina, $porPagina);
        }
        return [
            'escopo'     => $escopo,
            'linhas'     => $linhas,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
            'cortado'    => $cortado,
            'limite'     => $limite,
            'ordem'      => $ordem,
            'direcao'    => $direcao === 1 ? 'asc' : 'desc',
        ];
    }

    /** Monta as linhas de um tipo com atores, grupos, categoria, entidade e último acompanhamento (em lote) */
    private static function enriquecer(string $tipo, array $brutas, int $usuario): array
    {
        global $DB, $CFG_GLPI;
        if (!$brutas) {
            return [];
        }
        [$rotulo, , $icone, $tUsuarios, $tGrupos, $chave] = PluginVisaotecnicaConfig::TIPOS[$tipo];
        $ids = array_keys($brutas);
        $meusGrupos = self::gruposDoUsuario($usuario);

        $atores = [];
        $pessoas = [];
        foreach (array_chunk($ids, 500) as $lote) {
            foreach ($DB->request(['SELECT' => [$chave, 'users_id', 'type', 'alternative_email'], 'FROM' => $tUsuarios, 'WHERE' => [$chave => $lote]]) as $a) {
                $atores[(int) $a[$chave]][] = $a;
                if ((int) $a['users_id'] > 0) {
                    $pessoas[(int) $a['users_id']] = true;
                }
            }
        }
        $nomes = self::nomesUsuarios(array_keys($pessoas));
        $gruposItem = [];
        $idsGrupos = [];
        foreach (array_chunk($ids, 500) as $lote) {
            foreach ($DB->request(['SELECT' => [$chave, 'groups_id', 'type'], 'FROM' => $tGrupos, 'WHERE' => [$chave => $lote]]) as $g) {
                $gruposItem[(int) $g[$chave]][] = $g;
                $idsGrupos[(int) $g['groups_id']] = true;
            }
        }
        $nomesGrupos = self::nomes('glpi_groups', array_keys($idsGrupos), 'completename');
        $nomesEntidades = self::nomes('glpi_entities', array_unique(array_map(fn($r) => (int) $r['entities_id'], $brutas)), 'name');
        $nomesCategorias = self::nomes('glpi_itilcategories', array_unique(array_map(fn($r) => (int) $r['itilcategories_id'], $brutas)), 'completename');

        // Último acompanhamento (privados só para quem pode vê-los)
        $vePrivados = (bool) Session::haveRight('followup', ITILFollowup::SEEPRIVATE);
        $ultimos = [];
        foreach (array_chunk($ids, 500) as $lote) {
            $where = ['itemtype' => $tipo, 'items_id' => $lote] + ($vePrivados ? [] : ['is_private' => 0]);
            $maxIds = [];
            foreach ($DB->request(['SELECT' => ['items_id', new QueryExpression('MAX(id) AS ultimo')], 'FROM' => 'glpi_itilfollowups', 'WHERE' => $where, 'GROUPBY' => 'items_id']) as $m) {
                $maxIds[] = (int) $m['ultimo'];
            }
            if ($maxIds) {
                foreach ($DB->request(['SELECT' => ['id', 'items_id', 'date', 'users_id', 'is_private'], 'FROM' => 'glpi_itilfollowups', 'WHERE' => ['id' => $maxIds]]) as $u) {
                    $ultimos[(int) $u['items_id']] = $u;
                    $pessoas[(int) $u['users_id']] = true;
                }
            }
        }
        $nomes += self::nomesUsuarios(array_diff(array_keys($pessoas), array_keys($nomes)));

        $agora = time();
        $linhas = [];
        foreach ($brutas as $id => $r) {
            $req = $tec = $obs = [];
            $papel = [];
            $requerentes = [];
            foreach ($atores[$id] ?? [] as $a) {
                $uid = (int) $a['users_id'];
                $nome = $uid > 0 ? ($nomes[$uid] ?? '') : (string) $a['alternative_email'];
                if ((int) $a['type'] === CommonITILActor::REQUESTER) {
                    $req[] = $nome;
                    $requerentes[$uid] = true;
                } elseif ((int) $a['type'] === CommonITILActor::ASSIGN) {
                    $tec[] = $nome;
                } elseif ((int) $a['type'] === CommonITILActor::OBSERVER) {
                    $obs[] = $nome;
                }
                if ($uid === $usuario) {
                    $papel[] = [CommonITILActor::REQUESTER => 'requerente', CommonITILActor::ASSIGN => 'tecnico', CommonITILActor::OBSERVER => 'observador'][(int) $a['type']] ?? '';
                }
            }
            $grp = [];
            foreach ($gruposItem[$id] ?? [] as $g) {
                if ((int) $g['type'] === CommonITILActor::ASSIGN) {
                    $grp[] = $nomesGrupos[(int) $g['groups_id']] ?? '';
                    if (in_array((int) $g['groups_id'], $meusGrupos, true)) {
                        $papel[] = 'grupo';
                    }
                } elseif ((int) $g['type'] === CommonITILActor::OBSERVER && in_array((int) $g['groups_id'], $meusGrupos, true)) {
                    $papel[] = 'observador';
                }
            }
            $u = $ultimos[$id] ?? null;
            $ultimo = null;
            if ($u) {
                $ultimo = [
                    'data'           => (string) $u['date'],
                    'data_txt'       => Html::convDateTime((string) $u['date']),
                    'autor'          => $nomes[(int) $u['users_id']] ?? '',
                    'do_requerente'  => isset($requerentes[(int) $u['users_id']]) && (int) $u['users_id'] !== $usuario,
                    'privado'        => (bool) $u['is_private'],
                    'idade_horas'    => (int) floor(max(0, $agora - (int) strtotime((string) $u['date'])) / 3600),
                ];
            }
            $prazo = null;
            if (!empty($r['time_to_resolve'])) {
                $fim = (int) strtotime((string) $r['time_to_resolve']);
                $inicio = (int) strtotime((string) $r['date']);
                $pct = $fim > $inicio ? (int) round(min(100, max(0, ($agora - $inicio) * 100 / ($fim - $inicio)))) : 100;
                $prazo = [
                    'data'     => (string) $r['time_to_resolve'],
                    'data_txt' => Html::convDateTime((string) $r['time_to_resolve']),
                    'pct'      => $pct,
                    'estado'   => $agora > $fim ? 'vencido' : ($pct >= 80 ? 'proximo' : 'ok'),
                ];
            }
            $prioridade = (int) $r['priority'];
            $linhas[] = [
                'chave'        => $tipo . ':' . $id,
                'itemtype'     => $tipo,
                'id'           => $id,
                'tipo_rotulo'  => $rotulo,
                'tipo_icone'   => $icone,
                'titulo'       => (string) $r['name'],
                'url'          => $tipo::getFormURLWithID($id),
                'status'       => (int) $r['status'],
                'status_rotulo' => (string) $tipo::getStatus((int) $r['status']),
                'status_icone' => (string) $tipo::getStatusIcon((int) $r['status']),
                'prioridade'   => $prioridade,
                'prioridade_rotulo' => (string) CommonITILObject::getPriorityName($prioridade),
                'prioridade_cor' => (string) ($_SESSION['glpipriority_' . $prioridade] ?? ''),
                'entidade'     => $nomesEntidades[(int) $r['entities_id']] ?? '',
                'requerentes'  => array_values(array_filter($req)),
                'tecnicos'     => array_values(array_filter($tec)),
                'observadores' => array_values(array_filter($obs)),
                'grupos'       => array_values(array_filter($grp)),
                'categoria'    => $nomesCategorias[(int) $r['itilcategories_id']] ?? '',
                'abertura'     => (string) $r['date'],
                'abertura_txt' => Html::convDateTime((string) $r['date']),
                'atualizacao'  => (string) $r['date_mod'],
                'atualizacao_txt' => Html::convDateTime((string) $r['date_mod']),
                'idade_horas'  => (int) floor(max(0, $agora - (int) strtotime((string) $r['date_mod'])) / 3600),
                'ultimo'       => $ultimo,
                'prazo'        => $prazo,
                'papel'        => array_values(array_unique(array_filter($papel))),
                'sem_tecnico'  => !$tec,
            ];
        }
        return $linhas;
    }

    public static function nomesUsuarios(array $ids): array
    {
        global $DB;
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        $nomes = [];
        foreach (array_chunk($ids, 500) as $lote) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $lote]]) as $u) {
                $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
                $nomes[(int) $u['id']] = $nome !== '' ? $nome : (string) $u['name'];
            }
        }
        return $nomes;
    }

    private static function nomes(string $tabela, array $ids, string $campo): array
    {
        global $DB;
        $ids = array_map('intval', $ids);
        // A entidade raiz tem id 0
        $minimo = $tabela === 'glpi_entities' ? 0 : 1;
        $ids = array_values(array_unique(array_filter($ids, fn($v) => $v >= $minimo)));
        $nomes = [];
        foreach (array_chunk($ids, 500) as $lote) {
            foreach ($DB->request(['SELECT' => ['id', $campo, 'name'], 'FROM' => $tabela, 'WHERE' => ['id' => $lote]]) as $r) {
                $nomes[(int) $r['id']] = (string) ($r[$campo] ?: $r['name']);
            }
        }
        return $nomes;
    }

    // =====================================================================
    // Opções dos filtros
    // =====================================================================

    public static function opcoes(): array
    {
        global $DB;
        $C = PluginVisaotecnicaConfig::class;
        $tipos = $C::tiposPermitidos();
        $status = [];
        foreach ($tipos as $t) {
            foreach ($t::getAllStatusArray() as $valor => $rotulo) {
                $status[] = ['valor' => $t . ':' . $valor, 'rotulo' => (string) $rotulo, 'detalhe' => $C::TIPOS[$t][0], 'aberto' => in_array((int) $valor, self::statusAbertos($t), true)];
            }
        }
        $prioridades = [];
        foreach ([6, 5, 4, 3, 2, 1] as $p) {
            $prioridades[] = ['valor' => (string) $p, 'rotulo' => (string) CommonITILObject::getPriorityName($p)];
        }
        // Entidades pai e sem filhas, dentro das entidades ativas da pessoa
        $ativas = array_map('intval', $_SESSION['glpiactiveentities'] ?? [0]);
        $filhas = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $filhas[(int) $r['id']] = true;
        }
        $entidades = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ativas ?: [-1]], 'ORDER' => 'completename ASC']) as $r) {
            if (!isset($filhas[(int) $r['id']])) {
                $entidades[] = ['valor' => (string) $r['id'], 'rotulo' => (string) ($r['completename'] ?: $r['name'])];
            }
        }
        $grupos = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1] + getEntitiesRestrictCriteria('glpi_groups', '', '', true), 'ORDER' => 'completename ASC']) as $r) {
            $grupos[] = ['valor' => (string) $r['id'], 'rotulo' => (string) $r['completename']];
        }
        // Técnicos: pessoas com perfil da interface padrão nas entidades ativas
        $tecnicos = [['valor' => '0', 'rotulo' => 'Sem técnico', 'detalhe' => '']];
        $ids = [];
        foreach ($DB->request([
            'SELECT'     => ['pu.users_id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_profiles_users AS pu',
            'INNER JOIN' => ['glpi_profiles AS p' => ['ON' => ['pu' => 'profiles_id', 'p' => 'id']], 'glpi_users AS u' => ['ON' => ['pu' => 'users_id', 'u' => 'id']]],
            'WHERE'      => ['p.interface' => 'central', 'u.is_active' => 1, 'u.is_deleted' => 0, 'pu.entities_id' => $ativas ?: [-1]],
        ]) as $r) {
            $ids[] = (int) $r['users_id'];
        }
        $nomes = self::nomesUsuarios($ids);
        asort($nomes, SORT_FLAG_CASE | SORT_STRING);
        foreach ($nomes as $id => $nome) {
            $tecnicos[] = ['valor' => (string) $id, 'rotulo' => $nome];
        }
        $categorias = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories', 'WHERE' => getEntitiesRestrictCriteria('glpi_itilcategories', '', '', true), 'ORDER' => 'completename ASC']) as $r) {
            $categorias[] = ['valor' => (string) $r['id'], 'rotulo' => (string) $r['completename']];
        }
        $listaTipos = [];
        foreach ($tipos as $t) {
            $listaTipos[] = ['valor' => $t, 'rotulo' => $C::TIPOS[$t][1]];
        }
        return compact('status', 'prioridades', 'entidades', 'grupos', 'tecnicos', 'categorias') + ['tipos' => $listaTipos];
    }
}
