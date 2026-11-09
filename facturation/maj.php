<?php
/* Fabrice Lau Production — Facturation : mise à jour depuis GitHub (dépôt fabricelau/flp-facturation).
   Le dépôt contient un dossier « facturation/ » : c'est lui qui est copié ici.
   Le dossier data/ (factures, clients, réglages, mots de passe) n'est jamais touché.
   Avant chaque mise à jour, le code actuel est sauvegardé ; au moindre échec il est remis. */
declare(strict_types=1);
if (!defined('FLP')) { http_response_code(403); exit; }

const MAJ_REPO = 'fabricelau/flp-facturation';
const MAJ_BRANCH = 'main';
const MAJ_KEEP = ['index.php', 'lib.php', 'api.php', 'app.html', 'maj.php'];   // fichiers indispensables

function maj_version(): string { $f = DATA_DIR . '/version.txt'; return is_file($f) ? trim((string)file_get_contents($f)) : ''; }

function maj_http(string $url, ?string $saveTo = null, int $timeout = 20): array {
    if (!function_exists('curl_init')) return ['code' => 0, 'err' => 'curl absent', 'body' => ''];
    $ch = curl_init($url); $fp = null;
    $opt = [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'git/2.40 FLP-Facturation', CURLOPT_SSL_VERIFYPEER => true];
    if ($saveTo) { $fp = fopen($saveTo, 'wb'); $opt[CURLOPT_FILE] = $fp; } else $opt[CURLOPT_RETURNTRANSFER] = true;
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($fp) fclose($fp);
    return ['code' => $code, 'err' => $err, 'body' => is_string($body) ? $body : ''];
}

/** Dernière version publiée (lue par le protocole git : pas de limite d'appels de l'API GitHub). */
function maj_check(): array {
    $r = maj_http('https://github.com/' . MAJ_REPO . '.git/info/refs?service=git-upload-pack', null, 15);
    if ($r['code'] === 404 || $r['code'] === 401) return ['ok' => false, 'msg' => 'Dépôt GitHub introuvable.'];
    if ($r['code'] !== 200) return ['ok' => false, 'msg' => 'GitHub ne répond pas (' . ($r['code'] ?: $r['err']) . ').'];
    if (!preg_match('~([0-9a-f]{40}) refs/heads/' . preg_quote(MAJ_BRANCH, '~') . '\b~', $r['body'], $m)) return ['ok' => false, 'msg' => 'Aucune version publiée pour l’instant.'];
    $cur = maj_version();
    return ['ok' => true, 'current' => $cur, 'latest' => substr($m[1], 0, 12), 'sha' => $m[1], 'up_to_date' => $cur !== '' && strncmp($m[1], $cur, strlen($cur)) === 0];
}

function maj_rrmdir(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f)
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}
/** Fichiers de code (tout sauf data/ et les fichiers de travail). */
function maj_files(string $root): array {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($rel === 'data' || strncmp($rel, 'data/', 5) === 0 || preg_match('~\.(maj|bak)$|\.bak-~', $rel)) continue;
        $out[] = $rel;
    }
    return $out;
}

function maj_run(): array {
    @set_time_limit(180);
    if (!class_exists('ZipArchive')) return ['ok' => false, 'msg' => 'ZIP indisponible sur le serveur.'];
    $chk = maj_check();
    if (!$chk['ok']) return $chk;
    if ($chk['up_to_date']) return ['ok' => true, 'msg' => 'Déjà à jour.', 'version' => $chk['current']];
    $sha = $chk['sha'];
    $work = DATA_DIR . '/maj-' . date('His') . '-' . bin2hex(random_bytes(3));
    @mkdir($work, 0700, true);
    $zip = $work . '/code.zip';
    $r = maj_http('https://codeload.github.com/' . MAJ_REPO . '/zip/' . $sha, $zip, 120);
    if ($r['code'] !== 200 || !is_file($zip) || filesize($zip) < 1000) { maj_rrmdir($work); return ['ok' => false, 'msg' => 'Téléchargement impossible (' . ($r['code'] ?: $r['err']) . ').']; }
    $z = new ZipArchive();
    if ($z->open($zip) !== true) { maj_rrmdir($work); return ['ok' => false, 'msg' => 'Archive illisible.']; }
    $z->extractTo($work . '/x'); $z->close();
    $top = glob($work . '/x/*', GLOB_ONLYDIR);
    $src = $top ? $top[0] . '/facturation' : '';
    foreach (MAJ_KEEP as $f) if (!$src || !is_file("$src/$f")) { maj_rrmdir($work); return ['ok' => false, 'msg' => 'Le dépôt ne contient pas d’appli valide (fichier manquant : ' . $f . ').']; }

    /* 1. sauvegarde du code actuel : sans elle, on n'installe rien */
    $before = maj_files(__DIR__);
    $bk = DATA_DIR . '/sauvegardes/code-' . date('Y-m-d-His') . '-' . (maj_version() ?: 'v1') . '.zip';
    if (!is_dir(dirname($bk))) @mkdir(dirname($bk), 0750, true);
    $b = new ZipArchive();
    $ok = $b->open($bk, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true;
    if ($ok) { foreach ($before as $rel) $ok = $ok && $b->addFile(__DIR__ . '/' . $rel, $rel); $ok = $b->close() && $ok; }
    if (!$ok || !is_file($bk)) { @unlink($bk); maj_rrmdir($work); return ['ok' => false, 'msg' => 'Sauvegarde de la version actuelle impossible : rien n’a été installé.']; }
    $old = glob(DATA_DIR . '/sauvegardes/code-*.zip') ?: []; sort($old);
    while (count($old) > 8) @unlink(array_shift($old));

    /* 2. copie (fichier temporaire puis renommage) ; au moindre échec, l'ancienne version est remise */
    $incoming = maj_files($src); $n = 0;
    foreach ($incoming as $rel) {
        if (strpos($rel, '..') !== false) continue;
        $dst = __DIR__ . '/' . $rel;
        if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
        $tmp = $dst . '.maj';
        if (!@copy("$src/$rel", $tmp) || !@rename($tmp, $dst)) {
            @unlink($tmp); maj_restore($bk, $before); maj_rrmdir($work);
            return ['ok' => false, 'msg' => "Copie impossible ($rel) : l’ancienne version a été remise."];
        }
        @chmod($dst, 0644); $n++;
    }
    /* 3. fichiers de code retirés du dépôt : retirés aussi (jamais rien dans data/) */
    $keep = array_flip($incoming);
    foreach ($before as $rel) if (!isset($keep[$rel]) && preg_match('~\.(php|js|css|html|png|svg|ttf|json|txt)$|(^|/)\.htaccess$~', $rel)) @unlink(__DIR__ . '/' . $rel);
    file_put_contents(DATA_DIR . '/version.txt', substr($sha, 0, 12));
    maj_rrmdir($work);
    if (function_exists('opcache_reset')) @opcache_reset();
    return ['ok' => true, 'msg' => "Mise à jour installée ($n fichiers).", 'version' => substr($sha, 0, 12)];
}

function maj_restore(string $bk, array $before): void {
    $z = new ZipArchive();
    if ($z->open($bk) === true) { $z->extractTo(__DIR__); $z->close(); }
    $was = array_flip($before);
    foreach (maj_files(__DIR__) as $rel) if (substr($rel, -4) === '.maj' || !isset($was[$rel])) @unlink(__DIR__ . '/' . $rel);
}

/** Vérification automatique, au plus toutes les 15 minutes, quand l'appli est ouverte (après l'envoi de la réponse). */
function maj_auto(): void {
    $etat = DATA_DIR . '/maj.json';
    $e = is_file($etat) ? (json_decode((string)file_get_contents($etat), true) ?: []) : [];
    if (time() - (int)($e['vu'] ?? 0) < 900) return;
    $lock = @fopen(DATA_DIR . '/maj.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $e['vu'] = time(); file_put_contents($etat, json_encode($e));
    @ignore_user_abort(true);
    $chk = maj_check();
    if ($chk['ok'] && !$chk['up_to_date']) {
        $r = maj_run();
        $e['dernier'] = ['quand' => date('c'), 'ok' => $r['ok'], 'msg' => $r['msg'], 'version' => $r['version'] ?? ''];
        file_put_contents($etat, json_encode($e, JSON_UNESCAPED_UNICODE));
    }
    flock($lock, LOCK_UN); fclose($lock);
}
function maj_etat(): array {
    $etat = DATA_DIR . '/maj.json';
    $e = is_file($etat) ? (json_decode((string)file_get_contents($etat), true) ?: []) : [];
    return ['version' => maj_version(), 'dernier' => $e['dernier'] ?? null, 'depot' => MAJ_REPO];
}
