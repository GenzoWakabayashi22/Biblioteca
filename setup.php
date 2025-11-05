#!/usr/bin/env php
<?php
/**
 * Script di Setup Rapido per Sistema Biblioteca
 * Crea il file .env e verifica la connessione database
 */

echo "=== SETUP SISTEMA BIBLIOTECA ===\n\n";

$root_dir = __DIR__;
$env_file = $root_dir . '/.env';
$env_example = $root_dir . '/.env.example';

// Step 1: Verifica se .env esiste
echo "Step 1: Verifica file .env...\n";

if (file_exists($env_file)) {
    echo "✅ File .env già esistente\n";
    $response = readline("Vuoi sovrascriverlo? (y/N): ");
    if (strtolower(trim($response)) !== 'y') {
        echo "ℹ️ Mantengo .env esistente\n\n";
        goto test_connection;
    }
}

// Step 2: Copia .env.example in .env
echo "\nStep 2: Creazione file .env...\n";

if (!file_exists($env_example)) {
    die("❌ ERRORE: File .env.example non trovato!\n");
}

if (copy($env_example, $env_file)) {
    echo "✅ File .env creato con successo\n";
    chmod($env_file, 0600); // Permessi restrittivi
    echo "✅ Permessi impostati a 600 (solo owner)\n\n";
} else {
    die("❌ ERRORE: Impossibile creare file .env\n");
}

echo "📝 NOTA: Modifica .env con le credenziali corrette se necessario\n";
echo "   nano .env\n\n";

test_connection:

// Step 3: Test connessione database
echo "Step 3: Test connessione database...\n";

// Carica .env
$env_vars = [];
$lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $env_vars[trim($key)] = trim($value, '"\'');
    }
}

// Test connessione
try {
    $conn = new mysqli(
        $env_vars['DB_HOST'] ?? 'localhost',
        $env_vars['DB_USERNAME'] ?? '',
        $env_vars['DB_PASSWORD'] ?? '',
        $env_vars['DB_DATABASE'] ?? '',
        (int)($env_vars['DB_PORT'] ?? 3306)
    );

    if ($conn->connect_error) {
        throw new Exception("Connessione fallita: " . $conn->connect_error);
    }

    echo "✅ Connessione database riuscita!\n";
    echo "   Database: {$env_vars['DB_DATABASE']}\n";
    echo "   Host: {$env_vars['DB_HOST']}\n\n";

    // Verifica tabelle principali
    echo "Step 4: Verifica tabelle database...\n";

    $required_tables = ['fratelli', 'libri', 'categorie_libri', 'storico_prestiti'];
    $missing_tables = [];

    foreach ($required_tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows === 0) {
            $missing_tables[] = $table;
            echo "⚠️ Tabella '$table' non trovata\n";
        } else {
            echo "✅ Tabella '$table' presente\n";
        }
    }

    if (!empty($missing_tables)) {
        echo "\n⚠️ WARNING: Alcune tabelle mancanti. Verifica il database.\n";
    } else {
        echo "\n✅ Tutte le tabelle principali presenti!\n";
    }

    // Verifica colonne nuove
    echo "\nStep 5: Verifica colonne nuove aggiunte...\n";

    $columns_to_check = [
        'fratelli' => ['password_hash', 'role'],
        'liste_lettura' => ['icona', 'colore']
    ];

    foreach ($columns_to_check as $table => $columns) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            foreach ($columns as $column) {
                $check = $conn->query("SHOW COLUMNS FROM $table LIKE '$column'");
                if ($check->num_rows === 0) {
                    echo "⚠️ Colonna '$column' mancante in tabella '$table'\n";
                    echo "   Esegui migrate_passwords.php e migrate_admin_roles.php\n";
                } else {
                    echo "✅ Colonna '$table.$column' presente\n";
                }
            }
        }
    }

    $conn->close();

} catch (Exception $e) {
    echo "❌ ERRORE: " . $e->getMessage() . "\n";
    echo "\nVerifica le credenziali nel file .env\n";
    exit(1);
}

echo "\n=== SETUP COMPLETATO ===\n";
echo "\n📋 PROSSIMI PASSI:\n";
echo "1. Se le colonne password_hash/role sono mancanti:\n";
echo "   php migrate_passwords.php\n";
echo "   php migrate_admin_roles.php\n\n";
echo "2. Crea directory logs:\n";
echo "   mkdir -p logs && chmod 755 logs\n\n";
echo "3. Accedi al sistema:\n";
echo "   Apri nel browser: http://yourdomain.com/\n\n";
echo "✅ Sistema pronto!\n";
?>
