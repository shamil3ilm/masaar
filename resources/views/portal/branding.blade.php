@extends('layouts.portal')

@section('title', __('Branding'))

@section('content')
<x-ui.page-header :title="__('Branding')" :description="__('Your organization\'s colours, name and logo, over the platform\'s. They apply to your portal, and your logo is printed on your invoices.')" />

@include('branding.form')
@endsection
