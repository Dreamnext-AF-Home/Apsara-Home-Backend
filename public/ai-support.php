<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$loadEnv = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }

    $data = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $data[$key] = $value;
    }
    return $data;
};

$env = array_merge(
    $loadEnv(__DIR__ . '/../Apsara-Home-Backend/.env'),
    $loadEnv(__DIR__ . '/.env')
);

$envValue = static function (string $key, string $default = '') use ($env): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($env[$key]) && $env[$key] !== '') {
        return (string) $env[$key];
    }
    return $default;
};

$frontendBaseUrl = rtrim(
    $envValue('NEXT_PUBLIC_APP_URL', $envValue('NEXTAUTH_URL', $envValue('FRONTEND_URL', ''))),
    '/'
);
if ($frontendBaseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $frontendBaseUrl = $scheme . '://' . $host;
}

$dbHost = $envValue('DB_HOST', '127.0.0.1');
$dbPort = (int) $envValue('DB_PORT', '3306');
$dbUser = $envValue('DB_USERNAME', 'root');
$dbPass = $envValue('DB_PASSWORD', '');
$dbName = $envValue('DB_DATABASE', '');

$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($db->connect_errno) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}
$db->set_charset('utf8mb4');

$cleanInput = static function (string $value): string {
    $value = trim($value);
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return trim($value);
};

$slugify = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'product';
};

$buildProductUrl = static function (string $baseUrl, string $name, int $id): string {
    $slug = $name !== '' ? $name : 'product';
    return $baseUrl . '/product/' . $slug . '-i' . $id;
};

$xdeApi = null;

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$questionRaw = (string)($_POST['message'] ?? '');
$question = trim($cleanInput($questionRaw));
if ($question === '') {
    echo json_encode([
        'status' => 'ok',
        'reply' => 'Please type your question so I can help.',
        'quick_replies' => [
            'What products match a minimalist style?',
            'Suggest items under PHP 5,000.',
            'What is best for office setup at home?',
            'What is the highest-rated product?',
            'What items are low in stock?',
            'Show me trending home decor.',
            'What if I received the wrong item?',
            'Do you accept GCash or online banking?',
            'How can I track my order?',
            'What happens if my item arrives damaged?',
            'What courier do you use?',
            'Can you recommend a sofa for small spaces?',
            'What are your best-selling living room products?',
            'Do you have items on sale right now?'
        ]
    ]);
    exit;
}

$isMember = (!empty($_SESSION['MM_mem_ctr']) && (int)$_SESSION['MM_mem_ctr'] > 0);
$memberId = $isMember ? (int)$_SESSION['MM_mem_ctr'] : 0;
$qLower = strtolower($question);
$qNormSimple = preg_replace('/[^a-z0-9\s]/', '', preg_replace('/\s+/', ' ', trim($qLower)));
$faqMap = [
    'kumusta paano kayo makakatulong sa akin' => 'Kumusta! Maaari kitang tulungan sa paghahanap ng produkto, pagtsek ng order, at iba pang tanong tungkol sa aming tindahan.',
    'ano ang ecommerce ninyo' => 'Ang aming website ay nagbebenta ng produkto online at maaari kang bumili kahit nasa bahay ka lang.',
    'paano magsign up o gumawa ng account' => 'I-click ang “Mag-sign Up” at punan ang form gamit ang iyong email at password.',
    'libre ba ang pag sign up' => 'Oo! Libre ang paggawa ng account.',
    'paano maglogin' => 'I-click ang “Mag-login” at ilagay ang iyong email at password.',
    'ano ang available ninyong produkto' => 'Maaari mong tingnan lahat ng produkto sa aming “Shop” o gamitin ang search bar.',
    'paano ko malalaman ang laki o sukat ng produkto' => 'Bawat produkto ay may detalye sa description kasama ang sukat o dimension.',
    'may available ba kayong color red o blue' => 'Oo, nakalista ang kulay sa product page. Piliin ang nais na kulay bago mag-add to cart.',
    'paano magadd sa cart' => 'I-click ang “Add to Cart” sa produkto, at makikita mo ito sa iyong shopping cart.',
    'paano ko matitiyak na available pa ang stock' => 'Nakalagay sa product page kung may stock o out of stock ang item.',
    'paano ako makakabili' => 'Piliin ang produkto -> Add to Cart -> Checkout -> Pumili ng payment method -> Confirm order.',
    'puwede bang magorder ng maraming produkto' => 'Oo, puwede mong i-add sa cart ang lahat ng nais mong bilhin bago mag-checkout.',
    'may discount o promo ba' => 'Oo! Tingnan ang aming “Promotions” section para sa kasalukuyang promo codes at discounts.',
    'paano gamitin ang promo code' => 'Ilagay ang promo code sa checkout page bago i-confirm ang order.',
    'maaari ba akong magpreorder ng produkto' => 'Oo, may mga produkto na available for pre-order. Nakalagay ang details sa product page.',
    'anong payment methods ang tinatanggap ninyo' => 'Tinatanggap namin ang credit/debit card, GCash, PayMaya, at cash on delivery (COD).',
    'paano gumamit ng gcash sa pagbabayad' => 'Piliin ang GCash bilang payment option sa checkout at sundan ang instructions.',
    'libre ba ang cod' => 'Depende sa produkto at location, may kaunting shipping fee para sa COD.',
    'secure ba ang pagbabayad online' => 'Oo, ligtas ang aming payment gateway at may SSL encryption.',
    'maaari ba akong magbayad sa installment' => 'Oo, available ang installment sa ilang payment partners.',
    'paano malalaman kung successful ang payment' => 'Makakatanggap ka ng confirmation email o notification mula sa amin.',
    'magkano ang shipping fee' => 'Depende sa weight, size, at destination ng produkto. Makikita ito sa checkout.',
    'gaano katagal bago madeliver ang order' => 'Standard shipping ay 3-7 araw, express ay 1-3 araw depende sa location.',
    'may tracking number ba' => 'Oo, ibibigay namin ang tracking number para masubaybayan ang delivery.',
    'puwede bang magchange ng delivery address pagkatapos magorder' => 'Depende, kontakin agad ang customer support para ma-update ang address.',
    'ano ang ginagawa kung nadelay ang delivery' => 'Makipag-ugnayan sa amin o sa courier para sa updates at assistance.',
    'puwede bang magschedule ng delivery' => 'Oo, may option sa checkout para sa preferred delivery date.',
    'puwede bang pickup sa store' => 'Depende sa produkto at branch. Tingnan ang checkout options.',
    'paano magreturn ng produkto' => 'Kontakin ang customer support at sundan ang return instructions.',
    'puwede ba ang exchange ng produkto' => 'Oo, puwede palitan basta within return/exchange policy period.',
    'gaano katagal bago marefund' => 'Karaniwan 3-7 business days matapos ma-approve ang return.',
    'libre ba ang return shipping' => 'Depende sa item at reason ng return, nakasaad sa return policy.',
    'ano ang dapat gawin kung may defective na produkto' => 'I-report agad sa customer support at maaari itong ipalit o i-refund.',
    'paano makipagugnayan sa customer support' => 'Pwede sa chat, email, o hotline number na nakalagay sa website.',
    'available ba kayo 247' => 'Oo, ang chatbot ay available 24/7. Para sa human agent, depende sa office hours.',
    'ano ang average response time' => 'Sa chatbot, instant; sa email o human agent, 1-24 hours.',
    'paano magfollow up sa previous order inquiry' => 'Ibigay ang order number sa chatbot o customer support para matulungan ka.',
    'puwede ba akong magrequest ng special packaging' => 'Oo, may option sa checkout o sa customer support request.',
    'puwede bang humiling ng invoice' => 'Oo, makakakuha ka ng e-invoice pagkatapos ng order confirmation.',
    'ano ang bestseller products ninyo' => 'Makikita sa “Best Sellers” section ng website.',
    'ano ang recommended gift for 40 age' => 'Depende sa interest nila, pero popular ang home decor, gadgets, at fashion accessories.',
    'may seasonal products ba kayo' => 'Oo, may mga produkto na seasonal o limited edition.',
    'puwede ba akong humingi ng product suggestion' => 'Oo, sabihin lang ang budget, interest, o occasion, at tutulungan ka ng AI.',
    'ano ang bagong products' => 'Makikita sa “New Arrivals” section ng website.',
    'paano kung hindi gumana ang website' => 'Subukang i-refresh o i-clear ang cache. Kung hindi pa rin, kontakin ang customer support.',
    'paano kung hindi gumana ang payment' => 'Subukang ibang payment method o kontakin ang support para sa assistance.',
    'puwede bang iupdate ang account info' => 'Oo, sa “My Account” section puwede mong i-update ang personal details.',
    'nakalimutan ko ang password paano ko ireset' => 'I-click ang “Forgot Password” at sundin ang instructions para gumawa ng bago.',
    'puwede bang icancel ang order' => 'Oo, basta hindi pa naipadala. Kontakin agad ang customer support.',
    'paano matitiyak na ligtas ang personal info ko' => 'Lahat ng data ay secured at encrypted, at hindi ibinabahagi sa third parties.'
    ,
    'how can you help me' => 'Hi! I can help you find products, check orders, and answer questions about our store.',
    'what is your ecommerce' => 'Our website sells products online, so you can shop from home anytime.',
    'how do i sign up or create an account' => 'Click “Sign Up” and fill out the form using your email and password.',
    'is sign up free' => 'Yes! Creating an account is free.',
    'how do i log in' => 'Click “Log in” and enter your email and password.',
    'what products are available' => 'You can browse all products in our “Shop” or use the search bar.',
    'how do i know the size or dimensions' => 'Each product includes size and dimension details in the description.',
    'do you have red or blue color' => 'Yes, available colors are listed on the product page. Select your preferred color before adding to cart.',
    'how do i add to cart' => 'Click “Add to Cart” on a product and it will appear in your shopping cart.',
    'how can i make sure it is in stock' => 'The product page shows whether an item is in stock or out of stock.',
    'how can i buy' => 'Choose a product -> Add to Cart -> Checkout -> Select payment method -> Confirm order.',
    'can i order multiple products' => 'Yes, you can add multiple items to your cart before checkout.',
    'do you have discounts or promos' => 'Yes! Check our “Promotions” section for current promo codes and discounts.',
    'how do i use a promo code' => 'Enter the promo code on the checkout page before confirming your order.',
    'can i preorder a product' => 'Yes, some products are available for pre-order. Details are on the product page.',
    'what payment methods do you accept' => 'We accept credit/debit cards, GCash, PayMaya, and cash on delivery (COD).',
    'how to pay with gcash' => 'Select GCash at checkout and follow the instructions.',
    'is cod free' => 'It depends on the product and location; COD may include a small shipping fee.',
    'is online payment secure' => 'Yes, our payment gateway is secure and uses SSL encryption.',
    'can i pay in installment' => 'Yes, installment is available through selected payment partners.',
    'how do i know if payment is successful' => 'You will receive a confirmation email or notification from us.',
    'how much is the shipping fee' => 'Shipping fee depends on weight, size, and destination. You can see it at checkout.',
    'how long is delivery' => 'Standard shipping is 3-7 days; express is 1-3 days depending on location.',
    'do i get a tracking number' => 'Yes, we will provide a tracking number so you can monitor delivery.',
    'can i change the delivery address after ordering' => 'It depends; please contact customer support immediately to update your address.',
    'what if delivery is delayed' => 'Contact us or the courier for updates and assistance.',
    'can i schedule delivery' => 'Yes, you can select a preferred delivery date at checkout if available.',
    'can i pick up in store' => 'It depends on the product and branch. Check the checkout options.',
    'how do i return a product' => 'Contact customer support and follow the return instructions.',
    'can i exchange a product' => 'Yes, exchanges are allowed within the return/exchange policy period.',
    'how long does refund take' => 'Usually 3-7 business days after return approval.',
    'is return shipping free' => 'It depends on the item and return reason; please check the return policy.',
    'what if the product is defective' => 'Report it to customer support right away for replacement or refund.',
    'how can i contact customer support' => 'You can reach us via chat, email, or the hotline listed on the website.',
    'are you available 247' => 'Yes, the chatbot is available 24/7. Human agents are available during office hours.',
    'what is the average response time' => 'Chatbot: instant. Email or human agent: 1-24 hours.',
    'how do i follow up on an order inquiry' => 'Provide your order number to the chatbot or customer support.',
    'can i request special packaging' => 'Yes, you can request it at checkout or via customer support.',
    'can i request an invoice' => 'Yes, an e-invoice is available after order confirmation.',
    'what are your best seller products' => 'You can find them in the “Best Sellers” section of the website.',
    'what is a recommended gift for 40 age' => 'It depends on their interests, but home decor, gadgets, and fashion accessories are popular.',
    'do you have seasonal products' => 'Yes, some products are seasonal or limited edition.',
    'can you suggest a product' => 'Yes, share your budget, interest, or occasion and the AI will help.',
    'what are new products' => 'Check the “New Arrivals” section of the website.',
    'what if the website is not working' => 'Try refreshing or clearing cache. If it still fails, contact customer support.',
    'what if payment is not working' => 'Try another payment method or contact support for assistance.',
    'can i update my account info' => 'Yes, you can update your personal details in “My Account”.',
    'i forgot my password how do i reset' => 'Click “Forgot Password” and follow the instructions to reset.',
    'can i cancel my order' => 'Yes, as long as it has not been shipped. Contact customer support immediately.',
    'how do you keep my personal info safe' => 'All data is secured and encrypted, and not shared with third parties.'
];

// Tagalog support-intent aliases: map common Filipino phrases to existing intent keywords.
$tagalogIntentAliases = [
    // Exact/near-exact Tagalog question forms (orders & delivery).
    '/nasaan na po ang order ko\??/i' => ' track my order order status ',
    '/kailan darating ang order ko\??/i' => ' delivery time shipping time eta ',
    '/puwede pong ma-?track ang order\??/i' => ' track my order order tracking ',
    '/ano ang tracking number ko\??/i' => ' tracking number order tracking ',
    '/delayed po ba ang delivery\??/i' => ' delayed order delivery status ',
    '/out for delivery na po ba\??/i' => ' out for delivery delivery status ',
    '/puwede pong palitan ang delivery address\??/i' => ' change delivery address shipping address ',

    // Returns & refunds.
    '/paano mag return ng item\??/i' => ' how do i return return an item ',
    '/puwede po bang i-?refund\??/i' => ' refund refund process ',
    '/kailan marerefund ang payment\??/i' => ' refund time refund status ',
    '/defective po yung item.*ano gagawin\??/i' => ' defective damaged item return policy ',
    '/wrong item po ang nareceive ko|wrong item po ang nareceive ko/i' => ' wrong item incorrect item ',
    '/puwede pong replacement\??/i' => ' replacement exchange item ',

    // Payments.
    '/nagbayad na ako pero hindi reflected/i' => ' payment not reflected payment failed ',
    '/payment failed.*ano gagawin\??/i' => ' payment failed declined card ',
    '/puwede ba ang gcash\/maya\??/i' => ' gcash maya paymaya payment methods ',
    '/cash on delivery ba ito\??/i' => ' cash on delivery cod ',
    '/paano mag-?request ng invoice\??/i' => ' invoice payment receipt ',
    '/hindi ko nareceive ang confirmation|hindi ko nareceive ang confirmation/i' => ' order confirmation email not received ',

    // Account issues.
    '/nakalimutan ko password ko\??/i' => ' forgot password reset password ',
    '/paano mag-?change password\??/i' => ' change password reset password ',
    '/hindi ako makalogin\??/i' => ' login problem login issue ',
    '/paano mag-?update ng profile\??/i' => ' update profile account settings ',
    '/puwede bang mag-?delete ng account\??/i' => ' delete account close account ',

    // Product questions.
    '/available po ba ang stock\??/i' => ' stock availability in stock ',
    '/original ba itong product\??/i' => ' original authentic ',
    '/puwede pong makita ang size chart\??/i' => ' size chart sizes ',
    '/ano ang warranty\??/i' => ' warranty guarantee ',
    '/may ibang kulay ba\??/i' => ' kulay available colors available variants ',

    // Customer support help.
    '/paano po mag-?contact ng support\??/i' => ' contact support customer service ',
    '/puwede pong mag-?follow up\??/i' => ' follow-up inquiry contact support ',
    '/may live chat ba\??/i' => ' live chat support ',
    '/saan ko makikita ang ticket number\??/i' => ' ticket number support ',
    '/salamat po/i' => ' thank you ',
    '/mga mababang price na product\??/i' => ' lowest low price cheapest budget ',
    '/give lowest price product/i' => ' lowest low price cheapest budget ',
    '/mo ba itrack ang order ko/i' => ' track my order order tracking ',
    '/saan ko makikita ang order history ko\??/i' => ' order history my orders ',
    '/pwede bang i-?cancel ang order\??/i' => ' cancel my order cancel order ',
    '/na-?shipped na po ba\??/i' => ' shipping status delivery status order status ',
    '/bakit hindi pa nadedeliver\??/i' => ' delivery delay delayed order delivery time ',
    '/puwede bang palitan ang order details\??/i' => ' modify my order change my order edit order ',
    '/ano status ng order ko\??/i' => ' order status track my order ',
    '/wala pa akong order confirmation/i' => ' order confirmation email not received ',
    '/paano mag-?track ng order\??/i' => ' track my order order tracking ',
    '/ilang araw bago madeliver\??/i' => ' delivery time shipping time eta ',
    '/puwede bang i-?reschedule ang delivery\??/i' => ' delivery reschedule delivery time contact support ',

    '/paano mag-?request ng return\??/i' => ' return an item how do i return ',
    '/gaano katagal ang refund process\??/i' => ' refund time refund process ',
    '/hindi ko pa natatanggap ang refund\??/i' => ' refund status refund time ',
    '/pwede bang palitan ng ibang item\??/i' => ' exchange item replacement ',
    '/wrong size\/item ang nareceive ko|wrong size\/item ang nareceive ko/i' => ' wrong item incorrect item exchange item ',
    '/may return fee ba\??/i' => ' return shipping return fee return policy ',
    '/saan ibabalik ang item\??/i' => ' return process return policy ',
    '/paano mag-?file ng return request\??/i' => ' return request return an item ',
    '/kailangan ba ng packaging\??/i' => ' return policy packaging return request ',

    '/payment successful ba\??/i' => ' payment successful order confirmation ',
    '/hindi nag-?proceed ang payment\??/i' => ' payment failed payment declined ',
    '/puwede ba ang installment\??/i' => ' installment installments ',
    '/ano payment methods\??/i' => ' payment methods ',
    '/nagdouble charge ako/i' => ' double charge payment issue contact support ',
    '/wala akong payment confirmation/i' => ' payment confirmation email not received ',
    '/gcash\/maya payment issue/i' => ' gcash maya payment failed ',
    '/credit card declined/i' => ' declined card payment failed ',
    '/cash on delivery available ba\??/i' => ' cash on delivery cod available ',

    '/nakalimutan ko password\??/i' => ' forgot password reset password ',
    '/paano mag-?update ng email\??/i' => ' update profile account settings ',
    '/account verification issue/i' => ' account verification verify account ',
    '/hindi ko mareceive otp/i' => ' otp not received account verification ',
    '/email not recognized/i' => ' login problem email issue ',
    '/paano mag-?add ng address\??/i' => ' shipping address add address update profile ',
    '/account locked/i' => ' account locked login problem contact support ',

    '/paano mag-?care ng product\??/i' => ' product care product details ',
    '/puwede bang palitan ang size\??/i' => ' exchange item size chart ',
    '/ano materials nito\??/i' => ' product details specifications ',
    '/puwede ba sa cod\??/i' => ' cash on delivery cod ',
    '/may discount ba\??/i' => ' discount promo voucher ',

    '/saan mag-?file ng complaint\??/i' => ' complaint contact support ',
    '/puwede bang mag-?escalate\??/i' => ' escalate issue contact support human agent ',
    '/salamat sa tulong/i' => ' thank you ',
    '/ok na po, thank you/i' => ' thank you ',
    '/may update na ba\??/i' => ' follow-up inquiry order status ',
    '/paano mag-?send ng proof\??/i' => ' send proof support return request ',
    '/kailangan ko ng assistance/i' => ' help me support assistance ',

    '/\b(order ko|nasaan na order ko)\b/i' => ' track my order order status ',
    '/\b(tracking number|delivery status|out for delivery|delayed order)\b/i' => ' tracking delivery status shipping status ',
    '/\b(kailan darating)\b/i' => ' delivery time shipping time eta ',
    '/\b(shipping fee)\b/i' => ' shipping fee delivery fee shipping cost ',
    '/\b(delivery address)\b/i' => ' change delivery address shipping address ',
    '/\b(cod|cash on delivery)\b/i' => ' cash on delivery cod ',

    '/\b(return item|paano mag return)\b/i' => ' return an item how do i return ',
    '/\b(refund status|kailan marerefund)\b/i' => ' refund time refund process ',
    '/\b(defective item)\b/i' => ' damaged item defective ',
    '/\b(wrong item)\b/i' => ' wrong item incorrect item ',
    '/\b(replacement)\b/i' => ' exchange item replacement ',
    '/\b(return policy)\b/i' => ' return policy ',

    '/\b(payment method)\b/i' => ' payment methods ',
    '/\b(bayad na pero hindi reflected)\b/i' => ' payment failed payment not reflected order confirmation ',
    '/\b(credit card)\b/i' => ' credit card credit/debit ',
    '/\b(gcash)\b/i' => ' gcash ',
    '/\b(maya)\b/i' => ' maya paymaya ',
    '/\b(payment failed)\b/i' => ' payment failed declined card ',
    '/\b(order confirmation)\b/i' => ' order confirmation order status ',
    '/\b(invoice)\b/i' => ' invoice payment receipt ',

    '/\b(forgot password)\b/i' => ' forgot password reset password ',
    '/\b(change password)\b/i' => ' reset password account settings ',
    '/\b(account verification)\b/i' => ' verify account account verification ',
    '/\b(email not received)\b/i' => ' email issue verification email ',
    '/\b(login problem)\b/i' => ' login issue login problem ',
    '/\b(update profile)\b/i' => ' profile update account settings ',
    '/\b(delete account)\b/i' => ' delete account close account ',

    '/\b(stock availability)\b/i' => ' in stock available stock ',
    '/\b(size chart)\b/i' => ' sizes size chart ',
    '/\b(product details)\b/i' => ' product details specifications specs ',
    '/\b(kulay available)\b/i' => ' colors available variants ',
    '/\b(specifications)\b/i' => ' specifications specs ',
    '/\b(warranty)\b/i' => ' warranty guarantee ',
    '/\b(original ba ito)\b/i' => ' authentic original product ',

    '/\b(customer service|help me|support|live chat|ticket number|follow-?up inquiry|escalate issue)\b/i' => ' contact support human agent ',
    '/\b(pa-help po|paano po ito|hindi gumagana|nag error)\b/i' => ' help support issue not working error ',
    '/\b(salamat|ok po|sige po|pasensya na)\b/i' => ' thank you ',
];
foreach ($tagalogIntentAliases as $pattern => $append) {
    if (preg_match($pattern, $question)) {
        $qLower .= $append;
    }
}

$statusLabel = static function (int $odStatus, int $claimStatus): string {
    if ($odStatus === 0) {
        return 'To Pay';
    }
    if ($odStatus === 1 && $claimStatus === 0) {
        return 'Supplier to Pack';
    }
    if ($odStatus === 1 && $claimStatus === 1) {
        return 'Packed';
    }
    if ($odStatus === 1 && $claimStatus === 2) {
        return 'In Transit';
    }
    if ($odStatus === 1 && $claimStatus === 3) {
        return 'Delivered / Completed';
    }
    if ($odStatus === 1 && $claimStatus === 4) {
        return 'Cancelled';
    }
    if ($odStatus === 1 && $claimStatus === 5) {
        return 'Return / Refund';
    }
    return 'Processing';
};

$findGuestTable = static function (mysqli $dbConn): string {
    foreach (['tbl_guest-order', 'tbl_guest_order'] as $tbl) {
        try {
            $safe = $dbConn->real_escape_string($tbl);
            $res = $dbConn->query("SHOW TABLES LIKE '".$safe."'");
            if ($res && $res->num_rows > 0) {
                return $tbl;
            }
        } catch (Throwable) {
            return '';
        }
    }
    return '';
};

$reply = '';
$quickReplies = [
    'What products match a minimalist style?',
    'Suggest items under PHP 5,000.',
    'What is best for office setup at home?',
    'What is the highest-rated product?',
    'What items are low in stock?',
    'Show me trending home decor.',
    'What if I received the wrong item?',
    'Do you accept GCash or online banking?',
    'How can I track my order?',
    'What happens if my item arrives damaged?',
    'What courier do you use?',
    'Can you recommend a sofa for small spaces?',
    'What are your best-selling living room products?',
    'Do you have items on sale right now?'
];
$productCards = [];
$brandCards = [];
$brandViewAllUrl = '';

$baseUrl = $frontendBaseUrl;
$fallbackImage = $baseUrl . '/Images/HeroSection/chairs_stools.jpg';
$xdeBaseUrl = '';
$xdeApiKey = '';
$xdeToken = '';
$xdeEnabled = false;

$getXdeStatusText = static function ($api, string $xdeBaseUrl, string $xdeApiKey, string $xdeToken, string $trackingNo): string {
    $trackingNo = trim($trackingNo);
    if (!$api || $trackingNo === '') {
        return '';
    }
    if (stripos($trackingNo, 'XDE-') === 0 || stripos($trackingNo, 'XDEX') === 0) {
        return 'XDE update: Local placeholder tracking (no live status yet).';
    }
    if ($xdeBaseUrl === '' || $xdeApiKey === '' || $xdeToken === '') {
        return '';
    }
    try {
        $res = $api->getStatus(
            $xdeBaseUrl,
            $xdeApiKey,
            $xdeToken,
            $trackingNo
        );
        $httpCode = (int)($res['http_code'] ?? 0);
        if ($httpCode < 200 || $httpCode >= 300) {
            return '';
        }
        $payload = $res['response'] ?? null;
        if (!is_array($payload)) {
            return '';
        }

        $pick = static function (array $arr, array $keys): string {
            foreach ($keys as $k) {
                if (array_key_exists($k, $arr) && trim((string)$arr[$k]) !== '') {
                    return trim((string)$arr[$k]);
                }
            }
            return '';
        };

        $status = $pick($payload, ['status','shipment_status','delivery_status','latest_status','current_status','state']);
        $location = $pick($payload, ['location','current_location','hub','branch','area']);
        $updated = $pick($payload, ['updated_at','timestamp','event_date','last_update','date']);
        $remarks = $pick($payload, ['remarks','description','message']);

        $parts = [];
        if ($status !== '') { $parts[] = $status; }
        if ($location !== '') { $parts[] = 'at '.$location; }
        if ($updated !== '') { $parts[] = $updated; }
        if (empty($parts) && $remarks !== '') {
            $parts[] = $remarks;
        }

        return !empty($parts) ? ('XDE update: '.implode(' | ', $parts).'.') : '';
    } catch (Throwable) {
        return '';
    }
};

$mapProductCards = function ($res, string $baseUrl, string $fallbackImage) use ($slugify, $buildProductUrl): array {
    $cards = [];
    if (!$res) {
        return $cards;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $pdId = (int)($row['pd_id'] ?? 0);
        $pdName = trim((string)($row['pd_name'] ?? ''));
        if ($pdName !== '') {
            $pdName = html_entity_decode($pdName, ENT_QUOTES, 'UTF-8');
            $pdName = str_replace(['&nbsp;', '&amp;nbsp;', '&quot;', '&amp;quot;'], ' ', $pdName);
            $pdName = str_replace(["\xc2\xa0", "\xa0"], ' ', $pdName);
            $pdName = trim(preg_replace('/\s+/', ' ', $pdName));
        }
        $filename = trim((string)($row['pp_filename'] ?? ''));
        $imgUrl = $fallbackImage;
        if ($filename !== '') {
            $imgUrl = $baseUrl . '/product_img/' . rawurlencode($filename);
        }
        $slug = $slugify($pdName);
        $url = $baseUrl;
        if ($pdId > 0 && $pdName !== '') {
            $url = $buildProductUrl($baseUrl, $slug, (int) $pdId);
        }
        $priceNum = (float)($row['min_price'] ?? 0);
        if ($pdName === '' || $priceNum <= 0) {
            continue;
        }
        $descRaw = trim((string)($row['pd_description'] ?? ''));
        $descText = '';
        if ($descRaw !== '') {
            $decoded = html_entity_decode($descRaw, ENT_QUOTES, 'UTF-8');
            $decoded = str_replace(['&nbsp;', '&amp;nbsp;'], ' ', $decoded);
            $decoded = str_replace(["\xc2\xa0", "\xa0"], ' ', $decoded);
            $descText = trim(preg_replace('/\s+/', ' ', strip_tags($decoded)));
            if (strlen($descText) > 140) {
                $descText = substr($descText, 0, 137) . '...';
            }
        }
        $priceDecimals = (abs($priceNum - floor($priceNum)) < 0.00001) ? 0 : 2;
        $cards[] = [
            'name' => $pdName,
            'price' => 'PHP ' . number_format($priceNum, $priceDecimals),
            'description' => $descText,
            'image' => $imgUrl,
            'url' => $url
        ];
    }
    return $cards;
};

$detectedBrandId = 0;
$detectedBrandName = '';
$brandRows = $db->query("SELECT pb_id, pb_name FROM tbl_product_brand");
if ($brandRows) {
    $bestLen = 0;
    while ($br = mysqli_fetch_assoc($brandRows)) {
        $brandId = (int)($br['pb_id'] ?? 0);
        $brandName = trim((string)($br['pb_name'] ?? ''));
        $brandNamePlain = html_entity_decode($brandName, ENT_QUOTES, 'UTF-8');
        if ($brandId <= 0 || $brandName === '') {
            continue;
        }
        $brandNeedle = strtolower($brandNamePlain);
        if (strpos($qLower, $brandNeedle) !== false) {
            $len = strlen($brandNeedle);
            if ($len > $bestLen) {
                $bestLen = $len;
                $detectedBrandId = $brandId;
                $detectedBrandName = $brandNamePlain;
            }
        }
    }
}

$brandSqlFilter = $detectedBrandId > 0 ? (" AND p.pd_brand_type=" . (int)$detectedBrandId . " ") : "";
$aiCleanNameFilter = " AND LOWER(TRIM(p.pd_name)) NOT REGEXP '^(test|sample|demo)[0-9 _-]*$' ";

$buildSearchTokens = static function (string $text): array {
    $clean = strtolower($text);
    $clean = preg_replace('/[^a-z0-9\s]/', ' ', $clean);
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    if ($clean === '') {
        return [];
    }
    $stop = ['the','and','for','with','from','this','that','your','you','show','need','want','find','give','me','please','item','items','product','products','price','cost','php','peso','pesos','best','seller','recommended','recommend','cheap','low','lowest','high','highest','under','over','below','above'];
    $parts = explode(' ', $clean);
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || strlen($p) < 3 || in_array($p, $stop, true)) {
            continue;
        }
        $out[] = $p;
    }
    return array_values(array_unique($out));
};

$getExactOrClosestProduct = static function (mysqli $dbConn, string $rawQuery, string $brandSqlFilter, string $aiCleanNameFilter, callable $mapFn, string $baseUrl, string $fallbackImage, callable $tokenFn): array {
    $q = trim($rawQuery);
    if ($q === '') {
        return [];
    }
    $safeExact = $dbConn->real_escape_string($q);
    $resExact = $dbConn->query(
        "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
         FROM tbl_product p
         JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
         LEFT JOIN (
             SELECT pp_pdid, MIN(pp_id) AS min_pp_id
             FROM tbl_product_photo
             GROUP BY pp_pdid
         ) fp ON fp.pp_pdid=p.pd_id
         LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
         WHERE p.pd_status=1
         AND v.pvd_price>0
         ".$brandSqlFilter."
         ".$aiCleanNameFilter."
         AND LOWER(TRIM(p.pd_name)) = LOWER(TRIM('".$safeExact."'))
         GROUP BY p.pd_id, p.pd_name, pp.pp_filename
         HAVING min_price>0
         LIMIT 1"
    );
    $cards = $mapFn($resExact, $baseUrl, $fallbackImage);
    if (!empty($cards)) {
        return $cards;
    }

    $tokens = $tokenFn($q);
    if (empty($tokens)) {
        return [];
    }
    $whereParts = [];
    foreach ($tokens as $t) {
        $safe = $dbConn->real_escape_string($t);
        $whereParts[] = "LOWER(p.pd_name) LIKE LOWER('%".$safe."%')";
    }
    $whereSql = !empty($whereParts) ? (' AND ' . implode(' AND ', $whereParts)) : '';
    $safeFull = $dbConn->real_escape_string(strtolower($q));
    $res = $dbConn->query(
        "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
         FROM tbl_product p
         JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
         LEFT JOIN (
             SELECT pp_pdid, MIN(pp_id) AS min_pp_id
             FROM tbl_product_photo
             GROUP BY pp_pdid
         ) fp ON fp.pp_pdid=p.pd_id
         LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
         WHERE p.pd_status=1
         AND v.pvd_price>0
         ".$brandSqlFilter."
         ".$aiCleanNameFilter."
         ".$whereSql."
         GROUP BY p.pd_id, p.pd_name, pp.pp_filename
         HAVING min_price>0
         ORDER BY (LOWER(p.pd_name)=LOWER('".$safeFull."')) DESC, p.pd_sales DESC, p.pd_id DESC
         LIMIT 1"
    );
    return $mapFn($res, $baseUrl, $fallbackImage);
};

$getPriceSortedCards = static function (mysqli $dbConn, string $brandSqlFilter, string $aiCleanNameFilter, string $order, int $limit, callable $mapFn, string $baseUrl, string $fallbackImage): array {
    $safeOrder = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    $safeLimit = $limit > 0 ? $limit : 3;
    $res = $dbConn->query(
        "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
         FROM tbl_product p
         JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
         LEFT JOIN (
             SELECT pp_pdid, MIN(pp_id) AS min_pp_id
             FROM tbl_product_photo
             GROUP BY pp_pdid
         ) fp ON fp.pp_pdid=p.pd_id
         LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
         WHERE p.pd_status=1
         AND v.pvd_price>0
         ".$brandSqlFilter."
         ".$aiCleanNameFilter."
         GROUP BY p.pd_id, p.pd_name, pp.pp_filename
         HAVING min_price>0
         ORDER BY min_price ".$safeOrder.", p.pd_id DESC
         LIMIT ".$safeLimit
    );
    return $mapFn($res, $baseUrl, $fallbackImage);
};

$getPriceRangeCards = static function (mysqli $dbConn, float $minBudget, float $maxBudget, string $brandSqlFilter, string $aiCleanNameFilter, int $limit, callable $mapFn, string $baseUrl, string $fallbackImage): array {
    $safeLimit = $limit > 0 ? $limit : 5;
    $safeMin = $minBudget > 0 ? $minBudget : 0;
    $safeMax = $maxBudget > 0 ? $maxBudget : $safeMin;
    if ($safeMax < $safeMin) {
        $tmp = $safeMin;
        $safeMin = $safeMax;
        $safeMax = $tmp;
    }

    $res = $dbConn->query(
        "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
         FROM tbl_product p
         JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
         LEFT JOIN (
             SELECT pp_pdid, MIN(pp_id) AS min_pp_id
             FROM tbl_product_photo
             GROUP BY pp_pdid
         ) fp ON fp.pp_pdid=p.pd_id
         LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
         WHERE p.pd_status=1
         AND v.pvd_price>0
         ".$brandSqlFilter."
         ".$aiCleanNameFilter."
         GROUP BY p.pd_id, p.pd_name, pp.pp_filename
         HAVING min_price >= ".$safeMin." AND min_price <= ".$safeMax."
         ORDER BY min_price ASC, p.pd_sales DESC, p.pd_id DESC
         LIMIT ".$safeLimit
    );
    return $mapFn($res, $baseUrl, $fallbackImage);
};

$getTopRatedCards = static function (mysqli $dbConn, string $brandSqlFilter, string $aiCleanNameFilter, int $limit, callable $mapFn, string $baseUrl, string $fallbackImage): array {
    $safeLimit = $limit > 0 ? $limit : 5;
    $res = $dbConn->query(
        "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description, r.avg_rating, r.review_count
         FROM tbl_product p
         JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
         JOIN (
             SELECT pr_product_id, AVG(pr_rating) AS avg_rating, COUNT(*) AS review_count
             FROM tbl_product_reviews
             WHERE pr_status=1
             GROUP BY pr_product_id
         ) r ON r.pr_product_id=p.pd_id
         LEFT JOIN (
             SELECT pp_pdid, MIN(pp_id) AS min_pp_id
             FROM tbl_product_photo
             GROUP BY pp_pdid
         ) fp ON fp.pp_pdid=p.pd_id
         LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
         WHERE p.pd_status=1
         AND v.pvd_price>0
         ".$brandSqlFilter."
         ".$aiCleanNameFilter."
         GROUP BY p.pd_id, p.pd_name, pp.pp_filename, r.avg_rating, r.review_count
         HAVING min_price>0
         ORDER BY r.avg_rating DESC, r.review_count DESC, p.pd_sales DESC, p.pd_id DESC
         LIMIT ".$safeLimit
    );
    return $mapFn($res, $baseUrl, $fallbackImage);
};

$getBestSellingCards = static function (mysqli $dbConn, string $brandSqlFilter, int $limit, callable $mapFn, string $baseUrl, string $fallbackImage): array {
    $safeLimit = $limit > 0 ? $limit : 5;
    $res = $dbConn->query(
        "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
         FROM tbl_product p
         JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
         LEFT JOIN (
             SELECT pp_pdid, MIN(pp_id) AS min_pp_id
             FROM tbl_product_photo
             GROUP BY pp_pdid
         ) fp ON fp.pp_pdid=p.pd_id
         LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
         WHERE p.pd_status=1
         ".$brandSqlFilter."
         GROUP BY p.pd_id, p.pd_name, pp.pp_filename
         ORDER BY p.pd_sales DESC, p.pd_id DESC
         LIMIT ".$safeLimit
    );
    return $mapFn($res, $baseUrl, $fallbackImage);
};

$getTopicCards = static function (mysqli $dbConn, array $terms, string $brandSqlFilter, string $aiCleanNameFilter, int $limit, callable $mapFn, string $baseUrl, string $fallbackImage): array {
    $safeLimit = $limit > 0 ? $limit : 4;
    $whereParts = [];
    $scoreParts = [];
    foreach ($terms as $term) {
        $kw = trim((string)$term);
        if ($kw === '') {
            continue;
        }
        $safeKw = $dbConn->real_escape_string($kw);
        $whereParts[] = "LOWER(p.pd_name) LIKE LOWER('%".$safeKw."%')";
        $whereParts[] = "LOWER(c.cat_name) LIKE LOWER('%".$safeKw."%')";
        $whereParts[] = "LOWER(cs.subcat_name) LIKE LOWER('%".$safeKw."%')";
        $whereParts[] = "LOWER(i.item_name) LIKE LOWER('%".$safeKw."%')";

        // Score title matches highest, then item/subcategory/category.
        $scoreParts[] = "CASE WHEN LOWER(p.pd_name) LIKE LOWER('%".$safeKw."%') THEN 6 ELSE 0 END";
        $scoreParts[] = "CASE WHEN LOWER(i.item_name) LIKE LOWER('%".$safeKw."%') THEN 4 ELSE 0 END";
        $scoreParts[] = "CASE WHEN LOWER(cs.subcat_name) LIKE LOWER('%".$safeKw."%') THEN 3 ELSE 0 END";
        $scoreParts[] = "CASE WHEN LOWER(c.cat_name) LIKE LOWER('%".$safeKw."%') THEN 2 ELSE 0 END";
    }
    if (empty($whereParts)) {
        return [];
    }
    $scoreSql = !empty($scoreParts) ? implode(' + ', $scoreParts) : '0';
    $res = $dbConn->query(
        "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description, (".$scoreSql.") AS match_score
         FROM tbl_product p
         JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
         LEFT JOIN tbl_category c ON c.cat_id=p.pd_catid
         LEFT JOIN tbl_categorysub cs ON cs.subcat_id=p.pd_catsubid
         LEFT JOIN tbl_categoryitem i ON i.item_id=p.pd_catsubid2
         LEFT JOIN (
             SELECT pp_pdid, MIN(pp_id) AS min_pp_id
             FROM tbl_product_photo
             GROUP BY pp_pdid
         ) fp ON fp.pp_pdid=p.pd_id
         LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
         WHERE p.pd_status=1
         AND v.pvd_price>0
         ".$brandSqlFilter."
         ".$aiCleanNameFilter."
         AND (".implode(' OR ', $whereParts).")
         GROUP BY p.pd_id, p.pd_name, pp.pp_filename
         HAVING min_price>0
         ORDER BY match_score DESC, p.pd_sales DESC, p.pd_id DESC
         LIMIT ".$safeLimit
    );
    return $mapFn($res, $baseUrl, $fallbackImage);
};

$getActiveBrandCount = static function (mysqli $dbConn): int {
    $res = $dbConn->query(
        "SELECT COUNT(DISTINCT p.pd_brand_type) AS brand_count
         FROM tbl_product p
         JOIN tbl_product_brand b ON b.pb_id=p.pd_brand_type
         WHERE p.pd_status=1
         AND p.pd_brand_type>0"
    );
    if ($res && $res->num_rows > 0) {
        $row = mysqli_fetch_assoc($res);
        return max(0, (int)($row['brand_count'] ?? 0));
    }
    return 0;
};

$getTopActiveBrands = static function (mysqli $dbConn, int $limit = 10): array {
    $safeLimit = $limit > 0 ? $limit : 10;
    $res = $dbConn->query(
        "SELECT b.pb_id, b.pb_name, COUNT(*) AS product_count
         FROM tbl_product p
         JOIN tbl_product_brand b ON b.pb_id=p.pd_brand_type
         WHERE p.pd_status=1
         AND p.pd_brand_type>0
         AND TRIM(IFNULL(b.pb_name,''))<>''
         GROUP BY b.pb_id, b.pb_name
         ORDER BY product_count DESC, b.pb_name ASC
         LIMIT ".$safeLimit
    );
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $name = trim((string)($row['pb_name'] ?? ''));
            if ($name !== '') {
                $out[] = [
                    'id' => (int)($row['pb_id'] ?? 0),
                    'name' => $name,
                    'product_count' => (int)($row['product_count'] ?? 0)
                ];
            }
        }
    }
    return $out;
};

try {
    if (isset($faqMap[$qNormSimple])) {
        $reply = $faqMap[$qNormSimple];
        $quickReplies = ['Track my order', 'Payment methods', 'Contact support'];
    } else {
        $matchedFaq = '';
        foreach ($faqMap as $key => $ans) {
            if ($key !== '' && strpos($qNormSimple, $key) !== false) {
                $matchedFaq = $ans;
                break;
            }
        }
        if ($matchedFaq !== '') {
            $reply = $matchedFaq;
            $quickReplies = ['Track my order', 'Payment methods', 'Contact support'];
        } else {
            $specificCards = [];
            $tokens = $buildSearchTokens($question);
            if (count($tokens) >= 2 || strlen($question) >= 8) {
                $specificCards = $getExactOrClosestProduct(
                    $db,
                    $question,
                    $brandSqlFilter,
                    $aiCleanNameFilter,
                    $mapProductCards,
                    $baseUrl,
                    $fallbackImage,
                    $buildSearchTokens
                );
            }
            if (!empty($specificCards)) {
                $productCards = $specificCards;
                $reply = 'Here is the product you searched.';
                $quickReplies = ['Product specifications', 'Track my order', 'Contact support'];
            } elseif (preg_match('/\b(minimalist|minimalist style)\b/i', $qLower)) {
    
        $reply = "Minimalist style focuses on clean lines, neutral tones, and functional pieces.\nRecommended items:\n- Melo Reversible Fabric Sofa Set\n- Orla Fabric Sofa Bed\n- Flow Bench Sofa\n- Simple side tables, shelves, coffee tables, and console tables.";
        $productCards = $getTopicCards($db, ['minimalist','sofa','bench','coffee table','console table','side table','shelf'], $brandSqlFilter, $aiCleanNameFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
        $quickReplies = ['Suggest items under PHP 5,000.', 'Can you recommend a sofa for small spaces?', 'Show me trending home decor.'];
    } elseif (preg_match('/\b(suggest|items?)\b.*\b(under|below|less than)\b.*\b(5000|5,000|php)\b|\bunder\s*5000\b/i', $qLower)) {
        $productCards = $getPriceRangeCards($db, 1, 5000, $brandSqlFilter, $aiCleanNameFilter, 8, $mapProductCards, $baseUrl, $fallbackImage);
        $reply = !empty($productCards)
            ? 'Here are available items under PHP 5,000 based on current products.'
            : 'I could not find available items under PHP 5,000 right now. Please try again later.';
        $quickReplies = ['What is best for office setup at home?', 'What are your best-selling living room products?', 'Do you have items on sale right now?'];
    } elseif (preg_match('/\b(office setup|home office|office at home|work from home)\b/i', $qLower)) {
        $reply = "For a home office setup, good choices are:\n- Affordahome Office Table / Study Table\n- Affordahome Office Chair (A5 / A6)\n- Affordahome Laptop Table\n- Monitor stand and desk organizers.";
        $productCards = $getTopicCards($db, ['office table','study table','office chair','laptop table','monitor stand','desk organizer'], $brandSqlFilter, $aiCleanNameFilter, 6, $mapProductCards, $baseUrl, $fallbackImage);
        $quickReplies = ['Suggest items under PHP 5,000.', 'What is the highest-rated product?', 'Show me trending home decor.'];
    } elseif (preg_match('/\b(highest-rated|highest rated|top rated|best rated)\b/i', $qLower)) {
        $productCards = $getTopRatedCards($db, $brandSqlFilter, $aiCleanNameFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $reply = 'Here are our highest-rated products based on customer reviews.';
        } else {
            $productCards = $getBestSellingCards($db, $brandSqlFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
            $reply = !empty($productCards)
                ? 'We do not have enough ratings yet, so here are our current best-sellers instead.'
                : 'I cannot find top-rated products right now. Please try again later.';
        }
        $quickReplies = ['What are your best-selling living room products?', 'Can you recommend a sofa for small spaces?', 'Do you have items on sale right now?'];
    } elseif (preg_match('/\b(low stock|low in stock|stock status|stock)\b/i', $qLower)) {
        $reply = 'Most items are on-demand, so the majority of products are usually available. For a specific item, send the exact product name and I will check availability.';
        $quickReplies = ['What is the highest-rated product?', 'Do you have items on sale right now?', 'How can I track my order?'];
    } elseif (preg_match('/\b(trending home decor|home decor trend|trending decor)\b/i', $qLower)) {
        $reply = "Trending home decor now:\n- Ceramic vases in neutral tones\n- Abstract wall art and photo frames\n- Minimalist LED desk lamps\n- Indoor planters with stand\n- Textured throw blankets.";
        $productCards = $getTopicCards($db, ['vase','wall art','photo frame','lamp','planter','throw blanket','decor'], $brandSqlFilter, $aiCleanNameFilter, 6, $mapProductCards, $baseUrl, $fallbackImage);
        $quickReplies = ['What products match a minimalist style?', 'Can you recommend a sofa for small spaces?', 'Suggest items under PHP 5,000.'];
    } elseif (preg_match('/\b(received the wrong item|wrong item|incorrect item|wrong order)\b/i', $qLower)) {
        $reply = "We are sorry for the inconvenience. Please send:\n- A photo of the wrong item\n- Your order number\nWe will replace it free of charge or issue a full refund.";
        $quickReplies = ['What happens if my item arrives damaged?', 'How can I track my order?', 'What courier do you use?'];
    } elseif (preg_match('/\b(gcash|online banking|bank transfer|credit\/debit|credit card|debit card)\b/i', $qLower)) {
        $reply = "Yes, we accept:\n- GCash\n- Online Banking / Bank Transfer\n- Credit/Debit Cards\nPayment details are shown at checkout and in your invoice.";
        $quickReplies = ['How can I track my order?', 'What courier do you use?', 'What if I received the wrong item?'];
    } elseif (preg_match('/\b(how can i track my order|track my order|order tracking)\b/i', $qLower)) {
        $reply = "Once shipped, you will receive:\n- A tracking number via SMS or Email\n- Tracking access on the courier website or in your account.";
        $quickReplies = ['What courier do you use?', 'What happens if my item arrives damaged?', 'Do you accept GCash or online banking?'];
    } elseif (preg_match('/\b(arrives damaged|damaged item|damaged product)\b/i', $qLower)) {
        $reply = "We will replace or refund it. Please:\n1. Send a photo of the damaged area.\n2. Provide your order number.\nWe will handle the rest at no extra cost.";
        $quickReplies = ['What if I received the wrong item?', 'How can I track my order?', 'What courier do you use?'];
    } elseif (preg_match('/\b(what courier|courier do you use|shipping partner|delivery partner)\b/i', $qLower)) {
        $reply = "We ship via trusted partners such as:\n- SPX\n- J&T\n- XDE\n- AF Home Fleet\nCourier depends on your location and order size.";
        $quickReplies = ['How can I track my order?', 'What happens if my item arrives damaged?', 'Do you accept GCash or online banking?'];
    } elseif (preg_match('/\b(sofa for small spaces|small space sofa|small spaces)\b/i', $qLower)) {
        $reply = "Best choices for small spaces:\n- Melo Reversible Fabric Sofa Set\n- Orla Fabric Sofa Bed\n- Flow Bench Sofa.";
        $productCards = $getTopicCards($db, ['melo sofa','orla sofa bed','flow bench sofa','compact sofa','sofa bed'], $brandSqlFilter, $aiCleanNameFilter, 6, $mapProductCards, $baseUrl, $fallbackImage);
        $quickReplies = ['What products match a minimalist style?', 'What are your best-selling living room products?', 'Suggest items under PHP 5,000.'];
    } elseif (preg_match('/\b(best-selling living room|best selling living room|living room best seller)\b/i', $qLower)) {
        $reply = "Best-sellers in living room:\n- L-shape sofas and sofa sets\n- Sofa beds\n- Accent chairs\n- Coffee tables\n- Throw pillows.";
        $productCards = $getTopicCards($db, ['living room','sofa','sofa bed','accent chair','coffee table','throw pillow'], $brandSqlFilter, $aiCleanNameFilter, 6, $mapProductCards, $baseUrl, $fallbackImage);
        $quickReplies = ['Can you recommend a sofa for small spaces?', 'Do you have items on sale right now?', 'What is the highest-rated product?'];
    } elseif (preg_match('/\b(items on sale|on sale right now|sale right now)\b/i', $qLower)) {
        $reply = 'We will announce sale promos coming soon.';
        $quickReplies = ['Suggest items under PHP 5,000.', 'What are your best-selling living room products?', 'Show me trending home decor.'];
    } elseif (preg_match('/\b(i need some help|help me with something|i have a question|question about a product)\b/i', $qLower)) {
        $helpReplies = [
            'Absolutely. I am ready to help. Tell me what you need: product details, shipping, payments, or order tracking.',
            'Of course. Please share your concern and I will assist right away with products, payments, shipping, or orders.'
        ];
        $reply = $helpReplies[array_rand($helpReplies)];
        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
    } elseif (preg_match('/\b(how are you|how\'?s it going)\b/i', $qLower)) {
        $greetReplies = [
            'I am doing great and ready to help. What do you need today?',
            'I am good, thanks for asking. How can I assist you today?'
        ];
        $reply = $greetReplies[array_rand($greetReplies)];
        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
    } elseif (preg_match('/\b(are you there|are you available|are you working)\b/i', $qLower)) {
        $availReplies = [
            'Yes, I am here and active. You can ask me about products, shipping, payments, and orders.',
            'Yes, I am available now. Send your question and I will help right away.'
        ];
        $reply = $availReplies[array_rand($availReplies)];
        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
    } elseif (preg_match('/\b(hi|hello|hey|hi there|assistant|chatbot|ai|good morning|good afternoon|good evening)\b/i', $qLower)) {
        $helloReplies = [
            'Hi! Welcome. I can help with product details, shipping, payment options, and order tracking.',
            'Hello! I am ShopBuddy AI. Ask me anything about products, checkout, delivery, or your orders.'
        ];
        $reply = $helloReplies[array_rand($helloReplies)];
        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
    } elseif (preg_match('/\b(authentic|original|genuine)\b/i', $qLower)) {
        $reply = 'Our products are sourced from authorized suppliers and official brands. For a specific item, share the exact product name and I will help verify its listing details.';
        $quickReplies = ['Product specifications', 'Warranty', 'Customer reviews'];
    } elseif (preg_match('/\b(customer reviews?|reviews?|ratings?)\b/i', $qLower)) {
        $reply = 'You can check product reviews and ratings on the product page. If you share the product name, I can help you open the correct listing.';
        $quickReplies = ['Product specifications', 'Similar products', 'Best product'];
    } elseif (preg_match('/\b(show brands?|show brand|list brands?|brand list)\b/i', $qLower) || preg_match('/\b(how many brands?|total number of brands?|different brands?|unique brands?|count of brands?|brand names? does this shop include|distinct brands? does this store stock|number of brands listed|brands can customers choose from|brands are available for purchase|brands do you currently sell|list the number of brands|brand categories are represented|different brand labels|total number of labels\/brands|retrieve the number of brands|brand count .*database|brand entities .*seller|display the total brands|count all brands)\b/i', $qLower)) {
        $brandCount = $getActiveBrandCount($db);
        if ($brandCount > 0) {
            $topBrands = $getTopActiveBrands($db, 10);
            if (!empty($topBrands)) {
                $brandNames = [];
                foreach ($topBrands as $br) {
                    $brandId = (int)($br['id'] ?? 0);
                    $brandName = trim((string)($br['name'] ?? ''));
                    $brandNamePlain = html_entity_decode($brandName, ENT_QUOTES, 'UTF-8');
                    if ($brandName === '') {
                        continue;
                    }
                    $brandNames[] = $brandNamePlain;
                    $brandSlug = str_replace([' ', '.'], '', $brandNamePlain);
                    $brandUrl = $baseUrl;
                    if ($brandId > 0) {
                        $brandUrl = $baseUrl . '/by-brand';
                    }
                    $brandCards[] = [
                        'name' => $brandNamePlain,
                        'count' => (int)($br['product_count'] ?? 0),
                        'url' => $brandUrl
                    ];
                }
                $brandViewAllUrl = $baseUrl . '/all-brands';
                $reply = 'This shop currently offers '.$brandCount.' active brands. Top brands: '.implode(', ', array_filter($brandNames)).'.';
            } else {
                $reply = 'This shop currently offers '.$brandCount.' active brands.';
            }
        } else {
            $reply = 'I cannot find active brand records right now.';
        }
        $quickReplies = ['Show brands', 'Best product', 'Show lowest price'];
    } elseif (preg_match('/\b(can you recommend(?: the)? products?(?: for me)?|recommend (?:a )?product(?: for me)?|do you have products? that you can recommend)\b/i', $qLower)) {
        $res = $db->query(
            "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
             FROM tbl_product p
             JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
             LEFT JOIN (
                 SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                 FROM tbl_product_photo
                 GROUP BY pp_pdid
             ) fp ON fp.pp_pdid=p.pd_id
             LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
             WHERE p.pd_status=1
             AND v.pvd_price>0
             ".$brandSqlFilter."
             ".$aiCleanNameFilter."
             GROUP BY p.pd_id, p.pd_name, pp.pp_filename
             HAVING min_price>0
             ORDER BY p.pd_sales DESC, min_price ASC, p.pd_id DESC
             LIMIT 6"
        );
        $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $reply = 'Here are some of the best products in our shop right now.';
        } else {
            $reply = 'I can recommend products. Please share your budget and preferred category so I can suggest better matches.';
        }
        $quickReplies = ['Best product under ₱5,000', 'Show lowest price', 'Best product'];
    } elseif (preg_match('/\b(similar products?|show similar|recommend products?|suggest products?)\b/i', $qLower)) {
        $reply = 'I can recommend similar products. Share the product name, your budget, and preferred brand so I can suggest better matches.';
        $quickReplies = ['Best product under ₱5,000', 'Show lowest price', 'Best product'];
    } elseif (
        preg_match('/\b(?:between|from)\s*(?:php|p|₱)?\s*(\d[\d,\.]*)\s*(?:to|and|-)\s*(?:php|p|₱)?\s*(\d[\d,\.]*)\b/i', $question, $mRange)
        || preg_match('/\b(?:php|p|₱)?\s*(\d[\d,\.]*)\s*(?:to|and|-)\s*(?:php|p|₱)?\s*(\d[\d,\.]*)\b/i', $question, $mRange)
    ) {
        $minBudget = (float)str_replace([',', ' '], '', (string)$mRange[1]);
        $maxBudget = (float)str_replace([',', ' '], '', (string)$mRange[2]);
        if ($minBudget > 0 && $maxBudget > 0) {
            $productCards = $getPriceRangeCards($db, $minBudget, $maxBudget, $brandSqlFilter, $aiCleanNameFilter, 6, $mapProductCards, $baseUrl, $fallbackImage);
            if (!empty($productCards)) {
                $reply = 'Here are products within your budget range.';
            } else {
                $reply = 'I could not find products in that range right now. Try widening the range.';
            }
        } else {
            $reply = 'Please enter a valid range like "1,500 to 3,000".';
        }
        $quickReplies = ['Show lowest price', 'Best product', 'Similar products'];
    } elseif (preg_match('/\b(lower(?:\s+price)?(?:\s+than)?|below(?:\s+price)?|less\s+than|up\s*to)\b/i', $qLower) && preg_match('/(\d[\d,\.]*)/', $question, $mBudget)) {
        $budget = (float)str_replace([',', ' '], '', (string)$mBudget[1]);
        if ($budget > 0) {
            $safeBudget = (float)$budget;
            $res = $db->query(
                "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
                 FROM tbl_product p
                 JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
                 LEFT JOIN (
                     SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                     FROM tbl_product_photo
                     GROUP BY pp_pdid
                 ) fp ON fp.pp_pdid=p.pd_id
                 LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
                 WHERE p.pd_status=1
                 AND v.pvd_price>0
                 ".$brandSqlFilter."
                 ".$aiCleanNameFilter."
                 GROUP BY p.pd_id, p.pd_name, pp.pp_filename
                 HAVING min_price > 0 AND min_price <= ".$safeBudget."
                 ORDER BY min_price ASC, p.pd_sales DESC
                 LIMIT 5"
            );
            $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
            if (!empty($productCards)) {
                $reply = 'Here are products lower than your target budget.';
            } else {
                $reply = 'I could not find products under that amount right now. Try a slightly higher budget.';
            }
        } else {
            $reply = 'Please enter a valid amount like "lower than 1,000".';
        }
        $quickReplies = ['Show lowest price', 'Best product', 'Similar products'];
    } elseif (preg_match('/\b(what are the specifications|specifications?|specs?|sizes?|colors?|available variants?|what sizes|what colors)\b/i', $qLower)) {
        $reply = 'For exact specs, sizes, and colors, open the product page and check the variations/details section. If you send the product name, I can guide you faster.';
        $quickReplies = ['Is this item in stock?', 'Warranty', 'Similar products'];
    } elseif (preg_match('/\b(in stock|available|stock available|out of stock)\b/i', $qLower)) {
        $reply = 'Live stock depends on selected variation (size/color). Please select the exact variant on the product page to see current availability.';
        $quickReplies = ['Product price', 'Sizes and colors', 'Track my order'];
    } elseif (preg_match('/\b(warranty|guarantee)\b/i', $qLower)) {
        $reply = 'Warranty coverage depends on product type and brand policy. Please check the product page warranty section or share the product name so I can help you verify.';
        $quickReplies = ['Product specifications', 'Return policy', 'Contact support'];
    } elseif (preg_match('/\b(good for|best for|use case|for gaming|for office|for bedroom|for kitchen)\b/i', $qLower)) {
        $reply = 'I can help match products for your use case. Tell me what you will use it for, your budget, and preferred brand.';
        $quickReplies = ['Recommend products', 'Best product under ₱5,000', 'Similar products'];
    } elseif (preg_match('/\b(best product under|under\s*₱?\s*\d+|budget\s*₱?\s*\d+)\b/i', $qLower)) {
        $budget = 0;
        if (preg_match('/(\d[\d,\.]*)/', $question, $mBudget)) {
            $budget = (float)str_replace([',', ' '], '', (string)$mBudget[1]);
        }
        if ($budget > 0) {
            $safeBudget = (float)$budget;
            $res = $db->query(
                "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
                 FROM tbl_product p
                 JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
                 LEFT JOIN (
                     SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                     FROM tbl_product_photo
                     GROUP BY pp_pdid
                 ) fp ON fp.pp_pdid=p.pd_id
                 LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
                 WHERE p.pd_status=1
                 AND v.pvd_price>0
                 ".$brandSqlFilter."
                 ".$aiCleanNameFilter."
                 GROUP BY p.pd_id, p.pd_name, pp.pp_filename
                 HAVING min_price > 0 AND min_price <= ".$safeBudget."
                 ORDER BY p.pd_sales DESC, min_price ASC
                 LIMIT 5"
            );
            $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
            if (!empty($productCards)) {
                $reply = 'Here are recommended products within your budget.';
            } else {
                $reply = 'I could not find products in that budget right now. You can increase the budget slightly and I will suggest more options.';
            }
        } else {
            $reply = 'Please share your budget amount (for example: best product under ₱5,000).';
        }
        $quickReplies = ['Show lowest price', 'Best product', 'Similar products'];
    } elseif (preg_match('/\b(which one is better|better:\s*|compare)\b/i', $qLower)) {
        $reply = 'I can compare products by price, specifications, and use case. Please provide two exact product names so I can give a clear recommendation.';
        $quickReplies = ['Product A vs Product B', 'Best product', 'Similar products'];
    } elseif (preg_match('/\b(trending products?|popular now|best sellers? right now)\b/i', $qLower)) {
        $res = $db->query(
            "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
             FROM tbl_product p
             JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
             LEFT JOIN (
                 SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                 FROM tbl_product_photo
                 GROUP BY pp_pdid
             ) fp ON fp.pp_pdid=p.pd_id
             LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
             WHERE p.pd_status=1
                 AND v.pvd_price>0
             ".$brandSqlFilter."
             ".$aiCleanNameFilter."
             GROUP BY p.pd_id, p.pd_name, pp.pp_filename
             ORDER BY p.pd_sales DESC, p.pd_id DESC
             LIMIT 5"
        );
        $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
        $reply = !empty($productCards) ? 'Here are trending products right now.' : 'Trending products are not available at the moment.';
        $quickReplies = ['Best product', 'Show lowest price', 'Track my order'];
    } elseif (preg_match('/\b(compatible|compatibility|works with|supported device)\b/i', $qLower)) {
        $reply = 'Compatibility depends on the exact model/specification. Please share your device/model so I can help check compatibility.';
        $quickReplies = ['Product specifications', 'Compare products', 'Contact support'];
    } elseif (preg_match('/\b(what payment methods|payment methods?|accept payment|digital wallets?)\b/i', $qLower)) {
        $methods = [];
        $res = $db->query("SELECT p_name FROM tbl_payment WHERE (p_status=0 OR p_status IS NULL) ORDER BY p_name ASC");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $methods[] = trim((string)($row['p_name'] ?? ''));
            }
        }
        $reply = !empty($methods)
            ? ('Available payment methods: ' . implode(', ', array_filter($methods)) . '.')
            : 'Payment method list is currently unavailable. Please check checkout for the latest options.';
        $quickReplies = ['Is online payment safe?', 'Why was payment declined?', 'Can I pay via COD?'];
    } elseif (preg_match('/\b(cash on delivery|cod)\b/i', $qLower)) {
        $reply = 'Cash on Delivery availability depends on your delivery location and current checkout eligibility. Please check checkout for your address.';
        $quickReplies = ['Payment methods', 'Shipping fee', 'Track my order'];
    } elseif (preg_match('/\b(online payment safe|secure payment|is payment safe)\b/i', $qLower)) {
        $reply = 'Yes, online payment is processed through secured payment gateways. Avoid sharing OTP/PIN and only complete payment on official checkout pages.';
        $quickReplies = ['Payment methods', 'Why was payment declined?', 'Contact support'];
    } elseif (preg_match('/\b(payment declined|payment failed|declined card|failed payment)\b/i', $qLower)) {
        $reply = 'Payment may fail due to incorrect details, insufficient balance, bank restrictions, or timeout. Please retry once, then try another payment method if needed.';
        $quickReplies = ['Payment methods', 'Contact support', 'Track my order'];
    } elseif (preg_match('/\b(installments?|installment)\b/i', $qLower)) {
        $reply = 'Installment availability depends on selected payment channel and issuer. Please proceed to checkout to view eligible installment options.';
        $quickReplies = ['Payment methods', 'Best product under ₱5,000', 'Contact support'];
    } elseif (preg_match('/\b(shipping fee|delivery fee|shipping cost|how much shipping)\b/i', $qLower)) {
        $reply = 'Shipping fee depends on product, weight, courier option, and destination. The exact fee is shown on checkout after selecting delivery details.';
        $quickReplies = ['How long delivery takes?', 'Track my order', 'Free shipping'];
    } elseif (preg_match('/\b(how long delivery|delivery time|shipping time|eta)\b/i', $qLower)) {
        $reply = 'Delivery time depends on your location, courier, and order status. You can track updates using your order number.';
        $quickReplies = ['Track my order', 'Shipping fee', 'Change delivery address'];
    } elseif (preg_match('/\b(free shipping)\b/i', $qLower)) {
        $reply = 'Free shipping may be available during promos or for qualifying orders. Please check current campaign terms at checkout.';
        $quickReplies = ['Promo codes', 'Shipping fee', 'Track my order'];
    } elseif (preg_match('/\b(ship internationally|international shipping|outside philippines)\b/i', $qLower)) {
        $reply = 'International shipping availability depends on destination and courier support. Please contact support with your country and preferred items.';
        $quickReplies = ['Contact support', 'Shipping fee', 'Delivery time'];
    } elseif (preg_match('/\b(change delivery address|change shipping address)\b/i', $qLower)) {
        $reply = 'Delivery address can be changed only before the order is packed. Please contact support immediately with your order number.';
        $quickReplies = ['Track my order', 'Contact support', 'Cancel my order'];
    } elseif (preg_match('/\b(return policy|return policies)\b/i', $qLower)) {
        $reply = 'Return eligibility depends on item condition, timeline, and policy coverage. Please share your order number and issue so support can validate quickly.';
        $quickReplies = ['How do I return an item?', 'Refund time', 'Exchange item'];
    } elseif (preg_match('/\b(how do i return|return an item)\b/i', $qLower)) {
        $reply = 'To return an item, contact support with your order number, product issue, and photos (if damaged). The team will guide the return steps.';
        $quickReplies = ['Return policy', 'Refund time', 'Damaged item'];
    } elseif (preg_match('/\b(refund|refund take|refund time)\b/i', $qLower)) {
        $reply = 'Refund processing time depends on payment method and bank/e-wallet posting time after approval.';
        $quickReplies = ['Return policy', 'Exchange item', 'Contact support'];
    } elseif (preg_match('/\b(exchange instead of refund|exchange item)\b/i', $qLower)) {
        $reply = 'Yes, exchange may be possible if the item is eligible and stock is available. Please contact support with your order number.';
        $quickReplies = ['Return policy', 'Damaged item', 'Contact support'];
    } elseif (preg_match('/\b(damaged item|received damaged|defective)\b/i', $qLower)) {
        $reply = 'Sorry about that. Please report it immediately with order number and clear photos/video so support can process return/replacement quickly.';
        $quickReplies = ['Contact support', 'Return policy', 'Track my order'];
    } elseif (preg_match('/\b(create an account|sign up|register account)\b/i', $qLower)) {
        $registerLink = $baseUrl . '/register';
        $reply = 'You can create an account here: ' . $registerLink . ' . Use a valid email/mobile so you can track orders and receive updates.';
        $quickReplies = ['Forgot password', 'Order history', 'Login'];
    } elseif (preg_match('/\b(forgot password|reset password|recover password)\b/i', $qLower)) {
        $reply = 'Use the Forgot Password option on the login page to reset your password securely.';
        $quickReplies = ['Login', 'Contact support', 'Create an account'];
    } elseif (preg_match('/\b(cancel my order|cancel order)\b/i', $qLower)) {
        $reply = 'Order cancellation is possible only before packing/shipping. Please provide your order number immediately.';
        $quickReplies = ['Track my order', 'Contact support', 'Modify order'];
    } elseif (preg_match('/\b(modify my order|change my order|edit order)\b/i', $qLower)) {
        $reply = 'Order modifications are only possible before packing. Share your order number and requested change so support can verify.';
        $quickReplies = ['Track my order', 'Cancel my order', 'Contact support'];
    } elseif (preg_match('/\b(order history|my orders)\b/i', $qLower)) {
        $reply = $isMember
            ? 'You can view your full order history in your account orders page.'
            : 'Please sign in to view order history. For guest orders, use your order number for tracking.';
        $quickReplies = ['Track my order', 'Login', 'Contact support'];
    } elseif (preg_match('/\b(promo codes?|voucher|discount code|coupons?)\b/i', $qLower)) {
        $reply = 'Promo/voucher availability depends on active campaigns and validity rules. You can apply a valid code on checkout.';
        $quickReplies = ['Can I combine coupons?', 'First-time buyer discount', 'Next sale'];
    } elseif (preg_match('/\b(first[- ]?time buyers?|new customer discount)\b/i', $qLower)) {
        $reply = 'First-time buyer discounts depend on current promotions. Please check active promos or ask support for available offers.';
        $quickReplies = ['Promo codes', 'Next sale', 'Student discount'];
    } elseif (preg_match('/\b(next sale|upcoming sale|when sale)\b/i', $qLower)) {
        $reply = 'Upcoming sale schedules are announced via official channels. Please follow announcements for the latest campaign dates.';
        $quickReplies = ['Promo codes', 'Best product', 'Contact support'];
    } elseif (preg_match('/\b(combine coupons?|stack coupons?)\b/i', $qLower)) {
        $reply = 'Coupon stacking rules depend on promo mechanics. Most vouchers allow one code per order unless explicitly stated.';
        $quickReplies = ['Promo codes', 'Checkout help', 'Contact support'];
    } elseif (preg_match('/\b(student discount)\b/i', $qLower)) {
        $reply = 'Student discounts depend on current campaign availability and validation requirements.';
        $quickReplies = ['Promo codes', 'Contact support', 'Best product under ₱5,000'];
    } elseif (preg_match('/\b(based on my history|my history suggest|what do you suggest)\b/i', $qLower)) {
        $reply = $isMember
            ? 'I can suggest products based on your previous purchases and preferences. Tell me your current budget and category to narrow recommendations.'
            : 'For personalized recommendations, please sign in. You can still share your budget and category, and I can suggest popular items.';
        $quickReplies = ['Recommend products', 'Best product', 'Show lowest price'];
    } elseif (preg_match('/\b(specification|specifications|specs|in stock|available in stock|colors?|sizes?|warranty|compatible|compatibility|more product images|compare.*model|compare to other models)\b/i', $qLower)) {
        $reply = 'For product details like specs, stock, color/size options, warranty, compatibility, and more images, please open the product page and check the full details section. If you share the exact product name, I can help you faster.';
        $quickReplies = ['Product price', 'Best product', 'Track my order'];
    } elseif (preg_match('/\b(discount|promo|promo code|voucher|bulk|wholesale|installment|installments|price higher|price lower|competitor|price drop)\b/i', $qLower)) {
        $reply = 'Discounts and promos depend on current campaigns and voucher availability. For bulk purchase and installment options, please proceed to checkout to see eligible payment methods. You can also ask support for current promos.';
        $quickReplies = ['Payment methods', 'How to use voucher?', 'Contact support'];
        $reply = 'For order status and package tracking, please share your order number. Shipping timeline depends on location and courier. Shipping address changes and cancellations are only possible before the order is packed.';
        } elseif (preg_match('/\b(shipping take|shipping time|delivery time|change shipping address|cancel my order|cancel order|track my package|track package)\b/i', $qLower)) {
        $quickReplies = ['Track my order', 'Contact support', 'Return policy'];
    } elseif (preg_match('/\b(return policy|request a refund|refund|exchange|refund process|return shipping)\b/i', $qLower)) {
        $reply = 'You can request return, refund, or exchange based on order status and policy eligibility. Refund processing time depends on payment method, and return shipping responsibility depends on the return reason.';
        $quickReplies = ['I need help with my order', 'Track my order', 'Contact support'];
    } elseif (preg_match('/\b(payment failing|payment failed|payment secure|secure payment|paypal|credit card|cash on delivery|cod available|pay in installments)\b/i', $qLower)) {
        $reply = 'If payment fails, please retry once, check card/e-wallet details, and ensure network is stable. Available methods are shown at checkout. We currently support secure checkout with available payment gateways.';
        $quickReplies = ['Payment methods', 'Track my order', 'Contact support'];
    } elseif (preg_match('/\b(i need help with my order|talk to a human|human agent|arrived damaged|damaged item|create an account|forgot my password|reset password)\b/i', $qLower)) {
        $reply = 'I can help route you quickly. For urgent concerns like damaged items or account access, please contact support directly so a human agent can assist you right away.';
        $quickReplies = ['Contact support', 'Track my order', 'Payment methods'];
    } elseif (preg_match('/\b(best product for my needs|suggest similar|similar products|trending|gift recommendation|gift recommendations|gift)\b/i', $qLower)) {
        $reply = 'I can recommend products based on your needs. Please share your budget, category, and preferred brand, and I will suggest the best options for you.';
        $quickReplies = ['Show lowest price', 'Show highest price', 'Best product'];
    } elseif (preg_match('/\b(payment|pay|method|gcash|maya|grab|card|voucher|cod)\b/i', $qLower)) {
        $methods = [];
        $res = $db->query("SELECT p_name FROM tbl_payment WHERE (p_status=0 OR p_status IS NULL) ORDER BY p_name ASC");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $methods[] = trim((string)($row['p_name'] ?? ''));
            }
        }
        if (!empty($methods)) {
            $reply = 'Available payment methods: ' . implode(', ', array_filter($methods)) . '.';
        } else {
            $reply = 'I can help with payment options. Please check checkout payment methods for the latest list.';
        }
        $quickReplies = ['How to use voucher?', 'Track my order', 'Contact support'];
    } elseif (preg_match('/\b(contact|support|email|phone|hotline)\b/i', $qLower)) {
        $officeEmail = '';
        $officeNumber = '';
        $cp = $db->query("SELECT OFFICE_EMAIL, OFFICE_NUMBER FROM tbl_control_panel LIMIT 1");
        if ($cp && $cp->num_rows > 0) {
            $rowCp = mysqli_fetch_assoc($cp);
            $officeEmail = (string)($rowCp['OFFICE_EMAIL'] ?? '');
            $officeNumber = (string)($rowCp['OFFICE_NUMBER'] ?? '');
        }
        $reply = 'Support details: ' . ($officeNumber !== '' ? 'Phone: '.$officeNumber.'. ' : '') . ($officeEmail !== '' ? 'Email: '.$officeEmail.'.' : '');
        $quickReplies = ['Track my order', 'Payment methods', 'Shipping policy'];
    } elseif (preg_match('/\b(track|tracking|order status|shipping status|delivery status|where.*order|where.*package|where.*parcel|my order|my package)\b/i', $qLower)) {
        $orderNo = '';
        if (preg_match('/\b\d{6,}-\d{6,}\b/', $question, $m)) {
            $orderNo = trim((string)$m[0]);
        }

        if ($orderNo !== '') {
            if ($isMember) {
                $safeOrderNo = $db->real_escape_string($orderNo);
                $res = $db->query("SELECT od_orderno,od_status,od_claim_status,DATE_FORMAT(od_date,'%b %e, %Y %h:%i %p') as d FROM tbl_order WHERE od_user=".$memberId." AND od_orderno='".$safeOrderNo."' ORDER BY od_id DESC LIMIT 1");
                if ($res && $res->num_rows > 0) {
                    $row = mysqli_fetch_assoc($res);
                    $reply = 'Order '.$row['od_orderno'].' status: '.$statusLabel((int)$row['od_status'], (int)$row['od_claim_status']).' ('.$row['d'].').';
                    $trackingNo = trim((string)$row['od_orderno']);
                    $guestTable = $findGuestTable($db);
                    if ($guestTable !== '') {
                        $resGuest = $db->query("SELECT tracking_number FROM `".$guestTable."` WHERE order_no='".$safeOrderNo."' ORDER BY go_id DESC LIMIT 1");
                        if ($resGuest && $resGuest->num_rows > 0) {
                            $rowGuest = mysqli_fetch_assoc($resGuest);
                            $guestTracking = trim((string)($rowGuest['tracking_number'] ?? ''));
                            if ($guestTracking !== '') {
                                $trackingNo = $guestTracking;
                            }
                        }
                    }
                    if ($xdeEnabled) {
                        $xdeText = $getXdeStatusText($xdeApi, $xdeBaseUrl, $xdeApiKey, $xdeToken, $trackingNo);
                        if ($xdeText !== '') {
                            $reply .= ' '.$xdeText;
                        }
                    }
                } else {
                    $reply = 'I could not find that order number in your account. Please verify the number.';
                }
            } else {
                $guestTable = $findGuestTable($db);
                if ($guestTable === '') {
                    $reply = 'Guest tracking table is not available right now. Please try again later.';
                } else {
                    $safeOrderNo = $db->real_escape_string($orderNo);
                    $res = $db->query("SELECT g.order_no, g.tracking_number, o.od_status, o.od_claim_status
                                       FROM `".$guestTable."` g
                                       LEFT JOIN tbl_order o ON o.od_orderno = g.order_no
                                       WHERE g.order_no='".$safeOrderNo."'
                                       ORDER BY g.go_id DESC LIMIT 1");
                    if ($res && $res->num_rows > 0) {
                        $row = mysqli_fetch_assoc($res);
                        $track = trim((string)($row['tracking_number'] ?? ''));
                        $reply = 'Order '.$row['order_no'].' status: '.$statusLabel((int)($row['od_status'] ?? 0), (int)($row['od_claim_status'] ?? 0)).'. Tracking: '.($track !== '' ? $track : (string)$row['order_no']).'.';
                        if ($xdeEnabled) {
                            $trackForXde = $track !== '' ? $track : (string)$row['order_no'];
                            $xdeText = $getXdeStatusText($xdeApi, $xdeBaseUrl, $xdeApiKey, $xdeToken, $trackForXde);
                            if ($xdeText !== '') {
                                $reply .= ' '.$xdeText;
                            }
                        }
                    } else {
                        $reply = 'I could not find that guest order number. You can also use the Guest Track Order page.';
                    }
                }
            }
        } elseif ($isMember) {
            $res = $db->query("SELECT od_orderno,od_status,od_claim_status,DATE_FORMAT(od_date,'%b %e, %Y') as d FROM tbl_order WHERE od_user=".$memberId." ORDER BY od_id DESC LIMIT 3");
            if ($res && $res->num_rows > 0) {
                $items = [];
                while ($row = mysqli_fetch_assoc($res)) {
                    $items[] = $row['od_orderno'].' ('.$statusLabel((int)$row['od_status'], (int)$row['od_claim_status']).', '.$row['d'].')';
                }
                $reply = 'Your recent orders: '.implode('; ', $items).'.';
            } else {
                $reply = 'No recent orders found in your account.';
            }
        } else {
            $reply = 'Please share your order number (e.g., 2026020-639297319632) so I can check your status.';
        }
        $quickReplies = ['Payment methods', 'Contact support', 'Shipping policy'];
    } elseif (preg_match('/\b(appliances?|room|tv|television|beedroom|bedroom|bed|pillow|sofa|sofas|tabo|chair|chairs|table|tables|cabinet|cabinets|stool|stools|furniture)\b/i', $qLower)) {
        $topicTerms = [];
        if (preg_match('/\bappliances?\b/i', $qLower)) {
            $topicTerms[] = 'appliance';
        }
        if (preg_match('/\b(tv|television)\b/i', $qLower)) {
            $topicTerms[] = 'tv';
            $topicTerms[] = 'television';
        }
        if (preg_match('/\b(beedroom|bedroom)\b/i', $qLower)) {
            $topicTerms[] = 'bedroom';
        }
        if (preg_match('/\bbed\b/i', $qLower)) {
            $topicTerms[] = 'bed';
        }
        if (preg_match('/\bpillow\b/i', $qLower)) {
            $topicTerms[] = 'pillow';
        }
        if (preg_match('/\bsofas?\b/i', $qLower)) {
            $topicTerms[] = 'sofa';
        }
        if (preg_match('/\btabo\b/i', $qLower)) {
            $topicTerms[] = 'tabo';
        }
        if (preg_match('/\broom\b/i', $qLower)) {
            $topicTerms[] = 'room';
        }
        if (preg_match('/\bchairs?\b/i', $qLower)) {
            $topicTerms[] = 'chair';
        }
        if (preg_match('/\btables?\b/i', $qLower)) {
            $topicTerms[] = 'table';
        }
        if (preg_match('/\bcabinets?\b/i', $qLower)) {
            $topicTerms[] = 'cabinet';
        }
        if (preg_match('/\bstools?\b/i', $qLower)) {
            $topicTerms[] = 'stool';
        }
        if (preg_match('/\bfurniture\b/i', $qLower)) {
            $topicTerms[] = 'furniture';
        }

        $productCards = $getTopicCards($db, $topicTerms, $brandSqlFilter, $aiCleanNameFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $reply = 'Here are products that match your request.';
        } else {
            $reply = 'I could not find a matching product right now. Please try a more specific product name.';
        }
        $quickReplies = ['Show lowest price', 'Show highest price', 'Track my order'];
    } elseif (preg_match('/\bwhat is the best product here\b/i', $qLower)) {
        $productCards = $getTopRatedCards($db, $brandSqlFilter, $aiCleanNameFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $reply = $detectedBrandId > 0
                ? ('Here are some of our highest-rated ' . $detectedBrandName . ' products.')
                : 'Here are some of our highest-rated products.';
        } else {
            $productCards = $getBestSellingCards($db, $brandSqlFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
            $reply = !empty($productCards)
                ? 'We do not have enough ratings yet, so here are our current best-sellers instead.'
                : 'I could not find a matching product right now. Please try a more specific product name.';
        }
        $quickReplies = ['Show appliances', 'Show furniture', 'Track my order'];
    } elseif (preg_match('/\b(lowest|cheapest|budget|low price)\b/i', $qLower)) {
        $productCards = $getPriceSortedCards($db, $brandSqlFilter, $aiCleanNameFilter, 'ASC', 5, $mapProductCards, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $reply = $detectedBrandId > 0
                ? ('Here are some of the lowest-priced ' . $detectedBrandName . ' products.')
                : 'Here are some of the lowest-priced products.';
        } else {
            $reply = 'I could not find a matching product right now. Please try a more specific product name.';
        }
        $quickReplies = ['Show highest price', 'Track my order', 'Payment methods'];
    } elseif (preg_match('/\b(highest|expensive|premium|high price)\b/i', $qLower)) {
        $productCards = $getPriceSortedCards($db, $brandSqlFilter, $aiCleanNameFilter, 'DESC', 5, $mapProductCards, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $reply = $detectedBrandId > 0
                ? ('Here are some of the higher-priced ' . $detectedBrandName . ' products.')
                : 'Here are some of the higher-priced products.';
        } else {
            $reply = 'I could not find a matching product right now. Please try a more specific product name.';
        }
        $quickReplies = ['Show lowest price', 'Track my order', 'Payment methods'];
    } elseif (preg_match('/\b(best product|best seller|bestseller|top product|recommended product|what is the best product)\b/i', $qLower)) {
        $productCards = $getTopRatedCards($db, $brandSqlFilter, $aiCleanNameFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $arr = [];
            foreach ($productCards as $card) {
                $arr[] = $card['name'].' (from '.$card['price'].')';
            }
            $reply = $detectedBrandId > 0
                ? ('Great question. Here are some of our highest-rated ' . $detectedBrandName . ' products right now: '.implode('; ', $arr).'.')
                : ('Great question. Here are some of our highest-rated products right now: '.implode('; ', $arr).'.');
        } else {
            $productCards = $getBestSellingCards($db, $brandSqlFilter, 5, $mapProductCards, $baseUrl, $fallbackImage);
            if (!empty($productCards)) {
                $arr = [];
                foreach ($productCards as $card) {
                    $arr[] = $card['name'].' (from '.$card['price'].')';
                }
                $reply = $detectedBrandId > 0
                    ? ('We do not have enough ratings yet, so here are our current best-selling ' . $detectedBrandName . ' products: '.implode('; ', $arr).'.')
                    : ('We do not have enough ratings yet, so here are our current best-selling products: '.implode('; ', $arr).'.');
            } else {
                $reply = 'I can help you choose. Tell me your budget and category (e.g., sofa, appliance, bedroom), and I will recommend products.';
            }
        }
        $quickReplies = ['Show appliances', 'Show furniture', 'Track my order'];
    } elseif (preg_match('/\b(product|price|cost)\b/i', $qLower)) {
        $keywords = trim(preg_replace('/\b(product|price|cost|how much|is|the)\b/i', '', $question));
        if ($keywords === '') {
            $res = $db->query(
                "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
                 FROM tbl_product p
                 JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
                 LEFT JOIN (
                     SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                     FROM tbl_product_photo
                     GROUP BY pp_pdid
                 ) fp ON fp.pp_pdid=p.pd_id
                 LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
                 WHERE p.pd_status=1
                 ".$brandSqlFilter."
                 GROUP BY p.pd_id, p.pd_name, pp.pp_filename
                 ORDER BY p.pd_sales DESC, p.pd_id DESC
                 LIMIT 5"
            );
            if ($res && $res->num_rows > 0) {
                $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
                $arr = [];
                foreach ($productCards as $card) {
                    $arr[] = $card['name'].' (from '.$card['price'].')';
                }
                $reply = 'Sure. Here are popular products you can check: '.implode('; ', $arr).'.';
            } else {
                $reply = 'Please share the product name and I will check the latest price for you.';
            }
        } else {
            $safe = $db->real_escape_string($keywords);
            $res = $db->query(
                "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
                 FROM tbl_product p
                 JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
                 LEFT JOIN (
                     SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                     FROM tbl_product_photo
                     GROUP BY pp_pdid
                 ) fp ON fp.pp_pdid=p.pd_id
                 LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
                 WHERE p.pd_name LIKE '%".$safe."%'
                 ".$brandSqlFilter."
                 GROUP BY p.pd_id, p.pd_name, pp.pp_filename
                 ORDER BY p.pd_id DESC
                 LIMIT 5"
            );
            if ($res && $res->num_rows > 0) {
                $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
                $arr = [];
                foreach ($productCards as $card) {
                    $arr[] = $card['name'].' (from '.$card['price'].')';
                }
                $reply = 'Here are matching products: '.implode('; ', $arr).'.';
            } else {
                $productCards = [];
                $reply = 'I could not find a matching product right now. Please try a more specific product name.';
            }
        }
        $quickReplies = ['Track my order', 'Payment methods', 'Contact support'];
    } elseif ($detectedBrandId > 0) {
        $res = $db->query(
            "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
             FROM tbl_product p
             JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
             LEFT JOIN (
                 SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                 FROM tbl_product_photo
                 GROUP BY pp_pdid
             ) fp ON fp.pp_pdid=p.pd_id
             LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
             WHERE p.pd_status=1
             AND v.pvd_price>0
             ".$brandSqlFilter."
             ".$aiCleanNameFilter."
             GROUP BY p.pd_id, p.pd_name, pp.pp_filename
             HAVING min_price>0
             ORDER BY p.pd_sales DESC, min_price ASC, p.pd_id DESC
             LIMIT 6"
        );
        $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
        if (!empty($productCards)) {
            $reply = 'Here are available '.$detectedBrandName.' products.';
        } else {
            $reply = 'I found the brand '.$detectedBrandName.' but no active priced products are available right now.';
        }
        $quickReplies = ['Show lowest price', 'Best product', 'Track my order'];
    } elseif (preg_match('/^[a-z0-9][a-z0-9\s\-\.\&]{2,}$/i', $question)) {
        $rawTerms = preg_split('/\s+/', strtolower(trim($question)));
        $stopWords = ['the','and','for','with','from','this','that','your','you','show','need','want','find','give','me','please','item','items','product','products','price','cost','php','peso','pesos'];
        $topicTerms = [];
        foreach ($rawTerms as $t) {
            $t = trim((string)$t);
            if ($t === '' || strlen($t) < 3 || in_array($t, $stopWords, true)) {
                continue;
            }
            $topicTerms[] = $t;
            if (preg_match('/ies$/', $t)) {
                $topicTerms[] = substr($t, 0, -3) . 'y';
            } elseif (preg_match('/s$/', $t)) {
                $topicTerms[] = substr($t, 0, -1);
            } else {
                $topicTerms[] = $t . 's';
            }
        }
        $topicTerms = array_values(array_unique(array_filter($topicTerms)));

        if (!empty($topicTerms)) {
            $productCards = $getTopicCards($db, $topicTerms, $brandSqlFilter, $aiCleanNameFilter, 6, $mapProductCards, $baseUrl, $fallbackImage);
        }

        if (empty($productCards)) {
        $safe = $db->real_escape_string(trim($question));
        $res = $db->query(
            "SELECT p.pd_id, p.pd_name, MIN(v.pvd_price) AS min_price, pp.pp_filename, MAX(p.pd_description) AS pd_description
             FROM tbl_product p
             JOIN tbl_product_variation_details v ON v.pvd_pdid=p.pd_id
             LEFT JOIN (
                 SELECT pp_pdid, MIN(pp_id) AS min_pp_id
                 FROM tbl_product_photo
                 GROUP BY pp_pdid
             ) fp ON fp.pp_pdid=p.pd_id
             LEFT JOIN tbl_product_photo pp ON pp.pp_id=fp.min_pp_id
             WHERE p.pd_status=1
             AND v.pvd_price>0
             ".$aiCleanNameFilter."
             AND p.pd_name LIKE '%".$safe."%'
             GROUP BY p.pd_id, p.pd_name, pp.pp_filename
             HAVING min_price>0
             ORDER BY p.pd_sales DESC, min_price ASC, p.pd_id DESC
             LIMIT 6"
        );
        $productCards = $mapProductCards($res, $baseUrl, $fallbackImage);
        }
        if (!empty($productCards)) {
            $reply = 'Here are matching products for "'.$question.'".';
            $quickReplies = ['Show lowest price', 'Best product', 'Track my order'];
        } else {
            $reply = 'I could not find a matching product right now. Please try a more specific product name.';
        }
        } else {
            $reply = 'I can help with order tracking, payment methods, contact details, and product prices. Tell me what you need.';
        }
    }
}
} catch (Throwable $e) {
    $reply = 'Support assistant is temporarily unavailable. Please try again in a moment.';
}

echo json_encode([
    'status' => 'ok',
    'reply' => $reply,
    'quick_replies' => $quickReplies,
    'product_cards' => $productCards,
    'brand_cards' => $brandCards,
    'brand_view_all_url' => $brandViewAllUrl
]);

