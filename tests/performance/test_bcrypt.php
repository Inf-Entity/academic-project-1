<?php
foreach ([12, 10, 8, 6, 4] as $cost) {
    $h = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => $cost]);
    $t0 = microtime(true);
    for ($i = 0; $i < 10; $i++) {
        password_verify('Admin@123', $h);
    }
    $avg = round(((microtime(true) - $t0) / 10) * 1000, 2);
    echo "Cost {$cost}: avg {$avg} ms\n";
}
