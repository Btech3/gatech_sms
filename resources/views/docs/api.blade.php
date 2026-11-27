<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>School Management Portal API</title>
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.36.0/swagger-ui.css">
        <style>
            body {
                margin: 0;
                background: #f5f7fa;
            }

            .documentation-notice {
                max-width: 1460px;
                margin: 1rem auto 0;
                padding: 0 1.5rem;
                color: #263445;
                font: 14px/1.5 system-ui, sans-serif;
            }

            .documentation-notice strong {
                color: #9a3412;
            }
        </style>
    </head>
    <body>
        <aside class="documentation-notice" role="note">
            <strong>Authentication note:</strong>
            This API uses a browser-managed session cookie and CSRF protection. Sign in with the
            login operation; no bearer access token is issued by this backend.
        </aside>
        <div id="swagger-ui"></div>
        <script src="https://unpkg.com/swagger-ui-dist@5.36.0/swagger-ui-bundle.js"></script>
        <script>
            function readCookie(name) {
                const prefix = `${name}=`;
                const cookie = document.cookie
                    .split('; ')
                    .find((entry) => entry.startsWith(prefix));

                return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
            }

            async function loadApiDocumentation() {
                await fetch('/api/v1/auth/csrf-cookie', {
                    credentials: 'include',
                    headers: { Accept: 'application/json' },
                });

                SwaggerUIBundle({
                    url: '/docs/api/openapi.yaml',
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    withCredentials: true,
                    requestInterceptor: (request) => {
                        request.credentials = 'include';
                        request.headers = request.headers || {};

                        const method = (request.method || 'GET').toUpperCase();

                        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
                            const csrfToken = readCookie('XSRF-TOKEN');

                            if (csrfToken) {
                                request.headers['X-XSRF-TOKEN'] = csrfToken;
                            }
                        }

                        return request;
                    },
                });
            }

            loadApiDocumentation();
        </script>
    </body>
</html>
