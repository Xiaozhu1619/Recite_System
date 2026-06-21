<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$config = require 'config.php';

function getDbConnection() {
    global $config;
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function checkAuth() {
    global $config;
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return $token === md5($config['admin_password']);
}

function jsonResponse($success, $data = []) {
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 登录
if ($action === 'login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';
    
    if ($password === $config['admin_password']) {
        jsonResponse(true, ['token' => md5($password)]);
    } else {
        jsonResponse(false, ['error' => '密码错误']);
    }
}

// 需要认证的接口
if (!checkAuth()) {
    jsonResponse(false, ['error' => '未授权访问']);
}

$pdo = getDbConnection();
if (!$pdo) {
    jsonResponse(false, ['error' => '数据库连接失败']);
}

switch ($action) {
    case 'dashboard':
        $users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $cards = $pdo->query("SELECT COUNT(*) FROM cards")->fetchColumn();
        $reviews = $pdo->query("SELECT COALESCE(SUM(review_count), 0) FROM cards")->fetchColumn();
        $categories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        
        jsonResponse(true, [
            'stats' => [
                'users' => (int)$users,
                'cards' => (int)$cards,
                'reviews' => (int)$reviews,
                'categories' => (int)$categories
            ],
            'system' => [
                'php' => PHP_VERSION,
                'mysql' => $pdo->query("SELECT VERSION()")->fetchColumn(),
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'disk' => round(disk_free_space('.') / 1073741824, 2) . ' GB'
            ]
        ]);
        break;
        
    case 'users':
        $stmt = $pdo->query("
            SELECT u.*, COUNT(c.id) as card_count 
            FROM users u 
            LEFT JOIN cards c ON u.id = c.user_id 
            GROUP BY u.id 
            ORDER BY u.created_at DESC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, ['users' => $users]);
        break;
        
    case 'edit-user':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        $nickname = $input['nickname'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE users SET nickname = ? WHERE id = ?");
        $stmt->execute([$nickname, $id]);
        jsonResponse(true, ['message' => '用户已更新']);
        break;
        
    case 'delete-user':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        
        if ($id == 1) {
            jsonResponse(false, ['error' => '不能删除管理员账户']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(true, ['message' => '用户已删除']);
        break;
        
    case 'save-config':
        $input = json_decode(file_get_contents('php://input'), true);
        
        $newConfig = [
            'host' => $input['host'] ?? 'localhost',
            'dbname' => $input['dbname'] ?? 'recite_system',
            'username' => $input['username'] ?? 'recite',
            'password' => $input['password'] ?? $config['password'],
            'charset' => 'utf8mb4',
            'admin_password' => $config['admin_password']
        ];
        
        $content = "<?php\nreturn " . var_export($newConfig, true) . ";\n?>";
        file_put_contents('config.php', $content);
        jsonResponse(true, ['message' => '配置已保存']);
        break;
        
    case 'save-settings':
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!empty($input['admin_password'])) {
            $config['admin_password'] = $input['admin_password'];
        }
        
        $content = "<?php\nreturn " . var_export($config, true) . ";\n?>";
        file_put_contents('config.php', $content);
        jsonResponse(true, ['message' => '设置已保存']);
        break;
        
    case 'execute-sql':
        $input = json_decode(file_get_contents('php://input'), true);
        $query = $input['query'] ?? '';
        
        try {
            $stmt = $pdo->query($query);
            if ($stmt) {
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                jsonResponse(true, ['result' => $result]);
            } else {
                jsonResponse(true, ['result' => '执行成功']);
            }
        } catch (Exception $e) {
            jsonResponse(false, ['error' => $e->getMessage()]);
        }
        break;
        
    case 'backup':
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $backup = "-- 考研背诵系统数据库备份\n";
        $backup .= "-- 时间: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $backup .= $create[1] . ";\n\n";
        }
        
        $filename = 'backup_' . date('Ymd_His') . '.sql';
        file_put_contents($filename, $backup);
        jsonResponse(true, ['message' => "备份已保存到 {$filename}"]);
        break;
        
    case 'optimize':
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("OPTIMIZE TABLE `$table`");
        }
        jsonResponse(true, ['message' => '表已优化']);
        break;
        
    case 'check':
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $results = [];
        foreach ($tables as $table) {
            $result = $pdo->query("CHECK TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $results[] = $table . ': ' . $result['Msg_text'];
        }
        jsonResponse(true, ['message' => implode("\n", $results)]);
        break;
        
    case 'clear-data':
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("TRUNCATE TABLE cards");
        $pdo->exec("TRUNCATE TABLE history");
        $pdo->exec("TRUNCATE TABLE categories");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        jsonResponse(true, ['message' => '数据已清除']);
        break;
        
    case 'reset':
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("TRUNCATE TABLE cards");
        $pdo->exec("TRUNCATE TABLE history");
        $pdo->exec("TRUNCATE TABLE categories");
        $pdo->exec("TRUNCATE TABLE users");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        $pdo->exec("INSERT INTO users (username, password, nickname) VALUES ('admin', '123456', '管理员')");
        jsonResponse(true, ['message' => '系统已重置']);
        break;
        
    default:
        jsonResponse(false, ['error' => '未知操作']);
}
?>
