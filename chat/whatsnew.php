<?php
/**
 * "Što je novo" — popis objavljenih promjena, vidljiv svim korisnicima.
 * Sadržaj se čita iz CHANGELOG.md (router blokira .md izvana, ovo je jedini put do njega).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = require_auth_page();
$md = @file_get_contents(__DIR__ . '/CHANGELOG.md') ?: '';

/** Vrlo mali Markdown → HTML (naslovi, natuknice, **podebljano**, `kod`). */
function md_line(string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/`(.+?)`/', '<code>$1</code>', $s);
    return $s;
}

$html = '';
$inList = false;
foreach (explode("\n", $md) as $line) {
    $line = rtrim($line);
    if (preg_match('/^## (.+)/', $line, $m)) {
        if ($inList) { $html .= "</ul>\n"; $inList = false; }
        $html .= '<h2 class="wn-date">' . md_line($m[1]) . "</h2>\n";
    } elseif (preg_match('/^# (.+)/', $line, $m)) {
        continue; // glavni naslov dolazi iz predloška
    } elseif (preg_match('/^- (.+)/', $line, $m)) {
        if (!$inList) { $html .= "<ul class=\"wn-list\">\n"; $inList = true; }
        $html .= '<li>' . md_line($m[1]) . "</li>\n";
    } elseif ($line === '') {
        if ($inList) { $html .= "</ul>\n"; $inList = false; }
    } else {
        if ($inList) { $html .= "</ul>\n"; $inList = false; }
        $html .= '<p class="wn-p">' . md_line($line) . "</p>\n";
    }
}
if ($inList) $html .= "</ul>\n";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Our Chat — What's new</title>
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= asset("assets/style.css") ?>">
</head>
<body class="admin-page">
<div class="admin-wrap">
    <header class="admin-header">
        <a href="index.php" class="admin-back">‹ Back to chat</a>
        <h1>🆕 What's new</h1>
    </header>
    <section class="admin-card">
        <?= $html ?>
    </section>
</div>
</body>
</html>
