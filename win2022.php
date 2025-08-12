<?php
// Опасная биллинг-система с множеством уязвимостей (УЧЕБНЫЙ ПРИМЕР)

session_start();

// "Конфигурация" с жестко закодированными учетными данными
$config = [
    'db_host' => 'localhost',
    'db_user' => 'root',
    'db_pass' => '',
    'db_name' => 'insecure_billing',
    'admin_user' => 'admin',
    'admin_pass' => 'supersecret', // Пароль в открытом виде
    'encryption_key' => 'weakkey123' // Слабый ключ для "шифрования"
];

// Подключение к БД с сообщением об ошибке, раскрывающим информацию
$conn = @new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error . " on line " . __LINE__);
}

// Создание таблиц, если их нет (уязвимость к SQL-инъекциям через параметры)
function setupDatabase() {
    global $conn;
    
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        password VARCHAR(50) NOT NULL, -- Пароли хранятся в открытом виде
        balance DECIMAL(10,2) DEFAULT 0.00,
        is_admin TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    
    // Добавляем тестового админа, если его нет
    $result = $conn->query("SELECT * FROM users WHERE username = 'admin'");
    if ($result->num_rows == 0) {
        $conn->query("INSERT INTO users (username, password, is_admin) VALUES ('admin', 'supersecret', 1)");
    }
}

setupDatabase();

// "Шифрование" данных (очень слабое)
function weakEncrypt($data) {
    global $config;
    return base64_encode($data . $config['encryption_key']);
}

function weakDecrypt($data) {
    global $config;
    $decoded = base64_decode($data);
    return str_replace($config['encryption_key'], '', $decoded);
}

// Обработка действий без проверки CSRF токенов
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'register':
        handleRegister();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'add_funds':
        addFunds();
        break;
    case 'transfer':
        transferFunds();
        break;
    case 'edit_profile':
        editProfile();
        break;
    case 'admin_delete_user':
        adminDeleteUser();
        break;
    case 'download_backup':
        downloadBackup();
        break;
    default:
        showMainPage();
}

// Функции системы с уязвимостями

function handleLogin() {
    global $conn, $config;
    
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Уязвимость к SQL-инъекции
    $sql = "SELECT * FROM users WHERE username = '$username' AND password = '$password'";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user'] = $user;
        $_SESSION['is_admin'] = ($user['username'] == $config['admin_user'] && $password == $config['admin_pass']);
        
        // Установка куки с данными пользователя (опасно!)
        setcookie('user_data', weakEncrypt(json_encode($user)), time()+3600*24*30, '/');
        
        header("Location: ?");
    } else {
        echo "<script>alert('Invalid login!'); window.location='?';</script>";
    }
}

function handleRegister() {
    global $conn;
    
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Нет проверки на существующего пользователя
    $sql = "INSERT INTO users (username, password) VALUES ('$username', '$password')";
    if ($conn->query($sql)) {
        echo "<script>alert('Registration successful!'); window.location='?';</script>";
    } else {
        echo "<script>alert('Error: " . addslashes($conn->error) . "'); window.location='?';</script>";
    }
}

function addFunds() {
    global $conn;
    
    if (empty($_SESSION['user'])) {
        die("Not logged in!");
    }
    
    $user_id = $_SESSION['user']['id'];
    $amount = $_POST['amount'];
    
    // Уязвимость к race condition и нет проверки суммы
    $conn->query("UPDATE users SET balance = balance + $amount WHERE id = $user_id");
    $conn->query("INSERT INTO transactions (user_id, amount, description) VALUES ($user_id, $amount, 'Manual funds addition')");
    
    header("Location: ?");
}

function transferFunds() {
    global $conn;
    
    if (empty($_SESSION['user'])) {
        die("Not logged in!");
    }
    
    $from_user = $_SESSION['user']['id'];
    $to_user = $_POST['to_user'];
    $amount = $_POST['amount'];
    
    // Уязвимость к SQL-инъекции и race condition
    $conn->query("START TRANSACTION");
    $conn->query("UPDATE users SET balance = balance - $amount WHERE id = $from_user AND balance >= $amount");
    $conn->query("UPDATE users SET balance = balance + $amount WHERE id = $to_user");
    $conn->query("INSERT INTO transactions (user_id, amount, description) VALUES ($from_user, -$amount, 'Transfer to user $to_user')");
    $conn->query("INSERT INTO transactions (user_id, amount, description) VALUES ($to_user, $amount, 'Transfer from user $from_user')");
    $conn->query("COMMIT");
    
    header("Location: ?");
}

function editProfile() {
    global $conn;
    
    if (empty($_SESSION['user'])) {
        die("Not logged in!");
    }
    
    $user_id = $_SESSION['user']['id'];
    $new_username = $_POST['username'];
    $new_password = $_POST['password'];
    
    // Уязвимость к SQL-инъекции
    $sql = "UPDATE users SET username = '$new_username', password = '$new_password' WHERE id = $user_id";
    $conn->query($sql);
    
    // Обновляем сессию без проверки
    $_SESSION['user']['username'] = $new_username;
    
    header("Location: ?");
}

function adminDeleteUser() {
    global $conn;
    
    // Очень слабая проверка прав администратора
    if (empty($_SESSION['is_admin'])) {
        die("Access denied!");
    }
    
    $user_id = $_GET['user_id'];
    
    // Уязвимость к SQL-инъекции и каскадному удалению
    $conn->query("DELETE FROM users WHERE id = $user_id");
    
    header("Location: ?");
}

function downloadBackup() {
    global $conn;
    
    if (empty($_SESSION['is_admin'])) {
        die("Access denied!");
    }
    
    // Уязвимость к LFI (Local File Inclusion)
    $file = $_GET['file'] ?? 'backup.sql';
    
    // Опасное выполнение команд системы
    system("mysqldump -u root -p'' insecure_billing > /tmp/$file");
    
    // Отправка файла без проверки
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($file).'"');
    readfile("/tmp/$file");
    exit;
}

function showMainPage() {
    global $conn;
    
    $logged_in = !empty($_SESSION['user']);
    $is_admin = !empty($_SESSION['is_admin']);
    $user = $_SESSION['user'] ?? null;
    
    echo "<!DOCTYPE html><html><head><title>Insecure Billing System</title>
          <style>.error {color: red;}</style></head><body>";
    
    if ($logged_in) {
        // XSS через имя пользователя
        echo "<h1>Welcome, " . $user['username'] . "!</h1>";
        echo "<a href='?action=logout'>Logout</a> | ";
        echo "<a href='?action=edit_profile'>Edit Profile</a><br><br>";
        
        // Показываем баланс
        $balance = $conn->query("SELECT balance FROM users WHERE id = " . $user['id'])->fetch_assoc()['balance'];
        echo "Your balance: $" . $balance . "<br><br>";
        
        // Форма добавления средств
        echo "<h3>Add Funds</h3>";
        echo "<form action='?action=add_funds' method='post'>";
        echo "Amount: <input type='text' name='amount'><br>";
        echo "<input type='submit' value='Add'>";
        echo "</form>";
        
        // Форма перевода средств
        echo "<h3>Transfer Funds</h3>";
        echo "<form action='?action=transfer' method='post'>";
        echo "To User ID: <input type='text' name='to_user'><br>";
        echo "Amount: <input type='text' name='amount'><br>";
        echo "<input type='submit' value='Transfer'>";
        echo "</form>";
        
        // История транзакций (SQL-инъекция через сортировку)
        $sort = $_GET['sort'] ?? 'id';
        echo "<h3>Transaction History</h3>";
        $result = $conn->query("SELECT * FROM transactions WHERE user_id = " . $user['id'] . " ORDER BY $sort");
        echo "<table border='1'><tr><th><a href='?sort=id'>ID</a></th><th>Amount</th><th><a href='?sort=created_at'>Date</a></th><th>Description</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>" . $row['id'] . "</td><td>" . $row['amount'] . "</td><td>" . $row['created_at'] . "</td><td>" . $row['description'] . "</td></tr>";
        }
        echo "</table>";
        
        // Админ-панель с уязвимостью к горизонтальному повышению привилегий
        if ($is_admin) {
            echo "<h3>Admin Panel</h3>";
            
            // Список всех пользователей
            $result = $conn->query("SELECT * FROM users");
            echo "<table border='1'><tr><th>ID</th><th>Username</th><th>Balance</th><th>Actions</th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr><td>" . $row['id'] . "</td><td>" . $row['username'] . "</td><td>" . $row['balance'] . "</td>
                      <td><a href='?action=admin_delete_user&user_id=" . $row['id'] . "' onclick='return confirm(\"Are you sure?\")'>Delete</a></td></tr>";
            }
            echo "</table>";
            
            // Опасная функция создания резервной копии
            echo "<h4>Database Backup</h4>";
            echo "<a href='?action=download_backup'>Download Backup</a> | ";
            echo "<a href='?action=download_backup&file=../../../../etc/passwd'>Download System File</a> (уязвимость LFI)";
        }
        
    } else {
        // Форма входа с уязвимостью к CSRF
        echo "<h1>Login</h1>";
        echo "<form action='?action=login' method='post'>";
        echo "Username: <input type='text' name='username'><br>";
        echo "Password: <input type='password' name='password'><br>";
        echo "<input type='submit' value='Login'>";
        echo "</form>";
        
        // Форма регистрации
        echo "<h1>Register</h1>";
        echo "<form action='?action=register' method='post'>";
        echo "Username: <input type='text' name='username'><br>";
        echo "Password: <input type='password' name='password'><br>";
        echo "<input type='submit' value='Register'>";
        echo "</form>";
        
        // Уязвимость к reflected XSS через параметр error
        if (isset($_GET['error'])) {
            echo "<div class='error'>Error: " . $_GET['error'] . "</div>";
        }
    }
    
    // Отладочная информация (опасно для production)
    echo "<hr><pre>SESSION: " . print_r($_SESSION, true) . "</pre>";
    echo "<pre>COOKIE: " . print_r($_COOKIE, true) . "</pre>";
    if ($logged_in && isset($_COOKIE['user_data'])) {
        echo "<pre>Decrypted user data: " . print_r(json_decode(weakDecrypt($_COOKIE['user_data']), true), true) . "</pre>";
    }
    
    echo "</body></html>";
}

// Закрытие соединения
$conn->close();
?>
