<?php

require 'db.php';


$data = $_POST;

if (isset($data['on_signup'])) {
    
    $errors = [];

    if (trim($data['login']) == '') {
        $errors[] = 'Введите логин';
    }
    
    if (trim($data['email']) == '') {
        $errors[] = 'Введите Email';
    }
    
    if ($data['password'] == '') {
        $errors[] = 'Введите пароль';
    }
    
    if ($data['password_2'] != $data['password']) {
        $errors[] = 'Повторный пароль введен неверно';
    }
    
    if (R::count('users', "login = ?", array($data['login'])) > 0) {
        $errors[] = 'Данный логин уже используется';
    }
    
    if (R::count('users', "email = ?", array($data['email'])) > 0) {
        $errors[] = 'Данный Email уже используется';
    }
    
    if(empty($errors)) {
        $user = R::dispense('users');
        $user->login = $data["login"];
        $user->email = $data['email'];
        $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        R::store($user);
        echo 'Вы успешно зарегистрированы';
    } else {
        echo '<div>' . array_shift($errors) . '</div><hr>';
    }
}
?>

<form action="signup.php" method="POST">
    <p>
        <p>Введите логин</p>
        <input type="text" name="login" value="<?=$data['login']?>">
    </p>
    <p>
        <p>Введите Email</p>
        <input type="email" name="email" value="<?=$data['email']?>">
    </p>
    
    <p>
        <p>Введите Пароль</p>
        <input type="password" name="password" value="">
    </p>
    
    <p>
        <p>Введите Пароль повторно</p>
        <input type="password" name="password_2" value="">
    </p>
    
    <button type="submit" name="on_signup">Зарегистрироваться</button>
</form>

<a href="/">На главную</a>