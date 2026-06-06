<?php
namespace Piwik\Plugins\M392Funnels;

use Piwik\Menu\MenuReporting;

class Menu extends \Piwik\Plugin\Menu
{
    public function configureReportingMenu(MenuReporting $menu)
    {
        // Eigener Eintrag im Berichts-Menü: "M392 Funnel".
        $menu->addItem('M392 Funnel', 'Trichter', $this->urlForAction('index'), 90);
    }
}
