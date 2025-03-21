<?php
session_start();

require 'vendor/autoload.php';
use Dotenv\Dotenv;

$env_file = __DIR__ . '/.env';
$dotenv_exists = file_exists($env_file);
if ($dotenv_exists) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$config = [
    'db_host' => $_ENV['DB_HOST'] ?? '',
    'db_name' => $_ENV['DB_NAME'] ?? '',
    'db_user' => $_ENV['DB_USER'] ?? '',
    'db_pass' => $_ENV['DB_PASS'] ?? '',
    'site_name' => $_ENV['SITE_NAME'] ?? 'Forms App',
    'site_logo' => $_ENV['SITE_LOGO'] ?? '',
    'default_lang' => $_ENV['DEFAULT_LANG'] ?? ''
];

$needs_setup = !$dotenv_exists || empty($config['db_host']) || empty($config['db_name']) || empty($config['db_user']) || empty($config['db_pass']) || empty($config['site_name']) || empty($config['default_lang']);

$languages = [];
foreach (glob("languages/*.json") as $file) {
    $lang_code = basename($file, '.json');
    $content = json_decode(file_get_contents($file), true);
    if (isset($content['language-name'])) {
        $languages[$lang_code] = $content['language-name'];
    }
}

// Nyelv kiválasztása
if (isset($_GET['lang']) && isset($languages[$_GET['lang']])) {
    $lang = $_GET['lang'];
    $_SESSION['lang'] = $lang;
} elseif (isset($_SESSION['lang']) && isset($languages[$_SESSION['lang']])) {
    $lang = $_SESSION['lang'];
} else {
    $lang = $config['default_lang'] ?: array_key_first($languages); // Alapértelmezett nyelv vagy az első létező
}
$lang_file = "languages/{$lang}.json";
if (!file_exists($lang_file)) {
    $lang = array_key_first($languages); // Ha a fájl nem létezik, az első nyelvet vesszük
    $lang_file = "languages/{$lang}.json";
}
$translations = json_decode(file_get_contents($lang_file), true);

function t($key, $params = []) {
    global $translations;
    $text = $translations[$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace("%$k", $v, $text);
    }
    return $text;
}

// URL generáló függvény
function url($params = []) {
    global $lang;
    $base = '?';
    $params['lang'] = $lang;
    return $base . http_build_query($params);
}

// Setup ellenőrzés és átirányítás
$page = isset($_GET['page']) ? htmlspecialchars($_GET['page']) : ($needs_setup ? 'setup' : 'login');
if ($needs_setup && $page !== 'setup') {
    header("Location: " . url(['page' => 'setup']));
    exit;
} elseif (!$needs_setup && $page === 'setup') {
    header("Location: " . url(['page' => 'login']));
    exit;
}

// Adatbázis kapcsolat
if (!$needs_setup) {
    try {
        $db = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8", $config['db_user'], $config['db_pass']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("Adatbázis kapcsolat hiba: " . $e->getMessage());
    }
}

// Setup folyamat
if ($page === 'setup' && isset($_POST['setup'])) {
    $db_host = filter_input(INPUT_POST, 'db_host', FILTER_SANITIZE_STRING);
    $db_name = filter_input(INPUT_POST, 'db_name', FILTER_SANITIZE_STRING);
    $db_user = filter_input(INPUT_POST, 'db_user', FILTER_SANITIZE_STRING);
    $db_pass = $_POST['db_pass'];
    $site_name = filter_input(INPUT_POST, 'site_name', FILTER_SANITIZE_STRING);
    $site_logo = filter_input(INPUT_POST, 'site_logo', FILTER_SANITIZE_URL);
    $default_lang = filter_input(INPUT_POST, 'default_lang', FILTER_SANITIZE_STRING);
    $admin_user = filter_input(INPUT_POST, 'admin_user', FILTER_SANITIZE_STRING);
    $admin_pass = $_POST['admin_pass'];

    if (empty($db_host) || empty($db_name) || empty($db_user) || empty($db_pass) || empty($site_name) || empty($default_lang) || empty($admin_user) || empty($admin_pass)) {
        $setup_error = t('setup_error');
    } else {
        // .env fájl írása
        $env_content = "DB_HOST=$db_host\nDB_NAME=$db_name\nDB_USER=$db_user\nDB_PASS=$db_pass\nSITE_NAME=\"$site_name\"\nSITE_LOGO=$site_logo\nDEFAULT_LANG=$default_lang";
        file_put_contents('.env', $env_content);

        // Adatbázis inicializálása
        $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) UNIQUE,
                password VARCHAR(255),
                is_admin TINYINT(1) DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS forms (
                id VARCHAR(255) PRIMARY KEY,
                user_id INT,
                title VARCHAR(255),
                description TEXT,
                start_time DATETIME,
                end_time DATETIME,
                is_active TINYINT(1) DEFAULT 1,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
            CREATE TABLE IF NOT EXISTS questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                form_id VARCHAR(255),
                text TEXT,
                type ENUM('short', 'long', 'radio', 'checkbox', 'dropdown'),
                is_required TINYINT(1) DEFAULT 0,
                allows_file_upload TINYINT(1) DEFAULT 0,
                position INT,
                FOREIGN KEY (form_id) REFERENCES forms(id)
            );
            CREATE TABLE IF NOT EXISTS question_options (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT,
                option_text VARCHAR(255),
                FOREIGN KEY (question_id) REFERENCES questions(id)
            );
            CREATE TABLE IF NOT EXISTS responses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                form_id VARCHAR(255),
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (form_id) REFERENCES forms(id)
            );
            CREATE TABLE IF NOT EXISTS response_answers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                response_id INT,
                question_id INT,
                answer TEXT,
                FOREIGN KEY (response_id) REFERENCES responses(id),
                FOREIGN KEY (question_id) REFERENCES questions(id)
            );
            CREATE TABLE IF NOT EXISTS uploaded_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                response_id INT,
                question_id INT,
                file_path VARCHAR(255),
                FOREIGN KEY (response_id) REFERENCES responses(id),
                FOREIGN KEY (question_id) REFERENCES questions(id)
            );
        ");

        // Admin fiók létrehozása
        $hashed_pass = password_hash($admin_pass, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 1)");
        $stmt->execute([$admin_user, $hashed_pass]);

        header("Location: " . url(['page' => 'login']));
        exit;
    }
}

// Bejelentkezés
if (!$needs_setup && isset($_POST['login'])) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = $_POST['password'];
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['is_admin'] = $user['is_admin'];
        header("Location: " . url(['page' => 'home']));
        exit;
    } else {
        $login_error = t('wrong_credentials');
    }
}

// Csak bejelentkezett felhasználók számára, kivéve kitöltés és setup
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = $is_logged_in && $_SESSION['is_admin'];
if (!$needs_setup && !$is_logged_in && !in_array($page, ['login', 'fill_form', 'thank_you'])) {
    header("Location: " . url(['page' => 'login']));
    exit;
}

// Új felhasználó hozzáadása (admin)
if (!$needs_setup && $is_admin && isset($_POST['add_user'])) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)");
    $stmt->execute([$username, $password]);
    header("Location: " . url(['page' => 'admin_users']));
    exit;
}

// Új űrlap létrehozása
if (!$needs_setup && $is_admin && isset($_POST['create_form'])) {
    $form_id = uniqid();
    $start_time = $_POST['start_time'] ?: null;
    $end_time = $_POST['end_time'] ?: null;
    $stmt = $db->prepare("INSERT INTO forms (id, user_id, title, description, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$form_id, $_SESSION['user_id'], filter_input(INPUT_POST, 'form_title', FILTER_SANITIZE_STRING), filter_input(INPUT_POST, 'form_description', FILTER_SANITIZE_STRING), $start_time, $end_time]);
    header("Location: " . url(['page' => 'edit_form', 'form_id' => $form_id]));
    exit;
}

// Űrlap törlése
if (!$needs_setup && $is_admin && isset($_GET['delete_form'])) {
    $form_id = filter_input(INPUT_GET, 'delete_form', FILTER_SANITIZE_STRING);
    $stmt = $db->prepare("SELECT file_path FROM uploaded_files WHERE response_id IN (SELECT id FROM responses WHERE form_id = ?)");
    $stmt->execute([$form_id]);
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($files as $file_path) {
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    $db->prepare("DELETE FROM uploaded_files WHERE response_id IN (SELECT id FROM responses WHERE form_id = ?)")->execute([$form_id]);
    $db->prepare("DELETE FROM response_answers WHERE response_id IN (SELECT id FROM responses WHERE form_id = ?)")->execute([$form_id]);
    $db->prepare("DELETE FROM responses WHERE form_id = ?")->execute([$form_id]);
    $db->prepare("DELETE FROM question_options WHERE question_id IN (SELECT id FROM questions WHERE form_id = ?)")->execute([$form_id]);
    $db->prepare("DELETE FROM questions WHERE form_id = ?")->execute([$form_id]);
    $db->prepare("DELETE FROM forms WHERE id = ? AND user_id = ?")->execute([$form_id, $_SESSION['user_id']]);
    header("Location: " . url(['page' => 'home']));
    exit;
}

// Űrlap állapot váltása
if (!$needs_setup && $is_admin && isset($_GET['toggle_form'])) {
    $form_id = filter_input(INPUT_GET, 'toggle_form', FILTER_SANITIZE_STRING);
    $stmt = $db->prepare("UPDATE forms SET is_active = NOT is_active WHERE id = ? AND user_id = ?");
    $stmt->execute([$form_id, $_SESSION['user_id']]);
    header("Location: " . url(['page' => 'home']));
    exit;
}

// Kérdés hozzáadása/szerkesztése
if (!$needs_setup && $is_admin && isset($_POST['add_question']) && isset($_GET['form_id'])) {
    $form_id = filter_input(INPUT_GET, 'form_id', FILTER_SANITIZE_STRING);
    $question_text = filter_input(INPUT_POST, 'question_text', FILTER_SANITIZE_STRING);
    $question_type = filter_input(INPUT_POST, 'question_type', FILTER_SANITIZE_STRING);
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $allows_file_upload = isset($_POST['allows_file_upload']) ? 1 : 0;
    $options = isset($_POST['options']) ? array_filter(array_map('trim', $_POST['options'])) : [];
    
    if (isset($_GET['edit_question'])) {
        $q_id = filter_input(INPUT_GET, 'edit_question', FILTER_SANITIZE_NUMBER_INT);
        $stmt = $db->prepare("UPDATE questions SET text = ?, type = ?, is_required = ?, allows_file_upload = ? WHERE id = ? AND form_id = ?");
        $stmt->execute([$question_text, $question_type, $is_required, $allows_file_upload, $q_id, $form_id]);
        $db->prepare("DELETE FROM question_options WHERE question_id = ?")->execute([$q_id]);
    } else {
        $stmt = $db->prepare("SELECT MAX(position) FROM questions WHERE form_id = ?");
        $stmt->execute([$form_id]);
        $position = ($stmt->fetchColumn() ?: 0) + 1;
        $stmt = $db->prepare("INSERT INTO questions (form_id, text, type, is_required, allows_file_upload, position) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$form_id, $question_text, $question_type, $is_required, $allows_file_upload, $position]);
        $q_id = $db->lastInsertId();
    }
    
    if (in_array($question_type, ['radio', 'checkbox', 'dropdown'])) {
        foreach ($options as $option) {
            if (!empty($option)) {
                $stmt = $db->prepare("INSERT INTO question_options (question_id, option_text) VALUES (?, ?)");
                $stmt->execute([$q_id, $option]);
            }
        }
    }
    header("Location: " . url(['page' => 'edit_form', 'form_id' => $form_id]));
    exit;
}

// Kérdés törlése
if (!$needs_setup && $is_admin && isset($_GET['delete_question']) && isset($_GET['form_id'])) {
    $form_id = filter_input(INPUT_GET, 'form_id', FILTER_SANITIZE_STRING);
    $q_id = filter_input(INPUT_GET, 'delete_question', FILTER_SANITIZE_NUMBER_INT);
    $db->prepare("DELETE FROM question_options WHERE question_id = ?")->execute([$q_id]);
    $db->prepare("DELETE FROM response_answers WHERE question_id = ?")->execute([$q_id]);
    $db->prepare("DELETE FROM questions WHERE id = ? AND form_id = ?")->execute([$q_id, $form_id]);
    header("Location: " . url(['page' => 'edit_form', 'form_id' => $form_id]));
    exit;
}

// Kérdés mozgatása
if (!$needs_setup && $is_admin && isset($_GET['move_question']) && isset($_GET['form_id'])) {
    $form_id = filter_input(INPUT_GET, 'form_id', FILTER_SANITIZE_STRING);
    $q_id = filter_input(INPUT_GET, 'move_question', FILTER_SANITIZE_NUMBER_INT);
    $direction = $_GET['direction'] === 'up' ? 'up' : 'down';
    $stmt = $db->prepare("SELECT position FROM questions WHERE id = ? AND form_id = ?");
    $stmt->execute([$q_id, $form_id]);
    $current_pos = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT id, position FROM questions WHERE form_id = ? AND position " . ($direction === 'up' ? '<' : '>') . " ? ORDER BY position " . ($direction === 'up' ? 'DESC' : 'ASC') . " LIMIT 1");
    $stmt->execute([$form_id, $current_pos]);
    $swap = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($swap) {
        $db->prepare("UPDATE questions SET position = ? WHERE id = ?")->execute([$swap['position'], $q_id]);
        $db->prepare("UPDATE questions SET position = ? WHERE id = ?")->execute([$current_pos, $swap['id']]);
    }
    header("Location: " . url(['page' => 'edit_form', 'form_id' => $form_id]));
    exit;
}

// Űrlap másolása
if (!$needs_setup && $is_admin && isset($_GET['copy_form'])) {
    $form_id = filter_input(INPUT_GET, 'copy_form', FILTER_SANITIZE_STRING);
    $stmt = $db->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $new_form_id = uniqid();
    $stmt = $db->prepare("INSERT INTO forms (id, user_id, title, description, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$new_form_id, $_SESSION['user_id'], $form['title'] . ' (Másolat)', $form['description'], $form['start_time'], $form['end_time']]);
    
    $stmt = $db->prepare("SELECT * FROM questions WHERE form_id = ? ORDER BY position");
    $stmt->execute([$form_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($questions as $q) {
        $stmt = $db->prepare("INSERT INTO questions (form_id, text, type, is_required, allows_file_upload, position) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$new_form_id, $q['text'], $q['type'], $q['is_required'], $q['allows_file_upload'], $q['position']]);
        $new_q_id = $db->lastInsertId();
        
        $stmt = $db->prepare("SELECT option_text FROM question_options WHERE question_id = ?");
        $stmt->execute([$q['id']]);
        $options = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($options as $opt) {
            $db->prepare("INSERT INTO question_options (question_id, option_text) VALUES (?, ?)")->execute([$new_q_id, $opt]);
        }
    }
    header("Location: " . url(['page' => 'edit_form', 'form_id' => $new_form_id]));
    exit;
}

// Válasz beküldése
if (!$needs_setup && isset($_POST['submit_response']) && isset($_GET['form_id'])) {
    $form_id = filter_input(INPUT_GET, 'form_id', FILTER_SANITIZE_STRING);
    $stmt = $db->prepare("SELECT is_active, start_time, end_time FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $now = date('Y-m-d H:i:s');
    if (!$form || !$form['is_active'] || ($form['start_time'] && $now < $form['start_time']) || ($form['end_time'] && $now > $form['end_time'])) {
        $errors[] = t('form_not_fillable');
    } else {
        $stmt = $db->prepare("INSERT INTO responses (form_id) VALUES (?)");
        $stmt->execute([$form_id]);
        $response_id = $db->lastInsertId();
        
        $stmt = $db->prepare("SELECT id, type, is_required, allows_file_upload FROM questions WHERE form_id = ?");
        $stmt->execute([$form_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $errors = [];
        $upload_dir = 'uploads/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

        foreach ($questions as $q) {
            $key = "question_" . $q['id'];
            $file_key = "file_" . $q['id'];
            $value = null;
            
            if (isset($_POST[$key])) {
                if ($q['type'] === 'checkbox' && is_array($_POST[$key])) {
                    $value = implode(', ', array_map('htmlspecialchars', $_POST[$key]));
                } else {
                    $value = htmlspecialchars($_POST[$key]);
                }
            }
            
            if ($q['allows_file_upload'] && isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$file_key];
                $allowed_types = ['image/png', 'image/jpeg', 'image/webp', 'video/mp4'];
                $max_size = 2 * 1024 * 1024;
                
                if (in_array($file['type'], $allowed_types) && $file['size'] <= $max_size) {
                    $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $file_name = uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    move_uploaded_file($file['tmp_name'], $file_path);
                    
                    $stmt = $db->prepare("INSERT INTO uploaded_files (response_id, question_id, file_path) VALUES (?, ?, ?)");
                    $stmt->execute([$response_id, $q['id'], $file_path]);
                    $value = $file_name;
                } else {
                    $errors[] = t('invalid_file');
                }
            }
            
            if ($q['is_required'] && empty($value) && (!$q['allows_file_upload'] || !isset($_FILES[$file_key]))) {
                $errors[] = t('required_fields');
                break;
            }
            if (!empty($value)) {
                $stmt = $db->prepare("INSERT INTO response_answers (response_id, question_id, answer) VALUES (?, ?, ?)");
                $stmt->execute([$response_id, $q['id'], $value]);
            }
        }
        
        if (empty($errors)) {
            header("Location: " . url(['page' => 'thank_you', 'form_id' => $form_id]));
            exit;
        }
    }
}

// Válaszok törlése
if (!$needs_setup && $is_admin && isset($_GET['delete_responses'])) {
    $form_id = filter_input(INPUT_GET, 'delete_responses', FILTER_SANITIZE_STRING);
    $stmt = $db->prepare("SELECT file_path FROM uploaded_files WHERE response_id IN (SELECT id FROM responses WHERE form_id = ?)");
    $stmt->execute([$form_id]);
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($files as $file_path) {
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    $db->prepare("DELETE FROM uploaded_files WHERE response_id IN (SELECT id FROM responses WHERE form_id = ?)")->execute([$form_id]);
    $db->prepare("DELETE FROM response_answers WHERE response_id IN (SELECT id FROM responses WHERE form_id = ?)")->execute([$form_id]);
    $db->prepare("DELETE FROM responses WHERE form_id = ?")->execute([$form_id]);
    header("Location: " . url(['page' => 'view_responses', 'form_id' => $form_id]));
    exit;
}

// CSV export
if (!$needs_setup && $is_admin && isset($_GET['export_csv'])) {
    $form_id = filter_input(INPUT_GET, 'export_csv', FILTER_SANITIZE_STRING);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="responses_' . $form_id . '.csv"');
    $output = fopen('php://output', 'w');
    
    $stmt = $db->prepare("SELECT text FROM questions WHERE form_id = ? ORDER BY position");
    $stmt->execute([$form_id]);
    $headers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    fputcsv($output, array_merge([t('submission_time')], $headers));
    
    $stmt = $db->prepare("SELECT r.submitted_at, ra.question_id, ra.answer FROM responses r LEFT JOIN response_answers ra ON r.id = ra.response_id WHERE r.form_id = ? ORDER BY r.submitted_at");
    $stmt->execute([$form_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $rows = [];
    foreach ($responses as $r) {
        if (!isset($rows[$r['submitted_at']])) $rows[$r['submitted_at']] = [$r['submitted_at']];
        $rows[$r['submitted_at']][$r['question_id']] = $r['answer'];
    }
    
    foreach ($rows as $row) {
        fputcsv($output, array_pad($row, count($headers) + 1, ''));
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['site_name']); ?></title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
    body { font-family: 'Roboto', sans-serif; margin: 0; padding: 0; background: #1a1a1a; color: #e0e0e0; line-height: 1.6; }
    .container { max-width: 960px; margin: 32px auto; background: #2a2a2a; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.5); }
    header { text-align: center; margin-bottom: 24px; }
    header img { width: 100px; height: 100px; }
    h1, h2, h3 { color: #32cd32; font-weight: 400; margin: 0 0 16px; }
    .form-box { border: 1px solid #3a3a3a; padding: 16px; margin: 16px 0; border-radius: 8px; background: #333; transition: box-shadow 0.2s; }
    .form-box:hover { box-shadow: 0 3px 6px rgba(0,0,0,0.3); }
    input, select, textarea { width: 100%; padding: 12px; margin: 8px 0; border: 1px solid #444; border-radius: 4px; box-sizing: border-box; font-size: 16px; background: #222; color: #e0e0e0; outline: none; transition: border-color 0.2s; }
    input[type="file"] { padding: 6px; }
    input:focus, select:focus, textarea:focus { border-color: #32cd32; }
    button { background: #32cd32; color: #fff; border: none; padding: 10px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; transition: background 0.2s; margin: 8px 8px 0 0; }
    button:hover { background: #28a428; }
    .delete-option { background: #d93025; margin-left: 8px; padding: 6px 12px; }
    .delete-option:hover { background: #b71c1c; }
    .error { color: #ff5555; font-size: 14px; margin: 8px 0; }
    a { color: #32cd32; text-decoration: none; font-size: 14px; margin-right: 16px; }
    a:hover { text-decoration: underline; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #444; }
    th { background: #3a3a3a; font-weight: 500; }
    .icon { vertical-align: middle; margin-right: 8px; }
    .question-option { display: flex; align-items: center; margin: 8px 0; }
    .question-option input[type="text"] { width: 80%; }
    .form-box label.checkbox-label { display: flex; align-items: center; margin: 12px 0; font-size: 16px; cursor: pointer; }
    .form-box input[type="checkbox"] { appearance: none; width: 20px; height: 20px; min-width: 20px; margin-right: 12px; background: #222; border: 2px solid #444; border-radius: 4px; position: relative; cursor: pointer; transition: background 0.2s, border-color 0.2s; }
    .form-box input[type="checkbox"]:checked { background: #32cd32; border-color: #32cd32; }
    .form-box input[type="checkbox"]:checked::after { content: '\2713'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 14px; color: #fff; }
    .form-box input[type="checkbox"]:hover:not(:checked) { border-color: #666; }
    .form-box select { appearance: none; background: #222 url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="%23e0e0e0" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z"/></svg>') no-repeat right 12px center; padding-right: 36px; border: 2px solid #444; border-radius: 6px; height: 44px; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s; }
    .form-box select:hover { border-color: #666; }
    .form-box select:focus { border-color: #32cd32; box-shadow: 0 0 4px rgba(50, 205, 50, 0.5); }
    .form-box select option { background: #2a2a2a; color: #e0e0e0; padding: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <img src="<?php echo htmlspecialchars($config['site_logo']); ?>" alt="<?php echo htmlspecialchars($config['site_name']); ?> Logo">
            <h1><?php echo htmlspecialchars($config['site_name']); ?></h1>
            <select onchange="window.location.href='?page=home&lang='+this.value">
                <?php foreach ($languages as $code => $name): ?>
                    <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $lang === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars("$name <$code>"); ?></option>
                <?php endforeach; ?>
            </select>
        </header>

        <?php if ($page === 'setup'): ?>
            <h2><?php echo t('setup_welcome'); ?></h2>
            <?php if (isset($setup_error)): ?><div class="error"><?php echo $setup_error; ?></div><?php endif; ?>
            <form method="POST">
                <input type="text" name="db_host" placeholder="<?php echo t('setup_db_host'); ?>" value="<?php echo htmlspecialchars($config['db_host']); ?>" required>
                <input type="text" name="db_name" placeholder="<?php echo t('setup_db_name'); ?>" value="<?php echo htmlspecialchars($config['db_name']); ?>" required>
                <input type="text" name="db_user" placeholder="<?php echo t('setup_db_user'); ?>" value="<?php echo htmlspecialchars($config['db_user']); ?>" required>
                <input type="password" name="db_pass" placeholder="<?php echo t('setup_db_pass'); ?>" value="<?php echo htmlspecialchars($config['db_pass']); ?>" required>
                <input type="text" name="site_name" placeholder="<?php echo t('setup_site_name'); ?>" value="<?php echo htmlspecialchars($config['site_name']); ?>" required>
                <input type="url" name="site_logo" placeholder="<?php echo t('setup_site_logo'); ?>" value="<?php echo htmlspecialchars($config['site_logo']); ?>">
                <select name="default_lang" required>
                    <option value=""><?php echo t('select_default_lang'); ?></option>
                    <?php foreach ($languages as $code => $name): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $config['default_lang'] === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars("$name <$code>"); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="admin_user" placeholder="<?php echo t('setup_admin_user'); ?>" required>
                <input type="password" name="admin_pass" placeholder="<?php echo t('setup_admin_pass'); ?>" required>
                <button type="submit" name="setup"><i class="material-icons icon">build</i><?php echo t('setup_submit'); ?></button>
            </form>

        <?php elseif ($page === 'login'): ?>
            <h2><?php echo t('login'); ?></h2>
            <?php if (isset($login_error)): ?><div class="error"><?php echo $login_error; ?></div><?php endif; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="<?php echo t('username'); ?>" required>
                <input type="password" name="password" placeholder="<?php echo t('password'); ?>" required>
                <button type="submit" name="login"><i class="material-icons icon">login</i><?php echo t('login_button'); ?></button>
            </form>

        <?php elseif ($page === 'home' && $is_logged_in): ?>
            <h2><?php echo t('welcome', ['s' => htmlspecialchars($db->query("SELECT username FROM users WHERE id = " . $_SESSION['user_id'])->fetchColumn())]); ?></h2>
            <?php if ($is_admin): ?>
                <h3><?php echo t('new_form'); ?></h3>
                <form method="POST">
                    <input type="text" name="form_title" placeholder="<?php echo t('form_title'); ?>" required>
                    <textarea name="form_description" placeholder="<?php echo t('description'); ?>" rows="3"></textarea>
                    <input type="datetime-local" name="start_time" placeholder="<?php echo t('start_time'); ?>">
                    <input type="datetime-local" name="end_time" placeholder="<?php echo t('end_time'); ?>">
                    <button type="submit" name="create_form"><i class="material-icons icon">add</i><?php echo t('create'); ?></button>
                </form>
                <h3><a href="<?php echo url(['page' => 'admin_users']); ?>"><i class="material-icons icon">people</i><?php echo t('manage_users'); ?></a></h3>
            <?php endif; ?>
            <h3><?php echo t('my_forms'); ?></h3>
            <?php
            $stmt = $db->prepare("SELECT * FROM forms WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($forms as $form): ?>
                <div class="form-box">
                    <h3><?php echo htmlspecialchars($form['title']); ?> (<?php echo $form['is_active'] ? t('active') : t('inactive'); ?>)</h3>
                    <p><?php echo htmlspecialchars($form['description']); ?></p>
                    <p><?php echo t('duration'); ?>: <?php echo ($form['start_time'] ? $form['start_time'] : '-') . ' - ' . ($form['end_time'] ? $form['end_time'] : '-'); ?></p>
                    <a href="<?php echo url(['page' => 'fill_form', 'form_id' => $form['id']]); ?>"><i class="material-icons icon">edit</i><?php echo t('fill'); ?></a>
                    <?php if ($is_admin): ?>
                        <a href="<?php echo url(['page' => 'edit_form', 'form_id' => $form['id']]); ?>"><i class="material-icons icon">settings</i><?php echo t('edit'); ?></a>
                        <a href="<?php echo url(['page' => 'view_responses', 'form_id' => $form['id']]); ?>"><i class="material-icons icon">visibility</i><?php echo t('view_responses'); ?></a>
                        <a href="<?php echo url(['page' => 'home', 'copy_form' => $form['id']]); ?>"><i class="material-icons icon">content_copy</i><?php echo t('copy'); ?></a>
                        <a href="<?php echo url(['page' => 'home', 'toggle_form' => $form['id']]); ?>"><i class="material-icons icon"><?php echo $form['is_active'] ? 'pause' : 'play_arrow'; ?></i><?php echo $form['is_active'] ? t('deactivate') : t('activate'); ?></a>
                        <a href="<?php echo url(['page' => 'home', 'delete_form' => $form['id']]); ?>" onclick="return confirm('<?php echo t('delete'); ?>?');"><i class="material-icons icon">delete</i><?php echo t('delete'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <a href="<?php echo url(['page' => 'logout']); ?>"><i class="material-icons icon">logout</i><?php echo t('logout'); ?></a>

        <?php elseif ($page === 'admin_users' && $is_admin): ?>
            <h2><?php echo t('manage_users'); ?></h2>
            <h3><?php echo t('new_user'); ?></h3>
            <form method="POST">
                <input type="text" name="username" placeholder="<?php echo t('username'); ?>" required>
                <input type="password" name="password" placeholder="<?php echo t('password'); ?>" required>
                <button type="submit" name="add_user"><i class="material-icons icon">person_add</i><?php echo t('add'); ?></button>
            </form>
            <h3><?php echo t('users'); ?></h3>
            <table>
                <tr><th><?php echo t('username'); ?></th></tr>
                <?php
                $stmt = $db->query("SELECT username FROM users WHERE is_admin = 0");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user): ?>
                    <tr><td><?php echo htmlspecialchars($user['username']); ?></td></tr>
                <?php endforeach; ?>
            </table>
            <a href="<?php echo url(['page' => 'home']); ?>"><i class="material-icons icon">arrow_back</i><?php echo t('back'); ?></a>

        <?php elseif ($page === 'edit_form' && $is_admin && isset($_GET['form_id'])): ?>
            <?php
            $form_id = filter_input(INPUT_GET, 'form_id', FILTER_SANITIZE_STRING);
            $stmt = $db->prepare("SELECT * FROM forms WHERE id = ? AND user_id = ?");
            $stmt->execute([$form_id, $_SESSION['user_id']]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$form) exit(t('no_such_form'));
            
            $edit_q_id = isset($_GET['edit_question']) ? filter_input(INPUT_GET, 'edit_question', FILTER_SANITIZE_NUMBER_INT) : null;
            $edit_question = $edit_q_id ? $db->query("SELECT * FROM questions WHERE id = $edit_q_id AND form_id = '$form_id'")->fetch(PDO::FETCH_ASSOC) : null;
            $default_type = $edit_question ? $edit_question['type'] : 'short';
            ?>
            <h2><?php echo htmlspecialchars($form['title']); ?></h2>
            <p><?php echo htmlspecialchars($form['description']); ?></p>
            <h3><?php echo $edit_question ? t('edit_question') : t('new_question'); ?></h3>
            <form method="POST" id="question-form" enctype="multipart/form-data">
                <textarea name="question_text" placeholder="<?php echo t('question_text'); ?>" required rows="2"><?php echo $edit_question ? htmlspecialchars($edit_question['text']) : ''; ?></textarea>
                <select name="question_type" id="question-type">
                    <option value="short" <?php echo $default_type === 'short' ? 'selected' : ''; ?>><?php echo t('short_answer'); ?></option>
                    <option value="long" <?php echo $default_type === 'long' ? 'selected' : ''; ?>><?php echo t('long_answer'); ?></option>
                    <option value="radio" <?php echo $default_type === 'radio' ? 'selected' : ''; ?>><?php echo t('radio'); ?></option>
                    <option value="checkbox" <?php echo $default_type === 'checkbox' ? 'selected' : ''; ?>><?php echo t('checkbox'); ?></option>
                    <option value="dropdown" <?php echo $default_type === 'dropdown' ? 'selected' : ''; ?>><?php echo t('dropdown'); ?></option>
                </select>
                <label class="checkbox-label"><input type="checkbox" name="is_required" <?php echo $edit_question && $edit_question['is_required'] ? 'checked' : ''; ?>> <?php echo t('required'); ?></label>
                <label class="checkbox-label"><input type="checkbox" name="allows_file_upload" <?php echo $edit_question && $edit_question['allows_file_upload'] ? 'checked' : ''; ?>> <?php echo t('allow_file_upload'); ?></label>
                <div id="options" <?php echo !in_array($default_type, ['radio', 'checkbox', 'dropdown']) ? 'style="display:none;"' : ''; ?>>
                    <h3><?php echo t('options'); ?></h3>
                    <div id="options-container">
                        <?php
                        $options = $edit_question ? $db->query("SELECT option_text FROM question_options WHERE question_id = $edit_q_id")->fetchAll(PDO::FETCH_COLUMN) : [];
                        if (empty($options) && in_array($default_type, ['radio', 'checkbox', 'dropdown'])) {
                            $options = ['', ''];
                        }
                        foreach ($options as $i => $opt): ?>
                            <div class="question-option">
                                <input type="text" name="options[]" value="<?php echo htmlspecialchars($opt); ?>" placeholder="<?php echo t('option') . ' ' . ($i + 1); ?>">
                                <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="material-icons icon">delete</i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addOption()" <?php echo !in_array($default_type, ['radio', 'checkbox', 'dropdown']) ? 'style="display:none;"' : ''; ?> id="add-option-btn"><i class="material-icons icon">add_circle</i><?php echo t('new_option'); ?></button>
                </div>
                <button type="submit" name="add_question"><i class="material-icons icon"><?php echo $edit_question ? 'save' : 'add'; ?></i><?php echo $edit_question ? t('save') : t('add'); ?></button>
            </form>
            <h3><?php echo t('questions'); ?></h3>
            <?php
            $stmt = $db->prepare("SELECT * FROM questions WHERE form_id = ? ORDER BY position");
            $stmt->execute([$form_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q): ?>
                <div class="form-box">
                    <p><strong><?php echo htmlspecialchars($q['text']); ?></strong> (<?php echo t($q['type']); ?><?php echo $q['is_required'] ? ', ' . t('required') : ''; ?><?php echo $q['allows_file_upload'] ? ', ' . t('allow_file_upload') : ''; ?>)</p>
                    <?php
                    $stmt = $db->prepare("SELECT option_text FROM question_options WHERE question_id = ?");
                    $stmt->execute([$q['id']]);
                    $options = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if ($options): ?>
                        <ul>
                            <?php foreach ($options as $opt): ?>
                                <li><?php echo htmlspecialchars($opt); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a href="<?php echo url(['page' => 'edit_form', 'form_id' => $form_id, 'edit_question' => $q['id']]); ?>"><i class="material-icons icon">edit</i><?php echo t('edit'); ?></a>
                    <a href="<?php echo url(['page' => 'edit_form', 'form_id' => $form_id, 'move_question' => $q['id'], 'direction' => 'up']); ?>"><i class="material-icons icon">arrow_upward</i><?php echo t('up'); ?></a>
                    <a href="<?php echo url(['page' => 'edit_form', 'form_id' => $form_id, 'move_question' => $q['id'], 'direction' => 'down']); ?>"><i class="material-icons icon">arrow_downward</i><?php echo t('down'); ?></a>
                    <a href="<?php echo url(['page' => 'edit_form', 'form_id' => $form_id, 'delete_question' => $q['id']]); ?>" onclick="return confirm('<?php echo t('delete'); ?>?');"><i class="material-icons icon">delete</i><?php echo t('delete'); ?></a>
                </div>
            <?php endforeach; ?>
            <p><?php echo t('share_link'); ?>: <a href="<?php echo url(['page' => 'fill_form', 'form_id' => $form_id]); ?>"><i class="material-icons icon">link</i><?php echo htmlspecialchars($_SERVER['HTTP_HOST']) . url(['page' => 'fill_form', 'form_id' => $form_id]); ?></a></p>
            <a href="<?php echo url(['page' => 'home']); ?>"><i class="material-icons icon">arrow_back</i><?php echo t('back'); ?></a>

        <?php elseif ($page === 'fill_form' && isset($_GET['form_id'])): ?>
            <?php
            $form_id = filter_input(INPUT_GET, 'form_id', FILTER_SANITIZE_STRING);
            $stmt = $db->prepare("SELECT * FROM forms WHERE id = ?");
            $stmt->execute([$form_id]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$form) exit(t('no_such_form'));
            $now = date('Y-m-d H:i:s');
            if (!$form['is_active'] || ($form['start_time'] && $now < $form['start_time']) || ($form['end_time'] && $now > $form['end_time'])) {
                echo "<h2>" . t('form_not_fillable') . "</h2>";
                if ($is_admin) echo '<a href="' . url(['page' => 'home']) . '"><i class="material-icons icon">arrow_back</i>' . t('back') . '</a>';
                exit;
            }
            ?>
            <h2><?php echo htmlspecialchars($form['title']); ?></h2>
            <p><?php echo htmlspecialchars($form['description']); ?></p>
            <form method="POST" enctype="multipart/form-data">
                <?php if (!empty($errors)): ?><div class="error"><?php echo implode('<br>', $errors); ?></div><?php endif; ?>
                <?php
                $stmt = $db->prepare("SELECT * FROM questions WHERE form_id = ? ORDER BY position");
                $stmt->execute([$form_id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q): ?>
                    <div class="form-box">
                        <label><?php echo htmlspecialchars($q['text']); ?><?php echo $q['is_required'] ? ' *' : ''; ?></label><br>
                        <?php
                        $stmt = $db->prepare("SELECT option_text FROM question_options WHERE question_id = ?");
                        $stmt->execute([$q['id']]);
                        $options = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        if ($q['type'] === 'short'): ?>
                            <input type="text" name="question_<?php echo $q['id']; ?>" <?php echo $q['is_required'] ? 'required' : ''; ?>>
                        <?php elseif ($q['type'] === 'long'): ?>
                            <textarea name="question_<?php echo $q['id']; ?>" <?php echo $q['is_required'] ? 'required' : ''; ?> rows="4"></textarea>
                        <?php elseif ($q['type'] === 'radio'): ?>
                            <?php foreach ($options as $opt): ?>
                                <label><input type="radio" name="question_<?php echo $q['id']; ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $q['is_required'] ? 'required' : ''; ?>> <?php echo htmlspecialchars($opt); ?></label><br>
                            <?php endforeach; ?>
                        <?php elseif ($q['type'] === 'checkbox'): ?>
                            <?php foreach ($options as $opt): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="question_<?php echo $q['id']; ?>[]" value="<?php echo htmlspecialchars($opt); ?>"> 
                                    <?php echo htmlspecialchars($opt); ?>
                                </label>
                            <?php endforeach; ?>
                        <?php elseif ($q['type'] === 'dropdown'): ?>
                            <select name="question_<?php echo $q['id']; ?>" <?php echo $q['is_required'] ? 'required' : ''; ?>>
                                <option value=""><?php echo t('select'); ?></option>
                                <?php foreach ($options as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <?php if ($q['allows_file_upload']): ?>
                            <input type="file" name="file_<?php echo $q['id']; ?>" accept=".png,.jpg,.webp,.mp4" <?php echo $q['is_required'] ? 'required' : ''; ?>>
                            <small><?php echo t('file_info'); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" name="submit_response"><i class="material-icons icon">send</i><?php echo t('submit'); ?></button>
            </form>
            <?php if ($is_admin): ?><a href="<?php echo url(['page' => 'home']); ?>"><i class="material-icons icon">arrow_back</i><?php echo t('back'); ?></a><?php endif; ?>

        <?php elseif ($page === 'view_responses' && $is_admin && isset($_GET['form_id'])): ?>
            <?php
            $form_id = filter_input(INPUT_GET, 'form_id', FILTER_SANITIZE_STRING);
            $stmt = $db->prepare("SELECT * FROM forms WHERE id = ? AND user_id = ?");
            $stmt->execute([$form_id, $_SESSION['user_id']]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$form) exit(t('no_such_form'));
            ?>
            <h2><?php echo htmlspecialchars($form['title']); ?> - <?php echo t('view_responses'); ?></h2>
            <?php
            $stmt = $db->prepare("SELECT r.id, r.submitted_at, ra.question_id, ra.answer FROM responses r LEFT JOIN response_answers ra ON r.id = ra.response_id WHERE r.form_id = ? ORDER BY r.submitted_at");
            $stmt->execute([$form_id]);
            $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($responses): ?>
                <table>
                    <tr>
                        <th><?php echo t('submission_time'); ?></th>
                        <?php
                        $stmt = $db->prepare("SELECT id, text, allows_file_upload FROM questions WHERE form_id = ? ORDER BY position");
                        $stmt->execute([$form_id]);
                        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($questions as $q): ?>
                            <th><?php echo htmlspecialchars($q['text']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    <?php
                    $rows = [];
                    foreach ($responses as $r) {
                        $response_id = $r['id'];
                        if (!isset($rows[$response_id])) {
                            $rows[$response_id] = ['submitted_at' => $r['submitted_at'], 'response_id' => $response_id];
                        }
                        if ($r['question_id']) {
                            $rows[$response_id][$r['question_id']] = $r['answer'];
                        }
                    }
                    foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['submitted_at']); ?></td>
                            <?php foreach ($questions as $q): ?>
                                <td>
                                    <?php 
                                    if (isset($row[$q['id']])) {
                                        if ($q['allows_file_upload']) {
                                            $stmt = $db->prepare("SELECT file_path FROM uploaded_files WHERE response_id = ? AND question_id = ?");
                                            $stmt->execute([$row['response_id'], $q['id']]);
                                            $file_path = $stmt->fetchColumn();
                                            if ($file_path) {
                                                echo '<a href="' . htmlspecialchars($file_path) . '" target="_blank">' . htmlspecialchars(basename($file_path)) . '</a>';
                                            } else {
                                                echo htmlspecialchars($row[$q['id']]);
                                            }
                                        } else {
                                            echo htmlspecialchars($row[$q['id']]);
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <a href="<?php echo url(['page' => 'view_responses', 'form_id' => $form_id, 'export_csv' => $form_id]); ?>"><i class="material-icons icon">download</i><?php echo t('export_csv'); ?></a>
                <a href="<?php echo url(['page' => 'view_responses', 'form_id' => $form_id, 'delete_responses' => $form_id]); ?>" onclick="return confirm('<?php echo t('delete_responses'); ?>?');"><i class="material-icons icon">delete</i><?php echo t('delete_responses'); ?></a>
            <?php else: ?>
                <p><?php echo t('no_responses'); ?></p>
            <?php endif; ?>
            <a href="<?php echo url(['page' => 'home']); ?>"><i class="material-icons icon">arrow_back</i><?php echo t('back'); ?></a>

        <?php elseif ($page === 'thank_you' && isset($_GET['form_id'])): ?>
            <h2><?php echo t('thank_you'); ?></h2>
            <p><?php echo t('response_recorded'); ?></p>
            <?php if ($is_admin): ?><a href="<?php echo url(['page' => 'home']); ?>"><i class="material-icons icon">arrow_back</i><?php echo t('back'); ?></a><?php endif; ?>

        <?php elseif ($page === 'logout' && $is_logged_in): ?>
            <?php session_destroy(); header("Location: " . url(['page' => 'login'])); exit; ?>

        <?php else: ?>
            <h2><?php echo t('page_not_found'); ?></h2>
            <?php if ($is_admin): ?><a href="<?php echo url(['page' => 'home']); ?>"><i class="material-icons icon">arrow_back</i><?php echo t('back'); ?></a><?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        function addOption() {
            const container = document.getElementById('options-container');
            const optionCount = container.getElementsByTagName('div').length + 1;
            const div = document.createElement('div');
            div.className = 'question-option';
            div.innerHTML = `
                <input type="text" name="options[]" placeholder="<?php echo t('option'); ?> ${optionCount}">
                <button type="button" class="delete-option" onclick="deleteOption(this)"><i class="material-icons icon">delete</i></button>
            `;
            container.appendChild(div);
        }

        function deleteOption(button) {
            button.parentElement.remove();
        }

        document.getElementById('question-type')?.addEventListener('change', function() {
            const optionsDiv = document.getElementById('options');
            const addOptionBtn = document.getElementById('add-option-btn');
            const isOptionType = ['radio', 'checkbox', 'dropdown'].includes(this.value);
            optionsDiv.style.display = isOptionType ? 'block' : 'none';
            addOptionBtn.style.display = isOptionType ? 'inline-block' : 'none';
            if (isOptionType && !document.querySelector('#options-container .question-option')) {
                addOption();
                addOption();
            }
        });
    </script>
</body>
</html>