<?php
$config = require 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']}"
        ]
    );
} catch (PDOException $e) {
    sendJson(500, ['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()]);
}

function getUser($pdo, $username, $password) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt->execute([$username, $password]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserById($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function sendJson($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 处理action参数（用于PHP内置服务器）
$action = $_GET['action'] ?? '';
if (empty($action)) {
    // 从URL路径提取action
    if (preg_match('/\/api\/(\w+)/', $path, $matches)) {
        $action = $matches[1];
    } elseif (preg_match('/api\.php$/', $path)) {
        $action = $_POST['action'] ?? '';
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($action === 'login' || $path === '/api/login') {
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            sendJson(400, ['success' => false, 'error' => '请输入用户名和密码']);
        }
        
        $user = getUser($pdo, $username, $password);
        if ($user) {
            sendJson(200, [
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'nickname' => $user['nickname'] ?: $user['username'],
                    'avatar' => $user['avatar'] ?: ''
                ]
            ]);
        } else {
            sendJson(401, ['success' => false, 'error' => '用户名或密码错误']);
        }
    }
    
    if ($action === 'register' || $path === '/api/register') {
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $nickname = $input['nickname'] ?? $username;
        
        if (empty($username) || empty($password)) {
            sendJson(400, ['success' => false, 'error' => '用户名和密码不能为空']);
        }
        
        if (strlen($password) < 6) {
            sendJson(400, ['success' => false, 'error' => '密码至少6位']);
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            sendJson(409, ['success' => false, 'error' => '用户名已存在']);
        }
        
        $stmt = $pdo->prepare("INSERT INTO users (username, password, nickname, avatar, created_at) VALUES (?, ?, ?, '', NOW())");
        $stmt->execute([$username, $password, $nickname]);
        $userId = $pdo->lastInsertId();
        
        sendJson(200, [
            'success' => true,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'nickname' => $nickname,
                'avatar' => ''
            ]
        ]);
    }
    
    if ($action === 'load' || $path === '/api/load') {
        $userId = $input['userId'] ?? '';
        
        if (empty($userId)) {
            sendJson(400, ['success' => false, 'error' => '缺少用户ID']);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM cards WHERE user_id = ?");
        $stmt->execute([$userId]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT * FROM history WHERE user_id = ?");
        $stmt->execute([$userId]);
        $historyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $history = [];
        foreach ($historyRows as $row) {
            $history[$row['date']] = [
                'reviewed' => (int)$row['reviewed'],
                'mastered' => (int)$row['mastered'],
                'blurry' => (int)$row['blurry'],
                'forget' => (int)$row['forget']
            ];
        }
        
        sendJson(200, [
            'success' => true,
            'data' => [
                'version' => 1,
                'cards' => $cards,
                'history' => $history
            ]
        ]);
    }
    
    if ($action === 'save' || $path === '/api/save') {
        $userId = $input['userId'] ?? '';
        $cards = $input['cards'] ?? [];
        $history = $input['history'] ?? [];
        
        if (empty($userId)) {
            sendJson(400, ['success' => false, 'error' => '缺少用户ID']);
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("DELETE FROM cards WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            $stmt = $pdo->prepare("INSERT INTO cards (id, user_id, type, front, back, box, category, next_review, last_review, review_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cards as $card) {
                $stmt->execute([
                    $card['id'],
                    $userId,
                    $card['type'] ?? 'flashcard',
                    $card['front'] ?? '',
                    $card['back'] ?? '',
                    $card['box'] ?? 1,
                    $card['category'] ?? '',
                    $card['nextReview'] ?? null,
                    $card['lastReview'] ?? null,
                    $card['reviewCount'] ?? 0,
                    isset($card['createdAt']) ? date('Y-m-d H:i:s', $card['createdAt'] / 1000) : date('Y-m-d H:i:s')
                ]);
            }
            
            $stmt = $pdo->prepare("DELETE FROM history WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            $stmt = $pdo->prepare("INSERT INTO history (user_id, date, reviewed, mastered, blurry, forget) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($history as $date => $data) {
                $stmt->execute([
                    $userId,
                    $date,
                    $data['reviewed'] ?? 0,
                    $data['mastered'] ?? 0,
                    $data['blurry'] ?? 0,
                    $data['forget'] ?? 0
                ]);
            }
            
            $pdo->commit();
            sendJson(200, ['success' => true, 'cards' => count($cards)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendJson(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    if ($action === 'update-profile' || $path === '/api/update-profile') {
        $userId = $input['userId'] ?? '';
        $nickname = $input['nickname'] ?? '';
        $avatar = $input['avatar'] ?? '';
        
        if (empty($userId)) {
            sendJson(400, ['success' => false, 'error' => '缺少用户ID']);
        }
        
        $updates = [];
        $params = [];
        
        if (!empty($nickname)) {
            $updates[] = "nickname = ?";
            $params[] = $nickname;
        }
        
        if ($avatar !== null) {
            $updates[] = "avatar = ?";
            $params[] = $avatar;
        }
        
        if (!empty($updates)) {
            $params[] = $userId;
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        sendJson(200, ['success' => true]);
    }
    
    // 分类相关API
    if ($action === 'load-categories' || $path === '/api/load-categories') {
        $userId = $input['userId'] ?? '';
        
        if (empty($userId)) {
            sendJson(400, ['success' => false, 'error' => '缺少用户ID']);
        }
        
        $stmt = $pdo->prepare("SELECT name FROM categories WHERE user_id = ? ORDER BY name");
        $stmt->execute([$userId]);
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        sendJson(200, ['success' => true, 'categories' => $categories]);
    }
    
    if ($action === 'save-categories' || $path === '/api/save-categories') {
        $userId = $input['userId'] ?? '';
        $categories = $input['categories'] ?? [];
        
        if (empty($userId)) {
            sendJson(400, ['success' => false, 'error' => '缺少用户ID']);
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("DELETE FROM categories WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            $stmt = $pdo->prepare("INSERT INTO categories (user_id, name) VALUES (?, ?)");
            foreach ($categories as $name) {
                $stmt->execute([$userId, $name]);
            }
            
            $pdo->commit();
            sendJson(200, ['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendJson(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    // 批量删除API
    if ($action === 'batch-delete' || $path === '/api/batch-delete') {
        $userId = $input['userId'] ?? '';
        $cardIds = $input['cardIds'] ?? [];
        
        if (empty($userId) || empty($cardIds)) {
            sendJson(400, ['success' => false, 'error' => '缺少参数']);
        }
        
        try {
            $pdo->beginTransaction();
            
            $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM cards WHERE user_id = ? AND id IN ($placeholders)");
            $stmt->execute(array_merge([$userId], $cardIds));
            
            $pdo->commit();
            sendJson(200, ['success' => true, 'deleted' => count($cardIds)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendJson(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    sendJson(404, ['success' => false, 'error' => '接口不存在']);
}

if ($method === 'OPTIONS') {
    http_response_code(200);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

sendJson(404, ['success' => false, 'error' => '接口不存在']);
?>
