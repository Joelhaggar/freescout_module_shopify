@extends('layouts.app')

@section('title_full', 'Shopify'.' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')

    <div class="section-heading">
        Shopify
    </div>

    @include('partials/flash_messages')

 	<div class="row-container">
        <div class="row">
            <div class="col-xs-12">
                @include('shopify::settings')
            </div>
        </div>
    </div>

@endsection