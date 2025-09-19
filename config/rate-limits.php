<?php
// config/rate-limits.php (new file)

return [
    'authoring' => [
        'max_attempts' => 30,
        'decay_minutes' => 1,
    ],
    'search' => [
        'max_attempts' => 100,
        'decay_minutes' => 1,
    ],
    'storage' => [
        'max_attempts' => 10,
        'decay_minutes' => 1,
    ],
];

