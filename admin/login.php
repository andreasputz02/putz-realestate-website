<?php
session_start();

define('ADMIN_PASSWORD_HASH', '3fbc8239f973f692f677d1ed5249eb6b8f34fd42a8ac88bc74b2e9f629289b25');
define('ADMIN_SALT', 'putz-admin-salt-2026');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST['password'] ?? '';
    $hash = hash('sha256', ADMIN_SALT . $input);
    if (hash_equals(ADMIN_PASSWORD_HASH, $hash)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: tippgeber.php');
        exit;
    }
    $error = 'Falsches Passwort.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin Login — PUTZ Real Estate</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Inter Tight", Arial, sans-serif;
    background: #0b0b0c; color: #fff; min-height: 100vh;
    display: flex; align-items: center; justify-content: center; padding: 24px;
  }
  .box { background: #171512; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 40px; width: 100%; max-width: 360px; }
  h1 { font-size: 20px; margin-bottom: 24px; font-weight: 600; }
  input {
    width: 100%; padding: 14px 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15);
    background: #0b0b0c; color: #fff; font-size: 15px; margin-bottom: 16px;
  }
  input:focus { outline: none; border-color: #cfa858; }
  button {
    width: 100%; padding: 14px; border-radius: 100px; border: none;
    background: linear-gradient(180deg, #e6cd94, #cfa858); color: #171512;
    font-weight: 700; font-size: 14.5px; cursor: pointer;
  }
  .error { color: #e08a8a; font-size: 13.5px; margin-bottom: 16px; }
</style>
</head>
<body>
  <form class="box" method="post">
    <h1>Tippgeber-Übersicht</h1>
    <?php if ($error): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <input type="password" name="password" placeholder="Passwort" required autofocus>
    <button type="submit">Anmelden</button>
  </form>
</body>
</html>
