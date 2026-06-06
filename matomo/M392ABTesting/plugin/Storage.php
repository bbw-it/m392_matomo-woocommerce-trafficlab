<?php
/**
 * Persistenz der A/B-Test-Definitionen in einer Matomo-Option (JSON).
 * Ein Test = Name + Hypothese + Beschreibung + N Varianten (je Label + Segment).
 * Ist noch nichts gespeichert, wird der Standard-Test „ShopVariante" angelegt
 * (Original /shop/ vs. Shop-Variante /shop-variante/ über die Custom Dimension).
 */
namespace Piwik\Plugins\M392ABTesting;

use Piwik\Option;

class Storage
{
    const OPTION = 'M392ABTesting_tests';

    public static function getTests()
    {
        Option::clearCachedOption(self::OPTION);
        $raw = Option::get(self::OPTION);
        $tests = $raw ? json_decode($raw, true) : null;
        if (!is_array($tests) || count($tests) === 0) {
            $tests = [self::defaultTest()];
            self::saveTests($tests);
        }
        return array_values($tests);
    }

    public static function getTest($id)
    {
        foreach (self::getTests() as $t) {
            if (($t['id'] ?? '') === $id) {
                return $t;
            }
        }
        return null;
    }

    public static function saveTests(array $tests)
    {
        Option::set(self::OPTION, json_encode(array_values($tests)), $autoload = 0);
    }

    public static function addTest(array $test)
    {
        $tests = self::getTests();
        $tests[] = $test;
        self::saveTests($tests);
    }

    public static function deleteTest($id)
    {
        $tests = array_filter(self::getTests(), function ($t) use ($id) {
            return ($t['id'] ?? '') !== $id;
        });
        self::saveTests($tests);
    }

    /** Eindeutige, URL-/dateisystem-taugliche ID aus einem Namen ableiten. */
    public static function slug($name)
    {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $s = trim($s, '-');
        return $s !== '' ? $s : ('test-' . substr(md5($name), 0, 6));
    }

    public static function defaultTest()
    {
        return [
            'id'          => 'shopvariante',
            'name'        => 'ShopVariante',
            'hypothesis'  => 'Wenn die Shop-Seite moderner gestaltet ist (Shop-Variante mit Seiten-Filter), '
                           . 'dann steigt die Conversion-Rate, weil Produkte übersichtlicher gefunden werden.',
            'description' => 'Vergleich der Original-Shopseite (/shop/) mit der neuen Shop-Variante '
                           . '(/shop-variante/). Datenbasis: Custom Dimension „AB-Variante".',
            'created'     => 0,  // 0 = „seit Beginn der Daten"
            'variants'    => [
                ['label' => 'Original',      'segment' => 'dimension1==Original'],
                ['label' => 'Shop-Variante', 'segment' => 'dimension1==Shop-Variante'],
            ],
        ];
    }
}
