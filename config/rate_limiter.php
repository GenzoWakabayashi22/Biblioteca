<?php
/**
 * Rate Limiter per Protezione Brute Force
 * Sistema file-based (semplice e senza dipendenze Redis)
 */

class RateLimiter {
    private $storage_path;
    private $max_attempts;
    private $window_seconds;
    private $lockout_seconds;

    /**
     * @param string $storage_path Directory per storage file rate limiting
     * @param int $max_attempts Numero massimo tentativi permessi
     * @param int $window_seconds Finestra temporale in secondi
     * @param int $lockout_seconds Durata lockout dopo troppi tentativi
     */
    public function __construct(
        $storage_path = null,
        $max_attempts = 5,
        $window_seconds = 900, // 15 minuti
        $lockout_seconds = 3600 // 1 ora
    ) {
        $this->storage_path = $storage_path ?? sys_get_temp_dir() . '/biblioteca_rate_limit';
        $this->max_attempts = $max_attempts;
        $this->window_seconds = $window_seconds;
        $this->lockout_seconds = $lockout_seconds;

        // Crea directory se non esiste
        if (!file_exists($this->storage_path)) {
            $created = mkdir($this->storage_path, 0700, true);
            if (!$created) {
                // Fallback a sys_get_temp_dir() se non riesce a creare la directory
                error_log("WARNING: Impossibile creare directory rate limit in {$this->storage_path}, uso system temp");
                $this->storage_path = sys_get_temp_dir();
            }
        }
    }

    /**
     * Genera chiave unica per identificare utente (IP + User-Agent hash)
     */
    private function getIdentifier() {
        $ip = $this->getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return hash('sha256', $ip . '|' . $user_agent);
    }

    /**
     * Ottiene IP reale del client (considera proxy/load balancer)
     */
    private function getClientIP() {
        $headers_to_check = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers_to_check as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Se X-Forwarded-For contiene multiple IP, prendi la prima
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Valida che sia un IP valido
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * File path per storage dati rate limiting
     */
    private function getFilePath() {
        $identifier = $this->getIdentifier();
        return $this->storage_path . '/' . $identifier . '.json';
    }

    /**
     * Carica dati rate limiting dal file
     */
    private function loadData() {
        $file = $this->getFilePath();

        if (!file_exists($file)) {
            return ['attempts' => [], 'locked_until' => 0];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return ['attempts' => [], 'locked_until' => 0];
        }

        $data = json_decode($content, true);
        return $data ?: ['attempts' => [], 'locked_until' => 0];
    }

    /**
     * Salva dati rate limiting su file
     */
    private function saveData($data) {
        $file = $this->getFilePath();
        $json = json_encode($data);
        @file_put_contents($file, $json, LOCK_EX);
    }

    /**
     * Verifica se IP/User è bloccato
     * @return array ['blocked' => bool, 'retry_after' => int, 'attempts_left' => int]
     */
    public function check() {
        $data = $this->loadData();
        $now = time();

        // Verifica se è in lockout
        if ($data['locked_until'] > $now) {
            return [
                'blocked' => true,
                'retry_after' => $data['locked_until'] - $now,
                'attempts_left' => 0,
                'reason' => 'Troppi tentativi falliti. Account temporaneamente bloccato.'
            ];
        }

        // Rimuovi tentativi fuori dalla finestra temporale
        $data['attempts'] = array_filter($data['attempts'], function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->window_seconds;
        });

        // Conta tentativi nella finestra
        $attempts_count = count($data['attempts']);

        // Verifica se ha superato il limite
        if ($attempts_count >= $this->max_attempts) {
            // Attiva lockout
            $data['locked_until'] = $now + $this->lockout_seconds;
            $this->saveData($data);

            error_log(sprintf(
                "RATE LIMIT: IP %s bloccato per %d secondi dopo %d tentativi",
                $this->getClientIP(),
                $this->lockout_seconds,
                $attempts_count
            ));

            return [
                'blocked' => true,
                'retry_after' => $this->lockout_seconds,
                'attempts_left' => 0,
                'reason' => sprintf('Superato limite di %d tentativi. Riprova tra %d minuti.',
                    $this->max_attempts,
                    ceil($this->lockout_seconds / 60))
            ];
        }

        return [
            'blocked' => false,
            'retry_after' => 0,
            'attempts_left' => $this->max_attempts - $attempts_count,
            'reason' => null
        ];
    }

    /**
     * Registra tentativo fallito
     */
    public function recordFailure() {
        $data = $this->loadData();
        $data['attempts'][] = time();
        $this->saveData($data);

        error_log(sprintf(
            "RATE LIMIT: Tentativo fallito registrato per IP %s (totale: %d)",
            $this->getClientIP(),
            count($data['attempts'])
        ));
    }

    /**
     * Reset rate limiting dopo successo
     */
    public function reset() {
        $data = ['attempts' => [], 'locked_until' => 0];
        $this->saveData($data);
    }

    /**
     * Cleanup file vecchi (chiamare periodicamente via cron)
     */
    public static function cleanup($storage_path = null, $older_than_seconds = 86400) {
        $path = $storage_path ?? sys_get_temp_dir() . '/biblioteca_rate_limit';

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.json');
        $now = time();
        $deleted = 0;

        foreach ($files as $file) {
            if ($now - filemtime($file) > $older_than_seconds) {
                @unlink($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            error_log("RATE LIMIT: Cleanup completato, eliminati $deleted file vecchi");
        }
    }
}

/**
 * Helper function globale per verificare rate limit
 * @return array|null Null se OK, array con info se bloccato
 */
function checkRateLimit($action = 'login') {
    // Configurazioni diverse per azioni diverse
    $configs = [
        'login' => [
            'max_attempts' => 5,
            'window_seconds' => 900,    // 15 minuti
            'lockout_seconds' => 3600   // 1 ora
        ],
        'api' => [
            'max_attempts' => 100,
            'window_seconds' => 60,     // 1 minuto
            'lockout_seconds' => 300    // 5 minuti
        ]
    ];

    $config = $configs[$action] ?? $configs['login'];

    $limiter = new RateLimiter(
        null,
        $config['max_attempts'],
        $config['window_seconds'],
        $config['lockout_seconds']
    );

    $result = $limiter->check();

    if ($result['blocked']) {
        return $result;
    }

    return null;
}

/**
 * Helper function per registrare fallimento
 */
function recordRateLimitFailure($action = 'login') {
    $configs = [
        'login' => [
            'max_attempts' => 5,
            'window_seconds' => 900,
            'lockout_seconds' => 3600
        ]
    ];

    $config = $configs[$action] ?? $configs['login'];

    $limiter = new RateLimiter(
        null,
        $config['max_attempts'],
        $config['window_seconds'],
        $config['lockout_seconds']
    );

    $limiter->recordFailure();
}

/**
 * Helper function per reset dopo successo
 */
function resetRateLimit($action = 'login') {
    $configs = [
        'login' => [
            'max_attempts' => 5,
            'window_seconds' => 900,
            'lockout_seconds' => 3600
        ]
    ];

    $config = $configs[$action] ?? $configs['login'];

    $limiter = new RateLimiter(
        null,
        $config['max_attempts'],
        $config['window_seconds'],
        $config['lockout_seconds']
    );

    $limiter->reset();
}
?>
