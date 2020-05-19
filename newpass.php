<?php

require 'db.php';
$data = $_POST;

if(isset($data['on_change'])) {
    $errors = [];
    

    
    if ($data['password'] != '') {

    if($_SESSION['reset_user']) {
        $user = R::findOne('users', "login = ?", array($_SESSION['reset_user']['login']));
        
        if ($user) {
            $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
            R::store($user);
            header('Location: /');
        }
    }
    }else{
        $errors[] = 'Введите пароль';
    };
    if (!empty($errors)) {
        echo '<div>' . array_shift($errors) . '</div>';
    }
}
?>

<form action="newpass.php" method="POST">
    
    <p>
        <p>Введите новый пароль</p>
        <input type="password" name="password">
    </p>
    
    <button type="submit" name="on_change">Принять</button>
</form>