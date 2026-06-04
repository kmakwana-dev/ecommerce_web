@extends('Template::layouts.checkout')

@section('blade')
    <form action="{{ route('checkout.guest.shipping.info.store') }}" method="POST" id="shipping-form">
        @csrf
        <div>
            @php
                $shippingInformation = (object) Session::get('shipping_info');
                $checkoutContent = getContent('guest_checkout.content', true)?->data_values;
            @endphp

            @if ($checkoutContent->shipping_info_recipient_info_title)
                <h5 class="mb-1 ">{{ __($checkoutContent->shipping_info_recipient_info_title) }}</h5>
            @endif

            @if ($checkoutContent->shipping_info_recipient_info_description)
                <p class="text-muted fst-italic">
                    {{ __($checkoutContent->shipping_info_recipient_info_description) }}
                </p>
            @endif

            <div class="row">

                <div class="col-md-6">
                    <div class="form-group">
                        <label>@lang('First Name')</label>
                        <input type="text" value="{{ @$shippingInformation->firstname }}" class="form-control form--control" name="firstname" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>@lang('Last Name')</label>
                        <input type="text" value="{{ @$shippingInformation->lastname }}" class="form-control form--control" name="lastname" required>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>@lang('Mobile')</label>
                        <div class="input-group">
                            <span class="input-group-text mobile-code"></span>
                            <input type="hidden" name="mobile_code">
                            <input type="hidden" name="country_code">
                            <input type="number" name="mobile" value="{{ @$shippingInformation->mobile }}" class="form-control form--control  ps-0" required>
                        </div>
                        <small class="text-muted"><i class="la la-info-circle"></i> @lang('Enter the mobile number without the country code.')</small>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>@lang('Email')</label>
                        <input type="text" value="{{ @$shippingInformation->email }}" class="form-control form--control" name="email" required>
                    </div>
                </div>
            </div>

            <div class="row mt-4">

                @if ($checkoutContent->description_in_shipping_info_title)
                    <h5 class="mb-1 ">{{ __($checkoutContent->description_in_shipping_info_title) }}</h5>
                @endif

                @if ($checkoutContent->description_in_shipping_info_description)
                    <p class="text-muted fst-italic">
                        {{ __($checkoutContent->description_in_shipping_info_description) }}
                    </p>
                @endif

                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">@lang('Country')</label>
                        <input type="hidden" name="country" value="India">
                        <select class="form-control form--control select2 country-select" disabled>
                            @foreach ($countries as $key => $country)
                                <option data-mobile_code="{{ $country->dial_code }}" value="{{ $country->country }}" data-code="{{ $key }}" @if($country->country == 'India') selected @endif>
                                    {{ __($country->country) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>@lang('Zip')</label>
                        <input type="text" value="{{ @$shippingInformation->zip }}" class="form-control form--control" name="zip" required>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>@lang('State')</label>
                        <input type="text" value="{{ @$shippingInformation->state }}" class="form-control form--control" name="state" required>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-group">
                        <label>@lang('City')</label>
                        <input type="text" value="{{ @$shippingInformation->city }}" class="form-control form--control" name="city" required>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="form-group">
                        <label>@lang('Address')</label>
                        <input type="text" value="{{ @$shippingInformation->address }}" class="form-control form--control" name="address" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between flex-wrap mt-4">
            <a href="{{ route('cart.page') }}" class="text--base">
                <i class="las la-angle-left"></i> @lang('Back to Cart')
            </a>

            <button type="submit" class="btn btn--base h-45">@lang('Continue to Next') <i class="las la-angle-right"></i></button>
        </div>
    </form>
@endsection

@push('script')
    <script>
        (function($) {
            "use strict";

            let mobileElement = $('.mobile-code');
            $('.country-select').on('change', function() {
                mobileElement.text(`+${$('.country-select :selected').data('mobile_code')}`);
            });
            // Initial load
            mobileElement.text(`+${$('.country-select :selected').data('mobile_code')}`);

            let lastPincode = '';
            $('input[name=zip]').on('input', function() {
                let pincode = $(this).val();
                if(pincode.length >= 6 && pincode !== lastPincode) {
                    lastPincode = pincode;
                    let cityInput = $('input[name=city]');
                    let stateInput = $('input[name=state]');
                    
                    let oldCity = cityInput.val();
                    let oldState = stateInput.val();

                    cityInput.val('Fetching...');
                    stateInput.val('Fetching...');

                    $.ajax({
                        url: `{{ url('pincode') }}/${pincode}`,
                        type: 'GET',
                        success: function(response) {
                            if(response.success) {
                                cityInput.val(response.city);
                                stateInput.val(response.state);
                            } else {
                                cityInput.val(oldCity === 'Fetching...' ? '' : oldCity);
                                stateInput.val(oldState === 'Fetching...' ? '' : oldState);
                            }
                        },
                        error: function() {
                            cityInput.val(oldCity === 'Fetching...' ? '' : oldCity);
                            stateInput.val(oldState === 'Fetching...' ? '' : oldState);
                        }
                    });
                }
            });

        })(jQuery);
    </script>
@endpush
