<?php
// ============================================
// BACKEND NEXUSMAIL - VERSION AIVEN
// ============================================

// Configuration des erreurs pour la production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Headers CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclusion de la configuration
require_once __DIR__ . '/config.php';

class NexusMailAPI {
    private $pdo;
    private $uploadDir = 'uploads/';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->createUploadDirectories();
    }
    
    private function createUploadDirectories() {
        $dirs = ['uploads/images', 'uploads/documents', 'uploads/others'];
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    private function isAuthenticated() {
        if (!isset($_SESSION['user_id'])) {
            $this->sendResponse(false, 'Non authentifié', null, 401);
            return false;
        }
        return true;
    }
    
    private function sendResponse($success, $message, $data = null, $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        // Liste des actions qui ne nécessitent pas d'authentification
        $publicActions = ['register', 'login', 'check_session'];
        
        if (!in_array($action, $publicActions) && !$this->isAuthenticated()) {
            return;
        }
        
        switch($action) {
            case 'register':
                $this->register();
                break;
            case 'login':
                $this->login();
                break;
            case 'check_session':
                $this->checkSession();
                break;
            case 'logout':
                $this->logout();
                break;
            case 'send_message':
                $this->sendMessage();
                break;
            case 'get_messages':
                $this->getMessages();
                break;
            case 'get_conversations':
                $this->getConversations();
                break;
            case 'read_message':
                $this->readMessage();
                break;
            case 'delete_message':
                $this->deleteMessage();
                break;
            case 'get_users':
                $this->getUsers();
                break;
            case 'search_users':
                $this->searchUsers();
                break;
            case 'upload_file':
                $this->uploadFile();
                break;
            case 'download_file':
                $this->downloadFile();
                break;
            case 'update_status':
                $this->updateStatus();
                break;
            case 'get_unread_count':
                $this->getUnreadCount();
                break;
            default:
                $this->sendResponse(false, 'Action non trouvée: ' . $action, null, 404);
        }
    }
    
    private function register() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['username']) || !isset($data['email']) || !isset($data['password'])) {
            $this->sendResponse(false, 'Données incomplètes');
            return;
        }
        
        // Validation
        if ($data['password'] !== $data['confirm_password']) {
            $this->sendResponse(false, 'Les mots de passe ne correspondent pas');
            return;
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->sendResponse(false, 'Email invalide');
            return;
        }
        
        if (strlen($data['password']) < 6) {
            $this->sendResponse(false, 'Le mot de passe doit contenir au moins 6 caractères');
            return;
        }
        
        if (strlen($data['username']) < 3) {
            $this->sendResponse(false, 'Le nom d\'utilisateur doit contenir au moins 3 caractères');
            return;
        }
        
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        
        try {
            $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$data['username'], $data['email'], $hashed_password]);
            $this->sendResponse(true, 'Inscription réussie ! Vous pouvez maintenant vous connecter.');
        } catch(PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $this->sendResponse(false, 'Nom d\'utilisateur ou email déjà utilisé');
            } else {
                error_log("Erreur inscription: " . $e->getMessage());
                $this->sendResponse(false, 'Erreur lors de l\'inscription');
            }
        }
    }
    
    private function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['username']) || !isset($data['password'])) {
            $this->sendResponse(false, 'Données incomplètes');
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$data['username'], $data['username']]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($data['password'], $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                
                // Mettre à jour le statut
                $stmt = $this->pdo->prepare("UPDATE users SET status = 'online', last_seen = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                $this->sendResponse(true, 'Connexion réussie ! Redirection en cours...', [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email']
                ]);
            } else {
                $this->sendResponse(false, 'Nom d\'utilisateur ou mot de passe incorrect');
            }
        } catch(PDOException $e) {
            error_log("Erreur login: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la connexion');
        }
    }
    
    private function checkSession() {
        if (isset($_SESSION['user_id'])) {
            $this->sendResponse(true, 'Session valide', [
                'user_id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'email' => $_SESSION['email']
            ]);
        } else {
            $this->sendResponse(false, 'Session invalide');
        }
    }
    
    private function logout() {
        if (isset($_SESSION['user_id'])) {
            try {
                $stmt = $this->pdo->prepare("UPDATE users SET status = 'offline', last_seen = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            } catch(PDOException $e) {
                error_log("Erreur logout: " . $e->getMessage());
            }
        }
        session_destroy();
        $this->sendResponse(true, 'Déconnexion réussie');
    }
    
    private function uploadFile() {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->sendResponse(false, 'Erreur lors de l\'upload du fichier');
            return;
        }
        
        $file = $_FILES['file'];
        $maxSize = defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : 10 * 1024 * 1024;
        
        if ($file['size'] > $maxSize) {
            $this->sendResponse(false, 'Fichier trop volumineux (max ' . ($maxSize / 1024 / 1024) . 'MB)');
            return;
        }
        
        $mimeType = mime_content_type($file['tmp_name']);
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        $allowedExtensions = defined('ALLOWED_EXTENSIONS') ? ALLOWED_EXTENSIONS : ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
        
        if (!in_array($extension, $allowedExtensions)) {
            $this->sendResponse(false, 'Type de fichier non autorisé');
            return;
        }
        
        // Déterminer le dossier
        if (strpos($mimeType, 'image/') !== false) {
            $subDir = 'images';
        } elseif (in_array($extension, ['pdf', 'doc', 'docx', 'txt', 'xls', 'xlsx', 'ppt', 'pptx'])) {
            $subDir = 'documents';
        } else {
            $subDir = 'others';
        }
        
        $uploadPath = $this->uploadDir . $subDir . '/';
        $uniqueName = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $destination = $uploadPath . $uniqueName;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $this->sendResponse(true, 'Fichier uploadé avec succès', [
                'filename' => $uniqueName,
                'original_name' => $file['name'],
                'file_path' => $destination,
                'file_size' => $file['size'],
                'file_type' => $extension,
                'mime_type' => $mimeType
            ]);
        } else {
            $this->sendResponse(false, 'Erreur lors de la sauvegarde du fichier');
        }
    }
    
    private function sendMessage() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['to_username']) || (!isset($data['content']) && empty($data['attachments']))) {
            $this->sendResponse(false, 'Données incomplètes');
            return;
        }
        
        try {
            // Trouver le destinataire
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$data['to_username']]);
            $receiver = $stmt->fetch();
            
            if (!$receiver) {
                $this->sendResponse(false, 'Utilisateur destinataire non trouvé');
                return;
            }
            
            // Commencer la transaction
            $this->pdo->beginTransaction();
            
            // Insérer le message
            $stmt = $this->pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, subject, content) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $receiver['id'],
                $data['subject'] ?? '',
                $data['content'] ?? ''
            ]);
            
            $messageId = $this->pdo->lastInsertId();
            
            // Insérer les pièces jointes
            if (isset($data['attachments']) && is_array($data['attachments']) && count($data['attachments']) > 0) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO attachments (message_id, filename, original_name, file_path, file_size, file_type, mime_type) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($data['attachments'] as $attachment) {
                    $stmt->execute([
                        $messageId,
                        $attachment['filename'],
                        $attachment['original_name'],
                        $attachment['file_path'],
                        $attachment['file_size'],
                        $attachment['file_type'],
                        $attachment['mime_type']
                    ]);
                }
            }
            
            // Mettre à jour ou créer la conversation
            $user1 = min($_SESSION['user_id'], $receiver['id']);
            $user2 = max($_SESSION['user_id'], $receiver['id']);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO conversations (user1_id, user2_id, last_message_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE last_message_at = NOW()
            ");
            $stmt->execute([$user1, $user2]);
            
            $this->pdo->commit();
            $this->sendResponse(true, 'Message envoyé avec succès', ['message_id' => $messageId]);
            
        } catch(Exception $e) {
            $this->pdo->rollBack();
            error_log("Erreur envoi message: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de l\'envoi du message');
        }
    }
    
    private function getMessages() {
        $type = $_GET['type'] ?? 'inbox';
        $limit = min(intval($_GET['limit'] ?? 50), 100);
        $offset = intval($_GET['offset'] ?? 0);
        
        try {
            if ($type == 'inbox') {
                $sql = "
                    SELECT m.*, 
                           u1.username as sender_name, 
                           u1.avatar as sender_avatar,
                           u2.username as receiver_name,
                           u2.avatar as receiver_avatar,
                           (SELECT COUNT(*) FROM attachments WHERE message_id = m.id) as attachments_count
                    FROM messages m
                    JOIN users u1 ON m.sender_id = u1.id
                    JOIN users u2 ON m.receiver_id = u2.id
                    WHERE m.receiver_id = ? AND (m.is_deleted_receiver = FALSE OR m.is_deleted_receiver IS NULL)
                    ORDER BY m.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$_SESSION['user_id'], $limit, $offset]);
            } else {
                $sql = "
                    SELECT m.*, 
                           u1.username as sender_name,
                           u1.avatar as sender_avatar,
                           u2.username as receiver_name,
                           u2.avatar as receiver_avatar,
                           (SELECT COUNT(*) FROM attachments WHERE message_id = m.id) as attachments_count
                    FROM messages m
                    JOIN users u1 ON m.sender_id = u1.id
                    JOIN users u2 ON m.receiver_id = u2.id
                    WHERE m.sender_id = ? AND (m.is_deleted_sender = FALSE OR m.is_deleted_sender IS NULL)
                    ORDER BY m.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$_SESSION['user_id'], $limit, $offset]);
            }
            
            $messages = $stmt->fetchAll();
            
            // Récupérer les pièces jointes
            foreach ($messages as &$message) {
                $stmt = $this->pdo->prepare("SELECT * FROM attachments WHERE message_id = ?");
                $stmt->execute([$message['id']]);
                $message['attachments'] = $stmt->fetchAll();
            }
            
            $this->sendResponse(true, 'Messages récupérés', $messages);
        } catch(PDOException $e) {
            error_log("Erreur getMessages: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la récupération des messages');
        }
    }
    
    private function getConversations() {
        try {
            $sql = "
                SELECT 
                    CASE 
                        WHEN c.user1_id = ? THEN c.user2_id
                        ELSE c.user1_id
                    END as other_user_id,
                    u.username as other_username,
                    u.avatar as other_avatar,
                    u.status as other_status,
                    u.last_seen,
                    m.content as last_message,
                    m.created_at as last_message_time,
                    (SELECT COUNT(*) FROM messages WHERE sender_id = other_user_id AND receiver_id = ? AND is_read = FALSE AND (is_deleted_receiver = FALSE OR is_deleted_receiver IS NULL)) as unread_count
                FROM conversations c
                JOIN users u ON (CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END) = u.id
                LEFT JOIN messages m ON m.id = (
                    SELECT id FROM messages 
                    WHERE ((sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?))
                    AND (is_deleted_sender = FALSE OR is_deleted_sender IS NULL)
                    AND (is_deleted_receiver = FALSE OR is_deleted_receiver IS NULL)
                    ORDER BY created_at DESC 
                    LIMIT 1
                )
                WHERE (c.user1_id = ? OR c.user2_id = ?)
                ORDER BY m.created_at DESC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $_SESSION['user_id']
            ]);
            
            $conversations = $stmt->fetchAll();
            $this->sendResponse(true, 'Conversations récupérées', $conversations);
        } catch(PDOException $e) {
            error_log("Erreur getConversations: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la récupération des conversations');
        }
    }
    
    private function readMessage() {
        $messageId = intval($_GET['id'] ?? 0);
        
        if ($messageId <= 0) {
            $this->sendResponse(false, 'ID de message invalide');
            return;
        }
        
        try {
            // Marquer comme lu
            $stmt = $this->pdo->prepare("
                UPDATE messages SET is_read = TRUE 
                WHERE id = ? AND receiver_id = ?
            ");
            $stmt->execute([$messageId, $_SESSION['user_id']]);
            
            // Récupérer le message complet
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       u1.username as sender_name,
                       u1.avatar as sender_avatar,
                       u2.username as receiver_name,
                       u2.avatar as receiver_avatar
                FROM messages m
                JOIN users u1 ON m.sender_id = u1.id
                JOIN users u2 ON m.receiver_id = u2.id
                WHERE m.id = ?
            ");
            $stmt->execute([$messageId]);
            $message = $stmt->fetch();
            
            if (!$message) {
                $this->sendResponse(false, 'Message non trouvé');
                return;
            }
            
            // Récupérer les pièces jointes
            $stmt = $this->pdo->prepare("SELECT * FROM attachments WHERE message_id = ?");
            $stmt->execute([$messageId]);
            $message['attachments'] = $stmt->fetchAll();
            
            $this->sendResponse(true, 'Message lu', $message);
        } catch(PDOException $e) {
            error_log("Erreur readMessage: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la lecture du message');
        }
    }
    
    private function deleteMessage() {
        $messageId = intval($_GET['id'] ?? 0);
        $type = $_GET['type'] ?? 'inbox';
        
        if ($messageId <= 0) {
            $this->sendResponse(false, 'ID de message invalide');
            return;
        }
        
        try {
            if ($type == 'inbox') {
                $stmt = $this->pdo->prepare("UPDATE messages SET is_deleted_receiver = TRUE WHERE id = ? AND receiver_id = ?");
            } else {
                $stmt = $this->pdo->prepare("UPDATE messages SET is_deleted_sender = TRUE WHERE id = ? AND sender_id = ?");
            }
            
            $stmt->execute([$messageId, $_SESSION['user_id']]);
            $this->sendResponse(true, 'Message supprimé');
        } catch(PDOException $e) {
            error_log("Erreur deleteMessage: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la suppression');
        }
    }
    
    private function getUsers() {
        $search = $_GET['search'] ?? '';
        
        try {
            $sql = "SELECT id, username, email, avatar, status, last_seen FROM users WHERE id != ?";
            $params = [$_SESSION['user_id']];
            
            if (!empty($search)) {
                $sql .= " AND (username LIKE ? OR email LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $sql .= " ORDER BY username LIMIT 50";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            $this->sendResponse(true, 'Utilisateurs récupérés', $users);
        } catch(PDOException $e) {
            error_log("Erreur getUsers: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la récupération des utilisateurs');
        }
    }
    
    private function searchUsers() {
        $query = $_GET['q'] ?? '';
        
        if (strlen($query) < 2) {
            $this->sendResponse(true, 'Terme trop court', []);
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, username, email, avatar, status 
                FROM users 
                WHERE id != ? AND (username LIKE ? OR email LIKE ?)
                LIMIT 20
            ");
            $stmt->execute([$_SESSION['user_id'], "%$query%", "%$query%"]);
            $users = $stmt->fetchAll();
            
            $this->sendResponse(true, 'Recherche terminée', $users);
        } catch(PDOException $e) {
            error_log("Erreur searchUsers: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la recherche');
        }
    }
    
    private function downloadFile() {
        $fileId = intval($_GET['id'] ?? 0);
        
        if ($fileId <= 0) {
            $this->sendResponse(false, 'ID de fichier invalide', null, 404);
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT a.*, m.sender_id, m.receiver_id 
                FROM attachments a
                JOIN messages m ON a.message_id = m.id
                WHERE a.id = ?
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();
            
            if (!$file) {
                $this->sendResponse(false, 'Fichier non trouvé', null, 404);
                return;
            }
            
            // Vérifier l'accès
            if ($file['sender_id'] != $_SESSION['user_id'] && $file['receiver_id'] != $_SESSION['user_id']) {
                $this->sendResponse(false, 'Accès non autorisé', null, 403);
                return;
            }
            
            if (file_exists($file['file_path'])) {
                header('Content-Type: ' . $file['mime_type']);
                header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
                header('Content-Length: ' . $file['file_size']);
                header('Cache-Control: private');
                header('Pragma: public');
                readfile($file['file_path']);
                exit;
            } else {
                $this->sendResponse(false, 'Fichier introuvable sur le serveur', null, 404);
            }
        } catch(PDOException $e) {
            error_log("Erreur downloadFile: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors du téléchargement', null, 500);
        }
    }
    
    private function updateStatus() {
        $status = $_GET['status'] ?? 'online';
        
        $validStatus = ['online', 'offline', 'away'];
        if (!in_array($status, $validStatus)) {
            $status = 'online';
        }
        
        try {
            $stmt = $this->pdo->prepare("UPDATE users SET status = ?, last_seen = NOW() WHERE id = ?");
            $stmt->execute([$status, $_SESSION['user_id']]);
            
            $this->sendResponse(true, 'Statut mis à jour');
        } catch(PDOException $e) {
            error_log("Erreur updateStatus: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors de la mise à jour du statut');
        }
    }
    
    private function getUnreadCount() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM messages 
                WHERE receiver_id = ? AND is_read = FALSE AND (is_deleted_receiver = FALSE OR is_deleted_receiver IS NULL)
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $count = $stmt->fetch();
            
            $this->sendResponse(true, 'Nombre de messages non lus', ['count' => intval($count['count'])]);
        } catch(PDOException $e) {
            error_log("Erreur getUnreadCount: " . $e->getMessage());
            $this->sendResponse(false, 'Erreur lors du comptage', ['count' => 0]);
        }
    }
}

// ============================================
// CONNEXION À LA BASE DE DONNÉES
// ============================================

try {
    // Construction du DSN avec SSL si configuré
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 30
    ];
    
    // Ajout du certificat SSL si défini
    if (defined('SSL_CA_PATH') && file_exists(SSL_CA_PATH)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = SSL_CA_PATH;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    
    // Vérifier la connexion
    $pdo->query("SELECT 1");
    
    $api = new NexusMailAPI($pdo);
    $api->handleRequest();
    
} catch(PDOException $e) {
    error_log("Erreur de connexion BDD: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de connexion à la base de données. Veuillez réessayer plus tard.',
        'debug' => ($_SERVER['SERVER_NAME'] === 'localhost') ? $e->getMessage() : null
    ]);
}
?>