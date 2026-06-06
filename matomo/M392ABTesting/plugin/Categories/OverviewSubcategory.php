<?php
/**
 * Hängt die A/B-Report-Seite UNTER den bestehenden Matomo-Menüpunkt
 * „A/B Tests" (Promo-Kategorie des ProfessionalServices-Plugins, Icon icon-lab).
 */
namespace Piwik\Plugins\M392ABTesting\Categories;

use Piwik\Category\Subcategory;

class OverviewSubcategory extends Subcategory
{
    protected $categoryId = 'ProfessionalServices_PromoAbTesting';
    protected $id = 'M392ABTesting_Overview';
    protected $name = 'Vergleich (M392)';
    protected $order = 1;
}
