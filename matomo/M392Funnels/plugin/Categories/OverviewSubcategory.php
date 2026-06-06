<?php
/**
 * Hängt die Funnel-Report-Seite UNTER den bestehenden Matomo-Menüpunkt
 * „Funnels" (Promo-Kategorie des ProfessionalServices-Plugins, Icon icon-funnel).
 * So erscheint unser Bericht direkt dort, wo Lernende ihn erwarten.
 */
namespace Piwik\Plugins\M392Funnels\Categories;

use Piwik\Category\Subcategory;

class OverviewSubcategory extends Subcategory
{
    protected $categoryId = 'ProfessionalServices_PromoFunnels';
    protected $id = 'M392Funnels_Overview';
    protected $name = 'Trichter (M392)';
    protected $order = 1;
}
