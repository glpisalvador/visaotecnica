<?php

/**
 * Plugin Visão Técnica - item no menu Assistência
 */
class PluginVisaotecnicaMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Visão técnica';
    }

    public static function getMenuName(): string
    {
        return 'Visão técnica';
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-list';
    }

    public static function canView(): bool
    {
        return PluginVisaotecnicaConfig::podeUsar();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $C = PluginVisaotecnicaConfig::class;
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $C::url('visaotecnica.php'),
            'icon'  => self::getIcon(),
            'links' => ['search' => $C::url('visaotecnica.php')],
        ];
        if ($C::ehAdmin()) {
            $menu['links']['config'] = $C::url('config.form.php');
        }
        return $menu;
    }
}
