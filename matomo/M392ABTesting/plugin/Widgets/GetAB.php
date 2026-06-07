<?php
/**
 * M392 A/B-Test – Übersicht (Report-Seite unter „A/B Tests").
 *
 * Auswertung KUMULIERT über die gesamte Laufzeit seit Teststart (nicht je Monat) –
 * so ist der Gewinner stabil und der P-Wert springt nicht. Zusätzlich ein
 * Monats-Verlauf der Conversion-Rate je Variante als Kontext. Tests anlegen/löschen
 * inline (Formular) bzw. über den Controller.
 */
namespace Piwik\Plugins\M392ABTesting\Widgets;

use Piwik\Common;
use Piwik\Nonce;
use Piwik\Piwik;
use Piwik\View;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;
use Piwik\Plugins\M392ABTesting\Stats;
use Piwik\Plugins\M392ABTesting\Storage;

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
        Piwik::checkUserHasViewAccess($idSite);

        $tests = [];
        foreach (Storage::getTests() as $def) {
            $tests[] = self::computeTest($def, $idSite);
        }

        $view = new View('@M392ABTesting/index');
        $view->tests = $tests;
        $view->idSite = $idSite;
        // Datums-Kontext für Links (Controller braucht period/date für den Redirect zurück).
        $view->period = Common::getRequestVar('period', 'month', 'string');
        $view->date   = Common::getRequestVar('date', 'today', 'string');
        $view->deleteNonce = Nonce::getNonce('M392ABTesting.delete');
        $view->saveNonce   = Nonce::getNonce('M392ABTesting.save');
        $view->defaultId = Storage::defaultTest()['id'];
        return $view->render();
    }

    /** Kennzahlen (kumuliert seit Teststart) + Bayes + Monats-Verlauf für einen Test. */
    public static function computeTest(array $def, $idSite)
    {
        $range = Stats::testRange($def);

        $variants = [];
        $tot = ['visits' => 0, 'uniq' => 0, 'orders' => 0, 'revenue' => 0.0];
        foreach (($def['variants'] ?? []) as $v) {
            $m = Stats::variantMetrics($idSite, 'range', $range, $v['segment'] ?? '');
            $m['label']   = $v['label'] ?? '?';
            $m['segment'] = $v['segment'] ?? '';
            $variants[] = $m;
            $tot['visits']  += $m['visits'];
            $tot['uniq']    += $m['uniq'];
            $tot['orders']  += $m['orders'];
            $tot['revenue'] += $m['revenue'];
        }
        $tot['cr'] = $tot['visits'] > 0 ? $tot['orders'] / $tot['visits'] : 0.0;

        // Basis = erste Variante (Original). Bayes je weiterer Variante dagegen.
        $base = $variants[0] ?? null;
        foreach ($variants as $i => &$v) {
            $v['prob'] = ($i === 0 || !$base) ? null
                : Stats::probBetterNormal($base['orders'], $base['visits'], $v['orders'], $v['visits']);
        }
        unset($v);

        // Gewinner: höchste Conversion-Rate, nur bei echten Daten + klarem Vorsprung.
        $winner = -1; $bestCr = -1; $tie = false;
        foreach ($variants as $i => $v) {
            if ($v['visits'] <= 0) { continue; }
            if ($v['cr'] > $bestCr + 1e-9) { $bestCr = $v['cr']; $winner = $i; $tie = false; }
            elseif (abs($v['cr'] - $bestCr) <= 1e-9) { $tie = true; }
        }
        if ($winner < 0 || $tie || $bestCr <= 0) { $winner = -1; }
        foreach ($variants as $i => &$v) { $v['is_winner'] = ($i === $winner); }
        unset($v);

        // Monats-Verlauf der CR je Variante (Kontext). Gemeinsame Monatsachse.
        $monthsSet = []; $rawSeries = [];
        foreach (($def['variants'] ?? []) as $vi => $v) {
            $s = Stats::variantSeriesCR($idSite, $v['segment'] ?? '', $range);
            $byMonth = [];
            foreach ($s as $pt) { $byMonth[$pt['month']] = $pt['cr']; $monthsSet[$pt['month']] = true; }
            $rawSeries[$vi] = $byMonth;
        }
        $months = array_keys($monthsSet);
        sort($months);
        $evolution = [];
        foreach (($def['variants'] ?? []) as $vi => $v) {
            $crs = [];
            foreach ($months as $mo) { $crs[] = round((($rawSeries[$vi][$mo] ?? 0.0)) * 100, 4); }
            $evolution[] = ['label' => $v['label'] ?? '?', 'cr' => $crs];
        }

        $created = (int) ($def['created'] ?? 0);
        if ($created > 0) {
            $days = max(0, (int) floor((time() - $created) / 86400));
            $running = 'läuft seit ' . $days . ' Tag' . ($days === 1 ? '' : 'en')
                     . ' (Start ' . date('d.m.Y', $created) . ')';
        } else {
            $running = 'läuft seit Beginn der Daten';
        }

        return [
            'id'          => $def['id'] ?? '',
            'name'        => $def['name'] ?? '',
            'hypothesis'  => $def['hypothesis'] ?? '',
            'description' => $def['description'] ?? '',
            'running'     => $running,
            'variants'    => $variants,
            'total'       => $tot,
            'has_winner'  => $winner >= 0,
            'has_data'    => $tot['visits'] > 0,
            'months'      => array_values($months),
            'evolution'   => $evolution,
        ];
    }
}
