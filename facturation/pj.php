<?php
/* Fabrice Lau Production — Facturation : pièces jointes des devis (moodboard, plan de tournage, exemples…).
   Fichiers dans data/pj/<id du devis>/ (dossier protégé), liste dans index.json.
   Visibles par le client sur le lien de son devis. */
declare(strict_types=1);
if (!defined('FLP')) { http_response_code(403); exit; }

const PJ_MAX = 20 * 1024 * 1024;
const PJ_TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime'];

function pj_dir(string $docId): string { return DATA_DIR . '/pj/' . preg_replace('/[^A-Za-z0-9_-]/', '', $docId); }
function pj_list(string $docId): array {
    $f = pj_dir($docId) . '/index.json';
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}
function pj_save_list(string $docId, array $l): void {
    $dir = pj_dir($docId);
    if (!$l) { foreach (glob($dir . '/*') ?: [] as $x) @unlink($x); @rmdir($dir); return; }
    atomic_write($dir . '/index.json', json_encode(array_values($l), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function pj_add(string $docId, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = (int)($file['error'] ?? 4);
        throw new RuntimeException($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE ? 'Fichier trop lourd pour le serveur (limite : ' . ini_get('upload_max_filesize') . ').' : 'Le fichier n’a pas été reçu (erreur ' . $e . ').');
    }
    $nom = trim(preg_replace('/[\x00-\x1f\/\\\\]+/', ' ', (string)($file['name'] ?? 'fichier')));
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (!isset(PJ_TYPES[$ext])) throw new RuntimeException('Type de fichier non accepté. Formats possibles : PDF, JPG, PNG, WEBP, GIF, MP4, MOV.');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > PJ_MAX) throw new RuntimeException('Fichier trop lourd (20 Mo maximum).');
    if (!is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('Envoi invalide.');
    /* contrôle du contenu réel */
    $head = (string)file_get_contents((string)$file['tmp_name'], false, null, 0, 16);
    $okSig = ['pdf' => str_starts_with($head, '%PDF'), 'jpg' => str_starts_with($head, "\xFF\xD8\xFF"), 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
        'png' => str_starts_with($head, "\x89PNG"), 'gif' => str_starts_with($head, 'GIF8'), 'webp' => substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP',
        'mp4' => substr($head, 4, 4) === 'ftyp', 'mov' => in_array(substr($head, 4, 4), ['ftyp', 'moov', 'wide', 'mdat', 'free'], true)][$ext];
    if (!$okSig) throw new RuntimeException('Ce fichier ne correspond pas à son extension (.' . $ext . ').');
    ensure_data_dir();
    $dir = pj_dir($docId); if (!is_dir($dir)) mkdir($dir, 0750, true);
    $id = bin2hex(random_bytes(8));
    if (!move_uploaded_file((string)$file['tmp_name'], "$dir/$id.$ext")) throw new RuntimeException('Enregistrement du fichier impossible.');
    @chmod("$dir/$id.$ext", 0640);
    $l = pj_list($docId);
    $l[] = ['id' => $id, 'nom' => mb_substr($nom, 0, 120), 'ext' => $ext, 'taille' => $size, 'le' => date('c')];
    pj_save_list($docId, $l);
    return $l;
}
function pj_del(string $docId, string $id): array {
    $l = pj_list($docId); $out = [];
    foreach ($l as $x) { if ($x['id'] === $id) @unlink(pj_dir($docId) . '/' . $x['id'] . '.' . $x['ext']); else $out[] = $x; }
    pj_save_list($docId, $out);
    return $out;
}
/** envoie le fichier au navigateur (affichage dans le navigateur, téléchargement possible) */
function pj_send(string $docId, string $id): void {
    foreach (pj_list($docId) as $x) {
        if ($x['id'] !== $id) continue;
        $p = pj_dir($docId) . '/' . $x['id'] . '.' . $x['ext'];
        if (!is_file($p)) break;
        header('Content-Type: ' . PJ_TYPES[$x['ext']]);
        header('Content-Length: ' . filesize($p));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($x['nom']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, max-age=3600');
        readfile($p); exit;
    }
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Fichier introuvable.'; exit;
}
function pj_size(int $b): string { return $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' Mo' : max(1, (int)round($b / 1024)) . ' Ko'; }
