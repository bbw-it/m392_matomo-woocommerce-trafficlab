<?php
namespace Piwik\Plugins\M392Funnels;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\View;

class Controller extends \Piwik\Plugin\Controller
{
    /** Die vier Funnel-Schritte (Ziel-Namen exakt wie in M392Funnels/setup.sh). */
    private static $steps = [
        'Funnel-1: Produkt angesehen',
        'Funnel-2: In den Warenkorb',
        'Funnel-3: Kasse',
        'Funnel-4: Kauf abgeschlossen',
    ];
    private static $labels = ['Produkt', 'Warenkorb', 'Kasse', 'Kauf'];

    public function index()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        $period = Common::getRequestVar('period', 'month', 'string');
        $date   = Common::getRequestVar('date', 'today', 'string');

        // Ziel-IDs zu den Schritt-Namen ermitteln.
        $goals = Request::processRequest('Goals.getGoals', [
            'idSite' => $idSite, 'filter_limit' => '-1', 'format' => 'original',
        ]);
        $idByName = [];
        foreach ((array) $goals as $g) {
            if (isset($g['name'])) { $idByName[$g['name']] = (int) $g['idgoal']; }
        }

        $steps = [];
        $firstCount = null;
        foreach (self::$steps as $i => $name) {
            $count = 0;
            if (isset($idByName[$name])) {
                $table = Request::processRequest('Goals.get', [
                    'idSite' => $idSite, 'period' => $period, 'date' => $date,
                    'idGoal' => $idByName[$name], 'format' => 'original',
                ]);
                $row = $table->getFirstRow();
                $count = $row ? (int) $row->getColumn('nb_conversions') : 0;
            }
            if ($firstCount === null) { $firstCount = max(1, $count); }
            $steps[] = [
                'label'     => self::$labels[$i],
                'name'      => $name,
                'count'     => $count,
                'pct_total' => round(100 * $count / $firstCount, 1),
            ];
        }
        // Drop-off zum jeweils vorherigen Schritt.
        foreach ($steps as $i => &$s) {
            $prev = $i > 0 ? $steps[$i - 1]['count'] : $s['count'];
            $s['pct_step'] = $prev > 0 ? round(100 * $s['count'] / $prev, 1) : 100.0;
            $s['dropoff']  = $prev > 0 ? round(100 * ($prev - $s['count']) / $prev, 1) : 0.0;
        }
        unset($s);

        $view = new View('@M392Funnels/index');
        $view->steps = $steps;
        $view->prettyDate = $period . ' / ' . $date;
        return $view->render();
    }
}
