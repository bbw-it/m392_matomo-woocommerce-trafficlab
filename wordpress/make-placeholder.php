<?php
/**
 * make-placeholder.php
 *
 * Erzeugt ein sauberes 800x800 PNG-Platzhalterbild fuer ein Produkt.
 * Wird vom wp-init.sh als Offline-Fallback genutzt, wenn der Download
 * eines echten Fotos (LoremFlickr) fehlschlaegt.
 *
 * Benoetigt nur die PHP-GD-Extension (im wordpress:cli Image vorhanden).
 * Zeichnet den Text mit GD's eingebauter Bitmap-Schrift (imagestring),
 * hochskaliert -> KEINE TTF-Schrift noetig, funktioniert immer.
 *
 * Aufruf:
 *   php make-placeholder.php "<Produktname>" "<Kategorie>" "<Ausgabe.png>"
 */

if ($argc < 4) {
    fwrite(STDERR, "Usage: php make-placeholder.php <name> <category> <output.png>\n");
    exit(1);
}

$name     = $argv[1];
$category = $argv[2];
$out      = $argv[3];

$W = 800;
$H = 800;

// Kategorie-spezifische Farbpalette (Hintergrund + dunklerer Akzent unten).
$palette = array(
    'Bekleidung'  => array(0x2E, 0x6F, 0x95), // Blau
    'Accessoires' => array(0x8A, 0x55, 0x9A), // Violett
    'Haushalt'    => array(0x2A, 0x9D, 0x8F), // Tuerkis
    'Elektronik'  => array(0xE7, 0x6F, 0x51), // Orange
);
$rgb = isset($palette[$category]) ? $palette[$category] : array(0x4A, 0x4A, 0x4A);

$img = imagecreatetruecolor($W, $H);

// Vertikaler Verlauf: oben Grundfarbe -> unten dunklere Variante (intentional, kein Bug-Look).
for ($y = 0; $y < $H; $y++) {
    $t = $y / $H;            // 0..1
    $f = 1.0 - 0.45 * $t;    // unten ~55% Helligkeit
    $r = (int) round($rgb[0] * $f);
    $g = (int) round($rgb[1] * $f);
    $b = (int) round($rgb[2] * $f);
    $col = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, 0, $y, $W, $y, $col);
}

$white = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);
$light = imagecolorallocate($img, 0xEA, 0xEA, 0xEA);

// Heller Rahmen fuer einen "Karten"-Look.
imagesetthickness($img, 6);
imagerectangle($img, 24, 24, $W - 24, $H - 24, imagecolorallocatealpha($img, 0xFF, 0xFF, 0xFF, 100));

/**
 * Zeichnet eine Textzeile zentriert mit GD-Bitmap-Font, per Resampling
 * vergroessert, damit sie gut lesbar ist.
 */
function draw_scaled_line($dst, $text, $cy, $scale, $color_rgb) {
    $font = 5; // groesster eingebauter GD-Font
    $cw = imagefontwidth($font);
    $ch = imagefontheight($font);
    $tw = $cw * strlen($text);
    $th = $ch;
    if ($tw <= 0) { return; }

    $tmp = imagecreatetruecolor($tw, $th);
    // Transparenter Arbeitslayer.
    imagealphablending($tmp, false);
    imagesavealpha($tmp, true);
    $transp = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefilledrectangle($tmp, 0, 0, $tw, $th, $transp);
    $tc = imagecolorallocate($tmp, $color_rgb[0], $color_rgb[1], $color_rgb[2]);
    imagestring($tmp, $font, 0, 0, $text, $tc);

    $dw = (int) round($tw * $scale);
    $dh = (int) round($th * $scale);
    $dstW = imagesx($dst);
    $dx = (int) round(($dstW - $dw) / 2);
    $dy = (int) round($cy - $dh / 2);

    imagealphablending($dst, true);
    imagecopyresampled($dst, $tmp, $dx, $dy, 0, 0, $dw, $dh, $tw, $th);
    imagedestroy($tmp);
}

/**
 * Bricht einen langen Produktnamen in mehrere Zeilen um (max. Zeichen/Zeile).
 * Bricht auch an Bindestrichen und notfalls hart innerhalb langer Woerter,
 * damit nichts ueber den Rand laeuft.
 */
function wrap_words($text, $max) {
    // Bindestriche als Trennstellen behandeln (Wort bleibt mit '-' sichtbar).
    $tokens = preg_split('/\s+/', trim($text));
    $words = array();
    foreach ($tokens as $tok) {
        if ($tok === '') { continue; }
        // An Bindestrichen splitten, '-' am vorherigen Teil belassen.
        $parts = preg_split('/(?<=-)/', $tok);
        foreach ($parts as $p) {
            if ($p === '') { continue; }
            // Notfalls hart umbrechen, falls ein Teil laenger als $max ist.
            while (strlen($p) > $max) {
                $words[] = substr($p, 0, $max);
                $p = substr($p, $max);
            }
            $words[] = $p;
        }
    }

    $lines = array();
    $cur = '';
    foreach ($words as $w) {
        $sep = ($cur !== '' && substr($cur, -1) !== '-') ? ' ' : '';
        $cand = ($cur === '') ? $w : $cur . $sep . $w;
        if (strlen($cand) <= $max) {
            $cur = $cand;
        } else {
            if ($cur !== '') { $lines[] = $cur; }
            $cur = $w;
        }
    }
    if ($cur !== '') { $lines[] = $cur; }
    return $lines;
}

// Umlaute -> ASCII, weil der eingebaute GD-Font kein UTF-8 kann.
$ascii = strtr($name, array(
    'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
    'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
));
$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $ascii);
if ($ascii === false) { $ascii = preg_replace('/[^\x20-\x7E]/', '', $name); }

$lines = wrap_words($ascii, 14);

// Produktname (gross, weiss) zentriert.
// Skalierung automatisch an die breiteste Zeile anpassen, damit der Text
// mit ~80% Bildbreite immer in den Rahmen passt.
$font = 5;
$cw = imagefontwidth($font);
$maxLen = 1;
foreach ($lines as $ln) { $maxLen = max($maxLen, strlen($ln)); }
$maxScale = ($W * 0.80) / ($cw * $maxLen);
$scale = min(5.5, $maxScale);
$lineGap = (int) round(imagefontheight($font) * $scale * 1.35);
$startY  = ($H / 2) - (count($lines) - 1) * $lineGap / 2;
foreach ($lines as $li => $ln) {
    draw_scaled_line($img, $ln, $startY + $li * $lineGap, $scale, array(255, 255, 255));
}

// Kategorie-Label oben.
$catAscii = @iconv('UTF-8', 'ASCII//TRANSLIT', $category);
if ($catAscii === false) { $catAscii = $category; }
draw_scaled_line($img, strtoupper($catAscii), 120, 3.0, array(234, 234, 234));

// "DEMO" dezent unten.
draw_scaled_line($img, 'M392 DEMO-SHOP', $H - 110, 2.0, array(234, 234, 234));

imagepng($img, $out, 6);
imagedestroy($img);

if (!file_exists($out) || filesize($out) < 1000) {
    fwrite(STDERR, "Placeholder generation failed\n");
    exit(1);
}
echo $out, "\n";
