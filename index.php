<?php
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($usuario === '' || $senha === '') {
        $erro = 'Preencha usuário e senha.';
    } else {
        // TODO: validar credenciais (banco de dados, API, etc.)
        $erro = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-box {
            background: #fff;
            padding: 32px;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 360px;
        }
        h1 {
            margin: 0 0 24px;
            font-size: 22px;
            text-align: center;
            color: #333;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            color: #555;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            width: 100%;
            padding: 11px;
            background: #1877f2;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }
        button:hover { background: #166fe0; }
        .erro {
            background: #ffe5e5;
            color: #c0392b;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 16px;
            font-size: 14px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Acessar conta</h1>

        <?php if ($erro !== ''): ?>
            <div class="erro"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <label for="usuario">Usuário</label>
            <input type="text" id="usuario" name="usuario" value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>" required>

            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required>

            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
