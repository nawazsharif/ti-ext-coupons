<?php

namespace Igniter\Coupons;

use Admin\Models\Customers_model;
use Admin\Models\Orders_model;
use Igniter\Coupons\Models\Coupons_history_model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use System\Classes\BaseExtension;

class Extension extends BaseExtension
{
    public function boot()
    {
        Orders_model::extend(function ($model) {
            $model->relation['hasMany']['coupon_history'] = ['Igniter\Coupons\Models\Coupons_history_model'];
        });

        Event::listen('admin.order.beforePaymentProcessed', function ($order) {
//            new Coupons_model->redeemCoupon($order->order_id);
        });

        Event::listen('igniter.checkout.afterSaveOrder', function ($order) {
//            if ($couponCondition = Cart::conditions()->get('coupon'))
//                new Coupons_model->logCouponHistory($order, $couponCondition, $this->customer);
        });

        Customers_model::created(function ($customer) {
            Coupons_history_model::where('email', $customer->email)->update(['customer_id' => $customer->customer_id]);
        });

        Relation::morphMap([
            'coupon_history' => 'Igniter\Coupons\Models\Coupons_history_model',
            'coupons' => 'Igniter\Coupons\Models\Coupons_model',
        ]);
    }

    public function registerCartConditions()
    {
        return [
            \Igniter\Coupons\CartConditions\Coupon::class => [
                'name' => 'coupon',
                'label' => 'lang:igniter.cart::default.text_coupon',
                'description' => 'lang:igniter.cart::default.help_coupon_condition',
            ],
        ];
    }

    public function registerPermissions()
    {
        return [
            'Admin.Coupons' => [
                'label' => 'igniter.coupons::default.permissions',
                'group' => 'admin::lang.permissions.name',
            ],
        ];
    }

    public function registerNavigation()
    {
        return [
            'marketing' => [
                'child' => [
                    'coupons' => [
                        'priority' => 10,
                        'class' => 'coupons',
                        'href' => admin_url('igniter/coupons/coupons'),
                        'title' => lang('igniter.coupons::default.side_menu'),
                        'permission' => 'Admin.Coupons',
                    ],
                ],
            ],
        ];
    }
}
