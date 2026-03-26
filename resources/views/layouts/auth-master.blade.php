<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="Mark Otto, Jacob Thornton, and Bootstrap contributors">
    <meta name="generator" content="Hugo 0.87.0">
    <title>Signin Template · Bootstrap v5.1</title>

    <!-- Bootstrap core CSS -->
    <link href="{!! url('assets/bootstrap/css/bootstrap.min.css') !!}" rel="stylesheet">
    <link href="{!! url('assets/css/signin.css') !!}" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #121212;
            --card-dark: #1e1e1e;
            --accent-color: #ffc107; /* Amarelo Radar */
        }

        body {
            background-color: var(--bg-dark);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: radial-gradient(circle at top right, #1e1e1e, #121212);
            margin: 0;
        }

        .auth-card {
            background: var(--card-dark);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
        }

        .auth-logo {
            width: 70px;
            height: 70px;
            margin-bottom: 1rem;
            object-fit: contain;
        }

        /* CONFIGURAÇÃO DOS INPUTS BRANCOS */
        .form-control {
            background-color: #ffffff !important;
            border: 1px solid #ced4da !important;
            color: #212529 !important; /* Texto escuro */
            padding: 0.75rem 1rem;
            border-radius: 8px;
            height: auto;
        }

        .form-control:focus {
            background-color: #ffffff !important;
            border-color: var(--accent-color) !important;
            color: #212529 !important;
            box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.25);
        }

        /* Ajuste para Floating Labels sobre fundo branco */
        .form-floating > label {
            color: #6c757d !important;
            left: 5px;
        }

        .btn-primary {
            background-color: var(--accent-color);
            border: none;
            color: #000;
            font-weight: 700;
            padding: 0.8rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-primary:hover {
            background-color: #e5ac00;
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
        }

        .auth-links {
            color: #adb5bd;
            text-decoration: none;
            font-size: 0.85rem;
            transition: 0.2s;
        }

        .auth-links:hover {
            color: var(--accent-color);
        }

        .invalid-feedback {
            text-align: left;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
    </style>

    
    <!-- Custom styles for this template -->
    <link href="signin.css" rel="stylesheet">
</head>
<body class="text-center">
    
    <main class="form-signin">

        @yield('content')
        
    </main>
    

</body>
</html>
