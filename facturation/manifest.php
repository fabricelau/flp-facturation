<?php
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode(['name' => 'Facturation Fabrice Lau Production', 'short_name' => 'Facturation', 'start_url' => './', 'scope' => './', 'display' => 'standalone',
  'background_color' => '#16171B', 'theme_color' => '#16171B', 'lang' => 'fr',
  'icons' => [['src' => 'assets/icone-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => 'assets/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
