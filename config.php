<?php
// ============================================
// Configuration Aiven pour NexusMail
// ============================================

// Identifiants Aiven MySQL (à remplacer par les vôtres)

// ============================================
// Configuration pour Render + Aiven
// ============================================

// Variables d'environnement (Render les fournira)
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'nexusmail';    // Changé : pour XAMPP local
$db_user = getenv('DB_USER') ?: 'root';          // Changé : pour XAMPP local
$db_pass = getenv('DB_PASS') ?: '';              // Changé : pour XAMPP local

// Configuration des uploads
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt']);
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Créer le dossier d'upload si inexistant
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    mkdir(UPLOAD_DIR . 'images/', 0755, true);
    mkdir(UPLOAD_DIR . 'documents/', 0755, true);
    mkdir(UPLOAD_DIR . 'others/', 0755, true);
}

// Connexion PDO avec SSL
try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];
    
    // Ajouter SSL si certificat présent (pour Aiven)
    if (file_exists(__DIR__ . '/ca.pem')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = __DIR__ . '/ca.pem';
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    
    $pdo = new PDO(
        "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        $options
    );
} catch(PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données']));
}

// Session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);  // HTTPS uniquement en production
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
