<?php
$raw = bin2hex(random_bytes(32));
echo "RAW:  " . $raw . PHP_EOL;
echo "HASH: " . hash('sha256', $raw) . PHP_EOL;