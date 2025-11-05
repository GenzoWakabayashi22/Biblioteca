<?php
/**
 * Script di Migrazione Ruoli Admin
 * Sposta Admin IDs da hardcoded nel codice a colonna nel database
 *
 * IMPORTANTE: Esegui questo script UNA SOLA VOLTA
 * Backup del database prima di eseguire!
 */

require_once 'config/database.php';

echo "=== MIGRAZIONE RUOLI ADMIN A DATABASE ===\n\n";

// Step 1: Aggiungi colonna role se non esiste
echo "Step 1: Verifica/Aggiunta colonna role...\n";

$check_column = $conn->query("SHOW COLUMNS FROM fratelli LIKE 'role'");
if ($check_column->num_rows == 0) {
    $add_column = $conn->query("
        ALTER TABLE fratelli
        ADD COLUMN role ENUM('user', 'admin', 'guest') DEFAULT 'user' AFTER attivo
    ");

    if ($add_column) {
        echo "✅ Colonna role aggiunta con successo\n\n";
    } else {
        die("❌ ERRORE: Impossibile aggiungere colonna role: " . $conn->error . "\n");
    }
} else {
    echo "✅ Colonna role già esistente\n\n";
}

// Step 2: Imposta ruoli per admin esistenti
echo "Step 2: Impostazione ruoli admin esistenti...\n";

// Admin IDs da config originale
$admin_ids = [16, 9, 12, 11];
$admin_names = [
    'Paolo Giulio Gazzano',
    'Luca Guiducci',
    'Emiliano Menicucci',
    'Francesco Ropresti'
];

$success_count = 0;
$error_count = 0;

// Imposta admin per ID
foreach ($admin_ids as $admin_id) {
    $stmt = $conn->prepare("UPDATE fratelli SET role = 'admin' WHERE id = ?");
    $stmt->bind_param('i', $admin_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        // Recupera nome
        $check = $conn->query("SELECT nome FROM fratelli WHERE id = $admin_id");
        $row = $check->fetch_assoc();
        echo "✅ [ID: $admin_id] {$row['nome']} -> Admin\n";
        $success_count++;
    }

    $stmt->close();
}

// Imposta admin per nome (fallback se ID non match)
foreach ($admin_names as $admin_name) {
    $stmt = $conn->prepare("UPDATE fratelli SET role = 'admin' WHERE nome = ?");
    $stmt->bind_param('s', $admin_name);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo "✅ [Nome] $admin_name -> Admin\n";
        $success_count++;
    }

    $stmt->close();
}

// Step 3: Imposta role guest per utente Ospite
echo "\nStep 3: Impostazione ruolo guest per Ospite...\n";

$stmt = $conn->prepare("UPDATE fratelli SET role = 'guest' WHERE nome = 'Ospite'");
if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo "✅ Ospite -> Guest\n";
} else {
    echo "⚠️ Utente Ospite non trovato o già configurato\n";
}
$stmt->close();

// Step 4: Imposta role user per tutti gli altri (se NULL)
echo "\nStep 4: Impostazione ruolo user per fratelli normali...\n";

$result = $conn->query("UPDATE fratelli SET role = 'user' WHERE role IS NULL OR role = ''");
$affected = $conn->affected_rows;
echo "✅ $affected fratelli impostati come 'user'\n";

// Step 5: Statistiche finali
echo "\n=== RIEPILOGO MIGRAZIONE ===\n";

$stats = $conn->query("
    SELECT role, COUNT(*) as count
    FROM fratelli
    GROUP BY role
")->fetch_all(MYSQLI_ASSOC);

foreach ($stats as $stat) {
    echo "  - {$stat['role']}: {$stat['count']}\n";
}

echo "\n✅ MIGRAZIONE COMPLETATA CON SUCCESSO!\n";
echo "\n📋 PROSSIMI PASSI:\n";
echo "1. Il sistema ora usa la colonna 'role' invece di ADMIN_IDS hardcoded\n";
echo "2. Per promuovere un utente ad admin: UPDATE fratelli SET role = 'admin' WHERE id = X\n";
echo "3. Per rimuovere privilegi admin: UPDATE fratelli SET role = 'user' WHERE id = X\n";
echo "4. IMPORTANTE: Elimina questo file dopo l'esecuzione per sicurezza\n";

$conn->close();
?>
