@extends('front.layouts.app')

@section('title','Buy and Sell Cars - Motor Soog')

@section('content')
<div class="container mt-5">
    <div class="row align-items-center">
        <div class="col-md-6">
            <h1 class="display-5">Buy & Sell Cars with Confidence</h1>
            <p class="lead">Motor Soog is your trusted marketplace for buying and selling vehicles. We connect serious buyers with verified sellers across a wide selection of cars, from economy to luxury.</p>

            <div class="alert alert-info mt-3" role="alert">
                Note: We are in the process of verifying our site with Google Search Console for the platform hosted at <strong>https://motors.azsystems.tech</strong>.
                Our Privacy Policy is available at <a href="{{ route('privacy.policy') }}">/privacy-policy</a> on this site and is linked from the footer.
            </div>

            <ul>
                <li>Fast listings — post a car in minutes</li>
                <li>Verified sellers and secure communication</li>
                <li>Competitive prices and detailed listings</li>
                <li>Support and dispute assistance</li>
            </ul>

            <a href="https://motors.azsystems.tech/" class="btn btn-primary btn-lg">List Your Car / Login</a>
        </div>
        <div class="col-md-6 text-center">
            <img src="/favicon.ico" alt="Cars" class="img-fluid rounded" style="max-height:320px;">
        </div>
    </div>

    <hr class="my-5">

    <div class="row">
        <div class="col-md-4">
            <h3>Sell</h3>
            <p>Create an ad with photos, detailed specs, and contact information. Reach buyers in your area.</p>
        </div>
        <div class="col-md-4">
            <h3>Buy</h3>
            <p>Browse listings with filters, compare options, and message sellers directly.</p>
        </div>
        <div class="col-md-4">
            <h3>Support</h3>
            <p>Our team is ready to help you with listing advice, negotiations and after-sale issues.</p>
        </div>
    </div>

    <hr class="my-5">

    <h3 class="mb-4">Featured Cars</h3>
    <div class="row g-3">
        <div class="col-sm-6 col-md-4">
            <div class="card">
                <img src="/favicon.ico" class="card-img-top" alt="Car 1">
                <div class="card-body">
                    <h5 class="card-title">2018 Compact Sedan</h5>
                    <p class="card-text">Well-maintained, low mileage.</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="card">
                <img src="/favicon.ico" class="card-img-top" alt="Car 2">
                <div class="card-body">
                    <h5 class="card-title">2019 SUV</h5>
                    <p class="card-text">Spacious SUV in excellent condition.</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="card">
                <img src="/favicon.ico" class="card-img-top" alt="Car 3">
                <div class="card-body">
                    <h5 class="card-title">2017 Coupe</h5>
                    <p class="card-text">Sporty coupe with clean history.</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection