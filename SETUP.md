# Guida Setup e Deployment - Sistema Biblioteca

Questa guida spiega come risolvere l'errore HTTP 500 e configurare correttamente il sistema.

## Problema: HTTP 500 al Login

Se ricevi l'errore "HTTP ERROR 500" quando provi ad effettuare il login su `https://biblioteca.loggiakilwinning.com/api/login.php`, significa che il file `.env` con le credenziali del database non è configurato correttamente.

## Soluzione Passo-Passo

### 1. Connetti al Server via SSH

```bash
ssh tuoutente@biblioteca.loggiakilwinning.com
```

### 2. Naviga nella Directory del Progetto

```bash
cd /home/jmvvznbb/biblioteca
# oppure
cd /path/to/biblioteca
```

### 3. Verifica se esiste .env

```bash
ls -la .env
```

Se il file non esiste, prosegui con il punto 4.

### 4. Crea il File .env

**Opzione A - Usando setup.php (Raccomandato)**
```bash
php setup.php
```

**Opzione B - Manualmente**
```bash
cp .env.example .env
nano .env
```

### 5. Configura le Credenziali Database

Modifica il file `.env` con le credenziali corrette:

```env
DB_HOST=localhost
DB_PORT=3306
DB_USERNAME=jmvvznbb_tornate
DB_PASSWORD=la_tua_password_qui
DB_DATABASE=jmvvznbb_tornate_db
```

**Nota**: Sostituisci `la_tua_password_qui` con la password corretta del database.

### 6. Imposta i Permessi Corretti

```bash
chmod 600 .env
```

Questo assicura che solo il proprietario possa leggere il file (per sicurezza).

### 7. Verifica la Configurazione

```bash
php setup.php
```

Questo script verifica:
- Connessione al database
- Esistenza delle tabelle necessarie
- Configurazione corretta

### 8. Testa il Login

Apri il browser e vai a:
```
https://biblioteca.loggiakilwinning.com
```

Prova ad effettuare il login. L'errore HTTP 500 dovrebbe essere risolto.

## Risoluzione Problemi Comuni

### Errore: "Connessione fallita"

Se dopo aver configurato `.env` vedi ancora errori:

1. **Verifica le credenziali**:
   ```bash
   mysql -u jmvvznbb_tornate -p jmvvznbb_tornate_db
   ```
   Inserisci la password quando richiesto. Se non riesci a connetterti, le credenziali sono errate.

2. **Verifica che il database esista**:
   ```bash
   mysql -u root -p -e "SHOW DATABASES LIKE 'jmvvznbb_tornate_db';"
   ```

3. **Verifica i permessi dell'utente**:
   ```bash
   mysql -u root -p -e "SHOW GRANTS FOR 'jmvvznbb_tornate'@'localhost';"
   ```

### Errore: "Tabelle mancanti"

Se il database esiste ma mancano tabelle (recensioni, richieste_prestito, etc.):

1. **Trova il file SQL di schema** (se esiste):
   ```bash
   find . -name "*.sql" -type f
   ```

2. **Importa lo schema**:
   ```bash
   mysql -u jmvvznbb_tornate -p jmvvznbb_tornate_db < schema.sql
   ```

3. **Oppure crea le tabelle mancanti manualmente** seguendo la struttura necessaria.

### Permessi File e Directory

Se ci sono problemi di permessi:

```bash
# Imposta proprietario corretto
chown -R tuoutente:tuoutente /home/jmvvznbb/biblioteca

# Imposta permessi directory
find /home/jmvvznbb/biblioteca -type d -exec chmod 755 {} \;

# Imposta permessi file
find /home/jmvvznbb/biblioteca -type f -exec chmod 644 {} \;

# Permessi speciali per .env
chmod 600 /home/jmvvznbb/biblioteca/.env

# Permessi esecuzione per PHP scripts
chmod 755 /home/jmvvznbb/biblioteca/setup.php
```

### Log degli Errori

Per diagnosticare problemi, controlla i log:

```bash
# Log dell'applicazione
tail -f /home/jmvvznbb/biblioteca/error_log

# Log del server web
tail -f /var/log/apache2/error.log
# oppure
tail -f /var/log/nginx/error.log

# Log PHP
tail -f /var/log/php-fpm/error.log
```

## Checklist Post-Installazione

- [ ] File `.env` creato e configurato
- [ ] Permessi `.env` impostati a 600
- [ ] Connessione database verificata
- [ ] Tabelle database presenti
- [ ] Login funzionante
- [ ] Dashboard accessibile
- [ ] Log non mostrano errori critici

## Configurazioni Opzionali

### Timeout Sessione

Per modificare il timeout della sessione (default: 30 minuti):

```env
SESSION_TIMEOUT=1800   # 30 minuti in secondi
```

### Admin IDs

Per aggiungere amministratori (opzionale, preferire campo `role` nel database):

```env
ADMIN_IDS=16,9,12,11
```

### CORS (se necessario per API esterne)

```env
CORS_ALLOWED_ORIGINS=https://altrodominio.com,https://app.esempio.com
```

## Supporto

Se i problemi persistono dopo aver seguito questa guida:

1. Controlla i log per errori specifici
2. Verifica che tutte le dipendenze PHP siano installate
3. Assicurati che la versione PHP sia >= 7.4
4. Contatta l'amministratore di sistema

## Note di Sicurezza

- ⚠️ Non committare mai il file `.env` in Git
- ⚠️ Usa password forti per il database
- ⚠️ Mantieni i permessi restrittivi su `.env` (600)
- ⚠️ In produzione, disabilita `display_errors` in PHP
- ⚠️ Abilita HTTPS per tutte le connessioni
