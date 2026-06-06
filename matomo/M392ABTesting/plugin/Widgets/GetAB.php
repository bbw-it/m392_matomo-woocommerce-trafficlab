<?php
/**
 * M392 A/B-Test – Report-Seite (Widget) mit Vergleichstabelle Original vs.
 * Shop-Variante. Liest die Custom Dimension „AB-Variante" inkl. der
 * E-Commerce-Kennzahlen je Variante. Kostenfreier Ersatz fuer das bezahlte
 * A/B-Testing-Plugin. Gerendert als Sidebar-Seite via Category/Subcategory.
 */
namespace Piwik\Plugins\M392ABTesting\Widgets;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\View;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

class GetAB extends Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('ProfessionalServices_PromoAbTesting');
        $config->setSubcategoryId('M392ABTesting_Overview');
        $config->setName('Vergleich (M392)');
        $config->setOrder(1);
    }

    public function render()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        $period = Common::getRequestVar('period', 'month', 'string');
        $date   = Common::getRequestVar('date', 'today', 'string');
        Piwik::checkUserHasViewAccess($idSite);

        // Index der Custom Dimension "AB-Variante" ermitteln.
        $idDimension = null;
        $dims = Request::processRequest('CustomDimensions.getConfiguredCustomDimensions', [
            'idSite' => $idSite, 'format' => 'original',
        ]);
        foreach ((array) $dims as $d) {
            if (($d['name'] ?? '') === 'AB-Variante') {
                $idDimension = (int) $d['idcustomdimension'];
                break;
            }
        }

        $rows = [];
        if ($idDimension !== null) {
            // Ein Aufruf: Besuche + (verschachtelt) die Ziel-Kennzahlen je Variante.
            // Die E-Commerce-Bestellungen/-Umsatz stehen unter goals['idgoal=ecommerceOrder']
            // (die Spalte nb_conversions auf Zeilenebene waere die Summe ALLER Ziele).
            $base = Request::processRequest('CustomDimensions.getCustomDimension', [
                'idSite' => $idSite, 'period' => $period, 'date' => $date,
                'idDimension' => $idDimension, 'format' => 'original',
            ]);
            foreach ($base->getRows() as $r) {
                $visits = (int) $r->getColumn('nb_visits');
                $goals  = $r->getColumn('goals');
                $eco    = is_array($goals) && isset($goals['idgoal=ecommerceOrder'])
                    ? $goals['idgoal=ecommerceOrder'] : [];
                $orders  = (int) ($eco['nb_conversions'] ?? 0);
                $revenue = (float) ($eco['revenue'] ?? 0.0);
                $rows[] = [
                    'label'   => $r->getColumn('label'),
                    'visits'  => $visits,
                    'orders'  => $orders,
                    'revenue' => $revenue,
                    'cr'      => $visits > 0 ? round(100 * $orders / $visits, 2) : 0.0,
                    'aov'     => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                ];
            }
        }

        // Gewinner (hoehere Conversion-Rate) markieren.
        $best = null;
        foreach ($rows as $r) {
            if ($best === null || $r['cr'] > $best) {
                $best = $r['cr'];
            }
        }

        $view = new View('@M392ABTesting/index');
        $view->rows = $rows;
        $view->bestCr = $best;
        $view->prettyDate = $period . ' / ' . $date;
        $view->configured = ($idDimension !== null);
        return $view->render();
    }
}
