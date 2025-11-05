# Security Fixes - Progetto Biblioteca

## Data: 5 Novembre 2025

Questo documento descrive tutti i fix di sicurezza critici applicati al progetto.

---

## 🔴 FIX CRITICI COMPLETATI

### Fix #1: Credenziali Database Protette con .env ✅

**Problema:** Credenziali database hardcoded in chiaro nel codice sorgente.

**Soluzione:**
- Creato sistema `.env` per gestire credenziali sensibili
- File `.env` aggiunto a `.gitignore` (non committato)
- Funzione `loadEnv()` in `config/database.php` per caricare variabili
- File `.env.example` come template per setup

**File Modificati:**
- `config/database.php` - Aggiunta funzione loadEnv() e lettura da .env
- `.env` - Credenziali reali (NON committato)
- `.env.example` - Template per nuove installazioni
- `.gitignore` - Esclude .env dal versioning

**Istruzioni Setup:**
```bash
cp .env.example .env
# Modifica .env con le tue credenziali
```

---

### Fix #2: Credenziali Duplicate Rimosse ✅

**Problema:** Credenziali database duplicate in `pages/libro-dettaglio.php`

**Soluzione:**
- Rimossa connessione database duplicata
- Usa connessione $conn da `config/database.php`

**File Modificati:**
- `pages/libro-dettaglio.php` (linee 8-22 rimosse)

---

### Fix #3: SQL Injection Prevenuto ✅

**Problema:** Potenziale SQL injection in `api/libri.php` con parametro ORDER BY

**Soluzione:**
- Implementato mapping sicuro per sort e order
- Validazione con whitelist robusta
- Nessuna interpolazione diretta di variabili in query
- Aggiunta funzione `getDBConnection()` per PDO

**File Modificati:**
- `api/libri.php` (linee 144-163, 202)
- `config/database.php` (aggiunta funzione getDBConnection())

**Dettagli Tecnici:**
```php
// Prima (vulnerabile):
ORDER BY l.$sort $order

// Dopo (sicuro):
$sort_mapping = ['titolo' => 'l.titolo', ...];
$sort = $sort_mapping[$sort_input] ?? $sort_mapping['titolo'];
ORDER BY $sort $order
```

---

### Fix #4: Credenziali Ospite Rimosse dal HTML ✅

**Problema:** Credenziali account ospite esposte in HTML pubblico

**Soluzione:**
- Rimosso box con username/password dal template
- Aggiunto messaggio per contattare amministratore

**File Modificati:**
- `index.php` (linee 176-179)

---

### Fix #5: CORS Limitato a Domini Autorizzati ✅

**Problema:** `Access-Control-Allow-Origin: *` permette qualsiasi dominio

**Soluzione:**
- Creata funzione `configureCORS()` con whitelist
- Domini autorizzati configurabili da `.env`
- Gestione preflight OPTIONS centralizzata
- Supporto credentials con CORS specifico

**File Modificati:**
- `config/database.php` (aggiunta funzione configureCORS())
- `api/libri.php` (usa configureCORS())
- `api/liste.php` (usa configureCORS())
- `api/preferiti.php` (usa configureCORS())
- `.env` / `.env.example` (aggiunta CORS_ALLOWED_ORIGINS)

**Configurazione .env:**
```env
CORS_ALLOWED_ORIGINS=https://tornate.loggiakilwinning.com,https://loggiakilwinning.com
```

---

### Fix #6: Password Hashing Sicuro Implementato ✅

**Problema:** Password in chiaro con pattern prevedibile (Nome+25)

**Soluzione:**
- Implementato `password_hash()` con BCRYPT cost 12
- Sistema `password_verify()` per autenticazione
- Script migrazione `migrate_passwords.php` per hash password esistenti
- Fallback temporaneo per password non migrate
- Aggiunto `session_regenerate_id()` contro session fixation
- Timeout sessione configurabile da .env (default 30 minuti)

**File Modificati:**
- `api/login.php` (linee 40-86, 99-100)
- `config/database.php` (timeout da .env)
- `migrate_passwords.php` (nuovo file - script migrazione)
- `.env` / `.env.example` (aggiunta SESSION_TIMEOUT)

**Esecuzione Migrazione:**
```bash
php migrate_passwords.php
# Dopo l'esecuzione, elimina il file per sicurezza
rm migrate_passwords.php
```

---

## 📋 ISTRUZIONI POST-DEPLOYMENT

### 1. Setup Iniziale

```bash
# 1. Copia template .env
cp .env.example .env

# 2. Modifica .env con credenziali reali
nano .env

# 3. Verifica permessi (solo owner può leggere)
chmod 600 .env
```

### 2. Migrazione Password

```bash
# IMPORTANTE: Backup del database prima!
mysqldump -u username -p database_name > backup_pre_migration.sql

# Esegui migrazione password
php migrate_passwords.php

# Verifica successo, poi elimina lo script
rm migrate_passwords.php
```

### 3. Configurazione CORS

Modifica `.env` con i domini autorizzati:
```env
# Produzione
CORS_ALLOWED_ORIGINS=https://tornate.loggiakilwinning.com,https://loggiakilwinning.com

# Sviluppo locale (solo per testing!)
# CORS_ALLOWED_ORIGINS=*
```

### 4. Timeout Sessione

Default: 30 minuti (1800 secondi)
```env
SESSION_TIMEOUT=1800
```

Per ambiente diverso:
- Sviluppo: `SESSION_TIMEOUT=7200` (2 ore)
- Produzione Alta Sicurezza: `SESSION_TIMEOUT=900` (15 minuti)

---

## 🔒 CHECKLIST SICUREZZA

- [x] Credenziali non committate nel repository
- [x] .env aggiunto a .gitignore
- [x] SQL injection prevenuto con prepared statements
- [x] CORS limitato a domini autorizzati
- [x] Password hashate con BCRYPT
- [x] Session regeneration implementato
- [x] Timeout sessione configurabile
- [x] Credenziali pubbliche rimosse
- [ ] Rate limiting su login (TODO)
- [ ] CSRF token su form POST (TODO - priorità alta)
- [ ] Logging strutturato (TODO)
- [ ] Security headers HTTP (TODO)

---

## 📊 PROSSIMI PASSI (Priorità Alta)

### 1. CSRF Protection
- Implementare token CSRF su tutti i form POST
- Validazione server-side

### 2. Rate Limiting Login
- Implementare con Redis o file-based
- Max 5 tentativi per IP in 15 minuti
- Lockout temporaneo dopo tentativi falliti

### 3. Security Headers
```php
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

### 4. Logging Strutturato
- Implementare Monolog o PSR-3 logger
- Log JSON structured per analisi

---

## 🚨 AVVISI IMPORTANTI

### Per Sviluppatori

1. **MAI** committare il file `.env` nel repository
2. **SEMPRE** usare prepared statements per query SQL
3. **SEMPRE** validare e sanitizzare input utente
4. **SEMPRE** usare `password_hash()` per nuove password
5. **MAI** loggare password o dati sensibili

### Per Amministratori Sistema

1. Esegui `migrate_passwords.php` UNA SOLA VOLTA
2. Backup database prima di ogni migrazione
3. Monitora i log per tentativi di accesso sospetti
4. Cambia password admin periodicamente
5. Mantieni timeout sessione appropriato per il contesto d'uso

---

## 📝 LOG MODIFICHE

| Data | Fix | Sviluppatore | Note |
|------|-----|--------------|------|
| 2025-11-05 | Fix #1-6 Critici | Claude | Fix iniziali vulnerabilità critiche |

---

## 🔗 RISORSE

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Password Hashing](https://www.php.net/manual/en/function.password-hash.php)
- [CORS Best Practices](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS)

---

**Autore Fix:** Claude AI Assistant
**Data:** 5 Novembre 2025
**Branch:** claude/fai-analisi-011CUpWmD9V6V5yMPtujUrQi
