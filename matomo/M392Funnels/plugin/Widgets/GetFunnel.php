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

        $sankey = self::buildSankey($steps);

        $view = new View('@M392Funnels/index');
        $view->steps = $steps;
        $view->sankey = $sankey;
        $view->prettyDate = $period . ' / ' . $date;
        return $view->render();
    }

    /** SVG-Sankey-Geometrie: Knoten (Stufen) + Flüsse (weiter / Abbruch). */
    private static function buildSankey(array $steps)
    {
        $W = 880; $H = 430; $padL = 30; $topPad = 34; $nodeW = 20; $usableH = 200;
        $baseDrop = $topPad + $usableH + 64;          // y, wo die Abbruch-Bänder landen
        $n = count($steps);
        $maxCount = ($n > 0 && $steps[0]['count'] > 0) ? $steps[0]['count'] : 1;
        $colStep = $n > 1 ? ($W - 2 * $padL - $nodeW) / ($n - 1) : 0;

        $h = function ($c) use ($maxCount, $usableH) {
            if ($c <= 0) { return 3.0; }
            return max(6.0, ($c / $maxCount) * $usableH);
        };

        $nodes = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $padL + $i * $colStep;
            $nodes[] = [
                'x' => round($x, 1), 'y' => $topPad, 'w' => $nodeW, 'h' => round($h($steps[$i]['count']), 1),
                'cx' => round($x + $nodeW / 2, 1),
                'label' => $steps[$i]['label'], 'count' => $steps[$i]['count'],
                'pct_total' => $steps[$i]['pct_total'], 'page' => $steps[$i]['page'], 'path' => $steps[$i]['path'],
            ];
        }

        $band = function ($x1, $t1, $b1, $x2, $t2, $b2) {
            $cx = ($x1 + $x2) / 2;
            return sprintf('M %.1f %.1f C %.1f %.1f, %.1f %.1f, %.1f %.1f L %.1f %.1f C %.1f %.1f, %.1f %.1f, %.1f %.1f Z',
                $x1, $t1, $cx, $t1, $cx, $t2, $x2, $t2, $x2, $b2, $cx, $b2, $cx, $b1, $x1, $b1);
        };

        $go = []; $drop = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $hi = $h($steps[$i]['count']); $hn = $h($steps[$i + 1]['count']);
            $xr = $nodes[$i]['x'] + $nodeW; $xn = $nodes[$i + 1]['x'];
            // „weiter"-Band: oben angesetzt, Dicke = nächster Schritt.
            $go[] = [
                'd' => $band($xr, $topPad, $topPad + $hn, $xn, $topPad, $topPad + $hn),
                'count' => $steps[$i + 1]['count'], 'pct' => $steps[$i + 1]['pct_step'],
            ];
            // „Abbruch"-Band: unteres Reststück von Knoten i, fällt nach unten zu einem Chip.
            if ($steps[$i + 1]['drop_abs'] > 0 && $hi - $hn > 0.5) {
                $mx = $xr + $colStep * 0.5;
                $chipH = max(8.0, min($hi - $hn, 34.0));
                $drop[] = [
                    'd' => $band($xr, $topPad + $hn, $topPad + $hi, $mx, $baseDrop, $baseDrop + $chipH),
                    'count' => $steps[$i + 1]['drop_abs'], 'pct' => $steps[$i + 1]['dropoff'],
                    'mx' => round($mx, 1), 'my' => round($baseDrop + $chipH + 14, 1),
                ];
            }
        }

        return ['w' => $W, 'h' => $H, 'nodes' => $nodes, 'go' => $go, 'drop' => $drop, 'topPad' => $topPad];
    }
}
