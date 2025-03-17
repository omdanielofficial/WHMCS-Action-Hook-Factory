// HOOK UPDATED TO WORK WITH WHMCS 8.11

<?php

use WHMCS\Database\Capsule;
use WHMCS\Carbon;
use WHMCS\Session;
use WHMCS\Module\Gateway;

// Configuration
define('KT_ONETIME_PRODUCTS', []); // Array of product IDs to treat as "one-off"
define('KT_ONETIME_PRODUCT_GROUPS', []); // Array of product group IDs to treat as one-off
define('KT_FIRST_TIMER_TOLERANCE', true); // Disable restrictions for new customers' first order
define('KT_NOT_REPEATABLE', true); // Prevent purchasing multiple one-off products
define('KT_DOMAIN_REQUIRES_PRODUCT', false); // Require active product for domain purchase
define('KT_ON_CLIENT_REGISTER', false); // Days limit for new client registration to order one-off products
define('KT_PROMPT_REMOVAL', 'modal'); // Removal prompt type: "bootstrap-alert", "modal", "js-alert"
define('KT_TEXT_DISALLOWED', 'This product can only be activated once.'); // Disallowed product message
define('KT_TEXT_REQUIRE_PRODUCT', 'Domain purchase requires an active product/service.'); // Product requirement message

add_hook('ShoppingCartValidateProductUpdate', 1, function($vars) {
    try {
        $cart = Session::get('cart');
        if (empty($cart['products'])) {
            return;
        }

        $disallowedPids = Capsule::table('tblproducts')
            ->whereIn('gid', KT_ONETIME_PRODUCT_GROUPS)
            ->orWhereIn('id', KT_ONETIME_PRODUCTS)
            ->get()
            ->pluck('id')
            ->all();

        $productsInCart = array_column($cart['products'], 'pid');
        $userId = Session::get('uid');

        if ($userId && KT_ON_CLIENT_REGISTER) {
            $isNewCustomer = Capsule::table('tblclients')
                ->where('id', $userId)
                ->where('datecreated', '>=', Carbon::now()->subDays((int)KT_ON_CLIENT_REGISTER))
                ->exists();

            if (!$isNewCustomer) {
                $cart['products'] = array_filter($cart['products'], function($product) use ($disallowedPids) {
                    return !in_array($product['pid'], $disallowedPids);
                });
            }
        }

        if ($userId) {
            $userProducts = Capsule::table('tblhosting')
                ->where('userid', $userId)
                ->whereIn('packageid', $disallowedPids)
                ->groupBy('packageid')
                ->get()
                ->pluck('packageid')
                ->all();
        } else {
            $userProducts = [];
        }

        $productTotals = [];
        foreach ($cart['products'] as $k => $product) {
            $productTotals[$product['pid']] = ($productTotals[$product['pid']] ?? 0) + 1;

            if (in_array($product['pid'], $userProducts)) {
                unset($cart['products'][$k]);
                $removedFromCart = true;
            }

            if (KT_NOT_REPEATABLE && in_array($product['pid'], $disallowedPids) && $productTotals[$product['pid']] > 1) {
                unset($cart['products'][$k]);
                $removedFromCart = true;
            }
        }

        if (!KT_FIRST_TIMER_TOLERANCE) {
            foreach ($productTotals as $pid => $count) {
                if ($count > 1 && in_array($pid, $disallowedPids)) {
                    $cart['products'] = array_filter($cart['products'], function($product) use ($pid) {
                        return $product['pid'] !== $pid;
                    });
                    $removedFromCart = true;
                }
            }
        }

        Session::set('cart', $cart);

        if (!empty($removedFromCart)) {
            header('Location: cart.php?a=view&disallowed=1');
            exit;
        }

        if (!empty($cart['domains']) && KT_DOMAIN_REQUIRES_PRODUCT) {
            $userHasProduct = Capsule::table('tblhosting')
                ->where('userid', $userId)
                ->whereNotIn('domainstatus', ['Pending', 'Terminated'])
                ->exists();

            if (!$userHasProduct && empty($cart['products'])) {
                $cart['domains'] = [];
                Session::set('cart', $cart);
                header('Location: cart.php?a=view&requireProduct=1');
                exit;
            }
        }
    } catch (\Exception $e) {
        logActivity("Hook Error: " . $e->getMessage());
    }
});

add_hook('ClientAreaHeadOutput', 1, function($vars) {
    if ($vars['filename'] == 'cart' && isset($_GET['a']) && $_GET['a'] == 'view') {
        $text = '';
        if (isset($_GET['disallowed'])) {
            $text = KT_TEXT_DISALLOWED;
        } elseif (isset($_GET['requireProduct'])) {
            $text = KT_TEXT_REQUIRE_PRODUCT;
        }

        if (!empty($text)) {
            $code = '';
            switch (KT_PROMPT_REMOVAL) {
                case 'bootstrap-alert':
                    $code = "$('form[action=\"/cart.php?a=view\"]').prepend('<div class=\"alert alert-warning text-center\" role=\"alert\">{$text}</div>');";
                    break;
                case 'modal':
                    $code = <<<JS
                    $("#modalAjax .modal-header").hide();
                    $("#modalAjax .modal-body").html('<div class="text-center" style="padding:15px"><i class="far fa-grin-beam-sweat fa-5x"></i></div><div class="text-center" style="padding:15px">{$text}</div>');
                    $('#modalAjax .loader').hide();
                    $('#modalAjax .modal-submit').hide();
                    $("#modalAjax").modal('show');
                    JS;
                    break;
                case 'js-alert':
                    $code = "alert('{$text}');";
                    break;
            }

            return <<<HTML
            <script type="text/javascript">
            $(document).ready(function() {
                {$code}
            });
            </script>
            HTML;
        }
    }
});

add_hook('AdminAreaHeadOutput', 1, function($vars) {
    if ($vars['filename'] == 'configproducts') {
        $objProducts = json_encode(KT_ONETIME_PRODUCTS);
        $objGroups = json_encode(KT_ONETIME_PRODUCT_GROUPS);

        return <<<HTML
        <script type="text/javascript">
        $(document).ready(function() {
            $.each({$objProducts}, function(key, value) {
                $('#tableBackground > table > tbody > tr').find("a[href$='?action=edit&id=" + value + "']").closest('tr').find('td').css('background-color', '#d2eed0');
                $('#tableBackground > table > tbody > tr').find("a[href$='?action=edit&id=" + value + "']").closest('tr').find('td:first').append(' <label class="label label-success">Promo</label>');
            });

            $.each({$objGroups}, function(key, value) {
                $('#tableBackground > table > tbody > tr').find("a[href$='?action=editgroup&ids=" + value + "']").closest('tr').find('td').css('background-color', '#d2eed0');
                $('#tableBackground > table > tbody > tr').find("a[href$='?action=editgroup&ids=" + value + "']").closest('tr').find('td:first > div.prodGroup').append(' <label class="label label-success">Promo</label>');
            });
        });
        </script>
        HTML;
    }
});
