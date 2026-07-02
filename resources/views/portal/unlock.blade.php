@extends('portal.layout')

@section('title', 'Kennwort erforderlich')

@section('content')
    <h1>Vertrauliche Nachricht</h1>
    <p><strong>{{ $msg->sender_name ?: $msg->sender_email }}</strong> ({{ $msg->sender_email }}) hat Ihnen eine vertrauliche Nachricht gesendet.</p>
    <p>Bitte geben Sie das Kennwort ein, das Sie in einer separaten E-Mail erhalten haben.</p>

    @if (session('error'))
        <div class="alert">{{ session('error') }}</div>
    @endif

    <form method="post" action="{{ url('/m/'.$recipient->token) }}">
        @csrf
        <input type="password" name="password" placeholder="Kennwort" autocomplete="off" autofocus required>
        <button type="submit">Nachricht öffnen</button>
    </form>

    <p class="muted" style="margin-top:16px;">Die Nachricht ist abrufbar bis {{ $msg->expires_at->format('d.m.Y') }}. Danach wird sie automatisch gelöscht.</p>
@endsection
