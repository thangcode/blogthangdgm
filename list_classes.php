<?php
$html = file_get_contents('test_output.html');
preg_match_all('/class="([^"]+)"/', $html, $matches);
$classes = array_unique($matches[1]);
foreach($classes as $c) {
    echo $c . "\n";
}
