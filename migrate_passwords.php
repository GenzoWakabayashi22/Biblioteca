<?php
/**
 * Script di Migrazione Password
 * Aggiunge colonna password_hash e genera hash per password esistenti
 *
 * IMPORTANTE: Esegui questo script UNA SOLA VOLTA
 * Backup del database prima di eseguire!
 */

require_once 'config/database.php';

echo "=== MIGRAZIONE PASSWORD A SISTEMA SICURO ===\n\n";

// Step 1: Aggiungi colonna password_hash se non esiste
echo "Step 1: Verifica/Aggiunta colonna password_hash...\n";

$check_column = $conn->query("SHOW COLUMNS FROM fratelli LIKE 'password_hash'");
if ($check_column->num_rows == 0) {
    $add_column = $conn->query("
        ALTER TABLE fratelli
        ADD COLUMN password_hash VARCHAR(255) NULL AFTER attivo
    ");

    if ($add_column) {
        echo "✅ Colonna password_hash aggiunta con successo\n\n";
    } else {
        die("❌ ERRORE: Impossibile aggiungere colonna password_hash: " . $conn->error . "\n");
    }
} else {
    echo "✅ Colonna password_hash già esistente\n\n";
}

// Step 2: Genera hash per tutti i fratelli attivi
echo "Step 2: Generazione hash password per fratelli attivi...\n";

$query = "SELECT id, nome FROM fratelli WHERE attivo = 1";
$result = $conn->query($query);

if (!$result) {
    die("❌ ERRORE query fratelli: " . $conn->error . "\n");
}

$fratelli = [];
while ($row = $result->fetch_assoc()) {
    $fratelli[] = $row;
}

echo "Trovati " . count($fratelli) . " fratelli attivi\n\n";

$success_count = 0;
$error_count = 0;

foreach ($fratelli as $fratello) {
    // Genera password attesa
    if ($fratello['nome'] === 'Ospite') {
        $password = 'Ospite25';
    } else {
        $nome_parts = explode(' ', $fratello['nome']);
        $primo_nome = $nome_parts[0];
        $password = $primo_nome . '25';
    }

    // Genera hash sicuro
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Aggiorna database
    $stmt = $conn->prepare("UPDATE fratelli SET password_hash = ? WHERE id = ?");
    $stmt->bind_param('si', $password_hash, $fratello['id']);

    if ($stmt->execute()) {
        echo "✅ [{$fratello['id']}] {$fratello['nome']} - Hash generato\n";
        $success_count++;
    } else {
        echo "❌ [{$fratello['id']}] {$fratello['nome']} - ERRORE: " . $stmt->error . "\n";
        $error_count++;
    }

    $stmt->close();
}

echo "\n=== RIEPILOGO MIGRAZIONE ===\n";
echo "Successo: $success_count\n";
echo "Errori: $error_count\n";

if ($error_count === 0) {
    echo "\n✅ MIGRAZIONE COMPLETATA CON SUCCESSO!\n";
    echo "\n📋 PROSSIMI PASSI:\n";
    echo "1. Il sistema di login ora usa password_hash/password_verify\n";
    echo "2. Le vecchie password (Nome+25) sono state hashate in modo sicuro\n";
    echo "3. Per cambiare password di un utente, usa password_hash() in PHP\n";
    echo "4. IMPORTANTE: Elimina questo file dopo l'esecuzione per sicurezza\n";
} else {
    echo "\n⚠️ MIGRAZIONE COMPLETATA CON ERRORI\n";
    echo "Verifica i log sopra e riprova se necessario\n";
}

$conn->close();
?>
