<?require 'db.php'; ?>


<?if (isset($_SESSION['logged_user'])):?>
    <div>Вы авторизованы, <?=$_SESSION['logged_user']->login;?></div>
    <a href="logout.php">Выйти</a>
<?else:?>
     <h1>Форма авторизации</h1>
        <ul>
            <li><a href="/login.php">Авторизация</a></li>
            <li><a href="/signup.php">Регистрация</a></li>
        </ul>
     
     <div><a href="reset.php">Забыли пароль</div>
<?endif;?>