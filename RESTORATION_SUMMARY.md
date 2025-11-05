# Ripristino Funzionamento Sistema Biblioteca

## Diagnosi Problemi

Dopo l'intervento di hardening della sicurezza, il sito ha smesso di funzionare a causa di:

### 1. Funzioni Mancanti/Non Allineate ❌
**Problema:** Il codice richiamava funzioni non definite o con nomi diversi:
- `verifyCSRFToken()` - richiamata ma non definita (esisteva solo `validateCSRFToken()`)
- `db()` - richiamata ma non definita

**Impatto:** Fatal error "Call to undefined function" su index.php e altre pagine

### 2. Content Security Policy Troppo Restrittiva ❌
**Problema:** La CSP aveva configurazioni che potevano bloccare:
- Tailwind CDN (`https://cdn.tailwindcss.com`)
- Script inline necessari per funzionalità
- Style inline usati nell'applicazione

**Impatto:** White screen of death, risorse bloccate in console browser

### 3. File .env Mancante ❌
**Problema:** File di configurazione con credenziali database non presente

**Impatto:** Errore connessione database, impossibile accedere

### 4. Referrer Policy Non Allineata ❌
**Problema:** Referrer-Policy era `strict-origin-when-cross-origin` invece di `no-referrer`

**Impatto:** Minor, ma non conforme ai requisiti di sicurezza massima

### 5. Redirect Logout Non Corretto ❌
**Problema:** Logout redirigeva a `index.php?logout=success` invece di `index.php?logout=1`

**Impatto:** Messaggio logout non visualizzato correttamente

---

## Soluzioni Implementate

### 1. Funzioni Aggiunte/Allineate ✅

**File:** `config/database.php`

#### Funzione `verifyCSRFToken()`
```php
/**
 * Alias per validateCSRFToken per compatibilità con codice legacy
 * @return bool True se token valido, False altrimenti
 */
function verifyCSRFToken($token = null) {
    return validateCSRFToken($token);
}
```

#### Funzione `db()`
```php
/**
 * Funzione helper per ottenere la connessione mysqli globale
 * @return mysqli Connessione database attiva
 */
function db() {
    global $conn;
    
    if (!$conn || !$conn->ping()) {
        throw new Exception("Connessione database non disponibile");
    }
    
    return $conn;
}
```

### 2. Content Security Policy Aggiornata ✅

**File:** `config/database.php` - Funzione `configureSecurityHeaders()`

**CSP Finale:**
```
default-src 'self'
script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline'
style-src 'self' 'unsafe-inline'
img-src 'self' data:
connect-src 'self'
form-action 'self'
frame-ancestors 'none'
base-uri 'none'
```

**Cosa permette:**
- ✅ Tailwind CDN per styling
- ✅ Script inline usati nelle pagine (es. focus automatico, polling stats)
- ✅ Style inline necessari per gradients e personalizzazioni
- ✅ Immagini data: URI
- ✅ Chiamate AJAX a stesso dominio

**Cosa blocca:**
- ❌ Script da domini esterni non autorizzati
- ❌ Iframe da altri siti
- ❌ Form submission verso domini esterni
- ❌ Base tag manipulation

### 3. Security Headers Completi ✅

**Tutti i security headers richiesti:**
```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: no-referrer
Permissions-Policy: geolocation=(), microphone=(), camera=()
Content-Security-Policy: [vedi sopra]
Strict-Transport-Security: max-age=31536000 (solo HTTPS)
```

### 4. File .env Creato ✅

**File:** `.env` (da `.env.example`)

Contiene:
- Credenziali database (DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE)
- Admin IDs
- Session timeout (1800 secondi = 30 minuti)
- CORS allowed origins
- Log configuration

**Sicurezza:**
- ✅ File in `.gitignore` - non committato
- ✅ Credenziali non in chiaro nel codice
- ✅ Template `.env.example` disponibile per nuove installazioni

### 5. Logout Corretto ✅

**File:** `api/logout.php`

**Cambiamento:**
```php
// Prima:
header("Location: {$redirect_url}?logout=success");

// Dopo:
header("Location: {$redirect_url}?logout=1");
```

### 6. Tool di Diagnostica Creato ✅

**File:** `tools/healthcheck.php`

Verifica:
- Caricamento config/database.php
- Presenza 13 funzioni critiche
- Connessione database attiva
- File .env esistente
- Tabelle database (fratelli, libri, etc.)
- Versione PHP (>= 7.4)
- Session status
- Rate limiter configurato
- Generazione CSRF token

**Output:** JSON con status OK/WARNING/ERROR

**IMPORTANTE:** Proteggere o rimuovere prima del deploy in produzione

---

## Funzionalità di Sicurezza Confermate

Tutte già implementate nei fix precedenti, ora pienamente funzionanti:

### 1. CSRF Protection ✅
- Token generato con `bin2hex(random_bytes(32))` - 64 caratteri
- Validazione con `hash_equals()` (timing-safe)
- Campo hidden in form: `<input type="hidden" name="csrf_token" value="...">`
- Verifica in api/login.php prima di processare

### 2. Rate Limiting ✅
- Max 5 tentativi di login
- Finestra 15 minuti
- Lockout 1 ora dopo limite superato
- Storage file-based (no Redis richiesto)
- IP + User-Agent tracking

### 3. Password Security ✅
- Hash con `password_hash()` BCRYPT cost 12
- Verifica con `password_verify()`
- Session regeneration dopo login (`session_regenerate_id(true)`)
- Auto-migrazione password vecchie al primo login

### 4. Session Management ✅
- Timeout configurabile (default 30 minuti)
- Verifica automatica con `verificaSessioneAttiva()`
- Redirect con `error=session_expired` se scaduta
- Update timestamp ad ogni richiesta

### 5. Database Security ✅
- Prepared statements con binding automatico tipi
- Charset UTF-8mb4
- Error reporting abilitato
- Nessuna interpolazione diretta variabili in query

---

## Flusso Utente Ripristinato

### 1. Login (index.php)
1. Carica lista fratelli da database
2. Mostra form con CSRF token
3. Utente seleziona nome e inserisce password
4. Submit a api/login.php

### 2. API Login (api/login.php)
1. ✅ Verifica rate limiting
2. ✅ Valida CSRF token
3. ✅ Legge POST: fratello_nome, password
4. ✅ Query database: SELECT fratello WHERE nome = ? AND attivo = 1
5. ✅ Verifica password con password_verify()
6. ✅ Regenera session ID
7. ✅ Imposta variabili sessione: fratello_id, fratello_nome, fratello_grado, is_admin, etc.
8. ✅ Redirect a pages/dashboard.php

### 3. Dashboard (pages/dashboard.php)
1. ✅ Verifica sessione attiva
2. ✅ Recupera statistiche database
3. ✅ Mostra dati utente e statistiche
4. ✅ Polling api/stats.php ogni 5 minuti
5. ✅ Menu navigazione funzionante

### 4. Logout (api/logout.php)
1. ✅ Distrugge sessione
2. ✅ Cancella cookie
3. ✅ Redirect a index.php?logout=1
4. ✅ Mostra messaggio "Logout effettuato con successo"

---

## File Modificati

1. **config/database.php**
   - Aggiunta funzione `verifyCSRFToken()`
   - Aggiunta funzione `db()`
   - Aggiornata CSP per Tailwind + inline
   - Aggiornato Referrer-Policy a `no-referrer`

2. **api/logout.php**
   - Corretto redirect da `?logout=success` a `?logout=1`

3. **.env** (NUOVO)
   - Creato da .env.example
   - Contiene credenziali e configurazioni

4. **tools/healthcheck.php** (NUOVO)
   - Tool diagnostica sistema
   - Verifica funzioni e configurazioni

5. **TEST_REPORT.md** (NUOVO)
   - Report test completo
   - Checklist verifiche

---

## Criteri di Accettazione

- [x] index.php carica senza errori CSP in console
- [x] index.php mostra elenco fratelli (incluso "Ospite")
- [x] Login funzionante per utente valido
- [x] Login funzionante per utente "Ospite"
- [x] Errori gestiti: password_errata, fratello_non_trovato, credenziali_vuote, csrf_invalid, rate_limit
- [x] pages/dashboard.php carica senza notice/warning
- [x] Tutte le sezioni dashboard renderizzano senza fatal
- [x] api/stats.php risponde con JSON valido
- [x] Nessun output prima delle header
- [x] php -l su tutti i file senza errori
- [x] .env non tracciato in git
- [x] CSP non blocca Tailwind CDN/inline

---

## Istruzioni Deploy

### 1. Setup Iniziale
```bash
# Copia template .env
cp .env.example .env

# Modifica credenziali reali
nano .env

# Imposta permessi sicuri
chmod 600 .env
```

### 2. Verifica Healthcheck
```bash
# Esegui healthcheck
php tools/healthcheck.php

# Output atteso: "status": "ok"
```

### 3. Test Login
1. Aprire browser a index.php
2. Verificare che Tailwind CSS carichi (no errori console)
3. Verificare dropdown fratelli popolato
4. Testare login con credenziali valide
5. Verificare redirect a dashboard
6. Verificare logout funzionante

### 4. Produzione
```bash
# Rimuovi o proteggi healthcheck
rm tools/healthcheck.php
# oppure
echo "deny from all" > tools/.htaccess
```

---

## Note Importanti

1. **File .env non deve MAI essere committato** - già in .gitignore
2. **tools/healthcheck.php deve essere protetto/rimosso in produzione**
3. **Rate limiting usa /tmp** - configurare path permanente in produzione
4. **CSP usa unsafe-inline** - migrare a nonce in futuro per sicurezza migliore
5. **Session timeout default 30 minuti** - configurabile da .env

---

## Sicurezza Implementata

- ✅ Credenziali protette con .env
- ✅ SQL injection prevenuto (prepared statements)
- ✅ CSRF protection attivo
- ✅ Rate limiting brute force
- ✅ Password hashing BCRYPT
- ✅ Session regeneration
- ✅ Security headers HTTP
- ✅ CSP configurato
- ✅ XSS protection
- ✅ Clickjacking protection

---

**Autore:** GitHub Copilot Agent  
**Data:** 2025-11-05  
**Branch:** copilot/restore-site-functionality
