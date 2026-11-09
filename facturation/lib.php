<?php
/* Fabrice Lau Production — Facturation : bibliothèque commune (stockage, connexion, e-mails) */
declare(strict_types=1);
if (!defined('FLP')) { http_response_code(403); exit; }

date_default_timezone_set('Indian/Reunion');
/* compatibilité PHP 7.4 */
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n === '' || strncmp($h, $n, strlen($n)) === 0; } }
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n === '' || substr($h, -strlen($n)) === $n; } }
const APP_VERSION = '1.0.0';
const DATA_DIR = __DIR__ . '/data';
const COLLECTIONS = ['clients', 'articles', 'formules', 'docs'];

/* ---------- configuration (data/config.php, créé à l'installation) ---------- */
function cfg_path(): string { return DATA_DIR . '/config.php'; }
function installed(): bool { return is_file(cfg_path()); }
function cfg(bool $reload = false): array {
    static $c = null;
    if ($reload && function_exists('opcache_invalidate')) @opcache_invalidate(cfg_path(), true);
    if ($c === null || $reload) { clearstatcache(); $c = installed() ? (require cfg_path()) : []; }
    return $c;
}
function cfg_save(array $c): void {
    ensure_data_dir();
    $php = "<?php\n/* Généré par l'outil de facturation. Ne pas partager. */\nreturn " . var_export($c, true) . ";\n";
    atomic_write(cfg_path(), $php);
    cfg(true);
}
function ensure_data_dir(): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    if (!is_dir(DATA_DIR . '/sauvegardes')) mkdir(DATA_DIR . '/sauvegardes', 0750, true);
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    if (!is_file(DATA_DIR . '/index.html')) file_put_contents(DATA_DIR . '/index.html', '');
}
function atomic_write(string $file, string $content): void {
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) throw new RuntimeException("Écriture impossible : vérifie les droits du dossier data.");
    if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException("Écriture impossible."); }
}
function base_url(): string {
    $c = cfg();
    if (!empty($c['base_url'])) return rtrim($c['base_url'], '/') . '/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/* ---------- base de données : un fichier JSON verrouillé, sauvegardé chaque jour ---------- */
function db_file(): string { return DATA_DIR . '/donnees.json'; }
function db_empty(): array { return ['version' => 0, 'config' => [], 'clients' => [], 'articles' => [], 'formules' => [], 'docs' => [], 'meta' => []]; }
function db_read(): array {
    if (!is_file(db_file())) return db_empty();
    $raw = file_get_contents(db_file());
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) throw new RuntimeException('Fichier de données illisible. Restaure une sauvegarde du dossier data/sauvegardes.');
    return $d + db_empty();
}
/** Exécute $fn sous verrou exclusif ; $fn reçoit les données par référence. */
function db_tx(callable $fn) {
    ensure_data_dir();
    $lock = fopen(DATA_DIR . '/verrou.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Données occupées, réessaie.');
    try {
        $d = db_read();
        $before = json_encode($d);
        $res = $fn($d);
        if (json_encode($d) !== $before) {
            $d['version'] = (int)$d['version'] + 1;
            $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) throw new RuntimeException('Données invalides.');
            atomic_write(db_file(), $json);
            daily_backup($json);
        }
        return $res;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
function daily_backup(string $json): void {
    $f = DATA_DIR . '/sauvegardes/donnees-' . date('Y-m-d') . '.json';
    @file_put_contents($f, $json, LOCK_EX);
    $all = glob(DATA_DIR . '/sauvegardes/donnees-*.json') ?: [];
    sort($all);
    while (count($all) > 60) @unlink(array_shift($all));
}

/* ---------- session et connexion ---------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = str_starts_with(base_url(), 'https://');
    session_name('flp_fact');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => rtrim(parse_url(base_url(), PHP_URL_PATH) ?: '/', '/') . '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    $dir = DATA_DIR . '/sessions';
    if (is_dir(DATA_DIR) && (is_dir($dir) || @mkdir($dir, 0750))) session_save_path($dir);
    session_start();
}
function logged_in(): bool { start_session(); return !empty($_SESSION['ok']) && ($_SESSION['pw'] ?? '') === substr(cfg()['password'] ?? '', -16); }
function csrf(): string { start_session(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function login_blocked(): int {
    $f = DATA_DIR . '/connexions.json';
    $j = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $e = $j[$ip] ?? ['n' => 0, 't' => 0];
    if ($e['n'] >= 8 && time() - $e['t'] < 900) return 900 - (time() - $e['t']);
    return 0;
}
function login_attempt(string $pw): bool {
    $f = DATA_DIR . '/connexions.json';
    $j = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $ok = password_verify($pw, cfg()['password'] ?? '');
    if ($ok) { unset($j[$ip]); start_session(); session_regenerate_id(true); $_SESSION['ok'] = 1; $_SESSION['pw'] = substr(cfg()['password'], -16); }
    else { $e = $j[$ip] ?? ['n' => 0, 't' => 0]; if (time() - $e['t'] > 900) $e['n'] = 0; $e['n']++; $e['t'] = time(); $j[$ip] = $e; usleep(700000); }
    @file_put_contents($f, json_encode($j), LOCK_EX);
    return $ok;
}

/* ---------- e-mails : SMTP (recommandé) ou mail() de l'hébergeur ---------- */
function mime_header(string $s): string { return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }
function clean_addr(string $s): string { return trim(str_replace(["\r", "\n", '<', '>', ','], '', $s)); }

/**
 * $m = [to=>[], subject, body (texte), html? , attachments=>[[filename, content(base64), mimeType]], bcc=>[], reply_to?]
 */
function send_mail(array $m): void {
    $c = cfg();
    $from = clean_addr($c['from_email'] ?? '');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("L'adresse d'expédition n'est pas réglée (menu Compte et e-mails).");
    $fromName = $c['from_name'] ?? 'Fabrice Lau Production';
    $to = array_values(array_filter(array_map('clean_addr', (array)($m['to'] ?? [])), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
    if (!$to) throw new RuntimeException('Adresse du destinataire invalide.');
    $bcc = array_values(array_filter(array_map('clean_addr', (array)($m['bcc'] ?? [])), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) && !in_array($a, $to, true)));
    $reply = clean_addr($m['reply_to'] ?? ($c['reply_to'] ?? ''));

    $boundary = 'b' . bin2hex(random_bytes(12));
    $alt = 'a' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($from, '@'), 1);
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mime_header($fromName) . " <$from>",
        'To: ' . implode(', ', $to),
        'Subject: ' . mime_header(str_replace(["\r", "\n"], ' ', (string)($m['subject'] ?? ''))),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (parse_url(base_url(), PHP_URL_HOST) ?: $domain) . '>',
        'MIME-Version: 1.0',
        "Content-Type: multipart/mixed; boundary=\"$boundary\"",
    ];
    if ($reply && filter_var($reply, FILTER_VALIDATE_EMAIL) && $reply !== $from) $headers[] = "Reply-To: $reply";
    $text = str_replace(["\r\n", "\r"], "\n", (string)($m['body'] ?? ''));
    $html = $m['html'] ?? ('<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1b1f1e">'
        . preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))) . '</div>');
    $body = "--$boundary\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
        . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
        . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
        . "--$alt--\r\n";
    foreach ((array)($m['attachments'] ?? []) as $a) {
        $fn = preg_replace('/[^\w.\- ]+/u', '_', (string)($a['filename'] ?? 'document.pdf'));
        $mt = preg_match('~^[\w.+-]+/[\w.+-]+$~', (string)($a['mimeType'] ?? '')) ? $a['mimeType'] : 'application/octet-stream';
        $data = preg_replace('/\s+/', '', (string)($a['content'] ?? ''));
        if ($data === '' || base64_decode($data, true) === false) throw new RuntimeException('Pièce jointe invalide.');
        $body .= "--$boundary\r\nContent-Type: $mt; name=\"" . mime_header($fn) . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . mime_header($fn) . "\"\r\n\r\n" . chunk_split($data) . "\r\n";
    }
    $body .= "--$boundary--\r\n";

    /* identifiant Gmail sans serveur indiqué : on passe quand même par Gmail */
    if (empty($c['smtp_host']) && !empty($c['smtp_user']) && preg_match('/@(gmail|googlemail)\.com$/i', (string)$c['smtp_user'])) { $c['smtp_host'] = 'smtp.gmail.com'; $c['smtp_port'] = 465; $c['smtp_secure'] = 'ssl'; }
    if (!empty($c['smtp_host'])) {
        smtp_send($c, $from, array_merge($to, $bcc), implode("\r\n", $headers), $body);
        return;
    }
    /* mail() : To et Subject passent en paramètres, pas dans les en-têtes */
    $h = array_values(array_filter($headers, fn($x) => !str_starts_with($x, 'To:') && !str_starts_with($x, 'Subject:')));
    if ($bcc) $h[] = 'Bcc: ' . implode(', ', $bcc);
    $subject = mime_header(str_replace(["\r", "\n"], ' ', (string)($m['subject'] ?? '')));
    if (!@mail(implode(', ', $to), $subject, $body, implode("\r\n", $h), '-f' . $from))
        throw new RuntimeException("L'hébergeur a refusé l'envoi. Règle un serveur SMTP dans Compte et e-mails.");
}

function smtp_send(array $c, string $from, array $rcpts, string $headers, string $body): void {
    $host = $c['smtp_host']; $port = (int)($c['smtp_port'] ?? 465); $sec = $c['smtp_secure'] ?? 'ssl';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Connexion au serveur d'e-mails impossible ($host:$port) : $err");
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): array {
        $out = ''; $code = 0;
        while (($line = fgets($fp, 1024)) !== false) { $out .= $line; $code = (int)substr($line, 0, 3); if (strlen($line) < 4 || $line[3] !== '-') break; }
        return [$code, $out];
    };
    $cmd = function (string $s, array $ok) use ($fp, $read): string {
        fwrite($fp, $s . "\r\n");
        [$code, $out] = $read();
        if (!in_array($code, $ok, true)) {
            $shown = str_starts_with($s, 'AUTH') || strlen($s) > 60 ? strtok($s, ' ') : $s;
            throw new RuntimeException("Le serveur d'e-mails a refusé ($shown) : " . trim($out));
        }
        return $out;
    };
    try {
        [$code, $out] = $read();
        if ($code !== 220) throw new RuntimeException('Serveur SMTP : ' . trim($out));
        $ehlo = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd("EHLO $ehlo", [250]);
        if ($sec === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)))
                throw new RuntimeException('Chiffrement TLS impossible avec le serveur SMTP.');
            $cmd("EHLO $ehlo", [250]);
        }
        if (!empty($c['smtp_user'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($c['smtp_user']), [334]);
            try { $cmd(base64_encode((string)($c['smtp_pass'] ?? '')), [235]); }
            catch (RuntimeException $e) { throw new RuntimeException("Identifiant ou mot de passe SMTP refusé. Pour Gmail, il faut un « mot de passe d'application ». (" . $e->getMessage() . ')'); }
        }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($rcpts as $r) $cmd("RCPT TO:<$r>", [250, 251]);
        $cmd('DATA', [354]);
        $msg = $headers . "\r\n\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $msg));
        fwrite($fp, $msg . "\r\n.\r\n");
        [$code, $out] = $read();
        if ($code !== 250) throw new RuntimeException("Message refusé par le serveur d'e-mails : " . trim($out));
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/* ---------- calculs (miroir de l'appli, pour la page de signature et les relances) ---------- */
function r2($n): float { return round((float)$n + 0.0, 2); }
function doc_franchise(array $d, array $conf): bool { $e = (!empty($d['numero']) && !empty($d['entreprise'])) ? $d['entreprise'] : $conf; return ($e['regimeTva'] ?? '') === 'franchise'; }
function doc_totals(array $d, array $conf): array {
    $fr = doc_franchise($d, $conf); $ttcMode = ($d['saisie'] ?? '') === 'ttc';
    $ht = 0.0; $ttc = 0.0; $rows = [];
    foreach ((array)($d['lignes'] ?? []) as $l) {
        $rate = $fr ? 0.0 : (float)($l['tva'] ?? 0); $q = (float)($l['qte'] ?? 0);
        if ($ttcMode) { $lt = r2($q * (float)($l['puTTC'] ?? 0)); $lh = r2($lt / (1 + $rate / 100)); }
        else { $lh = r2($q * (float)($l['pu'] ?? 0)); $lt = (!empty($l['acompteRef']) && isset($l['puTTC'])) ? r2($q * (float)$l['puTTC']) : r2($lh * (1 + $rate / 100)); }
        $ht += $lh; $ttc += $lt; $rows[] = $l + ['_ht' => $lh, '_ttc' => $lt];
    }
    return ['ht' => r2($ht), 'ttc' => r2($ttc), 'tva' => r2($ttc - $ht), 'rows' => $rows, 'franchise' => $fr];
}
function eur($n): string { return number_format((float)$n, 2, ',', "\u{202F}") . "\u{00A0}€"; }
function fdate(?string $iso): string { if (!$iso) return '—'; $p = explode('-', substr($iso, 0, 10)); return count($p) === 3 ? "$p[2]/$p[1]/$p[0]" : $iso; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- relance automatique des devis sans réponse (une fois par jour) ---------- */
function run_daily(bool $force = false): array {
    $today = date('Y-m-d');
    $plan = db_tx(function (array &$d) use ($today, $force) {
        if (!$force && ($d['meta']['daily'] ?? '') === $today) return null;
        if ((int)date('G') < 7 && !$force) return null; /* le matin, pas en pleine nuit */
        $d['meta']['daily'] = $today;
        $conf = $d['config']['entreprise'] ?? [];
        if (($conf['relanceDevisAuto'] ?? true) === false || ($conf['relanceDevisAuto'] ?? '') === 'false') return [];
        $days = max(1, min(60, (int)($conf['relanceDevisJours'] ?? 7) ?: 7));
        $todo = [];
        foreach ($d['docs'] as $id => $doc) {
            if (($doc['type'] ?? '') !== 'devis' || ($doc['statut'] ?? '') !== 'envoye' || !empty($doc['test']) || !empty($doc['relanceAuto'])) continue;
            $env = (array)($doc['envois'] ?? []);
            if (array_filter($env, fn($e) => str_contains((string)($e['kind'] ?? ''), 'relance'))) continue;
            $sends = array_values(array_filter($env, fn($e) => !str_contains((string)($e['kind'] ?? ''), 'relance')));
            if (!$sends) continue;
            $last = end($sends)['date'] ?? null; if (!$last) continue;
            $due = date('Y-m-d', strtotime($last . ' +' . $days . ' days'));
            if ($due > $today) continue;
            if (!empty($doc['validite']) && $doc['validite'] < $today) continue;
            if (!empty($doc['evtDate']) && $doc['evtDate'] < $today) continue;
            $client = $doc['client'] ?? ($d['clients'][$doc['clientId'] ?? ''] ?? []);
            $email = $client['email'] ?? (($d['clients'][$doc['clientId'] ?? ''] ?? [])['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $todo[] = ['id' => $id, 'email' => $email];
        }
        return $todo;
    });
    if (!$plan) return [];
    $done = [];
    $data = db_read();
    $conf = $data['config']['entreprise'] ?? [];
    foreach ($plan as $p) {
        $doc = $data['docs'][$p['id']] ?? null; if (!$doc) continue;
        $doc['id'] = $p['id'];
        [$subject, $body] = relance_text($doc, $conf, $data);
        try {
            send_mail(['to' => [$p['email']], 'subject' => $subject, 'body' => $body, 'bcc' => [cfg()['notify_email'] ?? ''], 'reply_to' => $conf['email'] ?? '']);
            db_tx(function (array &$d) use ($p) {
                if (!isset($d['docs'][$p['id']])) return;
                $d['docs'][$p['id']]['relanceAuto'] = date('Y-m-d');
                $d['docs'][$p['id']]['envois'][] = ['date' => date('Y-m-d'), 'kind' => 'relance-auto', 'to' => $p['email']];
            });
            $done[] = $doc['numero'] ?? $p['id'];
        } catch (Throwable $e) { error_log('Relance devis : ' . $e->getMessage()); }
    }
    return $done;
}
function relance_text(array $d, array $s, array $data): array {
    $c = $d['client'] ?? ($data['clients'][$d['clientId'] ?? ''] ?? []);
    $t = doc_totals($d, $s);
    $sends = array_values(array_filter((array)($d['envois'] ?? []), fn($e) => !str_contains((string)($e['kind'] ?? ''), 'relance')));
    $v = [
        'bonjour' => ($c['type'] ?? '') === 'pro' ? 'Bonjour' . (!empty($c['contact']) ? ' ' . $c['contact'] : '') . ',' : 'Bonjour' . (!empty($c['nom']) ? ' ' . explode(' ', $c['nom'])[0] : '') . ',',
        'client' => $c['nom'] ?? '', 'numero' => $d['numero'] ?? '', 'objet' => $d['objet'] ?? '', 'montant' => eur($t['ttc']), 'date' => fdate($d['date'] ?? ''),
        'session' => !empty($d['evtDate']) ? ' pour la prestation du ' . fdate($d['evtDate']) . (!empty($d['evtHeure']) ? ' à ' . str_replace(':', 'h', $d['evtHeure']) : '') : '',
        'validite' => fdate($d['validite'] ?? ''), 'echeance' => fdate($d['echeance'] ?? ''),
        'lien_devis' => !empty($d['lienDevis']) ? "Pour accepter le devis en ligne, cliquez sur ce lien :\n" . $d['lienDevis'] : '',
        'date_envoi' => fdate($sends[0]['date'] ?? ($d['date'] ?? '')), 'acompte' => '', 'paiement' => '',
        'type_doc' => 'Devis', 'la_facture' => 'le devis',
        'signature' => implode("\n", array_filter(['Cordialement,', $s['nom'] ?? '', $s['tel'] ?? '', $s['email'] ?? ''])),
    ];
    $tpl = $s['mailTpl']['relanceDevis'] ?? [];
    $sujet = $tpl['sujet'] ?? 'Votre devis {numero} – {objet}';
    $corps = $tpl['corps'] ?? "{bonjour}\n\nNous revenons vers vous au sujet de notre devis n° {numero}{session}, d'un montant de {montant} TTC, que nous vous avons envoyé le {date_envoi}.\n\nAvez-vous pu en prendre connaissance ? Nous restons à votre disposition pour toute question ou pour l'adapter à vos besoins.\n\n{lien_devis}\n\nLe devis est valable jusqu'au {validite}.\n\n{signature}";
    $fill = function (string $str) use ($v): string {
        $o = preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $v) ? $v[$m[1]] : $m[0], $str);
        $o = preg_replace('/ – (?=\n|$)/', '', $o); $o = preg_replace("/[ \t]+\n/", "\n", $o); $o = preg_replace("/\n{3,}/", "\n\n", $o);
        return trim($o);
    };
    $body = $fill($corps);
    if (!empty($d['lienDevis']) && !str_contains($body, $d['lienDevis'])) $body .= "\n\nPour accepter le devis en ligne, cliquez sur ce lien :\n" . $d['lienDevis'];
    return [$fill($sujet), $body];
}

/* ---------- données de départ : Fabrice Lau Production ---------- */
function seed_entreprise(string $email): array {
    return [
        'nom' => 'Fabrice Lau Production', 'forme' => 'Entreprise individuelle', 'siren' => '800821290', 'siret' => '80082129000015', 'rcs' => '',
        'adresse' => '68 Allée des Saphirs', 'cp' => '97400', 'ville' => 'Saint-Denis', 'tel' => '0692 02 44 99', 'email' => $email ?: 'fabrice.ltl@gmail.com',
        'site' => 'www.fabricelau.com', 'tvaIntra' => '', 'regimeTva' => 'normal', 'tvaSurDebits' => false, 'saisiePrix' => 'ht',
        'iban' => 'FR76 4191 9094 0201 0683 0329 134', 'bic' => 'BNPARERXXXX', 'titulaire' => 'Fabrice Lau Production',
        'delaiPaiement' => 30, 'validiteDevis' => 30,
        'prefixeFacture' => '', 'prefixeDevis' => 'D', 'prefixeAvoir' => 'AV', 'annee' => false, 'debutFacture' => 625, 'debutDevis' => 1, 'debutAvoir' => 1,
        'penalites' => "Taux d'intérêt de la BCE majoré de 10 points",
        'conditionsDevis' => "Commande confirmée à réception du devis signé et de l'acompte.\nLe devis couvre la prestation décrite ci-dessus. Toute demande supplémentaire fera l'objet d'un devis complémentaire.\nDeux séries de corrections sur le montage sont incluses. Les suivantes sont facturées au temps passé.\nLes fichiers finaux sont livrés après règlement complet de la facture.\nEn cas d'annulation par le client moins de 7 jours avant le tournage, l'acompte reste acquis.\nEn cas d'intempéries empêchant le tournage, une nouvelle date est fixée d'un commun accord, sans frais.",
        'relanceDevisAuto' => true, 'relanceDevisJours' => 7,
    ];
}
