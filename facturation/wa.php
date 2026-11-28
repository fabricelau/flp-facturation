<?php
/* Fabrice Lau Production — Facturation : message WhatsApp à Fabrice (service gratuit CallMeBot).
   Numéro et clé dans data/whatsapp.json (jamais publié). */
declare(strict_types=1);
if (!defined('FLP')) { http_response_code(403); exit; }

function wa_file(): string { return DATA_DIR . '/whatsapp.json'; }
function wa_load(): array { $f = wa_file(); return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }
function wa_save(array $j): void { ensure_data_dir(); atomic_write(wa_file(), json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); @chmod(wa_file(), 0600); }
function wa_phone(string $p): string {
    $p = preg_replace('/[^\d+]/', '', $p);
    if (preg_match('/^0(69[23]|26[29])\d{6}$/', $p)) $p = '+262' . substr($p, 1);      // portable / fixe Réunion
    elseif (preg_match('/^0[67]\d{8}$/', $p)) $p = '+33' . substr($p, 1);             // portable métropole
    elseif (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    return preg_match('/^\+\d{8,15}$/', $p) ? $p : '';
}
/** envoie un message ; renvoie [ok, détail] */
function wa_send(string $text): array {
    $j = wa_load();
    if (empty($j['phone']) || empty($j['apikey'])) return [false, 'WhatsApp n’est pas configuré.'];
    if (!function_exists('curl_init')) return [false, 'curl absent'];
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query(['phone' => $j['phone'], 'text' => $text, 'apikey' => $j['apikey']], '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'FLP-Facturation']);
    $b = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $e = curl_error($ch); curl_close($ch);
    $ok = $c === 200 && !preg_match('/error|invalid|not\s+activ|apikey/i', strip_tags($b));
    $msg = $ok ? 'Message envoyé.' : ('CallMeBot a répondu : ' . ($e ?: trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($b)), 0, 160)) ?: 'erreur ' . $c));
    $j['dernier'] = ['quand' => date('c'), 'ok' => $ok, 'msg' => $msg]; wa_save($j);
    return [$ok, $msg];
}
/** message « devis accepté » */
function wa_devis_accepte(array $d, array $client, array $t, string $nom): void {
    try {
        $j = wa_load(); if (empty($j['phone']) || empty($j['apikey']) || (isset($j['accepte']) && !$j['accepte'])) return;
        $eur = fn($n) => number_format((float)$n, 2, ',', ' ') . ' €';
        $lignes = [];
        foreach ((array)($d['lignes'] ?? []) as $l) { $x = trim((string)($l['designation'] ?? '')); if ($x !== '' && count($lignes) < 6) $lignes[] = '• ' . mb_substr($x, 0, 90); }
        $txt = "✅ Devis " . ($d['numero'] ?? '') . " accepté !\n\n"
             . "Client : " . ($client['nom'] ?? '—') . "\n"
             . (!empty($d['objet']) ? "Prestation : " . $d['objet'] . "\n" : '')
             . ($lignes ? implode("\n", $lignes) . "\n" : '')
             . "Montant : " . $eur($t['ttc']) . " TTC (" . $eur($t['ht']) . " HT)\n\n"
             . "Accepté en ligne par $nom, le " . date('d/m/Y à H\hi') . ".";
        wa_send($txt);
    } catch (Throwable $e) { error_log('WhatsApp : ' . $e->getMessage()); }
}
