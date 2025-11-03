<?php
// admin/gestione-categorie.php
session_start();

// Connessione database
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
if (!isset($_SESSION['fratello_id']) || empty($_SESSION['fratello_id'])) {
    header('Location: ../index.php');
    exit;
}

// Verifica permessi admin
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
if (!in_array($_SESSION['fratello_id'], $admin_ids)) {
    header('Location: ../dashboard.php?error=no_permissions');
    exit;
}

// Variabili per messaggi
$message = '';
$error = '';
$message_type = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'aggiungi_categoria') {
        $nome = trim($_POST['nome'] ?? '');
        $colore = $_POST['colore'] ?? '#6366f1';
        $ordine = (int)($_POST['ordine'] ?? 999);
        
        if (empty($nome)) {
            $error = 'Il nome della categoria è obbligatorio';
        } else {
            // Verifica che il nome non esista già
            $check_stmt = $conn->prepare("SELECT id FROM categorie_libri WHERE nome = ?");
            $check_stmt->bind_param("s", $nome);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();
            
            if ($exists) {
                $error = "Una categoria con questo nome esiste già";
            } else {
                $stmt = $conn->prepare("INSERT INTO categorie_libri (nome, colore, ordine, attiva, created_at) VALUES (?, ?, ?, 1, NOW())");
                $stmt->bind_param("ssi", $nome, $colore, $ordine);
                
                if ($stmt->execute()) {
                    $message = "✅ Categoria '$nome' aggiunta con successo!";
                    $message_type = 'success';
                    error_log("ADMIN: Categoria '$nome' aggiunta da admin ID " . $_SESSION['fratello_id']);
                } else {
                    $error = "Errore nell'aggiunta della categoria: " . $conn->error;
                }
            }
        }
    }
    
    elseif ($action === 'modifica_categoria') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $colore = $_POST['colore'] ?? '#6366f1';
        $ordine = (int)($_POST['ordine'] ?? 999);
        $attiva = isset($_POST['attiva']) ? 1 : 0;
        
        if ($id <= 0) {
            $error = 'ID categoria non valido';
        } elseif (empty($nome)) {
            $error = 'Il nome della categoria è obbligatorio';
        } else {
            // Verifica che il nome non esista già (escludendo la categoria corrente)
            $check_stmt = $conn->prepare("SELECT id FROM categorie_libri WHERE nome = ? AND id != ?");
            $check_stmt->bind_param("si", $nome, $id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();
            
            if ($exists) {
                $error = "Una categoria con questo nome esiste già";
            } else {
                $stmt = $conn->prepare("UPDATE categorie_libri SET nome = ?, colore = ?, ordine = ?, attiva = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssiii", $nome, $colore, $ordine, $attiva, $id);
                
                if ($stmt->execute()) {
                    $message = "✅ Categoria aggiornata con successo!";
                    $message_type = 'success';
                    error_log("ADMIN: Categoria ID $id modificata da admin ID " . $_SESSION['fratello_id']);
                } else {
                    $error = "Errore nella modifica della categoria: " . $conn->error;
                }
            }
        }
    }
    
    elseif ($action === 'elimina_categoria') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            $error = 'ID categoria non valido';
        } else {
            // Verifica se ci sono libri che usano questa categoria
            $check_libri = $conn->prepare("SELECT COUNT(*) as count FROM libri WHERE categoria_id = ?");
            $check_libri->bind_param("i", $id);
            $check_libri->execute();
            $libri_count = $check_libri->get_result()->fetch_assoc()['count'];
            
            if ($libri_count > 0) {
                $error = "Impossibile eliminare la categoria: è utilizzata da $libri_count libri. Trasferisci prima i libri ad altre categorie.";
            } else {
                $stmt = $conn->prepare("DELETE FROM categorie_libri WHERE id = ?");
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $message = "🗑️ Categoria eliminata con successo!";
                    $message_type = 'success';
                    error_log("ADMIN: Categoria ID $id eliminata da admin ID " . $_SESSION['fratello_id']);
                } else {
                    $error = "Errore nell'eliminazione della categoria: " . $conn->error;
                }
            }
        }
    }
    
    elseif ($action === 'aggiorna_ordine') {
        $ordini = $_POST['ordini'] ?? [];
        
        foreach ($ordini as $id => $ordine) {
            $id = (int)$id;
            $ordine = (int)$ordine;
            
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE categorie_libri SET ordine = ? WHERE id = ?");
                $stmt->bind_param("ii", $ordine, $id);
                $stmt->execute();
            }
        }
        
        $message = "📋 Ordine categorie aggiornato!";
        $message_type = 'success';
        error_log("ADMIN: Ordine categorie aggiornato da admin ID " . $_SESSION['fratello_id']);
    }
}

// Recupera tutte le categorie
$categorie_query = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM libri WHERE categoria_id = c.id) as libri_count
    FROM categorie_libri c
    ORDER BY c.ordine ASC, c.nome ASC
";
$categorie_result = $conn->query($categorie_query);
$categorie = [];
if ($categorie_result) {
    while ($row = $categorie_result->fetch_assoc()) {
        $categorie[] = $row;
    }
}

// Statistiche
$stats = [
    'totale_categorie' => count($categorie),
    'attive' => count(array_filter($categorie, fn($c) => $c['attiva'] == 1)),
    'inattive' => count(array_filter($categorie, fn($c) => $c['attiva'] == 0)),
    'totale_libri' => array_sum(array_column($categorie, 'libri_count'))
];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Categorie - Admin | R∴ L∴ Kilwinning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#6366f1',
                        secondary: '#8b5cf6'
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
        .sortable-ghost { opacity: 0.4; }
        .sortable-chosen { transform: scale(1.02); }
    </style>
</head>
<body class="p-4">
    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
                <nav class="text-sm text-gray-500 mb-2">
                    <a href="../dashboard.php" class="hover:text-primary">🏠 Dashboard</a>
                    <span class="mx-2">→</span>
                    <a href="gestione-libri.php" class="hover:text-primary">📚 Gestione Libri</a>
                    <span class="mx-2">→</span>
                    <span class="text-gray-800">🏷️ Gestione Categorie</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">🏷️ Gestione Categorie Libri</h1>
                <p class="text-gray-600">Amministra le categorie: aggiungi, modifica, riordina ed elimina</p>
            </div>
            <div class="mt-4 md:mt-0 flex flex-wrap gap-3">
                <a href="gestione-libri.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                    📚 Gestione Libri
                </a>
                <a href="../catalogo.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                    📖 Catalogo
                </a>
                <a href="../dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                    ↩️ Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Messaggi -->
    <?php if ($message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Statistiche -->
        <div class="lg:col-span-3">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center">
                        <div class="bg-primary text-white p-3 rounded-lg">🏷️</div>
                        <div class="ml-4">
                            <p class="text-gray-600 text-sm">Totale Categorie</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats['totale_categorie'] ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center">
                        <div class="bg-green-500 text-white p-3 rounded-lg">✅</div>
                        <div class="ml-4">
                            <p class="text-gray-600 text-sm">Categorie Attive</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats['attive'] ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center">
                        <div class="bg-gray-500 text-white p-3 rounded-lg">💤</div>
                        <div class="ml-4">
                            <p class="text-gray-600 text-sm">Categorie Inattive</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats['inattive'] ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <div class="flex items-center">
                        <div class="bg-blue-500 text-white p-3 rounded-lg">📚</div>
                        <div class="ml-4">
                            <p class="text-gray-600 text-sm">Totale Libri</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $stats['totale_libri'] ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Aggiungi Categoria -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">➕ Aggiungi Nuova Categoria</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="aggiungi_categoria">
                
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Nome Categoria *</label>
                    <input type="text" name="nome" required 
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                           placeholder="es. Narrativa, Saggistica...">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Colore</label>
                    <div class="flex space-x-2">
                        <input type="color" name="colore" value="#6366f1" 
                               class="w-16 h-10 border border-gray-300 rounded cursor-pointer">
                        <input type="text" name="colore_text" value="#6366f1" 
                               class="flex-1 p-2 border border-gray-300 rounded-lg text-sm"
                               placeholder="#6366f1">
                    </div>
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Ordine</label>
                    <input type="number" name="ordine" value="<?= count($categorie) + 1 ?>" min="1"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <p class="text-xs text-gray-500 mt-1">Numero più basso = posizione più alta</p>
                </div>
                
                <button type="submit" class="w-full bg-primary hover:bg-blue-600 text-white py-3 rounded-lg transition font-medium">
                    ➕ Aggiungi Categoria
                </button>
            </form>
        </div>

        <!-- Lista Categorie -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-lg p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-gray-800">📋 Categorie Esistenti</h2>
                <div class="text-sm text-gray-600">
                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded">Trascinabile</span>
                </div>
            </div>

            <?php if (empty($categorie)): ?>
                <div class="text-center py-8">
                    <div class="text-6xl mb-4">🏷️</div>
                    <p class="text-gray-600">Nessuna categoria presente</p>
                    <p class="text-gray-500 text-sm">Inizia aggiungendo la prima categoria!</p>
                </div>
            <?php else: ?>
                <div id="categorieList" class="space-y-3">
                    <?php foreach ($categorie as $categoria): ?>
                        <div class="categoria-item border rounded-lg p-4 cursor-move" 
                             data-id="<?= $categoria['id'] ?>"
                             style="border-left: 4px solid <?= htmlspecialchars($categoria['colore']) ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-4 h-4 rounded-full" style="background-color: <?= htmlspecialchars($categoria['colore']) ?>"></div>
                                    <div>
                                        <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($categoria['nome']) ?></h3>
                                        <p class="text-sm text-gray-600">
                                            📚 <?= $categoria['libri_count'] ?> libri | 
                                            🔢 Ordine: <?= $categoria['ordine'] ?> | 
                                            <?= $categoria['attiva'] ? '✅ Attiva' : '💤 Inattiva' ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="modificaCategoria(<?= $categoria['id'] ?>)" 
                                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm transition">
                                        ✏️
                                    </button>
                                    <button onclick="eliminaCategoria(<?= $categoria['id'] ?>, '<?= htmlspecialchars($categoria['nome'], ENT_QUOTES) ?>', <?= $categoria['libri_count'] ?>)" 
                                            class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm transition">
                                        🗑️
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-6 pt-4 border-t">
                    <button onclick="salvaOrdine()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                        💾 Salva Nuovo Ordine
                    </button>
                    <p class="text-xs text-gray-500 mt-2">Trascina le categorie per riordinarle, poi clicca "Salva Nuovo Ordine"</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Modifica Categoria -->
    <div id="modalModifica" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl max-w-md w-full p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">✏️ Modifica Categoria</h3>
                <form id="formModifica" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="modifica_categoria">
                    <input type="hidden" id="modifica_id" name="id">
                    
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Nome Categoria *</label>
                        <input type="text" id="modifica_nome" name="nome" required 
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Colore</label>
                        <input type="color" id="modifica_colore" name="colore" 
                               class="w-full h-10 border border-gray-300 rounded cursor-pointer">
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Ordine</label>
                        <input type="number" id="modifica_ordine" name="ordine" min="1"
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="modifica_attiva" name="attiva" class="mr-2">
                        <label for="modifica_attiva" class="text-gray-700">Categoria attiva</label>
                    </div>
                    
                    <div class="flex space-x-3">
                        <button type="submit" class="flex-1 bg-primary hover:bg-blue-600 text-white py-3 rounded-lg transition font-medium">
                            💾 Salva Modifiche
                        </button>
                        <button type="button" onclick="chiudiModale()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition">
                            ❌
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Dati categorie per JavaScript
        const categorie = <?= json_encode($categorie) ?>;
        
        // Inizializza Sortable
        const sortable = Sortable.create(document.getElementById('categorieList'), {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen'
        });

        // Sincronizza input colore e testo
        const colorInput = document.querySelector('input[name="colore"]');
        const colorTextInput = document.querySelector('input[name="colore_text"]');
        
        colorInput.addEventListener('change', () => {
            colorTextInput.value = colorInput.value;
        });
        
        colorTextInput.addEventListener('input', () => {
            if (/^#[0-9A-F]{6}$/i.test(colorTextInput.value)) {
                colorInput.value = colorTextInput.value;
            }
        });

        function modificaCategoria(id) {
            const categoria = categorie.find(c => c.id == id);
            if (!categoria) return;
            
            document.getElementById('modifica_id').value = categoria.id;
            document.getElementById('modifica_nome').value = categoria.nome;
            document.getElementById('modifica_colore').value = categoria.colore;
            document.getElementById('modifica_ordine').value = categoria.ordine;
            document.getElementById('modifica_attiva').checked = categoria.attiva == 1;
            
            document.getElementById('modalModifica').classList.remove('hidden');
        }

        function chiudiModale() {
            document.getElementById('modalModifica').classList.add('hidden');
        }

        function eliminaCategoria(id, nome, libriCount) {
            if (libriCount > 0) {
                alert(`❌ Impossibile eliminare la categoria "${nome}".\n\nQuesta categoria è utilizzata da ${libriCount} libri.\nTrasferisci prima i libri ad altre categorie.`);
                return;
            }
            
            if (confirm(`🗑️ Sei sicuro di voler eliminare la categoria "${nome}"?\n\nQuesta operazione è irreversibile.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'elimina_categoria';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = id;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function salvaOrdine() {
            const items = document.querySelectorAll('.categoria-item');
            const ordini = {};
            
            items.forEach((item, index) => {
                const id = item.dataset.id;
                ordini[id] = index + 1;
            });
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'aggiorna_ordine';
            form.appendChild(actionInput);
            
            for (const [id, ordine] of Object.entries(ordini)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `ordini[${id}]`;
                input.value = ordine;
                form.appendChild(input);
            }
            
            document.body.appendChild(form);
            form.submit();
        }

        // Chiudi modale cliccando fuori
        document.getElementById('modalModifica').addEventListener('click', (e) => {
            if (e.target.id === 'modalModifica') {
                chiudiModale();
            }
        });
    </script>
</body>
</html>