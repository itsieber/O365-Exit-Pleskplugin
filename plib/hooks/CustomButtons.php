<?php

class Modules_O365ExitMigrator_CustomButtons extends pm_Hook_CustomButtons
{
    public function getButtons()
    {
        return [[
            'place'       => self::PLACE_ADMIN_NAVIGATION,
            'section'     => self::SECTION_NAV_GENERAL,
            'title'       => 'O365 Exit',
            'description' => 'Office 365 Postfächer zu Plesk migrieren',
            'link'        => pm_Context::getActionUrl('index', 'index'),
        ], [
            'place'        => self::PLACE_DOMAIN,
            'title'        => 'O365 Exit',
            'description'  => 'Office 365 Postfächer migrieren',
            'link'         => pm_Context::getActionUrl('index', 'domains'),
            'contextParams'=> true,
            'order'        => 2,
        ]];
    }
}
