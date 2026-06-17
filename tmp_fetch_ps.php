<?php
$url = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=https://www.thang-dgm.com/&strategy=mobile";
$json = file_get_contents($url);
file_put_contents('ps_mobile.json', $json);
$d = json_decode($json, true);
echo "Score: " . ($d['lighthouseResult']['categories']['performance']['score'] ?? 'N/A') . "\n";
if (isset($d['lighthouseResult']['audits'])) {
    foreach($d['lighthouseResult']['audits'] as $k => $v) {
        if (isset($v['score']) && $v['score'] < 0.9 && $v['score'] !== null) {
            echo "- $k: " . ($v['displayValue'] ?? '') . " (Score: {$v['score']})\n";
        }
    }
}
