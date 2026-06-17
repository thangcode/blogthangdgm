<?php
$json = file_get_contents('pagespeed2.json');
$d = json_decode($json, true);
echo "Score: " . ($d['lighthouseResult']['categories']['performance']['score'] ?? 'N/A') . "\n";
if (isset($d['lighthouseResult']['audits'])) {
    foreach($d['lighthouseResult']['audits'] as $k => $v) {
        if (isset($v['score']) && $v['score'] < 0.9 && $v['score'] !== null) {
            echo "- $k: " . ($v['displayValue'] ?? '') . " (Score: {$v['score']})\n";
            if (isset($v['details']['items'])) {
                $count = 0;
                foreach($v['details']['items'] as $item) {
                    if ($count++ > 3) break;
                    if (isset($item['url'])) echo "   * " . substr($item['url'], 0, 80) . "...\n";
                    if (isset($item['node']['snippet'])) echo "   * Node: " . substr($item['node']['snippet'], 0, 50) . "...\n";
                }
            }
        }
    }
}
