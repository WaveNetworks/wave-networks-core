<?php
// Copy to config/p3sig.php (gitignored) to load this app's service keys from p3sig.
// See include/common/runtimeSecretFunctions.php. Both files below live OUTSIDE public_html.
return [
    'url'        => 'https://p3sig.com/p3sig/api/index.php',
    'machine_id' => 'paste the Machine ID from p3sig.com',
    'key_file'   => __DIR__ . '/../../../p3sig/machine.key',    // base64 Ed25519 key from `p3sig init`
    'cache_file' => __DIR__ . '/../../../p3sig/p3sig-cache.json',
    'bundle'     => '',                                           // optional: only this bundle
];
