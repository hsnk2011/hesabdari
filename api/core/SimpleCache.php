<?php
/**
 * Simple File-Based Cache Manager for Shared Hosting
 * No Redis or Memcached required
 */
class SimpleCache {
    private $cache_dir;
    private $default_ttl;
    
    /**
     * @param string $cache_dir Directory to store cache files
     * @param int $default_ttl Default time-to-live in seconds (default: 3600)
     */
    public function __construct($cache_dir = null, $default_ttl = 3600) {
        $this->cache_dir = $cache_dir ?? sys_get_temp_dir() . '/app_cache/';
        $this->default_ttl = $default_ttl;
        
        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755, true);
        }
    }
    
    /**
     * Get cached value
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public function get($key) {
        $file = $this->getFilePath($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }

        $cached = json_decode($data, true);
        if ($cached === null || !is_array($cached)) {
            return null;
        }
        
        // Check expiration
        if (isset($cached['expires_at']) && time() > $cached['expires_at']) {
            @unlink($file);
            return null;
        }
        
        return $cached['value'] ?? null;
    }
    
    /**
     * Set cache value
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time-to-live in seconds (null = use default)
     * @return bool Success status
     */
    public function set($key, $value, $ttl = null) {
        $file = $this->getFilePath($key);
        $ttl = $ttl ?? $this->default_ttl;
        
        $data = [
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time()
        ];
        
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return @file_put_contents($file, $json, LOCK_EX) !== false;
    }
    
    /**
     * Delete cached value
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete($key) {
        $file = $this->getFilePath($key);
        return file_exists($file) ? @unlink($file) : true;
    }
    
    /**
     * Clear all cache files
     * @return bool Success status
     */
    public function clear() {
        $files = glob($this->cache_dir . 'cache_*.dat');
        if ($files === false) {
            return false;
        }
        
        foreach ($files as $file) {
            @unlink($file);
        }
        
        return true;
    }
    
    /**
     * Clean up expired cache files
     * @return int Number of files deleted
     */
    public function cleanup() {
        $files = glob($this->cache_dir . 'cache_*.dat');
        if ($files === false) {
            return 0;
        }
        
        $deleted = 0;
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data === false) {
                continue;
            }

            $cached = json_decode($data, true);
            if ($cached === null || !is_array($cached)) {
                @unlink($file);
                $deleted++;
                continue;
            }
            
            if (isset($cached['expires_at']) && time() > $cached['expires_at']) {
                @unlink($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Get file path for cache key
     * @param string $key Cache key
     * @return string File path
     */
    private function getFilePath($key) {
        $safe_key = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cache_dir . 'cache_' . $safe_key . '.dat';
    }
}
