<?php
// TEMPORARY diagnostic — DELETE after use.
// Reports whether the Authorization header survives the journey to PHP.
// Deliberately shows only presence + length, never the value itself.
header('Content-Type: application/json');
echo json_encode([
    'authorization_present' => isset($_SERVER['HTTP_AUTHORIZATION']),
    'authorization_length'  => strlen($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
]);