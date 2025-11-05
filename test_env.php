<?php
/**
 * Test caricamento .env
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Caricamento .env</h1>\n";
echo "<pre>\n";

// Verifica che il file .env esista
$env_path = __DIR__ . '/.env';
echo "Path .env: $env_path\n";
echo "Esiste: " . (file_exists($env_path) ? "✅ SI" : "❌ NO") . "\n";
echo "Leggibile: " . (is_readable($env_path) ? "✅ SI" : "❌ NO") . "\n\n";

if (file_exists($env_path)) {
    echo "Contenuto .env:\n";
    echo "==================\n";
    $lines = file($env_path);
    foreach ($lines as $line) {
        // Nascondi password
        if (strpos($line, 'PASSWORD') !== false) {
            echo "DB_PASSWORD=***\n";
        } else {
            echo htmlspecialchars($line);
        }
    }
    echo "==================\n\n";
}

// Prova a caricare database.php
echo "Tentativo di caricare config/database.php...\n";
try {
    require_once __DIR__ . '/config/database.php';
    echo "✅ Config caricato con successo!\n\n";

    echo "Variabili d'ambiente:\n";
    echo "  DB_HOST: " . ($_ENV['DB_HOST'] ?? 'NON DEFINITO') . "\n";
    echo "  DB_USERNAME: " . ($_ENV['DB_USERNAME'] ?? 'NON DEFINITO') . "\n";
    echo "  DB_DATABASE: " . ($_ENV['DB_DATABASE'] ?? 'NON DEFINITO') . "\n";
    echo "  CORS_ALLOWED_ORIGINS: " . ($_ENV['CORS_ALLOWED_ORIGINS'] ?? 'NON DEFINITO') . "\n\n";

    // Test connessione
    echo "Test connessione database:\n";
    if (isset($conn) && $conn->ping()) {
        echo "✅ Connessione MySQLi OK!\n";
    } else {
        echo "❌ Connessione MySQLi FALLITA!\n";
        if (isset($conn)) {
            echo "Errore: " . $conn->error . "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ ERRORE: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>
