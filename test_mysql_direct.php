<?php
/**
 * Test connessione MySQL diretta - prova diverse configurazioni
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Connessione MySQL</h1>\n<pre>\n";

$configs = [
    ['host' => 'localhost', 'port' => 3306],
    ['host' => '127.0.0.1', 'port' => 3306],
    ['host' => 'localhost', 'port' => 3307],
    ['host' => '127.0.0.1', 'port' => 3307],
];

$username = 'jmvvznbb_tornate_user';
$password = 'Puntorosso22';
$database = 'jmvvznbb_tornate_db';

foreach ($configs as $config) {
    echo "Provo connessione:\n";
    echo "  Host: {$config['host']}\n";
    echo "  Port: {$config['port']}\n";
    echo "  User: $username\n";

    try {
        $conn = new mysqli($config['host'], $username, $password, $database, $config['port']);

        if ($conn->connect_error) {
            echo "  ❌ Errore: " . $conn->connect_error . "\n";
            echo "  Codice: " . $conn->connect_errno . "\n";
        } else {
            echo "  ✅ CONNESSIONE RIUSCITA!\n";
            echo "  MySQL Version: " . $conn->server_info . "\n";

            // Test query
            $result = $conn->query("SELECT COUNT(*) as cnt FROM fratelli");
            if ($result) {
                $row = $result->fetch_assoc();
                echo "  ✅ Test query OK: {$row['cnt']} fratelli nel database\n";
            }

            $conn->close();
            echo "\n✅✅✅ USA QUESTA CONFIGURAZIONE! ✅✅✅\n";
            echo "DB_HOST={$config['host']}\n";
            echo "DB_PORT={$config['port']}\n";
            break;
        }
    } catch (Exception $e) {
        echo "  ❌ Exception: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

echo "</pre>";
?>
