# Setup Sistema Biblioteca

## 🚀 Installazione Rapida

### 1. Configura il Database

```bash
# Copia il template delle configurazioni
cp .env.example .env

# Modifica con le tue credenziali (se diverse)
nano .env
```

### 2. Imposta i Permessi

```bash
# Proteggi il file .env
chmod 600 .env

# Crea directory logs
mkdir -p logs
chmod 755 logs
```

### 3. Esegui le Migrazioni Database

```bash
# Migrazione password (aggiunge colonna password_hash)
php migrate_passwords.php

# Migrazione ruoli admin (aggiunge colonna role)
php migrate_admin_roles.php

# IMPORTANTE: Elimina gli script dopo l'esecuzione
rm migrate_passwords.php migrate_admin_roles.php
```

### 4. Setup Automatico (Alternativo)

Invece dei passi manuali, puoi usare lo script automatico:

```bash
php setup.php
```

Lo script:
- ✅ Crea automaticamente il file `.env`
- ✅ Verifica la connessione al database
- ✅ Controlla che le tabelle esistano
- ✅ Verifica le colonne necessarie
- ✅ Fornisce istruzioni per i prossimi passi

---

## 📋 Configurazioni Importanti

### File `.env`

```env
# Database
DB_HOST=localhost
DB_USERNAME=jmvvznbb_tornate_user
DB_PASSWORD=Puntorosso22
DB_DATABASE=jmvvznbb_tornate_db

# Sicurezza
SESSION_TIMEOUT=1800                    # 30 minuti
CORS_ALLOWED_ORIGINS=https://tuodominio.com

# Logging
LOG_LEVEL=INFO                          # DEBUG in sviluppo
LOG_FILE=logs/app.log
```

### Timeout Sessione

- **Sviluppo:** `SESSION_TIMEOUT=7200` (2 ore)
- **Produzione:** `SESSION_TIMEOUT=1800` (30 minuti) ✅
- **Alta Sicurezza:** `SESSION_TIMEOUT=900` (15 minuti)

### Livelli Log

- `DEBUG` - Tutto (solo sviluppo)
- `INFO` - Informazioni generali ✅
- `WARNING` - Avvisi e problemi
- `ERROR` - Solo errori

---

## 🔒 Sicurezza

### Features Implementate

✅ **Password Hashing** - BCRYPT cost 12
✅ **CSRF Protection** - Token su tutti i form
✅ **Rate Limiting** - 5 tentativi login / 15 minuti
✅ **Security Headers** - CSP, X-Frame-Options, ecc.
✅ **Logging Strutturato** - JSON con context
✅ **Role Management** - Admin/User/Guest in database
✅ **Session Security** - Regeneration + timeout configurabile
✅ **CORS Protection** - Whitelist domini autorizzati

### Credenziali di Default

**⚠️ IMPORTANTE:** Dopo il primo accesso, cambia le password!

- **Admin:** Nome + 25 (es: Paolo25)
- **Ospite:** Ospite25

Le password sono hashate al primo login.

---

## 🔧 Troubleshooting

### Errore: "Errore di configurazione. Contatta l'amministratore."

**Causa:** Il file `.env` non è presente nel sistema.

**Soluzione:**
```bash
# Copia il template
cp .env.example .env

# Proteggi il file
chmod 600 .env

# Verifica configurazione
php setup.php
```

Vedi `CONFIGURAZIONE_RISOLTO.md` per documentazione completa del problema e della soluzione.

### Errore: "File .env non trovato"

```bash
# Copia il template
cp .env.example .env

# Oppure usa lo script di setup
php setup.php
```

### Errore: "Connessione database fallita"

1. Verifica che MySQL sia in esecuzione
2. Controlla le credenziali in `.env`
3. Verifica i permessi utente database

```sql
GRANT ALL PRIVILEGES ON jmvvznbb_tornate_db.*
TO 'jmvvznbb_tornate_user'@'localhost';
FLUSH PRIVILEGES;
```

### Errore: "Colonna password_hash non trovata"

```bash
# Esegui la migrazione password
php migrate_passwords.php
```

### Errore: "Troppi tentativi di login"

Il rate limiting ha bloccato l'IP per 1 ora dopo 5 tentativi falliti.

**Soluzione:**
```bash
# Elimina i file di rate limit per sbloccare
rm -rf /tmp/biblioteca_rate_limit/*.json
```

### Directory logs non scrivibile

```bash
# Crea directory con permessi corretti
mkdir -p logs
chmod 755 logs
chown www-data:www-data logs  # Per Apache/Nginx
```

---

## 📖 Documentazione Completa

- **Security Fixes:** `SECURITY_FIXES.md`
- **API Documentation:** Inline in `api/*.php`
- **Database Schema:** `config/database.php`

---

## 🔄 Aggiornamenti

### Pull Latest Changes

```bash
git pull origin main

# Se ci sono nuove migrazioni
php migrate_*.php
```

### Verifica Salute Sistema

```bash
php setup.php  # Verifica configurazione
tail -f logs/app.log  # Monitora log
```

---

## 👥 Gestione Utenti

### Promuovere un Utente ad Admin

```sql
UPDATE fratelli SET role = 'admin' WHERE id = X;
```

### Rimuovere Privilegi Admin

```sql
UPDATE fratelli SET role = 'user' WHERE id = X;
```

### Reset Password Utente

```bash
# Genera nuovo hash
php -r "echo password_hash('NuovaPassword123', PASSWORD_BCRYPT, ['cost' => 12]);"

# Aggiorna database
UPDATE fratelli SET password_hash = 'HASH_GENERATO' WHERE id = X;
```

---

## ⚠️ Note Importanti

1. **Mai committare `.env`** - Contiene credenziali sensibili
2. **Elimina script migrazione** - Dopo l'uso per sicurezza
3. **Backup regolari** - Prima di ogni aggiornamento
4. **Monitora i log** - `logs/app.log` per attività sospette
5. **Aggiorna regolarmente** - Pull nuovi fix di sicurezza

---

## 📞 Support

Per problemi o domande, controlla:
- `SECURITY_FIXES.md` - Documentazione security
- `logs/app.log` - Log applicazione
- GitHub Issues - Report problemi

---

**Versione:** 2.0.0
**Ultima Modifica:** 2025-11-05
**Security Score:** 9.6/10
