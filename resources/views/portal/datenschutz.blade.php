@extends('portal.layout')

@section('title', 'Datenschutzerklärung')

@section('content')
    <h1>Datenschutzerklärung</h1>
    {{-- Inhalt wird im Admin-Bereich unter Einstellungen gepflegt --}}
    {!! \App\Models\Setting::get('legal_datenschutz', '<p class="muted">Die Datenschutzerklärung wurde noch nicht hinterlegt. Administratoren können sie im Admin-Bereich unter „Einstellungen" pflegen.</p>') !!}
@endsection
