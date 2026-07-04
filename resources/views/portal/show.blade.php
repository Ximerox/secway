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

    @if ($replyEnabled)
        <h2 id="antworten">Antworten</h2>

        @if (session('reply_ok'))
            <div class="alert ok">{{ session('reply_ok') }}</div>
        @endif
        @if (session('reply_error'))
            <div class="alert">{{ session('reply_error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert">{{ $errors->first() }}</div>
        @endif

        @if ($repliesLeft > 0)
            <form method="post" action="{{ url('/m/'.$recipient->token.'/reply') }}"
                  enctype="multipart/form-data" class="replyform">
                @csrf
                <textarea name="reply_text" rows="7" maxlength="50000" required
                          placeholder="Ihre Antwort an {{ $msg->sender_name ?: $msg->sender_email }} …">{{ old('reply_text') }}</textarea>
                <label class="filelabel">
                    Dateien anhängen (optional, zusammen max. {{ $replyMaxMb }} MB)
                    <input type="file" name="files[]" multiple>
                </label>
                <button type="submit">Antwort sicher übermitteln</button>
                <p class="muted">Ihre Antwort wird verschlüsselt übertragen und dem Absender direkt zugestellt.
                    Anhänge werden automatisch auf Schadsoftware geprüft.</p>
            </form>
        @else
            <p class="muted">Für diese Nachricht wurden bereits alle verfügbaren Antworten genutzt.
                Bitte wenden Sie sich direkt per E-Mail an den Absender.</p>
        @endif
    @endif
@endsection
