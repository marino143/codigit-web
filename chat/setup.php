<?php
/**
 * Prvo pokretanje — kreiranje dva korisnička računa.
 * Nakon što su računi kreirani, ova stranica se trajno zaključava.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
chat_session_start();

if (chat_is_configured()) {
    http_response_code(403);
    echo 'Chat je već postavljen. Ako želiš resetirati korisnike, obriši datoteku chat/data/users.json na serveru.';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [];
    foreach ([1, 2] as $i) {
        $fields[$i] = [
            'name' => trim((string)($_POST["name$i"] ?? '')),
            'user' => strtolower(trim((string)($_POST["user$i"] ?? ''))),
            'pass' => (string)($_POST["pass$i"] ?? ''),
        ];
    }
    if ($fields[1]['name'] === '' || $fields[2]['name'] === ''
        || $fields[1]['user'] === '' || $fields[2]['user'] === '') {
        $error = 'Sva polja su obavezna.';
    } elseif (!preg_match('/^[a-z0-9_.-]{2,30}$/', $fields[1]['user'])
        || !preg_match('/^[a-z0-9_.-]{2,30}$/', $fields[2]['user'])) {
        $error = 'Korisničko ime smije sadržavati samo slova, brojke, točku, crticu i donju crtu (2–30 znakova).';
    } elseif ($fields[1]['user'] === $fields[2]['user']) {
        $error = 'Korisnička imena moraju biti različita.';
    } elseif (strlen($fields[1]['pass']) < 8 || strlen($fields[2]['pass']) < 8) {
        $error = 'Lozinka mora imati barem 8 znakova.';
    } else {
        $users = [];
        foreach ($fields as $f) {
            $users[$f['user']] = [
                'name' => $f['name'],
                'hash' => password_hash($f['pass'], PASSWORD_DEFAULT),
            ];
        }
        if (!is_dir(CHAT_DATA_DIR)) mkdir(CHAT_DATA_DIR, 0755, true);
        file_put_contents(CHAT_USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        db(); // kreiraj bazu odmah
        header('Location: login.php?setup=ok');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Naš chat — postavljanje</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">💬</div>
    <h1>Naš chat</h1>
    <p class="auth-sub">Prvo pokretanje — napravite svoja dva računa. Ova se stranica nakon toga zaključava.</p>
    <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
        <fieldset>
            <legend>Prva osoba</legend>
            <input name="name1" placeholder="Ime (npr. Marino)" required value="<?= htmlspecialchars((string)($_POST['name1'] ?? '')) ?>">
            <input name="user1" placeholder="Korisničko ime (npr. marino)" required value="<?= htmlspecialchars((string)($_POST['user1'] ?? '')) ?>">
            <input name="pass1" type="password" placeholder="Lozinka (min. 8 znakova)" required>
        </fieldset>
        <fieldset>
            <legend>Druga osoba</legend>
            <input name="name2" placeholder="Ime" required value="<?= htmlspecialchars((string)($_POST['name2'] ?? '')) ?>">
            <input name="user2" placeholder="Korisničko ime" required value="<?= htmlspecialchars((string)($_POST['user2'] ?? '')) ?>">
            <input name="pass2" type="password" placeholder="Lozinka (min. 8 znakova)" required>
        </fieldset>
        <button type="submit">Kreiraj račune</button>
    </form>
</div>
</body>
</html>
