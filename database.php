<?php
// /babol_bargh_notifier/database.php

require_once __DIR__ . '/config.php'; // برای دسترسی به ثابت‌های DB_HOST, DB_NAME, DB_USER, DB_PASS

class Database {
    private static $instance = null;
    private PDO $pdo;

    // مقادیر از config.php خوانده می‌شوند
    private string $host = DB_HOST;
    private string $db_name = DB_NAME;
    private string $username = DB_USER;
    private string $password = DB_PASS;
    private string $charset = 'utf8mb4'; // استفاده مستقیم از utf8mb4

    private function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // این تنظیم برای جلوگیری از HY093 خوب است
        ];
        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            // در حالت DEBUG_MODE، جزئیات خطا نمایش داده می‌شود، در غیر این صورت پیام عمومی
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                throw new PDOException($e->getMessage(), (int)$e->getCode());
            } else {
                // برای کاربر نهایی، بهتر است جزئیات خطا نمایش داده نشود.
                die("خطا در برقراری ارتباط با پایگاه داده. لطفاً با پشتیبانی تماس بگیرید.");
            }
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }

    // متدهای کمکی (این متدها در کد manage_users.php فعلی مستقیماً استفاده نمی‌شوند،
    // اما اگر در جای دیگری از آنها استفاده می‌کنید، می‌توانند باقی بمانند)
    public function selectAll(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function selectOne(string $sql, array $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function executeQuery(string $sql, array $params = []): bool {
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Database ExecuteQuery Error: " . $e->getMessage() . " SQL: " . $sql . " Params: " . print_r($params, true));
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                // می‌توانید خطا را به جای echo کردن، throw کنید
                 throw $e;
            }
            return false;
        }
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}
?>