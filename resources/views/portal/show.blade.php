@extends('portal.layout')

@section('title', $msg->subject ?: 'Nachricht')

@section('content')
    <h1>{{ $msg->subject ?: '(Ohne Betreff)' }}</h1>
    <p class="meta">Von: <strong>{{ $msg->sender_name ?: $msg->sender_email }}</strong> &lt;{{ $msg->sender_email }}&gt;</p>
    <p class="meta">Gesendet: {{ $msg->created_at->format('d.m.Y H:i') }} Uhr · Abrufbar bis {{ $msg->expires_at->format('d.m.Y') }}</p>

    @if ($attachments->isNotEmpty())
        <h2>Anhänge ({{ $attachments->count() }})</h2>
        <ul class="attachments">
            @foreach ($attachments as $a)
                <li>
                    <a href="{{ url('/m/'.$recipient->token.'/download/'.$a->id) }}">{{ $a->filename }}</a>
                    <span class="muted">{{ number_format($a->size_bytes / 1024, 0, ',', '.') }} KB</span>
                </li>
            @endforeach
        </ul>
    @endif

    <h2>Nachricht</h2>
    @if ($bodyHtml)
        <iframe class="mailbody" sandbox="allow-popups allow-popups-to-escape-sandbox"
                srcdoc="{{ '<base target="_blank">'.$bodyHtml }}"></iframe>
    @elseif ($bodyText)
        <pre class="mailbody">{{ $bodyText }}</pre>
    @else
        <p class="muted">Diese Nachricht enthält keinen Text.</p>
    @endif
@endsection
