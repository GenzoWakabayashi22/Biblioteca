<?php
session_start();
require_once '../config/database.php';

// Verifica autenticazione
if (!isset($_SESSION['fratello_id'])) {
    header('Location: ../index.php');
    exit;
}

// Dati utente corrente
$user = [
    'id' => $_SESSION['fratello_id'] ?? null,
    'nome' => $_SESSION['fratello_nome'] ?? null,
    'grado' => $_SESSION['fratello_grado'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false
];

// Query per la classifica lettori basata sulla tabella libri_letti (come nella dashboard)
$classifica_lettori = getAllResults("
    SELECT 
        f.id,
        f.nome, 
        f.grado,
        COUNT(DISTINCT ll.libro_id) as libri_letti,
        COUNT(DISTINCT CASE WHEN l.prestato_a_fratello_id = f.id THEN l.id END) as libri_attuali,
        COUNT(DISTINCT r.libro_id) as libri_recensiti,
        ROUND(AVG(r.valutazione), 1) as valutazione_media,
        MAX(ll.data_lettura) as ultima_lettura
    FROM fratelli f
    INNER JOIN libri_letti ll ON f.id = ll.fratello_id
    LEFT JOIN libri l ON f.id = l.prestato_a_fratello_id AND l.stato = 'prestato'
    LEFT JOIN recensioni_libri r ON f.id = r.fratello_id
    WHERE f.attivo = 1
    GROUP BY f.id, f.nome, f.grado
    ORDER BY libri_letti DESC, f.nome ASC
");

// Statistiche generali basate sulla tabella libri_letti
$stats_generali = getSingleResult("
    SELECT 
        COUNT(DISTINCT f.id) as totale_fratelli_attivi,
        COUNT(DISTINCT CASE WHEN ll.fratello_id IS NOT NULL THEN f.id END) as fratelli_lettori,
        COALESCE(COUNT(DISTINCT ll.id), 0) as totale_letture,
        COALESCE(ROUND(COUNT(DISTINCT ll.id) / COUNT(DISTINCT CASE WHEN ll.fratello_id IS NOT NULL THEN f.id END), 1), 0) as media_libri_per_fratello
    FROM fratelli f
    LEFT JOIN libri_letti ll ON f.id = ll.fratello_id
    WHERE f.attivo = 1
");

// Funzione per determinare l'icona del grado
function getGradoIcon($grado) {
    $grado_lower = strtolower($grado);
    if (strpos($grado_lower, 'maestro') !== false) return '🔶';
    if (strpos($grado_lower, 'compagno') !== false) return '🔷';
    if (strpos($grado_lower, 'apprendista') !== false) return '🔺';
    return '📖';
}

// Funzione per determinare il colore del badge grado
function getGradoColor($grado) {
    $grado_lower = strtolower($grado);
    if (strpos($grado_lower, 'maestro') !== false) return 'bg-amber-100 text-amber-800 border-amber-300';
    if (strpos($grado_lower, 'compagno') !== false) return 'bg-blue-100 text-blue-800 border-blue-300';
    if (strpos($grado_lower, 'apprendista') !== false) return 'bg-green-100 text-green-800 border-green-300';
    return 'bg-gray-100 text-gray-800 border-gray-300';
}

// Funzione per le stelle di valutazione
function getStarsHtml($rating) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<span class="text-yellow-400">⭐</span>';
        } else {
            $html .= '<span class="text-gray-300">⭐</span>';
        }
    }
    return $html;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Classifica Lettori - Biblioteca R∴ L∴ Kilwinning</title>
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
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh; 
        }
        .hover-lift { 
            transition: all 0.2s ease-in-out; 
        }
        .hover-lift:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 10px 25px rgba(0,0,0,0.15); 
        }
    </style>
</head>
<body class="p-4">

    <!-- Header Navigation -->
    <div class="bg-white/10 backdrop-blur-md border-b border-white/20 rounded-t-2xl">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <!-- Logo e Info -->
                <div class="flex items-center space-x-4">
                    <div class="text-white">
                        <h1 class="text-xl font-bold">📚 Biblioteca R∴ L∴ Kilwinning</h1>
                        <p class="text-sm text-white/80">Benvenuto, <?php echo htmlspecialchars($user['nome']); ?></p>
                    </div>
                </div>
                
                <!-- Navigation -->
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                        🏠 Dashboard
                    </a>
                    <a href="catalogo.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                        📖 Catalogo
                    </a>
                    <a href="../api/logout.php" class="bg-red-500/80 hover:bg-red-500 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        🚪 Esci
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Header con Breadcrumb -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6 mt-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
                <nav class="text-sm text-gray-500 mb-2">
                    <a href="dashboard.php" class="hover:text-primary transition-colors">🏠 Dashboard</a>
                    <span class="mx-2">→</span>
                    <span class="text-gray-800">📊 Classifica Lettori</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">
                    🏆 Classifica Fratelli Lettori
                </h1>
                <p class="text-gray-600 mt-2">
                    Scopri chi sono i fratelli più appassionati di lettura della nostra loggia
                </p>
            </div>
        </div>
    </div>

    <!-- Statistiche Generali -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">📈 Statistiche Generali</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center p-4 bg-blue-50 rounded-xl">
                <div class="text-2xl font-bold text-blue-600"><?= $stats_generali['fratelli_lettori'] ?></div>
                <div class="text-sm text-gray-600">Fratelli Lettori</div>
                <div class="text-xs text-gray-500">su <?= $stats_generali['totale_fratelli_attivi'] ?> attivi</div>
            </div>
            <div class="text-center p-4 bg-green-50 rounded-xl">
                <div class="text-2xl font-bold text-green-600"><?= $stats_generali['totale_letture'] ?></div>
                <div class="text-sm text-gray-600">Letture Totali</div>
                <div class="text-xs text-gray-500">libri segnati come letti</div>
            </div>
            <div class="text-center p-4 bg-purple-50 rounded-xl">
                <div class="text-2xl font-bold text-purple-600"><?= $stats_generali['media_libri_per_fratello'] ?></div>
                <div class="text-sm text-gray-600">Media per Fratello</div>
                <div class="text-xs text-gray-500">libri letti</div>
            </div>
            <div class="text-center p-4 bg-amber-50 rounded-xl">
                <div class="text-2xl font-bold text-amber-600"><?= count($classifica_lettori) ?></div>
                <div class="text-sm text-gray-600">In Classifica</div>
                <div class="text-xs text-gray-500">con almeno 1 libro</div>
            </div>
        </div>
    </div>

    <!-- Classifica Lettori -->
    <div class="bg-white rounded-2xl shadow-xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-800">🏆 Classifica Completa</h2>
            <div class="text-sm text-gray-500">
                Ordinata per numero di libri letti
            </div>
        </div>

        <?php if (empty($classifica_lettori)): ?>
            <div class="text-center py-12">
                <div class="text-6xl mb-4">📚</div>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">Nessun dato disponibile</h3>
                <p class="text-gray-500">I fratelli devono iniziare a prendere libri in prestito per apparire in classifica!</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($classifica_lettori as $index => $fratello): ?>
                    <div class="border rounded-xl p-4 hover-lift <?= $fratello['id'] == $user['id'] ? 'border-primary bg-blue-50' : 'border-gray-200' ?>">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <!-- Posizione in classifica -->
                                <div class="flex-shrink-0">
                                    <?php if ($index === 0): ?>
                                        <div class="w-12 h-12 bg-yellow-400 rounded-full flex items-center justify-center text-white font-bold text-lg">
                                            🥇
                                        </div>
                                    <?php elseif ($index === 1): ?>
                                        <div class="w-12 h-12 bg-gray-400 rounded-full flex items-center justify-center text-white font-bold text-lg">
                                            🥈
                                        </div>
                                    <?php elseif ($index === 2): ?>
                                        <div class="w-12 h-12 bg-amber-600 rounded-full flex items-center justify-center text-white font-bold text-lg">
                                            🥉
                                        </div>
                                    <?php else: ?>
                                        <div class="w-12 h-12 bg-gray-200 rounded-full flex items-center justify-center text-gray-600 font-bold text-lg">
                                            <?= $index + 1 ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Info Fratello -->
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="text-xl"><?= getGradoIcon($fratello['grado']) ?></span>
                                        <h3 class="text-lg font-semibold text-gray-800">
                                            <?= htmlspecialchars($fratello['nome']) ?>
                                            <?php if ($fratello['id'] == $user['id']): ?>
                                                <span class="text-primary text-sm">(Tu)</span>
                                            <?php endif; ?>
                                        </h3>
                                        <span class="px-2 py-1 text-xs rounded-full border <?= getGradoColor($fratello['grado']) ?>">
                                            <?= htmlspecialchars($fratello['grado']) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center space-x-4 text-sm text-gray-600">
                                        <span class="font-medium">📚 <?= $fratello['libri_letti'] ?> libri letti</span>
                                        <?php if ($fratello['libri_attuali'] > 0): ?>
                                            <span class="text-amber-600">📖 <?= $fratello['libri_attuali'] ?> in corso</span>
                                        <?php endif; ?>
                                        <?php if ($fratello['libri_recensiti'] > 0): ?>
                                            <span class="text-purple-600">⭐ <?= $fratello['libri_recensiti'] ?> recensioni</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($fratello['ultima_lettura']): ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            Ultima lettura: <?= date('d/m/Y', strtotime($fratello['ultima_lettura'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Statistiche aggiuntive -->
                            <div class="text-right">
                                <?php if ($fratello['valutazione_media'] > 0): ?>
                                    <div class="text-sm text-gray-600 mb-1">
                                        <span class="font-medium">⭐ <?= $fratello['valutazione_media'] ?></span>
                                        <span class="text-xs text-gray-500">voto medio</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Link ai dettagli -->
                                <div class="mt-2">
                                    <a href="dettaglio-fratello.php?fratello_id=<?= $fratello['id'] ?>" 
                                       class="text-primary hover:text-blue-600 text-xs font-medium">
                                        👁️ Vedi libri letti →
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Suggerimenti per migliorare -->
            <div class="mt-8 p-4 bg-blue-50 rounded-xl">
                <h3 class="font-semibold text-blue-800 mb-2">💡 Come scalare la classifica</h3>
                <div class="text-sm text-blue-700 space-y-1">
                    <p>• Prendi più libri in prestito dal catalogo</p>
                    <p>• Restituisci i libri nei tempi previsti</p>
                    <p>• Scrivi recensioni per i libri che hai letto</p>
                    <p>• Partecipa attivamente alla vita culturale della loggia</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Aggiungi effetti hover per migliorare l'UX
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.hover-lift');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    if (!this.classList.contains('bg-blue-50')) {
                        this.style.transform = 'translateY(-2px)';
                        this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.15)';
                    }
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '';
                });
            });

            // Evidenzia la posizione dell'utente corrente
            const userCard = document.querySelector('.bg-blue-50');
            if (userCard) {
                userCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        console.log('📊 Pagina Classifica Lettori caricata');
        console.log('👥 Fratelli in classifica:', <?= count($classifica_lettori) ?>);
    </script>
</body>
</html>