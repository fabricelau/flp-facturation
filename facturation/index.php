<?php
/* Fabrice Lau Production — Facturation : installation, connexion, compte, appli */
declare(strict_types=1);
define('FLP', 1);
require __DIR__ . '/lib.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (PHP_VERSION_ID < 70400) { echo 'Cet outil demande PHP 7.4 ou plus récent. Change la version de PHP dans ton espace hébergeur.'; exit; }

$msg = ''; $err = '';

/* ---------- 1. installation (première visite) ---------- */
if (!installed()) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $pw = (string)($_POST['pw'] ?? ''); $pw2 = (string)($_POST['pw2'] ?? ''); $email = trim((string)($_POST['email'] ?? ''));
        if (strlen($pw) < 10) $err = 'Choisis un mot de passe d\'au moins 10 caractères.';
        elseif ($pw !== $pw2) $err = 'Les deux mots de passe ne sont pas identiques.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Indique une adresse e-mail valide.';
        else {
            try {
                ensure_data_dir();
                if (!is_writable(DATA_DIR)) throw new RuntimeException('Le dossier « data » n\'est pas accessible en écriture. Donne-lui les droits 755 dans ton gestionnaire de fichiers.');
                cfg_save(['password' => password_hash($pw, PASSWORD_DEFAULT), 'from_email' => $email, 'from_name' => 'Fabrice Lau Production', 'notify_email' => $email, 'reply_to' => $email,
                    'copy_me' => true, 'smtp_host' => '', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => '', 'smtp_pass' => '', 'cron_key' => bin2hex(random_bytes(12)), 'base_url' => '']);
                db_tx(function (array &$d) use ($email) { if (empty($d['config']['entreprise'])) $d['config']['entreprise'] = seed_entreprise($email); });
                login_attempt($pw);
                header('Location: ' . base_url() . '?page=compte&bienvenue=1'); exit;
            } catch (Throwable $e) { $err = $e->getMessage(); }
        }
    }
    page('Installation', '<h1>Installer la facturation</h1>
      <p class="muted">Première visite : choisis le mot de passe qui protégera ton outil. Tes informations (SIRET, adresse, IBAN) sont déjà pré-remplies, tu pourras les vérifier dans Réglages.</p>'
      . ($err ? '<p class="err">' . h($err) . '</p>' : '') . '
      <form method="post">
        <label>Ton adresse e-mail<input type="email" name="email" required value="' . h($_POST['email'] ?? 'fabrice.ltl@gmail.com') . '"></label>
        <label>Mot de passe (10 caractères minimum)<input type="password" name="pw" required minlength="10" autocomplete="new-password"></label>
        <label>Confirme le mot de passe<input type="password" name="pw2" required minlength="10" autocomplete="new-password"></label>
        <button>Installer</button>
      </form>');
}

/* ---------- 2. déconnexion / connexion ---------- */
if (isset($_GET['logout'])) {
    start_session();
    if (hash_equals(csrf(), (string)($_GET['t'] ?? ''))) { $_SESSION = []; session_destroy(); }
    header('Location: ' . base_url()); exit;
}
if (!logged_in()) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $wait = login_blocked();
        if ($wait) $err = 'Trop d\'essais. Réessaie dans ' . ceil($wait / 60) . ' minutes.';
        elseif (login_attempt((string)($_POST['pw'] ?? ''))) { header('Location: ' . base_url()); exit; }
        else $err = 'Mot de passe incorrect.';
    }
    page('Connexion', '<h1>Facturation</h1><p class="muted">Fabrice Lau Production</p>' . ($err ? '<p class="err">' . h($err) . '</p>' : '') . '
      <form method="post"><label>Mot de passe<input type="password" name="pw" required autofocus autocomplete="current-password"></label><button>Se connecter</button></form>');
}

/* ---------- 3. compte et e-mails ---------- */
if (($_GET['page'] ?? '') === 'compte') {
    $c = cfg();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals(csrf(), (string)($_POST['t'] ?? ''))) $err = 'Session invalide, recharge la page.';
        else try {
            $act = $_POST['act'] ?? '';
            if ($act === 'mail') {
                foreach (['from_email', 'notify_email'] as $k) if (!filter_var(trim((string)$_POST[$k]), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse e-mail invalide.');
                $c['from_email'] = trim((string)$_POST['from_email']); $c['from_name'] = trim((string)$_POST['from_name']) ?: 'Fabrice Lau Production';
                $c['notify_email'] = trim((string)$_POST['notify_email']); $c['copy_me'] = !empty($_POST['copy_me']);
                $c['smtp_host'] = trim((string)$_POST['smtp_host']); $c['smtp_port'] = (int)$_POST['smtp_port'] ?: 465;
                $c['smtp_secure'] = in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl';
                $c['smtp_user'] = trim((string)$_POST['smtp_user']);
                if (($_POST['smtp_pass'] ?? '') !== '') $c['smtp_pass'] = (string)$_POST['smtp_pass'];
                cfg_save($c); $msg = 'Réglages des e-mails enregistrés.';
            } elseif ($act === 'test') {
                send_mail(['to' => [$c['notify_email'] ?? $c['from_email']], 'subject' => 'Test – Facturation Fabrice Lau Production', 'body' => "Bonjour,\n\nCet e-mail confirme que l'envoi depuis ton outil de facturation fonctionne.\n\nFabrice Lau Production"]);
                $msg = 'E-mail de test envoyé à ' . ($c['notify_email'] ?? $c['from_email']) . '. Vérifie ta boîte (et les indésirables).';
            } elseif ($act === 'pw') {
                if (!password_verify((string)$_POST['old'], $c['password'])) throw new RuntimeException('Mot de passe actuel incorrect.');
                if (strlen((string)$_POST['new']) < 10) throw new RuntimeException('Le nouveau mot de passe doit faire au moins 10 caractères.');
                $c['password'] = password_hash((string)$_POST['new'], PASSWORD_DEFAULT); cfg_save($c);
                start_session(); $_SESSION['pw'] = substr($c['password'], -16);
                $msg = 'Mot de passe changé. Tes autres appareils devront se reconnecter.';
            }
        } catch (Throwable $e) { $err = $e->getMessage(); }
        $c = cfg_reload();
    }
    $t = csrf(); $sel = fn($v) => ($c['smtp_secure'] ?? 'ssl') === $v ? ' selected' : '';
    $cron = base_url() . 'cron.php?cle=' . ($c['cron_key'] ?? '');
    page('Compte et e-mails', '<p><a href="./">← Retour à la facturation</a></p><h1>Compte et e-mails</h1>'
      . (isset($_GET['bienvenue']) ? '<p class="ok">C\'est installé. Règle maintenant l\'envoi des e-mails, fais un test, puis retourne à la facturation.</p>' : '')
      . ($msg ? '<p class="ok">' . h($msg) . '</p>' : '') . ($err ? '<p class="err">' . h($err) . '</p>' : '') . '
      <form method="post"><input type="hidden" name="t" value="' . h($t) . '"><input type="hidden" name="act" value="mail">
        <h2>Envoi des e-mails</h2>
        <p class="muted">Pour que tes devis et factures arrivent bien chez tes clients (et pas dans leurs indésirables), passe par un serveur SMTP. Avec Gmail : serveur <b>smtp.gmail.com</b>, port <b>465</b>, SSL, identifiant = ton adresse Gmail, mot de passe = un <b>mot de passe d\'application</b> (Compte Google → Sécurité → Validation en deux étapes → Mots de passe des applications). Avec la boîte mail de ton hébergeur, utilise les réglages SMTP qu\'il indique. Laisse le serveur vide pour utiliser l\'envoi simple de l\'hébergeur.</p>
        <label>Nom de l\'expéditeur<input name="from_name" value="' . h($c['from_name'] ?? '') . '"></label>
        <label>Adresse d\'expédition<input type="email" name="from_email" required value="' . h($c['from_email'] ?? '') . '"></label>
        <label>Adresse qui reçoit les copies et les alertes (devis accepté…)<input type="email" name="notify_email" required value="' . h($c['notify_email'] ?? '') . '"></label>
        <label class="chk"><input type="checkbox" name="copy_me" value="1"' . (!empty($c['copy_me']) ? ' checked' : '') . '> Recevoir une copie de chaque e-mail envoyé à un client</label>
        <div class="grid"><label>Serveur SMTP<input name="smtp_host" placeholder="smtp.gmail.com" value="' . h($c['smtp_host'] ?? '') . '"></label>
        <label>Port<input type="number" name="smtp_port" value="' . h($c['smtp_port'] ?? 465) . '"></label>
        <label>Sécurité<select name="smtp_secure"><option value="ssl"' . $sel('ssl') . '>SSL (port 465)</option><option value="tls"' . $sel('tls') . '>STARTTLS (port 587)</option><option value="none"' . $sel('none') . '>Aucune</option></select></label></div>
        <label>Identifiant SMTP<input name="smtp_user" autocomplete="off" value="' . h($c['smtp_user'] ?? '') . '"></label>
        <label>Mot de passe SMTP<input type="password" name="smtp_pass" autocomplete="new-password" placeholder="' . (!empty($c['smtp_pass']) ? '•••••••• (inchangé si vide)' : '') . '"></label>
        <button>Enregistrer</button>
      </form>
      <form method="post"><input type="hidden" name="t" value="' . h($t) . '"><input type="hidden" name="act" value="test"><button class="sec">Envoyer un e-mail de test</button></form>
      <h2>Relance automatique des devis</h2>
      <p class="muted">Les devis restés sans réponse sont relancés une fois, automatiquement, à la première ouverture de l\'outil chaque matin (réglage dans Réglages → Modèles d\'e-mails). Pour qu\'elle parte même les jours où tu n\'ouvres pas l\'outil, ajoute une tâche planifiée (« cron ») chez ton hébergeur, tous les jours à 8 h, qui appelle cette adresse :</p>
      <p class="code">' . h($cron) . '</p>
      <form method="post"><input type="hidden" name="t" value="' . h($t) . '"><input type="hidden" name="act" value="pw">
        <h2>Mot de passe</h2>
        <label>Mot de passe actuel<input type="password" name="old" required autocomplete="current-password"></label>
        <label>Nouveau mot de passe (10 caractères minimum)<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
        <button>Changer le mot de passe</button>
      </form>
      <p class="muted">Sauvegardes : une copie de tes données est gardée chaque jour pendant 60 jours dans le dossier <b>data/sauvegardes</b> sur ton hébergement. Pense aussi à télécharger régulièrement la sauvegarde depuis Réglages.</p>
      <p><a href="?logout=1&amp;t=' . h($t) . '">Se déconnecter</a></p>');
}

/* ---------- 4. l'appli ---------- */
$gpf = [
    'rest' => 'api.php/', 'nonce' => csrf(), 'admin' => '?page=compte', 'logout' => '?logout=1&t=' . csrf(),
    'quoteBase' => base_url() . 'devis.php?t=', 'payBase' => '', 'sumup' => false, 'assets' => 'assets/', 'version' => APP_VERSION,
];
session_write_close();
$html = file_get_contents(__DIR__ . '/app.html');
$head = '<link rel="manifest" href="manifest.php"><link rel="icon" href="assets/icone.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="assets/icone-180.png">'
    . '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Facturation"><meta name="theme-color" content="#16171B"><meta name="robots" content="noindex,nofollow">';
$inject = '<script>window.GPF=' . json_encode($gpf, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script><script src="assets/shim.js?v=' . APP_VERSION . '"></script>';
$html = str_replace('<!--GPF-HEAD-->', $head, $html);
$html = str_replace('<!--GPF-->', $inject, $html);
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
echo $html;
exit;

/* ---------- gabarit des pages simples ---------- */
function cfg_reload(): array { return cfg(true); }
function page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . h($title) . ' – Facturation</title>
<link rel="icon" href="assets/icone.svg" type="image/svg+xml"><meta name="theme-color" content="#16171B">
<style>
:root{--bg:#F4F3F1;--card:#fff;--ink:#1B1C20;--muted:#63625D;--line:#DDDAD4;--acc:#B8302F;--ok:#1E7A3B;--okb:#E1F3E6;--er:#B42318;--erb:#FBE4E1;color-scheme:light}
@media (prefers-color-scheme:dark){:root{--bg:#0E0F12;--card:#1A1B20;--ink:#ECEAE6;--muted:#A3A19A;--line:#2D2E35;--acc:#FF6B66;--ok:#62C47F;--okb:#173622;--er:#F07B6E;--erb:#3D1B18;color-scheme:dark}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;padding:32px 16px}
main{max-width:560px;margin:0 auto;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:28px 24px;border-top:4px solid var(--acc)}
h1{font-size:24px;margin:0 0 6px}h2{font-size:17px;margin:26px 0 8px}.muted{color:var(--muted);font-size:14px}a{color:var(--acc)}
form{display:flex;flex-direction:column;gap:12px;margin:10px 0}label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:var(--muted)}
label.chk{flex-direction:row;align-items:center;gap:8px;color:var(--ink);font-weight:500;font-size:14px}
input,select{font:inherit;color:inherit;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:9px 11px;width:100%}input[type=checkbox]{width:18px;height:18px}
input:focus,select:focus{outline:2px solid var(--acc);outline-offset:1px}
button{font:inherit;font-weight:600;background:var(--acc);color:#fff;border:0;border-radius:8px;padding:10px 16px;cursor:pointer;align-self:flex-start}button.sec{background:transparent;color:var(--ink);border:1px solid var(--line)}
.grid{display:grid;grid-template-columns:2fr 1fr 1.4fr;gap:10px}@media (max-width:520px){.grid{grid-template-columns:1fr}}
.ok{background:var(--okb);color:var(--ok);padding:10px 12px;border-radius:8px}.err{background:var(--erb);color:var(--er);padding:10px 12px;border-radius:8px}
.code{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 10px;word-break:break-all}
</style></head><body><main>' . $body . '</main></body></html>';
    exit;
}
