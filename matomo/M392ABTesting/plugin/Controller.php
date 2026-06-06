<?php
namespace Piwik\Plugins\M392ABTesting;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\View;

class Controller extends \Piwik\Plugin\Controller
{
    public function index()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        $period = Common::getRequestVar('period', 'month', 'string');
        $date   = Common::getRequestVar('date', 'today', 'string');

        // Index der Custom Dimension "AB-Variante" ermitteln.
        $idDimension = null;
        $dims = Request::processRequest('CustomDimensions.getConfiguredCustomDimensions', [
            'idSite' => $idSite, 'format' => 'original',
        ]);
        foreach ((array) $dims as $d) {
            if (($d['name'] ?? '') === 'AB-Variante') { $idDimension = (int) $d['idcustomdimension']; break; }
        }

        $rows = [];
        if ($idDimension !== null) {
            // Ein Aufruf: Besuche + (verschachtelt) die Ziel-Kennzahlen je Variante.
            // Die E-Commerce-Bestellungen/-Umsatz stehen unter goals['idgoal=ecommerceOrder']
            // (die Spalte nb_conversions auf Zeilenebene wäre die Summe ALLER Ziele).
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

        // Gewinner (höhere Conversion-Rate) markieren.
        $best = null;
        foreach ($rows as $r) { if ($best === null || $r['cr'] > $best) { $best = $r['cr']; } }

        $view = new View('@M392ABTesting/index');
        $view->rows = $rows;
        $view->bestCr = $best;
        $view->prettyDate = $period . ' / ' . $date;
        $view->configured = ($idDimension !== null);
        return $view->render();
    }
}
