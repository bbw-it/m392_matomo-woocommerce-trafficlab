<?php
/**
 * M392 A/B-Test – Übersicht (Report-Seite unter „A/B Tests").
 * Listet alle konfigurierten Tests; je Test eine Variations-Tabelle
 * (Besuche / eindeutige Besucher / Bestellungen / Conversion-Rate / Umsatz /
 * Ø-Bestellwert) inkl. Total-Zeile, Bayes-Wahrscheinlichkeit „besser als
 * Original" (Sofort-Näherung) und Gewinner-Markierung. Tests anlegen/löschen
 * läuft über den Controller.
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
        $period = Common::getRequestVar('period', 'month', 'string');
        $date   = Common::getRequestVar('date', 'today', 'string');
        Piwik::checkUserHasViewAccess($idSite);

        $tests = [];
        foreach (Storage::getTests() as $def) {
            $tests[] = self::computeTest($def, $idSite, $period, $date);
        }

        $view = new View('@M392ABTesting/index');
        $view->tests = $tests;
        $view->prettyDate = $period . ' / ' . $date;
        $view->idSite = $idSite;
        $view->period = $period;
        $view->date = $date;
        $view->deleteNonce = Nonce::getNonce('M392ABTesting.delete');
        $view->defaultId = Storage::defaultTest()['id'];
        return $view->render();
    }

    /** Kennzahlen + Bayes für einen Test aufbereiten. */
    public static function computeTest(array $def, $idSite, $period, $date)
    {
        $variants = [];
        $tot = ['visits' => 0, 'uniq' => 0, 'orders' => 0, 'revenue' => 0.0];
        foreach (($def['variants'] ?? []) as $v) {
            $m = Stats::variantMetrics($idSite, $period, $date, $v['segment'] ?? '');
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
            if ($i === 0 || !$base) {
                $v['prob'] = null;          // Basis hat keine Vergleichswahrscheinlichkeit
            } else {
                $v['prob'] = Stats::probBetterNormal(
                    $base['orders'], $base['visits'], $v['orders'], $v['visits']
                );
            }
        }
        unset($v);

        // Gewinner: höchste Conversion-Rate, aber nur bei echten Daten und klarem
        // Vorsprung (keine „alle Gewinner" bei Gleichstand / 0 Bestellungen).
        $winner = -1; $bestCr = -1; $tie = false;
        foreach ($variants as $i => $v) {
            if ($v['visits'] <= 0) { continue; }
            if ($v['cr'] > $bestCr + 1e-9) { $bestCr = $v['cr']; $winner = $i; $tie = false; }
            elseif (abs($v['cr'] - $bestCr) <= 1e-9) { $tie = true; }
        }
        if ($winner < 0 || $tie || $bestCr <= 0) { $winner = -1; }
        foreach ($variants as $i => &$v) { $v['is_winner'] = ($i === $winner); }
        unset($v);

        $created = (int) ($def['created'] ?? 0);
        if ($created > 0) {
            $days = max(0, (int) floor((time() - $created) / 86400));
            $running = 'läuft seit ' . $days . ' Tag' . ($days === 1 ? '' : 'en')
                     . ' (seit ' . date('d.m.Y', $created) . ')';
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
        ];
    }
}
