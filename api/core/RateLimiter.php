<?php
/**
 * Simple file-based rate limiter for shared hosting compatibility
 * No Redis or Memcached required
 */
class RateLimiter {
    private $storage_path;
    private $max_requests;
    private $time_window; // in seconds
    
    /**
     * @param int $max_requests Maximum requests allowed in time window
     * @param int $time_window Time window in seconds (default: 60)
     */
    public function __construct($max_requests = 60, $time_window = 60) {
        $this->storage_path = sys_get_temp_dir() . '/rate_limit_';
        $this->max_requests = $max_requests;
        $this->time_window = $time_window;
    }
    
    /**
     * Check if request is allowed.
     * Sliding window only: a full minute is never added on top of the window.
     * If the limiter itself cannot record the hit, the request is allowed so
     * accounting work is not blocked by a temp-directory problem.
     *
     * @param string $identifier Unique identifier (e.g. "user:12" or "ip:1.2.3.4")
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int, 'retry_after' => int]
     */
    public function check($identifier) {
        $file = $this->storage_path . md5($identifier) . '.json';
        $now = time();
        $open = [
            'allowed' => true,
            'remaining' => $this->max_requests,
            'reset_at' => $now + $this->time_window,
            'retry_after' => 0
        ];

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return $open;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return $open;
        }

        $raw = stream_get_contents($fh);
        $data = json_decode($raw ?: '', true);
        $requests = [];
        if (is_array($data) && isset($data['requests']) && is_array($data['requests'])) {
            $requests = $data['requests'];
        }

        $cutoff = $now - $this->time_window;
        $requests = array_values(array_filter($requests, function ($timestamp) use ($cutoff) {
            return is_numeric($timestamp) && (int)$timestamp > $cutoff;
        }));

        if (count($requests) >= $this->max_requests) {
            $oldest = (int)min($requests);
            $retry_after = max(1, ($oldest + $this->time_window) - $now);
            $this->writeLocked($fh, ['requests' => $requests]);
            flock($fh, LOCK_UN);
            fclose($fh);

            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => $now + $retry_after,
                'retry_after' => $retry_after
            ];
        }

        $requests[] = $now;
        $this->writeLocked($fh, ['requests' => $requests]);
        flock($fh, LOCK_UN);
        fclose($fh);

        return [
            'allowed' => true,
            'remaining' => $this->max_requests - count($requests),
            'reset_at' => $now + $this->time_window,
            'retry_after' => 0
        ];
    }

    /**
     * @param resource $fh
     * @param array $data
     */
    private function writeLocked($fh, array $data) {
        $json = json_encode($data);
        if ($json === false) {
            return;
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $json);
        fflush($fh);
    }
    
    /**
     * Reset rate limit for identifier
     */
    public function reset($identifier) {
        $file = $this->storage_path . md5($identifier) . '.json';
        @unlink($file);
    }
    
    /**
     * Clean up old files (call periodically via cron)
     */
    public static function cleanup() {
        $dir = sys_get_temp_dir();
        $files = glob($dir . '/rate_limit_*.json');
        $now = time();
        
        foreach ($files as $file) {
            if (filemtime($file) < ($now - 3600)) { // 1 hour old
                @unlink($file);
            }
        }
    }
}
