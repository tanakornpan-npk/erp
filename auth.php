<?php
/**
 * Authentication & User Management API (No KU All-Login)
 * ระบบติดตามและรายงานผลการจัดซื้อจัดจ้างงบลงทุนเงินแผ่นดิน ปี 2569
 * มหาวิทยาลัยเกษตรศาสตร์ วิทยาเขตเฉลิมพระเกียรติ จังหวัดสกลนคร
 *
 * เก็บข้อมูลผู้ใช้งานใน data/users.json เข้ารหัสรหัสผ่านด้วย PASSWORD_BCRYPT
 * รองรับทั้ง PHP Session Cookie และ Bearer Token Header
 */

date_default_timezone_set('Asia/Bangkok');
@ini_set('display_errors', '0');

const USERS_FILE = __DIR__ . '/data/users.json';
const SESSIONS_FILE = __DIR__ . '/data/sessions.json';
const SESSION_TTL_SECONDS = 86400; // 24 ชั่วโมง

function ensureDataDir(): void {
    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0777, true);
    }
}

function getDefaultUsers(): array {
    $now = date('d/m/Y H:i:s');
    return [
        [
            'id'           => 'usr_1',
            'username'     => 'admin',
            'passwordHash' => password_hash('kucsc2569', PASSWORD_BCRYPT),
            'displayName'  => 'ผู้ดูแลระบบ (หน่วยพัสดุ)',
            'role'         => 'admin',
            'active'       => true,
            'createdAt'    => $now,
            'lastLoginAt'  => null,
        ],
        [
            'id'           => 'usr_2',
            'username'     => 'thanradee',
            'passwordHash' => password_hash('kucsc2569', PASSWORD_BCRYPT),
            'displayName'  => 'นางสาวธัญรดี ดาบสมเด็จ',
            'role'         => 'admin',
            'active'       => true,
            'createdAt'    => $now,
            'lastLoginAt'  => null,
        ],
        [
            'id'           => 'usr_3',
            'username'     => 'executive',
            'passwordHash' => password_hash('kucsc2569', PASSWORD_BCRYPT),
            'displayName'  => 'ผู้บริหารวิทยาเขตฯ',
            'role'         => 'user',
            'active'       => true,
            'createdAt'    => $now,
            'lastLoginAt'  => null,
        ],
        [
            'id'           => 'usr_4',
            'username'     => 'patcharaporn',
            'passwordHash' => password_hash('kucsc2569', PASSWORD_BCRYPT),
            'displayName'  => 'พัชรพร (เจ้าหน้าที่พัสดุ)',
            'role'         => 'user',
            'active'       => true,
            'createdAt'    => $now,
            'lastLoginAt'  => null,
        ],
        [
            'id'           => 'usr_5',
            'username'     => 'jirawat',
            'passwordHash' => password_hash('kucsc2569', PASSWORD_BCRYPT),
            'displayName'  => 'จิรวัฒน์ (เจ้าหน้าที่พัสดุ)',
            'role'         => 'user',
            'active'       => true,
            'createdAt'    => $now,
            'lastLoginAt'  => null,
        ],
        [
            'id'           => 'usr_6',
            'username'     => 'nattapon',
            'passwordHash' => password_hash('kucsc2569', PASSWORD_BCRYPT),
            'displayName'  => 'ณัฐพนธ์ (เจ้าหน้าที่พัสดุ)',
            'role'         => 'user',
            'active'       => true,
            'createdAt'    => $now,
            'lastLoginAt'  => null,
        ],
        [
            'id'           => 'usr_7',
            'username'     => 'aomjit',
            'passwordHash' => password_hash('kucsc2569', PASSWORD_BCRYPT),
            'displayName'  => 'อ้อมจิต (เจ้าหน้าที่พัสดุ)',
            'role'         => 'user',
            'active'       => true,
            'createdAt'    => $now,
            'lastLoginAt'  => null,
        ],
    ];
}

function loadUsers(): array {
    ensureDataDir();
    if (!file_exists(USERS_FILE)) {
        $defaults = getDefaultUsers();
        saveUsers($defaults);
        return $defaults;
    }
    $raw = @file_get_contents(USERS_FILE);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data) || empty($data)) {
        $defaults = getDefaultUsers();
        saveUsers($defaults);
        return $defaults;
    }
    return $data;
}

function saveUsers(array $users): void {
    ensureDataDir();
    @file_put_contents(USERS_FILE, json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function loadSessions(): array {
    ensureDataDir();
    if (!file_exists(SESSIONS_FILE)) return [];
    $raw = @file_get_contents(SESSIONS_FILE);
    $sessions = json_decode($raw ?: '{}', true);
    if (!is_array($sessions)) return [];

    $now = time();
    $cleaned = [];
    foreach ($sessions as $token => $info) {
        if (isset($info['expiresAt']) && $info['expiresAt'] > $now) {
            $cleaned[$token] = $info;
        }
    }
    return $cleaned;
}

function saveSessions(array $sessions): void {
    ensureDataDir();
    @file_put_contents(SESSIONS_FILE, json_encode($sessions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function getRequestToken(): string {
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return trim((string)$_SERVER['HTTP_X_AUTH_TOKEN']);
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.+)/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
            return trim($m[1]);
        }
    }
    if (!empty($_GET['token'])) {
        return trim((string)$_GET['token']);
    }
    if (!empty($_COOKIE['kucsc_auth_token'])) {
        return trim((string)$_COOKIE['kucsc_auth_token']);
    }
    return '';
}

function getAuthenticatedUser(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $users = loadUsers();
    $userId = null;

    if (!empty($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
    } else {
        $token = getRequestToken();
        if ($token !== '') {
            $sessions = loadSessions();
            if (isset($sessions[$token])) {
                $userId = $sessions[$token]['userId'];
            }
        }
    }

    if (!$userId) return null;

    foreach ($users as $u) {
        if ($u['id'] === $userId && !empty($u['active'])) {
            return sanitizeUser($u);
        }
    }
    return null;
}

function sanitizeUser(array $u): array {
    return [
        'id'          => $u['id'],
        'username'    => $u['username'],
        'displayName' => $u['displayName'],
        'role'        => $u['role'] ?? 'user',
        'active'      => !empty($u['active']),
        'createdAt'   => $u['createdAt'] ?? '-',
        'lastLoginAt' => $u['lastLoginAt'] ?? null,
    ];
}

// หากไฟล์นี้ถูกเรียกใช้งานตรงๆ ผ่าน HTTP Request ให้ประมวลผล Action
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    $rawBody = $_SERVER['HTTP_X_RAW_BODY'] ?? file_get_contents('php://input');
    $body = json_decode($rawBody ?: '{}', true);
    if (!is_array($body)) $body = [];

    if (!empty($_SERVER['HTTP_X_QUERY_STRING'])) {
        parse_str($_SERVER['HTTP_X_QUERY_STRING'], $parsedGet);
        $_GET = array_merge($_GET, $parsedGet);
    }

    $action = $_GET['action'] ?? $body['action'] ?? 'status';

    try {
        switch ($action) {
            case 'status': {
                $user = getAuthenticatedUser();
                echo json_encode([
                    'ok'            => true,
                    'authenticated' => $user !== null,
                    'user'          => $user,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'login': {
                $username = strtolower(trim((string)($body['username'] ?? $_POST['username'] ?? '')));
                $password = (string)($body['password'] ?? $_POST['password'] ?? '');

                if ($username === '' || $password === '') {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $users = loadUsers();
                $matchedIdx = -1;
                foreach ($users as $idx => $u) {
                    if (strtolower($u['username']) === $username) {
                        $matchedIdx = $idx;
                        break;
                    }
                }

                if ($matchedIdx === -1 || !password_verify($password, $users[$matchedIdx]['passwordHash'])) {
                    http_response_code(401);
                    echo json_encode(['ok' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                if (empty($users[$matchedIdx]['active'])) {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'message' => 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $users[$matchedIdx]['lastLoginAt'] = date('d/m/Y H:i:s');
                saveUsers($users);

                $userObj = sanitizeUser($users[$matchedIdx]);

                if (session_status() === PHP_SESSION_NONE) {
                    @session_start();
                }
                $_SESSION['user_id'] = $userObj['id'];

                $token = bin2hex(random_bytes(32));
                $sessions = loadSessions();
                $sessions[$token] = [
                    'userId'    => $userObj['id'],
                    'createdAt' => time(),
                    'expiresAt' => time() + SESSION_TTL_SECONDS,
                ];
                saveSessions($sessions);

                @setcookie('kucsc_auth_token', $token, [
                    'expires'  => time() + SESSION_TTL_SECONDS,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                echo json_encode([
                    'ok'      => true,
                    'message' => 'เข้าสู่ระบบสำเร็จ',
                    'token'   => $token,
                    'user'    => $userObj,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'logout': {
                if (session_status() === PHP_SESSION_NONE) {
                    @session_start();
                }
                $_SESSION = [];
                @session_destroy();

                $token = getRequestToken();
                if ($token !== '') {
                    $sessions = loadSessions();
                    unset($sessions[$token]);
                    saveSessions($sessions);
                }
                @setcookie('kucsc_auth_token', '', time() - 3600, '/');

                echo json_encode(['ok' => true, 'message' => 'ออกจากระบบเรียบร้อยแล้ว'], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'change_password': {
                $currentUser = getAuthenticatedUser();
                if (!$currentUser) {
                    http_response_code(401);
                    echo json_encode(['ok' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนดำเนินการ'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $oldPassword = (string)($body['oldPassword'] ?? '');
                $newPassword = trim((string)($body['newPassword'] ?? ''));

                if (strlen($newPassword) < 4) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 4 ตัวอักษร'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $users = loadUsers();
                foreach ($users as &$u) {
                    if ($u['id'] === $currentUser['id']) {
                        if (!password_verify($oldPassword, $u['passwordHash'])) {
                            http_response_code(400);
                            echo json_encode(['ok' => false, 'message' => 'รหัสผ่านเดิมไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                        $u['passwordHash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                        break;
                    }
                }
                unset($u);
                saveUsers($users);

                echo json_encode(['ok' => true, 'message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว'], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'list_users': {
                $currentUser = getAuthenticatedUser();
                if (!$currentUser || $currentUser['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'message' => 'เฉพาะผู้ดูแลระบบเท่านั้น'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $users = array_map('sanitizeUser', loadUsers());
                echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'create_user': {
                $currentUser = getAuthenticatedUser();
                if (!$currentUser || $currentUser['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'message' => 'เฉพาะผู้ดูแลระบบเท่านั้น'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $username    = strtolower(trim((string)($body['username'] ?? '')));
                $displayName = trim((string)($body['displayName'] ?? ''));
                $password    = trim((string)($body['password'] ?? ''));
                $role        = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

                if ($username === '' || $displayName === '' || strlen($password) < 4) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน (รหัสผ่านอย่างน้อย 4 ตัวอักษร)'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                if (!preg_match('/^[a-z0-9._\-]+$/', $username)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'message' => 'Username ใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ a-z, ตัวเลข 0-9, จุด หรือขีด'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $users = loadUsers();
                foreach ($users as $u) {
                    if (strtolower($u['username']) === $username) {
                        http_response_code(400);
                        echo json_encode(['ok' => false, 'message' => 'มีชื่อผู้ใช้ "' . $username . '" ในระบบแล้ว'], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }

                $newUser = [
                    'id'           => 'usr_' . uniqid(),
                    'username'     => $username,
                    'passwordHash' => password_hash($password, PASSWORD_BCRYPT),
                    'displayName'  => $displayName,
                    'role'         => $role,
                    'active'       => true,
                    'createdAt'    => date('d/m/Y H:i:s'),
                    'lastLoginAt'  => null,
                ];
                $users[] = $newUser;
                saveUsers($users);

                echo json_encode([
                    'ok'      => true,
                    'message' => 'เพิ่มผู้ใช้งาน "' . $displayName . '" เรียบร้อยแล้ว',
                    'users'   => array_map('sanitizeUser', $users),
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'update_user': {
                $currentUser = getAuthenticatedUser();
                if (!$currentUser || $currentUser['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'message' => 'เฉพาะผู้ดูแลระบบเท่านั้น'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $targetId    = (string)($body['id'] ?? '');
                $displayName = isset($body['displayName']) ? trim((string)$body['displayName']) : null;
                $role        = isset($body['role']) ? ($body['role'] === 'admin' ? 'admin' : 'user') : null;
                $active      = isset($body['active']) ? (bool)$body['active'] : null;
                $newPassword = isset($body['newPassword']) ? trim((string)$body['newPassword']) : '';

                if ($targetId === $currentUser['id'] && $active === false) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'message' => 'ไม่สามารถระงับบัญชีของตนเองที่กำลังใช้งานอยู่ได้'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $users = loadUsers();
                $found = false;
                foreach ($users as &$u) {
                    if ($u['id'] === $targetId) {
                        if ($displayName !== null && $displayName !== '') $u['displayName'] = $displayName;
                        if ($role !== null) $u['role'] = $role;
                        if ($active !== null) $u['active'] = $active;
                        if ($newPassword !== '') {
                            if (strlen($newPassword) < 4) {
                                http_response_code(400);
                                echo json_encode(['ok' => false, 'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 4 ตัวอักษร'], JSON_UNESCAPED_UNICODE);
                                exit;
                            }
                            $u['passwordHash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                        }
                        $found = true;
                        break;
                    }
                }
                unset($u);

                if (!$found) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'message' => 'ไม่พบบัญชีผู้ใช้งาน'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                saveUsers($users);
                echo json_encode([
                    'ok'      => true,
                    'message' => 'อัปเดตข้อมูลผู้ใช้งานเรียบร้อยแล้ว',
                    'users'   => array_map('sanitizeUser', $users),
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'delete_user': {
                $currentUser = getAuthenticatedUser();
                if (!$currentUser || $currentUser['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'message' => 'เฉพาะผู้ดูแลระบบเท่านั้น'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $targetId = (string)($body['id'] ?? '');
                if ($targetId === $currentUser['id']) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'message' => 'ไม่สามารถลบบัญชีของตนเองที่กำลังใช้งานอยู่ได้'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $users = loadUsers();
                $filtered = array_values(array_filter($users, fn($u) => $u['id'] !== $targetId));
                saveUsers($filtered);

                echo json_encode([
                    'ok'      => true,
                    'message' => 'ลบบัญชีผู้ใช้งานเรียบร้อยแล้ว',
                    'users'   => array_map('sanitizeUser', $filtered),
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'ไม่รู้จักคำสั่ง: ' . $action], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
