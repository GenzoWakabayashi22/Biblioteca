<?php
session_start();
require_once '../config/database.php';

// Verifica autenticazione
verificaSessioneAttiva();

$user_id = $_SESSION['fratello_id'];
$user_name = $_SESSION['nome'] ?? 'Utente';

// Recupera i preferiti dell'utente senza aggregazioni
$query_preferiti = "
    SELECT p.*, l.titolo, l.autore, l.stato, l.copertina_url, l.descrizione,
           c.nome as categoria_nome, c.colore as categoria_colore
    FROM preferiti p
    INNER JOIN libri l ON p.libro_id = l.id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    WHERE p.fratello_id = ?
    ORDER BY p.data_aggiunta DESC
";
$stmt = $conn->prepare($query_preferiti);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$preferiti = [];
while ($row = $result->fetch_assoc()) {
    // Aggiungi le statistiche delle recensioni per ogni libro
    $stats_query = "
        SELECT COALESCE(AVG(valutazione), 0) as voto_medio,
               COUNT(id) as num_recensioni
        FROM recensioni_libri
        WHERE libro_id = ?
    ";
    $stmt_stats = $conn->prepare($stats_query);
    $stmt_stats->bind_param("i", $row['libro_id']);
    $stmt_stats->execute();
    $stats_result = $stmt_stats->get_result();
    $stats = $stats_result->fetch_assoc();
    $row['voto_medio'] = $stats['voto_medio'];
    $row['num_recensioni'] = $stats['num_recensioni'];
    
    $preferiti[] = $row;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>I Miei Preferiti - Biblioteca R∴ L∴ Kilwinning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary': '#6366f1',
                        'secondary': '#8b5cf6'
                    }
                }
            }
        }
    </script>
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
    </style>
</head>
<body class="p-4">
    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
                <nav class="text-sm text-gray-500 mb-2">
                    <a href="dashboard.php" class="hover:text-primary">🏠 Dashboard</a>
                    <span class="mx-2">→</span>
                    <span class="text-gray-800">Preferiti</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">⭐ I Miei Preferiti</h1>
                <p class="text-gray-600">I libri che hai salvato come preferiti</p>
            </div>
            <div class="flex gap-2 mt-4 md:mt-0">
                <a href="liste.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition">
                    📚 Le Mie Liste
                </a>
                <a href="catalogo.php" class="bg-primary hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                    📖 Catalogo
                </a>
            </div>
        </div>
    </div>

    <?php if (empty($preferiti)): ?>
        <!-- Stato vuoto -->
        <div class="bg-white rounded-2xl shadow-xl p-12 text-center">
            <div class="text-6xl mb-4">⭐</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Nessun preferito ancora</h2>
            <p class="text-gray-600 mb-6">
                Non hai ancora aggiunto libri ai tuoi preferiti.<br>
                Esplora il catalogo e aggiungi i libri che ti interessano!
            </p>
            <a href="catalogo.php" class="inline-block bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                📚 Vai al Catalogo
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold text-gray-800">
                    <?= count($preferiti) ?> libr<?= count($preferiti) === 1 ? 'o' : 'i' ?> preferit<?= count($preferiti) === 1 ? 'o' : 'i' ?>
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($preferiti as $pref): ?>
                    <div class="border border-gray-200 rounded-lg overflow-hidden hover:shadow-lg transition">
                        <!-- Copertina -->
                        <div class="h-64 bg-gray-200 relative">
                            <?php if ($pref['copertina_url']): ?>
                                <img src="<?= htmlspecialchars($pref['copertina_url']) ?>" 
                                     alt="Copertina" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-400 text-5xl">
                                    📖
                                </div>
                            <?php endif; ?>
                            
                            <!-- Badge stato -->
                            <div class="absolute top-2 right-2">
                                <span class="px-3 py-1 rounded-full text-xs font-medium text-white <?= $pref['stato'] == 'disponibile' ? 'bg-green-500' : 'bg-orange-500' ?>">
                                    <?= ucfirst($pref['stato']) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Dettagli -->
                        <div class="p-4">
                            <h3 class="text-lg font-semibold text-gray-800 mb-1 truncate" title="<?= htmlspecialchars($pref['titolo']) ?>">
                                <?= htmlspecialchars($pref['titolo']) ?>
                            </h3>
                            <p class="text-gray-600 text-sm mb-3"><?= htmlspecialchars($pref['autore'] ?? 'Autore sconosciuto') ?></p>
                            
                            <div class="flex items-center space-x-2 mb-3">
                                <?php if ($pref['categoria_nome']): ?>
                                    <span class="px-2 py-1 rounded-full text-xs text-white" 
                                          style="background-color: <?= $pref['categoria_colore'] ?? '#6366f1' ?>">
                                        <?= htmlspecialchars($pref['categoria_nome']) ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($pref['voto_medio'] > 0): ?>
                                    <span class="text-yellow-500 text-sm flex items-center">
                                        ⭐ <?= number_format($pref['voto_medio'], 1) ?>
                                        <span class="text-gray-500 ml-1">(<?= $pref['num_recensioni'] ?>)</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($pref['descrizione']): ?>
                                <p class="text-gray-600 text-sm mb-4 line-clamp-2">
                                    <?= htmlspecialchars(substr($pref['descrizione'], 0, 100)) . (strlen($pref['descrizione']) > 100 ? '...' : '') ?>
                                </p>
                            <?php endif; ?>
                            
                            <?php if ($pref['note']): ?>
                                <div class="bg-blue-50 border border-blue-200 rounded p-2 mb-3">
                                    <p class="text-xs text-blue-700 italic">"<?= htmlspecialchars($pref['note']) ?>"</p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="text-xs text-gray-500 mb-4">
                                Aggiunto il <?= date('d/m/Y', strtotime($pref['data_aggiunta'])) ?>
                            </div>
                            
                            <div class="flex space-x-2">
                                <a href="libro-dettaglio.php?id=<?= $pref['libro_id'] ?>" 
                                   class="flex-1 text-center bg-primary hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition text-sm">
                                    👁️ Dettagli
                                </a>
                                <button onclick="rimuoviPreferito(<?= $pref['libro_id'] ?>)" 
                                        class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition text-sm">
                                    🗑️
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <script>
        async function rimuoviPreferito(libroId) {
            if (!confirm('Vuoi rimuovere questo libro dai preferiti?')) {
                return;
            }

            try {
                const response = await fetch('../api/preferiti.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        libro_id: libroId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ ' + (data.message || 'Errore nella rimozione'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Errore di connessione');
            }
        }
    </script>
</body>
</html>
