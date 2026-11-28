<?php
/* Fabrice Lau Production — Facturation : API de l'appli (réservée à l'utilisateur connecté) */
declare(strict_types=1);
define('FLP', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/depot.php';
if (is_file(__DIR__ . '/push.php')) require __DIR__ . '/push.php';
if (is_file(__DIR__ . '/dropbox.php')) require __DIR__ . '/dropbox.php';
if (is_file(__DIR__ . '/pj.php')) require __DIR__ . '/pj.php';
if (is_file(__DIR__ . '/passkey.php')) require __DIR__ . '/passkey.php';
if (is_file(__DIR__ . '/maj.php')) require __DIR__ . '/maj.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['message' => $msg], $code); }

set_exception_handler(function (Throwable $e) { error_log('Facturation : ' . $e); $e instanceof RuntimeException ? fail($e->getMessage(), 409) : fail('Erreur du serveur.', 500); });

if (!installed()) fail("L'outil n'est pas encore installé.", 503);
if (!logged_in()) fail('Ta session a expiré. Reconnecte-toi.', 401);

/* action : api.php/<action> (fonctionne sans réécriture d'URL), ou api.php?a=<action> */
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '') { $u = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''; $p = strpos($u, 'api.php/'); if ($p !== false) $path = substr($u, $p + 7); }
$action = trim($path, '/') ?: ($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    if (!hash_equals(csrf(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Session invalide. Recharge la page.', 403);
}
/* verrouillage Face ID : après un temps d'inactivité, l'appli demande Face ID (ou le mot de passe) */
$PK_FREE = ['pk_unlock_options', 'pk_unlock', 'pk_unlock_pw', 'pk_state'];
if (function_exists('pk_lock_on') && !empty($_SESSION['lock']) && pk_lock_on() && !in_array($action, $PK_FREE, true)) {
    if (empty($_SESSION['unlocked']) || time() - (int)($_SESSION['seen'] ?? 0) > pk_delay()) { $_SESSION['unlocked'] = 0; session_write_close(); fail('Facturation verrouillée.', 423); }
}
if (!in_array($action, $PK_FREE, true)) $_SESSION['seen'] = time();
session_write_close(); /* libère la session : les requêtes en parallèle ne s'attendent pas */
$in = $method === 'GET' ? [] : (json_decode((string)file_get_contents('php://input'), true) ?? []);
if (!is_array($in)) fail('Requête invalide.');

function valid_id($id): string { $id = (string)$id; if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) fail('Identifiant invalide.'); return $id; }
function valid_col($c): string { if (!in_array($c, COLLECTIONS, true)) fail('Collection inconnue.'); return $c; }
function locked_doc(?array $d): bool { return $d && in_array($d['type'] ?? '', ['facture', 'avoir'], true) && !empty($d['numero']) && empty($d['test']); }

switch ($action) {
    case 'load': {
        try { $sent = run_daily(); } catch (Throwable $e) { $sent = []; error_log('Relances : ' . $e->getMessage()); }
        $d = db_read();
        $o = ['version' => $d['version'], 'config' => ['entreprise' => $d['config']['entreprise'] ?? null], 'relances' => $sent];
        foreach (COLLECTIONS as $c) { $o[$c] = []; foreach ($d[$c] as $id => $v) $o[$c][] = ['id' => (string)$id, 'data' => $v]; }
        if (function_exists('maj_auto')) register_shutdown_function(function () { if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); try { maj_auto(); } catch (Throwable $e) { error_log('Mise à jour : ' . $e->getMessage()); } try { if (function_exists('dbx_auto')) dbx_auto(); } catch (Throwable $e) { error_log('Dropbox : ' . $e->getMessage()); } });
        out($o);
    }
    case 'version':
        out(['version' => db_read()['version']]);

    case 'put': {
        $col = valid_col($in['col'] ?? ''); $id = valid_id($in['id'] ?? ''); $data = $in['data'] ?? null;
        if (!is_array($data)) fail('Données invalides.');
        unset($data['id']);
        $v = db_tx(function (array &$d) use ($col, $id, $data) {
            $old = $d[$col][$id] ?? null;
            if ($col === 'docs' && locked_doc($old)) {
                /* facture émise : le numéro, la date et les montants ne changent plus */
                foreach (['numero', 'seq', 'type', 'date', 'lignes', 'saisie'] as $k)
                    if ($k === 'numero' && !empty($old['import']['source']) && is_string($data['numero'] ?? null) && $data['numero'] !== '' && ltrim((string)$old['numero'], '0') === $data['numero']) continue; /* historique importé : seuls les zéros de tête peuvent être retirés */
                    elseif (array_key_exists($k, $old) && json_encode($old[$k]) !== json_encode($data[$k] ?? null)) throw new RuntimeException('Une facture émise ne peut plus être modifiée. Fais un avoir.');
            }
            /* un devis accepté en ligne entre-temps garde son acceptation, même si l'appli renvoie une copie plus ancienne */
            /* le classement dans Factures FLP est noté par le serveur : une copie plus ancienne ne l'efface pas */
            if ($col === 'docs' && $old && !empty($old['depot']) && empty($data['depot'])) $data['depot'] = $old['depot'];
            if ($col === 'docs' && ($data['type'] ?? '') === 'devis' && $old && !empty($old['acceptation'])) {
                if (empty($data['acceptation'])) $data['acceptation'] = $old['acceptation'];
                if (in_array($data['statut'] ?? '', ['emis', 'envoye'], true)) $data['statut'] = $old['statut'];
            }
            $d[$col][$id] = $data;
            return null;
        });
        out(['ok' => true, 'version' => db_read()['version']]);
    }
    case 'delete': {
        $col = valid_col($in['col'] ?? ''); $id = valid_id($in['id'] ?? '');
        db_tx(function (array &$d) use ($col, $id) {
            if ($col === 'docs' && locked_doc($d['docs'][$id] ?? null)) throw new RuntimeException('Une facture émise ne peut pas être supprimée (conservation obligatoire 10 ans).');
            if (isset($d[$col][$id])) {
                /* corbeille : gardé 30 jours */
                $d['corbeille'] = array_values(array_filter((array)($d['corbeille'] ?? []), fn($x) => strtotime((string)($x['le'] ?? '')) > time() - 30 * 86400));
                $d['corbeille'][] = ['cid' => bin2hex(random_bytes(6)), 'col' => $col, 'id' => $id, 'le' => date('c'), 'data' => $d[$col][$id]];
            }
            unset($d[$col][$id]);
        });
        out(['ok' => true, 'version' => db_read()['version']]);
    }
    case 'config': {
        $data = $in['data'] ?? null; if (!is_array($data)) fail('Données invalides.');
        db_tx(function (array &$d) use ($data) { $d['config']['entreprise'] = $data; });
        out(['ok' => true, 'version' => db_read()['version']]);
    }
    case 'reset': {
        if (($in['confirm'] ?? '') !== 'SUPPRIMER') fail('Confirmation manquante.');
        $n = db_tx(function (array &$d) {
            $n = 0;
            foreach ($d['docs'] as $id => $doc) if (!(!empty($doc['numero']) && empty($doc['test']))) { unset($d['docs'][$id]); $n++; }
            return $n;
        });
        out(['ok' => true, 'deleted' => $n]);
    }
    case 'mail': {
        $to = (array)($in['to'] ?? []);
        $atts = [];
        foreach ((array)($in['attachments'] ?? []) as $a) $atts[] = ['filename' => $a['filename'] ?? 'document.pdf', 'content' => $a['content'] ?? '', 'mimeType' => $a['mimeType'] ?? 'application/pdf'];
        $conf = db_read()['config']['entreprise'] ?? [];
        $copy = cfg()['notify_email'] ?? '';
        send_mail(['to' => $to, 'subject' => (string)($in['subject'] ?? ''), 'body' => (string)($in['body'] ?? ''), 'attachments' => $atts,
            'bcc' => !empty(cfg()['copy_me']) ? [$copy] : [], 'reply_to' => $conf['email'] ?? '']);
        out(['ok' => true, 'payload' => ['sent' => true]]);
    }
    case 'corbeille': {
        $l = array_values(array_filter((array)(db_read()['corbeille'] ?? []), fn($x) => strtotime((string)($x['le'] ?? '')) > time() - 30 * 86400));
        out(['items' => array_reverse($l)]);
    }
    case 'corbeille_restore': {
        $cid = (string)($in['cid'] ?? ''); $r = null;
        db_tx(function (array &$d) use ($cid, &$r) {
            foreach ((array)($d['corbeille'] ?? []) as $k => $x) {
                if (($x['cid'] ?? '') !== $cid) continue;
                $col = valid_col($x['col']); $id = (string)$x['id'];
                if (isset($d[$col][$id])) $id = $id . '-r' . substr(bin2hex(random_bytes(3)), 0, 4);
                $d[$col][$id] = $x['data']; unset($d['corbeille'][$k]); $d['corbeille'] = array_values($d['corbeille']);
                $r = ['col' => $col, 'id' => $id]; return;
            }
            throw new RuntimeException('Élément introuvable dans la corbeille (peut-être déjà restauré).');
        });
        out(['ok' => true] + $r + ['version' => db_read()['version']]);
    }
    case 'corbeille_del': {
        $cid = (string)($in['cid'] ?? ''); $all = !empty($in['tout']);
        db_tx(function (array &$d) use ($cid, $all) {
            $d['corbeille'] = $all ? [] : array_values(array_filter((array)($d['corbeille'] ?? []), fn($x) => ($x['cid'] ?? '') !== $cid));
        });
        out(['ok' => true]);
    }
    case 'pj_list': out(['items' => pj_list(valid_id($_GET['doc'] ?? ''))]);
    case 'pj_add': {
        $doc = valid_id($_POST['doc'] ?? '');
        try { out(['items' => pj_add($doc, $_FILES['f'] ?? [])]); } catch (RuntimeException $e) { fail($e->getMessage()); }
    }
    case 'pj_del': out(['items' => pj_del(valid_id($in['doc'] ?? ''), (string)($in['id'] ?? ''))]);
    case 'pj_get': pj_send(valid_id($_GET['doc'] ?? ''), (string)($_GET['f'] ?? ''));
    case 'ping': out(['ok' => true]);
    case 'pk_state': { start_session(); $j = pk_load(); $r = ['active' => (bool)$j['creds'], 'lock' => !empty($j['lock']), 'delai' => (int)($j['delai'] ?? 5), 'session' => !empty($_SESSION['lock']), 'unlocked' => !empty($_SESSION['unlocked']) && time() - (int)($_SESSION['seen'] ?? 0) <= pk_delay(),
        'appareils' => array_map(fn($c) => ['id' => $c['id'], 'nom' => $c['nom'], 'cree' => $c['cree'], 'vu' => $c['vu'] ?? ''], $j['creds'])]; session_write_close(); out($r); }
    case 'pk_reg_options': { $o = pk_options('register'); session_write_close(); out($o); }
    case 'pk_register': { try { pk_register($in); start_session(); $_SESSION['lock'] = 1; $_SESSION['unlocked'] = 1; $_SESSION['seen'] = time(); session_write_close(); out(['ok' => true]); } catch (RuntimeException $e) { fail($e->getMessage()); } }
    case 'pk_unlock_options': { $o = pk_options('unlock'); session_write_close(); out($o); }
    case 'pk_unlock': { try { pk_check($in, 'unlock'); } catch (RuntimeException $e) { fail($e->getMessage(), 403); } start_session(); $_SESSION['unlocked'] = 1; $_SESSION['seen'] = time(); session_write_close(); out(['ok' => true]); }
    case 'pk_unlock_pw': {
        if (($w = login_blocked())) fail('Trop d’essais. Réessaie dans ' . ceil($w / 60) . ' minutes.', 429);
        if (!login_attempt((string)($in['pw'] ?? ''))) fail('Mot de passe incorrect.', 403);
        out(['ok' => true, 'nonce' => csrf()]);
    }
    case 'pk_mark': { start_session(); $_SESSION['lock'] = 1; $_SESSION['seen'] = time(); session_write_close(); out(['ok' => true]); }
    case 'pk_settings': { $j = pk_load(); $j['lock'] = !empty($in['lock']); $j['delai'] = in_array((int)($in['delai'] ?? 5), [1, 5, 15, 60], true) ? (int)$in['delai'] : 5; pk_save($j); out(['ok' => true]); }
    case 'pk_del': { $j = pk_load(); $id = (string)($in['id'] ?? ''); $j['creds'] = array_values(array_filter($j['creds'], fn($c) => $c['id'] !== $id)); pk_save($j); out(['ok' => true]); }
    case 'dbx_info': out(dbx_etat());
    case 'dbx_key': {
        $k = trim((string)($in['app_key'] ?? ''));
        if (!preg_match('/^[a-z0-9]{10,20}$/', $k)) fail('Cette clé d’appli Dropbox ne semble pas valide (une quinzaine de lettres et chiffres, rubrique « App key »).');
        $j = dbx_load(); if (($j['app_key'] ?? '') !== $k) $j = ['app_key' => $k]; dbx_save($j);
        out(['ok' => true, 'url' => dbx_auth_url()]);
    }
    case 'dbx_connect': {
        if (empty(dbx_load()['app_key'])) fail('Enregistre d’abord la clé de ton appli Dropbox.');
        out(['url' => dbx_auth_url()]);
    }
    case 'dbx_now': { $r = dbx_backup(true); out($r + ['etat' => dbx_etat()], $r['ok'] ? 200 : 502); }
    case 'dbx_off': { $j = dbx_load(); dbx_save(['app_key' => $j['app_key'] ?? '']); out(['ok' => true, 'etat' => dbx_etat()]); }
    case 'push_info': {
        $j = push_load();
        $dev = []; foreach ($j['subs'] as $id => $x) $dev[] = ['id' => $id, 'nom' => $x['nom'] ?? 'Appareil', 'cree' => $x['cree'] ?? ''];
        out(['key' => $j['vapid']['pub'], 'appareils' => $dev]);
    }
    case 'push_sub': {
        $sub = $in['sub'] ?? []; $ep = (string)($sub['endpoint'] ?? '');
        $p = (string)($sub['keys']['p256dh'] ?? ''); $a = (string)($sub['keys']['auth'] ?? '');
        if (!preg_match('~^https://[a-z0-9.-]+(:\d+)?/~i', $ep) || !preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p) || !preg_match('/^[A-Za-z0-9_-]{16,32}$/', $a)) fail('Abonnement invalide.');
        $j = push_load(); $id = substr(hash('sha256', $ep), 0, 16);
        $j['subs'][$id] = ['endpoint' => $ep, 'p256dh' => $p, 'auth' => $a, 'nom' => mb_substr(trim((string)($in['nom'] ?? 'Appareil')), 0, 60) ?: 'Appareil', 'cree' => date('c')];
        push_save($j);
        out(['ok' => true, 'id' => $id]);
    }
    case 'push_del': {
        $id = valid_id($in['id'] ?? ''); $j = push_load(); unset($j['subs'][$id]); push_save($j); out(['ok' => true]);
    }
    case 'push_test': {
        $r = push_send('Facturation', 'Les notifications fonctionnent sur cet appareil 👍', base_url(), 'test');
        out(['ok' => $r['envoyes'] > 0] + $r);
    }
    case 'entreprises': {
        $q = trim((string)($_GET['q'] ?? ''));
        if (preg_match('/^[\d\s.]{9,17}$/', $q)) $q = preg_replace('/\D/', '', $q);
        if (mb_strlen($q) < 3) fail('Tape au moins 3 caractères.');
        $FORMES = ['1000' => 'Entreprise individuelle', '5499' => 'SARL', '5498' => 'EURL', '5710' => 'SAS', '5720' => 'SASU', '5599' => 'SA', '5505' => 'SA', '6540' => 'SCI', '9220' => 'Association', '9210' => 'Association', '7210' => 'Commune', '7220' => 'Département', '7230' => 'Région', '7389' => 'Établissement public', '7490' => 'Établissement public', '5306' => 'SNC', '5202' => 'SNC', '6316' => 'CUMA', '5458' => 'SARL coopérative', '5560' => 'SA coopérative', '5785' => 'SAS coopérative', '6210' => 'GIE'];
        $seen = []; $res = [];
        foreach (['&departement=974', ''] as $f) {
            $raw = http_get('https://recherche-entreprises.api.gouv.fr/search?per_page=8&q=' . rawurlencode($q) . $f);
            $j = json_decode((string)$raw, true);
            if (!is_array($j)) { if (!$res && $f === '') fail("L'annuaire des entreprises ne répond pas. Réessaie dans un instant.", 502); continue; }
            foreach ((array)($j['results'] ?? []) as $r) {
                $siren = (string)($r['siren'] ?? ''); if ($siren === '' || isset($seen[$siren])) continue; $seen[$siren] = 1;
                $s = $r['siege'] ?? [];
                $adr = trim(implode(' ', array_filter([$s['numero_voie'] ?? '', $s['indice_repetition'] ?? '', $s['type_voie'] ?? '', $s['libelle_voie'] ?? ''])));
                if ($adr === '' && !empty($s['adresse'])) $adr = preg_replace('/\s*\d{5}\s.*$/', '', $s['adresse']);
                elseif (!empty($s['complement_adresse'])) $adr = trim($s['complement_adresse']) . ', ' . $adr;
                $dir = ''; $qual = '';
                foreach ((array)($r['dirigeants'] ?? []) as $p) {
                    if (($p['type_dirigeant'] ?? '') !== 'personne physique' || preg_match('/commissaire/i', (string)($p['qualite'] ?? ''))) continue;
                    $pr = explode(' ', trim((string)($p['prenoms'] ?? '')))[0] ?? '';
                    $dir = trim(mb_convert_case($pr, MB_CASE_TITLE) . ' ' . mb_convert_case((string)($p['nom'] ?? ''), MB_CASE_TITLE)); $qual = (string)($p['qualite'] ?? ''); break;
                }
                $nj = (string)($r['nature_juridique'] ?? '');
                $res[] = ['nom' => $r['nom_raison_sociale'] ?? ($r['nom_complet'] ?? ''), 'siren' => $siren, 'tva' => in_array($nj, ['9220', '9210'], true) ? '' : tva_intra($siren),
                    'adresse' => $adr, 'cp' => $s['code_postal'] ?? '', 'ville' => $s['libelle_commune'] ?? '', 'actif' => ($r['etat_administratif'] ?? 'A') === 'A',
                    'forme' => $FORMES[$nj] ?? '', 'contact' => $dir, 'dirigeant' => $dir ? $dir . (!empty($qual) ? ' (' . $qual . ')' : '') : ''];
            }
            if (count($res) >= 8) break;
        }
        out(['results' => array_slice($res, 0, 10)]);
    }
    case 'depot': {
        $id = valid_id($in['id'] ?? '');
        $data0 = db_read(); $d = $data0['docs'][$id] ?? null;
        if (!$d || ($d['type'] ?? '') !== 'facture' || empty($d['numero'])) fail('Facture introuvable.');
        if (empty($d['client'])) $d['client'] = $data0['clients'][$d['clientId'] ?? ''] ?? [];
        $dry = !empty($in['dry']);
        if (!$dry) {
            if (!empty($d['test'])) fail('Les documents de test ne sont pas classés dans Factures FLP.');
            if (($d['statut'] ?? '') !== 'payee') fail("La facture n'est pas encore marquée payée.");
            if (!empty($d['depot']['id']) && empty($in['force'])) out(['ok' => true, 'deja' => true, 'depot' => $d['depot']]);
        }
        $pdf = base64_decode(preg_replace('/\s+/', '', (string)($in['content'] ?? '')), true);
        if ($pdf === false || strncmp($pdf, '%PDF', 4) !== 0) fail('PDF invalide.');
        if (strlen($pdf) > 40 * 1048576) fail('PDF trop volumineux.');
        $item = depot_factures($d + ['id' => $id], $pdf, $data0['config']['entreprise'] ?? [], $dry);
        if ($dry) out(['ok' => true, 'dry' => $item]);
        $dep = ['id' => (int)$item['id'], 'date' => date('c'), 'fichier' => (string)($item['filename'] ?? '')];
        db_tx(function (array &$all) use ($id, $dep) { if (isset($all['docs'][$id])) $all['docs'][$id]['depot'] = $dep; });
        $json = (string)json_encode(['ok' => true, 'depot' => $dep, 'version' => db_read()['version']], JSON_UNESCAPED_UNICODE);
        ignore_user_abort(true); @set_time_limit(120);
        header('Content-Length: ' . strlen($json)); header('Connection: close'); echo $json;
        if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_flush(); flush(); }
        depot_dropbox($item);
        exit;
    }
    case 'maj': {
        if (!function_exists('maj_check')) fail('Mise à jour indisponible.', 404);
        $e = maj_etat();
        if ($method === 'GET') out($e + ['check' => maj_check()]);
        $r = maj_run();
        out($r + ['etat' => maj_etat()], $r['ok'] ? 200 : 409);
    }
    case 'import': {
        /* import ponctuel de l'historique : ajoute seulement ce qui n'existe pas encore, ne remplace jamais rien */
        $n = db_tx(function (array &$d) use ($in) {
            $n = ['clients' => 0, 'docs' => 0, 'deja' => 0];
            foreach (['clients', 'docs'] as $col) foreach ((array)($in[$col] ?? []) as $x) {
                $id = valid_id($x['id'] ?? ''); if (!is_array($x['data'] ?? null)) continue;
                if (isset($d[$col][$id])) { $n['deja']++; continue; }
                $d[$col][$id] = $x['data']; $n[$col]++;
            }
            return $n;
        });
        out(['ok' => true, 'n' => $n, 'version' => db_read()['version']]);
    }
    default:
        fail('Action inconnue.', 404);
}

function tva_intra(string $siren): string {
    if (!preg_match('/^\d{9}$/', $siren)) return '';
    $k = (12 + 3 * ((int)$siren % 97)) % 97;
    return 'FR' . str_pad((string)$k, 2, '0', STR_PAD_LEFT) . $siren;
}
function http_get(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'FLP-Facturation/' . APP_VERSION, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $r = curl_exec($ch); curl_close($ch);
        return is_string($r) ? $r : '';
    }
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\nUser-Agent: FLP-Facturation\r\n"]]);
    return (string)@file_get_contents($url, false, $ctx);
}
