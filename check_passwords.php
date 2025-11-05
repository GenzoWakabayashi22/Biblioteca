<?php
/**
 * Script per verificare lo stato delle password nel database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

echo "<h1>Stato Password Database</h1>\n";
echo "<pre>\n";

// Verifica password hashate
$query = "SELECT id, nome,
          CASE
            WHEN password_hash IS NULL OR password_hash = '' THEN 'NO HASH'
            ELSE 'HAS HASH'
          END as status,
          CASE
            WHEN password_hash IS NULL OR password_hash = '' THEN 0
            ELSE 1
          END as has_hash
          FROM fratelli
          WHERE attivo = 1
          ORDER BY nome";

$result = $conn->query($query);

if ($result) {
    $total = 0;
    $with_hash = 0;
    $without_hash = 0;

    echo "ID\tNome\t\t\t\tStato Password\n";
    echo str_repeat("=", 60) . "\n";

    while ($row = $result->fetch_assoc()) {
        $total++;
        if ($row['has_hash']) {
            $with_hash++;
        } else {
            $without_hash++;
        }

        printf("%-5d\t%-30s\t%-15s\n",
            $row['id'],
            substr($row['nome'], 0, 30),
            $row['status']
        );
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "Totale: $total\n";
    echo "Con hash: $with_hash\n";
    echo "Senza hash: $without_hash\n\n";

    if ($without_hash > 0) {
        echo "⚠️ ATTENZIONE: Ci sono $without_hash utenti senza password hashata\n";
        echo "   Il sistema userà il fallback (Nome+25) per questi utenti\n";
        echo "   Le password verranno hashate automaticamente al primo login\n";
    } else {
        echo "✅ Tutte le password sono hashate!\n";
    }
} else {
    echo "❌ Errore query: " . $conn->error . "\n";
}

echo "</pre>";
?>
