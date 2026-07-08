<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Whisper Item {{ $item->id }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #17202a; }
        main { max-width: 980px; margin: 0 auto; padding: 28px; }
        section { background: #fff; border: 1px solid #dde2e8; border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        pre { white-space: pre-wrap; background: #f9fafb; border: 1px solid #edf0f3; padding: 12px; border-radius: 6px; }
        a { color: #1769aa; }
    </style>
</head>
<body>
<main>
    <p><a href="/admin">Back to console</a></p>
    <section>
        <h1>{{ $item->qoyod_reference }}</h1>
        <p>Status: <strong>{{ $item->status }}</strong></p>
        <p>Entity: {{ $item->entity_type }} / Dentolize ID: {{ $item->dentolize_id }}</p>
        <p>Rejected by: {{ $item->rejected_by ?: 'none' }}</p>
        <p>{{ $item->last_error }}</p>
    </section>
    @foreach ($audit as $entry)
        <section>
            <h2>{{ $entry->action }} - {{ $entry->created_at }}</h2>
            <pre>{{ json_encode(['request' => $entry->request_body, 'response' => $entry->response_body], JSON_PRETTY_PRINT) }}</pre>
        </section>
    @endforeach
</main>
</body>
</html>
