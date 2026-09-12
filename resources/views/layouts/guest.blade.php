<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Sosmed') }}</title>

    <script>
        (function () {
            try {
                if (localStorage.getItem('simsosmed.theme') === 'light') {
                    document.documentElement.classList.add('light');
                }
                var accent = localStorage.getItem('simsosmed.accent');
                if (accent) {
                    document.documentElement.style.setProperty('--accent', accent);
                }
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    @vite(['resources/css/app.css', 'resources/css/admin.css', 'resources/js/app.js'])
</head>
<body>
    <div class="auth-wrap">
        {{ $slot }}
    </div>
</body>
</html>
