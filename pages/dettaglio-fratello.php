<?php
session_start();
require_once '../config/database.php';

// Verifica sessione
verificaSessioneAttiva();

// Dati utente corrente
$user = [
    'id' => $_SESSION['fratello_id'] ?? null,
    'nome' => $_SESSION['fratello_nome'] ?? null,
    'grado' => $_SESSION['fratello_grado'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false
];

// Recupera l'ID del fratello da visualizzare
$fratello_id = $_GET['fratello_id'] ?? null;
if (!$fratello_id || !is_numeric($fratello_id)) {
    header('Location: statistiche-lettori.php');
    exit;
}

// Recupera informazioni del fratello selezionato
$query_fratello = "
    SELECT f.*
    FROM fratelli f
    WHERE f.id = ? AND f.attivo = 1
";

$fratello_info = getSingleResult($query_fratello, [$fratello_id]);

if (!$fratello_info) {
    header('Location: statistiche-lettori.php');
    exit;
}

// Aggiungi statistiche separatamente per evitare problemi con GROUP BY
$stats_query = "
    SELECT 
           COUNT(DISTINCT ll.libro_id) as libri_letti_totali,
           COUNT(DISTINCT l.id) as libri_in_prestito,
           COUNT(DISTINCT r.libro_id) as libri_recensiti,
           ROUND(AVG(r.valutazione), 1) as valutazione_media,
           MAX(ll.data_lettura) as ultima_lettura
    FROM fratelli f
    LEFT JOIN libri_letti ll ON f.id = ll.fratello_id
    LEFT JOIN libri l ON f.id = l.prestato_a_fratello_id AND l.stato = 'prestato'
    LEFT JOIN recensioni_libri r ON f.id = r.fratello_id
    WHERE f.id = ?
";

$stats = getSingleResult($stats_query, [$fratello_id]);

// Merge stats into fratello_info
if ($stats) {
    $fratello_info['libri_letti_totali'] = $stats['libri_letti_totali'];
    $fratello_info['libri_in_prestito'] = $stats['libri_in_prestito'];
    $fratello_info['libri_recensiti'] = $stats['libri_recensiti'];
    $fratello_info['valutazione_media'] = $stats['valutazione_media'];
    $fratello_info['ultima_lettura'] = $stats['ultima_lettura'];
}

// Determina se stiamo visualizzando i nostri libri o quelli di un altro fratello
$is_my_profile = ($fratello_id == $user['id']);

// Query ottimizzata per i libri letti
$query_libri = "
    SELECT 
        l.id, l.titolo, l.autore, l.isbn, l.anno_pubblicazione, l.pagine as numero_pagine,
        l.copertina_url,
        l.voto_medio as voto_medio_libro,
        l.num_recensioni as num_recensioni_libro,
        c.nome as categoria_nome, c.colore as categoria_colore,
        ll.data_lettura, ll.note as note_personali,
        
        -- Recensione del fratello (subquery per evitare GROUP BY pesante)
        (SELECT valutazione FROM recensioni_libri WHERE libro_id = l.id AND fratello_id = ? LIMIT 1) as valutazione,
        (SELECT titolo FROM recensioni_libri WHERE libro_id = l.id AND fratello_id = ? LIMIT 1) as recensione_titolo,
        (SELECT contenuto FROM recensioni_libri WHERE libro_id = l.id AND fratello_id = ? LIMIT 1) as recensione_contenuto,
        (SELECT consigliato FROM recensioni_libri WHERE libro_id = l.id AND fratello_id = ? LIMIT 1) as consigliato,
        (SELECT created_at FROM recensioni_libri WHERE libro_id = l.id AND fratello_id = ? LIMIT 1) as data_recensione,
        
        -- Stato attuale del libro
        CASE 
            WHEN l.stato = 'prestato' AND l.prestato_a_fratello_id = ? THEN 'prestato' 
            ELSE l.stato 
        END as stato_attuale,
        l.data_prestito_corrente, 
        l.data_scadenza_corrente
        
    FROM libri_letti ll
    INNER JOIN libri l ON ll.libro_id = l.id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        
    WHERE ll.fratello_id = ?
    ORDER BY ll.data_lettura DESC
";

// Esegui query con parametri ripetuti
$fratello_id_params = array_fill(0, 7, $fratello_id);
$libri_letti = getAllResults($query_libri, $fratello_id_params, str_repeat('i', 7));

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
    <title>📚 Libri di <?= htmlspecialchars($fratello_info['nome']) ?> - Biblioteca R∴ L∴ Kilwinning</title>
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
        .hover-lift:hover {
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }
        .line-clamp-1 {
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body class="p-4">

<div class="max-w-7xl mx-auto">
    
    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">
                    📚 <?= $is_my_profile ? 'I Miei Libri Letti' : 'Libri di ' . htmlspecialchars($fratello_info['nome']) ?>
                </h1>
                <div class="flex items-center space-x-2 mt-2">
                    <span class="text-xl"><?= getGradoIcon($fratello_info['grado']) ?></span>
                    <span class="text-lg font-semibold text-gray-700"><?= htmlspecialchars($fratello_info['nome']) ?></span>
                    <span class="px-2 py-1 text-xs rounded-full border <?= getGradoColor($fratello_info['grado']) ?>">
                        <?= htmlspecialchars($fratello_info['grado']) ?>
                    </span>
                    <?php if (!$is_my_profile): ?>
                        <span class="text-sm text-blue-600">(Solo visualizzazione)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-4 md:mt-0 flex gap-3">
                <a href="statistiche-lettori.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition-colors">
                    ← Torna alla Classifica
                </a>
                <a href="catalogo.php" class="bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-colors">
                    📚 Vai al Catalogo
                </a>
            </div>
        </div>
    </div>

    <!-- Statistiche del Fratello -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4">📊 Statistiche di Lettura</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            <div class="text-center p-4 bg-blue-50 rounded-xl">
                <div class="text-2xl font-bold text-blue-600"><?= $fratello_info['libri_letti_totali'] ?></div>
                <div class="text-sm text-gray-600">Libri Letti</div>
            </div>
            <?php if ($fratello_info['libri_in_prestito'] > 0): ?>
                <div class="text-center p-4 bg-amber-50 rounded-xl">
                    <div class="text-2xl font-bold text-amber-600"><?= $fratello_info['libri_in_prestito'] ?></div>
                    <div class="text-sm text-gray-600">In Prestito</div>
                </div>
            <?php endif; ?>
            <?php if ($fratello_info['libri_recensiti'] > 0): ?>
                <div class="text-center p-4 bg-purple-50 rounded-xl">
                    <div class="text-2xl font-bold text-purple-600"><?= $fratello_info['libri_recensiti'] ?></div>
                    <div class="text-sm text-gray-600">Recensiti</div>
                </div>
            <?php endif; ?>
            <?php if ($fratello_info['valutazione_media'] > 0): ?>
                <div class="text-center p-4 bg-yellow-50 rounded-xl">
                    <div class="text-2xl font-bold text-yellow-600">⭐ <?= $fratello_info['valutazione_media'] ?></div>
                    <div class="text-sm text-gray-600">Voto Medio</div>
                </div>
            <?php endif; ?>
            <?php if ($fratello_info['ultima_lettura']): ?>
                <div class="text-center p-4 bg-indigo-50 rounded-xl">
                    <div class="text-sm font-bold text-indigo-600"><?= date('d/m/Y', strtotime($fratello_info['ultima_lettura'])) ?></div>
                    <div class="text-sm text-gray-600">Ultima Lettura</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lista Libri Letti -->
    <div class="bg-white rounded-2xl shadow-xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-800">📖 Libri Letti</h2>
            <div class="text-sm text-gray-500">
                <?= count($libri_letti) ?> libri totali
            </div>
        </div>

        <?php if (empty($libri_letti)): ?>
            <div class="text-center py-12">
                <div class="text-6xl mb-4">📚</div>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">
                    <?= $is_my_profile ? 'Non hai ancora letto nessun libro' : htmlspecialchars($fratello_info['nome']) . ' non ha ancora letto nessun libro' ?>
                </h3>
                <p class="text-gray-500">
                    <?= $is_my_profile ? 'Vai al catalogo per prendere in prestito il tuo primo libro!' : 'Invita ' . htmlspecialchars($fratello_info['nome']) . ' a scoprire i tesori della nostra biblioteca!' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($libri_letti as $libro): ?>
                    <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 hover:-translate-y-1 border border-gray-100 overflow-hidden">
                        
                        <!-- Header con data lettura e categoria -->
                        <div class="p-4 pb-2">
                            <div class="flex justify-between items-start mb-2">
                                <div class="text-xs text-gray-500">
                                    📅 Letto il <?= date('d/m/Y', strtotime($libro['data_lettura'])) ?>
                                </div>
                                <?php if ($libro['categoria_nome']): ?>
                                    <span class="px-2 py-1 text-xs rounded-full text-white text-center" 
                                          style="background-color: <?= htmlspecialchars($libro['categoria_colore'] ?? '#6b7280') ?>">
                                        <?= htmlspecialchars($libro['categoria_nome']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Copertina VERTICALE come un vero libro! -->
                        <div class="px-4 pb-3 flex justify-center">
                            <?php if (!empty($libro['copertina_url'])): ?>
                                <div class="w-32 h-44 rounded-lg overflow-hidden shadow-md border border-gray-200">
                                    <img src="<?= htmlspecialchars($libro['copertina_url']) ?>" 
                                         alt="Copertina <?= htmlspecialchars($libro['titolo']) ?>"
                                         class="w-full h-full object-cover hover:scale-105 transition-transform duration-300"
                                         loading="lazy"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <!-- Fallback se l'immagine non carica -->
                                    <div class="w-full h-44 bg-gradient-to-br from-gray-200 to-gray-300 rounded-lg items-center justify-center hidden">
                                        <div class="text-center text-gray-600">
                                            <div class="text-2xl mb-1">📖</div>
                                            <div class="text-xs px-2">Copertina non disponibile</div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="w-32 h-44 bg-gradient-to-br from-gray-200 to-gray-300 rounded-lg flex items-center justify-center shadow-md border border-gray-200">
                                    <div class="text-center text-gray-600">
                                        <div class="text-2xl mb-1">📖</div>
                                        <div class="text-xs px-2">Nessuna copertina</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Dettagli libro -->
                        <div class="p-4 pt-2">
                            <h3 class="font-bold text-gray-900 text-sm mb-1 line-clamp-2 leading-tight" title="<?= htmlspecialchars($libro['titolo']) ?>">
                                <?= htmlspecialchars($libro['titolo']) ?>
                            </h3>
                            
                            <p class="text-xs text-gray-600 mb-2 line-clamp-1">
                                <?= htmlspecialchars($libro['autore'] ?? 'Autore non specificato') ?>
                            </p>

                            <!-- Recensione del fratello (se presente) -->
                            <?php if ($libro['valutazione']): ?>
                                <div class="mb-3 p-2 bg-blue-50 rounded-lg border-l-4 border-blue-400">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-semibold text-blue-800">La mia valutazione:</span>
                                        <div class="flex items-center">
                                            <?= getStarsHtml($libro['valutazione']) ?>
                                            <span class="ml-1 text-xs text-gray-600">(<?= $libro['valutazione'] ?>/5)</span>
                                        </div>
                                    </div>
                                    <?php if ($libro['recensione_titolo']): ?>
                                        <div class="text-xs font-medium text-blue-700 mb-1">
                                            "<?= htmlspecialchars($libro['recensione_titolo']) ?>"
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($libro['recensione_contenuto']): ?>
                                        <div class="text-xs text-blue-600 line-clamp-2">
                                            <?= htmlspecialchars(substr($libro['recensione_contenuto'], 0, 100)) ?>...
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($libro['consigliato']): ?>
                                        <div class="text-xs text-green-600 font-medium mt-1">
                                            ✅ Consigliato
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Note personali (se presenti) -->
                            <?php if ($libro['note_personali']): ?>
                                <div class="mb-3 p-2 bg-yellow-50 rounded-lg">
                                    <div class="text-xs font-semibold text-yellow-800 mb-1">📝 Note personali:</div>
                                    <div class="text-xs text-yellow-700 line-clamp-2">
                                        <?= htmlspecialchars($libro['note_personali']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Statistiche generali del libro -->
                            <?php if ($libro['num_recensioni_libro'] > 0): ?>
                                <div class="mb-3 p-2 bg-gray-50 rounded-lg">
                                    <div class="text-xs text-gray-600">
                                        <div class="flex items-center justify-between">
                                            <span>Voto medio fratelli:</span>
                                            <div class="flex items-center">
                                                <span class="text-yellow-500">⭐</span>
                                                <span class="ml-1 font-medium"><?= number_format($libro['voto_medio_libro'], 1) ?></span>
                                                <span class="text-gray-400 ml-1">(<?= $libro['num_recensioni_libro'] ?> voti)</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Stato attuale del libro -->
                            <?php if ($libro['stato_attuale'] == 'prestato'): ?>
                                <div class="mb-3 p-2 bg-orange-50 rounded-lg border border-orange-200">
                                    <div class="text-xs text-orange-700">
                                        📚 Attualmente in prestito a te
                                        <?php if ($libro['data_scadenza_corrente']): ?>
                                            <div class="text-orange-600 font-medium">
                                                Scadenza: <?= date('d/m/Y', strtotime($libro['data_scadenza_corrente'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Informazioni aggiuntive libro -->
                            <div class="mt-2 text-xs text-gray-500">
                                <?php if ($libro['anno_pubblicazione']): ?>
                                    <div><?= $libro['anno_pubblicazione'] ?></div>
                                <?php endif; ?>
                                <?php if ($libro['numero_pagine']): ?>
                                    <div><?= $libro['numero_pagine'] ?> pagine</div>
                                <?php endif; ?>
                                <?php if ($libro['isbn']): ?>
                                    <div class="truncate">ISBN: <?= $libro['isbn'] ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Link al dettaglio -->
                            <div class="mt-3">
                                <a href="libro-dettaglio.php?id=<?= $libro['id'] ?>" 
                                   class="text-primary hover:text-blue-600 text-xs font-medium">
                                    👁️ Vedi dettagli libro →
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Riepilogo finale -->
            <div class="mt-8 p-4 bg-blue-50 rounded-xl">
                <h3 class="font-semibold text-blue-800 mb-2">
                    📈 Riepilogo letture di <?= $is_my_profile ? 'te' : htmlspecialchars($fratello_info['nome']) ?>
                </h3>
                <div class="text-sm text-blue-700 space-y-1">
                    <p>• <strong><?= count($libri_letti) ?></strong> libri letti in totale</p>
                    <?php if ($fratello_info['libri_recensiti'] > 0): ?>
                        <p>• <strong><?= $fratello_info['libri_recensiti'] ?></strong> libri recensiti 
                           (<?= round(($fratello_info['libri_recensiti'] / count($libri_letti)) * 100) ?>% del totale)</p>
                    <?php endif; ?>
                    <?php if ($fratello_info['ultima_lettura']): ?>
                        <p>• Ultima lettura: <strong><?= date('d/m/Y', strtotime($fratello_info['ultima_lettura'])) ?></strong></p>
                    <?php endif; ?>
                    <p class="pt-2 border-t border-blue-200">
                        💡 <strong>Clicca su qualsiasi libro</strong> per vedere tutti i dettagli, le recensioni degli altri fratelli e molto altro!
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Funzioni JavaScript utili per la pagina
document.addEventListener('DOMContentLoaded', function() {
    // Tooltips per informazioni aggiuntive
    const tooltipElements = document.querySelectorAll('[title]');
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', function() {
            this.classList.add('cursor-help');
        });
    });

    // Scroll smooth per le ancore
    const anchorLinks = document.querySelectorAll('a[href^="#"]');
    anchorLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // Gestione errori immagini copertine
    const coverImages = document.querySelectorAll('img[alt*="Copertina"]');
    coverImages.forEach(img => {
        img.addEventListener('error', function() {
            this.style.display = 'none';
            const fallback = this.nextElementSibling;
            if (fallback && fallback.classList.contains('hidden')) {
                fallback.classList.remove('hidden');
                fallback.style.display = 'flex';
            }
        });
    });
});
</script>

</body>
</html>