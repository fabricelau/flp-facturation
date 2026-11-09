<?php
/* Fabrice Lau Production — Facturation : API de l'appli (réservée à l'utilisateur connecté) */
declare(strict_types=1);
define('FLP', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/depot.php';
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
        if (function_exists('maj_auto')) register_shutdown_function(function () { if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); try { maj_auto(); } catch (Throwable $e) { error_log('Mise à jour : ' . $e->getMessage()); } });
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
                    if (array_key_exists($k, $old) && json_encode($old[$k]) !== json_encode($data[$k] ?? null)) throw new RuntimeException('Une facture émise ne peut plus être modifiée. Fais un avoir.');
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
    case 'entreprises': {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 3) fail('Tape au moins 3 caractères.');
        $url = 'https://recherche-entreprises.api.gouv.fr/search?per_page=8&q=' . rawurlencode($q);
        $raw = http_get($url);
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) fail("L'annuaire des entreprises ne répond pas. Réessaie dans un instant.", 502);
        $res = [];
        foreach ((array)($j['results'] ?? []) as $r) {
            $s = $r['siege'] ?? [];
            $adr = trim(implode(' ', array_filter([$s['numero_voie'] ?? '', $s['indice_repetition'] ?? '', $s['type_voie'] ?? '', $s['libelle_voie'] ?? ''])));
            if ($adr === '' && !empty($s['adresse'])) $adr = preg_replace('/\s*\d{5}\s.*$/', '', $s['adresse']);
            $siren = (string)($r['siren'] ?? '');
            $res[] = ['nom' => $r['nom_raison_sociale'] ?? ($r['nom_complet'] ?? ''), 'siren' => $siren, 'tva' => tva_intra($siren),
                'adresse' => $adr, 'cp' => $s['code_postal'] ?? '', 'ville' => $s['libelle_commune'] ?? '', 'actif' => ($r['etat_administratif'] ?? 'A') === 'A'];
        }
        out(['results' => $res]);
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
