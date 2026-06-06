<?php
/**
 * Eigene Sidebar-Kategorie „M392 · Funnel" im Matomo-Berichtsmenü.
 * In Matomo 5 entsteht ein Sidebar-Eintrag NUR über Category + Subcategory
 * (+ Widget) – der alte configureReportingMenu-Weg erzeugt keine Kategorie.
 */
namespace Piwik\Plugins\M392Funnels\Categories;

use Piwik\Category\Category;

class M392FunnelsCategory extends Category
{
    protected $id = 'M392Funnels';
    protected $order = 36;

    public function getDisplayName()
    {
        return 'M392 · Funnel';
    }
}
