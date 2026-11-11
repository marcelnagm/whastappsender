<!doctype html>
<html lang="{{ config('languages.supported.' . app()->getLocale() . '.html', 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Sender System') }}</title>

    <link href="{!! url('assets/bootstrap/css/bootstrap.min.css') !!}" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        /* Bootstrap 5.1 ships no "text-bg-*" helpers (added in 5.2): same look, so badges never turn invisible */
        .text-bg-primary { color: #fff !important; background-color: #0d6efd !important; }
        .text-bg-secondary { color: #fff !important; background-color: #6c757d !important; }
        .text-bg-success { color: #fff !important; background-color: #198754 !important; }
        .text-bg-danger { color: #fff !important; background-color: #dc3545 !important; }
        .text-bg-warning { color: #000 !important; background-color: #ffc107 !important; }
        .text-bg-info { color: #000 !important; background-color: #0dcaf0 !important; }
        .text-bg-light { color: #000 !important; background-color: #f8f9fa !important; }
        .text-bg-dark { color: #fff !important; background-color: #212529 !important; }
    </style>

    <style>
        :root {
            --primary-color: #0d6efd;
            --bg-body: #f8fafc;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: #334155;
        }
        .main-content {
            padding-top: 2rem;
            min-height: calc(100vh - 100px);
        }
        .navbar-brand { font-weight: 700; letter-spacing: -0.5px; }
        .nav-link { font-weight: 500; }
        
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
    
    <link href="{!! url('assets/css/app.css') !!}" rel="stylesheet">
</head>
<body>
    
    {{-- Guests have no navbar, so the language selector floats in the top-right corner --}}
    @guest
      @unless (View::hasSection('own_header'))
        <div style="position: fixed; top: 1rem; right: 1rem; z-index: 1050; max-width: 200px;">
            @include('layouts.partials.language-selector', ['variant' => 'inline'])
        </div>
      @endunless
    @endguest

    {{-- Navbar is included dynamically --}}
    @include('layouts.partials.navbar')

    <main class="container main-content fade-in">
        @auth
            @include('billing.partials.banner')
        @endauth
        @yield('content')
    </main>

    <script src="https://code.jquery.com/jquery-3.6.3.min.js"></script>   
    {{-- Bundle includes responsive menu JS --}}
    <script src="{{ url('assets/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    
    @yield('js')
</body>
</html>