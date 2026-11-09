<?php
/* Fabrice Lau Production — Facturation : classement des factures payées dans l'appli « Factures FLP »
   (dossier /factures du site). La pièce est enregistrée comme une vente, avec son PDF,
   puis copiée vers Dropbox par Factures FLP, comme les autres pièces. */
declare(strict_types=1);
if (!defined('FLP')) { http_response_code(403); exit; }

function factures_dir(): string {
    $c = cfg();
    return rtrim((string)($c['factures_dir'] ?? (dirname(__DIR__) . '/factures')), '/');
}
function factures_load(): void {
    $dir = factures_dir();
    if (!is_file($dir . '/lib.php')) throw new RuntimeException("L'appli Factures FLP est introuvable (dossier factures).");
    require_once $dir . '/lib.php';
    foreach (['flp_write', 'flp_read', 'flp_normalize', 'flp_file_path', 'flp_nice_name'] as $f)
        if (!function_exists($f)) throw new RuntimeException("L'appli Factures FLP a changé : classement impossible ($f).");
}
function depot_categorie(array $d): string {
    $cats = defined('FLP_CATEGORIES_VENTES') ? FLP_CATEGORIES_VENTES : [];
    $txt = mb_strtolower(($d['objet'] ?? '') . ' ' . implode(' ', array_map(function ($l) { return ($l['designation'] ?? '') . ' ' . ($l['details'] ?? ''); }, (array)($d['lignes'] ?? []))));
    $map = [
        'Clip musical' => '/\bclip\b/u', 'Mariage' => '/mariage/u', 'Publicité' => '/\bpub\b|publicit/u',
        'Événementiel' => '/événement|evenement|\bevent|soirée|séminaire|salon/u', 'Photo' => '/\bphoto/u',
        'Vidéo corporate' => '/vidéo|video|film|tournage|corporate|interview/u',
        'Montage & post-production' => '/montage|post-prod|étalonnage|etalonnage|mixage/u', 'Drone' => '/drone/u',
    ];
    foreach ($map as $cat => $re) if (in_array($cat, $cats, true) && preg_match($re, $txt)) return $cat;
    return in_array('Autre prestation', $cats, true) ? 'Autre prestation' : '';
}
function depot_paiement(string $mode): string {
    $m = mb_strtolower($mode);
    if (strpos($m, 'virement') !== false) return 'Virement';
    if (strpos($m, 'chèque') !== false || strpos($m, 'cheque') !== false) return 'Chèque';
    if (strpos($m, 'espèce') !== false || strpos($m, 'espece') !== false) return 'Espèces';
    if (strpos($m, 'carte') !== false || $m === 'cb') return 'CB';
    return '';
}

/** Classe le PDF d'une facture payée dans Factures FLP. $dry = true : calcule la pièce sans rien écrire. */
function depot_factures(array $d, string $pdf, array $conf, bool $dry = false): array {
    factures_load();
    $t = doc_totals($d, $conf);
    $client = (array)($d['client'] ?? []);
    $taux = array_values(array_unique(array_map(function ($l) { return (float)($l['tva'] ?? 0); }, (array)($d['lignes'] ?? []))));
    $pay = (array)($d['paiement'] ?? []);
    $hash = sha1($pdf);
    $designations = implode(' · ', array_filter(array_map(function ($l) { return trim((string)($l['designation'] ?? '')); }, (array)($d['lignes'] ?? []))));
    $fields = [
        'sens' => 'vente', 'type' => 'piece',
        'date' => (string)($d['date'] ?? ''), 'year' => substr((string)($d['date'] ?? date('Y')), 0, 4),
        'societe' => mb_substr((string)($client['nom'] ?? ''), 0, 120),
        'montant' => $t['ttc'], 'ht' => $t['ht'], 'tva' => $t['tva'], 'taux_tva' => count($taux) === 1 ? $taux[0] : null,
        'devise' => 'EUR', 'categorie' => depot_categorie($d),
        'numero' => mb_substr((string)($d['numero'] ?? ''), 0, 60),
        'paiement' => depot_paiement((string)($pay['mode'] ?? '')),
        'note' => mb_substr('Facture émise par la facturation' . (!empty($d['objet']) ? ' – ' . $d['objet'] : '') . (!empty($pay['date']) ? '. Payée le ' . fdate($pay['date']) . (!empty($pay['mode']) ? ' (' . $pay['mode'] . ')' : '') : '') . '.', 0, 2000),
        'texte' => mb_substr(trim(($d['numero'] ?? '') . ' ' . ($client['nom'] ?? '') . ' ' . ($d['objet'] ?? '') . ' ' . $designations), 0, 20000),
        'source' => 'import', 'ext' => 'pdf', 'hash' => $hash, 'pages' => max(1, (int)preg_match_all('/\/Type\s*\/Page[^s]/', $pdf)),
    ];
    if ($dry) return $fields + ['dry' => true, 'dossier' => factures_dir()];

    foreach (flp_read()['items'] as $x) if (($x['hash'] ?? '') === $hash && empty($x['trashed'])) return $x;   // déjà classé

    $tmp = tempnam(sys_get_temp_dir(), 'fac');
    if ($tmp === false || file_put_contents($tmp, $pdf) === false) throw new RuntimeException('Écriture temporaire impossible.');
    try {
        $item = flp_write(function (&$dd) use ($fields, $tmp) {
            $id = ++$dd['seq'];
            $x = flp_normalize(['id' => $id, 'v' => 2]);
            foreach ($fields as $k => $v) $x[$k] = $v;
            $x['added'] = date('c');
            $dest = flp_file_path($x);
            if (!copy($tmp, $dest)) throw new RuntimeException('Copie du PDF impossible dans Factures FLP.');
            @chmod($dest, 0600);
            $x['thumb'] = false;
            if (function_exists('flp_make_thumb') && function_exists('flp_thumb_path')) {
                try { $x['thumb'] = (bool)flp_make_thumb($dest, flp_thumb_path($id), 'pdf'); } catch (Throwable $e) { $x['thumb'] = false; }
            }
            $x['check'] = $x['societe'] === '' || $x['montant'] === null || $x['date'] === '' || $x['categorie'] === '';
            $x['filename'] = flp_nice_name($x, $dd['items']);
            $dd['items'][] = $x;
            return $x;
        });
    } finally {
        @unlink($tmp);
    }
    return $item;
}

/** Copie vers Dropbox (après la réponse), comme le fait Factures FLP pour ses propres pièces. */
function depot_dropbox(array $item): void {
    $f = factures_dir() . '/dropbox.php';
    if (!is_file($f)) return;
    try { require_once $f; if (function_exists('flp_dbx_after')) flp_dbx_after($item); }
    catch (Throwable $e) { error_log('Factures FLP / Dropbox : ' . $e->getMessage()); }
}
