@extends('portal.layout')

@section('title', $title)

@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $text }}</p>
@endsection
