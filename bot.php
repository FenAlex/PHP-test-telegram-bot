<?php

require_once "vendor/autoload.php";
include 'config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$bot = new \TelegramBot\Api\BotApi($BOT_TOKEN);


$data = file_get_contents('php://input');

try{
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME}", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e;
    exit();
}

function registerUser($pdo, $userId, $userName, $userFirstName) {
    $stmt = $pdo->prepare("
        INSERT INTO users (username, first_name, id) 
        VALUES (:username, :first_name, :telegram_id) 
        ON DUPLICATE KEY UPDATE 
            username = :username, 
            first_name = :first_name
    ");
    
    $stmt->bindParam(':username', $userName);
    $stmt->bindParam(':first_name', $userFirstName);
    $stmt->bindParam(':telegram_id', $userId);
    
    return $stmt->execute();
}

function editBalance($pdo, $userId, $changeBalance) {
    $pdo->beginTransaction();
    
    try {
        // Получаем текущий баланс пользователя
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = :userId");
        $stmt->bindParam(':userId', $userId);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $currentBalance = $result['balance'];

            if ($changeBalance >= 0) {
                // Если изменение положительное, просто добавляем к балансу
                $newBalance = $currentBalance + $changeBalance;
                $updateStmt = $pdo->prepare("UPDATE users SET balance = :newBalance WHERE id = :userId");
                $updateStmt->bindParam(':newBalance', $newBalance);
                $updateStmt->bindParam(':userId', $userId);
                $updateStmt->execute();

                $pdo->commit();
                return "Ваш баланс: \${$newBalance}";
            } else {
                // Если изменение отрицательное, проверяем достаточно ли средств
                $amountToDeduct = abs($changeBalance);
                if ($currentBalance >= $amountToDeduct) {
                    $newBalance = $currentBalance - $amountToDeduct;
                    $updateStmt = $pdo->prepare("UPDATE users SET balance = :newBalance WHERE id = :userId");
                    $updateStmt->bindParam(':newBalance', $newBalance);
                    $updateStmt->bindParam(':userId', $userId);
                    $updateStmt->execute();
                    
                    $pdo->commit();
                    return "Ваш баланс: \${$newBalance}"; // Возвращаем новый баланс
                } else {

                    $pdo->rollBack();
                    return "Вам не хватает средств, текущий баланс: \${$currentBalance}";
                }
            }
        } else {
            $pdo->rollBack(); // Откатываем транзакцию
            return "Пользователь не найден";
        }
    } catch (Exception $e) {
        // В случае ошибки откатываем транзакцию
        $pdo->rollBack();
        return "Ошибка: " . $e->getMessage();
    }
}

$getData = json_decode($data, true);
if ($getData !== null) {
    $userId = $getData['message']['from']['id'];
    $userName = $getData['message']['from']['username'];
    $userFirstName = $getData['message']['from']['first_name'];

    $userText = $getData['message']['text'];

} else {

    $userId = 749566905;
    $userName = "FenrirRus";
    $userFirstName = "Алексей";

    $userText = "10";
}

if ($userText === "/start") {
    registerUser($pdo, $userId, $userName, $userFirstName);
    $bot->sendMessage($userId, "Привет, {$userFirstName}! \nНапиши мне (-)00.00 или (-)00,00 для изменения баланса.");
    exit();
}

if (preg_match('/^-?\d+([.,]\d{2})?$/', $userText)) {
    $changeBalance = floatval(str_replace(',', '.', $userText));
    $balance = editBalance($pdo, $userId, $changeBalance);
    $bot->sendMessage($userId, $balance);
    exit();
} else {
    $bot->sendMessage($userId, "Неверное значение");
    exit();
}
