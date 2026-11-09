<?php
/* Relance automatique des devis sans réponse — à appeler une fois par jour (tâche planifiée de l'hébergeur) */
declare(strict_types=1);
define('FLP', 1);
require __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');
$key = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : (string)($_GET['cle'] ?? '');
if (!installed() || !hash_equals((string)(cfg()['cron_key'] ?? ''), $key)) { http_response_code(403); echo "Clé invalide\n"; exit; }
$sent = run_daily(true);
echo $sent ? 'Relances envoyées : ' . implode(', ', $sent) . "\n" : "Aucune relance à envoyer aujourd'hui.\n";
