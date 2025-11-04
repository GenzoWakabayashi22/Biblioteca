<?php
// admin/richieste-prestito.php
session_start();
require_once '../../config/database.php';

// Verifica sessione
verificaSessioneAttiva();

// Connessione database
$conn = new mysqli('localhost', 'jmvvznbb_tornate_user', 'Puntorosso22', 'jmvvznbb_tornate_db');
if ($conn->connect_error) {
    die("Errore connessione: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
// Gestione azioni POST per approvazione/rifiuto
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $richiesta_id = (int)($input['richiesta_id'] ?? 0);
    $note_admin = trim($input['note_admin'] ?? '');
    
    if ($action === 'approva_richiesta' && $richiesta_id > 0) {
        $stmt = $conn->prepare("
            UPDATE richieste_prestito 
            SET stato = 'approvata', note_admin = ?, data_risposta = NOW(), admin_id = ?
            WHERE id = ? AND stato = 'in_attesa'
        ");
        $stmt->bind_param("sii", $note_admin, $_SESSION['fratello_id'], $richiesta_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Richiesta approvata con successo']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Errore durante l\'approvazione: ' . $stmt->error]);
        }
        exit;
    }
    
    if ($action === 'rifiuta_richiesta' && $richiesta_id > 0) {
        $stmt = $conn->prepare("
            UPDATE richieste_prestito 
            SET stato = 'rifiutata', note_admin = ?, data_risposta = NOW(), admin_id = ?
            WHERE id = ? AND stato = 'in_attesa'
        ");
        $stmt->bind_param("sii", $note_admin, $_SESSION['fratello_id'], $richiesta_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Richiesta rifiutata con successo']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Errore durante il rifiuto: ' . $stmt->error]);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Azione non valida']);
    exit;
}
// Verifica admin
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca, Emiliano, Francesco
$is_admin = isset($_SESSION['fratello_id']) && in_array($_SESSION['fratello_id'], $admin_ids);

if (!$is_admin) {
    header('Location: ../dashboard.php');
    exit;
}

// Recupera richieste
$stato_filter = $_GET['stato'] ?? 'in_attesa';
$where_clause = $stato_filter ? "WHERE rp.stato = ?" : "";
$params = $stato_filter ? [$stato_filter] : [];

$sql = "
    SELECT rp.*, l.titolo, l.autore, l.copertina_url, l.stato as libro_stato,
           f.nome as fratello_nome, f.grado as fratello_grado, f.email as fratello_email,
           admin.nome as admin_nome
    FROM richieste_prestito rp
    JOIN libri l ON rp.libro_id = l.id
    JOIN fratelli f ON rp.fratello_id = f.id
    LEFT JOIN fratelli admin ON rp.admin_id = admin.id
    $where_clause
    ORDER BY 
        CASE WHEN rp.stato = 'in_attesa' THEN 0 ELSE 1 END,
        rp.data_richiesta DESC
";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param("s", ...$params);
}
$stmt->execute();
$richieste = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Conta richieste per stato
$count_sql = "
    SELECT stato, COUNT(*) as count
    FROM richieste_prestito
    GROUP BY stato
";
$count_result = $conn->query($count_sql);
$counts = [];
while ($row = $count_result->fetch_assoc()) {
    $counts[$row['stato']] = $row['count'];
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Richieste Prestito - Admin</title>
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
                    <a href="../dashboard.php" class="hover:text-primary">🏠 Dashboard</a>
                    <span class="mx-2">→</span>
                    <a href="gestione-libri.php" class="hover:text-primary">⚙️ Admin</a>
                    <span class="mx-2">→</span>
                    <span class="text-gray-800">Richieste Prestito</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">📋 Richieste Prestito</h1>
                <p class="text-gray-600">Gestisci le richieste di prestito dei fratelli</p>
            </div>
            <div class="flex flex-wrap gap-2 mt-4 md:mt-0">
                <a href="gestione-libri.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                    📚 Gestione Libri
                </a>
                <a href="gestione-prestiti.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                    📖 Gestione Prestiti
                </a>
            </div>
        </div>
    </div>

    <!-- Statistiche -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-lg p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">In Attesa</p>
                    <p class="text-2xl font-bold text-orange-600"><?= $counts['in_attesa'] ?? 0 ?></p>
                </div>
                <div class="text-orange-500 text-2xl">⏳</div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-lg p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Approvate</p>
                    <p class="text-2xl font-bold text-green-600"><?= $counts['approvata'] ?? 0 ?></p>
                </div>
                <div class="text-green-500 text-2xl">✅</div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-lg p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Rifiutate</p>
                    <p class="text-2xl font-bold text-red-600"><?= $counts['rifiutata'] ?? 0 ?></p>
                </div>
                <div class="text-red-500 text-2xl">❌</div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-lg p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Annullate</p>
                    <p class="text-2xl font-bold text-gray-600"><?= $counts['annullata'] ?? 0 ?></p>
                </div>
                <div class="text-gray-500 text-2xl">🚫</div>
            </div>
        </div>
    </div>

    <!-- Filtri -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex flex-wrap gap-2">
            <a href="?stato=in_attesa" 
               class="px-4 py-2 rounded-lg transition <?= $stato_filter == 'in_attesa' ? 'bg-orange-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                ⏳ In Attesa (<?= $counts['in_attesa'] ?? 0 ?>)
            </a>
            <a href="?stato=approvata" 
               class="px-4 py-2 rounded-lg transition <?= $stato_filter == 'approvata' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                ✅ Approvate (<?= $counts['approvata'] ?? 0 ?>)
            </a>
            <a href="?stato=rifiutata" 
               class="px-4 py-2 rounded-lg transition <?= $stato_filter == 'rifiutata' ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                ❌ Rifiutate (<?= $counts['rifiutata'] ?? 0 ?>)
            </a>
            <a href="?" 
               class="px-4 py-2 rounded-lg transition <?= !$stato_filter ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                📋 Tutte
            </a>
        </div>
    </div>

    <!-- Lista richieste -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-6">
            📋 Richieste <?= ucfirst($stato_filter) ?> (<?= count($richieste) ?>)
        </h2>

        <?php if (empty($richieste)): ?>
            <div class="text-center py-12">
                <div class="text-gray-500 text-6xl mb-4">📝</div>
                <h3 class="text-xl font-semibold text-gray-700 mb-2">Nessuna richiesta trovata</h3>
                <p class="text-gray-600">Non ci sono richieste di prestito con questo stato.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($richieste as $richiesta): ?>
                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <div class="flex flex-col md:flex-row md:items-center gap-4">
                            <!-- Copertina -->
                            <div class="flex-shrink-0">
                                <?php if ($richiesta['copertina_url']): ?>
                                    <img src="<?= htmlspecialchars($richiesta['copertina_url']) ?>" 
                                         alt="Copertina" 
                                         class="w-16 h-20 object-cover rounded-lg border">
                                <?php else: ?>
                                    <div class="w-16 h-20 bg-gray-200 rounded-lg flex items-center justify-center">
                                        <span class="text-gray-500 text-xl">📖</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Dettagli -->
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                                    <div class="flex-1">
                                        <h3 class="font-semibold text-gray-900 mb-1">
                                            <?= htmlspecialchars($richiesta['titolo']) ?>
                                        </h3>
                                        <p class="text-sm text-gray-600 mb-2">
                                            di <?= htmlspecialchars($richiesta['autore'] ?? 'Autore non specificato') ?>
                                        </p>
                                        
                                        <div class="flex flex-wrap items-center gap-4 text-sm text-gray-500">
                                            <span>👤 <?= htmlspecialchars($richiesta['fratello_nome']) ?></span>
                                            <span>🔰 <?= htmlspecialchars($richiesta['fratello_grado']) ?></span>
                                            <span>📅 <?= date('d/m/Y H:i', strtotime($richiesta['data_richiesta'])) ?></span>
                                            <span>⏱️ <?= $richiesta['giorni_richiesti'] ?> giorni</span>
                                        </div>
                                        
                                        <?php if ($richiesta['note_richiesta']): ?>
                                            <div class="mt-2 p-2 bg-blue-50 rounded text-sm">
                                                <strong>Note:</strong> <?= htmlspecialchars($richiesta['note_richiesta']) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($richiesta['stato'] != 'in_attesa'): ?>
                                            <div class="mt-2 p-2 bg-gray-50 rounded text-sm">
                                                <strong>Gestita da:</strong> <?= htmlspecialchars($richiesta['admin_nome']) ?>
                                                <span class="text-gray-500">
                                                    il <?= date('d/m/Y H:i', strtotime($richiesta['data_risposta'])) ?>
                                                </span>
                                                <?php if ($richiesta['note_admin']): ?>
                                                    <br><strong>Note admin:</strong> <?= htmlspecialchars($richiesta['note_admin']) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Azioni -->
                                    <div class="flex flex-col md:flex-row gap-2 mt-3 md:mt-0 md:ml-4">
                                        <!-- Stato -->
                                        <div class="flex items-center gap-2">
                                            <?php
                                            $stato_badges = [
                                                'in_attesa' => 'bg-orange-100 text-orange-800',
                                                'approvata' => 'bg-green-100 text-green-800',
                                                'rifiutata' => 'bg-red-100 text-red-800',
                                                'annullata' => 'bg-gray-100 text-gray-800'
                                            ];
                                            $stato_icons = [
                                                'in_attesa' => '⏳',
                                                'approvata' => '✅',
                                                'rifiutata' => '❌',
                                                'annullata' => '🚫'
                                            ];
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $stato_badges[$richiesta['stato']] ?>">
                                                <?= $stato_icons[$richiesta['stato']] ?> <?= ucfirst($richiesta['stato']) ?>
                                            </span>
                                        </div>

                                        <!-- Pulsanti azione -->
                                        <?php if ($richiesta['stato'] == 'in_attesa'): ?>
                                            <div class="flex gap-2">
                                                <button onclick="approvaRichiesta(<?= $richiesta['id'] ?>)" 
                                                        class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm transition">
                                                    ✅ Approva
                                                </button>
                                                <button onclick="rifiutaRichiesta(<?= $richiesta['id'] ?>)" 
                                                        class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm transition">
                                                    ❌ Rifiuta
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Dettagli libro -->
                                        <a href="../libro-dettaglio.php?id=<?= $richiesta['libro_id'] ?>" 
                                           class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm transition text-center">
                                            📖 Dettagli
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Stato libro -->
                                <div class="mt-2 flex items-center gap-2">
                                    <span class="text-xs text-gray-500">Stato libro:</span>
                                    <?php
                                    $libro_stati = [
                                        'disponibile' => 'text-green-600',
                                        'prestato' => 'text-orange-600',
                                        'manutenzione' => 'text-yellow-600',
                                        'perso' => 'text-red-600'
                                    ];
                                    ?>
                                    <span class="text-xs font-medium <?= $libro_stati[$richiesta['libro_stato']] ?? 'text-gray-600' ?>">
                                        <?= ucfirst($richiesta['libro_stato']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal per approvazione -->
    <div id="approva-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-xl p-6 max-w-md w-full">
            <h3 class="text-lg font-bold text-gray-900 mb-4">✅ Approva Richiesta</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Note admin (opzionale)</label>
                    <textarea id="note-approva" rows="3" 
                              class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                              placeholder="Eventuali note per l'approvazione..."></textarea>
                </div>
                <div class="flex gap-3">
                    <button onclick="confermaApprova()" 
                            class="flex-1 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                        ✅ Conferma Approvazione
                    </button>
                    <button onclick="chiudiModal()" 
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                        ❌ Annulla
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal per rifiuto -->
    <div id="rifiuta-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-xl p-6 max-w-md w-full">
            <h3 class="text-lg font-bold text-gray-900 mb-4">❌ Rifiuta Richiesta</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Motivo rifiuto *</label>
                    <textarea id="note-rifiuta" rows="3" 
                              class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500"
                              placeholder="Spiega il motivo del rifiuto..." required></textarea>
                </div>
                <div class="flex gap-3">
                    <button onclick="confermaRifiuta()" 
                            class="flex-1 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition">
                        ❌ Conferma Rifiuto
                    </button>
                    <button onclick="chiudiModal()" 
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                        🚫 Annulla
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let richiestaCorrente = null;

        function approvaRichiesta(richiestaId) {
            richiestaCorrente = richiestaId;
            document.getElementById('approva-modal').classList.remove('hidden');
            document.getElementById('note-approva').value = '';
        }

        function rifiutaRichiesta(richiestaId) {
            richiestaCorrente = richiestaId;
            document.getElementById('rifiuta-modal').classList.remove('hidden');
            document.getElementById('note-rifiuta').value = '';
        }

        function chiudiModal() {
            document.getElementById('approva-modal').classList.add('hidden');
            document.getElementById('rifiuta-modal').classList.add('hidden');
            richiestaCorrente = null;
        }

        function confermaApprova() {
            const note = document.getElementById('note-approva').value;
            
            if (!richiestaCorrente) return;
            
            fetch('', {  // Stesso file PHP
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'approva_richiesta',
                    richiesta_id: richiestaCorrente,
                    note_admin: note
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
            
            chiudiModal();
        }

        function confermaRifiuta() {
            const note = document.getElementById('note-rifiuta').value.trim();
            
            if (!note) {
                alert('⚠️ Devi specificare un motivo per il rifiuto');
                return;
            }
            
            if (!richiestaCorrente) return;
            
            fetch('', {  // Stesso file PHP
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'rifiuta_richiesta',
                    richiesta_id: richiestaCorrente,
                    note_admin: note
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
            
            chiudiModal();
        }

        // Chiudi modal cliccando fuori
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('fixed') && e.target.classList.contains('bg-black')) {
                chiudiModal();
            }
        });

        // Aggiorna automaticamente ogni 30 secondi
        setInterval(() => {
            if (<?= json_encode($stato_filter) ?> === 'in_attesa') {
                location.reload();
            }
        }, 30000);

        console.log('📋 Dashboard richieste prestito caricata');
        console.log('🔍 Filtro attivo:', <?= json_encode($stato_filter) ?>);
        console.log('📊 Richieste trovate:', <?= count($richieste) ?>);
    </script>
</body>
</html>