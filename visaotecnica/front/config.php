<?php

/**
 * Plugin Visão Técnica - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginVisaotecnicaConfig::url('config.form.php'));
