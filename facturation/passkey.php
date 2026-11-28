<?php
/* Fabrice Lau Production — Facturation : déverrouillage par Face ID / Touch ID (clés d'accès WebAuthn).
   Clés publiques et réglages dans data/passkeys.json (jamais publié).
   Appelé directement : connexion par Face ID depuis la page de connexion (?opt pour le défi, POST pour la réponse). */
declare(strict_types=1);
if (!defined('FLP')) {
    define('FLP', 1);
    require __DIR__ . '/lib.php';
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $o = function ($a, int $c = 200) { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
    if (!installed()) $o(['message' => 'Non installé.'], 503);
    start_session();
    if (isset($_GET['opt'])) {
        $j = pk_load();
        if (!$j['creds']) $o(['message' => 'Face ID n’est pas encore activé : connecte-toi avec ton mot de passe, puis active-le dans les Réglages.'], 404);
        $o(pk_options('login'));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (($w = login_blocked())) $o(['message' => 'Trop d’essais. Réessaie dans ' . ceil($w / 60) . ' minutes.'], 429);
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        try { pk_check($in, 'login'); }
        catch (Throwable $e) { login_attempt("\0passkey-refuse"); $o(['message' => $e->getMessage()], 403); }
        session_regenerate_id(true);
        $_SESSION['ok'] = 1; $_SESSION['pw'] = substr(cfg()['password'], -16);
        $_SESSION['lock'] = 1; $_SESSION['unlocked'] = 1; $_SESSION['seen'] = time();
        $o(['ok' => true]);
    }
    $o(['message' => 'Requête invalide.'], 400);
}

function pk_file(): string { return DATA_DIR . '/passkeys.json'; }
function pk_load(): array { $f = pk_file(); $j = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; $j['creds'] = $j['creds'] ?? []; return $j; }
function pk_save(array $j): void { ensure_data_dir(); atomic_write(pk_file(), json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); @chmod(pk_file(), 0600); }
function pk_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function pk_unb64u(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
/** domaine de rattachement : fabricelauproduction.com (marche avec et sans www) */
function pk_rpid(): string { $h = strtolower((string)parse_url(base_url(), PHP_URL_HOST)); return preg_replace('/^www\./', '', $h); }
function pk_lock_on(): bool { $j = pk_load(); return !empty($j['lock']) && $j['creds']; }
function pk_delay(): int { $j = pk_load(); return max(0, min(240, (int)($j['delai'] ?? 5))) * 60; }

/** défi à signer (stocké dans la session) */
function pk_options(string $kind): array {
    start_session();
    $ch = random_bytes(32); $_SESSION['pk_' . $kind] = ['c' => pk_b64u($ch), 't' => time()];
    $o = ['challenge' => pk_b64u($ch), 'rpId' => pk_rpid(), 'timeout' => 60000];
    if ($kind !== 'register') $o['allow'] = array_map(fn($c) => $c['id'], pk_load()['creds']);
    return $o;
}
function pk_take_challenge(string $kind): string {
    start_session();
    $c = $_SESSION['pk_' . $kind] ?? null; unset($_SESSION['pk_' . $kind]);
    if (!$c || time() - (int)$c['t'] > 300) throw new RuntimeException('Le délai est dépassé : recommence.');
    return pk_unb64u($c['c']);
}
function pk_client_data(string $json, string $type, string $challenge): void {
    $cd = json_decode($json, true);
    if (!is_array($cd) || ($cd['type'] ?? '') !== $type) throw new RuntimeException('Réponse Face ID invalide.');
    if (!hash_equals($challenge, pk_unb64u((string)($cd['challenge'] ?? '')))) throw new RuntimeException('Réponse Face ID périmée : recommence.');
    $h = strtolower((string)parse_url((string)($cd['origin'] ?? ''), PHP_URL_HOST)); $rp = pk_rpid();
    if ((!str_starts_with((string)$cd['origin'], 'https://') && $h !== 'localhost') || ($h !== $rp && !str_ends_with($h, '.' . $rp))) throw new RuntimeException('Origine refusée.');
}
/** enregistre une nouvelle clé (session déjà connectée) */
function pk_register(array $in): array {
    $ch = pk_take_challenge('register');
    $cdj = pk_unb64u((string)($in['clientDataJSON'] ?? ''));
    pk_client_data($cdj, 'webauthn.create', $ch);
    $id = (string)($in['id'] ?? ''); $spki = pk_unb64u((string)($in['publicKey'] ?? '')); $alg = (int)($in['alg'] ?? 0);
    if (!preg_match('/^[A-Za-z0-9_-]{16,1400}$/', $id) || strlen($spki) < 60 || !in_array($alg, [-7, -257], true)) throw new RuntimeException('Clé Face ID non prise en charge par ce navigateur.');
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    if (!openssl_pkey_get_public($pem)) throw new RuntimeException('Clé publique illisible.');
    $j = pk_load();
    $j['creds'] = array_values(array_filter($j['creds'], fn($c) => $c['id'] !== $id));
    $j['creds'][] = ['id' => $id, 'pem' => $pem, 'alg' => $alg, 'nom' => mb_substr(trim((string)($in['nom'] ?? 'Appareil')), 0, 60) ?: 'Appareil', 'cree' => date('c'), 'count' => 0];
    if (!isset($j['lock'])) $j['lock'] = true;
    if (!isset($j['delai'])) $j['delai'] = 5;
    pk_save($j);
    return $j;
}
/** vérifie une signature Face ID ; met à jour le compteur */
function pk_check(array $in, string $kind): array {
    $ch = pk_take_challenge($kind);
    $cdj = pk_unb64u((string)($in['clientDataJSON'] ?? ''));
    $ad = pk_unb64u((string)($in['authenticatorData'] ?? ''));
    $sig = pk_unb64u((string)($in['signature'] ?? ''));
    pk_client_data($cdj, 'webauthn.get', $ch);
    $j = pk_load(); $k = null;
    foreach ($j['creds'] as $i => $c) if (hash_equals($c['id'], (string)($in['id'] ?? ''))) $k = $i;
    if ($k === null) throw new RuntimeException('Cet appareil n’est pas autorisé : active Face ID dans les Réglages.');
    if (strlen($ad) < 37 || !hash_equals(hash('sha256', pk_rpid(), true), substr($ad, 0, 32))) throw new RuntimeException('Domaine incorrect.');
    $flags = ord($ad[32]);
    if (!($flags & 0x01) || !($flags & 0x04)) throw new RuntimeException('Face ID n’a pas confirmé ton identité.');
    $ok = openssl_verify($ad . hash('sha256', $cdj, true), $sig, $j['creds'][$k]['pem'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('Signature Face ID invalide.');
    $n = unpack('N', substr($ad, 33, 4))[1];
    $prev = (int)($j['creds'][$k]['count'] ?? 0);
    if ($n > 0 && $n <= $prev) throw new RuntimeException('Clé Face ID refusée (compteur).');
    $j['creds'][$k]['count'] = $n; $j['creds'][$k]['vu'] = date('c'); pk_save($j);
    return $j['creds'][$k];
}
