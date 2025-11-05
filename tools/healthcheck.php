<?php
/**
 * Healthcheck Tool - Sistema Biblioteca
 * R∴ L∴ Kilwinning
 * 
 * ATTENZIONE: Questo file è per diagnostica. 
 * NON esporre in produzione senza autenticazione adeguata.
 * Da rimuovere o proteggere prima del deploy in produzione.
 */

// Impedisci accesso se non in sviluppo (opzionale)
// if ($_ENV['APP_ENV'] !== 'development') {
//     http_response_code(404);
//     die('Not Found');
// }

session_start();

// Header JSON
header('Content-Type: application/json');

$checks = [];
$overall_status = 'ok';

// 1. Verifica inclusione config/database.php
try {
    require_once __DIR__ . '/../config/database.php';
    $checks['config_loaded'] = ['status' => 'ok', 'message' => 'Config database.php caricato correttamente'];
} catch (Exception $e) {
    $checks['config_loaded'] = ['status' => 'error', 'message' => 'Errore caricamento config: ' . $e->getMessage()];
    $overall_status = 'error';
}

// 2. Verifica esistenza funzioni critiche
$required_functions = [
    'configureSecurityHeaders',
    'generateCSRFToken',
    'validateCSRFToken',
    'verifyCSRFToken',
    'getAllResults',
    'getSingleResult',
    'verificaSessioneAttiva',
    'db',
    'executeQuery',
    'getDBConnection',
    'isAdmin',
    'isGuest',
    'getUserRole'
];

$missing_functions = [];
foreach ($required_functions as $func) {
    if (!function_exists($func)) {
        $missing_functions[] = $func;
    }
}

if (empty($missing_functions)) {
    $checks['required_functions'] = ['status' => 'ok', 'message' => 'Tutte le funzioni richieste sono definite'];
} else {
    $checks['required_functions'] = [
        'status' => 'error', 
        'message' => 'Funzioni mancanti: ' . implode(', ', $missing_functions)
    ];
    $overall_status = 'error';
}

// 3. Verifica connessione database
try {
    $conn_test = db();
    if ($conn_test->ping()) {
        $checks['database_connection'] = ['status' => 'ok', 'message' => 'Connessione database attiva'];
        
        // Test query SELECT 1
        $result = $conn_test->query("SELECT 1 as test");
        if ($result && $result->fetch_assoc()['test'] == 1) {
            $checks['database_query'] = ['status' => 'ok', 'message' => 'Query test eseguita con successo'];
        } else {
            $checks['database_query'] = ['status' => 'warning', 'message' => 'Query test fallita'];
            $overall_status = $overall_status === 'ok' ? 'warning' : $overall_status;
        }
    } else {
        $checks['database_connection'] = ['status' => 'error', 'message' => 'Connessione database non risponde'];
        $overall_status = 'error';
    }
} catch (Exception $e) {
    $checks['database_connection'] = ['status' => 'error', 'message' => 'Errore connessione: ' . $e->getMessage()];
    $overall_status = 'error';
}

// 4. Verifica file .env
if (file_exists(__DIR__ . '/../.env')) {
    $checks['env_file'] = ['status' => 'ok', 'message' => 'File .env presente'];
} else {
    $checks['env_file'] = ['status' => 'warning', 'message' => 'File .env non trovato, usando fallback'];
    $overall_status = $overall_status === 'ok' ? 'warning' : $overall_status;
}

// 5. Verifica tabelle database critiche
$required_tables = ['fratelli', 'libri', 'categorie_libri', 'recensioni_libri', 'storico_prestiti'];
$missing_tables = [];

try {
    $conn_test = db();
    foreach ($required_tables as $table) {
        $result = $conn_test->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows === 0) {
            $missing_tables[] = $table;
        }
    }
    
    if (empty($missing_tables)) {
        $checks['database_tables'] = ['status' => 'ok', 'message' => 'Tutte le tabelle richieste esistono'];
    } else {
        $checks['database_tables'] = [
            'status' => 'warning', 
            'message' => 'Tabelle mancanti: ' . implode(', ', $missing_tables)
        ];
        $overall_status = $overall_status === 'ok' ? 'warning' : $overall_status;
    }
} catch (Exception $e) {
    $checks['database_tables'] = ['status' => 'error', 'message' => 'Impossibile verificare tabelle: ' . $e->getMessage()];
}

// 6. Verifica configurazione PHP
$checks['php_version'] = [
    'status' => version_compare(PHP_VERSION, '7.4.0') >= 0 ? 'ok' : 'warning',
    'message' => 'PHP version: ' . PHP_VERSION
];

$checks['session_status'] = [
    'status' => session_status() === PHP_SESSION_ACTIVE ? 'ok' : 'warning',
    'message' => 'Session status: ' . (session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive')
];

// 7. Verifica rate limiter
if (file_exists(__DIR__ . '/../config/rate_limiter.php')) {
    $checks['rate_limiter'] = ['status' => 'ok', 'message' => 'Rate limiter configurato'];
} else {
    $checks['rate_limiter'] = ['status' => 'warning', 'message' => 'Rate limiter non trovato'];
}

// 8. Test CSRF Token
try {
    $token = generateCSRFToken();
    if (!empty($token)) {
        $checks['csrf_token'] = ['status' => 'ok', 'message' => 'CSRF token generato correttamente'];
    } else {
        $checks['csrf_token'] = ['status' => 'error', 'message' => 'CSRF token vuoto'];
        $overall_status = 'error';
    }
} catch (Exception $e) {
    $checks['csrf_token'] = ['status' => 'error', 'message' => 'Errore generazione CSRF token: ' . $e->getMessage()];
    $overall_status = 'error';
}

// Response finale
$response = [
    'status' => $overall_status,
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => $checks,
    'summary' => [
        'total_checks' => count($checks),
        'passed' => count(array_filter($checks, function($c) { return $c['status'] === 'ok'; })),
        'warnings' => count(array_filter($checks, function($c) { return $c['status'] === 'warning'; })),
        'errors' => count(array_filter($checks, function($c) { return $c['status'] === 'error'; }))
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>
