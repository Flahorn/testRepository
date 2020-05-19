<?php

require 'db.php';

$data = $_POST;
    if (isset($data['do_login'])) {
        $errors = [];
        
        if ($data['login'] == '') {
            $errors[] = 'Введите логин';
        }
        
        $user = R::findOne('users', "login = ?", array($data['login']));
            if ($user) {
                if (password_verify($data['password'], $user->password)) {
                    $_SESSION['logged_user'] = $user;
                    echo '<div>Вы авторизованы, можете перейти на '
                    . '<a href="/">главную</a></div>страницу';
                    
                } else {
                    $errors[] = 'Введенный пароль не верный';
                }
            } else {
                $errors[] = 'Пользователь с таким именем не существует';
            }
            
                    if ($data['login'] == '') {
            $errors[] = 'Введите логин';
        }
        
        }
        

        
        if (!empty($errors)) {
            echo '<div>' . array_shift($errors) . '</div><hr>';
        }
    
?>

<form action="login.php" method="POST">
    
    <p>
        <p>Ваш логин:</p>
        <input type="text" name="login" value="<?$data['login']?>">
    </p>
    
      <p>
        <p>Ваш пароль:</p>
        <input type="password" name="password" value="<?$data['password']?>">
    </p>
    <button type="submit" name="do_login">Авторизоваться</button>
</form>

<div><a href="/">На главную</a></div>