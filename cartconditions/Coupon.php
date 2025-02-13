<?php

namespace Igniter\Coupons\CartConditions;

use Admin\Models\Menus_model;
use Exception;
use Igniter\Coupons\Models\Coupons_model;
use Igniter\Flame\Cart\CartCondition;
use Igniter\Flame\Cart\Facades\Cart;
use Igniter\Flame\Cart\Helpers\ActsAsItemable;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Local\Facades\Location;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Session;
use Main\Facades\Auth;

class Coupon extends CartCondition
{
    use ActsAsItemable;

    public $removeable = true;

    public $priority = 200;

    /**
     * @var Coupons_model
     */
    protected static $couponModel;

    protected static $applicableItems;

    protected static $hasErrors = false;

    public function getLabel()
    {
        return sprintf(lang($this->label), $this->getMetaData('code'));
    }

    public function getValue()
    {
        return 0 - $this->calculatedValue;
    }

    public function getMetaData($key = null, $default = null)
    {
        $data = Coupons_model::where('status', 1)
            ->whereJsonContains('order_restriction', Location::orderType())
            ->select(['code', 'min_total'])
            ->orderBy('min_total', 'desc')
            ->get();

        $data = $data?->toArray();
        $metaData = [];
        if ($data) {
            foreach ($data as $item) {
                if ($item['min_total'] < Cart::subtotal()) {
                    $metaData = $item;
                    break;
                }
            }
        }

        if (empty($metaData)) {
            $metaData = Session::get($this->getSessionKey(), []);
        }

        if (is_null($key)) {
            return $metaData;
        }

        return Arr::get($metaData, $key, $default);
    }

    public function setMetaData($key, $value = null)
    {
        $data = Coupons_model::where('status', 1)
            ->where('order_restriction', json_encode([Location::orderType()]))
            ->select('code')
            ->first();
        $data = $data?->toArray();
        if ($data){
            $metaData = $data;
        }
        else{
            $metaData = Session::get($this->getSessionKey(), []);
        }
        //$metaData = Session::get($this->getSessionKey(), []);

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                Arr::set($metaData, $k, $v);
            }
        }
        else {
            Arr::set($metaData, $key, $value);
        }
        Session::put($this->getSessionKey(), $metaData);
    }

    public function getModel()
    {
        if (!strlen($couponCode = $this->getMetaData('code')))
            return null;

        if (
            is_null(self::$couponModel) ||
            (self::$couponModel && strtolower(self::$couponModel->code) !== strtolower($couponCode))){
            self::$couponModel = Coupons_model::where('status', 1)
                ->where('order_restriction', json_encode([Location::orderType()]))
                ->first() ?? Coupons_model::getByCodeAndLocation($couponCode, Location::getId());
        }
        return self::$couponModel;
    }

    public function getApplicableItems($couponModel)
    {
        $applicableItems = $couponModel->menus->pluck('menu_id');
        $couponModel->categories->pluck('category_id')
            ->each(function ($category) use (&$applicableItems) {
                $applicableItems = $applicableItems
                    ->merge(Menus_model::whereHasCategory($category)->pluck('menu_id'));
            });

        self::$applicableItems = $applicableItems;

        return self::$applicableItems;
    }

    public function onLoad()
    {
        if (!strlen($this->getMetaData('code')) || self::$hasErrors)
            return;

        try {
            if (!$couponModel = $this->getModel())
                throw new ApplicationException(lang('igniter.cart::default.alert_coupon_invalid'));

            $this->validateCoupon($couponModel);

            $this->getApplicableItems($couponModel);
        } catch (Exception $ex) {
            flash()->alert($ex->getMessage())->now();

            $this->removeMetaData('code');
        }
    }

    public function beforeApply()
    {
        $selectedPaymentMethod = Session::get('selectedPaymentMethod');

        $couponModel = $this->getModel();
        if ($condition = !empty($couponModel->hasPaymentRestriction())){
            if (!isset($selectedPaymentMethod) || $selectedPaymentMethod !== $condition ) {
                return false;
            }
        }

        if (!$couponModel || $couponModel->appliesOnMenuItems() || self::$hasErrors) {
            return false;
        }

        if ($couponModel->appliesOnDelivery() && !Location::orderTypeIsDelivery()) {
            return false;
        }
    }

    public function getActions()
    {
        $value = optional($this->getModel())->discountWithOperand();

        if (optional($this->getModel())->appliesOnDelivery()) {
            $value = $this->calculateDeliveryDiscount();
        } // if we are item limited and not a % we need to apportion
        elseif (stripos($value, '%') === false && optional($this->getModel())->appliesOnMenuItems()) {
            $value = $this->calculateApportionment($value);
        }

        $actions = [
            'value' => $value,
        ];

        return [$actions];
    }

    public function whenInvalid()
    {
        if (!$this->getModel()->auto_apply) {
            $minimumOrder = $this->getModel()->minimumOrderTotal();

            flash()->warning(sprintf(
                lang('igniter.cart::default.alert_coupon_not_applied'),
                currency_format($minimumOrder)
            ))->now();
        }

        $this->removeMetaData('code');
    }

    protected function calculateApportionment($value)
    {
        $applicableItems = self::$applicableItems;
        if ($applicableItems && count($applicableItems)) {
            $applicableItemsTotal = Cart::content()->sum(function ($cartItem) use ($applicableItems) {
                if (!$applicableItems->contains($cartItem->id))
                    return 0;

                return $cartItem->subtotalWithoutConditions();
            });

            $value = ($this->target->subtotalWithoutConditions() / $applicableItemsTotal) * (float)$value;
        }

        return $value;
    }

    protected function calculateDeliveryDiscount()
    {
        $cartSubtotal = Cart::subtotal();
        $deliveryCharge = Location::coveredArea()->deliveryAmount($cartSubtotal);
        $couponModel = optional($this->getModel());
        if ($couponModel->isFixed()) {
            if ($couponModel->discount > $deliveryCharge) {
                $value = $deliveryCharge;
            } else {
                $value = $couponModel->discount;
            }
        } else {
            $value = $deliveryCharge * ($couponModel->discount * 0.01);
        }

        return '-'.$value;
    }

    protected function validateCoupon($couponModel)
    {
        $user = Auth::getUser();
        $locationId = Location::getId();
        $orderType = Location::orderType();
        $orderDateTime = Location::orderDateTime();

        if ($couponModel->isExpired($orderDateTime))
            throw new ApplicationException(lang('igniter.cart::default.alert_coupon_expired'));

        if ($couponModel->hasRestriction($orderType))
            throw new ApplicationException(sprintf(
                lang('igniter.cart::default.alert_coupon_order_restriction'), $orderType
            ));

        if ($couponModel->hasLocationRestriction($locationId))
            throw new ApplicationException(lang('igniter.cart::default.alert_coupon_location_restricted'));

        if (Cart::subtotal() < $couponModel->minimumOrderTotal())
            throw new ApplicationException(sprintf(
                lang('igniter.cart::default.alert_coupon_not_applied'),
                currency_format($couponModel->minimumOrderTotal())
            ));

        if ($couponModel->hasReachedMaxRedemption())
            throw new ApplicationException(lang('igniter.cart::default.alert_coupon_maximum_reached'));

        if ((optional($couponModel->customers)->isNotEmpty() || optional($couponModel->customer_groups)->isNotEmpty()) && !$user
        ) throw new ApplicationException(lang('igniter.coupons::default.alert_coupon_login_required'));

        if ($user && $couponModel->customerHasMaxRedemption($user))
            throw new ApplicationException(lang('igniter.cart::default.alert_coupon_maximum_reached'));

        throw_unless($couponModel->customerCanRedeem($user),
            new ApplicationException(lang('igniter.coupons::default.alert_customer_cannot_redeem')));

        throw_unless($couponModel->customerGroupCanRedeem(optional($user)->group),
            new ApplicationException(lang('igniter.coupons::default.alert_customer_group_cannot_redeem')));
    }

    public static function isApplicableTo($cartItem)
    {
        if (!($couponModel = self::$couponModel) || self::$hasErrors)
            return false;

        if (!$couponModel->appliesOnMenuItems())
            return false;

        if (!$applicableItems = self::$applicableItems)
            return false;

        return $applicableItems->contains($cartItem->id);
    }
}
