<?php
/* Fabrice Lau Production — Facturation : notifications sur le téléphone (Web Push, sans service extérieur).
   Les clés et la liste des appareils sont dans data/push.json (jamais publié). */
declare(strict_types=1);
if (!defined('FLP')) { http_response_code(403); exit; }

function push_file(): string { return DATA_DIR . '/push.json'; }
function push_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function push_unb64u(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function push_load(): array {
    $f = push_file();
    $j = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if (empty($j['vapid']['pem'])) {
        ensure_data_dir();
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$k) throw new RuntimeException('Le serveur ne sait pas créer les clés de notification.');
        openssl_pkey_export($k, $pem);
        $j['vapid'] = ['pem' => $pem, 'pub' => push_b64u(push_raw_pub($k))];
        $j['subs'] = $j['subs'] ?? [];
        push_save($j);
    }
    $j['subs'] = $j['subs'] ?? [];
    return $j;
}
function push_save(array $j): void { atomic_write(push_file(), json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); @chmod(push_file(), 0600); }

/** clé publique P-256 brute (65 octets : 0x04 || x || y) */
function push_raw_pub($key): string {
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
/** clé publique brute -> objet OpenSSL */
function push_pub_key(string $raw) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}
/** signature ECDSA DER -> r||s (64 octets), format attendu par JWT ES256 */
function push_der2raw(string $der): string {
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$o + 1]); $v = substr($der, $o + 2, $len); $o += 2 + $len;
        $v = ltrim($v, "\0"); $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}
function push_hkdf(string $salt, string $ikm, string $info, int $len): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}

/** chiffrement du message (RFC 8291, aes128gcm) */
function push_encrypt(string $payload, string $uaPub, string $auth): string {
    $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPub = push_raw_pub($local);
    $peer = push_pub_key($uaPub);
    if (!$peer) throw new RuntimeException('Clé de l’appareil invalide.');
    $secret = openssl_pkey_derive($peer, $local, 32);
    if ($secret === false) throw new RuntimeException('Échange de clés impossible.');
    $ikm = push_hkdf($auth, $secret, "WebPush: info\0" . $uaPub . $asPub, 32);
    $salt = random_bytes(16);
    $cek = push_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
    $nonce = push_hkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

function push_jwt(string $endpoint, array $vapid): string {
    $u = parse_url($endpoint);
    $h = push_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $sub = preg_match('~^https://~', base_url()) ? rtrim(base_url(), '/') : 'mailto:notifications@fabricelauproduction.com';
    $c = push_b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    openssl_sign("$h.$c", $sig, openssl_pkey_get_private($vapid['pem']), OPENSSL_ALGO_SHA256);
    return "$h.$c." . push_b64u(push_der2raw($sig));
}

/** envoi à un appareil ; renvoie le code HTTP (201 = reçu) */
function push_one(array $s, array $vapid, array $msg): int {
    if (!function_exists('curl_init')) return 0;
    $body = push_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), push_unb64u($s['p256dh']), push_unb64u($s['auth']));
    $ch = curl_init($s['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: high',
            'Authorization: vapid t=' . push_jwt($s['endpoint'], $vapid) . ', k=' . $vapid['pub']]]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
}

/** envoie une notification à tous les appareils ; les appareils désinscrits sont retirés */
/** pastille de l'icône : factures en retard + devis envoyés sans réponse depuis 7 jours */
function push_badge(): int {
    try {
        $d = db_read(); $t = date('Y-m-d'); $d7 = date('Y-m-d', time() - 7 * 86400); $n = 0;
        foreach ((array)$d['docs'] as $x) {
            if (!empty($x['test'])) continue;
            if (($x['type'] ?? '') === 'facture' && ($x['statut'] ?? '') === 'emise' && !empty($x['echeance']) && $x['echeance'] < $t) $n++;
            elseif (($x['type'] ?? '') === 'devis' && ($x['statut'] ?? '') === 'envoye' && (string)($x['date'] ?? '') <= $d7 && (empty($x['validite']) || $x['validite'] >= $t)) $n++;
        }
        return $n;
    } catch (Throwable $e) { return -1; }
}
function push_send(string $title, string $body, string $url = '', string $tag = ''): array {
    $j = push_load(); $ok = 0; $gone = []; $badge = push_badge();
    foreach ($j['subs'] as $id => $s) {
        try { $code = push_one($s, $j['vapid'], ['title' => $title, 'body' => $body, 'url' => $url ?: base_url(), 'tag' => $tag] + ($badge >= 0 ? ['badge' => $badge] : [])); }
        catch (Throwable $e) { error_log('Notification : ' . $e->getMessage()); $code = 0; }
        if ($code >= 200 && $code < 300) $ok++;
        elseif ($code === 404 || $code === 410) $gone[] = $id;
    }
    if ($gone) { $j = push_load(); foreach ($gone as $id) unset($j['subs'][$id]); push_save($j); }
    return ['envoyes' => $ok, 'appareils' => count($j['subs'])];
}

/** notification d'un événement, si elle est activée dans les réglages */
function push_event(string $kind, string $title, string $body, string $tag = ''): void {
    try {
        if (!is_file(push_file())) return;
        $conf = db_read()['config']['entreprise'] ?? [];
        $key = ['ouvert' => 'notifDevisOuvert', 'accepte' => 'notifDevisAccepte'][$kind] ?? '';
        if ($key && array_key_exists($key, $conf) && !$conf[$key]) return;
        $j = push_load(); if (!$j['subs']) return;
        push_send($title, $body, base_url(), $tag);
    } catch (Throwable $e) { error_log('Notification : ' . $e->getMessage()); }
}
