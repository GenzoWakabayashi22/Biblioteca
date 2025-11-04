<?php
session_start();
require_once '../../config/database.php';

// Verifica sessione
verificaSessioneAttiva();

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

// Verifica autenticazione admin
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
$is_admin = in_array($_SESSION['fratello_id'], $admin_ids);

if (!$is_admin) {
    header('Location: ../dashboard.php');
    exit;
}

// Gestione aggiornamento copertina
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'aggiorna_copertina') {
        $libro_id = (int)$_POST['libro_id'];
        $copertina_url = trim($_POST['copertina_url']);
        
        // Validazione URL
        if (!empty($copertina_url) && !filter_var($copertina_url, FILTER_VALIDATE_URL)) {
            $message = 'URL non valido!';
        } else {
            $update_query = "UPDATE libri SET copertina_url = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("si", $copertina_url, $libro_id);
            
            if ($stmt->execute()) {
                $message = 'Copertina aggiornata con successo!';
            } else {
                $message = 'Errore nell\'aggiornamento della copertina.';
            }
        }
    }
}

// Recupera libri (con filtro opzionale)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filtro_copertina = isset($_GET['filtro']) ? $_GET['filtro'] : 'tutti';

$where_conditions = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where_conditions[] = "(l.titolo LIKE ? OR l.autore LIKE ?)";
    $search_param = '%' . $search . '%';
    $params = [$search_param, $search_param];
    $types = 'ss';
}

if ($filtro_copertina == 'senza_copertina') {
    $where_conditions[] = "(l.copertina_url IS NULL OR l.copertina_url = '')";
} elseif ($filtro_copertina == 'con_copertina') {
    $where_conditions[] = "(l.copertina_url IS NOT NULL AND l.copertina_url != '')";
}

$where_sql = 'WHERE ' . implode(' AND ', $where_conditions);

$libri_query = "
    SELECT l.id, l.titolo, l.autore, l.copertina_url, c.nome as categoria_nome
    FROM libri l 
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    {$where_sql}
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
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Copertine - R∴ L∴ Kilwinning</title>
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
                <h1 class="text-3xl font-bold text-gray-800">🖼️ Gestione Copertine</h1>
                <p class="text-gray-600">Aggiungi o modifica URL copertine libri</p>
            </div>
            <div class="flex flex-wrap gap-2 mt-4 md:mt-0">
                <a href="../catalogo.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                    📚 Catalogo
                </a>
                <a href="../dashboard.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                    🏠 Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Messaggio -->
    <?php if ($message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Filtri -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-gray-700 text-sm font-medium mb-2">Cerca Libro</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Titolo o autore..." 
                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-gray-700 text-sm font-medium mb-2">Filtro Copertine</label>
                <select name="filtro" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="tutti" <?= $filtro_copertina == 'tutti' ? 'selected' : '' ?>>Tutti i libri</option>
                    <option value="senza_copertina" <?= $filtro_copertina == 'senza_copertina' ? 'selected' : '' ?>>Senza copertina</option>
                    <option value="con_copertina" <?= $filtro_copertina == 'con_copertina' ? 'selected' : '' ?>>Con copertina</option>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition w-full">
                    🔍 Filtra
                </button>
            </div>
        </form>
    </div>

    <!-- Lista libri -->
    <div class="space-y-4">
        <?php foreach ($libri as $libro): ?>
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    <!-- Copertina attuale -->
                    <div class="lg:col-span-1">
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Copertina Attuale</h3>
                        <?php if (!empty($libro['copertina_url'])): ?>
                            <div class="w-full h-40 rounded-lg overflow-hidden mb-2 shadow-sm">
                                <img src="<?= htmlspecialchars($libro['copertina_url']) ?>" 
                                     alt="Copertina <?= htmlspecialchars($libro['titolo']) ?>"
                                     class="w-full h-full object-cover"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="w-full h-40 bg-gray-200 rounded-lg items-center justify-center hidden">
                                    <div class="text-center text-gray-500">
                                        <div class="text-2xl mb-1">❌</div>
                                        <div class="text-xs">URL non valido</div>
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 break-all"><?= htmlspecialchars($libro['copertina_url']) ?></p>
                        <?php else: ?>
                            <div class="w-full h-40 bg-gray-200 rounded-lg flex items-center justify-center">
                                <div class="text-center text-gray-500">
                                    <div class="text-2xl mb-1">📖</div>
                                    <div class="text-xs">Nessuna copertina</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Informazioni libro -->
                    <div class="lg:col-span-1">
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Informazioni Libro</h3>
                        <div class="space-y-2">
                            <p class="font-semibold text-gray-900"><?= htmlspecialchars($libro['titolo']) ?></p>
                            <p class="text-gray-600"><?= htmlspecialchars($libro['autore'] ?? 'Autore non specificato') ?></p>
                            <?php if ($libro['categoria_nome']): ?>
                                <p class="text-sm text-gray-500">📚 <?= htmlspecialchars($libro['categoria_nome']) ?></p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-400">ID: <?= $libro['id'] ?></p>
                        </div>
                    </div>
                    
                    <!-- Form aggiornamento -->
                    <div class="lg:col-span-2">
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Aggiorna Copertina</h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="aggiorna_copertina">
                            <input type="hidden" name="libro_id" value="<?= $libro['id'] ?>">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">URL Copertina</label>
                                <input type="url" name="copertina_url" 
                                       value="<?= htmlspecialchars($libro['copertina_url'] ?? '') ?>" 
                                       placeholder="https://esempio.com/copertina.jpg"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <p class="text-xs text-gray-500 mt-1">
                                    💡 Consigli: Usa URL diretti da Google Libri, Amazon, o siti editoriali. 
                                    Formati supportati: JPG, PNG, WebP
                                </p>
                            </div>
                            
                            <div class="flex space-x-2">
                                <button type="submit" 
                                        class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                                    💾 Aggiorna
                                </button>
                                
                                <?php if (!empty($libro['copertina_url'])): ?>
                                    <button type="button" 
                                            onclick="rimuoviCopertina(<?= $libro['id'] ?>)"
                                            class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition">
                                        🗑️ Rimuovi
                                    </button>
                                <?php endif; ?>
                                
                                <button type="button" 
                                        onclick="cercaCopertinaGoogle('<?= addslashes($libro['titolo']) ?>', '<?= addslashes($libro['autore'] ?? '') ?>')"
                                        class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                                    🔍 Cerca Google
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Messaggio se nessun libro -->
    <?php if (empty($libri)): ?>
        <div class="bg-white rounded-xl shadow-lg p-8 text-center">
            <div class="text-6xl mb-4">📚</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Nessun libro trovato</h2>
            <p class="text-gray-600">Modifica i filtri di ricerca</p>
        </div>
    <?php endif; ?>

    <script>
        function rimuoviCopertina(libroId) {
            if (confirm('Sei sicuro di voler rimuovere la copertina?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="aggiorna_copertina">
                    <input type="hidden" name="libro_id" value="${libroId}">
                    <input type="hidden" name="copertina_url" value="">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function cercaCopertinaGoogle(titolo, autore) {
            const query = `${titolo} ${autore} copertina libro`.trim();
            const url = `https://www.google.com/search?q=${encodeURIComponent(query)}&tbm=isch`;
            window.open(url, '_blank');
        }

        // Auto-preview URL mentre si digita
        document.querySelectorAll('input[type="url"]').forEach(input => {
            input.addEventListener('input', function() {
                const url = this.value.trim();
                if (url && isValidUrl(url)) {
                    // Potresti aggiungere qui una preview in tempo reale
                    console.log('URL valido:', url);
                }
            });
        });

        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        }

        // Suggerimenti per URL comuni
        function suggerisciURL(titolo, autore) {
            const suggestions = [
                `https://covers.openlibrary.org/b/title/${encodeURIComponent(titolo)}-L.jpg`,
                `https://books.google.com/books/content?id=...&printsec=frontcover&img=1&zoom=1`,
                `https://images-na.ssl-images-amazon.com/images/P/...._SX331_BO1,204,203,200_.jpg`
            ];
            
            return suggestions;
        }
    </script>
</body>
</html>