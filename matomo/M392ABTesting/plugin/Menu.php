<?php
namespace Piwik\Plugins\M392ABTesting;

use Piwik\Menu\MenuReporting;

class Menu extends \Piwik\Plugin\Menu
{
    public function configureReportingMenu(MenuReporting $menu)
    {
        $menu->addItem('M392 Funnel', 'A/B-Test', $this->urlForAction('index'), 95);
    }
}
