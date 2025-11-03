<?php
// DEBUG STEP BY STEP
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "🔍 STEP 1: PHP OK<br>";

// Test 1: Connessione database
echo "🔍 STEP 2: Connessione database...<br>";
try {
    $pdo = new PDO("mysql:host=localhost;dbname=jmvvznbb_tornate_db;charset=utf8mb4", 
                   'jmvvznbb_tornate_user', 'Puntorosso22');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ STEP 2: Database connesso<br>";
} catch (Exception $e) {
    die("❌ STEP 2 FALLITO: " . $e->getMessage());
}

// Test 2: Query semplice
echo "🔍 STEP 3: Query libri...<br>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM libri");
    $count = $stmt->fetchColumn();
    echo "✅ STEP 3: $count libri trovati<br>";
} catch (Exception $e) {
    die("❌ STEP 3 FALLITO: " . $e->getMessage());
}

// Test 3: Query complessa
echo "🔍 STEP 4: Query complessa...<br>";
try {
    $query = "
        SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore
        FROM libri l
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        LIMIT 5
    ";
    $stmt = $pdo->query($query);
    $libri = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ STEP 4: " . count($libri) . " libri con categoria<br>";
} catch (Exception $e) {
    die("❌ STEP 4 FALLITO: " . $e->getMessage());
}

// Test 4: HTML semplice
echo "🔍 STEP 5: Rendering HTML...<br>";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test OK</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-blue-100 p-8">
    <h1 class="text-2xl font-bold text-blue-800">✅ TUTTI I TEST SUPERATI!</h1>
    
    <div class="mt-4 p-4 bg-white rounded shadow">
        <h2 class="font-bold">Primi 5 libri dal database:</h2>
        <ul class="mt-2">
            <?php foreach ($libri as $libro): ?>
                <li class="border-b py-2">
                    <strong><?php echo htmlspecialchars($libro['titolo']); ?></strong><br>
                    <small>di <?php echo htmlspecialchars($libro['autore']); ?></small><br>
                    <?php if ($libro['categoria_nome']): ?>
                        <span class="text-xs bg-gray-200 px-2 py-1 rounded">
                            <?php echo htmlspecialchars($libro['categoria_nome']); ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    
    <div class="mt-4 p-4 bg-green-100 rounded">
        <p><strong>✅ Il sistema funziona!</strong></p>
        <p>Database: OK | PHP: OK | HTML: OK | TailwindCSS: OK</p>
    </div>
</body>
</html>