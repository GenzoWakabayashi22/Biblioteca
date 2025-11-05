<?php
/**
 * Test di connessione database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Connessione Database</h1>\n";
echo "<pre>\n";

// Include la configurazione
require_once 'config/database.php';

echo "✅ File di configurazione caricato\n\n";

// Test variabili d'ambiente
echo "📋 Variabili d'ambiente caricate:\n";
echo "  DB_HOST: " . ($_ENV['DB_HOST'] ?? 'NON DEFINITO') . "\n";
echo "  DB_USERNAME: " . (isset($_ENV['DB_USERNAME']) ? '***' : 'NON DEFINITO') . "\n";
echo "  DB_PASSWORD: " . (isset($_ENV['DB_PASSWORD']) ? '***' : 'NON DEFINITO') . "\n";
echo "  DB_DATABASE: " . ($_ENV['DB_DATABASE'] ?? 'NON DEFINITO') . "\n";
echo "  DB_PORT: " . ($_ENV['DB_PORT'] ?? 'NON DEFINITO') . "\n\n";

// Test connessione MySQLi
echo "🔌 Test connessione MySQLi:\n";
if (isDatabaseConnected()) {
    echo "  ✅ Connessione MySQLi attiva\n";

    // Test query
    $result = $conn->query("SELECT COUNT(*) as total FROM fratelli");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "  ✅ Query test riuscita: {$row['total']} fratelli nel database\n";
    }
} else {
    echo "  ❌ Connessione MySQLi fallita\n";
}
echo "\n";

// Test connessione PDO
echo "🔌 Test connessione PDO:\n";
try {
    $pdo = getDBConnection();
    echo "  ✅ Connessione PDO attiva\n";

    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM libri");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  ✅ Query test riuscita: {$row['total']} libri nel database\n";
} catch (Exception $e) {
    echo "  ❌ Connessione PDO fallita: " . $e->getMessage() . "\n";
}
echo "\n";

// Test funzioni helper
echo "🧪 Test funzioni helper:\n";
$test_query = "SELECT nome FROM fratelli WHERE id = ? LIMIT 1";
$test_result = getSingleResult($test_query, [1], 'i');
if ($test_result) {
    echo "  ✅ getSingleResult() funziona: {$test_result['nome']}\n";
} else {
    echo "  ⚠️ getSingleResult() non ha restituito risultati\n";
}
echo "\n";

// Test security headers
echo "🛡️ Test configurazione sicurezza:\n";
echo "  ADMIN_IDS: " . (defined('ADMIN_IDS') ? implode(', ', ADMIN_IDS) : 'NON DEFINITO') . "\n";
echo "  SESSION_TIMEOUT: " . ($_ENV['SESSION_TIMEOUT'] ?? 'NON DEFINITO') . " secondi\n";
echo "  CORS_ALLOWED_ORIGINS: " . ($_ENV['CORS_ALLOWED_ORIGINS'] ?? 'NON DEFINITO') . "\n";
echo "\n";

echo "✅ Test completati!\n";
echo "</pre>";
?>
