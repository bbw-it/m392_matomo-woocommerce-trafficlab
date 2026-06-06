<?php
/**
 * M392 Funnel – Report-Seite (Widget) mit Trichter-Diagramm.
 * Liest die vier Funnel-Ziele (URL-basiert) und zeigt je Schritt Conversions,
 * Anteil am Start und Drop-off. Kostenfreier Ersatz fuer das bezahlte
 * Funnels-Plugin. Gerendert als Sidebar-Seite via Category/Subcategory.
 */
namespace Piwik\Plugins\M392Funnels\Widgets;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\View;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

class GetFunnel extends Widget
{
    /** Die vier Funnel-Schritte (Ziel-Namen exakt wie in M392Funnels/setup.sh). */
    /**
     * Die vier Funnel-Schritte. Jeder Schritt entspricht EINER konkreten
     * WordPress/WooCommerce-Seite (Spalten page/path) und dem zugehoerigen
     * URL-Ziel (goal, exakt wie in M392Funnels/setup.sh angelegt).
     */
    private static $defs = [
        ['goal' => 'Funnel-1: Produkt angesehen',   'label' => 'Produkt angesehen',  'page' => 'Produkt-Detailseite', 'path' => '/product/…/'],
        ['goal' => 'Funnel-2: In den Warenkorb',    'label' => 'In den Warenkorb',   'page' => 'Warenkorb',           'path' => '/cart/'],
        ['goal' => 'Funnel-3: Kasse',               'label' => 'Kasse',              'page' => 'Kasse',               'path' => '/checkout/'],
        ['goal' => 'Funnel-4: Kauf abgeschlossen',  'label' => 'Kauf abgeschlossen', 'page' => 'Bestellbestätigung',  'path' => '/checkout/order-received/'],
    ];

    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('ProfessionalServices_PromoFunnels');
        $config->setSubcategoryId('M392Funnels_Overview');
        $config->setName('Trichter (M392)');
        $config->setOrder(1);
    }

    public function render()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        $period = Common::getRequestVar('period', 'month', 'string');
        $date   = Common::getRequestVar('date', 'today', 'string');
        Piwik::checkUserHasViewAccess($idSite);

        // Ziel-IDs zu den Schritt-Namen ermitteln.
        $goals = Request::processRequest('Goals.getGoals', [
            'idSite' => $idSite, 'filter_limit' => '-1', 'format' => 'original',
        ]);
        $idByName = [];
        foreach ((array) $goals as $g) {
            if (isset($g['name'])) {
                $idByName[$g['name']] = (int) $g['idgoal'];
            }
        }

        $steps = [];
        $firstCount = null;
        foreach (self::$defs as $i => $def) {
            $count = 0;
            if (isset($idByName[$def['goal']])) {
                $table = Request::processRequest('Goals.get', [
                    'idSite' => $idSite, 'period' => $period, 'date' => $date,
                    'idGoal' => $idByName[$def['goal']], 'format' => 'original',
                ]);
                $row = $table->getFirstRow();
                $count = $row ? (int) $row->getColumn('nb_conversions') : 0;
            }
            if ($firstCount === null) {
                $firstCount = max(1, $count);
            }
            $steps[] = [
                'label'     => $def['label'],
                'name'      => $def['goal'],
                'page'      => $def['page'],
                'path'      => $def['path'],
                'count'     => $count,
                'pct_total' => round(100 * $count / $firstCount, 1),
            ];
        }
        // Verhaeltnis + Abbruch zum jeweils VORHERIGEN Schritt (absolut + Prozent).
        foreach ($steps as $i => &$s) {
            $prev = $i > 0 ? $steps[$i - 1]['count'] : $s['count'];
            $s['pct_step']  = $prev > 0 ? round(100 * $s['count'] / $prev, 1) : 100.0;
            $s['dropoff']   = $prev > 0 ? round(100 * ($prev - $s['count']) / $prev, 1) : 0.0;
            $s['drop_abs']  = max(0, $prev - $s['count']);
            $s['is_widest'] = ($s['pct_total'] >= 100);
        }
        unset($s);

        $view = new View('@M392Funnels/index');
        $view->steps = $steps;
        $view->prettyDate = $period . ' / ' . $date;
        return $view->render();
    }
}
