# Sistema Biblioteca - R∴ L∴ Kilwinning

Sistema di gestione biblioteca per la Loggia Kilwinning.

## Setup Iniziale

### 1. Configurazione Database

Il sistema richiede un file `.env` con le credenziali del database.

**Opzione A - Setup Automatico (Raccomandato)**
```bash
php setup.php
```

**Opzione B - Setup Manuale**
```bash
# Copia il template
cp .env.example .env

# Modifica il file .env con le tue credenziali
nano .env
```

### 2. Configurazione .env

Modifica il file `.env` con le credenziali corrette del database:

```env
DB_HOST=localhost
DB_PORT=3306
DB_USERNAME=tuo_utente
DB_PASSWORD=tua_password
DB_DATABASE=nome_database
```

### 3. Verifica Installazione

Dopo aver configurato `.env`, accedi al sistema tramite browser:
- Login: `http://tuodominio.com/`

## Risoluzione Problemi

### Errore HTTP 500 al Login

Se ricevi un errore HTTP 500 quando provi ad effettuare il login:

1. **Verifica che il file .env esista**
   ```bash
   ls -la .env
   ```

2. **Verifica le credenziali nel file .env**
   - Assicurati che DB_USERNAME, DB_PASSWORD e DB_DATABASE siano corretti

3. **Verifica connessione database**
   ```bash
   php setup.php
   ```

4. **Controlla i permessi**
   ```bash
   chmod 600 .env
   ```

### Log degli Errori

I log degli errori si trovano in:
- `error_log` (root directory)
- `pages/error_log`
- Log del web server (es. `/var/log/apache2/error.log`)

## Struttura del Progetto

```
.
├── api/              # Endpoint API
│   ├── login.php     # Gestione login
│   ├── libri.php     # API libri
│   └── ...
├── config/           # Configurazioni
│   ├── database.php  # Connessione database
│   └── rate_limiter.php  # Rate limiting
├── pages/            # Pagine applicazione
│   ├── dashboard.php
│   └── ...
├── .env              # Credenziali (non in git)
└── .env.example      # Template configurazione
```

## Sicurezza

- Il file `.env` contiene credenziali sensibili e **non deve essere committato** in Git
- Il sistema implementa:
  - Rate limiting per protezione brute force
  - CSRF protection
  - Password hashing con bcrypt
  - Security headers HTTP
  - Session security

## Supporto

Per problemi o domande, contatta l'amministratore del sistema.
