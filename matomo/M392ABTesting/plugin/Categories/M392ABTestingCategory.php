<?php
/**
 * Eigene Sidebar-Kategorie „M392 · A/B-Test" im Matomo-Berichtsmenü.
 */
namespace Piwik\Plugins\M392ABTesting\Categories;

use Piwik\Category\Category;

class M392ABTestingCategory extends Category
{
    protected $id = 'M392ABTesting';
    protected $order = 37;

    public function getDisplayName()
    {
        return 'M392 · A/B-Test';
    }
}
