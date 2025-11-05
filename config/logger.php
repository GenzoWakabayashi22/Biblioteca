<?php
/**
 * Logger Strutturato per Sistema Biblioteca
 * PSR-3 style logging con output JSON
 */

class Logger {
    const EMERGENCY = 'EMERGENCY';
    const ALERT     = 'ALERT';
    const CRITICAL  = 'CRITICAL';
    const ERROR     = 'ERROR';
    const WARNING   = 'WARNING';
    const NOTICE    = 'NOTICE';
    const INFO      = 'INFO';
    const DEBUG     = 'DEBUG';

    private $log_file;
    private $min_level;
    private $context_data = [];

    private static $level_priority = [
        'EMERGENCY' => 800,
        'ALERT'     => 700,
        'CRITICAL'  => 600,
        'ERROR'     => 500,
        'WARNING'   => 400,
        'NOTICE'    => 300,
        'INFO'      => 200,
        'DEBUG'     => 100
    ];

    /**
     * @param string $log_file Path al file di log
     * @param string $min_level Livello minimo di logging
     */
    public function __construct($log_file = null, $min_level = 'INFO') {
        $this->log_file = $log_file ?? (__DIR__ . '/../logs/app.log');
        $this->min_level = $min_level;

        // Crea directory logs se non esiste
        $log_dir = dirname($this->log_file);
        if (!file_exists($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
    }

    /**
     * Aggiunge contesto globale al logger (user_id, session_id, etc.)
     */
    public function setContext($key, $value) {
        $this->context_data[$key] = $value;
    }

    /**
     * Log generico
     */
    public function log($level, $message, $context = []) {
        // Verifica se level supera min_level
        if (self::$level_priority[$level] < self::$level_priority[$this->min_level]) {
            return;
        }

        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'timestamp_unix' => time(),
            'level' => $level,
            'message' => $message,
            'context' => array_merge($this->context_data, $context),
            'server' => [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            ],
            'php' => [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ]
        ];

        // Scrivi log in formato JSON (una riga per entry)
        $json_line = json_encode($log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // Scrivi su file
        @file_put_contents($this->log_file, $json_line, FILE_APPEND | LOCK_EX);

        // Invia anche a error_log PHP nativo per compatibilità
        error_log(sprintf('[%s] %s: %s', $log_entry['timestamp'], $level, $message));
    }

    // Metodi convenience PSR-3
    public function emergency($message, $context = []) { $this->log(self::EMERGENCY, $message, $context); }
    public function alert($message, $context = []) { $this->log(self::ALERT, $message, $context); }
    public function critical($message, $context = []) { $this->log(self::CRITICAL, $message, $context); }
    public function error($message, $context = []) { $this->log(self::ERROR, $message, $context); }
    public function warning($message, $context = []) { $this->log(self::WARNING, $message, $context); }
    public function notice($message, $context = []) { $this->log(self::NOTICE, $message, $context); }
    public function info($message, $context = []) { $this->log(self::INFO, $message, $context); }
    public function debug($message, $context = []) { $this->log(self::DEBUG, $message, $context); }

    /**
     * Log specifico per security events
     */
    public function security($event_type, $message, $context = []) {
        $context['security_event'] = $event_type;
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Cleanup log file vecchi
     */
    public static function rotateLog($log_file, $max_size_mb = 10) {
        if (!file_exists($log_file)) {
            return;
        }

        $size_mb = filesize($log_file) / 1024 / 1024;

        if ($size_mb > $max_size_mb) {
            $backup_file = $log_file . '.' . date('Y-m-d_H-i-s') . '.bak';
            @rename($log_file, $backup_file);

            // Comprimi backup se disponibile gzip
            if (function_exists('gzencode')) {
                $content = file_get_contents($backup_file);
                file_put_contents($backup_file . '.gz', gzencode($content, 9));
                @unlink($backup_file);
            }
        }
    }
}

/**
 * Logger singleton globale
 */
class AppLogger {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            $log_level = $_ENV['LOG_LEVEL'] ?? 'INFO';
            $log_file = $_ENV['LOG_FILE'] ?? __DIR__ . '/../logs/app.log';

            self::$instance = new Logger($log_file, $log_level);

            // Aggiungi contesto globale da sessione
            if (isset($_SESSION['fratello_id'])) {
                self::$instance->setContext('user_id', $_SESSION['fratello_id']);
                self::$instance->setContext('user_name', $_SESSION['fratello_nome'] ?? null);
                self::$instance->setContext('is_admin', $_SESSION['is_admin'] ?? false);
            }

            self::$instance->setContext('session_id', session_id());
        }

        return self::$instance;
    }
}

/**
 * Helper functions globali per logging
 */
function logger() {
    return AppLogger::getInstance();
}

function log_info($message, $context = []) {
    logger()->info($message, $context);
}

function log_error($message, $context = []) {
    logger()->error($message, $context);
}

function log_warning($message, $context = []) {
    logger()->warning($message, $context);
}

function log_debug($message, $context = []) {
    logger()->debug($message, $context);
}

function log_security($event_type, $message, $context = []) {
    logger()->security($event_type, $message, $context);
}
?>
