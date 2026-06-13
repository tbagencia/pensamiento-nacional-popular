<?php
/**
 * Open Graph image per document (/documento/{id}/og.png).
 * Renders a 1200x630 card with the site identity — gradient, year
 * pill, wrapped title, author and the flag band — so every shared
 * link carries its own preview. Falls back to the static share image
 * when GD/FreeType or the bundled fonts are unavailable.
 */

require_once __DIR__ . '/db.php';

const OG_W = 1200;
const OG_H = 630;
const OG_MARGIN = 80;

$fontDisplay = __DIR__ . '/../assets/fonts/Bitter-Bold.ttf';
$fontUi = __DIR__ . '/../assets/fonts/Archivo-SemiBold.ttf';

if (!function_exists('imagettftext') || !is_file($fontDisplay) || !is_file($fontUi)) {
    header('Location: /assets/img/share-default.png', true, 302);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT id, title, " . author_label_sql('resources') . " AS author, year, type
     FROM resources WHERE id = ? AND status = 'approved'"
);
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit;
}

// The image only changes when the document does: cache aggressively.
$etag = '"' . md5(implode('|', [$doc['title'], $doc['author'], $doc['year'], $doc['type']])) . '"';
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

/** Rendered width of a single line, in pixels. */
function og_text_width(string $text, string $font, float $size): float
{
    $box = imagettfbbox($size, 0, $font, $text);
    return $box[2] - $box[0];
}

/** Greedy word wrap against a pixel width. */
function og_wrap(string $text, string $font, float $size, int $maxWidth): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        $probe = $line === '' ? $word : "$line $word";
        if (og_text_width($probe, $font, $size) <= $maxWidth) {
            $line = $probe;
        } else {
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = $word;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

$img = imagecreatetruecolor(OG_W, OG_H);

// Vertical gradient through the brand blues (--brand-dark, --brand-deep,
// towards --brand), mirroring the hero header.
$stops = [[0x16, 0x26, 0x3f], [0x1d, 0x35, 0x57], [0x1d, 0x4d, 0x7a]];
for ($y = 0; $y < OG_H; $y++) {
    $t = $y / OG_H;
    [$a, $b, $tt] = $t < 0.65
        ? [$stops[0], $stops[1], $t / 0.65]
        : [$stops[1], $stops[2], ($t - 0.65) / 0.35];
    imageline($img, 0, $y, OG_W, $y, imagecolorallocate(
        $img,
        (int) round($a[0] + ($b[0] - $a[0]) * $tt),
        (int) round($a[1] + ($b[1] - $a[1]) * $tt),
        (int) round($a[2] + ($b[2] - $a[2]) * $tt)
    ));
}

$gold = imagecolorallocate($img, 0xf6, 0xb4, 0x0e);
$sky = imagecolorallocate($img, 0x74, 0xac, 0xdf);
$white = imagecolorallocate($img, 0xff, 0xff, 0xff);
$deep = imagecolorallocate($img, 0x1d, 0x35, 0x57);
$dimWhite = imagecolorallocatealpha($img, 0xff, 0xff, 0xff, 55);

// Flag band along the bottom edge, gold rule above it — the same
// celeste-white-celeste signature the site header carries.
imagefilledrectangle($img, 0, OG_H - 21, OG_W, OG_H - 19, $gold);
imagefilledrectangle($img, 0, OG_H - 18, OG_W, OG_H - 13, $sky);
imagefilledrectangle($img, 0, OG_H - 12, OG_W, OG_H - 7, $white);
imagefilledrectangle($img, 0, OG_H - 6, OG_W, OG_H, $sky);

// Year pill (gold, rounded) plus the document type beside it.
$yearText = (string) (int) $doc['year'];
$pillFont = 34.0;
$pillTextW = og_text_width($yearText, $fontDisplay, $pillFont);
$pillH = 66;
$pillW = (int) ($pillTextW + $pillH);
$pillX = OG_MARGIN;
$pillY = 72;
imagefilledrectangle($img, $pillX + $pillH / 2, $pillY, (int) ($pillX + $pillW - $pillH / 2), $pillY + $pillH, $gold);
imagefilledellipse($img, (int) ($pillX + $pillH / 2), $pillY + $pillH / 2 + 1, $pillH, $pillH, $gold);
imagefilledellipse($img, (int) ($pillX + $pillW - $pillH / 2), $pillY + $pillH / 2 + 1, $pillH, $pillH, $gold);
imagettftext($img, $pillFont, 0, (int) ($pillX + ($pillW - $pillTextW) / 2), $pillY + 46, $deep, $fontDisplay, $yearText);

imagettftext($img, 24, 0, $pillX + $pillW + 28, $pillY + 44, $sky, $fontUi, mb_strtoupper($doc['type']));

// Title: biggest size whose wrap fits in three lines; the last line is
// truncated with an ellipsis if it still overflows.
$maxWidth = OG_W - 2 * OG_MARGIN;
$lines = [];
$titleSize = 62.0;
foreach ([62.0, 52.0, 44.0] as $size) {
    $lines = og_wrap($doc['title'], $fontDisplay, $size, $maxWidth);
    $titleSize = $size;
    if (count($lines) <= 3) {
        break;
    }
}
if (count($lines) > 3) {
    $lines = array_slice($lines, 0, 3);
    $lines[2] .= '…';
}

$y = 270;
$lineHeight = (int) ($titleSize * 1.35);
foreach ($lines as $line) {
    imagettftext($img, $titleSize, 0, OG_MARGIN, $y, $white, $fontDisplay, $line);
    $y += $lineHeight;
}

imagettftext($img, 30, 0, OG_MARGIN, $y + 26, $gold, $fontUi, $doc['author']);

imagettftext($img, 21, 0, OG_MARGIN, OG_H - 48, $dimWhite, $fontUi, 'pensamientonacionalypopular.com.ar');

header('Content-Type: image/png');
imagepng($img);
