<?php
session_start();

// Connessione database diretta
$db_config = [
    'host' => 'localhost',
    'username' => 'jmvvznbb_tornate_user', 
    'password' => 'Puntorosso22',
    'database' => 'jmvvznbb_tornate_db'
];

$conn = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['database']);

if ($conn->connect_error) {
    die("Errore connessione: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

// Verifica autenticazione
$user_logged = isset($_SESSION['fratello_id']) && !empty($_SESSION['fratello_id']);
if (!$user_logged) {
    header('Location: ../index.php');
    exit;
}

// Funzione corretta per mostrare le stelle
function mostraStelle($voto_medio, $dimensione = 'text-xs') {
    if ($voto_medio == 0) return '';
    
    $output = '<div class="flex items-center">';
    
    // Stelle piene
    $stelle_piene = floor($voto_medio);
    for ($i = 1; $i <= $stelle_piene; $i++) {
        $output .= "<span class=\"{$dimensione} text-yellow-400\">⭐</span>";
    }
    
    // Mezza stella se decimale >= 0.5
    $decimale = $voto_medio - $stelle_piene;
    if ($decimale >= 0.5 && $stelle_piene < 5) {
        $output .= "<span class=\"{$dimensione} text-yellow-400\">⭐</span>";
        $stelle_piene++;
    }
    
    // Stelle vuote per completare le 5
    $stelle_vuote = 5 - $stelle_piene;
    for ($i = 1; $i <= $stelle_vuote; $i++) {
        $output .= "<span class=\"{$dimensione} text-gray-300\">☆</span>";
    }
    
    $output .= '</div>';
    return $output;
}

// Admin check
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
$is_admin = in_array($_SESSION['fratello_id'], $admin_ids);

// Recupera dati utente
$user_query = "SELECT nome, grado FROM fratelli WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $_SESSION['fratello_id']);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc();

// Parametri di ricerca e filtri
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$categoria_filter = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$stato_filter = isset($_GET['stato']) ? $_GET['stato'] : '';
$grado_filter = isset($_GET['grado']) ? $_GET['grado'] : '';

// Recupera tutte le categorie per il filtro
$categorie_query = "SELECT id, nome FROM categorie_libri WHERE attiva = 1 ORDER BY ordine ASC, nome ASC";
$categorie_result = $conn->query($categorie_query);
$categorie = [];
if ($categorie_result) {
    while ($row = $categorie_result->fetch_assoc()) {
        $categorie[] = $row;
    }
}

// Costruisce la query per i libri con filtri
$where_conditions = ["1=1"];
$types = "";
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(l.titolo LIKE ? OR l.autore LIKE ? OR l.editore LIKE ? OR l.descrizione LIKE ?)";
    $search_param = '%' . $search . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= 'ssss';
}

if ($categoria_filter > 0) {
    $where_conditions[] = "l.categoria_id = ?";
    $params[] = $categoria_filter;
    $types .= 'i';
}

if (!empty($stato_filter)) {
    $where_conditions[] = "l.stato = ?";
    $params[] = $stato_filter;
    $types .= 's';
}

if (!empty($grado_filter)) {
    $where_conditions[] = "l.grado_minimo = ?";
    $params[] = $grado_filter;
    $types .= 's';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_conditions);

// Query principale per i libri
$libri_query = "
    SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore,
           f.nome as prestato_a_nome,
           COALESCE(AVG(r.valutazione), 0) as voto_medio,
           COUNT(DISTINCT r.id) as num_recensioni
    FROM libri l 
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    LEFT JOIN fratelli f ON l.prestato_a_fratello_id = f.id
    LEFT JOIN recensioni_libri r ON l.id = r.libro_id
    {$where_sql}
    GROUP BY l.id
    ORDER BY l.titolo ASC
";

if (!empty($params)) {
    $stmt = $conn->prepare($libri_query);
    if (!empty($types)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $libri_result = $stmt->get_result();
} else {
    $libri_result = $conn->query($libri_query);
}

$libri = [];
if ($libri_result) {
    while ($row = $libri_result->fetch_assoc()) {
        $libri[] = $row;
    }
}

// Conta totale libri
$count_query = "SELECT COUNT(DISTINCT l.id) as total FROM libri l LEFT JOIN categorie_libri c ON l.categoria_id = c.id {$where_sql}";
if (!empty($params)) {
    $stmt_count = $conn->prepare($count_query);
    if (!empty($types)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $count_result = $stmt_count->get_result();
    $total_result = $count_result->fetch_assoc();
} else {
    $count_result = $conn->query($count_query);
    $total_result = $count_result->fetch_assoc();
}

$total_libri = $total_result['total'];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalogo Biblioteca - R∴ L∴ Kilwinning</title>
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
                <h1 class="text-3xl font-bold text-gray-800">📚 Catalogo Biblioteca</h1>
                <p class="text-gray-600"><?= $total_libri ?> libri nella collezione</p>
                <p class="text-sm text-blue-600">👋 Benvenuto, <?= htmlspecialchars($user['nome'] ?? 'Fratello') ?> (<?= htmlspecialchars($user['grado'] ?? '') ?>)</p>
            </div>
            <div class="flex flex-wrap gap-2 mt-4 md:mt-0">
                <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                    🏠 Dashboard
                </a>
                <a href="prestiti.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                    📖 I Miei Prestiti
                </a>
                <?php if ($is_admin): ?>
                    <!-- NUOVO LINK per Admin: Richieste Prestito -->
                    <a href="admin/richieste-prestito.php" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition relative">
                        📋 Richieste
                        <?php
                        // Badge notifica
                        $richieste_query = "SELECT COUNT(*) as count FROM richieste_prestito WHERE stato = 'in_attesa'";
                        $richieste_result = $conn->query($richieste_query);
                        if ($richieste_result) {
                            $count_attesa = $richieste_result->fetch_assoc()['count'] ?? 0;
                            if ($count_attesa > 0):
                        ?>
                            <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?= $count_attesa ?></span>
                        <?php 
                            endif;
                        }
                        ?>
                    </a>
                    
                    <a href="admin/gestione-libri.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition">
                        ➕ Aggiungi Libro
                    </a>
                <?php endif; ?>
                <a href="../api/logout.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition">
                    🚪 Esci
                </a>
            </div>
        </div>
    </div>

    <!-- Filtri di ricerca -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div>
                <label class="block text-gray-700 text-sm font-medium mb-2">Cerca</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Titolo, autore, editore..." 
                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-gray-700 text-sm font-medium mb-2">Categoria</label>
                <select name="categoria" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Tutte le categorie</option>
                    <?php foreach ($categorie as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoria_filter == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-gray-700 text-sm font-medium mb-2">Stato</label>
                <select name="stato" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Tutti gli stati</option>
                    <option value="disponibile" <?= $stato_filter == 'disponibile' ? 'selected' : '' ?>>📗 Disponibile</option>
                    <option value="prestato" <?= $stato_filter == 'prestato' ? 'selected' : '' ?>>📘 In Prestito</option>
                    <option value="manutenzione" <?= $stato_filter == 'manutenzione' ? 'selected' : '' ?>>🔧 Manutenzione</option>
                    <option value="perso" <?= $stato_filter == 'perso' ? 'selected' : '' ?>>❌ Perso</option>
                </select>
            </div>
            
            <div>
                <label class="block text-gray-700 text-sm font-medium mb-2">Grado Minimo</label>
                <select name="grado" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Tutti i gradi</option>
                    <option value="pubblico" <?= $grado_filter == 'pubblico' ? 'selected' : '' ?>>🌍 Pubblico</option>
                    <option value="Apprendista" <?= $grado_filter == 'Apprendista' ? 'selected' : '' ?>>🔺 Apprendista</option>
                    <option value="Compagno" <?= $grado_filter == 'Compagno' ? 'selected' : '' ?>>🔷 Compagno</option>
                    <option value="Maestro" <?= $grado_filter == 'Maestro' ? 'selected' : '' ?>>🔶 Maestro</option>
                </select>
            </div>
            
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition flex-1">
                    🔍 Cerca
                </button>
                <a href="catalogo.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition">
                    🔄
                </a>
            </div>
        </form>
    </div>

    <!-- Griglia libri con COPERTINE VERTICALI -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php foreach ($libri as $libro): ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow duration-300">
                <!-- Header libro con stato -->
                <div class="p-4 pb-2">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex-1">
                            <?php if ($libro['categoria_nome']): ?>
                                <span class="inline-block px-2 py-1 text-xs rounded-full text-white mb-2" 
                                      style="background-color: <?= htmlspecialchars($libro['categoria_colore'] ?? '#8B4513') ?>">
                                    <?= htmlspecialchars($libro['categoria_nome']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <?php 
                            $stato_icons = [
                                'disponibile' => '📗',
                                'prestato' => '📘', 
                                'manutenzione' => '🔧',
                                'perso' => '❌'
                            ];
                            $grado_icons = [
                                'pubblico' => '🌍',
                                'Apprendista' => '🔺',
                                'Compagno' => '🔷', 
                                'Maestro' => '🔶'
                            ];
                            ?>
                            <div class="text-sm">
                                <?= $stato_icons[$libro['stato']] ?? '📖' ?>
                                <?= $grado_icons[$libro['grado_minimo']] ?? '' ?>
                            </div>
                        </div>
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
                    
                    <!-- Valutazione e recensioni -->
                    <?php if ($libro['num_recensioni'] > 0): ?>
                        <div class="flex items-center space-x-1 mb-2">
                            <?= mostraStelle($libro['voto_medio'], 'text-xs') ?>
                            <span class="text-xs text-gray-500">
                                <?= number_format($libro['voto_medio'], 1) ?> (<?= $libro['num_recensioni'] ?>)
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Footer con azioni -->
                <div class="p-4 pt-0">
                    <div class="flex space-x-2">
                        <a href="libro-dettaglio.php?id=<?= $libro['id'] ?>" 
                           class="bg-primary hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-xs transition flex-1 text-center font-medium">
                            📖 Dettagli
                        </a>
                        
                        <?php if ($libro['stato'] == 'disponibile'): ?>
                            <?php if ($is_admin): ?>
                                <button onclick="prestaLibro(<?= $libro['id'] ?>)" 
                                        class="bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-xs transition font-medium">
                                    ✋ Presta
                                </button>
                            <?php else: ?>
                                <button onclick="prenotaLibro(<?= $libro['id'] ?>)" 
                                        class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-2 rounded-lg text-xs transition font-medium">
                                    📅 Prenota
                                </button>
                            <?php endif; ?>
                        <?php elseif ($libro['stato'] == 'prestato' && $is_admin): ?>
                            <button onclick="restituisciLibro(<?= $libro['id'] ?>)" 
                                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-xs transition font-medium">
                                ↩️ Restituisci
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Messaggio se nessun risultato -->
    <?php if (empty($libri)): ?>
        <div class="bg-white rounded-xl shadow-lg p-8 text-center">
            <div class="text-6xl mb-4">📚</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Nessun libro trovato</h2>
            <p class="text-gray-600 mb-6">Prova a modificare i filtri di ricerca</p>
            <a href="catalogo.php" class="bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                🔄 Mostra tutti i libri
            </a>
        </div>
  <?php endif; ?>

    <script>
        // FIX: Percorso corretto per l'API
        function prenotaLibro(libroId) {
            const cardElement = document.querySelector(`[data-libro-id="${libroId}"]`);
            const titolo = cardElement ? cardElement.querySelector('h3').textContent : 'questo libro';
            
            if (confirm(`Vuoi richiedere in prestito "${titolo}"?\n\nUn amministratore esaminerà la tua richiesta.`)) {
                // Mostra loading
                const button = document.querySelector(`[onclick="prenotaLibro(${libroId})"]`);
                const originalText = button.innerHTML;
                button.innerHTML = '⏳ Invio...';
                button.disabled = true;
                
                fetch('../api/prestiti.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'richiedi_prestito',
                        libro_id: libroId,
                        giorni_richiesti: 30,
                        note: ''
                    })
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    
                    if (data.success) {
                        alert('✅ ' + data.message);
                        // Aggiorna il pulsante per mostrare che è stata fatta la richiesta
                        button.innerHTML = '✅ Richiesta Inviata';
                        button.className = button.className.replace('bg-orange-500 hover:bg-orange-600', 'bg-green-500 cursor-not-allowed');
                        button.onclick = null;
                    } else {
                        alert('❌ ' + data.message);
                        // Ripristina il pulsante
                        button.innerHTML = originalText;
                        button.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('❌ Errore di connessione. Riprova più tardi.');
                    // Ripristina il pulsante
                    button.innerHTML = originalText;
                    button.disabled = false;
                });
            }
        }

        function prestaLibro(libroId) {
            const fratelloId = prompt('Inserisci l\'ID del fratello:');
            if (fratelloId && !isNaN(fratelloId)) {
                fetch('../api/prestiti.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'nuovo_prestito',
                        libro_id: libroId,
                        fratello_id: parseInt(fratelloId),
                        giorni_prestito: 30,
                        note: 'Prestito diretto da catalogo'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        location.reload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(error => {
                    alert('❌ Errore di connessione');
                    console.error('Error:', error);
                });
            }
        }

        function restituisciLibro(libroId) {
            if (confirm('Confermi la restituzione del libro?')) {
                fetch('../api/prestiti.php', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'restituisci',
                        libro_id: libroId,
                        stato_rientro: 'buono',
                        note_restituzione: 'Restituzione da catalogo'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        location.reload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(error => {
                    alert('❌ Errore di connessione');
                    console.error('Error:', error);
                });
            }
        }

        // Lazy loading per prestazioni migliori
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.classList.remove('lazy');
                            imageObserver.unobserve(img);
                        }
                    }
                });
            });

            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }

        // Debug info
        console.log('📚 Catalogo caricato con', <?= count($libri) ?>, 'libri');
        console.log('🎨 Copertine in formato VERTICALE corretto!');
        console.log('✅ Sistema richieste prestito attivo');
        console.log('🔧 Percorso API: ../api/prestiti.php');
        console.log('🌐 URL corrente:', window.location.href);
        console.log('👤 Utente ID:', <?= $_SESSION['fratello_id'] ?? 'null' ?>);
        console.log('⚡ Admin:', <?= $is_admin ? 'true' : 'false' ?>);
    </script>
</body>
</html>