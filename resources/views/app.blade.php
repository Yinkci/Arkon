<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="arkon-upload-policy" content="{{ json_encode(\App\Arkon\Media\UploadPolicy::forRuntime()) }}">
    <meta name="robots" content="noindex, nofollow">
    <title inertia>Arkon</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="h-full bg-canvas text-fg antialiased">
    @inertia
</body>
</html>
