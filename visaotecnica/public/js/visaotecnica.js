/* Plugin Visão Técnica - mesa de trabalho do técnico: visões, filtros (multiselect com pesquisa),
 * colunas escolhidas pela pessoa, ordenação, paginação, destaque do que mudou, visões salvas,
 * acompanhamento rápido (editor rico), assumir, CSV e atualização automática.
 * Também cuida do multiselect da página de configuração. */
(function () {
    'use strict';

    if (window.visaotecnicaCarregado) {
        return;
    }
    window.visaotecnicaCarregado = true;

    // ------------------------------------------------------------------ utilitários

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    var aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    };

    var armazenar = {
        ler: function (k, padrao) {
            try {
                var v = window.localStorage.getItem(k);
                return v === null ? padrao : JSON.parse(v);
            } catch (e) {
                return padrao;
            }
        },
        gravar: function (k, v) {
            try {
                window.localStorage.setItem(k, JSON.stringify(v));
            } catch (e) { /* sem armazenamento */ }
        }
    };

    var relativo = function (horas) {
        if (horas < 1) {
            return 'há menos de 1 h';
        }
        if (horas < 24) {
            return 'há ' + horas + ' h';
        }
        var d = Math.floor(horas / 24);
        return d === 1 ? 'há 1 dia' : 'há ' + d + ' dias';
    };

    // ------------------------------------------------------------------ multiselect (página e configuração)

    var msHtml = function (nome, opcoes, selecionados, placeholder) {
        var sel = (selecionados || []).map(String);
        var itens = opcoes.map(function (o) {
            return { valor: String(o.valor), rotulo: o.rotulo, detalhe: o.detalhe || '', marcado: sel.indexOf(String(o.valor)) >= 0 };
        }).sort(function (a, b) { return (b.marcado - a.marcado) || 0; });
        return '<div class="visaotecnica-ms" data-visaotecnica-ms data-nome="' + esc(nome) + '" data-placeholder="' + esc(placeholder) + '">' +
            '<button type="button" class="visaotecnica-ms-cabecalho form-select form-select-sm" data-visaotecnica-ms-abrir><span class="visaotecnica-ms-texto"></span></button>' +
            '<div class="visaotecnica-ms-dropdown" hidden>' +
            '<div class="visaotecnica-ms-topo"><input type="text" class="form-control form-control-sm visaotecnica-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>' +
            '<label class="visaotecnica-ms-todos"><input type="checkbox" class="visaotecnica-check" data-visaotecnica-ms-todos> Marcar/desmarcar todos</label>' +
            '<div class="visaotecnica-ms-opcoes">' + itens.map(function (i) {
                return '<label class="visaotecnica-ms-opcao' + (i.marcado ? ' selected' : '') + '" data-label="' + esc((i.rotulo + ' ' + i.detalhe).toLowerCase()) + '">' +
                    '<input type="checkbox" class="visaotecnica-check" value="' + esc(i.valor) + '"' + (i.marcado ? ' checked' : '') + '>' +
                    '<span class="visaotecnica-ms-rotulo">' + esc(i.rotulo) + (i.detalhe ? ' <small>' + esc(i.detalhe) + '</small>' : '') + '</span></label>';
            }).join('') + '</div></div><div class="visaotecnica-ms-contador"></div></div>';
    };

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.visaotecnica-ms-opcao'));
    };

    var valoresMs = function (ms) {
        return opcoesMs(ms).filter(function (o) { return o.querySelector('input').checked; }).map(function (o) { return o.querySelector('input').value; });
    };

    var atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var nome = function (o) { return o.querySelector('.visaotecnica-ms-rotulo').childNodes[0].textContent.trim(); };
        var texto = ms.querySelector('.visaotecnica-ms-texto');
        if (!marcadas.length) {
            texto.textContent = ms.dataset.placeholder || 'Todos';
        } else if (marcadas.length <= 2) {
            texto.textContent = marcadas.map(nome).join(', ');
        } else {
            texto.textContent = marcadas.slice(0, 1).map(nome).join(', ') + ' e mais ' + (marcadas.length - 1);
        }
        ms.querySelector('.visaotecnica-ms-contador').textContent = marcadas.length ? marcadas.length + ' de ' + lista.length + ' selecionado(s)' : '';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-visaotecnica-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.visaotecnica-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : 0;
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.visaotecnica-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizarMs(ms);
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-visaotecnica-ms-abrir]');
        document.querySelectorAll('[data-visaotecnica-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.visaotecnica-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.visaotecnica-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.visaotecnica-ms-busca')) {
            filtrarMs(e.target.closest('[data-visaotecnica-ms]'));
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.visaotecnica-ms-busca')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        var ms = e.target.closest('[data-visaotecnica-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-visaotecnica-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.visaotecnica-ms-opcao')) {
            e.target.closest('.visaotecnica-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.visaotecnica-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
                busca.focus();
            }
        } else {
            return;
        }
        reordenarMs(ms);
        atualizarMs(ms);
        ms.dispatchEvent(new CustomEvent('visaotecnica-mudou', { bubbles: true }));
    });

    // ------------------------------------------------------------------ página

    var raiz = null;
    var est = {
        escopos: [], colunas: [], opcoes: {}, prefs: {}, visoes: [], parado: 3,
        filtros: { escopo: 'meus' }, ordem: 'atualizacao', direcao: 'desc', pagina: 1,
        dados: null, timer: null, visaoAtual: '', carregando: false
    };
    var CHAVE_ESTADO = function () { return 'visaotecnica-estado-' + raiz.dataset.usuario; };
    var CHAVE_VISTOS = function () { return 'visaotecnica-vistos-' + raiz.dataset.usuario; };

    var pedir = function (acao, dados, metodo) {
        var url = raiz.dataset.ajax + '?action=' + encodeURIComponent(acao);
        var opcoes = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' };
        if (metodo === 'POST') {
            var fd = new FormData();
            fd.append('action', acao);
            Object.keys(dados || {}).forEach(function (k) {
                if (Array.isArray(dados[k])) {
                    dados[k].forEach(function (v) { fd.append(k + '[]', v); });
                } else {
                    fd.append(k, dados[k]);
                }
            });
            var meta = document.querySelector('meta[property="glpi:csrf_token"]');
            var t = raiz.dataset.token || (meta ? meta.getAttribute('content') : '');
            if (t) {
                fd.append('_glpi_csrf_token', t);
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
            }
            opcoes.method = 'POST';
            opcoes.body = fd;
        } else {
            Object.keys(dados || {}).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(dados[k]);
            });
        }
        return fetch(url, opcoes).then(function (r) { return r.text(); }).then(function (texto) {
            var r = lerJson(texto);
            if (r.new_token) {
                raiz.dataset.token = r.new_token;
            }
            return r;
        });
    };

    var filtrosAtuais = function () {
        return Object.assign({}, est.filtros, { ordem: est.ordem, direcao: est.direcao });
    };

    var guardarEstado = function () {
        armazenar.gravar(CHAVE_ESTADO(), { filtros: est.filtros, ordem: est.ordem, direcao: est.direcao, visao: est.visaoAtual });
    };

    // ------------------------------------------------------------------ carregamento

    var carregar = function (silencioso) {
        if (est.carregando) {
            return;
        }
        est.carregando = true;
        guardarEstado();
        var corpo = raiz.querySelector('[data-tabela] tbody');
        if (!silencioso) {
            corpo.classList.add('visaotecnica-carregando');
        }
        pedir('listar', { f: JSON.stringify(filtrosAtuais()), pagina: est.pagina, por_pagina: est.prefs.por_pagina || 25 }).then(function (r) {
            est.carregando = false;
            corpo.classList.remove('visaotecnica-carregando');
            if (!r.success) {
                aviso(r.mensagem || 'Não foi possível carregar.', true);
                return;
            }
            est.dados = r;
            est.filtros.escopo = r.escopo;
            est.pagina = r.pagina;
            renderEscopos(r.contagens || {});
            renderTabela();
            raiz.querySelector('[data-hora]').textContent = 'Atualizado às ' + r.gerado_em;
            agendar();
        }).catch(function () {
            est.carregando = false;
            corpo.classList.remove('visaotecnica-carregando');
            agendar();
        });
    };

    var agendar = function () {
        clearTimeout(est.timer);
        var s = parseInt(est.prefs.intervalo || 0, 10);
        if (s > 0) {
            est.timer = setTimeout(function () {
                if (document.visibilityState === 'visible' && !document.querySelector('.visaotecnica-modal.show')) {
                    carregar(true);
                } else {
                    agendar();
                }
            }, s * 1000);
        }
    };

    var recarregarEspera = null;
    var recarregar = function () {
        est.pagina = 1;
        est.visaoAtual = '';
        raiz.querySelector('[data-visoes]').value = '';
        raiz.querySelector('[data-excluir-visao]').hidden = true;
        clearTimeout(recarregarEspera);
        recarregarEspera = setTimeout(function () {
            renderChips();
            carregar(false);
        }, 350);
    };

    // ------------------------------------------------------------------ desenho

    var renderEscopos = function (contagens) {
        raiz.querySelector('[data-escopos]').innerHTML = est.escopos.map(function (e) {
            var n = contagens[e.chave];
            return '<li class="nav-item"><a class="nav-link' + (e.chave === est.filtros.escopo ? ' active' : '') + '" href="#" data-escopo="' + esc(e.chave) + '">' +
                '<i class="' + esc(e.icone) + '"></i> ' + esc(e.rotulo) + (n !== undefined ? ' <span class="visaotecnica-contador' + (n ? '' : ' vazio') + '">' + n + '</span>' : '') + '</a></li>';
        }).join('');
    };

    var rotuloOpcao = function (lista, valor) {
        var o = (est.opcoes[lista] || []).filter(function (x) { return String(x.valor) === String(valor); })[0];
        return o ? o.rotulo + (o.detalhe ? ' (' + o.detalhe + ')' : '') : valor;
    };

    var NOMES_FILTRO = { tipos: 'Tipo', status: 'Status', prioridades: 'Prioridade', entidades: 'Entidade', grupos: 'Grupo', tecnicos: 'Técnico', categorias: 'Categoria' };
    var TEXTO_PRAZO = { vencido: 'Prazo vencido', hoje: 'Vence hoje', noprazo: 'No prazo', sem: 'Sem prazo' };

    var renderChips = function () {
        var chips = [];
        Object.keys(NOMES_FILTRO).forEach(function (k) {
            (est.filtros[k] || []).forEach(function (v) {
                chips.push({ k: k, v: v, t: NOMES_FILTRO[k] + ': ' + rotuloOpcao(k, v) });
            });
        });
        if (est.filtros.prazo) {
            chips.push({ k: 'prazo', t: TEXTO_PRAZO[est.filtros.prazo] || est.filtros.prazo });
        }
        if (est.filtros.parados) {
            chips.push({ k: 'parados', t: 'Sem atualização há ' + est.filtros.parados + '+ dias' });
        }
        if (est.filtros.abertura_de) {
            chips.push({ k: 'abertura_de', t: 'Aberto de ' + est.filtros.abertura_de.split('-').reverse().join('/') });
        }
        if (est.filtros.abertura_ate) {
            chips.push({ k: 'abertura_ate', t: 'Aberto até ' + est.filtros.abertura_ate.split('-').reverse().join('/') });
        }
        if (est.filtros.busca) {
            chips.push({ k: 'busca', t: 'Busca: ' + est.filtros.busca });
        }
        var caixa = raiz.querySelector('[data-chips]');
        caixa.hidden = !chips.length;
        caixa.innerHTML = chips.map(function (c) {
            return '<span class="visaotecnica-chip">' + esc(c.t) + '<button type="button" data-tirar-filtro="' + esc(c.k) + '"' + (c.v !== undefined ? ' data-valor="' + esc(c.v) + '"' : '') + ' title="Remover"><i class="ti ti-x"></i></button></span>';
        }).join('') + (chips.length ? '<button type="button" class="visaotecnica-chip-limpar" data-limpar-filtros>Limpar tudo</button>' : '');
        var n = chips.filter(function (c) { return c.k !== 'busca'; }).length;
        var cont = raiz.querySelector('[data-contador-filtros]');
        cont.hidden = !n;
        cont.textContent = String(n);
    };

    var PAPEIS = { tecnico: 'Técnico', grupo: 'Meu grupo', observador: 'Observador', requerente: 'Requerente' };

    var celula = function (c, l, vistos) {
        var lista = function (a) { return a && a.length ? esc(a.join(', ')) : '<span class="visaotecnica-mudo">—</span>'; };
        switch (c) {
            case 'id':
                return '<td class="visaotecnica-id"><a href="' + esc(l.url) + '" data-abrir="' + esc(l.chave) + '">' + l.id + '</a></td>';
            case 'tipo':
                return '<td class="text-nowrap"><i class="' + esc(l.tipo_icone) + ' visaotecnica-mudo"></i> ' + esc(l.tipo_rotulo) + '</td>';
            case 'titulo':
                var mudou = vistos[l.chave] && vistos[l.chave] !== l.atualizacao;
                return '<td class="visaotecnica-titulo">' + (mudou ? '<span class="visaotecnica-novidade" title="Atualizado desde a sua última visita"></span>' : '') +
                    '<a href="' + esc(l.url) + '" data-abrir="' + esc(l.chave) + '">' + esc(l.titulo) + '</a></td>';
            case 'status':
                return '<td class="text-nowrap">' + l.status_icone + ' ' + esc(l.status_rotulo) + '</td>';
            case 'prioridade':
                return '<td class="text-nowrap"><span class="visaotecnica-prioridade" style="--visaotecnica-cor:' + esc(l.prioridade_cor || '#adb5bd') + '"></span>' + esc(l.prioridade_rotulo) + '</td>';
            case 'entidade':
                return '<td>' + esc(l.entidade) + '</td>';
            case 'requerente':
                return '<td>' + lista(l.requerentes) + '</td>';
            case 'tecnico':
                return '<td>' + (l.tecnicos.length ? esc(l.tecnicos.join(', ')) : '<span class="visaotecnica-selo visaotecnica-selo-aviso">Sem técnico</span>') + '</td>';
            case 'grupo':
                return '<td>' + lista(l.grupos) + '</td>';
            case 'categoria':
                return '<td>' + (l.categoria ? esc(l.categoria) : '<span class="visaotecnica-mudo">—</span>') + '</td>';
            case 'abertura':
                return '<td class="text-nowrap">' + esc(l.abertura_txt) + '</td>';
            case 'atualizacao':
                var parado = l.idade_horas >= est.parado * 24;
                return '<td class="text-nowrap" title="' + esc(l.atualizacao_txt) + '"><span class="visaotecnica-idade' + (parado ? ' parado' : '') + '">' + esc(relativo(l.idade_horas)) + '</span></td>';
            case 'ultimo':
                if (!l.ultimo) {
                    return '<td><span class="visaotecnica-mudo">Nenhum</span></td>';
                }
                return '<td title="' + esc(l.ultimo.data_txt) + '">' + (l.ultimo.do_requerente ? '<span class="visaotecnica-selo visaotecnica-selo-aviso" title="O último acompanhamento é do requerente">Requerente respondeu</span> ' : '') +
                    (l.ultimo.privado ? '<i class="ti ti-lock visaotecnica-mudo" title="Privado"></i> ' : '') +
                    esc(l.ultimo.autor) + ' <small class="visaotecnica-mudo">' + esc(relativo(l.ultimo.idade_horas)) + '</small></td>';
            case 'prazo':
                if (!l.prazo) {
                    return '<td><span class="visaotecnica-mudo">—</span></td>';
                }
                return '<td title="' + esc(l.prazo.data_txt) + '"><div class="visaotecnica-prazo visaotecnica-prazo-' + esc(l.prazo.estado) + '"><div style="width:' + l.prazo.pct + '%"></div></div><small>' +
                    (l.prazo.estado === 'vencido' ? 'Vencido · ' : '') + esc(l.prazo.data_txt) + '</small></td>';
            case 'papel':
                return '<td>' + (l.papel.length ? l.papel.map(function (p) { return '<span class="visaotecnica-papel">' + esc(PAPEIS[p] || p) + '</span>'; }).join(' ') : '<span class="visaotecnica-mudo">—</span>') + '</td>';
        }
        return '<td></td>';
    };

    var renderTabela = function () {
        var r = est.dados;
        if (!r) {
            return;
        }
        var cols = est.prefs.colunas || [];
        var mapa = {};
        est.colunas.forEach(function (c) { mapa[c.chave] = c; });
        var tabela = raiz.querySelector('[data-tabela]');
        tabela.querySelector('thead tr').innerHTML = cols.map(function (c) {
            var ativo = est.ordem === c;
            return '<th data-ordenar="' + esc(c) + '" class="' + (ativo ? 'ordenado-' + est.direcao : '') + '">' + esc(mapa[c] ? mapa[c].rotulo : c) + '</th>';
        }).join('') + '<th class="visaotecnica-acoes-col"></th>';
        var vistos = armazenar.ler(CHAVE_VISTOS(), {});
        var novosVistos = false;
        tabela.querySelector('tbody').innerHTML = r.linhas.length ? r.linhas.map(function (l) {
            var html = '<tr data-chave="' + esc(l.chave) + '">' + cols.map(function (c) { return celula(c, l, vistos); }).join('') +
                '<td class="visaotecnica-acoes"><span>' +
                '<a class="btn btn-sm btn-ghost-secondary" href="' + esc(l.url) + '" target="_blank" title="Abrir em nova aba" data-abrir="' + esc(l.chave) + '"><i class="ti ti-external-link"></i></a>' +
                '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acompanhar="' + esc(l.chave) + '" title="Adicionar acompanhamento"><i class="ti ti-message-plus"></i></button>' +
                (l.papel.indexOf('tecnico') < 0 ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-assumir="' + esc(l.chave) + '" title="Assumir (atribuir a mim)"><i class="ti ti-user-plus"></i></button>' : '') +
                '</span></td></tr>';
            if (!vistos[l.chave]) {
                vistos[l.chave] = l.atualizacao;
                novosVistos = true;
            }
            return html;
        }).join('') : '<tr><td colspan="' + (cols.length + 1) + '" class="visaotecnica-vazio"><i class="ti ti-mood-empty"></i> Nada por aqui com esses filtros.</td></tr>';
        if (novosVistos) {
            var chaves = Object.keys(vistos);
            if (chaves.length > 4000) {
                chaves.slice(0, chaves.length - 4000).forEach(function (k) { delete vistos[k]; });
            }
            armazenar.gravar(CHAVE_VISTOS(), vistos);
        }
        var inicio = (r.pagina - 1) * r.por_pagina;
        raiz.querySelector('[data-resumo]').innerHTML = (r.total ? (inicio + 1) + '–' + Math.min(r.total, inicio + r.por_pagina) + ' de ' + r.total : '0 itens') +
            (r.cortado ? ' · <span class="visaotecnica-selo visaotecnica-selo-aviso">mais de ' + r.limite + ' por tipo: use filtros</span>' : '') +
            ' · <a href="#" data-marcar-vistos>marcar tudo como visto</a>';
        renderPaginacao(r);
    };

    var renderPaginacao = function (r) {
        var paginas = Math.max(1, Math.ceil(r.total / r.por_pagina));
        var nav = raiz.querySelector('[data-paginacao]');
        if (paginas <= 1) {
            nav.innerHTML = '';
            return;
        }
        var b = function (p, rotulo, ativo, desligado) {
            return '<button type="button" class="btn btn-sm ' + (ativo ? 'btn-secondary' : 'btn-ghost-secondary') + '" data-pagina="' + p + '"' + (desligado ? ' disabled' : '') + '>' + rotulo + '</button>';
        };
        var faixa = [1, r.pagina - 1, r.pagina, r.pagina + 1, paginas].filter(function (p, i, a) { return p >= 1 && p <= paginas && a.indexOf(p) === i; }).sort(function (x, y) { return x - y; });
        var h = b(r.pagina - 1, '<i class="ti ti-chevron-left"></i>', false, r.pagina <= 1);
        var ant = 0;
        faixa.forEach(function (p) {
            if (p - ant > 1) {
                h += '<span class="visaotecnica-mudo">…</span>';
            }
            h += b(p, String(p), p === r.pagina, false);
            ant = p;
        });
        nav.innerHTML = h + b(r.pagina + 1, '<i class="ti ti-chevron-right"></i>', false, r.pagina >= paginas) +
            '<select class="form-select form-select-sm" data-por-pagina>' + [10, 25, 50, 100].map(function (n) {
                return '<option value="' + n + '"' + (n === r.por_pagina ? ' selected' : '') + '>' + n + ' por página</option>';
            }).join('') + '</select>';
    };

    var renderFiltros = function () {
        var nomes = { tipos: 'Todos', status: 'Em aberto', prioridades: 'Todas', entidades: 'Todas', grupos: 'Todos', tecnicos: 'Todos', categorias: 'Todas' };
        raiz.querySelectorAll('[data-ms]').forEach(function (caixa) {
            var k = caixa.dataset.ms;
            caixa.innerHTML = msHtml(k, est.opcoes[k] || [], est.filtros[k] || [], nomes[k]);
            atualizarMs(caixa.querySelector('[data-visaotecnica-ms]'));
        });
        raiz.querySelectorAll('[data-filtro]').forEach(function (el) {
            el.value = est.filtros[el.dataset.filtro] || '';
        });
        raiz.querySelectorAll('[data-filtro-data]').forEach(function (bloco) {
            var input = bloco.querySelector('input[name="' + bloco.dataset.filtroData + '"]') || bloco.querySelector('input');
            var valor = est.filtros[bloco.dataset.filtroData] || '';
            var calendario = (bloco.querySelector('.flatpickr') || {})._flatpickr || (input && input._flatpickr);
            if (calendario) {
                calendario.setDate(valor || null, false);
            } else if (input) {
                input.value = valor;
            }
        });
        raiz.querySelector('[data-busca]').value = est.filtros.busca || '';
    };

    var renderColunasMenu = function () {
        var cols = est.prefs.colunas || [];
        raiz.querySelector('[data-lista-colunas]').innerHTML = est.colunas.map(function (c) {
            return '<label class="visaotecnica-opcao"><input type="checkbox" class="visaotecnica-check" value="' + esc(c.chave) + '"' + (cols.indexOf(c.chave) >= 0 ? ' checked' : '') + '> ' + esc(c.rotulo) + '</label>';
        }).join('');
    };

    var renderVisoes = function () {
        var sel = raiz.querySelector('[data-visoes]');
        sel.innerHTML = '<option value="">Visões salvas' + (est.visoes.length ? ' (' + est.visoes.length + ')' : '') + '</option>' + est.visoes.map(function (v) {
            return '<option value="' + v.id + '">' + esc(v.nome) + (v.padrao ? ' · padrão' : '') + '</option>';
        }).join('');
        sel.value = est.visaoAtual ? String(est.visaoAtual) : '';
        raiz.querySelector('[data-excluir-visao]').hidden = !est.visaoAtual;
    };

    var salvarPreferencias = function () {
        pedir('preferencias', { colunas: est.prefs.colunas, intervalo: est.prefs.intervalo, por_pagina: est.prefs.por_pagina }, 'POST');
    };

    var aplicarVisao = function (v) {
        var f = Object.assign({}, v.filtros);
        if (Array.isArray(f.colunas) && f.colunas.length) {
            est.prefs.colunas = f.colunas;
        }
        delete f.colunas;
        est.ordem = f.ordem || 'atualizacao';
        est.direcao = f.direcao || 'desc';
        delete f.ordem;
        delete f.direcao;
        est.filtros = Object.assign({ escopo: est.filtros.escopo }, f);
        est.visaoAtual = v.id;
        est.pagina = 1;
        renderFiltros();
        renderColunasMenu();
        renderChips();
        renderVisoes();
        carregar(false);
    };

    // ------------------------------------------------------------------ janelas

    var janela = function (titulo, corpo, botao, icone, aoConfirmar, aoAbrir, aoFechar) {
        var m = document.createElement('div');
        m.className = 'modal fade visaotecnica-modal';
        m.tabIndex = -1;
        m.innerHTML = '<div class="modal-dialog' + (aoAbrir ? ' modal-lg' : '') + '"><div class="modal-content">' +
            '<div class="modal-header"><h5 class="modal-title">' + titulo + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>' +
            '<div class="modal-body">' + corpo + '</div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-dismiss="modal">Cancelar</button>' +
            '<button type="button" class="btn btn-sm visaotecnica-btn-principal" data-confirmar><i class="' + icone + '"></i><span>' + esc(botao) + '</span></button></div></div></div>';
        document.body.appendChild(m);
        var inst = window.bootstrap.Modal.getOrCreateInstance(m);
        var ok = m.querySelector('[data-confirmar]');
        ok.addEventListener('click', function () {
            ok.disabled = true;
            Promise.resolve(aoConfirmar(m)).then(function (fechar) {
                ok.disabled = false;
                if (fechar !== false) {
                    inst.hide();
                }
            });
        });
        m.addEventListener('shown.bs.modal', function () {
            if (aoAbrir) {
                aoAbrir(m);
            }
            var f = m.querySelector('[data-foco]');
            if (f) {
                f.focus();
            }
        });
        m.addEventListener('hidden.bs.modal', function () {
            if (aoFechar) {
                aoFechar(m);
            }
            m.remove();
        });
        inst.show();
    };

    var linhaDe = function (chave) {
        return (est.dados && est.dados.linhas || []).filter(function (l) { return l.chave === chave; })[0];
    };

    var ID_EDITOR = 'visaotecnica-acompanhamento';

    var acompanhar = function (l) {
        janela('<i class="ti ti-message-plus"></i> Acompanhamento · ' + esc(l.tipo_rotulo) + ' #' + l.id,
            '<p class="visaotecnica-explicacao"><i class="ti ti-info-circle"></i><span>' + esc(l.titulo) + '</span></p>' +
            '<textarea id="' + ID_EDITOR + '" rows="8" class="form-control"></textarea>' +
            '<div class="form-check form-switch visaotecnica-switch"><input class="form-check-input" type="checkbox" id="visaotecnica-privado" data-privado>' +
            '<label class="form-check-label" for="visaotecnica-privado">Acompanhamento privado</label></div>',
            'Adicionar', 'ti ti-send',
            function (m) {
                var ed = window.tinymce ? window.tinymce.get(ID_EDITOR) : null;
                return pedir('acompanhar', { itemtype: l.itemtype, items_id: l.id, conteudo: ed ? ed.getContent() : m.querySelector('#' + ID_EDITOR).value, privado: m.querySelector('[data-privado]').checked ? 1 : 0 }, 'POST').then(function (r) {
                    aviso(r.mensagem, !r.success);
                    if (r.success) {
                        carregar(true);
                    }
                    return r.success;
                });
            },
            function () {
                if (!window.tinymce) {
                    return;
                }
                var configs = window.tinymce_editor_configs || {};
                var base = configs[Object.keys(configs)[0]] || {};
                var cfg = Object.assign({}, base, {
                    selector: '#' + ID_EDITOR, target: undefined, height: 260, min_height: 200,
                    license_key: 'gpl', menubar: false, statusbar: false, branding: false, toolbar_location: 'top',
                    quickbars_insert_toolbar: false, quickbars_selection_toolbar: false,
                    toolbar: 'bold italic underline | forecolor backcolor | bullist numlist | link table | removeformat',
                    setup: function (editor) { editor.on('init', function () { editor.focus(); }); },
                    init_instance_callback: undefined
                });
                if (!base.skin_url) {
                    cfg.skin = false;
                    cfg.content_css = false;
                    cfg.plugins = 'lists link table autoresize';
                }
                cfg.content_style = (cfg.content_style || '') + ' body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 13px; color: #333; }';
                window.tinymce.init(cfg);
            },
            function () {
                var ed = window.tinymce ? window.tinymce.get(ID_EDITOR) : null;
                if (ed) {
                    ed.remove();
                }
            });
    };

    var salvarVisao = function () {
        var atual = est.visoes.filter(function (v) { return v.id === est.visaoAtual; })[0];
        janela('<i class="ti ti-device-floppy"></i> Salvar visão',
            '<div class="visaotecnica-campo"><label>Nome</label><input type="text" class="form-control form-control-sm" maxlength="100" data-nome data-foco value="' + esc(atual ? atual.nome : '') + '" placeholder="Ex.: Meus urgentes"></div>' +
            '<p class="visaotecnica-explicacao"><i class="ti ti-info-circle"></i><span>Guarda a visão (aba), os filtros, a ordenação e as colunas atuais. Com o mesmo nome, substitui.</span></p>' +
            '<div class="form-check form-switch visaotecnica-switch"><input class="form-check-input" type="checkbox" id="visaotecnica-padrao" data-padrao' + (atual && atual.padrao ? ' checked' : '') + '>' +
            '<label class="form-check-label" for="visaotecnica-padrao">Abrir com esta visão</label></div>',
            'Salvar', 'ti ti-device-floppy',
            function (m) {
                var filtros = Object.assign({}, filtrosAtuais(), { colunas: est.prefs.colunas });
                return pedir('salvar_visao', { nome: m.querySelector('[data-nome]').value, filtros: JSON.stringify(filtros), padrao: m.querySelector('[data-padrao]').checked ? 1 : 0 }, 'POST').then(function (r) {
                    aviso(r.mensagem, !r.success);
                    if (r.success) {
                        est.visoes = r.visoes;
                        est.visaoAtual = r.id;
                        renderVisoes();
                        guardarEstado();
                    }
                    return r.success;
                });
            });
    };

    // ------------------------------------------------------------------ CSV

    var textoCelula = function (c, l) {
        switch (c) {
            case 'tipo': return l.tipo_rotulo;
            case 'status': return l.status_rotulo;
            case 'prioridade': return l.prioridade_rotulo;
            case 'requerente': return l.requerentes.join(', ');
            case 'tecnico': return l.tecnicos.join(', ');
            case 'grupo': return l.grupos.join(', ');
            case 'abertura': return l.abertura_txt;
            case 'atualizacao': return l.atualizacao_txt;
            case 'ultimo': return l.ultimo ? l.ultimo.autor + ' - ' + l.ultimo.data_txt : '';
            case 'prazo': return l.prazo ? l.prazo.data_txt : '';
            case 'papel': return l.papel.map(function (p) { return PAPEIS[p] || p; }).join(', ');
            default: return l[c] === undefined ? '' : String(l[c]);
        }
    };

    var exportar = function () {
        pedir('listar', { f: JSON.stringify(filtrosAtuais()), exportar: 1 }).then(function (r) {
            if (!r.success) {
                aviso(r.mensagem, true);
                return;
            }
            var cols = est.prefs.colunas;
            var mapa = {};
            est.colunas.forEach(function (c) { mapa[c.chave] = c.rotulo; });
            var q = function (t) { return '"' + String(t).replace(/"/g, '""') + '"'; };
            var linhas = [cols.map(function (c) { return q(mapa[c] || c); }).join(';')].concat(r.linhas.map(function (l) {
                return cols.map(function (c) { return q(textoCelula(c, l)); }).join(';');
            }));
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob(['\uFEFF' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
            a.download = 'visao-tecnica-' + r.escopo + '-' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        });
    };

    // ------------------------------------------------------------------ eventos da página

    var ligar = function () {
        raiz.addEventListener('click', function (e) {
            var el;
            if ((el = e.target.closest('[data-escopo]'))) {
                e.preventDefault();
                est.filtros.escopo = el.dataset.escopo;
                est.pagina = 1;
                carregar(false);
            } else if ((el = e.target.closest('[data-ordenar]'))) {
                var c = el.dataset.ordenar;
                est.direcao = est.ordem === c && est.direcao === 'desc' ? 'asc' : (est.ordem === c ? 'desc' : (['titulo', 'entidade', 'requerente', 'tecnico', 'grupo', 'categoria', 'tipo'].indexOf(c) >= 0 ? 'asc' : 'desc'));
                est.ordem = c;
                carregar(false);
            } else if ((el = e.target.closest('[data-pagina]'))) {
                est.pagina = parseInt(el.dataset.pagina, 10);
                carregar(false);
            } else if (e.target.closest('[data-alternar-filtros]')) {
                var f = raiz.querySelector('[data-filtros]');
                f.hidden = !f.hidden;
                armazenar.gravar('visaotecnica-filtros-abertos', !f.hidden);
            } else if (e.target.closest('[data-limpar-filtros]')) {
                est.filtros = { escopo: est.filtros.escopo };
                renderFiltros();
                recarregar();
            } else if ((el = e.target.closest('[data-tirar-filtro]'))) {
                var k = el.dataset.tirarFiltro;
                if (el.dataset.valor !== undefined && Array.isArray(est.filtros[k])) {
                    est.filtros[k] = est.filtros[k].filter(function (v) { return String(v) !== el.dataset.valor; });
                } else {
                    delete est.filtros[k];
                }
                renderFiltros();
                recarregar();
            } else if (e.target.closest('[data-abrir-colunas]')) {
                var lista = raiz.querySelector('[data-lista-colunas]');
                lista.hidden = !lista.hidden;
            } else if ((el = e.target.closest('[data-acompanhar]'))) {
                var l1 = linhaDe(el.dataset.acompanhar);
                if (l1) {
                    acompanhar(l1);
                }
            } else if ((el = e.target.closest('[data-assumir]'))) {
                var l2 = linhaDe(el.dataset.assumir);
                if (!l2) {
                    return;
                }
                if (el.dataset.confirmar !== '1') {
                    el.dataset.confirmar = '1';
                    el.classList.add('visaotecnica-confirmando');
                    el.title = 'Clique de novo para assumir';
                    setTimeout(function () { el.dataset.confirmar = ''; el.classList.remove('visaotecnica-confirmando'); el.title = 'Assumir (atribuir a mim)'; }, 3000);
                    return;
                }
                el.disabled = true;
                pedir('assumir', { itemtype: l2.itemtype, items_id: l2.id }, 'POST').then(function (r) {
                    aviso(r.mensagem, !r.success);
                    el.disabled = false;
                    if (r.success) {
                        carregar(true);
                    }
                });
            } else if ((el = e.target.closest('[data-abrir]'))) {
                var l3 = linhaDe(el.dataset.abrir);
                if (l3) {
                    var vistos = armazenar.ler(CHAVE_VISTOS(), {});
                    vistos[l3.chave] = l3.atualizacao;
                    armazenar.gravar(CHAVE_VISTOS(), vistos);
                }
            } else if (e.target.closest('[data-marcar-vistos]')) {
                e.preventDefault();
                var v2 = armazenar.ler(CHAVE_VISTOS(), {});
                (est.dados.linhas || []).forEach(function (l) { v2[l.chave] = l.atualizacao; });
                armazenar.gravar(CHAVE_VISTOS(), v2);
                renderTabela();
            } else if (e.target.closest('[data-exportar]')) {
                exportar();
            } else if (e.target.closest('[data-atualizar]')) {
                carregar(false);
            } else if (e.target.closest('[data-salvar-visao]')) {
                salvarVisao();
            } else if ((el = e.target.closest('[data-excluir-visao]'))) {
                if (el.dataset.confirmar !== '1') {
                    el.dataset.confirmar = '1';
                    el.classList.add('visaotecnica-confirmando');
                    el.title = 'Clique de novo para excluir';
                    setTimeout(function () { el.dataset.confirmar = ''; el.classList.remove('visaotecnica-confirmando'); el.title = 'Excluir a visão escolhida'; }, 3000);
                    return;
                }
                pedir('excluir_visao', { id: est.visaoAtual }, 'POST').then(function (r) {
                    aviso(r.mensagem, !r.success);
                    est.visoes = r.visoes || est.visoes;
                    est.visaoAtual = '';
                    renderVisoes();
                    guardarEstado();
                });
            }
        });
        document.addEventListener('click', function (e) {
            var menu = raiz.querySelector('[data-menu-colunas]');
            if (menu && !menu.contains(e.target)) {
                raiz.querySelector('[data-lista-colunas]').hidden = true;
            }
        });
        raiz.addEventListener('visaotecnica-mudou', function (e) {
            var ms = e.target.closest('[data-visaotecnica-ms]');
            if (ms && ms.dataset.nome) {
                est.filtros[ms.dataset.nome] = valoresMs(ms);
                if (!est.filtros[ms.dataset.nome].length) {
                    delete est.filtros[ms.dataset.nome];
                }
                recarregar();
            }
        });
        raiz.addEventListener('change', function (e) {
            var el = e.target;
            if (el.matches('[data-filtro]')) {
                if (el.value) {
                    est.filtros[el.dataset.filtro] = el.value;
                } else {
                    delete est.filtros[el.dataset.filtro];
                }
                recarregar();
            } else if (el.closest('[data-filtro-data]')) {
                var k = el.closest('[data-filtro-data]').dataset.filtroData;
                var input = el.closest('[data-filtro-data]').querySelector('input[name="' + k + '"]') || el;
                var v = (input.value || '').slice(0, 10);
                if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                    est.filtros[k] = v;
                } else {
                    delete est.filtros[k];
                }
                recarregar();
            } else if (el.closest('[data-lista-colunas]')) {
                est.prefs.colunas = Array.from(raiz.querySelectorAll('[data-lista-colunas] input:checked')).map(function (i) { return i.value; });
                if (!est.prefs.colunas.length) {
                    est.prefs.colunas = ['id', 'titulo'];
                }
                renderTabela();
                salvarPreferencias();
            } else if (el.matches('[data-intervalo]')) {
                est.prefs.intervalo = parseInt(el.value, 10);
                salvarPreferencias();
                agendar();
            } else if (el.matches('[data-por-pagina]')) {
                est.prefs.por_pagina = parseInt(el.value, 10);
                est.pagina = 1;
                salvarPreferencias();
                carregar(false);
            } else if (el.matches('[data-visoes]')) {
                var v3 = est.visoes.filter(function (x) { return String(x.id) === el.value; })[0];
                if (v3) {
                    aplicarVisao(v3);
                } else {
                    est.visaoAtual = '';
                    renderVisoes();
                }
            }
        });
        var espera = null;
        raiz.querySelector('[data-busca]').addEventListener('input', function () {
            var v = this.value.trim();
            clearTimeout(espera);
            espera = setTimeout(function () {
                if (v) {
                    est.filtros.busca = v;
                } else {
                    delete est.filtros.busca;
                }
                recarregar();
            }, 400);
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && parseInt(est.prefs.intervalo || 0, 10) > 0) {
                carregar(true);
            }
        });
    };

    var iniciarPagina = function () {
        raiz = document.querySelector('[data-visaotecnica]');
        if (!raiz) {
            return;
        }
        pedir('opcoes', {}).then(function (r) {
            if (!r.success) {
                aviso(r.mensagem || 'Não foi possível abrir a Visão técnica.', true);
                return;
            }
            est.escopos = r.escopos;
            est.colunas = r.colunas;
            est.opcoes = r.opcoes;
            est.prefs = r.preferencias;
            est.visoes = r.visoes;
            est.parado = r.parado_dias || 3;
            var salvo = armazenar.ler(CHAVE_ESTADO(), null);
            var padrao = est.visoes.filter(function (v) { return v.padrao; })[0];
            if (salvo && salvo.filtros) {
                est.filtros = salvo.filtros;
                est.ordem = salvo.ordem || est.ordem;
                est.direcao = salvo.direcao || est.direcao;
                est.visaoAtual = salvo.visao && est.visoes.some(function (v) { return v.id === salvo.visao; }) ? salvo.visao : '';
            } else if (padrao) {
                aplicarVisao(padrao);
                ligar();
                raiz.querySelector('[data-intervalo]').value = String(est.prefs.intervalo);
                return;
            }
            raiz.querySelector('[data-intervalo]').value = String(est.prefs.intervalo);
            raiz.querySelector('[data-filtros]').hidden = !armazenar.ler('visaotecnica-filtros-abertos', false);
            renderFiltros();
            renderColunasMenu();
            renderChips();
            renderVisoes();
            ligar();
            carregar(false);
        });
    };

    var iniciar = function () {
        document.querySelectorAll('[data-visaotecnica-ms]').forEach(atualizarMs);
        iniciarPagina();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
