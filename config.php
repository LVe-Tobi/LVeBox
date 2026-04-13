<?php
// ============================================
// Configuration Aiven pour NexusMail
// ============================================

// Identifiants Aiven MySQL (à remplacer par les vôtres)
$db_host = 'mysql-1139dde-lvetobitella-58eb.l.aivencloud.com';  // Votre host
$db_port = '12166';                                 // Votre port
$db_name = 'defaultdb';                             // Ne pas changer
$db_user = 'avnadmin';                              // Votre user
$db_pass = 'AVNS_ujl7q2RmHYQJPG7jQrq';              // Votre mot de passe

// Configuration des uploads
define('MAX_FILE_SIZE', 10 * 1024 * 1024);          // 10MB max
define('ALLOWED_EXTENSIONS', [
    'jpg', 'jpeg', 'png', 'gif',
    'pdf', 'doc', 'docx', 'txt',
    'zip', 'rar'
]);
define('UPLOAD_DIR', 'uploads/');

// Chemin du certificat SSL Aiven
define('SSL_CA_PATH', __DIR__ . '/ca.pem');

// Connexion PDO avec SSL
try {
    $pdo = new PDO(
        "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_SSL_CA => SSL_CA_PATH,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false
        ]
    );
} catch(PDOException $e) {
    error_log("Erreur de connexion DB: " . $e->getMessage());
    die("Erreur de connexion à la base de données. Veuillez réessayer plus tard.");
}

// Configuration de session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
