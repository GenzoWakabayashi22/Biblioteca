<?php
echo "<h1>TEST DATABASE</h1>";
echo "Data/ora: " . date('Y-m-d H:i:s') . "<br>";

$conn = new mysqli('localhost', 'jmvvznbb_tornate_user', 'Puntorosso22', 'jmvvznbb_tornate_db');

if ($conn->connect_error) {
    echo "❌ ERRORE DB: " . $conn->connect_error;
} else {
    echo "✅ DATABASE OK<br>";
    
    $result = $conn->query("SELECT COUNT(*) as total FROM libri");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ Libri nel database: " . $row['total'] . "<br>";
    }
}
?>