<?php
/**
 * Genera hash bcrypt válido para usar en schema.sql
 * Uso: php bases/generar_hash.php
 */
$password = 'patuju2024';
$hash = password_hash($password, PASSWORD_BCRYPT);

echo "Password: $password\n";
echo "Hash:     $hash\n";
echo "Longitud: " . strlen($hash) . " (debe ser 60)\n";
echo "Verifica: " . (password_verify($password, $hash) ? '✅ SÍ' : '❌ NO') . "\n\n";

// Verificar el hash ACTUAL del schema
$hashViejo = '$2y$10$k2QdRrKJhz6nBqEX5YvFXOdG3HgJ8sL0mN2pQ4rS6tU8vW0xY2zA';
echo "--- Hash actual en schema ---\n";
echo "Hash:     $hashViejo\n";
echo "Longitud: " . strlen($hashViejo) . "\n";
echo "Verifica: " . (password_verify($password, $hashViejo) ? '✅ SÍ' : '❌ NO') . "\n";
