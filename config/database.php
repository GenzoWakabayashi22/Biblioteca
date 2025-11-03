<?php
/**
 * Configurazione Database per Sistema Biblioteca
 * R∴ L∴ Kilwinning
 */

// Configurazione database
$db_config = [
    'host' => 'localhost',
    'username' => 'jmvvznbb_tornate_user',
    'password' => 'Puntorosso22',
    'database' => 'jmvvznbb_tornate_db',
    'charset' => 'utf8mb4',
    'port' => 3306
];

// Connessione al database
try {
    $conn = new mysqli(
        $db_config['host'],
        $db_config['username'], 
        $db_config['password'],
        $db_config['database'],
        $db_config['port']
    );

    // Imposta il charset
    $conn->set_charset($db_config['charset']);
    
    // Controlla la connessione
    if ($conn->connect_error) {
        throw new Exception("Connessione fallita: " . $conn->connect_error);
    }
    
    // Imposta il timezone
    $conn->query("SET time_zone = '+01:00'");
    
} catch (Exception $e) {
    // Log dell'errore (in produzione salvare su file)
    error_log("Errore database: " . $e->getMessage());
    
    // In caso di errore, mostra pagina di manutenzione
    if (!defined('DB_ERROR_HANDLED')) {
        define('DB_ERROR_HANDLED', true);
        http_response_code(503);
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Manutenzione - R∴ L∴ Kilwinning</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            </style>
        </head>
        <body class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-2xl shadow-2xl p-8 w-full max-w-md text-center">
                <div class="text-6xl mb-4">🔧</div>
                <h1 class="text-2xl font-bold text-gray-800 mb-4">Sistema in Manutenzione</h1>
                <p class="text-gray-600 mb-6">La biblioteca è temporaneamente non disponibile. Riprova tra qualche minuto.</p>
                <a href="/" class="inline-block bg-gradient-to-r from-blue-500 to-purple-600 text-white px-6 py-3 rounded-lg hover:opacity-90 transition">
                    🔄 Riprova
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

/**
 * Funzione per eseguire query preparate in modo sicuro
 */
function executeQuery($query, $params = [], $types = '') {
    global $conn;
    
    try {
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Errore preparazione query: " . $conn->error);
        }
        
        if (!empty($params)) {
            if (empty($types)) {
                // Auto-detect dei tipi
                $types = str_repeat('s', count($params));
            }
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        return $stmt;
        
    } catch (Exception $e) {
        error_log("Errore query: " . $e->getMessage());
        return false;
    }
}

/**
 * Funzione per ottenere un singolo risultato
 */
function getSingleResult($query, $params = [], $types = '') {
    $stmt = executeQuery($query, $params, $types);
    if (!$stmt) return false;
    
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Funzione per ottenere tutti i risultati
 */
function getAllResults($query, $params = [], $types = '') {
    $stmt = executeQuery($query, $params, $types);
    if (!$stmt) return [];
    
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Funzione per verificare se la connessione è attiva
 */
function isDatabaseConnected() {
    global $conn;
    return $conn && $conn->ping();
}

/**
 * Funzione di chiusura connessione (chiamata automaticamente)
 */
function closeDatabaseConnection() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
}

// Registra la funzione di chiusura da eseguire alla fine dello script
register_shutdown_function('closeDatabaseConnection');

?>