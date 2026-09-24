<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#666666;line-height:1.7;padding:24px">
<div style="max-width:600px;margin:auto;border:1px solid #D6D6D6;border-radius:9px;overflow:hidden">
<h1 style="margin:0;background:#7A291E;color:#ffffff;padding:24px;font-size:26px">Salon de la Danse d’Angers</h1>
<div style="padding:24px"><p>Bonjour,</p><p>Votre demande pour <strong>{{ $mission }}</strong>, {{ $jour }} {{ $debut }}–{{ $fin }}, a été {{ $decision === 'acceptee' ? 'acceptée' : 'refusée' }}.</p>
@if ($decision === 'refusee')<p>Vous pouvez choisir un autre créneau puis valider à nouveau votre planning.</p>@endif
<p>L’équipe du Salon de la Danse</p></div></div></body></html>
