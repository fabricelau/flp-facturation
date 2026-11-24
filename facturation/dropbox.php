<?php
/* Fabrice Lau Production — Facturation : sauvegarde automatique dans Dropbox.
   Connexion OAuth (PKCE, sans secret) à une appli Dropbox « dossier d'appli » créée par l'utilisateur.
   Jeton et état dans data/dropbox.json (jamais publié).
   Appelé directement (GET ?code=…) : retour de Dropbox après autorisation. */
declare(strict_types=1);
if (!defined('FLP')) {
    define('FLP', 1);
    require __DIR__ . '/lib.php';
    start_session();
    if (!installed() || !logged_in()) { header('Location: ./'); exit; }
    $back = function (string $msg, bool $ok) { header('Location: ./?dropbox=' . ($ok ? 'ok' : 'erreur') . '&msg=' . rawurlencode($msg) . '#parametres'); exit; };
    if (!empty($_GET['error'])) $back('Connexion à Dropbox annulée.', false);
    $code = (string)($_GET['code'] ?? ''); $state = (string)($_GET['state'] ?? '');
    $pk = $_SESSION['dbx_pkce'] ?? null; unset($_SESSION['dbx_pkce']);
    if (!$pk || $code === '' || !hash_equals((string)$pk['state'], $state)) $back('Le retour de Dropbox n’a pas pu être vérifié. Recommence la connexion.', false);
    $j = dbx_load();
    $r = dbx_post('https://api.dropboxapi.com/oauth2/token', http_build_query(['code' => $code, 'grant_type' => 'authorization_code', 'client_id' => $j['app_key'] ?? '',
        'code_verifier' => $pk['verifier'], 'redirect_uri' => dbx_redirect()]), ['Content-Type: application/x-www-form-urlencoded']);
    $t = json_decode($r['body'], true);
    if ($r['code'] !== 200 || empty($t['refresh_token'])) $back('Dropbox a refusé la connexion (' . ($t['error_description'] ?? $t['error'] ?? $r['code']) . '). Vérifie l’adresse de redirection dans ton appli Dropbox.', false);
    $j['refresh_token'] = $t['refresh_token']; $j['access_token'] = $t['access_token'] ?? ''; $j['expires'] = time() + (int)($t['expires_in'] ?? 0) - 60;
    $j['connecte'] = date('c'); unset($j['erreur']);
    $acc = dbx_api($j, 'users/get_current_account', null);
    if (is_array($acc)) $j['compte'] = trim(($acc['name']['display_name'] ?? '') . (isset($acc['email']) ? ' · ' . $acc['email'] : ''));
    dbx_save($j);
    $res = dbx_backup(true);
    $back($res['ok'] ? 'Dropbox est connecté : première sauvegarde envoyée.' : 'Dropbox est connecté, mais la première sauvegarde a échoué : ' . $res['msg'], $res['ok']);
}

function dbx_file(): string { return DATA_DIR . '/dropbox.json'; }
function dbx_load(): array { $f = dbx_file(); return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }
function dbx_save(array $j): void { ensure_data_dir(); atomic_write(dbx_file(), json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); @chmod(dbx_file(), 0600); }
function dbx_redirect(): string { return rtrim(base_url(), '/') . '/dropbox.php'; }
function dbx_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function dbx_post(string $url, $body, array $headers, int $timeout = 60): array {
    if (!function_exists('curl_init')) return ['code' => 0, 'body' => '', 'err' => 'curl absent'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $e = curl_error($ch); curl_close($ch);
    return ['code' => $c, 'body' => is_string($b) ? $b : '', 'err' => $e];
}

/** URL d'autorisation (PKCE) */
function dbx_auth_url(): string {
    $j = dbx_load();
    $verifier = dbx_b64u(random_bytes(48)); $state = bin2hex(random_bytes(16));
    start_session(); $_SESSION['dbx_pkce'] = ['verifier' => $verifier, 'state' => $state]; session_write_close();
    return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query(['client_id' => $j['app_key'] ?? '', 'response_type' => 'code', 'token_access_type' => 'offline',
        'code_challenge' => dbx_b64u(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'redirect_uri' => dbx_redirect(), 'state' => $state]);
}

function dbx_token(array &$j): string {
    if (!empty($j['access_token']) && (int)($j['expires'] ?? 0) > time()) return $j['access_token'];
    if (empty($j['refresh_token'])) throw new RuntimeException('Dropbox n’est pas connecté.');
    $r = dbx_post('https://api.dropboxapi.com/oauth2/token', http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $j['refresh_token'], 'client_id' => $j['app_key'] ?? '']),
        ['Content-Type: application/x-www-form-urlencoded'], 20);
    $t = json_decode($r['body'], true);
    if ($r['code'] !== 200 || empty($t['access_token'])) {
        if (in_array($t['error'] ?? '', ['invalid_grant', 'invalid_client'], true)) { unset($j['refresh_token']); }
        throw new RuntimeException('Dropbox refuse l’accès (' . ($t['error_description'] ?? $t['error'] ?? ($r['code'] ?: $r['err'])) . '). Reconnecte Dropbox dans les réglages.');
    }
    $j['access_token'] = $t['access_token']; $j['expires'] = time() + (int)($t['expires_in'] ?? 14400) - 60;
    return $j['access_token'];
}
/** appel de l'API Dropbox (JSON) */
function dbx_api(array &$j, string $ep, ?array $args) {
    $r = dbx_post('https://api.dropboxapi.com/2/' . $ep, $args === null ? 'null' : json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ['Authorization: Bearer ' . dbx_token($j), 'Content-Type: application/json'], 30);
    return $r['code'] === 200 ? json_decode($r['body'], true) : null;
}

/** contenu de la sauvegarde : même format que « Télécharger la sauvegarde » (réimportable) */
function dbx_payload(): string {
    $d = db_read();
    $bk = ['app' => 'flp-facturation', 'version' => 1, 'exportedAt' => gmdate('c'), 'source' => 'Sauvegarde automatique Dropbox', 'config' => ['entreprise' => $d['config']['entreprise'] ?? new stdClass()]];
    foreach (['clients', 'articles', 'formules', 'docs'] as $c) { $bk[$c] = []; foreach ((array)($d[$c] ?? []) as $id => $v) $bk[$c][] = ['id' => (string)$id] + (array)$v; }
    return json_encode($bk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/** envoi d'une sauvegarde (si les données ont changé, sauf $force) ; garde 60 fichiers + 1 par mois */
function dbx_backup(bool $force = false): array {
    $j = dbx_load();
    if (empty($j['refresh_token'])) return ['ok' => false, 'msg' => 'Dropbox n’est pas connecté.'];
    try {
        $data = dbx_payload(); $hash = hash('sha256', preg_replace('/"exportedAt": "[^"]*"/', '', $data));
        if (!$force && ($j['hash'] ?? '') === $hash) { $j['verifie'] = date('c'); dbx_save($j); return ['ok' => true, 'msg' => 'Rien n’a changé depuis la dernière sauvegarde.', 'inchange' => true]; }
        $name = 'Sauvegarde-facturation-' . date('Y-m-d') . '.json';
        $r = dbx_post('https://content.dropboxapi.com/2/files/upload', $data, ['Authorization: Bearer ' . dbx_token($j), 'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: ' . json_encode(['path' => '/' . $name, 'mode' => 'overwrite', 'mute' => true], JSON_UNESCAPED_SLASHES)], 120);
        if ($r['code'] !== 200) {
            $e = json_decode($r['body'], true);
            throw new RuntimeException('Envoi refusé par Dropbox (' . ($e['error_summary'] ?? ($r['code'] ?: $r['err'])) . ').');
        }
        $j['hash'] = $hash; $j['dernier'] = ['quand' => date('c'), 'fichier' => $name, 'taille' => strlen($data)]; $j['verifie'] = date('c'); unset($j['erreur']);
        /* ménage : les 60 plus récentes + la première de chaque mois */
        $l = dbx_api($j, 'files/list_folder', ['path' => '', 'limit' => 2000]);
        if (is_array($l)) {
            $files = [];
            foreach ((array)($l['entries'] ?? []) as $e) if (($e['.tag'] ?? '') === 'file' && preg_match('/^Sauvegarde-facturation-(\d{4}-\d{2})-(\d{2})\.json$/', $e['name'], $m)) $files[$e['name']] = $m;
            krsort($files); $keep = array_slice(array_keys($files), 0, 60); $month = [];
            ksort($files); foreach ($files as $n => $m) if (!isset($month[$m[1]])) $month[$m[1]] = $n;
            foreach ($files as $n => $m) if (!in_array($n, $keep, true) && !in_array($n, $month, true)) dbx_api($j, 'files/delete_v2', ['path' => '/' . $n]);
        }
        dbx_save($j);
        return ['ok' => true, 'msg' => 'Sauvegarde envoyée dans Dropbox (' . $name . ').'];
    } catch (Throwable $e) {
        $j['erreur'] = ['quand' => date('c'), 'msg' => $e->getMessage()]; dbx_save($j);
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/** une fois par jour, quand l'appli est ouverte ou par la tâche planifiée */
function dbx_auto(): void {
    $j = dbx_load();
    if (empty($j['refresh_token'])) return;
    if (substr((string)($j['verifie'] ?? ''), 0, 10) === date('Y-m-d')) return;
    $lock = @fopen(DATA_DIR . '/dropbox.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    dbx_backup(false);
    flock($lock, LOCK_UN); fclose($lock);
}

function dbx_etat(): array {
    $j = dbx_load();
    return ['app_key' => $j['app_key'] ?? '', 'connecte' => !empty($j['refresh_token']), 'compte' => $j['compte'] ?? '', 'dernier' => $j['dernier'] ?? null,
        'erreur' => $j['erreur'] ?? null, 'redirect' => dbx_redirect()];
}
