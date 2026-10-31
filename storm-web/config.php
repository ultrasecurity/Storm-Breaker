<?php
// config.php — credentials store. Managed by auth.php.
// One entry per user. 'hash' is a bcrypt hash; never store plaintext.
// Legacy 'password' (plaintext) is still accepted on read and auto-upgraded
// to 'hash' on the next successful login.
//
// Default credentials: admin / admin  (change them via the panel's settings).
$CONFIG = [
    'admin' => [
        'hash' => '$2y$10$rWRM/86rX63rwTadZAu1AeGyhH6ddlkKjaHMRRTiu8S78XDjg6aq2',
    ],
];
