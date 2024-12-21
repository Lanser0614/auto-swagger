<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JSONPlaceholder API Documentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.18.3/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.18.3/swagger-ui-bundle.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.18.3/swagger-ui-standalone-preset.js"></script>
<script>
    window.onload = () => {
        const ui = SwaggerUIBundle({
            url: "{{ route('swagger.json') }}", // Replace with the path to your Swagger JSON file
            dom_id: '#swagger-ui',
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset
            ],
            layout: 'StandaloneLayout',
            requestInterceptor: (req) => {
                const token = document.getElementById('authToken')?.value;
                if (token) {
                    req.headers['Authorization'] = `Bearer ${token}`;
                    console.log('Authorization header added:', req.headers['Authorization']);
                } else {
                    console.warn('Authorization token is missing.');
                }
                return req;
            }
        });
    };
</script>

<div style="margin: 20px;">
    <label for="authToken">Bearer Token:</label>
    <input type="text" id="authToken" placeholder="Enter your token here" style="width: 300px;">
    <button onclick="window.location.reload();" style="margin-left: 10px;">Apply Token</button>
</div>
</body>
</html>
