@extends('layouts.app')
@section('title', 'Plans and usage')
@section('content')
@include('billing.usage')
@include('billing.plans')
@endsection
