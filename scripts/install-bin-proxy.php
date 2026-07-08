<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$vendorBin = $root . '/vendor/bin';
$proxy = $vendorBin . '/agent-map';
$target = $root . '/bin/agent-map';

if (!is_dir($vendorBin) && !mkdir($vendorBin, 0o775, true) && !is_dir($vendorBin)) {
    fwrite(STDERR, "Unable to create vendor/bin\n");
    exit(1);
}

$relativeTarget = '../../bin/agent-map';
if (is_link($proxy) || is_file($proxy)) {
    unlink($proxy);
}

if (@symlink($relativeTarget, $proxy)) {
    exit(0);
}

$contents = "#!/usr/bin/env php\n<?php\nrequire __DIR__ . '/../../bin/agent-map';\n";
if (file_put_contents($proxy, $contents) === false) {
    fwrite(STDERR, "Unable to create vendor/bin/agent-map\n");
    exit(1);
}

chmod($proxy, 0o755);
