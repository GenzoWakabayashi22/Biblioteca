<?php
session_start();
require_once 'config/database.php';

echo "<h1>Test Database - Sistema Liste</h1>";
echo "<hr>";

// Test 1: Connessione database
echo "<h2>1. Connessione Database</h2>";
if ($conn && $conn->ping()) {
    echo "✅ Connessione al database: OK<br>";
} else {
    echo "❌ Connessione al database: FALLITA<br>";
    die();
}

// Test 2: Verifica esistenza tabelle
echo "<h2>2. Verifica Tabelle</h2>";

$tabelle_richieste = [
    'liste_lettura',
    'lista_libri',
    'libri',
    'fratelli',
    'preferiti'
];

foreach ($tabelle_richieste as $tabella) {
    $result = $conn->query("SHOW TABLES LIKE '$tabella'");
    if ($result && $result->num_rows > 0) {
        echo "✅ Tabella '$tabella': ESISTE<br>";

        // Mostra struttura tabella
        if ($tabella == 'liste_lettura' || $tabella == 'lista_libri') {
            echo "<details><summary>Struttura</summary>";
            $desc = $conn->query("DESCRIBE $tabella");
            echo "<table border='1' style='margin: 10px;'>";
            echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>";
            while ($row = $desc->fetch_assoc()) {
                echo "<tr>";
                echo "<td>{$row['Field']}</td>";
                echo "<td>{$row['Type']}</td>";
                echo "<td>{$row['Null']}</td>";
                echo "<td>{$row['Key']}</td>";
                echo "<td>{$row['Default']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</details>";
        }
    } else {
        echo "❌ Tabella '$tabella': NON ESISTE<br>";
    }
}

// Test 3: Crea tabelle se non esistono
echo "<h2>3. Creazione Tabelle Mancanti</h2>";

$create_liste_lettura = "
CREATE TABLE IF NOT EXISTS liste_lettura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fratello_id INT NOT NULL,
    nome VARCHAR(255) NOT NULL,
    descrizione TEXT,
    icona VARCHAR(50) DEFAULT '📚',
    colore VARCHAR(7) DEFAULT '#6366f1',
    privata BOOLEAN DEFAULT FALSE,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (fratello_id) REFERENCES fratelli(id) ON DELETE CASCADE,
    INDEX idx_fratello (fratello_id)
)";

$create_lista_libri = "
CREATE TABLE IF NOT EXISTS lista_libri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lista_id INT NOT NULL,
    libro_id INT NOT NULL,
    note TEXT,
    posizione INT DEFAULT 0,
    data_aggiunta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unq_lista_libro (lista_id, libro_id),
    FOREIGN KEY (lista_id) REFERENCES liste_lettura(id) ON DELETE CASCADE,
    FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE,
    INDEX idx_lista (lista_id),
    INDEX idx_libro (libro_id)
)";

if ($conn->query($create_liste_lettura)) {
    echo "✅ Tabella 'liste_lettura': CREATA/VERIFICATA<br>";
} else {
    echo "❌ Errore creazione 'liste_lettura': " . $conn->error . "<br>";
}

if ($conn->query($create_lista_libri)) {
    echo "✅ Tabella 'lista_libri': CREATA/VERIFICATA<br>";
} else {
    echo "❌ Errore creazione 'lista_libri': " . $conn->error . "<br>";
}

// Test 4: Test API liste
echo "<h2>4. Test API Liste</h2>";
echo "📡 <a href='api/liste.php' target='_blank'>Testa API Liste (GET)</a><br>";

// Test 5: Verifica sessione
echo "<h2>5. Verifica Sessione</h2>";
if (isset($_SESSION['fratello_id'])) {
    echo "✅ Sessione attiva - Fratello ID: {$_SESSION['fratello_id']}<br>";
    echo "✅ Nome: " . ($_SESSION['nome'] ?? 'Non disponibile') . "<br>";
} else {
    echo "❌ Sessione NON attiva - <a href='index.php'>Login</a><br>";
}

// Test 6: Conteggio dati
echo "<h2>6. Conteggio Dati</h2>";
$result_liste = $conn->query("SELECT COUNT(*) as count FROM liste_lettura");
if ($result_liste) {
    $count = $result_liste->fetch_assoc()['count'];
    echo "📊 Liste totali nel sistema: $count<br>";
}

$result_libri_liste = $conn->query("SELECT COUNT(*) as count FROM lista_libri");
if ($result_libri_liste) {
    $count = $result_libri_liste->fetch_assoc()['count'];
    echo "📊 Libri aggiunti alle liste: $count<br>";
}

echo "<hr>";
echo "<h2>✅ Test Completato</h2>";
echo "<p><a href='pages/liste.php'>Vai alla pagina Liste</a></p>";
echo "<p><a href='pages/catalogo.php'>Vai al Catalogo</a></p>";
?>
