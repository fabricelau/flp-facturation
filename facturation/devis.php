<?php
/* Fabrice Lau Production — page publique d'acceptation d'un devis (lien envoyé au client) */
declare(strict_types=1);
define('FLP', 1);
require __DIR__ . '/lib.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string)($_GET['t'] ?? ''), "/ \t\n");
if (!installed() || !preg_match('/^[A-Za-z0-9]{12,64}$/', $token)) show(null, 'Ce lien n\'est pas valide.');

function find_doc(array $data, string $token): ?array {
    foreach ($data['docs'] as $id => $d) if (($d['type'] ?? '') === 'devis' && isset($d['signToken']) && hash_equals((string)$d['signToken'], $token)) return $d + ['id' => (string)$id];
    return null;
}
$data = db_read();
$d = find_doc($data, $token);
$conf = $data['config']['entreprise'] ?? [];
if (!$d) show(null, 'Ce devis est introuvable. Le lien est peut-être incomplet : contacte-nous.');

$formKey = hash_hmac('sha256', $token, (string)(cfg()['cron_key'] ?? 'k'));
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $nom = trim(preg_replace('/\s+/', ' ', (string)($_POST['nom'] ?? '')));
    if (!hash_equals($formKey, (string)($_POST['k'] ?? ''))) $err = 'La page a expiré. Recharge-la et réessaie.';
    elseif (mb_strlen($nom) < 3) $err = 'Indiquez votre nom et prénom, ou le nom de votre société.';
    elseif (empty($_POST['ok'])) $err = 'Cochez la case pour accepter le devis.';
    else {
        $res = db_tx(function (array &$all) use ($d, $nom) {
            $cur = $all['docs'][$d['id']] ?? null;
            if (!$cur) return 'introuvable';
            if (($cur['statut'] ?? '') === 'accepte' || ($cur['statut'] ?? '') === 'facture') return 'deja';
            if (!in_array($cur['statut'] ?? '', ['emis', 'envoye'], true)) return 'ferme';
            if (!empty($cur['validite']) && $cur['validite'] < date('Y-m-d')) return 'expire';
            $all['docs'][$d['id']]['statut'] = 'accepte';
            $all['docs'][$d['id']]['acceptation'] = ['date' => date('Y-m-d\TH:i:s'), 'nom' => mb_substr($nom, 0, 120), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'navigateur' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)];
            return 'ok';
        });
        if ($res === 'ok') {
            $data = db_read(); $d = find_doc($data, $token);
            $t = doc_totals($d, $conf);
            $client = $d['client'] ?? ($data['clients'][$d['clientId'] ?? ''] ?? []);
            try {
                send_mail(['to' => [cfg()['notify_email'] ?? ''], 'subject' => 'Devis ' . ($d['numero'] ?? '') . ' accepté en ligne',
                    'body' => "Bonne nouvelle !\n\nLe devis " . ($d['numero'] ?? '') . ' (' . ($client['nom'] ?? '') . ', ' . eur($t['ttc']) . " TTC) vient d'être accepté en ligne par $nom, le " . date('d/m/Y à H\hi') . ".\n\nIl est passé en « Accepté » dans ta facturation.\n" . base_url()]);
            } catch (Throwable $e) { error_log('Alerte devis accepté : ' . $e->getMessage()); }
            if (filter_var($client['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                try {
                    send_mail(['to' => [$client['email']], 'reply_to' => $conf['email'] ?? '', 'subject' => 'Confirmation – devis ' . ($d['numero'] ?? '') . ' accepté',
                        'body' => "Bonjour,\n\nNous avons bien reçu votre accord pour le devis n° " . ($d['numero'] ?? '') . (!empty($d['objet']) ? ' (' . $d['objet'] . ')' : '') . ', d\'un montant de ' . eur($t['ttc']) . " TTC.\n\nMerci pour votre confiance, nous revenons vers vous très vite pour la suite.\n\nCordialement,\n" . implode("\n", array_filter([$conf['nom'] ?? '', $conf['tel'] ?? '', $conf['email'] ?? '']))]);
                } catch (Throwable $e) { error_log('Confirmation client : ' . $e->getMessage()); }
            }
        } elseif ($res === 'expire') $err = 'Ce devis n\'est plus valable. Contactez-nous pour en obtenir un nouveau.';
        elseif ($res === 'ferme') $err = 'Ce devis ne peut plus être accepté en ligne. Contactez-nous.';
        $data = db_read(); $d = find_doc($data, $token);
    }
}
show($d, $err);

function show(?array $d, string $err): void {
    global $conf, $formKey, $data;
    $conf = $conf ?? []; $s = (!empty($d['entreprise']) ? $d['entreprise'] + $conf : $conf);
    $name = $s['nom'] ?? 'Fabrice Lau Production';
    ob_start();
    if (!$d) {
        echo '<section class="card"><h1>Devis</h1><p class="err">' . h($err) . '</p></section>';
    } else {
        $t = doc_totals($d, $conf);
        $client = $d['client'] ?? ($data['clients'][$d['clientId'] ?? ''] ?? []);
        $st = $d['statut'] ?? '';
        $expired = !empty($d['validite']) && $d['validite'] < date('Y-m-d');
        $ttcMode = ($d['saisie'] ?? '') === 'ttc';
        echo '<section class="card paper"><div class="top"><div>';
        if (!empty($s['logo']) && str_starts_with((string)$s['logo'], 'data:image/')) echo '<img class="logo" src="' . h($s['logo']) . '" alt="">';
        echo '<b class="co">' . h($name) . '</b><div class="muted">' . h(trim(($s['adresse'] ?? '') . ', ' . ($s['cp'] ?? '') . ' ' . ($s['ville'] ?? ''), ', ')) . '<br>' . h(implode(' · ', array_filter([$s['tel'] ?? '', $s['email'] ?? '']))) . '</div></div>';
        echo '<div class="ttl"><h1>DEVIS</h1><div class="num">' . h($d['numero'] ?? '') . '</div><div class="muted">du ' . fdate($d['date'] ?? '') . (!empty($d['validite']) ? '<br>valable jusqu\'au ' . fdate($d['validite']) : '') . '</div></div></div>';
        echo '<div class="cl"><span class="muted">Pour</span><br><b>' . h($client['nom'] ?? '') . '</b>' . (!empty($client['contact']) ? '<br>' . h($client['contact']) : '') . '</div>';
        if (!empty($d['objet'])) echo '<p><b>Objet :</b> ' . h($d['objet']) . '</p>';
        $evt = [];
        if (!empty($d['evtDate'])) { $fmt = new IntlDateFormatterShim(); $evt[] = 'Date de la prestation : ' . $fmt->long($d['evtDate']) . (!empty($d['evtHeure']) ? ' à ' . str_replace(':', 'h', $d['evtHeure']) : '') . (!empty($d['evtDuree']) ? ' · durée ' . $d['evtDuree'] : ''); }
        if (!empty($d['evtLieu'])) $evt[] = 'Lieu : ' . $d['evtLieu'];
        foreach (preg_split('/\n/', (string)($d['evtNote'] ?? '')) as $x) if (trim($x) !== '') $evt[] = trim($x);
        if ($evt) echo '<div class="evt">' . implode('<br>', array_map('h', $evt)) . '</div>';
        echo '<div class="tw"><table><thead><tr><th>Désignation</th><th class="r">Qté</th><th class="r">' . ($ttcMode ? 'PU TTC' : 'PU HT') . '</th>' . ($t['franchise'] ? '' : '<th class="r">TVA</th>') . '<th class="r">' . ($ttcMode ? 'Total TTC' : 'Total HT') . '</th></tr></thead><tbody>';
        foreach ($t['rows'] as $l) {
            if (($l['designation'] ?? '') === '' && !(float)($l['pu'] ?? 0) && !(float)($l['puTTC'] ?? 0)) continue;
            $pu = $ttcMode ? (float)($l['puTTC'] ?? 0) : (float)($l['pu'] ?? 0);
            echo '<tr><td>' . h($l['designation'] ?? '') . (!empty($l['details']) ? '<small>' . nl2br(h($l['details'])) . '</small>' : '') . '</td><td class="r">' . h(rtrim(rtrim(number_format((float)($l['qte'] ?? 0), 2, ',', ' '), '0'), ',')) . ' ' . h($l['unite'] ?? '') . '</td><td class="r">' . eur($pu) . '</td>' . ($t['franchise'] ? '' : '<td class="r">' . h(str_replace('.', ',', (string)(+($l['tva'] ?? 0)))) . ' %</td>') . '<td class="r">' . eur($ttcMode ? $l['_ttc'] : $l['_ht']) . '</td></tr>';
        }
        echo '</tbody></table></div><div class="tot">';
        if (!$t['franchise']) echo '<div><span>Total HT</span><span>' . eur($t['ht']) . '</span></div><div><span>TVA</span><span>' . eur($t['tva']) . '</span></div>';
        echo '<div class="g"><span>Total TTC</span><span>' . eur($t['ttc']) . '</span></div>';
        if (!empty($d['acompteDemande'])) { $pct = (float)($d['acomptePct'] ?? 30) ?: 30; echo '<div><span>Acompte à la commande (' . h(rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',')) . ' %)</span><span>' . eur(r2($t['ttc'] * $pct / 100)) . '</span></div>'; }
        echo '</div>';
        if ($t['franchise']) echo '<p class="muted small">TVA non applicable, article 293 B du CGI.</p>';
        if (!empty($d['notes'])) echo '<p class="small">' . nl2br(h($d['notes'])) . '</p>';
        $cond = array_values(array_filter(array_map('trim', explode("\n", (string)($conf['conditionsDevis'] ?? '')))));
        if ($cond) { echo '<h2>Conditions</h2><ul class="small">'; foreach ($cond as $c) { if (empty($d['acompteDemande'])) $c = preg_replace('/\s+et de l[\'’]acompte(?=\s*\.?\s*$)/iu', '', $c); echo '<li>' . h($c) . '</li>'; } echo '</ul>'; }
        echo '</section>';

        echo '<section class="card act">';
        if ($st === 'accepte' || $st === 'facture') {
            $a = $d['acceptation'] ?? null;
            echo '<div class="ok"><b>✓ Devis accepté' . ($a ? ' le ' . fdate($a['date']) . ' à ' . str_replace(':', 'h', substr($a['date'], 11, 5)) . ' par ' . h($a['nom']) : '') . '.</b><br>Merci pour votre confiance' . (!empty($d['acompteDemande']) ? '. Pour l\'acompte, les coordonnées bancaires figurent ci-dessous.' : ', nous revenons vers vous très vite.') . '</div>';
            if (!empty($d['acompteDemande']) && !empty($s['iban'])) echo '<p class="small">Virement : ' . h($s['titulaire'] ?? $name) . ' · IBAN ' . h($s['iban']) . (!empty($s['bic']) ? ' · BIC ' . h($s['bic']) : '') . ' · Référence : Devis ' . h($d['numero'] ?? '') . '</p>';
        } elseif (in_array($st, ['refuse', 'annule'], true)) {
            echo '<p class="err">Ce devis n\'est plus d\'actualité. Pour toute question, contactez-nous : ' . h($s['tel'] ?? '') . ' · ' . h($s['email'] ?? '') . '</p>';
        } elseif ($expired) {
            echo '<p class="err">Ce devis a expiré le ' . fdate($d['validite']) . '. Contactez-nous pour en obtenir un nouveau : ' . h($s['tel'] ?? '') . ' · ' . h($s['email'] ?? '') . '</p>';
        } else {
            echo '<h2>Accepter ce devis</h2>' . ($err ? '<p class="err">' . h($err) . '</p>' : '')
                . '<form method="post"><input type="hidden" name="k" value="' . h($formKey) . '">'
                . '<label>Votre nom et prénom, ou votre société<input name="nom" required autocomplete="name" value="' . h($_POST['nom'] ?? ($client['contact'] ?? (($client['type'] ?? '') === 'pro' ? '' : ($client['nom'] ?? '')))) . '"></label>'
                . '<label class="chk"><input type="checkbox" name="ok" value="1" required> J\'accepte le devis n° ' . h($d['numero'] ?? '') . ' d\'un montant de ' . eur($t['ttc']) . ' TTC et ses conditions (bon pour accord).</label>'
                . '<button>Accepter le devis</button></form><p class="muted small">Votre accord est enregistré avec la date et l\'heure. Vous recevrez une confirmation par e-mail.</p>';
        }
        echo '</section>';
    }
    $body = ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . h($d ? 'Devis ' . ($d['numero'] ?? '') . ' – ' . $name : $name) . '</title><link rel="icon" href="assets/icone.svg" type="image/svg+xml">
<style>
:root{--bg:#F4F3F1;--card:#fff;--ink:#1B1C20;--muted:#6B6A64;--line:#DDDAD4;--acc:#B8302F;--ok:#1E7A3B;--okb:#E1F3E6;--er:#B42318;--erb:#FBE4E1;color-scheme:light}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;padding:24px 16px 48px}
.card{max-width:760px;margin:0 auto 16px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px 26px}
.paper{border-top:4px solid var(--acc)}.top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}.logo{max-height:56px;max-width:180px;display:block;margin-bottom:8px}
.co{font-size:17px}.ttl{text-align:right}.ttl h1{margin:0;color:var(--acc);letter-spacing:.06em;font-size:24px}.num{font-family:ui-monospace,Menlo,monospace;font-weight:600}
.muted{color:var(--muted)}.small{font-size:13px}.cl{margin:20px 0 14px auto;width:min(320px,100%);border:1px solid var(--line);border-radius:8px;padding:10px 12px}
.evt{background:var(--bg);border-radius:8px;padding:10px 12px;margin:10px 0;font-size:14px}
.tw{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:480px;font-size:14px}th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1.5px solid var(--ink);padding:6px 4px}
td{padding:8px 4px;border-bottom:1px solid var(--line);vertical-align:top}td small{display:block;color:var(--muted);font-size:12.5px}.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.tot{margin:12px 0 0 auto;width:min(340px,100%)}.tot div{display:flex;justify-content:space-between;padding:3px 4px;font-variant-numeric:tabular-nums}.tot .g{border-top:1.5px solid var(--ink);font-weight:700;font-size:17px;margin-top:4px;padding-top:6px}
h2{font-size:16px;margin:22px 0 6px}ul{padding-left:18px;margin:0}li{margin-bottom:3px}
form{display:flex;flex-direction:column;gap:12px}label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:var(--muted)}label.chk{flex-direction:row;align-items:flex-start;gap:9px;color:var(--ink);font-weight:500;font-size:14.5px}
input{font:inherit;border:1px solid var(--line);border-radius:8px;padding:10px 12px}input[type=checkbox]{width:20px;height:20px;flex:none;margin-top:1px}
button{font:inherit;font-weight:700;background:var(--acc);color:#fff;border:0;border-radius:9px;padding:13px 20px;cursor:pointer;font-size:16px}
.ok{background:var(--okb);color:var(--ok);padding:14px;border-radius:9px}.err{background:var(--erb);color:var(--er);padding:12px;border-radius:9px}
@media (max-width:560px){.card{padding:20px 16px}.ttl{text-align:left}}
</style></head><body>' . $body . '</body></html>';
    exit;
}

/* date longue en français, sans dépendre de l'extension intl */
final class IntlDateFormatterShim {
    public function long(string $iso): string {
        $ts = strtotime($iso . ' 12:00:00'); if (!$ts) return $iso;
        $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w', $ts)];
        $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int)date('n', $ts) - 1];
        return $j . ' ' . (int)date('j', $ts) . ($m ? ' ' . $m : '') . ' ' . date('Y', $ts);
    }
}
