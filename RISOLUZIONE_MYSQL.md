# 🔴 PROBLEMA: MySQL Non in Esecuzione

## Problema Identificato

Il sito mostra la **pagina di manutenzione** perché **MySQL non è in esecuzione** sul server.

Errori riscontrati:
- ❌ `No such file or directory` (socket MySQL non trovato)
- ❌ `Connection refused` (porta 3306 non in ascolto)
- ❌ Processo MySQL non trovato

## ✅ SOLUZIONE - Avvia MySQL

### Su Server Linux (Ubuntu/Debian)

```bash
# Prova con MySQL
sudo systemctl start mysql
sudo systemctl enable mysql
sudo systemctl status mysql

# OPPURE prova con MariaDB
sudo systemctl start mariadb
sudo systemctl enable mariadb
sudo systemctl status mariadb

# Se non usi systemd:
sudo service mysql start
```

### Verifica che MySQL sia Avviato

```bash
# Verifica processo
ps aux | grep mysql

# Verifica porta
netstat -tulpn | grep 3306

# Prova connessione manuale
mysql -h localhost -u jmvvznbb_tornate_user -p
# Password: Puntorosso22
```

### Su macOS (Homebrew)

```bash
# Avvia MySQL
brew services start mysql

# Verifica stato
brew services list
```

### Su Windows (XAMPP/WAMP)

1. Apri il pannello di controllo XAMPP/WAMP
2. Clicca "Start" su MySQL/MariaDB
3. Verifica che la spia sia verde

### Docker (se usi container)

```bash
# Verifica container MySQL
docker ps | grep mysql

# Avvia container se fermo
docker start [nome_container_mysql]

# Oppure crea nuovo container
docker run --name mysql-biblioteca \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=jmvvznbb_tornate_db \
  -e MYSQL_USER=jmvvznbb_tornate_user \
  -e MYSQL_PASSWORD=Puntorosso22 \
  -p 3306:3306 \
  -d mysql:8.0
```

## 🔍 Verifica Configurazione

Dopo aver avviato MySQL, testa la connessione:

```bash
cd /path/to/Biblioteca
php test_mysql_direct.php
```

Dovresti vedere:
```
✅ CONNESSIONE RIUSCITA!
✅ Test query OK: X fratelli nel database
```

## 📋 Checklist Completa

- [ ] MySQL/MariaDB è installato?
- [ ] MySQL/MariaDB è avviato?
- [ ] Porta 3306 è aperta? (o 3307?)
- [ ] Credenziali corrette in `.env`?
- [ ] Database `jmvvznbb_tornate_db` esiste?
- [ ] Utente `jmvvznbb_tornate_user` ha permessi?
- [ ] Firewall permette connessioni sulla porta 3306?

## 🆘 Troubleshooting

### MySQL non si avvia

```bash
# Controlla log errori MySQL
sudo tail -f /var/log/mysql/error.log

# OPPURE
sudo journalctl -u mysql -n 50
```

Errori comuni:
- **Port already in use:** Un altro servizio usa la porta 3306
- **Data directory not found:** Directory dati MySQL corrotta/mancante
- **Disk full:** Spazio disco esaurito
- **Permission denied:** Permessi errati sulla directory dati

### Database non esiste

```bash
mysql -u root -p
CREATE DATABASE jmvvznbb_tornate_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON jmvvznbb_tornate_db.* TO 'jmvvznbb_tornate_user'@'localhost' IDENTIFIED BY 'Puntorosso22';
FLUSH PRIVILEGES;
```

### Utente non ha permessi

```bash
mysql -u root -p
GRANT ALL PRIVILEGES ON jmvvznbb_tornate_db.* TO 'jmvvznbb_tornate_user'@'localhost';
FLUSH PRIVILEGES;
```

### Porta diversa da 3306

Se MySQL è su porta diversa (es. 3307), modifica `.env`:
```
DB_PORT=3307
```

## 🎯 Dopo il Fix

Una volta che MySQL è avviato e la connessione funziona:

1. **Testa il sito:** `https://tuodominio.com/`
2. **Prova il login:** Seleziona "Ospite", password: `Ospite25`
3. **Verifica funzionalità:** Dashboard, libri, ecc.

## 📞 Supporto

Se MySQL continua a non funzionare:
- Controlla i log: `/var/log/mysql/error.log`
- Verifica configurazione: `/etc/mysql/my.cnf`
- Consulta amministratore di sistema
- Verifica provider hosting (se su hosting condiviso)

---

**Nota:** Questo documento va eliminato dopo aver risolto il problema (contiene info sensibili sulle credenziali).
