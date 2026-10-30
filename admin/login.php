<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

start_session();
$error = '';

if (is_logged_in()) {
    header('Location: ./');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (rate_limited('login', 8, 900)) {
        $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } elseif (($user = check_credentials((string)($_POST['user'] ?? ''), (string)($_POST['password'] ?? ''))) !== null) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['user'] = $user;
        $_SESSION['exp'] = time() + 8 * 3600;
        header('Location: ./');
        exit;
    } else {
        usleep(400000);
        $error = 'Identifiant ou mot de passe incorrect.';
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Connexion · Admin Somnéa</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
  <form class="login-card" method="post">
    <div class="brand"><span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2-5 4 10 2-5h6"/></svg></span> Somnéa · Suivi</div>
    <h1>Connexion</h1>
    <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
    <label for="user">Identifiant</label>
    <input type="text" id="user" name="user" required autofocus autocomplete="username" autocapitalize="none" value="<?= h((string)($_POST['user'] ?? '')) ?>">
    <label for="pw">Mot de passe</label>
    <input type="password" id="pw" name="password" required autocomplete="current-password">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button type="submit" class="btn btn-primary">Se connecter</button>
  </form>
</body>
</html>
