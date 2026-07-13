<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Maintenance | {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 2rem;
            color: #172033;
            background:
                radial-gradient(circle at top left, rgba(30, 64, 175, .13), transparent 36rem),
                linear-gradient(145deg, #f8fafc, #eef2ff);
        }

        main {
            width: min(100%, 40rem);
            padding: clamp(2rem, 7vw, 4rem);
            text-align: center;
            background: rgba(255, 255, 255, .92);
            border: 1px solid rgba(148, 163, 184, .3);
            border-radius: 1.5rem;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .12);
        }

        .mark {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1.5rem;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -.04em;
            border-radius: 1.1rem;
            background: #1e40af;
            box-shadow: 0 12px 26px rgba(30, 64, 175, .28);
        }

        .eyebrow {
            margin: 0 0 .75rem;
            color: #1e40af;
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-size: clamp(2rem, 6vw, 3.25rem);
            line-height: 1.05;
            letter-spacing: -.045em;
        }

        .message {
            max-width: 31rem;
            margin: 1.25rem auto 0;
            color: #64748b;
            font-size: 1.05rem;
            line-height: 1.7;
        }

        .status {
            margin: 2rem 0 0;
            display: inline-flex;
            align-items: center;
            gap: .6rem;
            padding: .65rem 1rem;
            color: #334155;
            font-size: .875rem;
            font-weight: 650;
            background: #f1f5f9;
            border-radius: 999px;
        }

        .status::before {
            width: .55rem;
            height: .55rem;
            content: "";
            border-radius: 50%;
            background: #f59e0b;
            box-shadow: 0 0 0 .25rem rgba(245, 158, 11, .16);
        }

        footer {
            margin-top: 2.25rem;
            color: #94a3b8;
            font-size: .8rem;
        }
    </style>
</head>
<body>
    <main>
        <div class="mark" aria-hidden="true">BS</div>
        <p class="eyebrow">Scheduled maintenance</p>
        <h1>We’ll be back shortly.</h1>
        <p class="message">
            {{ config('app.name') }} is temporarily unavailable while we make improvements.
            Please try again in a few minutes.
        </p>
        <p class="status">Maintenance in progress</p>
        <footer>Error 503 &middot; Service temporarily unavailable</footer>
    </main>
</body>
</html>
