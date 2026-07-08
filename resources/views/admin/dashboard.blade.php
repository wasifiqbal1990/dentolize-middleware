<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Whisper Audit Console</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #17202a; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 18px 28px; background: #fff; border-bottom: 1px solid #dde2e8; }
        main { padding: 24px 28px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 22px; }
        .card, table { background: #fff; border: 1px solid #dde2e8; border-radius: 8px; }
        .card { padding: 16px; }
        .card strong { display: block; font-size: 28px; }
        table { width: 100%; border-collapse: collapse; overflow: hidden; }
        th, td { text-align: left; padding: 11px 12px; border-bottom: 1px solid #edf0f3; font-size: 14px; }
        th { background: #f9fafb; }
        .badge { padding: 3px 8px; border-radius: 999px; background: #e8eef7; }
        .failed { background: #fee4e2; }
        .pending { background: #fff1c2; }
        .transferred, .fixed { background: #dcfae6; }
        button, a.button { display: inline-block; padding: 8px 10px; border-radius: 6px; border: 1px solid #b8c0cc; background: #fff; color: #17202a; text-decoration: none; }
    </style>
</head>
<body>
<header>
    <div>
        <strong>Whisper Audit Console</strong>
        <div>Local fake adapter mode</div>
    </div>
    <form method="post" action="/admin/reconcile/run">@csrf<button>Run reconciliation</button></form>
</header>
<main>
    <section class="grid">
        @foreach (['transferred', 'fixed', 'pending', 'failed', 'skipped'] as $status)
            <div class="card"><span>{{ ucfirst($status) }}</span><strong>{{ $summary['counts'][$status] }}</strong></div>
        @endforeach
        <div class="card"><span>Control total</span><strong>SAR {{ $summary['totals']['dentolize'] }}</strong></div>
    </section>

    <table>
        <thead><tr><th>Status</th><th>Entity</th><th>Dentolize</th><th>Qoyod reference</th><th>Amount</th><th>Rejected by</th><th>Attempts</th></tr></thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                <td><span class="badge {{ $item->status }}">{{ $item->status }}</span></td>
                <td>{{ $item->entity_type }}</td>
                <td><a href="/admin/items/{{ $item->id }}">{{ $item->dentolize_id }}</a></td>
                <td>{{ $item->qoyod_reference }}</td>
                <td>{{ $item->amount }}</td>
                <td>{{ $item->rejected_by }}</td>
                <td>{{ $item->attempts }}</td>
            </tr>
        @empty
            <tr><td colspan="7">No sync items yet. Run reconciliation or post a webhook.</td></tr>
        @endforelse
        </tbody>
    </table>
</main>
</body>
</html>
