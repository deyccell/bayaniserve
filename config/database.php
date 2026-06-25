<?php
/**
 * BayaniServe - Database Configuration
 * Update DB_USER and DB_PASS to match your MySQL setup.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'bayaniserve');
define('DB_USER', 'root');
define('DB_PASS', '');  // change if your MySQL has a password

// AI model — running via Ollama on port 11434
define('OLLAMA_URL',   'http://127.0.0.1:11434/api/chat');
define('OLLAMA_MODEL', 'medicine-chatbot');   // your fine-tuned model

define('LOW_STOCK_THRESHOLD', 15);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
