<?php

/**
 * Plugin Visão Técnica - instalação e desinstalação
 */

function plugin_visaotecnica_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    if (!$DB->tableExists('glpi_plugin_visaotecnica_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_visaotecnica_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }
    foreach (PluginVisaotecnicaConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_visaotecnica_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_visaotecnica_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor]);
        }
    }

    // Visões salvas de cada pessoa (filtros + colunas)
    if (!$DB->tableExists('glpi_plugin_visaotecnica_visoes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_visaotecnica_visoes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `nome` varchar(100) NOT NULL DEFAULT '',
            `filtros` text NULL,
            `is_default` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `usuario_nome` (`users_id`, `nome`)
        ) $opcoes");
    }

    // Preferências de cada pessoa (colunas, atualização automática, itens por página)
    if (!$DB->tableExists('glpi_plugin_visaotecnica_preferencias')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_visaotecnica_preferencias` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `colunas` text NULL,
            `intervalo` int unsigned NOT NULL DEFAULT 60,
            `por_pagina` int unsigned NOT NULL DEFAULT 25,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`)
        ) $opcoes");
    }

    return true;
}

function plugin_visaotecnica_uninstall(): bool
{
    // Regra do projeto: as tabelas ficam (reinstalar recupera visões e preferências)
    return true;
}
