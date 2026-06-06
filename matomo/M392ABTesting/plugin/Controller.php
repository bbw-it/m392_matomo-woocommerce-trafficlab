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
            // Besuche je Variante.
            $base = Request::processRequest('CustomDimensions.getCustomDimension', [
                'idSite' => $idSite, 'period' => $period, 'date' => $date,
                'idDimension' => $idDimension, 'format' => 'original',
            ]);
            // E-Commerce-Kennzahlen je Variante (idGoal=ecommerceOrder).
            $eco = Request::processRequest('CustomDimensions.getCustomDimension', [
                'idSite' => $idSite, 'period' => $period, 'date' => $date,
                'idDimension' => $idDimension, 'idGoal' => '0', 'format' => 'original',
            ]);
            $ecoByLabel = [];
            foreach ($eco->getRows() as $r) {
                $ecoByLabel[$r->getColumn('label')] = $r;
            }
            foreach ($base->getRows() as $r) {
                $label = $r->getColumn('label');
                $e = $ecoByLabel[$label] ?? null;
                $visits = (int) $r->getColumn('nb_visits');
                $orders = $e ? (int) $e->getColumn('nb_conversions') : 0;
                $revenue = $e ? (float) $e->getColumn('revenue') : 0.0;
                $rows[] = [
                    'label'    => $label,
                    'visits'   => $visits,
                    'orders'   => $orders,
                    'revenue'  => $revenue,
                    'cr'       => $visits > 0 ? round(100 * $orders / $visits, 2) : 0.0,
                    'aov'      => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
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
