# 🔧 Fix Applicazione - Guida di Ripristino

## 🔴 PROBLEMA
Dopo le modifiche di sicurezza, l'applicazione non funzionava più perché mancava il file `.env` con le credenziali del database.

## ✅ SOLUZIONE APPLICATA

### 1. Creato File `.env`
Il file `.env` è stato creato copiando `.env.example` con le credenziali corrette:

```bash
cp .env.example .env
```

**Credenziali Database (.env):**
```
DB_HOST=localhost
DB_USERNAME=jmvvznbb_tornate_user
DB_PASSWORD=Puntorosso22
DB_DATABASE=jmvvznbb_tornate_db
DB_CHARSET=utf8mb4
DB_PORT=3306
```

**IMPORTANTE:** Il file `.env` è nel `.gitignore` e NON viene committato per sicurezza.

### 2. Configurazione CORS per Sviluppo
Nel file `.env` è stato impostato:
```
CORS_ALLOWED_ORIGINS=*
```

**⚠️ ATTENZIONE:** In produzione, sostituire `*` con i domini autorizzati:
```
CORS_ALLOWED_ORIGINS=https://tornate.loggiakilwinning.com,https://loggiakilwinning.com
```

### 3. Script di Test Creati

#### test_connection.php
Script per testare la connessione al database:
```
https://tuosito.com/test_connection.php
```

#### check_passwords.php
Script per verificare lo stato delle password (hashate o meno):
```
https://tuosito.com/check_passwords.php
```

#### migrate_passwords.php (già esistente)
Script per migrare le password al sistema sicuro con bcrypt.

## 🚀 PROCEDURA DI FIX SU SERVER

### Passo 1: Creare file .env
```bash
cd /path/to/Biblioteca
cp .env.example .env
nano .env
# Inserire le credenziali corrette del database
```

### Passo 2: Verificare connessione
Accedi a: `https://tuodominio.com/test_connection.php`

Se vedi errori di connessione, verifica:
- Credenziali database in `.env`
- Host MySQL corretto (localhost o indirizzo IP)
- Porta MySQL corretta (default: 3306)
- Permessi utente database

### Passo 3: Verificare password
Accedi a: `https://tuodominio.com/check_passwords.php`

Se vedi utenti "SENZA HASH":
- Il sistema userà il fallback temporaneo (Nome+25)
- Le password verranno hashate automaticamente al primo login
- OPPURE esegui manualmente lo script di migrazione

### Passo 4: (Opzionale) Migrare password
Se vuoi hashare tutte le password in anticipo:
```bash
cd /path/to/Biblioteca
php migrate_passwords.php
```

### Passo 5: Testare login
1. Accedi a: `https://tuodominio.com/`
2. Seleziona "Ospite"
3. Password: `Ospite25`
4. Se il login funziona ✅ tutto OK!

### Passo 6: Pulizia (IMPORTANTE!)
Dopo aver verificato che tutto funziona, elimina gli script di test:
```bash
rm test_connection.php
rm check_passwords.php
rm migrate_passwords.php  # Solo dopo aver migrato le password
```

## 📋 SISTEMA DI LOGIN - Come Funziona

### Password Non Hashate (Fallback Temporaneo)
Se `password_hash` nel database è vuoto:
- Sistema usa pattern: `PrimoNome + 25`
- Esempio: "Paolo Gazzano" → password: `Paolo25`
- Esempio: "Ospite" → password: `Ospite25`
- Al primo login, la password viene hashata automaticamente

### Password Hashate (Sistema Sicuro)
Se `password_hash` nel database contiene un hash:
- Sistema usa `password_verify()` (sicuro contro timing attacks)
- Hash generato con BCRYPT cost=12
- Password non sono più in chiaro nel database

## 🔒 SICUREZZA - Modifiche Applicate

### ✅ Fix #1: Credenziali Database Protette
- Password non più hardcoded
- File `.env` non committato su git
- Fallback a `.env.example` solo in sviluppo

### ✅ Fix #2: SQL Injection Prevenuto
- Prepared statements ovunque
- Mapping sicuro per ORDER BY
- Validazione input

### ✅ Fix #3: CORS Limitato
- Whitelist domini autorizzati
- Configurabile da `.env`
- `*` solo per sviluppo locale

### ✅ Fix #4: Password Hashing Sicuro
- BCRYPT con cost=12
- Migrazione automatica al login
- Fallback temporaneo per compatibilità

### ✅ Fix #5: CSRF Protection
- Token generato per ogni sessione
- Validato su tutte le richieste POST/PUT/DELETE
- Rigenato dopo login

### ✅ Fix #6: Rate Limiting
- 5 tentativi falliti → lockout 1 ora
- Protezione brute force
- Logging tentativi sospetti

### ✅ Fix #7: Session Security
- Session regeneration dopo login
- Timeout configurabile (default 30 minuti)
- Security headers HTTP

## 🆘 TROUBLESHOOTING

### Problema: "Errore di configurazione database"
**Causa:** File `.env` mancante o credenziali errate
**Soluzione:**
```bash
cp .env.example .env
nano .env
# Verifica DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE
```

### Problema: "Connessione fallita"
**Causa:** MySQL non raggiungibile o credenziali errate
**Soluzione:**
```bash
# Testa connessione manuale
mysql -h localhost -u jmvvznbb_tornate_user -pPuntorosso22 jmvvznbb_tornate_db
```

### Problema: "Password errata" ma password è corretta
**Causa:** Password già hashata, ma inserisci password in chiaro
**Soluzione:** Verifica con `check_passwords.php` lo stato della password

### Problema: "Token CSRF non valido"
**Causa:** Sessione scaduta o cookie bloccati
**Soluzione:**
- Ricarica la pagina di login (F5)
- Verifica che i cookie siano abilitati
- Controlla che session_start() funzioni

### Problema: "CORS error" in console browser
**Causa:** Domini non autorizzati in CORS_ALLOWED_ORIGINS
**Soluzione:** Aggiungi il tuo dominio a `.env`:
```
CORS_ALLOWED_ORIGINS=https://tuodominio.com,*
```

## 📞 SUPPORTO

Per problemi persistenti:
1. Controlla i log di PHP: `/var/log/php_errors.log`
2. Controlla i log di Apache/Nginx
3. Verifica `error_log` nelle funzioni PHP
4. Contatta l'amministratore di sistema

---

**Ultima modifica:** 2025-11-05
**Versione:** 1.0
