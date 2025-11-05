<?php
/**
 * Configurazione Database per Sistema Biblioteca
 * R∴ L∴ Kilwinning
 */

/**
 * Carica variabili d'ambiente da file .env
 */
function loadEnv($path = __DIR__ . '/../.env') {
    if (!file_exists($path)) {
        throw new Exception("File .env non trovato. Copia .env.example in .env e configura le credenziali.");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora commenti
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse linea
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Rimuovi virgolette se presenti
            $value = trim($value, '"\'');

            // Imposta variabile d'ambiente
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Carica variabili d'ambiente
try {
    loadEnv();
} catch (Exception $e) {
    error_log("ERRORE CRITICO: " . $e->getMessage());
    die("Errore di configurazione. Contatta l'amministratore.");
}

// Configurazione database da variabili d'ambiente
$db_config = [
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'username' => $_ENV['DB_USERNAME'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'database' => $_ENV['DB_DATABASE'] ?? '',
    'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    'port' => (int)($_ENV['DB_PORT'] ?? 3306)
];

// Verifica che le credenziali siano state caricate
if (empty($db_config['username']) || empty($db_config['password']) || empty($db_config['database'])) {
    error_log("ERRORE CRITICO: Credenziali database non configurate nel file .env");
    die("Errore di configurazione database. Contatta l'amministratore.");
}

// IDs degli amministratori del sistema da variabili d'ambiente
// Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
$admin_ids_str = $_ENV['ADMIN_IDS'] ?? '16,9,12,11';
$admin_ids_array = array_map('intval', explode(',', $admin_ids_str));
define('ADMIN_IDS', $admin_ids_array);

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

/**
 * Restituisce connessione PDO per API che lo richiedono
 * (alcune API usano PDO invece di MySQLi)
 */
function getDBConnection() {
    static $pdo = null;

    if ($pdo === null) {
        global $db_config;

        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=%s",
            $db_config['host'],
            $db_config['port'],
            $db_config['database'],
            $db_config['charset']
        );

        try {
            $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Imposta timezone
            $pdo->exec("SET time_zone = '+01:00'");
        } catch (PDOException $e) {
            error_log("Errore connessione PDO: " . $e->getMessage());
            throw new Exception("Errore connessione database");
        }
    }

    return $pdo;
}

/**
 * Configura headers CORS sicuri basati su whitelist
 */
function configureCORS() {
    $allowed_origins_str = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
    $allowed_origins = array_filter(array_map('trim', explode(',', $allowed_origins_str)));

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Verifica se l'origin è nella whitelist
    if (!empty($origin) && in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    } elseif (in_array('*', $allowed_origins)) {
        // Solo se esplicitamente configurato (dev only)
        header('Access-Control-Allow-Origin: *');
    } else {
        // Nessun CORS header se origin non autorizzato
        // Questo blocca richieste da domini non autorizzati
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400'); // Cache preflight per 24 ore

    // Gestione preflight OPTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Verifica che la sessione sia attiva e non scaduta
 * Timeout configurabile da .env (default: 1800 secondi = 30 minuti)
 */
function verificaSessioneAttiva() {
    // Verifica autenticazione
    if (!isset($_SESSION['fratello_id'])) {
        header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../../index.php' : '../index.php'));
        exit;
    }

    // Timeout sessione da .env (default 30 minuti se non configurato)
    $session_timeout = (int)($_ENV['SESSION_TIMEOUT'] ?? 1800);

    // Verifica timeout sessione
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
        session_destroy();
        header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../../index.php?error=session_expired' : '../index.php?error=session_expired'));
        exit;
    }

    // Aggiorna timestamp ultima attività
    $_SESSION['last_activity'] = time();
    
    return true;
}

// Registra la funzione di chiusura da eseguire alla fine dello script
register_shutdown_function('closeDatabaseConnection');

?>