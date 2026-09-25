<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:'Open Sans',Arial,sans-serif;color:#666666;background:#ffffff;line-height:1.7;padding:24px">
<div style="max-width:600px;margin:auto;border:1px solid #D6D6D6;border-radius:9px;overflow:hidden">
<h1 style="background:#7A291E;color:#ffffff;padding:24px;margin:0;font-family:Montserrat,Arial,sans-serif;font-size:26px">Salon de la Danse d’Angers</h1>
<div style="padding:24px"><h2 style="color:#333333">{{ $contenu['titre'] }}</h2><p>Bonjour,</p>
<p>{{ $contenu['message'] }}</p>
@foreach ($contenu['creneaux'] ?? [] as $creneau)
<p style="padding:12px;background:#f5f5f5;border-radius:9px"><strong style="color:#7A291E">{{ $creneau['mission'] }}</strong><br>{{ $creneau['jour'] }} · {{ $creneau['debut'] }}–{{ $creneau['fin'] }}<br>{{ $creneau['validation'] }}</p>
@endforeach
<p>À bientôt,<br>L’équipe du Salon de la Danse</p></div></div></body></html>
