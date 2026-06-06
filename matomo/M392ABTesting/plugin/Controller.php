<?php
/**
 * Controller für die A/B-Test-Verwaltung:
 *  - createTest : Formular „Neuen A/B-Test anlegen"
 *  - saveTest   : Formular speichern (Nonce-geschützt) → Option
 *  - deleteTest : Test entfernen (Nonce-geschützt)
 *  - bayesExact : exakte Bayes-Wahrscheinlichkeit (Monte-Carlo) als JSON (AJAX)
 */
namespace Piwik\Plugins\M392ABTesting;

use Piwik\Common;
use Piwik\Nonce;
use Piwik\Piwik;
use Piwik\Url;
use Piwik\View;

class Controller extends \Piwik\Plugin\Controller
{
    /** Zurück zur Report-Übersicht (SPA-Reporting-Seite unter „A/B Tests"). */
    private function overviewUrl()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        $period = Common::getRequestVar('period', 'month', 'string');
        $date   = Common::getRequestVar('date', 'today', 'string');
        $hash = '?idSite=' . $idSite . '&period=' . $period . '&date=' . $date
              . '&category=ProfessionalServices_PromoAbTesting&subcategory=M392ABTesting_Overview';
        return 'index.php?module=CoreHome&action=index&idSite=' . $idSite
             . '&period=' . $period . '&date=' . $date . '#' . $hash;
    }

    public function createTest()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        Piwik::checkUserHasViewAccess($idSite);

        $view = new View('@M392ABTesting/create');
        $view->nonce = Nonce::getNonce('M392ABTesting.save');
        $view->idSite = $idSite;
        $view->period = Common::getRequestVar('period', 'month', 'string');
        $view->date   = Common::getRequestVar('date', 'today', 'string');
        $view->overviewUrl = $this->overviewUrl();
        $view->error = Common::getRequestVar('error', '', 'string');
        return $view->render();
    }

    public function saveTest()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        Piwik::checkUserHasViewAccess($idSite);
        $nonce = Common::getRequestVar('nonce', '', 'string');
        if (!Nonce::verifyNonce('M392ABTesting.save', $nonce)) {
            throw new \Exception('Ungültiges Formular-Token (Nonce). Bitte erneut versuchen.');
        }

        $name = trim(Common::getRequestVar('name', '', 'string'));
        $hypothesis  = trim(Common::getRequestVar('hypothesis', '', 'string'));
        $description = trim(Common::getRequestVar('description', '', 'string'));

        $labels = Common::getRequestVar('variant_label', [], 'array');
        $urls   = Common::getRequestVar('variant_url', [], 'array');
        $labels = is_array($labels) ? $labels : [];
        $urls   = is_array($urls) ? $urls : [];

        $variants = [];
        foreach ($labels as $i => $label) {
            $label = trim((string) $label);
            $url   = trim((string) ($urls[$i] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            // „URL enthält"-Muster → Matomo-Segment.
            $variants[] = ['label' => $label, 'segment' => 'pageUrl=@' . $url];
        }

        if ($name === '' || count($variants) < 2) {
            Url::redirectToUrl('index.php?module=M392ABTesting&action=createTest&idSite=' . $idSite
                . '&period=' . Common::getRequestVar('period', 'month', 'string')
                . '&date=' . Common::getRequestVar('date', 'today', 'string')
                . '&error=' . urlencode('Bitte einen Namen und mindestens zwei Varianten (Label + URL) angeben.'));
            return;
        }

        Storage::addTest([
            'id'          => Storage::slug($name) . '-' . substr(md5($name . microtime()), 0, 4),
            'name'        => $name,
            'hypothesis'  => $hypothesis,
            'description' => $description,
            'created'     => time(),
            'variants'    => $variants,
        ]);

        Url::redirectToUrl($this->overviewUrl());
    }

    public function deleteTest()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        Piwik::checkUserHasViewAccess($idSite);
        $nonce = Common::getRequestVar('nonce', '', 'string');
        if (!Nonce::verifyNonce('M392ABTesting.delete', $nonce)) {
            throw new \Exception('Ungültiges Formular-Token (Nonce).');
        }
        $id = Common::getRequestVar('testId', '', 'string');
        if ($id !== '' && $id !== Storage::defaultTest()['id']) {
            Storage::deleteTest($id);
        }
        Url::redirectToUrl($this->overviewUrl());
    }

    /** Exakte Bayes-Wahrscheinlichkeit (Monte-Carlo) für eine Variante vs. Original. */
    public function bayesExact()
    {
        $idSite = Common::getRequestVar('idSite', 1, 'int');
        Piwik::checkUserHasViewAccess($idSite);
        $period = Common::getRequestVar('period', 'month', 'string');
        $date   = Common::getRequestVar('date', 'today', 'string');
        $testId = Common::getRequestVar('testId', '', 'string');
        $vIndex = Common::getRequestVar('variant', 1, 'int');

        $test = Storage::getTest($testId);
        $result = ['ok' => false];
        if ($test && isset($test['variants'][0], $test['variants'][$vIndex])) {
            $a = Stats::variantMetrics($idSite, $period, $date, $test['variants'][0]['segment']);
            $b = Stats::variantMetrics($idSite, $period, $date, $test['variants'][$vIndex]['segment']);
            $mc = Stats::bayesMonteCarlo($a['orders'], $a['visits'], $b['orders'], $b['visits']);
            $result = array_merge(['ok' => true], $mc);
        }

        Common::sendHeader('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        return;
    }
}
