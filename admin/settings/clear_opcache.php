<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache cleared successfully\n";
} else {
    echo "OPCache is not enabled\n";
}
