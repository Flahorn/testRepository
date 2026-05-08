<?require 'db.php'; ?>


<?if (isset($_SESSION['logged_user'])):?>
    <div>Вы авторизованы, <?=$_SESSION['logged_user']->login;?></div>
    <div><a href="/vigbo.php?url=https%3A%2F%2Fvaleriashukh.gallery.photo%2F">Поиск Vigbo-галерей</a></div>
    <a href="logout.php">Выйти</a>
<?else:?>
     <h1>Форма авторизации</h1>
        <ul>
            <li><a href="/login.php">Авторизация</a></li>
            <li><a href="/signup.php">Регистрация</a></li>
            <li><a href="/vigbo.php?url=https%3A%2F%2Fvaleriashukh.gallery.photo%2F">Поиск Vigbo-галерей</a></li>
        </ul>
     
     <div><a href="reset.php">Забыли пароль</div>
<?endif;?>