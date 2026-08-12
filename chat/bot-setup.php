<?php
/**
 * Jednokratno postavljanje bota.
 *
 *     php bot-setup.php sk-ant-...            # napravi bota "robi" (Robi)
 *     php bot-setup.php sk-ant-... zoe Zoe    # vlastito ime
 *     php bot-setup.php --off                 # ugasi bota (račun ostaje)
 *
 * Upisuje API ključ u data/bot.json (mapa je izvan gita, datoteka ide na 0600)
 * i stvara botov korisnički račun. Bot nema lozinku kojom se može prijaviti i
 * nikad nije administrator — postoji samo da bi pisao poruke.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bot-setup.php se pokreće samo iz komandne linije.\n");
}

require __DIR__ . '/lib.php';

$arg = (string)($argv[1] ?? '');
if ($arg === '') {
    exit("Uporaba: php bot-setup.php <api-kljuc> [korisnicko-ime] [ime]\n"
       . "         php bot-setup.php --off\n");
}

$pdo = db();

if ($arg === '--off') {
    // Račun i poruke ostaju, ali ga deaktiviramo — ugašen bot ne smije stajati
    // u popisu korisnika kao netko kome se piše, a nikad ne odgovori.
    $was = bot_username();
    if ($was !== '') {
        $pdo->prepare('UPDATE users SET active = 0 WHERE username = ?')->execute([$was]);
    }
    if (is_file(CHAT_BOT_FILE)) {
        @unlink(CHAT_BOT_FILE);
        echo "Bot ugašen: ključ obrisan, račun \"$was\" deaktiviran. Poruke su ostale.\n";
    } else {
        echo "Bot ionako nije bio uključen.\n";
    }
    exit;
}

$key  = $arg;
$user = strtolower(trim((string)($argv[2] ?? 'robi')));
$name = trim((string)($argv[3] ?? ucfirst($user)));

if (!str_starts_with($key, 'sk-ant-')) {
    exit("To ne izgleda kao Anthropic API ključ (očekujem da počinje sa 'sk-ant-').\n");
}
if (!preg_match(CHAT_USERNAME_RE, $user)) {
    exit("Korisničko ime smije imati samo mala slova, brojke, točku, crticu i podvlaku (2–30 znakova).\n");
}

// ---------- botov račun ----------
// Lozinka je slučajan niz koji nigdje ne zapisujemo: bot se ne prijavljuje,
// njegove poruke upisuje bot.php izravno u bazu.
$row = user_row($user);
if ($row === null) {
    $pdo->prepare('INSERT INTO users (username, name, hash, role, active, must_change, created_at)
                   VALUES (?, ?, ?, "member", 1, 0, ?)')
        ->execute([$user, $name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), time()]);
    echo "Napravljen račun bota: $user ($name)\n";
} else {
    $pdo->prepare('UPDATE users SET name = ?, active = 1, role = "member" WHERE username = ?')
        ->execute([$name, $user]);
    echo "Račun bota već postojao, osvježen: $user ($name)\n";
}

// ---------- postavke ----------
$cfg = [
    'user'             => $user,
    'api_key'          => $key,
    'model'            => 'claude-haiku-4-5',
    'replies_per_hour' => 60,
];
file_put_contents(CHAT_BOT_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@chmod(CHAT_BOT_FILE, 0600);

echo "Postavke upisane u " . CHAT_BOT_FILE . " (samo za tebe, 0600)\n";
echo "  model            : {$cfg['model']}\n";
echo "  limit            : {$cfg['replies_per_hour']} odgovora na sat\n";
echo "\nBot odgovara u DM-u uvijek, a u grupi i kanalu kad ga se spomene s @$user.\n";
echo "Dodaj ga u razgovore: php demo-seed.php (demo) ili ⓘ → Add member (ručno).\n";
