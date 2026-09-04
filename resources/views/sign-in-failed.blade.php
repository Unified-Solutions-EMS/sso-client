<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sign in failed</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f5f7;
            color: #1f2933;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.55;
        }
        .panel {
            max-width: 30rem;
            margin: 1.5rem;
            padding: 2rem;
            background: #ffffff;
            border: 1px solid #e0e4e8;
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.06);
        }
        h1 { margin: 0 0 0.75rem; font-size: 1.35rem; font-weight: 600; }
        p { margin: 0 0 0.75rem; }
        p:last-child { margin-bottom: 0; color: #52606d; font-size: 0.9rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #14181d; color: #e4e7eb; }
            .panel { background: #1c2126; border-color: #2b3138; box-shadow: none; }
            p:last-child { color: #9aa5b1; }
        }
    </style>
</head>
<body>
    <main class="panel">
        <h1>We could not sign you in</h1>
        <p>{{ $message ?? 'Sign in failed. Please try again.' }}</p>
        <p>Please try again in a few minutes. If it keeps happening, contact support.</p>
    </main>
</body>
</html>
