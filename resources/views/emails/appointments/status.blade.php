<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Status do agendamento atualizado</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f6f1e8; margin: 0; padding: 24px; color: #0b1f24; }
    .card { background: #ffffff; border-radius: 16px; padding: 24px; border: 1px solid rgba(11,31,36,0.08); }
    .title { font-size: 18px; margin: 0 0 12px; }
    .muted { color: rgba(11,31,36,0.6); font-size: 13px; }
    ul { padding-left: 18px; margin: 12px 0; }
    li { margin-bottom: 6px; }
  </style>
</head>
<body>
  <div class="card">
    <h1 class="title">Status do agendamento atualizado</h1>
    <p>Olá, {{ $clientName }}.</p>
    <p>O status do seu agendamento foi atualizado para <strong>{{ $statusLabel }}</strong>.</p>
    <ul>
      <li><strong>Data:</strong> {{ $date }}</li>
      <li><strong>Horário:</strong> {{ $timeRange }}</li>
      <li><strong>Barbeiro:</strong> {{ $staffName }}</li>
      <li><strong>Serviços:</strong> {{ implode(', ', $services) }}</li>
      <li><strong>Total:</strong> R$ {{ $total }}</li>
    </ul>
    @if (!empty($cancelReason))
      <p><strong>Motivo:</strong> {{ $cancelReason }}</p>
    @endif
    <p class="muted">Qualquer dúvida, fale conosco.</p>
  </div>
</body>
</html>
