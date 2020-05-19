<?php
require 'db.php';

$data = $_POST;
if (isset($data['on_reset'])) {
    $errors = [];
    
    $user = R::findOne('users', "login =?", array($data["login"]));

    if ($user) {
        $_SESSION['reset_user'] = $user;
        header('Location: newpass.php');
    }else{
        echo 'Пользователя с данным логином не существует';
    }
    
    if (!empty($errors)) {
        echo '<div>' . array_shift($errors) . '</div><hr>';
    }
}
?>


<form action="reset.php" method="POST">
    
    <p>
        <p>Введите ваш логин</p>
        <input type="text" name="login">
    </p>
    
    <button type="submit" name="on_reset">Сбросить пароль</button>
</form>