<?php

namespace FBCallNow\Core;

/**
 * Debug logging system for FB Call Now plugin
 * 
 * @package FBCallNow\Core
 * @since 3.0.0
 */
class Logger {
    
    /**
     * Old log file name, directly in uploads/ where anyone could download it (CR275)
     */
    const LEGACY_LOG_FILE = 'fb-call-now.log';

    /**
     * Option holding the random part of the private log folder's name
     */
    const DIR_KEY_OPTION = 'fbcn_log_dir_key';

    /**
     * First line of the log file. The log is a .php file, so a web request for it runs this
     * and gets an empty page, even on hosts that ignore .htaccess (nginx).
     */
    const GUARD = "<?php exit; ?>\n";

    /**
     * Past this size the log rolls over to debug-old.php (one old file is kept)
     */
    const MAX_BYTES = 1048576;

    /**
     * Lines already written in this request, so checks that run twice per page view
     * (should_display_button on enqueue and in the footer) are logged once
     */
    private static $written = array();
    
    /**
     * Log an info message
     * 
     * @param string $message The message to log
     */
    public static function info(string $message) {
        self::write_log('INFO', $message);
    }
    
    /**
     * Log an error message
     * 
     * @param string $message The message to log
     */
    public static function error(string $message) {
        self::write_log('ERROR', $message);
    }
    
    /**
     * Write message to log file
     * 
     * @param string $level Log level (INFO, ERROR, etc.)
     * @param string $message The message to log
     */
    private static function write_log(string $level, string $message) {
        // Check if debug logging is enabled in Pro Settings
        $pro_settings = get_option('fbcn_pro_settings', array());
        if (empty($pro_settings['debug_logging'])) {
            return; // Debug logging is disabled
        }

        // Same line twice in one request: log it once
        $seen = $level . ':' . $message;
        if (isset(self::$written[$seen])) {
            return;
        }
        self::$written[$seen] = true;
        
        $log_file = self::get_log_file_path();
        if (!self::ensure_log_dir(dirname($log_file))) {
            return;
        }
        
        // Format the log entry
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] %s: %s\n", $timestamp, $level, $message);

        // Size cap: roll the full log over to one old file
        clearstatcache(true, $log_file);
        if (file_exists($log_file) && filesize($log_file) + strlen($log_entry) > self::MAX_BYTES) {
            @rename($log_file, self::get_old_log_file_path());
        }

        self::ensure_guard($log_file);
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Make the private log folder with a deny-all .htaccess and an index.php
     *
     * @param string $dir Folder path
     * @return bool Whether the folder exists
     */
    private static function ensure_log_dir(string $dir) {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir)) {
            return false;
        }
        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return true;
    }

    /**
     * Start the log file with the exit guard if it doesn't exist yet ('x' mode creates it
     * atomically, so two requests can't both write the guard)
     *
     * @param string $file Log file path
     */
    private static function ensure_guard(string $file) {
        if (file_exists($file)) {
            return;
        }
        $h = @fopen($file, 'x');
        if ($h) {
            fwrite($h, self::GUARD);
            fclose($h);
        }
    }
    
    /**
     * Get the full path to the log file
     *
     * uploads/fbcn-logs-<random>/debug.php: a folder name nobody can guess, a deny-all
     * .htaccess, an index.php against listing, and an exit guard as the file's first line
     * for hosts that ignore .htaccess (CR275).
     * 
     * @return string Full path to log file
     */
    public static function get_log_file_path() {
        return self::get_log_dir() . '/debug.php';
    }

    /**
     * Path of the rolled-over old log
     *
     * @return string
     */
    private static function get_old_log_file_path() {
        return self::get_log_dir() . '/debug-old.php';
    }

    /**
     * Private log folder, picking its random name the first time
     *
     * @return string
     */
    private static function get_log_dir() {
        $upload_dir = wp_upload_dir();
        $key = (string) get_option(self::DIR_KEY_OPTION, '');
        if (!preg_match('/^[A-Za-z0-9]{20,}$/', $key)) {
            $key = wp_generate_password(32, false, false);
            update_option(self::DIR_KEY_OPTION, $key, false);
        }
        return trailingslashit($upload_dir['basedir']) . 'fbcn-logs-' . $key;
    }

    /**
     * Move an old public uploads/fb-call-now.log into the private log, whether or not logging
     * is on, and delete it (run once from Upgrade)
     */
    public static function secure_legacy_log() {
        $upload_dir = wp_upload_dir();
        $legacy = trailingslashit($upload_dir['basedir']) . self::LEGACY_LOG_FILE;
        if (!file_exists($legacy)) {
            return;
        }
        $log_file = self::get_log_file_path();
        if (self::ensure_log_dir(dirname($log_file))) {
            self::ensure_guard($log_file);
            file_put_contents($log_file, (string) file_get_contents($legacy), FILE_APPEND | LOCK_EX);
        }
        @unlink($legacy);
    }
    
    /**
     * Read log file contents
     * 
     * @param int $lines Number of lines to read from end of file
     * @return string Log file contents
     */
    public static function read_log(int $lines = 200) {
        $log_file = self::get_log_file_path();
        
        if (!file_exists($log_file)) {
            return __('Log file does not exist yet. Activate the plugin and perform some actions to generate log entries.', 'fb-call-now');
        }
        
        if (!is_readable($log_file)) {
            return __('Log file exists but is not readable. Please check file permissions.', 'fb-call-now');
        }
        
        // Read the last N lines efficiently (without the exit guard line)
        $content = self::tail($log_file, $lines);
        $guard = trim(self::GUARD);
        if (strpos($content, $guard) === 0) {
            $content = ltrim(substr($content, strlen($guard)), "\n");
        }
        
        if (empty($content)) {
            return __('Log file is empty.', 'fb-call-now');
        }
        
        return $content;
    }
    
    /**
     * Get the last N lines of a file efficiently
     * 
     * @param string $file Path to file
     * @param int $lines Number of lines to retrieve
     * @return string File content
     */
    private static function tail(string $file, int $lines = 200) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            return '';
        }
        
        $line_count = 0;
        $lines_array = array();
        
        // Start from the end of the file
        fseek($handle, -1, SEEK_END);
        
        // Read backwards
        $position = ftell($handle);
        $line = '';
        
        // Down to and including byte 0 (the first character was being dropped)
        while ($position >= 0 && $line_count < $lines) {
            fseek($handle, $position);
            $char = fgetc($handle);
            
            if ($char === "\n") {
                $lines_array[] = strrev($line);
                $line = '';
                $line_count++;
            } else {
                $line .= $char;
            }
            
            $position--;
        }
        
        // Don't forget the last line
        if (!empty($line) && $line_count < $lines) {
            $lines_array[] = strrev($line);
        }
        
        fclose($handle);
        
        // Reverse the array to get chronological order
        return implode("\n", array_reverse($lines_array));
    }
    
    /**
     * Clear the log file
     * 
     * @return bool Success status
     */
    public static function clear_log() {
        $log_file = self::get_log_file_path();
        
        $old = self::get_old_log_file_path();
        if (file_exists($old)) {
            @unlink($old);
        }

        if (file_exists($log_file)) {
            $result = unlink($log_file);
            if ($result) {
                self::info('Log file cleared by user');
            }
            return $result;
        }
        
        return true; // Already doesn't exist
    }
    
    /**
     * Get log file size
     * 
     * @return string Human readable file size
     */
    public static function get_log_file_size() {
        $log_file = self::get_log_file_path();
        
        if (!file_exists($log_file)) {
            return '0 B';
        }
        
        $bytes = max(0, filesize($log_file) - strlen(self::GUARD));
        $units = array('B', 'KB', 'MB', 'GB');
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}