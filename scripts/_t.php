<?php
$h = @file_get_contents('http://127.0.0.1:8128/index.php', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]));
$code = (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) ? $m[1] : '?';
echo "INDEX HTTP $code\n";
echo "grid 2-col rule    : " . (strpos($h, 'grid-template-columns: repeat(2, 1fr)') !== false ? 'YES' : 'NO') . "\n";
echo "rank rows          : " . substr_count($h, 'hot-row hot-row--') . "\n";
$iss = [];
foreach (['Fatal error', 'Parse error', '<b>Warning</b>', 'Uncaught'] as $mk) if (stripos($h, $mk) !== false) $iss[] = $mk;
echo "PHP issues         : " . ($iss ? implode(',', $iss) : 'NONE') . "\n";
