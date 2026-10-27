<!DOCTYPE html>
<html lang="en" class="{{ $theme === 'dark' ? 'dark-mode' : '' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.1/swagger-ui.css">
    <style>
        body { margin: 0; }
    </style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.1/swagger-ui-bundle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.1/swagger-ui-standalone-preset.js"></script>
<script>
    window.onload = () => {
        SwaggerUIBundle({
            url: {{ Illuminate\Support\Js::from(route('swagger.json')) }},
            dom_id: '#swagger-ui',
            presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
            layout: 'StandaloneLayout',
            validatorUrl: null,
        });
    };
</script>
</body>
</html>
