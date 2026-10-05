<?php

/**
 * Plugin Visão Técnica - GLPI 11 e 12
 * Mesa de trabalho do técnico: chamados, problemas e mudanças por visão (atribuídos a mim, do meu
 * grupo, fila do grupo, que acompanho, que abri, sem atribuição, todos), com filtros, colunas
 * escolhidas pela pessoa, visões salvas, acompanhamento rápido, "assumir" e atualização automática.
 * Substitui o antigo plugin meustickets.
 */

define('PLUGIN_VISAOTECNICA_VERSION', '1.0.0');
define('PLUGIN_VISAOTECNICA_MIN_GLPI', '11.0.0');
define('PLUGIN_VISAOTECNICA_MAX_GLPI', '12.99.99');

function plugin_init_visaotecnica(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['visaotecnica'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('visaotecnica')) {
        return;
    }

    Plugin::registerClass('PluginVisaotecnicaMenu');
    $PLUGIN_HOOKS['config_page']['visaotecnica'] = 'front/config.form.php';
    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['menu_toadd']['visaotecnica'] = ['helpdesk' => 'PluginVisaotecnicaMenu'];
    }
}

function plugin_version_visaotecnica(): array
{
    return [
        'name'         => 'Visão Técnica',
        'version'      => PLUGIN_VISAOTECNICA_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_VISAOTECNICA_MIN_GLPI,
                'max' => PLUGIN_VISAOTECNICA_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_visaotecnica_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_VISAOTECNICA_MIN_GLPI, '>=');
}

function plugin_visaotecnica_check_config($verbose = false): bool
{
    return true;
}
