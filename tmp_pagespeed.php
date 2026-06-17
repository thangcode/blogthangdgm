<?php
$json = file_get_contents('pagespeed.json');
$d = json_decode($json, true);
echo "Score: " . ($d['lighthouseResult']['categories']['performance']['score'] ?? 'N/A') . "\n";
if (isset($d['lighthouseResult']['audits'])) {
    foreach($d['lighthouseResult']['audits'] as $k => $v) {
        if (isset($v['score']) && $v['score'] < 0.9 && $v['score'] !== null) {
            echo "- $k: " . ($v['displayValue'] ?? '') . " (Score: {$v['score']})\n";
        }
    }
}
