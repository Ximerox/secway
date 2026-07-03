@extends('portal.layout')

@section('title', 'Impressum')

@section('content')
    <h1>Impressum</h1>
    {{-- Inhalt wird im Admin-Bereich unter Einstellungen gepflegt --}}
    {!! \App\Models\Setting::get('legal_impressum', '<p class="muted">Das Impressum wurde noch nicht hinterlegt. Administratoren können es im Admin-Bereich unter „Einstellungen" pflegen.</p>') !!}
@endsection
