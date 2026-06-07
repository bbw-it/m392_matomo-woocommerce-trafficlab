<?php
/**
 * Kennzahlen je Variante (über Matomo-Segmente) + Bayes-Auswertung.
 *
 * Modell (Standard für Conversion-A/B-Tests): die Conversion-Rate jeder Variante
 * ist Beta-verteilt. Mit uniformem Prior Beta(1,1) ist die Posterior
 *   Beta(1 + Bestellungen, 1 + Besuche − Bestellungen).
 * Gefragt ist P(Variante besser als Original) = P(CR_B > CR_A).
 *  - schnell: Normal-Approximation der Beta-Posterioren (sofort).
 *  - exakt:   Monte-Carlo aus den Beta-Posterioren (auf Knopfdruck).
 */
namespace Piwik\Plugins\M392ABTesting;

use Piwik\API\Request;

class Stats
{
    /**
     * Datums-Range „seit Teststart bis heute" für die KUMULIERTE Auswertung.
     * So springt der P-Wert nicht monatlich, sondern berücksichtigt die gesamte
     * Laufzeit (Best Practice für A/B-Tests). `created==0` = „seit Beginn".
     */
    public static function testRange($test)
    {
        $created = (int) ($test['created'] ?? 0);
        $startTs = $created > 0 ? $created : (time() - 730 * 86400);
        return date('Y-m-d', $startTs) . ',' . date('Y-m-d');
    }

    /**
     * Monatlicher Verlauf der Conversion-Rate je Variante (Kontext-Chart, NICHT
     * die Gewinner-Basis). Liefert [['month'=>label,'cr'=>float,'visits','orders'], …].
     */
    public static function variantSeriesCR($idSite, $segment, $dateRange)
    {
        $out = [];
        try {
            $vs = Request::processRequest('VisitsSummary.get', [
                'idSite' => $idSite, 'period' => 'month', 'date' => $dateRange,
                'segment' => $segment, 'format' => 'original',
            ]);
            $g = Request::processRequest('Goals.get', [
                'idSite' => $idSite, 'period' => 'month', 'date' => $dateRange,
                'idGoal' => 'ecommerceOrder', 'segment' => $segment, 'format' => 'original',
            ]);
            if (!$vs || !method_exists($vs, 'getDataTables')) {
                return $out;
            }
            $goalTables = ($g && method_exists($g, 'getDataTables')) ? $g->getDataTables() : [];
            foreach ($vs->getDataTables() as $label => $vt) {
                $vrow = $vt->getFirstRow();
                $visits = $vrow ? (int) $vrow->getColumn('nb_visits') : 0;
                $orders = 0;
                if (isset($goalTables[$label]) && $goalTables[$label]->getFirstRow()) {
                    $orders = (int) $goalTables[$label]->getFirstRow()->getColumn('nb_conversions');
                }
                $out[] = [
                    'month'  => $label,
                    'visits' => $visits,
                    'orders' => $orders,
                    'cr'     => $visits > 0 ? $orders / $visits : 0.0,
                ];
            }
        } catch (\Exception $e) {
            return [];
        }
        return $out;
    }

    /** Besuche / eindeutige Besucher / Bestellungen / Umsatz je Segment. */
    public static function variantMetrics($idSite, $period, $date, $segment)
    {
        $visits = 0;
        $uniq   = 0;
        $vs = Request::processRequest('VisitsSummary.get', [
            'idSite' => $idSite, 'period' => $period, 'date' => $date,
            'segment' => $segment, 'format' => 'original',
        ]);
        if ($vs && method_exists($vs, 'getFirstRow') && $vs->getFirstRow()) {
            $row = $vs->getFirstRow();
            $visits = (int) $row->getColumn('nb_visits');
            $uniq   = (int) ($row->getColumn('nb_uniq_visitors') ?: 0);
        }

        $orders  = 0;
        $revenue = 0.0;
        $g = Request::processRequest('Goals.get', [
            'idSite' => $idSite, 'period' => $period, 'date' => $date,
            'idGoal' => 'ecommerceOrder', 'segment' => $segment, 'format' => 'original',
        ]);
        if ($g && method_exists($g, 'getFirstRow') && $g->getFirstRow()) {
            $gr = $g->getFirstRow();
            $orders  = (int) $gr->getColumn('nb_conversions');
            $revenue = (float) $gr->getColumn('revenue');
        }

        return [
            'visits'  => $visits,
            'uniq'    => $uniq,
            'orders'  => $orders,
            'revenue' => $revenue,
            'cr'      => $visits > 0 ? $orders / $visits : 0.0,
            'aov'     => $orders > 0 ? $revenue / $orders : 0.0,
        ];
    }

    // ----- Bayes ------------------------------------------------------------

    private static function erf($x)
    {
        // Abramowitz & Stegun 7.1.26 (max. Fehler ~1.5e-7).
        $t = 1 / (1 + 0.3275911 * abs($x));
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t
                 - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);
        return $x < 0 ? -$y : $y;
    }

    /** P(CR_B > CR_A) via Normal-Approximation der Beta-Posterioren (sofort). */
    public static function probBetterNormal($cA, $nA, $cB, $nB)
    {
        $aA = 1 + $cA; $bA = 1 + max(0, $nA - $cA);
        $aB = 1 + $cB; $bB = 1 + max(0, $nB - $cB);
        $mA = $aA / ($aA + $bA);
        $mB = $aB / ($aB + $bB);
        $vA = $aA * $bA / (pow($aA + $bA, 2) * ($aA + $bA + 1));
        $vB = $aB * $bB / (pow($aB + $bB, 2) * ($aB + $bB + 1));
        $z = ($mB - $mA) / sqrt($vA + $vB + 1e-12);
        return 0.5 * (1 + self::erf($z / M_SQRT2));
    }

    // ----- Monte-Carlo (exakt) ---------------------------------------------

    private static function rnd()
    {
        return (mt_rand() + 1) / (mt_getrandmax() + 2); // (0,1)
    }

    private static function normal()
    {
        // Box-Muller
        $u1 = self::rnd();
        $u2 = self::rnd();
        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /** Gamma(shape, 1) – Marsaglia & Tsang. */
    private static function gamma($k)
    {
        if ($k < 1) {
            return self::gamma($k + 1) * pow(self::rnd(), 1 / $k);
        }
        $d = $k - 1.0 / 3.0;
        $c = 1.0 / sqrt(9 * $d);
        while (true) {
            do {
                $x = self::normal();
                $v = 1 + $c * $x;
            } while ($v <= 0);
            $v = $v * $v * $v;
            $u = self::rnd();
            if ($u < 1 - 0.0331 * $x * $x * $x * $x) {
                return $d * $v;
            }
            if (log($u) < 0.5 * $x * $x + $d * (1 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    private static function beta($a, $b)
    {
        $x = self::gamma($a);
        $y = self::gamma($b);
        return $x / ($x + $y);
    }

    /**
     * Exakte Bayes-Auswertung via Monte-Carlo.
     * Liefert P(B>A), erwartete relative Steigerung (uplift) und 95%-Intervall
     * der Differenz CR_B − CR_A.
     */
    public static function bayesMonteCarlo($cA, $nA, $cB, $nB, $samples = 100000)
    {
        mt_srand(392);  // reproduzierbar
        $aA = 1 + $cA; $bA = 1 + max(0, $nA - $cA);
        $aB = 1 + $cB; $bB = 1 + max(0, $nB - $cB);
        $wins = 0;
        $diffs = [];
        for ($i = 0; $i < $samples; $i++) {
            $pa = self::beta($aA, $bA);
            $pb = self::beta($aB, $bB);
            if ($pb > $pa) {
                $wins++;
            }
            // nur jede 4. Differenz behalten (Speicher/Quantil reicht völlig)
            if (($i & 3) === 0) {
                $diffs[] = $pb - $pa;
            }
        }
        sort($diffs);
        $n = count($diffs);
        $lo = $diffs[(int) floor(0.025 * ($n - 1))];
        $hi = $diffs[(int) floor(0.975 * ($n - 1))];
        $crA = $aA / ($aA + $bA);
        $crB = $aB / ($aB + $bB);
        $uplift = $crA > 0 ? ($crB - $crA) / $crA : 0.0;
        return [
            'prob'        => $wins / $samples,
            'uplift'      => $uplift,
            'ci_low'      => $lo,
            'ci_high'     => $hi,
            'samples'     => $samples,
        ];
    }
}
