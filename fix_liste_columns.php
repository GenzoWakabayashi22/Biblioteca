<?php
/**
 * Script per aggiungere colonne mancanti alle tabelle liste
 * Esegui questo file UNA SOLA VOLTA dal browser
 */

session_start();
require_once 'config/database.php';

// Verifica autenticazione (solo admin possono eseguire migrazioni)
if (!isset($_SESSION['fratello_id']) || !in_array($_SESSION['fratello_id'], ADMIN_IDS)) {
    die("❌ Accesso negato. Solo amministratori possono eseguire questo script.");
}

echo "<h1>🔧 Fix Colonne Tabelle Liste</h1>";
echo "<hr>";

$errori = [];
$successi = [];

// 1. Verifica e aggiungi data_modifica a liste_lettura
echo "<h2>1. Verifica colonna data_modifica in liste_lettura</h2>";

$check_query = "SHOW COLUMNS FROM liste_lettura LIKE 'data_modifica'";
$result = $conn->query($check_query);

if ($result->num_rows == 0) {
    echo "⚠️ Colonna 'data_modifica' NON ESISTE - Aggiunta in corso...<br>";

    $alter_query = "
        ALTER TABLE liste_lettura
        ADD COLUMN data_modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        AFTER data_creazione
    ";

    if ($conn->query($alter_query)) {
        echo "✅ Colonna 'data_modifica' aggiunta con successo!<br>";
        $successi[] = "Aggiunta colonna data_modifica a liste_lettura";
    } else {
        echo "❌ ERRORE: " . $conn->error . "<br>";
        $errori[] = "Errore aggiunta data_modifica: " . $conn->error;
    }
} else {
    echo "✅ Colonna 'data_modifica' già presente<br>";
    $successi[] = "Colonna data_modifica già esistente";
}

// 2. Verifica altre colonne di liste_lettura
echo "<h2>2. Verifica altre colonne liste_lettura</h2>";

$required_columns = [
    'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
    'fratello_id' => 'INT NOT NULL',
    'nome' => 'VARCHAR(255) NOT NULL',
    'descrizione' => 'TEXT',
    'icona' => "VARCHAR(50) DEFAULT '📚'",
    'colore' => "VARCHAR(7) DEFAULT '#6366f1'",
    'privata' => 'BOOLEAN DEFAULT FALSE',
    'data_creazione' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
];

foreach ($required_columns as $col_name => $col_def) {
    $check = $conn->query("SHOW COLUMNS FROM liste_lettura LIKE '$col_name'");
    if ($check->num_rows == 0) {
        echo "⚠️ Colonna '$col_name' mancante - Tentativo aggiunta...<br>";
        // Nota: non aggiungiamo automaticamente per sicurezza
        $errori[] = "Colonna $col_name mancante in liste_lettura";
    } else {
        echo "✅ Colonna '$col_name' presente<br>";
    }
}

// 3. Mostra struttura attuale
echo "<h2>3. Struttura Attuale liste_lettura</h2>";
$desc = $conn->query("DESCRIBE liste_lettura");
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
while ($row = $desc->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['Field']}</td>";
    echo "<td>{$row['Type']}</td>";
    echo "<td>{$row['Null']}</td>";
    echo "<td>{$row['Key']}</td>";
    echo "<td>{$row['Default']}</td>";
    echo "<td>{$row['Extra']}</td>";
    echo "</tr>";
}
echo "</table>";

// 4. Verifica lista_libri
echo "<h2>4. Verifica tabella lista_libri</h2>";
$desc2 = $conn->query("DESCRIBE lista_libri");
if ($desc2) {
    echo "✅ Tabella lista_libri esiste<br>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $desc2->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "<td>{$row['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ Tabella lista_libri non esiste!<br>";
    $errori[] = "Tabella lista_libri non trovata";
}

// 5. Test query getMieListe
echo "<h2>5. Test Query getMieListe</h2>";
try {
    $user_id = $_SESSION['fratello_id'];
    $test_query = "
        SELECT ll.*, COUNT(DISTINCT llb.libro_id) as num_libri
        FROM liste_lettura ll
        LEFT JOIN lista_libri llb ON ll.id = llb.lista_id
        WHERE ll.fratello_id = ?
        GROUP BY ll.id
        ORDER BY ll.data_modifica DESC
    ";

    $stmt = $conn->prepare($test_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->num_rows;

    echo "✅ Query funziona! Trovate $count liste<br>";
    $successi[] = "Query getMieListe funzionante";
} catch (Exception $e) {
    echo "❌ ERRORE query: " . $e->getMessage() . "<br>";
    $errori[] = "Query getMieListe fallita: " . $e->getMessage();
}

// Riepilogo
echo "<hr>";
echo "<h2>📊 Riepilogo</h2>";

if (count($successi) > 0) {
    echo "<h3 style='color: green;'>✅ Successi (" . count($successi) . ")</h3>";
    echo "<ul>";
    foreach ($successi as $msg) {
        echo "<li>$msg</li>";
    }
    echo "</ul>";
}

if (count($errori) > 0) {
    echo "<h3 style='color: red;'>❌ Errori (" . count($errori) . ")</h3>";
    echo "<ul>";
    foreach ($errori as $msg) {
        echo "<li>$msg</li>";
    }
    echo "</ul>";
} else {
    echo "<h3 style='color: green;'>🎉 Tutto OK!</h3>";
    echo "<p><strong>Le tabelle sono ora corrette e l'API dovrebbe funzionare.</strong></p>";
}

echo "<hr>";
echo "<h3>✅ Prossimi Passi</h3>";
echo "<ol>";
echo "<li>Se tutto è verde, <a href='pages/liste.php'>vai alla pagina Liste</a></li>";
echo "<li>Oppure <a href='pages/catalogo.php'>vai al Catalogo</a> e prova ad aggiungere un libro a una lista</li>";
echo "<li>Dopo aver verificato che tutto funziona, <strong>ELIMINA questo file</strong> (fix_liste_columns.php) per sicurezza</li>";
echo "</ol>";

echo "<hr>";
echo "<p><small>Eseguito il " . date('Y-m-d H:i:s') . "</small></p>";
?>
