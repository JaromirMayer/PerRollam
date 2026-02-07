<?php
if (!defined('ABSPATH')) exit;

final class Spolek_Plugin {

    public static function init() : void {
        // dočasně: legacy stále drží veškeré hooky (chování 1:1)
        Spolek_Hlasovani_MVP::init();
    }

    public static function activate() : void {
        Spolek_Hlasovani_MVP::activate();
    }
}
